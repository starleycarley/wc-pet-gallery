<?php
/**
 * Minimal page template — bypasses whatever theme page-template the admin
 * assigned, so the [petkey-gallery] shortcode renders without the theme's
 * banner / hero / sidebar chrome wrapping it.
 *
 * Activated by the plugin's "Force a clean layout for pages containing the
 * shortcode" setting + a template_include filter.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

if ( have_posts() ) {
	while ( have_posts() ) {
		the_post();
		?>
		<main class="wcpk-page-wrap site-main" role="main">
			<?php the_content(); ?>
		</main>
		<?php
	}
}

get_footer();
