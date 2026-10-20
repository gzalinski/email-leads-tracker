<?php
/**
 * Integration: create lead → send → retry → failure → resend → retention, on SQLite.
 */
// phpcs:ignoreFile
require_once dirname( __DIR__ ) . '/wpdb-sqlite.php';

use EmailLeadsTracker\Database\LeadRepository;
use EmailLeadsTracker\Database\MessageRepository;
use EmailLeadsTracker\Lead\LeadActions;
use EmailLeadsTracker\Lead\LeadService;
use EmailLeadsTracker\Privacy\PersonalDataEraser;
use EmailLeadsTracker\Privacy\PersonalDataExporter;
use EmailLeadsTracker\Queue\Lock;
use EmailLeadsTracker\Queue\SendWorker;
use EmailLeadsTracker\Retention\RetentionService;
use EmailLeadsTracker\Settings\SecretStore;
use EmailLeadsTracker\State;
use EmailLeadsTracker\Util;

global $wpdb;
$wpdb = new LLT_Test_WPDB();

reset_settings( array( 'postmark_token' => SecretStore::encrypt( 'tok' ), 'alert_threshold' => 2 ) );
$GLOBALS['llt_test_options']['llt_state']   = array();
$GLOBALS['llt_test_options']['admin_email'] = 'admin@example.com';

$data = array( 'name' => 'John', 'email' => 'john@example.com', 'phone' => '', 'message' => 'Hello' );

function llt_make_due( int $id ): void {
	global $wpdb;
	$wpdb->query( "UPDATE wp_location_leads SET next_retry_at = '2000-01-01 00:00:00' WHERE id = {$id}" );
}

// 1. Creation.
$id   = LeadService::create( $data, 245, 'downtown@example.com' );
$lead = LeadRepository::find( $id );
same( 'lead created pending', 'pending', $lead->send_status );
same( 'delivery unknown', 'unknown', $lead->delivery_status );
check( 'content saved', null !== MessageRepository::find( $id ) );
same( 'empty phone → NULL', null, MessageRepository::find( $id )->sender_phone );

// 2. Lock: the second claim fails.
$token = Lock::claim( $id );
check( 'claim succeeded', null !== $token );
same( 'second claim fails', null, Lock::claim( $id ) );
Lock::release( $id, $token );

// 3. Accepted send.
$GLOBALS['llt_http_queue'] = array( llt_http( 200, array( 'ErrorCode' => 0, 'MessageID' => 'pm-1' ) ) );
SendWorker::process_lead( $id );
$lead = LeadRepository::find( $id );
same( 'accepted', 'accepted', $lead->send_status );
same( 'MessageID saved', 'pm-1', $lead->postmark_message_id );
check( 'sent_at set', null !== $lead->sent_at );
same( 'lock released', null, $lead->lock_token );
$payload = json_decode( $GLOBALS['llt_http_log'][0][2]['body'], true );
same( 'metadata lead id', (string) $id, $payload['Metadata']['llt_lead_id'] );
same( 'ReplyTo is the visitor', 'john@example.com', $payload['ReplyTo'] );
same( 'To is the location', 'downtown@example.com', $payload['To'] );
same( 'stream', 'outbound', $payload['MessageStream'] );

// 4. An accepted lead is not sent again.
$GLOBALS['llt_http_log'] = array();
SendWorker::process_lead( $id );
same( 'accepted is not resent', 0, count( $GLOBALS['llt_http_log'] ) );

// 5. Temporary error → retry scheduled.
$id2 = LeadService::create( $data, 245, 'downtown@example.com' );
$GLOBALS['llt_http_queue'] = array( llt_http( 503, array( 'ErrorCode' => 100, 'Message' => 'Maintenance' ) ) );
SendWorker::process_lead( $id2 );
$lead = LeadRepository::find( $id2 );
same( 'temporary stays pending', 'pending', $lead->send_status );
same( 'retry_count 1', 1, (int) $lead->retry_count );
check( 'next_retry_at in the future', $lead->next_retry_at > Util::now() );
same( 'error code', 'pm_100', $lead->last_error_code );
$GLOBALS['llt_http_log'] = array();
SendWorker::process_lead( $id2 );
same( 'not due, not sent', 0, count( $GLOBALS['llt_http_log'] ) );

// 6. Ambiguous timeout → search in Postmark → found → accepted without resending.
llt_make_due( $id2 );
$GLOBALS['llt_http_queue'] = array( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 15001 milliseconds with 0 bytes received' ) );
SendWorker::process_lead( $id2 );
$lead = LeadRepository::find( $id2 );
same( 'ambiguous stays pending', 'pending', $lead->send_status );
same( 'ambiguous_timeout code', 'ambiguous_timeout', $lead->last_error_code );
check( 'ambiguous: pause ≥ 5 min', strtotime( $lead->next_retry_at . ' UTC' ) - time() >= 295 );

llt_make_due( $id2 );
$GLOBALS['llt_http_log']   = array();
$GLOBALS['llt_http_queue'] = array( llt_http( 200, array( 'TotalCount' => 1, 'Messages' => array( array( 'MessageID' => 'pm-2' ) ) ) ) );
SendWorker::process_lead( $id2 );
$lead = LeadRepository::find( $id2 );
same( 'found in Postmark → accepted', 'accepted', $lead->send_status );
same( 'MessageID from the search', 'pm-2', $lead->postmark_message_id );
same( 'a single request (GET), no resend', 1, count( $GLOBALS['llt_http_log'] ) );
same( 'the request was the search', 'GET', $GLOBALS['llt_http_log'][0][0] );
check( 'search by metadata', false !== strpos( $GLOBALS['llt_http_log'][0][1], 'metadata_llt_lead_id=' . $id2 ) );
check( 'search with the stream', false !== strpos( $GLOBALS['llt_http_log'][0][1], 'messagestream=outbound' ) );

// 7. Ambiguous → not found → resent.
$id3 = LeadService::create( $data, 245, 'downtown@example.com' );
$wpdb->query( "UPDATE wp_location_leads SET last_error_code = 'ambiguous_timeout' WHERE id = {$id3}" );
$GLOBALS['llt_http_log']   = array();
$GLOBALS['llt_http_queue'] = array(
	llt_http( 200, array( 'TotalCount' => 0, 'Messages' => array() ) ),
	llt_http( 200, array( 'ErrorCode' => 0, 'MessageID' => 'pm-3' ) ),
);
SendWorker::process_lead( $id3 );
same( 'not found → sent', 'pm-3', LeadRepository::find( $id3 )->postmark_message_id );
same( 'search + send', array( 'GET', 'POST' ), array_column( $GLOBALS['llt_http_log'], 0 ) );

// 8. Permanent error → failed + alert at the threshold.
$GLOBALS['llt_mail_log'] = array();
$id4 = LeadService::create( $data, 245, 'downtown@example.com' );
$GLOBALS['llt_http_queue'] = array( llt_http( 422, array( 'ErrorCode' => 406, 'Message' => 'Inactive recipient downtown@example.com' ) ) );
SendWorker::process_lead( $id4 );
$lead = LeadRepository::find( $id4 );
same( '406 → failed', 'failed', $lead->send_status );
same( '406 code', 'pm_406', $lead->last_error_code );
check( 'email masked in the error', false === strpos( (string) $lead->last_error_message, '@' ) );
same( 'below the threshold: no alert', 0, count( $GLOBALS['llt_mail_log'] ) );

$id5 = LeadService::create( $data, 245, 'downtown@example.com' );
$GLOBALS['llt_http_queue'] = array( llt_http( 422, array( 'ErrorCode' => 300, 'Message' => 'Invalid' ) ) );
SendWorker::process_lead( $id5 );
same( 'at the threshold: alert sent', 1, count( $GLOBALS['llt_mail_log'] ) );
check( 'the alert contains the IDs', false !== strpos( $GLOBALS['llt_mail_log'][0][2], '#' . $id4 ) && false !== strpos( $GLOBALS['llt_mail_log'][0][2], '#' . $id5 ) );
check( 'the alert has no visitor data', false === strpos( $GLOBALS['llt_mail_log'][0][2], 'john@example.com' ) );
same( 'the alert goes to the alert address', 'admin@example.com', $GLOBALS['llt_mail_log'][0][0] );

// Rate limiting: a second alert of the same type is not sent.
$id6 = LeadService::create( $data, 245, 'downtown@example.com' );
$GLOBALS['llt_http_queue'] = array( llt_http( 422, array( 'ErrorCode' => 300, 'Message' => 'Invalid' ) ) );
SendWorker::process_lead( $id6 );
same( 'alert rate-limited', 1, count( $GLOBALS['llt_mail_log'] ) );

// Recovery: a successful send triggers the "works again" notification.
$id7 = LeadService::create( $data, 245, 'downtown@example.com' );
$GLOBALS['llt_http_queue'] = array( llt_http( 200, array( 'ErrorCode' => 0, 'MessageID' => 'pm-7' ) ) );
SendWorker::process_lead( $id7 );
same( 'recovery alert', 2, count( $GLOBALS['llt_mail_log'] ) );
check( 'recovery subject', false !== strpos( $GLOBALS['llt_mail_log'][1][1], 'works again' ) );
same( 'counter reset', 0, (int) State::get( 'consecutive_failures' ) );

// 9. Configuration error → immediate alert.
$GLOBALS['llt_mail_log'] = array();
$id8 = LeadService::create( $data, 245, 'downtown@example.com' );
$GLOBALS['llt_http_queue'] = array( llt_http( 401, array( 'ErrorCode' => 10, 'Message' => 'Bad token' ) ) );
SendWorker::process_lead( $id8 );
same( 'config → failed', 'failed', LeadRepository::find( $id8 )->send_status );
same( 'config → immediate alert', 1, count( $GLOBALS['llt_mail_log'] ) );

// 10. Retries exhausted.
reset_settings( array( 'postmark_token' => SecretStore::encrypt( 'tok' ), 'retry_schedule' => '1, 5', 'max_retries' => 2 ) );
$id9 = LeadService::create( $data, 245, 'downtown@example.com' );
same( 'max_retries from the settings', 2, (int) LeadRepository::find( $id9 )->max_retries );
for ( $i = 0; $i < 3; $i++ ) {
	llt_make_due( $id9 );
	$GLOBALS['llt_http_queue'] = array( llt_http( 500, '' ) );
	SendWorker::process_lead( $id9 );
}
$lead = LeadRepository::find( $id9 );
same( 'exhausted → failed', 'failed', $lead->send_status );
same( 'exhausted code', 'retries_exhausted', $lead->last_error_code );
same( 'attempts: 1 initial + 2 retries', 2, (int) $lead->retry_count );

// 11. Manual resend.
MessageRepository::delete( $id5 );
$result = LeadActions::resend( array( $id4, $id5, $id ), 7 );
same( 'resend: queued', 1, $result['queued'] );
same( 'resend: no content', array( $id5 ), $result['no_content'] );
same( 'resend: not failed', array( $id ), $result['not_failed'] );
$lead = LeadRepository::find( $id4 );
same( 'resent → pending', 'pending', $lead->send_status );
same( 'retry_count reset', 0, (int) $lead->retry_count );
same( 'manual_resend_count', 1, (int) $lead->manual_resend_count );
same( 'last_resend_by', 7, (int) $lead->last_resend_by );
same( 'a second resend does not duplicate', 0, LeadActions::resend( array( $id4 ) )['queued'] );

// Dismiss / restore.
same( 'dismiss', 1, LeadActions::dismiss( array( $id8 ), 7 ) );
check( 'dismissed_at set', null !== LeadRepository::find( $id8 )->dismissed_at );
LeadActions::restore( array( $id8 ) );
same( 'restored', null, LeadRepository::find( $id8 )->dismissed_at );

// 12. The queue only processes due leads.
$GLOBALS['llt_http_queue'] = array( llt_http( 200, array( 'ErrorCode' => 0, 'MessageID' => 'pm-4' ) ) );
SendWorker::process_queue();
same( 'the queue sent the resent lead', 'accepted', LeadRepository::find( $id4 )->send_status );
check( 'heartbeat', (int) State::get( 'worker_heartbeat' ) > 0 );
same( 'global lock released', false, get_option( 'llt_worker_lock' ) );

// 13. Retention (without delivery confirmation).
reset_settings( array( 'retention_accepted_hours' => 24, 'retention_failed_days' => 14 ) );
$wpdb->query( "UPDATE wp_location_leads SET sent_at = '2000-01-01 00:00:00' WHERE id = {$id}" );
$wpdb->query( "UPDATE wp_location_lead_messages SET created_at = '2000-01-01 00:00:00' WHERE lead_id = {$id8}" );
$dry = RetentionService::run( true );
same( 'dry run accepted', 1, $dry['accepted'] );
same( 'dry run failed', 1, $dry['failed'] );
check( 'dry run does not delete', null !== MessageRepository::find( $id ) );
RetentionService::run();
same( 'old accepted deleted', null, MessageRepository::find( $id ) );
same( 'old failed deleted', null, MessageRepository::find( $id8 ) );
check( 'recent accepted kept', null !== MessageRepository::find( $id7 ) );
check( 'recent failed kept', null !== MessageRepository::find( $id6 ) );
check( 'the minimal lead stays', null !== LeadRepository::find( $id ) );

// 14. GDPR.
$export = PersonalDataExporter::export( 'john@example.com', 1 );
check( 'export finds the messages', count( $export['data'] ) > 0 && $export['done'] );
same( 'export: email value', 'john@example.com', $export['data'][0]['data'][2]['value'] );
$erase = PersonalDataEraser::erase( 'john@example.com', 1 );
check( 'GDPR erasure', $erase['items_removed'] && $erase['done'] );
same( 'nothing left after erasure', 0, count( PersonalDataExporter::export( 'john@example.com', 1 )['data'] ) );

// 15. Statistics.
$counts = LeadRepository::counts();
same( 'total leads', 9, $counts['total'] );
