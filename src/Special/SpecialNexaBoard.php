<?php

namespace MediaWiki\Extension\NexaBoard\Special;

use MediaWiki\Extension\NexaBoard\AvatarHelper;
use MediaWiki\Extension\NexaBoard\BoardAnchor;
use MediaWiki\Extension\NexaBoard\BoardBlock;
use MediaWiki\Extension\NexaBoard\BoardManager;
use MediaWiki\Extension\NexaBoard\Store\FollowStore;
use MediaWiki\Extension\NexaBoard\Store\MessageStore;
use MediaWiki\Extension\NexaBoard\Store\ThreadStore;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;

class SpecialNexaBoard extends SpecialPage {

	/** Deepest level of reply nesting rendered before replies stay flat. */
	private const MAX_REPLY_DEPTH = 4;

	public function __construct(
		private readonly BoardManager $manager,
		private readonly ThreadStore $threadStore,
		private readonly MessageStore $messageStore,
		private readonly FollowStore $followStore
	) {
		parent::__construct( 'NexaBoard' );
	}

	public function execute( $subPage ): void {
		$out     = $this->getOutput();
		$request = $this->getRequest();
		$viewer  = $this->getUser();

		$out->addModuleStyles( 'ext.nexaboard.styles' );
		$out->addModules( 'ext.nexaboard.scripts' );

		if ( !$subPage || trim( $subPage ) === '' ) {
			if ( !$viewer->isRegistered() ) {
				$out->showErrorPage( 'nexaboard', 'nexaboard-no-user' );
				return;
			}
			$out->redirect( $this->getPageTitle( $viewer->getName() )->getFullURL() );
			return;
		}

		$boardUserName = str_replace( '_', ' ', trim( $subPage ) );
		$boardUser     = MediaWikiServices::getInstance()
			->getUserFactory()
			->newFromName( $boardUserName );

		if ( !$boardUser || $boardUser->getId() === 0 ) {
			// showErrorPage takes a message key; handing it rendered text makes
			// MediaWiki treat the sentence itself as a key and print it in ⧼⧽.
			$out->showErrorPage( 'nexaboard', 'nexaboard-user-not-found', [ $boardUserName ] );
			return;
		}

		$out->setPageTitle( wfMessage( 'nexaboard-title', $boardUser->getName() )->text() );

		$userPage = Title::makeTitleSafe( NS_USER, $boardUser->getName() );
		if ( $userPage ) {
			$this->getSkin()->setRelevantTitle( $userPage );
		}

		$config         = MediaWikiServices::getInstance()->getMainConfig();
		$threadsPerPage = $config->get( 'NexaBoardThreadsPerPage' );
		$oldestFirst    = $request->getVal( 'sort' ) === 'oldest';
		$cursor         = self::parseCursor( $request->getVal( 'before' ), $oldestFirst );

		// Deleted threads are only listed on request, and only for those who
		// could undelete them.
		$showDeleted = $request->getBool( 'showdeleted' )
			&& $viewer->isAllowed( 'nexaboard-delete' );

		$rows = $this->threadStore->getByBoardUser(
			$boardUser->getId(), $threadsPerPage + 1, $cursor, $showDeleted, $oldestFirst
		);
		$hasMore = count( $rows ) > $threadsPerPage;
		$rows    = $hasMore ? array_slice( $rows, 0, $threadsPerPage ) : $rows;

		$last       = $rows ? end( $rows ) : null;
		$nextCursor = $hasMore && $last
			? self::makeCursor( $last->nbt_updated, (int)$last->nbt_id )
			: null;

		$out->addHTML( $this->renderBoard(
			$boardUser, $viewer, $rows, $nextCursor,
			$request->getVal( 'before' ), $showDeleted, $oldestFirst
		) );
	}

	/**
	 * Page cursors are "timestamp|id": the timestamp alone is not unique enough
	 * to page on, since threads frequently share one.
	 */
	private static function makeCursor( string $timestamp, int $threadId ): string {
		return $timestamp . '|' . $threadId;
	}

	private static function parseCursor( ?string $raw, bool $oldestFirst ): ?array {
		if ( $raw === null || $raw === '' ) {
			return null;
		}

		$parts = explode( '|', $raw, 2 );
		$ts    = $parts[0];

		if ( !preg_match( '/^\d{14}$/', $ts ) ) {
			return null;
		}

		// A cursor from before this format existed carries no id. Fall back to
		// the extreme of the sort direction so nothing at that timestamp is
		// skipped: the highest id when paging down, the lowest when paging up.
		$id = isset( $parts[1] ) && ctype_digit( $parts[1] )
			? (int)$parts[1]
			: ( $oldestFirst ? 0 : PHP_INT_MAX );

		return [ $ts, $id ];
	}

	private function renderBoard(
		\MediaWiki\User\User $boardUser,
		\MediaWiki\User\User $viewer,
		array $threads,
		?string $nextCursor,
		?string $currentCursor,
		bool $showDeleted = false,
		bool $oldestFirst = false
	): string {
		$config     = MediaWikiServices::getInstance()->getMainConfig();
		$maxTitle   = $config->get( 'NexaBoardMaxTitleLength' );
		$boardUserId = $boardUser->getId();
		$isOwner    = $viewer->isRegistered() && $viewer->getId() === $boardUserId;
		// isAllowed() knows nothing about blocks, so a blocked user would still be
		// shown the form and only find out on submit.
		$block      = BoardBlock::affecting( $viewer, $boardUserId );
		$canPost    = $viewer->isAllowed( 'nexaboard-post' ) && !$block;
		$canMerge   = $viewer->isAllowed( 'nexaboard-merge' );
		$canDelete  = $viewer->isAllowed( 'nexaboard-delete' );

		$threadIds = array_map( static fn ( $t ) => (int)$t->nbt_id, $threads );

		// One query each for follow state and merge provenance, rather than one
		// per thread.
		$followStates = $viewer->isRegistered()
			? $this->followStore->getExplicitStates( $threadIds, $viewer->getId() )
			: [];
		$mergedTitles = $this->threadStore->getMergedSourceTitles( $threadIds );

		$avatarData = AvatarHelper::getAvatarData( $boardUser );

		$html  = '<div class="mw-nexaboard" data-board-user="' . htmlspecialchars( $boardUser->getName() ) . '">';

		$siteName = MediaWikiServices::getInstance()->getMainConfig()->get( 'Sitename' );
		$guidelinesTitle = Title::makeTitleSafe(
			NS_PROJECT,
			wfMessage( 'nexaboard-guidelines-pagename' )->inContentLanguage()->text()
		);
		$guidelinesLink = $guidelinesTitle ? $guidelinesTitle->getPrefixedText() : '';
		$html .= '<div class="mw-nexaboard-welcome">';
		$html .= '<div class="mw-nexaboard-welcome-title">'
			. wfMessage( 'nexaboard-welcome-title', $siteName )->escaped() . '</div>';
		$html .= '<div class="mw-nexaboard-welcome-body">'
			. wfMessage( 'nexaboard-welcome-body' )->params( $guidelinesLink )->parse() . '</div>';
		$html .= '</div>';

		$html .= '<div class="mw-nexaboard-header">';
		$html .= $this->renderAvatar( $avatarData, 'large' );
		$html .= '<div class="mw-nexaboard-header-info">';
		$html .= '<h2>' . Html::element( 'a', [
			'href' => Title::newFromText( 'User:' . $boardUser->getName() )->getFullURL(),
		], $boardUser->getName() ) . '</h2>';
		if ( $isOwner ) {
			$html .= '<p class="mw-nexaboard-owner-note">'
				. wfMessage( 'nexaboard-board-owner' )->escaped()
				. '</p>';
		}
		$html .= '</div></div>';

		if ( $canPost ) {
			$html .= $this->renderNewMessageForm( $boardUser->getName(), $maxTitle );
		} elseif ( $block ) {
			$html .= '<div class="mw-nexaboard-login-notice mw-nexaboard-blocked-notice">'
				. wfMessage( 'nexaboard-blocked' )->escaped()
				. '</div>';
		} elseif ( !$viewer->isRegistered() ) {
			$html .= '<div class="mw-nexaboard-login-notice">'
				. wfMessage( 'nexaboard-login-required' )->parse()
				. '</div>';
		} else {
			$html .= '<div class="mw-nexaboard-login-notice">'
				. wfMessage( 'nexaboard-nopermission' )->escaped()
				. '</div>';
		}

		$html .= $this->renderToolbar(
			$boardUser->getName(), $viewer, $threads, $showDeleted, $oldestFirst
		);

		// With the toggle on and nothing hidden to reveal, the page is identical
		// to the page without it — which reads as the toggle being broken. Say
		// what it found either way.
		if ( $showDeleted ) {
			$hidden = 0;
			foreach ( $threads as $t ) {
				$st = (int)$t->nbt_status;
				if ( $st === ThreadStore::STATUS_DELETED || $st === ThreadStore::STATUS_MERGED ) {
					$hidden++;
				}
			}

			$html .= '<div class="mw-nexaboard-hidden-note">'
				. ( $hidden
					? wfMessage( 'nexaboard-hidden-shown' )->numParams( $hidden )->escaped()
					: wfMessage( 'nexaboard-hidden-none' )->escaped() )
				. '</div>';
		}

		if ( $canDelete && $threads ) {
			$html .= $this->renderBulkPanel();
		}

		if ( $canMerge && count( $threads ) > 1 ) {
			$html .= '<button id="mw-nexaboard-merge-trigger" class="mw-nexaboard-merge-trigger-btn">'
				. wfMessage( 'nexaboard-merge-start' )->escaped() . '</button>';
			$html .= $this->renderMergePanel( $threads );
		}

		if ( $viewer->isAllowed( 'nexaboard-move' ) && $threads ) {
			$html .= $this->renderMovePanel( $threads );
			$html .= $this->renderTransferPanel();
		}

		$html .= '<div class="mw-nexaboard-threads">';

		if ( empty( $threads ) ) {
			$html .= '<div class="mw-nexaboard-empty">'
				. wfMessage( 'nexaboard-empty' )->escaped()
				. '</div>';
		} else {
			foreach ( $threads as $thread ) {
				$tid = (int)$thread->nbt_id;
				$html .= $this->renderThread(
					$thread, $viewer, $isOwner, $boardUser->getName(),
					$followStates[$tid] ?? null,
					$mergedTitles[$tid] ?? [],
					(bool)$block
				);
			}
		}

		$html .= '</div>';

		if ( $nextCursor || $currentCursor ) {
			$viewQuery = [];
			if ( $oldestFirst ) {
				$viewQuery['sort'] = 'oldest';
			}
			if ( $showDeleted ) {
				$viewQuery['showdeleted'] = '1';
			}

			$pageTitle = $this->getPageTitle( $boardUser->getName() );

			$html .= '<div class="mw-nexaboard-pagination">';
			if ( $currentCursor ) {
				$html .= Html::element( 'a', [
					'href'  => $pageTitle->getFullURL( $viewQuery ),
					'class' => 'mw-nexaboard-page-btn',
				], wfMessage( 'nexaboard-prev-page' )->text() );
			}
			if ( $nextCursor ) {
				$html .= Html::element( 'a', [
					'href'  => $pageTitle->getFullURL( $viewQuery + [ 'before' => $nextCursor ] ),
					'class' => 'mw-nexaboard-page-btn',
				], wfMessage( 'nexaboard-next-page' )->text() );
			}
			$html .= '</div>';
		}

		$html .= '</div>';

		return $html;
	}

	private function renderThread(
		object $thread,
		\MediaWiki\User\User $viewer,
		bool $isOwner,
		string $boardOwnerName,
		?bool $followState = null,
		array $mergedFrom = [],
		bool $blocked = false
	): string {
		$threadId  = (int)$thread->nbt_id;
		$status    = (int)$thread->nbt_status;
		$isClosed  = $status === ThreadStore::STATUS_CLOSED;
		$isDeleted = $status === ThreadStore::STATUS_DELETED;
		$isMerged  = $status === ThreadStore::STATUS_MERGED;

		$canDelete = $viewer->isAllowed( 'nexaboard-delete' );
		$canClose  = $isOwner || $viewer->isAllowed( 'nexaboard-close' );

		// Reopening is narrower than closing: you may undo your own close, but
		// reversing someone else's — a moderator's — needs the right. Without this
		// the button would render and then fail at the API.
		if ( $isClosed && $canClose ) {
			$canClose = (int)$thread->nbt_closed_by === $viewer->getId()
				|| $viewer->isAllowed( 'nexaboard-close' );
		}

		// A merge moves every message, the originating post included, onto the
		// destination — so a merged source has nothing left to render from. It
		// gets a standalone tombstone built from the title the thread kept, and
		// returns before the code below, all of which needs an OP.
		if ( $isMerged ) {
			return $this->renderMergedTombstone( $thread, $boardOwnerName );
		}

		// Deleted threads show their replies too, so a moderator can see what
		// they are restoring.
		$op = $this->messageStore->getOpByThread( $threadId );

		if ( !$op ) {
			return '';
		}

		$authorUser = MediaWikiServices::getInstance()
			->getUserFactory()
			->newFromName( $op->nbm_author_name );
		$avatarData = $authorUser && $authorUser->getId()
			? AvatarHelper::getAvatarData( $authorUser )
			: $this->fallbackAvatarData( $op->nbm_author_name );

		$parsedBody = $this->parseWikitext( $op->nbm_body );
		$timestamp  = $this->formatTimestamp( $op->nbm_created );
		$replies    = $this->messageStore->getRepliesByThread( $threadId, $canDelete );
		// Moderators are shown deleted replies as tombstones, but they must not
		// be counted: a thread whose only reply was deleted reads as "1 reply"
		// otherwise. $replies drives rendering, $replyCount drives the labels.
		$replyCount = 0;
		foreach ( $replies as $reply ) {
			if ( !$reply->nbm_deleted ) {
				$replyCount++;
			}
		}

		$classes  = 'mw-nexaboard-thread';
		if ( $isClosed )  $classes .= ' mw-nexaboard-status-closed';
		if ( $isDeleted ) $classes .= ' mw-nexaboard-status-deleted';
		if ( $isMerged )  $classes .= ' mw-nexaboard-status-merged';

		$html  = '<div class="' . $classes . '" id="' . BoardAnchor::threadFragment( $threadId )
			. '" data-thread-id="' . $threadId . '">';

		$html .= '<div class="mw-nexaboard-thread-header">';
		$html .= $this->renderAvatar( $avatarData );
		$html .= '<div class="mw-nexaboard-thread-meta">';
		$html .= '<span class="mw-nexaboard-thread-author">';
		$userTitle = Title::newFromText( 'User:' . $op->nbm_author_name );
		$html .= Html::element( 'a', [ 'href' => $userTitle ? $userTitle->getFullURL() : '#' ],
			$op->nbm_author_name );
		$html .= '</span>';
		$html .= ' <span class="mw-nexaboard-thread-time">' . htmlspecialchars( $timestamp ) . '</span>';
		if ( $isClosed ) {
			$html .= ' <span class="mw-nexaboard-closed-badge">'
				. wfMessage( 'nexaboard-closed-label' )->escaped() . '</span>';
		}
		if ( $isDeleted ) {
			$html .= ' <span class="mw-nexaboard-deleted-badge">'
				. wfMessage( 'nexaboard-deleted-label' )->escaped() . '</span>';
		}
		$html .= ' ' . $this->renderPermalink(
			BoardAnchor::threadUrl( $boardOwnerName, $threadId ),
			wfMessage( 'nexaboard-thread-id', $threadId )->text(),
			wfMessage( 'nexaboard-permalink-thread-title', $threadId )->text()
		);
		$html .= '</div></div>';

		$html .= '<h3 class="mw-nexaboard-thread-title">'
			. htmlspecialchars( $thread->nbt_title ) . '</h3>';
		$html .= '<div class="mw-nexaboard-thread-body" data-msg-id="' . (int)$op->nbm_id . '">'
			. $parsedBody . '</div>';
		$html .= $this->renderEditedMarker( $op );

		// Merging keeps the source titles on their own rows; surface them here so
		// the merge is not silently invisible on the destination.
		if ( $mergedFrom ) {
			$html .= '<div class="mw-nexaboard-merged-note">'
				. wfMessage( 'nexaboard-merged-from' )
					->numParams( count( $mergedFrom ) )
					->params( $this->getLanguage()->listToText( array_map(
						static fn ( $t ) => '"' . $t . '"', $mergedFrom
					) ) )
					->escaped()
				. '</div>';
		}

		$html .= '<div class="mw-nexaboard-thread-actions">';

		if ( $replyCount > 0 ) {
			$html .= '<button class="mw-nexaboard-toggle-replies" data-thread-id="' . $threadId
				. '" data-count="' . $replyCount . '">'
				. wfMessage( 'nexaboard-show-replies' )->numParams( $replyCount )->escaped()
				. '</button>';
		}

		if ( !$isClosed && !$isDeleted && !$isMerged && !$blocked && $viewer->isAllowed( 'nexaboard-post' ) ) {
			$html .= '<button class="mw-nexaboard-reply-btn" data-thread-id="' . $threadId . '">'
				. wfMessage( 'nexaboard-reply-btn' )->escaped() . '</button>';
		}

		if ( !$isDeleted && !$isMerged && !$blocked && $this->canEditMessage( $viewer, $op, $status ) ) {
			$html .= '<button class="mw-nexaboard-edit-btn" data-msg-id="' . (int)$op->nbm_id
				. '" data-thread-id="' . $threadId . '" data-is-op="1">'
				. wfMessage( 'nexaboard-edit-btn' )->escaped() . '</button>';
		}

		if ( !$isDeleted && !$isMerged && !$blocked && $viewer->isRegistered() ) {
			$following = $this->isFollowing( $viewer, $op, $replies, $followState );
			$html .= '<button class="mw-nexaboard-follow-btn" data-thread-id="' . $threadId
				. '" data-following="' . ( $following ? '1' : '0' ) . '">'
				. wfMessage( $following ? 'nexaboard-unfollow-btn' : 'nexaboard-follow-btn' )->escaped()
				. '</button>';
		}

		if ( !$isDeleted && !$isMerged && !$blocked && $canClose ) {
			$html .= '<button class="mw-nexaboard-close-btn" data-thread-id="' . $threadId
				. '" data-reopen="' . ( $isClosed ? '1' : '0' ) . '">'
				. wfMessage( $isClosed ? 'nexaboard-reopen-btn' : 'nexaboard-close-btn' )->escaped()
				. '</button>';
		}

		if ( !$isDeleted && !$isMerged && !$blocked && $viewer->isAllowed( 'nexaboard-move' ) ) {
			$html .= '<button class="mw-nexaboard-transfer-btn" data-thread-id="' . $threadId
				. '" data-thread-title="' . htmlspecialchars( $thread->nbt_title ) . '">'
				. wfMessage( 'nexaboard-transfer-btn' )->escaped() . '</button>';
		}

		if ( $canDelete && $replyCount > 0 && !$isDeleted && !$isMerged && !$blocked ) {
			$html .= '<button class="mw-nexaboard-delete-replies-btn" data-thread-id="' . $threadId . '">'
				. wfMessage( 'nexaboard-delete-replies-btn' )->escaped() . '</button>';
		}

		if ( $canDelete && !$isMerged && !$blocked ) {
			if ( $isDeleted ) {
				$html .= '<button class="mw-nexaboard-undelete-btn" data-thread-id="' . $threadId . '">'
					. wfMessage( 'nexaboard-undelete-btn' )->escaped() . '</button>';
			} else {
				$html .= '<button class="mw-nexaboard-delete-btn" data-thread-id="' . $threadId . '">'
					. wfMessage( 'nexaboard-delete-btn' )->escaped() . '</button>';
			}
		}

		$html .= '</div>';

		if ( $replies ) {
			$display = $replyCount <= 2 ? 'block' : 'none';
			$html .= '<div class="mw-nexaboard-replies" data-thread-id="' . $threadId
				. '" style="display:' . $display . '">';
			$html .= $this->renderReplyTree(
				$replies, $viewer, $threadId, $boardOwnerName, $status, $blocked
			);
			$html .= '</div>';
		}

		if ( !$isClosed && !$isDeleted && !$isMerged && !$blocked && $viewer->isAllowed( 'nexaboard-post' ) ) {
			$html .= '<div class="mw-nexaboard-reply-form-wrap" data-thread-id="' . $threadId
				. '" data-parent-id="" style="display:none">';
			$html .= '<p class="mw-nexaboard-replying-to" style="display:none"></p>';
			$html .= '<textarea class="mw-nexaboard-reply-input" placeholder="'
				. wfMessage( 'nexaboard-reply-placeholder' )->escaped() . '"></textarea>';
			$html .= '<div class="mw-nexaboard-reply-form-actions">';
			$html .= '<button class="mw-nexaboard-reply-submit" data-thread-id="' . $threadId . '">'
				. wfMessage( 'nexaboard-reply-btn' )->escaped() . '</button>';
			$html .= ' <button class="mw-nexaboard-reply-cancel" data-thread-id="' . $threadId . '">'
				. wfMessage( 'nexaboard-cancel-btn' )->escaped() . '</button>';
			$html .= '</div></div>';
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Render replies as a tree. nbm_parent_id has always existed but was never
	 * written; replies that predate threading (or whose parent was deleted)
	 * simply stay at the top level.
	 */
	private function renderReplyTree(
		array $replies,
		\MediaWiki\User\User $viewer,
		int $threadId,
		string $boardOwnerName,
		int $threadStatus,
		bool $blocked = false
	): string {
		$byParent = [];
		$present  = [];

		foreach ( $replies as $reply ) {
			$present[(int)$reply->nbm_id] = true;
		}

		foreach ( $replies as $reply ) {
			$parent = $reply->nbm_parent_id !== null ? (int)$reply->nbm_parent_id : 0;

			// An orphan (parent deleted or moved away) is shown at the root
			// rather than dropped.
			if ( $parent && !isset( $present[$parent] ) ) {
				$parent = 0;
			}

			$byParent[$parent][] = $reply;
		}

		return $this->renderReplyLevel(
			$byParent, 0, $viewer, $threadId, $boardOwnerName, $threadStatus, 0, $blocked
		);
	}

	/**
	 * One level of the reply tree.
	 *
	 * Children are nested inside their parent's element rather than emitted as
	 * flat siblings with an indent class, so the connector rail and the
	 * collapse control have something real to attach to.
	 */
	private function renderReplyLevel(
		array $byParent,
		int $parentId,
		\MediaWiki\User\User $viewer,
		int $threadId,
		string $boardOwnerName,
		int $threadStatus,
		int $depth,
		bool $blocked = false
	): string {
		if ( empty( $byParent[$parentId] ) ) {
			return '';
		}

		$html = '';

		foreach ( $byParent[$parentId] as $reply ) {
			$msgId    = (int)$reply->nbm_id;
			$children = $byParent[$msgId] ?? [];

			$classes = 'mw-nexaboard-reply';
			if ( $depth > 0 ) {
				$classes .= ' mw-nexaboard-reply-nested';
			}
			if ( (int)$reply->nbm_deleted === 1 ) {
				$classes .= ' mw-nexaboard-reply-deleted';
			}

			$html .= '<div class="' . $classes . '" id="' . BoardAnchor::messageFragment( $msgId )
				. '" data-msg-id="' . $msgId . '" data-depth="' . $depth . '">';

			$html .= $this->renderReply(
				$reply, $viewer, $threadId, $boardOwnerName, $threadStatus, $depth, $blocked
			);

			if ( $children ) {
				$descendants = $this->countDescendants( $byParent, $msgId );

				$html .= '<div class="mw-nexaboard-reply-children" data-msg-id="' . $msgId . '">';

				// The rail doubles as the collapse control, the way the thread
				// line does in most discussion UIs.
				$html .= '<button class="mw-nexaboard-thread-line" data-msg-id="' . $msgId
					. '" aria-expanded="true" title="'
					. wfMessage( 'nexaboard-collapse-replies' )->escaped() . '">'
					. '<span class="mw-nexaboard-thread-line-hit"></span></button>';

				$html .= '<button class="mw-nexaboard-expand-chip" data-msg-id="' . $msgId
					. '" style="display:none">'
					. wfMessage( 'nexaboard-expand-replies' )->numParams( $descendants )->escaped()
					. '</button>';

				$html .= $this->renderReplyLevel(
					$byParent, $msgId, $viewer, $threadId, $boardOwnerName,
					$threadStatus, min( $depth + 1, self::MAX_REPLY_DEPTH ), $blocked
				);

				$html .= '</div>';
			}

			$html .= '</div>';
		}

		return $html;
	}

	/**
	 * Total replies beneath a message, for the collapsed-branch chip.
	 */
	private function countDescendants( array $byParent, int $msgId ): int {
		$total = 0;
		foreach ( $byParent[$msgId] ?? [] as $child ) {
			$total += 1 + $this->countDescendants( $byParent, (int)$child->nbm_id );
		}
		return $total;
	}

	/**
	 * The avatar and content of a single reply, without its children.
	 */
	private function renderReply(
		object $reply,
		\MediaWiki\User\User $viewer,
		int $threadId,
		string $boardOwnerName,
		int $threadStatus,
		int $depth = 0,
		bool $blocked = false
	): string {
		$msgId     = (int)$reply->nbm_id;
		$isDeleted = (int)$reply->nbm_deleted === 1;
		// Merging moves replies to the destination, so a merged source normally has
		// none left — but if one lingers it is history, not something to act on.
		$isMerged  = $threadStatus === ThreadStore::STATUS_MERGED;
		$canDelete = $viewer->isAllowed( 'nexaboard-delete' );

		$authorUser = MediaWikiServices::getInstance()
			->getUserFactory()
			->newFromName( $reply->nbm_author_name );
		$avatarData = $authorUser && $authorUser->getId()
			? AvatarHelper::getAvatarData( $authorUser )
			: $this->fallbackAvatarData( $reply->nbm_author_name );

		$timestamp = $this->formatTimestamp( $reply->nbm_created );

		$html  = '<div class="mw-nexaboard-reply-main">';
		$html .= $this->renderAvatar( $avatarData, 'small' );
		$html .= '<div class="mw-nexaboard-reply-content">';

		$html .= '<div class="mw-nexaboard-reply-meta">';
		$userTitle = Title::newFromText( 'User:' . $reply->nbm_author_name );
		$html .= Html::element( 'a', [
			'href'  => $userTitle ? $userTitle->getFullURL() : '#',
			'class' => 'mw-nexaboard-reply-author',
		], $reply->nbm_author_name );
		$html .= ' <span class="mw-nexaboard-reply-time">' . htmlspecialchars( $timestamp ) . '</span>';
		$html .= ' ' . $this->renderPermalink(
			BoardAnchor::messageUrl( $boardOwnerName, $msgId ),
			wfMessage( 'nexaboard-message-id', $msgId )->text(),
			wfMessage( 'nexaboard-permalink-message-title', $msgId )->text()
		);
		if ( $isDeleted ) {
			$html .= ' <span class="mw-nexaboard-deleted-badge">'
				. wfMessage( 'nexaboard-deleted-label' )->escaped() . '</span>';
		}
		$html .= '</div>';

		if ( $isDeleted ) {
			// The body stays hidden even from moderators; the row exists so the
			// message can be restored, not read around the deletion.
			$html .= '<div class="mw-nexaboard-reply-body mw-nexaboard-tombstone">'
				. wfMessage( 'nexaboard-thread-deleted' )->escaped() . '</div>';
		} else {
			$html .= '<div class="mw-nexaboard-reply-body" data-msg-id="' . $msgId . '">'
				. $this->parseWikitext( $reply->nbm_body ) . '</div>';
			$html .= $this->renderEditedMarker( $reply );
		}

		$html .= '<div class="mw-nexaboard-reply-actions">';

		$threadOpen = $threadStatus === ThreadStore::STATUS_OPEN;

		if (
			!$isDeleted && !$isMerged && !$blocked && $threadOpen
			&& $depth < self::MAX_REPLY_DEPTH
			&& $viewer->isAllowed( 'nexaboard-post' )
		) {
			$html .= '<button class="mw-nexaboard-reply-to-btn" data-thread-id="' . $threadId
				. '" data-parent-id="' . $msgId . '">'
				. wfMessage( 'nexaboard-reply-to-btn' )->escaped() . '</button>';
		}

		if ( !$isDeleted && !$isMerged && !$blocked && $this->canEditMessage( $viewer, $reply, $threadStatus ) ) {
			$html .= '<button class="mw-nexaboard-edit-btn" data-msg-id="' . $msgId
				. '" data-thread-id="' . $threadId . '" data-is-op="0">'
				. wfMessage( 'nexaboard-edit-btn' )->escaped() . '</button>';
		}

		if ( !$isDeleted && !$isMerged && !$blocked && $viewer->isAllowed( 'nexaboard-move' ) ) {
			$html .= '<button class="mw-nexaboard-move-msg-btn" data-msg-id="' . $msgId
				. '" data-thread-id="' . $threadId . '">'
				. wfMessage( 'nexaboard-move-msg-btn' )->escaped() . '</button>';
		}

		if ( !$blocked && $this->canDeleteMessage( $viewer, $reply ) ) {
			if ( $isDeleted ) {
				if ( $canDelete ) {
					$html .= '<button class="mw-nexaboard-undelete-msg-btn" data-msg-id="' . $msgId . '">'
						. wfMessage( 'nexaboard-undelete-btn' )->escaped() . '</button>';
				}
			} else {
				$html .= '<button class="mw-nexaboard-delete-msg-btn" data-msg-id="' . $msgId . '">'
					. wfMessage( 'nexaboard-delete-btn' )->escaped() . '</button>';
			}
		}

		$html .= '</div>';
		$html .= '</div></div>';

		return $html;
	}

	/**
	 * "edited 12:00, 1 January 2026", shown only once a message has been edited.
	 */
	private function renderEditedMarker( object $msg ): string {
		if ( $msg->nbm_edited === null || $msg->nbm_edited === '' ) {
			return '';
		}

		// Moderators may edit a message its author can no longer touch, including
		// in a closed thread. Saying so next to the text is the disclosure that
		// matters — a log entry nobody reads is not one.
		$editedBy = $msg->nbm_edited_by === null ? null : (int)$msg->nbm_edited_by;
		$byOther  = $editedBy !== null && $editedBy !== (int)$msg->nbm_author_id;

		if ( $byOther ) {
			$editor = MediaWikiServices::getInstance()->getUserFactory()->newFromId( $editedBy );
			$name   = $editor ? $editor->getName() : '';

			return '<div class="mw-nexaboard-edited mw-nexaboard-edited-by-other">'
				. wfMessage( 'nexaboard-edited-marker-by' )
					->params( $this->formatTimestamp( $msg->nbm_edited ) )
					->params( $name )
					->escaped()
				. '</div>';
		}

		return '<div class="mw-nexaboard-edited">'
			. wfMessage( 'nexaboard-edited-marker' )
				->params( $this->formatTimestamp( $msg->nbm_edited ) )
				->escaped()
			. '</div>';
	}

	/**
	 * Authors may edit their own messages while the thread is open; moderators
	 * may edit any message at any time.
	 */
	private function canEditMessage(
		\MediaWiki\User\User $viewer,
		object $msg,
		int $threadStatus
	): bool {
		if ( $viewer->isAllowed( 'nexaboard-edit-others' ) ) {
			return true;
		}

		return $viewer->isRegistered()
			&& $viewer->getId() === (int)$msg->nbm_author_id
			&& $viewer->isAllowed( 'nexaboard-edit-own' )
			&& $threadStatus === ThreadStore::STATUS_OPEN;
	}

	private function canDeleteMessage( \MediaWiki\User\User $viewer, object $msg ): bool {
		if ( $viewer->isAllowed( 'nexaboard-delete' ) ) {
			return true;
		}

		return $viewer->isRegistered()
			&& $viewer->getId() === (int)$msg->nbm_author_id
			&& $viewer->isAllowed( 'nexaboard-edit-own' );
	}

	/**
	 * Whether the viewer currently gets notifications for a thread: their
	 * explicit choice if they made one, otherwise whether they have posted in it.
	 */
	private function isFollowing(
		\MediaWiki\User\User $viewer,
		object $op,
		array $replies,
		?bool $explicit
	): bool {
		if ( $explicit !== null ) {
			return $explicit;
		}

		$viewerId = $viewer->getId();
		if ( (int)$op->nbm_author_id === $viewerId ) {
			return true;
		}

		foreach ( $replies as $reply ) {
			if ( (int)$reply->nbm_author_id === $viewerId ) {
				return true;
			}
		}

		return false;
	}

	private function renderMergePanel( array $threads ): string {
		$options = $this->renderThreadOptions( $threads );

		$html  = '<div class="mw-nexaboard-merge-panel" id="mw-nexaboard-merge-panel" style="display:none">';
		$html .= '<h3>' . wfMessage( 'nexaboard-merge-title' )->escaped() . '</h3>';
		$html .= '<p>' . wfMessage( 'nexaboard-merge-desc' )->escaped() . '</p>';
		$html .= '<label>' . wfMessage( 'nexaboard-merge-target' )->escaped()
			. ' <select id="mw-nexaboard-merge-target">' . $options . '</select></label>';
		$html .= '<p class="mw-nexaboard-merge-count" id="mw-nexaboard-merge-count"></p>';
		$html .= '<input type="text" id="mw-nexaboard-merge-reason" placeholder="'
			. wfMessage( 'nexaboard-merge-reason-placeholder' )->escaped() . '" />';
		$html .= '<div class="mw-nexaboard-merge-actions">';
		$html .= '<button id="mw-nexaboard-merge-submit">'
			. wfMessage( 'nexaboard-merge-btn' )->escaped() . '</button>';
		$html .= ' <button id="mw-nexaboard-merge-cancel">'
			. wfMessage( 'nexaboard-cancel-btn' )->escaped() . '</button>';
		$html .= '</div></div>';

		return $html;
	}

	/**
	 * A "#123" permalink chip. $label is what the reader sees, $tooltip explains it.
	 */
	private function renderPermalink( string $url, string $label, string $tooltip ): string {
		return Html::element( 'a', [
			'href'  => $url,
			'class' => 'mw-nexaboard-permalink',
			'title' => $tooltip,
		], $label );
	}

	/**
	 * <option> list of threads, labelled with the id so that two threads sharing
	 * a title can still be told apart.
	 */
	private function renderThreadOptions( array $threads ): string {
		$options = '';
		foreach ( $threads as $t ) {
			$options .= Html::element(
				'option',
				[ 'value' => (int)$t->nbt_id ],
				wfMessage( 'nexaboard-thread-option', (int)$t->nbt_id, $t->nbt_title )->text()
			);
		}
		return $options;
	}

	/**
	 * Destination picker for moving a reply between threads. Replaces the old
	 * window.prompt(), which asked for a thread id the page never displayed.
	 */
	/**
	 * Panel for moving a whole thread to another user's board. Unlike the move
	 * panel it takes a username rather than a thread, since the destination is a
	 * board this page knows nothing about.
	 */
	/**
	 * A thread that was merged away: shown only in the moderator view, as a row
	 * that says where its messages went. Without this a merge is indistinguishable
	 * from the thread having been destroyed.
	 */
	private function renderMergedTombstone( object $thread, string $boardOwnerName ): string {
		$threadId = (int)$thread->nbt_id;
		$targetId = (int)$thread->nbt_merged_into;

		$html  = '<div class="mw-nexaboard-thread mw-nexaboard-status-merged" id="'
			. BoardAnchor::threadFragment( $threadId )
			. '" data-thread-id="' . $threadId . '">';

		$html .= '<div class="mw-nexaboard-thread-header">';
		$html .= '<div class="mw-nexaboard-thread-meta">';
		$html .= '<span class="mw-nexaboard-merged-badge">'
			. wfMessage( 'nexaboard-merged-label' )->escaped() . '</span>';
		$html .= ' ' . $this->renderPermalink(
			BoardAnchor::threadUrl( $boardOwnerName, $threadId ),
			wfMessage( 'nexaboard-thread-id', $threadId )->text(),
			wfMessage( 'nexaboard-permalink-thread-title', $threadId )->text()
		);
		$html .= '</div></div>';

		$html .= '<h3 class="mw-nexaboard-thread-title">'
			. htmlspecialchars( $thread->nbt_title ) . '</h3>';

		if ( $targetId ) {
			$html .= '<div class="mw-nexaboard-merged-into">'
				. Html::element(
					'a',
					[ 'href' => BoardAnchor::threadUrl( $boardOwnerName, $targetId ) ],
					wfMessage( 'nexaboard-merged-into', $targetId )->text()
				)
				. '</div>';
		}

		$html .= '</div>';

		return $html;
	}

	private function renderTransferPanel(): string {
		$html  = '<div class="mw-nexaboard-transfer-panel" id="mw-nexaboard-transfer-panel" style="display:none">';
		$html .= '<h3>' . wfMessage( 'nexaboard-transfer-title' )->escaped() . '</h3>';
		$html .= '<p class="mw-nexaboard-transfer-subject" id="mw-nexaboard-transfer-subject"></p>';
		$html .= '<label>' . wfMessage( 'nexaboard-transfer-target' )->escaped()
			. ' <input type="text" id="mw-nexaboard-transfer-target" placeholder="'
			. wfMessage( 'nexaboard-transfer-target-placeholder' )->escaped()
			. '" autocomplete="off" /></label>';
		$html .= '<input type="text" id="mw-nexaboard-transfer-reason" placeholder="'
			. wfMessage( 'nexaboard-move-reason-placeholder' )->escaped() . '" />';
		$html .= '<div class="mw-nexaboard-transfer-actions">';
		$html .= '<button id="mw-nexaboard-transfer-submit">'
			. wfMessage( 'nexaboard-transfer-submit' )->escaped() . '</button>';
		$html .= ' <button id="mw-nexaboard-transfer-cancel">'
			. wfMessage( 'nexaboard-cancel-btn' )->escaped() . '</button>';
		$html .= '</div></div>';

		return $html;
	}

	private function renderMovePanel( array $threads ): string {
		$html  = '<div class="mw-nexaboard-move-panel" id="mw-nexaboard-move-panel" style="display:none">';
		$html .= '<h3>' . wfMessage( 'nexaboard-move-title' )->escaped() . '</h3>';
		$html .= '<p class="mw-nexaboard-move-subject" id="mw-nexaboard-move-subject"></p>';
		$html .= '<label>' . wfMessage( 'nexaboard-move-target' )->escaped()
			. ' <select id="mw-nexaboard-move-target">'
			. $this->renderThreadOptions( $threads )
			. '</select></label>';
		$html .= '<label class="mw-nexaboard-move-other">'
			. wfMessage( 'nexaboard-move-target-other' )->escaped()
			. ' <input type="text" id="mw-nexaboard-move-target-id" inputmode="numeric" '
			. 'placeholder="' . wfMessage( 'nexaboard-move-target-placeholder' )->escaped()
			. '" /></label>';
		$html .= '<input type="text" id="mw-nexaboard-move-reason" placeholder="'
			. wfMessage( 'nexaboard-move-reason-placeholder' )->escaped() . '" />';
		$html .= '<div class="mw-nexaboard-move-actions">';
		$html .= '<button id="mw-nexaboard-move-submit">'
			. wfMessage( 'nexaboard-move-msg-btn' )->escaped() . '</button>';
		$html .= ' <button id="mw-nexaboard-move-cancel">'
			. wfMessage( 'nexaboard-cancel-btn' )->escaped() . '</button>';
		$html .= '</div></div>';

		return $html;
	}

	/**
	 * Sort control, plus the deleted-threads toggle for moderators.
	 */
	private function renderToolbar(
		string $boardUserName,
		\MediaWiki\User\User $viewer,
		array $threads,
		bool $showDeleted,
		bool $oldestFirst
	): string {
		$base = $this->getPageTitle( $boardUserName );

		$linkFor = static function ( array $query ) use ( $base, $showDeleted, $oldestFirst ) {
			$q = [];
			if ( !empty( $query['sort'] ) ) {
				$q['sort'] = $query['sort'];
			} elseif ( $oldestFirst && !isset( $query['sort'] ) ) {
				$q['sort'] = 'oldest';
			}
			if ( array_key_exists( 'showdeleted', $query ) ) {
				if ( $query['showdeleted'] ) {
					$q['showdeleted'] = '1';
				}
			} elseif ( $showDeleted ) {
				$q['showdeleted'] = '1';
			}
			return $base->getFullURL( $q );
		};

		$html  = '<div class="mw-nexaboard-toolbar">';

		$html .= '<span class="mw-nexaboard-sort">';
		$html .= Html::element(
			'a',
			[
				'href'  => $linkFor( [ 'sort' => 'newest' ] ),
				'class' => 'mw-nexaboard-sort-link' . ( $oldestFirst ? '' : ' mw-nexaboard-sort-active' ),
			],
			wfMessage( 'nexaboard-sort-newest' )->text()
		);
		$html .= ' · ';
		$html .= Html::element(
			'a',
			[
				'href'  => $linkFor( [ 'sort' => 'oldest' ] ),
				'class' => 'mw-nexaboard-sort-link' . ( $oldestFirst ? ' mw-nexaboard-sort-active' : '' ),
			],
			wfMessage( 'nexaboard-sort-oldest' )->text()
		);
		$html .= '</span>';

		if ( $viewer->isAllowed( 'nexaboard-delete' ) ) {
			$html .= ' <span class="mw-nexaboard-showdeleted">';
			$html .= Html::element(
				'a',
				[ 'href' => $linkFor( [ 'showdeleted' => !$showDeleted ] ) ],
				wfMessage(
					$showDeleted ? 'nexaboard-hide-deleted' : 'nexaboard-show-deleted'
				)->text()
			);
			$html .= '</span>';
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Bulk selection bar. The checkboxes it drives are injected next to each
	 * thread by the client script.
	 */
	private function renderBulkPanel(): string {
		$html  = '<div class="mw-nexaboard-bulk" id="mw-nexaboard-bulk">';
		$html .= '<button id="mw-nexaboard-bulk-toggle" class="mw-nexaboard-bulk-toggle">'
			. wfMessage( 'nexaboard-bulk-select' )->escaped() . '</button>';
		$html .= '<div class="mw-nexaboard-bulk-actions" id="mw-nexaboard-bulk-actions" style="display:none">';
		$html .= '<span class="mw-nexaboard-bulk-count" id="mw-nexaboard-bulk-count"></span> ';
		$html .= '<button id="mw-nexaboard-bulk-all" class="mw-nexaboard-bulk-link">'
			. wfMessage( 'nexaboard-bulk-selectall' )->escaped() . '</button> ';
		$html .= '<button id="mw-nexaboard-bulk-none" class="mw-nexaboard-bulk-link">'
			. wfMessage( 'nexaboard-bulk-selectnone' )->escaped() . '</button>';
		$html .= '<input type="text" id="mw-nexaboard-bulk-reason" placeholder="'
			. wfMessage( 'nexaboard-merge-reason-placeholder' )->escaped() . '" />';
		$html .= '<button id="mw-nexaboard-bulk-delete">'
			. wfMessage( 'nexaboard-bulk-delete-threads' )->escaped() . '</button> ';
		$html .= '<button id="mw-nexaboard-bulk-delete-replies">'
			. wfMessage( 'nexaboard-bulk-delete-replies' )->escaped() . '</button> ';
		$html .= '<button id="mw-nexaboard-bulk-cancel">'
			. wfMessage( 'nexaboard-cancel-btn' )->escaped() . '</button>';
		$html .= '</div></div>';

		return $html;
	}

	private function renderNewMessageForm( string $boardUserName, int $maxTitle ): string {
		$html  = '<div class="mw-nexaboard-newmsg">';
		$html .= '<button class="mw-nexaboard-newmsg-btn" id="mw-nexaboard-open-form">'
			. wfMessage( 'nexaboard-leave-message' )->escaped() . '</button>';
		$html .= '<div class="mw-nexaboard-newmsg-form" id="mw-nexaboard-new-form" style="display:none">';
		$html .= '<h3>' . wfMessage( 'nexaboard-new-message-title' )->escaped() . '</h3>';
		$html .= '<input type="text" id="mw-nexaboard-new-title" class="mw-nexaboard-title-input" '
			. 'placeholder="' . wfMessage( 'nexaboard-title-placeholder' )->escaped() . '" '
			. 'maxlength="' . $maxTitle . '" />';
		$html .= '<textarea id="mw-nexaboard-new-body" class="mw-nexaboard-body-input" '
			. 'placeholder="' . wfMessage( 'nexaboard-body-placeholder' )->escaped() . '"></textarea>';
		$html .= '<div class="mw-nexaboard-newmsg-actions">';
		$html .= '<button id="mw-nexaboard-submit" data-board-user="' . htmlspecialchars( $boardUserName ) . '">'
			. wfMessage( 'nexaboard-post-btn' )->escaped() . '</button>';
		$html .= ' <button id="mw-nexaboard-cancel">'
			. wfMessage( 'nexaboard-cancel-btn' )->escaped() . '</button>';
		$html .= '</div></div></div>';

		return $html;
	}

	private function renderAvatar( array $data, string $size = 'medium' ): string {
		$cls = 'mw-nexaboard-avatar-' . $size;
		if ( $data['hasAvatar'] ) {
			return Html::element( 'img', [
				'src'   => $data['url'],
				'alt'   => $data['initial'],
				'class' => 'mw-nexaboard-avatar ' . $cls,
			] );
		}
		return '<div class="mw-nexaboard-avatar-fallback ' . $cls . '" '
			. 'style="background-color:' . htmlspecialchars( $data['color'] ) . '">'
			. htmlspecialchars( $data['initial'] ) . '</div>';
	}

	private function parseWikitext( string $wikitext ): string {
		$services = MediaWikiServices::getInstance();
		$parser   = $services->getParserFactory()->create();
		$title    = $this->getPageTitle();
		$options  = ParserOptions::newFromContext( $this->getContext() );

		$parserOutput = $parser->parse( $wikitext, $title, $options );
		$html = $parserOutput->runOutputPipeline( $options, [
			'unwrap'                 => true,
			'allowTOC'               => false,
			'enableSectionEditLinks' => false,
			'deduplicateStyles'      => false,
		] )->getContentHolderText();

		return self::forceNoFollow( $html );
	}

	private static function forceNoFollow( string $html ): string {
		$out = preg_replace_callback(
			'#<a\b([^>]*)>#i',
			static function ( array $m ): string {
				$attrs = $m[1];

				if ( !preg_match( '/\bclass="[^"]*\bexternal\b/i', $attrs ) ) {
					return $m[0];
				}

				if ( preg_match( '/\brel="([^"]*)"/i', $attrs, $rel ) ) {
					if ( preg_match( '/\bnofollow\b/i', $rel[1] ) ) {
						return $m[0];
					}
					return str_replace( $rel[0], 'rel="' . $rel[1] . ' nofollow"', $m[0] );
				}

				return '<a rel="nofollow"' . $attrs . '>';
			},
			$html
		);

		return $out ?? $html;
	}

	private function formatTimestamp( string $ts ): string {
		try {
			return $this->getLanguage()->userTimeAndDate( $ts, $this->getUser() );
		} catch ( \Exception ) {
			return '';
		}
	}

	private function fallbackAvatarData( string $username ): array {
		return [
			'url'       => null,
			'initial'   => mb_strtoupper( mb_substr( $username, 0, 1 ) ),
			'color'     => AvatarHelper::generateColor( $username ),
			'hasAvatar' => false,
		];
	}

	public function getGroupName(): string { return 'users'; }
	protected function getDisplayFormat(): string { return 'html'; }
}
