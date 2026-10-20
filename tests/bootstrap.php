<?php
/**
 * Minimal test environment: stubs for the WordPress functions used by the tested logic.
 * It does not replace testing in a real WordPress install (see README → Testing).
 *
 * @package LocationLeadTracker
 */

// phpcs:ignoreFile

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );
define( 'LLT_VERSION', 'test' );
define( 'LLT_DB_VERSION', '1' );
define( 'LLT_DIR', dirname( __DIR__ ) . '/' );
define( 'LLT_URL', 'https://example.test/wp-content/plugins/llt/' );

$GLOBALS['llt_test_options']    = array();
$GLOBALS['llt_test_transients'] = array();
$GLOBALS['llt_test_salt']       = 'salt-one';
$GLOBALS['llt_test_errors']     = array();
$GLOBALS['llt_test_posts']      = array();
$GLOBALS['llt_test_meta']       = array();

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() {
		return $this->code;
	}
	public function get_error_message() {
		return $this->message;
	}
	public function get_error_data() {
		return $this->data;
	}
}

class WP_Post {
	public $ID;
	public $post_status = 'publish';
	public $post_title  = '';
	public function __construct( array $data ) {
		foreach ( $data as $key => $value ) {
			$this->$key = $value;
		}
	}
}

class WP_REST_Request {
	private $headers = array();
	private $body    = '';
	public function __construct( array $headers = array(), string $body = '' ) {
		$this->headers = array_change_key_case( $headers, CASE_LOWER );
		$this->body    = $body;
	}
	public function get_header( $name ) {
		return $this->headers[ strtolower( str_replace( '-', '_', $name ) ) ] ?? ( $this->headers[ strtolower( $name ) ] ?? null );
	}
	public function get_body() {
		return $this->body;
	}
}

class WP_REST_Response {
	public $data;
	public $status;
	public function __construct( $data = null, $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}
	public function get_status() {
		return $this->status;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function do_action( ...$args ) {}
function apply_filters( $hook, $value, ...$args ) {
	return $value;
}
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['llt_test_options'] ) ? $GLOBALS['llt_test_options'][ $key ] : $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['llt_test_options'][ $key ] = $value;
	return true;
}
function add_option( $key, $value = '', $deprecated = '', $autoload = null ) {
	if ( array_key_exists( $key, $GLOBALS['llt_test_options'] ) ) {
		return false;
	}
	$GLOBALS['llt_test_options'][ $key ] = $value;
	return true;
}
function delete_option( $key ) {
	unset( $GLOBALS['llt_test_options'][ $key ] );
	return true;
}
function wp_cache_delete( ...$args ) {
	return true;
}
function get_transient( $key ) {
	return $GLOBALS['llt_test_transients'][ $key ] ?? false;
}
function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['llt_test_transients'][ $key ] = $value;
	return true;
}
function delete_transient( $key ) {
	unset( $GLOBALS['llt_test_transients'][ $key ] );
	return true;
}
function wp_salt( $scheme = 'auth' ) {
	return $GLOBALS['llt_test_salt'] . '|' . $scheme;
}
function wp_json_encode( $data ) {
	return json_encode( $data );
}
function wp_unslash( $value ) {
	return $value;
}
function sanitize_text_field( $value ) {
	$value = strip_tags( (string) $value );
	$value = preg_replace( '/[\r\n\t ]+/', ' ', $value );
	return trim( $value );
}
function sanitize_textarea_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}
function sanitize_email( $value ) {
	return trim( preg_replace( '/[^a-zA-Z0-9.@_+\-]/', '', (string) $value ) );
}
function is_email( $value ) {
	return false !== filter_var( (string) $value, FILTER_VALIDATE_EMAIL ) ? $value : false;
}
function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}
function wp_strip_all_tags( $value ) {
	return trim( strip_tags( (string) $value ) );
}
function wp_kses( $value, $allowed ) {
	return strip_tags( (string) $value, '<' . implode( '><', array_keys( $allowed ) ) . '>' );
}
function wp_rand( $min = 0, $max = 0 ) {
	return random_int( $min, $max );
}
function wp_specialchars_decode( $value, $quote = 0 ) {
	return htmlspecialchars_decode( (string) $value, ENT_QUOTES );
}
function get_bloginfo( $key = '' ) {
	return 'Test Site';
}
function home_url( $path = '' ) {
	return 'https://example.test' . $path;
}
function get_the_title( $id ) {
	return isset( $GLOBALS['llt_test_posts'][ $id ] ) ? $GLOBALS['llt_test_posts'][ $id ]->post_title : 'Location';
}
function get_post( $id ) {
	return $GLOBALS['llt_test_posts'][ $id ] ?? null;
}
function get_post_meta( $id, $key, $single = false ) {
	return $GLOBALS['llt_test_meta'][ $id ][ $key ] ?? '';
}
function get_gmt_from_date( $date ) {
	return $date;
}
function get_date_from_gmt( $date, $format ) {
	return $date;
}
function add_settings_error( $setting, $code, $message, $type = 'error' ) {
	$GLOBALS['llt_test_errors'][] = $message;
}
function current_user_can( $cap ) {
	return false;
}
function get_current_user_id() {
	return 0;
}
function human_time_diff( $from, $to = 0 ) {
	return abs( $to - $from ) . ' s';
}
function wp_schedule_single_event( $time, $hook, $args = array() ) {
	$GLOBALS['llt_test_cron'][] = array( $hook, $args );
	return true;
}
function wp_next_scheduled( $hook, $args = array() ) {
	return false;
}
function spawn_cron() {}
function esc_sql( $value ) {
	return addslashes( (string) $value );
}
function rest_url( $path = '' ) {
	return 'https://example.test/wp-json/' . ltrim( $path, '/' );
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}
function register_rest_route( ...$args ) {
	return true;
}

// Simulated HTTP: responses go into $GLOBALS['llt_http_queue'], calls are recorded in $GLOBALS['llt_http_log'].
$GLOBALS['llt_http_queue'] = array();
$GLOBALS['llt_http_log']   = array();
function llt_test_http( $method, $url, $args ) {
	$GLOBALS['llt_http_log'][] = array( $method, $url, $args );
	if ( ! $GLOBALS['llt_http_queue'] ) {
		throw new RuntimeException( "Unexpected HTTP request: {$method} {$url}" );
	}
	return array_shift( $GLOBALS['llt_http_queue'] );
}
function wp_remote_post( $url, $args = array() ) {
	return llt_test_http( 'POST', $url, $args );
}
function wp_remote_get( $url, $args = array() ) {
	return llt_test_http( 'GET', $url, $args );
}
function wp_remote_retrieve_response_code( $response ) {
	return $response['code'] ?? 0;
}
function wp_remote_retrieve_body( $response ) {
	return $response['body'] ?? '';
}
function wp_remote_retrieve_header( $response, $name ) {
	return $response['headers'][ strtolower( $name ) ] ?? '';
}
function llt_http( int $code, $body = '', array $headers = array() ) {
	return array(
		'code'    => $code,
		'body'    => is_array( $body ) ? json_encode( $body ) : $body,
		'headers' => $headers,
	);
}

// Simulated email.
$GLOBALS['llt_mail_log']  = array();
$GLOBALS['llt_mail_fail'] = false;
function wp_mail( $to, $subject, $body ) {
	$GLOBALS['llt_mail_log'][] = array( $to, $subject, $body );
	return ! $GLOBALS['llt_mail_fail'];
}

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'LocationLeadTracker\\';
		if ( 0 !== strncmp( $class, $prefix, strlen( $prefix ) ) ) {
			return;
		}
		$file = LLT_DIR . 'includes/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);
