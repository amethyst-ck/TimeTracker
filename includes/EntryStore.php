<?php

namespace MediaWiki\Extension\TimeTracker;

use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Permissions\Authority;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use WikitextContent;

/**
 * Reads and writes the per-day time-entry pages. One page per (user, customer,
 * job, day) holds that day's running total, at a title derived from the key
 * so adding time is a read-modify-write of one page. Pages live under the
 * owner's user page so they can be edit-restricted.
 */
class EntryStore {

	use PurgesTitles;

	private const TEMPLATE = 'Time entry';

	public function __construct(
		private readonly WikiPageFactory $wikiPageFactory,
		private readonly TitleFactory $titleFactory,
		private readonly EntryWikitext $wikitext
	) {
	}

	/**
	 * The stable page for a given day of work, under the user's User page so it
	 * can be edit-restricted (same key -> same page).
	 */
	public function dayEntryTitle( string $user, string $customerId, string $jobId, string $taskId, string $day ): ?Title {
		// Empty task keeps the pre-task key so existing "General" entries are
		// preserved; a task adds itself to the key for its own per-day bucket.
		$parts = $taskId !== '' ? "$customerId|$jobId|$taskId|$day" : "$customerId|$jobId|$day";
		$key = substr( sha1( $parts ), 0, 16 );
		return $this->titleFactory->makeTitleSafe( NS_USER, $user . '/TimeTracker/' . $key );
	}

	/**
	 * Add $hours to the day's entry, creating it if it doesn't exist. Durations
	 * sum; any existing note (set via Edit time) is preserved — the timer only
	 * records time. Returns the entry's resulting total (read from its own page,
	 * so it's authoritative without waiting on SMW), or 0 if nothing was added.
	 */
	public function addDuration(
		Authority $performer, string $targetUser, string $customer, string $job, string $task, string $day,
		float $hours
	): float {
		if ( $hours <= 0 ) {
			return 0.0;
		}
		$title = $this->dayEntryTitle( $targetUser, $customer, $job, $task, $day );
		if ( !$title ) {
			return 0.0;
		}

		$curDuration = 0.0;
		$curNote = '';
		if ( $title->exists() ) {
			$text = $this->pageText( $title );
			$curDuration = (float)$this->wikitext->field( $text, 'duration' );
			$curNote = $this->wikitext->field( $text, 'notes' );
		}

		$total = round( $curDuration + max( 0.0, $hours ), 4 );
		$this->write( $title, $performer, [
			'day' => $day,
			'customer' => $customer,
			'job' => $job,
			'task' => $task,
			'user' => $targetUser,
			'duration' => Duration::trim( $total ),
			'notes' => $curNote,
		], 'timetracker-summary-add' );
		return $total;
	}

	/**
	 * SET the day's total to $hours and its note. $hours of zero removes the
	 * entry, blanking the page so its SMW annotations are dropped.
	 */
	public function setDuration(
		Authority $performer, string $targetUser, string $customer, string $job, string $task, string $day,
		float $hours, string $note = ''
	): void {
		$note = $this->wikitext->normalizeNote( $note );
		$title = $this->dayEntryTitle( $targetUser, $customer, $job, $task, $day );
		if ( !$title ) {
			return;
		}

		if ( $hours <= 0 ) {
			// Blank (not delete) to drop the SMW data; PurgeHooks then can't
			// read the parents off the entry, so refresh them here.
			if ( $title->exists() ) {
				$this->wikiPageFactory->newFromTitle( $title )->doUserEditContent(
					new WikitextContent( '' ),
					$performer,
					wfMessage( 'timetracker-summary-remove' )->inContentLanguage()->text()
				);
			}
			$this->purgeParents( $customer, $job );
			return;
		}

		$this->write( $title, $performer, [
			'day' => $day,
			'customer' => $customer,
			'job' => $job,
			'task' => $task,
			'user' => $targetUser,
			'duration' => Duration::trim( round( $hours, 4 ) ),
			'notes' => $note,
		], 'timetracker-summary-edit' );
	}

	/**
	 * The stored [ duration (hours), note ] for a day, or [ 0.0, '' ].
	 *
	 * @return array{0:float,1:string}
	 */
	public function currentEntry( string $user, string $customer, string $job, string $task, string $day ): array {
		$title = $this->dayEntryTitle( $user, $customer, $job, $task, $day );
		if ( !$title || !$title->exists() ) {
			return [ 0.0, '' ];
		}
		$text = $this->pageText( $title );
		return [ (float)$this->wikitext->field( $text, 'duration' ),
			$this->wikitext->field( $text, 'notes' ) ];
	}

	/** Refresh the customer + job pages (their ids) whose lists include a day. */
	public function purgeParents( string $customerId, string $jobId ): void {
		$this->purgeTitles( [ $customerId, $jobId, 'Customers' ] );
	}

	/** Write a {{Time entry|…}} page as $performer. */
	private function write( Title $title, Authority $performer, array $fields, string $summaryKey ): void {
		$this->wikiPageFactory->newFromTitle( $title )->doUserEditContent(
			new WikitextContent( $this->wikitext->build( self::TEMPLATE, $fields ) ),
			$performer,
			wfMessage( $summaryKey )->inContentLanguage()->text()
		);
	}

	private function pageText( Title $title ): string {
		$content = $this->wikiPageFactory->newFromTitle( $title )->getContent();
		return $content instanceof WikitextContent ? $content->getText() : '';
	}
}
