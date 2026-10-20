<?php
/**
 * Applies Postmark delivery events (Delivery / Bounce) to leads (ARCHITECTURE.md §13).
 *
 * Used by both the webhook and the reconciliation, so the rules are the same in both cases:
 * - no new leads are created;
 * - `delivered` and `bounced` are final (monotonic rule);
 * - on `delivered`, the temporary content is deleted immediately.
 *
 * @package LocationLeadTracker
 */

namespace LocationLeadTracker\Webhook;

use LocationLeadTracker\Alerts\AdminNotifier;
use LocationLeadTracker\Database\LeadRepository;
use LocationLeadTracker\Database\MessageRepository;
use LocationLeadTracker\Database\Schema;
use LocationLeadTracker\Lead\LeadStatus;
use LocationLeadTracker\Util;

defined( 'ABSPATH' ) || exit;

final class EventProcessor {

	/** Permanent TypeCodes (Bounce API → Bounce types). */
	const FINAL_BOUNCES = array(
		1      => 'HardBounce',
		100000 => 'BadEmailAddress',
		100002 => 'ManuallyDeactivated',
		100006 => 'Blocked',
		100007 => 'SMTPApiError',
		100009 => 'DMARCPolicy',
	);

	/** Temporary TypeCodes: Postmark may still deliver. */
	const TEMPORARY_BOUNCES = array(
		2    => 'Transient',
		256  => 'DnsError',
		2048 => 'Unknown',
		4096 => 'SoftBounce',
	);

	const RESULT_APPLIED      = 'applied';
	const RESULT_DUPLICATE    = 'duplicate';
	const RESULT_UNCORRELATED = 'uncorrelated';
	const RESULT_IGNORED      = 'ignored';

	/**
	 * Processes the payload of a Postmark webhook.
	 *
	 * @return string One of RESULT_*.
	 */
	public static function handle( array $payload ): string {
		$type       = (string) ( $payload['RecordType'] ?? '' );
		$message_id = (string) ( $payload['MessageID'] ?? '' );
		$lead_hint  = (int) ( $payload['Metadata']['llt_lead_id'] ?? 0 );

		if ( 'Delivery' === $type ) {
			return self::apply(
				$message_id,
				$lead_hint,
				LeadStatus::DELIVERED,
				Util::iso_to_mysql( (string) ( $payload['DeliveredAt'] ?? '' ) )
			);
		}

		if ( 'Bounce' === $type ) {
			$code   = (int) ( $payload['TypeCode'] ?? 0 );
			$name   = (string) ( $payload['Type'] ?? '' );
			$target = self::classify_bounce( $code, ! empty( $payload['Inactive'] ) );
			if ( null === $target ) {
				return self::RESULT_IGNORED;
			}
			return self::apply(
				$message_id,
				$lead_hint,
				$target,
				Util::iso_to_mysql( (string) ( $payload['BouncedAt'] ?? '' ) ),
				'bounce:' . ( '' !== $name ? $name : (string) $code ),
				! empty( $payload['Inactive'] ) ? 'The address was deactivated in Postmark.' : (string) ( $payload['Description'] ?? '' )
			);
		}

		return self::RESULT_IGNORED;
	}

	/**
	 * The delivery status for a bounce, or null if the event is ignored.
	 * If Postmark deactivated the address (Inactive), the result is final regardless of the type.
	 */
	public static function classify_bounce( int $type_code, bool $inactive ): ?string {
		if ( $inactive || isset( self::FINAL_BOUNCES[ $type_code ] ) ) {
			return LeadStatus::BOUNCED;
		}
		if ( isset( self::TEMPORARY_BOUNCES[ $type_code ] ) ) {
			return LeadStatus::DEFERRED;
		}
		// AutoResponder, SpamNotification (the message was delivered), Unsubscribe, etc.
		return null;
	}

	public static function bounce_name( int $type_code ): string {
		return self::FINAL_BOUNCES[ $type_code ] ?? ( self::TEMPORARY_BOUNCES[ $type_code ] ?? (string) $type_code );
	}

	/**
	 * Applies a delivery status, following the monotonic rule.
	 *
	 * @param string      $message_id Postmark MessageID.
	 * @param int         $lead_hint  Lead ID from Metadata (correlation when the MessageID is not saved yet).
	 * @param string      $target     delivered | bounced | deferred.
	 * @param string|null $at         Time of the event (UTC, MySQL).
	 */
	public static function apply( string $message_id, int $lead_hint, string $target, ?string $at, string $code = '', string $message = '' ): string {
		global $wpdb;

		$lead = self::find_lead( $message_id, $lead_hint );
		if ( ! $lead ) {
			return self::RESULT_UNCORRELATED;
		}

		$sources = LeadStatus::delivery_sources( $target );
		if ( ! $sources ) {
			return self::RESULT_IGNORED;
		}

		$at     = $at ?: Util::now();
		$table  = Schema::leads();
		$set    = array( 'delivery_status = %s', 'updated_at = %s' );
		$params = array( $target, Util::now() );

		// The event confirms that Postmark accepted the message, even if the API response was not saved (timeout, webhook arrived first).
		$set[]    = "send_status = 'accepted'";
		$set[]    = 'next_retry_at = NULL';
		$set[]    = 'sent_at = COALESCE(sent_at, %s)';
		$params[] = $at;

		if ( '' !== $message_id ) {
			$set[]    = 'postmark_message_id = COALESCE(postmark_message_id, %s)';
			$params[] = $message_id;
		}
		if ( LeadStatus::DELIVERED === $target ) {
			$set[]    = 'delivered_at = %s';
			$params[] = $at;
			$set[]    = 'last_error_code = NULL';
			$set[]    = 'last_error_message = NULL';
		} else {
			$set[]    = 'last_error_code = %s';
			$params[] = substr( $code, 0, 40 );
			$set[]    = 'last_error_message = %s';
			$params[] = Util::error_text( $message );
		}

		$params[] = (int) $lead->id;
		$in       = "'" . implode( "','", array_map( 'esc_sql', $sources ) ) . "'";
		$sql      = "UPDATE {$table} SET " . implode( ', ', $set ) . " WHERE id = %d AND delivery_status IN ({$in})";
		$updated  = (int) $wpdb->query( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( 1 !== $updated ) {
			return self::RESULT_DUPLICATE;
		}

		if ( LeadStatus::DELIVERED === $target ) {
			MessageRepository::delete( (int) $lead->id );
		} elseif ( LeadStatus::BOUNCED === $target ) {
			AdminNotifier::bounce_recorded( (int) $lead->id, $code );
		}

		do_action( 'llt_leads_changed' );
		do_action( 'llt_delivery_status_changed', (int) $lead->id, $target );
		return self::RESULT_APPLIED;
	}

	/**
	 * Correlation: first by MessageID, then by `llt_lead_id` from Metadata.
	 * Correlation by Metadata is accepted only if the lead does not already have a different MessageID.
	 */
	private static function find_lead( string $message_id, int $lead_hint ): ?object {
		if ( '' !== $message_id ) {
			$lead = LeadRepository::find_by_message_id( $message_id );
			if ( $lead ) {
				return $lead;
			}
		}
		if ( $lead_hint > 0 ) {
			$lead = LeadRepository::find( $lead_hint );
			if ( $lead && ( empty( $lead->postmark_message_id ) || $lead->postmark_message_id === $message_id ) ) {
				return $lead;
			}
		}
		return null;
	}
}
