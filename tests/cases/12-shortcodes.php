<?php
/**
 * The two shortcodes: [enspire_contact_form] (inline) and [enspire_contact_popup] (button + SweetAlert2).
 */
// phpcs:ignoreFile
use EnspireEmailLeadsTracker\Shortcode\ContactShortcode;

$GLOBALS['eelt_test_scripts']   = array( 'enqueued' => array(), 'registered' => array() );
$GLOBALS['eelt_test_shortcodes'] = array();

foreach (
	array(
		'esc_html'   => static fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES ),
		'esc_attr'   => static fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES ),
		'esc_url'    => static fn( $v ) => (string) $v,
		'admin_url'  => static fn( $p = '' ) => 'https://example.test/wp-admin/' . $p,
		'esc_url_raw' => static fn( $v ) => (string) $v,
	) as $name => $fn
) {
	if ( ! function_exists( $name ) ) {
		$GLOBALS[ 'eelt_fn_' . $name ] = $fn;
		eval( "function {$name}( ...\$a ) { return \$GLOBALS['eelt_fn_{$name}']( ...\$a ); }" );
	}
}
if ( ! function_exists( 'shortcode_atts' ) ) {
	function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
		$atts = (array) $atts;
		$out  = array();
		foreach ( $pairs as $name => $default ) {
			$out[ $name ] = array_key_exists( $name, $atts ) ? $atts[ $name ] : $default;
		}
		return $out;
	}
	function add_shortcode( $tag, $callback ) {
		$GLOBALS['eelt_test_shortcodes'][ $tag ] = $callback;
	}
	function absint( $v ) {
		return abs( (int) $v );
	}
	function get_the_ID() {
		return $GLOBALS['eelt_test_current_post'] ?? 0;
	}
	function remove_query_arg( $keys, $url ) {
		return $url;
	}
	function sanitize_html_class( $class ) {
		return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $class );
	}
	function wp_enqueue_style( $handle, ...$args ) {
		$GLOBALS['eelt_test_scripts']['enqueued'][ $handle ] = $args;
	}
	function wp_enqueue_script( $handle, $src = '', $deps = array(), ...$args ) {
		$GLOBALS['eelt_test_scripts']['enqueued'][ $handle ] = $deps;
	}
	function wp_register_script( $handle, $src = '', $deps = array(), ...$args ) {
		$GLOBALS['eelt_test_scripts']['registered'][ $handle ] = $src;
	}
	function wp_script_is( $handle, $list = 'enqueued' ) {
		return isset( $GLOBALS['eelt_test_scripts'][ $list ][ $handle ] );
	}
	function locate_template( $name ) {
		return '';
	}
}

$GLOBALS['eelt_test_posts'] = array( 245 => new WP_Post( array( 'ID' => 245, 'post_title' => 'Downtown Location' ) ) );
$GLOBALS['eelt_test_meta']  = array( 245 => array( '_directory_email' => 'downtown@example.com' ) );
reset_settings( array( 'consent_text' => 'I agree' ) );

ContactShortcode::register();
check( 'both shortcodes registered', isset( $GLOBALS['eelt_test_shortcodes']['enspire_contact_form'], $GLOBALS['eelt_test_shortcodes']['enspire_contact_popup'] ) );

// Inline form.
$html = ContactShortcode::render( array( 'object_id' => 245, 'class' => 'my-form  extra"><script>', 'submit_text' => 'Send now' ) );
check( 'inline: form rendered', false !== strpos( $html, '<form class="eelt-form"' ) );
check( 'inline: extra classes, sanitized', false !== strpos( $html, 'class="eelt-form-wrap my-form extrascript"' ) );
check( 'inline: custom submit text', false !== strpos( $html, '>Send now</button>' ) );
check( 'inline: status box', false !== strpos( $html, 'eelt-status' ) );
check( 'inline: recipient not in HTML', false === strpos( $html, 'downtown@example.com' ) );
check( 'inline: no SweetAlert2', ! isset( $GLOBALS['eelt_test_scripts']['enqueued']['eelt-popup'] ) );
check( 'inline: default submit text', false !== strpos( ContactShortcode::render( array( 'object_id' => 245 ) ), '>Send</button>' ) );

// Popup.
$html = ContactShortcode::render_popup(
	array(
		'object_id'    => 245,
		'button_text'  => 'Write to us',
		'button_class' => 'btn btn-primary',
		'title'        => 'Contact Downtown',
		'submit_text'  => 'Send message',
		'popup_class'  => 'my-popup',
		'class'        => 'my-wrap',
	)
);
check( 'popup: wrapper classes', false !== strpos( $html, 'class="eelt-popup my-wrap"' ) );
check( 'popup: button classes', false !== strpos( $html, 'class="eelt-popup-button btn btn-primary"' ) );
check( 'popup: button text', false !== strpos( $html, '>Write to us</button>' ) );
check( 'popup: button hidden until JS runs', (bool) preg_match( '/<button type="button"[^>]*\shidden>/s', $html ) );
check( 'popup: title', false !== strpos( $html, 'data-eelt-title="Contact Downtown"' ) );
check( 'popup: confirm text', false !== strpos( $html, 'data-eelt-submit="Send message"' ) );
check( 'popup: popup class', false !== strpos( $html, 'data-eelt-popup-class="my-popup"' ) );
check( 'popup: form in a template', (bool) preg_match( '/<template id="eelt-form-\d+-template">.*<form class="eelt-form".*<\/template>/s', $html ) );
preg_match( '/<template[^>]*>(.*)<\/template>/s', $html, $m );
check( 'popup: no submit button inside the popup form', false === strpos( $m[1], 'eelt-submit' ) );
check( 'popup: no status box inside the popup form', false === strpos( $m[1], 'eelt-status' ) );
check( 'popup: inline fallback in noscript', (bool) preg_match( '/<noscript>.*eelt-submit.*<\/noscript>/s', $html ) );
check( 'popup: recipient not in HTML', false === strpos( $html, 'downtown@example.com' ) );
same( 'popup: script depends on SweetAlert2', array( 'eelt-form', 'sweetalert2' ), $GLOBALS['eelt_test_scripts']['enqueued']['eelt-popup'] );
check( 'popup: bundled SweetAlert2 registered', false !== strpos( (string) $GLOBALS['eelt_test_scripts']['registered']['sweetalert2'], 'assets/vendor/sweetalert2/sweetalert2.all.min.js' ) );
check( 'popup: bundled file exists', is_readable( EELT_DIR . 'assets/vendor/sweetalert2/sweetalert2.all.min.js' ) );

$defaults = ContactShortcode::render_popup( array( 'object_id' => 245 ) );
check( 'popup: default button text', false !== strpos( $defaults, '>Contact us</button>' ) );
check( 'popup: default classes', false !== strpos( $defaults, 'class="eelt-popup-button"' ) );
check( 'popup: attributes escaped', false !== strpos( ContactShortcode::render_popup( array( 'object_id' => 245, 'button_text' => '<b>Hi</b> & "you"' ) ), '>Hi &amp; &quot;you&quot;</button>' ) );

// The theme already registered SweetAlert2: it is reused, not registered again.
$GLOBALS['eelt_test_scripts'] = array( 'enqueued' => array(), 'registered' => array( 'sweetalert2' => 'theme.js' ) );
ContactShortcode::render_popup( array( 'object_id' => 245 ) );
same( 'theme SweetAlert2 reused', 'theme.js', $GLOBALS['eelt_test_scripts']['registered']['sweetalert2'] );

// Unique IDs per instance.
preg_match_all( '/id="(eelt-form-\d+)"/', ContactShortcode::render( array( 'object_id' => 245 ) ) . ContactShortcode::render( array( 'object_id' => 245 ) ), $ids );
same( 'unique form IDs', 2, count( array_unique( $ids[1] ) ) );

// No valid recipient: nothing for visitors.
same( 'popup: no recipient → empty for visitors', '', ContactShortcode::render_popup( array( 'object_id' => 999 ) ) );
same( 'class list sanitizer', 'a b-c d_e', ContactShortcode::classes( '  a  b-c  d_e a ' ) );
