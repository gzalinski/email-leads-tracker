<?php
/**
 * Handles form submissions (admin-post.php?action=llt_submit).
 *
 * The lead is saved, the visitor gets an immediate response, and the email is sent after the response.
 *
 * @package EmailLeadsTracker
 */

namespace EmailLeadsTracker\Form;

use EmailLeadsTracker\Form\Captcha\TurnstileVerifier;
use EmailLeadsTracker\Http\ClientIp;
use EmailLeadsTracker\Lead\LeadService;
use EmailLeadsTracker\Queue\SendWorker;
use EmailLeadsTracker\Settings\Settings;
use EmailLeadsTracker\Shortcode\ContactShortcode;
use EmailLeadsTracker\Shortcode\ContextSigner;
use EmailLeadsTracker\Shortcode\RecipientResolver;
use EmailLeadsTracker\Util;

defined( 'ABSPATH' ) || exit;

final class SubmissionHandler {

	/** @var bool */
	private static $ajax = false;
	/** @var string */
	private static $form_id = '';
	/** @var string */
	private static $return_url = '';

	/**
	 * No nonce, on purpose: the form must work on cached pages.
	 * Protection comes from the signed context, honeypot, time trap, rate limiting and Turnstile.
	 */
	public static function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$post = wp_unslash( $_POST );
		// phpcs:enable
		$post = is_array( $post ) ? $post : array();

		self::$ajax       = ! empty( $_SERVER['HTTP_X_LLT_AJAX'] );
		self::$form_id    = sanitize_key( (string) ( $post['llt_form'] ?? '' ) );
		self::$return_url = wp_validate_redirect( esc_url_raw( (string) ( $post['llt_return'] ?? '' ) ), home_url( '/' ) );

		// 1. Signed context.
		$context = ContextSigner::verify( (string) ( $post['llt_ctx'] ?? '' ), (string) ( $post['llt_sig'] ?? '' ) );
		if ( null === $context ) {
			self::finish( 'invalid' );
		}

		// 2. Honeypot: the bot gets "success", but nothing is created.
		if ( '' !== trim( (string) ( $post['llt_hp'] ?? '' ) ) ) {
			self::finish( 'success' );
		}

		// 3. Time trap, using the render time from the signed context.
		if ( time() - $context['rendered_at'] < (int) Settings::get( 'min_seconds' ) ) {
			self::finish( 'too_fast' );
		}

		// 4. Rate limiting on the real IP.
		$ip = ClientIp::get();
		if ( ! RateLimiter::allow( $ip ) ) {
			self::finish( 'rate_limited' );
		}

		// 5. Turnstile.
		if ( ContactShortcode::turnstile_enabled() ) {
			$verified = TurnstileVerifier::verify( (string) ( $post['cf-turnstile-response'] ?? '' ), $ip );
			if ( false === $verified || ( null === $verified && 'accept' !== Settings::get( 'turnstile_fail_mode' ) ) ) {
				self::finish( 'captcha' );
			}
		}

		// 6. Fields.
		$validated = Validator::validate( $post );
		if ( $validated['errors'] ) {
			self::finish( 'validation', $validated['errors'] );
		}

		// 7. Recipient, read again from post meta.
		$recipient = RecipientResolver::resolve( $context['object_id'] );
		if ( is_wp_error( $recipient ) ) {
			Util::log( 'Submission rejected: ' . $recipient->get_error_message() );
			self::finish( 'unavailable' );
		}

		// 8. Lead.
		$lead_id = LeadService::create( $validated['data'], $context['object_id'], $recipient['email'] );
		if ( is_wp_error( $lead_id ) ) {
			Util::log( 'The lead could not be saved: ' . $lead_id->get_error_message() );
			self::finish( 'error' );
		}

		RateLimiter::hit( $ip );
		self::finish( 'success', array(), (int) $lead_id );
	}

	/**
	 * Sends the response and stops execution. If a new lead exists, the email is sent after the response.
	 *
	 * @param array<string,string> $errors Field errors.
	 * @return never
	 */
	private static function finish( string $status, array $errors = array(), int $lead_id = 0 ): void {
		$ok   = 'success' === $status;
		$body = '';

		if ( self::$ajax ) {
			$code = $ok ? 200 : ( 'rate_limited' === $status ? 429 : 422 );
			status_header( $code );
			nocache_headers();
			header( 'Content-Type: application/json; charset=utf-8' );
			$body = (string) wp_json_encode(
				array(
					'ok'      => $ok,
					'status'  => $status,
					'message' => Messages::get( $status ),
					'errors'  => $errors,
				)
			);
		} else {
			$url = add_query_arg(
				array(
					'llt_status' => $status,
					'llt_form'   => self::$form_id,
				),
				self::$return_url
			);
			if ( '' !== self::$form_id ) {
				$url .= '#' . self::$form_id;
			}
			wp_safe_redirect( $url, 303 );
		}

		if ( $lead_id > 0 ) {
			self::respond_then_send( $body, $lead_id );
		}

		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON.
		exit;
	}

	/**
	 * @return never
	 */
	private static function respond_then_send( string $body, int $lead_id ): void {
		ignore_user_abort( true );

		$can_finish = function_exists( 'fastcgi_finish_request' ) || function_exists( 'litespeed_finish_request' );
		if ( ! $can_finish ) {
			// Without PHP-FPM / LiteSpeed: cron picks up the send within a minute.
			wp_schedule_single_event( time(), 'llt_send_lead', array( $lead_id ) );
			echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			if ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) {
				spawn_cron();
			}
			exit;
		}

		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}
		flush();
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} else {
			litespeed_finish_request();
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		SendWorker::process_lead( $lead_id );
		exit;
	}
}
