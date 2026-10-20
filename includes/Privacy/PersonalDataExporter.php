<?php
/**
 * Integrarea cu Unelte → Exportă date personale.
 *
 * @package LocationLeadTracker
 */

namespace LocationLeadTracker\Privacy;

use LocationLeadTracker\Database\LeadRepository;
use LocationLeadTracker\Database\MessageRepository;
use LocationLeadTracker\Util;

defined( 'ABSPATH' ) || exit;

final class PersonalDataExporter {

	const PER_PAGE = 50;

	/**
	 * @param array $exporters Exportatorii existenți.
	 */
	public static function register( $exporters ): array {
		$exporters                          = is_array( $exporters ) ? $exporters : array();
		$exporters['location-lead-tracker'] = array(
			'exporter_friendly_name' => 'Location Lead Tracker',
			'callback'               => array( self::class, 'export' ),
		);
		return $exporters;
	}

	/**
	 * @param string $email Adresa persoanei.
	 * @param int    $page  Pagina (de la 1).
	 */
	public static function export( $email, $page = 1 ): array {
		$page  = max( 1, (int) $page );
		$rows  = MessageRepository::by_sender( (string) $email, self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE );
		$items = array();

		foreach ( $rows as $row ) {
			$lead  = LeadRepository::find( (int) $row->lead_id );
			$title = $lead ? get_the_title( (int) $lead->object_id ) : '';

			$items[] = array(
				'group_id'    => 'location-lead-tracker',
				'group_label' => 'Mesaje trimise prin formularul de contact',
				'item_id'     => 'llt-lead-' . (int) $row->lead_id,
				'data'        => array(
					array(
						'name'  => 'Locație',
						'value' => $title,
					),
					array(
						'name'  => 'Nume',
						'value' => $row->sender_name,
					),
					array(
						'name'  => 'Email',
						'value' => $row->sender_email,
					),
					array(
						'name'  => 'Telefon',
						'value' => (string) $row->sender_phone,
					),
					array(
						'name'  => 'Mesaj',
						'value' => $row->message,
					),
					array(
						'name'  => 'Data',
						'value' => Util::local_date( (string) $row->created_at ),
					),
				),
			);
		}

		return array(
			'data' => $items,
			'done' => count( $rows ) < self::PER_PAGE,
		);
	}
}
