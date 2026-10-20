<?php
/**
 * Lead creation: the minimal lead and the temporary content are saved together, before sending.
 *
 * @package EnspireEmailLeadsTracker
 */

namespace EnspireEmailLeadsTracker\Lead;

use EnspireEmailLeadsTracker\Database\Schema;
use EnspireEmailLeadsTracker\Settings\Settings;
use EnspireEmailLeadsTracker\Util;

defined( 'ABSPATH' ) || exit;

final class LeadService {

	/**
	 * @param array{name:string,email:string,phone:string,message:string} $data Validated data.
	 * @return int|\WP_Error The lead ID.
	 */
	public static function create( array $data, int $object_id, string $recipient_email ) {
		global $wpdb;
		$now = Util::now();

		$wpdb->query( 'START TRANSACTION' );

		$inserted = $wpdb->insert(
			Schema::leads(),
			array(
				'object_id'       => $object_id,
				'recipient_email' => $recipient_email,
				'send_status'     => LeadStatus::PENDING,
				'delivery_status' => LeadStatus::UNKNOWN,
				'retry_count'     => 0,
				'max_retries'     => Settings::max_retries(),
				'next_retry_at'   => $now,
				'consent_at'      => $now,
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			array( '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
		);
		if ( ! $inserted ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'llt_insert_lead', (string) $wpdb->last_error );
		}
		$lead_id = (int) $wpdb->insert_id;

		$inserted = $wpdb->insert(
			Schema::messages(),
			array(
				'lead_id'      => $lead_id,
				'sender_name'  => $data['name'],
				'sender_email' => $data['email'],
				'sender_phone' => '' !== $data['phone'] ? $data['phone'] : null,
				'message'      => $data['message'],
				'created_at'   => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( ! $inserted ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'llt_insert_message', (string) $wpdb->last_error );
		}

		$wpdb->query( 'COMMIT' );

		do_action( 'llt_lead_created', $lead_id, $object_id );
		return $lead_id;
	}
}
