<?php
/**
 * Activation, deactivation, migrations and cron scheduling.
 *
 * @package EnspireEmailLeadsTracker
 */

namespace EnspireEmailLeadsTracker;

use EnspireEmailLeadsTracker\Database\LegacyMigration;
use EnspireEmailLeadsTracker\Database\Schema;
use EnspireEmailLeadsTracker\Settings\Settings;

defined( 'ABSPATH' ) || exit;

final class Installer {

	/**
	 * Recurring events: hook => recurrence.
	 *
	 * @return array<string,string>
	 */
	public static function recurring_events(): array {
		return array(
			'eelt_process_queue'        => 'eelt_every_minute',
			'eelt_health_check'         => 'hourly',
			'eelt_retention_cleanup'    => 'daily',
			'eelt_refresh_proxy_ranges' => 'weekly',
			'eelt_reconcile_delivery'   => 'hourly',
		);
	}

	/**
	 * All cron hooks used by the plugin (including single events).
	 *
	 * @return string[]
	 */
	public static function all_hooks(): array {
		return array_merge( array_keys( self::recurring_events() ), array( 'eelt_send_lead', 'eelt_process_queue_now' ) );
	}

	/**
	 * Adds the one-minute interval.
	 *
	 * @param array $schedules Existing intervals.
	 */
	public static function cron_schedules( $schedules ): array {
		$schedules = is_array( $schedules ) ? $schedules : array();
		if ( ! isset( $schedules['eelt_every_minute'] ) ) {
			$schedules['eelt_every_minute'] = array(
				'interval' => MINUTE_IN_SECONDS,
				'display'  => 'Enspire Email Leads Tracker: every minute',
			);
		}
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => WEEK_IN_SECONDS,
				'display'  => 'Once weekly',
			);
		}
		return $schedules;
	}

	public static function activate(): void {
		LegacyMigration::run();
		Schema::install();
		update_option( 'eelt_db_version', EELT_DB_VERSION );
		add_option( Settings::OPTION, array(), '', false );
		add_option( 'eelt_state', array(), '', false );
		self::schedule_events();
	}

	public static function deactivate(): void {
		foreach ( self::all_hooks() as $hook ) {
			wp_unschedule_hook( $hook );
		}
		delete_option( 'eelt_worker_lock' );
	}

	/**
	 * Runs migrations when the schema version changed and restores missing cron events.
	 */
	public static function maybe_upgrade(): void {
		LegacyMigration::run();
		if ( get_option( 'eelt_db_version' ) !== EELT_DB_VERSION ) {
			Schema::install();
			update_option( 'eelt_db_version', EELT_DB_VERSION );
		}
		self::schedule_events();
	}

	private static function schedule_events(): void {
		foreach ( self::recurring_events() as $hook => $recurrence ) {
			if ( ! wp_next_scheduled( $hook ) ) {
				wp_schedule_event( time() + MINUTE_IN_SECONDS, $recurrence, $hook );
			}
		}
	}
}
