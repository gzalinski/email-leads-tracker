<?php
/**
 * Plugin Name:       Location Lead Tracker
 * Description:       Formular de contact pentru locații: trimitere prin Postmark, evidența leadurilor, retry automat, alerte și retenție controlată a datelor personale.
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       location-lead-tracker
 *
 * @package LocationLeadTracker
 */

defined( 'ABSPATH' ) || exit;

define( 'LLT_VERSION', '1.2.0' );
define( 'LLT_DB_VERSION', '1' );
define( 'LLT_FILE', __FILE__ );
define( 'LLT_DIR', plugin_dir_path( __FILE__ ) );
define( 'LLT_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'LocationLeadTracker\\';
		if ( 0 !== strncmp( $class, $prefix, strlen( $prefix ) ) ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$file     = LLT_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

add_filter( 'cron_schedules', array( \LocationLeadTracker\Installer::class, 'cron_schedules' ) );
register_activation_hook( __FILE__, array( \LocationLeadTracker\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \LocationLeadTracker\Installer::class, 'deactivate' ) );
add_action( 'plugins_loaded', array( \LocationLeadTracker\Plugin::class, 'boot' ) );
