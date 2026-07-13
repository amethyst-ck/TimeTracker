( function () {
	'use strict';

	// Progressive enhancement for the Special:TimeReports filter bar. Without
	// JS everything still works via the Apply button and a full reload.
	$( function () {
		var $form = $( '.tt-filter' );
		if ( !$form.length ) {
			return;
		}

		// Show the custom from/to inputs only when the period is "custom".
		var $range = $form.find( '.tt-f-range' );
		var $custom = $form.find( '.tt-f-custom' );
		function syncCustom() {
			$custom.toggleClass( 'tt-f-custom-on', $range.val() === 'custom' );
		}
		$range.on( 'change', function () {
			syncCustom();
			// Jumping to a preset should apply immediately; leave "custom" for
			// the user to fill the dates and press Apply.
			if ( $range.val() !== 'custom' ) {
				$form.trigger( 'submit' );
			}
		} );
		syncCustom();

		// Changing the customer re-submits so the job list narrows to that
		// customer's jobs (and the task list clears); the server rebuilds them.
		$form.find( '.tt-f-customer' ).on( 'change', function () {
			$form.find( '.tt-f-job' ).val( '' );
			$form.find( '.tt-f-task' ).val( '' );
			$form.trigger( 'submit' );
		} );

		// Changing the job re-submits so the task list narrows to that
		// job's tasks.
		$form.find( '.tt-f-job' ).on( 'change', function () {
			$form.find( '.tt-f-task' ).val( '' );
			$form.trigger( 'submit' );
		} );
	} );
}() );
