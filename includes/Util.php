<?php
/**
 * Funcții ajutătoare fără stare.
 *
 * @package LocationLeadTracker
 */

namespace LocationLeadTracker;

defined( 'ABSPATH' ) || exit;

final class Util {

	/**
	 * Momentul curent în UTC, format MySQL.
	 *
	 * @param int $offset Secunde adăugate la momentul curent.
	 */
	public static function now( int $offset = 0 ): string {
		return gmdate( 'Y-m-d H:i:s', time() + $offset );
	}

	/**
	 * Convertește un timestamp ISO 8601 (ex.: "2026-11-05T16:33:54.9070259Z") în UTC, format MySQL.
	 * Postmark poate trimite 7 zecimale; PHP acceptă maximum 6, deci fracțiunea se taie.
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
	 * Înlocuiește adresele de email cu *** (pentru loguri și mesaje de eroare).
	 */
	public static function mask_emails( string $text ): string {
		return (string) preg_replace( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '***', $text );
	}

	/**
	 * Mesaj de eroare sigur pentru salvare: fără emailuri, maximum 255 de caractere.
	 */
	public static function error_text( string $text ): string {
		$text = trim( self::mask_emails( wp_strip_all_tags( $text ) ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 255 ) : substr( $text, 0, 255 );
	}

	/**
	 * Elimină caracterele de linie nouă (protecție la injecția de headere).
	 */
	public static function single_line( string $text ): string {
		return trim( (string) preg_replace( '/[\r\n\t]+/', ' ', $text ) );
	}

	/**
	 * Data MySQL (UTC) afișată în fusul orar al site-ului.
	 */
	public static function local_date( ?string $mysql_utc ): string {
		if ( empty( $mysql_utc ) ) {
			return '—';
		}
		return get_date_from_gmt( $mysql_utc, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
	}

	/**
	 * Lista de ID-uri întregi pozitive, fără duplicate.
	 *
	 * @param mixed $ids Valori brute.
	 * @return int[]
	 */
	public static function ids( $ids ): array {
		$ids = array_map( 'intval', (array) $ids );
		return array_values( array_unique( array_filter( $ids, static fn( $id ) => $id > 0 ) ) );
	}

	/**
	 * Lista de ID-uri pentru o clauză IN; valorile sunt deja întregi.
	 *
	 * @param int[] $ids ID-uri.
	 */
	public static function in_list( array $ids ): string {
		return implode( ',', array_map( 'intval', $ids ) );
	}

	public static function log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Location Lead Tracker] ' . self::mask_emails( $message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
