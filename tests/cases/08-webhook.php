<?php
/**
 * v2: Postmark webhook, authentication, monotonic rule, reconciliation, retention with confirmation.
 */
// phpcs:ignoreFile
require_once dirname( __DIR__ ) . '/wpdb-sqlite.php';

use LocationLeadTracker\Alerts\AdminNotifier;
use LocationLeadTracker\Database\LeadRepository;
use LocationLeadTracker\Database\MessageRepository;
use LocationLeadTracker\Http\ClientIp;
use LocationLeadTracker\Lead\LeadService;
use LocationLeadTracker\Retention\RetentionService;
use LocationLeadTracker\Settings\SecretStore;
use LocationLeadTracker\State;
use LocationLeadTracker\Util;
use LocationLeadTracker\Webhook\EventProcessor;
use LocationLeadTracker\Webhook\Reconciler;
use LocationLeadTracker\Webhook\WebhookAuth;
use LocationLeadTracker\Webhook\WebhookController;

global $wpdb;
$wpdb = new LLT_Test_WPDB();
$GLOBALS['llt_test_options']['llt_state'] = array();
$GLOBALS['llt_mail_log'] = array();

$v2 = array(
	'postmark_token'  => SecretStore::encrypt( 'tok' ),
	'webhook_enabled' => true,
	'webhook_user'    => 'lltuser',
	'webhook_pass'    => SecretStore::encrypt( 's3cret' ),
	'alert_on_bounce' => true,
);
reset_settings( $v2 );

$data = array( 'name' => 'Anna', 'email' => 'anna@example.com', 'phone' => '', 'message' => 'Hello' );

function llt_accepted_lead( array $data, string $message_id, string $sent_at = '' ): int {
	global $wpdb;
	$id      = LeadService::create( $data, 245, 'downtown@example.com' );
	$sent_at = $sent_at ?: Util::now();
	$wpdb->query( "UPDATE wp_location_leads SET send_status = 'accepted', postmark_message_id = '{$message_id}', sent_at = '{$sent_at}', next_retry_at = NULL WHERE id = {$id}" );
	return $id;
}

// Bounce classification.
same( 'HardBounce → bounced', 'bounced', EventProcessor::classify_bounce( 1, false ) );
same( 'BadEmailAddress → bounced', 'bounced', EventProcessor::classify_bounce( 100000, false ) );
same( 'DMARC → bounced', 'bounced', EventProcessor::classify_bounce( 100009, false ) );
same( 'Transient → deferred', 'deferred', EventProcessor::classify_bounce( 2, false ) );
same( 'SoftBounce → deferred', 'deferred', EventProcessor::classify_bounce( 4096, false ) );
same( 'inactive SoftBounce → bounced', 'bounced', EventProcessor::classify_bounce( 4096, true ) );
same( 'AutoResponder ignored', null, EventProcessor::classify_bounce( 64, false ) );
same( 'SpamNotification ignored', null, EventProcessor::classify_bounce( 512, false ) );

// Authentication.
$basic = 'Basic ' . base64_encode( 'lltuser:s3cret' );
same( 'correct auth', WebhookAuth::OK, WebhookAuth::check( $basic, array() ) );
same( 'wrong password', WebhookAuth::BAD_CREDENTIAL, WebhookAuth::check( 'Basic ' . base64_encode( 'lltuser:wrong' ), array() ) );
same( 'missing header', WebhookAuth::MISSING_HEADER, WebhookAuth::check( null, array() ) );
same( 'auth from PHP_AUTH_USER', WebhookAuth::OK, WebhookAuth::check( null, array( 'PHP_AUTH_USER' => 'lltuser', 'PHP_AUTH_PW' => 's3cret' ) ) );
same( 'auth from REDIRECT_HTTP_AUTHORIZATION', WebhookAuth::OK, WebhookAuth::check( '', array( 'REDIRECT_HTTP_AUTHORIZATION' => $basic ) ) );
same( 'password containing ":"', array( 'u', 'a:b' ), WebhookAuth::credentials( 'Basic ' . base64_encode( 'u:a:b' ), array() ) );

reset_settings( array_merge( $v2, array( 'webhook_ip_filter' => true ) ) );
$_SERVER['REMOTE_ADDR'] = '8.8.8.8';
ClientIp::reset();
same( 'IP filter rejects', WebhookAuth::IP_NOT_ALLOWED, WebhookAuth::check( $basic, array() ) );
$_SERVER['REMOTE_ADDR'] = '50.31.156.6';
ClientIp::reset();
same( 'IP filter accepts Postmark', WebhookAuth::OK, WebhookAuth::check( $basic, array() ) );
reset_settings( array_merge( $v2, array( 'webhook_pass' => '' ) ) );
same( 'auth not configured', WebhookAuth::NOT_CONFIGURED, WebhookAuth::check( $basic, array() ) );
reset_settings( $v2 );

// Delivery.
$id       = llt_accepted_lead( $data, 'pm-a' );
$delivery = array( 'RecordType' => 'Delivery', 'MessageID' => 'pm-a', 'DeliveredAt' => '2026-10-20T10:00:00.1234567Z', 'Metadata' => array( 'llt_lead_id' => (string) $id ) );
same( 'delivery applied', EventProcessor::RESULT_APPLIED, EventProcessor::handle( $delivery ) );
$lead = LeadRepository::find( $id );
same( 'delivered', 'delivered', $lead->delivery_status );
same( 'delivered_at', '2026-10-20 10:00:00', $lead->delivered_at );
same( 'content deleted on delivery', null, MessageRepository::find( $id ) );
same( 'duplicate', EventProcessor::RESULT_DUPLICATE, EventProcessor::handle( $delivery ) );
$late_bounce = array( 'RecordType' => 'Bounce', 'MessageID' => 'pm-a', 'TypeCode' => 1, 'Type' => 'HardBounce', 'Inactive' => true );
same( 'bounce after delivery does not change the status', EventProcessor::RESULT_DUPLICATE, EventProcessor::handle( $late_bounce ) );
same( 'stays delivered', 'delivered', LeadRepository::find( $id )->delivery_status );

// Webhook arriving before the MessageID is saved (lead still pending, e.g. ambiguous timeout).
$id2 = LeadService::create( $data, 245, 'downtown@example.com' );
$wpdb->query( "UPDATE wp_location_leads SET last_error_code = 'ambiguous_timeout' WHERE id = {$id2}" );
$early = array( 'RecordType' => 'Delivery', 'MessageID' => 'pm-b', 'DeliveredAt' => '2026-10-20T10:00:00Z', 'Metadata' => array( 'llt_lead_id' => (string) $id2 ) );
same( 'correlation by metadata', EventProcessor::RESULT_APPLIED, EventProcessor::handle( $early ) );
$lead = LeadRepository::find( $id2 );
same( 'early webhook → accepted', 'accepted', $lead->send_status );
same( 'early webhook → MessageID set', 'pm-b', $lead->postmark_message_id );
same( 'early webhook → out of the queue', null, $lead->next_retry_at );

// Metadata pointing to a lead with a different MessageID is not correlated.
$forged = array( 'RecordType' => 'Delivery', 'MessageID' => 'pm-x', 'Metadata' => array( 'llt_lead_id' => (string) $id2 ) );
same( 'metadata for another message → uncorrelated', EventProcessor::RESULT_UNCORRELATED, EventProcessor::handle( $forged ) );
$before = LeadRepository::counts()['total'];
same( 'uncorrelated', EventProcessor::RESULT_UNCORRELATED, EventProcessor::handle( array( 'RecordType' => 'Delivery', 'MessageID' => 'unknown' ) ) );
same( 'the webhook does not create leads', $before, LeadRepository::counts()['total'] );

// Permanent bounce.
$id3 = llt_accepted_lead( $data, 'pm-c' );
same( 'hard bounce', EventProcessor::RESULT_APPLIED, EventProcessor::handle( array( 'RecordType' => 'Bounce', 'MessageID' => 'pm-c', 'TypeCode' => 1, 'Type' => 'HardBounce', 'Inactive' => true, 'BouncedAt' => '2026-10-20T11:00:00Z' ) ) );
$lead = LeadRepository::find( $id3 );
same( 'bounced', 'bounced', $lead->delivery_status );
same( 'bounce code', 'bounce:HardBounce', $lead->last_error_code );
check( 'content kept on bounce', null !== MessageRepository::find( $id3 ) );
same( 'bounce in the alert queue', 1, count( (array) State::get( 'pending_bounces' ) ) );
AdminNotifier::flush_bounces();
same( 'bounce alert sent', 1, count( $GLOBALS['llt_mail_log'] ) );
check( 'the bounce alert contains the ID', false !== strpos( $GLOBALS['llt_mail_log'][0][2], '#' . $id3 ) );
same( 'bounce queue emptied', array(), State::get( 'pending_bounces' ) );

// Soft bounce → deferred → delivered.
$id4 = llt_accepted_lead( $data, 'pm-d' );
EventProcessor::handle( array( 'RecordType' => 'Bounce', 'MessageID' => 'pm-d', 'TypeCode' => 4096, 'Type' => 'SoftBounce', 'Inactive' => false ) );
same( 'soft bounce → deferred', 'deferred', LeadRepository::find( $id4 )->delivery_status );
EventProcessor::handle( array( 'RecordType' => 'Delivery', 'MessageID' => 'pm-d' ) );
same( 'deferred → delivered', 'delivered', LeadRepository::find( $id4 )->delivery_status );
same( 'error cleared on delivery', null, LeadRepository::find( $id4 )->last_error_code );

$id5 = llt_accepted_lead( $data, 'pm-e' );
same( 'autoresponder ignored', EventProcessor::RESULT_IGNORED, EventProcessor::handle( array( 'RecordType' => 'Bounce', 'MessageID' => 'pm-e', 'TypeCode' => 64 ) ) );
same( 'unknown type ignored', EventProcessor::RESULT_IGNORED, EventProcessor::handle( array( 'RecordType' => 'Open', 'MessageID' => 'pm-e' ) ) );

// The controller.
$_SERVER['REMOTE_ADDR'] = '50.31.156.6';
ClientIp::reset();
reset_settings( array_merge( $v2, array( 'webhook_enabled' => false ) ) );
same( 'webhook disabled → 404', 404, WebhookController::handle( new WP_REST_Request( array( 'authorization' => $basic ), '{}' ) )->get_status() );
reset_settings( $v2 );
same( 'wrong auth → 401', 401, WebhookController::handle( new WP_REST_Request( array(), '{}' ) )->get_status() );
same( 'rejection reason saved', WebhookAuth::MISSING_HEADER, State::get( 'last_webhook_rejected' )['reason'] );
same( 'invalid payload → 200', 200, WebhookController::handle( new WP_REST_Request( array( 'authorization' => $basic ), 'not json' ) )->get_status() );
same( 'payload too large → 413', 413, WebhookController::handle( new WP_REST_Request( array( 'authorization' => $basic ), str_repeat( 'x', 300000 ) ) )->get_status() );
$response = WebhookController::handle( new WP_REST_Request( array( 'authorization' => $basic ), json_encode( array( 'RecordType' => 'Delivery', 'MessageID' => 'pm-e' ) ) ) );
same( 'valid event → 200', 200, $response->get_status() );
same( 'result applied', 'applied', $response->data['result'] );
same( 'last webhook saved', 'Delivery', State::get( 'last_webhook' )['type'] );

// Reconciliation.
$GLOBALS['llt_mail_log'] = array();
$old    = Util::now( -3 * HOUR_IN_SECONDS );
$r1     = llt_accepted_lead( $data, 'pm-r1', $old );
$r2     = llt_accepted_lead( $data, 'pm-r2', $old );
$r3     = llt_accepted_lead( $data, 'pm-r3', $old );
$recent = llt_accepted_lead( $data, 'pm-recent' );
State::set( 'last_webhook', array( 'at' => time() - 2 * DAY_IN_SECONDS ) );
$GLOBALS['llt_http_log']   = array();
$GLOBALS['llt_http_queue'] = array(
	llt_http( 200, array( 'MessageEvents' => array( array( 'Type' => 'Delivered', 'ReceivedAt' => '2026-10-20T09:00:00Z' ) ) ) ),
	llt_http( 200, array( 'MessageEvents' => array( array( 'Type' => 'Bounced', 'ReceivedAt' => '2026-10-20T09:05:00Z', 'Details' => array( 'BounceID' => '777' ) ) ) ) ),
	llt_http( 200, array( 'ID' => 777, 'Type' => 'HardBounce', 'TypeCode' => 1, 'Inactive' => true ) ),
	llt_http( 429, array( 'Message' => 'Rate limit' ) ),
);
$stats = Reconciler::run();
same( 'reconciliation: delivered', 'delivered', LeadRepository::find( $r1 )->delivery_status );
same( 'reconciliation: delivered_at', '2026-10-20 09:00:00', LeadRepository::find( $r1 )->delivered_at );
same( 'reconciliation: bounce', 'bounced', LeadRepository::find( $r2 )->delivery_status );
same( 'reconciliation: stopped at 429', true, $stats['stopped'] );
same( 'reconciliation: r3 unchanged', 'unknown', LeadRepository::find( $r3 )->delivery_status );
same( 'reconciliation: the recent lead is not checked', 'unknown', LeadRepository::find( $recent )->delivery_status );
check( 'the details request', false !== strpos( $GLOBALS['llt_http_log'][0][1], '/messages/outbound/pm-r1/details' ) );
check( 'the bounce request', false !== strpos( $GLOBALS['llt_http_log'][2][1], '/bounces/777' ) );
check( 'webhook stopped alert', (bool) array_filter( $GLOBALS['llt_mail_log'], static fn( $m ) => false !== strpos( $m[1], 'seems to be stopped' ) ) );

// No events: the lead moves to the end of the line.
$GLOBALS['llt_http_queue'] = array(
	llt_http( 200, array( 'MessageEvents' => array() ) ),
);
$wpdb->query( "UPDATE wp_location_leads SET updated_at = '2000-01-01 00:00:00' WHERE id = {$r3}" );
$wpdb->query( "UPDATE wp_location_leads SET updated_at = '2030-01-01 00:00:00' WHERE id <> {$r3}" );
Reconciler::run();
check( 'lead without events: updated_at refreshed', LeadRepository::find( $r3 )->updated_at > '2000-01-01 00:00:00' );

// Retention with delivery confirmation.
reset_settings( array_merge( $v2, array( 'retention_unconfirmed_days' => 7, 'retention_failed_days' => 14 ) ) );
$u1    = llt_accepted_lead( $data, 'pm-u1', Util::now( -8 * DAY_IN_SECONDS ) );
$u2    = llt_accepted_lead( $data, 'pm-u2', Util::now( -2 * DAY_IN_SECONDS ) );
$rules = RetentionService::rules();
check( 'the "accepted" rule is replaced', ! isset( $rules['accepted'] ) && isset( $rules['unconfirmed'] ) );
RetentionService::run();
same( 'old unconfirmed deleted', null, MessageRepository::find( $u1 ) );
check( 'recent unconfirmed kept (even if older than 24 h)', null !== MessageRepository::find( $u2 ) );
check( 'recent bounce kept', null !== MessageRepository::find( $id3 ) );
