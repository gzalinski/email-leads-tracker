<?php
/**
 * Lead statuses and delivery transition rules.
 *
 * @package EnspireEmailLeadsTracker
 */

namespace EnspireEmailLeadsTracker\Lead;

defined( 'ABSPATH' ) || exit;

final class LeadStatus {

	// send_status.
	const PENDING  = 'pending';
	const ACCEPTED = 'accepted';
	const FAILED   = 'failed';

	// delivery_status.
	const UNKNOWN   = 'unknown';
	const DEFERRED  = 'deferred';
	const DELIVERED = 'delivered';
	const BOUNCED   = 'bounced';

	/**
	 * The statuses a delivery status can be reached from. `delivered` and `bounced` are final.
	 *
	 * @return string[]
	 */
	public static function delivery_sources( string $target ): array {
		switch ( $target ) {
			case self::DELIVERED:
			case self::BOUNCED:
				return array( self::UNKNOWN, self::DEFERRED );
			case self::DEFERRED:
				return array( self::UNKNOWN );
			default:
				return array();
		}
	}

	public static function can_transition( string $from, string $to ): bool {
		return in_array( $from, self::delivery_sources( $to ), true );
	}
}
