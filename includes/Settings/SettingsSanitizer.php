<?php
/**
 * Validarea setărilor la salvare. Se salvează doar câmpurile tabului curent; restul rămân neschimbate.
 *
 * @package LocationLeadTracker
 */

namespace LocationLeadTracker\Settings;

use LocationLeadTracker\Http\Cidr;
use LocationLeadTracker\Shortcode\ContactShortcode;

defined( 'ABSPATH' ) || exit;

final class SettingsSanitizer {

	/**
	 * Callback-ul `sanitize_callback` din register_setting.
	 *
	 * @param mixed $input Valorile trimise.
	 */
	public static function sanitize( $input ): array {
		$current = get_option( Settings::OPTION, array() );
		$current = is_array( $current ) ? $current : array();

		// WordPress poate apela callback-ul a doua oară cu valoarea deja procesată.
		if ( ! is_array( $input ) || ! isset( $input['_tab'] ) ) {
			return is_array( $input ) ? $input : $current;
		}

		$tabs = SettingsFields::tabs();
		$tab  = sanitize_key( (string) $input['_tab'] );
		if ( empty( $tabs[ $tab ]['fields'] ) ) {
			return $current;
		}

		foreach ( $tabs[ $tab ]['fields'] as $key => $field ) {
			$raw = $input[ $key ] ?? null;
			$value = self::field( $key, $field, $raw, $input, $current );
			if ( null !== $value ) {
				$current[ $key ] = $value;
			}
		}

		// Numărul de retry-uri nu poate depăși numărul de intervale.
		if ( isset( $current['max_retries'], $current['retry_schedule'] ) ) {
			$intervals = count( Settings::parse_schedule( (string) $current['retry_schedule'] ) );
			if ( (int) $current['max_retries'] > $intervals ) {
				$current['max_retries'] = $intervals;
				self::error( 'max_retries', sprintf( 'Numărul de retry-uri a fost redus la %d, câte intervale sunt definite.', $intervals ) );
			}
		}

		Settings::flush();
		return $current;
	}

	/**
	 * @param mixed $raw Valoarea brută.
	 * @return mixed Valoarea validată sau null pentru a păstra valoarea curentă.
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
					self::error( $key, sprintf( '„%s”: adresa de email nu este validă; valoarea anterioară a fost păstrată.', $label ) );
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
					self::error( $key, '„' . $label . '”: au fost ignorate valorile invalide: ' . implode( ', ', array_map( 'sanitize_text_field', $invalid ) ) );
				}
				return implode( "\n", $valid );

			case 'schedule':
				$parts = preg_split( '/[\s,;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY );
				$values = array();
				foreach ( $parts as $part ) {
					if ( ! ctype_digit( $part ) || (int) $part <= 0 ) {
						self::error( $key, '„' . $label . '”: folosește doar numere întregi pozitive.' );
						return null;
					}
					$values[] = (int) $part;
				}
				if ( ! $values || count( $values ) > 10 ) {
					self::error( $key, '„' . $label . '”: definește între 1 și 10 intervale.' );
					return null;
				}
				$sorted = $values;
				sort( $sorted );
				if ( $sorted !== $values ) {
					self::error( $key, '„' . $label . '”: intervalele trebuie să fie crescătoare.' );
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
