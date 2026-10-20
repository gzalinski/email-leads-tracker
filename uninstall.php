<?php
/**
 * Uninstall: the data is deleted only if "Delete the tables and settings when the plugin is uninstalled" is checked.
 *
 * @package EmailLeadsTracker
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'llt_send_lead', 'llt_process_queue', 'llt_process_queue_now', 'llt_health_check', 'llt_retention_cleanup', 'llt_refresh_proxy_ranges', 'llt_reconcile_delivery' ) as $llt_hook ) {
	wp_unschedule_hook( $llt_hook );
}

$llt_settings = get_option( 'llt_settings' );
if ( ! is_array( $llt_settings ) || empty( $llt_settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}location_lead_messages" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}location_leads" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

foreach ( array( 'llt_settings', 'llt_db_version', 'llt_state', 'llt_proxy_ranges', 'llt_worker_lock' ) as $llt_option ) {
	delete_option( $llt_option );
}

$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_llt\\_%' OR option_name LIKE '\\_transient\\_timeout\\_llt\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
