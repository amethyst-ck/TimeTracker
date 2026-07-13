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
		'edittime' => [ 'Special:EditTime', '📝' ],
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
		[ 'edittime', 'reports', 'browse' ],
	];

	/** Max rows an entity page's time table shows; the rest live in the reports. */
	private const TIMETABLE_LIMIT = 2000;

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
		$parser->setFunctionHook( 'timetracker_timetable', $this->renderTimeTable( ... ) );
		$parser->setFunctionHook( 'timetracker_total', $this->renderTotal( ... ) );
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
	 * {{#timetracker_timetable:<type>|<id>}} — the time table filtered by
	 * customer / job / user (Day, Customer, Job, User, Notes, Time, edit).
	 */
	public function renderTimeTable( Parser $parser, string $type = '', string $id = '' ): array {
		$this->live( $parser );
		// Fetch one past the display cap so we can tell whether it truncated (the
		// "Total logged" figure counts every entry, so a capped table would
		// otherwise silently disagree with it).
		$entries = $this->query->entriesForFilter( $type, $id, self::TIMETABLE_LIMIT + 1 );
		if ( !$entries ) {
			return $this->timetable( $this->table->empty( $this->msg( 'timetracker-none-time' ) ) );
		}
		$capped = count( $entries ) > self::TIMETABLE_LIMIT;
		if ( $capped ) {
			// entries() is newest-first, so keep the most recent.
			$entries = array_slice( $entries, 0, self::TIMETABLE_LIMIT );
		}
		$customers = $this->query->customers();
		$jobs = $this->query->jobs();
		$tasks = $this->query->tasks();
		// SMW returns entries unordered; sort into a deterministic total order
		// (customer -> job -> task with General first -> day -> user) so rows
		// don't shuffle on reload.
		usort( $entries, static fn ( $a, $b ) => TableRenderer::compareEntries( $a, $b, $customers, $jobs, $tasks ) );
		// Save on the pencil's EditTime should return to this page.
		$page = $parser->getPage();
		$returnTo = $page ? Title::castFromPageReference( $page )->getPrefixedText() : '';
		// Drop the columns that are constant for this page: a customer page's rows
		// are all that customer; a job page's all that job (and customer);
		// a task page's all that task; a user page's all that user.
		$showCustomer = !in_array( $type, [ 'customer', 'job', 'task' ], true );
		$showJob = !in_array( $type, [ 'job', 'task' ], true );
		$showTask = $type !== 'task';
		$showUser = $type !== 'user';
		$rows = '';
		foreach ( $entries as $e ) {
			$cells = Html::element( 'td', [], $e['day'] );
			if ( $showCustomer ) {
				$cells .= Html::rawElement( 'td', [],
					$this->table->pageLink( $e['customer'], $customers[$e['customer']] ?? $e['customer'] ) );
			}
			if ( $showJob ) {
				$cells .= Html::rawElement( 'td', [],
					$this->table->pageLink( $e['job'], $jobs[$e['job']] ?? $e['job'] ) );
			}
			if ( $showTask ) {
				$cells .= Html::rawElement( 'td', [],
					$this->table->pageLink( $e['task'], $tasks[$e['task']] ?? $e['task'] ) );
			}
			if ( $showUser ) {
				$cells .= Html::rawElement( 'td', [], $this->table->userLink( $e['user'] ) );
			}
			$cells .= Html::rawElement( 'td', [], $this->table->notes( $e['notes'] ) )
				. Html::element( 'td', [ 'class' => 'tt-num' ], Duration::hm( (float)$e['duration'] ) )
				. Html::rawElement( 'td', [ 'class' => 'tt-edit' ],
					$this->table->editLink( $e['customer'], $e['job'], $e['task'], $e['day'], $e['user'], $returnTo ) );
			$rows .= Html::rawElement( 'tr', [], $cells );
		}
		$head = '<tr><th>Day</th>'
			. ( $showCustomer ? '<th>Customer</th>' : '' )
			. ( $showJob ? '<th>Job</th>' : '' )
			. ( $showTask ? '<th>Task</th>' : '' )
			. ( $showUser ? '<th>User</th>' : '' )
			. '<th>Notes</th><th class="tt-num">Time</th><th></th></tr>';
		$note = $capped ? $this->cappedNote() : '';
		return $this->timetable( $this->table->scroll(
			'<table class="tt-table sortable">' . $head . $rows . '</table>' ) . $note );
	}

	/** A footnote shown when the time table is capped: the visible rows are only
	 * the most recent, so point at Special:TimeReports for the full history. */
	private function cappedNote(): string {
		$reports = $this->titleFactory->newFromText( 'Special:TimeReports' );
		$label = $this->msg( 'timetracker-link-reports' );
		$link = $reports
			? Html::element( 'a', [ 'href' => $reports->getLocalURL() ], $label )
			: $label;
		return Html::rawElement( 'div', [ 'class' => 'tt-table-note' ],
			wfMessage( 'timetracker-timetable-capped' )->inContentLanguage()
				->numParams( self::TIMETABLE_LIMIT )->rawParams( $link )->escaped() );
	}

	/**
	 * Wrap a time table in a stable container so the timer's post-stop JS can
	 * swap it in place (the inner element differs between the empty and populated
	 * states, so it can't be targeted directly).
	 */
	private function timetable( string $inner ): array {
		return $this->html( Html::rawElement( 'div', [ 'class' => 'tt-timetable' ], $inner ) );
	}

	/** {{#timetracker_total:<type>|<id>}} — the formatted total for a filter. */
	public function renderTotal( Parser $parser, string $type = '', string $id = '' ): string {
		$this->live( $parser );
		return Duration::hm( $this->query->total( $type, $id ) );
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
