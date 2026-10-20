<?php
/**
 * Corpul HTML al emailului trimis locației (folosit când formatul din setări este HTML).
 *
 * Poate fi suprascris din temă: `location-lead-tracker/email.php`.
 *
 * Variabile: $llt — site_name, post_title, name, email, phone, message, date, lead_id.
 *
 * @package LocationLeadTracker
 */

defined( 'ABSPATH' ) || exit;
?>
<!doctype html>
<html lang="ro">
<head><meta charset="utf-8"><title><?php echo esc_html( $llt['post_title'] ); ?></title></head>
<body style="margin:0;padding:24px;background:#f5f5f4;font-family:Arial,Helvetica,sans-serif;color:#1c1917;">
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:8px;">
		<tr><td style="padding:24px 24px 8px;">
			<p style="margin:0 0 4px;font-size:13px;color:#78716c;"><?php echo esc_html( $llt['site_name'] ); ?></p>
			<h1 style="margin:0;font-size:20px;">Mesaj nou pentru „<?php echo esc_html( $llt['post_title'] ); ?>”</h1>
		</td></tr>
		<tr><td style="padding:16px 24px;">
			<table role="presentation" cellpadding="0" cellspacing="0" style="font-size:14px;line-height:1.6;">
				<tr><td style="padding-right:16px;color:#78716c;">Nume</td><td><?php echo esc_html( $llt['name'] ); ?></td></tr>
				<tr><td style="padding-right:16px;color:#78716c;">Email</td><td><a href="mailto:<?php echo esc_attr( $llt['email'] ); ?>"><?php echo esc_html( $llt['email'] ); ?></a></td></tr>
				<?php if ( '' !== $llt['phone'] ) : ?>
					<tr><td style="padding-right:16px;color:#78716c;">Telefon</td><td><?php echo esc_html( $llt['phone'] ); ?></td></tr>
				<?php endif; ?>
				<tr><td style="padding-right:16px;color:#78716c;">Data</td><td><?php echo esc_html( $llt['date'] ); ?></td></tr>
			</table>
		</td></tr>
		<tr><td style="padding:0 24px 16px;">
			<div style="padding:16px;background:#fafaf9;border-radius:6px;font-size:15px;line-height:1.6;white-space:pre-wrap;"><?php echo esc_html( $llt['message'] ); ?></div>
		</td></tr>
		<tr><td style="padding:0 24px 24px;font-size:12px;color:#78716c;">
			Răspunde direct la acest email pentru a-i scrie vizitatorului. Referință: #<?php echo (int) $llt['lead_id']; ?>
		</td></tr>
	</table>
</body>
</html>
