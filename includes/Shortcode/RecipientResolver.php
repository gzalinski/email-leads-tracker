<?php
/**
 * Finds the recipient email: the `_directory_email` post meta of the given post.
 *
 * The meta key is fixed and there is no restriction by post type.
 *
 * @package EnspireEmailLeadsTracker
 */

namespace EnspireEmailLeadsTracker\Shortcode;

defined( 'ABSPATH' ) || exit;

final class RecipientResolver {

	const META_KEY = '_directory_email';

	/**
	 * @return array{post:\WP_Post,email:string}|\WP_Error
	 */
	public static function resolve( int $object_id ) {
		if ( $object_id <= 0 ) {
			return new \WP_Error( 'eelt_no_post', 'The shortcode has no object_id and there is no current post.' );
		}

		$post = get_post( $object_id );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'eelt_no_post', sprintf( 'Post #%d does not exist.', $object_id ) );
		}
		if ( 'publish' !== $post->post_status ) {
			return new \WP_Error( 'eelt_not_published', sprintf( 'Post #%d is not published.', $object_id ) );
		}

		$value = get_post_meta( $object_id, self::META_KEY, true );
		$email = is_string( $value ) ? trim( $value ) : '';
		$email = (string) apply_filters( 'eelt_recipient_email', $email, $post );

		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'eelt_no_email', sprintf( 'Post #%d does not have a valid email in the "%s" field.', $object_id, self::META_KEY ) );
		}

		return array(
			'post'  => $post,
			'email' => $email,
		);
	}
}
