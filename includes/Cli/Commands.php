<?php
/**
 * WP-CLI commands: wp llt ...
 *
 * Visitors' personal data is shown only on explicit request (--with-content).
 *
 * @package EnspireEmailLeadsTracker
 */

namespace EnspireEmailLeadsTracker\Cli;

use EnspireEmailLeadsTracker\Database\LeadRepository;
use EnspireEmailLeadsTracker\Database\MessageRepository;
use EnspireEmailLeadsTracker\Database\Schema;
use EnspireEmailLeadsTracker\Http\ProxyRanges;
use EnspireEmailLeadsTracker\Lead\LeadActions;
use EnspireEmailLeadsTracker\Postmark\EmailBuilder;
use EnspireEmailLeadsTracker\Postmark\ErrorClassifier;
use EnspireEmailLeadsTracker\Postmark\PostmarkClient;
use EnspireEmailLeadsTracker\Postmark\SendResult;
use EnspireEmailLeadsTracker\Queue\SendWorker;
use EnspireEmailLeadsTracker\Retention\RetentionService;
use EnspireEmailLeadsTracker\Settings\Settings;
use EnspireEmailLeadsTracker\State;
use EnspireEmailLeadsTracker\Util;

defined( 'ABSPATH' ) || exit;

final class Commands {

	public static function register(): void {
		$commands = array(
			'llt status'         => array( 'status', 'Plugin status: cron, leads per status, recent errors.' ),
			'llt leads list'     => array( 'leads_list', 'Lists leads (without visitor data).' ),
			'llt leads show'     => array( 'leads_show', 'Details of a lead.' ),
			'llt retry'          => array( 'retry', 'Puts failed leads back in the send queue.' ),
			'llt send-test'      => array( 'send_test', 'Sends a test email through Postmark.' ),
			'llt queue run'      => array( 'queue_run', 'Processes the queue now.' ),
			'llt cleanup run'    => array( 'cleanup_run', 'Runs retention (deletes expired content).' ),
			'llt content delete' => array( 'content_delete', 'Deletes the content of some leads.' ),
			'llt stats'          => array( 'stats', 'Statistics per status.' ),
		);
		$commands = (array) apply_filters( 'llt_cli_commands', $commands );

		foreach ( $commands as $name => $definition ) {
			$callable = is_array( $definition[0] ) ? $definition[0] : array( self::class, $definition[0] );
			\WP_CLI::add_command( $name, $callable, array( 'shortdesc' => $definition[1] ) );
		}
	}

	/**
	 * Plugin status.
	 */
	public static function status(): void {
		$state     = State::all();
		$counts    = LeadRepository::counts();
		$heartbeat = (int) ( $state['worker_heartbeat'] ?? 0 );

		$rows = array(
			array( 'Key' => 'Version', 'Value' => LLT_VERSION ),
			array( 'Key' => 'Postmark token', 'Value' => Settings::has_secret( 'postmark_token' ) ? 'configured' : 'MISSING' ),
			array( 'Key' => 'Message Stream', 'Value' => (string) Settings::get( 'postmark_stream' ) ),
			array( 'Key' => 'Last queue run', 'Value' => $heartbeat ? gmdate( 'Y-m-d H:i:s', $heartbeat ) . ' UTC' : 'never' ),
			array( 'Key' => 'WP-Cron disabled', 'Value' => ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ? 'yes (server cron)' : 'no' ),
			array( 'Key' => 'Pending', 'Value' => $counts['send']['pending'] ),
			array( 'Key' => 'Accepted', 'Value' => $counts['send']['accepted'] ),
			array( 'Key' => 'Failed', 'Value' => $counts['send']['failed'] ),
			array( 'Key' => 'Consecutive failures', 'Value' => (int) ( $state['consecutive_failures'] ?? 0 ) ),
			array( 'Key' => 'Last error', 'Value' => (string) ( $state['last_error']['code'] ?? '—' ) ),
			array( 'Key' => 'Last failed alert', 'Value' => (string) ( $state['last_alert_failure']['error'] ?? '—' ) ),
			array( 'Key' => 'Cloudflare list', 'Value' => ProxyRanges::updated_at() ? gmdate( 'Y-m-d', ProxyRanges::updated_at() ) : 'bundled with the plugin' ),
		);
		$rows = (array) apply_filters( 'llt_cli_status_rows', $rows, $state, $counts );

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'Key', 'Value' ) );

		if ( Settings::unreadable_secrets() ) {
			\WP_CLI::warning( 'Some secrets cannot be decrypted: ' . implode( ', ', Settings::unreadable_secrets() ) );
		}
		if ( ! $heartbeat || time() - $heartbeat > 15 * MINUTE_IN_SECONDS ) {
			\WP_CLI::warning( 'The queue has not run in the last 15 minutes. Check the server cron.' );
		}
	}

	/**
	 * Lists leads.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : pending, accepted or failed.
	 *
	 * [--delivery=<status>]
	 * : unknown, deferred, delivered or bounced.
	 *
	 * [--since=<date>]
	 * : Start date (YYYY-MM-DD, UTC).
	 *
	 * [--limit=<n>]
	 * : Maximum number of rows.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--format=<format>]
	 * : table, csv or json.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args  Arguments.
	 * @param array $assoc Options.
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
				'created'  => $row->created_at,
				'location' => (int) $row->object_id,
				'send'     => $row->send_status,
				'delivery' => $row->delivery_status,
				'attempts' => (int) $row->retry_count . '+' . (int) $row->manual_resend_count,
				'error'    => (string) $row->last_error_code,
				'content'  => $row->has_content ? 'yes' : 'no',
			);
		}
		\WP_CLI\Utils\format_items(
			(string) ( $assoc['format'] ?? 'table' ),
			$items,
			array( 'id', 'created', 'location', 'send', 'delivery', 'attempts', 'error', 'content' )
		);
	}

	/**
	 * Details of a lead.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The lead ID.
	 *
	 * [--with-content]
	 * : Also show the visitor's data, if it still exists.
	 *
	 * @param array $args  Arguments.
	 * @param array $assoc Options.
	 */
	public static function leads_show( $args, $assoc ): void {
		$lead = LeadRepository::find( (int) ( $args[0] ?? 0 ) );
		if ( ! $lead ) {
			\WP_CLI::error( 'The lead does not exist.' );
		}
		$rows = array();
		foreach ( (array) $lead as $key => $value ) {
			if ( 'lock_token' === $key ) {
				continue;
			}
			$rows[] = array( 'Field' => $key, 'Value' => (string) $value );
		}
		if ( $lead->last_error_code ) {
			$rows[] = array( 'Field' => 'error explanation', 'Value' => ErrorClassifier::describe( (string) $lead->last_error_code ) );
		}
		$message = MessageRepository::find( (int) $lead->id );
		$rows[]  = array( 'Field' => 'content', 'Value' => $message ? 'available' : 'deleted' );

		if ( $message && \WP_CLI\Utils\get_flag_value( $assoc, 'with-content', false ) ) {
			$rows[] = array( 'Field' => 'name', 'Value' => $message->sender_name );
			$rows[] = array( 'Field' => 'email', 'Value' => $message->sender_email );
			$rows[] = array( 'Field' => 'phone', 'Value' => (string) $message->sender_phone );
			$rows[] = array( 'Field' => 'message', 'Value' => $message->message );
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'Field', 'Value' ) );
	}

	/**
	 * Puts failed leads back in the queue.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : The lead IDs.
	 *
	 * [--failed]
	 * : All failed leads that still have content.
	 *
	 * @param array $args  Arguments.
	 * @param array $assoc Options.
	 */
	public static function retry( $args, $assoc ): void {
		global $wpdb;
		$ids = Util::ids( $args );
		if ( \WP_CLI\Utils\get_flag_value( $assoc, 'failed', false ) ) {
			$table = Schema::leads();
			$ids   = array_merge( $ids, array_map( 'intval', (array) $wpdb->get_col( "SELECT id FROM {$table} WHERE send_status = 'failed'" ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		if ( ! $ids ) {
			\WP_CLI::error( 'Give the lead IDs or use --failed.' );
		}

		$result = LeadActions::resend( $ids );
		if ( $result['no_content'] ) {
			\WP_CLI::warning( 'No content (deleted by retention), cannot be resent: #' . implode( ', #', $result['no_content'] ) );
		}
		if ( $result['not_failed'] ) {
			\WP_CLI::log( 'Not in the failed status, skipped: #' . implode( ', #', $result['not_failed'] ) );
		}
		\WP_CLI::success( sprintf( '%d leads queued. Run `wp llt queue run` to send them now.', $result['queued'] ) );
	}

	/**
	 * Test email through Postmark.
	 *
	 * ## OPTIONS
	 *
	 * <email>
	 * : The recipient.
	 *
	 * @param array $args Arguments.
	 */
	public static function send_test( $args ): void {
		$to = sanitize_email( (string) ( $args[0] ?? '' ) );
		if ( ! is_email( $to ) ) {
			\WP_CLI::error( 'The address is not valid.' );
		}
		$result = ( new PostmarkClient() )->send(
			array(
				'From'          => EmailBuilder::from(),
				'To'            => $to,
				'Subject'       => '[' . EmailBuilder::site_name() . '] Enspire Email Leads Tracker test email',
				'TextBody'      => 'The Postmark configuration works (sent from WP-CLI).',
				'MessageStream' => (string) Settings::get( 'postmark_stream' ),
				'Tag'           => 'llt-test',
			)
		);
		if ( $result->is( SendResult::ACCEPTED ) ) {
			\WP_CLI::success( 'Accepted by Postmark. MessageID: ' . $result->message_id );
		} else {
			\WP_CLI::error( ErrorClassifier::describe( $result->code ) . ' [' . $result->code . '] ' . $result->message );
		}
	}

	public static function queue_run(): void {
		SendWorker::process_queue();
		\WP_CLI::success( 'The queue was processed.' );
	}

	/**
	 * Runs retention.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Only count, without deleting.
	 *
	 * @param array $args  Arguments.
	 * @param array $assoc Options.
	 */
	public static function cleanup_run( $args, $assoc ): void {
		$dry    = (bool) \WP_CLI\Utils\get_flag_value( $assoc, 'dry-run', false );
		$counts = RetentionService::run( $dry );
		foreach ( $counts as $rule => $count ) {
			\WP_CLI::log( sprintf( '%s: %d', $rule, $count ) );
		}
		\WP_CLI::success( ( $dry ? 'Would be deleted: ' : 'Deleted: ' ) . array_sum( $counts ) );
	}

	/**
	 * Deletes the content of some leads.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : The lead IDs.
	 *
	 * @param array $args Arguments.
	 */
	public static function content_delete( $args ): void {
		$deleted = LeadActions::delete_content( Util::ids( $args ) );
		\WP_CLI::success( sprintf( 'Content deleted for %d leads.', $deleted ) );
	}

	/**
	 * Statistics.
	 *
	 * ## OPTIONS
	 *
	 * [--since=<date>]
	 * : Start date (YYYY-MM-DD, UTC).
	 *
	 * [--until=<date>]
	 * : End date, exclusive (YYYY-MM-DD, UTC).
	 *
	 * [--by=<field>]
	 * : object, to split by location.
	 *
	 * [--format=<format>]
	 * : table, csv or json.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args  Arguments.
	 * @param array $assoc Options.
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
				$items[] = array_merge( array( 'location' => $object_id . ' ' . get_the_title( $object_id ) ), self::stat_row( $c ) );
			}
			\WP_CLI\Utils\format_items( $format, $items, array_merge( array( 'location' ), $fields ) );
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
