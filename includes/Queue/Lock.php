<?php
/**
 * Row-level locking: a lead is processed by one worker at a time (ARCHITECTURE.md §9.3).
 *
 * @package LocationLeadTracker
 */

namespace LocationLeadTracker\Queue;

use LocationLeadTracker\Database\Schema;
use LocationLeadTracker\Util;

defined( 'ABSPATH' ) || exit;

final class Lock {

	const TTL = 300;

	/**
	 * @return string|null The lock token, or null if the lead is not available.
	 */
	public static function claim( int $lead_id ): ?string {
		global $wpdb;
		$table = Schema::leads();
		$token = bin2hex( random_bytes( 16 ) );
		$now   = Util::now();

		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET locked_until = %s, lock_token = %s WHERE id = %d AND send_status = 'pending' AND next_retry_at <= %s AND (locked_until IS NULL OR locked_until < %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Util::now( self::TTL ),
				$token,
				$lead_id,
				$now,
				$now
			)
		);
		return 1 === (int) $claimed ? $token : null;
	}

	public static function release( int $lead_id, string $token ): void {
		global $wpdb;
		$table = Schema::leads();
		$wpdb->query(
			$wpdb->prepare( "UPDATE {$table} SET locked_until = NULL, lock_token = NULL WHERE id = %d AND lock_token = %s", $lead_id, $token ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Global worker lock, so two cron runs do not process the queue in parallel.
	 */
	public static function acquire_worker(): bool {
		$expires = time() + self::TTL;
		if ( add_option( 'llt_worker_lock', $expires, '', false ) ) {
			return true;
		}
		wp_cache_delete( 'llt_worker_lock', 'options' );
		$current = (int) get_option( 'llt_worker_lock', 0 );
		if ( $current < time() ) {
			delete_option( 'llt_worker_lock' );
			return add_option( 'llt_worker_lock', $expires, '', false );
		}
		return false;
	}

	public static function release_worker(): void {
		delete_option( 'llt_worker_lock' );
	}
}
