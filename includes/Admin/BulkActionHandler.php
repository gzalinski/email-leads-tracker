<?php
/**
 * Actions in the lead list (v3): Resend, Dismiss, Restore, Delete content.
 *
 * Accepts checked IDs or "all matching the filter"; the logic is the same as in WP-CLI (LeadActions).
 *
 * @package EnspireEmailLeadsTracker
 */

namespace EnspireEmailLeadsTracker\Admin;

use EnspireEmailLeadsTracker\Lead\LeadActions;
use EnspireEmailLeadsTracker\Settings\AdminNotice;
use EnspireEmailLeadsTracker\Settings\SettingsPage;
use EnspireEmailLeadsTracker\Util;

defined( 'ABSPATH' ) || exit;

final class BulkActionHandler {

	// The same nonce that WP_List_Table prints in the form (bulk-{plural}).
	const NONCE = 'bulk-leads';

	/** Actions allowed in each view. */
	const ACTIONS = array(
		'failed'    => array( 'resend', 'dismiss', 'delete_content' ),
		'stuck'     => array(),
		'bounced'   => array( 'dismiss', 'delete_content' ),
		'dismissed' => array( 'restore', 'delete_content' ),
	);

	const LABELS = array(
		'resend'         => 'Resend',
		'dismiss'        => 'Dismiss',
		'restore'        => 'Restore',
		'delete_content' => 'Delete content',
	);

	public static function register(): void {
		add_action( 'admin_post_llt_lead_action', array( self::class, 'handle' ) );
	}

	/**
	 * @return string[]
	 */
	public static function actions_for( string $view ): array {
		return self::ACTIONS[ FailedLeadsQuery::sanitize_view( $view ) ] ?? array();
	}

	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You do not have the required permission.', 403 );
		}
		check_admin_referer( self::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonce checked above.
		$request = wp_unslash( $_REQUEST );
		// phpcs:enable
		$view    = FailedLeadsQuery::sanitize_view( sanitize_key( (string) ( $request['llt_view'] ?? 'failed' ) ) );
		$filters = FailedLeadsQuery::filters_from( $request );
		$action  = sanitize_key( (string) ( $request['llt_do'] ?? '' ) );
		if ( '' === $action ) {
			$action = sanitize_key( (string) ( $request['llt_do_bottom'] ?? '' ) );
		}

		if ( ! empty( $request['llt_all_matching'] ) ) {
			$ids = FailedLeadsQuery::ids( $view, $filters );
		} else {
			$ids = Util::ids( $request['ids'] ?? array() );
		}

		$message = self::perform( $action, $view, $ids, get_current_user_id() );
		AdminNotice::add( $message[0], $message[1] );

		$back = array( 'view' => $view );
		foreach ( array( 'llt_error' => 'error', 's' => 's', 'llt_from' => 'from', 'llt_to' => 'to' ) as $param => $key ) {
			if ( '' !== $filters[ $key ] ) {
				$back[ $param ] = $filters[ $key ];
			}
		}
		wp_safe_redirect( SettingsPage::url( 'failed', $back ) );
		exit;
	}

	/**
	 * Runs the action and returns the message for the administrator.
	 *
	 * @param int[] $ids IDs.
	 * @return array{0:string,1:string} [notice type, message]
	 */
	public static function perform( string $action, string $view, array $ids, int $user_id ): array {
		if ( ! in_array( $action, self::actions_for( $view ), true ) ) {
			return array( 'error', 'This action is not available in this view.' );
		}
		if ( ! $ids ) {
			return array( 'warning', 'No lead was selected.' );
		}

		switch ( $action ) {
			case 'resend':
				$result  = LeadActions::resend( $ids, $user_id );
				$message = sprintf( '%d leads were put in the send queue and will go out within the next minute.', $result['queued'] );
				if ( $result['no_content'] ) {
					$message .= sprintf( '<br>%d skipped: their content was already deleted by retention (#%s).', count( $result['no_content'] ), implode( ', #', array_slice( $result['no_content'], 0, 20 ) ) );
				}
				if ( $result['not_failed'] ) {
					$message .= sprintf( '<br>%d skipped: they are no longer in the "failed" status.', count( $result['not_failed'] ) );
				}
				return array( $result['queued'] ? 'success' : 'warning', $message );

			case 'dismiss':
				return array( 'success', sprintf( '%d leads dismissed. You can find them in the "Dismissed" view.', LeadActions::dismiss( $ids, $user_id ) ) );

			case 'restore':
				return array( 'success', sprintf( '%d leads restored.', LeadActions::restore( $ids ) ) );

			case 'delete_content':
				return array( 'success', sprintf( 'The visitors\' data was deleted for %d leads. The minimal leads stay on record.', LeadActions::delete_content( $ids ) ) );
		}
		return array( 'error', 'Unknown action.' );
	}

	/**
	 * URL for an action on a single row.
	 */
	public static function row_url( string $action, string $view, int $id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'llt_lead_action',
					'llt_do'   => $action,
					'llt_view' => $view,
					'ids[]'    => $id,
				),
				admin_url( 'admin-post.php' )
			),
			self::NONCE
		);
	}
}
