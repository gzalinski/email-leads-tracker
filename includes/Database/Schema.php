<?php
/**
 * Plugin table definitions.
 *
 * @package EnspireEmailLeadsTracker
 */

namespace EnspireEmailLeadsTracker\Database;

defined( 'ABSPATH' ) || exit;

final class Schema {

	public static function leads(): string {
		global $wpdb;
		return $wpdb->prefix . 'eelt_leads';
	}

	public static function messages(): string {
		global $wpdb;
		return $wpdb->prefix . 'eelt_lead_messages';
	}

	/**
	 * Creates or updates the tables with dbDelta.
	 * The schema includes the v2 and v3 columns from the start.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate  = $wpdb->get_charset_collate();
		$leads    = self::leads();
		$messages = self::messages();

		dbDelta(
			"CREATE TABLE {$leads} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  object_id bigint(20) unsigned NOT NULL,
  recipient_email varchar(254) NOT NULL,
  postmark_message_id varchar(64) NULL,
  send_status varchar(20) NOT NULL DEFAULT 'pending',
  delivery_status varchar(20) NOT NULL DEFAULT 'unknown',
  retry_count tinyint(3) unsigned NOT NULL DEFAULT 0,
  max_retries tinyint(3) unsigned NOT NULL DEFAULT 5,
  next_retry_at datetime NULL,
  locked_until datetime NULL,
  lock_token char(32) NULL,
  last_attempt_at datetime NULL,
  last_error_code varchar(40) NULL,
  last_error_message varchar(255) NULL,
  consent_at datetime NOT NULL,
  manual_resend_count tinyint(3) unsigned NOT NULL DEFAULT 0,
  last_resend_by bigint(20) unsigned NULL,
  last_resend_at datetime NULL,
  dismissed_at datetime NULL,
  dismissed_by bigint(20) unsigned NULL,
  created_at datetime NOT NULL,
  sent_at datetime NULL,
  delivered_at datetime NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_message_id (postmark_message_id),
  KEY idx_object (object_id),
  KEY idx_send_status (send_status),
  KEY idx_delivery_status (delivery_status),
  KEY idx_queue (send_status,next_retry_at),
  KEY idx_reconcile (send_status,delivery_status,sent_at),
  KEY idx_created (created_at)
) {$collate};"
		);

		dbDelta(
			"CREATE TABLE {$messages} (
  lead_id bigint(20) unsigned NOT NULL,
  sender_name varchar(200) NOT NULL,
  sender_email varchar(254) NOT NULL,
  sender_phone varchar(50) NULL,
  message text NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (lead_id),
  KEY idx_sender_email (sender_email),
  KEY idx_created (created_at)
) {$collate};"
		);
	}

	public static function drop(): void {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::messages() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::leads() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
