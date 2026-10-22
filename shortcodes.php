<?php
/**
 * Shortcodes.
 *
 * [pet-gallery] (alias: [petkey-gallery]) — renders hero + filter bar + grid.
 *   Attributes (all optional):
 *     hero               yes|no       (default yes) — plugin's own .wcpk-hero
 *     stats              yes|no       (default yes)
 *     columns            2|3|4        (default from settings, falls back to 4)
 *     count              int          initial page size, defaults to settings
 *     heading            string       override hero heading
 *     intro              string       override hero intro
 *     pet-gender         ALL|Male|Female  legacy filter lock
 *     container-class    string       extra wrapper class
 *     form-class         string       extra class on the filter form
 *     suppress-page-hero auto|yes|no  (default auto) — when set to `yes` (or
 *                                     left at the `auto` default while the
 *                                     plugin's own hero is enabled), injects
 *                                     an inline style rule that hides the
 *                                     active theme's `.page-hero` block so
 *                                     the page doesn't show two redundant
 *                                     titles. Targets the petland theme's
 *                                     banner; harmless on themes that don't
 *                                     use that class. Pass `no` to keep the
 *                                     theme banner visible.
 *
 * [pet-gallery-hero] (alias: [petkey-gallery-hero]) — renders just the hero.
 *   Useful for pages that pair the hero with the grid placed elsewhere.
 *   Accepts the same `suppress-page-hero` attribute (defaults to `yes`
 *   here since this shortcode always renders a hero).
 *
 * The petkey-* tags are aliases kept for backward compatibility with existing
 * pages. They route to the same callbacks as the pet-* tags.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_shortcode( 'pet-gallery', 'petkey_gallery_render_shortcode' );
add_shortcode( 'pet-gallery-hero', 'petkey_gallery_render_hero_shortcode' );
add_shortcode( 'petkey-gallery', 'petkey_gallery_render_shortcode' );
add_shortcode( 'petkey-gallery-hero', 'petkey_gallery_render_hero_shortcode' );

function petkey_gallery_render_shortcode( $atts, $content = null, $tag = 'pet-gallery' ) {
	global $petkey_gallery_functions;

	$defaults = array(
		'title'              => '',
		'hero'               => '',
		'stats'              => '',
		'columns'            => '',
		'count'              => '',
		'heading'            => '',
		'intro'              => '',
		'pet-gender'         => 'ALL',
		'container-class'    => '',
		'form-class'         => '',
		'suppress-page-hero' => 'auto',
	);

	// Pass the actual tag so shortcode_atts_{pet-gallery} and the legacy
	// shortcode_atts_{petkey-gallery} filter both fire under their own name.
	$atts = shortcode_atts( $defaults, (array) $atts, $tag );
	$opts = wcpk_gallery_get_options();

	if ( $atts['hero'] === '' ) { $atts['hero'] = $opts['hero_enabled']; }
	if ( $atts['stats'] === '' ) { $atts['stats'] = $opts['stats_enabled']; }
	if ( $atts['columns'] === '' ) { $atts['columns'] = $opts['columns']; }
	if ( $atts['count'] === '' ) { $atts['count'] = $opts['count']; }

	// "auto" means: suppress the theme's page-hero only when the plugin's
	// own hero is rendering, so the page doesn't show two stacked titles.
	if ( strtolower( (string) $atts['suppress-page-hero'] ) === 'auto' ) {
		$atts['suppress-page-hero'] = ( $atts['hero'] !== 'no' ) ? 'yes' : 'no';
	}

	$resolver      = new WCPK_Gallery_Form_Resolver();
	$polaroids     = ( $atts['hero'] !== 'no' ) ? wcpk_gallery_get_hero_polaroids( 5 ) : array();
	$unique_breeds = wcpk_gallery_count_unique_breeds();

	wcpk_gallery_enqueue_assets();

	$output = wcpk_gallery_render_template( 'gallery', array(
		'atts'          => $atts,
		'opts'          => $opts,
		'resolver'      => $resolver,
		'functions'     => $petkey_gallery_functions,
		'polaroids'     => $polaroids,
		'unique_breeds' => $unique_breeds,
		'total_homes'   => wcpk_gallery_format_homes_count(),
	) );

	return wcpk_gallery_maybe_suppress_page_hero( $atts['suppress-page-hero'] ) . $output;
}

function petkey_gallery_render_hero_shortcode( $atts, $content = null, $tag = 'pet-gallery-hero' ) {
	$atts = shortcode_atts( array(
		'stats'              => '',
		'heading'            => '',
		'intro'              => '',
		'suppress-page-hero' => 'yes',
	), (array) $atts, $tag );

	$opts = wcpk_gallery_get_options();
	if ( $atts['stats'] === '' ) { $atts['stats'] = $opts['stats_enabled']; }

	$resolver  = new WCPK_Gallery_Form_Resolver();
	$polaroids = wcpk_gallery_get_hero_polaroids( 5 );
	$unique_breeds = wcpk_gallery_count_unique_breeds();

	wcpk_gallery_enqueue_assets();

	ob_start();
	?>
	<section class="wcpk-gallery wcpk-gallery--hero-only" data-wcpk-gallery-hero<?php echo ! empty( $opts['color'] ) ? ' style="--wcpk-primary:' . esc_attr( $opts['color'] ) . ';"' : ''; ?>>
		<header class="wcpk-hero">
			<div class="wcpk-hero__col wcpk-hero__col--text">
				<?php if ( ! empty( $opts['hero_eyebrow'] ) ) : ?>
				<p class="wcpk-eyebrow">
					<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 21s-7.5-4.5-9.6-9.4C1 7.6 3.6 4 7.4 4c2 0 3.6 1.1 4.6 2.6C13 5.1 14.6 4 16.6 4 20.4 4 23 7.6 21.6 11.6 19.5 16.5 12 21 12 21z"/></svg>
					<span><?php echo esc_html( $opts['hero_eyebrow'] ); ?></span>
				</p>
				<?php endif ?>
				<h2 class="wcpk-hero__heading">
					<?php
					$heading = ! empty( $atts['heading'] ) ? $atts['heading'] : $opts['hero_heading'];
					$accent  = $opts['hero_heading_accent'];
					if ( $accent && stripos( $heading, $accent ) !== false ) {
						echo preg_replace(
							'/(' . preg_quote( $accent, '/' ) . ')/i',
							'<em class="wcpk-hero__accent">$1</em>',
							esc_html( $heading ),
							1
						);
					} else {
						echo esc_html( $heading );
					}
					?>
				</h2>
				<?php $intro = ! empty( $atts['intro'] ) ? $atts['intro'] : $opts['hero_intro']; ?>
				<?php if ( ! empty( $intro ) ) : ?>
				<p class="wcpk-hero__intro"><?php echo wp_kses_post( $intro ); ?></p>
				<?php endif ?>
				<?php $hero_cta = $resolver->render_hero_cta(); ?>
				<?php if ( $hero_cta ) : ?>
				<p class="wcpk-hero__actions"><?php echo $hero_cta; ?></p>
				<?php endif ?>
				<?php if ( $atts['stats'] !== 'no' ) : ?>
				<dl class="wcpk-stats">
					<div class="wcpk-stats__item"><dt>Forever Homes</dt><dd><?php echo esc_html( wcpk_gallery_format_homes_count() ); ?></dd></div>
					<div class="wcpk-stats__item"><dt>Unique Breeds</dt><dd><?php echo esc_html( number_format_i18n( $unique_breeds ) ); ?></dd></div>
					<div class="wcpk-stats__item"><dt>Tail Wags</dt><dd>Endless</dd></div>
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
						$rot = isset( $rotations[ $i ] ) ? $rotations[ $i ] : 0; $i++;
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
		<?php echo $resolver->render_dialog(); ?>
	</section>
	<?php
	return wcpk_gallery_maybe_suppress_page_hero( $atts['suppress-page-hero'] ) . ob_get_clean();
}

if ( ! function_exists( 'wcpk_gallery_maybe_suppress_page_hero' ) ) {
	/**
	 * Return an inline <style> block that hides the active theme's
	 * `.page-hero` element, or '' when the caller didn't request it.
	 *
	 * Used by the [pet-gallery] / [pet-gallery-hero] shortcodes when
	 * `suppress-page-hero="yes"`. Targets the petland theme's banner; the
	 * same selector is harmless on themes that don't use that class.
	 *
	 * Accepts: 'yes', '1', 'true' (case-insensitive) — anything else is a
	 * no-op.
	 */
	function wcpk_gallery_maybe_suppress_page_hero( $value ) {
		$value = strtolower( (string) $value );
		if ( ! in_array( $value, array( 'yes', '1', 'true' ), true ) ) {
			return '';
		}
		return '<style id="wcpk-gallery-suppress-page-hero">.page-hero{display:none !important;}</style>';
	}
}
