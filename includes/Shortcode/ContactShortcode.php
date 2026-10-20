<?php
/**
 * Shortcode-ul [location_contact object_id="245"].
 *
 * @package LocationLeadTracker
 */

namespace LocationLeadTracker\Shortcode;

use LocationLeadTracker\Form\Messages;
use LocationLeadTracker\Settings\Settings;
use LocationLeadTracker\Template;

defined( 'ABSPATH' ) || exit;

final class ContactShortcode {

	/** @var int */
	private static $instance = 0;

	public static function register(): void {
		add_shortcode( 'location_contact', array( self::class, 'render' ) );
	}

	/**
	 * @param array|string $atts Atributele shortcode-ului.
	 */
	public static function render( $atts ): string {
		$atts      = shortcode_atts( array( 'object_id' => 0 ), $atts, 'location_contact' );
		$object_id = absint( $atts['object_id'] );
		if ( ! $object_id ) {
			$object_id = (int) get_the_ID();
		}

		$resolved = RecipientResolver::resolve( $object_id );
		if ( is_wp_error( $resolved ) ) {
			if ( current_user_can( 'edit_posts' ) ) {
				return '<div class="llt-notice llt-notice--error"><strong>Location Lead Tracker:</strong> '
					. esc_html( $resolved->get_error_message() )
					. ' Formularul nu este afișat vizitatorilor.</div>';
			}
			return '';
		}

		++self::$instance;
		$form_id = 'llt-form-' . self::$instance;
		$context = ContextSigner::sign( $object_id );

		self::enqueue_assets();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- doar afișarea rezultatului după redirect.
		$status = '';
		if ( isset( $_GET['llt_status'], $_GET['llt_form'] ) && sanitize_key( wp_unslash( $_GET['llt_form'] ) ) === $form_id ) {
			$status = sanitize_key( wp_unslash( $_GET['llt_status'] ) );
		}
		// phpcs:enable

		$return_url = remove_query_arg( array( 'llt_status', 'llt_form' ), self::current_url() );

		return Template::render(
			'form.php',
			array(
				'form_id'        => $form_id,
				'action'         => admin_url( 'admin-post.php' ),
				'ctx'            => $context['ctx'],
				'sig'            => $context['sig'],
				'return_url'     => $return_url,
				'status'         => $status,
				'status_ok'      => 'success' === $status,
				'status_message' => '' !== $status ? Messages::get( $status ) : '',
				'consent_html'   => self::consent_html(),
				'turnstile_key'  => self::turnstile_enabled() ? (string) Settings::get( 'turnstile_site_key' ) : '',
				'post'           => $resolved['post'],
			)
		);
	}

	/**
	 * Textul de consimțământ, cu HTML limitat la linkuri și formatare simplă.
	 */
	public static function consent_html(): string {
		$html = wp_kses( (string) Settings::get( 'consent_text' ), self::consent_allowed_tags() );
		// Linkurile deschise în tab nou primesc rel="noopener".
		return (string) preg_replace_callback(
			'/<a\s[^>]*target=("|\')_blank\1[^>]*>/i',
			static function ( $m ) {
				return false === stripos( $m[0], 'rel=' ) ? substr( $m[0], 0, -1 ) . ' rel="noopener">' : $m[0];
			},
			$html
		);
	}

	public static function consent_allowed_tags(): array {
		return array(
			'a'      => array(
				'href'   => true,
				'target' => true,
				'rel'    => true,
			),
			'strong' => array(),
			'em'     => array(),
			'br'     => array(),
		);
	}

	/**
	 * URL-ul paginii curente. Domeniul se ia din home_url() (nu din headerul Host), iar calea din REQUEST_URI,
	 * care include deja subdirectorul unei instalări de tip example.com/blog/.
	 */
	public static function current_url(): string {
		$home = wp_parse_url( home_url() );
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( '' === $uri || '/' !== $uri[0] ) {
			$uri = '/';
		}
		$origin = ( $home['scheme'] ?? 'https' ) . '://' . ( $home['host'] ?? '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
		return esc_url_raw( $origin . $uri );
	}

	public static function turnstile_enabled(): bool {
		return Settings::get( 'turnstile_enabled' ) && '' !== (string) Settings::get( 'turnstile_site_key' );
	}

	private static function enqueue_assets(): void {
		wp_enqueue_style( 'llt-form', LLT_URL . 'assets/form.css', array(), LLT_VERSION );
		wp_enqueue_script( 'llt-form', LLT_URL . 'assets/form.js', array(), LLT_VERSION, true );
		if ( self::turnstile_enabled() ) {
			wp_enqueue_script( 'llt-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		}
	}
}
