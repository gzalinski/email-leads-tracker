<?php
/**
 * Definiția taburilor și câmpurilor din pagina de setări.
 *
 * Fiecare câmp: type, label, desc (opțional), plus atribute specifice tipului.
 * Tipuri: text, email, number, select, checkbox, secret, textarea, html, cidr_list, schedule.
 *
 * @package LocationLeadTracker
 */

namespace LocationLeadTracker\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsFields {

	/**
	 * @return array<string,array{label:string,fields?:array,render?:callable,after?:callable}>
	 */
	public static function tabs(): array {
		$tabs = array(
			'general'   => array(
				'label'  => 'General',
				'fields' => array(
					'from_email'   => array(
						'type'  => 'email',
						'label' => 'Email expeditor',
						'desc'  => 'Adresă de pe un domeniu verificat în Postmark (DKIM și Return-Path).',
					),
					'from_name'    => array(
						'type'  => 'text',
						'label' => 'Nume expeditor',
					),
					'subject'      => array(
						'type'  => 'text',
						'label' => 'Subiect email',
						'desc'  => 'Variabile disponibile: {post_title}, {site_name}.',
					),
					'email_format' => array(
						'type'    => 'select',
						'label'   => 'Format email',
						'options' => array(
							'text' => 'Text simplu',
							'html' => 'HTML (cu variantă text)',
						),
					),
				),
			),
			'postmark'  => array(
				'label'  => 'Postmark',
				'fields' => array(
					'postmark_token'   => array(
						'type'  => 'secret',
						'label' => 'Server API Token',
						'desc'  => 'Din Postmark → Server → API Tokens. Se salvează criptat și nu se mai afișează.',
					),
					'postmark_stream'  => array(
						'type'  => 'text',
						'label' => 'Message Stream',
						'desc'  => 'Streamul tranzacțional; de obicei „outbound”.',
					),
					'postmark_timeout' => array(
						'type'  => 'number',
						'label' => 'Timeout (secunde)',
						'min'   => 5,
						'max'   => 60,
					),
				),
				'after'  => array( TestActions::class, 'render_test_email' ),
			),
			'form'      => array(
				'label'  => 'Formular',
				'fields' => array(
					'consent_text'    => array(
						'type'  => 'html',
						'label' => 'Text consimțământ GDPR',
						'desc'  => 'Afișat lângă checkbox-ul obligatoriu. HTML permis: <a href target rel>, <strong>, <em>, <br>. Adaugă aici linkul către pagina de confidențialitate.',
					),
					'success_message' => array(
						'type'  => 'text',
						'label' => 'Mesaj de confirmare',
						'desc'  => 'Afișat vizitatorului după trimitere. Nu promite livrarea emailului.',
					),
				),
			),
			'antispam'  => array(
				'label'  => 'Anti-spam',
				'fields' => array(
					'turnstile_enabled'   => array(
						'type'  => 'checkbox',
						'label' => 'Cloudflare Turnstile',
						'text'  => 'Activează verificarea Turnstile',
					),
					'turnstile_site_key'  => array(
						'type'  => 'text',
						'label' => 'Turnstile Site Key',
					),
					'turnstile_secret'    => array(
						'type'  => 'secret',
						'label' => 'Turnstile Secret Key',
					),
					'turnstile_fail_mode' => array(
						'type'    => 'select',
						'label'   => 'Dacă verificarea nu este disponibilă',
						'options' => array(
							'reject' => 'Respinge trimiterea',
							'accept' => 'Acceptă trimiterea',
						),
					),
					'rate_limit'          => array(
						'type'  => 'number',
						'label' => 'Mesaje / IP / oră',
						'desc'  => '0 dezactivează limita.',
						'min'   => 0,
						'max'   => 1000,
					),
					'min_seconds'         => array(
						'type'  => 'number',
						'label' => 'Timp minim de completare (secunde)',
						'min'   => 0,
						'max'   => 60,
					),
					'ip_source'           => array(
						'type'    => 'select',
						'label'   => 'Sursa IP',
						'desc'    => 'Automat recunoaște conexiunea directă, Cloudflare și proxy-urile locale.',
						'options' => array(
							'auto'       => 'Automat',
							'direct'     => 'Doar conexiunea directă',
							'cloudflare' => 'Doar Cloudflare',
						),
					),
					'extra_proxies'       => array(
						'type'  => 'cidr_list',
						'label' => 'Proxy-uri suplimentare',
						'desc'  => 'Rețele CIDR (câte una pe linie) ale altor CDN-uri sau load balancere cu IP public.',
					),
				),
				'after'  => array( TestActions::class, 'render_ip_diagnostic' ),
			),
			'retry'     => array(
				'label'  => 'Retry',
				'fields' => array(
					'max_retries'    => array(
						'type'  => 'number',
						'label' => 'Număr maxim de retry-uri',
						'min'   => 0,
						'max'   => 10,
					),
					'retry_schedule' => array(
						'type'  => 'schedule',
						'label' => 'Intervale (minute)',
						'desc'  => 'Separate prin virgulă, crescătoare. Ex.: 1, 5, 15, 60, 360.',
					),
				),
			),
			'alerts'    => array(
				'label'  => 'Alerte',
				'fields' => array(
					'alert_email'             => array(
						'type'  => 'email',
						'label' => 'Email pentru alerte',
					),
					'alert_threshold'         => array(
						'type'  => 'number',
						'label' => 'Prag eșecuri consecutive',
						'min'   => 1,
						'max'   => 100,
					),
					'alert_interval_hours'    => array(
						'type'  => 'number',
						'label' => 'Interval minim între alerte (ore)',
						'min'   => 1,
						'max'   => 168,
					),
					'alert_fallback_postmark' => array(
						'type'  => 'checkbox',
						'label' => 'Fallback',
						'text'  => 'Trimite alertele prin Postmark dacă wp_mail() eșuează',
					),
				),
				'after'  => array( TestActions::class, 'render_test_alert' ),
			),
			'retention' => array(
				'label'  => 'Retenție',
				'fields' => array(
					'retention_accepted_hours' => array(
						'type'  => 'number',
						'label' => 'Ore după acceptare',
						'desc'  => 'Când confirmarea livrării nu este activă, conținutul se șterge la acest interval după acceptarea de către Postmark. 0 = la următoarea curățare.',
						'min'   => 0,
						'max'   => 720,
					),
					'retention_failed_days'    => array(
						'type'  => 'number',
						'label' => 'Zile pentru leadurile eșuate',
						'desc'  => 'Cât timp pot fi retrimise.',
						'min'   => 1,
						'max'   => 365,
					),
					'delete_on_uninstall'      => array(
						'type'  => 'checkbox',
						'label' => 'Dezinstalare',
						'text'  => 'Șterge tabelele și setările la dezinstalarea pluginului',
					),
				),
			),
			'status'    => array(
				'label'  => 'Stare',
				'render' => array( StatusTab::class, 'render' ),
			),
		);

		return (array) apply_filters( 'llt_settings_tabs', $tabs );
	}

	/**
	 * Toate câmpurile, din toate taburile.
	 */
	public static function all_fields(): array {
		$fields = array();
		foreach ( self::tabs() as $tab ) {
			$fields += $tab['fields'] ?? array();
		}
		return $fields;
	}
}
