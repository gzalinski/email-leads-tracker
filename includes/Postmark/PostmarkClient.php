<?php
/**
 * Client minimal pentru Postmark API.
 *
 * @package LocationLeadTracker
 */

namespace LocationLeadTracker\Postmark;

use LocationLeadTracker\Settings\Settings;
use LocationLeadTracker\Util;

defined( 'ABSPATH' ) || exit;

final class PostmarkClient {

	const API = 'https://api.postmarkapp.com';

	/** @var string */
	private $token;
	/** @var int */
	private $timeout;

	public function __construct( ?string $token = null ) {
		$this->token   = null !== $token ? $token : Settings::secret( 'postmark_token' );
		$this->timeout = max( 5, min( 60, (int) Settings::get( 'postmark_timeout' ) ) );
	}

	public function has_token(): bool {
		return '' !== $this->token;
	}

	/**
	 * POST /email
	 */
	public function send( array $payload ): SendResult {
		if ( ! $this->has_token() ) {
			return SendResult::failure( SendResult::CONFIG, 'no_token', ErrorClassifier::describe( 'no_token' ) );
		}

		$response = wp_remote_post(
			self::API . '/email',
			array(
				'timeout'     => $this->timeout,
				'headers'     => $this->headers(),
				'body'        => (string) wp_json_encode( $payload ),
				'data_format' => 'body',
			)
		);

		if ( is_wp_error( $response ) ) {
			return ErrorClassifier::from_transport_error( $response );
		}
		return ErrorClassifier::from_response(
			(int) wp_remote_retrieve_response_code( $response ),
			(string) wp_remote_retrieve_body( $response ),
			wp_remote_retrieve_header( $response, 'retry-after' )
		);
	}

	/**
	 * Caută mesajul trimis pentru un lead, după metadata `llt_lead_id` (ARCHITECTURE.md §7.4).
	 *
	 * @return array{found:bool,message_id:string}|\WP_Error
	 */
	public function find_by_lead( int $lead_id ) {
		$data = $this->get(
			'/messages/outbound',
			array(
				'count'                => 1,
				'offset'               => 0,
				'messagestream'        => (string) Settings::get( 'postmark_stream' ),
				'metadata_llt_lead_id' => (string) $lead_id,
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$message = $data['Messages'][0] ?? null;
		return array(
			'found'      => is_array( $message ) && ! empty( $message['MessageID'] ),
			'message_id' => is_array( $message ) ? (string) ( $message['MessageID'] ?? '' ) : '',
		);
	}

	/**
	 * GET /messages/outbound/{id}/details
	 *
	 * @return array|\WP_Error
	 */
	public function message_details( string $message_id ) {
		return $this->get( '/messages/outbound/' . rawurlencode( $message_id ) . '/details' );
	}

	/**
	 * GET /bounces/{id}
	 *
	 * @return array|\WP_Error
	 */
	public function bounce( int $bounce_id ) {
		return $this->get( '/bounces/' . $bounce_id );
	}

	/**
	 * @return array|\WP_Error Corpul JSON decodat; eroarea conține `status` (cod HTTP) în date.
	 */
	public function get( string $path, array $query = array() ) {
		if ( ! $this->has_token() ) {
			return new \WP_Error( 'no_token', ErrorClassifier::describe( 'no_token' ), array( 'status' => 0 ) );
		}
		$url      = self::API . $path . ( $query ? '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ) : '' );
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => $this->timeout,
				'headers' => $this->headers(),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'transport', Util::error_text( $response->get_error_message() ), array( 'status' => 0 ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$json   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $status || ! is_array( $json ) ) {
			$message = is_array( $json ) && isset( $json['Message'] ) ? (string) $json['Message'] : 'HTTP ' . $status;
			return new \WP_Error( 'http_' . $status, Util::error_text( $message ), array( 'status' => $status ) );
		}
		return $json;
	}

	private function headers(): array {
		return array(
			'Accept'                  => 'application/json',
			'Content-Type'            => 'application/json',
			'X-Postmark-Server-Token' => $this->token,
		);
	}
}
