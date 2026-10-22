<?php
/**
 * Plugin-provided archive template for the `pet-gallery` CPT.
 * Themes that ship their own archive-pet-gallery.php take precedence —
 * this is only loaded when the active theme has no override.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

global $petkey_gallery_functions;

$opts          = wcpk_gallery_get_options();
$resolver      = new WCPK_Gallery_Form_Resolver();
$polaroids     = wcpk_gallery_get_hero_polaroids( 5 );
$unique_breeds = wcpk_gallery_count_unique_breeds();

$atts = array(
	'hero'            => $opts['hero_enabled'] === 'yes' ? 'yes' : 'no',
	'stats'           => $opts['stats_enabled'] === 'yes' ? 'yes' : 'no',
	'columns'         => $opts['columns'],
	'count'           => $opts['count'],
	'heading'         => $opts['hero_heading'],
	'intro'           => $opts['hero_intro'],
	'pet-gender'      => '',
	'container-class' => 'wcpk-gallery--archive',
	'form-class'      => '',
);
?>
<main class="wcpk-page-wrap site-main" role="main">
<?php
echo wcpk_gallery_render_template( 'gallery', array(
	'atts'          => $atts,
	'opts'          => $opts,
	'resolver'      => $resolver,
	'functions'     => $petkey_gallery_functions,
	'polaroids'     => $polaroids,
	'unique_breeds' => $unique_breeds,
	'total_homes'   => wcpk_gallery_format_homes_count(),
) );
?>
</main>
<?php
get_footer();
