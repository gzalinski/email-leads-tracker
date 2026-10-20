<?php
/**
 * Acțiunile din lista de leaduri (v3): Retrimite, Ignoră, Restaurează, Șterge conținutul.
 *
 * Primește ID-uri bifate sau „toate după filtru”; logica este aceeași ca în WP-CLI (LeadActions).
 *
 * @package LocationLeadTracker
 */

namespace LocationLeadTracker\Admin;

use LocationLeadTracker\Lead\LeadActions;
use LocationLeadTracker\Settings\AdminNotice;
use LocationLeadTracker\Settings\SettingsPage;
use LocationLeadTracker\Util;

defined( 'ABSPATH' ) || exit;

final class BulkActionHandler {

	// Același nonce pe care WP_List_Table îl afișează în formular (bulk-{plural}).
	const NONCE = 'bulk-leads';

	/** Acțiunile permise pe fiecare vizualizare. */
	const ACTIONS = array(
		'failed'    => array( 'resend', 'dismiss', 'delete_content' ),
		'stuck'     => array(),
		'bounced'   => array( 'dismiss', 'delete_content' ),
		'dismissed' => array( 'restore', 'delete_content' ),
	);

	const LABELS = array(
		'resend'         => 'Retrimite',
		'dismiss'        => 'Ignoră',
		'restore'        => 'Restaurează',
		'delete_content' => 'Șterge conținutul',
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
			wp_die( 'Nu ai permisiunea necesară.', 403 );
		}
		check_admin_referer( self::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonce verificat mai sus.
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
	 * Execută acțiunea și întoarce mesajul pentru administrator.
	 *
	 * @param int[] $ids ID-uri.
	 * @return array{0:string,1:string} [tip notice, mesaj]
	 */
	public static function perform( string $action, string $view, array $ids, int $user_id ): array {
		if ( ! in_array( $action, self::actions_for( $view ), true ) ) {
			return array( 'error', 'Acțiunea nu este disponibilă în această vizualizare.' );
		}
		if ( ! $ids ) {
			return array( 'warning', 'Nu ai selectat niciun lead.' );
		}

		switch ( $action ) {
			case 'resend':
				$result  = LeadActions::resend( $ids, $user_id );
				$message = sprintf( '%d leaduri au fost puse în coada de trimitere și vor pleca în următorul minut.', $result['queued'] );
				if ( $result['no_content'] ) {
					$message .= sprintf( '<br>%d sărite: conținutul a fost deja șters de retenție (#%s).', count( $result['no_content'] ), implode( ', #', array_slice( $result['no_content'], 0, 20 ) ) );
				}
				if ( $result['not_failed'] ) {
					$message .= sprintf( '<br>%d sărite: nu mai sunt în starea „eșuat”.', count( $result['not_failed'] ) );
				}
				return array( $result['queued'] ? 'success' : 'warning', $message );

			case 'dismiss':
				return array( 'success', sprintf( '%d leaduri ignorate. Le găsești în vizualizarea „Ignorate”.', LeadActions::dismiss( $ids, $user_id ) ) );

			case 'restore':
				return array( 'success', sprintf( '%d leaduri restaurate.', LeadActions::restore( $ids ) ) );

			case 'delete_content':
				return array( 'success', sprintf( 'Datele vizitatorilor au fost șterse pentru %d leaduri. Leadurile minimale rămân în evidență.', LeadActions::delete_content( $ids ) ) );
		}
		return array( 'error', 'Acțiune necunoscută.' );
	}

	/**
	 * URL pentru o acțiune pe un singur rând.
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
