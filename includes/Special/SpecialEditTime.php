<?php

namespace MediaWiki\Extension\TimeTracker\Special;

use DateTime;
use MediaWiki\Extension\TimeTracker\EntryStore;
use MediaWiki\Extension\TimeTracker\Timer;
use MediaWiki\Extension\TimeTracker\Timezone;
use MediaWiki\Extension\TimeTracker\TimeTrackerQuery;
use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\TitleFactory;

/**
 * Special:EditTime — correct a day's tracked time by hand. Pick a customer,
 * job and day; the form pre-fills that day's total and note and SETS them
 * on save (zero removes the entry). A time-tracker admin may edit another user.
 */
class SpecialEditTime extends SpecialPage {

	use FormControls;

	public function __construct(
		private readonly EntryStore $entryStore,
		private readonly TimeTrackerQuery $query,
		private readonly TitleFactory $titleFactory,
		private readonly Timezone $timezone,
		private readonly Timer $timer
	) {
		parent::__construct( 'EditTime' );
	}

	/** @inheritDoc */
	public function execute( $subPage ) {
		$this->setHeaders();
		$this->requireLogin();
		$out = $this->getOutput();
		$out->addModules( 'ext.timetracker.timer' );
		$out->addModuleStyles( 'ext.timetracker.timer' );

		$request = $this->getRequest();
		$csrf = $this->getContext()->getCsrfTokenSet();
		if ( $request->wasPosted() && $csrf->matchToken( $request->getVal( 'wpEditToken' ) ) ) {
			$job = trim( $request->getVal( 'job', '' ) );
			$result = $this->save(
				trim( $request->getVal( 'customer', '' ) ),
				$job,
				trim( $request->getVal( 'task', '' ) ),
				trim( $request->getVal( 'user', '' ) ),
				trim( $request->getVal( 'day', '' ) ),
				(int)$request->getVal( 'hours', 0 ),
				(int)$request->getVal( 'minutes', 0 ),
				trim( $request->getVal( 'note', '' ) )
			);
			if ( $result === null ) {
				// Invalid (e.g. the job's customer changed, or the job was deleted
				// between render and submit). Return to this entry's form carrying
				// its identity + origin so the user sees why (the entry-gone notice
				// or the corrected selection), not a blank default form.
				$params = array_filter( [
					'customer' => $request->getVal( 'customer', '' ),
					'job' => $job,
					'task' => $request->getVal( 'task', '' ),
					'user' => $request->getVal( 'user', '' ),
					'day' => $request->getVal( 'day', '' ),
					'returnto' => $request->getVal( 'returnto', '' ),
					'returntoquery' => $request->getVal( 'returntoquery', '' ),
				], static fn ( $v ) => $v !== '' );
				$out->redirect( $this->getPageTitle()->getLocalURL( $params ) );
				return;
			}
			// After saving, return where the user came from (a pencil link passes
			// returnto[/returntoquery]); otherwise fall back to the job page.
			$out->redirect( $this->returnUrl(
				trim( $request->getVal( 'returnto', '' ) ),
				$request->getVal( 'returntoquery', '' ),
				$job ) );
			return;
		}

		$out->addHTML( $this->renderForm() );
	}

	/**
	 * Where Save returns to: the posted returnto page (with its query) if it is a
	 * real/special page, else the edited job's page, else this form.
	 */
	private function returnUrl( string $returnto, string $returntoquery, string $jobId ): string {
		// ttfresh triggers a one-time reload on the destination so its SMW tables
		// pick up the entry data SMW commits just after this redirect is sent.
		$origin = $returnto !== '' ? $this->titleFactory->newFromText( $returnto ) : null;
		if ( $origin && ( $origin->isSpecialPage() || $origin->exists() ) ) {
			return $origin->getLocalURL( $returntoquery !== '' ? $returntoquery . '&ttfresh=1' : 'ttfresh=1' );
		}
		$job = $jobId !== '' ? $this->titleFactory->newFromText( $jobId ) : null;
		if ( $job && $job->exists() ) {
			return $job->getLocalURL( 'ttfresh=1' );
		}
		return $this->getPageTitle()->getLocalURL();
	}

	/** Set the day's total. Returns the new total (0 = removed), or null. */
	private function save(
		string $customerId, string $jobId, string $taskId, string $userName, string $day,
		int $hours, int $minutes, string $note
	): ?float {
		if ( $customerId === '' || $jobId === ''
			|| !$this->query->jobBelongsToCustomer( $customerId, $jobId )
			|| ( $taskId !== '' && !$this->query->taskBelongsToJob( $jobId, $taskId ) )
		) {
			return null;
		}
		$target = $this->resolveTargetUser( $userName );
		$day = $this->normalizeDay( $day );
		$total = max( 0, $hours ) + min( 59, max( 0, $minutes ) ) / 60.0;
		$this->entryStore->setDuration( $this->getAuthority(), $target, $customerId, $jobId, $taskId, $day, $total, $note );
		return $total;
	}

	/** A valid Y-m-d day, defaulting to today in the wiki timezone. */
	private function normalizeDay( string $day ): string {
		$tz = $this->timezone->safeZone();
		try {
			return ( new DateTime( $day !== '' ? $day : 'now', $tz ) )->format( 'Y-m-d' );
		} catch ( \Exception $e ) {
			return ( new DateTime( 'now', $tz ) )->format( 'Y-m-d' );
		}
	}

	private function renderForm(): string {
		// A pencil link passes the exact entry (customer/job/day/user); its
		// job may be archived, so keep it in the picker even though archived
		// jobs are otherwise hidden (no new time should go to them).
		$req = $this->getRequest();
		$reqCust = trim( $req->getVal( 'customer', '' ) );
		$reqProj = trim( $req->getVal( 'job', '' ) );
		// A pencil on a task entry passes the task; carried (hidden) through the
		// POST so the edit targets the same task bucket.
		$reqTask = trim( $req->getVal( 'task', '' ) );
		// Carry the origin through the POST so Save can return there.
		$returnto = trim( $req->getVal( 'returnto', '' ) );
		$returntoquery = $req->getVal( 'returntoquery', '' );
		$map = $this->query->customerJobsMap( $reqProj, $reqTask );
		// No in-card heading: the special page's own <h1> already says "Edit time".
		$body = '';
		if ( !$map ) {
			$link = $this->titleFactory->newFromText( 'Special:FormEdit/Job' );
			$body .= Html::rawElement( 'p', [ 'class' => 'tt-no-jobs' ],
				$this->msg( 'timetracker-no-jobs' )->parse() . ' '
				. Html::element( 'a', [ 'href' => $link ? $link->getLocalURL() : '#' ],
					$this->msg( 'timetracker-new-job' )->text() ) );
			return Html::rawElement( 'div', [ 'class' => 'tt-card' ], $body );
		}

		// A pencil link names an exact entry (customer + job). If that pair no
		// longer resolves — the customer or job was deleted — do NOT fall back to
		// a different selection: that would pre-fill (and on save overwrite) an
		// unrelated entry. Show a notice instead. (Delete protection now blocks
		// deleting an entity with logged time, so this is only for legacy orphans.)
		$requestedEntry = $reqCust !== '' && $reqProj !== '';
		$requestedValid = $requestedEntry && isset( $map[$reqCust] )
			&& $this->query->jobBelongsToCustomer( $reqCust, $reqProj );
		if ( $requestedEntry && !$requestedValid ) {
			return Html::rawElement( 'div', [ 'class' => 'tt-card' ],
				Html::rawElement( 'p', [ 'class' => 'tt-no-jobs' ],
					$this->msg( 'edittime-entry-gone' )->parse() ) );
		}

		// Default to the pencil's exact entry; otherwise the running timer's
		// job, or the first one.
		if ( $requestedValid ) {
			[ $defCust, $defProj ] = [ $reqCust, $reqProj ];
		} else {
			[ $defCust, $defProj ] = $this->defaultSelection( $map );
		}
		$selUser = $this->resolveTargetUser( trim( $req->getVal( 'user', '' ) ) );
		$day = $this->normalizeDay( trim( $req->getVal( 'day', '' ) ) );

		// Pre-fill with the current total for that selection; the JS refreshes
		// it when customer/job/day/user changes.
		[ $curHours, $curNote ] = $this->entryStore->currentEntry( $selUser, $defCust, $defProj, $reqTask, $day );
		[ $h, $m ] = $this->splitHoursMinutes( $curHours );

		$duration = Html::rawElement( 'span', [ 'class' => 'tt-duration-inputs' ],
			Html::element( 'input', [ 'type' => 'number', 'name' => 'hours', 'min' => '0',
				'value' => (string)$h, 'class' => 'tt-num-input tt-hours' ] )
			. ' ' . Html::element( 'span', [], $this->msg( 'addtime-hours' )->text() ) . ' '
			. Html::element( 'input', [ 'type' => 'number', 'name' => 'minutes', 'min' => '0', 'max' => '59',
				'value' => (string)$m, 'class' => 'tt-num-input tt-minutes' ] )
			. ' ' . Html::element( 'span', [], $this->msg( 'addtime-minutes' )->text() ) );

		$form = Html::rawElement( 'form', [
				'method' => 'post', 'action' => $this->getPageTitle()->getLocalURL(),
				'data-tt-jobs' => json_encode( $this->jobsJson( $map ) ),
			],
			Html::hidden( 'wpEditToken', $this->getContext()->getCsrfTokenSet()->getToken() )
			. ( $returnto !== '' ? Html::hidden( 'returnto', $returnto ) : '' )
			. ( $returntoquery !== '' ? Html::hidden( 'returntoquery', $returntoquery ) : '' )
			. $this->fieldRow( 'timetracker-label-customer', $this->customerSelect( $map, $defCust ) )
			. $this->fieldRow( 'timetracker-label-job', $this->jobSelect( $map, $defCust, $defProj ) )
			. $this->fieldRow( 'timetracker-label-task', $this->taskSelect( $map, $defCust, $defProj, $reqTask ) )
			. $this->userField( $selUser )
			. $this->fieldRow( 'addtime-label-day',
				Html::element( 'input', [ 'type' => 'date', 'name' => 'day', 'value' => $day, 'class' => 'tt-day-input' ] ) )
			. $this->fieldRow( 'edittime-label-duration', $duration )
			. $this->fieldRow( 'timetracker-label-note',
				Html::element( 'textarea', [ 'name' => 'note', 'rows' => '3',
					'class' => 'tt-description tt-note', 'placeholder' => $this->msg( 'timetracker-description-placeholder' )->text() ], $curNote ) )
			. Html::rawElement( 'div', [ 'class' => 'tt-actions' ],
				Html::element( 'button', [ 'type' => 'submit', 'class' => 'mw-ui-button mw-ui-progressive' ],
					$this->msg( 'edittime-submit' )->text() )
				. ' '
				. $this->cancelButton() ) );

		return Html::rawElement( 'div', [ 'class' => 'tt-card' ], $body . $form );
	}

	/** Cancel: a quiet red link, matching the PageForms cancel button. The JS
	 * turns it into history.back(); the href is the fallback. */
	private function cancelButton(): string {
		$home = $this->titleFactory->newFromText( 'Main Page' );
		return Html::element( 'a', [
			'href' => $home ? $home->getLocalURL() : '#',
			'class' => 'mw-ui-button mw-ui-quiet mw-ui-destructive tt-cancel',
		], $this->msg( 'edittime-cancel' )->text() );
	}

	/**
	 * The customer/job to start on: the running timer's pair if valid, else
	 * the first customer's first job.
	 *
	 * @return array{0:string,1:string}
	 */
	private function defaultSelection( array $map ): array {
		$running = $this->timer->getRunning( $this->getUser()->getName() );
		if ( $running && isset( $map[$running['customer']] ) ) {
			foreach ( $map[$running['customer']]['jobs'] as $job ) {
				if ( $job['id'] === $running['job'] ) {
					return [ $running['customer'], $running['job'] ];
				}
			}
		}
		$first = array_key_first( $map );
		return [ $first, $map[$first]['jobs'][0]['id'] ];
	}

	/** Decimal hours -> [ whole hours, remaining minutes ]. */
	private function splitHoursMinutes( float $hours ): array {
		$totalMinutes = (int)round( $hours * 60 );
		return [ intdiv( $totalMinutes, 60 ), $totalMinutes % 60 ];
	}

	/** @inheritDoc */
	protected function getGroupName() {
		return 'other';
	}
}
