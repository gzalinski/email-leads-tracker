<?php
/**
 * Suggested privacy policy text (Settings → Privacy).
 *
 * @package EnspireEmailLeadsTracker
 */

namespace EnspireEmailLeadsTracker\Privacy;

use EnspireEmailLeadsTracker\Settings\Settings;

defined( 'ABSPATH' ) || exit;

final class PolicyContent {

	public static function register(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$accepted_hours = (int) Settings::get( 'retention_accepted_hours' );
		$failed_days    = (int) Settings::get( 'retention_failed_days' );

		$html  = '<h2>Location contact form</h2>';
		$html .= '<p>When you send a message through a location\'s contact form, we collect your name, email address, phone number (optional) and your message, in order to forward it to that location and to be able to reply to you.</p>';
		$html .= '<p>The message is sent through the Postmark email service (ActiveCampaign, LLC), which keeps a copy of the email for the retention period configured in our Postmark account.</p>';
		if ( Settings::get( 'turnstile_enabled' ) ) {
			$html .= '<p>To protect against spam, the form uses Cloudflare Turnstile, which loads resources from Cloudflare and may process technical data about your browser.</p>';
		}
		if ( Settings::tracking_enabled() ) {
			$html .= '<p>Your personal data is deleted from our database as soon as the recipient\'s server confirms receipt of the email, or after at most '
				. (int) Settings::get( 'retention_unconfirmed_days' ) . ' days if delivery is not confirmed.</p>';
		} else {
			$html .= '<p>Your personal data is deleted from our database ' . $accepted_hours . ' hours after the email is sent.</p>';
		}
		$html .= '<p>If sending fails, the data is kept for at most ' . $failed_days . ' days so that it can be resent. We only keep a minimal record of the request (the location, the date and the sending status), without your personal data.</p>';
		$html .= '<p>Your IP address is not stored; we only use a cryptographic value derived from it, for one hour, to limit abuse.</p>';

		wp_add_privacy_policy_content( 'Enspire Email Leads Tracker', wp_kses_post( $html ) );
	}
}
