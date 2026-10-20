<?php
/**
 * Encryption of secrets (Postmark token, Turnstile keys, webhook password) with libsodium.
 *
 * Key: `EELT_ENCRYPTION_KEY` (or the legacy `LLT_ENCRYPTION_KEY`) from wp-config.php if defined, otherwise derived from the WordPress salts.
 *
 * @package EnspireEmailLeadsTracker
 */

namespace EnspireEmailLeadsTracker\Settings;

use EnspireEmailLeadsTracker\Util;

defined( 'ABSPATH' ) || exit;

final class SecretStore {

	const PREFIX = 'v1:';

	private static function key(): string {
		$material = Util::constant( array( 'EELT_ENCRYPTION_KEY', 'LLT_ENCRYPTION_KEY' ) );
		if ( '' === $material ) {
			$material = wp_salt( 'secure_auth' );
		}
		// The 'llt-secret|' context string predates the rename and must stay unchanged,
		// otherwise secrets saved by earlier versions could no longer be decrypted.
		return hash( 'sha256', 'llt-secret|' . $material, true );
	}

	public static function encrypt( string $plain ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return self::PREFIX . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * @return string|null The decrypted text, or null if the value cannot be decrypted.
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
