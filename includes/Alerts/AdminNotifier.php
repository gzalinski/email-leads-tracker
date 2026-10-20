<?php
/**
 * Alerte pentru administrator (ARCHITECTURE.md §10).
 *
 * Alertele merg prin wp_mail(), nu prin Postmark, ca să ajungă și când problema este chiar Postmark.
 * Nu conțin date personale ale vizitatorilor.
 *
 * @package LocationLeadTracker
 */

namespace LocationLeadTracker\Alerts;

use LocationLeadTracker\Postmark\EmailBuilder;
use LocationLeadTracker\Postmark\ErrorClassifier;
use LocationLeadTracker\Postmark\PostmarkClient;
use LocationLeadTracker\Postmark\SendResult;
use LocationLeadTracker\Settings\Settings;
use LocationLeadTracker\State;
use LocationLeadTracker\Util;

defined( 'ABSPATH' ) || exit;

final class AdminNotifier {

	/** @var string|null */
	private static $mail_error = null;

	/**
	 * Un lead a devenit `failed`.
	 */
	public static function lead_failed( int $lead_id, SendResult $result ): void {
		$state                         = State::all();
		$state['consecutive_failures'] = (int) ( $state['consecutive_failures'] ?? 0 ) + 1;
		$state['pending_failed_ids']   = array_slice( array_merge( (array) ( $state['pending_failed_ids'] ?? array() ), array( $lead_id ) ), -50 );
		$state['first_failure_at']     = $state['first_failure_at'] ?? time();
		$state['last_failure_at']      = time();
		$state['last_error']           = array(
			'code'     => $result->code,
			'message'  => $result->message,
			'category' => $result->category,
			'at'       => time(),
		);
		State::replace( $state );

		if ( SendResult::CONFIG === $result->category ) {
			self::send_failure_alert( 'config' );
		} elseif ( $state['consecutive_failures'] >= max( 1, (int) Settings::get( 'alert_threshold' ) ) ) {
			self::send_failure_alert( 'threshold' );
		}
	}

	/**
	 * O trimitere a fost acceptată: contorul se resetează; dacă exista o alertă activă, se anunță revenirea.
	 */
	public static function send_succeeded(): void {
		$state = State::all();
		if ( empty( $state['consecutive_failures'] ) && empty( $state['alert_active'] ) ) {
			return;
		}
		$was_alerting                  = ! empty( $state['alert_active'] );
		$state['consecutive_failures'] = 0;
		$state['first_failure_at']     = null;
		$state['pending_failed_ids']   = array();
		$state['alert_active']         = false;
		State::replace( $state );

		if ( $was_alerting ) {
			self::deliver(
				'Trimiterea funcționează din nou',
				"Location Lead Tracker a trimis cu succes un lead după eșecurile semnalate anterior.\n\n"
				. "Leadurile eșuate între timp nu se retrimit automat. Verifică-le:\n"
				. self::resolution_hint()
			);
		}
	}

	/**
	 * Alertă pentru leaduri blocate în `pending` (cron oprit sau Postmark indisponibil).
	 */
	public static function stuck_leads( int $count ): void {
		self::alert(
			'stuck',
			sprintf( '%d leaduri nu au fost trimise la timp', $count ),
			sprintf( "%d leaduri trebuiau trimise acum mai mult de o oră și sunt încă în coadă.\n\n", $count )
			. "Cauze posibile: cronul serverului nu rulează sau Postmark nu răspunde.\n"
			. "Verifică tabul Stare din Setări → Location Lead Tracker sau rulează: wp llt status\n"
		);
	}

	/**
	 * Un email a fost respins definitiv (v2). Alertele de bounce se grupează și pleacă la verificarea orară.
	 */
	public static function bounce_recorded( int $lead_id, string $code ): void {
		if ( ! Settings::get( 'alert_on_bounce' ) ) {
			return;
		}
		$pending   = (array) State::get( 'pending_bounces', array() );
		$pending[] = array(
			'id'   => $lead_id,
			'code' => $code,
		);
		State::set( 'pending_bounces', array_slice( $pending, -100 ) );
	}

	/**
	 * Trimite alerta grupată pentru bounce-urile acumulate (hook `llt_health_check_run`).
	 */
	public static function flush_bounces(): void {
		$pending = (array) State::get( 'pending_bounces', array() );
		if ( ! $pending || ! Settings::get( 'alert_on_bounce' ) ) {
			return;
		}
		$lines = array();
		foreach ( $pending as $bounce ) {
			$lines[] = sprintf( '#%d — %s', (int) $bounce['id'], str_replace( 'bounce:', '', (string) $bounce['code'] ) );
		}
		$sent = self::alert(
			'bounce',
			sprintf( '%d emailuri respinse de serverul destinatarului', count( $pending ) ),
			"Următoarele leaduri nu au ajuns la locație, pentru că serverul destinatarului a respins emailul:\n\n"
			. implode( "\n", $lines )
			. "\n\nVerifică adresa din câmpul „email” al locației. Dacă Postmark a dezactivat adresa, reactiveaz-o din Postmark → Suppressions.\n"
			. "Datele vizitatorilor se păstrează temporar; le poți vedea cu: wp llt leads show <id> --with-content\n"
		);
		if ( $sent ) {
			State::set( 'pending_bounces', array() );
		}
	}

	/**
	 * Reconcilierea a găsit livrări, dar webhookul nu a mai trimis nimic de o zi (v2).
	 */
	public static function webhook_silent( int $recovered ): void {
		self::alert(
			'webhook_silent',
			'Webhookul Postmark pare oprit',
			sprintf( "Reconcilierea orară a găsit %d confirmări de livrare pe care webhookul nu le-a trimis, iar ultimul webhook a sosit acum mai mult de o zi.\n\n", $recovered )
			. "Postmark oprește un tip de eveniment după eșecuri repetate. Verifică-l în Postmark → Server → Message Stream → Webhooks și apasă Verify.\n"
			. "Până atunci, reconcilierea confirmă livrările cu o întârziere de cel mult o oră.\n"
		);
	}

	/**
	 * Trimite o alertă, cu limitare în timp pe tip.
	 */
	public static function alert( string $type, string $subject, string $body ): bool {
		$interval = max( 1, (int) Settings::get( 'alert_interval_hours' ) ) * HOUR_IN_SECONDS;
		$last     = (array) State::get( 'last_alert_by_type', array() );
		if ( isset( $last[ $type ] ) && time() - (int) $last[ $type ] < $interval ) {
			return false;
		}

		$sent = self::deliver( $subject, $body );
		if ( $sent ) {
			$last[ $type ] = time();
			State::set( 'last_alert_by_type', $last );
		}
		return $sent;
	}

	/**
	 * Alertă de test, fără limitare.
	 *
	 * @return array{ok:bool,error:string,to:string}
	 */
	public static function test(): array {
		$ok = self::deliver(
			'Alertă de test',
			"Acesta este un email de test trimis din Setări → Location Lead Tracker → Alerte.\n\n"
			. "Dacă l-ai primit, alertele despre leadurile eșuate vor ajunge la această adresă.\n"
		);
		return array(
			'ok'    => $ok,
			'error' => (string) self::$mail_error,
			'to'    => self::recipient(),
		);
	}

	/**
	 * Capturează eroarea wp_mail() (hookul `wp_mail_failed`).
	 *
	 * @param \WP_Error $error Eroarea.
	 */
	public static function capture_mail_error( $error ): void {
		if ( $error instanceof \WP_Error ) {
			self::$mail_error = Util::error_text( $error->get_error_message() );
		}
	}

	public static function recipient(): string {
		$to = (string) Settings::get( 'alert_email' );
		return is_email( $to ) ? $to : (string) get_option( 'admin_email' );
	}

	private static function send_failure_alert( string $type ): void {
		$state = State::all();
		$ids   = array_map( 'intval', (array) ( $state['pending_failed_ids'] ?? array() ) );
		$error = (array) ( $state['last_error'] ?? array() );
		$code  = (string) ( $error['code'] ?? '' );
		$count = count( $ids );

		$subject = 'config' === $type
			? 'Trimiterea leadurilor nu funcționează'
			: sprintf( '%d leaduri nu au putut fi trimise', $count );

		$lines   = array();
		$lines[] = 'Postmark a respins trimiterea: ' . ErrorClassifier::describe( $code ) . ( $code ? " ({$code})" : '' );
		if ( ! empty( $error['message'] ) ) {
			$lines[] = 'Detalii: ' . $error['message'];
		}
		if ( 'config' === $type ) {
			$lines[] = 'Verifică setările din Setări → Location Lead Tracker → Postmark și trimite un email de test.';
		}
		$lines[] = '';
		if ( $ids ) {
			$lines[] = 'Leaduri afectate: #' . implode( ', #', $ids );
		}
		if ( ! empty( $state['first_failure_at'] ) ) {
			$lines[] = 'Primul eșec: ' . gmdate( 'd.m.Y H:i', (int) $state['first_failure_at'] ) . ' UTC';
		}
		$lines[] = '';
		$lines[] = sprintf( 'Conținutul acestor mesaje se păstrează %d zile.', (int) Settings::get( 'retention_failed_days' ) );
		$lines[] = 'După remediere, retrimite-le din:';
		$lines[] = self::resolution_hint();

		if ( self::alert( $type, $subject, implode( "\n", $lines ) . "\n" ) ) {
			$state                       = State::all();
			$state['alert_active']       = true;
			$state['pending_failed_ids'] = array();
			State::replace( $state );
		}
	}

	/**
	 * Unde se rezolvă leadurile eșuate: lista din admin (v3) și/sau WP-CLI.
	 */
	public static function resolution_hint(): string {
		$hint = (string) apply_filters( 'llt_alert_admin_link', '' );
		return ( '' !== $hint ? $hint . "\nsau rulează:  " : '' ) . 'wp llt retry --failed';
	}

	private static function deliver( string $subject, string $body ): bool {
		$to      = self::recipient();
		$subject = '[' . EmailBuilder::site_name() . '] ' . $subject;
		$body   .= "\n—\nLocation Lead Tracker · " . home_url( '/' ) . "\n";

		self::$mail_error = null;
		$sent             = (bool) wp_mail( $to, $subject, $body );

		if ( ! $sent && Settings::get( 'alert_fallback_postmark' ) ) {
			$client = new PostmarkClient();
			if ( $client->has_token() ) {
				$result = $client->send(
					array(
						'From'          => EmailBuilder::from(),
						'To'            => $to,
						'Subject'       => $subject,
						'TextBody'      => $body,
						'MessageStream' => (string) Settings::get( 'postmark_stream' ),
						'Tag'           => 'llt-alert',
					)
				);
				$sent = $result->is( SendResult::ACCEPTED );
				if ( ! $sent ) {
					self::$mail_error = trim( self::$mail_error . ' Fallback Postmark: ' . ErrorClassifier::describe( $result->code ) );
				}
			}
		}

		if ( $sent ) {
			State::set(
				'last_alert_sent',
				array(
					'at'      => time(),
					'subject' => $subject,
				)
			);
		} else {
			State::set(
				'last_alert_failure',
				array(
					'at'    => time(),
					'error' => self::$mail_error ?: 'wp_mail() a eșuat fără un mesaj de eroare.',
				)
			);
		}
		return $sent;
	}
}
