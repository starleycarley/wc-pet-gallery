<?php
/**
 * Plugin-owned <dialog> used when the active theme does not expose its own
 * #petInquiry modal. Triggered by buttons with data-wcpk-open="wcpk-inquire-dialog".
 *
 * Variables: $resolver, $opts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$close_label = __( 'Close', 'petkey-gallery' );
?>
<dialog id="wcpk-inquire-dialog" class="wcpk-dialog" data-wcpk-dialog aria-labelledby="wcpk-dialog-title">
	<form method="dialog" class="wcpk-dialog__inner">
		<header class="wcpk-dialog__head">
			<div class="wcpk-dialog__pet" data-wcpk-pet>
				<img data-wcpk-pet-image alt="" width="64" height="64">
				<div>
					<p class="wcpk-dialog__pet-title" data-wcpk-pet-title></p>
					<p class="wcpk-dialog__pet-sub" data-wcpk-pet-sub></p>
				</div>
			</div>
			<button type="button" class="wcpk-dialog__close" data-wcpk-dialog-close aria-label="<?php echo esc_attr( $close_label ); ?>">
				<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
			</button>
		</header>
		<h2 id="wcpk-dialog-title" class="wcpk-dialog__title">
			<?php esc_html_e( 'Tell us a bit about you', 'petkey-gallery' ); ?>
		</h2>
		<p class="wcpk-dialog__lede">
			<?php esc_html_e( 'A team member will reach out with available pups that match what you’re looking for.', 'petkey-gallery' ); ?>
		</p>
		<div class="wcpk-dialog__form" data-wcpk-form-host>
			<?php echo $resolver->render_form_markup(); ?>
		</div>
	</form>
</dialog>
