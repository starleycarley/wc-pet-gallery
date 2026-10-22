<?php
/**
 * Safety net: register the product statuses this plugin depends on, when the
 * parent sync plugin does not.
 *
 * Why this is necessary
 * --------------------
 * The gallery writes `gallery_moved` onto a source product (petkey and pinogy
 * paths) and queries `unavailable` as its default pending status — but it has
 * never registered either, and neither does wc-pinogy-sync, which has no
 * register_post_status call anywhere. wc-petkey-sync registers both
 * (includes/wc-product-statuses.php:42,52) and so does wc-unified-km, so the
 * gap is specific to pinogy-only sites.
 *
 * An unregistered post_status is not merely cosmetic. Three concrete
 * consequences, all verified against WP core:
 *
 *  1. A WP_Query asking for ONLY unregistered statuses emits no status clause
 *     at all — WP_Query::get_posts() builds `$statuswheres` solely from
 *     statuses present in get_post_stati(). The query then silently widens to
 *     every status, trash and drafts included. The gallery dodges this today
 *     only because the pinogy bridge happens to append the registered
 *     'pending' to the same filter; override that filter and the promotion
 *     sweep would start scanning the entire catalogue.
 *  2. The product vanishes from wp-admin → Products with no status-filter link
 *     to find it again, because those views are built from registered stati.
 *  3. The status dropdown on the edit screen has no matching option, so
 *     opening and saving the product silently rewrites its status.
 *
 * Registered at priority 101 — after wc-petkey-sync and wc-unified-km (both
 * priority 100) — and only when the status is genuinely absent, so a parent
 * plugin's own definition always wins.
 *
 * @package WooCommerce Pet Gallery
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wcpk_gallery_register_fallback_statuses' ) ) {
	/**
	 * Labels mirror wc-petkey-sync's, so a site that later installs it sees no
	 * change. `public => false` keeps these pets out of the shop, which is the
	 * whole point of moving them to the gallery.
	 */
	function wcpk_gallery_register_fallback_statuses() {

		$statuses = array(
			'gallery_moved' => __( 'Moved to Gallery', 'petkey-gallery' ),
			'unavailable'   => __( 'Removed', 'petkey-gallery' ),
		);

		/**
		 * Filter the statuses the gallery will register when absent. Return an
		 * empty array to opt out entirely.
		 */
		$statuses = (array) apply_filters( 'wcpk_gallery_fallback_statuses', $statuses );

		foreach ( $statuses as $slug => $label ) {

			// The parent sync plugin's definition always wins.
			if ( get_post_status_object( $slug ) ) {
				continue;
			}

			register_post_status(
				$slug,
				array(
					'label'                     => $label,
					'public'                    => false,
					'exclude_from_search'       => false,
					'show_in_admin_all_list'    => false,
					'show_in_admin_status_list' => true,
					'post_type'                 => array( 'product' ),
					'label_count'               => _n_noop(
						$label . ' <span class="count">(%s)</span>',
						$label . ' <span class="count">(%s)</span>',
						'petkey-gallery'
					),
				)
			);
		}
	}
}
add_action( 'init', 'wcpk_gallery_register_fallback_statuses', 101 );

if ( ! function_exists( 'wcpk_gallery_registered_statuses_only' ) ) {
	/**
	 * Drop unregistered statuses from a query list, and signal when nothing is
	 * left — because asking WP_Query for only-unregistered statuses is the same
	 * as asking for all of them.
	 *
	 * Belt and braces alongside the registration above: a site can filter these
	 * statuses away, and a silent full-catalogue scan is not an acceptable
	 * failure mode for a sweep that moves products.
	 *
	 * @param string[] $statuses
	 * @return string[] Possibly empty — callers must treat that as "run nothing".
	 */
	function wcpk_gallery_registered_statuses_only( $statuses ) {

		$kept = array();

		foreach ( (array) $statuses as $status ) {
			if ( get_post_status_object( $status ) ) {
				$kept[] = $status;
			}
		}

		return $kept;
	}
}
