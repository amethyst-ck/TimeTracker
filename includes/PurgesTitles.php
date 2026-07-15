<?php

namespace MediaWiki\Extension\TimeTracker;

/**
 * Purges a set of pages by title. The using class must expose readonly
 * `$titleFactory` (TitleFactory) and `$wikiPageFactory` (WikiPageFactory)
 * properties — used by both EntryStore and the PurgeHooks handler.
 */
trait PurgesTitles {

	/** Purge each existing page named in $names (deduped; missing pages skipped). */
	private function purgeTitles( array $names ): void {
		foreach ( array_unique( $names ) as $name ) {
			$title = $this->titleFactory->newFromText( (string)$name );
			if ( $title && $title->exists() ) {
				$this->wikiPageFactory->newFromTitle( $title )->doPurge();
			}
		}
	}
}
