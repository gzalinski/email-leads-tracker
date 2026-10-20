<?php
// phpcs:ignoreFile
use EnspireEmailLeadsTracker\Form\Validator;
use EnspireEmailLeadsTracker\Shortcode\RecipientResolver;

$valid = array(
	'eelt_name'    => 'John Smith',
	'eelt_email'   => 'john@example.com',
	'eelt_phone'   => '+373 69 123 456',
	'eelt_message' => "Hello,\nI would like to book an appointment.",
	'eelt_consent' => '1',
);
$result = Validator::validate( $valid );
same( 'valid form, no errors', array(), $result['errors'] );
same( 'multi-line message kept', "Hello,\nI would like to book an appointment.", $result['data']['message'] );

$result = Validator::validate( array() );
same( 'required fields', array( 'eelt_name', 'eelt_email', 'eelt_message', 'eelt_consent' ), array_keys( $result['errors'] ) );

$result = Validator::validate( array_merge( $valid, array( 'eelt_phone' => 'abc' ) ) );
check( 'invalid phone', isset( $result['errors']['eelt_phone'] ) );
$result = Validator::validate( array_merge( $valid, array( 'eelt_phone' => '' ) ) );
check( 'phone is optional', ! isset( $result['errors']['eelt_phone'] ) );
$result = Validator::validate( array_merge( $valid, array( 'eelt_email' => 'not-an-email' ) ) );
check( 'invalid email', isset( $result['errors']['eelt_email'] ) );
$result = Validator::validate( array_merge( $valid, array( 'eelt_message' => str_repeat( 'a', 5001 ) ) ) );
check( 'message too long', isset( $result['errors']['eelt_message'] ) );
$result = Validator::validate( array_merge( $valid, array( 'eelt_name' => "John\r\nBcc: x@y.z" ) ) );
check( 'name without line breaks', false === strpos( $result['data']['name'], "\n" ) );

// Recipient from post meta.
$GLOBALS['eelt_test_posts'] = array(
	245 => new WP_Post( array( 'ID' => 245, 'post_title' => 'Downtown Location' ) ),
	246 => new WP_Post( array( 'ID' => 246, 'post_status' => 'draft' ) ),
	247 => new WP_Post( array( 'ID' => 247 ) ),
	248 => new WP_Post( array( 'ID' => 248 ) ),
);
$GLOBALS['eelt_test_meta'] = array(
	245 => array( 'email' => ' downtown@example.com ' ),
	246 => array( 'email' => 'draft@example.com' ),
	247 => array( 'email' => 'not-an-email' ),
);

$resolved = RecipientResolver::resolve( 245 );
check( 'recipient found', is_array( $resolved ) && 'downtown@example.com' === $resolved['email'] );
same( 'post does not exist', 'eelt_no_post', RecipientResolver::resolve( 999 )->get_error_code() );
same( 'post not published', 'eelt_not_published', RecipientResolver::resolve( 246 )->get_error_code() );
same( 'invalid email', 'eelt_no_email', RecipientResolver::resolve( 247 )->get_error_code() );
same( 'no email field', 'eelt_no_email', RecipientResolver::resolve( 248 )->get_error_code() );
same( 'object_id zero', 'eelt_no_post', RecipientResolver::resolve( 0 )->get_error_code() );
