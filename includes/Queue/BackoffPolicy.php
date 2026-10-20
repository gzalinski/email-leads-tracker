<?php
/**
 * Calculul întârzierii până la următorul retry: interval din setări, jitter ±20%, Retry-After.
 *
 * @package LocationLeadTracker
 */

namespace LocationLeadTracker\Queue;

use LocationLeadTracker\Settings\Settings;

defined( 'ABSPATH' ) || exit;

final class BackoffPolicy {

	const JITTER        = 0.2;
	const MIN_DELAY     = 30;
	const AMBIGUOUS_MIN = 300;

	/**
	 * @param int      $retry_index Indexul retry-ului (0 = primul retry).
	 * @param int      $retry_after Secunde cerute de Postmark.
	 * @param int[]|null $schedule  Intervalele în minute (implicit din setări).
	 */
	public static function delay( int $retry_index, int $retry_after = 0, ?array $schedule = null ): int {
		$schedule = $schedule ?? Settings::retry_schedule();
		$minutes  = $schedule[ min( max( 0, $retry_index ), count( $schedule ) - 1 ) ];
		$base     = $minutes * MINUTE_IN_SECONDS;
		$jitter   = $base * ( wp_rand( -1000, 1000 ) / 1000 ) * self::JITTER;
		return max( (int) round( $base + $jitter ), $retry_after, self::MIN_DELAY );
	}
}
