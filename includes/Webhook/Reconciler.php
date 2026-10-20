<?php
/**
 * Hourly reconciliation: recovers through the Messages API the delivery events missed by the webhook (ARCHITECTURE.md §13.5).
 *
 * Postmark retries a failed webhook for only ~50 minutes and pauses the event type
 * after repeated failures, so confirmations can be lost. Reconciliation recovers them.
 *
 * @package LocationLeadTracker
 */

namespace LocationLeadTracker\Webhook;

use LocationLeadTracker\Alerts\AdminNotifier;
use LocationLeadTracker\Database\LeadRepository;
use LocationLeadTracker\Database\Schema;
use LocationLeadTracker\Lead\LeadStatus;
use LocationLeadTracker\Postmark\PostmarkClient;
use LocationLeadTracker\Settings\Settings;
use LocationLeadTracker\State;
use LocationLeadTracker\Util;

defined( 'ABSPATH' ) || exit;

final class Reconciler {

	const BATCH   = 50;
	const MIN_AGE = 2 * HOUR_IN_SECONDS;
	const SILENCE = DAY_IN_SECONDS;

	/**
	 * @return array{checked:int,delivered:int,bounced:int,deferred:int,stopped:bool}
	 */
	public static function run(): array {
		$stats = array(
			'checked'   => 0,
			'delivered' => 0,
			'bounced'   => 0,
			'deferred'  => 0,
			'stopped'   => false,
		);
		if ( ! Settings::tracking_enabled() ) {
			return $stats;
		}

		$client = new PostmarkClient();
		if ( ! $client->has_token() ) {
			return $stats;
		}

		foreach ( self::candidates() as $lead ) {
			++$stats['checked'];
			$outcome = self::reconcile_lead( $client, $lead );
			if ( 'rate_limited' === $outcome ) {
				$stats['stopped'] = true;
				break;
			}
			if ( isset( $stats[ $outcome ] ) ) {
				++$stats[ $outcome ];
			}
		}

		State::set( 'last_reconcile', array_merge( $stats, array( 'at' => time() ) ) );
		self::check_webhook_silence( $stats );
		return $stats;
	}

	/**
	 * wp llt reconcile run
	 */
	public static function cli(): void {
		if ( ! Settings::tracking_enabled() ) {
			\WP_CLI::warning( 'Delivery confirmation is not enabled (Settings → Location Lead Tracker → Webhook).' );
			return;
		}
		$stats = self::run();
		\WP_CLI::success(
			sprintf(
				'Checked %d: delivered %d, bounced %d, deferred %d%s.',
				$stats['checked'],
				$stats['delivered'],
				$stats['bounced'],
				$stats['deferred'],
				$stats['stopped'] ? ' (stopped at the Postmark request limit)' : ''
			)
		);
	}

	/**
	 * Accepted leads without confirmation, older than 2 hours and newer than the retention period.
	 * Ordered by `updated_at`, so each run checks different leads.
	 *
	 * @return object[]
	 */
	private static function candidates(): array {
		global $wpdb;
		$table = Schema::leads();
		$days  = max( 1, (int) Settings::get( 'retention_unconfirmed_days' ) );
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE send_status = 'accepted' AND delivery_status IN ('unknown','deferred') AND postmark_message_id IS NOT NULL AND sent_at < %s AND sent_at > %s ORDER BY updated_at ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Util::now( -self::MIN_AGE ),
				Util::now( -$days * DAY_IN_SECONDS ),
				self::BATCH
			)
		);
	}

	/**
	 * @return string delivered | bounced | deferred | none | error | rate_limited
	 */
	private static function reconcile_lead( PostmarkClient $client, object $lead ): string {
		$details = $client->message_details( (string) $lead->postmark_message_id );
		if ( is_wp_error( $details ) ) {
			$data = $details->get_error_data();
			return ( is_array( $data ) && 429 === (int) ( $data['status'] ?? 0 ) ) ? 'rate_limited' : 'error';
		}

		$events    = is_array( $details['MessageEvents'] ?? null ) ? $details['MessageEvents'] : array();
		$delivered = self::first_event( $events, 'Delivered' );
		$bounced   = self::first_event( $events, 'Bounced' );
		$transient = self::first_event( $events, 'Transient' );
		$hint      = (int) $lead->id;
		$mid       = (string) $lead->postmark_message_id;

		if ( $delivered ) {
			$result = EventProcessor::apply( $mid, $hint, LeadStatus::DELIVERED, Util::iso_to_mysql( (string) ( $delivered['ReceivedAt'] ?? '' ) ) );
			return EventProcessor::RESULT_APPLIED === $result ? 'delivered' : 'none';
		}

		if ( $bounced ) {
			$bounce_id = (int) ( $bounced['Details']['BounceID'] ?? 0 );
			$bounce    = $bounce_id ? $client->bounce( $bounce_id ) : null;
			if ( is_wp_error( $bounce ) ) {
				$data = $bounce->get_error_data();
				return ( is_array( $data ) && 429 === (int) ( $data['status'] ?? 0 ) ) ? 'rate_limited' : 'error';
			}
			$code     = is_array( $bounce ) ? (int) ( $bounce['TypeCode'] ?? 0 ) : 1;
			$inactive = is_array( $bounce ) ? ! empty( $bounce['Inactive'] ) : true;
			$target   = EventProcessor::classify_bounce( $code, $inactive );
			if ( null === $target ) {
				self::touch( $lead );
				return 'none';
			}
			$name   = is_array( $bounce ) ? (string) ( $bounce['Type'] ?? EventProcessor::bounce_name( $code ) ) : 'Bounced';
			$result = EventProcessor::apply(
				$mid,
				$hint,
				$target,
				Util::iso_to_mysql( (string) ( $bounced['ReceivedAt'] ?? '' ) ),
				'bounce:' . $name,
				$inactive ? 'The address was deactivated in Postmark.' : (string) ( $bounced['Details']['Summary'] ?? '' )
			);
			return EventProcessor::RESULT_APPLIED === $result ? ( LeadStatus::BOUNCED === $target ? 'bounced' : 'deferred' ) : 'none';
		}

		if ( $transient && LeadStatus::UNKNOWN === $lead->delivery_status ) {
			$result = EventProcessor::apply( $mid, $hint, LeadStatus::DEFERRED, null, 'bounce:Transient', (string) ( $transient['Details']['DeliveryMessage'] ?? '' ) );
			return EventProcessor::RESULT_APPLIED === $result ? 'deferred' : 'none';
		}

		self::touch( $lead );
		return 'none';
	}

	/**
	 * @return array|null
	 */
	private static function first_event( array $events, string $type ): ?array {
		foreach ( $events as $event ) {
			if ( is_array( $event ) && ( $event['Type'] ?? '' ) === $type ) {
				return $event;
			}
		}
		return null;
	}

	/**
	 * Updates `updated_at`, so the lead moves to the end of the line for the next reconciliation.
	 */
	private static function touch( object $lead ): void {
		LeadRepository::update( (int) $lead->id, array() );
	}

	/**
	 * If reconciliation finds deliveries but no webhook has arrived for a day, the webhook seems stopped.
	 */
	private static function check_webhook_silence( array $stats ): void {
		if ( $stats['delivered'] + $stats['bounced'] === 0 ) {
			return;
		}
		$last = (array) State::get( 'last_webhook', array() );
		if ( ! empty( $last['at'] ) && time() - (int) $last['at'] < self::SILENCE ) {
			return;
		}
		AdminNotifier::webhook_silent( $stats['delivered'] + $stats['bounced'] );
	}
}
