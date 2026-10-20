<?php
/**
 * Endpointul REST pentru webhookul Postmark: POST /wp-json/llt/v1/postmark
 *
 * Coduri de răspuns (Postmark reîncearcă doar la 5xx, 408, 429 și erori de rețea):
 * - 401: autentificare eșuată (fără reîncercare);
 * - 200: eveniment procesat, duplicat, necorelat, ignorat sau payload invalid;
 * - 500: eroare internă, ca Postmark să reîncerce.
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
				'permission_callback' => '__return_true', // Autentificarea se face în handler, ca să putem răspunde 401 fără reîncercări.
			)
		);
	}

	public static function url(): string {
		return rest_url( self::NAMESPACE . self::ROUTE );
	}

	/**
	 * @param \WP_REST_Request $request Cererea.
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
			Util::log( 'Webhook cu payload invalid, ignorat.' );
			return new \WP_REST_Response( array( 'ok' => true, 'result' => 'invalid_payload' ), 200 );
		}

		try {
			$result = EventProcessor::handle( $payload );
		} catch ( \Throwable $e ) {
			Util::log( 'Eroare la procesarea webhookului: ' . $e->getMessage() );
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
			Util::log( 'Webhook necorelat: ' . (string) ( $payload['MessageID'] ?? '' ) );
		}

		return new \WP_REST_Response( array( 'ok' => true, 'result' => $result ), 200 );
	}
}
