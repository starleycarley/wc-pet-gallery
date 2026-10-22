<?php
/**
 * Plugin Name: WooCommerce Pet Gallery
 * Plugin URI: https://cosmickmedia.com/
 * Update URI: https://github.com/CosmickMedia/wc-pet-gallery
 * Description: Adopted-pet image gallery for WooCommerce, integrating with Petkey Sync, Unified KM, and Pinogy Sync. Ships with a modern, theme-agnostic UI and adaptive lead capture.
 * Version: 1.7.5
 * Author: Cosmick Media
 * Author URI: https://cosmickmedia.com/
 * Requires at least: 5.7
 * Requires PHP: 7.4
 * License: Proprietary
 * Text Domain: pet-gallery
 * Domain Path: /languages
 *
 * Maintained by Cosmick Media. See readme.txt for the full license notice.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ============================================================
 * Plugin updater — GitHub via plugin-update-checker (YahnisElsts v5).
 *
 * Repo: CosmickMedia/wc-pet-gallery (private).
 *
 * Auth: a fine-grained or classic GitHub PAT defined as the
 * COSMICK_AUTH_KEY constant in wp-config.php. Constant is read at
 * runtime; if missing, the updater is skipped and a dismissible
 * admin notice tells the operator the key is not set.
 *
 * Detection follows the library's default order on a main/master
 * branch: latest release -> highest tag -> branch HEAD. Keeping all
 * three means there is no silent "no update available" state: a draft
 * or failed GitHub release falls through to the tag, and a push with
 * no tag at all falls through to the branch header.
 *
 * Release channel: `wcpk_gallery_release_channel` (stable|beta).
 * "beta" opts a site into GitHub pre-releases. Channel gating happens
 * on the resolved version rather than per-strategy, because the branch
 * strategy has no version until the Version: header is read off the
 * ref — see wcpk_gallery_filter_update_info().
 *
 * Overrides (constant first, filter second — see NOTE):
 *   WCPK_GALLERY_UPDATE_REPO        / wcpk_gallery_update_repo
 *   WCPK_GALLERY_RELEASE_CHANNEL    / option wcpk_gallery_release_channel
 *   WCPK_GALLERY_UPDATE_BRANCH_ONLY / wcpk_gallery_update_from_branch_only
 *                                     wcpk_gallery_update_auth_token
 *                                     wcpk_gallery_update_branch
 *
 * NOTE: the updater is built at file scope, before `plugins_loaded`.
 * A regular plugin or theme cannot hook these filters in time — only
 * wp-config.php or an mu-plugin can. That is why each override also
 * has a constant.
 * ============================================================ */

require __DIR__ . '/update/plugin-update-checker.php';

if ( ! function_exists( 'wcpk_gallery_update_repo_url' ) ) {
	function wcpk_gallery_update_repo_url() {
		if ( defined( 'WCPK_GALLERY_UPDATE_REPO' ) && ! empty( WCPK_GALLERY_UPDATE_REPO ) ) {
			return (string) WCPK_GALLERY_UPDATE_REPO;
		}

		return (string) apply_filters(
			'wcpk_gallery_update_repo',
			'https://github.com/CosmickMedia/wc-pet-gallery'
		);
	}
}

if ( ! function_exists( 'wcpk_gallery_release_channel' ) ) {
	/**
	 * `stable` (default) or `beta`. A beta site also receives GitHub
	 * pre-releases, which is how a fix reaches one site ahead of the fleet.
	 */
	function wcpk_gallery_release_channel() {
		if ( defined( 'WCPK_GALLERY_RELEASE_CHANNEL' ) ) {
			$channel = (string) WCPK_GALLERY_RELEASE_CHANNEL;
		} else {
			$channel = (string) get_option( 'wcpk_gallery_release_channel', 'stable' );
		}

		return ( 'beta' === strtolower( trim( $channel ) ) ) ? 'beta' : 'stable';
	}
}

if ( ! function_exists( 'wcpk_gallery_is_prerelease_version' ) ) {
	/**
	 * A version carrying a SemVer pre-release suffix (1.7.0-beta.1) or a
	 * recognisable channel word. GitHub's own `prerelease` flag only guards the
	 * release strategy; the tag and branch strategies know nothing about it, so
	 * the version string is the one signal available to all three.
	 */
	function wcpk_gallery_is_prerelease_version( $version ) {
		$version = ltrim( (string) $version, 'vV' );

		return ( strpos( $version, '-' ) !== false )
			|| (bool) preg_match( '/(alpha|beta|rc|dev)/i', $version );
	}
}

if ( ! function_exists( 'wcpk_gallery_filter_update_info' ) ) {
	/**
	 * Runs on every successful metadata fetch, whichever strategy produced it.
	 *
	 * Two jobs: record what the updater actually resolved (so the settings screen
	 * can show it instead of the operator discovering a broken update by
	 * accident), and enforce the release channel on the resolved version.
	 *
	 * Returning null means "no update information", which `requestUpdate()`
	 * turns into "no update available" — see Plugin/UpdateChecker::requestUpdate().
	 *
	 * @param object|null $info
	 * @return object|null
	 */
	function wcpk_gallery_filter_update_info( $info ) {

		if ( ! is_object( $info ) || empty( $info->version ) ) {
			return $info;
		}

		update_option(
			'wcpk_gallery_updater_last_check',
			array(
				'version'    => (string) $info->version,
				'prerelease' => wcpk_gallery_is_prerelease_version( $info->version ),
				'channel'    => wcpk_gallery_release_channel(),
				'time'       => time(),
			),
			false
		);

		// A successful fetch clears any stored error.
		delete_option( 'wcpk_gallery_updater_last_error' );

		return $info;
	}
}

if ( ! function_exists( 'wcpk_gallery_filter_update_result' ) ) {
	/**
	 * Enforce the release channel on the resolved version.
	 *
	 * Deliberately on the UPDATE result rather than the INFO result. Returning
	 * null from the info filter would suppress the update AND the "View
	 * details" popup and the changelog, because plugins_api() is served from
	 * the same metadata — a stable site would lose the ability to read anything
	 * about the plugin simply because the newest tag happened to be a beta.
	 *
	 * The gate is on the version rather than per-strategy because the branch
	 * strategy has no version at all until the Version: header is read off the
	 * ref — so filtering strategies would let a tagged beta through the branch
	 * fallback.
	 *
	 * @param object|null $update
	 * @return object|null
	 */
	function wcpk_gallery_filter_update_result( $update ) {

		if ( ! is_object( $update ) || empty( $update->version ) ) {
			return $update;
		}

		if ( wcpk_gallery_is_prerelease_version( $update->version )
			&& 'beta' !== wcpk_gallery_release_channel() ) {
			return null;
		}

		return $update;
	}
}

if ( ! function_exists( 'wcpk_gallery_record_updater_error' ) ) {
	/**
	 * @param \WP_Error $error
	 */
	function wcpk_gallery_record_updater_error( $error, $response = null, $url = null, $slug = null ) {
		if ( 'wc-pet-gallery' !== $slug || ! is_wp_error( $error ) ) {
			return;
		}

		update_option(
			'wcpk_gallery_updater_last_error',
			array(
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
				'time'    => time(),
			),
			false
		);
	}
}

if ( ! function_exists( 'wcpk_gallery_init_updater' ) ) {
	function wcpk_gallery_init_updater() {

		$token = '';

		if ( defined( 'COSMICK_AUTH_KEY' ) && ! empty( COSMICK_AUTH_KEY ) ) {
			$token = (string) COSMICK_AUTH_KEY;
		}

		/**
		 * Filter the GitHub auth token used by the updater. Return an empty
		 * string to force the "missing key" branch.
		 */
		$token = (string) apply_filters( 'wcpk_gallery_update_auth_token', $token );

		if ( empty( $token ) ) {
			// No token — register an admin notice and bail. We never throw,
			// so the plugin still functions; just no update checks.
			add_action( 'admin_notices', 'wcpk_gallery_missing_update_key_notice' );
			return null;
		}

		if ( ! class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
			return null;
		}

		try {
			$updater = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
				wcpk_gallery_update_repo_url(),
				__FILE__,
				'wc-pet-gallery'
			);

			$branch = (string) apply_filters( 'wcpk_gallery_update_branch', 'main' );

			if ( method_exists( $updater, 'setBranch' ) ) {
				$updater->setBranch( $branch );
			}

			// On the beta channel, let the release strategy consider GitHub
			// pre-releases too. The constant is resolved off the live class so a
			// library upgrade can't leave a stale namespace behind.
			if ( 'beta' === wcpk_gallery_release_channel() && method_exists( $updater, 'getVcsApi' ) ) {
				$api = $updater->getVcsApi();

				if ( is_object( $api ) && method_exists( $api, 'setReleaseFilter' ) ) {
					$constant      = get_class( $api ) . '::RELEASE_FILTER_ALL';
					$include_all   = defined( $constant ) ? constant( $constant ) : 3;

					$api->setReleaseFilter( '__return_true', $include_all );
				}
			}

			// Escape hatch: track the branch HEAD and ignore releases and tags
			// entirely, which is how 1.6.0 and earlier behaved.
			$branch_only = defined( 'WCPK_GALLERY_UPDATE_BRANCH_ONLY' )
				? (bool) WCPK_GALLERY_UPDATE_BRANCH_ONLY
				: (bool) apply_filters( 'wcpk_gallery_update_from_branch_only', false );

			if ( $branch_only ) {
				add_filter(
					'puc_vcs_update_detection_strategies-wc-pet-gallery',
					function ( $strategies ) {
						if ( isset( $strategies['branch'] ) ) {
							return array( 'branch' => $strategies['branch'] );
						}
						return $strategies;
					}
				);
			}

			add_filter( 'puc_request_info_result-wc-pet-gallery', 'wcpk_gallery_filter_update_info', 10, 1 );
			add_filter( 'puc_request_update_result-wc-pet-gallery', 'wcpk_gallery_filter_update_result', 10, 1 );
			add_action( 'puc_api_error', 'wcpk_gallery_record_updater_error', 10, 4 );

			$updater->setAuthentication( $token );

			return $updater;
		} catch ( \Throwable $e ) {
			// Defensive: any failure inside the library must not take down the site.
			add_action( 'admin_notices', function () use ( $e ) {
				if ( ! current_user_can( 'manage_options' ) ) { return; }
				printf(
					'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
					esc_html__( 'WooCommerce Pet Gallery updater error:', 'petkey-gallery' ),
					esc_html( $e->getMessage() )
				);
			} );
			return null;
		}
	}
}

if ( ! function_exists( 'wcpk_gallery_missing_update_key_notice' ) ) {
	function wcpk_gallery_missing_update_key_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// One per page load. Keep it dismissible per-user.
		$user_id    = get_current_user_id();
		$meta_key   = 'wcpk_gallery_dismissed_update_key_notice';
		$dismissed  = get_user_meta( $user_id, $meta_key, true );
		if ( $dismissed === '1' ) {
			return;
		}
		$nonce = wp_create_nonce( 'wcpk_gallery_dismiss_update_key' );
		$dismiss_url = add_query_arg( array(
			'wcpk_gallery_dismiss_update_key' => 1,
			'_wpnonce' => $nonce,
		), admin_url() );
		?>
		<div class="notice notice-warning is-dismissible" id="wcpk-gallery-missing-key">
			<p>
				<strong><?php esc_html_e( 'WooCommerce Pet Gallery updates are disabled.', 'petkey-gallery' ); ?></strong>
				<?php
				printf(
					/* translators: 1: constant name, 2: wp-config.php */
					esc_html__( 'No GitHub auth key found. Add %1$s to %2$s to enable automatic updates.', 'petkey-gallery' ),
					'<code>COSMICK_AUTH_KEY</code>',
					'<code>wp-config.php</code>'
				);
				?>
				<a class="button button-small" style="margin-left:.5rem;" href="<?php echo esc_url( $dismiss_url ); ?>"><?php esc_html_e( 'Dismiss', 'petkey-gallery' ); ?></a>
			</p>
		</div>
		<?php
	}
}

if ( ! function_exists( 'wcpk_gallery_handle_dismiss_update_key_notice' ) ) {
	function wcpk_gallery_handle_dismiss_update_key_notice() {
		if ( ! is_admin() || empty( $_GET['wcpk_gallery_dismiss_update_key'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'wcpk_gallery_dismiss_update_key' );
		update_user_meta( get_current_user_id(), 'wcpk_gallery_dismissed_update_key_notice', '1' );
		wp_safe_redirect( remove_query_arg( array( 'wcpk_gallery_dismiss_update_key', '_wpnonce' ) ) );
		exit;
	}
}
add_action( 'admin_init', 'wcpk_gallery_handle_dismiss_update_key_notice' );

$wcpk_update = wcpk_gallery_init_updater();

if ( ! defined( 'PETGALLERYVERSION' ) ) {
	define( 'PETGALLERYVERSION', '1.7.5' );
}
if ( ! defined( 'PETGALLERY_PLUGIN_FILE' ) ) {
	define( 'PETGALLERY_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'PETGALLERY_PLUGIN_DIR' ) ) {
	define( 'PETGALLERY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'PETGALLERY_PLUGIN_URL' ) ) {
	define( 'PETGALLERY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

// Boot gate. We accept any of the three parent sync plugins: wc-petkey-sync,
// wc-unified-km, or wc-pinogy-sync. Exactly one is expected per site, and the
// chosen mode is frozen in WCPK_GALLERY_PARENT_MODE so downstream code can
// branch without re-reading active_plugins.
//
// The precedence below (petkey > ukm > pinogy) only ever decides a petkey/ukm
// or ukm/pinogy overlap. petkey and pinogy cannot both be active: they declare
// 19 identical unguarded top-level functions — get_pet_data(),
// wc_petkey_create_product(), wc_petkey_sync_function() and more — so WordPress
// fatals with "Cannot redeclare" before this gate is ever reached.
if ( ! function_exists( 'wcpk_gallery_parent_active' ) ) {
	/**
	 * Is a parent sync plugin active, judged by its main file's basename?
	 *
	 * Basename rather than full path, because a plugin installed from a GitHub
	 * zip often lands in a suffixed directory (`wc-unified-km-main/`), and an
	 * exact-path test then silently elects 'none' — which makes this entire
	 * plugin inert, with no post type, no shortcodes and no admin page, and
	 * nothing anywhere saying why.
	 *
	 * Every caller must use this. Having the boot gate match on basename while
	 * another check matched on path produced a worse failure than either alone:
	 * the gate would elect 'petkey' and register the cron hook, while the
	 * scheduler decided petkey was absent and cleared the schedule on every
	 * request, so the hourly sweep could never stay scheduled.
	 *
	 * @param string     $main_file e.g. 'wc-unified-km.php'
	 * @param array|null $active    Defaults to the current active_plugins list.
	 * @return bool
	 */
	function wcpk_gallery_parent_active( $main_file, $active = null ) {

		if ( null === $active ) {
			$active = (array) apply_filters( 'active_plugins', get_option( 'active_plugins', array() ) );
		}

		foreach ( (array) $active as $entry ) {
			if ( basename( (string) $entry ) === $main_file ) {
				return true;
			}
		}

		return false;
	}
}

$wcpk_active_plugins = (array) apply_filters( 'active_plugins', get_option( 'active_plugins', array() ) );

if ( ! defined( 'WCPK_GALLERY_PARENT_MODE' ) ) {
	if ( wcpk_gallery_parent_active( 'wc-petkey-sync.php', $wcpk_active_plugins ) ) {
		define( 'WCPK_GALLERY_PARENT_MODE', 'petkey' );
	} elseif ( wcpk_gallery_parent_active( 'wc-unified-km.php', $wcpk_active_plugins ) ) {
		define( 'WCPK_GALLERY_PARENT_MODE', 'ukm' );
	} elseif ( wcpk_gallery_parent_active( 'wc-pinogy-sync.php', $wcpk_active_plugins ) ) {
		define( 'WCPK_GALLERY_PARENT_MODE', 'pinogy' );
	} else {
		define( 'WCPK_GALLERY_PARENT_MODE', 'none' );
	}
}

/*
 * The predecessor, wc-petkey-gallery, carries the IDENTICAL `Plugin Name:
 * WooCommerce Pet Gallery` header, reads the same `pet_Datadetail` meta, owns
 * the same `pet-gallery` post type and can flip the same products to
 * `gallery_moved`. A site that installs this plugin alongside the old one
 * instead of replacing it therefore runs two indistinguishable copies of the
 * same promotion logic — and the plugins list shows two rows with the same
 * name, so the situation is genuinely hard to spot.
 *
 * Stand down rather than compete, and say why.
 */
if ( wcpk_gallery_parent_active( 'wc-petkey-gallery.php', $wcpk_active_plugins ) ) {

	add_action(
		'admin_notices',
		function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'Two copies of WooCommerce Pet Gallery are active.', 'petkey-gallery' ),
				esc_html__( 'The older “wc-petkey-gallery” plugin is still installed and does the same job. This newer copy has stood down to avoid creating duplicate gallery entries — deactivate the old one, then reload this page.', 'petkey-gallery' )
			);
		}
	);

	return;
}

if ( 'none' === WCPK_GALLERY_PARENT_MODE ) {
	/*
	 * The plugin sleeps entirely in this mode — no post type, no shortcodes, no
	 * settings page. Say so, rather than leaving an admin to work out why a
	 * plugin they just activated has no interface at all.
	 */
	add_action(
		'admin_notices',
		function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			printf(
				'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'WooCommerce Pet Gallery is inactive.', 'petkey-gallery' ),
				esc_html__( 'It needs one of Petkey Sync, Unified KM or Pinogy Sync to be active, and none was detected. Nothing on the site has been changed.', 'petkey-gallery' )
			);
		}
	);
}

if ( WCPK_GALLERY_PARENT_MODE !== 'none' ) {

	require PETGALLERY_PLUGIN_DIR . 'includes/template-loader.php';
	// schema.php before query.php: the query builder asks it for the pet-type
	// clause. Both before functions.php, which calls into them.
	require PETGALLERY_PLUGIN_DIR . 'includes/schema.php';
	// options.php is the translation-free option registry. It must load
	// unconditionally — wcpk_gallery_get_options() has front-end call sites, so
	// the defaults and sanitizers cannot live in an admin-only file.
	require PETGALLERY_PLUGIN_DIR . 'includes/options.php';
	require PETGALLERY_PLUGIN_DIR . 'includes/query.php';
	require PETGALLERY_PLUGIN_DIR . 'includes/seo.php';
	require PETGALLERY_PLUGIN_DIR . 'includes/product-statuses.php';
	require PETGALLERY_PLUGIN_DIR . 'includes/class-form-resolver.php';
	require PETGALLERY_PLUGIN_DIR . 'post-type.php';
	require PETGALLERY_PLUGIN_DIR . 'includes/admin/class-status.php';
	require PETGALLERY_PLUGIN_DIR . 'settings.php';
	require PETGALLERY_PLUGIN_DIR . 'functions.php';
	require PETGALLERY_PLUGIN_DIR . 'shortcodes.php';
	require PETGALLERY_PLUGIN_DIR . 'widget.php';

	// UKM mode gets its own ingest, in its own file, for the same reason pinogy
	// does: create_gallery_post_from_product() is Petkey-shaped and ends by
	// flipping the source product to `gallery_moved`, which must never happen
	// to a UKM pet. Nothing in ukm-ingest.php calls it.
	//
	// Deliberately NOT gated on the detected mode. Action Scheduler resolves a
	// callback with has_action() inside whatever request happens to drain the
	// queue -- wp-cron.php, or its admin-ajax loopback -- and refuses the action
	// outright when none is registered:
	//
	//   "Scheduled action for wcpk_gallery_ukm_backfill_batch will not be
	//    executed as no callbacks are registered."
	//
	// Observed on a live UKM site: every queued batch failed exactly that way,
	// via both WP Cron and Async Request, so a 3,800-pet backfill could not drain
	// itself and the recurring scan was never scheduled at all. Gating
	// REGISTRATION on a value re-resolved per request is the bug. The gate
	// belongs on BEHAVIOUR and is already there: enabled() is re-checked at the
	// top of run_backfill_batch(), run_scan(), on_transition(), mirror_one_async()
	// and every admin entry point, and returns false unless the mode really is
	// ukm. A registered callback that declines to act costs nothing; a missing
	// one loses the job with no retry.
	$ukm_ingest = PETGALLERY_PLUGIN_DIR . 'includes/ukm-ingest.php';
	if ( file_exists( $ukm_ingest ) ) {
		require $ukm_ingest;
	}

	// Pinogy mode wires the WC order lifecycle directly. file_exists for
	// defense-in-depth (matches the pattern used for template overrides).
	if ( WCPK_GALLERY_PARENT_MODE === 'pinogy' ) {
		$pinogy_bridge = PETGALLERY_PLUGIN_DIR . 'includes/pinogy-bridge.php';
		if ( file_exists( $pinogy_bridge ) ) {
			require $pinogy_bridge;
		}
	}

	register_activation_hook( __FILE__, 'wc_petkey_gallery_activation' );
	register_deactivation_hook( __FILE__, 'wc_petkey_gallery_deactivation' );

	// The petkey-specific cron handler only registers in petkey mode.
	// UKM has its own sync mechanism; pinogy uses order hooks via the bridge.
	if ( WCPK_GALLERY_PARENT_MODE === 'petkey' ) {
		add_action( 'wc_petkey_gallery_data', 'wc_petkey_gallery_cron' );
	}

	function wc_petkey_gallery_activation() {
		// Re-detect at activation since WCPK_GALLERY_PARENT_MODE may not be
		// set yet on the very first activation (the constant is frozen on the
		// next request after activation). Only the petkey path needs a cron
		// scheduled at activation time.
		if ( wcpk_gallery_parent_active( 'wc-petkey-sync.php' ) ) {
			if ( ! wp_next_scheduled( 'wc_petkey_gallery_data' ) ) {
				wp_schedule_event( time(), 'hourly', 'wc_petkey_gallery_data' );
			}
		}
		if ( function_exists( 'wc_petkey_gallery_init' ) ) {
			wc_petkey_gallery_init();
		}
		flush_rewrite_rules();
	}

	function wc_petkey_gallery_deactivation() {

		foreach ( array(
			'wc_petkey_gallery_data',
			'wcpk_gallery_slug_sweep',
			'wcpk_gallery_typecode_backfill',
			'wcpk_gallery_ukm_scan',
			'wcpk_gallery_ukm_backfill_batch',
			'wcpk_gallery_ukm_mirror_pet',
			'wcpk_gallery_ukm_demirror_pet',
		) as $hook ) {
			wp_clear_scheduled_hook( $hook );

			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $hook );
			}
		}

		delete_transient( 'wcpk_gallery_ukm_sched' );
		delete_option( 'wcpk_gallery_migrating' );

		flush_rewrite_rules();
	}

	add_action( 'init', 'version_data_updates', 5 );

	/**
	 * Run data migrations once per version bump.
	 *
	 * This routine runs on `init` on EVERY request until the version option is
	 * bumped, and the bump used to be the last statement in the function — so a
	 * fatal or a timeout anywhere inside it meant the whole thing retried on
	 * every page load forever, never reaching the bump. Worse, because the
	 * updater has been broken since 1.5.4, long-stale sites will meet the 1.5.4
	 * block (a 5,000-row slug sweep with a query and a cache purge per row) for
	 * the first time on a front-end request.
	 *
	 * So: a fast path out, a single-flight lock, per-step completion flags
	 * persisted as each step finishes, and anything expensive handed to cron
	 * instead of run inline.
	 */
	function version_data_updates() {

		$installed = get_option( 'wc_petkey_gallery_version', '1.0.0' );

		// Fast path — the common case, every request after the first.
		if ( version_compare( $installed, PETGALLERYVERSION, '>=' ) ) {
			return;
		}

		/*
		 * Single flight. add_option() returns false when the row already
		 * exists, which makes it an atomic claim in a way update_option() is
		 * not. Non-autoloaded so it costs nothing on the happy path.
		 */
		$lock = get_option( 'wcpk_gallery_migrating', 0 );

		if ( $lock && ( time() - (int) $lock ) < 300 ) {
			return;
		}

		delete_option( 'wcpk_gallery_migrating' );

		if ( ! add_option( 'wcpk_gallery_migrating', time(), '', 'no' ) ) {
			return;
		}

		$done = get_option( 'wcpk_gallery_migration_done', array() );
		$done = is_array( $done ) ? $done : array();

		/**
		 * One migration step. Records completion immediately, so a crash in a
		 * later step never re-runs an earlier one.
		 *
		 * @param string   $id
		 * @param callable $callback
		 */
		$step = function ( $id, $callback ) use ( &$done ) {
			if ( ! empty( $done[ $id ] ) ) {
				return;
			}

			call_user_func( $callback );

			$done[ $id ] = time();
			update_option( 'wcpk_gallery_migration_done', $done, false );
		};

		if ( version_compare( $installed, '1.1.8', '<' ) ) {

			$step( 'card_cta_118', function () {
				$saved = get_option( 'petkey_gallery_card_cta_label', '' );

				if ( 'Inquire about a pup like this' === $saved || '' === $saved ) {
					update_option( 'petkey_gallery_card_cta_label', 'Get one like this' );
				}
			} );

			$step( 'sticker_118', function () {
				$saved = get_option( 'petkey_gallery_sticker_label', '' );

				if ( 'Forever Adopted' === $saved || '' === $saved ) {
					update_option( 'petkey_gallery_sticker_label', 'Adopted' );
				}
			} );
		}

		if ( version_compare( $installed, '1.5.4', '<' ) ) {

			$step( 'cleanup_154', function () {
				delete_option( 'petkey_gallery_share' );

				if ( get_option( 'petgallery_check_updated_on' ) !== false ) {
					$current = get_option( 'petgallery_check_updated_on' );
					delete_option( 'petgallery_check_updated_on' );
					add_option( 'petgallery_check_updated_on', $current, '', 'no' );
				}
			} );

			$step( 'slug_sweep_154', function () {
				if ( get_option( 'wcpk_gallery_slug_sweep_done' ) ) {
					return;
				}

				// Queued, not run inline: up to 5,000 rows with a query and a
				// cache purge each, and this can land on a front-end request.
				if ( ! wp_next_scheduled( 'wcpk_gallery_slug_sweep' ) ) {
					wp_schedule_single_event( time() + 30, 'wcpk_gallery_slug_sweep' );
				}
			} );
		}

		if ( version_compare( $installed, '1.7.0', '<' ) ) {

			/*
			 * Repair `petkey_gallery_types`. Saving with no box ticked wrote the
			 * CODE => LABEL map, which matched no animal_type and blanked the
			 * grid. Snapshot the old value first so the change is auditable, then
			 * resolve through the classifier — which collapses an all-types
			 * result to "show everything".
			 */
			$step( 'types_repair_170', function () {
				$saved = get_option( 'petkey_gallery_types', array() );

				if ( ! is_array( $saved ) ) {
					$saved = array();
				}

				add_option( 'petkey_gallery_types_pre170', $saved, '', 'no' );
				update_option( 'petkey_gallery_types', wcpk_gallery_get_allowed_type_codes() );
			} );

			// A stored 0 is reachable from the old number input, and each of the
			// three readers fell back to a different default when it saw one.
			$step( 'per_page_repair_170', function () {
				if ( (int) get_option( 'petkey_gallery_numposts', 0 ) < 1 ) {
					delete_option( 'petkey_gallery_numposts' );
				}
			} );

			// Carry the old bypass checkbox into the new numeric hold.
			$step( 'wait_days_170', function () {
				if ( '' !== (string) get_option( 'petkey_gallery_wait_days', '' ) ) {
					return;
				}

				if ( 'yes' === get_option( 'petkey_gallery_skip_cooling_off', 'no' ) ) {
					$days = 0;
				} else {
					$days = ( defined( 'WCPK_GALLERY_PARENT_MODE' ) && 'petkey' === WCPK_GALLERY_PARENT_MODE ) ? 14 : 0;
				}

				update_option( 'petkey_gallery_wait_days', $days );

				/*
				 * Retire the flag now that its meaning is captured. Leaving it
				 * set would make the new "Hold for N days" field inert on
				 * exactly the sites that had deliberately bypassed the wait:
				 * the three call sites read `$days < wait_days() && ! $skip`,
				 * so a lingering 'yes' short-circuits any value entered here,
				 * with nowhere in the UI left to turn it off.
				 */
				delete_option( 'petkey_gallery_skip_cooling_off' );
			} );

			// Written explicitly so the semantics change is a recorded decision
			// rather than an invisible default flip.
			$step( 'cat_behavior_170', function () {
				add_option( 'petkey_gallery_cat_all_behavior', 'include' );
			} );

			// add_option, never update_option: this must not re-arm anything on
			// a site that has already turned it on.
			$step( 'ingest_seed_170', function () {
				add_option( 'wcpk_gallery_ukm_ingest_mode', 'off', '', 'no' );
				add_option( 'wcpk_gallery_ukm_backfill', array(), '', 'no' );
			} );

			// Stamp the canonical pet-type code on existing posts, in batches.
			$step( 'typecode_backfill_170', function () {
				if ( ! get_option( 'wcpk_gallery_typecode_backfill_done' ) && ! wp_next_scheduled( 'wcpk_gallery_typecode_backfill' ) ) {
					wp_schedule_single_event( time() + 30, 'wcpk_gallery_typecode_backfill' );
				}
			} );
		}

		// Never pass an autoload argument: the row is already autoloaded on
		// every live site, and changing that is not this routine's business.
		update_option( 'wc_petkey_gallery_version', PETGALLERYVERSION );
		delete_option( 'wcpk_gallery_migrating' );

		if ( function_exists( 'wcpk_gallery_clear_count_cache' ) ) {
			wcpk_gallery_clear_count_cache();
		}

		// Rewrite rules are flushed once, on the next admin request — never on
		// a front-end init, where it is an expensive write on every page view
		// until something clears it.
		update_option( 'wcpk_gallery_needs_rewrite_flush', 1, false );
	}

	/**
	 * Deferred rewrite flush, admin-side only.
	 */
	function wcpk_gallery_maybe_flush_rewrites() {
		if ( ! get_option( 'wcpk_gallery_needs_rewrite_flush' ) ) {
			return;
		}

		delete_option( 'wcpk_gallery_needs_rewrite_flush' );
		flush_rewrite_rules( false );
	}
	add_action( 'admin_init', 'wcpk_gallery_maybe_flush_rewrites', 20 );

	add_action( 'wcpk_gallery_slug_sweep', 'wc_petkey_gallery_run_slug_sweep' );

	/**
	 * Keep the petkey cron schedule in step with whether petkey is active.
	 */
	function wcpk_gallery_sync_cron_schedule() {

		if ( wcpk_gallery_parent_active( 'wc-petkey-sync.php' ) ) {
			if ( ! wp_next_scheduled( 'wc_petkey_gallery_data' ) ) {
				wp_schedule_event( time(), 'hourly', 'wc_petkey_gallery_data' );
			}
		} else {
			wp_clear_scheduled_hook( 'wc_petkey_gallery_data' );
		}
	}
	add_action( 'init', 'wcpk_gallery_sync_cron_schedule', 20 );

	function wc_petkey_gallery_cron() {
		global $petkey_gallery_functions;
		if ( $petkey_gallery_functions ) {
			$petkey_gallery_functions->check_petgallery_cron();
		}
	}

	/**
	 * One-time pass that fills in missing `post_name` slugs on existing
	 * pet-gallery posts. The original plugin did this inside the AJAX render
	 * loop on every request; we run it once at upgrade time instead.
	 */
	function wc_petkey_gallery_run_slug_sweep() {
		global $wpdb;

		$rows = $wpdb->get_results( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'pet-gallery' AND ( post_name IS NULL OR post_name = '' ) LIMIT 5000" );

		if ( empty( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$post_id     = (int) $row->ID;
			$post_title  = get_the_title( $post_id );
			$reference   = (string) get_post_meta( $post_id, 'reference_id', true );
			$gender      = (string) get_post_meta( $post_id, 'gender', true );
			$slug        = sanitize_title_with_dashes( trim( $reference . ' ' . $gender . ' ' . $post_title ) );
			$unique_slug = wp_unique_post_slug( $slug, $post_id, 'publish', 'pet-gallery', 0 );

			$wpdb->update(
				$wpdb->posts,
				array( 'post_name' => $unique_slug ),
				array( 'ID' => $post_id ),
				array( '%s' ),
				array( '%d' )
			);

			clean_post_cache( $post_id );
		}
	}
}
