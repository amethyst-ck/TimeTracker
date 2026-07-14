<?php

namespace MediaWiki\Extension\TimeTracker\Hooks;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\TimeTracker\Duration;
use MediaWiki\Extension\TimeTracker\TableRenderer;
use MediaWiki\Extension\TimeTracker\TimerWidget;
use MediaWiki\Extension\TimeTracker\Timezone;
use MediaWiki\Extension\TimeTracker\TimeTrackerQuery;
use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\Html\Html;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use Parser;

/**
 * The {{#timetracker_*}} parser functions used by the templates, the dashboard,
 * and the individual launcher tiles.
 */
class ParserHooks implements ParserFirstCallInitHook {

	/**
	 * The launcher tiles, in dashboard order: key => [ target page, icon ]. The
	 * label + sub-label are the messages timetracker-dash-<key>-label / -sub.
	 */
	private const TILES = [
		'newcustomer' => [ 'Special:FormEdit/Customer', '🏢' ],
		'newjob' => [ 'Special:FormEdit/Job', '📁' ],
		'newtask' => [ 'Special:FormEdit/Task', '📋' ],
		'browse' => [ 'Customers', '🏙️' ],
		'reports' => [ 'Special:TimeReports', '📊' ],
	];

	/**
	 * The dashboard grid, row by row (3 columns). Timers start from a job or
	 * task page, and entities are created from their parent (customers from
	 * Browse, jobs from a customer, tasks from a job), so there are no Start
	 * or New tiles here. null is a blank cell (supported, unused).
	 */
	private const DASHBOARD_LAYOUT = [
		[ 'reports', 'browse' ],
	];

	public function __construct(
		private readonly TitleFactory $titleFactory,
		private readonly Timezone $timezone,
		private readonly TimeTrackerQuery $query,
		private readonly TableRenderer $table,
		private readonly TimerWidget $widget
	) {
	}

	/** @inheritDoc */
	public function onParserFirstCallInit( $parser ) {
		$parser->setFunctionHook( 'timetracker_format_duration', $this->renderFormatDuration( ... ) );
		$parser->setFunctionHook( 'timetracker_dashboard', $this->renderDashboard( ... ) );
		$parser->setFunctionHook( 'timetracker_tile', $this->renderTile( ... ) );
		$parser->setFunctionHook( 'timetracker_jobtimer', $this->renderJobTimer( ... ) );
		$parser->setFunctionHook( 'timetracker_job_customer', $this->renderJobCustomer( ... ) );
		$parser->setFunctionHook( 'timetracker_timer', $this->renderTimer( ... ) );
		$parser->setFunctionHook( 'timetracker_customers', $this->renderCustomers( ... ) );
		$parser->setFunctionHook( 'timetracker_jobs', $this->renderJobs( ... ) );
		$parser->setFunctionHook( 'timetracker_tasks', $this->renderTasks( ... ) );
		$parser->setFunctionHook( 'timetracker_grid', $this->renderGrid( ... ) );
		$parser->setFunctionHook( 'timetracker_progress', $this->renderProgress( ... ) );
	}

	/**
	 * {{#timetracker_timer:}} — the Start/Stop timer, inline on a content page
	 * (e.g. a user's home). Renders the VIEWER's live state, so the parser cache
	 * is disabled for the page; the form posts to Special:TimeTracker and returns
	 * here after. @return array{0:string,isHTML:bool,noparse:bool}
	 */
	public function renderTimer( Parser $parser ): array {
		$out = $parser->getOutput();
		$out->updateCacheExpiry( 0 );
		$out->addModules( [ 'ext.timetracker.timer' ] );
		$out->addModuleStyles( [ 'ext.timetracker.timer' ] );
		$returnTo = Title::castFromPageReference( $parser->getPage() );
		return $this->html( $this->widget->render( RequestContext::getMain(), $returnTo ) );
	}

	/**
	 * {{#timetracker_grid:<scope>|<id>}} — the editable weekly grid embedded on an
	 * entity or user page, scoped to that entity for the viewing user (a user page
	 * shows that user's whole week). Cells are read-only for anyone who can't edit
	 * the shown user's time.
	 */
	public function renderGrid( Parser $parser, string $scope = '', string $id = '', string $arg = '' ): array {
		$out = $parser->getOutput();
		$out->updateCacheExpiry( 0 );
		$out->addModules( [ 'ext.timetracker.grid' ] );
		$ctx = RequestContext::getMain();
		$viewer = $ctx->getUser();
		$anchor = trim( $ctx->getRequest()->getVal( 'ttweek', '' ) );
		$days = $this->timezone->weekDays( $anchor !== '' ? $anchor : null );
		$from = $days[0]['ymd'];
		$to = $days[count( $days ) - 1]['ymd'];
		$id = trim( $id );
		$arg = trim( $arg );
		$scope = trim( $scope );
		[ $customer, $job, $task, $gridUser ] = match ( $scope ) {
			'customer' => [ $id, '', '', $viewer->getName() ],
			'job' => [ '', $id, '', $viewer->getName() ],
			'task' => [ '', '', $id, $viewer->getName() ],
			'user' => [ '', '', '', $id !== '' ? $id : $viewer->getName() ],
			default => [ '', '', '', $viewer->getName() ],
		};
		$rows = $this->query->entries( $customer, $job, $gridUser, $from, $to, $task );
		$names = [
			'customers' => $this->query->customers(),
			'jobs' => $this->query->jobs(),
			'tasks' => $this->query->tasks(),
		];
		$page = Title::castFromPageReference( $parser->getPage() );
		return $this->html( ( $page ? $this->weekNav( $page, $days ) : '' ) . $this->table->renderGrid(
			$rows, $names, $days, $viewer->getName(),
			$viewer->isAllowed( 'timetracker-editothers' ),
			$gridUser, $this->gridAddMap( $scope, $id ),
			$this->gridFixed( $scope, $id, $arg, $names ) ) );
	}

	/** Prev/next week buttons + a date picker above an embedded grid; navigation
	 * reloads the page with the chosen week's Monday in ?ttweek. */
	private function weekNav( Title $page, array $days ): string {
		$monday = $days[0]['ymd'];
		$prev = ( new \DateTime( $monday ) )->modify( '-7 days' )->format( 'Y-m-d' );
		$next = ( new \DateTime( $monday ) )->modify( '+7 days' )->format( 'Y-m-d' );
		$label = ( new \DateTime( $monday ) )->format( 'M j' ) . ' – ' . ( new \DateTime( $days[6]['ymd'] ) )->format( 'M j' );
		$btn = static fn ( string $mon, string $text ): string => Html::element( 'a',
			[ 'href' => $page->getLocalURL( [ 'ttweek' => $mon ] ), 'class' => 'mw-ui-button tt-g-weekbtn' ], $text );
		return Html::rawElement( 'div', [ 'class' => 'tt-g-weeknav' ],
			$btn( $prev, '◀' )
			. Html::element( 'span', [ 'class' => 'tt-g-weeklabel' ], $label )
			. $btn( $next, '▶' )
			. Html::element( 'input', [ 'type' => 'date', 'class' => 'tt-g-weekpick', 'value' => $monday ] ) );
	}

	/**
	 * The dimensions an embedded grid's page fixes, as dim => [id, name]: a
	 * customer page fixes the customer, a job page the customer + job, a task
	 * page all three (its job comes in as $arg). Nothing is fixed on a user page.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	private function gridFixed( string $scope, string $id, string $arg, array $names ): array {
		$cust = static fn ( string $c ): array => [ $c, $names['customers'][$c] ?? $c ];
		$job = static fn ( string $j ): array => [ $j, $names['jobs'][$j] ?? $j ];
		switch ( $scope ) {
			case 'customer':
				return [ 'customer' => $cust( $id ) ];
			case 'job':
				return [ 'customer' => $cust( $this->query->jobCustomer( $id ) ), 'job' => $job( $id ) ];
			case 'task':
				return [
					'customer' => $cust( $this->query->jobCustomer( $arg ) ),
					'job' => $job( $arg ),
					'task' => [ $id, $names['tasks'][$id] ?? $id ],
				];
			default:
				return [];
		}
	}

	/**
	 * The add-row picker map for an embedded grid, scoped to the entity: the whole
	 * customer→job→task map for a user page, that customer's jobs for a customer
	 * page, just that job's tasks for a job page, and nothing for a single task.
	 *
	 * @return array<string,mixed>
	 */
	private function gridAddMap( string $scope, string $id ): array {
		$map = $this->query->customerJobsMap();
		switch ( $scope ) {
			case 'customer':
				return isset( $map[$id] ) ? [ $id => $map[$id] ] : [];
			case 'job':
				$cust = $this->query->jobCustomer( $id );
				if ( $cust === '' || !isset( $map[$cust] ) ) {
					return [];
				}
				$entry = $map[$cust];
				$entry['jobs'] = array_values( array_filter( $entry['jobs'] ?? [],
					static fn ( $j ) => $j['id'] === $id ) );
				return [ $cust => $entry ];
			case 'task':
				return [];
			default:
				return $map;
		}
	}

	/**
	 * {{#timetracker_jobtimer:<jobId>}} — on a job (or task) page: the
	 * viewer's running-timer card if one is active, else a Start-timer tile
	 * pre-filled for this job. @return array{0:string,isHTML:bool,noparse:bool}
	 */
	public function renderJobTimer( Parser $parser, string $jobId = '', string $taskId = '' ): array {
		$this->live( $parser );
		$out = $parser->getOutput();
		// The timer module has scripts, so its CSS must load via addModules —
		// addModuleStyles alone does not reliably load a script module's styles.
		// Every branch below (card, note, start form) needs that CSS.
		$out->addModules( [ 'ext.timetracker.timer' ] );
		$out->addModuleStyles( [ 'ext.timetracker.timer' ] );
		$ctx = RequestContext::getMain();
		$returnTo = Title::castFromPageReference( $parser->getPage() );
		$running = $this->widget->running( $ctx );

		// A user has at most one timer, and it belongs to one job/task. Show
		// the running card only on that exact page; elsewhere it would be someone
		// else's context, so don't surface it here.
		if ( $running !== null && $running['job'] === $jobId && $running['task'] === $taskId ) {
			return $this->html( $this->widget->runningCard( $ctx, $returnTo ) );
		}

		// A timer is running on something else: don't offer to start another (only
		// one at a time), just point to where it can be viewed and stopped.
		if ( $running !== null ) {
			return $this->html( $this->widget->runningElsewhereNote( $ctx ) );
		}

		// Idle: an inline Start form, fixed to this job's General bucket
		// (job page, task='') or this task — timing is started right here.
		return $this->html( $this->widget->renderJobStart( $ctx, $jobId, $taskId, $returnTo ) );
	}

	/** {{#timetracker_customers:}} — the Customers listing table. */
	public function renderCustomers( Parser $parser ): array {
		$this->live( $parser );
		$rows = '';
		foreach ( $this->query->customerRows() as $c ) {
			$rows .= Html::rawElement( 'tr', [],
				Html::rawElement( 'td', [], $this->table->pageLink( $c['id'], $c['name'] ) )
				. Html::element( 'td', [], $c['contact'] )
				. Html::rawElement( 'td', [], $this->table->notes( $c['notes'] ) ) );
		}
		if ( $rows === '' ) {
			return $this->html( $this->table->empty( $this->msg( 'timetracker-none-customers' ) ) );
		}
		$head = '<tr><th>Customer</th><th>Contact</th><th>Notes</th></tr>';
		return $this->html( $this->table->scroll(
			'<table class="tt-table sortable">' . $head . $rows . '</table>' ) );
	}

	/** {{#timetracker_jobs:<customerId>}} — a customer's jobs table. */
	public function renderJobs( Parser $parser, string $customerId = '' ): array {
		$this->live( $parser );
		$rows = '';
		foreach ( $this->query->jobRows( $customerId ) as $p ) {
			$logged = $this->query->total( 'job', $p['id'] );
			$progress = $p['estimate'] > 0
				? Duration::hm( $logged ) . ' / ' . Duration::hm( $p['estimate'] )
				: Duration::hm( $logged );
			$rows .= Html::rawElement( 'tr', [],
				Html::rawElement( 'td', [], $this->table->pageLink( $p['id'], $p['name'] ) )
				. Html::rawElement( 'td', [], $this->table->statusBadge( $p['status'] ) )
				. Html::element( 'td', [ 'class' => 'tt-num' ], $progress ) );
		}
		if ( $rows === '' ) {
			return $this->html( $this->table->empty( $this->msg( 'timetracker-none-jobs' ) ) );
		}
		$head = '<tr><th>Job</th><th>Status</th><th class="tt-num">Logged / Est.</th></tr>';
		return $this->html( $this->table->scroll(
			'<table class="tt-table sortable">' . $head . $rows . '</table>' ) );
	}

	/** {{#timetracker_tasks:<jobId>}} — a job's tasks table. */
	public function renderTasks( Parser $parser, string $jobId = '' ): array {
		$this->live( $parser );
		$rows = '';
		foreach ( $this->query->tasksOfJob( $jobId ) as $t ) {
			$rows .= Html::rawElement( 'tr', [],
				Html::rawElement( 'td', [], $this->table->pageLink( $t['id'], $t['name'] ) )
				. Html::rawElement( 'td', [], $this->table->statusBadge( $t['status'] ) )
				. Html::element( 'td', [ 'class' => 'tt-num' ], Duration::hm( $this->query->total( 'task', $t['id'] ) ) ) );
		}
		if ( $rows === '' ) {
			return $this->html( $this->table->empty( $this->msg( 'timetracker-none-tasks' ) ) );
		}
		$head = '<tr><th>Task</th><th>Status</th><th class="tt-num">Logged</th></tr>';
		return $this->html( $this->table->scroll(
			'<table class="tt-table sortable">' . $head . $rows . '</table>' ) );
	}

	/**
	 * {{#timetracker_progress:<jobId>}} — the job's logged time against its
	 * estimate (a bar + "X of Y (Z%)"), or just the total when no estimate is set.
	 *
	 * @return array{0:string,isHTML:bool,noparse:bool}
	 */
	public function renderProgress( Parser $parser, string $jobId = '', string $estimateArg = '' ): array {
		$this->live( $parser );
		$logged = $this->query->total( 'job', $jobId );
		// The estimate is passed in from the job template ({{{estimate}}}), not
		// read from SMW, so an estimate edit shows in the bar immediately instead
		// of lagging behind SMW's post-save reindex.
		$estimate = (float)$estimateArg;
		if ( $estimate <= 0 ) {
			return $this->html( Html::rawElement( 'div', [ 'class' => 'tt-total' ],
				'Total logged' . Html::element( 'span', [ 'class' => 'tt-total-value' ], Duration::hm( $logged ) ) ) );
		}
		$pct = (int)round( $logged / $estimate * 100 );
		$fill = Html::element( 'div', [
			'class' => 'tt-bar-fill' . ( $logged > $estimate ? ' tt-bar-over' : '' ),
			'style' => 'width:' . min( 100, max( 0, $pct ) ) . '%',
		], '' );
		return $this->html( Html::rawElement( 'div', [ 'class' => 'tt-progress' ],
			Html::rawElement( 'div', [ 'class' => 'tt-bar' ], $fill )
			. Html::element( 'span', [ 'class' => 'tt-progress-text' ],
				Duration::hm( $logged ) . ' of ' . Duration::hm( $estimate ) . ' (' . $pct . '%)' ) ) );
	}

	/**
	 * Mark the page uncacheable. These tables/totals read live SMW data that SMW
	 * re-indexes asynchronously (via the job queue) after an entry changes, so a
	 * cached render would show stale figures with no reliable moment to purge it.
	 * Re-querying on each view (like the timer widget and Special:TimeReports) is
	 * the dependable way to stay current.
	 */
	private function live( Parser $parser ): void {
		$parser->getOutput()->updateCacheExpiry( 0 );
	}

	/** Wrap raw HTML for a parser-function return. */
	private function html( string $html ): array {
		return [ $html, 'isHTML' => true, 'noparse' => true ];
	}

	/** A message text (content language). */
	private function msg( string $key ): string {
		return wfMessage( $key )->inContentLanguage()->text();
	}

	/**
	 * {{#timetracker_dashboard:}} — the launcher-tile grid. Raw HTML because the
	 * wikitext sanitizer strips the <a> tags a fully-clickable tile needs.
	 *
	 * @return array{0:string,isHTML:bool,noparse:bool}
	 */
	public function renderDashboard( Parser $parser ): array {
		$parser->getOutput()->addModuleStyles( [ 'ext.timetracker.dashboard' ] );
		$tileHtml = '';
		foreach ( self::DASHBOARD_LAYOUT as $row ) {
			foreach ( $row as $key ) {
				if ( $key === null ) {
					$tileHtml .= Html::element( 'div', [ 'class' => 'tt-dash-blank', 'aria-hidden' => 'true' ], '' );
					continue;
				}
				[ $target, $icon ] = self::TILES[$key];
				$tileHtml .= $this->tileHtml( $key, $target, $icon );
			}
		}
		return [
			Html::rawElement( 'div', [ 'class' => 'tt-dash-grid' ], $tileHtml ),
			'isHTML' => true,
			'noparse' => true,
		];
	}

	/**
	 * {{#timetracker_tile:<key>[|<arg>]}} — one launcher tile for a content page.
	 * For "newjob", <arg> is a customer page id that pre-fills the form.
	 *
	 * @return array{0:string,isHTML:bool,noparse:bool}
	 */
	public function renderTile( Parser $parser, string $key = '', string $arg = '' ): array {
		$parser->getOutput()->addModuleStyles( [ 'ext.timetracker.dashboard' ] );
		if ( !isset( self::TILES[$key] ) ) {
			return [ '', 'isHTML' => true, 'noparse' => true ];
		}
		[ $target, $icon ] = self::TILES[$key];
		$query = [];
		if ( $key === 'newjob' && $arg !== '' ) {
			$query = [ 'Job[customer]' => $arg ];
		} elseif ( $key === 'newtask' && $arg !== '' ) {
			$query = [ 'Task[job]' => $arg ];
		}
		// An inline action button (icon + label on one line), not a dashboard tile.
		$title = $this->titleFactory->newFromText( $target );
		return $this->html( Html::rawElement( 'a',
			[ 'href' => $title ? $title->getLocalURL( $query ) : '#', 'class' => 'tt-newbtn' ],
			Html::element( 'span', [ 'class' => 'tt-newbtn-icon' ], $icon )
			. Html::element( 'span', [], wfMessage( "timetracker-dash-$key-label" )->inContentLanguage()->text() ) ) );
	}

	/** One clickable tile: icon + message label + message sub-label. */
	private function tileHtml( string $key, string $target, string $icon, array $query = [] ): string {
		$title = $this->titleFactory->newFromText( $target );
		return Html::rawElement( 'a',
			[ 'href' => $title ? $title->getLocalURL( $query ) : '#', 'class' => 'tt-dash-tile' ],
			Html::element( 'span', [ 'class' => 'tt-dash-icon' ], $icon )
			. Html::element( 'span', [ 'class' => 'tt-dash-label' ],
				wfMessage( "timetracker-dash-$key-label" )->inContentLanguage()->text() )
			. Html::element( 'span', [ 'class' => 'tt-dash-sub' ],
				wfMessage( "timetracker-dash-$key-sub" )->inContentLanguage()->text() )
		);
	}

	/** {{#timetracker_job_customer:<jobId>}} — the job's customer as a
	 * link (via DisplayTitle), or a dash. Lets a task page show its customer, which
	 * is stored on the job, not the task. */
	public function renderJobCustomer( Parser $parser, string $jobId = '' ): string {
		$customerId = $jobId !== '' ? $this->query->jobCustomer( $jobId ) : '';
		return $customerId !== '' ? '[[' . $customerId . ']]' : '—';
	}

	/** {{#timetracker_format_duration:HOURS}} — decimal hours as "2h 15m". */
	public function renderFormatDuration( Parser $parser, string $hours = '' ): string {
		return Duration::hm( $hours );
	}
}
