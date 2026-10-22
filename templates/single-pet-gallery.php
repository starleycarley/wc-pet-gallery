<?php
/**
 * Plugin-provided single template for the `pet-gallery` CPT.
 *
 * Renders a clean detail page:
 *   - Breadcrumb back to gallery
 *   - Hero card with photo + sticker + breed/gender/ref
 *   - Info grid (status, ref, DOB, gender, color)
 *   - Inquiry CTA (defers to resolver — theme modal, plugin dialog, or link)
 *   - Optional breed info block (pulls from the theme's "breeds" CPT if present)
 *   - Optional "more pups like this" related grid
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

if ( ! have_posts() ) {
	get_footer();
	return;
}
the_post();

$post_id   = get_the_ID();
$opts      = wcpk_gallery_get_options();
$resolver  = new WCPK_Gallery_Form_Resolver();

if ( function_exists( 'wcpk_gallery_enqueue_assets' ) ) {
	wcpk_gallery_enqueue_assets();
}

$breed       = wcpk_gallery_get_pet_field( $post_id, 'breed_name' );
$gender      = wcpk_gallery_get_pet_field( $post_id, 'gender' );
$ref_id      = wcpk_gallery_get_pet_field( $post_id, 'reference_id' );
$type        = wcpk_gallery_get_pet_field( $post_id, 'animal_type' );
$color       = wcpk_gallery_get_pet_field( $post_id, 'coloring' );
$birth_date  = wcpk_gallery_get_pet_field( $post_id, 'birth_date' );
$location    = wcpk_gallery_get_pet_field( $post_id, 'location' );

$adopted_when = get_the_date( 'F Y', $post_id );

$image_id = get_post_thumbnail_id( $post_id );
$image    = $image_id ? wp_get_attachment_image_src( $image_id, 'large' ) : null;
$image_url = $image ? $image[0] : ( plugin_dir_url( dirname( __FILE__ ) ) . 'asset/img/placeholder.svg' );
$alt = trim( implode( ' ', array_filter( array( $breed, $type, $gender, $color, $ref_id ) ) ) );

$archive_url = get_post_type_archive_link( 'pet-gallery' );

$dob_pretty = '';
if ( ! empty( $birth_date ) ) {
	$ts = strtotime( $birth_date );
	if ( $ts ) { $dob_pretty = date_i18n( 'F j, Y', $ts ); }
}

// Theme's "breeds" CPT lookup — petland uses it for breed descriptions/characteristics
$breed_post = null;
$breed_description = '';
$breed_chars = array();

if ( $opts['single_show_breed_info'] === 'yes' && ! empty( $breed ) ) {
	$breed_query = get_posts( array(
		'post_type'      => 'breeds',
		'title'          => $breed,
		'posts_per_page' => 1,
		'post_status'    => 'publish',
	) );
	if ( ! empty( $breed_query ) ) {
		$breed_post = $breed_query[0];
		if ( function_exists( 'get_field' ) ) {
			$short_desc = get_field( 'short_description', $breed_post->ID );
			if ( $short_desc ) {
				$breed_description = $short_desc;
			}
			$chars = get_field( 'characteristic_tab', $breed_post->ID );
			if ( is_array( $chars ) ) {
				$breed_chars = $chars;
			}
		}
		if ( ! $breed_description ) {
			$breed_description = wp_strip_all_tags( $breed_post->post_content );
		}
	}
}

// Related pets — same breed
$related_pets = array();
$related_limit = (int) apply_filters( 'wcpk_gallery_related_limit', 6 );
if ( $opts['single_show_related'] === 'yes' && ! empty( $breed ) ) {
	$related_meta = array( wcpk_gallery_meta_or_clause( 'breed_name', $breed ) );

	$related_type_clause = function_exists( 'wcpk_gallery_allowed_types_clause' )
		? wcpk_gallery_allowed_types_clause()
		: null;

	if ( null !== $related_type_clause ) {
		$related_meta[] = $related_type_clause;
	}

	$related_query = new WP_Query( array(
		'post_type'      => 'pet-gallery',
		'post_status'    => 'publish',
		'posts_per_page' => $related_limit,
		'post__not_in'   => array( $post_id ),
		'orderby'        => 'rand',
		'meta_query'     => $related_meta,
		'no_found_rows'  => true,
	) );
	if ( $related_query->have_posts() ) {
		while ( $related_query->have_posts() ) {
			$related_query->the_post();
			$rid = get_the_ID();
			$thumb = get_post_thumbnail_id( $rid );
			if ( ! $thumb ) { continue; }
			$src = wp_get_attachment_image_src( $thumb, 'medium_large' );
			if ( empty( $src ) ) { continue; }
			$related_pets[] = array(
				'id'           => $rid,
				'permalink'    => get_permalink( $rid ),
				'image'        => $src[0],
				'breed_name'   => wcpk_gallery_get_pet_field( $rid, 'breed_name' ),
				'gender'       => wcpk_gallery_get_pet_field( $rid, 'gender' ),
				'reference_id' => wcpk_gallery_get_pet_field( $rid, 'reference_id' ),
				'animal_type'  => wcpk_gallery_get_pet_field( $rid, 'animal_type' ),
				'coloring'     => wcpk_gallery_get_pet_field( $rid, 'coloring' ),
				'birth_date'   => wcpk_gallery_get_pet_field( $rid, 'birth_date' ),
				'adopted_when' => get_the_date( 'F Y', $rid ),
			);
		}
		wp_reset_postdata();
	}
}

$wrap_style = '';
if ( ! empty( $opts['color'] ) ) {
	$wrap_style = ' style="--wcpk-primary:' . esc_attr( $opts['color'] ) . ';"';
}
?>
<section class="wcpk-gallery wcpk-single" data-wcpk-single<?php echo $wrap_style; ?>>

	<nav class="wcpk-breadcrumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'petkey-gallery' ); ?>">
		<a href="<?php echo esc_url( $archive_url ); ?>">
			<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
			<span><?php esc_html_e( 'Back to gallery', 'petkey-gallery' ); ?></span>
		</a>
	</nav>

	<div class="wcpk-single__layout">
		<div class="wcpk-single__photo-col">
			<figure class="wcpk-single__photo">
				<img src="<?php echo esc_url( $image_url ); ?>" alt="<?php echo esc_attr( $alt ); ?>" loading="eager" decoding="async">
				<figcaption class="wcpk-sticker" aria-hidden="true">
					<small><?php echo esc_html( ! empty( $opts['sticker_eyebrow'] ) ? $opts['sticker_eyebrow'] : 'Forever' ); ?></small>
					<strong><?php echo esc_html( ! empty( $opts['sticker_label'] ) ? $opts['sticker_label'] : 'Adopted' ); ?></strong>
					<?php if ( $adopted_when ) : ?><time><?php echo esc_html( $adopted_when ); ?></time><?php endif ?>
				</figcaption>
			</figure>
		</div>

		<div class="wcpk-single__detail-col">
			<header class="wcpk-single__heading">
				<p class="wcpk-eyebrow">
					<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 21s-7.5-4.5-9.6-9.4C1 7.6 3.6 4 7.4 4c2 0 3.6 1.1 4.6 2.6C13 5.1 14.6 4 16.6 4 20.4 4 23 7.6 21.6 11.6 19.5 16.5 12 21 12 21z"/></svg>
					<span><?php printf( esc_html__( 'A %s who found home', 'petkey-gallery' ), esc_html( strtolower( $type ?: 'pup' ) ) ); ?></span>
				</p>
				<h1 class="wcpk-single__title"><?php echo esc_html( $breed ); ?></h1>
				<p class="wcpk-single__subtitle">
					<?php if ( $gender ) : ?><span><?php echo esc_html( $gender ); ?></span><?php endif ?>
					<?php if ( $ref_id ) : ?><span class="wcpk-single__ref">#<?php echo esc_html( $ref_id ); ?></span><?php endif ?>
				</p>
			</header>

			<dl class="wcpk-single__facts">
				<div class="wcpk-fact">
					<dt><?php esc_html_e( 'Status', 'petkey-gallery' ); ?></dt>
					<dd><?php echo esc_html( ! empty( $opts['sticker_label'] ) ? $opts['sticker_label'] : 'Adopted' ); ?></dd>
				</div>
				<?php if ( $ref_id ) : ?>
				<div class="wcpk-fact">
					<dt><?php esc_html_e( 'Reference ID', 'petkey-gallery' ); ?></dt>
					<dd><?php echo esc_html( $ref_id ); ?></dd>
				</div>
				<?php endif ?>
				<?php if ( $type ) : ?>
				<div class="wcpk-fact">
					<dt><?php esc_html_e( 'Type', 'petkey-gallery' ); ?></dt>
					<dd><?php echo esc_html( ucfirst( strtolower( $type ) ) ); ?></dd>
				</div>
				<?php endif ?>
				<?php if ( $gender ) : ?>
				<div class="wcpk-fact">
					<dt><?php esc_html_e( 'Gender', 'petkey-gallery' ); ?></dt>
					<dd><?php echo esc_html( $gender ); ?></dd>
				</div>
				<?php endif ?>
				<?php if ( $color ) : ?>
				<div class="wcpk-fact">
					<dt><?php esc_html_e( 'Color', 'petkey-gallery' ); ?></dt>
					<dd><?php echo esc_html( $color ); ?></dd>
				</div>
				<?php endif ?>
				<?php if ( $dob_pretty ) : ?>
				<div class="wcpk-fact">
					<dt><?php esc_html_e( 'Birth date', 'petkey-gallery' ); ?></dt>
					<dd><?php echo esc_html( $dob_pretty ); ?></dd>
				</div>
				<?php endif ?>
				<?php if ( $adopted_when ) : ?>
				<div class="wcpk-fact">
					<dt><?php esc_html_e( 'Found home', 'petkey-gallery' ); ?></dt>
					<dd><?php echo esc_html( $adopted_when ); ?></dd>
				</div>
				<?php endif ?>
			</dl>

			<div class="wcpk-single__cta">
				<?php
				$cta_html = $resolver->render_card_cta( array(
					'post_id' => $post_id,
					'label'   => ! empty( $opts['cta_label'] ) ? $opts['cta_label'] : __( 'Inquire about a pup like this', 'petkey-gallery' ),
				) );
				if ( $cta_html ) {
					echo $cta_html;
				}
				?>
				<a href="<?php echo esc_url( $archive_url ); ?>" class="wcpk-cta wcpk-cta--ghost">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 7h18M3 12h18M3 17h18"/></svg>
					<span><?php esc_html_e( 'Browse all pups', 'petkey-gallery' ); ?></span>
				</a>
			</div>

			<?php $post_content = get_the_content(); if ( ! empty( trim( wp_strip_all_tags( $post_content ) ) ) ) : ?>
			<div class="wcpk-single__content">
				<?php echo apply_filters( 'the_content', $post_content ); ?>
			</div>
			<?php endif ?>
		</div>
	</div>

	<?php if ( $breed_post && ( $breed_description || ! empty( $breed_chars ) ) ) : ?>
	<section class="wcpk-single__breed">
		<header class="wcpk-section">
			<div class="wcpk-section__heading">
				<p class="wcpk-eyebrow"><?php esc_html_e( 'About the breed', 'petkey-gallery' ); ?></p>
				<h2 class="wcpk-section__title"><?php echo esc_html( $breed ); ?></h2>
			</div>
		</header>

		<?php if ( $breed_description ) : ?>
		<div class="wcpk-single__breed-desc"><?php echo wpautop( wp_kses_post( $breed_description ) ); ?></div>
		<?php endif ?>

		<?php if ( ! empty( $breed_chars ) ) : ?>
		<dl class="wcpk-single__breed-traits">
			<?php foreach ( $breed_chars as $char ) :
				$heading = isset( $char['heading'] ) ? $char['heading'] : '';
				$content_raw = isset( $char['content'] ) ? $char['content'] : '';
				if ( ! $heading && ! $content_raw ) { continue; }
			?>
			<div class="wcpk-trait">
				<?php if ( $heading ) : ?><dt><?php echo esc_html( $heading ); ?></dt><?php endif ?>
				<?php if ( $content_raw ) : ?><dd><?php echo wp_kses_post( $content_raw ); ?></dd><?php endif ?>
			</div>
			<?php endforeach; ?>
		</dl>
		<?php endif ?>
	</section>
	<?php endif ?>

	<?php if ( ! empty( $related_pets ) ) : ?>
	<section class="wcpk-single__related wcpk-related">
		<header class="wcpk-related__head">
			<div>
				<p class="wcpk-eyebrow"><?php esc_html_e( 'More like this', 'petkey-gallery' ); ?></p>
				<h2 class="wcpk-related__title"><?php printf( esc_html__( 'Other %s pups who found homes', 'petkey-gallery' ), esc_html( $breed ) ); ?></h2>
			</div>
			<a class="wcpk-related__link" href="<?php echo esc_url( $archive_url ); ?>">
				<span><?php esc_html_e( 'See the whole album', 'petkey-gallery' ); ?></span>
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><path d="m9 18 6-6-6-6"/></svg>
			</a>
		</header>

		<ul class="wcpk-related__list" role="list">
			<?php foreach ( $related_pets as $pet ) :
				$ralt = trim( implode( ' ', array_filter( array( $pet['breed_name'], $pet['animal_type'], $pet['gender'], $pet['coloring'], $pet['reference_id'] ) ) ) );
				$rgender = $pet['gender'];
				$meta_bits = array();
				if ( $rgender ) {
					$meta_bits[] = $rgender;
				}
				if ( ! empty( $pet['reference_id'] ) ) {
					$meta_bits[] = '#' . $pet['reference_id'];
				}
			?>
			<li class="wcpk-related__item">
				<a class="wcpk-related__card" href="<?php echo esc_url( $pet['permalink'] ); ?>" aria-label="<?php echo esc_attr( $ralt ); ?>">
					<figure class="wcpk-related__photo">
						<img src="<?php echo esc_url( $pet['image'] ); ?>" alt="" loading="lazy" decoding="async">
						<figcaption class="wcpk-related__sticker" aria-hidden="true"><?php echo esc_html( ! empty( $opts['sticker_label'] ) ? $opts['sticker_label'] : 'Adopted' ); ?></figcaption>
					</figure>
					<div class="wcpk-related__body">
						<h3 class="wcpk-related__name"><?php echo esc_html( $pet['breed_name'] ); ?></h3>
						<?php if ( ! empty( $meta_bits ) ) : ?>
						<p class="wcpk-related__meta"><?php echo esc_html( implode( ' · ', $meta_bits ) ); ?></p>
						<?php endif ?>
					</div>
				</a>
			</li>
			<?php endforeach; ?>
		</ul>
	</section>
	<?php endif ?>

	<?php echo $resolver->render_dialog(); ?>
</section>
<?php
get_footer();
