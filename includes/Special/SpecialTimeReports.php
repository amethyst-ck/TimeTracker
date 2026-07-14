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
 * Special:TimeReports — a filterable timesheet grouped by day. A filter bar
 * (customer, job, user, and a week/month/year/custom period) drives the
 * table; state lives in GET params so a view is shareable and works without JS.
 * Ids are resolved to names in PHP from the customer/job maps.
 */
class SpecialTimeReports extends SpecialPage {

	use FormControls;

	private const RANGES = [ 'week', 'this_month', 'last_month', 'this_year', 'last_year', 'custom' ];

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

		// The weekly grid is the primary, editable view; list is a read-only report.
		$view = $req->getVal( 'view', 'grid' ) === 'list' ? 'list' : 'grid';
		if ( $view === 'grid' ) {
			$out->addModules( 'ext.timetracker.grid' );
		}
		$range = $req->getVal( 'range', 'week' );
		// The grid is inherently a Mon–Sun matrix, so it forces the week range.
		if ( $view === 'grid' || !in_array( $range, self::RANGES, true ) ) {
			$range = 'week';
		}
		$period = $this->resolvePeriod( $range, $req );

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

		$out->addHTML( $this->renderFilterBar( $customer, $job, $userValue, $range, $period, $jobs, $task, $view ) );
		$out->addHTML( $view === 'grid'
			? $this->renderGrid( $rows, $names, $period, $userFilter )
			: $this->renderTimesheet( $rows, $names ) );
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
	 * Resolve the active time window from the range choice, honoring the
	 * week pager (nav=prev/next) and custom from/to inputs.
	 *
	 * @return array{from:string,to:string,label:string,weekstart:?string}
	 */
	private function resolvePeriod( string $range, $req ): array {
		$tz = $this->timezone->safeZone();
		$today = ( new DateTime( 'now', $tz ) )->setTime( 0, 0, 0 );

		if ( $range === 'custom' ) {
			$from = $this->parseDate( $req->getVal( 'from', '' ), $tz ) ?? ( clone $today );
			$to = $this->parseDate( $req->getVal( 'to', '' ), $tz ) ?? ( clone $today );
			if ( $to < $from ) {
				[ $from, $to ] = [ $to, $from ];
			}
			return $this->window( $from, $to,
				$from->format( 'M j, Y' ) . ' – ' . $to->format( 'M j, Y' ), null );
		}

		if ( $range === 'this_month' || $range === 'last_month' ) {
			$anchor = ( clone $today )->modify( 'first day of' . ( $range === 'last_month' ? ' last month' : ' this month' ) );
			$from = ( clone $anchor );
			$to = ( clone $anchor )->modify( 'last day of this month' );
			return $this->window( $from, $to, $anchor->format( 'F Y' ), null );
		}

		if ( $range === 'this_year' || $range === 'last_year' ) {
			$year = (int)$today->format( 'Y' ) - ( $range === 'last_year' ? 1 : 0 );
			$from = ( new DateTime( "$year-01-01", $tz ) )->setTime( 0, 0, 0 );
			$to = ( new DateTime( "$year-12-31", $tz ) )->setTime( 0, 0, 0 );
			return $this->window( $from, $to, (string)$year, null );
		}

		// week (default): Monday..Sunday, pageable.
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
		string $customer, string $job, string $userValue, string $range, array $period, array $jobs, string $task = '', string $view = 'list'
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
			Html::rawElement( 'select', [ 'name' => 'user' ], $userOpts ) );

		// The range selector and custom-date inputs only apply to the list view;
		// the grid always shows the current week (chosen via the week pager).
		if ( $view === 'list' ) {
			$rangeOpts = '';
			foreach ( self::RANGES as $r ) {
				$rangeOpts .= Html::element( 'option',
					[ 'value' => $r ] + ( $r === $range ? [ 'selected' => '' ] : [] ),
					$this->msg( "timereports-range-$r" )->text() );
			}
			$form[] = $this->field( 'timereports-filter-period',
				Html::rawElement( 'select', [ 'name' => 'range', 'class' => 'tt-f-range' ], $rangeOpts ) );

			// Custom range inputs (shown by CSS/JS only when range=custom).
			$customCls = 'tt-f-custom' . ( $range === 'custom' ? ' tt-f-custom-on' : '' );
			$fromVal = $range === 'custom' ? substr( $period['from'], 0, 10 ) : '';
			$toVal = $range === 'custom' ? substr( $period['to'], 0, 10 ) : '';
			$form[] = Html::rawElement( 'span', [ 'class' => $customCls ],
				Html::element( 'input', [ 'type' => 'date', 'name' => 'from', 'value' => $fromVal ] )
				. ' – '
				. Html::element( 'input', [ 'type' => 'date', 'name' => 'to', 'value' => $toVal ] ) );
		}

		// Preserve the resolved week so Apply keeps the current week.
		$hidden = '';
		if ( $period['weekstart'] !== null ) {
			$hidden = Html::hidden( 'weekstart', $period['weekstart'] );
		}

		$apply = Html::element( 'button',
			[ 'type' => 'submit', 'class' => 'mw-ui-button mw-ui-progressive' ],
			$this->msg( 'timereports-apply' )->text() );

		// Grid mode forces the week range; keep the chosen view across an Apply.
		if ( $view === 'grid' ) {
			$hidden .= Html::hidden( 'view', 'grid' );
		}

		// Section 1 — the filters and their Apply button.
		$filterBar = Html::rawElement( 'form',
			[ 'method' => 'get', 'action' => $this->getPageTitle()->getLocalURL(), 'class' => 'tt-filter' ],
			implode( '', $form ) . $hidden
			. Html::rawElement( 'span', [ 'class' => 'tt-f-actions' ], $apply ) );

		// Section 2 — week navigation, the List/Grid chooser, and CSV export.
		// Pass $view so the week pager's hidden fields keep Grid view across a step.
		$baseParams = $this->filterParams( $customer, $job, $userValue, $range, $period, $task, $view );
		$viewToggle = Html::rawElement( 'span', [ 'class' => 'tt-view-toggle' ],
			$this->viewLink( 'list', $view, $baseParams ) . $this->viewLink( 'grid', $view, $baseParams ) );
		$csv = Html::element( 'a',
			[ 'href' => $this->getPageTitle()->getLocalURL( [ 'format' => 'csv' ] + $baseParams ),
				'class' => 'mw-ui-button' ],
			$this->msg( 'timereports-export-csv' )->text() );
		$controls = Html::rawElement( 'div', [ 'class' => 'tt-report-controls' ],
			$this->weekPager( $range, $period, $baseParams )
			. Html::rawElement( 'span', [ 'class' => 'tt-controls-right' ], $viewToggle . ' ' . $csv ) );

		return $filterBar . $controls;
	}

	/** The current filter as query params — shared by the CSV link and returnto. */
	private function filterParams( string $customer, string $job, string $userValue, string $range, array $period, string $task = '', string $view = 'list' ): array {
		$params = [ 'range' => $range, 'user' => $userValue ];
		if ( $view === 'grid' ) {
			$params['view'] = 'grid';
		}
		if ( $customer !== '' ) {
			$params['customer'] = $customer;
		}
		if ( $job !== '' ) {
			$params['job'] = $job;
		}
		if ( $task !== '' ) {
			$params['task'] = $task;
		}
		if ( $range === 'custom' ) {
			$params['from'] = $period['from'];
			$params['to'] = $period['to'];
		} elseif ( $period['weekstart'] !== null ) {
			$params['weekstart'] = $period['weekstart'];
		}
		return $params;
	}

	/** A List/Grid toggle button — an anchor carrying the current filters + target view. */
	private function viewLink( string $target, string $current, array $params ): string {
		if ( $target === 'grid' ) {
			// The grid always shows the current Mon–Sun week.
			$params['range'] = 'week';
			unset( $params['from'], $params['to'] );
			$params['view'] = 'grid';
		} else {
			unset( $params['view'] );
		}
		$cls = 'mw-ui-button' . ( $current === $target ? ' mw-ui-progressive' : '' );
		return Html::element( 'a',
			[ 'href' => $this->getPageTitle()->getLocalURL( $params ), 'class' => $cls ],
			$this->msg( 'timereports-view-' . $target )->text() );
	}

	/**
	 * Prev / current-week / Next pager, only for the week range. Its own form
	 * (it sits in the controls section, outside the filter form) that carries
	 * the applied filters as hidden fields so stepping weeks keeps them.
	 */
	private function weekPager( string $range, array $period, array $baseParams ): string {
		if ( $range !== 'week' ) {
			return '';
		}
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

	/* ------------------------------------------------------------ timesheet */

	/**
	 * @param array<int,array<string,?string>> $rows
	 * @param array{customers:array<string,string>,jobs:array<string,string>} $names
	 * @param string $returntoquery current filter query, so a row pencil returns here
	 */
	private function renderTimesheet( array $rows, array $names ): string {
		$head = Html::rawElement( 'tr', [],
			$this->th( 'timereports-col-customer' ) . $this->th( 'timereports-col-job' )
			. $this->th( 'timereports-col-task' )
			. $this->th( 'timereports-col-user' )
			. $this->th( 'timereports-col-notes' )
			. $this->th( 'timereports-col-duration', 'tt-num' ) );

		if ( !$rows ) {
			$body = Html::rawElement( 'tr', [],
				Html::rawElement( 'td', [ 'colspan' => 6, 'class' => 'tt-empty' ],
					$this->msg( 'timereports-none' )->text() ) );
			return $this->tableShell( $head, $body, '' );
		}

		// Group the rows under a day header; sort each day by customer then job name.
		$byDay = [];
		foreach ( $rows as $r ) {
			$byDay[(string)$r['day']][] = $r;
		}

		$body = '';
		$grand = 0.0;
		foreach ( $byDay as $day => $dayRows ) {
			usort( $dayRows, static fn ( $a, $b ) => TableRenderer::compareEntries( $a, $b, $names['customers'], $names['jobs'], $names['tasks'] ) );
			$dayTotal = 0.0;
			foreach ( $dayRows as $r ) {
				$dayTotal += (float)$r['duration'];
			}
			$grand += $dayTotal;
			$body .= Html::rawElement( 'tr', [ 'class' => 'tt-ts-day' ],
				Html::rawElement( 'th', [ 'colspan' => 5 ], $this->dayLabel( $day ) )
				. Html::rawElement( 'th', [ 'class' => 'tt-num' ], Duration::hm( $dayTotal ) ) );
			foreach ( $dayRows as $r ) {
				$body .= $this->entryRow( $r, $names );
			}
		}

		$foot = Html::rawElement( 'tr', [ 'class' => 'tt-ts-total' ],
			Html::rawElement( 'th', [ 'colspan' => 5 ], $this->msg( 'timereports-total' )->text() )
			. Html::rawElement( 'th', [ 'class' => 'tt-num' ], Duration::hm( $grand ) ) );

		return $this->tableShell( $head, $body, $foot );
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
	private function renderGrid( array $rows, array $names, array $period, ?string $gridUser ): string {
		$canAdd = $gridUser !== null && $this->canEdit( (string)$gridUser );
		return $this->table->renderGrid(
			$rows, $names, $this->weekDays( $period['from'] ),
			$this->getUser()->getName(),
			$this->getUser()->isAllowed( 'timetracker-editothers' ),
			$gridUser,
			$canAdd ? $this->query->customerJobsMap() : [] );
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


	/**
	 * @param array<string,?string> $r
	 * @param array{customers:array<string,string>,jobs:array<string,string>} $names
	 */
	private function entryRow( array $r, array $names ): string {
		// customer/job are stable page ids; resolve to names (fall back to
		// the id if the parent page is gone).
		$custId = (string)$r['customer'];
		$projId = (string)$r['job'];
		$taskId = (string)$r['task'];
		$user = (string)$r['user'];
		return Html::rawElement( 'tr', [],
			Html::rawElement( 'td', [], $this->pageLink( $custId, $names['customers'][$custId] ?? $custId ) )
			. Html::rawElement( 'td', [], $this->pageLink( $projId, $names['jobs'][$projId] ?? $projId ) )
			. Html::rawElement( 'td', [], $this->pageLink( $taskId, $names['tasks'][$taskId] ?? $taskId ) )
			. Html::rawElement( 'td', [], $this->pageLink( 'User:' . $user, $user ) )
			. Html::rawElement( 'td', [ 'class' => 'tt-ts-notes' ],
				nl2br( htmlspecialchars( $this->wikitext->unfoldNewlines( (string)$r['notes'] ) ) ) )
			. Html::element( 'td', [ 'class' => 'tt-num' ], Duration::hm( (float)$r['duration'] ) ) );
	}

	/** Whether the viewer may edit $rowUser's time (their own, or an admin). */
	private function canEdit( string $rowUser ): bool {
		return $rowUser === $this->getUser()->getName()
			|| $this->getUser()->isAllowed( 'timetracker-editothers' );
	}


	private function dayLabel( string $day ): string {
		try {
			return ( new DateTime( $day ) )->format( 'l, F j, Y' );
		} catch ( \Exception $e ) {
			return $day;
		}
	}

	private function tableShell( string $head, string $body, string $foot, string $extraClass = '' ): string {
		$cls = 'wikitable tt-timesheet' . ( $extraClass !== '' ? ' ' . $extraClass : '' );
		return Html::rawElement( 'div', [ 'class' => 'tt-ts-wrap' ],
			Html::rawElement( 'table', [ 'class' => $cls ],
				Html::rawElement( 'thead', [], $head )
				. Html::rawElement( 'tbody', [], $body )
				. ( $foot !== '' ? Html::rawElement( 'tfoot', [], $foot ) : '' ) ) );
	}

	private function th( string $msg, string $class = '' ): string {
		return Html::element( 'th', $class ? [ 'class' => $class ] : [], $this->msg( $msg )->text() );
	}

	/** @inheritDoc */
	protected function getGroupName() {
		return 'other';
	}
}
