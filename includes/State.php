<?php
/**
 * Internal plugin state (heartbeat, counters, recent alerts), stored in the `eelt_state` option.
 *
 * @package EnspireEmailLeadsTracker
 */

namespace EnspireEmailLeadsTracker;

defined( 'ABSPATH' ) || exit;

final class State {

	const OPTION = 'eelt_state';

	public static function all(): array {
		wp_cache_delete( self::OPTION, 'options' );
		$state = get_option( self::OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * @param mixed $default Default value.
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$state = self::all();
		return array_key_exists( $key, $state ) ? $state[ $key ] : $default;
	}

	/**
	 * @param mixed $value Value.
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
