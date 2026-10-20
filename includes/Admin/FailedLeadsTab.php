<?php
/**
 * The "Failed leads" tab in Settings → Enspire Email Leads Tracker (v3). Administrators only.
 *
 * @package EmailLeadsTracker
 */

namespace EmailLeadsTracker\Admin;

use EmailLeadsTracker\Postmark\ErrorClassifier;
use EmailLeadsTracker\Postmark\SendResult;
use EmailLeadsTracker\Settings\SettingsPage;
use EmailLeadsTracker\State;

defined( 'ABSPATH' ) || exit;

final class FailedLeadsTab {

	public static function register(): void {
		add_filter( 'llt_settings_tabs', array( self::class, 'tab' ), 20 );
		add_action( 'llt_settings_page_registered', array( self::class, 'screen_options' ) );
		add_filter( 'set-screen-option', array( self::class, 'save_screen_option' ), 10, 3 );
		BulkActionHandler::register();
		LeadDetailsAjax::register();
		MenuBadge::register();
	}

	/**
	 * @param array $tabs The tabs.
	 */
	public static function tab( $tabs ): array {
		$tabs           = (array) $tabs;
		$tabs['failed'] = array(
			'label'  => 'Failed leads',
			'render' => array( self::class, 'render' ),
		);
		return $tabs;
	}

	public static function alert_link(): string {
		return SettingsPage::url( 'failed' );
	}

	/**
	 * @param string $hook The settings page hook.
	 */
	public static function screen_options( $hook ): void {
		if ( ! $hook ) {
			return;
		}
		add_action(
			'load-' . $hook,
			static function () {
				if ( 'failed' === SettingsPage::current_tab() ) {
					add_screen_option(
						'per_page',
						array(
							'label'   => 'Leads per page',
							'default' => 20,
							'option'  => FailedLeadsTable::PER_PAGE_OPTION,
						)
					);
				}
			}
		);
	}

	/**
	 * @param mixed  $status Current value.
	 * @param string $option The option.
	 * @param mixed  $value  New value.
	 * @return mixed
	 */
	public static function save_screen_option( $status, $option, $value ) {
		return FailedLeadsTable::PER_PAGE_OPTION === $option ? max( 1, min( 200, (int) $value ) ) : $status;
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- filtering and display only.
		$request = wp_unslash( $_GET );
		// phpcs:enable
		$view    = FailedLeadsQuery::sanitize_view( sanitize_key( (string) ( $request['view'] ?? 'failed' ) ) );
		$views   = FailedLeadsQuery::views();
		$view    = isset( $views[ $view ] ) ? $view : 'failed';
		$filters = FailedLeadsQuery::filters_from( $request );
		$counts  = FailedLeadsQuery::view_counts();

		self::render_config_banner();

		// Views.
		echo '<ul class="subsubsub">';
		$links = array();
		foreach ( $views as $key => $label ) {
			$links[] = sprintf(
				'<li><a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( SettingsPage::url( 'failed', array( 'view' => $key ) ) ),
				$key === $view ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				(int) ( $counts[ $key ] ?? 0 )
			);
		}
		echo implode( ' | </li>', $links ) . '</li></ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<br class="clear">';

		if ( 'stuck' === $view ) {
			echo '<p class="description">The leads in this view became due more than an hour ago and have not been sent. If the number grows, check in the Status tab that the server cron is running.</p>';
		}
		if ( 'bounced' === $view ) {
			echo '<p class="description">The recipient server rejected these emails. Resending will not help until you correct the location\'s address or reactivate it in Postmark → Suppressions. You can contact the visitor directly from "View message".</p>';
		}

		self::render_filters( $view, $filters );

		$table = new FailedLeadsTable( $view, $filters );
		$table->prepare_items();
		$total = (int) $table->get_pagination_arg( 'total_items' );

		// Bulk actions go to admin-post.php; the filters are sent again, for "select all".
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="llt-leads-form" data-total="' . (int) $total . '">';
		echo '<input type="hidden" name="action" value="llt_lead_action">';
		// The nonce (bulk-leads) is printed by WP_List_Table in the top bar.
		printf( '<input type="hidden" name="llt_view" value="%s">', esc_attr( $view ) );
		foreach ( array( 'llt_error' => 'error', 's' => 's', 'llt_from' => 'from', 'llt_to' => 'to' ) as $param => $key ) {
			printf( '<input type="hidden" name="%s" value="%s">', esc_attr( $param ), esc_attr( $filters[ $key ] ) );
		}
		echo '<input type="hidden" name="llt_all_matching" value="" id="llt-all-matching">';
		printf(
			'<div class="llt-select-all notice notice-info inline" hidden><p><span class="llt-select-all-text">All leads on this page are selected.</span> <a href="#" class="llt-select-all-link">Select all %d leads matching the filter</a></p></div>',
			(int) $total
		);
		$table->display();
		echo '</form>';

		echo '<dialog id="llt-message-dialog" class="llt-dialog"><form method="dialog"><h2>Visitor message</h2><div class="llt-dialog-body"></div><p><button class="button">Close</button></p></form></dialog>';
	}

	private static function render_filters( string $view, array $filters ): void {
		$codes = FailedLeadsQuery::error_codes( $view );
		echo '<form method="get" class="llt-filters">';
		echo '<input type="hidden" name="page" value="' . esc_attr( SettingsPage::SLUG ) . '">';
		echo '<input type="hidden" name="tab" value="failed">';
		echo '<input type="hidden" name="view" value="' . esc_attr( $view ) . '">';

		echo '<label class="screen-reader-text" for="llt-filter-error">Error code</label>';
		echo '<select name="llt_error" id="llt-filter-error"><option value="">All errors</option>';
		foreach ( $codes as $code ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $code ), selected( $filters['error'], $code, false ), esc_html( $code ) );
		}
		echo '</select> ';

		printf( '<label>From <input type="date" name="llt_from" value="%s"></label> ', esc_attr( $filters['from'] ) );
		printf( '<label>To <input type="date" name="llt_to" value="%s"></label> ', esc_attr( $filters['to'] ) );
		printf( '<label class="screen-reader-text" for="llt-filter-s">Location</label><input type="search" id="llt-filter-s" name="s" value="%s" placeholder="Search by location"> ', esc_attr( $filters['s'] ) );
		submit_button( 'Filter', 'secondary', '', false );
		if ( array_filter( $filters ) ) {
			printf( ' <a href="%s" class="button-link">Reset filters</a>', esc_url( SettingsPage::url( 'failed', array( 'view' => $view ) ) ) );
		}
		echo '</form>';
	}

	/**
	 * If the last failure is a configuration error, resending will not succeed until it is fixed.
	 */
	private static function render_config_banner(): void {
		$error = (array) State::get( 'last_error', array() );
		if ( SendResult::CONFIG !== ( $error['category'] ?? '' ) || ! State::get( 'consecutive_failures' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error inline"><p><strong>Sending is not working: %s (%s).</strong> Fix the settings in the <a href="%s">Postmark tab</a> and send a test email before resending the leads.</p></div>',
			esc_html( ErrorClassifier::describe( (string) ( $error['code'] ?? '' ) ) ),
			esc_html( (string) ( $error['code'] ?? '' ) ),
			esc_url( SettingsPage::url( 'postmark' ) )
		);
	}
}
