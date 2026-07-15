( function () {
	'use strict';

	// This page was loaded straight after a Start/Stop timer redirected here
	// with a `ttfresh` marker. The grid it shows is an SMW query, and SMW
	// commits the saved entry's data in a post-send update that can land just
	// after this page was fetched — so the first render may still show the old
	// value. Reload once, a short beat later (by when the data has committed),
	// to the same URL with the marker stripped, so it happens exactly once and
	// leaves a clean address.
	var url = new URL( window.location.href );
	if ( !url.searchParams.has( 'ttfresh' ) ) {
		return;
	}
	url.searchParams.delete( 'ttfresh' );
	setTimeout( function () {
		window.location.replace( url.toString() );
	}, 300 );
}() );
