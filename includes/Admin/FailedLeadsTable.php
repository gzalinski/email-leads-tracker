<?php
/**
 * Lista de leaduri din admin (v3), pe baza WP_List_Table.
 *
 * Datele vizitatorilor nu apar în listă; se încarcă la cerere („Vezi mesajul”).
 *
 * @package LocationLeadTracker
 */

namespace LocationLeadTracker\Admin;

use LocationLeadTracker\Postmark\ErrorClassifier;
use LocationLeadTracker\Settings\Settings;
use LocationLeadTracker\Util;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class FailedLeadsTable extends \WP_List_Table {

	const PER_PAGE_OPTION = 'llt_leads_per_page';

	/** Erori care probabil se repetă la retrimitere. */
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
			'created'      => 'Data',
			'location'     => 'Locație',
			'recipient'    => 'Destinatar',
			'error'        => 'Eroare',
			'attempts'     => 'Încercări',
			'last_attempt' => 'Ultima încercare',
			'content'      => 'Conținut',
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
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- doar sortare.
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
		echo 'Nu există leaduri în această vizualizare.';
	}

	/**
	 * Selectul de acțiuni în masă folosește numele `llt_do`, ca să nu intre în conflict cu `action` din admin-post.php.
	 *
	 * @param string $which top sau bottom.
	 */
	protected function bulk_actions( $which = '' ) {
		$actions = BulkActionHandler::actions_for( $this->view );
		if ( ! $actions ) {
			return;
		}
		$id = 'llt-bulk-' . esc_attr( $which );
		echo '<label for="' . esc_attr( $id ) . '" class="screen-reader-text">Acțiuni în masă</label>';
		echo '<select name="' . ( 'top' === $which ? 'llt_do' : 'llt_do_bottom' ) . '" id="' . esc_attr( $id ) . '" class="llt-bulk-select">';
		echo '<option value="">Acțiuni în masă</option>';
		foreach ( $actions as $action ) {
			printf( '<option value="%s">%s</option>', esc_attr( $action ), esc_html( BulkActionHandler::LABELS[ $action ] ) );
		}
		echo '</select>';
		submit_button( 'Aplică', 'action', '', false, array( 'id' => 'llt-apply-' . $which ) );
	}

	/**
	 * @param object $item Rândul.
	 */
	public function column_cb( $item ) {
		if ( ! BulkActionHandler::actions_for( $this->view ) ) {
			return '';
		}
		return sprintf( '<input type="checkbox" name="ids[]" value="%d" class="llt-row-check">', (int) $item->id );
	}

	/**
	 * @param object $item Rândul.
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
				'delete_content' === $action ? ' data-llt-confirm="Ștergi definitiv datele vizitatorului pentru acest lead?"' : '',
				esc_html( BulkActionHandler::LABELS[ $action ] )
			);
		}
		if ( $item->has_content ) {
			$actions['view'] = sprintf( '<a href="#" class="llt-view-message" data-lead="%d">Vezi mesajul</a>', $id );
		}
		return '<strong>#' . $id . '</strong>' . $this->row_actions( $actions );
	}

	/**
	 * @param object $item Rândul.
	 */
	public function column_created( $item ) {
		return esc_html( Util::local_date( (string) $item->created_at ) );
	}

	/**
	 * @param object $item Rândul.
	 */
	public function column_location( $item ) {
		$title = '' !== (string) $item->location_title ? (string) $item->location_title : '#' . (int) $item->object_id;
		$link  = get_edit_post_link( (int) $item->object_id );
		return $link ? sprintf( '<a href="%s">%s</a>', esc_url( $link ), esc_html( $title ) ) : esc_html( $title );
	}

	/**
	 * @param object $item Rândul.
	 */
	public function column_recipient( $item ) {
		return esc_html( (string) $item->recipient_email );
	}

	/**
	 * @param object $item Rândul.
	 */
	public function column_error( $item ) {
		$code = (string) $item->last_error_code;
		if ( '' === $code ) {
			return '—';
		}
		$repeating = in_array( $code, self::REPEATING, true ) || 0 === strpos( $code, 'bounce:' );
		$text      = 0 === strpos( $code, 'bounce:' ) ? 'Respins de serverul destinatarului (' . substr( $code, 7 ) . ')' : ErrorClassifier::describe( $code );
		return sprintf(
			'%s%s<br><code>%s</code>',
			$repeating ? '<span class="dashicons dashicons-warning llt-warning" title="Probabil se va repeta la retrimitere"></span> ' : '',
			esc_html( $text ),
			esc_html( $code )
		);
	}

	/**
	 * @param object $item Rândul.
	 */
	public function column_attempts( $item ) {
		$auto   = (int) $item->retry_count + 1;
		$manual = (int) $item->manual_resend_count;
		return esc_html( $auto . ' automate' . ( $manual ? ', ' . $manual . ' manuale' : '' ) );
	}

	/**
	 * @param object $item Rândul.
	 */
	public function column_last_attempt( $item ) {
		return esc_html( Util::local_date( $item->last_attempt_at ? (string) $item->last_attempt_at : null ) );
	}

	/**
	 * @param object $item Rândul.
	 */
	public function column_content( $item ) {
		if ( ! $item->has_content ) {
			return '<span class="llt-muted">Șters — nu poate fi retrimis</span>';
		}
		if ( in_array( $this->view, array( 'failed', 'bounced', 'dismissed' ), true ) && $item->content_created_at ) {
			$expires = strtotime( $item->content_created_at . ' UTC' ) + DAY_IN_SECONDS * max( 1, (int) Settings::get( 'retention_failed_days' ) );
			$days    = max( 0, (int) ceil( ( $expires - time() ) / DAY_IN_SECONDS ) );
			return esc_html( 0 === $days ? 'Disponibil — se șterge azi' : sprintf( 'Disponibil — se șterge în %d %s', $days, 1 === $days ? 'zi' : 'zile' ) );
		}
		return 'Disponibil';
	}
}
