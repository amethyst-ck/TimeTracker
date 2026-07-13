<?php

namespace MediaWiki\Extension\TimeTracker;

/**
 * Formatting for decimal-hour durations, shared by the parser function and
 * Special:TimeReports so they render identically.
 */
class Duration {

	/**
	 * Turn a decimal-hours value ("2.25") into a readable "2h 15m". Rounds to
	 * the nearest minute. Blank/zero -> "0m"; non-numeric passes through.
	 *
	 * @param string|float|int|null $hours
	 */
	public static function hm( $hours ): string {
		if ( $hours === null ) {
			return '0m';
		}
		$trimmed = trim( (string)$hours );
		if ( $trimmed === '' ) {
			return '0m';
		}
		if ( !is_numeric( $trimmed ) ) {
			return (string)$hours;
		}
		$totalMinutes = (int)round( (float)$trimmed * 60 );
		if ( $totalMinutes <= 0 ) {
			return '0m';
		}
		$h = intdiv( $totalMinutes, 60 );
		$m = $totalMinutes % 60;
		if ( $h > 0 && $m > 0 ) {
			return "{$h}h {$m}m";
		}
		if ( $h > 0 ) {
			return "{$h}h";
		}
		return "{$m}m";
	}

	/** A decimal number without trailing zeros: "2.5" not "2.5000"; "3" not "3.0". */
	public static function trim( float $n ): string {
		return rtrim( rtrim( sprintf( '%.4f', $n ), '0' ), '.' );
	}

	/**
	 * Split a decimal-hours value into whole hours and minutes, rounded to the
	 * nearest minute: "8.5" -> [8, 30]. Blank/zero/non-numeric -> [0, 0].
	 *
	 * @param string|float|int|null $hours
	 * @return array{0:int,1:int}
	 */
	public static function hoursMinutes( $hours ): array {
		$trimmed = trim( (string)( $hours ?? '' ) );
		if ( $trimmed === '' || !is_numeric( $trimmed ) ) {
			return [ 0, 0 ];
		}
		$totalMinutes = max( 0, (int)round( (float)$trimmed * 60 ) );
		return [ intdiv( $totalMinutes, 60 ), $totalMinutes % 60 ];
	}
}
