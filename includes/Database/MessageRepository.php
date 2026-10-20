<?php
/**
 * Accesul la tabelul temporar `location_lead_messages` (datele personale ale vizitatorului).
 *
 * @package LocationLeadTracker
 */

namespace LocationLeadTracker\Database;

use LocationLeadTracker\Util;

defined( 'ABSPATH' ) || exit;

final class MessageRepository {

	public static function find( int $lead_id ): ?object {
		global $wpdb;
		$table = Schema::messages();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE lead_id = %d", $lead_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $row : null;
	}

	public static function delete( int $lead_id ): int {
		global $wpdb;
		return (int) $wpdb->delete( Schema::messages(), array( 'lead_id' => $lead_id ), array( '%d' ) );
	}

	/**
	 * @param int[] $lead_ids ID-uri.
	 */
	public static function delete_many( array $lead_ids ): int {
		global $wpdb;
		$lead_ids = Util::ids( $lead_ids );
		if ( ! $lead_ids ) {
			return 0;
		}
		$table = Schema::messages();
		return (int) $wpdb->query( "DELETE FROM {$table} WHERE lead_id IN (" . Util::in_list( $lead_ids ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Din lista dată, leadurile care încă au conținut.
	 *
	 * @param int[] $lead_ids ID-uri.
	 * @return int[]
	 */
	public static function existing( array $lead_ids ): array {
		global $wpdb;
		$lead_ids = Util::ids( $lead_ids );
		if ( ! $lead_ids ) {
			return array();
		}
		$table = Schema::messages();
		return array_map( 'intval', (array) $wpdb->get_col( "SELECT lead_id FROM {$table} WHERE lead_id IN (" . Util::in_list( $lead_ids ) . ')' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Mesajele unui vizitator, pentru exportul GDPR.
	 *
	 * @return object[]
	 */
	public static function by_sender( string $email, int $limit, int $offset ): array {
		global $wpdb;
		$table = Schema::messages();
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE sender_email = %s ORDER BY lead_id ASC LIMIT %d OFFSET %d", $email, $limit, $offset ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Șterge un lot de mesaje ale unui vizitator, pentru ștergerea GDPR.
	 */
	public static function delete_by_sender( string $email, int $limit ): int {
		global $wpdb;
		$table = Schema::messages();
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT lead_id FROM {$table} WHERE sender_email = %s LIMIT %d", $email, $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return self::delete_many( (array) $ids );
	}
}
