<?php
/**
 * Manual lead actions, shared by WP-CLI and the admin page (v3).
 *
 * @package EnspireEmailLeadsTracker
 */

namespace EnspireEmailLeadsTracker\Lead;

use EnspireEmailLeadsTracker\Database\MessageRepository;
use EnspireEmailLeadsTracker\Database\Schema;
use EnspireEmailLeadsTracker\Util;

defined( 'ABSPATH' ) || exit;

final class LeadActions {

	/**
	 * Puts `failed` leads back in the queue. Only leads that still have content can be resent.
	 *
	 * @param int[] $ids IDs.
	 * @return array{queued:int,no_content:int[],not_failed:int[]}
	 */
	public static function resend( array $ids, int $user_id = 0 ): array {
		global $wpdb;
		$ids    = Util::ids( $ids );
		$result = array(
			'queued'     => 0,
			'no_content' => array(),
			'not_failed' => array(),
		);
		if ( ! $ids ) {
			return $result;
		}

		$table  = Schema::leads();
		$failed = array_map(
			'intval',
			(array) $wpdb->get_col( "SELECT id FROM {$table} WHERE send_status = 'failed' AND id IN (" . Util::in_list( $ids ) . ')' ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$with_content         = MessageRepository::existing( $failed );
		$result['not_failed'] = array_values( array_diff( $ids, $failed ) );
		$result['no_content'] = array_values( array_diff( $failed, $with_content ) );

		if ( $with_content ) {
			$now = Util::now();
			$sql = "UPDATE {$table} SET send_status = 'pending', retry_count = 0, next_retry_at = %s, locked_until = NULL, lock_token = NULL,"
				. ' manual_resend_count = CASE WHEN manual_resend_count < 255 THEN manual_resend_count + 1 ELSE 255 END,'
				. ' last_resend_by = %d, last_resend_at = %s, dismissed_at = NULL, dismissed_by = NULL, updated_at = %s'
				. " WHERE send_status = 'failed' AND id IN (" . Util::in_list( $with_content ) . ')';
			$result['queued'] = (int) $wpdb->query( $wpdb->prepare( $sql, $now, $user_id, $now, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		if ( $result['queued'] > 0 ) {
			self::kick_queue();
			do_action( 'eelt_leads_changed' );
		}
		return $result;
	}

	/**
	 * @param int[] $ids IDs.
	 */
	public static function dismiss( array $ids, int $user_id = 0 ): int {
		return self::set_dismissed( $ids, Util::now(), $user_id ?: null );
	}

	/**
	 * @param int[] $ids IDs.
	 */
	public static function restore( array $ids ): int {
		return self::set_dismissed( $ids, null, null );
	}

	/**
	 * @param int[] $ids IDs.
	 */
	public static function delete_content( array $ids ): int {
		$deleted = MessageRepository::delete_many( $ids );
		if ( $deleted ) {
			do_action( 'eelt_leads_changed' );
		}
		return $deleted;
	}

	/**
	 * Starts processing the queue as soon as possible.
	 */
	public static function kick_queue(): void {
		if ( ! wp_next_scheduled( 'eelt_process_queue_now' ) ) {
			wp_schedule_single_event( time(), 'eelt_process_queue_now' );
		}
		if ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) {
			spawn_cron();
		}
	}

	/**
	 * @param int[] $ids IDs.
	 */
	private static function set_dismissed( array $ids, ?string $at, ?int $user_id ): int {
		global $wpdb;
		$ids = Util::ids( $ids );
		if ( ! $ids ) {
			return 0;
		}
		$table = Schema::leads();
		$sql   = "UPDATE {$table} SET dismissed_at = %s, dismissed_by = %d, updated_at = %s WHERE id IN (" . Util::in_list( $ids ) . ')';
		if ( null === $at ) {
			$sql   = "UPDATE {$table} SET dismissed_at = NULL, dismissed_by = NULL, updated_at = %s WHERE id IN (" . Util::in_list( $ids ) . ')';
			$count = (int) $wpdb->query( $wpdb->prepare( $sql, Util::now() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} else {
			$count = (int) $wpdb->query( $wpdb->prepare( $sql, $at, (int) $user_id, Util::now() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		if ( $count ) {
			do_action( 'eelt_leads_changed' );
		}
		return $count;
	}
}
