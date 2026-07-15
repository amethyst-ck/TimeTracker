<?php

namespace MediaWiki\Extension\TimeTracker\Special;

use DateTime;
use DateTimeZone;
use MediaWiki\Extension\TimeTracker\Duration;
use MediaWiki\Extension\TimeTracker\EntryWikitext;
use MediaWiki\Extension\TimeTracker\TableRenderer;
use MediaWiki\Extension\TimeTracker\Timezone;
use MediaWiki\Extension\TimeTracker\TimeTrackerQuery;
use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\TitleFactory;

/**
 * Special:TimeReports — the editable weekly timesheet grid. A filter bar
 * (customer, job, task, user) narrows the rows and the week is chosen with the
 * pager; state lives in GET params so a view is shareable and works without JS.
 * Ids are resolved to names in PHP from the customer/job/task maps.
 */
class SpecialTimeReports extends SpecialPage {

	use FormControls;

	public function __construct(
		private readonly TimeTrackerQuery $query,
		private readonly TitleFactory $titleFactory,
		private readonly Timezone $timezone,
		private readonly EntryWikitext $wikitext,
		private readonly TableRenderer $table
	) {
		parent::__construct( 'TimeReports' );
	}

	/** @inheritDoc */
	public function execute( $subPage ) {
		$this->setHeaders();
		$this->requireLogin();
		$out = $this->getOutput();
		$out->addModuleStyles( 'ext.timetracker.reports' );
		$out->addModules( 'ext.timetracker.reports' );

		$req = $this->getRequest();
		$customer = trim( $req->getVal( 'customer', '' ) );
		$job = trim( $req->getVal( 'job', '' ) );
		// user absent -> the viewer; user='*' -> everyone.
		$userParam = $req->getVal( 'user' );
		$viewer = $this->getUser()->getName();
		if ( $userParam === null ) {
			$userFilter = $viewer;
			$userValue = $viewer;
		} elseif ( $userParam === '*' ) {
			$userFilter = null;
			$userValue = '*';
		} else {
			$userFilter = $userParam;
			$userValue = $userParam;
		}

		$out->addModules( 'ext.timetracker.grid' );
		// The editable weekly grid is the only view: a Mon–Sun matrix, pageable.
		$period = $this->resolveWeek( $req );

		// The job filter is scoped to the chosen customer: with no customer
		// the dropdown offers only "All jobs". Archived jobs are hidden
		// from the dropdown, but an explicitly-filtered one is kept so the filter
		// is honored and labeled. A chosen job must belong to the chosen
		// customer (dropped if not).
		$jobs = $customer !== '' ? $this->query->jobs( $customer, true ) : [];
		if ( $job !== '' && !isset( $jobs[$job] )
			&& $this->query->jobBelongsToCustomer( $customer, $job )
		) {
			$jobs[$job] = $this->query->nameById( $job );
		}
		if ( $job !== '' && !isset( $jobs[$job] ) ) {
			$job = '';
		}

		// The task filter is scoped to the chosen job; drop it otherwise.
		$task = trim( $req->getVal( 'task', '' ) );
		if ( $task !== '' && ( $job === '' || !$this->query->taskBelongsToJob( $job, $task ) ) ) {
			$task = '';
		}
		$rows = $this->query->entries( $customer, $job, $userFilter, $period['from'], $period['to'], $task );
		// Id => name maps to resolve customers/jobs/tasks for display.
		$names = [ 'customers' => $this->query->customers(), 'jobs' => $this->query->jobs(),
			'tasks' => $this->query->tasks() ];

		if ( $req->getVal( 'format' ) === 'csv' ) {
			$this->outputCsv( $rows, $names, $period );
			return;
		}

		$out->addHTML( $this->renderFilterBar( $customer, $job, $userValue, $period, $jobs, $task ) );
		$out->addHTML( $this->renderGrid( $rows, $names, $period, $userFilter, $customer, $job, $task ) );
	}

	/**
	 * Stream the filtered entries as a CSV download (Day, Customer, Job, Task,
	 * User, Notes, Hours, Minutes). Duration is split into whole hours and
	 * minutes rather than decimal hours. Uses the same rows as the timesheet.
	 *
	 * @param array<int,array<string,?string>> $rows
	 * @param array{customers:array<string,string>,jobs:array<string,string>} $names
	 * @param array{from:string,to:string,label:string,weekstart:?string} $period
	 */
	private function outputCsv( array $rows, array $names, array $period ): void {
		$this->getOutput()->disable();
		$response = $this->getRequest()->response();
		$response->header( 'Content-Type: text/csv; charset=utf-8' );
		$response->header(
			'Content-Disposition: attachment; filename="timesheet-' . $period['from'] . '_' . $period['to'] . '.csv"' );

		$fh = fopen( 'php://output', 'w' );
		fputcsv( $fh, [ 'Day', 'Customer', 'Job', 'Task', 'User', 'Notes', 'Hours', 'Minutes' ] );
		foreach ( $rows as $r ) {
			$custId = (string)$r['customer'];
			$projId = (string)$r['job'];
			$taskId = (string)$r['task'];
			[ $h, $m ] = Duration::hoursMinutes( $r['duration'] );
			fputcsv( $fh, array_map( [ $this, 'csvCell' ], [
				(string)$r['day'],
				$names['customers'][$custId] ?? $custId,
				$names['jobs'][$projId] ?? $projId,
				$taskId !== '' ? ( $names['tasks'][$taskId] ?? $taskId ) : '',
				(string)$r['user'],
				$this->wikitext->unfoldNewlines( (string)$r['notes'] ),
				(string)$h,
				(string)$m,
			] ) );
		}
		fclose( $fh );
	}

	/**
	 * Neutralize spreadsheet formula injection: a cell a spreadsheet would treat
	 * as a formula (leading =, +, -, @, or a control char) is prefixed with a
	 * single quote so it is imported as literal text.
	 */
	private function csvCell( string $value ): string {
		return $value !== '' && strpbrk( $value[0], "=+-@\t\r" ) !== false ? "'" . $value : $value;
	}

	/* ---------------------------------------------------------------- period */

	/**
	 * Resolve the displayed Monday..Sunday week, honoring the week pager
	 * (nav=prev/next) and the weekstart date picker.
	 *
	 * @return array{from:string,to:string,label:string,weekstart:?string}
	 */
	private function resolveWeek( $req ): array {
		$tz = $this->timezone->safeZone();
		$today = ( new DateTime( 'now', $tz ) )->setTime( 0, 0, 0 );
		$weekstart = $this->parseDate( $req->getVal( 'weekstart', '' ), $tz )
			?? ( clone $today );
		$nav = $req->getVal( 'nav', '' );
		if ( $nav === 'prev' ) {
			$weekstart->modify( '-7 days' );
		} elseif ( $nav === 'next' ) {
			$weekstart->modify( '+7 days' );
		}
		$monday = ( clone $weekstart )->modify( 'monday this week' );
		$sunday = ( clone $monday )->modify( '+6 days' );
		return $this->window( $monday, $sunday,
			'Week of ' . $monday->format( 'M j, Y' ), $monday->format( 'Y-m-d' ) );
	}

	/** @return array{from:string,to:string,label:string,weekstart:?string} */
	private function window( DateTime $from, DateTime $to, string $label, ?string $weekstart ): array {
		return [
			'from' => $from->format( 'Y-m-d' ),
			'to' => $to->format( 'Y-m-d' ),
			'label' => $label,
			'weekstart' => $weekstart,
		];
	}

	private function parseDate( string $value, DateTimeZone $tz ): ?DateTime {
		$value = trim( $value );
		if ( $value === '' ) {
			return null;
		}
		try {
			return ( new DateTime( $value, $tz ) )->setTime( 0, 0, 0 );
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/* --------------------------------------------------------------- filter */

	private function renderFilterBar(
		string $customer, string $job, string $userValue, array $period, array $jobs, string $task = ''
	): string {
		$form = [];
		$form[] = $this->select( 'customer', $this->options(
			$this->query->customers(), $customer, $this->msg( 'timereports-all-customers' )->text() ),
			'timereports-filter-customer', 'tt-f-customer' );
		$form[] = $this->select( 'job', $this->options(
			$jobs, $job, $this->msg( 'timereports-all-jobs' )->text() ),
			'timereports-filter-job', 'tt-f-job' );

		// Task filter — scoped to the chosen job (only populated when a
		// job is selected, mirroring how jobs are scoped to a customer).
		$tasksMap = [];
		if ( $job !== '' ) {
			foreach ( $this->query->tasksOfJob( $job ) as $tk ) {
				$tasksMap[$tk['id']] = $tk['name'];
			}
		}
		$form[] = $this->select( 'task', $this->options(
			$tasksMap, $task, $this->msg( 'timereports-all-tasks' )->text() ),
			'timereports-filter-task', 'tt-f-task' );

		$userOpts = Html::element( 'option',
			[ 'value' => '*' ] + ( $userValue === '*' ? [ 'selected' => '' ] : [] ),
			$this->msg( 'timereports-all-users' )->text() );
		foreach ( $this->userNames() as $u ) {
			$userOpts .= Html::element( 'option',
				[ 'value' => $u ] + ( $u === $userValue ? [ 'selected' => '' ] : [] ), $u );
		}
		$form[] = $this->field( 'timereports-filter-user',
			Html::rawElement( 'select', [ 'name' => 'user', 'class' => 'tt-f-user' ], $userOpts ) );

		// Preserve the resolved week so a filter change keeps the current week.
		$hidden = '';
		if ( $period['weekstart'] !== null ) {
			$hidden = Html::hidden( 'weekstart', $period['weekstart'] );
		}

		// Section 1 — the filters. The selects auto-submit this form on change
		// (see the JS); $hidden carries the current week along.
		$filterBar = Html::rawElement( 'form',
			[ 'method' => 'get', 'action' => $this->getPageTitle()->getLocalURL(), 'class' => 'tt-filter' ],
			implode( '', $form ) . $hidden );

		// Section 2 — week navigation and CSV export.
		$baseParams = $this->filterParams( $customer, $job, $userValue, $period, $task );
		$csv = Html::element( 'a',
			[ 'href' => $this->getPageTitle()->getLocalURL( [ 'format' => 'csv' ] + $baseParams ),
				'class' => 'mw-ui-button' ],
			$this->msg( 'timereports-export-csv' )->text() );
		$controls = Html::rawElement( 'div', [ 'class' => 'tt-report-controls' ],
			$this->weekPager( $period, $baseParams )
			. Html::rawElement( 'span', [ 'class' => 'tt-controls-right' ], $csv ) );

		return $filterBar . $controls;
	}

	/** The current filter as query params — shared by the CSV link and the pager. */
	private function filterParams( string $customer, string $job, string $userValue, array $period, string $task = '' ): array {
		$params = [ 'user' => $userValue ];
		if ( $customer !== '' ) {
			$params['customer'] = $customer;
		}
		if ( $job !== '' ) {
			$params['job'] = $job;
		}
		if ( $task !== '' ) {
			$params['task'] = $task;
		}
		if ( $period['weekstart'] !== null ) {
			$params['weekstart'] = $period['weekstart'];
		}
		return $params;
	}

	/**
	 * Prev / current-week / Next pager. Its own form (it sits in the controls
	 * section, outside the filter form) that carries the applied filters as
	 * hidden fields so stepping weeks keeps them.
	 */
	private function weekPager( array $period, array $baseParams ): string {
		$hidden = '';
		foreach ( $baseParams as $k => $v ) {
			$hidden .= Html::hidden( (string)$k, (string)$v );
		}
		$prev = Html::element( 'button',
			[ 'type' => 'submit', 'name' => 'nav', 'value' => 'prev', 'class' => 'mw-ui-button tt-week-nav' ], '◀' );
		$next = Html::element( 'button',
			[ 'type' => 'submit', 'name' => 'nav', 'value' => 'next', 'class' => 'mw-ui-button tt-week-nav' ], '▶' );
		$label = Html::element( 'span', [ 'class' => 'tt-week-label' ], $period['label'] );
		// A date picker to jump to any week (handled by ext.timetracker.grid's JS,
		// loaded on the grid view). It reloads with weekstart set to the chosen day.
		$pick = Html::element( 'input', [ 'type' => 'date', 'class' => 'tt-g-weekpick tt-week-nav',
			'value' => $period['from'], 'data-param' => 'weekstart',
			'title' => $this->msg( 'timereports-pick-week' )->text() ] );
		return Html::rawElement( 'form',
			[ 'method' => 'get', 'action' => $this->getPageTitle()->getLocalURL(), 'class' => 'tt-week-pager' ],
			$hidden . $prev . $label . $next . $pick );
	}

	/** Distinct users who have logged time, plus the viewer, sorted. @return string[] */
	private function userNames(): array {
		$users = $this->query->reportUserNames();
		$users[] = $this->getUser()->getName();
		$users = array_values( array_unique( array_filter( $users ) ) );
		sort( $users );
		return $users;
	}

	/** Options from an id => name map: value is the id, the label is the name. */
	private function options( array $map, string $selected, string $allLabel ): string {
		$html = Html::element( 'option',
			[ 'value' => '' ] + ( $selected === '' ? [ 'selected' => '' ] : [] ), $allLabel );
		foreach ( $map as $id => $name ) {
			$html .= Html::element( 'option',
				[ 'value' => (string)$id ] + ( (string)$id === $selected ? [ 'selected' => '' ] : [] ), $name );
		}
		return $html;
	}

	private function select( string $name, string $options, string $labelMsg, string $class ): string {
		return $this->field( $labelMsg,
			Html::rawElement( 'select', [ 'name' => $name, 'class' => $class ], $options ) );
	}

	private function field( string $labelMsg, string $control ): string {
		return Html::rawElement( 'label', [ 'class' => 'tt-f-field' ],
			Html::element( 'span', [ 'class' => 'tt-f-label' ], $this->msg( $labelMsg )->text() ) . $control );
	}

	/* ----------------------------------------------------------------- grid */

	/**
	 * Weekly timesheet matrix: one row per customer/job/task (plus user when
	 * viewing everyone), a column per weekday, cells = hours logged that day.
	 * Each cell is inline-editable and auto-saves via the setcell API.
	 *
	 * @param array<int,array<string,?string>> $rows
	 * @param array{customers:array<string,string>,jobs:array<string,string>,tasks:array<string,string>} $names
	 * @param array{from:string,to:string,label:string,weekstart:?string} $period from = the week's Monday
	 * @param bool $allUsers whether the user filter spans everyone (adds a User column + row key)
	 */
	private function renderGrid(
		array $rows, array $names, array $period, ?string $gridUser,
		string $customer, string $job, string $task
	): string {
		$canAdd = $gridUser !== null && $this->canEdit( (string)$gridUser );
		// Scope the add-row to the active filter so it can't offer (and then
		// overwrite) a bucket that exists but is hidden by the filter. Filters
		// are hierarchical (task ⇒ job ⇒ customer), so they compose into fixed
		// add-row dimensions just like an entity page's grid.
		$fixed = [];
		if ( $customer !== '' ) {
			$fixed['customer'] = [ $customer, $names['customers'][$customer] ?? $customer ];
		}
		if ( $job !== '' ) {
			$fixed['job'] = [ $job, $names['jobs'][$job] ?? $job ];
		}
		if ( $task !== '' ) {
			$fixed['task'] = [ $task, $names['tasks'][$task] ?? $task ];
		}
		return $this->table->renderGrid(
			$rows, $names, $this->weekDays( $period['from'] ),
			$this->getUser()->getName(),
			$this->getUser()->isAllowed( 'timetracker-editothers' ),
			$gridUser,
			$canAdd ? $this->query->customerJobsMap() : [],
			$fixed );
	}

	/** The seven Mon..Sun days of the week starting at $mondayYmd. @return array<int,array{ymd:string,label:string}> */
	private function weekDays( string $mondayYmd ): array {
		$out = [];
		try {
			$d = new DateTime( $mondayYmd );
		} catch ( \Exception $e ) {
			return $out;
		}
		for ( $i = 0; $i < 7; $i++ ) {
			$out[] = [ 'ymd' => $d->format( 'Y-m-d' ), 'label' => $d->format( 'D j' ) ];
			$d->modify( '+1 day' );
		}
		return $out;
	}


	/** Whether the viewer may edit $rowUser's time (their own, or an admin). */
	private function canEdit( string $rowUser ): bool {
		return $rowUser === $this->getUser()->getName()
			|| $this->getUser()->isAllowed( 'timetracker-editothers' );
	}

	/** @inheritDoc */
	protected function getGroupName() {
		return 'other';
	}
}
