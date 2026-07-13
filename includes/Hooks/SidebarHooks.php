<?php

namespace MediaWiki\Extension\TimeTracker\Hooks;

use MediaWiki\Hook\SidebarBeforeOutputHook;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\TitleFactory;

/**
 * Adds a "Time Tracker" sidebar section mirroring the Main Page tiles
 * (Edit time, Time reports, Browse), so those actions are reachable from any
 * page. The skin renders it as its own group under the heading message.
 */
class SidebarHooks implements SidebarBeforeOutputHook {

	public function __construct( private readonly TitleFactory $titleFactory ) {
	}

	/** @inheritDoc */
	public function onSidebarBeforeOutput( $skin, &$sidebar ): void {
		// Edit time / Time reports need a login, so only show the section to
		// logged-in users.
		if ( !$skin->getUser()->isRegistered() ) {
			return;
		}
		$browse = $this->titleFactory->newFromText( 'Customers' );
		$sidebar['timetracker-sidebar-heading'] = [
			[
				'msg' => 'timetracker-dash-edittime-label',
				'href' => SpecialPage::getTitleFor( 'EditTime' )->getLocalURL(),
				'id' => 't-tt-edittime',
			],
			[
				'msg' => 'timetracker-dash-reports-label',
				'href' => SpecialPage::getTitleFor( 'TimeReports' )->getLocalURL(),
				'id' => 't-tt-reports',
			],
			[
				'msg' => 'timetracker-dash-browse-label',
				'href' => $browse ? $browse->getLocalURL() : '#',
				'id' => 't-tt-browse',
			],
		];
	}
}
