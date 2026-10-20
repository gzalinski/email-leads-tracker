<?php
/**
 * Administrator alerts (ARCHITECTURE.md §10).
 *
 * Alerts go through wp_mail(), not Postmark, so they still arrive when Postmark itself is the problem.
 * They contain no personal data of the visitors.
 *
 * @package EnspireEmailLeadsTracker
 */

namespace EnspireEmailLeadsTracker\Alerts;

use EnspireEmailLeadsTracker\Postmark\EmailBuilder;
use EnspireEmailLeadsTracker\Postmark\ErrorClassifier;
use EnspireEmailLeadsTracker\Postmark\PostmarkClient;
use EnspireEmailLeadsTracker\Postmark\SendResult;
use EnspireEmailLeadsTracker\Settings\Settings;
use EnspireEmailLeadsTracker\State;
use EnspireEmailLeadsTracker\Util;

defined( 'ABSPATH' ) || exit;

final class AdminNotifier {

	/** @var string|null */
	private static $mail_error = null;

	/**
	 * A lead became `failed`.
	 */
	public static function lead_failed( int $lead_id, SendResult $result ): void {
		$state                         = State::all();
		$state['consecutive_failures'] = (int) ( $state['consecutive_failures'] ?? 0 ) + 1;
		$state['pending_failed_ids']   = array_slice( array_merge( (array) ( $state['pending_failed_ids'] ?? array() ), array( $lead_id ) ), -50 );
		$state['first_failure_at']     = $state['first_failure_at'] ?? time();
		$state['last_failure_at']      = time();
		$state['last_error']           = array(
			'code'     => $result->code,
			'message'  => $result->message,
			'category' => $result->category,
			'at'       => time(),
		);
		State::replace( $state );

		if ( SendResult::CONFIG === $result->category ) {
			self::send_failure_alert( 'config' );
		} elseif ( $state['consecutive_failures'] >= max( 1, (int) Settings::get( 'alert_threshold' ) ) ) {
			self::send_failure_alert( 'threshold' );
		}
	}

	/**
	 * A send was accepted: the counter resets; if an alert was active, the recovery is announced.
	 */
	public static function send_succeeded(): void {
		$state = State::all();
		if ( empty( $state['consecutive_failures'] ) && empty( $state['alert_active'] ) ) {
			return;
		}
		$was_alerting                  = ! empty( $state['alert_active'] );
		$state['consecutive_failures'] = 0;
		$state['first_failure_at']     = null;
		$state['pending_failed_ids']   = array();
		$state['alert_active']         = false;
		State::replace( $state );

		if ( $was_alerting ) {
			self::deliver(
				'Sending works again',
				"Enspire Email Leads Tracker successfully sent a lead after the failures reported earlier.\n\n"
				. "Leads that failed in the meantime are not resent automatically. Review them:\n"
				. self::resolution_hint()
			);
		}
	}

	/**
	 * Alert for leads stuck in `pending` (cron stopped or Postmark unavailable).
	 */
	public static function stuck_leads( int $count ): void {
		self::alert(
			'stuck',
			sprintf( '%d leads were not sent on time', $count ),
			sprintf( "%d leads were due more than an hour ago and are still in the queue.\n\n", $count )
			. "Possible causes: the server cron is not running or Postmark is not responding.\n"
			. "Check the Status tab in Settings → Enspire Email Leads Tracker or run: wp eelt status\n"
		);
	}

	/**
	 * An email was permanently rejected (v2). Bounce alerts are grouped and sent by the hourly check.
	 */
	public static function bounce_recorded( int $lead_id, string $code ): void {
		if ( ! Settings::get( 'alert_on_bounce' ) ) {
			return;
		}
		$pending   = (array) State::get( 'pending_bounces', array() );
		$pending[] = array(
			'id'   => $lead_id,
			'code' => $code,
		);
		State::set( 'pending_bounces', array_slice( $pending, -100 ) );
	}

	/**
	 * Sends the grouped alert for the accumulated bounces (hook `eelt_health_check_run`).
	 */
	public static function flush_bounces(): void {
		$pending = (array) State::get( 'pending_bounces', array() );
		if ( ! $pending || ! Settings::get( 'alert_on_bounce' ) ) {
			return;
		}
		$lines = array();
		foreach ( $pending as $bounce ) {
			$lines[] = sprintf( '#%d — %s', (int) $bounce['id'], str_replace( 'bounce:', '', (string) $bounce['code'] ) );
		}
		$sent = self::alert(
			'bounce',
			sprintf( '%d emails rejected by the recipient server', count( $pending ) ),
			"The following leads did not reach the location, because the recipient server rejected the email:\n\n"
			. implode( "\n", $lines )
			. "\n\nCheck the address in the location's \"email\" field. If Postmark deactivated the address, reactivate it in Postmark → Suppressions.\n"
			. "The visitors' data is kept temporarily; you can view it with: wp eelt leads show <id> --with-content\n"
		);
		if ( $sent ) {
			State::set( 'pending_bounces', array() );
		}
	}

	/**
	 * Reconciliation found deliveries, but the webhook has not sent anything for a day (v2).
	 */
	public static function webhook_silent( int $recovered ): void {
		self::alert(
			'webhook_silent',
			'The Postmark webhook seems to be stopped',
			sprintf( "The hourly reconciliation found %d delivery confirmations that the webhook did not send, and the last webhook arrived more than a day ago.\n\n", $recovered )
			. "Postmark pauses an event type after repeated failures. Check it in Postmark → Server → Message Stream → Webhooks and click Verify.\n"
			. "Until then, reconciliation confirms deliveries with a delay of up to an hour.\n"
		);
	}

	/**
	 * Sends an alert, rate-limited per type.
	 */
	public static function alert( string $type, string $subject, string $body ): bool {
		$interval = max( 1, (int) Settings::get( 'alert_interval_hours' ) ) * HOUR_IN_SECONDS;
		$last     = (array) State::get( 'last_alert_by_type', array() );
		if ( isset( $last[ $type ] ) && time() - (int) $last[ $type ] < $interval ) {
			return false;
		}

		$sent = self::deliver( $subject, $body );
		if ( $sent ) {
			$last[ $type ] = time();
			State::set( 'last_alert_by_type', $last );
		}
		return $sent;
	}

	/**
	 * Test alert, without rate limiting.
	 *
	 * @return array{ok:bool,error:string,to:string}
	 */
	public static function test(): array {
		$ok = self::deliver(
			'Test alert',
			"This is a test email sent from Settings → Enspire Email Leads Tracker → Alerts.\n\n"
			. "If you received it, alerts about failed leads will reach this address.\n"
		);
		return array(
			'ok'    => $ok,
			'error' => (string) self::$mail_error,
			'to'    => self::recipient(),
		);
	}

	/**
	 * Captures the wp_mail() error (hook `wp_mail_failed`).
	 *
	 * @param \WP_Error $error The error.
	 */
	public static function capture_mail_error( $error ): void {
		if ( $error instanceof \WP_Error ) {
			self::$mail_error = Util::error_text( $error->get_error_message() );
		}
	}

	public static function recipient(): string {
		$to = (string) Settings::get( 'alert_email' );
		return is_email( $to ) ? $to : (string) get_option( 'admin_email' );
	}

	private static function send_failure_alert( string $type ): void {
		$state = State::all();
		$ids   = array_map( 'intval', (array) ( $state['pending_failed_ids'] ?? array() ) );
		$error = (array) ( $state['last_error'] ?? array() );
		$code  = (string) ( $error['code'] ?? '' );
		$count = count( $ids );

		$subject = 'config' === $type
			? 'Lead sending is not working'
			: sprintf( '%d leads could not be sent', $count );

		$lines   = array();
		$lines[] = 'Postmark rejected the send: ' . ErrorClassifier::describe( $code ) . ( $code ? " ({$code})" : '' );
		if ( ! empty( $error['message'] ) ) {
			$lines[] = 'Details: ' . $error['message'];
		}
		if ( 'config' === $type ) {
			$lines[] = 'Check the settings in Settings → Enspire Email Leads Tracker → Postmark and send a test email.';
		}
		$lines[] = '';
		if ( $ids ) {
			$lines[] = 'Affected leads: #' . implode( ', #', $ids );
		}
		if ( ! empty( $state['first_failure_at'] ) ) {
			$lines[] = 'First failure: ' . gmdate( 'Y-m-d H:i', (int) $state['first_failure_at'] ) . ' UTC';
		}
		$lines[] = '';
		$lines[] = sprintf( 'The content of these messages is kept for %d days.', (int) Settings::get( 'retention_failed_days' ) );
		$lines[] = 'Once fixed, resend them from:';
		$lines[] = self::resolution_hint();

		if ( self::alert( $type, $subject, implode( "\n", $lines ) . "\n" ) ) {
			$state                       = State::all();
			$state['alert_active']       = true;
			$state['pending_failed_ids'] = array();
			State::replace( $state );
		}
	}

	/**
	 * Where failed leads are handled: the admin list (v3) and/or WP-CLI.
	 */
	public static function resolution_hint(): string {
		$hint = (string) apply_filters( 'eelt_alert_admin_link', '' );
		return ( '' !== $hint ? $hint . "\nor run:  " : '' ) . 'wp eelt retry --failed';
	}

	private static function deliver( string $subject, string $body ): bool {
		$to      = self::recipient();
		$subject = '[' . EmailBuilder::site_name() . '] ' . $subject;
		$body   .= "\n—\nEnspire Email Leads Tracker · " . home_url( '/' ) . "\n";

		self::$mail_error = null;
		$sent             = (bool) wp_mail( $to, $subject, $body );

		if ( ! $sent && Settings::get( 'alert_fallback_postmark' ) ) {
			$client = new PostmarkClient();
			if ( $client->has_token() ) {
				$result = $client->send(
					array(
						'From'          => EmailBuilder::from(),
						'To'            => $to,
						'Subject'       => $subject,
						'TextBody'      => $body,
						'MessageStream' => (string) Settings::get( 'postmark_stream' ),
						'Tag'           => 'eelt-alert',
					)
				);
				$sent = $result->is( SendResult::ACCEPTED );
				if ( ! $sent ) {
					self::$mail_error = trim( self::$mail_error . ' Postmark fallback: ' . ErrorClassifier::describe( $result->code ) );
				}
			}
		}

		if ( $sent ) {
			State::set(
				'last_alert_sent',
				array(
					'at'      => time(),
					'subject' => $subject,
				)
			);
		} else {
			State::set(
				'last_alert_failure',
				array(
					'at'    => time(),
					'error' => self::$mail_error ?: 'wp_mail() failed without an error message.',
				)
			);
		}
		return $sent;
	}
}
