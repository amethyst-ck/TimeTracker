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
 * Special:TimeReports — a read-only totals report. A filter bar (customer, job,
 * task, user) narrows the entries and a period (week, this/last month, this/last
 * year, or a custom from–to range) sets the window; the result is one total per
 * customer/job/task bucket plus a grand total, with CSV export. Time is entered
 * and edited on the weekly grid on entity/user pages, not here. State lives in
 * GET params so a view is shareable and works without JS.
 */
class SpecialTimeReports extends SpecialPage {

	use FormControls;

	/** Selectable reporting periods. */
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

		$range = $req->getVal( 'range', 'week' );
		if ( !in_array( $range, self::RANGES, true ) ) {
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

		$out->addHTML( $this->renderFilterBar( $customer, $job, $userValue, $range, $period, $jobs, $task ) );
		$out->addHTML( $this->renderSummary( $rows, $names, $userFilter === null ) );
	}

	/**
	 * Stream the filtered entries as a CSV download (Day, Customer, Job, Task,
	 * User, Notes, Hours, Minutes). Duration is split into whole hours and
	 * minutes rather than decimal hours. Uses the same rows as the report.
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
	 * Resolve the reporting window from the chosen range. Week honors the pager
	 * (nav=prev/next) around a weekstart; the month/year presets are fixed
	 * windows; custom reads from/to (swapped if reversed).
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
			$to = ( clone $anchor )->modify( 'last day of this month' );
			return $this->window( $anchor, $to, $anchor->format( 'F Y' ), null );
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
		string $customer, string $job, string $userValue, string $range, array $period, array $jobs, string $task = ''
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

		// Period selector (auto-submits). Custom reveals from/to date inputs.
		$rangeOpts = '';
		foreach ( self::RANGES as $r ) {
			$rangeOpts .= Html::element( 'option',
				[ 'value' => $r ] + ( $r === $range ? [ 'selected' => '' ] : [] ),
				$this->msg( 'timereports-range-' . $r )->text() );
		}
		$form[] = $this->field( 'timereports-filter-period',
			Html::rawElement( 'select', [ 'name' => 'range', 'class' => 'tt-f-period' ], $rangeOpts ) );
		if ( $range === 'custom' ) {
			$form[] = $this->field( 'timereports-from', Html::element( 'input',
				[ 'type' => 'date', 'name' => 'from', 'class' => 'tt-f-from', 'value' => $period['from'] ] ) );
			$form[] = $this->field( 'timereports-to', Html::element( 'input',
				[ 'type' => 'date', 'name' => 'to', 'class' => 'tt-f-to', 'value' => $period['to'] ] ) );
		}

		// Preserve the resolved week so a filter change keeps the current week.
		$hidden = $range === 'week' && $period['weekstart'] !== null
			? Html::hidden( 'weekstart', $period['weekstart'] ) : '';

		// Section 1 — the filters. The selects auto-submit this form on change
		// (see the JS); $hidden carries the current week along.
		$filterBar = Html::rawElement( 'form',
			[ 'method' => 'get', 'action' => $this->getPageTitle()->getLocalURL(), 'class' => 'tt-filter' ],
			implode( '', $form ) . $hidden );

		// Section 2 — the week pager (week range only) or the period label, and
		// CSV export.
		$baseParams = $this->filterParams( $customer, $job, $userValue, $range, $period, $task );
		$csv = Html::element( 'a',
			[ 'href' => $this->getPageTitle()->getLocalURL( [ 'format' => 'csv' ] + $baseParams ),
				'class' => 'mw-ui-button' ],
			$this->msg( 'timereports-export-csv' )->text() );
		$left = $range === 'week'
			? $this->weekPager( $period, $baseParams )
			: Html::element( 'span', [ 'class' => 'tt-period-label' ], $period['label'] );
		$controls = Html::rawElement( 'div', [ 'class' => 'tt-report-controls' ],
			$left . Html::rawElement( 'span', [ 'class' => 'tt-controls-right' ], $csv ) );

		return $filterBar . $controls;
	}

	/** The current filter + period as query params — shared by the CSV link and the pager. */
	private function filterParams(
		string $customer, string $job, string $userValue, string $range, array $period, string $task = ''
	): array {
		$params = [ 'user' => $userValue, 'range' => $range ];
		if ( $customer !== '' ) {
			$params['customer'] = $customer;
		}
		if ( $job !== '' ) {
			$params['job'] = $job;
		}
		if ( $task !== '' ) {
			$params['task'] = $task;
		}
		if ( $range === 'week' && $period['weekstart'] !== null ) {
			$params['weekstart'] = $period['weekstart'];
		} elseif ( $range === 'custom' ) {
			$params['from'] = $period['from'];
			$params['to'] = $period['to'];
		}
		return $params;
	}

	/**
	 * Prev / current-week / Next pager for the week range. Its own form (it sits
	 * in the controls section, outside the filter form) carrying the applied
	 * filters + period as hidden fields so stepping weeks keeps them.
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
		return Html::rawElement( 'form',
			[ 'method' => 'get', 'action' => $this->getPageTitle()->getLocalURL(), 'class' => 'tt-week-pager' ],
			$hidden . $prev . $label . $next );
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

	/* -------------------------------------------------------------- summary */

	/**
	 * Read-only totals for the period: one row per customer/job/task bucket (plus
	 * user when viewing everyone) with its summed hours, ordered by name, and a
	 * grand total. Names resolve from the id maps; the general (task-less) bucket
	 * shows a dash.
	 *
	 * @param array<int,array<string,?string>> $rows
	 * @param array{customers:array<string,string>,jobs:array<string,string>,tasks:array<string,string>} $names
	 * @param bool $allUsers add a User column and key rows by user too
	 */
	private function renderSummary( array $rows, array $names, bool $allUsers ): string {
		$buckets = [];
		foreach ( $rows as $r ) {
			$c = (string)$r['customer'];
			$j = (string)$r['job'];
			$t = (string)$r['task'];
			$u = (string)$r['user'];
			$key = $c . '|' . $j . '|' . $t . ( $allUsers ? '|' . $u : '' );
			$buckets[$key] ??= [ 'customer' => $c, 'job' => $j, 'task' => $t, 'user' => $u, 'hours' => 0.0 ];
			$buckets[$key]['hours'] += (float)$r['duration'];
		}
		if ( !$buckets ) {
			return $this->table->empty( $this->msg( 'timereports-none' )->text() );
		}

		$cn = static fn ( array $b ): string => $names['customers'][$b['customer']] ?? $b['customer'];
		$jn = static fn ( array $b ): string => $names['jobs'][$b['job']] ?? $b['job'];
		$tn = static fn ( array $b ): string => $b['task'] === '' ? '' : ( $names['tasks'][$b['task']] ?? $b['task'] );
		usort( $buckets, static fn ( $a, $b ) => strnatcasecmp( $cn( $a ), $cn( $b ) )
			?: strnatcasecmp( $jn( $a ), $jn( $b ) )
			?: strnatcasecmp( $tn( $a ), $tn( $b ) )
			?: ( $allUsers ? strnatcasecmp( $a['user'], $b['user'] ) : 0 ) );

		$th = fn ( string $key, string $class = '' ): string => Html::element(
			'th', $class !== '' ? [ 'class' => $class ] : [], $this->msg( $key )->text() );
		$head = $th( 'timereports-col-customer' ) . $th( 'timereports-col-job' ) . $th( 'timereports-col-task' )
			. ( $allUsers ? $th( 'timereports-col-user' ) : '' ) . $th( 'timereports-col-hours', 'tt-num' );

		$body = '';
		$grand = 0.0;
		foreach ( $buckets as $b ) {
			$grand += $b['hours'];
			$cells = Html::rawElement( 'td', [ 'data-label' => $this->msg( 'timereports-col-customer' )->text() ],
					$this->table->pageLink( $b['customer'], $cn( $b ) ) )
				. Html::rawElement( 'td', [ 'data-label' => $this->msg( 'timereports-col-job' )->text() ],
					$this->table->pageLink( $b['job'], $jn( $b ) ) )
				. Html::rawElement( 'td', [ 'data-label' => $this->msg( 'timereports-col-task' )->text() ],
					$this->table->taskLabel( $b['task'], $tn( $b ) ) );
			if ( $allUsers ) {
				$cells .= Html::rawElement( 'td', [ 'data-label' => $this->msg( 'timereports-col-user' )->text() ],
					$this->table->pageLink( 'User:' . $b['user'], $b['user'] ) );
			}
			$cells .= Html::element( 'td',
				[ 'class' => 'tt-num', 'data-label' => $this->msg( 'timereports-col-hours' )->text() ],
				Duration::hm( $b['hours'] ) );
			$body .= Html::rawElement( 'tr', [], $cells );
		}

		$foot = Html::rawElement( 'tr', [ 'class' => 'tt-ts-total' ],
			Html::element( 'th', [ 'colspan' => $allUsers ? 4 : 3 ], $this->msg( 'timereports-total' )->text() )
			. Html::element( 'th', [ 'class' => 'tt-num' ], Duration::hm( $grand ) ) );

		// Reuse the timesheet's styled look (.tt-ts-wrap / .tt-timesheet).
		return Html::rawElement( 'div', [ 'class' => 'tt-ts-wrap' ],
			Html::rawElement( 'table', [ 'class' => 'tt-timesheet tt-summary' ],
				Html::rawElement( 'thead', [], Html::rawElement( 'tr', [], $head ) )
				. Html::rawElement( 'tbody', [], $body )
				. Html::rawElement( 'tfoot', [], $foot ) ) );
	}

	/** @inheritDoc */
	protected function getGroupName() {
		return 'other';
	}
}
