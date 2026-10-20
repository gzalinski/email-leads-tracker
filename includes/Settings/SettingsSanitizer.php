<?php
/**
 * Validation of the settings on save. Only the current tab's fields are saved; the rest stay unchanged.
 *
 * @package LocationLeadTracker
 */

namespace LocationLeadTracker\Settings;

use LocationLeadTracker\Http\Cidr;
use LocationLeadTracker\Shortcode\ContactShortcode;

defined( 'ABSPATH' ) || exit;

final class SettingsSanitizer {

	/**
	 * The `sanitize_callback` of register_setting.
	 *
	 * @param mixed $input Submitted values.
	 */
	public static function sanitize( $input ): array {
		$current = get_option( Settings::OPTION, array() );
		$current = is_array( $current ) ? $current : array();

		// WordPress may call the callback a second time with the already processed value.
		if ( ! is_array( $input ) || ! isset( $input['_tab'] ) ) {
			return is_array( $input ) ? $input : $current;
		}

		$tabs = SettingsFields::tabs();
		$tab  = sanitize_key( (string) $input['_tab'] );
		if ( empty( $tabs[ $tab ]['fields'] ) ) {
			return $current;
		}

		foreach ( $tabs[ $tab ]['fields'] as $key => $field ) {
			$raw   = $input[ $key ] ?? null;
			$value = self::field( $key, $field, $raw, $input, $current );
			if ( null !== $value ) {
				$current[ $key ] = $value;
			}
		}

		// The number of retries cannot exceed the number of intervals.
		if ( isset( $current['max_retries'], $current['retry_schedule'] ) ) {
			$intervals = count( Settings::parse_schedule( (string) $current['retry_schedule'] ) );
			if ( (int) $current['max_retries'] > $intervals ) {
				$current['max_retries'] = $intervals;
				self::error( 'max_retries', sprintf( 'The number of retries was reduced to %d, the number of defined intervals.', $intervals ) );
			}
		}

		Settings::flush();
		return $current;
	}

	/**
	 * @param mixed $raw Raw value.
	 * @return mixed The validated value, or null to keep the current value.
	 */
	private static function field( string $key, array $field, $raw, array $input, array $current ) {
		$label = (string) $field['label'];

		switch ( $field['type'] ) {
			case 'checkbox':
				return ! empty( $raw );

			case 'secret':
				if ( ! empty( $input[ $key . '_clear' ] ) ) {
					return '';
				}
				$raw = is_string( $raw ) ? trim( $raw ) : '';
				return '' === $raw ? null : SecretStore::encrypt( $raw );

			case 'email':
				$email = sanitize_email( (string) $raw );
				if ( ! is_email( $email ) ) {
					self::error( $key, sprintf( '"%s": the email address is not valid; the previous value was kept.', $label ) );
					return null;
				}
				return $email;

			case 'number':
				$value = (int) $raw;
				$min   = (int) ( $field['min'] ?? 0 );
				$max   = (int) ( $field['max'] ?? PHP_INT_MAX );
				return max( $min, min( $max, $value ) );

			case 'select':
				$value = (string) $raw;
				return array_key_exists( $value, $field['options'] ) ? $value : (string) array_key_first( $field['options'] );

			case 'html':
				return trim( wp_kses( (string) $raw, ContactShortcode::consent_allowed_tags() ) );

			case 'cidr_list':
				$valid   = array();
				$invalid = array();
				foreach ( preg_split( '/\R/', (string) $raw ) as $line ) {
					$line = trim( $line );
					if ( '' === $line ) {
						continue;
					}
					if ( Cidr::is_valid( $line ) ) {
						$valid[] = $line;
					} else {
						$invalid[] = $line;
					}
				}
				if ( $invalid ) {
					self::error( $key, '"' . $label . '": these invalid values were ignored: ' . implode( ', ', array_map( 'sanitize_text_field', $invalid ) ) );
				}
				return implode( "\n", $valid );

			case 'schedule':
				$parts  = preg_split( '/[\s,;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY );
				$values = array();
				foreach ( $parts as $part ) {
					if ( ! ctype_digit( $part ) || (int) $part <= 0 ) {
						self::error( $key, '"' . $label . '": use positive whole numbers only.' );
						return null;
					}
					$values[] = (int) $part;
				}
				if ( ! $values || count( $values ) > 10 ) {
					self::error( $key, '"' . $label . '": define between 1 and 10 intervals.' );
					return null;
				}
				$sorted = $values;
				sort( $sorted );
				if ( $sorted !== $values ) {
					self::error( $key, '"' . $label . '": the intervals must be in increasing order.' );
					return null;
				}
				return implode( ', ', $values );

			case 'text':
			default:
				return sanitize_text_field( (string) $raw );
		}
	}

	private static function error( string $key, string $message ): void {
		add_settings_error( Settings::OPTION, 'llt_' . $key, $message, 'error' );
	}
}
