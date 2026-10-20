<?php
/**
 * Checks whether an IP belongs to a CIDR network (IPv4 and IPv6).
 *
 * @package EmailLeadsTracker
 */

namespace EmailLeadsTracker\Http;

defined( 'ABSPATH' ) || exit;

final class Cidr {

	public static function is_valid( string $cidr ): bool {
		$parts = explode( '/', trim( $cidr ) );
		if ( count( $parts ) > 2 ) {
			return false;
		}
		$packed = @inet_pton( $parts[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $packed ) {
			return false;
		}
		if ( ! isset( $parts[1] ) ) {
			return true;
		}
		if ( ! ctype_digit( $parts[1] ) ) {
			return false;
		}
		return (int) $parts[1] <= strlen( $packed ) * 8;
	}

	public static function contains( string $cidr, string $ip ): bool {
		$parts   = explode( '/', trim( $cidr ) );
		$network = @inet_pton( $parts[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$address = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $network || false === $address || strlen( $network ) !== strlen( $address ) ) {
			return false;
		}

		$bits = isset( $parts[1] ) ? (int) $parts[1] : strlen( $network ) * 8;
		if ( $bits < 0 || $bits > strlen( $network ) * 8 ) {
			return false;
		}

		$bytes = intdiv( $bits, 8 );
		if ( substr( $network, 0, $bytes ) !== substr( $address, 0, $bytes ) ) {
			return false;
		}
		$remaining = $bits % 8;
		if ( 0 === $remaining ) {
			return true;
		}
		$mask = ( 0xFF << ( 8 - $remaining ) ) & 0xFF;
		return ( ord( $network[ $bytes ] ) & $mask ) === ( ord( $address[ $bytes ] ) & $mask );
	}

	/**
	 * @param string[] $cidrs Networks.
	 */
	public static function in_any( array $cidrs, string $ip ): bool {
		foreach ( $cidrs as $cidr ) {
			if ( self::contains( $cidr, $ip ) ) {
				return true;
			}
		}
		return false;
	}
}
