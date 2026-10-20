<?php
/**
 * Definition of the tabs and fields of the settings page.
 *
 * Each field: type, label, desc (optional), plus type-specific attributes.
 * Types: text, email, number, select, checkbox, secret, textarea, html, cidr_list, schedule.
 *
 * @package EnspireEmailLeadsTracker
 */

namespace EnspireEmailLeadsTracker\Settings;

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
						'label' => 'Sender email',
						'desc'  => 'An address on a domain verified in Postmark (DKIM and Return-Path).',
					),
					'from_name'    => array(
						'type'  => 'text',
						'label' => 'Sender name',
					),
					'subject'      => array(
						'type'  => 'text',
						'label' => 'Email subject',
						'desc'  => 'Available variables: {post_title}, {site_name}.',
					),
					'email_format' => array(
						'type'    => 'select',
						'label'   => 'Email format',
						'options' => array(
							'text' => 'Plain text',
							'html' => 'HTML (with a text version)',
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
						'desc'  => 'From Postmark → Server → API Tokens. Stored encrypted and never shown again.',
					),
					'postmark_stream'  => array(
						'type'  => 'text',
						'label' => 'Message Stream',
						'desc'  => 'The transactional stream; usually "outbound".',
					),
					'postmark_timeout' => array(
						'type'  => 'number',
						'label' => 'Timeout (seconds)',
						'min'   => 5,
						'max'   => 60,
					),
				),
				'after'  => array( TestActions::class, 'render_test_email' ),
			),
			'form'      => array(
				'label'  => 'Form',
				'fields' => array(
					'consent_text'    => array(
						'type'  => 'html',
						'label' => 'GDPR consent text',
						'desc'  => 'Shown next to the required checkbox. Allowed HTML: <a href target rel>, <strong>, <em>, <br>. Add the link to your privacy page here.',
					),
					'success_message' => array(
						'type'  => 'text',
						'label' => 'Confirmation message',
						'desc'  => 'Shown to the visitor after submitting. Do not promise that the email was delivered.',
					),
				),
			),
			'antispam'  => array(
				'label'  => 'Anti-spam',
				'fields' => array(
					'turnstile_enabled'   => array(
						'type'  => 'checkbox',
						'label' => 'Cloudflare Turnstile',
						'text'  => 'Enable the Turnstile check',
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
						'label'   => 'If the check is unavailable',
						'options' => array(
							'reject' => 'Reject the submission',
							'accept' => 'Accept the submission',
						),
					),
					'rate_limit'          => array(
						'type'  => 'number',
						'label' => 'Messages per IP per hour',
						'desc'  => '0 disables the limit.',
						'min'   => 0,
						'max'   => 1000,
					),
					'min_seconds'         => array(
						'type'  => 'number',
						'label' => 'Minimum time to fill in (seconds)',
						'min'   => 0,
						'max'   => 60,
					),
					'ip_source'           => array(
						'type'    => 'select',
						'label'   => 'IP source',
						'desc'    => 'Automatic recognizes direct connections, Cloudflare and local proxies.',
						'options' => array(
							'auto'       => 'Automatic',
							'direct'     => 'Direct connection only',
							'cloudflare' => 'Cloudflare only',
						),
					),
					'extra_proxies'       => array(
						'type'  => 'cidr_list',
						'label' => 'Additional proxies',
						'desc'  => 'CIDR networks (one per line) of other CDNs or load balancers with public IPs.',
					),
				),
				'after'  => array( TestActions::class, 'render_ip_diagnostic' ),
			),
			'retry'     => array(
				'label'  => 'Retries',
				'fields' => array(
					'max_retries'    => array(
						'type'  => 'number',
						'label' => 'Maximum number of retries',
						'min'   => 0,
						'max'   => 10,
					),
					'retry_schedule' => array(
						'type'  => 'schedule',
						'label' => 'Intervals (minutes)',
						'desc'  => 'Comma-separated, in increasing order. E.g. 1, 5, 15, 60, 360.',
					),
				),
			),
			'alerts'    => array(
				'label'  => 'Alerts',
				'fields' => array(
					'alert_email'             => array(
						'type'  => 'email',
						'label' => 'Alert email',
					),
					'alert_threshold'         => array(
						'type'  => 'number',
						'label' => 'Consecutive failure threshold',
						'min'   => 1,
						'max'   => 100,
					),
					'alert_interval_hours'    => array(
						'type'  => 'number',
						'label' => 'Minimum interval between alerts (hours)',
						'min'   => 1,
						'max'   => 168,
					),
					'alert_fallback_postmark' => array(
						'type'  => 'checkbox',
						'label' => 'Fallback',
						'text'  => 'Send alerts through Postmark if wp_mail() fails',
					),
				),
				'after'  => array( TestActions::class, 'render_test_alert' ),
			),
			'retention' => array(
				'label'  => 'Retention',
				'fields' => array(
					'retention_accepted_hours' => array(
						'type'  => 'number',
						'label' => 'Hours after acceptance',
						'desc'  => 'When delivery confirmation is off, the content is deleted this long after Postmark accepts the email. 0 = at the next cleanup.',
						'min'   => 0,
						'max'   => 720,
					),
					'retention_failed_days'    => array(
						'type'  => 'number',
						'label' => 'Days for failed leads',
						'desc'  => 'How long they can be resent.',
						'min'   => 1,
						'max'   => 365,
					),
					'delete_on_uninstall'      => array(
						'type'  => 'checkbox',
						'label' => 'Uninstall',
						'text'  => 'Delete the tables and settings when the plugin is uninstalled',
					),
				),
			),
			'status'    => array(
				'label'  => 'Status',
				'render' => array( StatusTab::class, 'render' ),
			),
		);

		return (array) apply_filters( 'eelt_settings_tabs', $tabs );
	}

	/**
	 * All fields, from all tabs.
	 */
	public static function all_fields(): array {
		$fields = array();
		foreach ( self::tabs() as $tab ) {
			$fields += $tab['fields'] ?? array();
		}
		return $fields;
	}
}
