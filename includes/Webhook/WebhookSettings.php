<?php
/**
 * v2 settings: the Webhook tab, the extra fields in Alerts and Retention, rows in Status and WP-CLI.
 *
 * @package EnspireEmailLeadsTracker
 */

namespace EnspireEmailLeadsTracker\Webhook;

use EnspireEmailLeadsTracker\Settings\AdminNotice;
use EnspireEmailLeadsTracker\Settings\SecretStore;
use EnspireEmailLeadsTracker\Settings\Settings;
use EnspireEmailLeadsTracker\Settings\SettingsPage;
use EnspireEmailLeadsTracker\Settings\StatusTab;
use EnspireEmailLeadsTracker\State;

defined( 'ABSPATH' ) || exit;

final class WebhookSettings {

	public static function register(): void {
		add_filter( 'llt_settings_tabs', array( self::class, 'tabs' ) );
		add_filter( 'llt_status_rows', array( self::class, 'status_rows' ), 10, 2 );
		add_filter( 'llt_cli_status_rows', array( self::class, 'cli_status_rows' ), 10, 2 );
		add_action( 'admin_post_llt_webhook_credentials', array( self::class, 'handle_generate' ) );
		add_filter( 'llt_cli_commands', array( self::class, 'cli_commands' ) );
	}

	/**
	 * @param array $commands WP-CLI commands.
	 */
	public static function cli_commands( $commands ): array {
		$commands                      = (array) $commands;
		$commands['llt reconcile run'] = array( array( Reconciler::class, 'cli' ), 'Checks in Postmark the delivery of leads without confirmation (v2).' );
		return $commands;
	}

	/**
	 * @param array $tabs Existing tabs.
	 */
	public static function tabs( $tabs ): array {
		$tabs = (array) $tabs;

		$tabs['alerts']['fields']['alert_on_bounce'] = array(
			'type'  => 'checkbox',
			'label' => 'Bounces',
			'text'  => 'Send an alert (grouped, hourly) when an email is permanently rejected',
		);

		$tabs['retention']['fields'] = self::insert_after(
			$tabs['retention']['fields'] ?? array(),
			'retention_failed_days',
			array(
				'retention_unconfirmed_days' => array(
					'type'  => 'number',
					'label' => 'Days without confirmation',
					'desc'  => 'When delivery confirmation is on: the content is deleted right after delivery, or after this many days if delivery is not confirmed.',
					'min'   => 1,
					'max'   => 365,
				),
			)
		);
		$tabs['retention']['fields']['retention_accepted_hours']['desc'] = 'Used only when delivery confirmation (Webhook tab) is off.';

		$webhook = array(
			'webhook' => array(
				'label'  => 'Webhook',
				'fields' => array(
					'webhook_enabled'   => array(
						'type'  => 'checkbox',
						'label' => 'Delivery confirmation',
						'text'  => 'Enable the Postmark webhook (Delivery and Bounce) and the hourly reconciliation',
					),
					'webhook_ip_filter' => array(
						'type'  => 'checkbox',
						'label' => 'IP filter',
						'text'  => 'Accept only requests from the webhook IPs published by Postmark',
						'desc'  => 'Optional. Basic Auth is enough; enable this only if you want extra protection. Postmark may change its IPs.',
					),
				),
				'after'  => array( self::class, 'render_tab' ),
			),
		);

		// The Webhook tab goes before the Status tab.
		$position = array_search( 'status', array_keys( $tabs ), true );
		if ( false === $position ) {
			return array_merge( $tabs, $webhook );
		}
		return array_slice( $tabs, 0, (int) $position, true ) + $webhook + array_slice( $tabs, (int) $position, null, true );
	}

	public static function render_tab(): void {
		$user      = (string) Settings::get( 'webhook_user' );
		$has_creds = '' !== $user && Settings::has_secret( 'webhook_pass' );
		$url       = WebhookController::url();
		$parts     = wp_parse_url( $url );
		$masked    = $has_creds
			? sprintf( '%s://%s:••••••••@%s%s%s', $parts['scheme'] ?? 'https', $user, $parts['host'] ?? '', isset( $parts['port'] ) ? ':' . $parts['port'] : '', $parts['path'] ?? '' )
			: $url;
		$state     = State::all();
		?>
		<hr>
		<h2>Setting it up in Postmark</h2>
		<?php if ( 0 !== strpos( $url, 'https://' ) ) : ?>
			<div class="notice notice-error inline"><p>The site does not use HTTPS. The webhook must be reachable over HTTPS, otherwise the credentials travel unencrypted.</p></div>
		<?php endif; ?>
		<ol>
			<li>Generate the credentials below and copy the full URL, which is shown only once.</li>
			<li>In Postmark: Server → Message Stream <code><?php echo esc_html( (string) Settings::get( 'postmark_stream' ) ); ?></code> → Webhooks → Add webhook.</li>
			<li>Paste the URL, tick <strong>Delivery</strong> and <strong>Bounce</strong>, then click <strong>Verify</strong> and save.</li>
			<li>Tick "Enable the Postmark webhook" above and save the settings.</li>
		</ol>
		<p>Webhook URL: <code class="llt-copyable"><?php echo esc_html( $masked ); ?></code></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="llt_webhook_credentials">
			<?php wp_nonce_field( 'llt_webhook_credentials' ); ?>
			<?php
			submit_button(
				$has_creds ? 'Regenerate credentials' : 'Generate credentials',
				'secondary',
				'submit',
				false,
				$has_creds ? array( 'onclick' => "return confirm('The existing webhook in Postmark will stop working until you update the URL there. Continue?');" ) : array()
			);
			?>
		</form>
		<?php if ( ! empty( $state['last_webhook_rejected']['at'] ) ) : ?>
			<p class="description">
				Last rejected request: <?php echo esc_html( StatusTab::ago( (int) $state['last_webhook_rejected']['at'] ) ); ?> —
				<?php echo esc_html( WebhookAuth::describe( (string) $state['last_webhook_rejected']['reason'] ) ); ?>.
			</p>
		<?php endif; ?>
		<p class="description">If Postmark reports 401 on Verify even though the credentials are correct, the server is probably stripping the <code>Authorization</code> header. On Apache, add to <code>.htaccess</code>: <code>SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1</code></p>
		<?php
	}

	public static function handle_generate(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You do not have the required permission.', 403 );
		}
		check_admin_referer( 'llt_webhook_credentials' );

		$user = 'llt' . strtolower( wp_generate_password( 9, false, false ) );
		$pass = wp_generate_password( 32, false, false );

		$settings                 = get_option( Settings::OPTION, array() );
		$settings                 = is_array( $settings ) ? $settings : array();
		$settings['webhook_user'] = $user;
		$settings['webhook_pass'] = SecretStore::encrypt( $pass );
		update_option( Settings::OPTION, $settings, false );
		Settings::flush();

		$parts = wp_parse_url( WebhookController::url() );
		$full  = sprintf(
			'%s://%s:%s@%s%s%s',
			$parts['scheme'] ?? 'https',
			rawurlencode( $user ),
			rawurlencode( $pass ),
			$parts['host'] ?? '',
			isset( $parts['port'] ) ? ':' . $parts['port'] : '',
			$parts['path'] ?? ''
		);

		AdminNotice::add(
			'success',
			'New credentials generated. Copy the full URL into Postmark now; the password will not be shown again:<br><code>' . esc_html( $full ) . '</code>'
		);
		wp_safe_redirect( SettingsPage::url( 'webhook' ) );
		exit;
	}

	/**
	 * @param array $rows  Rows of the Status tab.
	 * @param array $state Plugin state.
	 */
	public static function status_rows( $rows, $state ): array {
		$rows = (array) $rows;
		if ( ! Settings::tracking_enabled() ) {
			$rows['Delivery confirmation'] = 'disabled';
			return $rows;
		}
		$counts                        = \EnspireEmailLeadsTracker\Database\LeadRepository::counts();
		$rows['Delivery confirmation'] = 'enabled';
		$rows['Delivered']             = (string) $counts['delivery']['delivered'];
		$rows['Bounced']               = (string) $counts['delivery']['bounced'];
		$rows['Deferred']              = (string) $counts['delivery']['deferred'];
		$rows['Last webhook']          = ! empty( $state['last_webhook']['at'] )
			? StatusTab::ago( (int) $state['last_webhook']['at'] ) . ' (' . $state['last_webhook']['type'] . ', ' . $state['last_webhook']['result'] . ')'
			: 'none';
		$rows['Last reconciliation']   = ! empty( $state['last_reconcile']['at'] )
			? StatusTab::ago( (int) $state['last_reconcile']['at'] ) . sprintf( ' (checked %d, delivered %d, bounced %d)', $state['last_reconcile']['checked'], $state['last_reconcile']['delivered'], $state['last_reconcile']['bounced'] )
			: 'none';
		return $rows;
	}

	/**
	 * @param array $rows  Rows of `wp llt status`.
	 * @param array $state Plugin state.
	 */
	public static function cli_status_rows( $rows, $state ): array {
		$rows   = (array) $rows;
		$rows[] = array( 'Key' => 'Delivery confirmation', 'Value' => Settings::tracking_enabled() ? 'enabled' : 'disabled' );
		$rows[] = array( 'Key' => 'Last webhook', 'Value' => ! empty( $state['last_webhook']['at'] ) ? gmdate( 'Y-m-d H:i:s', (int) $state['last_webhook']['at'] ) . ' UTC' : 'none' );
		$rows[] = array( 'Key' => 'Last reconciliation', 'Value' => ! empty( $state['last_reconcile']['at'] ) ? gmdate( 'Y-m-d H:i:s', (int) $state['last_reconcile']['at'] ) . ' UTC' : 'none' );
		return $rows;
	}

	private static function insert_after( array $fields, string $after, array $new ): array {
		$result = array();
		foreach ( $fields as $key => $field ) {
			$result[ $key ] = $field;
			if ( $key === $after ) {
				$result += $new;
			}
		}
		return $result + $new;
	}
}
