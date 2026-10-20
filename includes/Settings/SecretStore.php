<?php
/**
 * Criptarea secretelor (token Postmark, chei Turnstile, parolă webhook) cu libsodium.
 *
 * Cheia: `LLT_ENCRYPTION_KEY` din wp-config.php dacă există, altfel derivată din salturile WordPress.
 *
 * @package LocationLeadTracker
 */

namespace LocationLeadTracker\Settings;

defined( 'ABSPATH' ) || exit;

final class SecretStore {

	const PREFIX = 'v1:';

	private static function key(): string {
		$material = ( defined( 'LLT_ENCRYPTION_KEY' ) && '' !== (string) LLT_ENCRYPTION_KEY )
			? (string) LLT_ENCRYPTION_KEY
			: wp_salt( 'secure_auth' );
		return hash( 'sha256', 'llt-secret|' . $material, true );
	}

	public static function encrypt( string $plain ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return self::PREFIX . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * @return string|null Textul decriptat sau null dacă valoarea nu poate fi decriptată.
	 */
	public static function decrypt( string $stored ): ?string {
		if ( 0 !== strpos( $stored, self::PREFIX ) ) {
			return null;
		}
		$binary = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $binary || strlen( $binary ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES - 1 ) {
			return null;
		}
		$nonce  = substr( $binary, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $binary, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		try {
			$plain = sodium_crypto_secretbox_open( $cipher, $nonce, self::key() );
		} catch ( \SodiumException $e ) {
			return null;
		}
		return false === $plain ? null : $plain;
	}
}
