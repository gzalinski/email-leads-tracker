<?php
/**
 * "View message" (v3): the visitor's data is loaded only on request, only for administrators.
 *
 * @package EnspireEmailLeadsTracker
 */

namespace EnspireEmailLeadsTracker\Admin;

use EnspireEmailLeadsTracker\Database\MessageRepository;
use EnspireEmailLeadsTracker\Util;

defined( 'ABSPATH' ) || exit;

final class LeadDetailsAjax {

	public static function register(): void {
		add_action( 'wp_ajax_eelt_lead_details', array( self::class, 'handle' ) );
	}

	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'You do not have the required permission.' ), 403 );
		}
		check_ajax_referer( 'eelt_admin', 'nonce' );

		$lead_id = isset( $_POST['lead'] ) ? absint( wp_unslash( $_POST['lead'] ) ) : 0;
		$message = $lead_id ? MessageRepository::find( $lead_id ) : null;
		if ( ! $message ) {
			wp_send_json_error( array( 'message' => 'The content of this lead no longer exists.' ), 404 );
		}

		wp_send_json_success(
			array(
				'lead'    => $lead_id,
				'name'    => (string) $message->sender_name,
				'email'   => (string) $message->sender_email,
				'phone'   => (string) $message->sender_phone,
				'message' => (string) $message->message,
				'date'    => Util::local_date( (string) $message->created_at ),
			)
		);
	}
}
