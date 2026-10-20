<?php
/**
 * Deletes the temporary content (ARCHITECTURE.md §12). The minimal lead is never deleted automatically.
 *
 * @package LocationLeadTracker
 */

namespace LocationLeadTracker\Retention;

use LocationLeadTracker\Database\MessageRepository;
use LocationLeadTracker\Database\Schema;
use LocationLeadTracker\Settings\Settings;
use LocationLeadTracker\Util;

defined( 'ABSPATH' ) || exit;

final class RetentionService {

	const BATCH       = 500;
	const MAX_BATCHES = 20;

	/**
	 * Active rules: name => [SQL condition on l (lead) and m (message), date parameter].
	 *
	 * @return array<string,array{0:string,1:string|null}>
	 */
	public static function rules(): array {
		$failed_cutoff = Util::now( -DAY_IN_SECONDS * max( 1, (int) Settings::get( 'retention_failed_days' ) ) );

		$rules = array(
			'failed' => array( "l.send_status = 'failed' AND m.created_at < %s", $failed_cutoff ),
		);

		if ( Settings::tracking_enabled() ) {
			$rules['delivered']   = array( "l.delivery_status = 'delivered'", null );
			$rules['bounced']     = array( "l.delivery_status = 'bounced' AND m.created_at < %s", $failed_cutoff );
			$rules['unconfirmed'] = array(
				"l.send_status = 'accepted' AND l.delivery_status IN ('unknown','deferred') AND l.sent_at < %s",
				Util::now( -DAY_IN_SECONDS * max( 1, (int) Settings::get( 'retention_unconfirmed_days' ) ) ),
			);
		} else {
			$rules['accepted'] = array(
				"l.send_status = 'accepted' AND l.sent_at < %s",
				Util::now( -HOUR_IN_SECONDS * max( 0, (int) Settings::get( 'retention_accepted_hours' ) ) ),
			);
		}

		return $rules;
	}

	/**
	 * @return array<string,int> Number of messages deleted (or that would be deleted, on a dry run) per rule.
	 */
	public static function run( bool $dry_run = false ): array {
		global $wpdb;
		$leads    = Schema::leads();
		$messages = Schema::messages();
		$counts   = array();

		foreach ( self::rules() as $name => $rule ) {
			list( $condition, $param ) = $rule;
			$counts[ $name ]           = 0;

			if ( $dry_run ) {
				$sql             = "SELECT COUNT(*) FROM {$messages} m JOIN {$leads} l ON l.id = m.lead_id WHERE {$condition}";
				$counts[ $name ] = (int) $wpdb->get_var( null !== $param ? $wpdb->prepare( $sql, $param ) : $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				continue;
			}

			for ( $batch = 0; $batch < self::MAX_BATCHES; $batch++ ) {
				$sql = "SELECT m.lead_id FROM {$messages} m JOIN {$leads} l ON l.id = m.lead_id WHERE {$condition} LIMIT " . self::BATCH;
				$ids = $wpdb->get_col( null !== $param ? $wpdb->prepare( $sql, $param ) : $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				if ( ! $ids ) {
					break;
				}
				$counts[ $name ] += MessageRepository::delete_many( $ids );
				if ( count( $ids ) < self::BATCH ) {
					break;
				}
			}
		}

		if ( ! $dry_run && array_sum( $counts ) > 0 ) {
			do_action( 'llt_leads_changed' );
		}
		return $counts;
	}
}
