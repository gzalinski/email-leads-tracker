<?php
/**
 * Postmark webhook authentication: Basic Auth (Postmark does not sign webhooks) and an optional IP filter.
 *
 * @package EnspireEmailLeadsTracker
 */

namespace EnspireEmailLeadsTracker\Webhook;

use EnspireEmailLeadsTracker\Http\ClientIp;
use EnspireEmailLeadsTracker\Settings\Settings;

defined( 'ABSPATH' ) || exit;

final class WebhookAuth {

	/** Postmark webhook IPs (support article 800-ips-for-firewalls, checked October 2026). */
	const POSTMARK_IPS = array( '3.134.147.250', '50.31.156.6', '50.31.156.77', '18.217.206.57' );

	const OK             = 'ok';
	const NOT_CONFIGURED = 'not_configured';
	const MISSING_HEADER = 'missing_header';
	const BAD_CREDENTIAL = 'bad_credentials';
	const IP_NOT_ALLOWED = 'ip_not_allowed';

	/**
	 * @param string|null $authorization The request's Authorization header.
	 * @param array       $server        $_SERVER.
	 * @return string One of OK, NOT_CONFIGURED, MISSING_HEADER, BAD_CREDENTIAL, IP_NOT_ALLOWED.
	 */
	public static function check( ?string $authorization, array $server ): string {
		$user = (string) Settings::get( 'webhook_user' );
		$pass = Settings::secret( 'webhook_pass' );
		if ( '' === $user || '' === $pass ) {
			return self::NOT_CONFIGURED;
		}

		if ( Settings::get( 'webhook_ip_filter' ) && ! in_array( ClientIp::get(), self::POSTMARK_IPS, true ) ) {
			return self::IP_NOT_ALLOWED;
		}

		list( $given_user, $given_pass ) = self::credentials( $authorization, $server );
		if ( null === $given_user ) {
			return self::MISSING_HEADER;
		}

		$user_ok = hash_equals( $user, $given_user );
		$pass_ok = hash_equals( $pass, (string) $given_pass );
		return ( $user_ok && $pass_ok ) ? self::OK : self::BAD_CREDENTIAL;
	}

	/**
	 * Extracts the credentials: from the Authorization header or from the PHP variables (PHP_AUTH_*).
	 * Some servers (Apache with CGI/FPM) strip the header; see the README.
	 *
	 * @return array{0:string|null,1:string|null}
	 */
	public static function credentials( ?string $authorization, array $server ): array {
		$header = $authorization;
		if ( null === $header || '' === $header ) {
			$header = (string) ( $server['HTTP_AUTHORIZATION'] ?? ( $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '' ) );
		}
		if ( '' !== $header && 0 === stripos( $header, 'basic ' ) ) {
			$decoded = base64_decode( trim( substr( $header, 6 ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( false !== $decoded && false !== strpos( $decoded, ':' ) ) {
				list( $user, $pass ) = explode( ':', $decoded, 2 );
				return array( $user, $pass );
			}
		}
		if ( isset( $server['PHP_AUTH_USER'] ) ) {
			return array( (string) $server['PHP_AUTH_USER'], (string) ( $server['PHP_AUTH_PW'] ?? '' ) );
		}
		return array( null, null );
	}

	public static function describe( string $result ): string {
		$labels = array(
			self::NOT_CONFIGURED => 'the webhook credentials have not been generated',
			self::MISSING_HEADER => 'the request has no Authorization header (the server may strip it; see the documentation)',
			self::BAD_CREDENTIAL => 'wrong username or password',
			self::IP_NOT_ALLOWED => 'the IP does not belong to Postmark',
		);
		return $labels[ $result ] ?? $result;
	}
}
