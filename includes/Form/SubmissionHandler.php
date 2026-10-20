<?php
/**
 * Procesează trimiterea formularului (admin-post.php?action=llt_submit).
 *
 * Leadul se salvează, vizitatorul primește răspunsul imediat, iar emailul se trimite după răspuns.
 *
 * @package LocationLeadTracker
 */

namespace LocationLeadTracker\Form;

use LocationLeadTracker\Form\Captcha\TurnstileVerifier;
use LocationLeadTracker\Http\ClientIp;
use LocationLeadTracker\Lead\LeadService;
use LocationLeadTracker\Queue\SendWorker;
use LocationLeadTracker\Settings\Settings;
use LocationLeadTracker\Shortcode\ContactShortcode;
use LocationLeadTracker\Shortcode\ContextSigner;
use LocationLeadTracker\Shortcode\RecipientResolver;
use LocationLeadTracker\Util;

defined( 'ABSPATH' ) || exit;

final class SubmissionHandler {

	/** @var bool */
	private static $ajax = false;
	/** @var string */
	private static $form_id = '';
	/** @var string */
	private static $return_url = '';

	/**
	 * Fără nonce, intenționat: formularul trebuie să funcționeze pe pagini cache-uite.
	 * Protecția vine din contextul semnat, honeypot, time-trap, rate limiting și Turnstile.
	 */
	public static function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$post = wp_unslash( $_POST );
		// phpcs:enable
		$post = is_array( $post ) ? $post : array();

		self::$ajax       = ! empty( $_SERVER['HTTP_X_LLT_AJAX'] );
		self::$form_id    = sanitize_key( (string) ( $post['llt_form'] ?? '' ) );
		self::$return_url = wp_validate_redirect( esc_url_raw( (string) ( $post['llt_return'] ?? '' ) ), home_url( '/' ) );

		// 1. Contextul semnat.
		$context = ContextSigner::verify( (string) ( $post['llt_ctx'] ?? '' ), (string) ( $post['llt_sig'] ?? '' ) );
		if ( null === $context ) {
			self::finish( 'invalid' );
		}

		// 2. Honeypot: botul primește „succes”, dar nu se creează nimic.
		if ( '' !== trim( (string) ( $post['llt_hp'] ?? '' ) ) ) {
			self::finish( 'success' );
		}

		// 3. Time-trap, cu momentul randării din contextul semnat.
		if ( time() - $context['rendered_at'] < (int) Settings::get( 'min_seconds' ) ) {
			self::finish( 'too_fast' );
		}

		// 4. Rate limiting pe IP-ul real.
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

		// 6. Câmpurile.
		$validated = Validator::validate( $post );
		if ( $validated['errors'] ) {
			self::finish( 'validation', $validated['errors'] );
		}

		// 7. Destinatarul, recitit din post meta.
		$recipient = RecipientResolver::resolve( $context['object_id'] );
		if ( is_wp_error( $recipient ) ) {
			Util::log( 'Submit respins: ' . $recipient->get_error_message() );
			self::finish( 'unavailable' );
		}

		// 8. Leadul.
		$lead_id = LeadService::create( $validated['data'], $context['object_id'], $recipient['email'] );
		if ( is_wp_error( $lead_id ) ) {
			Util::log( 'Leadul nu a putut fi salvat: ' . $lead_id->get_error_message() );
			self::finish( 'error' );
		}

		RateLimiter::hit( $ip );
		self::finish( 'success', array(), (int) $lead_id );
	}

	/**
	 * Trimite răspunsul și oprește execuția. Dacă există un lead nou, emailul se trimite după răspuns.
	 *
	 * @param array<string,string> $errors Erori pe câmpuri.
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
			// Fără PHP-FPM / LiteSpeed: trimiterea o preia cronul în cel mult un minut.
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
