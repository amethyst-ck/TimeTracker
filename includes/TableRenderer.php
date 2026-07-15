<?php

namespace MediaWiki\Extension\TimeTracker;

use MediaWiki\Html\Html;
use MediaWiki\Title\TitleFactory;

/**
 * Shared HTML for the time/customer/job tables rendered in PHP: page links,
 * the pencil edit-link, note text, status badges, and the scroll wrapper.
 */
class TableRenderer {

	public function __construct(
		private readonly TitleFactory $titleFactory,
		private readonly EntryWikitext $wikitext
	) {
	}

	/**
	 * Total ordering for entry/row arrays so table rows are deterministic (SMW
	 * returns them unordered): by day (most recent first), then customer name,
	 * job name, task name with the General bucket (empty task) first, then user —
	 * with the page ids as final tie-breakers so rows never shuffle even when two
	 * entities share a name. Day-less tables (day '') fall straight through to the
	 * name keys. $customers/$jobs/$tasks map page id -> display name. Each row
	 * needs customer/job/task keys and, for per-day tables, day/user.
	 *
	 * @param array<string,?string> $a
	 * @param array<string,?string> $b
	 * @param array<string,string> $customers
	 * @param array<string,string> $jobs
	 * @param array<string,string> $tasks
	 */
	public static function compareEntries( array $a, array $b, array $customers, array $jobs, array $tasks ): int {
		$name = static fn ( array $map, string $id ): string => $map[$id] ?? $id;
		$ta = (string)( $a['task'] ?? '' );
		$tb = (string)( $b['task'] ?? '' );
		if ( $ta === '' || $tb === '' ) {
			// General (empty task) first; two Generals fall through to later keys.
			$taskCmp = ( $ta === '' ? 0 : 1 ) <=> ( $tb === '' ? 0 : 1 );
		} else {
			$taskCmp = strcasecmp( $name( $tasks, $ta ), $name( $tasks, $tb ) );
		}
		// Date first (most recent first), then customer -> job -> task (General
		// first) -> user, with the page ids as final tie-breakers so the order is
		// total (rows never shuffle when two entities share a name).
		return strcmp( (string)( $b['day'] ?? '' ), (string)( $a['day'] ?? '' ) )
			?: strcasecmp( $name( $customers, (string)$a['customer'] ), $name( $customers, (string)$b['customer'] ) )
			?: strcasecmp( $name( $jobs, (string)$a['job'] ), $name( $jobs, (string)$b['job'] ) )
			?: $taskCmp
			?: strcmp( (string)( $a['user'] ?? '' ), (string)( $b['user'] ?? '' ) )
			?: strcmp( (string)$a['customer'], (string)$b['customer'] )
			?: strcmp( (string)$a['job'], (string)$b['job'] )
			?: strcmp( $ta, $tb );
	}

	/**
	 * The editable weekly grid: rows = customer/job/task (+ user when the grid
	 * spans users), columns = the week's days, cells = H:MM inputs that auto-save
	 * via the timetrackersetcell API (see ext.timetracker.grid). Shared by
	 * Special:TimeReports and the {{#timetracker_grid}} parser function.
	 *
	 * @param array<int,array<string,?string>> $rows entry rows
	 * @param array{customers:array<string,string>,jobs:array<string,string>,tasks:array<string,string>} $names
	 * @param array<int,array{ymd:string,label:string}> $days the week's days
	 * @param string $viewer acting user name
	 * @param bool $isAdmin whether the viewer may edit others' time
	 * @param ?string $gridUser the single user the grid is for (null = all users)
	 * @param array<string,mixed> $addMap customer→job→task map for the add-row picker ([] = none)
	 */
	public function renderGrid(
		array $rows, array $names, array $days, string $viewer, bool $isAdmin,
		?string $gridUser, array $addMap, array $fixed = []
	): string {
		$allUsers = $gridUser === null;
		$nameCols = $allUsers ? 4 : 3;
		$canEdit = static fn ( string $u ): bool => $u === $viewer || $isAdmin;

		$cells = [];
		$meta = [];
		foreach ( $rows as $r ) {
			$m = [ 'customer' => (string)$r['customer'], 'job' => (string)$r['job'],
				'task' => (string)$r['task'], 'user' => (string)$r['user'] ];
			$key = implode( '|', $m );
			$meta[$key] ??= $m;
			$cells[$key][(string)$r['day']] = ( $cells[$key][(string)$r['day']] ?? 0.0 ) + (float)$r['duration'];
		}
		// A fully-scoped grid (a task page fixes customer + job + task) has exactly
		// one bucket. Show it as an editable row straight away: with no time yet
		// there is nothing to "add", so the row stands in for the Add button.
		$fullyFixed = $gridUser !== null && $canEdit( (string)$gridUser )
			&& isset( $fixed['customer'], $fixed['job'], $fixed['task'] );
		if ( $fullyFixed ) {
			$m = [ 'customer' => (string)$fixed['customer'][0], 'job' => (string)$fixed['job'][0],
				'task' => (string)$fixed['task'][0], 'user' => (string)$gridUser ];
			$meta[ implode( '|', $m ) ] ??= $m;
		}
		uasort( $meta, fn ( $a, $b ) => self::compareEntries( $a, $b, $names['customers'], $names['jobs'], $names['tasks'] ) );

		$head = $this->gridTh( 'timereports-col-customer' ) . $this->gridTh( 'timereports-col-job' )
			. $this->gridTh( 'timereports-col-task' )
			. ( $allUsers ? $this->gridTh( 'timereports-col-user' ) : '' );
		foreach ( $days as $d ) {
			$head .= Html::element( 'th', [ 'class' => 'tt-num tt-g-day' ], $d['label'] );
		}
		$head .= $this->gridTh( 'timereports-total', 'tt-num' );

		$colTotals = array_fill_keys( array_column( $days, 'ymd' ), 0.0 );
		$grand = 0.0;
		$body = '';
		foreach ( $meta as $key => $m ) {
			$editable = $canEdit( $m['user'] );
			$rowTotal = 0.0;
			$dayCells = '';
			foreach ( $days as $d ) {
				$val = $cells[$key][$d['ymd']] ?? 0.0;
				$rowTotal += $val;
				$colTotals[$d['ymd']] += $val;
				$dayCells .= $this->gridCell( $val, $m, $d['ymd'], $d['label'], $editable );
			}
			$grand += $rowTotal;
			$body .= Html::rawElement( 'tr', [], $this->gridNameCells( $m, $names, $allUsers ) . $dayCells
				. Html::element( 'td', [ 'class' => 'tt-num tt-g-rowtot',
					'data-label' => wfMessage( 'timereports-total' )->text() ], Duration::hm( $rowTotal ) ) );
		}
		if ( $body === '' ) {
			$body = Html::rawElement( 'tr', [ 'class' => 'tt-g-none' ],
				Html::rawElement( 'td', [ 'colspan' => $nameCols + count( $days ) + 1, 'class' => 'tt-empty' ],
					wfMessage( 'timereports-none' )->text() ) );
		}

		$footDays = '';
		foreach ( $days as $d ) {
			$footDays .= Html::element( 'th', [ 'class' => 'tt-num tt-g-daytot', 'data-day' => $d['ymd'] ],
				Duration::hm( $colTotals[$d['ymd']] ) );
		}
		$foot = Html::rawElement( 'tr', [ 'class' => 'tt-ts-total' ],
			Html::rawElement( 'th', [ 'colspan' => $nameCols, 'class' => 'tt-g-foot-label' ],
				wfMessage( 'timereports-total' )->text() )
			. $footDays . Html::element( 'th', [ 'class' => 'tt-num tt-g-grand',
				'data-label' => wfMessage( 'timereports-total' )->text() ], Duration::hm( $grand ) ) );

		$table = Html::rawElement( 'div', [ 'class' => 'tt-ts-wrap' ],
			Html::rawElement( 'table', [ 'class' => 'wikitable tt-timesheet tt-grid' ],
				Html::rawElement( 'thead', [], Html::rawElement( 'tr', [], $head ) )
				. Html::rawElement( 'tbody', [], $body )
				. Html::rawElement( 'tfoot', [], $foot ) ) );

		// No add-row on a fully-scoped grid: its one bucket is already shown above.
		$canAdd = !$allUsers && $canEdit( (string)$gridUser ) && ( $addMap || $fixed ) && !$fullyFixed;
		$wrap = [ 'class' => 'tt-grid-wrap' ];
		$add = '';
		if ( $canAdd ) {
			$wrap['data-tt-jobs'] = json_encode( $this->gridJobsJson( $addMap ) );
			$wrap['data-tt-days'] = json_encode( array_column( $days, 'ymd' ) );
			$wrap['data-tt-user'] = (string)$gridUser;
			$add = $this->gridAddRow( $addMap, $fixed );
		}
		return Html::rawElement( 'div', $wrap, $table . $add );
	}

	private function gridTh( string $msg, string $class = '' ): string {
		return Html::element( 'th', $class ? [ 'class' => $class ] : [], wfMessage( $msg )->text() );
	}

	private function gridCell( float $val, array $m, string $ymd, string $dayLabel, bool $editable ): string {
		$td = [ 'class' => 'tt-num tt-g-cell', 'data-day' => $ymd,
			'data-label' => $dayLabel, 'data-h' => Duration::trim( $val ) ];
		if ( !$editable ) {
			return Html::element( 'td', $td, $val > 0 ? Duration::hm( $val ) : '' );
		}
		[ $h, $mm ] = Duration::hoursMinutes( $val );
		return Html::rawElement( 'td', $td, Html::element( 'input', [
			'type' => 'text', 'class' => 'tt-g-in', 'autocomplete' => 'off', 'inputmode' => 'text',
			'value' => $val > 0 ? sprintf( '%d:%02d', $h, $mm ) : '',
			'data-c' => $m['customer'], 'data-j' => $m['job'], 'data-k' => $m['task'], 'data-u' => $m['user'],
		] ) );
	}

	private function gridNameCells( array $m, array $names, bool $allUsers ): string {
		// data-label backs the stacked mobile layout (see the grid CSS).
		return Html::rawElement( 'td', [ 'data-label' => wfMessage( 'timereports-col-customer' )->text() ],
				$this->pageLink( $m['customer'], $names['customers'][$m['customer']] ?? $m['customer'] ) )
			. Html::rawElement( 'td', [ 'data-label' => wfMessage( 'timereports-col-job' )->text() ],
				$this->pageLink( $m['job'], $names['jobs'][$m['job']] ?? $m['job'] ) )
			. Html::rawElement( 'td', [ 'data-label' => wfMessage( 'timereports-col-task' )->text() ],
				$this->taskLabel( $m['task'], $names['tasks'][$m['task']] ?? $m['task'] ) )
			. ( $allUsers ? Html::rawElement( 'td', [ 'data-label' => wfMessage( 'timereports-col-user' )->text() ],
				$this->pageLink( 'User:' . $m['user'], $m['user'] ) ) : '' );
	}

	/**
	 * The add-row picker. Dimensions the page fixes (a customer page fixes the
	 * customer, a job page the customer + job, a task page all three) get no
	 * dropdown — their ids/names ride on data-fix-* for the JS; the rest are
	 * selects the JS fills/cascades. A task page shows just the Add button.
	 *
	 * @param array<string,mixed> $map customer→job→task picker data
	 * @param array<string,array{0:string,1:string}> $fixed dim => [id, name] for fixed dims
	 */
	private function gridAddRow( array $map, array $fixed ): string {
		$selects = '';
		if ( !isset( $fixed['customer'] ) ) {
			$custOpts = '';
			foreach ( $map as $id => $info ) {
				$custOpts .= Html::element( 'option', [ 'value' => (string)$id ], $info['name'] ?? (string)$id );
			}
			$selects .= Html::rawElement( 'select', [ 'class' => 'tt-customer' ], $custOpts ) . ' ';
		}
		if ( !isset( $fixed['job'] ) ) {
			$selects .= Html::rawElement( 'select', [ 'class' => 'tt-job' ], '' ) . ' ';
		}
		if ( !isset( $fixed['task'] ) ) {
			$selects .= Html::rawElement( 'select', [ 'class' => 'tt-task' ], '' ) . ' ';
		}
		$attrs = [ 'class' => 'tt-g-addrow' ];
		foreach ( [ 'customer' => 'c', 'job' => 'j', 'task' => 'k' ] as $dim => $short ) {
			if ( isset( $fixed[$dim] ) ) {
				$attrs["data-fix-$short"] = $fixed[$dim][0];
				$attrs["data-fix-{$short}name"] = $fixed[$dim][1];
			}
		}
		return Html::rawElement( 'div', $attrs,
			Html::element( 'span', [ 'class' => 'tt-g-addlabel' ], wfMessage( 'timetracker-grid-addrow' )->text() )
			. ' ' . $selects
			. Html::element( 'button', [ 'type' => 'button', 'class' => 'tt-g-add-go mw-ui-button mw-ui-progressive' ],
				wfMessage( 'timetracker-grid-add' )->text() ) );
	}

	/** customer→job→task map for the JS cascade: { custId: [ [jobId, name, [[taskId, name]…]]…] }. */
	private function gridJobsJson( array $map ): array {
		$out = [];
		foreach ( $map as $custId => $info ) {
			$jobs = [];
			foreach ( $info['jobs'] ?? [] as $job ) {
				$jobs[] = [ $job['id'], $job['name'],
					array_map( static fn ( $t ) => [ $t['id'], $t['name'] ], $job['tasks'] ?? [] ) ];
			}
			$out[(string)$custId] = $jobs;
		}
		return $out;
	}

	/** Task cell content: a link to the task, or the general-bucket marker
	 * (a dash) for the job's default, task-less bucket. */
	public function taskLabel( string $taskId, string $name ): string {
		return $taskId === ''
			? htmlspecialchars( wfMessage( 'timetracker-task-general' )->text() )
			: $this->pageLink( $taskId, $name );
	}

	/** A link to a page by id, labeled $label; '—' if blank. */
	public function pageLink( string $id, string $label ): string {
		if ( $id === '' ) {
			return '—';
		}
		$title = $this->titleFactory->newFromText( $id );
		return $title
			? Html::element( 'a', [ 'href' => $title->getLocalURL() ], $label !== '' ? $label : $id )
			: htmlspecialchars( $label !== '' ? $label : $id );
	}

	/** Render a stored note: decode the storage markers, keep line breaks, escape HTML. */
	public function notes( string $stored ): string {
		return nl2br( htmlspecialchars( $this->wikitext->unfoldNewlines( $stored ) ) );
	}

	/** Status pill; the label is localised, the modifier class keeps the raw key. */
	public function statusBadge( string $status ): string {
		$status = strtolower( $status !== '' ? $status : 'active' );
		$msg = wfMessage( 'timetracker-status-' . $status )->inContentLanguage();
		return Html::element( 'span', [ 'class' => 'tt-badge tt-badge-' . $status ],
			$msg->exists() ? $msg->text() : $status );
	}

	/** Wrap a table in the scrollable, bordered container. */
	public function scroll( string $tableHtml ): string {
		return Html::rawElement( 'div', [ 'class' => 'tt-table-scroll' ], $tableHtml );
	}

	/** An empty-state message. */
	public function empty( string $message ): string {
		return Html::element( 'div', [ 'class' => 'tt-empty' ], $message );
	}
}
