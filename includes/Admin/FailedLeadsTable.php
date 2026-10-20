<?php
/**
 * The admin lead list (v3), based on WP_List_Table.
 *
 * Visitors' data does not appear in the list; it is loaded on request ("View message").
 *
 * @package EnspireEmailLeadsTracker
 */

namespace EnspireEmailLeadsTracker\Admin;

use EnspireEmailLeadsTracker\Postmark\ErrorClassifier;
use EnspireEmailLeadsTracker\Settings\Settings;
use EnspireEmailLeadsTracker\Util;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class FailedLeadsTable extends \WP_List_Table {

	const PER_PAGE_OPTION = 'eelt_leads_per_page';

	/** Errors that will probably repeat on resend. */
	const REPEATING = array( 'pm_300', 'pm_406', 'content_missing' );

	/** @var string */
	private $view;
	/** @var array */
	private $filters;

	public function __construct( string $view, array $filters ) {
		parent::__construct(
			array(
				'singular' => 'lead',
				'plural'   => 'leads',
				'ajax'     => false,
			)
		);
		$this->view    = FailedLeadsQuery::sanitize_view( $view );
		$this->filters = $filters;
	}

	public function get_columns() {
		return array(
			'cb'           => '<input type="checkbox">',
			'id'           => 'ID',
			'created'      => 'Date',
			'location'     => 'Location',
			'recipient'    => 'Recipient',
			'error'        => 'Error',
			'attempts'     => 'Attempts',
			'last_attempt' => 'Last attempt',
			'content'      => 'Content',
		);
	}

	protected function get_sortable_columns() {
		return array(
			'id'       => array( 'id', true ),
			'created'  => array( 'created', true ),
			'location' => array( 'location', false ),
			'attempts' => array( 'attempts', true ),
		);
	}

	protected function get_primary_column_name() {
		return 'id';
	}

	public function prepare_items() {
		$per_page = (int) $this->get_items_per_page( self::PER_PAGE_OPTION, 20 );
		$page     = $this->get_pagenum();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- sorting only.
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'id';
		$order   = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc';
		// phpcs:enable

		$total       = FailedLeadsQuery::count( $this->view, $this->filters );
		$this->items = FailedLeadsQuery::rows( $this->view, $this->filters, $orderby, $order, $per_page, ( $page - 1 ) * $per_page );

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'id' );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / max( 1, $per_page ) ),
			)
		);
	}

	public function no_items() {
		echo 'There are no leads in this view.';
	}

	/**
	 * The bulk action select uses the name `eelt_do`, so it does not conflict with `action` from admin-post.php.
	 *
	 * @param string $which top or bottom.
	 */
	protected function bulk_actions( $which = '' ) {
		$actions = BulkActionHandler::actions_for( $this->view );
		if ( ! $actions ) {
			return;
		}
		$id = 'eelt-bulk-' . esc_attr( $which );
		echo '<label for="' . esc_attr( $id ) . '" class="screen-reader-text">Bulk actions</label>';
		echo '<select name="' . ( 'top' === $which ? 'eelt_do' : 'eelt_do_bottom' ) . '" id="' . esc_attr( $id ) . '" class="eelt-bulk-select">';
		echo '<option value="">Bulk actions</option>';
		foreach ( $actions as $action ) {
			printf( '<option value="%s">%s</option>', esc_attr( $action ), esc_html( BulkActionHandler::LABELS[ $action ] ) );
		}
		echo '</select>';
		submit_button( 'Apply', 'action', '', false, array( 'id' => 'eelt-apply-' . $which ) );
	}

	/**
	 * @param object $item The row.
	 */
	public function column_cb( $item ) {
		if ( ! BulkActionHandler::actions_for( $this->view ) ) {
			return '';
		}
		return sprintf( '<input type="checkbox" name="ids[]" value="%d" class="eelt-row-check">', (int) $item->id );
	}

	/**
	 * @param object $item The row.
	 */
	public function column_id( $item ) {
		$id      = (int) $item->id;
		$actions = array();
		foreach ( BulkActionHandler::actions_for( $this->view ) as $action ) {
			if ( 'resend' === $action && ! $item->has_content ) {
				continue;
			}
			$actions[ $action ] = sprintf(
				'<a href="%s"%s>%s</a>',
				esc_url( BulkActionHandler::row_url( $action, $this->view, $id ) ),
				'delete_content' === $action ? ' data-eelt-confirm="Permanently delete the visitor\'s data for this lead?"' : '',
				esc_html( BulkActionHandler::LABELS[ $action ] )
			);
		}
		if ( $item->has_content ) {
			$actions['view'] = sprintf( '<a href="#" class="eelt-view-message" data-lead="%d">View message</a>', $id );
		}
		return '<strong>#' . $id . '</strong>' . $this->row_actions( $actions );
	}

	/**
	 * @param object $item The row.
	 */
	public function column_created( $item ) {
		return esc_html( Util::local_date( (string) $item->created_at ) );
	}

	/**
	 * @param object $item The row.
	 */
	public function column_location( $item ) {
		$title = '' !== (string) $item->location_title ? (string) $item->location_title : '#' . (int) $item->object_id;
		$link  = get_edit_post_link( (int) $item->object_id );
		return $link ? sprintf( '<a href="%s">%s</a>', esc_url( $link ), esc_html( $title ) ) : esc_html( $title );
	}

	/**
	 * @param object $item The row.
	 */
	public function column_recipient( $item ) {
		return esc_html( (string) $item->recipient_email );
	}

	/**
	 * @param object $item The row.
	 */
	public function column_error( $item ) {
		$code = (string) $item->last_error_code;
		if ( '' === $code ) {
			return '—';
		}
		$repeating = in_array( $code, self::REPEATING, true ) || 0 === strpos( $code, 'bounce:' );
		$text      = 0 === strpos( $code, 'bounce:' ) ? 'Rejected by the recipient server (' . substr( $code, 7 ) . ')' : ErrorClassifier::describe( $code );
		return sprintf(
			'%s%s<br><code>%s</code>',
			$repeating ? '<span class="dashicons dashicons-warning eelt-warning" title="This will probably repeat on resend"></span> ' : '',
			esc_html( $text ),
			esc_html( $code )
		);
	}

	/**
	 * @param object $item The row.
	 */
	public function column_attempts( $item ) {
		$auto   = (int) $item->retry_count + 1;
		$manual = (int) $item->manual_resend_count;
		return esc_html( $auto . ' automatic' . ( $manual ? ', ' . $manual . ' manual' : '' ) );
	}

	/**
	 * @param object $item The row.
	 */
	public function column_last_attempt( $item ) {
		return esc_html( Util::local_date( $item->last_attempt_at ? (string) $item->last_attempt_at : null ) );
	}

	/**
	 * @param object $item The row.
	 */
	public function column_content( $item ) {
		if ( ! $item->has_content ) {
			return '<span class="eelt-muted">Deleted — cannot be resent</span>';
		}
		if ( in_array( $this->view, array( 'failed', 'bounced', 'dismissed' ), true ) && $item->content_created_at ) {
			$expires = strtotime( $item->content_created_at . ' UTC' ) + DAY_IN_SECONDS * max( 1, (int) Settings::get( 'retention_failed_days' ) );
			$days    = max( 0, (int) ceil( ( $expires - time() ) / DAY_IN_SECONDS ) );
			return esc_html( 0 === $days ? 'Available — deleted today' : sprintf( 'Available — deleted in %d %s', $days, 1 === $days ? 'day' : 'days' ) );
		}
		return 'Available';
	}
}
