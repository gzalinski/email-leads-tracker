<?php
/**
 * Validarea și sanitizarea câmpurilor formularului.
 *
 * @package LocationLeadTracker
 */

namespace LocationLeadTracker\Form;

use LocationLeadTracker\Util;

defined( 'ABSPATH' ) || exit;

final class Validator {

	const MAX_NAME    = 200;
	const MAX_PHONE   = 50;
	const MAX_MESSAGE = 5000;

	/**
	 * @param array $input Datele brute (deja fără slash-uri).
	 * @return array{data:array,errors:array<string,string>}
	 */
	public static function validate( array $input ): array {
		$errors = array();

		$name    = Util::single_line( sanitize_text_field( (string) ( $input['llt_name'] ?? '' ) ) );
		$email   = sanitize_email( (string) ( $input['llt_email'] ?? '' ) );
		$phone   = Util::single_line( sanitize_text_field( (string) ( $input['llt_phone'] ?? '' ) ) );
		$message = sanitize_textarea_field( (string) ( $input['llt_message'] ?? '' ) );
		$consent = ! empty( $input['llt_consent'] );

		if ( '' === $name ) {
			$errors['llt_name'] = 'Numele este obligatoriu.';
		} elseif ( self::length( $name ) > self::MAX_NAME ) {
			$errors['llt_name'] = 'Numele este prea lung.';
		}

		if ( '' === $email || ! is_email( $email ) ) {
			$errors['llt_email'] = 'Introdu o adresă de email validă.';
		}

		if ( '' !== $phone && ( self::length( $phone ) > self::MAX_PHONE || ! preg_match( '/^[0-9+()\-.\s]{5,}$/', $phone ) ) ) {
			$errors['llt_phone'] = 'Numărul de telefon nu este valid.';
		}

		if ( '' === trim( $message ) ) {
			$errors['llt_message'] = 'Mesajul este obligatoriu.';
		} elseif ( self::length( $message ) > self::MAX_MESSAGE ) {
			$errors['llt_message'] = sprintf( 'Mesajul poate avea cel mult %d de caractere.', self::MAX_MESSAGE );
		}

		if ( ! $consent ) {
			$errors['llt_consent'] = 'Este necesar acordul pentru prelucrarea datelor.';
		}

		return array(
			'data'   => array(
				'name'    => $name,
				'email'   => $email,
				'phone'   => $phone,
				'message' => $message,
			),
			'errors' => $errors,
		);
	}

	private static function length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
	}
}
