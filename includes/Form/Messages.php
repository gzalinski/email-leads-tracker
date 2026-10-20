<?php
/**
 * Messages shown to the visitor after submitting the form.
 *
 * @package EnspireEmailLeadsTracker
 */

namespace EnspireEmailLeadsTracker\Form;

use EnspireEmailLeadsTracker\Settings\Settings;

defined( 'ABSPATH' ) || exit;

final class Messages {

	public static function get( string $status ): string {
		$messages = array(
			'success'      => (string) Settings::get( 'success_message' ),
			'invalid'      => 'The form has expired or is invalid. Please reload the page and try again.',
			'too_fast'     => 'Please fill in the form before submitting it.',
			'rate_limited' => 'You have sent too many messages. Please try again later.',
			'captcha'      => 'The anti-spam check failed. Please try again.',
			'validation'   => 'Please correct the highlighted fields.',
			'unavailable'  => 'The form is not available right now. Please try again later.',
			'error'        => 'Something went wrong. Please try again.',
		);
		$messages = apply_filters( 'eelt_form_messages', $messages );
		return $messages[ $status ] ?? $messages['error'];
	}
}
