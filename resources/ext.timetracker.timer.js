( function () {
	'use strict';

	// Live H:MM:SS readout for the running timer (start time is authoritative
	// on the server).
	$( function () {
		var $elapsed = $( '.tt-elapsed' );
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
	} );

	// Cascade the dropdowns: customer -> its jobs -> the job's tasks
	// (map embedded on the form as { custId: [ [projId, name, [[taskId, name]…]]…] }).
	$( function () {
		var $form = $( 'form[data-tt-jobs]' );
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
		// The server-rendered "(General)" label, reused when rebuilding the list.
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
	} );

	// Special:EditTime: pre-fill duration + note for the chosen
	// customer/job/day/user, refreshing on change.
	$( function () {
		var $hours = $( '.tt-hours' );
		if ( !$hours.length ) {
			return;
		}
		var $form = $hours.closest( 'form' );
		var $customer = $form.find( '.tt-customer' );
		var $job = $form.find( '.tt-job' );
		var $day = $form.find( '.tt-day-input' );
		var $minutes = $form.find( '.tt-minutes' );
		var $note = $form.find( '.tt-note' );
		var $task = $form.find( '.tt-task' );
		var $user = $form.find( '.tt-user' );
		var api = new mw.Api();

		function askEsc( s ) {
			return String( s ).replace( /[[\]|]/g, '' );
		}
		function first( po, prop ) {
			var v = po && po[ prop ] && po[ prop ][ 0 ];
			return ( v && typeof v === 'object' ) ? v.value : v;
		}
		function refresh() {
			var c = $customer.val(), p = $job.val(), d = $day.val();
			// The user field is the acting user (hidden) or, for admins, the
			// chosen user (select); fall back to the logged-in name.
			var u = ( $user.val() || mw.config.get( 'wgUserName' ) );
			var tk = $task.length ? $task.val() : '';
			if ( !c || !p || !d ) {
				return;
			}
			// SMW can't express "no task", so fetch all buckets for this
			// customer/job/day/user and pick the one matching the chosen task
			// (empty = the job's General bucket).
			api.get( {
				action: 'ask',
				query: '[[Category:Time entries]]'
					+ '[[Tt user::' + askEsc( u ) + ']]'
					+ '[[Tt customer::' + askEsc( c ) + ']]'
					+ '[[Tt job::' + askEsc( p ) + ']]'
					+ '[[Tt day::' + askEsc( d ) + ']]'
					+ '|?Tt duration|?Tt notes|?Tt task|limit=50',
				format: 'json'
			} ).done( function ( data ) {
				var results = ( data.query && data.query.results ) || {};
				var po = {};
				Object.keys( results ).forEach( function ( k ) {
					var rp = results[ k ].printouts;
					var tv = first( rp, 'Tt task' );
					tv = ( tv && typeof tv === 'object' ) ? ( tv.fulltext || '' ) : ( tv || '' );
					if ( tv === tk ) {
						po = rp;
					}
				} );
				var dur = parseFloat( first( po, 'Tt duration' ) ) || 0;
				var note = String( first( po, 'Tt notes' ) || '' );
				var mins = Math.round( dur * 60 );
				$hours.val( Math.floor( mins / 60 ) );
				$minutes.val( mins % 60 );
				$note.val( note.replace( /<br\s*\/?>/gi, '\n' ) );
			} );
		}

		$customer.add( $job ).add( $day ).add( $user ).add( $task ).on( 'change', refresh );
	} );

	// Cancel: go back; falls back to the href when there's no history.
	$( function () {
		$( '.tt-cancel' ).on( 'click', function ( e ) {
			if ( window.history.length > 1 ) {
				e.preventDefault();
				window.history.back();
			}
		} );
	} );
}() );
