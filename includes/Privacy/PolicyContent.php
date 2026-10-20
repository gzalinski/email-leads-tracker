<?php
/**
 * Text sugerat pentru politica de confidențialitate (Setări → Confidențialitate).
 *
 * @package LocationLeadTracker
 */

namespace LocationLeadTracker\Privacy;

use LocationLeadTracker\Settings\Settings;

defined( 'ABSPATH' ) || exit;

final class PolicyContent {

	public static function register(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$accepted_hours = (int) Settings::get( 'retention_accepted_hours' );
		$failed_days    = (int) Settings::get( 'retention_failed_days' );

		$html  = '<h2>Formularul de contact pentru locații</h2>';
		$html .= '<p>Când trimiți un mesaj prin formularul de contact al unei locații, colectăm numele, adresa de email, numărul de telefon (opțional) și mesajul tău, pentru a-l transmite locației respective și pentru a-ți putea răspunde.</p>';
		$html .= '<p>Mesajul este trimis prin serviciul de email Postmark (ActiveCampaign, LLC), care păstrează o copie a emailului pe durata retenției configurate în contul nostru Postmark.</p>';
		if ( Settings::get( 'turnstile_enabled' ) ) {
			$html .= '<p>Pentru protecția împotriva spamului, formularul folosește Cloudflare Turnstile, care încarcă resurse de la Cloudflare și poate prelucra date tehnice despre browserul tău.</p>';
		}
		if ( Settings::tracking_enabled() ) {
			$html .= '<p>Datele tale personale sunt șterse din baza noastră de date imediat după ce serverul destinatarului confirmă primirea emailului, sau după cel mult '
				. (int) Settings::get( 'retention_unconfirmed_days' ) . ' zile dacă livrarea nu este confirmată.</p>';
		} else {
			$html .= '<p>Datele tale personale sunt șterse din baza noastră de date la ' . $accepted_hours . ' ore după trimiterea emailului.</p>';
		}
		$html .= '<p>Dacă trimiterea eșuează, datele se păstrează cel mult ' . $failed_days . ' zile, pentru a putea fi retrimise. Păstrăm doar o evidență minimă a solicitării (locația, data și starea trimiterii), fără datele tale personale.</p>';
		$html .= '<p>Adresa IP nu este stocată; folosim doar o valoare criptografică derivată din ea, timp de o oră, pentru a limita abuzurile.</p>';

		wp_add_privacy_policy_content( 'Location Lead Tracker', wp_kses_post( $html ) );
	}
}
