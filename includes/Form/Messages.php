<?php
/**
 * Mesajele afișate vizitatorului după trimiterea formularului.
 *
 * @package LocationLeadTracker
 */

namespace LocationLeadTracker\Form;

use LocationLeadTracker\Settings\Settings;

defined( 'ABSPATH' ) || exit;

final class Messages {

	public static function get( string $status ): string {
		$messages = array(
			'success'      => (string) Settings::get( 'success_message' ),
			'invalid'      => 'Formularul a expirat sau este invalid. Reîncarcă pagina și încearcă din nou.',
			'too_fast'     => 'Te rugăm să completezi formularul înainte de a-l trimite.',
			'rate_limited' => 'Ai trimis prea multe mesaje. Te rugăm să încerci din nou mai târziu.',
			'captcha'      => 'Verificarea anti-spam nu a reușit. Te rugăm să încerci din nou.',
			'validation'   => 'Te rugăm să completezi corect câmpurile marcate.',
			'unavailable'  => 'Formularul nu este disponibil momentan. Te rugăm să încerci mai târziu.',
			'error'        => 'A apărut o eroare. Te rugăm să încerci din nou.',
		);
		$messages = apply_filters( 'llt_form_messages', $messages );
		return $messages[ $status ] ?? $messages['error'];
	}
}
