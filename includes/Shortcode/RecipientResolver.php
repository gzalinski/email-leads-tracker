<?php
/**
 * Găsește emailul destinatarului: post meta `email` a postării indicate.
 *
 * Cheia meta este fixă și nu există restricție după tipul postării.
 *
 * @package LocationLeadTracker
 */

namespace LocationLeadTracker\Shortcode;

defined( 'ABSPATH' ) || exit;

final class RecipientResolver {

	const META_KEY = 'email';

	/**
	 * @return array{post:\WP_Post,email:string}|\WP_Error
	 */
	public static function resolve( int $object_id ) {
		if ( $object_id <= 0 ) {
			return new \WP_Error( 'llt_no_post', 'Shortcode-ul nu are object_id și nu există o postare curentă.' );
		}

		$post = get_post( $object_id );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'llt_no_post', sprintf( 'Postarea #%d nu există.', $object_id ) );
		}
		if ( 'publish' !== $post->post_status ) {
			return new \WP_Error( 'llt_not_published', sprintf( 'Postarea #%d nu este publicată.', $object_id ) );
		}

		$value = get_post_meta( $object_id, self::META_KEY, true );
		$email = is_string( $value ) ? trim( $value ) : '';
		$email = (string) apply_filters( 'llt_recipient_email', $email, $post );

		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'llt_no_email', sprintf( 'Postarea #%d nu are un câmp „email” valid.', $object_id ) );
		}

		return array(
			'post'  => $post,
			'email' => $email,
		);
	}
}
