<?php

namespace MediaWiki\Extension\NexaBoard;

use MediaWiki\Extension\NexaBoard\Notifications\EchoBoardMentionPresentationModel;
use MediaWiki\Extension\NexaBoard\Notifications\EchoBoardPostPresentationModel;
use MediaWiki\Extension\NexaBoard\Notifications\EchoBoardReplyPresentationModel;
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\ContentHandler;
use MediaWiki\Installer\DatabaseUpdater;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\OutputPage;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Skin\Skin;
use MediaWiki\Skin\SkinTemplate;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use MediaWiki\User\User;

class Hooks {

	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ): void {

		$sqlDir = __DIR__ . '/../sql/' . $updater->getDB()->getType();
		// tables-generated.sql creates all three tables at once, so the first
		// call does the work and the other two find their table and skip.
		$updater->addExtensionTable( 'nexaboard_thread', "$sqlDir/tables-generated.sql" );
		$updater->addExtensionTable( 'nexaboard_message', "$sqlDir/tables-generated.sql" );
		$updater->addExtensionTable( 'nexaboard_follow', "$sqlDir/tables-generated.sql" );

		$updater->addExtensionField(
			'nexaboard_message',
			'nbm_edited_by',
			"$sqlDir/patch-nexaboard_message-nbm_edited_by.sql"
		);

		// Title and body were sized in bytes while their limits are counted in
		// characters, so multibyte text was truncated mid-character.
		$updater->modifyExtensionField(
			'nexaboard_thread',
			'nbt_title',
			"$sqlDir/patch-widen-title-and-body.sql"
		);

		$updater->addExtensionUpdate( [ [ self::class, 'createGuidelinesPage' ] ] );
	}

	public static function createGuidelinesPage( DatabaseUpdater $updater ): bool {
		$services = MediaWikiServices::getInstance();

		$pageName = wfMessage( 'nexaboard-guidelines-pagename' )->inContentLanguage()->text();
		$title = Title::makeTitleSafe( NS_PROJECT, $pageName );
		if ( !$title ) {
			$updater->output( "NexaBoard: invalid guidelines page name, skipping.\n" );
			return true;
		}

		$page = $services->getWikiPageFactory()->newFromTitle( $title );
		if ( $page->exists() ) {
			$updater->output( "NexaBoard: guidelines page [[{$title->getPrefixedText()}]] already exists, skipping.\n" );
			return true;
		}

		$sitename = $services->getMainConfig()->get( 'Sitename' );
		$text = wfMessage( 'nexaboard-guidelines-content', $sitename )->inContentLanguage()->text();
		$content = ContentHandler::makeContent( $text, $title, CONTENT_MODEL_WIKITEXT );

		$user = User::newSystemUser( 'MediaWiki default', [ 'steal' => true ] );
		$summary = wfMessage( 'nexaboard-guidelines-summary' )->inContentLanguage()->text();

		$pageUpdater = $page->newPageUpdater( $user );
		$pageUpdater->setContent( SlotRecord::MAIN, $content );
		$pageUpdater->saveRevision(
			CommentStoreComment::newUnsavedComment( $summary ),
			EDIT_NEW | EDIT_FORCE_BOT | EDIT_SUPPRESS_RC
		);

		if ( $pageUpdater->wasSuccessful() ) {
			$updater->output( "NexaBoard: created guidelines page [[{$title->getPrefixedText()}]].\n" );
		} else {
			$updater->output( "NexaBoard: failed to create guidelines page.\n" );
		}

		return true;
	}

	public static function onBeforePageDisplay( OutputPage $out, Skin $skin ): void {

		$out->addModules( 'ext.nexaboard.links' );

		$title = $out->getTitle();
		if ( !$title ) {
			return;
		}

		$username  = null;
		$activeTab = UserTabBar::TAB_ABOUT;

		if ( $title->getNamespace() === NS_USER ) {
			$parts     = explode( '/', $title->getText(), 2 );
			$username  = $parts[0];
			$activeTab = UserTabBar::TAB_ABOUT;
		}

		if ( $title->isSpecialPage() && $username === null ) {
			$spFactory      = MediaWikiServices::getInstance()->getSpecialPageFactory();
			[ $name, $sub ] = $spFactory->resolveAlias( $title->getDBkey() );

			if ( $name === 'Contributions' && $sub !== null && $sub !== '' ) {
				$username  = str_replace( '_', ' ', $sub );
				$activeTab = UserTabBar::TAB_CONTRIBUTIONS;
			}
		}

		if ( $username === null ) {
			return;
		}

		$user = MediaWikiServices::getInstance()->getUserFactory()->newFromName( $username );
		if ( !$user || $user->getId() === 0 ) {
			return;
		}

		$out->addModuleStyles( 'ext.nexaboard.styles' );
		$out->prependHTML(
			UserTabBar::render( $user->getName(), $activeTab, $out->getContext() )
		);
	}

	public static function onBeforeInitialize(
		&$title, $unused, OutputPage $output, $user, $request, $mediaWiki
	): void {
		if ( !( $title instanceof Title ) ) {
			return;
		}
		if ( $title->getNamespace() !== NS_USER_TALK ) {
			return;
		}

		$config = MediaWikiServices::getInstance()->getMainConfig();
		if ( !$config->get( 'NexaBoardRedirectUserTalk' ) ) {
			return;
		}

		// Only the base talk page stands in for the board. Subpages are archives
		// and other real content that exists nowhere else.
		if ( str_contains( $title->getText(), '/' ) ) {
			return;
		}

		if ( !self::shouldRedirectUserTalk( $request ) ) {
			return;
		}

		$boardTitle = SpecialPage::getTitleFor( 'NexaBoard', $title->getText() );
		$output->redirect( $boardTitle->getFullURL(), '302' );
	}

	/**
	 * Whether a user talk request should be bounced to the board.
	 *
	 * Only plain views are redirected. History, diffs, raw output and every
	 * other action stay reachable, otherwise pre-migration talk archives — and
	 * the ability to moderate them at all — become unreachable. ?redirect=no
	 * is the manual escape hatch, matching how core redirects behave.
	 */
	private static function shouldRedirectUserTalk( $request ): bool {
		if ( $request->getRawVal( 'redirect' ) === 'no' ) {
			return false;
		}

		if ( $request->getVal( 'action', 'view' ) !== 'view' ) {
			return false;
		}

		// A specific revision or comparison is history, not the live talk page.
		foreach ( [ 'oldid', 'diff', 'direction', 'curid' ] as $param ) {
			if ( $request->getCheck( $param ) ) {
				return false;
			}
		}

		return true;
	}

	public static function onSkinTemplateNavigationUniversal(
		SkinTemplate $skin, array &$links
	): void {
		$title = $skin->getRelevantTitle() ?: $skin->getTitle();
		if ( !$title ) {
			return;
		}

		$ns = $title->getNamespace();

		if ( $ns === NS_USER || $ns === NS_USER_TALK ) {
			$rootUser = explode( '/', $title->getText(), 2 )[0];
			$boardHref = SpecialPage::getTitleFor( 'NexaBoard', $rootUser )->getFullURL();

			$actual = $skin->getTitle();
			$onBoard = $actual && $actual->isSpecial( 'NexaBoard' );

			$boardTab = [
				'class' => $onBoard ? 'selected' : '',
				'text'  => wfMessage( 'nexaboard-tab-nexaboard' )->text(),
				'href'  => $boardHref,
				'id'    => 'ca-nexaboard',
			];

			foreach ( [ 'associated-pages', 'namespaces' ] as $menu ) {
				if ( isset( $links[$menu] ) ) {

					if ( $onBoard ) {
						foreach ( [ 'user', 'talk' ] as $key ) {
							if ( isset( $links[$menu][$key]['class'] ) ) {
								$links[$menu][$key]['class'] =
									trim( str_replace( 'selected', '', $links[$menu][$key]['class'] ) );
							}
						}
					}
					$links[$menu]['nexaboard'] = $boardTab;
				}
			}
		}

		self::addPersonalLinks( $skin, $links );
	}

	/**
	 * Put "Board" in the personal menu, and keep a working "Talk" entry.
	 *
	 * The board entry takes the slot the talk link used to occupy so the menu
	 * order is unchanged. The talk link is restored with redirect=no, because
	 * without it every route to your own talk page bounces straight back to the
	 * board.
	 */
	private static function addPersonalLinks( SkinTemplate $skin, array &$links ): void {
		$user = $skin->getUser();
		if ( !$user->isRegistered() ) {
			return;
		}

		$boardHref = SpecialPage::getTitleFor( 'NexaBoard', $user->getName() )->getFullURL();
		$talkPage = Title::makeTitleSafe( NS_USER_TALK, $user->getName() );

		$boardLink = [
			'text'  => wfMessage( 'nexaboard-personal-board' )->text(),
			'href'  => $boardHref,
			'id'    => 'pt-nexaboard',
			'icon'  => 'userTalk',
		];

		$talkLink = $talkPage ? [
			'text'  => wfMessage( 'nexaboard-personal-talk' )->text(),
			'href'  => $talkPage->getFullURL( [ 'redirect' => 'no' ] ),
			'id'    => 'pt-mytalk-page',
			'icon'  => 'article',
		] : null;

		foreach ( [ 'user-menu', 'user-page', 'personal' ] as $section ) {
			if ( !isset( $links[$section] ) ) {
				continue;
			}

			$section_links = $links[$section];
			$rebuilt       = [];

			// Rebuild in place so "Board" lands exactly where "Talk" used to be.
			$replaced = false;
			foreach ( $section_links as $key => $value ) {
				if ( $key === 'mytalk' ) {
					$rebuilt['nexaboard'] = $boardLink;
					if ( $talkLink ) {
						$rebuilt['mytalk-page'] = $talkLink;
					}
					$replaced = true;
					continue;
				}
				$rebuilt[$key] = $value;
			}

			if ( !$replaced ) {
				$rebuilt['nexaboard'] = $boardLink;
				if ( $talkLink ) {
					$rebuilt['mytalk-page'] = $talkLink;
				}
			}

			$links[$section] = $rebuilt;
		}
	}

	public static function onSetupAfterCache(): void {
		global $wgEchoNotifications, $wgEchoNotificationCategories, $wgEchoNotificationIcons;

		if ( !isset( $wgEchoNotifications ) ) {
			return;
		}

		$wgEchoNotificationCategories['nexaboard'] = [
			'priority' => 3,
			'tooltip'  => 'echo-pref-tooltip-nexaboard',
		];

		$wgEchoNotificationIcons['nexaboard'] = [
			'path' => 'NexaBoard/resources/images/nexaboard-icon.svg',
		];

		$locator = [ 'MediaWiki\\Extension\\Notifications\\UserLocator::locateFromEventExtra', [ 'recipients' ] ];

		$wgEchoNotifications['nexaboard-post'] = [
			'presentation-model' => EchoBoardPostPresentationModel::class,
			'user-locators'      => [ $locator ],
			'category'           => 'nexaboard',
			'group'              => 'interactive',
			'section'            => 'alert',
		];

		$wgEchoNotifications['nexaboard-reply'] = [
			'presentation-model' => EchoBoardReplyPresentationModel::class,
			'user-locators'      => [ $locator ],
			'category'           => 'nexaboard',
			'group'              => 'interactive',
			'section'            => 'alert',
		];

		$wgEchoNotifications['nexaboard-mention'] = [
			'presentation-model' => EchoBoardMentionPresentationModel::class,
			'user-locators'      => [ $locator ],
			'category'           => 'mention',
			'group'              => 'interactive',
			'section'            => 'alert',
		];
	}
}
