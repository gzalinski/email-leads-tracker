<?php
/**
 * The Settings → Enspire Email Leads Tracker page.
 *
 * @package EnspireEmailLeadsTracker
 */

namespace EnspireEmailLeadsTracker\Settings;

use EnspireEmailLeadsTracker\State;

defined( 'ABSPATH' ) || exit;

final class SettingsPage {

	const SLUG  = 'enspire-email-leads-tracker';
	const GROUP = 'eelt_settings_group';

	/** @var string */
	private static $hook = '';

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_init', array( self::class, 'register_setting' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		TestActions::register();
	}

	public static function url( string $tab = 'general', array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $args ), admin_url( 'options-general.php' ) );
	}

	public static function menu(): void {
		$title      = (string) apply_filters( 'eelt_menu_title', 'Enspire Email Leads Tracker' );
		self::$hook = (string) add_options_page( 'Enspire Email Leads Tracker', $title, 'manage_options', self::SLUG, array( self::class, 'render' ) );
		do_action( 'eelt_settings_page_registered', self::$hook );
	}

	public static function hook(): string {
		return self::$hook;
	}

	public static function register_setting(): void {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( SettingsSanitizer::class, 'sanitize' ),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * @param string $hook The current admin page.
	 */
	public static function assets( $hook ): void {
		if ( $hook !== self::$hook ) {
			return;
		}
		wp_enqueue_style( 'eelt-admin', EELT_URL . 'assets/admin.css', array(), EELT_VERSION );
		wp_enqueue_script( 'eelt-admin', EELT_URL . 'assets/admin.js', array(), EELT_VERSION, true );
		wp_localize_script(
			'eelt-admin',
			'eeltAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'eelt_admin' ),
			)
		);
	}

	public static function current_tab(): string {
		$tabs = SettingsFields::tabs();
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $tabs[ $tab ] ) ? $tab : 'general';
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tabs    = SettingsFields::tabs();
		$current = self::current_tab();
		$tab     = $tabs[ $current ];
		$values  = Settings::all();

		echo '<div class="wrap eelt-admin">';
		echo '<h1>Enspire Email Leads Tracker</h1>';

		AdminNotice::render();
		self::render_secret_warning();
		self::render_migration_notice();

		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $definition ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( self::url( $key ) ),
				$key === $current ? ' nav-tab-active' : '',
				esc_html( $definition['label'] )
			);
		}
		echo '</nav>';

		if ( ! empty( $tab['fields'] ) ) {
			echo '<form method="post" action="options.php">';
			settings_fields( self::GROUP );
			printf( '<input type="hidden" name="%s[_tab]" value="%s">', esc_attr( Settings::OPTION ), esc_attr( $current ) );
			echo '<table class="form-table" role="presentation"><tbody>';
			foreach ( $tab['fields'] as $key => $field ) {
				self::field( $key, $field, $values );
			}
			echo '</tbody></table>';
			submit_button( 'Save settings' );
			echo '</form>';
		}
		if ( ! empty( $tab['render'] ) && is_callable( $tab['render'] ) ) {
			call_user_func( $tab['render'] );
		}
		if ( ! empty( $tab['after'] ) && is_callable( $tab['after'] ) ) {
			call_user_func( $tab['after'] );
		}

		echo '</div>';
	}

	/**
	 * Shown for 30 days after the migration from the pre-1.5.0 identifiers.
	 */
	private static function render_migration_notice(): void {
		$migrated = (int) State::get( 'legacy_migrated' );
		if ( ! $migrated || time() - $migrated > 30 * DAY_IN_SECONDS ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>The plugin was updated to new identifiers.</strong> Your leads and settings were kept. Three things need your attention:</p><ol>';
		echo '<li>Replace the shortcode <code>[location_contact]</code> with <code>[enspire_contact_form]</code> on your pages and templates (the attributes are the same).</li>';
		printf(
			'<li>If you use delivery confirmation, edit the webhook in Postmark: change the path from <code>/wp-json/llt/v1/postmark</code> to <code>%s</code>, keep the same user and password, and click Verify. Until then, the hourly reconciliation still confirms deliveries. (<a href="%s">Webhook tab</a>)</li>',
			esc_html( (string) wp_parse_url( \EnspireEmailLeadsTracker\Webhook\WebhookController::url(), PHP_URL_PATH ) ),
			esc_url( self::url( 'webhook' ) )
		);
		echo '<li>Clear the page cache, so cached forms use the new field names. The WP-CLI command is now <code>wp eelt</code>; in wp-config.php, rename <code>LLT_ENCRYPTION_KEY</code> to <code>EELT_ENCRYPTION_KEY</code> when convenient (the old name still works).</li>';
		echo '</ol></div>';
	}

	private static function render_secret_warning(): void {
		$unreadable = Settings::unreadable_secrets();
		if ( ! $unreadable ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>Some secrets can no longer be decrypted</strong> (';
		echo esc_html( implode( ', ', $unreadable ) );
		echo '). Most likely the site was moved or the WordPress salts changed. Enter the values again. To avoid this when moving the site, define <code>EELT_ENCRYPTION_KEY</code> in wp-config.php.</p></div>';
	}

	private static function field( string $key, array $field, array $values ): void {
		$name  = Settings::OPTION . '[' . $key . ']';
		$id    = 'eelt_' . $key;
		$value = $values[ $key ] ?? '';

		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . '</label></th><td>';

		switch ( $field['type'] ) {
			case 'checkbox':
				printf(
					'<label><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s> %4$s</label>',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( ! empty( $value ), true, false ),
					esc_html( $field['text'] ?? '' )
				);
				break;

			case 'secret':
				$saved = Settings::has_secret( $key );
				printf(
					'<input type="password" id="%1$s" name="%2$s" class="regular-text" autocomplete="new-password" placeholder="%3$s">',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $saved ? '•••••••• (saved — leave empty to keep it)' : '' )
				);
				if ( $saved ) {
					printf(
						' <label><input type="checkbox" name="%s" value="1"> Delete</label>',
						esc_attr( Settings::OPTION . '[' . $key . '_clear]' )
					);
				}
				break;

			case 'number':
				printf(
					'<input type="number" id="%1$s" name="%2$s" value="%3$d" min="%4$d" max="%5$d" class="small-text">',
					esc_attr( $id ),
					esc_attr( $name ),
					(int) $value,
					(int) ( $field['min'] ?? 0 ),
					(int) ( $field['max'] ?? 999999 )
				);
				break;

			case 'select':
				printf( '<select id="%1$s" name="%2$s">', esc_attr( $id ), esc_attr( $name ) );
				foreach ( $field['options'] as $option => $label ) {
					printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $option ), selected( $value, $option, false ), esc_html( $label ) );
				}
				echo '</select>';
				break;

			case 'html':
			case 'cidr_list':
				printf(
					'<textarea id="%1$s" name="%2$s" rows="4" class="large-text code">%3$s</textarea>',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_textarea( (string) $value )
				);
				break;

			case 'email':
			case 'schedule':
			case 'text':
			default:
				printf(
					'<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" class="regular-text">',
					'email' === $field['type'] ? 'email' : 'text',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value )
				);
				break;
		}

		if ( ! empty( $field['desc'] ) ) {
			echo '<p class="description">' . esc_html( $field['desc'] ) . '</p>';
		}
		echo '</td></tr>';
	}
}
