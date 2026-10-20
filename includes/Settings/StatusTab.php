<?php
/**
 * The "Status" tab: cron, leads per status, alerts (read-only).
 *
 * @package EnspireEmailLeadsTracker
 */

namespace EnspireEmailLeadsTracker\Settings;

use EnspireEmailLeadsTracker\Database\LeadRepository;
use EnspireEmailLeadsTracker\Http\ProxyRanges;
use EnspireEmailLeadsTracker\Postmark\ErrorClassifier;
use EnspireEmailLeadsTracker\State;

defined( 'ABSPATH' ) || exit;

final class StatusTab {

	public static function render(): void {
		$state     = State::all();
		$counts    = LeadRepository::counts();
		$heartbeat = (int) ( $state['worker_heartbeat'] ?? 0 );
		$stale     = ! $heartbeat || time() - $heartbeat > 15 * MINUTE_IN_SECONDS;

		$rows = array(
			'Last queue run'        => $heartbeat ? self::ago( $heartbeat ) : 'never',
			'WordPress cron'        => ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ? 'disabled (the server cron is used)' : 'enabled (recommended: a real server cron, see the documentation)',
			'Postmark token'        => Settings::has_secret( 'postmark_token' ) ? 'configured' : 'missing',
			'Leads in the queue'    => (string) $counts['send']['pending'],
			'Accepted leads'        => (string) $counts['send']['accepted'],
			'Failed leads'          => (string) $counts['send']['failed'],
			'Consecutive failures'  => (string) (int) ( $state['consecutive_failures'] ?? 0 ),
			'Last error'            => ! empty( $state['last_error']['code'] )
				? ErrorClassifier::describe( (string) $state['last_error']['code'] ) . ' (' . $state['last_error']['code'] . ', ' . self::ago( (int) ( $state['last_error']['at'] ?? 0 ) ) . ')'
				: '—',
			'Last alert sent'       => ! empty( $state['last_alert_sent']['at'] )
				? self::ago( (int) $state['last_alert_sent']['at'] ) . ': ' . $state['last_alert_sent']['subject']
				: '—',
			'Last failed alert'     => ! empty( $state['last_alert_failure']['at'] )
				? self::ago( (int) $state['last_alert_failure']['at'] ) . ': ' . $state['last_alert_failure']['error']
				: '—',
			'Cloudflare IP list'    => ProxyRanges::updated_at() ? 'updated ' . self::ago( ProxyRanges::updated_at() ) : 'the list bundled with the plugin',
		);
		$rows = (array) apply_filters( 'llt_status_rows', $rows, $state, $counts );

		if ( $stale ) {
			echo '<div class="notice notice-warning inline"><p>The queue has not run in the last 15 minutes. Check that the server cron calls WordPress every minute.</p></div>';
		}
		echo '<table class="widefat striped llt-status"><tbody>';
		foreach ( $rows as $label => $value ) {
			printf( '<tr><th scope="row">%s</th><td>%s</td></tr>', esc_html( $label ), esc_html( (string) $value ) );
		}
		echo '</tbody></table>';
		echo '<p class="description">Useful commands: <code>wp llt status</code>, <code>wp llt leads list --status=failed</code>, <code>wp llt retry --failed</code>.</p>';
	}

	public static function ago( int $timestamp ): string {
		if ( $timestamp <= 0 ) {
			return '—';
		}
		return sprintf( '%s ago', human_time_diff( $timestamp, time() ) );
	}
}
