<?php
/**
 * Detectarea IP-ului real al vizitatorului (ARCHITECTURE.md §6.5).
 *
 * Headerele de proxy se citesc doar când conexiunea vine de la un proxy cunoscut,
 * altfel ar putea fi falsificate de oricine trimite cereri direct la server.
 *
 * @package LocationLeadTracker
 */

namespace LocationLeadTracker\Http;

use LocationLeadTracker\Settings\Settings;

defined( 'ABSPATH' ) || exit;

final class ClientIp {

	const METHOD_LABELS = array(
		'direct'     => 'conexiune directă',
		'cloudflare' => 'prin Cloudflare',
		'proxy'      => 'prin proxy',
		'unknown'    => 'necunoscut',
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
	 * @return array{0:string,1:string} [ip, metodă]
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
	 * Logica de detectare, fără stare (testabilă).
	 *
	 * @param array    $server Variabilele $_SERVER.
	 * @param string   $mode   auto | direct | cloudflare.
	 * @param string[] $extra  Proxy-uri suplimentare (CIDR).
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

		// Pasul 2: conexiunea vine de la Cloudflare.
		if ( ProxyRanges::is_cloudflare( $remote ) ) {
			$cf = isset( $server['HTTP_CF_CONNECTING_IP'] ) ? trim( (string) $server['HTTP_CF_CONNECTING_IP'] ) : '';
			return self::valid( $cf ) ? array( $cf, 'cloudflare' ) : array( $remote, 'cloudflare' );
		}
		if ( 'cloudflare' === $mode ) {
			return array( $remote, 'direct' );
		}

		// Pasul 3: proxy local, intern sau declarat în setări.
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

		// Pasul 4: conexiune directă; headerele sunt ignorate.
		return array( $remote, 'direct' );
	}

	public static function valid( string $ip ): bool {
		return '' !== $ip && false !== filter_var( $ip, FILTER_VALIDATE_IP );
	}

	/**
	 * Adresă privată/locală sau proxy declarat în setări.
	 *
	 * @param string[] $extra Proxy-uri suplimentare.
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
