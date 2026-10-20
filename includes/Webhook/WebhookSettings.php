<?php
/**
 * Setările v2: tabul Webhook, câmpurile suplimentare din Alerte și Retenție, rânduri în Stare și WP-CLI.
 *
 * @package LocationLeadTracker
 */

namespace LocationLeadTracker\Webhook;

use LocationLeadTracker\Settings\AdminNotice;
use LocationLeadTracker\Settings\SecretStore;
use LocationLeadTracker\Settings\Settings;
use LocationLeadTracker\Settings\SettingsPage;
use LocationLeadTracker\Settings\StatusTab;
use LocationLeadTracker\State;

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
	 * @param array $commands Comenzile WP-CLI.
	 */
	public static function cli_commands( $commands ): array {
		$commands                      = (array) $commands;
		$commands['llt reconcile run'] = array( array( Reconciler::class, 'cli' ), 'Verifică în Postmark livrarea leadurilor fără confirmare (v2).' );
		return $commands;
	}

	/**
	 * @param array $tabs Taburile existente.
	 */
	public static function tabs( $tabs ): array {
		$tabs = (array) $tabs;

		$tabs['alerts']['fields']['alert_on_bounce'] = array(
			'type'  => 'checkbox',
			'label' => 'Bounce',
			'text'  => 'Trimite o alertă (grupată, orar) când un email este respins definitiv',
		);

		$tabs['retention']['fields'] = self::insert_after(
			$tabs['retention']['fields'] ?? array(),
			'retention_failed_days',
			array(
				'retention_unconfirmed_days' => array(
					'type'  => 'number',
					'label' => 'Zile fără confirmare',
					'desc'  => 'Când confirmarea livrării este activă: conținutul se șterge imediat după livrare, sau după acest număr de zile dacă livrarea nu este confirmată.',
					'min'   => 1,
					'max'   => 365,
				),
			)
		);
		$tabs['retention']['fields']['retention_accepted_hours']['desc'] = 'Folosit doar când confirmarea livrării (tabul Webhook) nu este activă.';

		$webhook = array(
			'webhook' => array(
				'label'  => 'Webhook',
				'fields' => array(
					'webhook_enabled'   => array(
						'type'  => 'checkbox',
						'label' => 'Confirmarea livrării',
						'text'  => 'Activează webhookul Postmark (Delivery și Bounce) și reconcilierea orară',
					),
					'webhook_ip_filter' => array(
						'type'  => 'checkbox',
						'label' => 'Filtru IP',
						'text'  => 'Acceptă doar cereri de la IP-urile de webhook publicate de Postmark',
						'desc'  => 'Opțional. Basic Auth este suficient; activează doar dacă vrei o protecție suplimentară. Postmark poate schimba IP-urile.',
					),
				),
				'after'  => array( self::class, 'render_tab' ),
			),
		);

		// Tabul Webhook stă înaintea tabului Stare.
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
		<h2>Configurare în Postmark</h2>
		<?php if ( 0 !== strpos( $url, 'https://' ) ) : ?>
			<div class="notice notice-error inline"><p>Site-ul nu folosește HTTPS. Webhookul trebuie să fie accesibil prin HTTPS, altfel credențialele circulă necriptat.</p></div>
		<?php endif; ?>
		<ol>
			<li>Generează credențialele de mai jos și copiază URL-ul complet afișat o singură dată.</li>
			<li>În Postmark: Server → Message Stream <code><?php echo esc_html( (string) Settings::get( 'postmark_stream' ) ); ?></code> → Webhooks → Add webhook.</li>
			<li>Lipește URL-ul, bifează <strong>Delivery</strong> și <strong>Bounce</strong>, apoi apasă <strong>Verify</strong> și salvează.</li>
			<li>Bifează „Activează webhookul” mai sus și salvează setările.</li>
		</ol>
		<p>URL webhook: <code class="llt-copyable"><?php echo esc_html( $masked ); ?></code></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="llt_webhook_credentials">
			<?php wp_nonce_field( 'llt_webhook_credentials' ); ?>
			<?php
			submit_button(
				$has_creds ? 'Regenerează credențialele' : 'Generează credențialele',
				'secondary',
				'submit',
				false,
				$has_creds ? array( 'onclick' => "return confirm('Webhookul existent din Postmark nu va mai funcționa până nu actualizezi URL-ul acolo. Continui?');" ) : array()
			);
			?>
		</form>
		<?php if ( ! empty( $state['last_webhook_rejected']['at'] ) ) : ?>
			<p class="description">
				Ultima cerere respinsă: <?php echo esc_html( StatusTab::ago( (int) $state['last_webhook_rejected']['at'] ) ); ?> —
				<?php echo esc_html( WebhookAuth::describe( (string) $state['last_webhook_rejected']['reason'] ) ); ?>.
			</p>
		<?php endif; ?>
		<p class="description">Dacă Postmark raportează 401 la Verify deși credențialele sunt corecte, serverul elimină probabil headerul <code>Authorization</code>. Pe Apache adaugă în <code>.htaccess</code>: <code>SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1</code></p>
		<?php
	}

	public static function handle_generate(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Nu ai permisiunea necesară.', 403 );
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
			'Credențiale noi generate. Copiază acum URL-ul complet în Postmark; parola nu va mai fi afișată:<br><code>' . esc_html( $full ) . '</code>'
		);
		wp_safe_redirect( SettingsPage::url( 'webhook' ) );
		exit;
	}

	/**
	 * @param array $rows  Rândurile din tabul Stare.
	 * @param array $state Starea pluginului.
	 */
	public static function status_rows( $rows, $state ): array {
		$rows = (array) $rows;
		if ( ! Settings::tracking_enabled() ) {
			$rows['Confirmarea livrării'] = 'dezactivată';
			return $rows;
		}
		$counts                       = \LocationLeadTracker\Database\LeadRepository::counts();
		$rows['Confirmarea livrării'] = 'activă';
		$rows['Livrate']              = (string) $counts['delivery']['delivered'];
		$rows['Respinse']             = (string) $counts['delivery']['bounced'];
		$rows['Amânate']              = (string) $counts['delivery']['deferred'];
		$rows['Ultimul webhook']      = ! empty( $state['last_webhook']['at'] )
			? StatusTab::ago( (int) $state['last_webhook']['at'] ) . ' (' . $state['last_webhook']['type'] . ', ' . $state['last_webhook']['result'] . ')'
			: 'niciunul';
		$rows['Ultima reconciliere']  = ! empty( $state['last_reconcile']['at'] )
			? StatusTab::ago( (int) $state['last_reconcile']['at'] ) . sprintf( ' (verificate %d, livrate %d, respinse %d)', $state['last_reconcile']['checked'], $state['last_reconcile']['delivered'], $state['last_reconcile']['bounced'] )
			: 'niciuna';
		return $rows;
	}

	/**
	 * @param array $rows  Rândurile din `wp llt status`.
	 * @param array $state Starea pluginului.
	 */
	public static function cli_status_rows( $rows, $state ): array {
		$rows   = (array) $rows;
		$rows[] = array( 'Cheie' => 'Confirmarea livrării', 'Valoare' => Settings::tracking_enabled() ? 'activă' : 'dezactivată' );
		$rows[] = array( 'Cheie' => 'Ultimul webhook', 'Valoare' => ! empty( $state['last_webhook']['at'] ) ? gmdate( 'Y-m-d H:i:s', (int) $state['last_webhook']['at'] ) . ' UTC' : 'niciunul' );
		$rows[] = array( 'Cheie' => 'Ultima reconciliere', 'Valoare' => ! empty( $state['last_reconcile']['at'] ) ? gmdate( 'Y-m-d H:i:s', (int) $state['last_reconcile']['at'] ) . ' UTC' : 'niciuna' );
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
