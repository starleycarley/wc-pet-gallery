<?php
/**
 * Server-rendered card. Used by initial-load fallbacks (e.g. archive template).
 * The JS card renderer mirrors this exact structure.
 *
 * Variables:
 *   $post_id, $resolver, $opts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$image = get_the_post_thumbnail_url( $post_id, 'medium_large' );
$image = $image ? $image : ( plugin_dir_url( dirname( __FILE__ ) ) . 'asset/img/placeholder.svg' );

$breed   = wcpk_gallery_get_pet_field( $post_id, 'breed_name' );
$gender  = wcpk_gallery_get_pet_field( $post_id, 'gender' );
$ref_id  = wcpk_gallery_get_pet_field( $post_id, 'reference_id' );
$type    = wcpk_gallery_get_pet_field( $post_id, 'animal_type' );
$color   = wcpk_gallery_get_pet_field( $post_id, 'coloring' );

$gender_class = $gender ? 'wcpk-chip--' . sanitize_html_class( strtolower( $gender ) ) : '';

$sticker_eyebrow = ! empty( $opts['sticker_eyebrow'] ) ? $opts['sticker_eyebrow'] : 'Forever';
$sticker_label   = ! empty( $opts['sticker_label'] ) ? $opts['sticker_label'] : 'Adopted';
$adopted_when    = get_the_date( 'F Y', $post_id );

$alt = trim( implode( ' ', array_filter( array( $breed, $type, $gender, $color, $ref_id ) ) ) );
?>
<article class="wcpk-card pet pet-info" data-post-id="<?php echo (int) $post_id; ?>" data-ref-id="<?php echo esc_attr( $ref_id ); ?>">
	<a href="<?php echo esc_url( get_permalink( $post_id ) ); ?>" class="wcpk-card__photo img-holder" data-post="<?php echo (int) $post_id; ?>" aria-label="<?php echo esc_attr( $alt ); ?>">
		<img class="pet-info-img" src="<?php echo esc_url( $image ); ?>" alt="<?php echo esc_attr( $alt ); ?>" loading="lazy" decoding="async">
		<span class="wcpk-sticker" aria-hidden="true">
			<small><?php echo esc_html( $sticker_eyebrow ); ?></small>
			<strong><?php echo esc_html( $sticker_label ); ?></strong>
			<?php if ( $adopted_when ) : ?><time><?php echo esc_html( $adopted_when ); ?></time><?php endif ?>
		</span>
	</a>

	<div class="wcpk-card__body">
		<h4 class="wcpk-card__title">
			<a href="<?php echo esc_url( get_permalink( $post_id ) ); ?>"><?php echo esc_html( $breed ); ?><?php if ( $gender ) : ?> <span class="wcpk-card__gender">(<?php echo esc_html( substr( $gender, 0, 1 ) ); ?>)</span><?php endif ?> <?php if ( $ref_id ) : ?><span class="wcpk-card__ref">#<?php echo esc_html( $ref_id ); ?></span><?php endif ?></a>
		</h4>
		<?php if ( $type ) : ?>
		<p class="wcpk-card__meta"><?php echo esc_html( $breed ?: $type ); ?> · <?php echo esc_html( strtoupper( $type ) ); ?></p>
		<?php endif ?>
		<p class="wcpk-card__chips">
			<?php if ( $gender ) : ?>
			<span class="wcpk-chip <?php echo esc_attr( $gender_class ); ?>">
				<?php echo strtolower( $gender ) === 'female' ? '♀' : '♂'; ?> <?php echo esc_html( $gender ); ?>
			</span>
			<?php endif ?>
			<?php if ( $ref_id ) : ?>
			<span class="wcpk-chip wcpk-chip--ref"># <?php echo esc_html( $ref_id ); ?></span>
			<?php endif ?>
		</p>
	</div>

	<?php
	/*
	 * The hidden #petInquiry{ref} helper form is what the petland theme's modal
	 * reads. render_card_cta() emits it alongside the button, but returns an
	 * empty string entirely in MODE_NONE — whereas the JS renderer this template
	 * replaces always emitted the form regardless. Emitting it here when there
	 * is no CTA keeps "No CTA" sites working exactly as they did.
	 */
	if ( $resolver && ! $resolver->has_cta() ) {
		$helper_fields = WCPK_Gallery_Form_Resolver::hidden_fields_for_post( $post_id );

		if ( ! empty( $helper_fields['reference'] ) ) {
			echo WCPK_Gallery_Form_Resolver::render_helper_form( $helper_fields['reference'], $helper_fields ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	echo $resolver ? $resolver->render_card_cta( array( 'post_id' => $post_id ) ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
</article>
