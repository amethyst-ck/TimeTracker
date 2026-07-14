<?php

namespace MediaWiki\Extension\TimeTracker;

use DateTime;
use DateTimeZone;
use MediaWiki\Config\Config;

/** Resolves the wiki's working timezone ($wgLocaltimezone), with fallbacks. */
class Timezone {

	public function __construct( private readonly Config $config ) {
	}

	/** The wiki's configured timezone, or the PHP default when unset. */
	public function system(): string {
		$tz = (string)$this->config->get( 'Localtimezone' );
		return $tz !== '' ? $tz : date_default_timezone_get();
	}

	/** $tz if it is a valid timezone, otherwise the wiki's system timezone. */
	public function safe( string $tz ): string {
		if ( $tz === '' ) {
			return $this->system();
		}
		try {
			new DateTimeZone( $tz );
			return $tz;
		} catch ( \Exception $e ) {
			return $this->system();
		}
	}

	/** A DateTimeZone for {@see safe()}. */
	public function safeZone( string $tz = '' ): DateTimeZone {
		return new DateTimeZone( $this->safe( $tz ) );
	}

	/**
	 * The seven Mon–Sun days of the week containing $anchorYmd (or the current
	 * week when null/invalid), in the wiki timezone, for the embedded grid.
	 *
	 * @return array<int,array{ymd:string,label:string}>
	 */
	public function weekDays( ?string $anchorYmd = null ): array {
		try {
			$day = ( new DateTime( $anchorYmd ?: 'now', $this->safeZone() ) )->setTime( 0, 0, 0 );
		} catch ( \Exception $e ) {
			$day = ( new DateTime( 'now', $this->safeZone() ) )->setTime( 0, 0, 0 );
		}
		$d = ( clone $day )->modify( '-' . ( (int)$day->format( 'N' ) - 1 ) . ' days' );
		$out = [];
		for ( $i = 0; $i < 7; $i++ ) {
			$out[] = [ 'ymd' => $d->format( 'Y-m-d' ), 'label' => $d->format( 'D j' ) ];
			$d->modify( '+1 day' );
		}
		return $out;
	}
}
