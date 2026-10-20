<?php
/**
 * Verificare orară: leaduri blocate în coadă.
 *
 * @package LocationLeadTracker
 */

namespace LocationLeadTracker\Alerts;

use LocationLeadTracker\Database\LeadRepository;
use LocationLeadTracker\Util;

defined( 'ABSPATH' ) || exit;

final class HealthCheck {

	const STUCK_AFTER = HOUR_IN_SECONDS;

	public static function run(): void {
		$stuck = LeadRepository::count_stuck( Util::now( -self::STUCK_AFTER ) );
		if ( $stuck > 0 ) {
			AdminNotifier::stuck_leads( $stuck );
		}
		do_action( 'llt_health_check_run' );
	}
}
