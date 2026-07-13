<?php

namespace MediaWiki\Extension\TimeTracker\Special;

use MediaWiki\Extension\TimeTracker\Timer;
use MediaWiki\Extension\TimeTracker\TimerWidget;
use MediaWiki\Extension\TimeTracker\TimeTrackerQuery;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\TitleFactory;

/**
 * Special:TimeTracker — the Start/Stop stopwatch. Handles the request; the
 * widget itself (running card / start form) is rendered by {@see TimerWidget},
 * shared with the {{#timetracker_timer}} / {{#timetracker_jobtimer}} parser
 * functions. An admin may log
 * against another user. A `returnto` field lets a form on another page (the
 * inline widget) send the user back there after Start/Stop.
 */
class SpecialTimeTracker extends SpecialPage {

	use FormControls;

	public function __construct(
		private readonly Timer $timer,
		private readonly TimeTrackerQuery $query,
		private readonly TitleFactory $titleFactory,
		private readonly TimerWidget $widget
	) {
		parent::__construct( 'TimeTracker' );
	}

	/**
	 * Unlisted: timers are started from a job or task page. This page is only
	 * the POST handler for start/stop and shows the viewer's running timer.
	 * @inheritDoc
	 */
	public function isListed() {
		return false;
	}

	/** @inheritDoc */
	public function execute( $subPage ) {
		$this->setHeaders();
		$this->requireLogin();
		$out = $this->getOutput();

		$request = $this->getRequest();
		$csrf = $this->getContext()->getCsrfTokenSet();
		if ( $request->wasPosted() && $csrf->matchToken( $request->getVal( 'wpEditToken' ) ) ) {
			$query = [];
			if ( $request->getVal( 'action' ) === 'start' ) {
				$this->startTimer(
					trim( $request->getVal( 'customer', '' ) ),
					trim( $request->getVal( 'job', '' ) ),
					trim( $request->getVal( 'task', '' ) ),
					trim( $request->getVal( 'user', '' ) )
				);
			} elseif ( $request->getVal( 'action' ) === 'stop' ) {
				$added = $this->timer->stop( $this->getAuthority() );
				if ( $added !== null ) {
					$query['saved'] = (string)$added;
					// Reload the destination once so its SMW tables reflect the
					// entry data SMW commits just after this redirect is sent.
					$query['ttfresh'] = '1';
				}
			}
			$out->redirect( $this->returnDestination( trim( $request->getVal( 'returnto', '' ) ) )->getLocalURL( $query ) );
			return;
		}

		$out->addModules( 'ext.timetracker.timer' );
		$out->addModuleStyles( 'ext.timetracker.timer' );
		$out->addHTML( $this->widget->render( $this->getContext() ) );
	}

	/** Begin a timer, once the customer/job pair (and task, if any) is valid. */
	private function startTimer( string $customerId, string $jobId, string $taskId, string $userName ): void {
		if ( $customerId === '' || $jobId === ''
			|| !$this->query->jobBelongsToCustomer( $customerId, $jobId )
			|| ( $taskId !== '' && !$this->query->taskBelongsToJob( $jobId, $taskId ) )
		) {
			return;
		}
		$this->timer->start(
			$this->getAuthority(), $this->resolveTargetUser( $userName ), $customerId, $jobId, $taskId );
	}

	/** Where to redirect after Start/Stop: the posted returnto page, else here. */
	private function returnDestination( string $returnto ) {
		if ( $returnto !== '' ) {
			$title = $this->titleFactory->newFromText( $returnto );
			if ( $title && $title->exists() ) {
				return $title;
			}
		}
		return $this->getPageTitle();
	}

	/** @inheritDoc */
	protected function getGroupName() {
		return 'other';
	}
}
