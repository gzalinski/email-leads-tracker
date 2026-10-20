<?php
/**
 * Migration from the pre-1.5.0 identifiers (llt_ prefix, location_* tables).
 */
// phpcs:ignoreFile
require_once dirname( __DIR__ ) . '/wpdb-sqlite.php';

use EnspireEmailLeadsTracker\Database\LeadRepository;
use EnspireEmailLeadsTracker\Database\LegacyMigration;
use EnspireEmailLeadsTracker\Database\MessageRepository;
use EnspireEmailLeadsTracker\Lead\LeadService;
use EnspireEmailLeadsTracker\Settings\SecretStore;
use EnspireEmailLeadsTracker\Settings\Settings;
use EnspireEmailLeadsTracker\State;

global $wpdb;
$wpdb = new EELT_Test_WPDB();
$GLOBALS['eelt_test_options'] = array( 'admin_email' => 'admin@example.com' );

// Nothing to migrate on a fresh install.
same( 'fresh install: not needed', false, LegacyMigration::needed() );
same( 'fresh install: does not run', false, LegacyMigration::run() );

// Data written by an older version: one lead with content, in the new tables, then renamed to the old names.
$id = LeadService::create( array( 'name' => 'John', 'email' => 'john@example.com', 'phone' => '', 'message' => 'Hello' ), 245, 'downtown@example.com' );
$wpdb->query( 'ALTER TABLE wp_eelt_leads RENAME TO wp_location_leads' );
$wpdb->query( 'ALTER TABLE wp_eelt_lead_messages RENAME TO wp_location_lead_messages' );
// Activation of the new version may already have created empty new tables.
$wpdb->query( 'CREATE TABLE wp_eelt_leads (id INTEGER PRIMARY KEY)' );

$GLOBALS['eelt_test_options'] = array(
	'admin_email'      => 'admin@example.com',
	'llt_db_version'   => '1',
	'llt_settings'     => array( 'from_email' => 'noreply@example.com', 'postmark_token' => SecretStore::encrypt( 'secret-token' ) ),
	'llt_state'        => array( 'consecutive_failures' => 2 ),
	'llt_proxy_ranges' => array( 'v4' => array( '1.2.3.0/24' ) ),
	'llt_worker_lock'  => 123,
	'eelt_settings'    => array(),
);

same( 'legacy data: needed', true, LegacyMigration::needed() );
same( 'legacy data: runs', true, LegacyMigration::run() );

$lead = LeadRepository::find( $id );
check( 'lead kept in the renamed table', null !== $lead );
same( 'lead recipient kept', 'downtown@example.com', $lead->recipient_email );
same( 'message kept', 'Hello', MessageRepository::find( $id )->message );
same( 'old table gone', null, $wpdb->get_var( "SHOW TABLES LIKE 'wp\\_location\\_leads'" ) );

same( 'settings moved', 'noreply@example.com', Settings::get( 'from_email' ) );
same( 'secret still decrypts', 'secret-token', SecretStore::decrypt( Settings::get( 'postmark_token' ) ) );
same( 'state moved', 2, (int) State::get( 'consecutive_failures' ) );
check( 'migration time recorded', (int) State::get( 'legacy_migrated' ) > 0 );
same( 'proxy ranges moved', array( 'v4' => array( '1.2.3.0/24' ) ), get_option( 'eelt_proxy_ranges' ) );
same( 'db version moved', '1', get_option( 'eelt_db_version' ) );
foreach ( array( 'llt_db_version', 'llt_settings', 'llt_state', 'llt_proxy_ranges', 'llt_worker_lock' ) as $old ) {
	same( "old option removed: {$old}", false, get_option( $old ) );
}
same( 'second run does nothing', false, LegacyMigration::run() );
check( 'old cron hooks unscheduled', in_array( 'llt_process_queue', (array) ( $GLOBALS['eelt_test_unscheduled'] ?? array() ), true ) );
