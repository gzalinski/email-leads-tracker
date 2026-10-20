<?php
/**
 * „Vezi mesajul” (v3): datele vizitatorului se încarcă doar la cerere, doar pentru administratori.
 *
 * @package LocationLeadTracker
 */

namespace LocationLeadTracker\Admin;

use LocationLeadTracker\Database\MessageRepository;
use LocationLeadTracker\Util;

defined( 'ABSPATH' ) || exit;

final class LeadDetailsAjax {

	public static function register(): void {
		add_action( 'wp_ajax_llt_lead_details', array( self::class, 'handle' ) );
	}

	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Nu ai permisiunea necesară.' ), 403 );
		}
		check_ajax_referer( 'llt_admin', 'nonce' );

		$lead_id = isset( $_POST['lead'] ) ? absint( wp_unslash( $_POST['lead'] ) ) : 0;
		$message = $lead_id ? MessageRepository::find( $lead_id ) : null;
		if ( ! $message ) {
			wp_send_json_error( array( 'message' => 'Conținutul acestui lead nu mai există.' ), 404 );
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
