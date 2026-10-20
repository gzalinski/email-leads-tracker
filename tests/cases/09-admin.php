<?php
/**
 * v3: interogările listei din admin, acțiunile în masă, „selectează toate după filtru”, contorul din meniu.
 */
// phpcs:ignoreFile
require_once dirname( __DIR__ ) . '/wpdb-sqlite.php';

use LocationLeadTracker\Admin\BulkActionHandler;
use LocationLeadTracker\Admin\FailedLeadsQuery;
use LocationLeadTracker\Admin\MenuBadge;
use LocationLeadTracker\Database\LeadRepository;
use LocationLeadTracker\Database\MessageRepository;
use LocationLeadTracker\Lead\LeadService;
use LocationLeadTracker\Util;

global $wpdb;
$wpdb = new LLT_Test_WPDB();
$GLOBALS['llt_test_options']['llt_state'] = array();
$GLOBALS['llt_test_transients'] = array();
reset_settings( array( 'retention_failed_days' => 14 ) );

$wpdb->query( "INSERT INTO wp_posts (ID, post_title) VALUES (245, 'Locația Centru'), (300, 'Locația Botanica')" );
$data = array( 'name' => 'Ion', 'email' => 'ion@example.md', 'phone' => '', 'message' => 'Salut' );

function llt_failed( array $data, int $object_id, string $code, string $created = '' ): int {
	global $wpdb;
	$id      = LeadService::create( $data, $object_id, 'x@example.md' );
	$created = $created ?: Util::now();
	$wpdb->query( "UPDATE wp_location_leads SET send_status = 'failed', last_error_code = '{$code}', next_retry_at = NULL, created_at = '{$created}' WHERE id = {$id}" );
	return $id;
}

$f1 = llt_failed( $data, 245, 'pm_406', '2026-10-01 10:00:00' );
$f2 = llt_failed( $data, 245, 'retries_exhausted', '2026-10-05 10:00:00' );
$f3 = llt_failed( $data, 300, 'pm_406', '2026-10-10 10:00:00' );
$f4 = llt_failed( $data, 300, 'pm_10', '2026-10-15 10:00:00' );
MessageRepository::delete( $f4 );

// Lead blocat în coadă și lead respins.
$stuck = LeadService::create( $data, 245, 'x@example.md' );
$wpdb->query( "UPDATE wp_location_leads SET created_at = '" . Util::now( -2 * HOUR_IN_SECONDS ) . "', next_retry_at = '" . Util::now( -2 * HOUR_IN_SECONDS ) . "' WHERE id = {$stuck}" );
$fresh = LeadService::create( $data, 245, 'x@example.md' );
// Lead vechi care așteaptă un retry programat peste câteva ore: nu este blocat.
$waiting = LeadService::create( $data, 245, 'x@example.md' );
$wpdb->query( "UPDATE wp_location_leads SET created_at = '" . Util::now( -7 * HOUR_IN_SECONDS ) . "', next_retry_at = '" . Util::now( 3 * HOUR_IN_SECONDS ) . "' WHERE id = {$waiting}" );
$bounced = LeadService::create( $data, 300, 'x@example.md' );
$wpdb->query( "UPDATE wp_location_leads SET send_status = 'accepted', delivery_status = 'bounced', last_error_code = 'bounce:HardBounce' WHERE id = {$bounced}" );

// Vizualizări.
same( 'vizualizare eșuate', 4, FailedLeadsQuery::count( 'failed', array() ) );
same( 'vizualizare blocate (scadente de > 1 h)', array( $stuck ), FailedLeadsQuery::ids( 'stuck', array() ) );
same( 'HealthCheck numără doar leadurile întârziate', 1, LeadRepository::count_stuck( Util::now( -HOUR_IN_SECONDS ) ) );
same( 'vizualizare respinse', 1, FailedLeadsQuery::count( 'bounced', array() ) );
same( 'vizualizare ignorate', 0, FailedLeadsQuery::count( 'dismissed', array() ) );
same( 'vizualizare necunoscută → eșuate', 'failed', FailedLeadsQuery::sanitize_view( 'orice' ) );
check( '„Respinse” vizibil când există leaduri respinse', isset( FailedLeadsQuery::views()['bounced'] ) );

// Filtre.
same( 'filtru cod eroare', array( $f1, $f3 ), FailedLeadsQuery::ids( 'failed', array( 'error' => 'pm_406', 's' => '', 'from' => '', 'to' => '' ) ) );
same( 'filtru locație', array( $f3, $f4 ), FailedLeadsQuery::ids( 'failed', array( 'error' => '', 's' => 'Botanica', 'from' => '', 'to' => '' ) ) );
same( 'filtru interval (inclusiv ziua de sfârșit)', array( $f2, $f3 ), FailedLeadsQuery::ids( 'failed', array( 'error' => '', 's' => '', 'from' => '2026-10-05', 'to' => '2026-10-10' ) ) );
same( 'filtru combinat', array( $f3 ), FailedLeadsQuery::ids( 'failed', array( 'error' => 'pm_406', 's' => 'Botanica', 'from' => '', 'to' => '' ) ) );
same( 'coduri de eroare pentru dropdown', array( 'pm_10', 'pm_406', 'retries_exhausted' ), FailedLeadsQuery::error_codes( 'failed' ) );

$filters = FailedLeadsQuery::filters_from( array( 'llt_error' => 'pm_406', 's' => '<b>Centru</b>', 'llt_from' => '2026-13-99x', 'llt_to' => '2026-10-31' ) );
same( 'filtre sanitizate', array( 'error' => 'pm_406', 's' => 'Centru', 'from' => '', 'to' => '2026-10-31' ), $filters );

// Rânduri, sortare, paginare.
$rows = FailedLeadsQuery::rows( 'failed', array(), 'created', 'asc', 2, 0 );
same( 'paginare: 2 rânduri', 2, count( $rows ) );
same( 'sortare după dată', $f1, (int) $rows[0]->id );
same( 'titlul locației', 'Locația Centru', $rows[0]->location_title );
same( 'are conținut', 1, (int) $rows[0]->has_content );
$rows = FailedLeadsQuery::rows( 'failed', array(), 'location', 'asc', 10, 0 );
same( 'sortare după locație', 'Locația Botanica', $rows[0]->location_title );
$rows = FailedLeadsQuery::rows( 'failed', array(), 'id; DROP TABLE x', 'desc', 10, 0 );
same( 'coloană de sortare invalidă → id', $f4, (int) $rows[0]->id );
$last = FailedLeadsQuery::rows( 'failed', array(), 'id', 'desc', 10, 0 );
same( 'fără conținut marcat', 0, (int) $last[0]->has_content );

// Contorul din meniu: eșuate, neignorate, cu conținut.
same( 'contor meniu', 3, MenuBadge::count() );

// Acțiuni.
same( 'acțiune nepermisă în vizualizare', 'error', BulkActionHandler::perform( 'resend', 'bounced', array( $bounced ), 1 )[0] );
same( 'blocate: fără acțiuni', array(), BulkActionHandler::actions_for( 'stuck' ) );
same( 'nimic selectat', 'warning', BulkActionHandler::perform( 'resend', 'failed', array(), 1 )[0] );

// „Selectează toate după filtru” → retrimite exact leadurile filtrate.
$ids    = FailedLeadsQuery::ids( 'failed', array( 'error' => 'pm_406', 's' => '', 'from' => '', 'to' => '' ) );
$result = BulkActionHandler::perform( 'resend', 'failed', $ids, 5 );
same( 'retrimite filtrate: succes', 'success', $result[0] );
check( 'mesaj cu numărul pus în coadă', false !== strpos( $result[1], '2 leaduri' ) );
same( 'f1 în coadă', 'pending', LeadRepository::find( $f1 )->send_status );
same( 'f3 în coadă', 'pending', LeadRepository::find( $f3 )->send_status );
same( 'f2 neatins', 'failed', LeadRepository::find( $f2 )->send_status );
same( 'cine a retrimis', 5, (int) LeadRepository::find( $f1 )->last_resend_by );

$result = BulkActionHandler::perform( 'resend', 'failed', array( $f2, $f4 ), 5 );
check( 'fără conținut raportat', false !== strpos( $result[1], 'conținutul a fost deja șters' ) && false !== strpos( $result[1], '#' . $f4 ) );

// Ignoră / restaurează / șterge conținut.
BulkActionHandler::perform( 'dismiss', 'failed', array( $f4 ), 5 );
same( 'ignorat dispare din eșuate', 0, FailedLeadsQuery::count( 'failed', array( 'error' => 'pm_10', 's' => '', 'from' => '', 'to' => '' ) ) );
same( 'ignorat apare în ignorate', 1, FailedLeadsQuery::count( 'dismissed', array() ) );
same( 'ignorat rămâne failed', 'failed', LeadRepository::find( $f4 )->send_status );
BulkActionHandler::perform( 'restore', 'dismissed', array( $f4 ), 5 );
same( 'restaurat', 0, FailedLeadsQuery::count( 'dismissed', array() ) );
BulkActionHandler::perform( 'delete_content', 'bounced', array( $bounced ), 5 );
same( 'conținut șters din respinse', null, MessageRepository::find( $bounced ) );
check( 'leadul respins rămâne', null !== LeadRepository::find( $bounced ) );

// Randarea coloanelor din tabel, cu un WP_List_Table minimal.
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

$table = new LocationLeadTracker\Admin\FailedLeadsTable( 'failed', array( 'error' => '', 's' => '', 'from' => '', 'to' => '' ) );
$table->prepare_items();
same( 'tabel: total (doar f4 mai e eșuat)', 1, $table->get_pagination_arg( 'total_items' ) );
$item = $table->items[0];
$id_html = $table->column_id( $item );
check( 'tabel: acțiuni de rând', false !== strpos( $id_html, 'Ignoră' ) );
check( 'tabel: „Vezi mesajul” doar cu conținut', ( (int) $item->has_content ) === (int) ( false !== strpos( $id_html, 'Vezi mesajul' ) ) );
check( 'tabel: retrimite doar cu conținut', ( (int) $item->has_content ) === (int) ( false !== strpos( $id_html, 'Retrimite' ) ) );
check( 'tabel: locație cu link', false !== strpos( $table->column_location( $item ), 'post.php?post=' ) );
check( 'tabel: eroare explicată', '' !== strip_tags( $table->column_error( $item ) ) );
check( 'tabel: încercări', false !== strpos( $table->column_attempts( $item ), 'automate' ) );
check( 'tabel: conținut', '' !== $table->column_content( $item ) );
check( 'tabel: checkbox', false !== strpos( $table->column_cb( $item ), 'name="ids[]"' ) );
$stuck_table = new LocationLeadTracker\Admin\FailedLeadsTable( 'stuck', array( 'error' => '', 's' => '', 'from' => '', 'to' => '' ) );
same( 'tabel blocate: fără checkbox', '', $stuck_table->column_cb( $item ) );
check( 'tabel: avertizare pentru 406', false !== strpos( $table->column_error( (object) array( 'last_error_code' => 'pm_406' ) ), 'dashicons-warning' ) );
