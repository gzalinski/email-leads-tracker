<?php
/**
 * Integrarea cu Unelte → Șterge date personale.
 *
 * Se șterge conținutul (nume, email, telefon, mesaj). Leadul minimal rămâne: nu conține date ale vizitatorului.
 *
 * @package LocationLeadTracker
 */

namespace LocationLeadTracker\Privacy;

use LocationLeadTracker\Database\MessageRepository;

defined( 'ABSPATH' ) || exit;

final class PersonalDataEraser {

	const PER_PAGE = 50;

	/**
	 * @param array $erasers Ștergătorii existenți.
	 */
	public static function register( $erasers ): array {
		$erasers                          = is_array( $erasers ) ? $erasers : array();
		$erasers['location-lead-tracker'] = array(
			'eraser_friendly_name' => 'Location Lead Tracker',
			'callback'             => array( self::class, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * @param string $email Adresa persoanei.
	 * @param int    $page  Pagina (nefolosită: fiecare apel șterge următorul lot).
	 */
	public static function erase( $email, $page = 1 ): array {
		$deleted = MessageRepository::delete_by_sender( (string) $email, self::PER_PAGE );
		if ( $deleted ) {
			do_action( 'llt_leads_changed' );
		}
		return array(
			'items_removed'  => $deleted > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => $deleted < self::PER_PAGE,
		);
	}
}
