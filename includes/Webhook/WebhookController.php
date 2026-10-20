<?php
/**
 * The REST endpoint for the Postmark webhook: POST /wp-json/llt/v1/postmark
 *
 * Response codes (Postmark retries only on 5xx, 408, 429 and network errors):
 * - 401: authentication failed (no retry);
 * - 200: event processed, duplicate, uncorrelated, ignored or invalid payload;
 * - 500: internal error, so Postmark retries.
 *
 * @package LocationLeadTracker
 */

namespace LocationLeadTracker\Webhook;

use LocationLeadTracker\Settings\Settings;
use LocationLeadTracker\State;
use LocationLeadTracker\Util;

defined( 'ABSPATH' ) || exit;

final class WebhookController {

	const NAMESPACE = 'llt/v1';
	const ROUTE     = '/postmark';
	const MAX_BODY  = 262144;

	public static function register(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle' ),
				'permission_callback' => '__return_true', // Authentication happens in the handler, so we can answer 401 without retries.
			)
		);
	}

	/**
	 * Postmark's Basic Auth credentials are not a WordPress user.
	 * On sites that use Application Passwords, WordPress would try to authenticate them
	 * and reject the request with 401 before it reaches the handler. Only the webhook route is excluded.
	 *
	 * @param bool $is_api_request Current value.
	 */
	public static function skip_application_passwords( $is_api_request ): bool {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( false !== strpos( $uri, self::NAMESPACE . self::ROUTE ) || false !== strpos( rawurldecode( $uri ), self::NAMESPACE . self::ROUTE ) ) {
			return false;
		}
		return (bool) $is_api_request;
	}

	public static function url(): string {
		return rest_url( self::NAMESPACE . self::ROUTE );
	}

	/**
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public static function handle( $request ) {
		if ( ! Settings::tracking_enabled() ) {
			return new \WP_REST_Response( array( 'ok' => false, 'error' => 'disabled' ), 404 );
		}

		$auth = WebhookAuth::check( $request->get_header( 'authorization' ), $_SERVER );
		if ( WebhookAuth::OK !== $auth ) {
			State::set(
				'last_webhook_rejected',
				array(
					'at'     => time(),
					'reason' => $auth,
				)
			);
			return new \WP_REST_Response( array( 'ok' => false, 'error' => 'unauthorized' ), 401 );
		}

		$body = (string) $request->get_body();
		if ( strlen( $body ) > self::MAX_BODY ) {
			return new \WP_REST_Response( array( 'ok' => false, 'error' => 'too_large' ), 413 );
		}

		$payload = json_decode( $body, true );
		if ( ! is_array( $payload ) || empty( $payload['RecordType'] ) ) {
			Util::log( 'Webhook with an invalid payload, ignored.' );
			return new \WP_REST_Response( array( 'ok' => true, 'result' => 'invalid_payload' ), 200 );
		}

		try {
			$result = EventProcessor::handle( $payload );
		} catch ( \Throwable $e ) {
			Util::log( 'Error while processing the webhook: ' . $e->getMessage() );
			return new \WP_REST_Response( array( 'ok' => false, 'error' => 'internal' ), 500 );
		}

		State::set(
			'last_webhook',
			array(
				'at'     => time(),
				'type'   => (string) $payload['RecordType'],
				'result' => $result,
				'trace'  => substr( (string) $request->get_header( 'x_pm_webhook_trace_id' ), 0, 64 ),
			)
		);
		if ( EventProcessor::RESULT_UNCORRELATED === $result ) {
			Util::log( 'Uncorrelated webhook: ' . (string) ( $payload['MessageID'] ?? '' ) );
		}

		return new \WP_REST_Response( array( 'ok' => true, 'result' => $result ), 200 );
	}
}
