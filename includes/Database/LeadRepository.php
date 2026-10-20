<?php
/**
 * Accesul la tabelul `location_leads`.
 *
 * @package LocationLeadTracker
 */

namespace LocationLeadTracker\Database;

use LocationLeadTracker\Util;

defined( 'ABSPATH' ) || exit;

final class LeadRepository {

	public static function find( int $id ): ?object {
		global $wpdb;
		$table = Schema::leads();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $row : null;
	}

	public static function find_by_message_id( string $message_id ): ?object {
		global $wpdb;
		$table = Schema::leads();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE postmark_message_id = %s", $message_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $row : null;
	}

	/**
	 * Actualizează un lead; `updated_at` se setează automat.
	 *
	 * @param array      $data  Coloane => valori (null = NULL).
	 * @param array|null $where Condiții suplimentare (coloană => valoare).
	 * @return int Număr de rânduri modificate.
	 */
	public static function update( int $id, array $data, ?array $where = null ): int {
		global $wpdb;
		$data['updated_at'] = Util::now();
		$conditions         = array_merge( array( 'id' => $id ), (array) $where );
		$result             = $wpdb->update( Schema::leads(), $data, $conditions );
		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Leadurile `pending` scadente, cele mai vechi primele.
	 *
	 * @return int[]
	 */
	public static function due_ids( int $limit ): array {
		global $wpdb;
		$table = Schema::leads();
		$now   = Util::now();
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE send_status = 'pending' AND next_retry_at <= %s AND (locked_until IS NULL OR locked_until < %s) ORDER BY next_retry_at ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$now,
				$now,
				$limit
			)
		);
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Numărul de leaduri pe stări.
	 *
	 * @return array{send:array<string,int>,delivery:array<string,int>,total:int}
	 */
	public static function counts( ?string $since = null, ?string $until = null, ?int $object_id = null ): array {
		global $wpdb;
		$table  = Schema::leads();
		$where  = array( '1=1' );
		$params = array();
		if ( $since ) {
			$where[]  = 'created_at >= %s';
			$params[] = $since;
		}
		if ( $until ) {
			$where[]  = 'created_at < %s';
			$params[] = $until;
		}
		if ( $object_id ) {
			$where[]  = 'object_id = %d';
			$params[] = $object_id;
		}
		$sql = "SELECT send_status, delivery_status, COUNT(*) AS n FROM {$table} WHERE " . implode( ' AND ', $where ) . ' GROUP BY send_status, delivery_status';
		$rows = $wpdb->get_results( $params ? $wpdb->prepare( $sql, $params ) : $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$result = array(
			'send'     => array(
				'pending'  => 0,
				'accepted' => 0,
				'failed'   => 0,
			),
			'delivery' => array(
				'unknown'   => 0,
				'deferred'  => 0,
				'delivered' => 0,
				'bounced'   => 0,
			),
			'total'    => 0,
		);
		foreach ( (array) $rows as $row ) {
			$n                                         = (int) $row->n;
			$result['send'][ $row->send_status ]       = ( $result['send'][ $row->send_status ] ?? 0 ) + $n;
			$result['delivery'][ $row->delivery_status ] = ( $result['delivery'][ $row->delivery_status ] ?? 0 ) + $n;
			$result['total']                          += $n;
		}
		return $result;
	}

	/**
	 * Leaduri blocate: `pending` și scadente înainte de momentul dat, dar neprocesate.
	 * Leadurile care așteaptă un retry programat (ex.: peste 6 ore) nu sunt blocate.
	 */
	public static function count_stuck( string $due_before ): int {
		global $wpdb;
		$table = Schema::leads();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE send_status = 'pending' AND next_retry_at < %s", $due_before ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Căutare pentru CLI și admin.
	 *
	 * @param array $filters status, delivery, since, until, object_id, limit, offset.
	 * @return object[]
	 */
	public static function search( array $filters ): array {
		global $wpdb;
		$table    = Schema::leads();
		$messages = Schema::messages();
		$where    = array( '1=1' );
		$params   = array();

		if ( ! empty( $filters['status'] ) ) {
			$where[]  = 'l.send_status = %s';
			$params[] = (string) $filters['status'];
		}
		if ( ! empty( $filters['delivery'] ) ) {
			$where[]  = 'l.delivery_status = %s';
			$params[] = (string) $filters['delivery'];
		}
		if ( ! empty( $filters['since'] ) ) {
			$where[]  = 'l.created_at >= %s';
			$params[] = (string) $filters['since'];
		}
		if ( ! empty( $filters['until'] ) ) {
			$where[]  = 'l.created_at < %s';
			$params[] = (string) $filters['until'];
		}
		if ( ! empty( $filters['object_id'] ) ) {
			$where[]  = 'l.object_id = %d';
			$params[] = (int) $filters['object_id'];
		}
		$limit    = max( 1, (int) ( $filters['limit'] ?? 50 ) );
		$offset   = max( 0, (int) ( $filters['offset'] ?? 0 ) );
		$params[] = $limit;
		$params[] = $offset;

		$sql = "SELECT l.*, (m.lead_id IS NOT NULL) AS has_content FROM {$table} l LEFT JOIN {$messages} m ON m.lead_id = l.id WHERE "
			. implode( ' AND ', $where ) . ' ORDER BY l.id DESC LIMIT %d OFFSET %d';
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
