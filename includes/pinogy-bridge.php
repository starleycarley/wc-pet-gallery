<?php
/**
 * Pinogy parent bridge for WooCommerce Pet Gallery.
 *
 * Loaded only when WCPK_GALLERY_PARENT_MODE === 'pinogy'. Wires the
 * WooCommerce order lifecycle to the gallery move/rollback paths, and
 * broadens the cron status filter to treat pinogy's 'pending' as a
 * candidate status for the safety-net cron sweep.
 *
 * Pinogy stores its pet payload on the WC product as `pet_Datadetail` with
 * the same shape petkey uses, so the existing
 * `create_gallery_post_from_product()` path in functions.php works as-is.
 * The bridge just fires it at the right moment.
 *
 * Hook priority is intentional: pinogy's own status-flip handlers at
 * wc-pinogy-sync/wc-pinogy-sync.php:102-105 register at the WP default
 * priority of 10 (no explicit priority is passed). The bridge registers at
 * priority 20 so pinogy's flip to 'pending' runs first and the bridge then
 * overwrites the status to 'gallery_moved'.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Broaden the cron's product-status filter so pinogy's 'pending' is also
// considered. Petkey/UKM mode keeps the default 'unavailable' on its own.
add_filter( 'wcpk_gallery_pending_product_statuses', function ( $statuses ) {
	$statuses = (array) $statuses;
	if ( ! in_array( 'pending', $statuses, true ) ) {
		$statuses[] = 'pending';
	}
	return $statuses;
} );

add_action( 'woocommerce_order_status_processing', 'wcpk_gallery_pinogy_move_on_order', 20, 1 );
add_action( 'woocommerce_order_status_completed',  'wcpk_gallery_pinogy_move_on_order', 20, 1 );

add_action( 'woocommerce_order_status_cancelled', 'wcpk_gallery_pinogy_rollback_on_order', 20, 1 );
add_action( 'woocommerce_order_status_refunded',  'wcpk_gallery_pinogy_rollback_on_order', 20, 1 );
add_action( 'woocommerce_order_status_failed',    'wcpk_gallery_pinogy_rollback_on_order', 20, 1 );

if ( ! function_exists( 'wcpk_gallery_pinogy_move_on_order' ) ) {
	/**
	 * Move each pinogy-sourced pet in the order to the pet-gallery CPT.
	 * Idempotent: skips any product whose SKU already maps to an existing
	 * pet-gallery post. Non-pet line items (accessories, food) are skipped
	 * by the missing-`pet_Datadetail`-meta guard.
	 */
	function wcpk_gallery_pinogy_move_on_order( $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) || ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		global $petkey_gallery_functions;
		if ( ! isset( $petkey_gallery_functions ) || ! method_exists( $petkey_gallery_functions, 'add_to_gallery_for_product' ) ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			$product_id = (int) $item->get_product_id();
			if ( $product_id <= 0 ) {
				continue;
			}

			$pet_data = get_post_meta( $product_id, 'pet_Datadetail', true );
			if ( empty( $pet_data ) ) {
				continue;
			}

			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				continue;
			}

			$sku = (string) $product->get_sku();
			if ( $sku === '' ) {
				continue;
			}

			if ( wcpk_gallery_pinogy_find_gallery_post_by_sku( $sku ) ) {
				continue;
			}

			update_post_meta( $product_id, 'pk_pet_status_changed', time() );

			$petkey_gallery_functions->add_to_gallery_for_product( $product_id );
		}
	}
}

if ( ! function_exists( 'wcpk_gallery_pinogy_rollback_on_order' ) ) {
	/**
	 * Reverse the move: trash the pet-gallery post, then revert the source
	 * product to 'publish' but only if its current status is still
	 * 'gallery_moved'. If another piece of code (admin, pinogy resync) has
	 * already changed it, leave it alone.
	 */
	function wcpk_gallery_pinogy_rollback_on_order( $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) || ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			$product_id = (int) $item->get_product_id();
			if ( $product_id <= 0 ) {
				continue;
			}

			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				continue;
			}

			$sku = (string) $product->get_sku();
			if ( $sku === '' ) {
				continue;
			}

			$gallery_post_id = wcpk_gallery_pinogy_find_gallery_post_by_sku( $sku );
			if ( $gallery_post_id ) {
				wp_trash_post( $gallery_post_id );
			}

			if ( get_post_status( $product_id ) === 'gallery_moved' ) {
				wp_update_post( array(
					'ID'          => $product_id,
					'post_status' => 'publish',
				) );
			}
		}
	}
}

if ( ! function_exists( 'wcpk_gallery_pinogy_find_gallery_post_by_sku' ) ) {
	/**
	 * Return the pet-gallery post ID whose `petkey_gallery_ID` meta matches
	 * the given SKU, or 0 if none. Uses a meta_query (no SQL string
	 * interpolation).
	 */
	function wcpk_gallery_pinogy_find_gallery_post_by_sku( $sku ) {
		$sku = (string) $sku;
		if ( $sku === '' ) {
			return 0;
		}

		$found = get_posts( array(
			'post_type'        => 'pet-gallery',
			'post_status'      => 'any',
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			'meta_key'         => 'petkey_gallery_ID',
			'meta_value'       => $sku,
			'suppress_filters' => false,
		) );

		return ! empty( $found ) ? (int) $found[0] : 0;
	}
}
