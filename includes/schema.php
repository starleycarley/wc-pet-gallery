<?php
/**
 * Pet-type schema: one canonical code per gallery post.
 *
 * Why this file exists
 * --------------------
 * The "Pet types to show" setting used to be enforced in PHP, *after* the SQL
 * LIMIT had already been applied (functions.php), which produced short pages and
 * a Load-more button that never terminated. It also compared the stored option's
 * *values* against the raw `animal_type` meta, and one save path wrote labels
 * ("Dogs") where every other path wrote codes ("DOG") — so a single save with no
 * box ticked silently emptied the entire grid.
 *
 * The fix is a canonical `_wcpk_type_code` meta on every gallery post, filtered
 * in SQL. The code is one of DOG | CAT | BIRD | REPTILE | OTHER, derived from
 * whichever schema actually wrote the post:
 *
 *   petkey : `animal_type`  -> 'DOG' (an uppercase code)
 *   ukm    : `_pet_type`    -> 'Dog', 'Rabbit', 'Guinea Pig' (a term NAME)
 *
 * OTHER is the default bucket rather than a negative match, because WP compiles
 * a meta `NOT IN` as an INNER JOIN: a petkey row carrying `animal_type` but no
 * `_pet_type` would be dropped by a negative clause. A positive code avoids that
 * entirely, and the clause still fails OPEN for any post that has not been
 * stamped yet (see wcpk_gallery_allowed_types_clause).
 *
 * @package WooCommerce Pet Gallery
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WCPK_GALLERY_TYPE_META' ) ) {
	define( 'WCPK_GALLERY_TYPE_META', '_wcpk_type_code' );
}

if ( ! function_exists( 'wcpk_gallery_type_taxonomy' ) ) {
	/**
	 * code => tokens that resolve to it. Anything recognisable but unlisted
	 * falls into OTHER, which is why OTHER itself has no token list.
	 *
	 * Tokens are matched lowercase, longest-first, on word boundaries.
	 *
	 * @return array<string, string[]>
	 */
	function wcpk_gallery_type_taxonomy() {
		static $map = null;

		if ( $map !== null ) {
			return $map;
		}

		$map = array(
			'DOG'     => array( 'dog', 'dogs', 'puppy', 'puppies', 'canine' ),
			'CAT'     => array( 'cat', 'cats', 'kitten', 'kittens', 'feline' ),
			'BIRD'    => array(
				'bird', 'birds', 'parrot', 'parakeet', 'cockatiel', 'conure', 'finch',
				'canary', 'dove', 'macaw', 'lovebird', 'cockatoo', 'budgie',
			),
			'REPTILE' => array(
				'reptile', 'reptiles', 'bearded dragon', 'ball python', 'corn snake',
				'leopard gecko', 'snake', 'gecko', 'lizard', 'turtle', 'tortoise',
				'iguana', 'chameleon', 'skink',
			),
		);

		$map = (array) apply_filters( 'wcpk_gallery_type_taxonomy', $map );

		return $map;
	}
}

if ( ! function_exists( 'wcpk_gallery_type_codes' ) ) {
	/**
	 * Every valid code, OTHER included.
	 *
	 * @return string[]
	 */
	function wcpk_gallery_type_codes() {
		return array_merge( array_keys( wcpk_gallery_type_taxonomy() ), array( 'OTHER' ) );
	}
}

if ( ! function_exists( 'wcpk_gallery_classify_type' ) ) {
	/**
	 * Resolve a raw type value to a canonical code.
	 *
	 * Returns '' — NOT 'OTHER' — for an empty input. The distinction matters: a
	 * post with no type information must never be stamped, so that the query
	 * clause's NOT EXISTS leg keeps it visible instead of silently hiding it.
	 *
	 * @param string $raw
	 * @return string One of wcpk_gallery_type_codes(), or '' when unknown.
	 */
	function wcpk_gallery_classify_type( $raw ) {

		$raw = is_scalar( $raw ) ? (string) $raw : '';
		$raw = strtolower( trim( preg_replace( '/\s+/', ' ', $raw ) ) );

		if ( '' === $raw ) {
			return '';
		}

		$taxonomy = wcpk_gallery_type_taxonomy();

		// An explicit code round-trips unchanged.
		$upper = strtoupper( $raw );
		if ( in_array( $upper, wcpk_gallery_type_codes(), true ) ) {
			return $upper;
		}

		// Exact token match first — cheapest and least surprising.
		foreach ( $taxonomy as $code => $tokens ) {
			if ( in_array( $raw, $tokens, true ) ) {
				return $code;
			}
		}

		// Then word-boundary matching, longest token first so "bearded dragon"
		// is tried before "dragon"-like fragments of a shorter token.
		$ranked = array();
		foreach ( $taxonomy as $code => $tokens ) {
			foreach ( $tokens as $token ) {
				$ranked[] = array( 'code' => $code, 'token' => $token, 'len' => strlen( $token ) );
			}
		}

		usort(
			$ranked,
			static function ( $a, $b ) {
				return $b['len'] - $a['len'];
			}
		);

		foreach ( $ranked as $entry ) {
			if ( preg_match( '/\b' . preg_quote( $entry['token'], '/' ) . '\b/', $raw ) ) {
				return $entry['code'];
			}
		}

		// Recognisable as *something*, just not one of the four. Rabbits, guinea
		// pigs, hamsters, ferrets, hedgehogs and chinchillas all land here, which
		// is the bucket the old filter had no way to express.
		return 'OTHER';
	}
}

if ( ! function_exists( 'wcpk_gallery_type_token_to_code' ) ) {
	/**
	 * Normalise one stored option token to a code. Tolerates every historical
	 * shape of `petkey_gallery_types`: a code ('DOG'), a label ('Dogs',
	 * 'Small Animals'), or lowercase.
	 *
	 * @param string $token
	 * @return string Code, or '' when the token means nothing.
	 */
	function wcpk_gallery_type_token_to_code( $token ) {

		$token = is_scalar( $token ) ? trim( (string) $token ) : '';

		if ( '' === $token ) {
			return '';
		}

		// The one label that does not classify on its own wording.
		if ( 0 === strcasecmp( $token, 'small animals' ) || 0 === strcasecmp( $token, 'small animal' ) ) {
			return 'OTHER';
		}

		return wcpk_gallery_classify_type( $token );
	}
}

if ( ! function_exists( 'wcpk_gallery_get_allowed_type_codes' ) ) {
	/**
	 * The codes the gallery is allowed to show.
	 *
	 * An empty array means "show everything" and is the default. The stored
	 * shape is a flat list of codes, but three historical shapes exist in the
	 * wild and all must read correctly:
	 *
	 *   ['DOG' => 'DOG', 'CAT' => 'CAT']     a normal save
	 *   ['DOG' => 'Dogs', 'CAT' => 'Cats']   the save-with-nothing-ticked bug
	 *   ['DOG', 'CAT']                       the shape written from 1.7.0 on
	 *
	 * Keys and values are both mapped and unioned, so every shape resolves to
	 * the same answer without needing the migration to have run first.
	 *
	 * @return string[]
	 */
	function wcpk_gallery_get_allowed_type_codes() {

		$stored = get_option( 'petkey_gallery_types', array() );

		if ( ! is_array( $stored ) || empty( $stored ) ) {
			return array();
		}

		$codes = array();

		foreach ( $stored as $key => $value ) {
			if ( is_string( $key ) ) {
				$code = wcpk_gallery_type_token_to_code( $key );
				if ( '' !== $code ) {
					$codes[ $code ] = true;
				}
			}

			if ( is_scalar( $value ) ) {
				$code = wcpk_gallery_type_token_to_code( $value );
				if ( '' !== $code ) {
					$codes[ $code ] = true;
				}
			}
		}

		$codes = array_keys( $codes );

		// Everything selected is the same as nothing selected, and saying so
		// here means the query clause can be skipped entirely.
		if ( ! array_diff( wcpk_gallery_type_codes(), $codes ) ) {
			return array();
		}

		return $codes;
	}
}

if ( ! function_exists( 'wcpk_gallery_allowed_types_clause' ) ) {
	/**
	 * meta_query clause enforcing the type filter, or null when every type is
	 * allowed and no clause is needed.
	 *
	 * The NOT EXISTS leg is load-bearing. A bare IN clause compiles to an INNER
	 * JOIN, so any post without `_wcpk_type_code` would vanish — and posts do
	 * get created without it: wc-ukm-gallery writes `_pet_type` *after*
	 * wp_insert_post and copies the rest of its meta with raw SQL, so no
	 * save_post hook can stamp them at creation time. Failing open means an
	 * unstamped post is shown rather than silently lost.
	 *
	 * @return array|null
	 */
	function wcpk_gallery_allowed_types_clause() {

		$codes = wcpk_gallery_get_allowed_type_codes();

		if ( empty( $codes ) ) {
			return null;
		}

		return array(
			'relation' => 'OR',
			array(
				'key'     => WCPK_GALLERY_TYPE_META,
				'value'   => $codes,
				'compare' => 'IN',
			),
			array(
				'key'     => WCPK_GALLERY_TYPE_META,
				'compare' => 'NOT EXISTS',
			),
		);
	}
}

if ( ! function_exists( 'wcpk_gallery_read_raw_type' ) ) {
	/**
	 * The post's raw type value, read from both schemas directly.
	 *
	 * Deliberately not wcpk_gallery_get_pet_field(): that routes through the
	 * get_post_metadata compat shim, and this function is called *from* meta
	 * hooks where the extra indirection is both unnecessary and a recursion
	 * risk.
	 *
	 * @param int $post_id
	 * @return string
	 */
	function wcpk_gallery_read_raw_type( $post_id ) {

		foreach ( array( 'animal_type', '_pet_type' ) as $key ) {
			$value = get_post_meta( (int) $post_id, $key, true );

			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return (string) $value;
			}
		}

		return '';
	}
}

if ( ! function_exists( 'wcpk_gallery_stamp_type_code' ) ) {
	/**
	 * Write `_wcpk_type_code` for one post. No-op when the post has no type
	 * information yet — see wcpk_gallery_classify_type() on why '' must not be
	 * stamped.
	 *
	 * @param int  $post_id
	 * @param bool $force Recompute even when a code is already stored.
	 * @return string The stored code, or '' if nothing was written.
	 */
	function wcpk_gallery_stamp_type_code( $post_id, $force = false ) {

		$post_id = (int) $post_id;

		if ( $post_id <= 0 || 'pet-gallery' !== get_post_type( $post_id ) ) {
			return '';
		}

		$existing = (string) get_post_meta( $post_id, WCPK_GALLERY_TYPE_META, true );

		// A stored OTHER is always recomputed: it is the bucket a post lands in
		// when its type could not be resolved, and the alias may have been
		// filled in since.
		if ( ! $force && '' !== $existing && 'OTHER' !== $existing ) {
			return $existing;
		}

		$code = wcpk_gallery_classify_type( wcpk_gallery_read_raw_type( $post_id ) );

		if ( '' === $code ) {
			return '';
		}

		if ( $code !== $existing ) {
			update_post_meta( $post_id, WCPK_GALLERY_TYPE_META, $code );
		}

		return $code;
	}
}

if ( ! function_exists( 'wcpk_gallery_maybe_stamp_type_code' ) ) {
	/**
	 * Lazy stamp, safe to call in a render loop: only does work when the code
	 * is missing or is the provisional OTHER bucket. Meta is already primed by
	 * the query at that point, so the common case is one cache read.
	 *
	 * @param int $post_id
	 */
	function wcpk_gallery_maybe_stamp_type_code( $post_id ) {

		$existing = get_post_meta( (int) $post_id, WCPK_GALLERY_TYPE_META, true );

		if ( '' === $existing || 'OTHER' === $existing ) {
			wcpk_gallery_stamp_type_code( $post_id );
		}
	}
}

/* ------------------------------------------------------------------
 * Stamping hooks — three legs, because no single one covers every
 * creator of a pet-gallery post.
 * ------------------------------------------------------------------ */

if ( ! function_exists( 'wcpk_gallery_stamp_type_on_save' ) ) {
	function wcpk_gallery_stamp_type_on_save( $post_id, $post = null ) {

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( $post instanceof WP_Post && 'auto-draft' === $post->post_status ) {
			return;
		}

		wcpk_gallery_stamp_type_code( $post_id, true );
	}
}
add_action( 'save_post_pet-gallery', 'wcpk_gallery_stamp_type_on_save', 20, 2 );

if ( ! function_exists( 'wcpk_gallery_stamp_type_on_meta' ) ) {
	/**
	 * Covers the creators that write the type meta *after* the post row exists —
	 * notably wc-ukm-gallery, whose set_terms() runs post-insert.
	 */
	function wcpk_gallery_stamp_type_on_meta( $meta_id, $object_id, $meta_key ) {

		// The whitelist already excludes our own key, so this cannot recurse.
		if ( 'animal_type' !== $meta_key && '_pet_type' !== $meta_key ) {
			return;
		}

		wcpk_gallery_stamp_type_code( $object_id, true );
	}
}
add_action( 'added_post_meta', 'wcpk_gallery_stamp_type_on_meta', 20, 3 );
add_action( 'updated_post_meta', 'wcpk_gallery_stamp_type_on_meta', 20, 3 );

if ( ! function_exists( 'wcpk_gallery_backfill_type_codes' ) ) {
	/**
	 * Stamp a batch of existing posts. Picks up posts with no code at all and
	 * posts sitting in the provisional OTHER bucket, so a site whose type meta
	 * arrived late converges instead of staying wrong.
	 *
	 * @param int $limit
	 * @return int Number of posts examined (0 means the backfill is complete).
	 */
	function wcpk_gallery_backfill_type_codes( $limit = 500 ) {

		global $wpdb;

		$limit = max( 1, min( 2000, (int) $limit ) );

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID
				   FROM {$wpdb->posts} p
				   LEFT JOIN {$wpdb->postmeta} tc
				          ON tc.post_id = p.ID AND tc.meta_key = %s
				  WHERE p.post_type = 'pet-gallery'
				    AND p.post_status NOT IN ( 'auto-draft', 'trash' )
				    AND ( tc.post_id IS NULL OR tc.meta_value = 'OTHER' OR tc.meta_value = '' )
				  ORDER BY p.ID DESC
				  LIMIT %d",
				WCPK_GALLERY_TYPE_META,
				$limit
			)
		);

		if ( empty( $ids ) ) {
			update_option( 'wcpk_gallery_typecode_backfill_done', 1, false );
			return 0;
		}

		$ids = array_map( 'intval', $ids );
		update_meta_cache( 'post', $ids );

		foreach ( $ids as $id ) {
			wcpk_gallery_stamp_type_code( $id, true );
		}

		return count( $ids );
	}
}

if ( ! function_exists( 'wcpk_gallery_run_type_backfill' ) ) {
	/**
	 * Cron drain. Re-schedules itself until there is nothing left, so the pass
	 * never runs inline on a page load.
	 */
	function wcpk_gallery_run_type_backfill() {

		if ( get_option( 'wcpk_gallery_typecode_backfill_done' ) ) {
			return;
		}

		$examined = wcpk_gallery_backfill_type_codes( 500 );

		// A short batch means we drained the tail; the done flag is set inside
		// wcpk_gallery_backfill_type_codes() when nothing is left.
		if ( $examined > 0 && ! get_option( 'wcpk_gallery_typecode_backfill_done' ) ) {
			wp_schedule_single_event( time() + 60, 'wcpk_gallery_typecode_backfill' );
		}
	}
}
add_action( 'wcpk_gallery_typecode_backfill', 'wcpk_gallery_run_type_backfill' );
