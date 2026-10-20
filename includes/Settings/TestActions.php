<?php
/**
 * The test buttons and diagnostics on the settings page.
 *
 * @package EmailLeadsTracker
 */

namespace EmailLeadsTracker\Settings;

use EmailLeadsTracker\Alerts\AdminNotifier;
use EmailLeadsTracker\Http\ClientIp;
use EmailLeadsTracker\Postmark\EmailBuilder;
use EmailLeadsTracker\Postmark\ErrorClassifier;
use EmailLeadsTracker\Postmark\PostmarkClient;
use EmailLeadsTracker\Postmark\SendResult;

defined( 'ABSPATH' ) || exit;

final class TestActions {

	public static function register(): void {
		add_action( 'admin_post_llt_test_email', array( self::class, 'handle_test_email' ) );
		add_action( 'admin_post_llt_test_alert', array( self::class, 'handle_test_alert' ) );
	}

	public static function render_test_email(): void {
		?>
		<hr>
		<h2>Test email</h2>
		<p>Sends an email through Postmark with the saved settings. While the Postmark account is pending approval, use an address on the sender's domain.</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="llt_test_email">
			<?php wp_nonce_field( 'llt_test_email' ); ?>
			<input type="email" name="llt_test_to" class="regular-text" required value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>">
			<?php submit_button( 'Send test email', 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	public static function render_test_alert(): void {
		?>
		<hr>
		<h2>Test alert</h2>
		<p>Sends an alert through <code>wp_mail()</code>, exactly the channel used for real alerts. If it does not arrive, configure an SMTP plugin for WordPress or enable the Postmark fallback.</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="llt_test_alert">
			<?php wp_nonce_field( 'llt_test_alert' ); ?>
			<?php submit_button( 'Send test alert', 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	public static function render_ip_diagnostic(): void {
		list( $ip, $method ) = ClientIp::detect();
		$remote              = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		?>
		<hr>
		<h2>IP diagnostics</h2>
		<p>
			Your detected IP: <strong><?php echo esc_html( $ip ); ?></strong> — <?php echo esc_html( ClientIp::method_label( $method ) ); ?>.
			<?php if ( $remote && $remote !== $ip ) : ?>
				<br>The connection came from <code><?php echo esc_html( $remote ); ?></code>.
			<?php endif; ?>
		</p>
		<p class="description">If the IP shown is not yours (for example it is the server's or a CDN's IP), add that proxy's network under "Additional proxies". Otherwise all visitors will share the same message limit.</p>
		<?php
	}

	public static function handle_test_email(): void {
		self::guard( 'llt_test_email' );
		$to = isset( $_POST['llt_test_to'] ) ? sanitize_email( wp_unslash( $_POST['llt_test_to'] ) ) : '';
		if ( ! is_email( $to ) ) {
			AdminNotice::add( 'error', 'The test address is not valid.' );
			self::back( 'postmark' );
		}

		$result = ( new PostmarkClient() )->send(
			array(
				'From'          => EmailBuilder::from(),
				'To'            => $to,
				'Subject'       => '[' . EmailBuilder::site_name() . '] Email Leads Tracker test email',
				'TextBody'      => "The Postmark configuration works.\n\nThis email was sent from Settings → Email Leads Tracker → Postmark.",
				'MessageStream' => (string) Settings::get( 'postmark_stream' ),
				'Tag'           => 'llt-test',
				'TrackOpens'    => false,
				'TrackLinks'    => 'None',
			)
		);

		if ( $result->is( SendResult::ACCEPTED ) ) {
			AdminNotice::add( 'success', sprintf( 'Postmark accepted the test email to <strong>%s</strong> (MessageID <code>%s</code>).', esc_html( $to ), esc_html( $result->message_id ) ) );
		} else {
			AdminNotice::add(
				'error',
				sprintf(
					'The test email was not sent: %s <code>%s</code>%s',
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
			AdminNotice::add( 'success', sprintf( 'The test alert was sent to <strong>%s</strong>. Check the Spam folder too.', esc_html( $result['to'] ) ) );
		} else {
			AdminNotice::add(
				'error',
				sprintf(
					'The test alert could not be sent to <strong>%s</strong>: %s',
					esc_html( $result['to'] ),
					esc_html( $result['error'] ?: 'wp_mail() failed.' )
				)
			);
		}
		self::back( 'alerts' );
	}

	private static function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You do not have the required permission.', 403 );
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
