<?php
/**
 * Uninstall: the data is deleted only if "Delete the tables and settings when the plugin is uninstalled" is checked.
 *
 * @package EnspireEmailLeadsTracker
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'eelt_send_lead', 'eelt_process_queue', 'eelt_process_queue_now', 'eelt_health_check', 'eelt_retention_cleanup', 'eelt_refresh_proxy_ranges', 'eelt_reconcile_delivery' ) as $eelt_hook ) {
	wp_unschedule_hook( $eelt_hook );
}

$eelt_settings = get_option( 'eelt_settings' );
if ( ! is_array( $eelt_settings ) || empty( $eelt_settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}eelt_lead_messages" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}eelt_leads" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

foreach ( array( 'eelt_settings', 'eelt_db_version', 'eelt_state', 'eelt_proxy_ranges', 'eelt_worker_lock' ) as $eelt_option ) {
	delete_option( $eelt_option );
}

$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_eelt\\_%' OR option_name LIKE '\\_transient\\_timeout\\_eelt\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
