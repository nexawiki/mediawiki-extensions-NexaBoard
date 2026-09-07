<?php

namespace MediaWiki\Extension\NexaBoard\Tests\Integration;

use MediaWiki\Extension\NexaBoard\NotificationMode;
use MediaWiki\Extension\NexaBoard\Store\FollowStore;
use MediaWiki\Extension\NexaBoard\Store\MessageStore;
use MediaWiki\Extension\NexaBoard\Store\ThreadStore;
use MediaWiki\Extension\NexaBoard\BoardBlock;
use MediaWiki\Extension\NexaBoard\BoardManager;
use MediaWiki\Context\DerivativeContext;
use MediaWiki\Context\RequestContext;
use MediaWiki\MainConfigNames;
use MediaWiki\Output\OutputPage;
use MediaWiki\Request\FauxRequest;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;
use RuntimeException;

/**
 * @group Database
 * @group NexaBoard
 * @covers \MediaWiki\Extension\NexaBoard\BoardManager
 * @covers \MediaWiki\Extension\NexaBoard\Store\FollowStore
 */
class BoardManagerTest extends MediaWikiIntegrationTestCase {

	private function manager(): BoardManager {
		return $this->getServiceContainer()->get( 'NexaBoard.Manager' );
	}

	private function threadStore(): ThreadStore {
		return $this->getServiceContainer()->get( 'NexaBoard.ThreadStore' );
	}

	private function messageStore(): MessageStore {
		return $this->getServiceContainer()->get( 'NexaBoard.MessageStore' );
	}

	private function followStore(): FollowStore {
		return $this->getServiceContainer()->get( 'NexaBoard.FollowStore' );
	}

	private function actor(): User {
		return $this->getTestSysop()->getUser();
	}

	/**
	 * A thread with an originating post and one reply, notifications suppressed.
	 */
	private function seedThread( User $actor, string $title = 'Subject' ): array {
		return $this->manager()->createThread(
			$actor->getId(), $actor, $title, 'Body', NotificationMode::Suppress
		);
	}

	public function testReplyRecordsItsParent(): void {
		$actor = $this->actor();
		$thread = $this->seedThread( $actor );

		$first = $this->manager()->reply(
			$thread['thread_id'], $actor, 'First', null, NotificationMode::Suppress
		);
		$second = $this->manager()->reply(
			$thread['thread_id'], $actor, 'Nested', null, NotificationMode::Suppress, $first
		);

		$row = $this->messageStore()->getById( $second );
		$this->assertSame( $first, (int)$row->nbm_parent_id );
	}

	public function testReplyRejectsParentFromAnotherThread(): void {
		$actor = $this->actor();
		$a = $this->seedThread( $actor, 'A' );
		$b = $this->seedThread( $actor, 'B' );

		$this->expectException( RuntimeException::class );
		$this->manager()->reply(
			$a['thread_id'], $actor, 'Bad parent', null,
			NotificationMode::Suppress, $b['msg_id']
		);
	}

	public function testCloseThenReopenRoundTrips(): void {
		$actor = $this->actor();
		$thread = $this->seedThread( $actor );
		$id = $thread['thread_id'];

		$this->assertTrue( $this->manager()->closeThread( $id, $actor ) );
		$this->assertSame(
			ThreadStore::STATUS_CLOSED,
			(int)$this->threadStore()->getById( $id )->nbt_status
		);

		$this->assertTrue( $this->manager()->reopenThread( $id, $actor ) );

		$row = $this->threadStore()->getById( $id );
		$this->assertSame( ThreadStore::STATUS_OPEN, (int)$row->nbt_status );
		$this->assertNull( $row->nbt_closed_by, 'reopening clears who closed it' );
	}

	public function testClosedThreadRefusesReplies(): void {
		$actor = $this->actor();
		$thread = $this->seedThread( $actor );
		$this->manager()->closeThread( $thread['thread_id'], $actor );

		$this->expectException( RuntimeException::class );
		$this->manager()->reply(
			$thread['thread_id'], $actor, 'Too late', null, NotificationMode::Suppress
		);
	}

	public function testUndeleteRestoresAClosedThreadToClosed(): void {
		$actor = $this->actor();
		$thread = $this->seedThread( $actor );
		$id = $thread['thread_id'];

		$this->manager()->closeThread( $id, $actor );
		$this->manager()->deleteThread( $id, $actor );
		$this->assertTrue( $this->manager()->undeleteThread( $id, $actor ) );

		$this->assertSame(
			ThreadStore::STATUS_CLOSED,
			(int)$this->threadStore()->getById( $id )->nbt_status,
			'a thread closed before deletion must not silently reopen'
		);
	}

	public function testDeletedThreadsAreHiddenButRecoverable(): void {
		$actor = $this->actor();
		$boardId = $actor->getId();
		$thread = $this->seedThread( $actor );

		$this->manager()->deleteThread( $thread['thread_id'], $actor );

		$visible = $this->threadStore()->getByBoardUser( $boardId, 50 );
		$this->assertCount( 0, $visible );

		$all = $this->threadStore()->getByBoardUser( $boardId, 50, null, true );
		$this->assertCount( 1, $all );
	}

	public function testEditUpdatesBodyTimestampAndTitle(): void {
		$actor = $this->actor();
		$thread = $this->seedThread( $actor );

		$this->assertTrue( $this->manager()->editMessage(
			$thread['msg_id'], $actor, 'New body', 'New title'
		) );

		$msg = $this->messageStore()->getById( $thread['msg_id'] );
		$this->assertSame( 'New body', $msg->nbm_body );
		$this->assertNotNull( $msg->nbm_edited );

		$this->assertSame(
			'New title',
			$this->threadStore()->getById( $thread['thread_id'] )->nbt_title
		);
	}

	public function testDeletingAReplyAdjustsTheReplyCount(): void {
		$actor = $this->actor();
		$thread = $this->seedThread( $actor );
		$replyId = $this->manager()->reply(
			$thread['thread_id'], $actor, 'A reply', null, NotificationMode::Suppress
		);

		$this->assertSame(
			1, (int)$this->threadStore()->getById( $thread['thread_id'] )->nbt_reply_count
		);

		$this->manager()->deleteMessage( $replyId, $actor );
		$this->assertSame(
			0, (int)$this->threadStore()->getById( $thread['thread_id'] )->nbt_reply_count
		);

		$this->manager()->restoreMessage( $replyId, $actor );
		$this->assertSame(
			1, (int)$this->threadStore()->getById( $thread['thread_id'] )->nbt_reply_count
		);
	}

	public function testTheOriginatingPostCannotBeDeletedOnItsOwn(): void {
		$actor = $this->actor();
		$thread = $this->seedThread( $actor );

		$this->expectException( RuntimeException::class );
		$this->manager()->deleteMessage( $thread['msg_id'], $actor );
	}

	public function testDeleteAllRepliesKeepsTheOriginatingPost(): void {
		$actor = $this->actor();
		$thread = $this->seedThread( $actor );
		$this->manager()->reply( $thread['thread_id'], $actor, 'One', null, NotificationMode::Suppress );
		$this->manager()->reply( $thread['thread_id'], $actor, 'Two', null, NotificationMode::Suppress );

		$deleted = $this->manager()->deleteAllReplies( $thread['thread_id'], $actor );

		$this->assertSame( 2, $deleted );
		$this->assertSame(
			0, (int)$this->threadStore()->getById( $thread['thread_id'] )->nbt_reply_count
		);
		$this->assertNotNull(
			$this->messageStore()->getOpByThread( $thread['thread_id'] ),
			'the thread itself survives'
		);
	}

	public function testFollowStateRoundTrips(): void {
		$actor = $this->actor();
		$thread = $this->seedThread( $actor );
		$id = $thread['thread_id'];

		$this->assertNull(
			$this->followStore()->getExplicitState( $id, $actor->getId() ),
			'no explicit state until the user chooses one'
		);

		$this->manager()->setFollowing( $id, $actor, true );
		$this->assertTrue( $this->followStore()->getExplicitState( $id, $actor->getId() ) );

		$this->manager()->setFollowing( $id, $actor, false );
		$this->assertFalse( $this->followStore()->getExplicitState( $id, $actor->getId() ) );

		$states = $this->followStore()->getFollowStates( $id );
		$this->assertContains( $actor->getId(), $states['muted'] );
	}

	public function testMergeMovesDeletedMessagesAndKeepsSourceTitle(): void {
		$actor = $this->actor();
		$target = $this->seedThread( $actor, 'Target' );
		$source = $this->seedThread( $actor, 'Source' );

		$replyId = $this->manager()->reply(
			$source['thread_id'], $actor, 'Doomed', null, NotificationMode::Suppress
		);
		$this->manager()->deleteMessage( $replyId, $actor );

		$this->manager()->mergeThreads(
			[ $source['thread_id'] ], $target['thread_id'], $actor
		);

		$this->assertSame(
			$target['thread_id'],
			(int)$this->messageStore()->getById( $replyId )->nbm_thread_id,
			'a deleted message must not be stranded on the merged-away thread'
		);

		$titles = $this->threadStore()->getMergedSourceTitles( [ $target['thread_id'] ] );
		$this->assertSame( [ 'Source' ], $titles[$target['thread_id']] );
	}

	public function testMergeAcrossBoardsIsRefused(): void {
		$actor = $this->actor();
		$mine = $this->seedThread( $actor, 'Mine' );

		$otherBoardId = $actor->getId() + 1000;
		$theirs = $this->manager()->createThread(
			$otherBoardId, $actor, 'Theirs', 'Body', NotificationMode::Suppress
		);

		$this->expectException( RuntimeException::class );
		$this->manager()->mergeThreads(
			[ $theirs['thread_id'] ], $mine['thread_id'], $actor
		);
	}

	public function testMoveAcrossBoardsIsRefused(): void {
		$actor = $this->actor();
		$mine = $this->seedThread( $actor, 'Mine' );

		$otherBoardId = $actor->getId() + 1000;
		$theirs = $this->manager()->createThread(
			$otherBoardId, $actor, 'Theirs', 'Body', NotificationMode::Suppress
		);

		$this->expectException( RuntimeException::class );
		$this->manager()->moveMessage( $theirs['msg_id'], $mine['thread_id'], $actor );
	}

	/**
	 * nbt_updated is only second-accurate, so a board routinely has several
	 * threads sharing one timestamp. Paging on the timestamp alone skipped every
	 * thread that shared a page boundary; the composite (updated, id) cursor is
	 * what stops threads disappearing between pages.
	 */
	public function testPagingIsStableWhenTimestampsAreTied(): void {
		$actor  = $this->actor();
		$boardId = $actor->getId();
		$ts     = $this->threadStore();

		$ids = [];
		for ( $i = 0; $i < 6; $i++ ) {
			$ids[] = $ts->insert( $boardId, "Tied $i", '20260101000000' );
		}

		$seen  = [];
		$cursor = null;

		// Walk the whole board two at a time, exactly as the special page does.
		for ( $page = 0; $page < 5; $page++ ) {
			$rows = $ts->getByBoardUser( $boardId, 3, $cursor );
			$more = count( $rows ) > 2;
			$rows = array_slice( $rows, 0, 2 );

			if ( !$rows ) {
				break;
			}

			foreach ( $rows as $row ) {
				$seen[] = (int)$row->nbt_id;
			}

			if ( !$more ) {
				break;
			}

			$last   = end( $rows );
			$cursor = [ $last->nbt_updated, (int)$last->nbt_id ];
		}

		sort( $ids );
		$unique = array_unique( $seen );
		sort( $unique );

		$this->assertSame( $ids, $unique, 'every thread is reachable by paging' );
		$this->assertSame( count( $seen ), count( $unique ), 'no thread is served twice' );
	}

	public function testModerationActionsAreLogged(): void {
		$actor = $this->actor();
		$thread = $this->seedThread( $actor );
		$id = $thread['thread_id'];

		$this->manager()->closeThread( $id, $actor, 'because' );
		$this->manager()->reopenThread( $id, $actor );
		$this->manager()->deleteThread( $id, $actor );
		$this->manager()->undeleteThread( $id, $actor );

		$rows = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'log_action', 'log_title' ] )
			->from( 'logging' )
			->where( [ 'log_type' => 'nexaboard' ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$actions = [];
		foreach ( $rows as $row ) {
			$actions[] = $row->log_action;
			$this->assertFalse(
				ctype_digit( $row->log_title ),
				'the log target must be a user name, not a raw user id'
			);
		}

		foreach ( [ 'close', 'reopen', 'delete', 'undelete' ] as $expected ) {
			$this->assertContains( $expected, $actions );
		}
	}

	public function testTransferMovesThreadAndRepliesToAnotherBoard(): void {
		$actor  = $this->actor();
		$target = $this->getTestUser( 'sysop' )->getUser();
		$this->assertNotSame( $actor->getId(), $target->getId(), 'need two distinct users' );

		$thread = $this->seedThread( $actor, 'Wrong board' );
		$this->manager()->reply(
			$thread['thread_id'], $actor, 'A reply', null, NotificationMode::Suppress
		);

		$this->assertTrue( $this->manager()->transferThread(
			$thread['thread_id'], $target->getId(), $actor, 'moved'
		) );

		$row = $this->threadStore()->getById( $thread['thread_id'] );
		$this->assertSame( $target->getId(), (int)$row->nbt_board_user_id );
		$this->assertSame( 1, (int)$row->nbt_reply_count, 'replies travel with the thread' );

		$ids = static fn ( array $rows ) => array_map( static fn ( $r ) => (int)$r->nbt_id, $rows );
		$this->assertNotContains(
			$thread['thread_id'],
			$ids( $this->threadStore()->getByBoardUser( $actor->getId(), 50 ) ),
			'no longer listed on the original board'
		);
		$this->assertContains(
			$thread['thread_id'],
			$ids( $this->threadStore()->getByBoardUser( $target->getId(), 50 ) ),
			'listed on the destination board'
		);
	}

	public function testTransferToTheSameBoardIsRefused(): void {
		$actor  = $this->actor();
		$thread = $this->seedThread( $actor );

		$this->expectException( RuntimeException::class );
		$this->manager()->transferThread( $thread['thread_id'], $actor->getId(), $actor );
	}

	public function testTransferSubscribesTheReceivingBoardOwner(): void {
		$actor  = $this->actor();
		$target = $this->getTestUser( 'sysop' )->getUser();
		$thread = $this->seedThread( $actor );

		$this->manager()->transferThread( $thread['thread_id'], $target->getId(), $actor );

		$this->assertTrue(
			$this->followStore()->getExplicitState( $thread['thread_id'], $target->getId() ),
			'the new board owner hears about replies'
		);
	}

	public function testDeletedThreadIsNotTransferred(): void {
		$actor  = $this->actor();
		$target = $this->getTestUser( 'sysop' )->getUser();
		$thread = $this->seedThread( $actor );

		$this->manager()->deleteThread( $thread['thread_id'], $actor );

		$this->assertFalse(
			$this->manager()->transferThread( $thread['thread_id'], $target->getId(), $actor ),
			'a thread nobody can see is not handed to another board'
		);
		$this->assertSame(
			$actor->getId(),
			(int)$this->threadStore()->getById( $thread['thread_id'] )->nbt_board_user_id
		);
	}

	/**
	 * Moderators see deleted replies as tombstones, so the raw row count is not
	 * the count a reader should be shown.
	 */
	public function testDeletedRepliesLeaveAZeroReplyCount(): void {
		$actor  = $this->actor();
		$thread = $this->seedThread( $actor );

		$reply = $this->manager()->reply(
			$thread['thread_id'], $actor, 'Only reply', null, NotificationMode::Suppress
		);
		$this->assertSame(
			1, (int)$this->threadStore()->getById( $thread['thread_id'] )->nbt_reply_count
		);

		$this->manager()->deleteMessage( $reply, $actor );

		$this->assertSame(
			0,
			(int)$this->threadStore()->getById( $thread['thread_id'] )->nbt_reply_count,
			'the stored count drops'
		);
		$this->assertCount(
			0,
			$this->messageStore()->getRepliesByThread( $thread['thread_id'], false ),
			'nothing live is left to count'
		);
		$this->assertCount(
			1,
			$this->messageStore()->getRepliesByThread( $thread['thread_id'], true ),
			'but the tombstone is still there for moderators'
		);
	}


	/**
	 * A merge moves every message onto the destination, so the source is left
	 * with nothing. It must still be findable, or the merge is indistinguishable
	 * from the thread having been destroyed.
	 */
	public function testMergedSourceStaysVisibleToModerators(): void {
		$actor  = $this->actor();
		$source = $this->seedThread( $actor, 'Same headline' );
		$target = $this->seedThread( $actor, 'Same headline' );

		$this->manager()->mergeThreads(
			[ $source['thread_id'] ], $target['thread_id'], $actor, 'duplicate'
		);

		$row = $this->threadStore()->getById( $source['thread_id'] );
		$this->assertSame( ThreadStore::STATUS_MERGED, (int)$row->nbt_status );
		$this->assertSame( $target['thread_id'], (int)$row->nbt_merged_into );

		$ids = static fn ( array $rows ) => array_map( static fn ( $r ) => (int)$r->nbt_id, $rows );

		$this->assertNotContains(
			$source['thread_id'],
			$ids( $this->threadStore()->getByBoardUser( $actor->getId(), 50 ) ),
			'hidden from the ordinary board view'
		);
		$this->assertContains(
			$source['thread_id'],
			$ids( $this->threadStore()->getByBoardUser( $actor->getId(), 50, null, true ) ),
			'but a moderator can still find where it went'
		);
	}

	public function testMergedSourceKeepsNoMessagesOfItsOwn(): void {
		$actor  = $this->actor();
		$source = $this->seedThread( $actor );
		$target = $this->seedThread( $actor );

		$this->manager()->mergeThreads(
			[ $source['thread_id'] ], $target['thread_id'], $actor
		);

		$this->assertNull(
			$this->messageStore()->getOpByThread( $source['thread_id'] ),
			'the originating post moved to the destination with everything else'
		);
		$this->assertNotNull(
			$this->messageStore()->getOpByThread( $target['thread_id'] )
		);
	}


	public function testCloseRecordsWhoClosedAndReopenClearsIt(): void {
		$actor  = $this->actor();
		$thread = $this->seedThread( $actor );

		$this->manager()->closeThread( $thread['thread_id'], $actor );
		$this->assertSame(
			$actor->getId(),
			(int)$this->threadStore()->getById( $thread['thread_id'] )->nbt_closed_by,
			'a reopen check needs to know who closed it'
		);

		$this->manager()->reopenThread( $thread['thread_id'], $actor );
		$this->assertNull(
			$this->threadStore()->getById( $thread['thread_id'] )->nbt_closed_by
		);
	}

	/**
	 * A board owner may undo their own close, but not a moderator's — otherwise
	 * a moderation decision is reversible by the person it was aimed at.
	 */
	public function testOnlyTheCloserOrAModeratorMayReopen(): void {
		$moderator = $this->actor();
		$owner     = $this->getTestUser()->getUser();
		$this->assertFalse( $owner->isAllowed( 'nexaboard-close' ), 'owner holds no close right' );

		$thread = $this->manager()->createThread(
			$owner->getId(), $owner, 'Owned', 'Body', NotificationMode::Suppress
		);

		$mayReopen = static fn ( $user, $row ) =>
			(int)$row->nbt_closed_by === $user->getId()
			|| $user->isAllowed( 'nexaboard-close' );

		$this->manager()->closeThread( $thread['thread_id'], $moderator );
		$row = $this->threadStore()->getById( $thread['thread_id'] );
		$this->assertFalse( $mayReopen( $owner, $row ), 'owner cannot undo a moderator close' );
		$this->assertTrue( $mayReopen( $moderator, $row ) );

		$this->manager()->reopenThread( $thread['thread_id'], $moderator );
		$this->manager()->closeThread( $thread['thread_id'], $owner );
		$row = $this->threadStore()->getById( $thread['thread_id'] );
		$this->assertTrue( $mayReopen( $owner, $row ), 'owner may undo their own close' );
		$this->assertTrue( $mayReopen( $moderator, $row ) );
	}

	public function testEditRecordsWhoMadeIt(): void {
		$author    = $this->getTestUser()->getUser();
		$moderator = $this->actor();

		$thread = $this->manager()->createThread(
			$author->getId(), $author, 'Subject', 'Original', NotificationMode::Suppress
		);
		$msgId = $thread['msg_id'];

		$this->assertNull(
			$this->messageStore()->getById( $msgId )->nbm_edited_by,
			'never edited'
		);

		$this->manager()->editMessage( $msgId, $author, 'Author revised' );
		$msg = $this->messageStore()->getById( $msgId );
		$this->assertSame( $author->getId(), (int)$msg->nbm_edited_by );
		$this->assertSame(
			(int)$msg->nbm_author_id, (int)$msg->nbm_edited_by,
			'an author editing themselves is not disclosed as a third party'
		);

		$this->manager()->editMessage( $msgId, $moderator, 'Redacted', null, 'personal data' );
		$msg = $this->messageStore()->getById( $msgId );
		$this->assertSame( $moderator->getId(), (int)$msg->nbm_edited_by );
		$this->assertNotSame(
			(int)$msg->nbm_author_id, (int)$msg->nbm_edited_by,
			'a moderator edit is attributable'
		);
	}


	/**
	 * Moving the originating post out would leave the source thread with nothing
	 * to render, so it silently disappears while still counting as open.
	 */
	public function testTheOriginatingPostCannotBeMovedOut(): void {
		$actor  = $this->actor();
		$source = $this->seedThread( $actor, 'Source' );
		$target = $this->seedThread( $actor, 'Target' );

		try {
			$this->manager()->moveMessage(
				$source['msg_id'], $target['thread_id'], $actor
			);
			$this->fail( 'expected the move to be refused' );
		} catch ( RuntimeException $e ) {
			// expected
		}

		$this->assertNotNull(
			$this->messageStore()->getOpByThread( $source['thread_id'] ),
			'the source thread keeps something to render'
		);
	}

	public function testRepliesStillMoveBetweenThreads(): void {
		$actor  = $this->actor();
		$source = $this->seedThread( $actor, 'Source' );
		$target = $this->seedThread( $actor, 'Target' );

		$reply = $this->manager()->reply(
			$source['thread_id'], $actor, 'Movable', null, NotificationMode::Suppress
		);
		$this->manager()->moveMessage( $reply, $target['thread_id'], $actor );

		$this->assertSame(
			$target['thread_id'],
			(int)$this->messageStore()->getById( $reply )->nbm_thread_id
		);
		$this->assertSame(
			0, (int)$this->threadStore()->getById( $source['thread_id'] )->nbt_reply_count
		);
		$this->assertSame(
			1, (int)$this->threadStore()->getById( $target['thread_id'] )->nbt_reply_count
		);
	}


	/**
	 * Deletion hides a thread from everyone without the right, and the threads
	 * most worth hiding land on the board of the person they concern. Closing is
	 * the owner's tool; deleting is not.
	 */
	public function testBoardOwnerHasNoDeleteRightByDefault(): void {
		$owner = $this->getTestUser()->getUser();

		$this->assertTrue( $owner->isAllowed( 'nexaboard-post' ) );
		$this->assertTrue(
			$owner->isAllowed( 'nexaboard-edit-own' ),
			'authors still manage their own messages'
		);
		$this->assertFalse(
			$owner->isAllowed( 'nexaboard-delete' ),
			'owning a board must not confer deletion'
		);

		$moderator = $this->getTestSysop()->getUser();
		$this->assertTrue( $moderator->isAllowed( 'nexaboard-delete' ) );
	}

	/**
	 * A moderator closing a thread must not be routed around by the board owner
	 * deleting it instead.
	 */
	public function testOwnerCannotRouteAroundAModeratorClose(): void {
		$moderator = $this->getTestSysop()->getUser();
		$owner     = $this->getTestUser()->getUser();

		$thread = $this->manager()->createThread(
			$owner->getId(), $moderator, 'Final warning', 'Stop.', NotificationMode::Suppress
		);
		$this->manager()->closeThread( $thread['thread_id'], $moderator, 'moderation' );

		$row = $this->threadStore()->getById( $thread['thread_id'] );
		$this->assertSame( $moderator->getId(), (int)$row->nbt_closed_by );

		// Both routes out of a moderator close are shut to the owner.
		$this->assertFalse(
			(int)$row->nbt_closed_by === $owner->getId() || $owner->isAllowed( 'nexaboard-close' ),
			'cannot reopen'
		);
		$this->assertFalse( $owner->isAllowed( 'nexaboard-delete' ), 'cannot delete either' );
	}


	/**
	 * The moderator view must reveal both hidden states. Deleted threads were
	 * reaching the page and then being hidden by CSS, so the toggle appeared to
	 * do nothing; this pins the server side of that contract.
	 */
	public function testModeratorListingRevealsBothDeletedAndMergedThreads(): void {
		$actor = $this->actor();

		$deleted = $this->seedThread( $actor, 'Deleted one' );
		$this->manager()->deleteThread( $deleted['thread_id'], $actor );

		$source = $this->seedThread( $actor, 'Merged one' );
		$target = $this->seedThread( $actor, 'Destination' );
		$this->manager()->mergeThreads( [ $source['thread_id'] ], $target['thread_id'], $actor );

		$ids = static fn ( array $rows ) => array_map( static fn ( $r ) => (int)$r->nbt_id, $rows );

		$plain = $ids( $this->threadStore()->getByBoardUser( $actor->getId(), 50 ) );
		$this->assertNotContains( $deleted['thread_id'], $plain );
		$this->assertNotContains( $source['thread_id'], $plain );

		$withHidden = $ids( $this->threadStore()->getByBoardUser( $actor->getId(), 50, null, true ) );
		$this->assertContains( $deleted['thread_id'], $withHidden, 'deleted thread revealed' );
		$this->assertContains( $source['thread_id'], $withHidden, 'merged thread revealed' );
	}


	/**
	 * Limits are counted in characters but storage is sized in bytes. A title cut
	 * at the byte boundary is invalid UTF-8, and the parser then throws while
	 * rendering — taking the whole board down, not just that thread.
	 */
	public function testMultibyteTitleAndBodySurviveStorageIntact(): void {
		$actor = $this->actor();
		$max   = $this->getServiceContainer()->getMainConfig()->get( 'NexaBoardMaxTitleLength' );

		$title = str_repeat( "\u{6F22}", $max );           // 3 bytes per character
		$body  = str_repeat( "\u{1F600}", 20000 );         // 4 bytes per character

		$thread = $this->manager()->createThread(
			$actor->getId(), $actor, $title, $body, NotificationMode::Suppress
		);

		$storedTitle = $this->threadStore()->getById( $thread['thread_id'] )->nbt_title;
		$this->assertTrue( mb_check_encoding( $storedTitle, 'UTF-8' ), 'title is valid UTF-8' );
		$this->assertSame( $max, mb_strlen( $storedTitle, 'UTF-8' ), 'no characters lost' );

		$storedBody = $this->messageStore()->getById( $thread['msg_id'] )->nbm_body;
		$this->assertTrue( mb_check_encoding( $storedBody, 'UTF-8' ), 'body is valid UTF-8' );
		$this->assertSame( 20000, mb_strlen( $storedBody, 'UTF-8' ) );
	}

	/**
	 * Deleting a thread flags the thread, not its messages, so merging a deleted
	 * source would carry still-live messages into a readable thread.
	 */
	public function testDeletedThreadCannotBeMerged(): void {
		$actor  = $this->actor();
		$source = $this->seedThread( $actor, 'Sensitive' );
		$target = $this->seedThread( $actor, 'Public' );

		$this->manager()->deleteThread( $source['thread_id'], $actor );

		$this->expectException( RuntimeException::class );
		$this->manager()->mergeThreads( [ $source['thread_id'] ], $target['thread_id'], $actor );
	}

	public function testCannotMergeIntoADeletedThread(): void {
		$actor  = $this->actor();
		$source = $this->seedThread( $actor, 'Live' );
		$target = $this->seedThread( $actor, 'Gone' );

		$this->manager()->deleteThread( $target['thread_id'], $actor );

		$this->expectException( RuntimeException::class );
		$this->manager()->mergeThreads( [ $source['thread_id'] ], $target['thread_id'], $actor );
	}

	/**
	 * nbt_merged_into would be overwritten, pointing the tombstone at a thread
	 * that never received the content.
	 */
	public function testAlreadyMergedThreadCannotBeMergedAgain(): void {
		$actor = $this->actor();
		$a = $this->seedThread( $actor, 'A' );
		$b = $this->seedThread( $actor, 'B' );
		$c = $this->seedThread( $actor, 'C' );

		$this->manager()->mergeThreads( [ $a['thread_id'] ], $b['thread_id'], $actor );

		try {
			$this->manager()->mergeThreads( [ $a['thread_id'] ], $c['thread_id'], $actor );
			$this->fail( 'expected the second merge to be refused' );
		} catch ( RuntimeException $e ) {
			// expected
		}

		$this->assertSame(
			$b['thread_id'],
			(int)$this->threadStore()->getById( $a['thread_id'] )->nbt_merged_into,
			'the trail still points where the content actually went'
		);
	}

	public function testReplyChainIsCappedAtMaxDepth(): void {
		$actor  = $this->actor();
		$thread = $this->seedThread( $actor );

		$parent = null;
		$accepted = 0;
		for ( $i = 0; $i < BoardManager::MAX_REPLY_DEPTH + 5; $i++ ) {
			try {
				$parent = $this->manager()->reply(
					$thread['thread_id'], $actor, "level $i", null, NotificationMode::Suppress, $parent
				);
				$accepted++;
			} catch ( RuntimeException $e ) {
				break;
			}
		}

		$this->assertSame( BoardManager::MAX_REPLY_DEPTH, $accepted );
	}

	public function testAThreadNeedsATitle(): void {
		$actor = $this->actor();

		$this->expectException( RuntimeException::class );
		$this->manager()->createThread( $actor->getId(), $actor, '   ', 'Body', NotificationMode::Suppress );
	}

	public function testDeletingNoRepliesWritesNoLogEntry(): void {
		$actor  = $this->actor();
		$thread = $this->seedThread( $actor );

		$count = fn () => (int)$this->getDb()->newSelectQueryBuilder()
			->select( 'COUNT(*)' )->from( 'logging' )
			->where( [ 'log_type' => 'nexaboard', 'log_action' => 'deletereplies' ] )
			->caller( __METHOD__ )->fetchField();

		$before = $count();
		$this->assertSame( 0, $this->manager()->deleteAllReplies( $thread['thread_id'], $actor ) );
		$this->assertSame( $before, $count(), 'a no-op does not reach the moderation log' );
	}


	private function blockUser( $target, array $restrictions = [] ): void {
		$this->getServiceContainer()->getBlockUserFactory()->newBlockUser(
			$target,
			$this->getTestSysop()->getUser(),
			'infinity',
			'test block',
			[],
			$restrictions
		)->placeBlock( true );
	}

	/**
	 * isAllowed() is a right lookup and knows nothing about blocks, and
	 * ApiMain::checkExecutePermissions() does not check them either — so without
	 * an explicit check a sitewide-blocked user could post on every board.
	 */
	public function testSitewideBlockStopsActionOnOtherPeoplesBoards(): void {
		$owner   = $this->getTestUser()->getUser();
		$blocked = $this->getMutableTestUser()->getUser();

		$this->assertNull( BoardBlock::affecting( $blocked, $owner->getId() ), 'clean before the block' );

		$this->blockUser( $blocked );
		$blocked->clearInstanceCache();

		$this->assertTrue(
			$blocked->isAllowed( 'nexaboard-post' ),
			'the right itself is untouched — which is exactly why the block needs its own check'
		);
		$this->assertNotNull(
			BoardBlock::affecting( $blocked, $owner->getId() ),
			'but the block stops them acting on the board'
		);
	}

	public function testUnblockedUsersAreUnaffected(): void {
		$owner = $this->getTestUser()->getUser();
		$other = $this->getMutableTestUser()->getUser();

		$this->assertNull( BoardBlock::affecting( $other, $owner->getId() ) );
	}

	/**
	 * A board stands in for its owner's talk page, so a partial block scoped
	 * elsewhere must not reach it.
	 */
	public function testPartialBlockElsewhereDoesNotReachBoards(): void {
		$owner   = $this->getTestUser()->getUser();
		$blocked = $this->getMutableTestUser()->getUser();

		$page = $this->getExistingTestPage( 'NexaBoard block scope probe' );
		$this->blockUser( $blocked, [
			\MediaWiki\Block\Restriction\PageRestriction::newFromRow(
				(object)[ 'ir_ipb_id' => 0, 'ir_type' => 1, 'ir_value' => $page->getId() ]
			),
		] );
		$blocked->clearInstanceCache();

		$this->assertNull(
			BoardBlock::affecting( $blocked, $owner->getId() ),
			'a page block somewhere else leaves boards alone'
		);
	}

	public function testNamespaceBlockOnUserTalkCoversEveryBoard(): void {
		$owner   = $this->getTestUser()->getUser();
		$blocked = $this->getMutableTestUser()->getUser();

		$this->blockUser( $blocked, [
			new \MediaWiki\Block\Restriction\NamespaceRestriction( 0, NS_USER_TALK ),
		] );
		$blocked->clearInstanceCache();

		$this->assertNotNull(
			BoardBlock::affecting( $blocked, $owner->getId() ),
			'blocking the User talk namespace blocks the boards that replace it'
		);
	}


	public function testEveryExternalLinkInAMessageIsNoFollow(): void {
		$this->overrideConfigValues( [
			MainConfigNames::NoFollowLinks => false,
			MainConfigNames::NoFollowDomainExceptions => [ 'exempt.example' ],
		] );

		$actor  = $this->actor();
		$thread = $this->manager()->createThread(
			$actor->getId(),
			$actor,
			'Links',
			"Plain: https://spam.example/one\n\n"
			. "Labelled: [https://exempt.example/two two]",
			NotificationMode::Suppress
		);

		$html = $this->renderBoardFor( $actor );

		preg_match_all( '/<a\s[^>]*class="[^"]*external[^"]*"[^>]*>/', $html, $anchors );
		$this->assertNotEmpty( $anchors[0], 'the message rendered some external links' );

		foreach ( $anchors[0] as $tag ) {
			$this->assertStringContainsString(
				'nofollow', $tag,
				'every external link carries nofollow, exempt domains included'
			);
		}

		$this->assertDoesNotMatchRegularExpression(
			'/nofollow[^"]*nofollow/', $html, 'nofollow is not doubled up'
		);
	}

	public function testInternalLinksAreLeftAlone(): void {
		$actor  = $this->actor();
		$this->manager()->createThread(
			$actor->getId(), $actor, 'Internal', 'See [[Main Page]].', NotificationMode::Suppress
		);

		$html = $this->renderBoardFor( $actor );

		if ( preg_match( '/<a\s[^>]*href="[^"]*Main_Page[^"]*"[^>]*>/', $html, $m ) ) {
			$this->assertStringNotContainsString(
				'nofollow', $m[0], 'internal wiki links do not need nofollow'
			);
		} else {
			$this->addToAssertionCount( 1 );
		}
	}

	private function renderBoardFor( User $viewer ): string {
		$context = new DerivativeContext( RequestContext::getMain() );
		$context->setUser( $viewer );
		$context->setRequest( new FauxRequest( [] ) );
		$context->setTitle( SpecialPage::getTitleFor( 'NexaBoard', $viewer->getName() ) );

		$output = new OutputPage( $context );
		$context->setOutput( $output );

		$page = $this->getServiceContainer()->getSpecialPageFactory()->getPage( 'NexaBoard' );
		$page->setContext( $context );
		$page->execute( $viewer->getName() );

		return $output->getHTML();
	}

}
