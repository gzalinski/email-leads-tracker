<?php
/**
 * Starea internă a pluginului (heartbeat, contoare, ultimele alerte), în opțiunea `llt_state`.
 *
 * @package LocationLeadTracker
 */

namespace LocationLeadTracker;

defined( 'ABSPATH' ) || exit;

final class State {

	const OPTION = 'llt_state';

	public static function all(): array {
		wp_cache_delete( self::OPTION, 'options' );
		$state = get_option( self::OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * @param mixed $default Valoare implicită.
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$state = self::all();
		return array_key_exists( $key, $state ) ? $state[ $key ] : $default;
	}

	/**
	 * @param mixed $value Valoare.
	 */
	public static function set( string $key, $value ): void {
		$state         = self::all();
		$state[ $key ] = $value;
		self::replace( $state );
	}

	public static function replace( array $state ): void {
		update_option( self::OPTION, $state, false );
	}
}
