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

	/**
	 * @dataProvider provideParse
	 */
	public function testParse( string $input, ?float $expected ) {
		$got = Duration::parse( $input );
		if ( $expected === null ) {
			$this->assertNull( $got );
		} else {
			$this->assertEqualsWithDelta( $expected, $got, 1e-9 );
		}
	}

	public static function provideParse(): array {
		return [
			'h:mm' => [ '2:30', 2.5 ],
			'decimal' => [ '2.5', 2.5 ],
			'whole' => [ '2', 2.0 ],
			'minutes only' => [ ':30', 0.5 ],
			'hours colon' => [ '2:', 2.0 ],
			'leading dot' => [ '.5', 0.5 ],
			'empty clears' => [ '', 0.0 ],
			'whitespace clears' => [ '   ', 0.0 ],
			'odd minutes' => [ '1:07', 1.0 + 7 / 60 ],
			'letters invalid' => [ 'abc', null ],
			'minutes over 59 invalid' => [ '2:75', null ],
			'negative invalid' => [ '-1', null ],
			'double dot invalid' => [ '2.5.1', null ],
			'exponent invalid' => [ '1e3', null ],
			'suffix invalid' => [ '2h', null ],
		];
	}
}
