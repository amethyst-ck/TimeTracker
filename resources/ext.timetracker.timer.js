( function () {
	'use strict';

	// Live H:MM:SS readout for the running timer (start time is authoritative
	// on the server).
	function bindClock( $scope ) {
		var $elapsed = $scope.find( '.tt-elapsed' );
		if ( !$elapsed.length ) {
			return;
		}
		var startEpoch = parseInt( $elapsed.attr( 'data-tt-start' ), 10 );
		if ( !startEpoch ) {
			return;
		}

		function pad( n ) {
			return n < 10 ? '0' + n : String( n );
		}

		function tick() {
			var secs = Math.max( 0, Math.floor( Date.now() / 1000 ) - startEpoch );
			var h = Math.floor( secs / 3600 );
			var m = Math.floor( ( secs % 3600 ) / 60 );
			var s = secs % 60;
			var clock = h + ':' + pad( m ) + ':' + pad( s );
			$elapsed.text( mw.message( 'timetracker-timer-elapsed', clock ).text() );
		}

		tick();
		setInterval( tick, 1000 );
	}

	// Cascade the dropdowns: customer -> its jobs -> the job's tasks
	// (map embedded on the form as { custId: [ [projId, name, [[taskId, name]…]]…] }).
	function bindPicker( $scope ) {
		var $form = $scope.find( 'form[data-tt-jobs]' );
		if ( !$form.length ) {
			return;
		}
		var map;
		try {
			map = JSON.parse( $form.attr( 'data-tt-jobs' ) );
		} catch ( e ) {
			return;
		}
		var $customer = $form.find( '.tt-customer' );
		var $job = $form.find( '.tt-job' );
		var $task = $form.find( '.tt-task' );
		// The server-rendered general-bucket label (a dash), reused when
		// rebuilding the list.
		var generalLabel = $task.find( 'option[value=""]' ).first().text();

		function jobs() {
			return map[ $customer.val() ] || [];
		}
		function fillJobs() {
			$job.empty();
			jobs().forEach( function ( p ) {
				// p = [ jobId, jobName, tasks ]: submit the id, show the name.
				$job.append( $( '<option>' ).attr( 'value', p[ 0 ] ).text( p[ 1 ] ) );
			} );
			fillTasks();
		}
		function fillTasks() {
			if ( !$task.length ) {
				return;
			}
			var tasks = [];
			jobs().forEach( function ( p ) {
				if ( p[ 0 ] === $job.val() ) {
					tasks = p[ 2 ] || [];
				}
			} );
			$task.empty();
			$task.append( $( '<option>' ).attr( 'value', '' ).text( generalLabel ) );
			tasks.forEach( function ( t ) {
				$task.append( $( '<option>' ).attr( 'value', t[ 0 ] ).text( t[ 1 ] ) );
			} );
		}
		$customer.on( 'change', fillJobs );
		$job.on( 'change', fillTasks );
	}

	// Intercept Stop: save via the API, update the weekly grid in place (each
	// written cell is announced for ext.timetracker.grid), and swap the running
	// card for the freshly-rendered idle Start widget — no reload. On failure,
	// fall back to submitting the form (the server's redirect + refresh path).
	function bindStop( $scope ) {
		$scope.find( '.tt-stop-form' ).on( 'submit', function ( e ) {
			var form = this;
			var $form = $( form );
			var $card = $form.closest( '.tt-card' );
			e.preventDefault();
			$form.find( 'button, input[type=submit]' ).prop( 'disabled', true );
			new mw.Api().postWithToken( 'csrf', {
				action: 'timetrackerstop',
				surface: $card.attr( 'data-tt-surface' ) || '',
				returnto: $form.find( 'input[name=returnto]' ).val() || ''
			} ).done( function ( data ) {
				var r = ( data && data.timetrackerstop ) || {};
				( r.cells || [] ).forEach( function ( cell ) {
					document.dispatchEvent( new CustomEvent( 'timetracker:cell-set', { detail: {
						customer: r.customer, job: r.job, task: r.task, user: r.user,
						customerName: r.customerName, jobName: r.jobName, taskName: r.taskName,
						day: cell.day, hours: cell.hours, display: cell.display
					} } ) );
				} );
				if ( r.display ) {
					mw.notify( mw.msg( 'timetracker-saved-added', r.display ), { type: 'info' } );
				}
				if ( r.widget ) {
					var $idle = $( r.widget );
					$card.replaceWith( $idle );
					bindPicker( $idle );
				}
			} ).fail( function () {
				// HTMLFormElement.submit() bypasses this handler, so the native
				// stop + redirect still completes.
				form.submit();
			} );
		} );
	}

	$( function () {
		var $doc = $( document );
		bindClock( $doc );
		bindPicker( $doc );
		bindStop( $doc );
	} );
}() );
