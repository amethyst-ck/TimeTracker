<?php

namespace MediaWiki\Extension\TimeTracker;

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
}
