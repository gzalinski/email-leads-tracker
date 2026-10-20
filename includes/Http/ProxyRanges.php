<?php
/**
 * Cloudflare networks. The bundled list works from day one; a weekly cron refreshes it.
 *
 * Source: https://www.cloudflare.com/ips/
 *
 * @package EmailLeadsTracker
 */

namespace EmailLeadsTracker\Http;

defined( 'ABSPATH' ) || exit;

final class ProxyRanges {

	const OPTION = 'llt_proxy_ranges';

	const DEFAULT_V4 = array(
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
	);

	const DEFAULT_V6 = array(
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	);

	const SOURCES = array(
		'v4' => 'https://www.cloudflare.com/ips-v4/',
		'v6' => 'https://www.cloudflare.com/ips-v6/',
	);

	/** @var string[]|null */
	private static $cache = null;

	/**
	 * @return string[]
	 */
	public static function cloudflare(): array {
		if ( null === self::$cache ) {
			$stored = get_option( self::OPTION );
			if ( is_array( $stored ) && ! empty( $stored['v4'] ) && ! empty( $stored['v6'] ) ) {
				self::$cache = array_merge( (array) $stored['v4'], (array) $stored['v6'] );
			} else {
				self::$cache = array_merge( self::DEFAULT_V4, self::DEFAULT_V6 );
			}
		}
		return self::$cache;
	}

	public static function is_cloudflare( string $ip ): bool {
		return Cidr::in_any( self::cloudflare(), $ip );
	}

	public static function updated_at(): int {
		$stored = get_option( self::OPTION );
		return is_array( $stored ) ? (int) ( $stored['updated'] ?? 0 ) : 0;
	}

	/**
	 * Downloads the current list. If the download fails, the existing list stays unchanged.
	 */
	public static function refresh(): bool {
		$lists = array();
		foreach ( self::SOURCES as $family => $url ) {
			$response = wp_remote_get( $url, array( 'timeout' => 15 ) );
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return false;
			}
			$cidrs = self::parse( (string) wp_remote_retrieve_body( $response ) );
			if ( ! $cidrs ) {
				return false;
			}
			$lists[ $family ] = $cidrs;
		}

		update_option(
			self::OPTION,
			array(
				'v4'      => $lists['v4'],
				'v6'      => $lists['v6'],
				'updated' => time(),
			),
			false
		);
		self::$cache = null;
		return true;
	}

	/**
	 * @return string[]
	 */
	public static function parse( string $body ): array {
		$cidrs = array();
		foreach ( preg_split( '/\R/', $body ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line && false !== strpos( $line, '/' ) && Cidr::is_valid( $line ) ) {
				$cidrs[] = $line;
			}
		}
		return $cidrs;
	}
}
