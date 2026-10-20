<?php
// phpcs:ignoreFile
use LocationLeadTracker\Form\Validator;
use LocationLeadTracker\Shortcode\RecipientResolver;

$valid = array(
	'llt_name'    => 'Ion Popescu',
	'llt_email'   => 'ion@example.md',
	'llt_phone'   => '+373 69 123 456',
	'llt_message' => "Bună ziua,\nAș dori o programare.",
	'llt_consent' => '1',
);
$result = Validator::validate( $valid );
same( 'formular valid fără erori', array(), $result['errors'] );
same( 'mesaj multi-linie păstrat', "Bună ziua,\nAș dori o programare.", $result['data']['message'] );

$result = Validator::validate( array() );
same( 'câmpuri obligatorii', array( 'llt_name', 'llt_email', 'llt_message', 'llt_consent' ), array_keys( $result['errors'] ) );

$result = Validator::validate( array_merge( $valid, array( 'llt_phone' => 'abc' ) ) );
check( 'telefon invalid', isset( $result['errors']['llt_phone'] ) );
$result = Validator::validate( array_merge( $valid, array( 'llt_phone' => '' ) ) );
check( 'telefon opțional', ! isset( $result['errors']['llt_phone'] ) );
$result = Validator::validate( array_merge( $valid, array( 'llt_email' => 'nu-e-email' ) ) );
check( 'email invalid', isset( $result['errors']['llt_email'] ) );
$result = Validator::validate( array_merge( $valid, array( 'llt_message' => str_repeat( 'a', 5001 ) ) ) );
check( 'mesaj prea lung', isset( $result['errors']['llt_message'] ) );
$result = Validator::validate( array_merge( $valid, array( 'llt_name' => "Ion\r\nBcc: x@y.z" ) ) );
check( 'nume fără linii noi', false === strpos( $result['data']['name'], "\n" ) );

// Destinatarul din post meta.
$GLOBALS['llt_test_posts'] = array(
	245 => new WP_Post( array( 'ID' => 245, 'post_title' => 'Locația Centru' ) ),
	246 => new WP_Post( array( 'ID' => 246, 'post_status' => 'draft' ) ),
	247 => new WP_Post( array( 'ID' => 247 ) ),
	248 => new WP_Post( array( 'ID' => 248 ) ),
);
$GLOBALS['llt_test_meta'] = array(
	245 => array( 'email' => ' centru@example.md ' ),
	246 => array( 'email' => 'draft@example.md' ),
	247 => array( 'email' => 'nu-e-email' ),
);

$resolved = RecipientResolver::resolve( 245 );
check( 'destinatar găsit', is_array( $resolved ) && 'centru@example.md' === $resolved['email'] );
same( 'postare inexistentă', 'llt_no_post', RecipientResolver::resolve( 999 )->get_error_code() );
same( 'postare nepublicată', 'llt_not_published', RecipientResolver::resolve( 246 )->get_error_code() );
same( 'email invalid', 'llt_no_email', RecipientResolver::resolve( 247 )->get_error_code() );
same( 'fără câmp email', 'llt_no_email', RecipientResolver::resolve( 248 )->get_error_code() );
same( 'object_id zero', 'llt_no_post', RecipientResolver::resolve( 0 )->get_error_code() );
