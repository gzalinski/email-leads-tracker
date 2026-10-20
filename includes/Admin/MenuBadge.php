<?php
/**
 * Numărul de leaduri eșuate afișat lângă „Location Lead Tracker” în meniul Setări (v3).
 *
 * @package LocationLeadTracker
 */

namespace LocationLeadTracker\Admin;

defined( 'ABSPATH' ) || exit;

final class MenuBadge {

	const CACHE = 'llt_failed_count';

	public static function register(): void {
		add_filter( 'llt_menu_title', array( self::class, 'title' ) );
	}

	/**
	 * @param string $title Titlul din meniu.
	 */
	public static function title( $title ): string {
		if ( ! current_user_can( 'manage_options' ) ) {
			return (string) $title;
		}
		$count = self::count();
		if ( $count <= 0 ) {
			return (string) $title;
		}
		return sprintf(
			'%s <span class="awaiting-mod count-%d"><span class="pending-count" aria-hidden="true">%d</span><span class="screen-reader-text">%d leaduri eșuate</span></span>',
			esc_html( (string) $title ),
			$count,
			$count,
			$count
		);
	}

	public static function count(): int {
		$cached = get_transient( self::CACHE );
		if ( false !== $cached ) {
			return (int) $cached;
		}
		$count = FailedLeadsQuery::attention_count();
		set_transient( self::CACHE, $count, 5 * MINUTE_IN_SECONDS );
		return $count;
	}

	public static function bust(): void {
		delete_transient( self::CACHE );
	}
}
