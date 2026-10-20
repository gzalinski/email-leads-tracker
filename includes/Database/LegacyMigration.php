<?php
/**
 * One-time migration from the identifiers used before 1.5.0
 * (prefix `llt`, tables `location_leads` / `location_lead_messages`).
 *
 * Runs on activation and on the first load after an update, before the schema check.
 * It renames the tables (data, IDs and history are kept), moves the options to the
 * new names and removes the old cron events. Encrypted secrets stay readable, because
 * the encryption key derivation did not change.
 *
 * @package EnspireEmailLeadsTracker
 */

namespace EnspireEmailLeadsTracker\Database;

use EnspireEmailLeadsTracker\Settings\Settings;
use EnspireEmailLeadsTracker\Util;

defined( 'ABSPATH' ) || exit;

final class LegacyMigration {

	/** Option name → new option name. */
	const OPTIONS = array(
		'llt_settings'     => 'eelt_settings',
		'llt_state'        => 'eelt_state',
		'llt_proxy_ranges' => 'eelt_proxy_ranges',
	);

	/** Old cron hooks. */
	const HOOKS = array(
		'llt_send_lead',
		'llt_process_queue',
		'llt_process_queue_now',
		'llt_health_check',
		'llt_retention_cleanup',
		'llt_refresh_proxy_ranges',
		'llt_reconcile_delivery',
	);

	/**
	 * True when data from a version before 1.5.0 is present.
	 */
	public static function needed(): bool {
		return false !== get_option( 'llt_db_version' );
	}

	/**
	 * Runs the migration if needed.
	 *
	 * @return bool Whether a migration ran.
	 */
	public static function run(): bool {
		if ( ! self::needed() ) {
			return false;
		}
		global $wpdb;

		$tables = array(
			$wpdb->prefix . 'location_leads'         => Schema::leads(),
			$wpdb->prefix . 'location_lead_messages' => Schema::messages(),
		);
		foreach ( $tables as $old => $new ) {
			if ( ! self::table_exists( $old ) ) {
				continue;
			}
			if ( self::table_exists( $new ) ) {
				if ( self::row_count( $new ) > 0 ) {
					// Both exist and the new one already has data: leave the old one for manual review.
					Util::log( sprintf( 'Legacy migration: both %s and %s exist; the old table was left untouched.', $old, $new ) );
					continue;
				}
				$wpdb->query( "DROP TABLE `{$new}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			$wpdb->query( "ALTER TABLE `{$old}` RENAME TO `{$new}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		foreach ( self::OPTIONS as $old => $new ) {
			$value = get_option( $old, null );
			if ( null === $value ) {
				continue;
			}
			if ( false === get_option( $new ) || array() === get_option( $new ) ) {
				update_option( $new, $value, false );
			}
			delete_option( $old );
		}
		Settings::flush();
		update_option( 'eelt_db_version', (string) get_option( 'llt_db_version' ) );
		delete_option( 'llt_db_version' );
		delete_option( 'llt_worker_lock' );

		foreach ( self::HOOKS as $hook ) {
			wp_unschedule_hook( $hook );
		}

		// The settings page shows a notice for 30 days: the shortcode and the webhook path changed.
		$state                    = (array) get_option( 'eelt_state', array() );
		$state['legacy_migrated'] = time();
		update_option( 'eelt_state', $state, false );

		Util::log( 'Legacy migration from the llt_ identifiers completed.' );
		return true;
	}

	private static function table_exists( string $table ): bool {
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $found === $table;
	}

	private static function row_count( string $table ): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
