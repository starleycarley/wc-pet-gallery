<?php
/**
 * One query builder and one card renderer for every surface that lists pets.
 *
 * Before this file there were four separate, slightly different queries (the
 * AJAX handler, get_gallery_by_breed(), the widget, and the related grid on the
 * single template) and two separate card renderers — templates/card.php, which
 * nothing ever included, and buildCardHtml() in gallery.js, which did all the
 * real work. Keeping two renderers in sync by hand is how card.php drifted into
 * being dead code, so there is now exactly one: card.php. The JS renders from
 * server-built HTML and only falls back to building its own markup when talking
 * to an older endpoint.
 *
 * @package WooCommerce Pet Gallery
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wcpk_gallery_max_per_page' ) ) {
	function wcpk_gallery_max_per_page() {
		return (int) apply_filters( 'wcpk_gallery_max_per_page', 120 );
	}
}

if ( ! function_exists( 'wcpk_gallery_default_per_page' ) ) {
	/**
	 * The live page size. `petkey_gallery_numposts` has carried three different
	 * defaults in three different files (36 in the admin, 12 in the options
	 * reader, 36 in the AJAX handler); 12 is the one actually in force today,
	 * because the options reader coerces anything < 1 before the others see it.
	 * Declaring it once here changes no site's behaviour.
	 */
	function wcpk_gallery_default_per_page() {
		$opts  = function_exists( 'wcpk_gallery_get_options' ) ? wcpk_gallery_get_options() : array();
		$count = isset( $opts['count'] ) ? (int) $opts['count'] : 0;

		return $count > 0 ? $count : 12;
	}
}

if ( ! function_exists( 'wcpk_gallery_cat_all_behavior' ) ) {
	/**
	 * What the "All Pets" view means for the petgallery_cat taxonomy.
	 *
	 * Historically the ALL view applied `NOT IN` against *every* term, so
	 * putting a pet in any category removed it from the default view — almost
	 * certainly not what anyone intended, and invisible until you noticed pets
	 * missing. The default is now `include`; `exclude_categorised` restores the
	 * old behaviour for any site that was relying on hide-by-categorising.
	 *
	 * @return string 'include'|'exclude_categorised'
	 */
	function wcpk_gallery_cat_all_behavior() {
		$value = (string) get_option( 'petkey_gallery_cat_all_behavior', 'include' );

		return ( 'exclude_categorised' === $value ) ? 'exclude_categorised' : 'include';
	}
}

if ( ! function_exists( 'wcpk_gallery_hidden_cat_term_ids' ) ) {
	/**
	 * A reserved bucket that is always excluded, so "hide this pet" has a
	 * deliberate mechanism that does not depend on the ALL-view semantics.
	 *
	 * @return int[]
	 */
	function wcpk_gallery_hidden_cat_term_ids() {
		static $ids = null;

		if ( $ids !== null ) {
			return $ids;
		}

		$slugs = (array) apply_filters( 'wcpk_gallery_excluded_cat_slugs', array( 'gallery-hidden' ) );
		$ids   = array();

		foreach ( $slugs as $slug ) {
			$term = get_term_by( 'slug', $slug, 'petgallery_cat' );

			if ( $term && ! is_wp_error( $term ) ) {
				$ids[] = (int) $term->term_id;
			}
		}

		return $ids;
	}
}

if ( ! function_exists( 'wcpk_gallery_sanitize_filters' ) ) {
	/**
	 * Normalise a raw filter set from $_POST, $_GET or a shortcode into the one
	 * shape every caller uses.
	 *
	 * @param array $raw
	 * @return array{type:string,breed:string,gender:string,page:int,per_page:int}
	 */
	function wcpk_gallery_sanitize_filters( $raw ) {

		$raw = is_array( $raw ) ? $raw : array();

		$get = static function ( $key, $default = 'ALL' ) use ( $raw ) {
			if ( ! isset( $raw[ $key ] ) || '' === $raw[ $key ] ) {
				return $default;
			}
			return sanitize_text_field( wp_unslash( (string) $raw[ $key ] ) );
		};

		$per_page = isset( $raw['per_page'] ) ? (int) $raw['per_page'] : 0;
		if ( $per_page < 1 ) {
			$per_page = wcpk_gallery_default_per_page();
		}

		return array(
			'type'     => $get( 'type' ),
			'breed'    => $get( 'breed' ),
			'gender'   => $get( 'gender' ),
			'page'     => isset( $raw['page'] ) ? max( 1, (int) $raw['page'] ) : 1,
			'per_page' => min( wcpk_gallery_max_per_page(), $per_page ),
		);
	}
}

if ( ! function_exists( 'wcpk_gallery_build_query_args' ) ) {
	/**
	 * WP_Query args for one page of pets.
	 *
	 * Two details here are not stylistic:
	 *
	 * 1. `offset`, never `paged`. We over-fetch one row to learn `has_more`
	 *    without a COUNT query, and WP computes a paged offset from
	 *    posts_per_page — so `posts_per_page => $per + 1` together with
	 *    `paged => N` makes the offset ($per + 1) * (N - 1) and skips exactly
	 *    one pet at every page boundary. See WP_Query::get_posts(), which does
	 *    `$page * $q['posts_per_page'] - $q['posts_per_page']`.
	 *
	 * 2. A tiebreaker on ID. A backfill creates thousands of posts whose
	 *    post_date is identical, and MySQL's row order under LIMIT/OFFSET is
	 *    not stable for ties — without the tiebreaker, pets duplicate on one
	 *    page and vanish from another.
	 *
	 * @param array $f A sanitized filter set.
	 * @return array
	 */
	function wcpk_gallery_build_query_args( $f ) {

		$f = wcpk_gallery_sanitize_filters( $f );

		$args = array(
			'post_type'              => 'pet-gallery',
			'post_status'            => 'publish',
			'posts_per_page'         => $f['per_page'] + 1,
			'offset'                 => ( $f['page'] - 1 ) * $f['per_page'],
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
			'orderby'                => array(
				'date' => 'DESC',
				'ID'   => 'DESC',
			),
		);

		$meta_query = array();

		if ( 'ALL' !== $f['breed'] ) {
			$meta_query[] = wcpk_gallery_meta_or_clause( 'breed_name', $f['breed'] );
		}

		if ( 'ALL' !== $f['gender'] ) {
			$meta_query[] = wcpk_gallery_meta_or_clause( 'gender', $f['gender'] );
		}

		// The pet-type filter, in SQL rather than in a post-LIMIT PHP loop.
		$type_clause = wcpk_gallery_allowed_types_clause();
		if ( null !== $type_clause ) {
			$meta_query[] = $type_clause;
		}

		if ( ! empty( $meta_query ) ) {
			if ( count( $meta_query ) > 1 ) {
				$meta_query['relation'] = 'AND';
			}
			$args['meta_query'] = $meta_query;
		}

		$tax_query = array();

		if ( 'ALL' === $f['type'] ) {

			if ( 'exclude_categorised' === wcpk_gallery_cat_all_behavior() ) {
				$all_terms = get_terms(
					array(
						'taxonomy'   => 'petgallery_cat',
						'hide_empty' => false,
						'fields'     => 'ids',
					)
				);

				if ( ! is_wp_error( $all_terms ) && ! empty( $all_terms ) ) {
					$tax_query[] = array(
						'taxonomy' => 'petgallery_cat',
						'field'    => 'term_id',
						'terms'    => array_map( 'intval', $all_terms ),
						'operator' => 'NOT IN',
					);
				}
			} else {
				$hidden = wcpk_gallery_hidden_cat_term_ids();

				if ( ! empty( $hidden ) ) {
					$tax_query[] = array(
						'taxonomy' => 'petgallery_cat',
						'field'    => 'term_id',
						'terms'    => $hidden,
						'operator' => 'NOT IN',
					);
				}
			}
		} else {
			$tax_query[] = array(
				'taxonomy' => 'petgallery_cat',
				'field'    => 'term_id',
				'terms'    => (int) $f['type'],
			);
		}

		if ( ! empty( $tax_query ) ) {
			$args['tax_query'] = $tax_query;
		}

		return (array) apply_filters( 'wcpk_gallery_query_args', $args, $f );
	}
}

if ( ! function_exists( 'wcpk_gallery_query_page' ) ) {
	/**
	 * Run one page of the gallery query and prime the caches the cards need.
	 *
	 * @param array $f
	 * @return array{posts:WP_Post[],has_more:bool,page:int,per_page:int}
	 */
	function wcpk_gallery_query_page( $f ) {

		$f    = wcpk_gallery_sanitize_filters( $f );
		$args = wcpk_gallery_build_query_args( $f );

		$query = new WP_Query( $args );
		$posts = $query->posts;

		// The over-fetched row answers "is there another page?" and is then
		// discarded. Note $f['per_page'], not $args['posts_per_page'] - 1: a
		// third party filtering the args must not be able to corrupt the
		// arithmetic.
		$has_more = count( $posts ) > $f['per_page'];

		if ( $has_more ) {
			$posts = array_slice( $posts, 0, $f['per_page'] );
		}

		if ( ! empty( $posts ) ) {
			$ids = wp_list_pluck( $posts, 'ID' );
			update_postmeta_cache( $ids );

			// Primes the attachment posts behind _thumbnail_id. _prime_post_caches()
			// would not: those post IDs are already cached, and it does not look
			// at thumbnails at all.
			$thumb_query        = new WP_Query();
			$thumb_query->posts = $posts;
			update_post_thumbnail_cache( $thumb_query );
		}

		return array(
			'posts'    => $posts,
			'has_more' => $has_more,
			'page'     => $f['page'],
			'per_page' => $f['per_page'],
		);
	}
}

if ( ! function_exists( 'wcpk_gallery_pet_payload' ) ) {
	/**
	 * The per-pet JSON shape the front-end script consumes. Kept for the
	 * legacy client path and for themes that call the endpoint directly.
	 *
	 * @param WP_Post|int $post
	 * @return array
	 */
	function wcpk_gallery_pet_payload( $post ) {

		$post    = get_post( $post );
		$post_id = $post ? (int) $post->ID : 0;

		if ( ! $post_id ) {
			return array();
		}

		return array(
			'petid'        => wcpk_gallery_get_pet_field( $post_id, 'petid' ),
			'name'         => wcpk_gallery_get_pet_field( $post_id, 'name' ),
			'age'          => wcpk_gallery_get_pet_field( $post_id, 'age' ),
			'animal_type'  => wcpk_gallery_get_pet_field( $post_id, 'animal_type' ),
			'breed_name'   => wcpk_gallery_get_pet_field( $post_id, 'breed_name' ),
			'coloring'     => wcpk_gallery_get_pet_field( $post_id, 'coloring' ),
			'gender'       => wcpk_gallery_get_pet_field( $post_id, 'gender' ),
			'reference_id' => wcpk_gallery_get_pet_field( $post_id, 'reference_id' ),
			'location'     => wcpk_gallery_get_pet_field( $post_id, 'location' ),
			'post_image'   => get_the_post_thumbnail_url( $post_id, 'medium_large' ),
			'post_id'      => $post_id,
			'post_title'   => $post->post_title,
			'post_link'    => get_permalink( $post_id ),
			// card.php stamps the adoption month on the sticker; the JS
			// renderer had no value to print and silently omitted it, so a
			// server-rendered card and an appended one did not match.
			'adopted_on'   => get_the_date( 'F Y', $post_id ),
		);
	}
}

if ( ! function_exists( 'wcpk_gallery_render_cards' ) ) {
	/**
	 * Render a set of pets as card markup, using templates/card.php — which a
	 * theme may override, and which is therefore arbitrary third-party code
	 * running inside the_content() after the header has already been flushed.
	 * A fatal there would leave a half-rendered white page, so each card is
	 * individually guarded and a broken one is simply skipped.
	 *
	 * @param WP_Post[]|int[] $posts
	 * @param object|null     $resolver Form resolver; built on demand when omitted.
	 * @param array|null      $opts
	 * @return string
	 */
	function wcpk_gallery_render_cards( $posts, $resolver = null, $opts = null ) {

		if ( empty( $posts ) ) {
			return '';
		}

		if ( null === $opts ) {
			$opts = function_exists( 'wcpk_gallery_get_options' ) ? wcpk_gallery_get_options() : array();
		}

		// Built once per render pass, not per card: the constructor resolves the
		// whole lead-capture chain (theme modal -> GF/CF7/WPForms -> page link).
		if ( null === $resolver && class_exists( 'WCPK_Gallery_Form_Resolver' ) ) {
			static $shared = null;

			if ( null === $shared ) {
				$shared = new WCPK_Gallery_Form_Resolver();
			}

			$resolver = $shared;
		}

		$html = '';

		foreach ( $posts as $post ) {
			$post_id = ( $post instanceof WP_Post ) ? (int) $post->ID : (int) $post;

			if ( $post_id <= 0 ) {
				continue;
			}

			// Cheap: the meta is already primed, so this is a cache read unless
			// the post genuinely has no code yet.
			if ( function_exists( 'wcpk_gallery_maybe_stamp_type_code' ) ) {
				wcpk_gallery_maybe_stamp_type_code( $post_id );
			}

			// wcpk_gallery_render_template() buffers internally and returns the
			// markup, so there is no output buffer of our own to unwind here —
			// but a throw inside the template leaves *its* buffer open, which
			// would otherwise swallow the rest of the page.
			$depth = ob_get_level();

			try {
				$card = wcpk_gallery_render_template(
					'card',
					array(
						'post_id'  => $post_id,
						'resolver' => $resolver,
						'opts'     => $opts,
					)
				);
			} catch ( \Throwable $e ) {
				while ( ob_get_level() > $depth ) {
					ob_end_clean();
				}

				$card = '';

				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( sprintf( '[wc-pet-gallery] card %d failed to render: %s', $post_id, $e->getMessage() ) );
				}
			}

			$html .= (string) apply_filters( 'wcpk_gallery_card_html', $card, $post_id, $opts );
		}

		return $html;
	}
}
