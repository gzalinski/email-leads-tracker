<?php
/**
 * Reading settings. All settings live in the `llt_settings` option; secrets are encrypted.
 *
 * @package LocationLeadTracker
 */

namespace LocationLeadTracker\Settings;

defined( 'ABSPATH' ) || exit;

final class Settings {

	const OPTION  = 'llt_settings';
	const SECRETS = array( 'postmark_token', 'turnstile_secret', 'webhook_pass' );

	/** @var array|null */
	private static $cache = null;

	public static function defaults(): array {
		$admin_email = (string) get_option( 'admin_email' );
		return array(
			// General.
			'from_email'                 => $admin_email,
			'from_name'                  => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'subject'                    => 'New message for {post_title}',
			'email_format'               => 'text',
			// Postmark.
			'postmark_token'             => '',
			'postmark_stream'            => 'outbound',
			'postmark_timeout'           => 15,
			// Form.
			'consent_text'               => 'I agree to the processing of my personal data in order to receive a reply to this request.',
			'success_message'            => 'Thank you! Your request has been received.',
			// Anti-spam.
			'turnstile_enabled'          => false,
			'turnstile_site_key'         => '',
			'turnstile_secret'           => '',
			'turnstile_fail_mode'        => 'reject',
			'rate_limit'                 => 5,
			'min_seconds'                => 3,
			'ip_source'                  => 'auto',
			'extra_proxies'              => '',
			// Retries.
			'max_retries'                => 5,
			'retry_schedule'             => '1, 5, 15, 60, 360',
			// Alerts.
			'alert_email'                => $admin_email,
			'alert_threshold'            => 3,
			'alert_interval_hours'       => 6,
			'alert_fallback_postmark'    => false,
			'alert_on_bounce'            => false,
			// Retention.
			'retention_accepted_hours'   => 24,
			'retention_failed_days'      => 14,
			'retention_unconfirmed_days' => 7,
			'delete_on_uninstall'        => false,
			// Webhook (v2).
			'webhook_enabled'            => false,
			'webhook_user'               => '',
			'webhook_pass'               => '',
			'webhook_ip_filter'          => false,
		);
	}

	public static function all(): array {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		}
		return self::$cache;
	}

	/**
	 * @return mixed
	 */
	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/**
	 * The decrypted value of a secret, or an empty string if it is missing or cannot be decrypted.
	 */
	public static function secret( string $key ): string {
		$stored = (string) ( self::all()[ $key ] ?? '' );
		if ( '' === $stored ) {
			return '';
		}
		$plain = SecretStore::decrypt( $stored );
		return null === $plain ? '' : $plain;
	}

	public static function has_secret( string $key ): bool {
		return '' !== (string) ( self::all()[ $key ] ?? '' );
	}

	/**
	 * Saved secrets that can no longer be decrypted (e.g. after the WordPress salts changed).
	 *
	 * @return string[]
	 */
	public static function unreadable_secrets(): array {
		$unreadable = array();
		foreach ( self::SECRETS as $key ) {
			$stored = (string) ( self::all()[ $key ] ?? '' );
			if ( '' !== $stored && null === SecretStore::decrypt( $stored ) ) {
				$unreadable[] = $key;
			}
		}
		return $unreadable;
	}

	/**
	 * Retry intervals, in minutes.
	 *
	 * @return int[]
	 */
	public static function retry_schedule(): array {
		return self::parse_schedule( (string) self::get( 'retry_schedule' ) );
	}

	/**
	 * @return int[]
	 */
	public static function parse_schedule( string $raw ): array {
		$values = array();
		foreach ( preg_split( '/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY ) as $part ) {
			if ( ctype_digit( $part ) && (int) $part > 0 ) {
				$values[] = (int) $part;
			}
		}
		return $values ? $values : array( 1, 5, 15, 60, 360 );
	}

	/**
	 * The effective maximum number of retries: it cannot exceed the number of intervals.
	 */
	public static function max_retries(): int {
		return max( 0, min( (int) self::get( 'max_retries' ), count( self::retry_schedule() ) ) );
	}

	/**
	 * Delivery confirmation (v2) is enabled.
	 */
	public static function tracking_enabled(): bool {
		return (bool) self::get( 'webhook_enabled' );
	}

	public static function flush(): void {
		self::$cache = null;
	}
}
