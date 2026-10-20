<?php
/**
 * Clasificarea răspunsurilor Postmark (verificat în documentația Postmark, octombrie 2026).
 *
 * @package LocationLeadTracker
 */

namespace LocationLeadTracker\Postmark;

use LocationLeadTracker\Util;

defined( 'ABSPATH' ) || exit;

final class ErrorClassifier {

	/** ErrorCode-uri care afectează toate leadurile. */
	const CONFIG_CODES = array( 10, 400, 402, 403, 412, 413, 422, 1235, 1236, 1480 );

	/** ErrorCode-uri care țin doar de leadul curent. */
	const PERMANENT_CODES = array( 300, 406 );

	/** Coduri cURL care apar înainte ca cererea să fie trimisă (sigur de reîncercat). */
	const CONNECT_CURL_CODES = array( 5, 6, 7, 35, 51, 58, 59, 60, 77, 83 );

	/** Explicații în română pentru administrator. */
	const DESCRIPTIONS = array(
		'pm_10'             => 'Tokenul Postmark este invalid sau nu este un Server API Token.',
		'pm_100'            => 'Postmark este în mentenanță.',
		'pm_101'            => 'Eroare internă Postmark.',
		'pm_300'            => 'Postmark a respins mesajul: adresă invalidă sau limită depășită.',
		'pm_400'            => 'Semnătura expeditorului nu a fost găsită în Postmark.',
		'pm_402'            => 'Cererea către Postmark nu este un JSON valid (eroare în plugin).',
		'pm_403'            => 'Câmpuri invalide în cerere; de obicei adresa expeditorului nu este verificată în Postmark.',
		'pm_406'            => 'Destinatarul este inactiv în Postmark (după un bounce sau o plângere de spam). Reactivează adresa din Postmark înainte de retrimitere.',
		'pm_412'            => 'Contul Postmark este încă în aprobare: poți trimite doar către adrese de pe domeniul expeditorului.',
		'pm_413'            => 'Contul Postmark nu este aprobat pentru trimitere.',
		'pm_422'            => 'Serverul Postmark este invalid.',
		'pm_1235'           => 'Message Stream-ul configurat nu există pe acest server Postmark.',
		'pm_1236'           => 'Message Stream-ul configurat nu permite trimiterea; folosește un stream tranzacțional.',
		'pm_1480'           => 'IP-ul serverului nu are voie să trimită prin acest cont Postmark.',
		'http_413'          => 'Mesajul depășește limita de mărime a Postmark.',
		'http_429'          => 'Limita de cereri Postmark a fost depășită.',
		'no_token'          => 'Tokenul Postmark nu este configurat.',
		'transport'         => 'Serverul nu a putut contacta Postmark.',
		'ambiguous_timeout' => 'Postmark nu a răspuns la timp; mesajul poate să fi fost trimis.',
		'retries_exhausted' => 'Toate reîncercările automate au eșuat.',
		'content_missing'   => 'Conținutul mesajului nu mai există.',
		'search_failed'     => 'Verificarea în Postmark a eșuat.',
	);

	public static function describe( string $code ): string {
		if ( isset( self::DESCRIPTIONS[ $code ] ) ) {
			return self::DESCRIPTIONS[ $code ];
		}
		if ( 0 === strpos( $code, 'http_5' ) ) {
			return 'Postmark a întors o eroare de server.';
		}
		return 'Eroare Postmark: ' . $code;
	}

	/**
	 * @param string|array $retry_after Valoarea headerului Retry-After.
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
			// Răspuns 200 fără MessageID: nu știm sigur ce s-a întâmplat.
			return SendResult::failure( SendResult::AMBIGUOUS, 'ambiguous_timeout', 'Răspuns Postmark fără MessageID.' );
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
			$message ?: sprintf( 'Răspuns neașteptat de la Postmark (HTTP %d).', $http )
		);
	}

	/**
	 * Erorile de transport (fără răspuns HTTP).
	 * Dacă eroarea a apărut înainte de trimiterea cererii, reîncercarea este sigură;
	 * altfel cererea poate să fi ajuns la Postmark, deci rezultatul este ambiguu.
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
	 * @param string|array $value Headerul Retry-After (secunde sau dată HTTP).
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
