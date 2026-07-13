<?php
/**
 * Magic word definitions for the TimeTracker extension.
 * Parser functions must be registered here before Parser::setFunctionHook
 * will accept them.
 */

$magicWords = [];

/** English */
$magicWords['en'] = [
	// case-insensitive parser function name + canonical alias
	'timetracker_format_duration' => [ 0, 'timetracker_format_duration' ],
	'timetracker_dashboard' => [ 0, 'timetracker_dashboard' ],
	'timetracker_tile' => [ 0, 'timetracker_tile' ],
	'timetracker_timer' => [ 0, 'timetracker_timer' ],
	'timetracker_jobtimer' => [ 0, 'timetracker_jobtimer' ],
	'timetracker_job_customer' => [ 0, 'timetracker_job_customer' ],
	'timetracker_customers' => [ 0, 'timetracker_customers' ],
	'timetracker_jobs' => [ 0, 'timetracker_jobs' ],
	'timetracker_tasks' => [ 0, 'timetracker_tasks' ],
	'timetracker_timetable' => [ 0, 'timetracker_timetable' ],
	'timetracker_total' => [ 0, 'timetracker_total' ],
	'timetracker_progress' => [ 0, 'timetracker_progress' ],
];
