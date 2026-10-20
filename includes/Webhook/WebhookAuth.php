<?php
/**
 * Autentificarea webhookului Postmark: Basic Auth (Postmark nu semnează webhookurile) și filtru IP opțional.
 *
 * @package LocationLeadTracker
 */

namespace LocationLeadTracker\Webhook;

use LocationLeadTracker\Http\ClientIp;
use LocationLeadTracker\Settings\Settings;

defined( 'ABSPATH' ) || exit;

final class WebhookAuth {

	/** IP-urile webhookurilor Postmark (support article 800-ips-for-firewalls, verificat octombrie 2026). */
	const POSTMARK_IPS = array( '3.134.147.250', '50.31.156.6', '50.31.156.77', '18.217.206.57' );

	const OK             = 'ok';
	const NOT_CONFIGURED = 'not_configured';
	const MISSING_HEADER = 'missing_header';
	const BAD_CREDENTIAL = 'bad_credentials';
	const IP_NOT_ALLOWED = 'ip_not_allowed';

	/**
	 * @param string|null $authorization Headerul Authorization din cerere.
	 * @param array       $server        $_SERVER.
	 * @return string Unul dintre OK, NOT_CONFIGURED, MISSING_HEADER, BAD_CREDENTIAL, IP_NOT_ALLOWED.
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
	 * Extrage credențialele: din headerul Authorization sau din variabilele PHP (PHP_AUTH_*).
	 * Unele servere (Apache cu CGI/FPM) elimină headerul; vezi README.
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
			self::NOT_CONFIGURED => 'credențialele webhookului nu sunt generate',
			self::MISSING_HEADER => 'cererea nu conține headerul Authorization (serverul îl poate elimina; vezi documentația)',
			self::BAD_CREDENTIAL => 'utilizator sau parolă greșite',
			self::IP_NOT_ALLOWED => 'IP-ul nu aparține Postmark',
		);
		return $labels[ $result ] ?? $result;
	}
}
