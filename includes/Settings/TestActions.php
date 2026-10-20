<?php
/**
 * Butoanele de test și diagnosticul din pagina de setări.
 *
 * @package LocationLeadTracker
 */

namespace LocationLeadTracker\Settings;

use LocationLeadTracker\Alerts\AdminNotifier;
use LocationLeadTracker\Http\ClientIp;
use LocationLeadTracker\Postmark\EmailBuilder;
use LocationLeadTracker\Postmark\ErrorClassifier;
use LocationLeadTracker\Postmark\PostmarkClient;
use LocationLeadTracker\Postmark\SendResult;

defined( 'ABSPATH' ) || exit;

final class TestActions {

	public static function register(): void {
		add_action( 'admin_post_llt_test_email', array( self::class, 'handle_test_email' ) );
		add_action( 'admin_post_llt_test_alert', array( self::class, 'handle_test_alert' ) );
	}

	public static function render_test_email(): void {
		?>
		<hr>
		<h2>Email de test</h2>
		<p>Trimite un email prin Postmark cu setările salvate. Cât timp contul Postmark este în aprobare, folosește o adresă de pe domeniul expeditorului.</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="llt_test_email">
			<?php wp_nonce_field( 'llt_test_email' ); ?>
			<input type="email" name="llt_test_to" class="regular-text" required value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>">
			<?php submit_button( 'Trimite email de test', 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	public static function render_test_alert(): void {
		?>
		<hr>
		<h2>Alertă de test</h2>
		<p>Trimite o alertă prin <code>wp_mail()</code>, exact pe canalul folosit pentru alertele reale. Dacă nu ajunge, configurează un plugin SMTP pentru WordPress sau activează fallback-ul prin Postmark.</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="llt_test_alert">
			<?php wp_nonce_field( 'llt_test_alert' ); ?>
			<?php submit_button( 'Trimite alertă de test', 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	public static function render_ip_diagnostic(): void {
		list( $ip, $method ) = ClientIp::detect();
		$remote              = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		?>
		<hr>
		<h2>Diagnostic IP</h2>
		<p>
			IP-ul tău detectat: <strong><?php echo esc_html( $ip ); ?></strong> — <?php echo esc_html( ClientIp::method_label( $method ) ); ?>.
			<?php if ( $remote && $remote !== $ip ) : ?>
				<br>Conexiunea a venit de la <code><?php echo esc_html( $remote ); ?></code>.
			<?php endif; ?>
		</p>
		<p class="description">Dacă IP-ul afișat nu este al tău (de exemplu este IP-ul serverului sau al unui CDN), adaugă rețeaua acelui proxy în „Proxy-uri suplimentare”. Altfel, toți vizitatorii vor împărți aceeași limită de mesaje.</p>
		<?php
	}

	public static function handle_test_email(): void {
		self::guard( 'llt_test_email' );
		$to = isset( $_POST['llt_test_to'] ) ? sanitize_email( wp_unslash( $_POST['llt_test_to'] ) ) : '';
		if ( ! is_email( $to ) ) {
			AdminNotice::add( 'error', 'Adresa de test nu este validă.' );
			self::back( 'postmark' );
		}

		$result = ( new PostmarkClient() )->send(
			array(
				'From'          => EmailBuilder::from(),
				'To'            => $to,
				'Subject'       => '[' . EmailBuilder::site_name() . '] Email de test Location Lead Tracker',
				'TextBody'      => "Configurarea Postmark funcționează.\n\nAcest email a fost trimis din Setări → Location Lead Tracker → Postmark.",
				'MessageStream' => (string) Settings::get( 'postmark_stream' ),
				'Tag'           => 'llt-test',
				'TrackOpens'    => false,
				'TrackLinks'    => 'None',
			)
		);

		if ( $result->is( SendResult::ACCEPTED ) ) {
			AdminNotice::add( 'success', sprintf( 'Postmark a acceptat emailul de test către <strong>%s</strong> (MessageID <code>%s</code>).', esc_html( $to ), esc_html( $result->message_id ) ) );
		} else {
			AdminNotice::add(
				'error',
				sprintf(
					'Emailul de test nu a fost trimis: %s <code>%s</code>%s',
					esc_html( ErrorClassifier::describe( $result->code ) ),
					esc_html( $result->code ),
					$result->message ? '<br>' . esc_html( $result->message ) : ''
				)
			);
		}
		self::back( 'postmark' );
	}

	public static function handle_test_alert(): void {
		self::guard( 'llt_test_alert' );
		$result = AdminNotifier::test();
		if ( $result['ok'] ) {
			AdminNotice::add( 'success', sprintf( 'Alerta de test a fost trimisă către <strong>%s</strong>. Verifică și folderul Spam.', esc_html( $result['to'] ) ) );
		} else {
			AdminNotice::add(
				'error',
				sprintf(
					'Alerta de test nu a putut fi trimisă către <strong>%s</strong>: %s',
					esc_html( $result['to'] ),
					esc_html( $result['error'] ?: 'wp_mail() a eșuat.' )
				)
			);
		}
		self::back( 'alerts' );
	}

	private static function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Nu ai permisiunea necesară.', 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * @return never
	 */
	private static function back( string $tab ): void {
		wp_safe_redirect( SettingsPage::url( $tab ) );
		exit;
	}
}
