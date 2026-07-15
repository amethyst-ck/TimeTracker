( function () {
	'use strict';

	// Special:TimeReports filter bar: every dropdown applies immediately on
	// change by submitting the (GET) filter form, so the server re-renders.
	$( function () {
		var $form = $( '.tt-filter' );
		if ( !$form.length ) {
			return;
		}

		// Changing the customer or job also clears the now-stale dependent
		// selections so the server rebuilds the narrowed job/task lists.
		$form.find( '.tt-f-customer' ).on( 'change', function () {
			$form.find( '.tt-f-job, .tt-f-task' ).val( '' );
			$form.trigger( 'submit' );
		} );
		$form.find( '.tt-f-job' ).on( 'change', function () {
			$form.find( '.tt-f-task' ).val( '' );
			$form.trigger( 'submit' );
		} );
		$form.find( '.tt-f-task, .tt-f-user, .tt-f-from, .tt-f-to, .tt-f-group' ).on( 'change', function () {
			$form.trigger( 'submit' );
		} );
		// Switching period starts fresh in that range — drop any week anchor so
		// e.g. selecting "Week" lands on the current week.
		$form.find( '.tt-f-period' ).on( 'change', function () {
			$form.find( 'input[name=weekstart]' ).remove();
			$form.trigger( 'submit' );
		} );
	} );
}() );
