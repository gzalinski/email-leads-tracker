<?php
/**
 * v3: admin list queries, bulk actions, "select all matching the filter", the menu counter.
 */
// phpcs:ignoreFile
require_once dirname( __DIR__ ) . '/wpdb-sqlite.php';

use EmailLeadsTracker\Admin\BulkActionHandler;
use EmailLeadsTracker\Admin\FailedLeadsQuery;
use EmailLeadsTracker\Admin\MenuBadge;
use EmailLeadsTracker\Database\LeadRepository;
use EmailLeadsTracker\Database\MessageRepository;
use EmailLeadsTracker\Lead\LeadService;
use EmailLeadsTracker\Util;

global $wpdb;
$wpdb = new LLT_Test_WPDB();
$GLOBALS['llt_test_options']['llt_state'] = array();
$GLOBALS['llt_test_transients']           = array();
reset_settings( array( 'retention_failed_days' => 14 ) );

$wpdb->query( "INSERT INTO wp_posts (ID, post_title) VALUES (245, 'Downtown Location'), (300, 'Riverside Location')" );
$data = array( 'name' => 'John', 'email' => 'john@example.com', 'phone' => '', 'message' => 'Hello' );

function llt_failed( array $data, int $object_id, string $code, string $created = '' ): int {
	global $wpdb;
	$id      = LeadService::create( $data, $object_id, 'x@example.com' );
	$created = $created ?: Util::now();
	$wpdb->query( "UPDATE wp_location_leads SET send_status = 'failed', last_error_code = '{$code}', next_retry_at = NULL, created_at = '{$created}' WHERE id = {$id}" );
	return $id;
}

$f1 = llt_failed( $data, 245, 'pm_406', '2026-10-01 10:00:00' );
$f2 = llt_failed( $data, 245, 'retries_exhausted', '2026-10-05 10:00:00' );
$f3 = llt_failed( $data, 300, 'pm_406', '2026-10-10 10:00:00' );
$f4 = llt_failed( $data, 300, 'pm_10', '2026-10-15 10:00:00' );
MessageRepository::delete( $f4 );

// A lead stuck in the queue and a bounced lead.
$stuck = LeadService::create( $data, 245, 'x@example.com' );
$wpdb->query( "UPDATE wp_location_leads SET created_at = '" . Util::now( -2 * HOUR_IN_SECONDS ) . "', next_retry_at = '" . Util::now( -2 * HOUR_IN_SECONDS ) . "' WHERE id = {$stuck}" );
$fresh = LeadService::create( $data, 245, 'x@example.com' );
// An old lead waiting for a retry scheduled in a few hours: not stuck.
$waiting = LeadService::create( $data, 245, 'x@example.com' );
$wpdb->query( "UPDATE wp_location_leads SET created_at = '" . Util::now( -7 * HOUR_IN_SECONDS ) . "', next_retry_at = '" . Util::now( 3 * HOUR_IN_SECONDS ) . "' WHERE id = {$waiting}" );
$bounced = LeadService::create( $data, 300, 'x@example.com' );
$wpdb->query( "UPDATE wp_location_leads SET send_status = 'accepted', delivery_status = 'bounced', last_error_code = 'bounce:HardBounce' WHERE id = {$bounced}" );

// Views.
same( 'failed view', 4, FailedLeadsQuery::count( 'failed', array() ) );
same( 'stuck view (overdue by > 1 h)', array( $stuck ), FailedLeadsQuery::ids( 'stuck', array() ) );
same( 'HealthCheck counts only overdue leads', 1, LeadRepository::count_stuck( Util::now( -HOUR_IN_SECONDS ) ) );
same( 'bounced view', 1, FailedLeadsQuery::count( 'bounced', array() ) );
same( 'dismissed view', 0, FailedLeadsQuery::count( 'dismissed', array() ) );
same( 'unknown view → failed', 'failed', FailedLeadsQuery::sanitize_view( 'anything' ) );
check( '"Bounced" visible when bounced leads exist', isset( FailedLeadsQuery::views()['bounced'] ) );

// Filters.
same( 'error code filter', array( $f1, $f3 ), FailedLeadsQuery::ids( 'failed', array( 'error' => 'pm_406', 's' => '', 'from' => '', 'to' => '' ) ) );
same( 'location filter', array( $f3, $f4 ), FailedLeadsQuery::ids( 'failed', array( 'error' => '', 's' => 'Riverside', 'from' => '', 'to' => '' ) ) );
same( 'date range filter (end day included)', array( $f2, $f3 ), FailedLeadsQuery::ids( 'failed', array( 'error' => '', 's' => '', 'from' => '2026-10-05', 'to' => '2026-10-10' ) ) );
same( 'combined filter', array( $f3 ), FailedLeadsQuery::ids( 'failed', array( 'error' => 'pm_406', 's' => 'Riverside', 'from' => '', 'to' => '' ) ) );
same( 'error codes for the dropdown', array( 'pm_10', 'pm_406', 'retries_exhausted' ), FailedLeadsQuery::error_codes( 'failed' ) );

$filters = FailedLeadsQuery::filters_from( array( 'llt_error' => 'pm_406', 's' => '<b>Downtown</b>', 'llt_from' => '2026-13-99x', 'llt_to' => '2026-10-31' ) );
same( 'sanitized filters', array( 'error' => 'pm_406', 's' => 'Downtown', 'from' => '', 'to' => '2026-10-31' ), $filters );

// Rows, sorting, pagination.
$rows = FailedLeadsQuery::rows( 'failed', array(), 'created', 'asc', 2, 0 );
same( 'pagination: 2 rows', 2, count( $rows ) );
same( 'sorting by date', $f1, (int) $rows[0]->id );
same( 'location title', 'Downtown Location', $rows[0]->location_title );
same( 'has content', 1, (int) $rows[0]->has_content );
$rows = FailedLeadsQuery::rows( 'failed', array(), 'location', 'asc', 10, 0 );
same( 'sorting by location', 'Downtown Location', $rows[0]->location_title );
$rows = FailedLeadsQuery::rows( 'failed', array(), 'id; DROP TABLE x', 'desc', 10, 0 );
same( 'invalid sort column → id', $f4, (int) $rows[0]->id );
$last = FailedLeadsQuery::rows( 'failed', array(), 'id', 'desc', 10, 0 );
same( 'no content flagged', 0, (int) $last[0]->has_content );

// The menu counter: failed, not dismissed, with content.
same( 'menu counter', 3, MenuBadge::count() );

// Actions.
same( 'action not allowed in the view', 'error', BulkActionHandler::perform( 'resend', 'bounced', array( $bounced ), 1 )[0] );
same( 'stuck: no actions', array(), BulkActionHandler::actions_for( 'stuck' ) );
same( 'nothing selected', 'warning', BulkActionHandler::perform( 'resend', 'failed', array(), 1 )[0] );

// "Select all matching the filter" → resends exactly the filtered leads.
$ids    = FailedLeadsQuery::ids( 'failed', array( 'error' => 'pm_406', 's' => '', 'from' => '', 'to' => '' ) );
$result = BulkActionHandler::perform( 'resend', 'failed', $ids, 5 );
same( 'resend filtered: success', 'success', $result[0] );
check( 'message with the queued count', false !== strpos( $result[1], '2 leads' ) );
same( 'f1 in the queue', 'pending', LeadRepository::find( $f1 )->send_status );
same( 'f3 in the queue', 'pending', LeadRepository::find( $f3 )->send_status );
same( 'f2 untouched', 'failed', LeadRepository::find( $f2 )->send_status );
same( 'who resent', 5, (int) LeadRepository::find( $f1 )->last_resend_by );

$result = BulkActionHandler::perform( 'resend', 'failed', array( $f2, $f4 ), 5 );
check( 'no content reported', false !== strpos( $result[1], 'already deleted' ) && false !== strpos( $result[1], '#' . $f4 ) );

// Dismiss / restore / delete content.
BulkActionHandler::perform( 'dismiss', 'failed', array( $f4 ), 5 );
same( 'dismissed leaves the failed view', 0, FailedLeadsQuery::count( 'failed', array( 'error' => 'pm_10', 's' => '', 'from' => '', 'to' => '' ) ) );
same( 'dismissed appears in dismissed', 1, FailedLeadsQuery::count( 'dismissed', array() ) );
same( 'dismissed stays failed', 'failed', LeadRepository::find( $f4 )->send_status );
BulkActionHandler::perform( 'restore', 'dismissed', array( $f4 ), 5 );
same( 'restored', 0, FailedLeadsQuery::count( 'dismissed', array() ) );
BulkActionHandler::perform( 'delete_content', 'bounced', array( $bounced ), 5 );
same( 'content deleted from bounced', null, MessageRepository::find( $bounced ) );
check( 'the bounced lead stays', null !== LeadRepository::find( $bounced ) );

// Rendering the table columns, with a minimal WP_List_Table.
if ( ! class_exists( 'WP_List_Table' ) ) {
	class WP_List_Table {
		public $items = array();
		protected $_column_headers;
		private $pagination = array();
		public function __construct( $args = array() ) {}
		protected function get_items_per_page( $option, $default = 20 ) {
			return $default;
		}
		public function get_pagenum() {
			return 1;
		}
		protected function set_pagination_args( $args ) {
			$this->pagination = $args;
		}
		public function get_pagination_arg( $key ) {
			return $this->pagination[ $key ] ?? 0;
		}
		protected function row_actions( $actions ) {
			return '<div class="row-actions">' . implode( ' | ', $actions ) . '</div>';
		}
	}
}
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES ); }
function esc_attr( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES ); }
function esc_url( $v ) { return (string) $v; }
function get_edit_post_link( $id ) { return 'https://example.test/wp-admin/post.php?post=' . $id . '&action=edit'; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function wp_nonce_url( $url, $action ) { return $url . '&_wpnonce=' . md5( $action ); }

$table = new EmailLeadsTracker\Admin\FailedLeadsTable( 'failed', array( 'error' => '', 's' => '', 'from' => '', 'to' => '' ) );
$table->prepare_items();
same( 'table: total (only f4 is still failed)', 1, $table->get_pagination_arg( 'total_items' ) );
$item    = $table->items[0];
$id_html = $table->column_id( $item );
check( 'table: row actions', false !== strpos( $id_html, 'Dismiss' ) );
check( 'table: "View message" only with content', ( (int) $item->has_content ) === (int) ( false !== strpos( $id_html, 'View message' ) ) );
check( 'table: resend only with content', ( (int) $item->has_content ) === (int) ( false !== strpos( $id_html, 'Resend' ) ) );
check( 'table: location with a link', false !== strpos( $table->column_location( $item ), 'post.php?post=' ) );
check( 'table: error explained', '' !== strip_tags( $table->column_error( $item ) ) );
check( 'table: attempts', false !== strpos( $table->column_attempts( $item ), 'automatic' ) );
check( 'table: content', '' !== $table->column_content( $item ) );
check( 'table: checkbox', false !== strpos( $table->column_cb( $item ), 'name="ids[]"' ) );
$stuck_table = new EmailLeadsTracker\Admin\FailedLeadsTable( 'stuck', array( 'error' => '', 's' => '', 'from' => '', 'to' => '' ) );
same( 'stuck table: no checkbox', '', $stuck_table->column_cb( $item ) );
check( 'table: warning for 406', false !== strpos( $table->column_error( (object) array( 'last_error_code' => 'pm_406' ) ), 'dashicons-warning' ) );
