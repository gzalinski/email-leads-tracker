<?php
// phpcs:ignoreFile
use EnspireEmailLeadsTracker\Lead\LeadStatus;
use EnspireEmailLeadsTracker\Postmark\EmailBuilder;
use EnspireEmailLeadsTracker\Queue\BackoffPolicy;
use EnspireEmailLeadsTracker\Settings\SecretStore;
use EnspireEmailLeadsTracker\Settings\Settings;
use EnspireEmailLeadsTracker\Settings\SettingsSanitizer;

same( 'default schedule', array( 1, 5, 15, 60, 360 ), Settings::retry_schedule() );
same( 'parsed schedule', array( 2, 10 ), Settings::parse_schedule( '2, 10' ) );
same( 'invalid schedule → default', array( 1, 5, 15, 60, 360 ), Settings::parse_schedule( 'abc' ) );

reset_settings( array( 'max_retries' => 9, 'retry_schedule' => '1, 5' ) );
same( 'max retries limited to the intervals', 2, Settings::max_retries() );
reset_settings();

// Backoff: base interval ±20%, at least 30 s, Retry-After respected.
for ( $i = 0; $i < 50; $i++ ) {
	$delay = BackoffPolicy::delay( 1, 0, array( 1, 5, 15 ) );
	check( 'backoff 5 min ±20%', $delay >= 240 && $delay <= 360, (string) $delay );
}
same( 'backoff index past the list', true, BackoffPolicy::delay( 10, 0, array( 1, 2 ) ) <= 144 );
same( 'backoff Retry-After', 900, BackoffPolicy::delay( 0, 900, array( 1 ) ) );
check( 'backoff minimum', BackoffPolicy::delay( 0, 0, array( 1 ) ) >= 48 );

// Monotonic delivery statuses.
check( 'unknown → delivered', LeadStatus::can_transition( 'unknown', 'delivered' ) );
check( 'deferred → bounced', LeadStatus::can_transition( 'deferred', 'bounced' ) );
check( 'delivered → bounced forbidden', ! LeadStatus::can_transition( 'delivered', 'bounced' ) );
check( 'bounced → deferred forbidden', ! LeadStatus::can_transition( 'bounced', 'deferred' ) );
check( 'deferred → deferred forbidden', ! LeadStatus::can_transition( 'deferred', 'deferred' ) );

// Sanitizer: only the current tab is saved.
$GLOBALS['llt_test_options']['llt_settings'] = array(
	'from_email'     => 'old@example.com',
	'postmark_token' => SecretStore::encrypt( 'old-token' ),
);
$out = SettingsSanitizer::sanitize(
	array(
		'_tab'             => 'postmark',
		'postmark_token'   => '',
		'postmark_stream'  => 'outbound',
		'postmark_timeout' => '500',
	)
);
same( 'empty secret keeps the value', 'old-token', SecretStore::decrypt( $out['postmark_token'] ) );
same( 'number limited to max', 60, $out['postmark_timeout'] );
same( 'other tab untouched', 'old@example.com', $out['from_email'] );
check( '_tab not saved', ! isset( $out['_tab'] ) );

$out = SettingsSanitizer::sanitize( array( '_tab' => 'postmark', 'postmark_token' => 'new-token' ) );
same( 'new secret', 'new-token', SecretStore::decrypt( $out['postmark_token'] ) );

$out = SettingsSanitizer::sanitize( array( '_tab' => 'postmark', 'postmark_token' => '', 'postmark_token_clear' => '1' ) );
same( 'secret deleted', '', $out['postmark_token'] );

$already = array( 'from_email' => 'x@example.com' );
same( 'second call returns the processed value', $already, SettingsSanitizer::sanitize( $already ) );

$GLOBALS['llt_test_errors'] = array();
$out = SettingsSanitizer::sanitize( array( '_tab' => 'retry', 'max_retries' => '5', 'retry_schedule' => '10, 5' ) );
check( 'non-increasing schedule rejected', ! empty( $GLOBALS['llt_test_errors'] ) );

$GLOBALS['llt_test_errors'] = array();
$GLOBALS['llt_test_options']['llt_settings'] = array();
$out = SettingsSanitizer::sanitize( array( '_tab' => 'retry', 'max_retries' => '8', 'retry_schedule' => '1, 5, 15' ) );
same( 'max retries reduced to the number of intervals', 3, $out['max_retries'] );

$out = SettingsSanitizer::sanitize( array( '_tab' => 'general', 'from_email' => 'not-an-email', 'from_name' => 'Company', 'subject' => 'S', 'email_format' => 'pdf' ) );
check( 'invalid email not saved', ! isset( $out['from_email'] ) );
same( 'invalid select → first option', 'text', $out['email_format'] );

$out = SettingsSanitizer::sanitize( array( '_tab' => 'antispam', 'extra_proxies' => "203.0.113.0/24\ngarbage\n2001:db8::/32", 'ip_source' => 'auto', 'rate_limit' => '5', 'min_seconds' => '3', 'turnstile_fail_mode' => 'reject' ) );
same( 'CIDR list filtered', "203.0.113.0/24\n2001:db8::/32", $out['extra_proxies'] );
same( 'unchecked checkbox', false, $out['turnstile_enabled'] );

$out = SettingsSanitizer::sanitize( array( '_tab' => 'form', 'consent_text' => 'I accept the <a href="/privacy" target="_blank">policy</a><script>x</script>', 'success_message' => 'Thank you' ) );
check( 'consent without script', false === strpos( $out['consent_text'], '<script' ) && false !== strpos( $out['consent_text'], '<a href' ) );

// The email.
reset_settings( array( 'from_name' => 'Company "Test"', 'from_email' => 'noreply@example.com' ) );
same( 'From without inner quotes', '"Company Test" <noreply@example.com>', EmailBuilder::from() );
$body = EmailBuilder::text_body( array( 'site_name' => 'Site', 'post_title' => 'Downtown', 'name' => 'John', 'email' => 'john@example.com', 'phone' => '', 'message' => 'Hello', 'date' => 'today', 'lead_id' => 7 ) );
check( 'text body without an empty phone', false === strpos( $body, 'Phone:' ) && false !== strpos( $body, '#7' ) );
