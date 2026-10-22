<?php
/**
 * The hard constraint, enforced as a test.
 *
 * "The pets I need to keep in sold in UKM need to stay in sold status because
 * they need that for UKM, we cannot change that status for UKM sites."
 *
 * UKM's warranty, certificate, my-account and bulk-transport tools all key off
 * `sold_puppy`. The UKM ingest must therefore never write a product's
 * post_status, and must never route a UKM pet through
 * create_gallery_post_from_product(), whose last act is to flip the source
 * product to `gallery_moved`.
 *
 * This is a source-level check on purpose: it fails the moment someone adds the
 * "obvious" one-line fix, which is exactly when it is most useful.
 *
 * Run: php tests/test-ukm-constraint.php
 */

$root  = dirname( __DIR__ );
$fail  = 0;
$check = static function ( $label, $ok, $detail = '' ) use ( &$fail ) {
	if ( $ok ) {
		echo " ok   {$label}\n";
	} else {
		echo "FAIL  {$label}" . ( $detail ? "\n        {$detail}" : '' ) . "\n";
		$fail++;
	}
};

/**
 * Strip comments and string literals so the checks below look at CODE only.
 * Without this the file's own documentation — which names the very functions it
 * promises not to call — fails every check.
 *
 * @param string $php
 * @return string
 */
function wcpk_code_only( $php ) {
	$out = '';

	foreach ( token_get_all( $php ) as $token ) {
		if ( is_array( $token ) ) {
			if ( in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				$out .= str_repeat( "\n", substr_count( $token[1], "\n" ) );
				continue;
			}

			$out .= $token[1];
			continue;
		}

		$out .= $token;
	}

	return $out;
}

$ingest_raw = file_get_contents( $root . '/includes/ukm-ingest.php' );
$ingest     = wcpk_code_only( $ingest_raw );

/* 1. No product status writes anywhere in the ingest.
 *
 * The ingest legitimately writes gallery-post statuses — untrashing a card,
 * publishing it. What it must never do is write a status on a PRODUCT. The
 * product id is carried in $pet_id throughout, so the precise rule is: no
 * status-writing call may mention $pet_id, and no raw UPDATE may touch
 * $wpdb->posts at all.
 */
$status_writes = array();
$lines         = preg_split( '/\R/', $ingest );

foreach ( $lines as $n => $line ) {

	if ( preg_match( '/\b(wp_update_post|wp_publish_post|wp_insert_post|set_post_status|wp_transition_post_status)\s*\(/', $line ) ) {
		// Look at the whole call, which may span several lines.
		$call = implode( ' ', array_slice( $lines, $n, 8 ) );
		$call = substr( $call, 0, strpos( $call, ');' ) !== false ? strpos( $call, ');' ) + 2 : strlen( $call ) );

		if ( false !== strpos( $call, '$pet_id' ) ) {
			$status_writes[] = ( $n + 1 ) . ': ' . trim( $line );
		}
	}

	// A raw posts-table UPDATE would bypass wp_update_post entirely.
	if ( preg_match( '/->update\(\s*\$wpdb->posts/', $line ) || preg_match( '/UPDATE\s+\S*\$wpdb->posts/i', $line ) ) {
		$status_writes[] = ( $n + 1 ) . ': ' . trim( $line );
	}
}

$check(
	'no status-writing call in the UKM ingest mentions a product id',
	empty( $status_writes ),
	implode( "\n        ", $status_writes )
);

/* 2. It never calls the Petkey promotion function, which ends in that flip. */
$check(
	'the UKM ingest never calls create_gallery_post_from_product()',
	false === strpos( $ingest, 'create_gallery_post_from_product' )
);

$check(
	'the UKM ingest never calls add_to_gallery_for_product()',
	false === strpos( $ingest, 'add_to_gallery_for_product' )
);

/* 3. A source status must never be added to wcpk_gallery_pending_product_statuses.
      That filter feeds the Petkey cron, which promotes via
      create_gallery_post_from_product() — the function that flips the product
      to `gallery_moved`. The pinogy bridge legitimately adds 'pending' there;
      what must never appear is a UKM sold status. */
$all = '';
foreach ( array( '/*.php', '/includes/*.php', '/includes/admin/*.php' ) as $pattern ) {
	foreach ( glob( $root . $pattern ) as $f ) {
		$all .= wcpk_code_only( file_get_contents( $f ) );
	}
}

$bad_filters = array();

if ( preg_match_all( '/add_filter\(\s*[\'"]wcpk_gallery_pending_product_statuses[\'"].{0,400}/s', $all, $matches ) ) {
	foreach ( $matches[0] as $hit ) {
		if ( false !== strpos( $hit, 'sold_puppy' ) ) {
			$bad_filters[] = substr( $hit, 0, 120 );
		}
	}
}

$check(
	'no filter adds a UKM sold status to the Petkey promotion sweep',
	empty( $bad_filters ),
	implode( ' | ', $bad_filters )
);

/* 4. The promotion tools are gated off UKM. */
$settings = file_get_contents( $root . '/settings.php' );
$check(
	'the promotion handlers are guarded',
	substr_count( $settings, 'wcpk_gallery_guard_promotion_request(' ) >= 4,
	'expected the shared guard in the helper plus all three handlers'
);
$check(
	'the guard excludes UKM',
	(bool) preg_match( "/in_array\(\s*\\\$provider,\s*array\(\s*'petkey',\s*'pinogy'\s*\)/", $settings )
);

/* 5. De-mirroring trashes, never force-deletes, and clears BOTH link keys —
      keeping either one poisons both plugins' "already mirrored" guards. */
$demirror = '';
if ( preg_match( '/function demirror_pet.*?\n\t\}/s', $ingest, $m ) ) {
	$demirror = $m[0];
}
$check( 'the de-mirror function was located for inspection', '' !== $demirror );
$check( 'de-mirror uses wp_trash_post', false !== strpos( $demirror, 'wp_trash_post' ) );
$check( 'de-mirror never force-deletes', false === strpos( $demirror, 'wp_delete_post' ) );
$check( 'de-mirror clears our link key', false !== strpos( $demirror, "delete_post_meta( \$pet_id, self::LINK_META )" ) );
$check( 'de-mirror clears ukm_gallery_id too', false !== strpos( $demirror, "delete_post_meta( \$pet_id, 'ukm_gallery_id' )" ) );

/* 5b. Bulk-mode hook restoration must be symmetric. Re-registering a
       hard-coded callback list is how a bare-string `clear_breeds_cache` got
       added for a function that only exists as a bound method — a latent fatal
       on the next pet-gallery save in the request. */
$bulk = '';
if ( preg_match( '/function end_bulk.*?\n\t\}/s', $ingest, $m ) ) {
	$bulk = $m[0];
}
$check( 'end_bulk() was located for inspection', '' !== $bulk );
$check(
	'end_bulk() restores only what begin_bulk() recorded',
	false !== strpos( $bulk, 'self::$detached' )
		&& ! preg_match( "/add_action\(\s*'[a-z_]+'/", $bulk ),
	'end_bulk must not add_action() a hard-coded hook/callback pair'
);

/* 6. petkey_gallery_ID must never be written: the pinogy bridge matches that
      key against a SKU and trashes what it finds. */
$check(
	'the UKM ingest never writes petkey_gallery_ID',
	false === strpos( $ingest, "'petkey_gallery_ID'" )
);

/* 7. Both meta schemas are written, or the petland-florida archive filter —
      which uses a pre_get_posts meta_query and raw SQL on breed_name/gender/
      animal_type — cannot see these posts. */
foreach ( array( 'breed_name', 'animal_type', 'gender', '_breed', '_pet_type' ) as $key ) {
	$check( "write_meta() writes '{$key}'", false !== strpos( $ingest, "'{$key}'" ) );
}

/* 8. Guards added after the 1.7.1 pre-flight review. Each of these closed a
      path that could reach a product's post_status, or publish a duplicate
      card, on a site that wc-ukm-gallery populated and then left behind. */

/* 8a. Net 2 reads an id out of stored meta. Nets 3 and 4 pin post_type in SQL;
       this one did not, so a stale pointer naming a PRODUCT would be adopted,
       stamped with our back-pointer, and later trashed by the de-mirror. */
$check(
	'net 2 adopts only a real pet-gallery post',
	false !== strpos( $ingest, "'pet-gallery' === get_post_type( \$theirs )" ),
	'ukm_gallery_id may name any post type; without this a product can be adopted'
);

/* 8b. Defence in depth at the only place a stored id reaches a post-row write. */
$check(
	'de-mirror refuses to trash a non-gallery post',
	false !== strpos( $demirror, "'pet-gallery' !== get_post_type( \$gallery_id )" ),
	'wp_trash_post() on a product is a product post_status write'
);
$pos_guard = strpos( $demirror, 'get_post_type( $gallery_id )' );
$pos_trash = strpos( $demirror, 'wp_trash_post' );
$check(
	'the de-mirror post_type guard runs BEFORE the trash',
	false !== $pos_guard && false !== $pos_trash && $pos_guard < $pos_trash
);

/* 8c. No duplicate cards. wc-ukm-gallery derived its slug exactly as we do, so
       a legacy card whose pet-side pointer was lost is invisible to all four
       nets and WordPress would silently publish `<slug>-2`. */
$check(
	'a slug lookup exists',
	false !== strpos( $ingest, 'function find_gallery_by_slug' )
);
$slug_fn = '';
if ( preg_match( '/function find_gallery_by_slug.*?\n\t\}/s', $ingest, $m ) ) {
	$slug_fn = $m[0];
}
$check(
	'the slug lookup pins post_type and skips trash',
	false !== strpos( $slug_fn, "post_type = 'pet-gallery'" )
		&& false !== strpos( $slug_fn, "NOT IN ( 'trash', 'auto-draft' )" )
);
$pos_slug   = strpos( $ingest, 'self::find_gallery_by_slug(' );
$pos_insert = strpos( $ingest, 'wp_insert_post(' );
$check(
	'the slug guard runs BEFORE the insert',
	false !== $pos_slug && false !== $pos_insert && $pos_slug < $pos_insert,
	'otherwise WordPress appends -2 and publishes a second card for one animal'
);

/* 8d. The single-pet async paths must suppress purges the way the batch paths
       do. The theme's transition_post_status handler constructs a fresh
       Kinsta\Cache_Purge, which defeats Kinsta's own per-request guard, so an
       unwrapped insert costs a wp_cache_flush() plus an opcache_reset(). */
foreach ( array( 'mirror_one_async', 'demirror_one_async' ) as $fn ) {
	$body = '';
	if ( preg_match( '/function ' . $fn . '.*?\n\t\}/s', $ingest, $m ) ) {
		$body = $m[0];
	}
	$check( "{$fn}() was located for inspection", '' !== $body );
	$check(
		"{$fn}() suppresses purges with begin_bulk()",
		false !== strpos( $body, 'self::begin_bulk()' )
			&& false !== strpos( $body, 'self::end_bulk()' )
			&& false !== strpos( $body, 'finally' )
	);
}

/* 9. Action Scheduler resolves callbacks with has_action() inside whatever
      request drains the queue (wp-cron.php, or its admin-ajax loopback). While
      the ingest was required only when the detected mode was 'ukm', every
      queued batch on a live site failed with "no callbacks are registered" and
      the recurring scan was never scheduled. Registration must be
      unconditional; behaviour stays gated on enabled(). */
$main = wcpk_code_only( file_get_contents( $root . '/wc-pet-gallery.php' ) );

$check(
	'the UKM ingest is required unconditionally',
	(bool) preg_match( "/\\\$ukm_ingest = PETGALLERY_PLUGIN_DIR \. 'includes\/ukm-ingest\.php';/", $main )
		&& ! preg_match( "/WCPK_GALLERY_PARENT_MODE === 'ukm' \)\s*\{\s*\\\$ukm_ingest/", $main ),
	'gating the require on a per-request mode loses the Action Scheduler callback'
);

/* Registration is only safe to make unconditional because every entry point
   re-checks the gates. If one of these loses its guard, a non-UKM site would
   start running UKM code. */
foreach ( array( 'run_backfill_batch', 'run_scan', 'on_transition', 'mirror_one_async' ) as $fn ) {
	$body = '';
	if ( preg_match( '/function ' . $fn . '.*?\n\t\}/s', $ingest, $m ) ) {
		$body = $m[0];
	}
	$check( "{$fn}() still re-checks enabled() before acting", false !== strpos( $body, 'self::enabled()' ) );
}

$check(
	'enabled() tolerates its helper not being loaded yet',
	false !== strpos( $ingest, "function_exists( 'wcpk_gallery_ukm_gallery_active' )" ),
	'the file now loads in contexts where template-loader.php may not have run'
);

printf( "\n%s\n", $fail ? "{$fail} failure(s)" : 'constraint holds' );
exit( $fail ? 1 : 0 );
