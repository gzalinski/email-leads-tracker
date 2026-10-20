<?php
/**
 * Integration with Tools → Erase Personal Data.
 *
 * The content is deleted (name, email, phone, message). The minimal lead stays: it contains no visitor data.
 *
 * @package EnspireEmailLeadsTracker
 */

namespace EnspireEmailLeadsTracker\Privacy;

use EnspireEmailLeadsTracker\Database\MessageRepository;

defined( 'ABSPATH' ) || exit;

final class PersonalDataEraser {

	const PER_PAGE = 50;

	/**
	 * @param array $erasers Existing erasers.
	 */
	public static function register( $erasers ): array {
		$erasers                          = is_array( $erasers ) ? $erasers : array();
		$erasers['enspire-email-leads-tracker'] = array(
			'eraser_friendly_name' => 'Enspire Email Leads Tracker',
			'callback'             => array( self::class, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * @param string $email The person's address.
	 * @param int    $page  Page (unused: each call deletes the next batch).
	 */
	public static function erase( $email, $page = 1 ): array {
		$deleted = MessageRepository::delete_by_sender( (string) $email, self::PER_PAGE );
		if ( $deleted ) {
			do_action( 'eelt_leads_changed' );
		}
		return array(
			'items_removed'  => $deleted > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => $deleted < self::PER_PAGE,
		);
	}
}
