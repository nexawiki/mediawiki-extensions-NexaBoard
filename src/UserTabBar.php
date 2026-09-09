<?php

namespace MediaWiki\Extension\NexaBoard;

use MediaWiki\Context\IContextSource;
use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;

class UserTabBar {

	public const TAB_ABOUT         = 'about';
	public const TAB_BOARD         = 'nexaboard';
	public const TAB_CONTRIBUTIONS = 'contributions';

	public static function render(
		string $username,
		string $activeTab,
		IContextSource $context
	): string {
		$userPage = Title::makeTitleSafe( NS_USER, $username );
		if ( !$userPage ) {
			return '';
		}

		$tabs = [
			self::TAB_ABOUT => [
				'label' => $context->msg( 'nexaboard-tab-about' )->text(),
				'href'  => $userPage->getFullURL(),
			],
			self::TAB_BOARD => [
				'label' => $context->msg( 'nexaboard-tab-nexaboard' )->text(),
				'href'  => SpecialPage::getTitleFor( 'NexaBoard', $username )->getFullURL(),
			],
			self::TAB_CONTRIBUTIONS => [
				'label' => $context->msg( 'nexaboard-tab-contributions' )->text(),
				'href'  => SpecialPage::getTitleFor( 'Contributions', $username )->getFullURL(),
			],
		];

		$inner = '';
		foreach ( $tabs as $key => $tab ) {
			$classes = 'mw-user-tab';
			if ( $key === $activeTab ) {
				$classes .= ' mw-user-tab--active';
			}
			$inner .= Html::element( 'a', [
				'href'  => $tab['href'],
				'class' => $classes,
			], $tab['label'] );
		}

		return '<div class="mw-user-tabbar">'
			. '<nav class="mw-user-tabbar-inner">'
			. $inner
			. '</nav>'
			. '</div>';
	}
}
