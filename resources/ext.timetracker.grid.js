/**
 * Editable weekly timesheet grid: click a cell, type "2:30" or "2.5", and it
 * auto-saves on Enter/blur via the timetrackersetcell API, then updates the
 * row/day/grand totals live. A cell's customer/job/task/day/user is fixed by
 * its position, so saving is just "set this bucket" — there is no reassignment.
 * A timer stop updates the affected cells in place via a timetracker:cell-set
 * event; paging to another week reloads and re-renders server-side.
 */
( function () {
	'use strict';

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

	// A name cell linking to the entity page (mirrors TableRenderer::pageLink),
	// with the mobile-stacking data-label the server rows carry.
	function nameCell( label, id, name ) {
		var $td = $( '<td>' ).attr( 'data-label', label );
		return id
			? $td.append( $( '<a>' ).attr( 'href', mw.util.getUrl( id ) ).text( name || id ) )
			: $td.text( '—' );
	}

	// The task cell: a link, or the general-bucket dash when task is empty
	// (mirrors TableRenderer::taskLabel).
	function taskCell( id, name ) {
		return id
			? nameCell( mw.msg( 'timereports-col-task' ), id, name )
			: $( '<td>' ).attr( 'data-label', mw.msg( 'timereports-col-task' ) )
				.text( mw.msg( 'timetracker-task-general' ) );
	}

	// Add (or reveal) the row for a bucket. focus !== false puts the cursor in
	// its first cell (the add-row flow); the timer-stop path passes false so it
	// can set a specific day's cell without stealing focus. Returns the row.
	function appendRow( $wrap, days, user, cId, cName, jId, jName, kId, kName, focus ) {
		var $table = $wrap.find( 'table' ).first();
		var dup = $table.find( 'input.tt-g-in' ).filter( function () {
			var $i = $( this );
			return $i.attr( 'data-c' ) === cId && $i.attr( 'data-j' ) === jId &&
				( $i.attr( 'data-k' ) || '' ) === ( kId || '' );
		} ).first();
		if ( dup.length ) {
			if ( focus !== false ) {
				dup.trigger( 'focus' );
			}
			return dup.closest( 'tr' );
		}
		$table.find( 'tr.tt-g-none' ).remove();
		var $tr = $( '<tr>' )
			.append( nameCell( mw.msg( 'timereports-col-customer' ), cId, cName ) )
			.append( nameCell( mw.msg( 'timereports-col-job' ), jId, jName ) )
			.append( taskCell( kId, kName ) );
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
		if ( focus !== false ) {
			$tr.find( 'input.tt-g-in' ).first().trigger( 'focus' );
		}
		return $tr;
	}

	// A fixed dimension's name comes from the grid's own data (page data, always
	// current) when it matches; otherwise fall back to the supplied name.
	function fixedName( $wrap, dim, id, fallback ) {
		var $row = $wrap.find( '.tt-g-addrow' );
		var fix = $row.attr( 'data-fix-' + dim ), name = $row.attr( 'data-fix-' + dim + 'name' );
		return ( fix !== undefined && fix === id && name !== undefined ) ? name : fallback;
	}

	// Apply a bucket/day total pushed by another module (the timer stop): update
	// the cell in place, adding its row first if the grid isn't showing it. Only
	// this user's grid and only a day within the shown week are touched; anything
	// else is already saved server-side and shows when navigated to.
	function applyCellSet( $wrap, d ) {
		if ( String( $wrap.attr( 'data-tt-user' ) || '' ) !== String( d.user || '' ) ) {
			return;
		}
		var days = parseData( $wrap, 'tt-days' ) || [];
		if ( days.indexOf( d.day ) === -1 ) {
			return;
		}
		function find() {
			return $wrap.find( 'input.tt-g-in' ).filter( function () {
				var $i = $( this );
				return $i.attr( 'data-c' ) === d.customer && $i.attr( 'data-j' ) === d.job &&
					( $i.attr( 'data-k' ) || '' ) === ( d.task || '' ) &&
					$i.closest( 'td' ).attr( 'data-day' ) === d.day;
			} ).first();
		}
		var $in = find();
		if ( !$in.length ) {
			appendRow( $wrap, days, String( $wrap.attr( 'data-tt-user' ) || '' ),
				d.customer, fixedName( $wrap, 'c', d.customer, d.customerName || d.customer ),
				d.job, fixedName( $wrap, 'j', d.job, d.jobName || d.job ),
				d.task, d.task ? ( d.taskName || d.task ) : mw.msg( 'timetracker-task-general' ), false );
			$in = find();
		}
		if ( $in.length ) {
			setCell( $in.closest( 'td' ), $in, d.hours );
			recompute( $wrap );
			updateAddState( $wrap );
		}
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

		// Customer names for rebuilding that select come from its server-rendered
		// options (the job map carries only job/task names).
		var custName = {};
		$c.find( 'option' ).each( function () {
			custName[ this.value ] = $( this ).text();
		} );

		function curC() {
			return fixC !== undefined ? fixC : $c.val();
		}
		function curJ() {
			return fixJ !== undefined ? fixJ : $j.val();
		}
		function tasksOf( c, j ) {
			var out = [];
			( map[ c ] || [] ).forEach( function ( p ) {
				if ( p[ 0 ] === j ) {
					out = p[ 2 ] || [];
				}
			} );
			return out;
		}
		function shownSet() {
			var s = {};
			$wrap.find( 'input.tt-g-in' ).each( function () {
				var $i = $( this );
				s[ [ $i.attr( 'data-c' ), $i.attr( 'data-j' ), $i.attr( 'data-k' ) || '' ].join( '|' ) ] = true;
			} );
			return s;
		}
		// Does job p (under customer c) still have any bucket not in the grid?
		function jobHasRemaining( c, p, shown ) {
			return !shown[ [ c, p[ 0 ], '' ].join( '|' ) ] ||
				( p[ 2 ] || [] ).some( function ( t ) {
					return !shown[ [ c, p[ 0 ], t[ 0 ] ].join( '|' ) ];
				} );
		}
		function keepSelection( $sel, prev ) {
			if ( $sel.find( 'option' ).filter( function () {
				return this.value === prev;
			} ).length ) {
				$sel.val( prev );
			}
		}
		// Rebuild the unfixed selects so they offer only buckets not already in
		// the grid (dropping the just-added one advances the selection), then
		// grey the row when nothing is left to add.
		function rebuild() {
			var shown = shownSet();
			if ( fixC === undefined ) {
				var prevC = $c.val();
				$c.empty();
				Object.keys( map ).forEach( function ( cId ) {
					if ( ( map[ cId ] || [] ).some( function ( p ) {
						return jobHasRemaining( cId, p, shown );
					} ) ) {
						$c.append( $( '<option>' ).val( cId ).text(
							custName[ cId ] !== undefined ? custName[ cId ] : cId ) );
					}
				} );
				keepSelection( $c, prevC );
			}
			if ( fixJ === undefined ) {
				var prevJ = $j.val();
				$j.empty();
				( map[ curC() ] || [] ).forEach( function ( p ) {
					if ( jobHasRemaining( curC(), p, shown ) ) {
						$j.append( $( '<option>' ).val( p[ 0 ] ).text( p[ 1 ] ) );
					}
				} );
				keepSelection( $j, prevJ );
			}
			if ( fixK === undefined ) {
				var c = curC(), j = curJ(), prevK = $k.val();
				$k.empty();
				if ( !shown[ [ c, j, '' ].join( '|' ) ] ) {
					$k.append( $( '<option>' ).val( '' ).text( mw.msg( 'timetracker-task-general' ) ) );
				}
				tasksOf( c, j ).forEach( function ( t ) {
					if ( !shown[ [ c, j, t[ 0 ] ].join( '|' ) ] ) {
						$k.append( $( '<option>' ).val( t[ 0 ] ).text( t[ 1 ] ) );
					}
				} );
				keepSelection( $k, prevK );
			}
			updateAddState( $wrap );
		}

		if ( $c.length ) {
			$c.on( 'change', rebuild );
		}
		if ( $j.length ) {
			$j.on( 'change', rebuild );
		}
		rebuild();

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
			rebuild();
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
			if ( $wrap.find( '.tt-g-addrow' ).length ) {
				initAddRow( $wrap );
			}
		} );

		// A timer stop (ext.timetracker.timer) announces each cell it wrote so the
		// grid reflects it without a reload.
		document.addEventListener( 'timetracker:cell-set', function ( e ) {
			var d = ( e && e.detail ) || {};
			$( '.tt-grid-wrap' ).each( function () {
				applyCellSet( $( this ), d );
			} );
		} );
	} );
}() );
