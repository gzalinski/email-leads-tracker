<?php
/**
 * Comenzile WP-CLI: wp llt ...
 *
 * Nu afișează datele personale ale vizitatorilor decât la cerere explicită (--with-content).
 *
 * @package LocationLeadTracker
 */

namespace LocationLeadTracker\Cli;

use LocationLeadTracker\Database\LeadRepository;
use LocationLeadTracker\Database\MessageRepository;
use LocationLeadTracker\Database\Schema;
use LocationLeadTracker\Http\ProxyRanges;
use LocationLeadTracker\Lead\LeadActions;
use LocationLeadTracker\Postmark\EmailBuilder;
use LocationLeadTracker\Postmark\ErrorClassifier;
use LocationLeadTracker\Postmark\PostmarkClient;
use LocationLeadTracker\Postmark\SendResult;
use LocationLeadTracker\Queue\SendWorker;
use LocationLeadTracker\Retention\RetentionService;
use LocationLeadTracker\Settings\Settings;
use LocationLeadTracker\State;
use LocationLeadTracker\Util;

defined( 'ABSPATH' ) || exit;

final class Commands {

	public static function register(): void {
		$commands = array(
			'llt status'         => array( 'status', 'Starea pluginului: cron, leaduri pe stări, ultimele erori.' ),
			'llt leads list'     => array( 'leads_list', 'Listează leadurile (fără datele vizitatorilor).' ),
			'llt leads show'     => array( 'leads_show', 'Detaliile unui lead.' ),
			'llt retry'          => array( 'retry', 'Readuce leadurile eșuate în coada de trimitere.' ),
			'llt send-test'      => array( 'send_test', 'Trimite un email de test prin Postmark.' ),
			'llt queue run'      => array( 'queue_run', 'Procesează coada imediat.' ),
			'llt cleanup run'    => array( 'cleanup_run', 'Rulează retenția (ștergerea conținutului expirat).' ),
			'llt content delete' => array( 'content_delete', 'Șterge conținutul unor leaduri.' ),
			'llt stats'          => array( 'stats', 'Statistici pe stări.' ),
		);
		$commands = (array) apply_filters( 'llt_cli_commands', $commands );

		foreach ( $commands as $name => $definition ) {
			$callable = is_array( $definition[0] ) ? $definition[0] : array( self::class, $definition[0] );
			\WP_CLI::add_command( $name, $callable, array( 'shortdesc' => $definition[1] ) );
		}
	}

	/**
	 * Starea pluginului.
	 */
	public static function status(): void {
		$state     = State::all();
		$counts    = LeadRepository::counts();
		$heartbeat = (int) ( $state['worker_heartbeat'] ?? 0 );

		$rows = array(
			array( 'Cheie' => 'Versiune', 'Valoare' => LLT_VERSION ),
			array( 'Cheie' => 'Token Postmark', 'Valoare' => Settings::has_secret( 'postmark_token' ) ? 'configurat' : 'LIPSEȘTE' ),
			array( 'Cheie' => 'Message Stream', 'Valoare' => (string) Settings::get( 'postmark_stream' ) ),
			array( 'Cheie' => 'Ultima rulare coadă', 'Valoare' => $heartbeat ? gmdate( 'Y-m-d H:i:s', $heartbeat ) . ' UTC' : 'niciodată' ),
			array( 'Cheie' => 'WP-Cron dezactivat', 'Valoare' => ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ? 'da (cron de server)' : 'nu' ),
			array( 'Cheie' => 'Pending', 'Valoare' => $counts['send']['pending'] ),
			array( 'Cheie' => 'Accepted', 'Valoare' => $counts['send']['accepted'] ),
			array( 'Cheie' => 'Failed', 'Valoare' => $counts['send']['failed'] ),
			array( 'Cheie' => 'Eșecuri consecutive', 'Valoare' => (int) ( $state['consecutive_failures'] ?? 0 ) ),
			array( 'Cheie' => 'Ultima eroare', 'Valoare' => (string) ( $state['last_error']['code'] ?? '—' ) ),
			array( 'Cheie' => 'Ultima alertă eșuată', 'Valoare' => (string) ( $state['last_alert_failure']['error'] ?? '—' ) ),
			array( 'Cheie' => 'Lista Cloudflare', 'Valoare' => ProxyRanges::updated_at() ? gmdate( 'Y-m-d', ProxyRanges::updated_at() ) : 'inclusă în plugin' ),
		);
		$rows = (array) apply_filters( 'llt_cli_status_rows', $rows, $state, $counts );

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'Cheie', 'Valoare' ) );

		if ( Settings::unreadable_secrets() ) {
			\WP_CLI::warning( 'Unele secrete nu pot fi decriptate: ' . implode( ', ', Settings::unreadable_secrets() ) );
		}
		if ( ! $heartbeat || time() - $heartbeat > 15 * MINUTE_IN_SECONDS ) {
			\WP_CLI::warning( 'Coada nu a rulat în ultimele 15 minute. Verifică cronul serverului.' );
		}
	}

	/**
	 * Listează leadurile.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : pending, accepted sau failed.
	 *
	 * [--delivery=<status>]
	 * : unknown, deferred, delivered sau bounced.
	 *
	 * [--since=<date>]
	 * : Data de început (YYYY-MM-DD, UTC).
	 *
	 * [--limit=<n>]
	 * : Numărul maxim de rânduri.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--format=<format>]
	 * : table, csv sau json.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args  Argumente.
	 * @param array $assoc Opțiuni.
	 */
	public static function leads_list( $args, $assoc ): void {
		$rows = LeadRepository::search(
			array(
				'status'   => $assoc['status'] ?? '',
				'delivery' => $assoc['delivery'] ?? '',
				'since'    => isset( $assoc['since'] ) ? gmdate( 'Y-m-d 00:00:00', (int) strtotime( (string) $assoc['since'] ) ) : '',
				'limit'    => (int) ( $assoc['limit'] ?? 50 ),
			)
		);
		$items = array();
		foreach ( $rows as $row ) {
			$items[] = array(
				'id'       => (int) $row->id,
				'creat'    => $row->created_at,
				'locație'  => (int) $row->object_id,
				'trimitere' => $row->send_status,
				'livrare'  => $row->delivery_status,
				'încercări' => (int) $row->retry_count . '+' . (int) $row->manual_resend_count,
				'eroare'   => (string) $row->last_error_code,
				'conținut' => $row->has_content ? 'da' : 'nu',
			);
		}
		\WP_CLI\Utils\format_items(
			(string) ( $assoc['format'] ?? 'table' ),
			$items,
			array( 'id', 'creat', 'locație', 'trimitere', 'livrare', 'încercări', 'eroare', 'conținut' )
		);
	}

	/**
	 * Detaliile unui lead.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : ID-ul leadului.
	 *
	 * [--with-content]
	 * : Afișează și datele vizitatorului, dacă încă există.
	 *
	 * @param array $args  Argumente.
	 * @param array $assoc Opțiuni.
	 */
	public static function leads_show( $args, $assoc ): void {
		$lead = LeadRepository::find( (int) ( $args[0] ?? 0 ) );
		if ( ! $lead ) {
			\WP_CLI::error( 'Leadul nu există.' );
		}
		$rows = array();
		foreach ( (array) $lead as $key => $value ) {
			if ( 'lock_token' === $key ) {
				continue;
			}
			$rows[] = array( 'Câmp' => $key, 'Valoare' => (string) $value );
		}
		if ( $lead->last_error_code ) {
			$rows[] = array( 'Câmp' => 'explicație eroare', 'Valoare' => ErrorClassifier::describe( (string) $lead->last_error_code ) );
		}
		$message = MessageRepository::find( (int) $lead->id );
		$rows[]  = array( 'Câmp' => 'conținut', 'Valoare' => $message ? 'disponibil' : 'șters' );

		if ( $message && \WP_CLI\Utils\get_flag_value( $assoc, 'with-content', false ) ) {
			$rows[] = array( 'Câmp' => 'nume', 'Valoare' => $message->sender_name );
			$rows[] = array( 'Câmp' => 'email', 'Valoare' => $message->sender_email );
			$rows[] = array( 'Câmp' => 'telefon', 'Valoare' => (string) $message->sender_phone );
			$rows[] = array( 'Câmp' => 'mesaj', 'Valoare' => $message->message );
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'Câmp', 'Valoare' ) );
	}

	/**
	 * Readuce leadurile eșuate în coadă.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : ID-urile leadurilor.
	 *
	 * [--failed]
	 * : Toate leadurile eșuate care încă au conținut.
	 *
	 * @param array $args  Argumente.
	 * @param array $assoc Opțiuni.
	 */
	public static function retry( $args, $assoc ): void {
		global $wpdb;
		$ids = Util::ids( $args );
		if ( \WP_CLI\Utils\get_flag_value( $assoc, 'failed', false ) ) {
			$table = Schema::leads();
			$ids   = array_merge( $ids, array_map( 'intval', (array) $wpdb->get_col( "SELECT id FROM {$table} WHERE send_status = 'failed'" ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		if ( ! $ids ) {
			\WP_CLI::error( 'Indică ID-urile leadurilor sau folosește --failed.' );
		}

		$result = LeadActions::resend( $ids );
		if ( $result['no_content'] ) {
			\WP_CLI::warning( 'Fără conținut (șters de retenție), nu pot fi retrimise: #' . implode( ', #', $result['no_content'] ) );
		}
		if ( $result['not_failed'] ) {
			\WP_CLI::log( 'Nu sunt în starea failed, sărite: #' . implode( ', #', $result['not_failed'] ) );
		}
		\WP_CLI::success( sprintf( '%d leaduri puse în coadă. Rulează `wp llt queue run` pentru trimitere imediată.', $result['queued'] ) );
	}

	/**
	 * Email de test prin Postmark.
	 *
	 * ## OPTIONS
	 *
	 * <email>
	 * : Destinatarul.
	 *
	 * @param array $args Argumente.
	 */
	public static function send_test( $args ): void {
		$to = sanitize_email( (string) ( $args[0] ?? '' ) );
		if ( ! is_email( $to ) ) {
			\WP_CLI::error( 'Adresa nu este validă.' );
		}
		$result = ( new PostmarkClient() )->send(
			array(
				'From'          => EmailBuilder::from(),
				'To'            => $to,
				'Subject'       => '[' . EmailBuilder::site_name() . '] Email de test Location Lead Tracker',
				'TextBody'      => 'Configurarea Postmark funcționează (trimis din WP-CLI).',
				'MessageStream' => (string) Settings::get( 'postmark_stream' ),
				'Tag'           => 'llt-test',
			)
		);
		if ( $result->is( SendResult::ACCEPTED ) ) {
			\WP_CLI::success( 'Acceptat de Postmark. MessageID: ' . $result->message_id );
		} else {
			\WP_CLI::error( ErrorClassifier::describe( $result->code ) . ' [' . $result->code . '] ' . $result->message );
		}
	}

	public static function queue_run(): void {
		SendWorker::process_queue();
		\WP_CLI::success( 'Coada a fost procesată.' );
	}

	/**
	 * Rulează retenția.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Doar numără, fără ștergere.
	 *
	 * @param array $args  Argumente.
	 * @param array $assoc Opțiuni.
	 */
	public static function cleanup_run( $args, $assoc ): void {
		$dry    = (bool) \WP_CLI\Utils\get_flag_value( $assoc, 'dry-run', false );
		$counts = RetentionService::run( $dry );
		foreach ( $counts as $rule => $count ) {
			\WP_CLI::log( sprintf( '%s: %d', $rule, $count ) );
		}
		\WP_CLI::success( ( $dry ? 'Ar fi șterse: ' : 'Șterse: ' ) . array_sum( $counts ) );
	}

	/**
	 * Șterge conținutul unor leaduri.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : ID-urile leadurilor.
	 *
	 * @param array $args Argumente.
	 */
	public static function content_delete( $args ): void {
		$deleted = LeadActions::delete_content( Util::ids( $args ) );
		\WP_CLI::success( sprintf( 'Conținut șters pentru %d leaduri.', $deleted ) );
	}

	/**
	 * Statistici.
	 *
	 * ## OPTIONS
	 *
	 * [--since=<date>]
	 * : Data de început (YYYY-MM-DD, UTC).
	 *
	 * [--until=<date>]
	 * : Data de sfârșit, exclusiv (YYYY-MM-DD, UTC).
	 *
	 * [--by=<field>]
	 * : object pentru împărțire pe locații.
	 *
	 * [--format=<format>]
	 * : table, csv sau json.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args  Argumente.
	 * @param array $assoc Opțiuni.
	 */
	public static function stats( $args, $assoc ): void {
		global $wpdb;
		$since  = isset( $assoc['since'] ) ? gmdate( 'Y-m-d 00:00:00', (int) strtotime( (string) $assoc['since'] ) ) : null;
		$until  = isset( $assoc['until'] ) ? gmdate( 'Y-m-d 00:00:00', (int) strtotime( (string) $assoc['until'] ) ) : null;
		$format = (string) ( $assoc['format'] ?? 'table' );
		$fields = array( 'total', 'accepted', 'failed', 'pending', 'delivered', 'bounced' );

		if ( 'object' === ( $assoc['by'] ?? '' ) ) {
			$table = Schema::leads();
			$ids   = array_map( 'intval', (array) $wpdb->get_col( "SELECT DISTINCT object_id FROM {$table}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$items = array();
			foreach ( $ids as $object_id ) {
				$c       = LeadRepository::counts( $since, $until, $object_id );
				$items[] = array_merge( array( 'locație' => $object_id . ' ' . get_the_title( $object_id ) ), self::stat_row( $c ) );
			}
			\WP_CLI\Utils\format_items( $format, $items, array_merge( array( 'locație' ), $fields ) );
			return;
		}

		\WP_CLI\Utils\format_items( $format, array( self::stat_row( LeadRepository::counts( $since, $until ) ) ), $fields );
	}

	private static function stat_row( array $c ): array {
		return array(
			'total'     => $c['total'],
			'accepted'  => $c['send']['accepted'],
			'failed'    => $c['send']['failed'],
			'pending'   => $c['send']['pending'],
			'delivered' => $c['delivery']['delivered'],
			'bounced'   => $c['delivery']['bounced'],
		);
	}
}
