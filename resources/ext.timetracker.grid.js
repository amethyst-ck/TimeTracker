/**
 * Editable weekly timesheet grid: click a cell, type "2:30" or "2.5", and it
 * auto-saves on Enter/blur via the timetrackersetcell API, then updates the
 * row/day/grand totals live. A cell's customer/job/task/day/user is fixed by
 * its position, so saving is just "set this bucket" — there is no reassignment.
 *
 * Semantic MediaWiki reindexes a saved entry a few seconds later, so a fresh
 * page load (e.g. paging weeks) can briefly re-read a stale value. To keep the
 * user's own just-made edits from appearing to vanish, each save is mirrored
 * into sessionStorage and re-applied on load until SMW catches up.
 */
( function () {
	'use strict';

	var CACHE_MS = 60000;

	function parseData( $el, name ) {
		try {
			return JSON.parse( $el.attr( 'data-' + name ) );
		} catch ( e ) {
			return null;
		}
	}

	function toMinutes( dec ) {
		return Math.round( ( parseFloat( dec ) || 0 ) * 60 );
	}

	// "2h 30m" (matches Duration::hm), for the totals.
	function fmtTotal( dec ) {
		var t = toMinutes( dec );
		if ( t <= 0 ) {
			return '0m';
		}
		var h = Math.floor( t / 60 ), m = t % 60;
		return h && m ? h + 'h ' + m + 'm' : ( h ? h + 'h' : m + 'm' );
	}

	// "2:30" (blank when empty), for a cell input.
	function fmtCell( dec ) {
		var t = toMinutes( dec );
		return t <= 0 ? '' : Math.floor( t / 60 ) + ':' + ( '0' + ( t % 60 ) ).slice( -2 );
	}

	function cellKey( $input, day ) {
		return 'ttg:' + [
			$input.attr( 'data-c' ), $input.attr( 'data-j' ),
			$input.attr( 'data-k' ) || '', $input.attr( 'data-u' ) || '', day
		].join( '|' );
	}

	function cachePut( key, hours ) {
		try {
			sessionStorage.setItem( key, JSON.stringify( { h: hours, t: Date.now() } ) );
		} catch ( e ) {}
	}

	function cacheGet( key ) {
		var raw;
		try {
			raw = sessionStorage.getItem( key );
		} catch ( e ) {
			return null;
		}
		if ( !raw ) {
			return null;
		}
		var o = JSON.parse( raw );
		return ( Date.now() - o.t < CACHE_MS ) ? o.h : null;
	}

	function recompute( $wrap ) {
		var $table = $wrap.find( 'table' ).first();
		var days = {}, grand = 0;
		$table.find( 'tbody tr' ).each( function () {
			var rowTot = 0;
			$( this ).find( 'td.tt-g-cell' ).each( function () {
				var h = parseFloat( $( this ).attr( 'data-h' ) ) || 0;
				var d = $( this ).attr( 'data-day' );
				rowTot += h;
				days[ d ] = ( days[ d ] || 0 ) + h;
			} );
			grand += rowTot;
			$( this ).find( '.tt-g-rowtot' ).text( fmtTotal( rowTot ) );
		} );
		$table.find( '.tt-g-daytot' ).each( function () {
			$( this ).text( fmtTotal( days[ $( this ).attr( 'data-day' ) ] || 0 ) );
		} );
		$table.find( '.tt-g-grand' ).text( fmtTotal( grand ) );
	}

	function setCell( $td, $input, hours ) {
		$td.attr( 'data-h', hours );
		$input.val( fmtCell( hours ) ).data( 'last', $input.val() );
	}

	function saveCell( $wrap, $input ) {
		var $td = $input.closest( 'td' );
		var day = $td.attr( 'data-day' );
		$input.prop( 'disabled', true ).removeClass( 'tt-g-err' );
		new mw.Api().postWithToken( 'csrf', {
			action: 'timetrackersetcell',
			customer: $input.attr( 'data-c' ),
			job: $input.attr( 'data-j' ),
			task: $input.attr( 'data-k' ) || '',
			user: $input.attr( 'data-u' ) || '',
			day: day,
			value: $input.val()
		} ).done( function ( data ) {
			var hours = ( data && data.timetrackersetcell && data.timetrackersetcell.hours ) || 0;
			setCell( $td, $input, hours );
			cachePut( cellKey( $input, day ), hours );
			recompute( $wrap );
		} ).fail( function ( code, res ) {
			$input.addClass( 'tt-g-err' ).trigger( 'focus' );
			mw.notify( ( res && res.error && res.error.info ) || code, { type: 'error' } );
		} ).always( function () {
			$input.prop( 'disabled', false );
		} );
	}

	function bindCell( $wrap, input ) {
		var $input = $( input );
		$input.data( 'last', $input.val() );
		$input.on( 'keydown', function ( e ) {
			if ( e.key === 'Enter' ) {
				e.preventDefault();
				$input.trigger( 'blur' );
			}
		} );
		$input.on( 'blur', function () {
			if ( $input.val() !== $input.data( 'last' ) ) {
				saveCell( $wrap, $input );
			}
		} );
	}

	// Re-apply recent local edits over the server-rendered (possibly SMW-stale) values.
	function applyCache( $wrap ) {
		$wrap.find( 'td.tt-g-cell' ).each( function () {
			var $td = $( this ), $in = $td.find( 'input.tt-g-in' );
			if ( !$in.length ) {
				return;
			}
			var h = cacheGet( cellKey( $in, $td.attr( 'data-day' ) ) );
			if ( h !== null && String( h ) !== String( $td.attr( 'data-h' ) ) ) {
				setCell( $td, $in, h );
			}
		} );
		recompute( $wrap );
	}

	function appendRow( $wrap, days, user, cId, cName, jId, jName, kId, kName ) {
		var $table = $wrap.find( 'table' ).first();
		var dup = $table.find( 'input.tt-g-in' ).filter( function () {
			var $i = $( this );
			return $i.attr( 'data-c' ) === cId && $i.attr( 'data-j' ) === jId &&
				( $i.attr( 'data-k' ) || '' ) === ( kId || '' );
		} ).first();
		if ( dup.length ) {
			dup.trigger( 'focus' );
			return;
		}
		$table.find( 'tr.tt-g-none' ).remove();
		var $tr = $( '<tr>' )
			.append( $( '<td>' ).text( cName ) )
			.append( $( '<td>' ).text( jName ) )
			.append( $( '<td>' ).text( kName ) );
		days.forEach( function ( ymd ) {
			var $in = $( '<input>' ).attr( {
				type: 'text', autocomplete: 'off', inputmode: 'text',
				'data-c': cId, 'data-j': jId, 'data-k': kId || '', 'data-u': user
			} ).addClass( 'tt-g-in' );
			$tr.append( $( '<td>' ).addClass( 'tt-num tt-g-cell' )
				.attr( { 'data-day': ymd, 'data-h': '0' } ).append( $in ) );
			bindCell( $wrap, $in[ 0 ] );
		} );
		$tr.append( $( '<td>' ).addClass( 'tt-num tt-g-rowtot' ).text( '0m' ) );
		$table.find( 'tbody' ).append( $tr );
		$tr.find( 'input.tt-g-in' ).first().trigger( 'focus' );
	}

	// Every (customer, job, task) row the add-row could offer for this grid,
	// including each job's General ('') bucket.
	function addableCombos( $wrap, map ) {
		var $row = $wrap.find( '.tt-g-addrow' );
		var fixC = $row.attr( 'data-fix-c' ), fixJ = $row.attr( 'data-fix-j' ), fixK = $row.attr( 'data-fix-k' );
		if ( fixK !== undefined ) {
			return [ [ fixC, fixJ, fixK ] ];
		}
		var combos = [];
		( fixC !== undefined ? [ fixC ] : Object.keys( map ) ).forEach( function ( c ) {
			var jobs = map[ c ] || [];
			( fixJ !== undefined ? jobs.filter( function ( j ) { return j[ 0 ] === fixJ; } ) : jobs )
				.forEach( function ( j ) {
					combos.push( [ c, j[ 0 ], '' ] );
					( j[ 2 ] || [] ).forEach( function ( t ) { combos.push( [ c, j[ 0 ], t[ 0 ] ] ); } );
				} );
		} );
		return combos;
	}

	// Grey out (and disable) the add-row once every combo it could add is shown.
	// Returns the combos still addable (so callers can advance the selects).
	function updateAddState( $wrap ) {
		var $row = $wrap.find( '.tt-g-addrow' );
		if ( !$row.length ) {
			return [];
		}
		var shown = {};
		$wrap.find( 'input.tt-g-in' ).each( function () {
			var $i = $( this );
			shown[ [ $i.attr( 'data-c' ), $i.attr( 'data-j' ), $i.attr( 'data-k' ) || '' ].join( '|' ) ] = true;
		} );
		var remaining = addableCombos( $wrap, parseData( $wrap, 'tt-jobs' ) || {} ).filter( function ( c ) {
			return !shown[ [ c[ 0 ], c[ 1 ], c[ 2 ] || '' ].join( '|' ) ];
		} );
		var done = remaining.length === 0;
		$row.toggleClass( 'tt-g-addrow-done', done );
		$row.find( '.tt-g-add-go, select' ).prop( 'disabled', done );
		return remaining;
	}

	function initAddRow( $wrap ) {
		var map = parseData( $wrap, 'tt-jobs' ) || {};
		var days = parseData( $wrap, 'tt-days' ) || [];
		var user = String( $wrap.attr( 'data-tt-user' ) || '' );
		var $row = $wrap.find( '.tt-g-addrow' );
		// Dimensions the page fixes have no <select>; their id/name come in on
		// data-fix-*. undefined = not fixed (use the dropdown).
		var fixC = $row.attr( 'data-fix-c' ), fixCN = $row.attr( 'data-fix-cname' );
		var fixJ = $row.attr( 'data-fix-j' ), fixJN = $row.attr( 'data-fix-jname' );
		var fixK = $row.attr( 'data-fix-k' ), fixKN = $row.attr( 'data-fix-kname' );
		var $c = $row.find( '.tt-customer' ), $j = $row.find( '.tt-job' ), $k = $row.find( '.tt-task' );

		function curC() {
			return fixC !== undefined ? fixC : $c.val();
		}
		function curJ() {
			return fixJ !== undefined ? fixJ : $j.val();
		}
		function fillJobs() {
			if ( fixJ === undefined ) {
				$j.empty();
				( map[ curC() ] || [] ).forEach( function ( p ) {
					$j.append( $( '<option>' ).val( p[ 0 ] ).text( p[ 1 ] ) );
				} );
			}
			fillTasks();
		}
		function fillTasks() {
			if ( fixK !== undefined ) {
				return;
			}
			var tasks = [];
			( map[ curC() ] || [] ).forEach( function ( p ) {
				if ( p[ 0 ] === curJ() ) {
					tasks = p[ 2 ] || [];
				}
			} );
			$k.empty().append( $( '<option>' ).val( '' ).text( mw.msg( 'timetracker-task-general' ) ) );
			tasks.forEach( function ( t ) {
				$k.append( $( '<option>' ).val( t[ 0 ] ).text( t[ 1 ] ) );
			} );
		}
		// Point the (unfixed) selects at a specific customer/job/task combo,
		// rebuilding the cascaded option lists so the values exist.
		function pointAt( combo ) {
			if ( $c.length ) {
				$c.val( combo[ 0 ] );
				fillJobs();
			}
			if ( $j.length ) {
				$j.val( combo[ 1 ] );
				fillTasks();
			}
			if ( $k.length ) {
				$k.val( combo[ 2 ] || '' );
			}
		}

		if ( $c.length ) {
			$c.on( 'change', fillJobs );
		}
		if ( $j.length ) {
			$j.on( 'change', fillTasks );
		}
		fillJobs();
		updateAddState( $wrap );

		$row.find( '.tt-g-add-go' ).on( 'click', function () {
			var c = curC(), j = curJ();
			if ( !c || !j ) {
				return;
			}
			appendRow( $wrap, days, user,
				c, fixC !== undefined ? fixCN : $c.find( 'option:selected' ).text(),
				j, fixJ !== undefined ? fixJN : $j.find( 'option:selected' ).text(),
				fixK !== undefined ? fixK : $k.val(),
				fixK !== undefined ? fixKN :
					( $k.val() ? $k.find( 'option:selected' ).text() : mw.msg( 'timetracker-task-general' ) ) );
			// Advance the selects to the next still-addable combo so a repeat
			// click adds something new; the row greys once none remain.
			var remaining = updateAddState( $wrap );
			if ( remaining.length ) {
				pointAt( remaining[ 0 ] );
			}
		} );
	}

	$( function () {
		// The week date-picker reloads the page with the chosen week in a query
		// param (ttweek for embedded grids, or whatever data-param names).
		$( '.tt-g-weekpick' ).on( 'change', function () {
			var v = $( this ).val();
			if ( v ) {
				var u = new URL( location.href );
				u.searchParams.set( $( this ).attr( 'data-param' ) || 'ttweek', v );
				location.href = u.toString();
			}
		} );
		$( '.tt-grid-wrap' ).each( function () {
			var $wrap = $( this );
			$wrap.find( 'input.tt-g-in' ).each( function () {
				bindCell( $wrap, this );
			} );
			applyCache( $wrap );
			if ( $wrap.find( '.tt-g-addrow' ).length ) {
				initAddRow( $wrap );
			}
		} );
	} );
}() );
