<?php
/**
 * The option registry — one declaration per setting, no translation.
 *
 * Deliberately split from the admin UI. `settings.php` is required
 * unconditionally and `wcpk_gallery_get_options()` has front-end call sites, so
 * the defaults and sanitizers have to be available at plugin-load time on every
 * request. Labels and help text are built separately, on `admin_init`, because
 * only that layer may call __().
 *
 * @package WooCommerce Pet Gallery
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wcpk_gallery_layout_preset_values' ) ) {
	/**
	 * The three legacy routing options each Layout preset resolves to.
	 *
	 * The presets exist because those three switches — plus the two detail-page
	 * checkboxes — interact in ways you can only discover by saving and
	 * reloading the front end, which is exactly the "I keep testing switches to
	 * get things working" problem. They are still written to their own option
	 * keys, because themes and the template router read them.
	 *
	 *   theme        theme templates where they exist, plugin's as the fallback
	 *   plugin       always the plugin's design, inside the theme's page chrome
	 *   plugin_clean the plugin's design with the theme's chrome bypassed
	 *   wp_default   leave WordPress/the theme entirely alone
	 *
	 * `wp_default` is never offered unless a site is already in it — see
	 * wcpk_gallery_layout_preset(). It exists so an existing deliberate
	 * configuration is not silently rewritten by the first save on the new UI.
	 *
	 * @return array<string, array<string, string>>
	 */
	function wcpk_gallery_layout_preset_values() {
		return array(
			'theme'        => array(
				'petkey_gallery_archive_override'   => 'yes',
				'petkey_gallery_single_override'    => 'auto',
				'petkey_gallery_force_clean_layout' => 'no',
			),
			'plugin'       => array(
				'petkey_gallery_archive_override'   => 'always',
				'petkey_gallery_single_override'    => 'always',
				'petkey_gallery_force_clean_layout' => 'no',
			),
			'plugin_clean' => array(
				'petkey_gallery_archive_override'   => 'always',
				'petkey_gallery_single_override'    => 'always',
				'petkey_gallery_force_clean_layout' => 'yes',
			),
			'wp_default'   => array(
				'petkey_gallery_archive_override'   => 'no',
				'petkey_gallery_single_override'    => 'no',
				'petkey_gallery_force_clean_layout' => 'no',
			),
		);
	}
}

if ( ! function_exists( 'wcpk_gallery_layout_preset' ) ) {
	/**
	 * Which preset the stored options currently describe.
	 *
	 * @return string
	 */
	function wcpk_gallery_layout_preset() {

		$archive = get_option( 'petkey_gallery_archive_override', 'yes' );
		$single  = get_option( 'petkey_gallery_single_override', 'auto' );
		$clean   = get_option( 'petkey_gallery_force_clean_layout', 'no' );

		if ( 'yes' === $clean ) {
			return 'plugin_clean';
		}

		if ( 'no' === $archive || 'no' === $single ) {
			return 'wp_default';
		}

		if ( 'always' === $single && 'always' === $archive ) {
			return 'plugin';
		}

		// A half-and-half configuration (one route forced, the other automatic)
		// has no preset. Report the closest one rather than silently rewriting
		// the other route on the next save.
		if ( 'always' === $single || 'always' === $archive ) {
			return 'mixed';
		}

		return 'theme';
	}
}

if ( ! function_exists( 'wcpk_gallery_apply_layout_preset' ) ) {
	/**
	 * Write the legacy routing options for a preset.
	 *
	 * @param string $preset
	 * @return bool Whether anything was written.
	 */
	function wcpk_gallery_apply_layout_preset( $preset ) {

		$presets = wcpk_gallery_layout_preset_values();

		if ( ! isset( $presets[ $preset ] ) ) {
			return false;
		}

		foreach ( $presets[ $preset ] as $key => $value ) {
			update_option( $key, $value );
		}

		/*
		 * The two detail-page extras are meaningless under the theme's own
		 * single template and were two more switches to guess at. They default
		 * to on whenever the plugin's template renders, and stay overridable
		 * through the wcpk_gallery_show_breed_info / _show_related filters.
		 *
		 * add_option, not update_option: a site that deliberately stored 'no'
		 * keeps it. Force-writing here would silently re-enable them on every
		 * unrelated save of the Gallery tab, with no UI to set them back.
		 */
		add_option( 'petkey_gallery_single_show_breed_info', 'yes' );
		add_option( 'petkey_gallery_single_show_related', 'yes' );

		return true;
	}
}

if ( ! function_exists( 'wcpk_gallery_detect_theme_templates' ) ) {
	/**
	 * Which gallery templates the active theme actually ships, and therefore
	 * which design is rendering right now.
	 *
	 * This readout is the real fix for the trial-and-error problem: nothing in
	 * the admin has ever told an operator which template won, so the only way
	 * to find out was to toggle a switch, save, and go look.
	 *
	 * @return array{archive:string,single:string,active:string}
	 */
	function wcpk_gallery_detect_theme_templates() {

		$archive_tpl = locate_template( array( 'archive-pet-gallery.php' ), false, false );
		$single_tpl  = locate_template( array( 'single-pet-gallery.php' ), false, false );

		$archive_opt = get_option( 'petkey_gallery_archive_override', 'yes' );
		$single_opt  = get_option( 'petkey_gallery_single_override', 'auto' );
		$clean       = 'yes' === get_option( 'petkey_gallery_force_clean_layout', 'no' );

		/*
		 * Resolved per route, mirroring maybe_override_archive_template()
		 * exactly. Reporting one combined answer was wrong often enough to
		 * matter: with the theme shipping an archive template but not a single
		 * one, the two routes genuinely render different designs, and a single
		 * "currently rendering" line had to be lying about one of them.
		 */
		if ( $clean ) {
			$archive_active = 'plugin';
		} elseif ( 'no' === $archive_opt ) {
			$archive_active = 'wp';
		} elseif ( 'always' === $archive_opt ) {
			$archive_active = 'plugin';
		} else {
			$archive_active = $archive_tpl ? 'theme' : 'plugin';
		}

		if ( $clean ) {
			$single_active = 'plugin';
		} elseif ( 'no' === $single_opt ) {
			$single_active = 'wp';
		} elseif ( 'always' === $single_opt ) {
			$single_active = 'plugin';
		} else {
			$single_active = $single_tpl ? 'theme' : 'plugin';
		}

		return array(
			'archive'        => $archive_tpl ? (string) $archive_tpl : '',
			'single'         => $single_tpl ? (string) $single_tpl : '',
			'archive_active' => $archive_active,
			'single_active'  => $single_active,
			'active'         => $archive_active === $single_active ? $archive_active : 'mixed',
		);
	}
}

if ( ! function_exists( 'wcpk_gallery_seo_modes' ) ) {
	/**
	 * Valid values for `petkey_gallery_seo_mode`.
	 *
	 * @return string[]
	 */
	function wcpk_gallery_seo_modes() {
		return array( 'hide_pages', 'hide_pages_and_images', 'index_all' );
	}
}

if ( ! function_exists( 'wcpk_gallery_option_registry' ) ) {
	/**
	 * Every admin-editable option: where it lives, what it defaults to, how it
	 * is sanitized, which providers it applies to, and what makes it visible.
	 *
	 * `tab`       which tab renders it
	 * `section`   grouping within that tab
	 * `providers` omit for "all"; otherwise the parent sync plugins it applies to
	 * `show_if`   [key, values] — hidden, and NOT SAVED, unless the condition holds
	 * `advanced`  rendered inside a collapsed <details>
	 *
	 * @return array<string, array>
	 */
	function wcpk_gallery_option_registry() {

		static $registry = null;

		if ( $registry !== null ) {
			return $registry;
		}

		$registry = array(

			/* ---- Gallery tab ------------------------------------------- */
			'petkey_gallery_page_title'          => array(
				'tab'      => 'gallery',
				'section'  => 'page',
				'type'     => 'text',
				'default'  => 'Pet Photo Gallery',
				'sanitize' => 'sanitize_text_field',
			),
			'petkey_gallery_columns'             => array(
				'tab'      => 'gallery',
				'section'  => 'grid',
				'type'     => 'select',
				'default'  => 4,
				'choices'  => array( 2, 3, 4 ),
				'sanitize' => 'wcpk_gallery_sanitize_columns',
			),
			'petkey_gallery_color'               => array(
				'tab'      => 'gallery',
				'section'  => 'grid',
				'type'     => 'color',
				'default'  => '',
				'sanitize' => 'wcpk_gallery_sanitize_color',
			),
			'petkey_gallery_numposts'            => array(
				'tab'      => 'gallery',
				'section'  => 'grid',
				'type'     => 'number',
				'default'  => 12,
				'min'      => 1,
				'sanitize' => 'wcpk_gallery_sanitize_per_page',
			),
			'petkey_gallery_types'               => array(
				'tab'      => 'gallery',
				'section'  => 'grid',
				'type'     => 'types',
				'default'  => array(),
				'sanitize' => 'wcpk_gallery_sanitize_types',
			),
			'petkey_gallery_hero_enabled'        => array(
				'tab'      => 'gallery',
				'section'  => 'hero',
				'type'     => 'checkbox',
				'default'  => 'yes',
				'sanitize' => 'wcpk_gallery_sanitize_yesno',
			),
			'petkey_gallery_hero_heading'        => array(
				'tab'      => 'gallery',
				'section'  => 'hero',
				'type'     => 'text',
				'default'  => 'Pups who found their forever happily-ever-after.',
				'sanitize' => 'sanitize_text_field',
				'show_if'  => array( 'petkey_gallery_hero_enabled', array( 'yes' ) ),
			),
			'petkey_gallery_hero_intro'          => array(
				'tab'      => 'gallery',
				'section'  => 'hero',
				'type'     => 'textarea',
				'default'  => 'Every face on this page is a story we get to keep. Browse our diverse collection of adorable pups who have found their forever homes — and if a particular cutie steals your heart, tap their photo to raise your hand for a pup just like them.',
				'sanitize' => 'wcpk_gallery_sanitize_rich_text',
				'show_if'  => array( 'petkey_gallery_hero_enabled', array( 'yes' ) ),
			),

			/* ---- Inquiries tab ----------------------------------------- */
			'petkey_gallery_form_source'         => array(
				'tab'      => 'inquiries',
				'section'  => 'form',
				'type'     => 'select',
				'default'  => 'auto',
				'choices'  => array( 'auto', 'theme', 'gravityforms', 'contactform7', 'wpforms', 'shortcode', 'none' ),
				'sanitize' => 'wcpk_gallery_sanitize_form_source',
			),
			'petkey_gallery_form_id'             => array(
				'tab'      => 'inquiries',
				'section'  => 'form',
				'type'     => 'form_id',
				'default'  => '',
				'sanitize' => 'sanitize_text_field',
				'show_if'  => array( 'petkey_gallery_form_source', array( 'auto', 'gravityforms', 'contactform7', 'wpforms' ) ),
			),
			'petkey_gallery_form_shortcode'      => array(
				'tab'      => 'inquiries',
				'section'  => 'form',
				'type'     => 'textarea',
				'default'  => '',
				// Trim only. A shortcode must survive verbatim; running it
				// through sanitize_text_field would mangle quoted attributes.
				'sanitize' => 'wcpk_gallery_sanitize_shortcode',
				'show_if'  => array( 'petkey_gallery_form_source', array( 'auto', 'shortcode' ) ),
			),
			'petkey_gallery_inquiry_page'        => array(
				'tab'      => 'inquiries',
				'section'  => 'form',
				'type'     => 'page',
				'default'  => '',
				'sanitize' => 'sanitize_text_field',
				'show_if'  => array( 'petkey_gallery_form_source', array( 'auto', 'theme', 'gravityforms', 'contactform7', 'wpforms', 'shortcode' ) ),
			),
			'petkey_gallery_cta_label'           => array(
				'tab'      => 'inquiries',
				'section'  => 'labels',
				'type'     => 'text',
				'default'  => 'Inquire about a pup',
				'sanitize' => 'sanitize_text_field',
			),
			'petkey_gallery_card_cta_label'      => array(
				'tab'      => 'inquiries',
				'section'  => 'labels',
				'type'     => 'text',
				'default'  => 'Get one like this',
				'sanitize' => 'sanitize_text_field',
			),

			/* ---- Advanced: search engines ------------------------------ */
			'petkey_gallery_seo_mode'            => array(
				'tab'      => 'advanced',
				'section'  => 'seo',
				'type'     => 'select',
				'default'  => 'hide_pages',
				'choices'  => array( 'hide_pages', 'hide_pages_and_images', 'index_all' ),
				'sanitize' => 'wcpk_gallery_sanitize_seo_mode',
				'advanced' => true,
			),

			/* ---- Advanced: wording ------------------------------------- */
			'petkey_gallery_hero_eyebrow'        => array(
				'tab'      => 'advanced',
				'section'  => 'wording',
				'type'     => 'text',
				'default'  => 'The Family Album',
				'sanitize' => 'sanitize_text_field',
				'advanced' => true,
			),
			'petkey_gallery_hero_heading_accent' => array(
				'tab'      => 'advanced',
				'section'  => 'wording',
				'type'     => 'text',
				'default'  => 'forever',
				'sanitize' => 'sanitize_text_field',
				'advanced' => true,
			),
			'petkey_gallery_grid_eyebrow'        => array(
				'tab'      => 'advanced',
				'section'  => 'wording',
				'type'     => 'text',
				'default'  => 'Recently Adopted',
				'sanitize' => 'sanitize_text_field',
				'advanced' => true,
			),
			'petkey_gallery_grid_heading'        => array(
				'tab'      => 'advanced',
				'section'  => 'wording',
				'type'     => 'text',
				'default'  => 'Meet the latest love stories',
				'sanitize' => 'sanitize_text_field',
				'advanced' => true,
			),
			'petkey_gallery_grid_heading_accent' => array(
				'tab'      => 'advanced',
				'section'  => 'wording',
				'type'     => 'text',
				'default'  => 'love stories',
				'sanitize' => 'sanitize_text_field',
				'advanced' => true,
			),
			'petkey_gallery_sticker_eyebrow'     => array(
				'tab'      => 'advanced',
				'section'  => 'wording',
				'type'     => 'text',
				'default'  => 'Forever',
				'sanitize' => 'sanitize_text_field',
				'advanced' => true,
			),
			'petkey_gallery_sticker_label'       => array(
				'tab'      => 'advanced',
				'section'  => 'wording',
				'type'     => 'text',
				'default'  => 'Adopted',
				'sanitize' => 'sanitize_text_field',
				'advanced' => true,
			),
			'petkey_gallery_stats_enabled'       => array(
				'tab'      => 'advanced',
				'section'  => 'wording',
				'type'     => 'checkbox',
				'default'  => 'yes',
				'sanitize' => 'wcpk_gallery_sanitize_yesno',
				'advanced' => true,
			),
			'petkey_gallery_forever_homes_base'  => array(
				'tab'      => 'advanced',
				'section'  => 'wording',
				'type'     => 'number',
				'default'  => 0,
				'min'      => 0,
				'sanitize' => 'absint',
				'advanced' => true,
			),

			/* ---- Sync (provider-gated) --------------------------------- */
			'petkey_gallery_accepted_statuses'   => array(
				'tab'       => 'advanced',
				'section'   => 'sync',
				'type'      => 'text',
				'default'   => 'adopted, sold',
				'sanitize'  => 'sanitize_text_field',
				'providers' => array( 'petkey' ),
				'advanced'  => true,
			),
			'petkey_gallery_wait_days'           => array(
				'tab'       => 'advanced',
				'section'   => 'sync',
				'type'      => 'number',
				'default'   => 14,
				'min'       => 0,
				'sanitize'  => 'absint',
				'providers' => array( 'petkey', 'pinogy' ),
				'advanced'  => true,
			),

			/* ---- Advanced: compatibility ------------------------------- */
			'petkey_gallery_cat_all_behavior'    => array(
				'tab'      => 'advanced',
				'section'  => 'compat',
				'type'     => 'select',
				'default'  => 'include',
				'choices'  => array( 'include', 'exclude_categorised' ),
				'sanitize' => 'wcpk_gallery_sanitize_cat_behavior',
				'advanced' => true,
			),
			'petkey_gallery_enquiry'             => array(
				'tab'      => 'advanced',
				'section'  => 'compat',
				'type'     => 'form_id',
				'default'  => '',
				'sanitize' => 'sanitize_text_field',
				'advanced' => true,
			),
			'wcpk_gallery_release_channel'       => array(
				'tab'      => 'advanced',
				'section'  => 'compat',
				'type'     => 'select',
				'default'  => 'stable',
				'choices'  => array( 'stable', 'beta' ),
				'sanitize' => 'wcpk_gallery_sanitize_channel',
				'advanced' => true,
			),
		);

		return $registry;
	}
}

/* ------------------------------------------------------------------
 * Sanitizers. Declared individually rather than inferred from `type`,
 * because three of them (rich text, shortcode, colour) must NOT get the
 * generic string treatment.
 * ------------------------------------------------------------------ */

if ( ! function_exists( 'wcpk_gallery_sanitize_yesno' ) ) {
	function wcpk_gallery_sanitize_yesno( $value ) {
		return ( 'yes' === $value || '1' === $value || 1 === $value || true === $value ) ? 'yes' : 'no';
	}
}

if ( ! function_exists( 'wcpk_gallery_sanitize_columns' ) ) {
	function wcpk_gallery_sanitize_columns( $value ) {
		return max( 2, min( 4, (int) $value ) );
	}
}

if ( ! function_exists( 'wcpk_gallery_sanitize_per_page' ) ) {
	/**
	 * A stored 0 is reachable from the old <input type=number> with no min, and
	 * it silently fell back to a different default in each of the three places
	 * that read it. Reject it here, and keep the reader's coercion as a belt.
	 */
	function wcpk_gallery_sanitize_per_page( $value ) {
		$value = (int) $value;

		// The same ceiling the query path enforces. Two different caps meant the
		// field happily stored 150 and redisplayed it while the gallery rendered
		// 120 — a setting the product silently did not honour.
		$max = function_exists( 'wcpk_gallery_max_per_page' ) ? wcpk_gallery_max_per_page() : 120;

		return $value > 0 ? min( $max, $value ) : 12;
	}
}

if ( ! function_exists( 'wcpk_gallery_sanitize_color' ) ) {
	function wcpk_gallery_sanitize_color( $value ) {
		$clean = sanitize_hex_color( $value );

		return $clean ? $clean : '';
	}
}

if ( ! function_exists( 'wcpk_gallery_sanitize_rich_text' ) ) {
	function wcpk_gallery_sanitize_rich_text( $value ) {
		// NOT wp_unslash() — maybe_save() already unslashed. Doing it twice
		// silently eats a literal backslash, so `\\` collapses to `\`.
		return wp_kses_post( (string) $value );
	}
}

if ( ! function_exists( 'wcpk_gallery_sanitize_shortcode' ) ) {
	function wcpk_gallery_sanitize_shortcode( $value ) {
		// See wcpk_gallery_sanitize_rich_text(): already unslashed upstream, and
		// a shortcode like [form path="c:\\x"] must survive verbatim.
		return trim( (string) $value );
	}
}

if ( ! function_exists( 'wcpk_gallery_sanitize_form_source' ) ) {
	function wcpk_gallery_sanitize_form_source( $value ) {
		$allowed = array( 'auto', 'theme', 'gravityforms', 'contactform7', 'wpforms', 'shortcode', 'none' );

		return in_array( $value, $allowed, true ) ? $value : 'auto';
	}
}

if ( ! function_exists( 'wcpk_gallery_sanitize_seo_mode' ) ) {
	function wcpk_gallery_sanitize_seo_mode( $value ) {
		return in_array( $value, wcpk_gallery_seo_modes(), true ) ? $value : 'hide_pages';
	}
}

if ( ! function_exists( 'wcpk_gallery_sanitize_cat_behavior' ) ) {
	function wcpk_gallery_sanitize_cat_behavior( $value ) {
		return ( 'exclude_categorised' === $value ) ? 'exclude_categorised' : 'include';
	}
}

if ( ! function_exists( 'wcpk_gallery_sanitize_channel' ) ) {
	function wcpk_gallery_sanitize_channel( $value ) {
		return ( 'beta' === $value ) ? 'beta' : 'stable';
	}
}

if ( ! function_exists( 'wcpk_gallery_sanitize_types' ) ) {
	/**
	 * Store a flat list of canonical codes. An empty array means "show every
	 * type" and is the default.
	 *
	 * The old save path wrote a CODE => LABEL map whenever nothing was ticked,
	 * which the reader could not match against any animal_type — the bug that
	 * blanked the whole grid. There is no branch here that can produce a label.
	 */
	function wcpk_gallery_sanitize_types( $value ) {

		if ( ! is_array( $value ) ) {
			return array();
		}

		$codes = array();

		foreach ( $value as $token ) {
			$code = wcpk_gallery_type_token_to_code( is_scalar( $token ) ? (string) $token : '' );

			if ( '' !== $code ) {
				$codes[ $code ] = true;
			}
		}

		$codes = array_keys( $codes );

		// Everything selected is the same as nothing selected.
		if ( ! array_diff( wcpk_gallery_type_codes(), $codes ) ) {
			return array();
		}

		return $codes;
	}
}

if ( ! function_exists( 'wcpk_gallery_option_default' ) ) {
	function wcpk_gallery_option_default( $key ) {
		$registry = wcpk_gallery_option_registry();

		return isset( $registry[ $key ]['default'] ) ? $registry[ $key ]['default'] : '';
	}
}

if ( ! function_exists( 'wcpk_gallery_option_applies' ) ) {
	/**
	 * Whether an option is relevant to the site's parent sync plugin.
	 *
	 * @param string $key
	 * @param string $provider
	 * @return bool
	 */
	function wcpk_gallery_option_applies( $key, $provider = null ) {

		$registry = wcpk_gallery_option_registry();

		if ( ! isset( $registry[ $key ] ) ) {
			return false;
		}

		if ( empty( $registry[ $key ]['providers'] ) ) {
			return true;
		}

		if ( null === $provider ) {
			$provider = defined( 'WCPK_GALLERY_PARENT_MODE' ) ? WCPK_GALLERY_PARENT_MODE : 'none';
		}

		return in_array( $provider, (array) $registry[ $key ]['providers'], true );
	}
}

if ( ! function_exists( 'wcpk_gallery_option_visible' ) ) {
	/**
	 * Whether a conditional field's condition currently holds.
	 *
	 * Used for rendering AND for saving. Skipping the save matters: without it,
	 * switching Form source to "Theme" and pressing Save would wipe
	 * `petkey_gallery_form_id`, because the hidden input posts nothing and the
	 * saver would read that as an empty value.
	 *
	 * @param string $key
	 * @param array  $posted Values from the current request, if any.
	 * @return bool
	 */
	function wcpk_gallery_option_visible( $key, $posted = array() ) {

		$registry = wcpk_gallery_option_registry();

		if ( empty( $registry[ $key ]['show_if'] ) ) {
			return true;
		}

		list( $dep_key, $dep_values ) = $registry[ $key ]['show_if'];

		$current = array_key_exists( $dep_key, $posted )
			? $posted[ $dep_key ]
			: get_option( $dep_key, wcpk_gallery_option_default( $dep_key ) );

		return in_array( (string) $current, array_map( 'strval', (array) $dep_values ), true );
	}
}
