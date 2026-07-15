<?php

namespace MediaWiki\Extension\TimeTracker;

use DateTime;
use DateTimeZone;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Permissions\Authority;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use WikitextContent;

/**
 * The Start/Stop stopwatch's state and accounting. A running timer is a control
 * page under the actor's user page (start instant, customer/job id, target
 * user, note). On stop the elapsed span is split at every midnight and each
 * day's portion is accumulated into that day's Time entry (via {@see EntryStore}).
 */
class Timer {

	private const TEMPLATE = 'Running timer';
	private const TIME_FORMAT = 'Y-m-d H:i:s';
	private const NOT_RUNNING = '<!-- no timer running -->';

	public function __construct(
		private readonly WikiPageFactory $wikiPageFactory,
		private readonly TitleFactory $titleFactory,
		private readonly EntryStore $entryStore,
		private readonly EntryWikitext $wikitext,
		private readonly Timezone $timezone
	) {
	}

	/** The running-timer control page for the acting user. */
	private function runningTitle( string $actorName ): ?Title {
		return $this->titleFactory->makeTitleSafe( NS_USER, $actorName . '/TimeTracker/running' );
	}

	/** Whether the acting user already has a running timer. */
	public function isRunning( string $actorName ): bool {
		return $this->getRunning( $actorName ) !== null;
	}

	/**
	 * Begin a timer for $targetUser on behalf of $actor, unless $actor already
	 * has one running. Caller is responsible for validating the customer/job
	 * pair and resolving the target user.
	 */
	public function start(
		Authority $actor, string $targetUser, string $customerId, string $jobId, string $taskId
	): void {
		$actorName = $actor->getUser()->getName();
		if ( $this->isRunning( $actorName ) ) {
			return;
		}
		$title = $this->runningTitle( $actorName );
		if ( !$title ) {
			return;
		}
		$tz = $this->timezone->system();
		$this->wikiPageFactory->newFromTitle( $title )->doUserEditContent(
			new WikitextContent( $this->wikitext->build( self::TEMPLATE, [
				'customer' => $customerId,
				'job' => $jobId,
				'task' => $taskId,
				'user' => $targetUser,
				'start' => ( new DateTime( 'now', new DateTimeZone( $tz ) ) )->format( self::TIME_FORMAT ),
				'timezone' => $tz,
			] ) ),
			$actor,
			wfMessage( 'timetracker-summary-start' )->inContentLanguage()->text()
		);
	}

	/**
	 * The acting user's running timer as
	 * [ customer, job, task, user, start, timezone ], or null.
	 *
	 * @return array{customer:string,job:string,task:string,user:string,start:string,timezone:string}|null
	 */
	public function getRunning( string $actorName ): ?array {
		$title = $this->runningTitle( $actorName );
		if ( !$title || !$title->exists() ) {
			return null;
		}
		$content = $this->wikiPageFactory->newFromTitle( $title )->getContent();
		$text = $content instanceof WikitextContent ? $content->getText() : '';
		if ( !str_contains( $text, '{{' . self::TEMPLATE ) ) {
			return null;
		}
		return [
			'customer' => $this->wikitext->field( $text, 'customer' ),
			'job' => $this->wikitext->field( $text, 'job' ),
			'task' => $this->wikitext->field( $text, 'task' ),
			'user' => $this->wikitext->field( $text, 'user' ),
			'start' => $this->wikitext->field( $text, 'start' ),
			'timezone' => $this->wikitext->field( $text, 'timezone' ),
		];
	}

	/**
	 * Stop the running timer: split the elapsed span at each midnight and
	 * accumulate every day's portion into the target user's Time entry. Returns
	 * the stopped bucket and, per affected day, that cell's resulting total (so
	 * the grid can update in place) — or null if no timer was running.
	 *
	 * @return array{total:float,customer:string,job:string,task:string,user:string,days:array<string,float>}|null
	 */
	public function stop( Authority $actor ): ?array {
		$actorName = $actor->getUser()->getName();
		$running = $this->getRunning( $actorName );
		if ( !$running || $running['start'] === '' ) {
			return null;
		}
		$tz = $this->timezone->safeZone( $running['timezone'] );
		try {
			$start = new DateTime( $running['start'], $tz );
		} catch ( \Exception $e ) {
			$this->clear( $actor );
			return null;
		}
		$now = new DateTime( 'now', $tz );
		$target = $running['user'] !== '' ? $running['user'] : $actorName;

		$total = 0.0;
		$days = [];
		$cursor = clone $start;
		// Round the whole span UP to the nearest minute once, distributing the
		// minutes across days via a running cumulative — rounding each day's
		// segment separately would add up to a minute per midnight crossed.
		$cumSeconds = 0;
		$cumMinutes = 0;
		while ( $cursor < $now ) {
			$day = $cursor->format( 'Y-m-d' );
			$nextMidnight = ( clone $cursor )->setTime( 0, 0, 0 )->modify( '+1 day' );
			$segmentEnd = $now < $nextMidnight ? $now : $nextMidnight;
			$seconds = $segmentEnd->getTimestamp() - $cursor->getTimestamp();
			if ( $seconds > 0 ) {
				$cumSeconds += $seconds;
				$newCumMinutes = (int)ceil( $cumSeconds / 60 );
				$minutes = $newCumMinutes - $cumMinutes;
				$cumMinutes = $newCumMinutes;
				if ( $minutes > 0 ) {
					$hours = $minutes / 60.0;
					$days[$day] = $this->entryStore->addDuration(
						$actor, $target, $running['customer'], $running['job'], $running['task'], $day, $hours );
					$total += $hours;
				}
			}
			$cursor = $nextMidnight;
		}

		$this->clear( $actor );
		return [
			'total' => $total,
			'customer' => $running['customer'],
			'job' => $running['job'],
			'task' => $running['task'],
			'user' => $target,
			'days' => $days,
		];
	}

	/** Blank the acting user's running-timer page. */
	private function clear( Authority $actor ): void {
		$title = $this->runningTitle( $actor->getUser()->getName() );
		if ( $title && $title->exists() ) {
			$this->wikiPageFactory->newFromTitle( $title )->doUserEditContent(
				new WikitextContent( self::NOT_RUNNING ),
				$actor,
				wfMessage( 'timetracker-summary-stop' )->inContentLanguage()->text()
			);
		}
	}
}
