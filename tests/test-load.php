<?php
/**
 * Load smoke test — proves every plugin file can be included without a fatal.
 *
 * Not a substitute for running it in WordPress, but it catches the failures
 * that are most expensive to discover on a client site: a call to an undefined
 * function at include time, a duplicate declaration, a parse error in a
 * template, a class referenced before it is loaded.
 *
 * Run: php tests/test-load.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_DEBUG', false );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['__opts']    = array();
$GLOBALS['__actions'] = array();

/* --- the slice of WordPress these files touch at include time --- */
function add_action( $tag, $cb = null, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $tag ][] = $cb; return true; }
function add_filter( $tag, $cb = null, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $tag ][] = $cb; return true; }
function remove_action( $tag, $cb = null, $p = 10 ) { return true; }
function remove_filter( $tag, $cb = null, $p = 10 ) { return true; }
function apply_filters( $tag, $value = null ) { return $value; }
function do_action( $tag ) {}
function did_action( $tag ) { return 1; }
function has_action( $tag, $cb = false ) { return false; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opts'] ) ? $GLOBALS['__opts'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opts'][ $k ] = $v; return true; }
function add_option( $k, $v, $x = '', $a = 'yes' ) { if ( array_key_exists( $k, $GLOBALS['__opts'] ) ) { return false; } $GLOBALS['__opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['__opts'][ $k ] ); return true; }
function get_site_option( $k, $d = false ) { return $d; }
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $t = 0 ) { return true; }
function delete_transient( $k ) { return true; }
function is_multisite() { return false; }
function is_admin() { return false; }
function wp_doing_ajax() { return false; }
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugin_dir_url( $f ) { return 'https://example.test/wp-content/plugins/wc-pet-gallery/'; }
function plugin_basename( $f ) { return 'wc-pet-gallery/wc-pet-gallery.php'; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function home_url( $p = '' ) { return 'https://example.test/' . $p; }
function register_activation_hook( $f, $cb ) {}
function register_deactivation_hook( $f, $cb ) {}
function wp_next_scheduled( $h ) { return false; }
function wp_schedule_event( $t, $r, $h ) {}
function wp_schedule_single_event( $t, $h ) {}
function wp_clear_scheduled_hook( $h ) {}
function wp_get_schedule( $h ) { return false; }
function current_user_can( $c ) { return true; }
function wp_create_nonce( $a = -1 ) { return 'nonce'; }
function wp_nonce_field() {}
function wp_nonce_url( $u, $a = -1 ) { return $u; }
function check_admin_referer( $a = -1 ) { return 1; }
function check_ajax_referer( $a = -1, $q = false, $die = true ) { return 1; }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_url( $t ) { return (string) $t; }
function esc_url_raw( $t ) { return (string) $t; }
function esc_textarea( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_html__( $t, $d = null ) { return $t; }
function esc_attr__( $t, $d = null ) { return $t; }
function esc_html_e( $t, $d = null ) { echo $t; }
function esc_attr_e( $t, $d = null ) { echo $t; }
function __( $t, $d = null ) { return $t; }
function _e( $t, $d = null ) { echo $t; }
function _x( $t, $c, $d = null ) { return $t; }
function _n_noop( $s, $p, $d = null ) { return array( $s, $p ); }
function sanitize_text_field( $t ) { return is_scalar( $t ) ? trim( strip_tags( (string) $t ) ) : ''; }
function sanitize_key( $t ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $t ) ); }
function sanitize_title( $t ) { return strtolower( preg_replace( '/[^a-z0-9]+/i', '-', (string) $t ) ); }
function sanitize_title_with_dashes( $t ) { return sanitize_title( $t ); }
function sanitize_html_class( $t ) { return preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $t ); }
function sanitize_hex_color( $t ) { return preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', (string) $t ) ? $t : null; }
function wp_kses_post( $t ) { return (string) $t; }
function wp_unslash( $t ) { return is_array( $t ) ? array_map( 'stripslashes', $t ) : stripslashes( (string) $t ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function absint( $v ) { return abs( (int) $v ); }
function wp_parse_args( $a, $d = array() ) { return array_merge( $d, (array) $a ); }
function register_post_type( $t, $a = array() ) {}
function register_taxonomy( $t, $o, $a = array() ) {}

// A minimal post-status registry, so the fallback registration can be tested.
$GLOBALS['__stati'] = array( 'publish' => 1, 'draft' => 1, 'pending' => 1, 'trash' => 1 );
function register_post_status( $slug, $args = array() ) { $GLOBALS['__stati'][ $slug ] = $args; }
function get_post_status_object( $slug ) { return isset( $GLOBALS['__stati'][ $slug ] ) ? (object) array( 'label' => $slug ) : null; }
function post_type_exists( $t ) { return false; }
function taxonomy_exists( $t ) { return true; }
function add_meta_box() {}
function get_post_type( $id = null ) { return 'pet-gallery'; }
function get_post_meta( $id, $k = '', $single = false ) { return ''; }
function update_post_meta( $id, $k, $v ) { return true; }
function delete_post_meta( $id, $k ) { return true; }
function get_post( $p = null ) { return null; }
function get_permalink( $p = null ) { return 'https://example.test/pet-gallery/x/'; }
function get_post_type_archive_link( $t ) { return 'https://example.test/pet-gallery/'; }
function get_the_post_thumbnail_url( $id = null, $s = 'post-thumbnail' ) { return ''; }
function get_post_thumbnail_id( $id = null ) { return 0; }
function get_the_title( $id = null ) { return 'Pet'; }
function get_the_date( $f = '', $id = null ) { return '2026-01-01'; }
function get_terms( $a = array() ) { return array(); }
function get_term( $id, $tax = '' ) { return null; }
function get_term_by( $f, $v, $tax ) { return false; }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function wp_count_posts( $t = 'post' ) { return (object) array( 'publish' => 0 ); }
function wp_list_pluck( $l, $f ) { return array(); }
function update_postmeta_cache( $ids ) {}
function update_meta_cache( $t, $ids ) {}
function update_post_thumbnail_cache( $q = null ) {}
function wp_cache_delete( $k, $g = '' ) {}
function clean_post_cache( $id ) {}
function wp_reset_postdata() {}
function wp_send_json( $d, $s = null ) {}
function wp_send_json_success( $d = null, $s = null ) {}
function wp_send_json_error( $d = null, $s = null ) {}
function wp_safe_redirect( $u, $s = 302 ) {}
function wp_die( $m = '', $t = '', $a = array() ) {}
function wp_remote_get( $u, $a = array() ) { return array(); }
function wp_remote_retrieve_response_code( $r ) { return 200; }
function wp_remote_retrieve_body( $r ) { return ''; }
function wp_enqueue_style( $h, $s = '', $d = array(), $v = false ) {}
function wp_enqueue_script( $h, $s = '', $d = array(), $v = false, $f = false ) {}
function wp_localize_script( $h, $n, $d ) {}
function locate_template( $t, $load = false, $once = true ) { return ''; }
function get_stylesheet_directory() { return '/themes/child'; }
function get_template_directory() { return '/themes/parent'; }
function wp_get_theme() { return new WP_Theme(); }
function get_pages( $a = array() ) { return array(); }
function get_posts( $a = array() ) { return array(); }
function number_format_i18n( $n, $d = 0 ) { return (string) $n; }
function wp_date( $f, $t = null ) { return date( 'Y-m-d', $t ?: time() ); }
function date_i18n( $f, $t = null ) { return date( $f, $t ?: time() ); }
function current_time( $t = 'mysql' ) { return 'timestamp' === $t ? time() : date( 'Y-m-d H:i:s' ); }
function selected( $a, $b = true, $e = true ) { $r = (string) $a === (string) $b ? ' selected' : ''; if ( $e ) { echo $r; } return $r; }
function disabled( $a, $b = true, $e = true ) { $r = $a == $b ? ' disabled' : ''; if ( $e ) { echo $r; } return $r; }
function esc_js( $t ) { return addslashes( (string) $t ); }
function wp_get_object_terms( $id, $tax, $a = array() ) { return array(); }
function get_the_terms( $id, $tax ) { return false; }
function get_post_time( $f = 'U', $gmt = false, $p = null ) { return time(); }
function get_post_modified_time( $f = 'U', $gmt = false, $p = null ) { return time(); }
function wp_insert_post( $a, $e = false ) { return 123; }
function wp_trash_post( $id ) { return true; }
function wp_delete_post( $id, $force = false ) { return true; }
function wc_get_order( $id ) { return false; }
function as_unschedule_all_actions( $h ) {}
function as_has_scheduled_action( $h ) { return false; }
function as_schedule_recurring_action() {}
function as_enqueue_async_action() {}
function register_shutdown_function_stub() {}
function checked( $a, $b = true, $e = true ) { $r = (string) $a === (string) $b ? ' checked' : ''; if ( $e ) { echo $r; } return $r; }
function submit_button( $t = null ) { echo '<button>' . esc_html( (string) $t ) . '</button>'; }
function add_settings_error( $s, $c, $m, $ty = 'error' ) {}
function get_settings_errors( $s = '' ) { return array(); }
function settings_errors( $s = '' ) {}
function add_submenu_page() { return 'toplevel_page_stub'; }
function add_menu_page() { return 'toplevel_page_stub'; }
function add_query_arg() { return 'https://example.test/'; }
function remove_query_arg( $k, $u = false ) { return 'https://example.test/'; }
function get_current_screen() { return null; }
function is_singular( $t = '' ) { return false; }
function is_post_type_archive( $t = '' ) { return false; }
function is_paged() { return false; }
function has_shortcode( $c, $t ) { return false; }
function add_shortcode( $t, $cb ) {}
function shortcode_exists( $t ) { return false; }
function shortcode_atts( $p, $a, $s = '' ) { return array_merge( $p, (array) $a ); }
function register_widget( $w ) {}
function wc_get_product( $id ) { return null; }
function wc_get_products( $a = array() ) { return array(); }
function as_next_scheduled_action( $h ) { return false; }
function get_user_meta( $u, $k = '', $s = false ) { return ''; }
function update_user_meta( $u, $k, $v ) { return true; }
function get_current_user_id() { return 1; }
function wp_get_attachment_url( $id ) { return ''; }
function set_post_thumbnail( $p, $t ) { return true; }

class WP_Error { public function get_error_code() { return ''; } public function get_error_message() { return ''; } }
class WP_Post {}
class WP_Query { public $posts = array(); public function __construct( $a = array() ) {} public function have_posts() { return false; } public function the_post() {} }
class WP_Theme { public function get( $k ) { return 'Stub Theme'; } }
class WP_Widget { public function __construct( $id = '', $name = '', $o = array(), $c = array() ) {} public function get_field_id( $f ) { return $f; } public function get_field_name( $f ) { return $f; } }
class wpdb {
	public $posts = 'wp_posts'; public $postmeta = 'wp_postmeta'; public $prefix = 'wp_';
	public function prepare( $q ) { return $q; }
	public function get_results( $q ) { return array(); }
	public function get_col( $q ) { return array(); }
	public function get_var( $q ) { return 0; }
	public function query( $q ) { return 0; }
	public function update() { return 0; }
}
$GLOBALS['wpdb'] = new wpdb();

/* --- pretend a parent sync plugin is active so the boot gate opens --- */
$GLOBALS['__opts']['active_plugins'] = array( 'wc-unified-km/wc-unified-km.php' );

$root = dirname( __DIR__ );
$fail = 0;

echo "Loading the plugin as WordPress would…\n";

try {
	require $root . '/wc-pet-gallery.php';
	echo "  ok  plugin bootstrapped\n";
} catch ( \Throwable $e ) {
	echo '  FAIL  ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n";
	$fail++;
}

// The statuses the plugin WRITES must exist. An unregistered post_status makes
// WP_Query drop its status clause entirely and scan every product.
if ( function_exists( 'wcpk_gallery_register_fallback_statuses' ) ) {
	wcpk_gallery_register_fallback_statuses();

	foreach ( array( 'gallery_moved', 'unavailable' ) as $slug ) {
		if ( get_post_status_object( $slug ) ) {
			echo "  ok  product status '{$slug}' is registered\n";
		} else {
			echo "  FAIL  product status '{$slug}' is NOT registered\n";
			$fail++;
		}
	}

	$kept = wcpk_gallery_registered_statuses_only( array( 'publish', 'made-up-status' ) );

	if ( array( 'publish' ) === $kept ) {
		echo "  ok  unregistered statuses are stripped from query lists\n";
	} else {
		echo '  FAIL  status filter returned ' . var_export( $kept, true ) . "\n";
		$fail++;
	}

	if ( array() === wcpk_gallery_registered_statuses_only( array( 'made-up-status' ) ) ) {
		echo "  ok  an all-unregistered list resolves to empty (callers must bail)\n";
	} else {
		echo "  FAIL  an all-unregistered list did not resolve to empty\n";
		$fail++;
	}
}

// A parent plugin installed from a GitHub zip lands in a suffixed directory.
// The boot gate must still find it, or the whole plugin goes inert.
if ( function_exists( 'wcpk_gallery_parent_active' ) ) {
	$suffixed = array( 'wc-unified-km-main/wc-unified-km.php' );

	if ( wcpk_gallery_parent_active( 'wc-unified-km.php', $suffixed ) ) {
		echo "  ok  parent detected in a suffixed install directory\n";
	} else {
		echo "  FAIL  parent NOT detected in a suffixed install directory\n";
		$fail++;
	}

	if ( ! wcpk_gallery_parent_active( 'wc-unified-km.php', array( 'something-else/other.php' ) ) ) {
		echo "  ok  parent not falsely detected\n";
	} else {
		echo "  FAIL  parent falsely detected\n";
		$fail++;
	}
}

// settings.php is require'd, not require_once'd. A second include must be a
// no-op, not a fatal redeclare that also re-registers every admin_post handler.
try {
	require dirname( __DIR__ ) . '/settings.php';
	echo "  ok  settings.php survives a second include\n";
} catch ( \Throwable $e ) {
	echo '  FAIL  second include of settings.php: ' . $e->getMessage() . "\n";
	$fail++;
}

$expect = array(
	'wcpk_gallery_classify_type',
	'wcpk_gallery_allowed_types_clause',
	'wcpk_gallery_build_query_args',
	'wcpk_gallery_render_cards',
	'wcpk_gallery_option_registry',
	'wcpk_gallery_layout_preset',
	'wcpk_gallery_seo_mode',
	'wcpk_gallery_wait_days',
	'wcpk_gallery_update_repo_url',
	'wcpk_gallery_release_channel',
	'wcpk_gallery_promotion_tools_enabled',
	'version_data_updates',
	'wcpk_gallery_parent_active',
	'wcpk_gallery_register_fallback_statuses',
	'wcpk_gallery_registered_statuses_only',
);

foreach ( $expect as $fn ) {
	if ( function_exists( $fn ) ) {
		echo "  ok  {$fn}()\n";
	} else {
		echo "  FAIL  {$fn}() is not defined\n";
		$fail++;
	}
}

foreach ( array( 'PetkeyGallerySettings', 'WCPK_Gallery_Status', 'WCPK_Gallery_Form_Resolver', 'WCPK_Gallery_UKM_Ingest' ) as $class ) {
	if ( class_exists( $class ) ) {
		echo "  ok  class {$class}\n";
	} else {
		echo "  FAIL  class {$class} missing\n";
		$fail++;
	}
}

// The boot gate must have elected UKM from active_plugins.
if ( defined( 'WCPK_GALLERY_PARENT_MODE' ) && 'ukm' === WCPK_GALLERY_PARENT_MODE ) {
	echo "  ok  parent mode elected: ukm\n";
} else {
	echo '  FAIL  parent mode is ' . ( defined( 'WCPK_GALLERY_PARENT_MODE' ) ? WCPK_GALLERY_PARENT_MODE : 'undefined' ) . "\n";
	$fail++;
}

// The ingest must be OFF until someone turns it on. An upgrade creating
// thousands of posts unattended is the thing this gate exists to prevent.
if ( class_exists( 'WCPK_Gallery_UKM_Ingest' ) && ! WCPK_Gallery_UKM_Ingest::enabled() ) {
	echo "  ok  UKM ingest is off by default\n";
} else {
	echo "  FAIL  UKM ingest is armed without anyone enabling it\n";
	$fail++;
}

// ...and must stay off while wc-ukm-gallery owns the mirroring.
$GLOBALS['__opts'][ WCPK_Gallery_UKM_Ingest::MODE_OPTION ] = 'on';
if ( WCPK_Gallery_UKM_Ingest::enabled() ) {
	echo "  ok  UKM ingest arms when switched on\n";
} else {
	echo "  FAIL  UKM ingest did not arm when switched on\n";
	$fail++;
}
$GLOBALS['__opts'][ WCPK_Gallery_UKM_Ingest::MODE_OPTION ] = 'off';

// The promotion tools must be off on UKM — they are the path that would flip a
// product out of sold_puppy.
if ( ! wcpk_gallery_promotion_tools_enabled() ) {
	echo "  ok  promotion tools gated off on UKM\n";
} else {
	echo "  FAIL  promotion tools are enabled on a UKM site\n";
	$fail++;
}

// Render every settings tab. This is where a missing helper, a bad array key
// or a typo'd method surfaces — none of which php -l can see.
foreach ( array( 'status', 'gallery', 'inquiries', 'advanced' ) as $tab ) {
	$_GET['tab'] = $tab;

	try {
		ob_start();
		$GLOBALS['petkey_gallery_settings']->settings_page();
		$html = ob_get_clean();

		if ( strpos( $html, 'nav-tab' ) === false ) {
			echo "  FAIL  tab {$tab} rendered no tab bar\n";
			$fail++;
		} else {
			printf( "  ok  tab %-10s rendered (%d bytes)\n", $tab, strlen( $html ) );
		}
	} catch ( \Throwable $e ) {
		while ( ob_get_level() > 0 ) { ob_end_clean(); }
		echo "  FAIL  tab {$tab}: " . $e->getMessage() . ' in ' . basename( $e->getFile() ) . ':' . $e->getLine() . "\n";
		$fail++;
	}
}

unset( $_GET['tab'] );

// Every template must at least parse.
foreach ( glob( $root . '/templates/*.php' ) as $tpl ) {
	$out = array();
	exec( 'php -l ' . escapeshellarg( $tpl ) . ' 2>&1', $out, $code );
	if ( 0 === $code ) {
		echo '  ok  template ' . basename( $tpl ) . "\n";
	} else {
		echo '  FAIL  template ' . basename( $tpl ) . ': ' . implode( ' ', $out ) . "\n";
		$fail++;
	}
}

printf( "\n%s\n", $fail ? "{$fail} failure(s)" : 'all good' );
exit( $fail ? 1 : 0 );
