<?php
/**
 * The two contact form shortcodes:
 *
 *   [enspire_contact_form]  — the form, shown directly in the page.
 *   [enspire_contact_popup] — a button that opens the form in a SweetAlert2 popup;
 *                             success and error messages are shown in the popup too.
 *
 * Both accept `object_id` (the location post; default: the current post).
 *
 * @package EnspireEmailLeadsTracker
 */

namespace EnspireEmailLeadsTracker\Shortcode;

use EnspireEmailLeadsTracker\Form\Messages;
use EnspireEmailLeadsTracker\Settings\Settings;
use EnspireEmailLeadsTracker\Template;

defined( 'ABSPATH' ) || exit;

final class ContactShortcode {

	const INLINE = 'enspire_contact_form';
	const POPUP  = 'enspire_contact_popup';

	/** Version of the bundled SweetAlert2 (assets/vendor/sweetalert2). */
	const SWEETALERT2_VERSION = '11.26.25';

	/** @var int */
	private static $instance = 0;

	public static function register(): void {
		add_shortcode( self::INLINE, array( self::class, 'render' ) );
		add_shortcode( self::POPUP, array( self::class, 'render_popup' ) );
	}

	/**
	 * Default attributes of [enspire_contact_form].
	 *
	 * @return array<string,string|int>
	 */
	public static function inline_defaults(): array {
		return array(
			'object_id'   => 0,
			'class'       => '',
			'submit_text' => 'Send',
		);
	}

	/**
	 * Default attributes of [enspire_contact_popup].
	 *
	 * @return array<string,string|int>
	 */
	public static function popup_defaults(): array {
		return array(
			'object_id'    => 0,
			'button_text'  => 'Contact us',
			'button_class' => '',
			'class'        => '',
			'title'        => 'Send us a message',
			'submit_text'  => 'Send',
			'popup_class'  => '',
			'error_title'  => 'Message not sent',
		);
	}

	/**
	 * [enspire_contact_form object_id="245" class="my-form" submit_text="Send"]
	 *
	 * @param array|string $atts Shortcode attributes.
	 */
	public static function render( $atts ): string {
		$atts = shortcode_atts( self::inline_defaults(), $atts, self::INLINE );
		$form = self::prepare( (int) absint( $atts['object_id'] ) );
		if ( is_string( $form ) ) {
			return $form;
		}

		self::enqueue_assets( false );

		return Template::render(
			'form.php',
			array_merge(
				$form,
				array(
					'mode'        => 'inline',
					'class'       => self::classes( (string) $atts['class'] ),
					'submit_text' => self::text( $atts['submit_text'], 'Send' ),
				)
			)
		);
	}

	/**
	 * [enspire_contact_popup object_id="245" button_text="Contact us" button_class="btn btn-primary"
	 *  title="Send us a message" submit_text="Send" popup_class="my-popup" class="my-wrapper"]
	 *
	 * @param array|string $atts Shortcode attributes.
	 */
	public static function render_popup( $atts ): string {
		$atts = shortcode_atts( self::popup_defaults(), $atts, self::POPUP );
		$form = self::prepare( (int) absint( $atts['object_id'] ) );
		if ( is_string( $form ) ) {
			return $form;
		}

		self::enqueue_assets( true );

		$submit_text = self::text( $atts['submit_text'], 'Send' );

		// The form inside the popup (no own submit button: the popup's confirm button submits it).
		$popup_form = Template::render(
			'form.php',
			array_merge(
				$form,
				array(
					'mode'           => 'popup',
					'class'          => '',
					'submit_text'    => $submit_text,
					'status'         => '',
					'status_ok'      => false,
					'status_message' => '',
				)
			)
		);
		// Without JavaScript the popup cannot open: the same form is shown inline in <noscript>.
		$fallback_form = Template::render(
			'form.php',
			array_merge(
				$form,
				array(
					'mode'        => 'inline',
					'class'       => '',
					'submit_text' => $submit_text,
				)
			)
		);

		return Template::render(
			'popup.php',
			array(
				'form_id'       => $form['form_id'],
				'class'         => self::classes( (string) $atts['class'] ),
				'button_text'   => self::text( $atts['button_text'], 'Contact us' ),
				'button_class'  => self::classes( (string) $atts['button_class'] ),
				'title'         => self::text( $atts['title'], '' ),
				'submit_text'   => $submit_text,
				'popup_class'   => self::classes( (string) $atts['popup_class'] ),
				'error_title'   => self::text( $atts['error_title'], 'Message not sent' ),
				'popup_form'    => $popup_form,
				'fallback_form' => $fallback_form,
				'post'          => $form['post'],
			)
		);
	}

	/**
	 * Resolves the recipient and builds the variables shared by both shortcodes.
	 *
	 * @return array<string,mixed>|string The template variables, or the HTML to output instead (error notice or '').
	 */
	private static function prepare( int $object_id ) {
		if ( ! $object_id ) {
			$object_id = (int) get_the_ID();
		}

		$resolved = RecipientResolver::resolve( $object_id );
		if ( is_wp_error( $resolved ) ) {
			if ( current_user_can( 'edit_posts' ) ) {
				return '<div class="eelt-notice eelt-notice--error"><strong>Enspire Email Leads Tracker:</strong> '
					. esc_html( $resolved->get_error_message() )
					. ' The form is not shown to visitors.</div>';
			}
			return '';
		}

		++self::$instance;
		$form_id = 'eelt-form-' . self::$instance;
		$context = ContextSigner::sign( $object_id );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- only displays the result after a redirect.
		$status = '';
		if ( isset( $_GET['eelt_status'], $_GET['eelt_form'] ) && sanitize_key( wp_unslash( $_GET['eelt_form'] ) ) === $form_id ) {
			$status = sanitize_key( wp_unslash( $_GET['eelt_status'] ) );
		}
		// phpcs:enable

		return array(
			'form_id'        => $form_id,
			'action'         => admin_url( 'admin-post.php' ),
			'ctx'            => $context['ctx'],
			'sig'            => $context['sig'],
			'return_url'     => remove_query_arg( array( 'eelt_status', 'eelt_form' ), self::current_url() ),
			'status'         => $status,
			'status_ok'      => 'success' === $status,
			'status_message' => '' !== $status ? Messages::get( $status ) : '',
			'consent_html'   => self::consent_html(),
			'turnstile_key'  => self::turnstile_enabled() ? (string) Settings::get( 'turnstile_site_key' ) : '',
			'post'           => $resolved['post'],
		);
	}

	/**
	 * A space-separated list of CSS classes, each one sanitized.
	 */
	public static function classes( string $value ): string {
		$classes = array();
		foreach ( preg_split( '/\s+/', trim( $value ) ) ?: array() as $class ) {
			$class = sanitize_html_class( $class );
			if ( '' !== $class ) {
				$classes[] = $class;
			}
		}
		return implode( ' ', array_unique( $classes ) );
	}

	/**
	 * A plain-text attribute value, or the default when it is empty.
	 *
	 * @param mixed $value Attribute value.
	 */
	private static function text( $value, string $default ): string {
		$value = trim( sanitize_text_field( (string) $value ) );
		return '' !== $value ? $value : $default;
	}

	/**
	 * The consent text, with HTML limited to links and simple formatting.
	 */
	public static function consent_html(): string {
		$html = wp_kses( (string) Settings::get( 'consent_text' ), self::consent_allowed_tags() );
		// Links that open in a new tab get rel="noopener".
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
	 * URL of the current page. The domain comes from home_url() (not from the Host header) and the path from
	 * REQUEST_URI, which already includes the subdirectory of an install such as example.com/blog/.
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

	private static function enqueue_assets( bool $popup ): void {
		wp_enqueue_style( 'eelt-form', EELT_URL . 'assets/form.css', array(), EELT_VERSION );
		wp_enqueue_script( 'eelt-form', EELT_URL . 'assets/form.js', array(), EELT_VERSION, true );
		if ( self::turnstile_enabled() ) {
			wp_enqueue_script( 'eelt-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		}
		if ( ! $popup ) {
			return;
		}

		$deps = array( 'eelt-form' );
		/**
		 * Whether the plugin loads its bundled SweetAlert2. Return false if the theme already loads it
		 * under another handle; a script registered as `sweetalert2` is reused automatically.
		 */
		if ( apply_filters( 'eelt_enqueue_sweetalert2', true ) ) {
			if ( ! wp_script_is( 'sweetalert2', 'registered' ) ) {
				wp_register_script( 'sweetalert2', EELT_URL . 'assets/vendor/sweetalert2/sweetalert2.all.min.js', array(), self::SWEETALERT2_VERSION, true );
			}
			$deps[] = 'sweetalert2';
		}
		wp_enqueue_script( 'eelt-popup', EELT_URL . 'assets/popup.js', $deps, EELT_VERSION, true );
	}
}
