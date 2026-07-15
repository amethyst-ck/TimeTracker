<?php

namespace MediaWiki\Extension\TimeTracker\Hooks;

use MediaWiki\Extension\TimeTracker\EntryWikitext;
use MediaWiki\Extension\TimeTracker\PurgesTitles;
use MediaWiki\Page\Hook\PageDeleteCompleteHook;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\Title\TitleFactory;
use WikitextContent;

/**
 * The #timetracker_* lists are parser-cached with no dependency on the pages
 * they query, so a parent page can show stale child lists. These hooks purge the
 * parents when a child changes: entry -> its job + customer + Customers
 * list; job -> its customer + Customers list; customer -> Customers list.
 */
class PurgeHooks implements PageSaveCompleteHook, PageDeleteCompleteHook {

	use PurgesTitles;

	private const CUSTOMER_LIST = 'Customers';

	public function __construct(
		private readonly WikiPageFactory $wikiPageFactory,
		private readonly TitleFactory $titleFactory,
		private readonly EntryWikitext $wikitext
	) {
	}

	/** @inheritDoc */
	public function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult ) {
		$content = $wikiPage->getContent();
		$text = $content instanceof WikitextContent ? $content->getText() : '';
		$this->purgeParents( $wikiPage->getTitle(), $text );
	}

	/** @inheritDoc */
	public function onPageDeleteComplete(
		$page, $deleter, $reason, $pageID, $deletedRev, $logEntry, $archivedRevisionCount
	) {
		$content = $deletedRev->getContent( SlotRecord::MAIN );
		$text = $content instanceof WikitextContent ? $content->getText() : '';
		$title = $this->titleFactory->newFromPageIdentity( $page );
		if ( $title ) {
			$this->purgeParents( $title, $text );
		}
	}

	/** Purge the pages whose lists include this page, by page type. */
	private function purgeParents( $title, string $text ): void {
		// Time entries live under the owner's user page (User: namespace);
		// customers and jobs are main-namespace pages referenced by id.
		if ( str_contains( $text, '{{Time entry' ) ) {
			// An entry lists on its job page, its customer page, and the
			// Customers total. customer/job are stable page ids.
			$this->purgeTitles( array_filter( [
				$this->wikitext->field( $text, 'job' ),
				$this->wikitext->field( $text, 'customer' ),
				self::CUSTOMER_LIST,
			] ) );
			return;
		}

		if ( $title->getNamespace() !== NS_MAIN ) {
			return;
		}

		if ( str_contains( $text, '{{Job' ) ) {
			// A job lists on its customer page (id) and the Customers list.
			$this->purgeTitles( array_filter( [ $this->wikitext->field( $text, 'customer' ), self::CUSTOMER_LIST ] ) );
		} elseif ( str_contains( $text, '{{Customer' ) ) {
			$this->purgeTitles( [ self::CUSTOMER_LIST ] );
		}
	}
}
