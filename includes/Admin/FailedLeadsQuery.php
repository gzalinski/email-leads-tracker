<?php
/**
 * Queries for the admin lead list (v3): views, filters, pagination.
 *
 * The same condition is used for the list, the counts and "select all matching the filter",
 * so a bulk action applies exactly to the leads shown by the filter.
 *
 * @package EnspireEmailLeadsTracker
 */

namespace EnspireEmailLeadsTracker\Admin;

use EnspireEmailLeadsTracker\Database\Schema;
use EnspireEmailLeadsTracker\Settings\Settings;
use EnspireEmailLeadsTracker\Util;

defined( 'ABSPATH' ) || exit;

final class FailedLeadsQuery {

	const VIEWS = array(
		'failed'    => 'Failed',
		'stuck'     => 'Stuck',
		'bounced'   => 'Bounced',
		'dismissed' => 'Dismissed',
	);

	const MAX_IDS = 5000;

	/**
	 * The available views. "Bounced" appears only when delivery confirmation is on or bounced leads exist.
	 *
	 * @return array<string,string>
	 */
	public static function views(): array {
		$views = self::VIEWS;
		if ( ! Settings::tracking_enabled() && 0 === self::count( 'bounced', array() ) ) {
			unset( $views['bounced'] );
		}
		return $views;
	}

	public static function sanitize_view( string $view ): string {
		return isset( self::VIEWS[ $view ] ) ? $view : 'failed';
	}

	/**
	 * Filters from the request: error, s (location), from, to (YYYY-MM-DD).
	 *
	 * @param array $request $_GET / $_POST.
	 */
	public static function filters_from( array $request ): array {
		$date = static function ( $value ): string {
			$value = sanitize_text_field( (string) $value );
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
		};
		return array(
			'error' => substr( sanitize_text_field( (string) ( $request['llt_error'] ?? '' ) ), 0, 40 ),
			's'     => substr( sanitize_text_field( (string) ( $request['s'] ?? '' ) ), 0, 100 ),
			'from'  => $date( $request['llt_from'] ?? '' ),
			'to'    => $date( $request['llt_to'] ?? '' ),
		);
	}

	/**
	 * The SQL condition (on the aliases l = lead, m = message, p = post) and its parameters.
	 *
	 * @return array{0:string,1:array}
	 */
	public static function where( string $view, array $filters ): array {
		$where  = array();
		$params = array();

		switch ( self::sanitize_view( $view ) ) {
			case 'stuck':
				// Due more than an hour ago, but not processed (cron stopped or Postmark unavailable).
				$where[]  = "l.send_status = 'pending' AND l.next_retry_at < %s";
				$params[] = Util::now( -HOUR_IN_SECONDS );
				break;
			case 'bounced':
				$where[] = "l.delivery_status = 'bounced' AND l.dismissed_at IS NULL";
				break;
			case 'dismissed':
				$where[] = 'l.dismissed_at IS NOT NULL';
				break;
			case 'failed':
			default:
				$where[] = "l.send_status = 'failed' AND l.dismissed_at IS NULL";
				break;
		}

		if ( ! empty( $filters['error'] ) ) {
			$where[]  = 'l.last_error_code = %s';
			$params[] = $filters['error'];
		}
		if ( ! empty( $filters['s'] ) ) {
			global $wpdb;
			$where[]  = 'p.post_title LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $filters['s'] ) . '%';
		}
		if ( ! empty( $filters['from'] ) ) {
			$where[]  = 'l.created_at >= %s';
			$params[] = get_gmt_from_date( $filters['from'] . ' 00:00:00' );
		}
		if ( ! empty( $filters['to'] ) ) {
			$where[]  = 'l.created_at < %s';
			$params[] = get_gmt_from_date( gmdate( 'Y-m-d', strtotime( $filters['to'] . ' +1 day' ) ) . ' 00:00:00' );
		}

		return array( implode( ' AND ', $where ), $params );
	}

	private static function from(): string {
		global $wpdb;
		return Schema::leads() . ' l LEFT JOIN ' . Schema::messages() . ' m ON m.lead_id = l.id LEFT JOIN ' . $wpdb->posts . ' p ON p.ID = l.object_id';
	}

	private static function prepare( string $sql, array $params ): string {
		global $wpdb;
		return $params ? $wpdb->prepare( $sql, $params ) : $sql; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function count( string $view, array $filters ): int {
		global $wpdb;
		list( $where, $params ) = self::where( $view, $filters );
		return (int) $wpdb->get_var( self::prepare( 'SELECT COUNT(*) FROM ' . self::from() . " WHERE {$where}", $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @return array<string,int>
	 */
	public static function view_counts(): array {
		$counts = array();
		foreach ( array_keys( self::views() ) as $view ) {
			$counts[ $view ] = self::count( $view, array() );
		}
		return $counts;
	}

	/**
	 * All IDs matching the filter (for "select all").
	 *
	 * @return int[]
	 */
	public static function ids( string $view, array $filters ): array {
		global $wpdb;
		list( $where, $params ) = self::where( $view, $filters );
		$params[]               = self::MAX_IDS;
		return array_map( 'intval', (array) $wpdb->get_col( self::prepare( 'SELECT l.id FROM ' . self::from() . " WHERE {$where} ORDER BY l.id ASC LIMIT %d", $params ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @return object[]
	 */
	public static function rows( string $view, array $filters, string $orderby, string $order, int $per_page, int $offset ): array {
		global $wpdb;
		$columns = array(
			'id'       => 'l.id',
			'created'  => 'l.created_at',
			'location' => 'p.post_title',
			'attempts' => '(l.retry_count + l.manual_resend_count)',
		);
		$orderby = $columns[ $orderby ] ?? 'l.id';
		$order   = 'asc' === strtolower( $order ) ? 'ASC' : 'DESC';

		list( $where, $params ) = self::where( $view, $filters );
		$params[]               = max( 1, $per_page );
		$params[]               = max( 0, $offset );

		$sql = 'SELECT l.*, p.post_title AS location_title, (m.lead_id IS NOT NULL) AS has_content, m.created_at AS content_created_at FROM '
			. self::from() . " WHERE {$where} ORDER BY {$orderby} {$order}, l.id DESC LIMIT %d OFFSET %d";
		return (array) $wpdb->get_results( self::prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * The error codes present in a view (for the dropdown filter).
	 *
	 * @return string[]
	 */
	public static function error_codes( string $view ): array {
		global $wpdb;
		list( $where, $params ) = self::where( $view, array() );
		$codes                  = $wpdb->get_col( self::prepare( 'SELECT DISTINCT l.last_error_code FROM ' . self::from() . " WHERE {$where} AND l.last_error_code IS NOT NULL ORDER BY l.last_error_code", $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_values( array_filter( array_map( 'strval', (array) $codes ) ) );
	}

	/**
	 * The number shown in the menu: failed, not dismissed leads that can still be resent.
	 */
	public static function attention_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			'SELECT COUNT(*) FROM ' . Schema::leads() . ' l INNER JOIN ' . Schema::messages() . " m ON m.lead_id = l.id WHERE l.send_status = 'failed' AND l.dismissed_at IS NULL" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
	}
}
