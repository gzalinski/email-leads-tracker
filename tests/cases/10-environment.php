<?php
/**
 * Environment-dependent behavior: subdirectory installs, Application Passwords.
 */
// phpcs:ignoreFile
use LocationLeadTracker\Shortcode\ContactShortcode;
use LocationLeadTracker\Webhook\WebhookController;

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return (string) $url;
	}
}

$_SERVER['REQUEST_URI'] = '/blog/locations/downtown/?utm=x';
same( 'current URL in a subdirectory (no duplication)', 'https://example.test/blog/locations/downtown/?utm=x', ContactShortcode::current_url() );
$_SERVER['REQUEST_URI'] = 'http://evil.test/';
same( 'invalid REQUEST_URI → root', 'https://example.test/', ContactShortcode::current_url() );

$_SERVER['REQUEST_URI'] = '/wp-json/llt/v1/postmark';
same( 'Application Passwords skipped on the webhook', false, WebhookController::skip_application_passwords( true ) );
$_SERVER['REQUEST_URI'] = '/?rest_route=%2Fllt%2Fv1%2Fpostmark';
same( 'Application Passwords skipped (rest_route)', false, WebhookController::skip_application_passwords( true ) );
$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts';
same( 'Application Passwords kept on other routes', true, WebhookController::skip_application_passwords( true ) );
