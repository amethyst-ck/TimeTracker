<?php

namespace MediaWiki\Extension\TimeTracker\Special;

use MediaWiki\Html\Html;

/**
 * Shared form controls for the timer and edit-time pages: customer/job/user
 * selectors (show names, submit page ids) plus small label/link helpers. The
 * using SpecialPage must expose a TimeTrackerQuery `$query` and TitleFactory
 * `$titleFactory`.
 */
trait FormControls {

	/** Customer <select> — options value=id, label=name; $selectedId pre-selected. */
	protected function customerSelect( array $map, string $selectedId = '' ): string {
		$options = '';
		foreach ( $map as $custId => $customer ) {
			$options .= Html::element( 'option',
				[ 'value' => $custId ] + ( (string)$custId === $selectedId ? [ 'selected' => '' ] : [] ),
				$customer['name'] );
		}
		return Html::rawElement( 'select', [ 'name' => 'customer', 'class' => 'tt-customer' ], $options );
	}

	/**
	 * Job <select>, populated for $selectedCustId's jobs (defaults to the
	 * first customer; JS narrows it on customer change), with $selectedProjId
	 * pre-selected.
	 */
	protected function jobSelect( array $map, string $selectedCustId = '', string $selectedProjId = '' ): string {
		$custId = ( $selectedCustId !== '' && isset( $map[$selectedCustId] ) ) ? $selectedCustId : array_key_first( $map );
		$options = '';
		foreach ( $map[$custId]['jobs'] as $job ) {
			$options .= Html::element( 'option',
				[ 'value' => $job['id'] ] + ( $job['id'] === $selectedProjId ? [ 'selected' => '' ] : [] ),
				$job['name'] );
		}
		return Html::rawElement( 'select', [ 'name' => 'job', 'class' => 'tt-job' ], $options );
	}

	/**
	 * { custId: [ [projId, projName, [[taskId, taskName], …]], … ] } for the
	 * client-side customer→job→task narrowing.
	 */
	protected function jobsJson( array $map ): array {
		$json = [];
		foreach ( $map as $custId => $customer ) {
			$json[$custId] = array_map(
				static fn ( $job ) => [ $job['id'], $job['name'],
					array_map( static fn ( $task ) => [ $task['id'], $task['name'] ], $job['tasks'] ?? [] ) ],
				$customer['jobs'] );
		}
		return $json;
	}

	/**
	 * Task <select> for the job shown in the job dropdown: a "(General)"
	 * option (value '' = job-level) followed by that job's tasks, with
	 * $selectedTaskId pre-selected.
	 */
	protected function taskSelect( array $map, string $selectedCustId = '', string $selectedProjId = '', string $selectedTaskId = '' ): string {
		$custId = ( $selectedCustId !== '' && isset( $map[$selectedCustId] ) ) ? $selectedCustId : array_key_first( $map );
		$jobs = $map[$custId]['jobs'] ?? [];
		$projId = ( $selectedProjId !== '' ) ? $selectedProjId : ( $jobs[0]['id'] ?? '' );
		$tasks = [];
		foreach ( $jobs as $job ) {
			if ( $job['id'] === $projId ) {
				$tasks = $job['tasks'] ?? [];
				break;
			}
		}
		$options = Html::element( 'option',
			[ 'value' => '' ] + ( $selectedTaskId === '' ? [ 'selected' => '' ] : [] ),
			$this->msg( 'timetracker-task-general' )->text() );
		foreach ( $tasks as $task ) {
			$options .= Html::element( 'option',
				[ 'value' => $task['id'] ] + ( $task['id'] === $selectedTaskId ? [ 'selected' => '' ] : [] ),
				$task['name'] );
		}
		return Html::rawElement( 'select', [ 'name' => 'task', 'class' => 'tt-task' ], $options );
	}

	/**
	 * User field: a dropdown for admins (defaulting to $selectedUser, or the
	 * acting user), a read-only self value otherwise.
	 */
	protected function userField( string $selectedUser = '' ): string {
		$current = $this->getUser()->getName();
		if ( !$this->isAdmin() ) {
			// A non-admin only ever logs their own time, so there is nothing to
			// choose — submit the user as a hidden field with no visible row.
			return Html::hidden( 'user', $current, [ 'class' => 'tt-user' ] );
		}
		$selected = $selectedUser !== '' ? $selectedUser : $current;
		$options = '';
		foreach ( $this->query->allUserNames() as $user ) {
			$options .= Html::element( 'option',
				[ 'value' => $user ] + ( $user === $selected ? [ 'selected' => '' ] : [] ), $user );
		}
		return $this->fieldRow( 'timetracker-label-user',
			Html::rawElement( 'select', [ 'name' => 'user', 'class' => 'tt-user' ], $options ) );
	}

	protected function fieldRow( string $labelMsg, string $control ): string {
		return Html::rawElement( 'div', [ 'class' => 'tt-row' ],
			Html::element( 'label', [ 'class' => 'tt-label' ], $this->msg( $labelMsg )->text() ) . $control );
	}

	protected function isAdmin(): bool {
		return $this->getUser()->isAllowed( 'timetracker-editothers' );
	}

	/** The user time is logged against: the request's, if an admin picked a real one. */
	protected function resolveTargetUser( string $requested ): string {
		$current = $this->getUser()->getName();
		if ( $requested === '' || $requested === $current || !$this->isAdmin() ) {
			return $current;
		}
		return in_array( $requested, $this->query->allUserNames(), true ) ? $requested : $current;
	}

	/** A link to a page by name, labeled $label (defaults to the name); '—' if blank. */
	protected function pageLink( string $name, string $label = '' ): string {
		if ( $name === '' ) {
			return '—';
		}
		$text = $label !== '' ? $label : $name;
		$title = $this->titleFactory->newFromText( $name );
		return $title
			? Html::element( 'a', [ 'href' => $title->getLocalURL() ], $text )
			: htmlspecialchars( $text );
	}
}
