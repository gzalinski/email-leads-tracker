<?php
/**
 * Validation and sanitization of the form fields.
 *
 * @package EnspireEmailLeadsTracker
 */

namespace EnspireEmailLeadsTracker\Form;

use EnspireEmailLeadsTracker\Util;

defined( 'ABSPATH' ) || exit;

final class Validator {

	const MAX_NAME    = 200;
	const MAX_PHONE   = 50;
	const MAX_MESSAGE = 5000;

	/**
	 * @param array $input Raw data (already unslashed).
	 * @return array{data:array,errors:array<string,string>}
	 */
	public static function validate( array $input ): array {
		$errors = array();

		$name    = Util::single_line( sanitize_text_field( (string) ( $input['eelt_name'] ?? '' ) ) );
		$email   = sanitize_email( (string) ( $input['eelt_email'] ?? '' ) );
		$phone   = Util::single_line( sanitize_text_field( (string) ( $input['eelt_phone'] ?? '' ) ) );
		$message = sanitize_textarea_field( (string) ( $input['eelt_message'] ?? '' ) );
		$consent = ! empty( $input['eelt_consent'] );

		if ( '' === $name ) {
			$errors['eelt_name'] = 'Your name is required.';
		} elseif ( self::length( $name ) > self::MAX_NAME ) {
			$errors['eelt_name'] = 'The name is too long.';
		}

		if ( '' === $email || ! is_email( $email ) ) {
			$errors['eelt_email'] = 'Please enter a valid email address.';
		}

		if ( '' !== $phone && ( self::length( $phone ) > self::MAX_PHONE || ! preg_match( '/^[0-9+()\-.\s]{5,}$/', $phone ) ) ) {
			$errors['eelt_phone'] = 'The phone number is not valid.';
		}

		if ( '' === trim( $message ) ) {
			$errors['eelt_message'] = 'A message is required.';
		} elseif ( self::length( $message ) > self::MAX_MESSAGE ) {
			$errors['eelt_message'] = sprintf( 'The message can have at most %d characters.', self::MAX_MESSAGE );
		}

		if ( ! $consent ) {
			$errors['eelt_consent'] = 'Your consent to the processing of your data is required.';
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
