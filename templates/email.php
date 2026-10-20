<?php
/**
 * The HTML body of the email sent to the location (used when the email format setting is HTML).
 *
 * Can be overridden from the theme: `enspire-email-leads-tracker/email.php`.
 *
 * Variables: $eelt — site_name, post_title, name, email, phone, message, date, lead_id.
 *
 * @package EnspireEmailLeadsTracker
 */

defined( 'ABSPATH' ) || exit;
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title><?php echo esc_html( $eelt['post_title'] ); ?></title></head>
<body style="margin:0;padding:24px;background:#f5f5f4;font-family:Arial,Helvetica,sans-serif;color:#1c1917;">
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:8px;">
		<tr><td style="padding:24px 24px 8px;">
			<p style="margin:0 0 4px;font-size:13px;color:#78716c;"><?php echo esc_html( $eelt['site_name'] ); ?></p>
			<h1 style="margin:0;font-size:20px;">New message for "<?php echo esc_html( $eelt['post_title'] ); ?>"</h1>
		</td></tr>
		<tr><td style="padding:16px 24px;">
			<table role="presentation" cellpadding="0" cellspacing="0" style="font-size:14px;line-height:1.6;">
				<tr><td style="padding-right:16px;color:#78716c;">Name</td><td><?php echo esc_html( $eelt['name'] ); ?></td></tr>
				<tr><td style="padding-right:16px;color:#78716c;">Email</td><td><a href="mailto:<?php echo esc_attr( $eelt['email'] ); ?>"><?php echo esc_html( $eelt['email'] ); ?></a></td></tr>
				<?php if ( '' !== $eelt['phone'] ) : ?>
					<tr><td style="padding-right:16px;color:#78716c;">Phone</td><td><?php echo esc_html( $eelt['phone'] ); ?></td></tr>
				<?php endif; ?>
				<tr><td style="padding-right:16px;color:#78716c;">Date</td><td><?php echo esc_html( $eelt['date'] ); ?></td></tr>
			</table>
		</td></tr>
		<tr><td style="padding:0 24px 16px;">
			<div style="padding:16px;background:#fafaf9;border-radius:6px;font-size:15px;line-height:1.6;white-space:pre-wrap;"><?php echo esc_html( $eelt['message'] ); ?></div>
		</td></tr>
		<tr><td style="padding:0 24px 24px;font-size:12px;color:#78716c;">
			Reply directly to this email to write to the visitor. Reference: #<?php echo (int) $eelt['lead_id']; ?>
		</td></tr>
	</table>
</body>
</html>
