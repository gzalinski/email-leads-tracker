<?php
/**
 * The contact form template.
 *
 * Can be overridden from the theme: copy the file to `wp-content/themes/<theme>/enspire-email-leads-tracker/form.php`.
 * The hidden fields and the field names (eelt_*) must be kept.
 *
 * Variables: $eelt — form_id, action, ctx, sig, return_url, status, status_ok, status_message,
 * consent_html, turnstile_key, post.
 *
 * @package EnspireEmailLeadsTracker
 */

defined( 'ABSPATH' ) || exit;

$eelt_id = $eelt['form_id'];
?>
<div class="eelt-form-wrap" id="<?php echo esc_attr( $eelt_id ); ?>">
	<form class="eelt-form" method="post" action="<?php echo esc_url( $eelt['action'] ); ?>" novalidate>
		<input type="hidden" name="action" value="eelt_submit">
		<input type="hidden" name="eelt_ctx" value="<?php echo esc_attr( $eelt['ctx'] ); ?>">
		<input type="hidden" name="eelt_sig" value="<?php echo esc_attr( $eelt['sig'] ); ?>">
		<input type="hidden" name="eelt_form" value="<?php echo esc_attr( $eelt_id ); ?>">
		<input type="hidden" name="eelt_return" value="<?php echo esc_url( $eelt['return_url'] ); ?>">

		<div class="eelt-hp" aria-hidden="true">
			<label for="<?php echo esc_attr( $eelt_id ); ?>-hp">Leave this field empty</label>
			<input type="text" id="<?php echo esc_attr( $eelt_id ); ?>-hp" name="eelt_hp" value="" tabindex="-1" autocomplete="off">
		</div>

		<div class="eelt-status<?php echo $eelt['status'] ? ( $eelt['status_ok'] ? ' eelt-status--ok' : ' eelt-status--error' ) : ''; ?>" role="status" aria-live="polite"><?php echo esc_html( $eelt['status_message'] ); ?></div>

		<p class="eelt-field">
			<label for="<?php echo esc_attr( $eelt_id ); ?>-name">Full name <span class="eelt-required" aria-hidden="true">*</span></label>
			<input type="text" id="<?php echo esc_attr( $eelt_id ); ?>-name" name="eelt_name" maxlength="200" autocomplete="name" required>
			<span class="eelt-error" data-for="eelt_name"></span>
		</p>

		<p class="eelt-field">
			<label for="<?php echo esc_attr( $eelt_id ); ?>-email">Email <span class="eelt-required" aria-hidden="true">*</span></label>
			<input type="email" id="<?php echo esc_attr( $eelt_id ); ?>-email" name="eelt_email" maxlength="254" autocomplete="email" required>
			<span class="eelt-error" data-for="eelt_email"></span>
		</p>

		<p class="eelt-field">
			<label for="<?php echo esc_attr( $eelt_id ); ?>-phone">Phone number</label>
			<input type="tel" id="<?php echo esc_attr( $eelt_id ); ?>-phone" name="eelt_phone" maxlength="50" autocomplete="tel">
			<span class="eelt-error" data-for="eelt_phone"></span>
		</p>

		<p class="eelt-field">
			<label for="<?php echo esc_attr( $eelt_id ); ?>-message">Message <span class="eelt-required" aria-hidden="true">*</span></label>
			<textarea id="<?php echo esc_attr( $eelt_id ); ?>-message" name="eelt_message" rows="5" maxlength="5000" required></textarea>
			<span class="eelt-error" data-for="eelt_message"></span>
		</p>

		<p class="eelt-field eelt-field--consent">
			<label>
				<input type="checkbox" name="eelt_consent" value="1" required>
				<span><?php echo $eelt['consent_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- filtered with wp_kses. ?></span>
			</label>
			<span class="eelt-error" data-for="eelt_consent"></span>
		</p>

		<?php if ( '' !== $eelt['turnstile_key'] ) : ?>
			<div class="cf-turnstile" data-sitekey="<?php echo esc_attr( $eelt['turnstile_key'] ); ?>"></div>
		<?php endif; ?>

		<p class="eelt-actions">
			<button type="submit" class="eelt-submit">Send</button>
		</p>
	</form>
</div>
