<?php

namespace MediaWiki\Extension\TimeTracker\Hooks;

use MediaWiki\Extension\TimeTracker\TimeTrackerQuery;
use MediaWiki\Permissions\Hook\GetUserPermissionsErrorsExpensiveHook;

/**
 * Delete protection for the time-tracker hierarchy (customer → job → task →
 * time). An entity is undeletable while anything still depends on it: a
 * customer with jobs, a job with tasks, or any entity with time logged against
 * it. Child entities must be removed first; an entity with logged time should
 * be archived, not deleted, so its history is never orphaned.
 *
 * Relationship presence is the signal — a page id that no job references as its
 * customer simply has no jobs, so a non-entity page never blocks. Uses the
 * *Expensive* permission hook so the queries run only on a real delete attempt
 * (via the UI or the API), not on the delete-link probe every skin makes on
 * each view.
 */
class DeleteHooks implements GetUserPermissionsErrorsExpensiveHook {

	public function __construct( private readonly TimeTrackerQuery $query ) {
	}

	/** @inheritDoc */
	public function onGetUserPermissionsErrorsExpensive( $title, $user, $action, &$result ) {
		if ( $action !== 'delete' || !$this->namespacesConfigured() ) {
			return true;
		}
		// A customer may have jobs; a job may have tasks; a task has no child
		// entities. Each entity type may also have time logged against it.
		$id = $title->getPrefixedText();
		switch ( $title->getNamespace() ) {
			case NS_CUSTOMER:
				$childHas = $this->query->hasJobs( $id );
				$childMsg = 'timetracker-delete-has-jobs';
				$timeProperty = 'customer';
				break;
			case NS_JOB:
				$childHas = $this->query->hasTasks( $id );
				$childMsg = 'timetracker-delete-has-tasks';
				$timeProperty = 'job';
				break;
			case NS_TASK:
				$childHas = false;
				$childMsg = '';
				$timeProperty = 'task';
				break;
			default:
				return true;
		}
		// Child entities first: a false "no children" (SMW error) would orphan them.
		if ( $childHas === null ) {
			$result = [ 'timetracker-delete-check-failed' ];
			return false;
		}
		if ( $childHas ) {
			$result = [ $childMsg ];
			return false;
		}
		// Then logged time: archive rather than orphan it. Also fail closed.
		$hasTime = $this->query->hasTime( $timeProperty, $id );
		if ( $hasTime === null ) {
			$result = [ 'timetracker-delete-check-failed' ];
			return false;
		}
		if ( $hasTime ) {
			$result = [ 'timetracker-delete-has-time' ];
			return false;
		}
		return true;
	}

	/** The entity namespaces come from the app's settings; if the extension is
	 * loaded without them, do nothing rather than fatal on an undefined constant. */
	private function namespacesConfigured(): bool {
		return defined( 'NS_CUSTOMER' ) && defined( 'NS_JOB' ) && defined( 'NS_TASK' );
	}
}
