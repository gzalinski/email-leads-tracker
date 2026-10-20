<?php
/**
 * Integrare: creare lead → trimitere → retry → eșec → retrimitere → retenție, pe SQLite.
 */
// phpcs:ignoreFile
require_once dirname( __DIR__ ) . '/wpdb-sqlite.php';

use LocationLeadTracker\Database\LeadRepository;
use LocationLeadTracker\Database\MessageRepository;
use LocationLeadTracker\Lead\LeadActions;
use LocationLeadTracker\Lead\LeadService;
use LocationLeadTracker\Privacy\PersonalDataEraser;
use LocationLeadTracker\Privacy\PersonalDataExporter;
use LocationLeadTracker\Queue\Lock;
use LocationLeadTracker\Queue\SendWorker;
use LocationLeadTracker\Retention\RetentionService;
use LocationLeadTracker\Settings\SecretStore;
use LocationLeadTracker\State;
use LocationLeadTracker\Util;

global $wpdb;
$wpdb = new LLT_Test_WPDB();

reset_settings( array( 'postmark_token' => SecretStore::encrypt( 'tok' ), 'alert_threshold' => 2 ) );
$GLOBALS['llt_test_options']['llt_state'] = array();
$GLOBALS['llt_test_options']['admin_email'] = 'admin@example.md';

$data = array( 'name' => 'Ion', 'email' => 'ion@example.md', 'phone' => '', 'message' => 'Salut' );

function llt_make_due( int $id ): void {
	global $wpdb;
	$wpdb->query( "UPDATE wp_location_leads SET next_retry_at = '2000-01-01 00:00:00' WHERE id = {$id}" );
}

// 1. Creare.
$id   = LeadService::create( $data, 245, 'centru@example.md' );
$lead = LeadRepository::find( $id );
same( 'lead creat pending', 'pending', $lead->send_status );
same( 'delivery unknown', 'unknown', $lead->delivery_status );
check( 'conținut salvat', null !== MessageRepository::find( $id ) );
same( 'telefon gol → NULL', null, MessageRepository::find( $id )->sender_phone );

// 2. Lock: al doilea claim eșuează.
$token = Lock::claim( $id );
check( 'claim reușit', null !== $token );
same( 'al doilea claim eșuează', null, Lock::claim( $id ) );
Lock::release( $id, $token );

// 3. Trimitere acceptată.
$GLOBALS['llt_http_queue'] = array( llt_http( 200, array( 'ErrorCode' => 0, 'MessageID' => 'pm-1' ) ) );
SendWorker::process_lead( $id );
$lead = LeadRepository::find( $id );
same( 'acceptat', 'accepted', $lead->send_status );
same( 'MessageID salvat', 'pm-1', $lead->postmark_message_id );
check( 'sent_at setat', null !== $lead->sent_at );
same( 'lock eliberat', null, $lead->lock_token );
$payload = json_decode( $GLOBALS['llt_http_log'][0][2]['body'], true );
same( 'metadata lead id', (string) $id, $payload['Metadata']['llt_lead_id'] );
same( 'ReplyTo vizitator', 'ion@example.md', $payload['ReplyTo'] );
same( 'To locație', 'centru@example.md', $payload['To'] );
same( 'stream', 'outbound', $payload['MessageStream'] );

// 4. Lead acceptat nu se mai trimite.
$GLOBALS['llt_http_log'] = array();
SendWorker::process_lead( $id );
same( 'acceptat nu se retrimite', 0, count( $GLOBALS['llt_http_log'] ) );

// 5. Eroare temporară → retry programat.
$id2 = LeadService::create( $data, 245, 'centru@example.md' );
$GLOBALS['llt_http_queue'] = array( llt_http( 503, array( 'ErrorCode' => 100, 'Message' => 'Maintenance' ) ) );
SendWorker::process_lead( $id2 );
$lead = LeadRepository::find( $id2 );
same( 'temporar rămâne pending', 'pending', $lead->send_status );
same( 'retry_count 1', 1, (int) $lead->retry_count );
check( 'next_retry_at în viitor', $lead->next_retry_at > Util::now() );
same( 'cod eroare', 'pm_100', $lead->last_error_code );
$GLOBALS['llt_http_log'] = array();
SendWorker::process_lead( $id2 );
same( 'nescadent nu se trimite', 0, count( $GLOBALS['llt_http_log'] ) );

// 6. Timeout ambiguu → căutare în Postmark → găsit → acceptat fără retrimitere.
llt_make_due( $id2 );
$GLOBALS['llt_http_queue'] = array( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 15001 milliseconds with 0 bytes received' ) );
SendWorker::process_lead( $id2 );
$lead = LeadRepository::find( $id2 );
same( 'ambiguu rămâne pending', 'pending', $lead->send_status );
same( 'cod ambiguous_timeout', 'ambiguous_timeout', $lead->last_error_code );
check( 'ambiguu: pauză ≥ 5 min', strtotime( $lead->next_retry_at . ' UTC' ) - time() >= 295 );

llt_make_due( $id2 );
$GLOBALS['llt_http_log']   = array();
$GLOBALS['llt_http_queue'] = array( llt_http( 200, array( 'TotalCount' => 1, 'Messages' => array( array( 'MessageID' => 'pm-2' ) ) ) ) );
SendWorker::process_lead( $id2 );
$lead = LeadRepository::find( $id2 );
same( 'găsit în Postmark → acceptat', 'accepted', $lead->send_status );
same( 'MessageID din căutare', 'pm-2', $lead->postmark_message_id );
same( 'o singură cerere (GET), fără retrimitere', 1, count( $GLOBALS['llt_http_log'] ) );
same( 'cererea a fost căutarea', 'GET', $GLOBALS['llt_http_log'][0][0] );
check( 'căutare după metadata', false !== strpos( $GLOBALS['llt_http_log'][0][1], 'metadata_llt_lead_id=' . $id2 ) );
check( 'căutare cu stream', false !== strpos( $GLOBALS['llt_http_log'][0][1], 'messagestream=outbound' ) );

// 7. Ambiguu → negăsit → se retrimite.
$id3 = LeadService::create( $data, 245, 'centru@example.md' );
$wpdb->query( "UPDATE wp_location_leads SET last_error_code = 'ambiguous_timeout' WHERE id = {$id3}" );
$GLOBALS['llt_http_log']   = array();
$GLOBALS['llt_http_queue'] = array(
	llt_http( 200, array( 'TotalCount' => 0, 'Messages' => array() ) ),
	llt_http( 200, array( 'ErrorCode' => 0, 'MessageID' => 'pm-3' ) ),
);
SendWorker::process_lead( $id3 );
same( 'negăsit → trimis', 'pm-3', LeadRepository::find( $id3 )->postmark_message_id );
same( 'căutare + trimitere', array( 'GET', 'POST' ), array_column( $GLOBALS['llt_http_log'], 0 ) );

// 8. Eroare permanentă → failed + alertă la prag.
$GLOBALS['llt_mail_log'] = array();
$id4 = LeadService::create( $data, 245, 'centru@example.md' );
$GLOBALS['llt_http_queue'] = array( llt_http( 422, array( 'ErrorCode' => 406, 'Message' => 'Inactive recipient centru@example.md' ) ) );
SendWorker::process_lead( $id4 );
$lead = LeadRepository::find( $id4 );
same( '406 → failed', 'failed', $lead->send_status );
same( '406 cod', 'pm_406', $lead->last_error_code );
check( 'email mascat în eroare', false === strpos( (string) $lead->last_error_message, '@' ) );
same( 'sub prag: fără alertă', 0, count( $GLOBALS['llt_mail_log'] ) );

$id5 = LeadService::create( $data, 245, 'centru@example.md' );
$GLOBALS['llt_http_queue'] = array( llt_http( 422, array( 'ErrorCode' => 300, 'Message' => 'Invalid' ) ) );
SendWorker::process_lead( $id5 );
same( 'la prag: alertă trimisă', 1, count( $GLOBALS['llt_mail_log'] ) );
check( 'alerta conține ID-urile', false !== strpos( $GLOBALS['llt_mail_log'][0][2], '#' . $id4 ) && false !== strpos( $GLOBALS['llt_mail_log'][0][2], '#' . $id5 ) );
check( 'alerta fără datele vizitatorului', false === strpos( $GLOBALS['llt_mail_log'][0][2], 'ion@example.md' ) );
same( 'alerta la adresa de alertă', 'admin@example.md', $GLOBALS['llt_mail_log'][0][0] );

// Limitare: a doua alertă de același tip nu pleacă.
$id6 = LeadService::create( $data, 245, 'centru@example.md' );
$GLOBALS['llt_http_queue'] = array( llt_http( 422, array( 'ErrorCode' => 300, 'Message' => 'Invalid' ) ) );
SendWorker::process_lead( $id6 );
same( 'alertă limitată în timp', 1, count( $GLOBALS['llt_mail_log'] ) );

// Revenire: o trimitere reușită trimite notificarea „funcționează din nou”.
$id7 = LeadService::create( $data, 245, 'centru@example.md' );
$GLOBALS['llt_http_queue'] = array( llt_http( 200, array( 'ErrorCode' => 0, 'MessageID' => 'pm-7' ) ) );
SendWorker::process_lead( $id7 );
same( 'alertă de revenire', 2, count( $GLOBALS['llt_mail_log'] ) );
check( 'subiect revenire', false !== strpos( $GLOBALS['llt_mail_log'][1][1], 'din nou' ) );
same( 'contor resetat', 0, (int) State::get( 'consecutive_failures' ) );

// 9. Eroare de configurare → alertă imediată.
$GLOBALS['llt_mail_log'] = array();
$id8 = LeadService::create( $data, 245, 'centru@example.md' );
$GLOBALS['llt_http_queue'] = array( llt_http( 401, array( 'ErrorCode' => 10, 'Message' => 'Bad token' ) ) );
SendWorker::process_lead( $id8 );
same( 'config → failed', 'failed', LeadRepository::find( $id8 )->send_status );
same( 'config → alertă imediată', 1, count( $GLOBALS['llt_mail_log'] ) );

// 10. Retry-uri epuizate.
reset_settings( array( 'postmark_token' => SecretStore::encrypt( 'tok' ), 'retry_schedule' => '1, 5', 'max_retries' => 2 ) );
$id9 = LeadService::create( $data, 245, 'centru@example.md' );
same( 'max_retries din setări', 2, (int) LeadRepository::find( $id9 )->max_retries );
for ( $i = 0; $i < 3; $i++ ) {
	llt_make_due( $id9 );
	$GLOBALS['llt_http_queue'] = array( llt_http( 500, '' ) );
	SendWorker::process_lead( $id9 );
}
$lead = LeadRepository::find( $id9 );
same( 'epuizat → failed', 'failed', $lead->send_status );
same( 'epuizat cod', 'retries_exhausted', $lead->last_error_code );
same( 'încercări: 1 inițială + 2 retry', 2, (int) $lead->retry_count );

// 11. Retrimitere manuală.
MessageRepository::delete( $id5 );
$result = LeadActions::resend( array( $id4, $id5, $id ), 7 );
same( 'retrimitere: puse în coadă', 1, $result['queued'] );
same( 'retrimitere: fără conținut', array( $id5 ), $result['no_content'] );
same( 'retrimitere: nu sunt failed', array( $id ), $result['not_failed'] );
$lead = LeadRepository::find( $id4 );
same( 'retrimis → pending', 'pending', $lead->send_status );
same( 'retry_count resetat', 0, (int) $lead->retry_count );
same( 'manual_resend_count', 1, (int) $lead->manual_resend_count );
same( 'last_resend_by', 7, (int) $lead->last_resend_by );
same( 'a doua retrimitere nu dublează', 0, LeadActions::resend( array( $id4 ) )['queued'] );

// Ignoră / restaurează.
same( 'ignoră', 1, LeadActions::dismiss( array( $id8 ), 7 ) );
check( 'dismissed_at setat', null !== LeadRepository::find( $id8 )->dismissed_at );
LeadActions::restore( array( $id8 ) );
same( 'restaurat', null, LeadRepository::find( $id8 )->dismissed_at );

// 12. Coada procesează doar leadurile scadente.
$GLOBALS['llt_http_queue'] = array( llt_http( 200, array( 'ErrorCode' => 0, 'MessageID' => 'pm-4' ) ) );
SendWorker::process_queue();
same( 'coada a trimis leadul retrimis', 'accepted', LeadRepository::find( $id4 )->send_status );
check( 'heartbeat', (int) State::get( 'worker_heartbeat' ) > 0 );
same( 'lock global eliberat', false, get_option( 'llt_worker_lock' ) );

// 13. Retenție (fără confirmarea livrării).
reset_settings( array( 'retention_accepted_hours' => 24, 'retention_failed_days' => 14 ) );
$wpdb->query( "UPDATE wp_location_leads SET sent_at = '2000-01-01 00:00:00' WHERE id = {$id}" );
$wpdb->query( "UPDATE wp_location_lead_messages SET created_at = '2000-01-01 00:00:00' WHERE lead_id = {$id8}" );
$dry = RetentionService::run( true );
same( 'dry-run accepted', 1, $dry['accepted'] );
same( 'dry-run failed', 1, $dry['failed'] );
check( 'dry-run nu șterge', null !== MessageRepository::find( $id ) );
RetentionService::run();
same( 'accepted vechi șters', null, MessageRepository::find( $id ) );
same( 'failed vechi șters', null, MessageRepository::find( $id8 ) );
check( 'accepted recent păstrat', null !== MessageRepository::find( $id7 ) );
check( 'failed recent păstrat', null !== MessageRepository::find( $id6 ) );
check( 'leadul minimal rămâne', null !== LeadRepository::find( $id ) );

// 14. GDPR.
$export = PersonalDataExporter::export( 'ion@example.md', 1 );
check( 'export găsește mesajele', count( $export['data'] ) > 0 && $export['done'] );
same( 'export: valoare email', 'ion@example.md', $export['data'][0]['data'][2]['value'] );
$erase = PersonalDataEraser::erase( 'ion@example.md', 1 );
check( 'ștergere GDPR', $erase['items_removed'] && $erase['done'] );
same( 'nimic rămas după ștergere', 0, count( PersonalDataExporter::export( 'ion@example.md', 1 )['data'] ) );

// 15. Statistici.
$counts = LeadRepository::counts();
same( 'total leaduri', 9, $counts['total'] );
