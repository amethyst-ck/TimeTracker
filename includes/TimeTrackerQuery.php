<?php

namespace MediaWiki\Extension\TimeTracker;

use SMW\StoreFactory;
use SMWDataItem;
use SMWQueryProcessor;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * Reads the customer / job / time-entry data from the Semantic MediaWiki
 * store, and does the aggregations (totals) in PHP. Customers and jobs are
 * referenced by their stable page id. {@see allUserNames()} reads the core
 * `user` table (there is no property for accounts).
 */
class TimeTrackerQuery {

	private const CAT_CUSTOMERS = 'Category:Customers';
	private const CAT_JOBS = 'Category:Jobs';
	private const CAT_TASKS = 'Category:Tasks';
	private const CAT_ENTRIES = 'Category:Time entries';

	/** Rows fetched per #ask page (kept under $smwgQMaxLimit) and the hard ceiling. */
	private const PAGE_SIZE = 2000;
	private const MAX_ROWS = 100000;

	public function __construct(
		private readonly IConnectionProvider $connectionProvider
	) {
	}

	/**
	 * Run an #ask query and return one row per page: [ 'id' => prefixed title,
	 * '<printout label>' => first value, … ]. Pages through the store so a large
	 * result is never silently truncated at the store's per-query cap; $opts.limit
	 * bounds the total (default: the hard ceiling). Returns [] on any failure
	 * (e.g. SMW not yet populated).
	 *
	 * @param string[] $printouts property names, without the leading '?'
	 * @param array{limit?:int,sort?:string,order?:string} $opts
	 * @return array<int,array<string,string>>
	 */
	private function ask( string $conditions, array $printouts = [], array $opts = [] ): array {
		$max = $opts['limit'] ?? self::MAX_ROWS;
		$pageSize = min( self::PAGE_SIZE, $max );
		$rows = [];
		$offset = 0;
		do {
			$batch = $this->askPage( $conditions, $printouts, $opts, $pageSize, $offset );
			$rows = array_merge( $rows, $batch );
			$offset += $pageSize;
		} while ( count( $batch ) === $pageSize && count( $rows ) < $max );
		return count( $rows ) > $max ? array_slice( $rows, 0, $max ) : $rows;
	}

	/**
	 * One page of an #ask query.
	 *
	 * @param string[] $printouts
	 * @param array{sort?:string,order?:string} $opts
	 * @return array<int,array<string,string>>
	 */
	private function askPage( string $conditions, array $printouts, array $opts, int $limit, int $offset ): array {
		try {
			return $this->runQuery( $conditions, $printouts, $opts, $limit, $offset );
		} catch ( \Throwable $e ) {
			return [];
		}
	}

	/**
	 * One #ask page's rows, throwing if SMW can't answer — so a caller that must
	 * distinguish "no results" from "couldn't check" (see {@see hasJobs}) can.
	 * SMW reports query/store problems via SMWQueryResult::getErrors() with an
	 * empty row set rather than throwing, so that is turned into an exception too.
	 */
	private function runQuery( string $conditions, array $printouts, array $opts, int $limit, int $offset ): array {
		$raw = [ $conditions ];
		foreach ( $printouts as $p ) {
			$raw[] = '?' . $p;
		}
		$raw[] = 'limit=' . $limit;
		$raw[] = 'offset=' . $offset;
		if ( !empty( $opts['sort'] ) ) {
			$raw[] = 'sort=' . $opts['sort'];
			$raw[] = 'order=' . ( $opts['order'] ?? 'asc' );
		}
		[ $queryString, $params, $prints ] = SMWQueryProcessor::getComponentsFromFunctionParams( $raw, false );
		SMWQueryProcessor::addThisPrintout( $prints, $params );
		$params = SMWQueryProcessor::getProcessedParams( $params, $prints );
		$query = SMWQueryProcessor::createQuery( $queryString, $params, SMWQueryProcessor::SPECIAL_PAGE, '', $prints );
		$result = StoreFactory::getStore()->getQueryResult( $query );
		$errors = $result->getErrors();
		if ( $errors ) {
			// A soft query/store error yields empty rows without throwing; surface
			// it so fail-closed callers (delete protection) see "couldn't check".
			throw new \RuntimeException( 'SMW query error: ' . implode( '; ', $errors ) );
		}

		$rows = [];
		while ( ( $row = $result->getNext() ) !== false ) {
			$r = [ 'id' => '' ];
			foreach ( $row as $col ) {
				$subject = $col->getResultSubject();
				if ( $subject && $r['id'] === '' ) {
					$r['id'] = $subject->getTitle()->getPrefixedText();
				}
				$label = $col->getPrintRequest()->getLabel();
				if ( $label === '' ) {
					continue;
				}
				// Collect every value (a multi-valued property can hold several),
				// comma-joined; single-valued properties yield just their one value.
				$vals = [];
				while ( ( $di = $col->getNextDataItem() ) !== false ) {
					$vals[] = $this->itemToString( $di );
				}
				$r[$label] = implode( ',', $vals );
			}
			$rows[] = $r;
		}
		return $rows;
	}

	/** Scalar string for a data item (page id, number, Y-m-d date, or text). */
	private function itemToString( SMWDataItem $di ): string {
		switch ( $di->getDIType() ) {
			case SMWDataItem::TYPE_WIKIPAGE:
				$title = $di->getTitle();
				return $title ? $title->getPrefixedText() : '';
			case SMWDataItem::TYPE_NUMBER:
				return Duration::trim( (float)$di->getNumber() );
			case SMWDataItem::TYPE_TIME:
				$dt = $di->asDateTime();
				return $dt ? $dt->format( 'Y-m-d' ) : '';
			case SMWDataItem::TYPE_BLOB:
				return $di->getString();
			default:
				return '';
		}
	}

	/** Guard an #ask value so it can't break out of the [[Property::value]]. */
	private static function v( string $value ): string {
		// Strip SMW query syntax so a value can't break out of its [[…]] segment:
		// link brackets, the printout pipe, property assignment (::), and the
		// comparison/glob operators. Values are page ids, dates, or user names,
		// none of which legitimately contain these.
		return str_replace( [ ']]', '[[', '|', '::', '<', '>', '~' ], '', $value );
	}

	/** @return array<string,string> Customer page id => name, ordered by name. */
	public function customers(): array {
		$map = [];
		foreach ( $this->ask( '[[' . self::CAT_CUSTOMERS . ']]', [ 'Tt name' ], [ 'sort' => 'Tt name' ] ) as $row ) {
			if ( $row['id'] !== '' ) {
				$map[$row['id']] = $row['Tt name'] ?? '';
			}
		}
		return $map;
	}

	/** @return array<int,array{id:string,name:string,contact:string,notes:string}> */
	public function customerRows(): array {
		$out = [];
		foreach ( $this->ask( '[[' . self::CAT_CUSTOMERS . ']]',
			[ 'Tt name', 'Tt contact', 'Tt notes' ], [ 'sort' => 'Tt name' ] ) as $row ) {
			if ( $row['id'] !== '' ) {
				$out[] = [ 'id' => $row['id'], 'name' => $row['Tt name'] ?? '',
					'contact' => $row['Tt contact'] ?? '', 'notes' => $row['Tt notes'] ?? '' ];
			}
		}
		return $out;
	}

	/**
	 * @param string $customerId restrict to one customer id, or '' for all
	 * @param bool $activeOnly drop archived jobs (for pickers; name-resolution
	 *   callers keep the default so archived entries still resolve to a name)
	 * @return array<string,string> Job page id => name, ordered by name.
	 */
	public function jobs( string $customerId = '', bool $activeOnly = false ): array {
		$cond = '[[' . self::CAT_JOBS . ']]';
		if ( $customerId !== '' ) {
			$cond .= '[[Tt customer::' . self::v( $customerId ) . ']]';
		}
		$map = [];
		foreach ( $this->ask( $cond, [ 'Tt name', 'Tt status' ], [ 'sort' => 'Tt name' ] ) as $row ) {
			if ( $row['id'] === '' || ( $activeOnly && ( $row['Tt status'] ?? '' ) === 'archived' ) ) {
				continue;
			}
			$map[$row['id']] = $row['Tt name'] ?? '';
		}
		return $map;
	}

	/** @return array<int,array{id:string,name:string,status:string,estimate:float}> Jobs of a customer. */
	public function jobRows( string $customerId ): array {
		$out = [];
		foreach ( $this->ask( '[[' . self::CAT_JOBS . ']][[Tt customer::' . self::v( $customerId ) . ']]',
			[ 'Tt name', 'Tt status', 'Tt estimate' ], [ 'sort' => 'Tt name' ] ) as $row ) {
			if ( $row['id'] !== '' ) {
				$out[] = [ 'id' => $row['id'], 'name' => $row['Tt name'] ?? '', 'status' => $row['Tt status'] ?? '',
					'estimate' => (float)( $row['Tt estimate'] ?? 0 ) ];
			}
		}
		return $out;
	}

	/** The customer page id a job belongs to (or '' if unknown). */
	public function jobCustomer( string $jobId ): string {
		$rows = $this->ask( '[[' . self::v( $jobId ) . ']]', [ 'Tt customer' ], [ 'limit' => 1 ] );
		return $rows[0]['Tt customer'] ?? '';
	}

	/** @return array<string,string> Task page id => name, ordered by name. */
	public function tasks(): array {
		$map = [];
		foreach ( $this->ask( '[[' . self::CAT_TASKS . ']]', [ 'Tt name' ], [ 'sort' => 'Tt name' ] ) as $row ) {
			if ( $row['id'] !== '' ) {
				$map[$row['id']] = $row['Tt name'] ?? '';
			}
		}
		return $map;
	}

	/**
	 * @param bool $activeOnly drop archived tasks (for pickers)
	 * @return array<int,array{id:string,name:string,status:string}> A job's tasks.
	 */
	public function tasksOfJob( string $jobId, bool $activeOnly = false ): array {
		$out = [];
		foreach ( $this->ask( '[[' . self::CAT_TASKS . ']][[Tt job::' . self::v( $jobId ) . ']]',
			[ 'Tt name', 'Tt status' ], [ 'sort' => 'Tt name' ] ) as $row ) {
			if ( $row['id'] === '' || ( $activeOnly && ( $row['Tt status'] ?? '' ) === 'archived' ) ) {
				continue;
			}
			$out[] = [ 'id' => $row['id'], 'name' => $row['Tt name'] ?? '', 'status' => $row['Tt status'] ?? '' ];
		}
		return $out;
	}

	/** Whether the task (page id) belongs to the job (page id). */
	public function taskBelongsToJob( string $jobId, string $taskId ): bool {
		return (bool)$this->ask(
			'[[' . self::v( $taskId ) . ']][[Tt job::' . self::v( $jobId ) . ']]', [], [ 'limit' => 1 ] );
	}

	/** Whether a customer has any jobs: true / false, or null if SMW can't
	 * answer (used by delete protection, which must not fail open on error). */
	public function hasJobs( string $customerId ): ?bool {
		return $this->exists( '[[' . self::CAT_JOBS . ']][[Tt customer::' . self::v( $customerId ) . ']]' );
	}

	/** Whether a job has any tasks: true / false, or null if SMW can't answer. */
	public function hasTasks( string $jobId ): ?bool {
		return $this->exists( '[[' . self::CAT_TASKS . ']][[Tt job::' . self::v( $jobId ) . ']]' );
	}

	/**
	 * Whether any time entry references this entity id via $property
	 * ('customer'|'job'|'task'): true / false, or null if SMW can't answer.
	 * Scoped to the Time entries category so a job's own Tt customer annotation
	 * isn't counted as logged time. Zeroed entries are removed, so a match here
	 * always means real time. Used by delete protection (must not fail open).
	 */
	public function hasTime( string $property, string $entityId ): ?bool {
		return $this->exists(
			'[[' . self::CAT_ENTRIES . ']][[Tt ' . $property . '::' . self::v( $entityId ) . ']]' );
	}

	/** True/false whether any page matches, or null if the SMW query errored. */
	private function exists( string $condition ): ?bool {
		try {
			return $this->runQuery( $condition, [], [], 1, 0 ) !== [];
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Customer -> its (active) jobs, each with its (active) tasks, for the
	 * timer and edit-time pickers. Archived jobs/tasks are hidden so new time
	 * isn't logged against them, except $keepJobId / $keepTaskId — the entry
	 * being edited may be on an archived job/task and must stay selectable.
	 *
	 * @return array<string,array{name:string,jobs:array<int,array{id:string,name:string,tasks:array<int,array{id:string,name:string}>}>}>
	 */
	public function customerJobsMap( string $keepJobId = '', string $keepTaskId = '' ): array {
		$customers = $this->customers();
		// Active tasks grouped by their job id (plus a kept archived one).
		$tasksByJob = [];
		foreach ( $this->ask( '[[' . self::CAT_TASKS . ']]',
			[ 'Tt name', 'Tt job', 'Tt status' ], [ 'sort' => 'Tt name' ] ) as $row ) {
			$proj = $row['Tt job'] ?? '';
			$taskId = $row['id'];
			if ( $proj === '' || $taskId === '' ) {
				continue;
			}
			if ( ( $row['Tt status'] ?? '' ) === 'archived' && $taskId !== $keepTaskId ) {
				continue;
			}
			$tasksByJob[$proj][] = [ 'id' => $taskId, 'name' => $row['Tt name'] ?? '' ];
		}

		$map = [];
		foreach ( $this->ask( '[[' . self::CAT_JOBS . ']]',
			[ 'Tt name', 'Tt customer', 'Tt status' ], [ 'sort' => 'Tt name' ] ) as $row ) {
			$custId = $row['Tt customer'] ?? '';
			$projId = $row['id'];
			if ( $custId === '' || $projId === '' || !isset( $customers[$custId] ) ) {
				continue;
			}
			if ( ( $row['Tt status'] ?? '' ) === 'archived' && $projId !== $keepJobId ) {
				continue;
			}
			$map[$custId] ??= [ 'name' => $customers[$custId], 'jobs' => [] ];
			$map[$custId]['jobs'][] = [ 'id' => $projId, 'name' => $row['Tt name'] ?? '',
				'tasks' => $tasksByJob[$projId] ?? [] ];
		}
		uasort( $map, static fn ( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );
		return $map;
	}

	/** Whether the job (page id) belongs to the customer (page id). */
	public function jobBelongsToCustomer( string $customerId, string $jobId ): bool {
		return (bool)$this->ask(
			'[[' . self::v( $jobId ) . ']][[Tt customer::' . self::v( $customerId ) . ']]', [], [ 'limit' => 1 ] );
	}

	/** The human name of a customer/job page id, or the id if not found. */
	public function nameById( string $id ): string {
		$rows = $this->ask( '[[' . self::v( $id ) . ']]', [ 'Tt name' ], [ 'limit' => 1 ] );
		$name = $rows[0]['Tt name'] ?? '';
		return $name !== '' ? $name : $id;
	}

	/**
	 * Time entries filtered by customer/job id, user, and an inclusive day
	 * range. Ordered by day desc.
	 *
	 * @param ?string $user a user name, or null for every user
	 * @return array<int,array{id:string,day:string,customer:string,job:string,user:string,notes:string,duration:string}>
	 */
	public function entries( string $customerId, string $jobId, ?string $user, string $from, string $to, string $task = '' ): array {
		$cond = '[[' . self::CAT_ENTRIES . ']]';
		if ( $from !== '' ) {
			$cond .= '[[Tt day::≥' . self::v( $from ) . ']]';
		}
		if ( $to !== '' ) {
			$cond .= '[[Tt day::≤' . self::v( $to ) . ']]';
		}
		if ( $customerId !== '' ) {
			$cond .= '[[Tt customer::' . self::v( $customerId ) . ']]';
		}
		if ( $jobId !== '' ) {
			$cond .= '[[Tt job::' . self::v( $jobId ) . ']]';
		}
		if ( $task !== '' ) {
			$cond .= '[[Tt task::' . self::v( $task ) . ']]';
		}
		if ( $user !== null && $user !== '' ) {
			$cond .= '[[Tt user::' . self::v( $user ) . ']]';
		}
		return $this->rowsToEntries( $this->ask( $cond,
			[ 'Tt day', 'Tt customer', 'Tt job', 'Tt task', 'Tt user', 'Tt notes', 'Tt duration' ],
			[ 'sort' => 'Tt day', 'order' => 'desc' ] ) );
	}

	/** Sum of duration (hours) over entries matching one filter. */
	public function total( string $filterType, string $filterId ): float {
		[ $c, $p, $u, $tk ] = $this->filterTriple( $filterType, $filterId );
		$sum = 0.0;
		foreach ( $this->entries( $c, $p, $u, '', '', $tk ) as $e ) {
			$sum += (float)$e['duration'];
		}
		return $sum;
	}

	/** Distinct user names that have logged time. @return string[] */
	public function reportUserNames(): array {
		$users = [];
		foreach ( $this->ask( '[[' . self::CAT_ENTRIES . ']]', [ 'Tt user' ] ) as $row ) {
			$u = $row['Tt user'] ?? '';
			if ( $u !== '' ) {
				$users[$u] = true;
			}
		}
		return array_keys( $users );
	}

	/** All registered user names, sorted — for the admin user selector. */
	public function allUserNames(): array {
		$dbr = $this->connectionProvider->getReplicaDatabase();
		return array_values( array_filter( $dbr->newSelectQueryBuilder()
			->select( 'user_name' )->from( 'user' )
			->orderBy( 'user_name' )->caller( __METHOD__ )->fetchFieldValues() ) );
	}

	/** @return array{0:string,1:string,2:?string,3:string} [customerId, jobId, user, taskId] */
	private function filterTriple( string $filterType, string $filterId ): array {
		return [
			$filterType === 'customer' ? $filterId : '',
			$filterType === 'job' ? $filterId : '',
			$filterType === 'user' ? $filterId : null,
			$filterType === 'task' ? $filterId : '',
		];
	}

	/** Normalize ask rows to entry shape (aliasing the Tt-prefixed labels). */
	private function rowsToEntries( array $rows ): array {
		$out = [];
		foreach ( $rows as $row ) {
			$out[] = [
				'id' => $row['id'],
				'day' => $row['Tt day'] ?? '',
				'customer' => $row['Tt customer'] ?? '',
				'job' => $row['Tt job'] ?? '',
				'task' => $row['Tt task'] ?? '',
				'user' => $row['Tt user'] ?? '',
				'notes' => $row['Tt notes'] ?? '',
				'duration' => $row['Tt duration'] ?? '',
			];
		}
		return $out;
	}
}
