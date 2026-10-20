<?php
/**
 * The contact form template.
 *
 * Can be overridden from the theme: copy the file to `wp-content/themes/<theme>/location-lead-tracker/form.php`.
 * The hidden fields and the field names (llt_*) must be kept.
 *
 * Variables: $llt — form_id, action, ctx, sig, return_url, status, status_ok, status_message,
 * consent_html, turnstile_key, post.
 *
 * @package LocationLeadTracker
 */

defined( 'ABSPATH' ) || exit;

$llt_id = $llt['form_id'];
?>
<div class="llt-form-wrap" id="<?php echo esc_attr( $llt_id ); ?>">
	<form class="llt-form" method="post" action="<?php echo esc_url( $llt['action'] ); ?>" novalidate>
		<input type="hidden" name="action" value="llt_submit">
		<input type="hidden" name="llt_ctx" value="<?php echo esc_attr( $llt['ctx'] ); ?>">
		<input type="hidden" name="llt_sig" value="<?php echo esc_attr( $llt['sig'] ); ?>">
		<input type="hidden" name="llt_form" value="<?php echo esc_attr( $llt_id ); ?>">
		<input type="hidden" name="llt_return" value="<?php echo esc_url( $llt['return_url'] ); ?>">

		<div class="llt-hp" aria-hidden="true">
			<label for="<?php echo esc_attr( $llt_id ); ?>-hp">Leave this field empty</label>
			<input type="text" id="<?php echo esc_attr( $llt_id ); ?>-hp" name="llt_hp" value="" tabindex="-1" autocomplete="off">
		</div>

		<div class="llt-status<?php echo $llt['status'] ? ( $llt['status_ok'] ? ' llt-status--ok' : ' llt-status--error' ) : ''; ?>" role="status" aria-live="polite"><?php echo esc_html( $llt['status_message'] ); ?></div>

		<p class="llt-field">
			<label for="<?php echo esc_attr( $llt_id ); ?>-name">Full name <span class="llt-required" aria-hidden="true">*</span></label>
			<input type="text" id="<?php echo esc_attr( $llt_id ); ?>-name" name="llt_name" maxlength="200" autocomplete="name" required>
			<span class="llt-error" data-for="llt_name"></span>
		</p>

		<p class="llt-field">
			<label for="<?php echo esc_attr( $llt_id ); ?>-email">Email <span class="llt-required" aria-hidden="true">*</span></label>
			<input type="email" id="<?php echo esc_attr( $llt_id ); ?>-email" name="llt_email" maxlength="254" autocomplete="email" required>
			<span class="llt-error" data-for="llt_email"></span>
		</p>

		<p class="llt-field">
			<label for="<?php echo esc_attr( $llt_id ); ?>-phone">Phone number</label>
			<input type="tel" id="<?php echo esc_attr( $llt_id ); ?>-phone" name="llt_phone" maxlength="50" autocomplete="tel">
			<span class="llt-error" data-for="llt_phone"></span>
		</p>

		<p class="llt-field">
			<label for="<?php echo esc_attr( $llt_id ); ?>-message">Message <span class="llt-required" aria-hidden="true">*</span></label>
			<textarea id="<?php echo esc_attr( $llt_id ); ?>-message" name="llt_message" rows="5" maxlength="5000" required></textarea>
			<span class="llt-error" data-for="llt_message"></span>
		</p>

		<p class="llt-field llt-field--consent">
			<label>
				<input type="checkbox" name="llt_consent" value="1" required>
				<span><?php echo $llt['consent_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- filtered with wp_kses. ?></span>
			</label>
			<span class="llt-error" data-for="llt_consent"></span>
		</p>

		<?php if ( '' !== $llt['turnstile_key'] ) : ?>
			<div class="cf-turnstile" data-sitekey="<?php echo esc_attr( $llt['turnstile_key'] ); ?>"></div>
		<?php endif; ?>

		<p class="llt-actions">
			<button type="submit" class="llt-submit">Send</button>
		</p>
	</form>
</div>
