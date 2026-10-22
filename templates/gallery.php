<?php
/**
 * Main gallery template. Renders the hero, filter bar, grid, and dialog.
 *
 * Available variables (extracted by wcpk_gallery_render_template):
 * - $atts (array): merged shortcode attrs.
 * - $opts (array): plugin settings.
 * - $resolver (WCPK_Gallery_Form_Resolver)
 * - $functions (PetkeyGalleryFunctions): for breed list access.
 * - $polaroids (array): hero polaroid items.
 * - $total_homes (string), $unique_breeds (int)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$show_hero       = ( ! empty( $atts['hero'] ) && $atts['hero'] !== 'no' );
$show_stats      = ( ! empty( $atts['stats'] ) && $atts['stats'] !== 'no' );
$columns         = isset( $atts['columns'] ) ? max( 2, min( 4, (int) $atts['columns'] ) ) : 4;
$initial_count   = isset( $atts['count'] ) && (int) $atts['count'] > 0 ? (int) $atts['count'] : (int) $opts['count'];
$wrap_style      = '';
if ( ! empty( $opts['color'] ) ) {
	$wrap_style = ' style="--wcpk-primary:' . esc_attr( $opts['color'] ) . ';"';
}

$container_class = trim( 'wcpk-gallery petkey-gallery wcpk-gallery--cols-' . $columns . ' ' . ( ! empty( $atts['container-class'] ) ? $atts['container-class'] : '' ) );
$form_class      = trim( 'wcpk-filter ' . ( ! empty( $atts['form-class'] ) ? $atts['form-class'] : '' ) );

$pet_gender_lock = ! empty( $atts['pet-gender'] ) && in_array( strtoupper( $atts['pet-gender'] ), array( 'MALE', 'FEMALE' ), true ) ? ucfirst( strtolower( $atts['pet-gender'] ) ) : '';

$heading        = ! empty( $atts['heading'] ) ? $atts['heading'] : $opts['hero_heading'];
$accent         = $opts['hero_heading_accent'];
$intro          = ! empty( $atts['intro'] ) ? $atts['intro'] : $opts['hero_intro'];
$eyebrow        = $opts['hero_eyebrow'];

$grid_heading   = $opts['grid_heading'];
$grid_accent    = $opts['grid_heading_accent'];
$grid_eyebrow   = $opts['grid_eyebrow'];

$published      = wcpk_gallery_count_published();

if ( ! empty( $accent ) && stripos( $heading, $accent ) !== false ) {
	$heading_html = preg_replace(
		'/(' . preg_quote( $accent, '/' ) . ')/i',
		'<em class="wcpk-hero__accent">$1</em>',
		esc_html( $heading ),
		1
	);
} else {
	$heading_html = esc_html( $heading );
}

if ( ! empty( $grid_accent ) && stripos( $grid_heading, $grid_accent ) !== false ) {
	$grid_heading_html = preg_replace(
		'/(' . preg_quote( $grid_accent, '/' ) . ')/i',
		'<em class="wcpk-section__accent">$1</em>',
		esc_html( $grid_heading ),
		1
	);
} else {
	$grid_heading_html = esc_html( $grid_heading );
}

$breeds        = $functions ? $functions->get_breeds() : array();
$pet_cats      = get_terms( array( 'taxonomy' => 'petgallery_cat', 'hide_empty' => false ) );
$show_type     = ! empty( $pet_cats ) && ! is_wp_error( $pet_cats );

/* ============================================================
 * Server-rendered first page.
 * ------------------------------------------------------------
 * The grid used to ship as an empty <div> and depend entirely on an
 * admin-ajax round trip, with no <noscript> and no server-side fallback — so
 * any failure of that request (a 500, a security plugin, a blocked
 * admin-ajax.php) looked exactly like "no pets found", and crawlers saw an
 * empty page. Page one is now rendered here, through the same card template
 * the AJAX handler uses, and the script takes over from page two.
 *
 * Filters come from `pg_*` query args so the no-JS form below is a real,
 * linkable control. Every one of them is validated against a known list
 * before it reaches a query: `pg_breed` compares an unindexed meta_value and
 * each distinct query string is its own page-cache entry, so an unchecked
 * value is a cheap way for a crawler to generate unbounded uncached work.
 * ============================================================ */
$ssr_ok       = false;
$ssr_html     = '';
$ssr_has_more = false;
$ssr_empty    = false;

/*
 * Which page to render. `pg_page` is our own parameter and always wins, but
 * WordPress has pagination of its own on the archive route and it generates
 * and links those URLs itself -- /pet-gallery/page/2/ is real, and one live
 * site advertises 800 of them in its title tag. Reading only `pg_page` meant
 * every one of them re-rendered page 1 under a different URL.
 *
 * Only on the archive: inside a [pet-gallery] shortcode placed on an ordinary
 * paginated page or post, `paged` belongs to that page's own pagination and
 * must not move this grid.
 */
$ssr_page_request = 1;

if ( isset( $_GET['pg_page'] ) ) {
	$ssr_page_request = $_GET['pg_page'];
} elseif ( is_post_type_archive( 'pet-gallery' ) ) {
	$ssr_page_request = max( (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ), 1 );
}

$ssr_filters = wcpk_gallery_sanitize_filters(
	array(
		'type'     => isset( $_GET['pg_type'] ) ? $_GET['pg_type'] : 'ALL',
		'breed'    => isset( $_GET['pg_breed'] ) ? $_GET['pg_breed'] : 'ALL',
		'gender'   => isset( $_GET['pg_gender'] ) ? $_GET['pg_gender'] : 'ALL',
		'page'     => $ssr_page_request,
		'per_page' => $initial_count,
	)
);

// A shortcode-level gender lock always wins over the query string.
if ( $pet_gender_lock ) {
	$ssr_filters['gender'] = $pet_gender_lock;
} elseif ( ! in_array( $ssr_filters['gender'], array( 'ALL', 'Male', 'Female' ), true ) ) {
	$ssr_filters['gender'] = 'ALL';
}

if ( 'ALL' !== $ssr_filters['breed'] && ! in_array( $ssr_filters['breed'], (array) $breeds, true ) ) {
	$ssr_filters['breed'] = 'ALL';
}

if ( 'ALL' !== $ssr_filters['type'] ) {
	$valid_type = false;

	if ( $show_type ) {
		foreach ( $pet_cats as $cat ) {
			if ( (int) $cat->term_id === (int) $ssr_filters['type'] ) {
				$valid_type = true;
				break;
			}
		}
	}

	if ( ! $valid_type ) {
		$ssr_filters['type'] = 'ALL';
	}
}

// Clamp the page against the cached published count so ?pg_page=99999 cannot
// be crawled into thousands of expensive, empty responses.
$ssr_requested_page  = max( 1, (int) $ssr_filters['page'] );
$ssr_max_page        = $published > 0 ? (int) ceil( $published / max( 1, $ssr_filters['per_page'] ) ) : 1;
$ssr_filters['page'] = min( $ssr_requested_page, max( 1, $ssr_max_page ) );

// The count behind the clamp is cached and unfiltered, so it can lag reality.
// When it clamps a page away, the Next link below is suppressed rather than
// pointing back at the page the visitor is already on.
$ssr_was_clamped = ( $ssr_requested_page !== $ssr_filters['page'] );

$ssr_buffer_depth = ob_get_level();

try {
	$ssr_page_result = wcpk_gallery_query_page( $ssr_filters );
	$ssr_html        = wcpk_gallery_render_cards( $ssr_page_result['posts'], $resolver, $opts );
	$ssr_has_more    = ! empty( $ssr_page_result['has_more'] );
	$ssr_empty       = empty( $ssr_page_result['posts'] );
	$ssr_ok          = true;
} catch ( \Throwable $e ) {
	// A theme may override card.php, so this is third-party code running
	// inside the_content() after the header has been flushed. Degrade to the
	// pre-1.7.0 behaviour (an empty grid the script fills) rather than leaving
	// a half-rendered page.
	while ( ob_get_level() > $ssr_buffer_depth ) {
		ob_end_clean();
	}

	$ssr_ok = false;

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[wc-pet-gallery] server-side render failed: ' . $e->getMessage() );
	}
}

$ssr_base_url = remove_query_arg( array( 'pg_type', 'pg_breed', 'pg_gender', 'pg_page' ) );

/*
 * On the archive the current URL may already carry a /page/N/ segment, and
 * appending pg_page to that yields /pet-gallery/page/2/?pg_page=3 -- two page
 * numbers, the stale one first. Build the pager from the clean archive link.
 */
if ( is_post_type_archive( 'pet-gallery' ) ) {
	$ssr_archive_link = get_post_type_archive_link( 'pet-gallery' );

	if ( $ssr_archive_link ) {
		$ssr_base_url = $ssr_archive_link;
	}
}
?>
<section class="<?php echo esc_attr( $container_class ); ?>"<?php echo $wrap_style; ?> data-wcpk-gallery>

	<?php
	$page_title = '';
	if ( isset( $atts['title'] ) && $atts['title'] !== '' ) {
		$page_title = $atts['title'] === 'no' ? '' : $atts['title'];
	} elseif ( ! empty( $opts['page_title'] ) ) {
		$page_title = $opts['page_title'];
	}
	if ( ! empty( $page_title ) ) :
	?>
	<h1 class="wcpk-page-title"><?php echo esc_html( $page_title ); ?></h1>
	<?php endif ?>

	<?php if ( $show_hero ) : ?>
	<header class="wcpk-hero">
		<div class="wcpk-hero__col wcpk-hero__col--text">
			<?php if ( ! empty( $eyebrow ) ) : ?>
			<p class="wcpk-eyebrow">
				<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 21s-7.5-4.5-9.6-9.4C1 7.6 3.6 4 7.4 4c2 0 3.6 1.1 4.6 2.6C13 5.1 14.6 4 16.6 4 20.4 4 23 7.6 21.6 11.6 19.5 16.5 12 21 12 21z"/></svg>
				<span><?php echo esc_html( $eyebrow ); ?></span>
			</p>
			<?php endif ?>

			<h2 class="wcpk-hero__heading"><?php echo $heading_html; ?></h2>

			<?php if ( ! empty( $intro ) ) : ?>
			<p class="wcpk-hero__intro"><?php echo wp_kses_post( $intro ); ?></p>
			<?php endif ?>

			<?php $hero_cta = $resolver->render_hero_cta(); ?>
			<?php if ( $hero_cta ) : ?>
			<p class="wcpk-hero__actions"><?php echo $hero_cta; ?></p>
			<?php endif ?>

			<?php if ( $show_stats ) : ?>
			<dl class="wcpk-stats">
				<div class="wcpk-stats__item">
					<dt>Forever Homes</dt>
					<dd><?php echo esc_html( wcpk_gallery_format_homes_count() ); ?></dd>
				</div>
				<div class="wcpk-stats__item">
					<dt>Unique Breeds</dt>
					<dd><?php echo esc_html( number_format_i18n( $unique_breeds ) ); ?></dd>
				</div>
				<div class="wcpk-stats__item">
					<dt>Tail Wags</dt>
					<dd>Endless</dd>
				</div>
			</dl>
			<?php endif ?>
		</div>

		<?php if ( ! empty( $polaroids ) ) : ?>
		<div class="wcpk-hero__col wcpk-hero__col--polaroids" aria-hidden="true">
			<div class="wcpk-polaroids">
				<?php
				$rotations = array( -8, 5, -3, 7, -5 );
				$i = 0;
				foreach ( $polaroids as $p ) :
					$rot   = isset( $rotations[ $i ] ) ? $rotations[ $i ] : 0;
					$i++;
				?>
				<figure class="wcpk-polaroid" style="--wcpk-rot:<?php echo (int) $rot; ?>deg;">
					<img src="<?php echo esc_url( $p['image'] ); ?>" alt="" loading="lazy" decoding="async">
					<figcaption><svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 21s-7.5-4.5-9.6-9.4C1 7.6 3.6 4 7.4 4c2 0 3.6 1.1 4.6 2.6C13 5.1 14.6 4 16.6 4 20.4 4 23 7.6 21.6 11.6 19.5 16.5 12 21 12 21z"/></svg></figcaption>
				</figure>
				<?php endforeach; ?>
			</div>
		</div>
		<?php endif ?>
	</header>
	<?php endif ?>

	<form class="<?php echo esc_attr( $form_class ); ?> animal-selectionForm" action="<?php echo esc_url( get_permalink() ); ?>" method="post" data-wcpk-filter>
		<input type="hidden" name="pet_gallery_page" id="pet_gallery_page" value="<?php echo (int) time(); ?>" />
		<input type="hidden" name="pet_gallery_page_number" id="pet_gallery_page_number" value="1" />

		<?php if ( $show_type ) : ?>
		<div class="wcpk-filter__field">
			<label for="pet-gallery-type">Type</label>
			<select id="pet-gallery-type" class="pet-gallery-search wcpk-select" name="type">
				<option value="ALL"<?php selected( 'ALL', $ssr_filters['type'] ); ?>>All Pets</option>
				<?php foreach ( $pet_cats as $cat ) : ?>
				<option value="<?php echo esc_attr( $cat->term_id ); ?>"<?php selected( (string) $cat->term_id, (string) $ssr_filters['type'] ); ?>><?php echo esc_html( $cat->name ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php endif ?>

		<div class="wcpk-filter__field gallery-type-toggle">
			<label for="pet-gallery-breed">Breed</label>
			<select id="pet-gallery-breed" class="pet-gallery-search wcpk-select" name="breed">
				<option value="ALL"<?php selected( 'ALL', $ssr_filters['breed'] ); ?>>All Breeds</option>
				<?php if ( ! empty( $breeds ) ) : ?>
					<?php foreach ( $breeds as $b ) : ?>
					<option value="<?php echo esc_attr( $b ); ?>"<?php selected( (string) $b, (string) $ssr_filters['breed'] ); ?>><?php echo esc_html( $b ); ?></option>
					<?php endforeach ?>
				<?php endif ?>
			</select>
		</div>

		<div class="wcpk-filter__field gallery-type-toggle">
			<label for="pet-gallery-gender">Gender</label>
			<select id="pet-gallery-gender" class="pet-gallery-search wcpk-select" name="gender"<?php echo $pet_gender_lock ? ' data-locked="' . esc_attr( $pet_gender_lock ) . '"' : ''; ?>>
				<option value="ALL"<?php selected( 'ALL', $ssr_filters['gender'] ); ?>>All</option>
				<option value="Female"<?php selected( 'Female', $ssr_filters['gender'] ); ?>>Female</option>
				<option value="Male"<?php selected( 'Male', $ssr_filters['gender'] ); ?>>Male</option>
			</select>
		</div>
	</form>

	<header class="wcpk-section">
		<div class="wcpk-section__heading">
			<?php if ( ! empty( $grid_eyebrow ) ) : ?>
			<p class="wcpk-eyebrow"><?php echo esc_html( $grid_eyebrow ); ?></p>
			<?php endif ?>
			<h3 class="wcpk-section__title"><?php echo $grid_heading_html; ?></h3>
		</div>
		<?php if ( $published > 0 ) : ?>
		<p class="wcpk-section__badge">
			<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><rect x="4" y="7" width="16" height="13" rx="2"/></svg>
			<span><?php printf( esc_html__( '%s adopted pups', 'petkey-gallery' ), esc_html( number_format_i18n( $published ) ) ); ?></span>
		</p>
		<?php endif ?>
	</header>

	<div class="wcpk-grid listing listingSection" id="pet-gallery-listing-section"
		data-wcpk-grid
		data-initial="<?php echo (int) $initial_count; ?>"
		data-wcpk-ssr="<?php echo $ssr_ok ? '1' : '0'; ?>"
		data-wcpk-page="<?php echo (int) $ssr_filters['page']; ?>"
		data-wcpk-has-more="<?php echo $ssr_has_more ? '1' : '0'; ?>"
		data-wcpk-type="<?php echo esc_attr( $ssr_filters['type'] ); ?>"
		data-wcpk-breed="<?php echo esc_attr( $ssr_filters['breed'] ); ?>"
		data-wcpk-gender="<?php echo esc_attr( $ssr_filters['gender'] ); ?>"
		aria-live="polite"
		aria-busy="false">
		<div class="wcpk-grid__loading" data-wcpk-loading hidden>
			<span class="wcpk-spinner" aria-hidden="true"></span>
			<span class="screen-reader-text"><?php esc_html_e( 'Loading…', 'petkey-gallery' ); ?></span>
		</div>
		<?php
		if ( $ssr_ok && ! $ssr_empty ) {
			// Already escaped by card.php.
			echo $ssr_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( $ssr_ok && $ssr_empty ) {
			?>
			<div class="wcpk-empty" data-wcpk-empty>
				<p><?php esc_html_e( 'No pets found for your search.', 'petkey-gallery' ); ?></p>
			</div>
			<?php
		}
		?>
	</div>

	<div class="wcpk-loadmore">
		<button type="button" class="wcpk-cta wcpk-cta--ghost" id="pg_load_more" data-wcpk-loadmore<?php echo ( $ssr_ok && ! $ssr_has_more ) ? ' hidden' : ''; ?>><?php esc_html_e( 'Load more', 'petkey-gallery' ); ?></button>
		<p class="wcpk-loadmore__hint" data-wcpk-end<?php echo ( $ssr_ok && ! $ssr_has_more && ! $ssr_empty ) ? '' : ' hidden'; ?>><?php esc_html_e( 'That’s every story so far. Keep an eye out for more!', 'petkey-gallery' ); ?></p>
	</div>

	<?php
	/*
	 * No-JS path. A separate sibling GET form rather than changes to the POST
	 * form above — `.animal-selectionForm`, `#pet_gallery_page`,
	 * `#pet_gallery_page_number` and the `type|breed|gender` field names are
	 * theme contract that existing child themes bind to.
	 */
	?>
	<noscript>
		<form class="wcpk-filter wcpk-filter--noscript" action="<?php echo esc_url( $ssr_base_url ); ?>" method="get">
			<?php if ( $show_type ) : ?>
			<label for="pg-type-noscript"><?php esc_html_e( 'Type', 'petkey-gallery' ); ?></label>
			<select id="pg-type-noscript" name="pg_type">
				<option value="ALL"<?php selected( 'ALL', $ssr_filters['type'] ); ?>><?php esc_html_e( 'All Pets', 'petkey-gallery' ); ?></option>
				<?php foreach ( $pet_cats as $cat ) : ?>
				<option value="<?php echo esc_attr( $cat->term_id ); ?>"<?php selected( (string) $cat->term_id, (string) $ssr_filters['type'] ); ?>><?php echo esc_html( $cat->name ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php endif ?>

			<label for="pg-breed-noscript"><?php esc_html_e( 'Breed', 'petkey-gallery' ); ?></label>
			<select id="pg-breed-noscript" name="pg_breed">
				<option value="ALL"<?php selected( 'ALL', $ssr_filters['breed'] ); ?>><?php esc_html_e( 'All Breeds', 'petkey-gallery' ); ?></option>
				<?php foreach ( (array) $breeds as $b ) : ?>
				<option value="<?php echo esc_attr( $b ); ?>"<?php selected( (string) $b, (string) $ssr_filters['breed'] ); ?>><?php echo esc_html( $b ); ?></option>
				<?php endforeach ?>
			</select>

			<?php if ( ! $pet_gender_lock ) : ?>
			<label for="pg-gender-noscript"><?php esc_html_e( 'Gender', 'petkey-gallery' ); ?></label>
			<select id="pg-gender-noscript" name="pg_gender">
				<option value="ALL"<?php selected( 'ALL', $ssr_filters['gender'] ); ?>><?php esc_html_e( 'All', 'petkey-gallery' ); ?></option>
				<option value="Female"<?php selected( 'Female', $ssr_filters['gender'] ); ?>><?php esc_html_e( 'Female', 'petkey-gallery' ); ?></option>
				<option value="Male"<?php selected( 'Male', $ssr_filters['gender'] ); ?>><?php esc_html_e( 'Male', 'petkey-gallery' ); ?></option>
			</select>
			<?php endif ?>

			<button type="submit" class="wcpk-cta"><?php esc_html_e( 'Filter', 'petkey-gallery' ); ?></button>
		</form>

		<nav class="wcpk-pager" aria-label="<?php esc_attr_e( 'Gallery pages', 'petkey-gallery' ); ?>">
			<?php
			$pager_args = array(
				'pg_type'   => $ssr_filters['type'],
				'pg_breed'  => $ssr_filters['breed'],
				'pg_gender' => $ssr_filters['gender'],
			);

			if ( $ssr_filters['page'] > 1 ) :
				$prev = add_query_arg( array_merge( $pager_args, array( 'pg_page' => $ssr_filters['page'] - 1 ) ), $ssr_base_url );
				?>
				<a class="wcpk-cta wcpk-cta--ghost" rel="prev" href="<?php echo esc_url( $prev ); ?>"><?php esc_html_e( '← Previous', 'petkey-gallery' ); ?></a>
			<?php endif ?>

			<?php
			if ( $ssr_has_more && ! $ssr_was_clamped ) :
				$next = add_query_arg( array_merge( $pager_args, array( 'pg_page' => $ssr_filters['page'] + 1 ) ), $ssr_base_url );
				?>
				<a class="wcpk-cta wcpk-cta--ghost" rel="next" href="<?php echo esc_url( $next ); ?>"><?php esc_html_e( 'Next →', 'petkey-gallery' ); ?></a>
			<?php endif ?>
		</nav>
	</noscript>

	<?php echo $resolver->render_dialog(); ?>
</section>
