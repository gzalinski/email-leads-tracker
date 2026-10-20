<?php
/**
 * Sends leads: right after the submission and periodically from the queue (ARCHITECTURE.md §7, §9).
 *
 * @package EmailLeadsTracker
 */

namespace EmailLeadsTracker\Queue;

use EmailLeadsTracker\Alerts\AdminNotifier;
use EmailLeadsTracker\Database\LeadRepository;
use EmailLeadsTracker\Database\MessageRepository;
use EmailLeadsTracker\Lead\LeadStatus;
use EmailLeadsTracker\Postmark\EmailBuilder;
use EmailLeadsTracker\Postmark\ErrorClassifier;
use EmailLeadsTracker\Postmark\PostmarkClient;
use EmailLeadsTracker\Postmark\SendResult;
use EmailLeadsTracker\Settings\Settings;
use EmailLeadsTracker\State;
use EmailLeadsTracker\Util;

defined( 'ABSPATH' ) || exit;

final class SendWorker {

	const BATCH = 20;

	/**
	 * Processes due leads. Run by cron every minute.
	 */
	public static function process_queue(): void {
		State::set( 'worker_heartbeat', time() );
		if ( ! Lock::acquire_worker() ) {
			return;
		}
		try {
			foreach ( LeadRepository::due_ids( self::BATCH ) as $lead_id ) {
				self::process_lead( $lead_id );
			}
		} finally {
			Lock::release_worker();
		}
	}

	/**
	 * Sends a lead. Does nothing if the lead is not due or is being processed by another worker.
	 *
	 * @param int|string $lead_id Lead ID.
	 */
	public static function process_lead( $lead_id ): void {
		$lead_id = (int) $lead_id;
		$token   = Lock::claim( $lead_id );
		if ( null === $token ) {
			return;
		}

		try {
			$lead = LeadRepository::find( $lead_id );
			if ( ! $lead ) {
				return;
			}
			$client = new PostmarkClient();

			// Previous ambiguous timeout: check in Postmark before resending (§7.4).
			if ( 'ambiguous_timeout' === $lead->last_error_code && $client->has_token() ) {
				$found = $client->find_by_lead( $lead_id );
				if ( is_wp_error( $found ) ) {
					self::schedule_retry( $lead, SendResult::failure( SendResult::AMBIGUOUS, 'ambiguous_timeout', $found->get_error_message() ) );
					return;
				}
				if ( $found['found'] ) {
					self::mark_accepted( $lead, $found['message_id'] );
					return;
				}
			}

			$message = MessageRepository::find( $lead_id );
			if ( ! $message ) {
				self::mark_failed( $lead, SendResult::failure( SendResult::PERMANENT, 'content_missing', ErrorClassifier::describe( 'content_missing' ) ) );
				return;
			}

			$result = $client->send( EmailBuilder::build( $lead, $message ) );

			if ( $result->is( SendResult::ACCEPTED ) ) {
				self::mark_accepted( $lead, $result->message_id );
			} elseif ( $result->is_final_failure() ) {
				self::mark_failed( $lead, $result );
			} else {
				self::schedule_retry( $lead, $result );
			}
		} finally {
			Lock::release( $lead_id, $token );
		}
	}

	private static function mark_accepted( object $lead, string $message_id ): void {
		global $wpdb;
		$table = \EmailLeadsTracker\Database\Schema::leads();
		$now   = Util::now();

		// The webhook (v2) may have arrived first and set postmark_message_id; COALESCE keeps it.
		$set_message = '' !== $message_id ? 'postmark_message_id = COALESCE(postmark_message_id, %s), ' : '';
		$params      = '' !== $message_id ? array( $message_id ) : array();
		$params      = array_merge( $params, array( $now, $now, $now, (int) $lead->id ) );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET send_status = 'accepted', {$set_message}sent_at = COALESCE(sent_at, %s), last_attempt_at = %s, next_retry_at = NULL, last_error_code = NULL, last_error_message = NULL, updated_at = %s WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$params
			)
		);

		do_action( 'llt_leads_changed' );
		do_action( 'llt_lead_accepted', (int) $lead->id, $message_id );
		AdminNotifier::send_succeeded();
	}

	private static function mark_failed( object $lead, SendResult $result ): void {
		LeadRepository::update(
			(int) $lead->id,
			array(
				'send_status'        => LeadStatus::FAILED,
				'next_retry_at'      => null,
				'last_attempt_at'    => Util::now(),
				'last_error_code'    => substr( $result->code, 0, 40 ),
				'last_error_message' => Util::error_text( $result->message ),
			)
		);
		do_action( 'llt_leads_changed' );
		do_action( 'llt_lead_failed', (int) $lead->id, $result );
		AdminNotifier::lead_failed( (int) $lead->id, $result );
	}

	private static function schedule_retry( object $lead, SendResult $result ): void {
		$retry = (int) $lead->retry_count;
		$max   = min( (int) $lead->max_retries, count( Settings::retry_schedule() ) );

		if ( $retry >= $max ) {
			$last = ErrorClassifier::describe( $result->code );
			self::mark_failed(
				$lead,
				SendResult::failure( SendResult::PERMANENT, 'retries_exhausted', $last . ' ' . $result->message )
			);
			return;
		}

		$delay = BackoffPolicy::delay( $retry, $result->retry_after );
		if ( $result->is( SendResult::AMBIGUOUS ) ) {
			$delay = max( $delay, BackoffPolicy::AMBIGUOUS_MIN );
		}

		LeadRepository::update(
			(int) $lead->id,
			array(
				'retry_count'        => $retry + 1,
				'next_retry_at'      => Util::now( $delay ),
				'last_attempt_at'    => Util::now(),
				'last_error_code'    => $result->is( SendResult::AMBIGUOUS ) ? 'ambiguous_timeout' : substr( $result->code, 0, 40 ),
				'last_error_message' => Util::error_text( $result->message ),
			)
		);
	}
}
