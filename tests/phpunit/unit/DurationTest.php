<?php

namespace MediaWiki\Extension\TimeTracker\Tests\Unit;

use MediaWiki\Extension\TimeTracker\Duration;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\TimeTracker\Duration
 */
class DurationTest extends MediaWikiUnitTestCase {

	/**
	 * @dataProvider provideHm
	 */
	public function testHm( $input, string $expected ) {
		$this->assertSame( $expected, Duration::hm( $input ) );
	}

	public static function provideHm(): array {
		return [
			'null' => [ null, '0m' ],
			'empty string' => [ '', '0m' ],
			'zero' => [ 0, '0m' ],
			'whole hours' => [ 2, '2h' ],
			'hours + minutes' => [ 2.5, '2h 30m' ],
			'minutes only' => [ 0.25, '15m' ],
			'string input' => [ '2.25', '2h 15m' ],
			'rounds up to a minute' => [ 0.009, '1m' ],
			'rounds down to nothing' => [ 0.004, '0m' ],
			'non-numeric passes through' => [ 'n/a', 'n/a' ],
			'negative is zero' => [ -3, '0m' ],
		];
	}
}
