<?php
/**
 * The archive must honour WordPress's own pagination.
 *
 * /pet-gallery/page/2/ is a URL WordPress generates, links and advertises in
 * the title tag ("Page 2 of 800" on one live site). The SSR block originally
 * read only our own `pg_page` parameter, so every one of those URLs rendered
 * page 1 again under a different address — duplicate content, and a pager that
 * never moved for anyone without JavaScript.
 *
 * Source-level on purpose: it fails the moment the fallback is dropped.
 *
 * Run: php tests/test-pagination.php
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

$tpl = file_get_contents( $root . '/templates/gallery.php' );

$check(
	'the template reads WordPress\'s own paged query var',
	false !== strpos( $tpl, "get_query_var( 'paged' )" ),
	'without it /pet-gallery/page/N/ renders page 1'
);

$check(
	'the paged fallback is gated to the archive route',
	(bool) preg_match(
		"/elseif \(\s*is_post_type_archive\( 'pet-gallery' \)\s*\)\s*\{\s*\\\$ssr_page_request = max\(/",
		$tpl
	),
	'inside a shortcode on a paginated page, `paged` belongs to that page'
);

$check(
	'our own pg_page still takes precedence',
	(bool) preg_match(
		"/if \(\s*isset\( \\\$_GET\['pg_page'\] \)\s*\)\s*\{\s*\\\$ssr_page_request = \\\$_GET\['pg_page'\];/",
		$tpl
	)
);

$check(
	'the resolved page is what reaches the query builder',
	false !== strpos( $tpl, "'page'     => \$ssr_page_request," ),
	'the sanitizer must receive the resolved value, not $_GET again'
);

$check(
	'the pager base drops any /page/N/ segment on the archive',
	false !== strpos( $tpl, "get_post_type_archive_link( 'pet-gallery' )" ),
	'otherwise Next links read /pet-gallery/page/2/?pg_page=3'
);

/* The clamp must stay: it is what stops ?pg_page=99999 being crawled into
   thousands of expensive empty responses, and it now also bounds `paged`. */
$check(
	'the page is still clamped to the published count',
	false !== strpos( $tpl, '$ssr_max_page' )
		&& false !== strpos( $tpl, 'min( $ssr_requested_page' )
);

printf( "\n%s\n", $fail ? "{$fail} failure(s)" : 'pagination holds' );
exit( $fail ? 1 : 0 );
