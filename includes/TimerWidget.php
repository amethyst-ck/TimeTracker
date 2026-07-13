<?php

namespace MediaWiki\Extension\TimeTracker;

use DateTime;
use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\TimeTracker\Special\CsrfPostButton;
use MediaWiki\Extension\TimeTracker\Special\FormControls;
use MediaWiki\Html\Html;
use MediaWiki\Message\Message;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\UserIdentity;

/**
 * Renders the Start/Stop timer — the running card or the idle start form — for a
 * context's user. Shared by Special:TimeTracker and the {{#timetracker_timer}} /
 * {{#timetracker_jobtimer}} parser functions so the same widget can live inline
 * on a content page (e.g. a user's home) or a job/task page. Forms POST to
 * Special:TimeTracker, which owns the accounting and, when given a $returnTo,
 * redirects back there afterward.
 */
class TimerWidget {

	use FormControls;

	private IContextSource $context;

	public function __construct(
		private readonly Timer $timer,
		private readonly TimeTrackerQuery $query,
		private readonly TitleFactory $titleFactory,
		private readonly Timezone $timezone
	) {
	}

	/**
	 * The timer widget HTML for $context's user: a "saved" banner (if the request
	 * carries one), then the running card or the idle start form. Forms post to
	 * Special:TimeTracker; $returnTo (a content page) is where it returns after.
	 */
	public function render( IContextSource $context, ?Title $returnTo = null ): string {
		$this->context = $context;
		$html = '';
		$saved = $context->getRequest()->getVal( 'saved', '' );
		if ( $saved !== '' && is_numeric( $saved ) ) {
			$html .= $this->renderSaved( (float)$saved );
		}
		$running = $this->timer->getRunning( $this->getUser()->getName() );
		// The running card when a timer is active; otherwise a picker so a timer
		// can be started right here (the user's home page) without first
		// visiting a job or task page.
		return $html . ( $running
			? $this->renderRunning( $running, $returnTo )
			: $this->renderPickerStart( $returnTo ) );
	}

	/**
	 * Just the running-timer card for the viewer, or null if none is running —
	 * for pages (job/task) that show the timer only when one is active.
	 */
	public function runningCard( IContextSource $context, ?Title $returnTo = null ): ?string {
		$this->context = $context;
		$running = $this->timer->getRunning( $this->getUser()->getName() );
		// The job/task page already names the customer/job/task.
		return $running ? $this->renderRunning( $running, $returnTo, false ) : null;
	}

	/**
	 * The viewer's running timer as [customer,job,task,...], or null — so a
	 * job/task page can show the card only for the exact thing being timed.
	 *
	 * @return array{customer:string,job:string,task:string,user:string,start:string,timezone:string}|null
	 */
	public function running( IContextSource $context ): ?array {
		$this->context = $context;
		return $this->timer->getRunning( $this->getUser()->getName() );
	}

	/**
	 * A brief note for a job/task page when the viewer's one timer is running
	 * on something else: names it and links to Special:TimeTracker to view/stop it.
	 */
	public function runningElsewhereNote( IContextSource $context ): string {
		$this->context = $context;
		$running = $this->timer->getRunning( $this->getUser()->getName() );
		// A "Customer : Job : Task" breadcrumb, each segment linked, so a
		// repeated task name stays unambiguous; the task segment is dropped for a
		// job-level timer.
		$crumb = '';
		if ( $running !== null ) {
			$parts = [
				$this->pageLink( $running['customer'], $this->query->nameById( $running['customer'] ) ),
				$this->pageLink( $running['job'], $this->query->nameById( $running['job'] ) ),
			];
			if ( $running['task'] !== '' ) {
				$parts[] = $this->pageLink( $running['task'], $this->query->nameById( $running['task'] ) );
			}
			$crumb = implode( ' : ', $parts );
		}
		return Html::rawElement( 'div', [ 'class' => 'tt-card tt-timer-note' ],
			Html::rawElement( 'span', [ 'class' => 'tt-timer-note-text' ],
				'⏱️ ' . $this->msg( 'timetracker-timer-elsewhere' )->rawParams( $crumb )->text() ) );
	}

	/* ----------------------------------------- context accessors for FormControls */

	private function getUser(): UserIdentity {
		return $this->context->getUser();
	}

	private function msg( string $key, ...$params ): Message {
		return $this->context->msg( $key, ...$params );
	}

	private function getContext(): IContextSource {
		return $this->context;
	}

	/** Special:TimeTracker — the page the widget's forms post to. */
	private function timeTrackerTitle(): ?Title {
		return $this->titleFactory->newFromText( 'Special:TimeTracker' );
	}

	/* ------------------------------------------------------------- rendering */

	/** Confirmation banner after Stop. */
	private function renderSaved( float $hours ): string {
		$reports = $this->titleFactory->newFromText( 'Special:TimeReports' );
		return Html::rawElement( 'div', [ 'class' => 'tt-saved' ],
			Html::element( 'span', [ 'class' => 'tt-saved-icon' ], '✓' ) . ' '
			. Html::element( 'span', [ 'class' => 'tt-saved-label' ],
				$this->msg( 'timetracker-saved-added', Duration::hm( $hours ) )->text() . ' ' )
			. Html::element( 'a', [ 'href' => $reports ? $reports->getLocalURL() : '#' ],
				$this->msg( 'timetracker-link-reports' )->text() ) . '.' );
	}

	/** The running-timer card: live elapsed, Stop, and context rows as applicable. */
	private function renderRunning( array $running, ?Title $returnTo, bool $showContext = true ): string {
		// On a job/task page the page itself names the customer/job/task,
		// so those rows are shown only where there is no such context (the user
		// page and Special:TimeTracker).
		$rows = '';
		if ( $showContext ) {
			$rows .= $this->infoRow( 'timetracker-label-customer',
				$this->pageLink( $running['customer'], $this->query->nameById( $running['customer'] ) ) );
			$rows .= $this->infoRow( 'timetracker-label-job',
				$this->pageLink( $running['job'], $this->query->nameById( $running['job'] ) ) );
			if ( $running['task'] !== '' ) {
				$rows .= $this->infoRow( 'timetracker-label-task',
					$this->pageLink( $running['task'], $this->query->nameById( $running['task'] ) ) );
			}
		}
		// A user only ever sees their own running timer, so their own name is
		// redundant; show it only when an admin started this timer for someone else.
		if ( $running['user'] !== '' && $running['user'] !== $this->getUser()->getName() ) {
			$rows .= $this->infoRow( 'timetracker-label-user',
				$this->pageLink( 'User:' . $running['user'], $running['user'] ) );
		}
		$clock = Html::element( 'div',
			[ 'class' => 'tt-elapsed', 'data-tt-start' => (string)$this->epochOf( $running['start'], $running['timezone'] ) ], '—' );
		$stop = CsrfPostButton::render(
			$this->getContext(), $this->timeTrackerTitle(), 'action', 'stop',
			$this->msg( 'timetracker-stop' )->text(), $this->returnHidden( $returnTo ),
			[ 'class' => 'tt-stop-form' ], 'mw-ui-button mw-ui-progressive' );

		return Html::rawElement( 'div', [ 'class' => 'tt-card tt-running' ],
			$clock . $rows . $stop );
	}

	private function infoRow( string $labelMsg, string $valueHtml ): string {
		return Html::rawElement( 'div', [ 'class' => 'tt-row' ],
			Html::element( 'span', [ 'class' => 'tt-label' ], $this->msg( $labelMsg )->text() )
			. Html::rawElement( 'span', [ 'class' => 'tt-value' ], $valueHtml ) );
	}

	/**
	 * Inline "start a timer" form with a customer → job → task picker (narrowed
	 * client-side via data-tt-jobs), for a surface with no fixed context: the
	 * user's home page. Posts to Special:TimeTracker; empty if there is nothing
	 * to time yet.
	 */
	private function renderPickerStart( ?Title $returnTo ): string {
		$map = $this->query->customerJobsMap();
		if ( $map === [] ) {
			return '';
		}
		$action = $this->timeTrackerTitle();
		$hidden = Html::hidden( 'action', 'start' )
			. Html::hidden( 'wpEditToken', $this->getContext()->getCsrfTokenSet()->getToken() );
		if ( $returnTo ) {
			$hidden .= Html::hidden( 'returnto', $returnTo->getPrefixedDBkey() );
		}
		$form = Html::rawElement( 'form',
			[ 'method' => 'post', 'action' => $action ? $action->getLocalURL() : '',
				'data-tt-jobs' => json_encode( $this->jobsJson( $map ) ) ],
			$hidden
			. $this->fieldRow( 'timetracker-label-customer', $this->customerSelect( $map ) )
			. $this->fieldRow( 'timetracker-label-job', $this->jobSelect( $map ) )
			. $this->fieldRow( 'timetracker-label-task', $this->taskSelect( $map ) )
			. $this->userField()
			. Html::rawElement( 'div', [ 'class' => 'tt-actions' ],
				Html::element( 'button', [ 'type' => 'submit', 'class' => 'mw-ui-button mw-ui-progressive' ],
					$this->msg( 'timetracker-start' )->text() ) ) );

		return Html::rawElement( 'div', [ 'class' => 'tt-card' ], $form );
	}

	/**
	 * Inline "start a timer" form for a job or task page. Customer/job/task
	 * are fixed to this page (a job page starts its General bucket, task=''),
	 * so there is no picker — timing is initiated right here. Posts to the
	 * (unlisted) Special:TimeTracker handler and returns to $returnTo.
	 */
	public function renderJobStart( IContextSource $context, string $jobId, string $taskId, ?Title $returnTo ): string {
		$this->context = $context;
		$customerId = $this->query->jobCustomer( $jobId );
		$action = $this->timeTrackerTitle();
		$hidden = Html::hidden( 'action', 'start' )
			. Html::hidden( 'wpEditToken', $this->getContext()->getCsrfTokenSet()->getToken() )
			. Html::hidden( 'customer', $customerId )
			. Html::hidden( 'job', $jobId )
			. Html::hidden( 'task', $taskId );
		if ( $returnTo ) {
			$hidden .= Html::hidden( 'returnto', $returnTo->getPrefixedDBkey() );
		}
		$form = Html::rawElement( 'form',
			[ 'method' => 'post', 'action' => $action ? $action->getLocalURL() : '' ],
			$hidden
			. $this->userField()
			. Html::rawElement( 'div', [ 'class' => 'tt-actions' ],
				Html::element( 'button', [ 'type' => 'submit', 'class' => 'mw-ui-button mw-ui-progressive' ],
					$this->msg( 'timetracker-start' )->text() ) ) );

		return Html::rawElement( 'div', [ 'class' => 'tt-card' ], $form );
	}

	/** The returnto hidden field for the Stop button, or none. */
	private function returnHidden( ?Title $returnTo ): array {
		return $returnTo ? [ 'returnto' => $returnTo->getPrefixedDBkey() ] : [];
	}

	/** Epoch for the given wall time in a stored timezone (for the live clock). */
	private function epochOf( string $wall, string $tz ): int {
		try {
			return ( new DateTime( $wall, $this->timezone->safeZone( $tz ) ) )->getTimestamp();
		} catch ( \Exception $e ) {
			return 0;
		}
	}
}
