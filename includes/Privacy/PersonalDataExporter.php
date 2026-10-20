<?php
/**
 * Integration with Tools → Export Personal Data.
 *
 * @package EnspireEmailLeadsTracker
 */

namespace EnspireEmailLeadsTracker\Privacy;

use EnspireEmailLeadsTracker\Database\LeadRepository;
use EnspireEmailLeadsTracker\Database\MessageRepository;
use EnspireEmailLeadsTracker\Util;

defined( 'ABSPATH' ) || exit;

final class PersonalDataExporter {

	const PER_PAGE = 50;

	/**
	 * @param array $exporters Existing exporters.
	 */
	public static function register( $exporters ): array {
		$exporters                          = is_array( $exporters ) ? $exporters : array();
		$exporters['enspire-email-leads-tracker'] = array(
			'exporter_friendly_name' => 'Enspire Email Leads Tracker',
			'callback'               => array( self::class, 'export' ),
		);
		return $exporters;
	}

	/**
	 * @param string $email The person's address.
	 * @param int    $page  Page (starting at 1).
	 */
	public static function export( $email, $page = 1 ): array {
		$page  = max( 1, (int) $page );
		$rows  = MessageRepository::by_sender( (string) $email, self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE );
		$items = array();

		foreach ( $rows as $row ) {
			$lead  = LeadRepository::find( (int) $row->lead_id );
			$title = $lead ? get_the_title( (int) $lead->object_id ) : '';

			$items[] = array(
				'group_id'    => 'enspire-email-leads-tracker',
				'group_label' => 'Messages sent through the contact form',
				'item_id'     => 'eelt-lead-' . (int) $row->lead_id,
				'data'        => array(
					array(
						'name'  => 'Location',
						'value' => $title,
					),
					array(
						'name'  => 'Name',
						'value' => $row->sender_name,
					),
					array(
						'name'  => 'Email',
						'value' => $row->sender_email,
					),
					array(
						'name'  => 'Phone',
						'value' => (string) $row->sender_phone,
					),
					array(
						'name'  => 'Message',
						'value' => $row->message,
					),
					array(
						'name'  => 'Date',
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
