<?php
// phpcs:ignoreFile
use LocationLeadTracker\Lead\LeadStatus;
use LocationLeadTracker\Postmark\EmailBuilder;
use LocationLeadTracker\Queue\BackoffPolicy;
use LocationLeadTracker\Settings\SecretStore;
use LocationLeadTracker\Settings\Settings;
use LocationLeadTracker\Settings\SettingsSanitizer;

same( 'program implicit', array( 1, 5, 15, 60, 360 ), Settings::retry_schedule() );
same( 'program parsat', array( 2, 10 ), Settings::parse_schedule( '2, 10' ) );
same( 'program invalid → implicit', array( 1, 5, 15, 60, 360 ), Settings::parse_schedule( 'abc' ) );

reset_settings( array( 'max_retries' => 9, 'retry_schedule' => '1, 5' ) );
same( 'max retries limitat la intervale', 2, Settings::max_retries() );
reset_settings();

// Backoff: intervalul de bază ±20%, minimum 30 s, Retry-After respectat.
for ( $i = 0; $i < 50; $i++ ) {
	$delay = BackoffPolicy::delay( 1, 0, array( 1, 5, 15 ) );
	check( 'backoff 5 min ±20%', $delay >= 240 && $delay <= 360, (string) $delay );
}
same( 'backoff index peste listă', true, BackoffPolicy::delay( 10, 0, array( 1, 2 ) ) <= 144 );
same( 'backoff Retry-After', 900, BackoffPolicy::delay( 0, 900, array( 1 ) ) );
check( 'backoff minim', BackoffPolicy::delay( 0, 0, array( 1 ) ) >= 48 );

// Monotonia stărilor de livrare.
check( 'unknown → delivered', LeadStatus::can_transition( 'unknown', 'delivered' ) );
check( 'deferred → bounced', LeadStatus::can_transition( 'deferred', 'bounced' ) );
check( 'delivered → bounced interzis', ! LeadStatus::can_transition( 'delivered', 'bounced' ) );
check( 'bounced → deferred interzis', ! LeadStatus::can_transition( 'bounced', 'deferred' ) );
check( 'deferred → deferred interzis', ! LeadStatus::can_transition( 'deferred', 'deferred' ) );

// Sanitizer: salvează doar tabul curent.
$GLOBALS['llt_test_options']['llt_settings'] = array(
	'from_email'     => 'vechi@example.md',
	'postmark_token' => SecretStore::encrypt( 'token-vechi' ),
);
$out = SettingsSanitizer::sanitize(
	array(
		'_tab'             => 'postmark',
		'postmark_token'   => '',
		'postmark_stream'  => 'outbound',
		'postmark_timeout' => '500',
	)
);
same( 'secret gol păstrează valoarea', 'token-vechi', SecretStore::decrypt( $out['postmark_token'] ) );
same( 'număr limitat la max', 60, $out['postmark_timeout'] );
same( 'alt tab neatins', 'vechi@example.md', $out['from_email'] );
check( '_tab nesalvat', ! isset( $out['_tab'] ) );

$out = SettingsSanitizer::sanitize( array( '_tab' => 'postmark', 'postmark_token' => 'token-nou' ) );
same( 'secret nou', 'token-nou', SecretStore::decrypt( $out['postmark_token'] ) );

$out = SettingsSanitizer::sanitize( array( '_tab' => 'postmark', 'postmark_token' => '', 'postmark_token_clear' => '1' ) );
same( 'secret șters', '', $out['postmark_token'] );

$already = array( 'from_email' => 'x@example.md' );
same( 'al doilea apel returnează valoarea procesată', $already, SettingsSanitizer::sanitize( $already ) );

$GLOBALS['llt_test_errors'] = array();
$out = SettingsSanitizer::sanitize( array( '_tab' => 'retry', 'max_retries' => '5', 'retry_schedule' => '10, 5' ) );
check( 'program necrescător respins', ! empty( $GLOBALS['llt_test_errors'] ) );

$GLOBALS['llt_test_errors'] = array();
$GLOBALS['llt_test_options']['llt_settings'] = array();
$out = SettingsSanitizer::sanitize( array( '_tab' => 'retry', 'max_retries' => '8', 'retry_schedule' => '1, 5, 15' ) );
same( 'max retries redus la numărul de intervale', 3, $out['max_retries'] );

$out = SettingsSanitizer::sanitize( array( '_tab' => 'general', 'from_email' => 'nu-e-email', 'from_name' => 'Firma', 'subject' => 'S', 'email_format' => 'pdf' ) );
check( 'email invalid nu se salvează', ! isset( $out['from_email'] ) );
same( 'select invalid → prima opțiune', 'text', $out['email_format'] );

$out = SettingsSanitizer::sanitize( array( '_tab' => 'antispam', 'extra_proxies' => "203.0.113.0/24\ngunoi\n2001:db8::/32", 'ip_source' => 'auto', 'rate_limit' => '5', 'min_seconds' => '3', 'turnstile_fail_mode' => 'reject' ) );
same( 'listă CIDR filtrată', "203.0.113.0/24\n2001:db8::/32", $out['extra_proxies'] );
same( 'checkbox nebifat', false, $out['turnstile_enabled'] );

$out = SettingsSanitizer::sanitize( array( '_tab' => 'form', 'consent_text' => 'Accept <a href="/confidentialitate" target="_blank">politica</a><script>x</script>', 'success_message' => 'Mulțumim' ) );
check( 'consimțământ fără script', false === strpos( $out['consent_text'], '<script' ) && false !== strpos( $out['consent_text'], '<a href' ) );

// Emailul.
reset_settings( array( 'from_name' => 'Firma "Test"', 'from_email' => 'noreply@example.md' ) );
same( 'From fără ghilimele interne', '"Firma Test" <noreply@example.md>', EmailBuilder::from() );
$body = EmailBuilder::text_body( array( 'site_name' => 'Site', 'post_title' => 'Centru', 'name' => 'Ion', 'email' => 'ion@example.md', 'phone' => '', 'message' => 'Salut', 'date' => 'azi', 'lead_id' => 7 ) );
check( 'corp text fără telefon gol', false === strpos( $body, 'Telefon:' ) && false !== strpos( $body, '#7' ) );
