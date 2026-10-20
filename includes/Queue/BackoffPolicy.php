<?php
/**
 * Computes the delay until the next retry: interval from the settings, ±20% jitter, Retry-After.
 *
 * @package EnspireEmailLeadsTracker
 */

namespace EnspireEmailLeadsTracker\Queue;

use EnspireEmailLeadsTracker\Settings\Settings;

defined( 'ABSPATH' ) || exit;

final class BackoffPolicy {

	const JITTER        = 0.2;
	const MIN_DELAY     = 30;
	const AMBIGUOUS_MIN = 300;

	/**
	 * @param int        $retry_index Retry index (0 = first retry).
	 * @param int        $retry_after Seconds requested by Postmark.
	 * @param int[]|null $schedule    Intervals in minutes (from the settings by default).
	 */
	public static function delay( int $retry_index, int $retry_after = 0, ?array $schedule = null ): int {
		$schedule = $schedule ?? Settings::retry_schedule();
		$minutes  = $schedule[ min( max( 0, $retry_index ), count( $schedule ) - 1 ) ];
		$base     = $minutes * MINUTE_IN_SECONDS;
		$jitter   = $base * ( wp_rand( -1000, 1000 ) / 1000 ) * self::JITTER;
		return max( (int) round( $base + $jitter ), $retry_after, self::MIN_DELAY );
	}
}
