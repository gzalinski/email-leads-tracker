<?php
/**
 * Verificarea server-side a tokenului Cloudflare Turnstile.
 *
 * @package LocationLeadTracker
 */

namespace LocationLeadTracker\Form\Captcha;

use LocationLeadTracker\Settings\Settings;

defined( 'ABSPATH' ) || exit;

final class TurnstileVerifier {

	const ENDPOINT = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

	/**
	 * @return bool|null true = valid, false = invalid, null = verificarea nu este disponibilă.
	 */
	public static function verify( string $token, string $ip ): ?bool {
		$secret = Settings::secret( 'turnstile_secret' );
		if ( '' === $secret ) {
			return null;
		}
		if ( '' === $token ) {
			return false;
		}

		$body = array(
			'secret'   => $secret,
			'response' => $token,
		);
		if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			$body['remoteip'] = $ip;
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 10,
				'body'    => $body,
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $json ) ) {
			return null;
		}
		return ! empty( $json['success'] );
	}
}
