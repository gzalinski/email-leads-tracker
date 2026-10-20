<?php
/**
 * Activation, deactivation, migrations and cron scheduling.
 *
 * @package LocationLeadTracker
 */

namespace LocationLeadTracker;

use LocationLeadTracker\Database\Schema;
use LocationLeadTracker\Settings\Settings;

defined( 'ABSPATH' ) || exit;

final class Installer {

	/**
	 * Recurring events: hook => recurrence.
	 *
	 * @return array<string,string>
	 */
	public static function recurring_events(): array {
		return array(
			'llt_process_queue'        => 'llt_every_minute',
			'llt_health_check'         => 'hourly',
			'llt_retention_cleanup'    => 'daily',
			'llt_refresh_proxy_ranges' => 'weekly',
			'llt_reconcile_delivery'   => 'hourly',
		);
	}

	/**
	 * All cron hooks used by the plugin (including single events).
	 *
	 * @return string[]
	 */
	public static function all_hooks(): array {
		return array_merge( array_keys( self::recurring_events() ), array( 'llt_send_lead', 'llt_process_queue_now' ) );
	}

	/**
	 * Adds the one-minute interval.
	 *
	 * @param array $schedules Existing intervals.
	 */
	public static function cron_schedules( $schedules ): array {
		$schedules = is_array( $schedules ) ? $schedules : array();
		if ( ! isset( $schedules['llt_every_minute'] ) ) {
			$schedules['llt_every_minute'] = array(
				'interval' => MINUTE_IN_SECONDS,
				'display'  => 'Location Lead Tracker: every minute',
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
		Schema::install();
		update_option( 'llt_db_version', LLT_DB_VERSION );
		add_option( Settings::OPTION, array(), '', false );
		add_option( 'llt_state', array(), '', false );
		self::schedule_events();
	}

	public static function deactivate(): void {
		foreach ( self::all_hooks() as $hook ) {
			wp_unschedule_hook( $hook );
		}
		delete_option( 'llt_worker_lock' );
	}

	/**
	 * Runs migrations when the schema version changed and restores missing cron events.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( 'llt_db_version' ) !== LLT_DB_VERSION ) {
			Schema::install();
			update_option( 'llt_db_version', LLT_DB_VERSION );
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
