<?php
/**
 * Template loader for WooCommerce Pet Gallery.
 *
 * Looks up template parts with the standard WP override chain:
 *   1. {child-theme}/wc-pet-gallery/{name}.php
 *   2. {parent-theme}/wc-pet-gallery/{name}.php
 *   3. {child-theme}/wc-petkey-gallery/{name}.php  (legacy back-compat)
 *   4. {parent-theme}/wc-petkey-gallery/{name}.php (legacy back-compat)
 *   5. {plugin}/templates/{name}.php
 *
 * Themes can drop a file at any of the first four locations to override.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ============================================================
 * Provider detection + meta-key adapter
 * ------------------------------------------------------------
 * The gallery's parent plugin is either:
 *   - wc-petkey-sync  (legacy Petkey integration)
 *   - wc-unified-km   (newer UnifiedKM integration)
 * Both write to the same `pet-gallery` CPT but with different
 * meta-key naming. This adapter normalizes reads so the rest of
 * the plugin works against a single logical field set.
 * ============================================================ */

if ( ! function_exists( 'wcpk_gallery_active_plugins' ) ) {
	function wcpk_gallery_active_plugins() {
		static $cached = null;
		if ( $cached !== null ) {
			return $cached;
		}
		$cached = (array) apply_filters( 'active_plugins', get_option( 'active_plugins', array() ) );
		if ( is_multisite() ) {
			$network = (array) get_site_option( 'active_sitewide_plugins', array() );
			if ( ! empty( $network ) ) {
				$cached = array_unique( array_merge( $cached, array_keys( $network ) ) );
			}
		}
		return $cached;
	}
}

if ( ! function_exists( 'wcpk_gallery_detect_provider' ) ) {
	/**
	 * Returns 'petkey' | 'ukm' | 'pinogy' | 'none'. Precedence (petkey > ukm
	 * > pinogy) matches the boot-gate constant WCPK_GALLERY_PARENT_MODE in
	 * wc-pet-gallery.php. Filter `wcpk_gallery_provider` can override.
	 */
	function wcpk_gallery_detect_provider() {
		static $cached = null;
		if ( $cached !== null ) {
			return $cached;
		}

		$active = wcpk_gallery_active_plugins();

		// Same basename test the boot gate uses — see wcpk_gallery_parent_active().
		$has_petkey = wcpk_gallery_parent_active( 'wc-petkey-sync.php', $active );
		$has_ukm    = wcpk_gallery_parent_active( 'wc-unified-km.php', $active )
			|| function_exists( 'wc_ukm_get_pet' )
			|| class_exists( 'UKMGalleryFunctions' );
		$has_pinogy = wcpk_gallery_parent_active( 'wc-pinogy-sync.php', $active );

		if ( $has_petkey ) {
			$cached = 'petkey';
		} elseif ( $has_ukm ) {
			$cached = 'ukm';
		} elseif ( $has_pinogy ) {
			$cached = 'pinogy';
		} else {
			$cached = 'none';
		}

		$resolved = (string) apply_filters( 'wcpk_gallery_provider', $cached, $has_petkey, $has_ukm, $has_pinogy );

		// Same reasoning as wcpk_gallery_ukm_gallery_active(): the function_exists
		// and class_exists legs above are false until the sibling plugin has
		// loaded, so an answer computed before plugins_loaded must not stick.
		$cached = did_action( 'plugins_loaded' ) ? $resolved : null;

		return $resolved;
	}
}

if ( ! function_exists( 'wcpk_gallery_ukm_gallery_active' ) ) {
	/**
	 * True when the standalone wc-ukm-gallery plugin is present.
	 *
	 * The active_plugins entry is the primary signal, not the class. Plugins
	 * load alphabetically, so wc-pet-gallery is included *before*
	 * wc-ukm-gallery — a class_exists() check run at our own include time
	 * answers false on a site where that plugin is very much active. The class
	 * and constant remain as fallbacks for a non-standard install path.
	 *
	 * The answer is only cached once plugins_loaded has fired, so an early
	 * caller cannot freeze the wrong value for the rest of the request.
	 */
	function wcpk_gallery_ukm_gallery_active() {
		static $cached = null;

		if ( $cached !== null ) {
			return $cached;
		}

		$active = wcpk_gallery_parent_active( 'wc-ukm-gallery.php', wcpk_gallery_active_plugins() )
			|| class_exists( 'UKMGalleryFunctions' )
			|| defined( 'UKMGALLERY' );

		if ( did_action( 'plugins_loaded' ) ) {
			$cached = $active;
		}

		return $active;
	}
}

if ( ! function_exists( 'wcpk_gallery_meta_key_map' ) ) {
	/**
	 * Logical field => ordered list of underlying meta keys to try (first
	 * non-empty wins). Petkey keys come first; UKM keys are the fallback.
	 * Themes / sister plugins can extend via the filter.
	 */
	function wcpk_gallery_meta_key_map() {
		static $map = null;
		if ( $map !== null ) {
			return $map;
		}
		$map = array(
			'petid'        => array( 'petid', '_petid' ),
			'name'         => array( 'name', 'pet_name' ),
			'age'          => array( 'age' ),
			'breed_name'   => array( 'breed_name', '_breed' ),
			'animal_type'  => array( 'animal_type', '_pet_type' ),
			'gender'       => array( 'gender' ),
			'coloring'     => array( 'coloring', 'coloration' ),
			'reference_id' => array( 'reference_id', 'reference_number' ),
			'birth_date'   => array( 'birth_date' ),
			'location'     => array( 'location', 'location_slug' ),
		);
		$map = (array) apply_filters( 'wcpk_gallery_meta_key_map', $map );
		return $map;
	}
}

if ( ! function_exists( 'wcpk_gallery_get_pet_field' ) ) {
	function wcpk_gallery_get_pet_field( $post_id, $field, $default = '' ) {
		$map = wcpk_gallery_meta_key_map();
		$keys = isset( $map[ $field ] ) ? $map[ $field ] : array( $field );
		foreach ( $keys as $meta_key ) {
			$value = get_post_meta( $post_id, $meta_key, true );
			if ( $value !== '' && $value !== null && $value !== false ) {
				return $value;
			}
		}
		return $default;
	}
}

if ( ! function_exists( 'wcpk_gallery_get_pet_fields' ) ) {
	function wcpk_gallery_get_pet_fields( $post_id, $fields = null ) {
		$map = wcpk_gallery_meta_key_map();
		if ( $fields === null ) {
			$fields = array_keys( $map );
		}
		$out = array();
		foreach ( $fields as $field ) {
			$out[ $field ] = wcpk_gallery_get_pet_field( $post_id, $field, '' );
		}
		return $out;
	}
}

if ( ! function_exists( 'wcpk_gallery_meta_aliases' ) ) {
	/**
	 * Reverse map: given any underlying meta key, return the ordered list of
	 * aliases to try (starting with the requested key so a present value wins).
	 * Returns null when the requested key isn't part of any logical field.
	 */
	function wcpk_gallery_meta_aliases( $meta_key ) {
		static $reverse = null;
		if ( $reverse === null ) {
			$reverse = array();
			foreach ( wcpk_gallery_meta_key_map() as $logical => $aliases ) {
				foreach ( $aliases as $alias ) {
					$reverse[ $alias ] = $aliases;
				}
			}
		}
		if ( ! isset( $reverse[ $meta_key ] ) ) {
			return null;
		}
		$ordered = $reverse[ $meta_key ];
		// Bring the requested key to the front so its value (if present) wins.
		if ( ( $idx = array_search( $meta_key, $ordered, true ) ) !== 0 ) {
			array_splice( $ordered, $idx, 1 );
			array_unshift( $ordered, $meta_key );
		}
		return $ordered;
	}
}

if ( ! function_exists( 'wcpk_gallery_meta_compat_filter' ) ) {
	/**
	 * Cross-schema meta-read shim. Any code anywhere (themes, sibling plugins,
	 * 3rd-party builders) that calls `get_post_meta( $pet_gallery_id, 'breed_name', true )`
	 * on a UKM-origin post will transparently get the value from `_breed`, and
	 * vice versa. This is what makes the petland theme's archive loop, which
	 * reads meta directly, render identically regardless of which sync plugin
	 * created the gallery post.
	 */
	function wcpk_gallery_meta_compat_filter( $value, $object_id, $meta_key, $single ) {
		static $recursion = false;
		if ( $recursion ) {
			return $value;
		}
		// Only act on keys that belong to a logical field.
		$aliases = wcpk_gallery_meta_aliases( $meta_key );
		if ( empty( $aliases ) || count( $aliases ) < 2 ) {
			return $value;
		}
		// Only act on pet-gallery posts. get_post_type is cheap (cached).
		if ( get_post_type( $object_id ) !== 'pet-gallery' ) {
			return $value;
		}

		$recursion = true;
		$found = '';
		foreach ( $aliases as $alias ) {
			$val = get_post_meta( $object_id, $alias, true );
			if ( $val !== '' && $val !== null && $val !== false && $val !== array() ) {
				$found = $val;
				break;
			}
		}
		$recursion = false;

		if ( $found === '' ) {
			return $value; // null => let WP do its normal (empty) lookup
		}

		// Return shape must match what WP would have returned for $single.
		return $single ? $found : array( $found );
	}

	add_filter( 'get_post_metadata', 'wcpk_gallery_meta_compat_filter', 10, 4 );
}

if ( ! function_exists( 'wcpk_gallery_meta_or_clause' ) ) {
	/**
	 * Build an OR meta_query clause for a logical field. Used in WP_Query
	 * filtering so picking a breed in the dropdown matches posts indexed
	 * under either schema.
	 */
	function wcpk_gallery_meta_or_clause( $field, $value, $compare = '=' ) {
		$map = wcpk_gallery_meta_key_map();
		$keys = isset( $map[ $field ] ) ? $map[ $field ] : array( $field );
		if ( count( $keys ) === 1 ) {
			return array( 'key' => $keys[0], 'value' => $value, 'compare' => $compare );
		}
		$clause = array( 'relation' => 'OR' );
		foreach ( $keys as $key ) {
			$clause[] = array( 'key' => $key, 'value' => $value, 'compare' => $compare );
		}
		return $clause;
	}
}

if ( ! function_exists( 'wcpk_gallery_locate_template' ) ) {
	function wcpk_gallery_locate_template( $name ) {
		$name = preg_replace( '/[^a-z0-9\-_]/i', '', (string) $name );
		if ( $name === '' ) {
			return false;
		}

		$theme = locate_template( array(
			'wc-pet-gallery/' . $name . '.php',
			'wc-petkey-gallery/' . $name . '.php',
		), false, false );
		if ( $theme ) {
			return $theme;
		}

		$fallback = plugin_dir_path( dirname( __FILE__ ) ) . 'templates/' . $name . '.php';
		if ( file_exists( $fallback ) ) {
			return $fallback;
		}

		return false;
	}
}

if ( ! function_exists( 'wcpk_gallery_render_template' ) ) {
	function wcpk_gallery_render_template( $name, $vars = array() ) {
		$template = wcpk_gallery_locate_template( $name );
		if ( ! $template ) {
			return '';
		}

		if ( is_array( $vars ) && ! empty( $vars ) ) {
			extract( $vars, EXTR_SKIP );
		}

		ob_start();
		include $template;
		return ob_get_clean();
	}
}

if ( ! function_exists( 'wcpk_gallery_get_options' ) ) {
	/**
	 * Read all plugin settings in one go, with safe defaults.
	 * The new code paths read from these options directly instead of the
	 * legacy $petkey_gallery_settings global.
	 */
	function wcpk_gallery_get_options() {
		$defaults = array(
			'page_title'         => 'Pet Photo Gallery',
			'form_source'        => 'auto',
			'form_id'            => '',
			'form_shortcode'     => '',
			'inquiry_page'       => '',
			'hero_enabled'       => 'yes',
			'hero_eyebrow'       => 'The Family Album',
			'hero_heading'       => 'Pups who found their forever happily-ever-after.',
			'hero_heading_accent'=> 'forever',
			'hero_intro'         => 'Every face on this page is a story we get to keep. Browse our diverse collection of adorable pups who have found their forever homes — and if a particular cutie steals your heart, tap their photo to raise your hand for a pup just like them.',
			'cta_label'          => 'Inquire about a pup',
			'card_cta_label'     => 'Get one like this',
			'sticker_label'      => 'Adopted',
			'sticker_eyebrow'    => 'Forever',
			'stats_enabled'      => 'yes',
			'forever_homes_base' => 0,
			'grid_eyebrow'       => 'Recently Adopted',
			'grid_heading'       => 'Meet the latest love stories',
			'grid_heading_accent'=> 'love stories',
			'color'              => '',
			'columns'            => 4,
			'count'              => 0,
			'archive_override'   => 'yes',
			'single_override'    => 'auto',
			'single_show_breed_info' => 'yes',
			'single_show_related' => 'yes',
			'force_clean_layout' => 'no',
		);

		$opts = array(
			'page_title'         => get_option( 'petkey_gallery_page_title', $defaults['page_title'] ),
			'form_source'        => get_option( 'petkey_gallery_form_source', $defaults['form_source'] ),
			'form_id'            => get_option( 'petkey_gallery_form_id', $defaults['form_id'] ),
			'form_shortcode'     => get_option( 'petkey_gallery_form_shortcode', $defaults['form_shortcode'] ),
			'inquiry_page'       => get_option( 'petkey_gallery_inquiry_page', $defaults['inquiry_page'] ),
			'hero_enabled'       => get_option( 'petkey_gallery_hero_enabled', $defaults['hero_enabled'] ),
			'hero_eyebrow'       => get_option( 'petkey_gallery_hero_eyebrow', $defaults['hero_eyebrow'] ),
			'hero_heading'       => get_option( 'petkey_gallery_hero_heading', $defaults['hero_heading'] ),
			'hero_heading_accent'=> get_option( 'petkey_gallery_hero_heading_accent', $defaults['hero_heading_accent'] ),
			'hero_intro'         => get_option( 'petkey_gallery_hero_intro', $defaults['hero_intro'] ),
			'cta_label'          => get_option( 'petkey_gallery_cta_label', $defaults['cta_label'] ),
			'card_cta_label'     => get_option( 'petkey_gallery_card_cta_label', $defaults['card_cta_label'] ),
			'sticker_label'      => get_option( 'petkey_gallery_sticker_label', $defaults['sticker_label'] ),
			'sticker_eyebrow'    => get_option( 'petkey_gallery_sticker_eyebrow', $defaults['sticker_eyebrow'] ),
			'stats_enabled'      => get_option( 'petkey_gallery_stats_enabled', $defaults['stats_enabled'] ),
			'forever_homes_base' => (int) get_option( 'petkey_gallery_forever_homes_base', $defaults['forever_homes_base'] ),
			'grid_eyebrow'       => get_option( 'petkey_gallery_grid_eyebrow', $defaults['grid_eyebrow'] ),
			'grid_heading'       => get_option( 'petkey_gallery_grid_heading', $defaults['grid_heading'] ),
			'grid_heading_accent'=> get_option( 'petkey_gallery_grid_heading_accent', $defaults['grid_heading_accent'] ),
			'color'              => get_option( 'petkey_gallery_color', $defaults['color'] ),
			'columns'            => (int) get_option( 'petkey_gallery_columns', $defaults['columns'] ),
			'count'              => (int) get_option( 'petkey_gallery_numposts', $defaults['count'] ),
			'archive_override'   => get_option( 'petkey_gallery_archive_override', $defaults['archive_override'] ),
			'single_override'    => get_option( 'petkey_gallery_single_override', $defaults['single_override'] ),
			'single_show_breed_info' => get_option( 'petkey_gallery_single_show_breed_info', $defaults['single_show_breed_info'] ),
			'single_show_related' => get_option( 'petkey_gallery_single_show_related', $defaults['single_show_related'] ),
			'force_clean_layout' => get_option( 'petkey_gallery_force_clean_layout', $defaults['force_clean_layout'] ),
		);

		if ( empty( $opts['form_id'] ) ) {
			$legacy = get_option( 'petkey_gallery_enquiry', '' );
			if ( ! empty( $legacy ) ) {
				$opts['form_id'] = $legacy;
			}
		}

		if ( empty( $opts['count'] ) ) {
			$opts['count'] = 12;
		}

		return $opts;
	}
}

if ( ! function_exists( 'wcpk_gallery_count_published' ) ) {
	function wcpk_gallery_count_published( $force = false ) {
		$cached = get_transient( 'wcpk_gallery_count_published' );
		if ( ! $force && false !== $cached ) {
			return (int) $cached;
		}
		$counts = wp_count_posts( 'pet-gallery' );
		$count  = isset( $counts->publish ) ? (int) $counts->publish : 0;
		set_transient( 'wcpk_gallery_count_published', $count, HOUR_IN_SECONDS );
		return $count;
	}
}

if ( ! function_exists( 'wcpk_gallery_count_unique_breeds' ) ) {
	function wcpk_gallery_count_unique_breeds( $force = false ) {
		global $wpdb;
		$cached = get_transient( 'wcpk_gallery_count_unique_breeds' );
		if ( ! $force && false !== $cached ) {
			return (int) $cached;
		}
		// Union of both schemas — petkey writes `breed_name`, UKM writes `_breed`.
		$sql   = "SELECT COUNT(*) FROM (
					SELECT DISTINCT pm.meta_value
					FROM {$wpdb->postmeta} pm
					INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					WHERE pm.meta_key IN ('breed_name', '_breed')
					AND pm.meta_value <> ''
					AND p.post_type = 'pet-gallery'
					AND p.post_status = 'publish'
				) u";
		$count = (int) $wpdb->get_var( $sql );
		set_transient( 'wcpk_gallery_count_unique_breeds', $count, HOUR_IN_SECONDS );
		return $count;
	}
}

if ( ! function_exists( 'wcpk_gallery_get_hero_polaroids' ) ) {
	function wcpk_gallery_get_hero_polaroids( $count = 5 ) {
		$query = new WP_Query( array(
			'post_type'      => 'pet-gallery',
			'post_status'    => 'publish',
			'posts_per_page' => (int) $count,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => array(
				array(
					'key'     => '_thumbnail_id',
					'compare' => 'EXISTS',
				),
			),
			'no_found_rows'  => true,
		) );
		$items = array();
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$pid      = get_the_ID();
				$image_id = get_post_thumbnail_id( $pid );
				$src      = wp_get_attachment_image_src( $image_id, 'medium' );
				if ( empty( $src ) ) {
					continue;
				}
				$items[] = array(
					'id'         => $pid,
					'permalink'  => get_permalink( $pid ),
					'image'      => $src[0],
					'width'      => $src[1],
					'height'     => $src[2],
					'breed_name' => wcpk_gallery_get_pet_field( $pid, 'breed_name' ),
					'gender'     => wcpk_gallery_get_pet_field( $pid, 'gender' ),
				);
			}
			wp_reset_postdata();
		}
		return $items;
	}
}

if ( ! function_exists( 'wcpk_gallery_format_homes_count' ) ) {
	function wcpk_gallery_format_homes_count() {
		$opts  = wcpk_gallery_get_options();
		$base  = (int) $opts['forever_homes_base'];
		$count = wcpk_gallery_count_published();
		$total = $base + $count;
		if ( $total <= 0 ) {
			return '—';
		}
		if ( $total >= 100 ) {
			$rounded = (int) ( floor( $total / 10 ) * 10 );
			return number_format_i18n( $rounded ) . '+';
		}
		return number_format_i18n( $total );
	}
}

if ( ! function_exists( 'wcpk_gallery_clear_count_cache' ) ) {
	function wcpk_gallery_clear_count_cache() {
		delete_transient( 'wcpk_gallery_count_published' );
		delete_transient( 'wcpk_gallery_count_unique_breeds' );
	}
}

add_action( 'wc_petgallery_post_created', 'wcpk_gallery_clear_count_cache' );
add_action( 'wc_petgallery_post_removed', 'wcpk_gallery_clear_count_cache' );
add_action( 'save_post_pet-gallery', 'wcpk_gallery_clear_count_cache' );
add_action( 'deleted_post', function ( $post_id ) {
	if ( get_post_type( $post_id ) === 'pet-gallery' ) {
		wcpk_gallery_clear_count_cache();
	}
} );

if ( ! function_exists( 'wcpk_gallery_get_accepted_api_statuses' ) ) {
	/**
	 * Petkey API statuses that count as "this pet found its home, move to gallery".
	 * Default: adopted + sold. Admin can override via the comma-separated setting
	 * `petkey_gallery_accepted_statuses`. Sister code can extend via the filter.
	 */
	function wcpk_gallery_get_accepted_api_statuses() {
		static $cached = null;
		if ( $cached !== null ) {
			return $cached;
		}
		$raw = get_option( 'petkey_gallery_accepted_statuses', '' );
		$list = array();
		if ( is_string( $raw ) && $raw !== '' ) {
			foreach ( explode( ',', $raw ) as $item ) {
				$item = strtolower( trim( $item ) );
				if ( $item !== '' ) {
					$list[] = $item;
				}
			}
		}
		if ( empty( $list ) ) {
			$list = array( 'adopted', 'sold' );
		}
		$list = array_values( array_unique( array_map( 'strtolower', (array) apply_filters( 'wcpk_gallery_gallery_accept_statuses', $list ) ) ) );
		$cached = $list;
		return $cached;
	}
}

if ( ! function_exists( 'wcpk_gallery_cooling_off_disabled' ) ) {
	/**
	 * Whether the 14-day cooling-off period should be skipped this run.
	 * Default false (delay enforced). Admin toggles via setting.
	 */
	function wcpk_gallery_cooling_off_disabled() {
		return get_option( 'petkey_gallery_skip_cooling_off', 'no' ) === 'yes';
	}
}

if ( ! function_exists( 'wcpk_gallery_enqueue_assets' ) ) {
	function wcpk_gallery_enqueue_assets() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;

		$plugin_url = plugin_dir_url( dirname( __FILE__ ) );
		$version    = defined( 'PETGALLERYVERSION' ) ? PETGALLERYVERSION : '1.1.0';

		wp_enqueue_style( 'wcpk-gallery', $plugin_url . 'asset/css/gallery.css', array(), $version );
		wp_enqueue_script( 'wcpk-gallery', $plugin_url . 'asset/js/gallery.js', array(), $version, true );

		$resolver = new WCPK_Gallery_Form_Resolver();
		$opts     = wcpk_gallery_get_options();

		$mode = $resolver->mode();
		$mode_for_js = ( $mode === WCPK_Gallery_Form_Resolver::MODE_NONE ) ? 'none' : $mode;

		$link_url = '';
		if ( $mode === WCPK_Gallery_Form_Resolver::MODE_LINK ) {
			$payload = $resolver->get_payload();
			$link_url = isset( $payload['url'] ) ? $payload['url'] : '';
		}

		wp_localize_script( 'wcpk-gallery', 'wcpkGallery', array(
			'ajax_url'        => admin_url( 'admin-ajax.php' ),
			'nonce'           => wp_create_nonce( 'wcpk_gallery' ),
			'no_image'        => $plugin_url . 'asset/img/placeholder.svg',
			'sticker_label'   => $opts['sticker_label'],
			'sticker_eyebrow' => $opts['sticker_eyebrow'],
			'card_cta_label'  => $opts['card_cta_label'],
			'form_mode'       => $mode_for_js,
			'link_url'        => $link_url,
			'dialog_selector' => '#wcpk-inquire-dialog',
			// Translatable strings for the empty and error states. The error
			// state is new: the script used to render nothing at all when the
			// endpoint failed, so a 500 looked exactly like an empty gallery.
			'empty_text'      => __( 'No pets found for your search.', 'petkey-gallery' ),
			'error_text'      => __( 'We couldn’t load the gallery just now.', 'petkey-gallery' ),
			'retry_text'      => __( 'Try again', 'petkey-gallery' ),
		) );
	}
}
