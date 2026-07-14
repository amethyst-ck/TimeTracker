<?php

namespace MediaWiki\Extension\TimeTracker\Hooks;

use MediaWiki\Hook\SidebarBeforeOutputHook;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\TitleFactory;

/**
 * Adds a "Time Tracker" sidebar section (My timesheet, Time reports, Browse) so
 * those actions are reachable from any page. The skin renders it as its own
 * group under the heading message.
 */
class SidebarHooks implements SidebarBeforeOutputHook {

	public function __construct( private readonly TitleFactory $titleFactory ) {
	}

	/** @inheritDoc */
	public function onSidebarBeforeOutput( $skin, &$sidebar ): void {
		// The timesheet/reports need a login, so only show the section to
		// logged-in users.
		$user = $skin->getUser();
		if ( !$user->isRegistered() ) {
			return;
		}
		$mine = $this->titleFactory->newFromText( 'User:' . $user->getName() );
		$browse = $this->titleFactory->newFromText( 'Customers' );
		$sidebar['timetracker-sidebar-heading'] = [
			[
				'msg' => 'timetracker-sidebar-mytime',
				'href' => $mine ? $mine->getLocalURL() : '#',
				'id' => 't-tt-mytime',
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
