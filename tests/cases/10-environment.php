<?php
/**
 * Comportament dependent de mediu: instalare în subdirector, Application Passwords.
 */
// phpcs:ignoreFile
use LocationLeadTracker\Shortcode\ContactShortcode;
use LocationLeadTracker\Webhook\WebhookController;

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return (string) $url;
	}
}

$_SERVER['REQUEST_URI'] = '/blog/locatii/centru/?utm=x';
same( 'URL curent în subdirector (fără dublare)', 'https://example.test/blog/locatii/centru/?utm=x', ContactShortcode::current_url() );
$_SERVER['REQUEST_URI'] = 'http://evil.test/';
same( 'REQUEST_URI invalid → rădăcina', 'https://example.test/', ContactShortcode::current_url() );

$_SERVER['REQUEST_URI'] = '/wp-json/llt/v1/postmark';
same( 'Application Passwords ignorat pe webhook', false, WebhookController::skip_application_passwords( true ) );
$_SERVER['REQUEST_URI'] = '/?rest_route=%2Fllt%2Fv1%2Fpostmark';
same( 'Application Passwords ignorat (rest_route)', false, WebhookController::skip_application_passwords( true ) );
$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts';
same( 'Application Passwords păstrat pe alte rute', true, WebhookController::skip_application_passwords( true ) );
