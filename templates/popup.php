<?php
/**
 * The [enspire_contact_popup] template: a button that opens the form in a SweetAlert2 popup.
 *
 * Can be overridden from the theme: copy the file to `wp-content/themes/<theme>/enspire-email-leads-tracker/popup.php`.
 * Keep the `eelt-popup-button` class, the `data-eelt-*` attributes and the <template> element.
 *
 * Variables: $eelt — form_id, class, button_text, button_class, title, submit_text, popup_class,
 * error_title, popup_form (the form HTML for the popup), fallback_form (the inline form, for visitors
 * without JavaScript), post.
 *
 * @package EnspireEmailLeadsTracker
 */

defined( 'ABSPATH' ) || exit;

$eelt_id = $eelt['form_id'];
?>
<div class="<?php echo esc_attr( trim( 'eelt-popup ' . $eelt['class'] ) ); ?>">
	<button type="button"
		class="<?php echo esc_attr( trim( 'eelt-popup-button ' . $eelt['button_class'] ) ); ?>"
		data-eelt-template="<?php echo esc_attr( $eelt_id ); ?>-template"
		data-eelt-title="<?php echo esc_attr( $eelt['title'] ); ?>"
		data-eelt-submit="<?php echo esc_attr( $eelt['submit_text'] ); ?>"
		data-eelt-popup-class="<?php echo esc_attr( $eelt['popup_class'] ); ?>"
		data-eelt-error-title="<?php echo esc_attr( $eelt['error_title'] ); ?>"
		aria-haspopup="dialog"
		hidden><?php echo esc_html( $eelt['button_text'] ); ?></button>
	<template id="<?php echo esc_attr( $eelt_id ); ?>-template">
		<?php echo $eelt['popup_form']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered by form.php, escaped there. ?>
	</template>
	<noscript>
		<?php echo $eelt['fallback_form']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered by form.php, escaped there. ?>
	</noscript>
</div>
