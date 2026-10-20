<?php
/**
 * Construiește cererea Postmark pentru un lead.
 *
 * @package LocationLeadTracker
 */

namespace LocationLeadTracker\Postmark;

use LocationLeadTracker\Settings\Settings;
use LocationLeadTracker\Template;
use LocationLeadTracker\Util;

defined( 'ABSPATH' ) || exit;

final class EmailBuilder {

	const TAG = 'location-lead';

	/**
	 * @param object $lead    Rând din `location_leads`.
	 * @param object $message Rând din `location_lead_messages`.
	 */
	public static function build( object $lead, object $message ): array {
		$post_title = Util::single_line( wp_strip_all_tags( get_the_title( (int) $lead->object_id ) ) );
		$site_name  = self::site_name();

		$subject = strtr(
			(string) Settings::get( 'subject' ),
			array(
				'{post_title}' => $post_title,
				'{site_name}'  => $site_name,
			)
		);
		$subject = Util::single_line( (string) apply_filters( 'llt_email_subject', $subject, $lead ) );

		$fields = array(
			'site_name'  => $site_name,
			'post_title' => $post_title,
			'name'       => (string) $message->sender_name,
			'email'      => (string) $message->sender_email,
			'phone'      => (string) ( $message->sender_phone ?? '' ),
			'message'    => (string) $message->message,
			'date'       => Util::local_date( (string) $lead->created_at ),
			'lead_id'    => (int) $lead->id,
		);

		$payload = array(
			'From'          => self::from(),
			'To'            => (string) $lead->recipient_email,
			'Subject'       => $subject,
			'TextBody'      => (string) apply_filters( 'llt_email_body', self::text_body( $fields ), $lead, $message ),
			'MessageStream' => (string) Settings::get( 'postmark_stream' ),
			'Tag'           => self::TAG,
			'Metadata'      => array( 'llt_lead_id' => (string) $lead->id ),
			'TrackOpens'    => false,
			'TrackLinks'    => 'None',
		);

		if ( is_email( $fields['email'] ) ) {
			$payload['ReplyTo'] = $fields['email'];
		}
		if ( 'html' === Settings::get( 'email_format' ) ) {
			$payload['HtmlBody'] = Template::render( 'email.php', $fields );
		}

		return $payload;
	}

	public static function from(): string {
		$name  = str_replace( array( '"', '\\' ), '', Util::single_line( (string) Settings::get( 'from_name' ) ) );
		$email = (string) Settings::get( 'from_email' );
		return '' !== $name ? sprintf( '"%s" <%s>', $name, $email ) : $email;
	}

	public static function site_name(): string {
		return Util::single_line( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );
	}

	/**
	 * @param array $f Câmpurile emailului.
	 */
	public static function text_body( array $f ): string {
		$lines   = array();
		$lines[] = sprintf( 'Mesaj nou de pe %s pentru „%s”.', $f['site_name'], $f['post_title'] );
		$lines[] = '';
		$lines[] = 'Nume: ' . $f['name'];
		$lines[] = 'Email: ' . $f['email'];
		if ( '' !== $f['phone'] ) {
			$lines[] = 'Telefon: ' . $f['phone'];
		}
		$lines[] = 'Data: ' . $f['date'];
		$lines[] = '';
		$lines[] = 'Mesaj:';
		$lines[] = $f['message'];
		$lines[] = '';
		$lines[] = '—';
		$lines[] = 'Răspunde direct la acest email pentru a-i scrie vizitatorului.';
		$lines[] = sprintf( 'Referință: #%d', $f['lead_id'] );
		return implode( "\n", $lines );
	}
}
