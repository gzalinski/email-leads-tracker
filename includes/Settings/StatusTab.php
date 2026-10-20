<?php
/**
 * Tabul „Stare”: cron, leaduri pe stări, alerte (doar citire).
 *
 * @package LocationLeadTracker
 */

namespace LocationLeadTracker\Settings;

use LocationLeadTracker\Database\LeadRepository;
use LocationLeadTracker\Http\ProxyRanges;
use LocationLeadTracker\Postmark\ErrorClassifier;
use LocationLeadTracker\State;

defined( 'ABSPATH' ) || exit;

final class StatusTab {

	public static function render(): void {
		$state     = State::all();
		$counts    = LeadRepository::counts();
		$heartbeat = (int) ( $state['worker_heartbeat'] ?? 0 );
		$stale     = ! $heartbeat || time() - $heartbeat > 15 * MINUTE_IN_SECONDS;

		$rows = array(
			'Ultima rulare a cozii'   => $heartbeat ? self::ago( $heartbeat ) : 'niciodată',
			'Cron WordPress'          => ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ? 'dezactivat (se folosește cronul serverului)' : 'activ (recomandat: cron real de server, vezi documentația)',
			'Token Postmark'          => Settings::has_secret( 'postmark_token' ) ? 'configurat' : 'lipsește',
			'Leaduri în coadă'        => (string) $counts['send']['pending'],
			'Leaduri acceptate'       => (string) $counts['send']['accepted'],
			'Leaduri eșuate'          => (string) $counts['send']['failed'],
			'Eșecuri consecutive'     => (string) (int) ( $state['consecutive_failures'] ?? 0 ),
			'Ultima eroare'           => ! empty( $state['last_error']['code'] )
				? ErrorClassifier::describe( (string) $state['last_error']['code'] ) . ' (' . $state['last_error']['code'] . ', ' . self::ago( (int) ( $state['last_error']['at'] ?? 0 ) ) . ')'
				: '—',
			'Ultima alertă trimisă'   => ! empty( $state['last_alert_sent']['at'] )
				? self::ago( (int) $state['last_alert_sent']['at'] ) . ': ' . $state['last_alert_sent']['subject']
				: '—',
			'Ultima alertă eșuată'    => ! empty( $state['last_alert_failure']['at'] )
				? self::ago( (int) $state['last_alert_failure']['at'] ) . ': ' . $state['last_alert_failure']['error']
				: '—',
			'Lista IP-uri Cloudflare' => ProxyRanges::updated_at() ? 'actualizată ' . self::ago( ProxyRanges::updated_at() ) : 'lista inclusă în plugin',
		);
		$rows = (array) apply_filters( 'llt_status_rows', $rows, $state, $counts );

		if ( $stale ) {
			echo '<div class="notice notice-warning inline"><p>Coada nu a rulat în ultimele 15 minute. Verifică dacă cronul serverului apelează WordPress la fiecare minut.</p></div>';
		}
		echo '<table class="widefat striped llt-status"><tbody>';
		foreach ( $rows as $label => $value ) {
			printf( '<tr><th scope="row">%s</th><td>%s</td></tr>', esc_html( $label ), esc_html( (string) $value ) );
		}
		echo '</tbody></table>';
		echo '<p class="description">Comenzi utile: <code>wp llt status</code>, <code>wp llt leads list --status=failed</code>, <code>wp llt retry --failed</code>.</p>';
	}

	public static function ago( int $timestamp ): string {
		if ( $timestamp <= 0 ) {
			return '—';
		}
		return sprintf( 'acum %s', human_time_diff( $timestamp, time() ) );
	}
}
