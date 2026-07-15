<?php

namespace MediaWiki\Extension\TimeTracker\Hooks;

use MediaWiki\Permissions\Hook\GetUserPermissionsErrorsHook;

/**
 * Only the owner or a holder of timetracker-editothers may change a user's time
 * pages (User:<name>/TimeTracker/...); anyone may read them.
 */
class PermissionHooks implements GetUserPermissionsErrorsHook {

	private const WRITE_ACTIONS = [ 'edit', 'create', 'delete', 'move', 'move-target', 'protect' ];

	/** @inheritDoc */
	public function onGetUserPermissionsErrors( $title, $user, $action, &$result ) {
		if ( $title->getNamespace() !== NS_USER
			|| !in_array( $action, self::WRITE_ACTIONS, true )
		) {
			return true;
		}
		// Guard only our time subpages: User:<owner>/TimeTracker[/...].
		if ( !preg_match( '#^([^/]+)/TimeTracker(?:/|$)#', $title->getText(), $m ) ) {
			return true;
		}
		$owner = $m[1];
		if ( $user->getName() === $owner || $user->isAllowed( 'timetracker-editothers' ) ) {
			return true;
		}
		$result = [ 'timetracker-notyourtime', $owner ];
		return false;
	}
}
