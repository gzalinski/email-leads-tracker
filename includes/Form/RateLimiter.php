<?php
/**
 * Limits the number of leads created from the same IP within an hour.
 *
 * The IP is not stored: the key is an HMAC hash of the IP.
 *
 * @package LocationLeadTracker
 */

namespace LocationLeadTracker\Form;

use LocationLeadTracker\Settings\Settings;

defined( 'ABSPATH' ) || exit;

final class RateLimiter {

	const WINDOW = HOUR_IN_SECONDS;

	private static function key( string $ip ): string {
		return 'llt_rl_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 32 );
	}

	public static function allow( string $ip ): bool {
		$limit = (int) Settings::get( 'rate_limit' );
		if ( $limit <= 0 ) {
			return true;
		}
		$entry = get_transient( self::key( $ip ) );
		if ( ! is_array( $entry ) || ( $entry['exp'] ?? 0 ) < time() ) {
			return true;
		}
		return (int) $entry['n'] < $limit;
	}

	public static function hit( string $ip ): void {
		if ( (int) Settings::get( 'rate_limit' ) <= 0 ) {
			return;
		}
		$key   = self::key( $ip );
		$entry = get_transient( $key );
		if ( ! is_array( $entry ) || ( $entry['exp'] ?? 0 ) < time() ) {
			$entry = array(
				'n'   => 0,
				'exp' => time() + self::WINDOW,
			);
		}
		++$entry['n'];
		set_transient( $key, $entry, max( 1, (int) $entry['exp'] - time() ) );
	}
}
