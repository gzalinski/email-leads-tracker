<?php
/**
 * Înregistrează toate componentele pluginului.
 *
 * @package LocationLeadTracker
 */

namespace LocationLeadTracker;

use LocationLeadTracker\Alerts\AdminNotifier;
use LocationLeadTracker\Alerts\HealthCheck;
use LocationLeadTracker\Cli\Commands;
use LocationLeadTracker\Form\SubmissionHandler;
use LocationLeadTracker\Http\ProxyRanges;
use LocationLeadTracker\Privacy\PersonalDataEraser;
use LocationLeadTracker\Privacy\PersonalDataExporter;
use LocationLeadTracker\Privacy\PolicyContent;
use LocationLeadTracker\Queue\SendWorker;
use LocationLeadTracker\Retention\RetentionService;
use LocationLeadTracker\Settings\SettingsPage;
use LocationLeadTracker\Shortcode\ContactShortcode;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/**
	 * Punctul de pornire, apelat pe `plugins_loaded`.
	 */
	public static function boot(): void {
		Installer::maybe_upgrade();

		// Front-end.
		add_action( 'init', array( ContactShortcode::class, 'register' ) );
		add_action( 'admin_post_llt_submit', array( SubmissionHandler::class, 'handle' ) );
		add_action( 'admin_post_nopriv_llt_submit', array( SubmissionHandler::class, 'handle' ) );

		// Cron.
		add_action( 'llt_send_lead', array( SendWorker::class, 'process_lead' ) );
		add_action( 'llt_process_queue', array( SendWorker::class, 'process_queue' ) );
		add_action( 'llt_process_queue_now', array( SendWorker::class, 'process_queue' ) );
		add_action( 'llt_health_check', array( HealthCheck::class, 'run' ) );
		add_action( 'llt_retention_cleanup', array( RetentionService::class, 'run' ) );
		add_action( 'llt_refresh_proxy_ranges', array( ProxyRanges::class, 'refresh' ) );

		// Alerte.
		add_action( 'wp_mail_failed', array( AdminNotifier::class, 'capture_mail_error' ) );

		// GDPR.
		add_filter( 'wp_privacy_personal_data_exporters', array( PersonalDataExporter::class, 'register' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( PersonalDataEraser::class, 'register' ) );
		add_action( 'admin_init', array( PolicyContent::class, 'register' ) );

		// Admin.
		if ( is_admin() ) {
			SettingsPage::register();
		}

		// WP-CLI.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Commands::register();
		}
	}
}
