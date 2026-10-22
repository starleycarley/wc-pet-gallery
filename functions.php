<?php
/**
 * Main runtime class for WooCommerce Pet Gallery.
 *
 * Method names intentionally preserved (including the historical typo
 * `petkey_galley_*`) so legacy themes that call them as part of the global
 * `$petkey_gallery_functions` API keep working.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PetkeyGalleryFunctions {

	public function __construct() {
		add_action( 'pre_get_posts', array( $this, 'petkey_galley_filter' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'petkey_galley_scripts' ) );
		add_action( 'wp_ajax_petkey_gallery_data', array( $this, 'process_petkey_gallery_data' ) );
		add_action( 'wp_ajax_nopriv_petkey_gallery_data', array( $this, 'process_petkey_gallery_data' ) );
		add_filter( 'wpseo_title', array( $this, 'filter_petgallery_wpseo_title' ) );
		add_filter( 'wpseo_metadesc', array( $this, 'filter_petgallery_wpseo_metadesc' ) );
		add_action( 'wc_petkey_syncpet_statuschange', array( $this, 'wc_petkey_syncpet_statuschange' ), 10, 3 );
		add_filter( 'template_include', array( $this, 'maybe_override_archive_template' ), 20 );

		add_action( 'save_post_pet-gallery', array( $this, 'clear_breeds_cache' ) );
		add_action( 'wc_petgallery_post_created', array( $this, 'clear_breeds_cache' ) );
		add_action( 'wc_petgallery_post_removed', array( $this, 'clear_breeds_cache' ) );
		add_action( 'deleted_post', array( $this, 'maybe_clear_breeds_cache_on_delete' ) );
	}

	/* ============================================================
	 * Asset enqueue / template routing
	 * ============================================================ */

	public function petkey_galley_scripts() {
		if ( is_admin() ) {
			return;
		}

		$needs_assets = false;

		if ( is_post_type_archive( 'pet-gallery' ) || is_singular( 'pet-gallery' ) ) {
			$needs_assets = true;
		}

		if ( ! $needs_assets ) {
			global $post;
			if ( $post instanceof WP_Post && $this->post_has_gallery_shortcode( $post ) ) {
				$needs_assets = true;
			}
		}

		if ( ! apply_filters( 'wcpk_gallery_should_enqueue', $needs_assets ) ) {
			return;
		}

		wcpk_gallery_enqueue_assets();
	}

	/**
	 * True when the post contains any of the gallery shortcodes (new pet-* tags
	 * or the legacy petkey-* aliases). Centralized so every gate stays in sync.
	 */
	protected function post_has_gallery_shortcode( $post ) {
		if ( ! ( $post instanceof WP_Post ) ) {
			return false;
		}
		$content = (string) $post->post_content;
		return has_shortcode( $content, 'pet-gallery' )
			|| has_shortcode( $content, 'pet-gallery-hero' )
			|| has_shortcode( $content, 'petkey-gallery' )
			|| has_shortcode( $content, 'petkey-gallery-hero' );
	}

	public function maybe_override_archive_template( $template ) {
		$opts = wcpk_gallery_get_options();
		$force_clean = isset( $opts['force_clean_layout'] ) && $opts['force_clean_layout'] === 'yes';

		// Force a clean layout on any page (or singular post) whose content
		// contains the gallery shortcode. Bypasses theme page templates
		// entirely so the shortcode renders without theme chrome.
		if ( $force_clean && is_singular() && ! is_singular( 'pet-gallery' ) ) {
			$post = get_post();
			if ( $this->post_has_gallery_shortcode( $post ) ) {
				$plugin_template = plugin_dir_path( __FILE__ ) . 'templates/page-shortcode.php';
				if ( file_exists( $plugin_template ) ) {
					return $plugin_template;
				}
			}
		}

		if ( is_post_type_archive( 'pet-gallery' ) ) {
			$plugin_template = plugin_dir_path( __FILE__ ) . 'templates/archive-pet-gallery.php';

			// When "Force clean layout" is on, the archive must also use the
			// plugin's template (which now ships the <main class="wcpk-page-wrap">
			// wrapper) so it visually matches a clean-layout shortcode page.
			// This overrides both archive_override=no AND a theme's own
			// archive-pet-gallery.php.
			if ( $force_clean && file_exists( $plugin_template ) ) {
				return $plugin_template;
			}

			$archive_override = isset( $opts['archive_override'] ) ? $opts['archive_override'] : 'yes';

			if ( $archive_override === 'no' ) {
				return $template;
			}

			// 'always' forces the plugin's archive even when the theme ships
			// one. Without it "Use the plugin's design" could not actually
			// change the archive on a theme that has its own template — the
			// setting claimed something it could not deliver.
			if ( $archive_override === 'always' && file_exists( $plugin_template ) ) {
				return $plugin_template;
			}

			$theme_template = locate_template( array( 'archive-pet-gallery.php' ), false, false );
			if ( $theme_template ) {
				return $template;
			}
			if ( file_exists( $plugin_template ) ) {
				return $plugin_template;
			}
			return $template;
		}

		if ( is_singular( 'pet-gallery' ) ) {
			$single_override = isset( $opts['single_override'] ) ? $opts['single_override'] : 'auto';
			if ( $single_override === 'no' ) {
				return $template;
			}
			$theme_template = locate_template( array( 'single-pet-gallery.php' ), false, false );
			if ( $single_override === 'auto' && $theme_template ) {
				return $template;
			}
			$plugin_template = plugin_dir_path( __FILE__ ) . 'templates/single-pet-gallery.php';
			if ( file_exists( $plugin_template ) ) {
				return $plugin_template;
			}
			return $template;
		}

		return $template;
	}

	/* ============================================================
	 * Cron — sync adopted pets from wc-petkey-sync into pet-gallery
	 * ============================================================ */

	public function check_petgallery_cron() {

		$current_time = time();

		/*
		 * Unregistered statuses are stripped first. WP_Query emits no status
		 * clause at all when none of the requested statuses are registered, so
		 * a list that resolves to nothing would silently scan every product in
		 * the catalogue rather than none.
		 */
		$pending_statuses = wcpk_gallery_registered_statuses_only(
			apply_filters( 'wcpk_gallery_pending_product_statuses', array( 'unavailable' ) )
		);

		if ( empty( $pending_statuses ) ) {
			return;
		}


		$args = array(
			'orderby'      => 'date',
			'order'        => 'DESC',
			'limit'        => 50,
			'status'       => $pending_statuses,
			'meta_key'     => 'pet_status_last_checked',
			'meta_value'   => gmdate( 'Ymd' ),
			'meta_compare' => '!=',
		);

		$unavailable_products = wc_get_products( $args );

		$touched = 0;

		if ( ! empty( $unavailable_products ) ) {

			update_option( 'petgallery_check', $current_time, false );

			foreach ( $unavailable_products as $product ) {

				$status_changed = $product->get_meta( 'pk_pet_status_changed' );

				if ( empty( $status_changed ) ) {
					update_post_meta( $product->get_id(), 'pk_pet_status_changed', $current_time );
					$status_changed = $current_time;
				}

				$days_past_since_moved = round( ( $current_time - $status_changed ) / DAY_IN_SECONDS );

				$skip_cooling_off = function_exists( 'wcpk_gallery_cooling_off_disabled' ) && wcpk_gallery_cooling_off_disabled();

				if ( $days_past_since_moved < wcpk_gallery_wait_days() && ! $skip_cooling_off ) {
					update_post_meta( $product->get_id(), 'pet_status_last_checked', gmdate( 'Ymd' ) );
					continue;
				}

				$status = $this->update_datadetails_meta( $product->get_id(), $product->get_sku() );

				$accepted = function_exists( 'wcpk_gallery_get_accepted_api_statuses' )
					? wcpk_gallery_get_accepted_api_statuses()
					: array( 'adopted', 'sold' );

				if ( ! empty( $status ) && in_array( strtolower( $status ), $accepted, true ) ) {

					$created = $this->create_gallery_post_from_product( $product );
					if ( $created ) {
						$touched++;
					}

				} else {
					update_post_meta( $product->get_id(), 'pet_status_last_checked', gmdate( 'Ymd' ) );
				}
			}
		}

		// Only touch the timestamp option when something actually happened,
		// so the autoloaded options cache doesn't churn on quiet cron ticks.
		if ( $touched > 0 ) {
			update_option( 'petgallery_check_updated_on', wp_date( 'F j, Y g:i a' ), false );
		}
	}

	/**
	 * Run the cron synchronously and return a summary of what happened. Called
	 * from the admin "Run cron now" button. Mirrors check_petgallery_cron()'s
	 * shape but returns counts instead of just logging.
	 *
	 * @return array { examined, moved, waiting, skipped, no_data }
	 */
	public function run_cron_now( $batch_limit = 200 ) {

		$summary = array(
			'examined' => 0,
			'moved'    => 0,
			'waiting'  => 0,
			'skipped'  => 0,
			'no_data'  => 0,
		);

		$current_time = time();
		$accepted     = wcpk_gallery_get_accepted_api_statuses();
		$skip_delay   = wcpk_gallery_cooling_off_disabled();

		/*
		 * Unregistered statuses are stripped first. WP_Query emits no status
		 * clause at all when none of the requested statuses are registered, so
		 * a list that resolves to nothing would silently scan every product in
		 * the catalogue rather than none.
		 */
		$pending_statuses = wcpk_gallery_registered_statuses_only(
			apply_filters( 'wcpk_gallery_pending_product_statuses', array( 'unavailable' ) )
		);

		if ( empty( $pending_statuses ) ) {
			return $summary;
		}


		$products = wc_get_products( array(
			'status'  => $pending_statuses,
			'limit'   => (int) $batch_limit,
			'orderby' => 'date',
			'order'   => 'DESC',
		) );

		if ( empty( $products ) ) {
			return $summary;
		}

		foreach ( $products as $product ) {

			$summary['examined']++;

			$status_changed = $product->get_meta( 'pk_pet_status_changed' );
			if ( empty( $status_changed ) ) {
				update_post_meta( $product->get_id(), 'pk_pet_status_changed', $current_time );
				$status_changed = $current_time;
			}

			$days_past = round( ( $current_time - $status_changed ) / DAY_IN_SECONDS );
			if ( $days_past < wcpk_gallery_wait_days() && ! $skip_delay ) {
				$summary['waiting']++;
				update_post_meta( $product->get_id(), 'pet_status_last_checked', gmdate( 'Ymd' ) );
				continue;
			}

			$api_status = $this->update_datadetails_meta( $product->get_id(), $product->get_sku() );

			if ( empty( $api_status ) ) {
				$summary['no_data']++;
				continue;
			}

			if ( in_array( strtolower( $api_status ), $accepted, true ) ) {
				$created = $this->create_gallery_post_from_product( $product );
				if ( $created ) {
					$summary['moved']++;
				} else {
					$summary['skipped']++;
				}
			} else {
				$summary['skipped']++;
				update_post_meta( $product->get_id(), 'pet_status_last_checked', gmdate( 'Ymd' ) );
			}
		}

		if ( $summary['moved'] > 0 && function_exists( 'wcpk_gallery_clear_count_cache' ) ) {
			wcpk_gallery_clear_count_cache();
			update_option( 'petgallery_check_updated_on', wp_date( 'F j, Y g:i a' ), false );
		}

		return $summary;
	}

	/**
	 * One-shot move for a single product by ID. Used by the per-row "Move now"
	 * action in the diagnostic table. Returns the new gallery post ID or false.
	 */
	public function add_to_gallery_for_product( $product_id ) {
		$product = wc_get_product( (int) $product_id );
		if ( ! $product ) {
			return false;
		}
		$this->update_datadetails_meta( $product->get_id(), $product->get_sku() );
		$gallery_id = $this->create_gallery_post_from_product( $product );
		if ( $gallery_id && function_exists( 'wcpk_gallery_clear_count_cache' ) ) {
			wcpk_gallery_clear_count_cache();
		}
		return $gallery_id;
	}

	/**
	 * Pull a list of products currently in 'unavailable' status with their
	 * cron-relevant meta, ready for display in the diagnostic table.
	 *
	 * @return array of arrays: id, title, api_status, days_since_changed, verdict
	 */
	public function get_pending_pets_diagnostic( $limit = 100 ) {

		$out = array();
		/*
		 * Unregistered statuses are stripped first. WP_Query emits no status
		 * clause at all when none of the requested statuses are registered, so
		 * a list that resolves to nothing would silently scan every product in
		 * the catalogue rather than none.
		 */
		$pending_statuses = wcpk_gallery_registered_statuses_only(
			apply_filters( 'wcpk_gallery_pending_product_statuses', array( 'unavailable' ) )
		);

		if ( empty( $pending_statuses ) ) {
			return array();
		}


		$products = wc_get_products( array(
			'status'  => $pending_statuses,
			'limit'   => (int) $limit,
			'orderby' => 'date',
			'order'   => 'DESC',
		) );

		if ( empty( $products ) ) {
			return $out;
		}

		$accepted   = wcpk_gallery_get_accepted_api_statuses();
		$skip_delay = wcpk_gallery_cooling_off_disabled();
		$now        = time();

		foreach ( $products as $product ) {
			$pid       = $product->get_id();
			$pet_data  = get_post_meta( $pid, 'pet_Datadetail', true );
			$api_status = ( is_object( $pet_data ) && isset( $pet_data->Status ) ) ? (string) $pet_data->Status : '';

			$changed  = (int) $product->get_meta( 'pk_pet_status_changed' );
			$days     = $changed > 0 ? (int) round( ( $now - $changed ) / DAY_IN_SECONDS ) : null;

			if ( empty( $pet_data ) ) {
				$verdict = __( 'No pet_Datadetail meta — no API record', 'petkey-gallery' );
			} elseif ( $days !== null && $days < wcpk_gallery_wait_days() && ! $skip_delay ) {
				$verdict = sprintf(
					/* translators: 1: days remaining, 2: total hold in days. */
					__( 'Waiting %1$dd more (%2$d-day hold)', 'petkey-gallery' ),
					wcpk_gallery_wait_days() - $days,
					wcpk_gallery_wait_days()
				);
			} elseif ( $api_status === '' ) {
				$verdict = __( 'No API status returned', 'petkey-gallery' );
			} elseif ( in_array( strtolower( $api_status ), $accepted, true ) ) {
				$verdict = __( 'Will move next run', 'petkey-gallery' );
			} else {
				$verdict = sprintf( __( 'Skipped: status "%s" not in accept list', 'petkey-gallery' ), $api_status );
			}

			$out[] = array(
				'id'         => $pid,
				'title'      => $product->get_name(),
				'api_status' => $api_status,
				'days'       => $days,
				'verdict'    => $verdict,
			);
		}

		return $out;
	}

	/**
	 * Insert a `pet-gallery` post from a WooCommerce product that the
	 * wc-petkey-sync plugin has marked as adopted. Replaces the original
	 * hand-built INSERT-INTO-postmeta SQL with safe WP APIs.
	 */
	protected function create_gallery_post_from_product( $product ) {

		$pet_data = get_post_meta( $product->get_id(), 'pet_Datadetail', true );
		if ( empty( $pet_data ) || ! is_object( $pet_data ) ) {
			return false;
		}

		$breed_name = isset( $pet_data->BreedName ) ? (string) $pet_data->BreedName : '';
		$pet_name   = $breed_name;
		if ( ! empty( $pet_data->PetName ) ) {
			$pet_name .= ' - ' . $pet_data->PetName;
		}

		$reference  = isset( $pet_data->ReferenceNumber ) ? (string) $pet_data->ReferenceNumber : '';
		$gender     = isset( $pet_data->Gender ) ? (string) $pet_data->Gender : '';
		$petkey_id  = isset( $pet_data->PetId ) ? (string) $pet_data->PetId : '';

		if ( empty( $petkey_id ) ) {
			return false;
		}

		// Already imported? Safe meta query, no SQL string interpolation.
		$existing = get_posts( array(
			'post_type'        => 'pet-gallery',
			'post_status'      => 'any',
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			'meta_key'         => 'petkey_gallery_ID',
			'meta_value'       => $petkey_id,
			'suppress_filters' => false,
		) );

		if ( ! empty( $existing ) ) {
			return false;
		}

		$thumbnail_id = $product->get_image_id();
		if ( empty( $thumbnail_id ) ) {
			return false;
		}

		$slug = sanitize_title_with_dashes( trim( $reference . ' ' . $gender . ' ' . $pet_name ) );

		$petgallery_post_id = wp_insert_post( array(
			'post_title'  => wp_strip_all_tags( $pet_name ),
			'post_status' => 'publish',
			'post_name'   => $slug,
			'post_type'   => 'pet-gallery',
		), true );

		if ( is_wp_error( $petgallery_post_id ) || empty( $petgallery_post_id ) ) {
			return false;
		}

		do_action( 'wc_petgallery_post_created', $petgallery_post_id, get_post( $petgallery_post_id ) );

		$meta_pairs = array(
			'petid'             => $petkey_id,
			'name'              => isset( $pet_data->PetName )       ? (string) $pet_data->PetName       : '',
			'age'               => isset( $pet_data->Age )           ? (string) $pet_data->Age           : '',
			'animal_type'       => isset( $pet_data->PetType )       ? (string) $pet_data->PetType       : '',
			'breed_name'        => $breed_name,
			'coloring'          => isset( $pet_data->Coloring )      ? (string) $pet_data->Coloring      : '',
			'gender'            => $gender,
			'birth_date'        => isset( $pet_data->BirthDate )     ? (string) $pet_data->BirthDate     : '',
			'status'            => isset( $pet_data->Status )        ? (string) $pet_data->Status        : '',
			'reference_id'      => $reference,
			'location'          => isset( $pet_data->OrgName )       ? (string) $pet_data->OrgName       : '',
			'petkey_gallery'    => 'Y',
			'petkey_gallery_ID' => $petkey_id,
			'actual_product'    => (int) $product->get_id(),
		);

		foreach ( $meta_pairs as $key => $value ) {
			update_post_meta( $petgallery_post_id, $key, $value );
		}

		set_post_thumbnail( $petgallery_post_id, (int) $thumbnail_id );

		$gallery_image_ids = $product->get_gallery_image_ids();
		if ( ! empty( $gallery_image_ids ) ) {
			update_post_meta( $petgallery_post_id, '_gallery_ids', implode( ',', array_map( 'intval', $gallery_image_ids ) ) );
		}

		// Move the source product to the custom `gallery_moved` status. Using
		// wp_update_post() keeps caches in sync (the legacy code went straight
		// to the posts table via $wpdb->update).
		wp_update_post( array(
			'ID'          => $product->get_id(),
			'post_status' => 'gallery_moved',
		) );

		update_post_meta( $product->get_id(), 'moved_to_gallery', gmdate( 'Ymd' ) );

		return $petgallery_post_id;
	}

	/**
	 * Pull the latest pet status from the Petkey API and store it on the
	 * product. Returns the new status string.
	 *
	 * In UKM / pinogy mode the petkey API isn't available, so we trust the
	 * parent plugin's own hourly sync and read the cached
	 * `pet_Datadetail->Status` from product meta. This keeps the cron
	 * safety-net path functional outside petkey mode.
	 */
	public function update_datadetails_meta( $pet_id, $petkey_pet_id ) {

		if ( empty( $pet_id ) || empty( $petkey_pet_id ) ) {
			return '';
		}

		$parent_mode = defined( 'WCPK_GALLERY_PARENT_MODE' ) ? WCPK_GALLERY_PARENT_MODE : 'none';

		if ( $parent_mode !== 'petkey' || ! function_exists( 'wc_petkey_get_api' ) ) {
			$existing_data = get_post_meta( $pet_id, 'pet_Datadetail', true );
			if ( is_object( $existing_data ) && isset( $existing_data->Status ) ) {
				return (string) $existing_data->Status;
			}
			return '';
		}

		$location_api  = function_exists( 'get_pet_data' ) ? get_pet_data( $pet_id, 'location_api' ) : '';
		$existing_data = get_post_meta( $pet_id, 'pet_Datadetail', true );

		if ( empty( $existing_data ) ) {
			return '';
		}

		$pet_detail = null;

		if ( empty( $location_api ) ) {
			$wc_petkey_apis = wc_petkey_get_api();
			if ( ! empty( $wc_petkey_apis ) ) {
				foreach ( $wc_petkey_apis as $location_slug => $api_details ) {
					$pk     = new Petkey_Functions( $api_details->api );
					$detail = $pk->get_pet( $petkey_pet_id );
					if ( ! empty( $detail ) ) {
						$en_slug                  = function_exists( 'wc_petkey_get_enabled_pets' )
							? array_search( strtoupper( $detail->PetType ), wc_petkey_get_enabled_pets(), true )
							: '';
						$pet_type_name            = function_exists( 'wc_petkey_get_pet_type_name' )
							? wc_petkey_get_pet_type_name( $en_slug )
							: '';
						$detail->pet_type_name    = $pet_type_name;
						$detail->location_slug    = $location_slug;
						$detail->location_api     = $api_details->api;
						$detail->list_status      = $detail->Status;
						$pet_detail               = $detail;
						break;
					}
				}
			}
		} else {
			$pet_detail = $this->get_pet_status( $location_api, $petkey_pet_id );
			if ( ! empty( $pet_detail ) ) {
				$pet_detail->pet_type_name = isset( $existing_data->pet_type_name ) ? $existing_data->pet_type_name : '';
				$pet_detail->location_slug = isset( $existing_data->location_slug ) ? $existing_data->location_slug : '';
				$pet_detail->location_api  = isset( $existing_data->location_api )  ? $existing_data->location_api  : '';
				$pet_detail->list_status   = $pet_detail->Status;
			}
		}

		if ( empty( $pet_detail ) ) {
			return '';
		}

		update_post_meta( $pet_id, 'pet_Datadetail', $pet_detail );

		return isset( $pet_detail->Status ) ? $pet_detail->Status : '';
	}

	public function get_pet_status( $api_key, $pet_id ) {
		if ( ! class_exists( 'Petkey_Functions' ) ) {
			return null;
		}
		$pk = new Petkey_Functions( $api_key );
		return $pk->get_pet( $pet_id );
	}

	/* ============================================================
	 * Admin query filter (legacy)
	 * ============================================================ */

	public function petkey_galley_filter( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( ! isset( $_GET['petkey_gallery'] ) || $_GET['petkey_gallery'] != 1 ) {
			return;
		}
		$query->set( 'meta_query', array(
			array(
				'key'     => 'petkey_gallery',
				'value'   => 'Y',
				'compare' => '=',
			),
		) );
	}

	/* ============================================================
	 * Breeds — fast, cached lookup of every distinct breed name
	 * ============================================================ */

	public function get_breeds() {

		$cached = get_transient( 'wcpk_gallery_breeds' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		// Read from both petkey (`breed_name`) and UKM (`_breed`) schemas so
		// sites with mixed-origin gallery posts get a complete dropdown.
		$sql = "SELECT DISTINCT pm.meta_value
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key IN ('breed_name', '_breed')
				AND pm.meta_value <> ''
				AND p.post_type = 'pet-gallery'
				AND p.post_status = 'publish'
				AND NOT EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} ex
					WHERE ex.post_id = p.ID
					AND ex.meta_key = '_exclude_from_breeds'
					AND ex.meta_value = 'Y'
				)
				ORDER BY pm.meta_value ASC";

		$rows = $wpdb->get_col( $sql );
		$breeds = is_array( $rows ) ? array_values( array_filter( $rows ) ) : array();

		set_transient( 'wcpk_gallery_breeds', $breeds, HOUR_IN_SECONDS );

		return $breeds;
	}

	public function clear_breeds_cache() {
		delete_transient( 'wcpk_gallery_breeds' );
	}

	public function maybe_clear_breeds_cache_on_delete( $post_id ) {
		if ( get_post_type( $post_id ) === 'pet-gallery' ) {
			$this->clear_breeds_cache();
		}
	}

	/* ============================================================
	 * AJAX — fetch a page of gallery cards
	 * ============================================================ */

	public function process_petkey_gallery_data() {

		/*
		 * The nonce is advisory and must never deny the read.
		 *
		 * This is a `nopriv` public endpoint, and the nonce it checks is printed
		 * into the gallery HTML (template-loader.php, wcpk_gallery_enqueue_assets)
		 * — which a page cache or CDN will happily serve for longer than a nonce
		 * tick. Enforcing it would mean every "Load more" on a cached page
		 * returned 403 and wiped the grid. Verify it so abuse can be measured,
		 * then carry on.
		 */
		$nonce_ok = true;

		if ( ! empty( $_REQUEST['nonce'] ) ) {
			$nonce_ok = (bool) check_ajax_referer( 'wcpk_gallery', 'nonce', false );
		}

		if ( ! post_type_exists( 'pet-gallery' ) ) {
			wp_send_json_error(
				array(
					'code'    => 'gallery_unavailable',
					'message' => __( 'The pet gallery is not available on this site.', 'petkey-gallery' ),
				),
				500
			);
		}

		if ( isset( $_POST['pet_page'] ) && (int) $_POST['pet_page'] < 1 ) {
			wp_send_json_error(
				array(
					'code'    => 'bad_page',
					'message' => __( 'Invalid page number.', 'petkey-gallery' ),
				),
				400
			);
		}

		$filters = wcpk_gallery_sanitize_filters(
			array(
				'type'     => isset( $_POST['pet_gallery_type'] ) ? $_POST['pet_gallery_type'] : 'ALL',
				'breed'    => isset( $_POST['pet_breed'] ) ? $_POST['pet_breed'] : 'ALL',
				'gender'   => isset( $_POST['pet_gender'] ) ? $_POST['pet_gender'] : 'ALL',
				'page'     => isset( $_POST['pet_page'] ) ? $_POST['pet_page'] : 1,
				// Sent by the client from data-initial so a shortcode's
				// count="12" is not silently overridden by the global setting.
				'per_page' => isset( $_POST['pet_per_page'] ) ? $_POST['pet_per_page'] : 0,
			)
		);

		try {
			$page = wcpk_gallery_query_page( $filters );
			$html = wcpk_gallery_render_cards( $page['posts'] );
		} catch ( \Throwable $e ) {
			error_log( '[wc-pet-gallery] gallery query failed: ' . $e->getMessage() );

			wp_send_json_error(
				array(
					'code'    => 'render_failed',
					'message' => __( 'The gallery could not be loaded. Please try again.', 'petkey-gallery' ),
				),
				500
			);
		}

		$pets = array();

		foreach ( $page['posts'] as $post_obj ) {
			$payload = wcpk_gallery_pet_payload( $post_obj );

			if ( ! empty( $payload ) ) {
				$pets[] = $payload;
			}
		}

		wp_reset_postdata();

		/*
		 * Zero results is a successful answer, not a failure. The old handler
		 * returned HTTP 200 with `success:false` for BOTH "no pets matched" and
		 * "something broke", which is why a 500 or a 403 was indistinguishable
		 * from an empty gallery and showed the visitor nothing at all.
		 */
		wp_send_json_success(
			array(
				'html'     => $html,
				'pets'     => $pets,
				'data'     => $pets, // legacy key, kept for older clients
				'page'     => $page['page'],
				'per_page' => $page['per_page'],
				'has_more' => $page['has_more'],
				'found'    => count( $pets ),
				'empty'    => empty( $pets ),
				'nonce_ok' => $nonce_ok,
			)
		);
	}

	/**
	 * Cache the petgallery_cat term IDs for the lifetime of the request.
	 */
	protected function get_petgallery_cat_terms() {
		static $cached = null;
		if ( $cached !== null ) {
			return $cached;
		}
		$cached = array();
		$terms = get_terms( array( 'taxonomy' => 'petgallery_cat', 'hide_empty' => false, 'fields' => 'ids' ) );
		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			$cached = array_map( 'intval', $terms );
		}
		return $cached;
	}

	/* ============================================================
	 * Legacy method retained for older themes that call it directly.
	 * ============================================================ */

	public function get_gallery_by_breed( $breed_name = '', $number_of_posts = 3 ) {

		$args = array(
			'post_type'      => 'pet-gallery',
			'posts_per_page' => (int) $number_of_posts,
			'post_status'    => 'publish',
			'no_found_rows'  => true,
		);

		if ( ! empty( $breed_name ) ) {
			$args['meta_query'][] = wcpk_gallery_meta_or_clause( 'breed_name', $breed_name );
		}

		// The pet-type filter is applied in SQL now. It used to run after the
		// LIMIT, so a caller asking for 3 pets could silently receive fewer —
		// or none — with no way to tell the difference from "no matches".
		$type_clause = wcpk_gallery_allowed_types_clause();
		if ( null !== $type_clause ) {
			$args['meta_query'][] = $type_clause;
		}

		$breeds_query = new WP_Query( $args );
		if ( ! $breeds_query->have_posts() ) {
			return array();
		}

		$post_ids = wp_list_pluck( $breeds_query->posts, 'ID' );
		update_postmeta_cache( $post_ids );

		$pet_gallery_data = array();

		foreach ( $breeds_query->posts as $post_obj ) {
			$post_id     = (int) $post_obj->ID;
			$animal_type = (string) wcpk_gallery_get_pet_field( $post_id, 'animal_type' );

			$pet_gallery_data[] = array(
				'ID'           => $post_id,
				'animal_type'  => $animal_type,
				'breed_name'   => wcpk_gallery_get_pet_field( $post_id, 'breed_name' ),
				'coloring'     => wcpk_gallery_get_pet_field( $post_id, 'coloring' ),
				'gender'       => wcpk_gallery_get_pet_field( $post_id, 'gender' ),
				'reference_id' => wcpk_gallery_get_pet_field( $post_id, 'reference_id' ),
				'birth_date'   => wcpk_gallery_get_pet_field( $post_id, 'birth_date' ),
				'post_image'   => get_the_post_thumbnail_url( $post_id ),
				'thumbnail_id' => get_post_thumbnail_id( $post_id ),
				'post_title'   => $post_obj->post_title,
				'post_link'    => get_permalink( $post_id ),
			);
		}

		wp_reset_postdata();

		return $pet_gallery_data;
	}

	/* ============================================================
	 * Yoast SEO filters — bail early for non-pet-gallery requests
	 * ============================================================ */

	public function filter_petgallery_wpseo_title( $title ) {

		if ( ! is_singular( 'pet-gallery' ) ) {
			return $title;
		}

		$post_id = get_the_ID();
		if ( get_post_meta( $post_id, '_yoast_wpseo_title', true ) ) {
			return $title;
		}

		$breed_name = wcpk_gallery_get_pet_field( $post_id, 'breed_name' );
		$gender     = wcpk_gallery_get_pet_field( $post_id, 'gender' );
		$coloring   = wcpk_gallery_get_pet_field( $post_id, 'coloring' );
		$petid      = wcpk_gallery_get_pet_field( $post_id, 'petid' );
		$pet_type   = wcpk_gallery_get_pet_field( $post_id, 'animal_type' );
		$site_name  = get_bloginfo( 'name' );

		$parts = array_filter( array( $breed_name, $pet_type, $gender, $coloring, $petid, $site_name ) );
		return wp_strip_all_tags( implode( '-', $parts ) );
	}

	public function filter_petgallery_wpseo_metadesc( $description ) {

		if ( ! is_singular( 'pet-gallery' ) ) {
			return $description;
		}

		$post_id = get_the_ID();
		if ( get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ) ) {
			return $description;
		}

		$breed_name = wcpk_gallery_get_pet_field( $post_id, 'breed_name' );
		$petid      = wcpk_gallery_get_pet_field( $post_id, 'petid' );
		$pet_type   = wcpk_gallery_get_pet_field( $post_id, 'animal_type' );
		$birth_date = wcpk_gallery_get_pet_field( $post_id, 'birth_date' );
		$coloring   = wcpk_gallery_get_pet_field( $post_id, 'coloring' );
		$site_name  = get_bloginfo( 'name' );

		return wp_strip_all_tags( sprintf(
			'This %s %s %s Id: %s was born on %s here at %s.',
			$coloring,
			$breed_name,
			$pet_type,
			$petid,
			$birth_date,
			$site_name
		) );
	}

	/* ============================================================
	 * Sync hook — when the parent sync plugin reverses a status, clean up.
	 *
	 * wc-petkey-sync and wc-pinogy-sync both dispatch
	 * `wc_petkey_syncpet_statuschange` with the same 3-arg signature
	 * (product_id, previous_post_status, list_status) when a previously
	 * non-published product comes back to a publishable status.
	 *
	 * petkey mode reads the publishable list from get_publishables_statuses()
	 * (a wc-petkey-sync helper). pinogy mode uses pinogy's own
	 * $allowed_statuses set defined in
	 * wc-pinogy-sync/inc/wc-pet-sync-functions-pinogy.php:37.
	 * ============================================================ */

	public function wc_petkey_syncpet_statuschange( $pet_post_id, $pet_post_status, $pet_petkey_status ) {

		$parent_mode = defined( 'WCPK_GALLERY_PARENT_MODE' ) ? WCPK_GALLERY_PARENT_MODE : 'none';

		if ( $parent_mode === 'petkey' ) {
			if ( ! function_exists( 'get_publishables_statuses' ) ) {
				return;
			}
			$allowed_statuses = (array) get_publishables_statuses();
		} elseif ( $parent_mode === 'pinogy' ) {
			$allowed_statuses = array( 'available', 'coming_soon' );
		} else {
			return;
		}

		if ( ! in_array( $pet_petkey_status, $allowed_statuses, true ) ) {
			return;
		}

		delete_post_meta( $pet_post_id, 'moved_to_gallery' );

		// Safe meta-based lookup; no SQL interpolation.
		$gallery_posts = get_posts( array(
			'post_type'        => 'pet-gallery',
			'post_status'      => 'any',
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			'meta_key'         => 'actual_product',
			'meta_value'       => (int) $pet_post_id,
		) );

		if ( empty( $gallery_posts ) ) {
			return;
		}

		$gallery_id   = (int) $gallery_posts[0];
		$gallery_post = get_post( $gallery_id );

		wp_delete_post( $gallery_id, true );

		do_action( 'wc_petgallery_post_removed', $gallery_id, $gallery_post );
	}
}

if ( ! function_exists( 'wcpk_gallery_wait_days' ) ) {
	/**
	 * How long a pet must sit in a source status before it is promoted.
	 *
	 * This was the literal 14 in three separate places, one of them baked into
	 * the "Waiting Nd more (14-day cooling-off)" verdict string — so the engine
	 * and the panel reporting on the engine could only agree because neither
	 * could be changed. Migrated from the old `skip_cooling_off` checkbox, which
	 * is still written for back-compat; 0 here means what that toggle meant.
	 *
	 * @return int
	 */
	function wcpk_gallery_wait_days() {
		static $days = null;

		if ( $days !== null ) {
			return $days;
		}

		$stored = get_option( 'petkey_gallery_wait_days', null );

		if ( null === $stored || '' === $stored ) {
			if ( 'yes' === get_option( 'petkey_gallery_skip_cooling_off', 'no' ) ) {
				$days = 0;
			} else {
				$provider = defined( 'WCPK_GALLERY_PARENT_MODE' ) ? WCPK_GALLERY_PARENT_MODE : 'none';
				$days     = ( 'petkey' === $provider ) ? 14 : 0;
			}
		} else {
			$days = max( 0, (int) $stored );
		}

		return $days;
	}
}

if ( ! function_exists( 'wcpk_gallery_get_allowed_animal_types' ) ) {
	/**
	 * The allowed pet types as a lowercase list. Empty means "allow everything".
	 *
	 * @deprecated 1.7.0 No query path calls this any more — the filter is applied
	 * in SQL via wcpk_gallery_allowed_types_clause(). It is kept, with its
	 * signature intact, because it is a public `function_exists`-guarded helper
	 * that themes may call.
	 *
	 * It now reads through the canonical classifier rather than lowercasing the
	 * option's raw values, so it no longer returns labels like "dogs" that match
	 * no stored animal_type — the bug that silently emptied the grid.
	 */
	function wcpk_gallery_get_allowed_animal_types() {
		static $cached = null;

		if ( $cached !== null ) {
			return $cached;
		}

		$cached = array_map( 'strtolower', wcpk_gallery_get_allowed_type_codes() );

		return $cached;
	}
}

$petkey_gallery_functions = new PetkeyGalleryFunctions();
