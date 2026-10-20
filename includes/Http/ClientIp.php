<?php
/**
 * Detects the visitor's real IP (ARCHITECTURE.md §6.5).
 *
 * Proxy headers are read only when the connection comes from a known proxy;
 * otherwise anyone sending requests directly to the server could forge them.
 *
 * @package EmailLeadsTracker
 */

namespace EmailLeadsTracker\Http;

use EmailLeadsTracker\Settings\Settings;

defined( 'ABSPATH' ) || exit;

final class ClientIp {

	const METHOD_LABELS = array(
		'direct'     => 'direct connection',
		'cloudflare' => 'via Cloudflare',
		'proxy'      => 'via proxy',
		'unknown'    => 'unknown',
	);

	/** @var array{0:string,1:string}|null */
	private static $cached = null;

	public static function get(): string {
		return self::detect()[0];
	}

	public static function method(): string {
		return self::detect()[1];
	}

	/**
	 * @return array{0:string,1:string} [ip, method]
	 */
	public static function detect(): array {
		if ( null === self::$cached ) {
			self::$cached = self::resolve( $_SERVER, (string) Settings::get( 'ip_source' ), self::extra_proxies() );
		}
		return self::$cached;
	}

	public static function reset(): void {
		self::$cached = null;
	}

	/**
	 * The detection logic, stateless (testable).
	 *
	 * @param array    $server The $_SERVER variables.
	 * @param string   $mode   auto | direct | cloudflare.
	 * @param string[] $extra  Additional proxies (CIDR).
	 * @return array{0:string,1:string}
	 */
	public static function resolve( array $server, string $mode, array $extra = array() ): array {
		$remote = isset( $server['REMOTE_ADDR'] ) ? trim( (string) $server['REMOTE_ADDR'] ) : '';
		if ( ! self::valid( $remote ) ) {
			return array( '0.0.0.0', 'unknown' );
		}
		if ( 'direct' === $mode ) {
			return array( $remote, 'direct' );
		}

		// Step 2: the connection comes from Cloudflare.
		if ( ProxyRanges::is_cloudflare( $remote ) ) {
			$cf = isset( $server['HTTP_CF_CONNECTING_IP'] ) ? trim( (string) $server['HTTP_CF_CONNECTING_IP'] ) : '';
			return self::valid( $cf ) ? array( $cf, 'cloudflare' ) : array( $remote, 'cloudflare' );
		}
		if ( 'cloudflare' === $mode ) {
			return array( $remote, 'direct' );
		}

		// Step 3: local, internal or configured proxy.
		if ( self::is_trusted_proxy( $remote, $extra ) ) {
			$forwarded = isset( $server['HTTP_X_FORWARDED_FOR'] ) ? (string) $server['HTTP_X_FORWARDED_FOR'] : '';
			if ( '' !== $forwarded ) {
				$chain = array_reverse( array_map( 'trim', explode( ',', $forwarded ) ) );
				foreach ( $chain as $hop ) {
					if ( ! self::valid( $hop ) ) {
						break;
					}
					if ( self::is_trusted_proxy( $hop, $extra ) || ProxyRanges::is_cloudflare( $hop ) ) {
						continue;
					}
					return array( $hop, 'proxy' );
				}
			}
			$real = isset( $server['HTTP_X_REAL_IP'] ) ? trim( (string) $server['HTTP_X_REAL_IP'] ) : '';
			if ( self::valid( $real ) && ! self::is_trusted_proxy( $real, $extra ) ) {
				return array( $real, 'proxy' );
			}
			return array( $remote, 'proxy' );
		}

		// Step 4: direct connection; the headers are ignored.
		return array( $remote, 'direct' );
	}

	public static function valid( string $ip ): bool {
		return '' !== $ip && false !== filter_var( $ip, FILTER_VALIDATE_IP );
	}

	/**
	 * A private/local address or a proxy configured in the settings.
	 *
	 * @param string[] $extra Additional proxies.
	 */
	public static function is_trusted_proxy( string $ip, array $extra = array() ): bool {
		if ( ! self::valid( $ip ) ) {
			return false;
		}
		$public = filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		if ( false === $public ) {
			return true;
		}
		return Cidr::in_any( $extra, $ip );
	}

	/**
	 * @return string[]
	 */
	public static function extra_proxies(): array {
		$lines = preg_split( '/\R/', (string) Settings::get( 'extra_proxies' ) );
		return array_values( array_filter( array_map( 'trim', $lines ), array( Cidr::class, 'is_valid' ) ) );
	}

	public static function method_label( string $method ): string {
		return self::METHOD_LABELS[ $method ] ?? $method;
	}
}
