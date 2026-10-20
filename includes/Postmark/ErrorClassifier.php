<?php
/**
 * Classification of Postmark responses (checked against the official Postmark documentation, October 2026).
 *
 * @package EmailLeadsTracker
 */

namespace EmailLeadsTracker\Postmark;

use EmailLeadsTracker\Util;

defined( 'ABSPATH' ) || exit;

final class ErrorClassifier {

	/** ErrorCodes that affect all leads. */
	const CONFIG_CODES = array( 10, 400, 402, 403, 412, 413, 422, 1235, 1236, 1480 );

	/** ErrorCodes that concern only the current lead. */
	const PERMANENT_CODES = array( 300, 406 );

	/** cURL codes raised before the request is sent (safe to retry). */
	const CONNECT_CURL_CODES = array( 5, 6, 7, 35, 51, 58, 59, 60, 77, 83 );

	/** Explanations for the administrator. */
	const DESCRIPTIONS = array(
		'pm_10'             => 'The Postmark token is invalid or is not a Server API Token.',
		'pm_100'            => 'Postmark is down for maintenance.',
		'pm_101'            => 'Internal Postmark error.',
		'pm_300'            => 'Postmark rejected the message: invalid address or a limit was exceeded.',
		'pm_400'            => 'The sender signature was not found in Postmark.',
		'pm_402'            => 'The request to Postmark is not valid JSON (plugin error).',
		'pm_403'            => 'Invalid request fields; usually the sender address is not verified in Postmark.',
		'pm_406'            => 'The recipient is inactive in Postmark (after a bounce or a spam complaint). Reactivate the address in Postmark before resending.',
		'pm_412'            => 'The Postmark account is still pending approval: you can only send to addresses on the sender\'s domain.',
		'pm_413'            => 'The Postmark account is not approved for sending.',
		'pm_422'            => 'The Postmark server is invalid.',
		'pm_1235'           => 'The configured Message Stream does not exist on this Postmark server.',
		'pm_1236'           => 'The configured Message Stream does not allow sending; use a transactional stream.',
		'pm_1480'           => 'The server\'s IP is not allowed to send through this Postmark account.',
		'http_413'          => 'The message exceeds the Postmark size limit.',
		'http_429'          => 'The Postmark request limit was exceeded.',
		'no_token'          => 'The Postmark token is not configured.',
		'transport'         => 'The server could not reach Postmark.',
		'ambiguous_timeout' => 'Postmark did not respond in time; the message may have been sent.',
		'retries_exhausted' => 'All automatic retries failed.',
		'content_missing'   => 'The message content no longer exists.',
		'search_failed'     => 'The check in Postmark failed.',
	);

	public static function describe( string $code ): string {
		if ( isset( self::DESCRIPTIONS[ $code ] ) ) {
			return self::DESCRIPTIONS[ $code ];
		}
		if ( 0 === strpos( $code, 'http_5' ) ) {
			return 'Postmark returned a server error.';
		}
		return 'Postmark error: ' . $code;
	}

	/**
	 * @param string|array $retry_after Value of the Retry-After header.
	 */
	public static function from_response( int $http, string $body, $retry_after = '' ): SendResult {
		$json    = json_decode( $body, true );
		$json    = is_array( $json ) ? $json : array();
		$code    = isset( $json['ErrorCode'] ) && is_numeric( $json['ErrorCode'] ) ? (int) $json['ErrorCode'] : null;
		$message = isset( $json['Message'] ) ? Util::error_text( (string) $json['Message'] ) : '';

		if ( 200 === $http && 0 === (int) $code && ! empty( $json['MessageID'] ) ) {
			return SendResult::accepted( (string) $json['MessageID'] );
		}
		if ( 200 === $http ) {
			// A 200 response without a MessageID: we do not know for sure what happened.
			return SendResult::failure( SendResult::AMBIGUOUS, 'ambiguous_timeout', 'Postmark response without a MessageID.' );
		}

		$wait = self::retry_after( $retry_after );

		if ( 429 === $http ) {
			return SendResult::failure( SendResult::TEMPORARY, 'http_429', $message ?: self::describe( 'http_429' ), $wait );
		}
		if ( $http >= 500 ) {
			$key = null !== $code ? 'pm_' . $code : 'http_' . $http;
			return SendResult::failure( SendResult::TEMPORARY, $key, $message ?: self::describe( $key ), $wait );
		}
		if ( 401 === $http || ( null !== $code && in_array( $code, self::CONFIG_CODES, true ) ) ) {
			$key = 'pm_' . ( null !== $code ? $code : 10 );
			return SendResult::failure( SendResult::CONFIG, $key, $message ?: self::describe( $key ) );
		}
		if ( 413 === $http ) {
			return SendResult::failure( SendResult::PERMANENT, 'http_413', self::describe( 'http_413' ) );
		}

		$key = null !== $code ? 'pm_' . $code : 'http_' . $http;
		return SendResult::failure(
			SendResult::PERMANENT,
			$key,
			$message ?: sprintf( 'Unexpected response from Postmark (HTTP %d).', $http )
		);
	}

	/**
	 * Transport errors (no HTTP response).
	 * If the error happened before the request was sent, retrying is safe;
	 * otherwise the request may have reached Postmark, so the result is ambiguous.
	 */
	public static function from_transport_error( \WP_Error $error ): SendResult {
		$message = Util::error_text( $error->get_error_message() );
		$curl    = preg_match( '/cURL error (\d+)/i', $message, $m ) ? (int) $m[1] : 0;

		$connect_phase = in_array( $curl, self::CONNECT_CURL_CODES, true )
			|| ( 28 === $curl && preg_match( '/(connection|resolving|connect) time/i', $message ) )
			|| preg_match( '/could not resolve|failed to connect|connection refused/i', $message );

		if ( $connect_phase ) {
			return SendResult::failure( SendResult::TEMPORARY, 'transport', $message );
		}
		return SendResult::failure( SendResult::AMBIGUOUS, 'ambiguous_timeout', $message );
	}

	/**
	 * @param string|array $value The Retry-After header (seconds or an HTTP date).
	 */
	public static function retry_after( $value ): int {
		if ( is_array( $value ) ) {
			$value = (string) reset( $value );
		}
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return 0;
		}
		if ( ctype_digit( $value ) ) {
			return min( (int) $value, DAY_IN_SECONDS );
		}
		$time = strtotime( $value );
		return false === $time ? 0 : max( 0, min( $time - time(), DAY_IN_SECONDS ) );
	}
}
