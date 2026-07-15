<?php

namespace MediaWiki\Extension\TimeTracker\Hooks;

use MediaWiki\Content\TextContent;
use MediaWiki\Hook\EditFilterMergedContentHook;

/**
 * Rejects reserved task names. A lone dash marks a job's general (task-less)
 * bucket in the timesheet, so a task cannot be named that or it would be
 * indistinguishable from it.
 */
class SaveHooks implements EditFilterMergedContentHook {

	private const RESERVED = [ '-', '–', '—' ];

	/** @inheritDoc */
	public function onEditFilterMergedContent( $context, $content, $status, $summary, $user, $minoredit ) {
		if ( !( $content instanceof TextContent ) ) {
			return true;
		}
		$text = $content->getText();
		// Only the task page (which transcludes {{Task}}) carries a task name.
		if ( !preg_match( '/\{\{\s*Task\s*[|}]/', $text ) ) {
			return true;
		}
		if ( preg_match( '/\|\s*name\s*=\s*([^|}\n]*)/', $text, $m )
			&& in_array( trim( $m[1] ), self::RESERVED, true )
		) {
			$status->fatal( 'timetracker-task-name-reserved', trim( $m[1] ) );
			return false;
		}
		return true;
	}
}
