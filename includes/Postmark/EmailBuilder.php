<?php
/**
 * Builds the Postmark request for a lead.
 *
 * @package EnspireEmailLeadsTracker
 */

namespace EnspireEmailLeadsTracker\Postmark;

use EnspireEmailLeadsTracker\Settings\Settings;
use EnspireEmailLeadsTracker\Template;
use EnspireEmailLeadsTracker\Util;

defined( 'ABSPATH' ) || exit;

final class EmailBuilder {

	const TAG = 'eelt-lead';

	/**
	 * @param object $lead    Row from `eelt_leads`.
	 * @param object $message Row from `eelt_lead_messages`.
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
		$subject = Util::single_line( (string) apply_filters( 'eelt_email_subject', $subject, $lead ) );

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
			'TextBody'      => (string) apply_filters( 'eelt_email_body', self::text_body( $fields ), $lead, $message ),
			'MessageStream' => (string) Settings::get( 'postmark_stream' ),
			'Tag'           => self::TAG,
			'Metadata'      => array( 'eelt_lead_id' => (string) $lead->id ),
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
	 * @param array $f Email fields.
	 */
	public static function text_body( array $f ): string {
		$lines   = array();
		$lines[] = sprintf( 'New message from %s for "%s".', $f['site_name'], $f['post_title'] );
		$lines[] = '';
		$lines[] = 'Name: ' . $f['name'];
		$lines[] = 'Email: ' . $f['email'];
		if ( '' !== $f['phone'] ) {
			$lines[] = 'Phone: ' . $f['phone'];
		}
		$lines[] = 'Date: ' . $f['date'];
		$lines[] = '';
		$lines[] = 'Message:';
		$lines[] = $f['message'];
		$lines[] = '';
		$lines[] = '—';
		$lines[] = 'Reply directly to this email to write to the visitor.';
		$lines[] = sprintf( 'Reference: #%d', $f['lead_id'] );
		return implode( "\n", $lines );
	}
}
