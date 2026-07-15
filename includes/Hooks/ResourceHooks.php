<?php

namespace MediaWiki\Extension\TimeTracker\Hooks;

use MediaWiki\Hook\BeforePageDisplayHook;

/** Loads the base style module (tokens + card) on every page. */
class ResourceHooks implements BeforePageDisplayHook {

	/** @inheritDoc */
	public function onBeforePageDisplay( $out, $skin ): void {
		$out->addModuleStyles( [ 'ext.timetracker.base' ] );
		// A timer stop redirects here with ?ttfresh; the module reloads the page
		// once so its SMW-backed grid reflects the just-committed entry.
		if ( $out->getRequest()->getCheck( 'ttfresh' ) ) {
			$out->addModules( [ 'ext.timetracker.refresh' ] );
		}
	}
}
