<?php

namespace MediaWiki\Extension\TimeTracker\Hooks;

use MediaWiki\Hook\BeforePageDisplayHook;

/** Loads the base style module (tokens + card) on every page. */
class ResourceHooks implements BeforePageDisplayHook {

	/** @inheritDoc */
	public function onBeforePageDisplay( $out, $skin ): void {
		$out->addModuleStyles( [ 'ext.timetracker.base' ] );
	}
}
