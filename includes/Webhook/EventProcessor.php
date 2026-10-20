<?php
/**
 * Aplică evenimentele de livrare Postmark (Delivery / Bounce) pe leaduri (ARCHITECTURE.md §13).
 *
 * Folosit de webhook și de reconciliere, deci regulile sunt aceleași în ambele cazuri:
 * - nu se creează leaduri noi;
 * - `delivered` și `bounced` sunt finale (regula de monotonie);
 * - la `delivered`, conținutul temporar se șterge imediat.
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

	/** TypeCode-uri definitive (Bounce API → Bounce types). */
	const FINAL_BOUNCES = array(
		1      => 'HardBounce',
		100000 => 'BadEmailAddress',
		100002 => 'ManuallyDeactivated',
		100006 => 'Blocked',
		100007 => 'SMTPApiError',
		100009 => 'DMARCPolicy',
	);

	/** TypeCode-uri temporare: Postmark poate încă livra. */
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
	 * Procesează payload-ul unui webhook Postmark.
	 *
	 * @return string Unul dintre RESULT_*.
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
				! empty( $payload['Inactive'] ) ? 'Adresa a fost dezactivată în Postmark.' : (string) ( $payload['Description'] ?? '' )
			);
		}

		return self::RESULT_IGNORED;
	}

	/**
	 * Starea de livrare pentru un bounce, sau null dacă evenimentul se ignoră.
	 * Dacă Postmark a dezactivat adresa (Inactive), rezultatul este definitiv indiferent de tip.
	 */
	public static function classify_bounce( int $type_code, bool $inactive ): ?string {
		if ( $inactive || isset( self::FINAL_BOUNCES[ $type_code ] ) ) {
			return LeadStatus::BOUNCED;
		}
		if ( isset( self::TEMPORARY_BOUNCES[ $type_code ] ) ) {
			return LeadStatus::DEFERRED;
		}
		// AutoResponder, SpamNotification (mesajul a fost livrat), Unsubscribe etc.
		return null;
	}

	public static function bounce_name( int $type_code ): string {
		return self::FINAL_BOUNCES[ $type_code ] ?? ( self::TEMPORARY_BOUNCES[ $type_code ] ?? (string) $type_code );
	}

	/**
	 * Aplică o stare de livrare, respectând regula de monotonie.
	 *
	 * @param string      $message_id MessageID Postmark.
	 * @param int         $lead_hint  ID-ul leadului din Metadata (corelare când MessageID nu este încă salvat).
	 * @param string      $target     delivered | bounced | deferred.
	 * @param string|null $at         Momentul evenimentului (UTC, MySQL).
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

		// Evenimentul confirmă că Postmark a acceptat mesajul, chiar dacă răspunsul API nu a fost salvat (timeout, webhook sosit primul).
		$set[] = "send_status = 'accepted'";
		$set[] = 'next_retry_at = NULL';
		$set[] = 'sent_at = COALESCE(sent_at, %s)';
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
	 * Corelarea: întâi după MessageID, apoi după `llt_lead_id` din Metadata.
	 * Corelarea după Metadata se acceptă doar dacă leadul nu are deja alt MessageID.
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
