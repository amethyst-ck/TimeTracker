<?php

namespace MediaWiki\Extension\TimeTracker\Tests\Unit;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\TimeTracker\Timezone;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\TimeTracker\Timezone
 */
class TimezoneTest extends MediaWikiUnitTestCase {

	private function timezone( string $localtimezone ): Timezone {
		return new Timezone( new HashConfig( [ 'Localtimezone' => $localtimezone ] ) );
	}

	public function testSystemReturnsConfiguredZone() {
		$this->assertSame( 'America/New_York', $this->timezone( 'America/New_York' )->system() );
	}

	public function testSystemFallsBackWhenUnset() {
		$this->assertSame( date_default_timezone_get(), $this->timezone( '' )->system() );
	}

	public function testSafeAcceptsValidZone() {
		$this->assertSame( 'UTC', $this->timezone( 'America/New_York' )->safe( 'UTC' ) );
	}

	public function testSafeFallsBackForInvalidZone() {
		$tz = $this->timezone( 'America/New_York' );
		$this->assertSame( 'America/New_York', $tz->safe( 'Not/ARealZone' ) );
	}

	public function testSafeFallsBackForEmptyZone() {
		$this->assertSame( 'America/New_York', $this->timezone( 'America/New_York' )->safe( '' ) );
	}

	public function testSafeZoneReturnsDateTimeZone() {
		$this->assertSame( 'UTC', $this->timezone( 'UTC' )->safeZone()->getName() );
	}
}
