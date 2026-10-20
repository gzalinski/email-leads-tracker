<?php
/**
 * Messages shown after admin actions (test email, test alert, lead actions).
 *
 * @package EmailLeadsTracker
 */

namespace EmailLeadsTracker\Settings;

defined( 'ABSPATH' ) || exit;

final class AdminNotice {

	private static function key(): string {
		return 'llt_notice_' . get_current_user_id();
	}

	public static function add( string $type, string $message ): void {
		$notices   = get_transient( self::key() );
		$notices   = is_array( $notices ) ? $notices : array();
		$notices[] = array(
			'type'    => in_array( $type, array( 'success', 'error', 'warning', 'info' ), true ) ? $type : 'info',
			'message' => $message,
		);
		set_transient( self::key(), $notices, 5 * MINUTE_IN_SECONDS );
	}

	public static function render(): void {
		$notices = get_transient( self::key() );
		if ( ! is_array( $notices ) || ! $notices ) {
			return;
		}
		delete_transient( self::key() );
		foreach ( $notices as $notice ) {
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $notice['type'] ),
				wp_kses( $notice['message'], array( 'strong' => array(), 'code' => array(), 'br' => array(), 'a' => array( 'href' => true ) ) )
			);
		}
	}
}
