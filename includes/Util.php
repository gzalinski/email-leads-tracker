<?php
/**
 * Stateless helpers.
 *
 * @package EmailLeadsTracker
 */

namespace EmailLeadsTracker;

defined( 'ABSPATH' ) || exit;

final class Util {

	/**
	 * Current time in UTC, MySQL format.
	 *
	 * @param int $offset Seconds added to the current time.
	 */
	public static function now( int $offset = 0 ): string {
		return gmdate( 'Y-m-d H:i:s', time() + $offset );
	}

	/**
	 * Converts an ISO 8601 timestamp (e.g. "2026-11-05T16:33:54.9070259Z") to UTC, MySQL format.
	 * Postmark may send 7 decimals; PHP accepts at most 6, so the fraction is truncated.
	 */
	public static function iso_to_mysql( string $iso ): ?string {
		$iso = trim( $iso );
		if ( '' === $iso ) {
			return null;
		}
		$iso = preg_replace( '/(\.\d{6})\d+/', '$1', $iso );
		try {
			$date = new \DateTimeImmutable( $iso );
		} catch ( \Exception $e ) {
			return null;
		}
		return $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Replaces email addresses with *** (for logs and error messages).
	 */
	public static function mask_emails( string $text ): string {
		return (string) preg_replace( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '***', $text );
	}

	/**
	 * Error message safe to store: no email addresses, at most 255 characters.
	 */
	public static function error_text( string $text ): string {
		$text = trim( self::mask_emails( wp_strip_all_tags( $text ) ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 255 ) : substr( $text, 0, 255 );
	}

	/**
	 * Removes line breaks (protection against header injection).
	 */
	public static function single_line( string $text ): string {
		return trim( (string) preg_replace( '/[\r\n\t]+/', ' ', $text ) );
	}

	/**
	 * A MySQL date (UTC) shown in the site's time zone.
	 */
	public static function local_date( ?string $mysql_utc ): string {
		if ( empty( $mysql_utc ) ) {
			return '—';
		}
		return get_date_from_gmt( $mysql_utc, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
	}

	/**
	 * A list of unique positive integer IDs.
	 *
	 * @param mixed $ids Raw values.
	 * @return int[]
	 */
	public static function ids( $ids ): array {
		$ids = array_map( 'intval', (array) $ids );
		return array_values( array_unique( array_filter( $ids, static fn( $id ) => $id > 0 ) ) );
	}

	/**
	 * A list of IDs for an IN clause; the values are already integers.
	 *
	 * @param int[] $ids IDs.
	 */
	public static function in_list( array $ids ): string {
		return implode( ',', array_map( 'intval', $ids ) );
	}

	public static function log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Email Leads Tracker] ' . self::mask_emails( $message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
