<?php
/**
 * v2: webhook Postmark, autentificare, monotonie, reconciliere, retenție cu confirmare.
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

$data = array( 'name' => 'Ana', 'email' => 'ana@example.md', 'phone' => '', 'message' => 'Bună' );

function llt_accepted_lead( array $data, string $message_id, string $sent_at = '' ): int {
	global $wpdb;
	$id = LeadService::create( $data, 245, 'centru@example.md' );
	$sent_at = $sent_at ?: Util::now();
	$wpdb->query( "UPDATE wp_location_leads SET send_status = 'accepted', postmark_message_id = '{$message_id}', sent_at = '{$sent_at}', next_retry_at = NULL WHERE id = {$id}" );
	return $id;
}

// Clasificarea bounce-urilor.
same( 'HardBounce → bounced', 'bounced', EventProcessor::classify_bounce( 1, false ) );
same( 'BadEmailAddress → bounced', 'bounced', EventProcessor::classify_bounce( 100000, false ) );
same( 'DMARC → bounced', 'bounced', EventProcessor::classify_bounce( 100009, false ) );
same( 'Transient → deferred', 'deferred', EventProcessor::classify_bounce( 2, false ) );
same( 'SoftBounce → deferred', 'deferred', EventProcessor::classify_bounce( 4096, false ) );
same( 'SoftBounce inactiv → bounced', 'bounced', EventProcessor::classify_bounce( 4096, true ) );
same( 'AutoResponder ignorat', null, EventProcessor::classify_bounce( 64, false ) );
same( 'SpamNotification ignorat', null, EventProcessor::classify_bounce( 512, false ) );

// Autentificare.
$basic = 'Basic ' . base64_encode( 'lltuser:s3cret' );
same( 'auth corectă', WebhookAuth::OK, WebhookAuth::check( $basic, array() ) );
same( 'auth parolă greșită', WebhookAuth::BAD_CREDENTIAL, WebhookAuth::check( 'Basic ' . base64_encode( 'lltuser:gresit' ), array() ) );
same( 'auth lipsă header', WebhookAuth::MISSING_HEADER, WebhookAuth::check( null, array() ) );
same( 'auth din PHP_AUTH_USER', WebhookAuth::OK, WebhookAuth::check( null, array( 'PHP_AUTH_USER' => 'lltuser', 'PHP_AUTH_PW' => 's3cret' ) ) );
same( 'auth din REDIRECT_HTTP_AUTHORIZATION', WebhookAuth::OK, WebhookAuth::check( '', array( 'REDIRECT_HTTP_AUTHORIZATION' => $basic ) ) );
same( 'parolă cu „:”', array( 'u', 'a:b' ), WebhookAuth::credentials( 'Basic ' . base64_encode( 'u:a:b' ), array() ) );

reset_settings( array_merge( $v2, array( 'webhook_ip_filter' => true ) ) );
$_SERVER['REMOTE_ADDR'] = '8.8.8.8';
ClientIp::reset();
same( 'filtru IP respinge', WebhookAuth::IP_NOT_ALLOWED, WebhookAuth::check( $basic, array() ) );
$_SERVER['REMOTE_ADDR'] = '50.31.156.6';
ClientIp::reset();
same( 'filtru IP acceptă Postmark', WebhookAuth::OK, WebhookAuth::check( $basic, array() ) );
reset_settings( array_merge( $v2, array( 'webhook_pass' => '' ) ) );
same( 'auth neconfigurată', WebhookAuth::NOT_CONFIGURED, WebhookAuth::check( $basic, array() ) );
reset_settings( $v2 );

// Delivery.
$id = llt_accepted_lead( $data, 'pm-a' );
$delivery = array( 'RecordType' => 'Delivery', 'MessageID' => 'pm-a', 'DeliveredAt' => '2026-10-20T10:00:00.1234567Z', 'Metadata' => array( 'llt_lead_id' => (string) $id ) );
same( 'delivery aplicat', EventProcessor::RESULT_APPLIED, EventProcessor::handle( $delivery ) );
$lead = LeadRepository::find( $id );
same( 'delivered', 'delivered', $lead->delivery_status );
same( 'delivered_at', '2026-10-20 10:00:00', $lead->delivered_at );
same( 'conținut șters la livrare', null, MessageRepository::find( $id ) );
same( 'duplicat', EventProcessor::RESULT_DUPLICATE, EventProcessor::handle( $delivery ) );
$late_bounce = array( 'RecordType' => 'Bounce', 'MessageID' => 'pm-a', 'TypeCode' => 1, 'Type' => 'HardBounce', 'Inactive' => true );
same( 'bounce după livrare nu schimbă starea', EventProcessor::RESULT_DUPLICATE, EventProcessor::handle( $late_bounce ) );
same( 'rămâne delivered', 'delivered', LeadRepository::find( $id )->delivery_status );

// Webhook sosit înainte de salvarea MessageID (lead încă pending, ex. timeout ambiguu).
$id2 = LeadService::create( $data, 245, 'centru@example.md' );
$wpdb->query( "UPDATE wp_location_leads SET last_error_code = 'ambiguous_timeout' WHERE id = {$id2}" );
$early = array( 'RecordType' => 'Delivery', 'MessageID' => 'pm-b', 'DeliveredAt' => '2026-10-20T10:00:00Z', 'Metadata' => array( 'llt_lead_id' => (string) $id2 ) );
same( 'corelare după metadata', EventProcessor::RESULT_APPLIED, EventProcessor::handle( $early ) );
$lead = LeadRepository::find( $id2 );
same( 'webhook timpuriu → accepted', 'accepted', $lead->send_status );
same( 'webhook timpuriu → MessageID setat', 'pm-b', $lead->postmark_message_id );
same( 'webhook timpuriu → ieșit din coadă', null, $lead->next_retry_at );

// Metadata care indică un lead cu alt MessageID nu se corelează.
$forged = array( 'RecordType' => 'Delivery', 'MessageID' => 'pm-x', 'Metadata' => array( 'llt_lead_id' => (string) $id2 ) );
same( 'metadata pentru alt mesaj → necorelat', EventProcessor::RESULT_UNCORRELATED, EventProcessor::handle( $forged ) );
$before = LeadRepository::counts()['total'];
same( 'necorelat', EventProcessor::RESULT_UNCORRELATED, EventProcessor::handle( array( 'RecordType' => 'Delivery', 'MessageID' => 'necunoscut' ) ) );
same( 'webhookul nu creează leaduri', $before, LeadRepository::counts()['total'] );

// Bounce definitiv.
$id3 = llt_accepted_lead( $data, 'pm-c' );
same( 'hard bounce', EventProcessor::RESULT_APPLIED, EventProcessor::handle( array( 'RecordType' => 'Bounce', 'MessageID' => 'pm-c', 'TypeCode' => 1, 'Type' => 'HardBounce', 'Inactive' => true, 'BouncedAt' => '2026-10-20T11:00:00Z' ) ) );
$lead = LeadRepository::find( $id3 );
same( 'bounced', 'bounced', $lead->delivery_status );
same( 'cod bounce', 'bounce:HardBounce', $lead->last_error_code );
check( 'conținut păstrat la bounce', null !== MessageRepository::find( $id3 ) );
same( 'bounce în coada de alerte', 1, count( (array) State::get( 'pending_bounces' ) ) );
AdminNotifier::flush_bounces();
same( 'alertă bounce trimisă', 1, count( $GLOBALS['llt_mail_log'] ) );
check( 'alerta bounce conține ID-ul', false !== strpos( $GLOBALS['llt_mail_log'][0][2], '#' . $id3 ) );
same( 'coada de bounce golită', array(), State::get( 'pending_bounces' ) );

// Soft bounce → deferred → delivered.
$id4 = llt_accepted_lead( $data, 'pm-d' );
EventProcessor::handle( array( 'RecordType' => 'Bounce', 'MessageID' => 'pm-d', 'TypeCode' => 4096, 'Type' => 'SoftBounce', 'Inactive' => false ) );
same( 'soft bounce → deferred', 'deferred', LeadRepository::find( $id4 )->delivery_status );
EventProcessor::handle( array( 'RecordType' => 'Delivery', 'MessageID' => 'pm-d' ) );
same( 'deferred → delivered', 'delivered', LeadRepository::find( $id4 )->delivery_status );
same( 'eroarea ștearsă la livrare', null, LeadRepository::find( $id4 )->last_error_code );

$id5 = llt_accepted_lead( $data, 'pm-e' );
same( 'autoresponder ignorat', EventProcessor::RESULT_IGNORED, EventProcessor::handle( array( 'RecordType' => 'Bounce', 'MessageID' => 'pm-e', 'TypeCode' => 64 ) ) );
same( 'tip necunoscut ignorat', EventProcessor::RESULT_IGNORED, EventProcessor::handle( array( 'RecordType' => 'Open', 'MessageID' => 'pm-e' ) ) );

// Controllerul.
$_SERVER['REMOTE_ADDR'] = '50.31.156.6';
ClientIp::reset();
reset_settings( array_merge( $v2, array( 'webhook_enabled' => false ) ) );
same( 'webhook dezactivat → 404', 404, WebhookController::handle( new WP_REST_Request( array( 'authorization' => $basic ), '{}' ) )->get_status() );
reset_settings( $v2 );
same( 'auth greșită → 401', 401, WebhookController::handle( new WP_REST_Request( array(), '{}' ) )->get_status() );
same( 'motiv respingere salvat', WebhookAuth::MISSING_HEADER, State::get( 'last_webhook_rejected' )['reason'] );
same( 'payload invalid → 200', 200, WebhookController::handle( new WP_REST_Request( array( 'authorization' => $basic ), 'nu e json' ) )->get_status() );
same( 'payload prea mare → 413', 413, WebhookController::handle( new WP_REST_Request( array( 'authorization' => $basic ), str_repeat( 'x', 300000 ) ) )->get_status() );
$response = WebhookController::handle( new WP_REST_Request( array( 'authorization' => $basic ), json_encode( array( 'RecordType' => 'Delivery', 'MessageID' => 'pm-e' ) ) ) );
same( 'eveniment valid → 200', 200, $response->get_status() );
same( 'rezultat aplicat', 'applied', $response->data['result'] );
same( 'ultimul webhook salvat', 'Delivery', State::get( 'last_webhook' )['type'] );

// Reconciliere.
$GLOBALS['llt_mail_log'] = array();
$old = Util::now( -3 * HOUR_IN_SECONDS );
$r1  = llt_accepted_lead( $data, 'pm-r1', $old );
$r2  = llt_accepted_lead( $data, 'pm-r2', $old );
$r3  = llt_accepted_lead( $data, 'pm-r3', $old );
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
same( 'reconciliere: livrat', 'delivered', LeadRepository::find( $r1 )->delivery_status );
same( 'reconciliere: delivered_at', '2026-10-20 09:00:00', LeadRepository::find( $r1 )->delivered_at );
same( 'reconciliere: bounce', 'bounced', LeadRepository::find( $r2 )->delivery_status );
same( 'reconciliere: oprită la 429', true, $stats['stopped'] );
same( 'reconciliere: r3 neschimbat', 'unknown', LeadRepository::find( $r3 )->delivery_status );
same( 'reconciliere: leadul recent nu e verificat', 'unknown', LeadRepository::find( $recent )->delivery_status );
check( 'cererea de detalii', false !== strpos( $GLOBALS['llt_http_log'][0][1], '/messages/outbound/pm-r1/details' ) );
check( 'cererea de bounce', false !== strpos( $GLOBALS['llt_http_log'][2][1], '/bounces/777' ) );
check( 'alertă webhook oprit', (bool) array_filter( $GLOBALS['llt_mail_log'], static fn( $m ) => false !== strpos( $m[1], 'Webhookul Postmark pare oprit' ) ) );

// Fără evenimente: leadul trece la sfârșitul rândului.
$GLOBALS['llt_http_queue'] = array(
	llt_http( 200, array( 'MessageEvents' => array() ) ),
);
$wpdb->query( "UPDATE wp_location_leads SET updated_at = '2000-01-01 00:00:00' WHERE id = {$r3}" );
$wpdb->query( "UPDATE wp_location_leads SET updated_at = '2030-01-01 00:00:00' WHERE id <> {$r3}" );
Reconciler::run();
check( 'lead fără evenimente: updated_at actualizat', LeadRepository::find( $r3 )->updated_at > '2000-01-01 00:00:00' );

// Retenție cu confirmarea livrării.
reset_settings( array_merge( $v2, array( 'retention_unconfirmed_days' => 7, 'retention_failed_days' => 14 ) ) );
$u1 = llt_accepted_lead( $data, 'pm-u1', Util::now( -8 * DAY_IN_SECONDS ) );
$u2 = llt_accepted_lead( $data, 'pm-u2', Util::now( -2 * DAY_IN_SECONDS ) );
$rules = RetentionService::rules();
check( 'regula „accepted” înlocuită', ! isset( $rules['accepted'] ) && isset( $rules['unconfirmed'] ) );
RetentionService::run();
same( 'neconfirmat vechi șters', null, MessageRepository::find( $u1 ) );
check( 'neconfirmat recent păstrat (chiar dacă e mai vechi de 24 h)', null !== MessageRepository::find( $u2 ) );
check( 'bounce recent păstrat', null !== MessageRepository::find( $id3 ) );
