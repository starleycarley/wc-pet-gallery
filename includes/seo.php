<?php
/**
 * Search-engine handling for gallery pages.
 *
 * Mirroring gives every pet its own public URL. On a site with a few hundred
 * adoptions that is a few hundred thin pages — one photo plus a breed, gender
 * and reference number — and the site is already signalling that an individual
 * sold pet is not an index destination: a sold pet's product URL 301s to its
 * breed archive. So the default keeps the pet pages out of the index and out of
 * the sitemap, while /pet-gallery/ itself stays indexable and is the page that
 * ranks.
 *
 * Deliberately NOT done here: filtering `petland_indexation_policy`. That hook
 * exists only in the `petland` theme and not in the petland-florida chain that
 * several of these sites actually run, so relying on it would be a silent no-op
 * exactly where it matters.
 *
 * @package WooCommerce Pet Gallery
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wcpk_gallery_seo_mode' ) ) {
	/**
	 * @return string hide_pages|hide_pages_and_images|index_all
	 */
	function wcpk_gallery_seo_mode() {
		$mode = (string) get_option( 'petkey_gallery_seo_mode', 'hide_pages' );

		return in_array( $mode, wcpk_gallery_seo_modes(), true ) ? $mode : 'hide_pages';
	}
}

if ( ! function_exists( 'wcpk_gallery_is_filtered_request' ) ) {
	/**
	 * A gallery view narrowed by the no-JS filter form, or a page past the
	 * first. Each distinct combination is its own URL and its own page-cache
	 * entry, and none of them is a page anyone should land on from search.
	 *
	 * @return bool
	 */
	function wcpk_gallery_is_filtered_request() {

		foreach ( array( 'pg_type', 'pg_breed', 'pg_gender' ) as $key ) {
			if ( isset( $_GET[ $key ] ) && 'ALL' !== $_GET[ $key ] && '' !== $_GET[ $key ] ) {
				return true;
			}
		}

		if ( isset( $_GET['pg_page'] ) && (int) $_GET['pg_page'] > 1 ) {
			return true;
		}

		return is_paged() && ( is_post_type_archive( 'pet-gallery' ) || is_singular( 'pet-gallery' ) );
	}
}

if ( ! function_exists( 'wcpk_gallery_wants_noindex' ) ) {
	/**
	 * @return bool
	 */
	function wcpk_gallery_wants_noindex() {

		if ( is_singular( 'pet-gallery' ) ) {
			return 'index_all' !== wcpk_gallery_seo_mode();
		}

		if ( is_post_type_archive( 'pet-gallery' ) ) {
			// The archive itself stays indexable; only its filtered and
			// paginated variants are held back.
			return wcpk_gallery_is_filtered_request();
		}

		return false;
	}
}

if ( ! function_exists( 'wcpk_gallery_filter_robots' ) ) {
	/**
	 * Core's robots meta (WP 5.7+).
	 *
	 * @param array $robots
	 * @return array
	 */
	function wcpk_gallery_filter_robots( $robots ) {

		if ( ! wcpk_gallery_wants_noindex() ) {
			return $robots;
		}

		/*
		 * A site-wide "Discourage search engines" (Settings → Reading) is a
		 * deliberate decision that outranks ours — typically a staging or dev
		 * site. WP has already set noindex AND nofollow there; weakening that
		 * nofollow into follow would be us overriding an explicit choice.
		 */
		if ( ! get_option( 'blog_public' ) ) {
			return $robots;
		}

		$robots['noindex'] = true;
		$robots['follow']  = true;

		/*
		 * Both opposites must go. Leaving `nofollow` in place while adding
		 * `follow` emits `noindex, nofollow, follow` — a self-contradicting
		 * directive that a crawler resolves however it likes.
		 */
		unset( $robots['index'], $robots['nofollow'] );

		if ( is_singular( 'pet-gallery' ) && 'hide_pages_and_images' === wcpk_gallery_seo_mode() ) {
			$robots['noimageindex'] = true;
			unset( $robots['imageindex'] );
		}

		return $robots;
	}
}
add_filter( 'wp_robots', 'wcpk_gallery_filter_robots', 20 );

if ( ! function_exists( 'wcpk_gallery_filter_yoast_robots' ) ) {
	/**
	 * Yoast replaces core's robots output entirely, so the same decision has to
	 * be expressed again through its own filter or it is simply discarded.
	 *
	 * @param array $robots
	 * @return array
	 */
	function wcpk_gallery_filter_yoast_robots( $robots ) {

		if ( ! is_array( $robots ) || ! wcpk_gallery_wants_noindex() ) {
			return $robots;
		}

		// Same deference as the core filter: don't argue with a site-wide
		// discouragement.
		if ( ! get_option( 'blog_public' ) ) {
			return $robots;
		}

		$robots['index']  = 'noindex';
		$robots['follow'] = 'follow';

		if ( is_singular( 'pet-gallery' ) && 'hide_pages_and_images' === wcpk_gallery_seo_mode() ) {
			$robots['noimageindex'] = 'noimageindex';
		}

		return $robots;
	}
}
add_filter( 'wpseo_robots_array', 'wcpk_gallery_filter_yoast_robots', 20 );

if ( ! function_exists( 'wcpk_gallery_filter_sitemap_post_type' ) ) {
	/**
	 * Keep the pet pages out of the Yoast sitemap when they are noindexed.
	 * Yoast would otherwise list URLs it is simultaneously telling crawlers to
	 * ignore.
	 *
	 * @param bool   $excluded
	 * @param string $post_type
	 * @return bool
	 */
	function wcpk_gallery_filter_sitemap_post_type( $excluded, $post_type ) {

		if ( 'pet-gallery' !== $post_type ) {
			return $excluded;
		}

		return ( 'index_all' !== wcpk_gallery_seo_mode() ) ? true : $excluded;
	}
}
add_filter( 'wpseo_sitemap_exclude_post_type', 'wcpk_gallery_filter_sitemap_post_type', 20, 2 );

if ( ! function_exists( 'wcpk_gallery_filter_core_sitemap' ) ) {
	/**
	 * The same exclusion for WordPress's built-in sitemaps.
	 *
	 * Filtering only Yoast's would leave the pet pages listed in
	 * wp-sitemap.xml on every site that does not run Yoast, while the settings
	 * copy promises they are kept out of the sitemap. Core applies this in
	 * WP_Sitemaps_Posts, so one unset removes the provider, the index entry and
	 * the URL lists together.
	 *
	 * @param array $post_types
	 * @return array
	 */
	function wcpk_gallery_filter_core_sitemap( $post_types ) {

		if ( 'index_all' !== wcpk_gallery_seo_mode() ) {
			unset( $post_types['pet-gallery'] );
		}

		return $post_types;
	}
}
add_filter( 'wp_sitemaps_post_types', 'wcpk_gallery_filter_core_sitemap', 20 );

if ( ! function_exists( 'wcpk_gallery_canonical_for_filtered_views' ) ) {
	/**
	 * Point every filtered or paginated archive view at the plain archive, so
	 * crawl weight consolidates there rather than scattering across query
	 * strings.
	 *
	 * @param string $canonical
	 * @return string
	 */
	function wcpk_gallery_canonical_for_filtered_views( $canonical ) {

		if ( ! is_post_type_archive( 'pet-gallery' ) || ! wcpk_gallery_is_filtered_request() ) {
			return $canonical;
		}

		$archive = get_post_type_archive_link( 'pet-gallery' );

		return $archive ? $archive : $canonical;
	}
}
add_filter( 'wpseo_canonical', 'wcpk_gallery_canonical_for_filtered_views', 20 );

if ( ! function_exists( 'wcpk_gallery_print_canonical' ) ) {
	/**
	 * The same canonical for sites without Yoast. Core prints a canonical for
	 * singular views only, never for a post-type archive.
	 */
	function wcpk_gallery_print_canonical() {

		// Any SEO plugin that prints its own canonical owns the tag — a second
		// one is worse than none.
		if ( defined( 'WPSEO_VERSION' )
			|| defined( 'RANK_MATH_VERSION' )
			|| defined( 'AIOSEO_VERSION' )
			|| defined( 'SEOPRESS_VERSION' )
			|| defined( 'SLIM_SEO_VERSION' ) ) {
			return;
		}

		if ( ! is_post_type_archive( 'pet-gallery' ) || ! wcpk_gallery_is_filtered_request() ) {
			return;
		}

		$archive = get_post_type_archive_link( 'pet-gallery' );

		if ( $archive ) {
			printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $archive ) );
		}
	}
}
add_action( 'wp_head', 'wcpk_gallery_print_canonical', 9 );
