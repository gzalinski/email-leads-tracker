<?php
/**
 * Semnează contextul formularului (object_id + momentul randării) cu HMAC.
 *
 * Vizitatorul nu poate schimba postarea destinatar și nici momentul randării (folosit de time-trap).
 * Contextul nu expiră, ca formularul să funcționeze și pe pagini cache-uite.
 *
 * @package LocationLeadTracker
 */

namespace LocationLeadTracker\Shortcode;

defined( 'ABSPATH' ) || exit;

final class ContextSigner {

	private static function secret(): string {
		return ( defined( 'LLT_CONTEXT_SECRET' ) && '' !== (string) LLT_CONTEXT_SECRET )
			? (string) LLT_CONTEXT_SECRET
			: wp_salt( 'auth' );
	}

	/**
	 * @return array{ctx:string,sig:string}
	 */
	public static function sign( int $object_id, ?int $time = null ): array {
		$payload = self::encode( (string) wp_json_encode( array( 'o' => $object_id, 't' => $time ?? time() ) ) );
		return array(
			'ctx' => $payload,
			'sig' => hash_hmac( 'sha256', $payload, self::secret() ),
		);
	}

	/**
	 * @return array{object_id:int,rendered_at:int}|null
	 */
	public static function verify( string $ctx, string $sig ): ?array {
		if ( '' === $ctx || '' === $sig || strlen( $ctx ) > 256 || strlen( $sig ) !== 64 ) {
			return null;
		}
		if ( ! hash_equals( hash_hmac( 'sha256', $ctx, self::secret() ), $sig ) ) {
			return null;
		}
		$data = json_decode( (string) self::decode( $ctx ), true );
		if ( ! is_array( $data ) || empty( $data['o'] ) || empty( $data['t'] ) ) {
			return null;
		}
		return array(
			'object_id'   => (int) $data['o'],
			'rendered_at' => (int) $data['t'],
		);
	}

	private static function encode( string $value ): string {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	private static function decode( string $value ) {
		$padded = str_pad( strtr( $value, '-_', '+/' ), (int) ( ceil( strlen( $value ) / 4 ) * 4 ), '=' );
		return base64_decode( $padded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	}
}
