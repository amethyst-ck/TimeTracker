<?php

namespace MediaWiki\Extension\TimeTracker\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Extension\TimeTracker\Duration;
use MediaWiki\Extension\TimeTracker\EntryStore;
use MediaWiki\Extension\TimeTracker\TimeTrackerQuery;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * action=timetrackersetcell — set one timesheet cell (a customer/job/task/day/
 * user bucket) to a typed total, for the weekly grid's inline auto-save. The
 * cell fixes the bucket, so there is no reassignment: the value is simply set
 * (0/empty removes the entry). Owner-or-admin only; a forged user resolves back
 * to the acting user. The grid does not edit notes, so any existing note is kept.
 */
class ApiSetCell extends ApiBase {

	public function __construct(
		$mainModule,
		$moduleName,
		private readonly EntryStore $entryStore,
		private readonly TimeTrackerQuery $query
	) {
		parent::__construct( $mainModule, $moduleName );
	}

	/** @inheritDoc */
	public function execute() {
		$user = $this->getUser();
		if ( !$user->isRegistered() ) {
			$this->dieWithError( 'apierror-mustbeloggedin-generic', 'notloggedin' );
		}
		$params = $this->extractRequestParams();
		$customer = trim( (string)$params['customer'] );
		$job = trim( (string)$params['job'] );
		$task = trim( (string)$params['task'] );
		$day = trim( (string)$params['day'] );

		if ( $customer === '' || $job === ''
			|| !$this->query->jobBelongsToCustomer( $customer, $job )
			|| ( $task !== '' && !$this->query->taskBelongsToJob( $job, $task ) )
		) {
			$this->dieWithError( 'timetracker-api-badbucket', 'badbucket' );
		}
		if ( !preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ) {
			$this->dieWithError( 'timetracker-api-badday', 'badday' );
		}
		$hours = Duration::parse( (string)$params['value'] );
		if ( $hours === null ) {
			$this->dieWithError( 'timetracker-api-badvalue', 'badvalue' );
		}
		$hours = max( 0.0, $hours );

		$target = $this->resolveTargetUser( trim( (string)$params['user'] ) );
		// Keep any existing note — the grid sets only the duration.
		[ , $note ] = $this->entryStore->currentEntry( $target, $customer, $job, $task, $day );
		$this->entryStore->setDuration(
			$this->getAuthority(), $target, $customer, $job, $task, $day, $hours, $note );

		$this->getResult()->addValue( null, $this->getModuleName(), [
			'customer' => $customer,
			'job' => $job,
			'task' => $task,
			'day' => $day,
			'user' => $target,
			'hours' => Duration::trim( $hours ),
			'display' => $hours > 0 ? Duration::hm( $hours ) : '',
		] );
	}

	/** Self, or the requested user when the actor may edit others; a forged
	 * name falls back to self (matching Special:TimeTracker's resolution). */
	private function resolveTargetUser( string $requested ): string {
		$current = $this->getUser()->getName();
		if ( $requested === '' || $requested === $current
			|| !$this->getUser()->isAllowed( 'timetracker-editothers' )
		) {
			return $current;
		}
		return in_array( $requested, $this->query->allUserNames(), true ) ? $requested : $current;
	}

	/** @inheritDoc */
	public function isWriteMode() {
		return true;
	}

	/** @inheritDoc */
	public function needsToken() {
		return 'csrf';
	}

	/** @inheritDoc */
	public function mustBePosted() {
		return true;
	}

	/** @inheritDoc */
	public function getAllowedParams() {
		return [
			'customer' => [ ParamValidator::PARAM_TYPE => 'string', ParamValidator::PARAM_REQUIRED => true ],
			'job' => [ ParamValidator::PARAM_TYPE => 'string', ParamValidator::PARAM_REQUIRED => true ],
			'task' => [ ParamValidator::PARAM_TYPE => 'string', ParamValidator::PARAM_DEFAULT => '' ],
			'day' => [ ParamValidator::PARAM_TYPE => 'string', ParamValidator::PARAM_REQUIRED => true ],
			'user' => [ ParamValidator::PARAM_TYPE => 'string', ParamValidator::PARAM_DEFAULT => '' ],
			// Optional so an empty value is accepted — it clears (removes) the cell.
			'value' => [ ParamValidator::PARAM_TYPE => 'string', ParamValidator::PARAM_DEFAULT => '' ],
		];
	}
}
