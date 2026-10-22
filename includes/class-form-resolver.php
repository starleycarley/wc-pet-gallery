<?php
/**
 * Form / lead-capture resolver for WooCommerce Pet Gallery.
 *
 * Picks a "best available" inquiry mechanism for the current site and renders
 * either a button that triggers it (modal/dialog) or a link that falls through
 * to a contact page. The plugin emits a standard set of hidden fields that
 * the petland & petland-pep themes already understand:
 *   #petInquiry modal + form#petInquiry{ref} helper form.
 *
 * Resolution order in 'auto' mode:
 *   1. petland-style theme   -> reuse #petInquiry modal
 *   2. Gravity Forms (ID)    -> open plugin <dialog>
 *   3. Contact Form 7 (ID)   -> open plugin <dialog>
 *   4. WPForms (ID)          -> open plugin <dialog>
 *   5. Raw shortcode         -> open plugin <dialog>
 *   6. Inquiry page          -> link with ?pet={ref}
 *   7. Site contact page     -> link with ?pet={ref}
 *   8. Nothing               -> render no CTA
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCPK_Gallery_Form_Resolver {

	const MODE_NONE          = 'none';
	const MODE_THEME_MODAL   = 'theme_modal';
	const MODE_GRAVITYFORMS  = 'gravityforms';
	const MODE_CONTACTFORM7  = 'contactform7';
	const MODE_WPFORMS       = 'wpforms';
	const MODE_SHORTCODE     = 'shortcode';
	const MODE_LINK          = 'link';

	protected $opts;
	protected $resolved_mode;
	protected $resolved_payload;

	public function __construct() {
		$this->opts = wcpk_gallery_get_options();
		$this->resolve();
	}

	public function mode() {
		return $this->resolved_mode;
	}

	public function has_cta() {
		return $this->resolved_mode !== self::MODE_NONE;
	}

	public function needs_plugin_dialog() {
		return in_array( $this->resolved_mode, array(
			self::MODE_GRAVITYFORMS,
			self::MODE_CONTACTFORM7,
			self::MODE_WPFORMS,
			self::MODE_SHORTCODE,
		), true );
	}

	public function get_payload() {
		return $this->resolved_payload;
	}

	/**
	 * Hidden fields contract — matches the petland #petInquiry modal handler.
	 */
	public static function hidden_fields_for_post( $post_id ) {
		$ref   = wcpk_gallery_get_pet_field( $post_id, 'reference_id' );
		$image = get_the_post_thumbnail_url( $post_id, 'medium' );
		$dob   = wcpk_gallery_get_pet_field( $post_id, 'birth_date' );
		$dobf  = '';
		if ( ! empty( $dob ) ) {
			$ts = strtotime( $dob );
			if ( $ts ) {
				$dobf = date( 'm/d/Y', $ts );
			}
		}
		return array(
			'type'      => (string) wcpk_gallery_get_pet_field( $post_id, 'animal_type' ),
			'breed'     => (string) wcpk_gallery_get_pet_field( $post_id, 'breed_name' ),
			'petid'     => (string) wcpk_gallery_get_pet_field( $post_id, 'petid' ),
			'reference' => (string) $ref,
			'gender'    => (string) wcpk_gallery_get_pet_field( $post_id, 'gender' ),
			'color'     => (string) wcpk_gallery_get_pet_field( $post_id, 'coloring' ),
			'dob'       => $dobf,
			'loc'       => (string) wcpk_gallery_get_pet_field( $post_id, 'location' ),
			'image'     => (string) $image,
			'peturl'    => (string) get_permalink( $post_id ),
		);
	}

	/**
	 * Inline hidden helper form that petland JS reads to populate the modal.
	 */
	public static function render_helper_form( $ref, array $fields ) {
		if ( $ref === '' || $ref === null ) {
			return '';
		}
		$id = 'petInquiry' . sanitize_html_class( $ref );
		$out = '<form id="' . esc_attr( $id ) . '" class="wcpk-helper-form" aria-hidden="true" hidden>';
		foreach ( $fields as $name => $value ) {
			$out .= '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
		}
		$out .= '</form>';
		return $out;
	}

	/**
	 * Attribute string for a CTA button / link.
	 */
	public function get_trigger_attributes( $ref, array $hidden = array() ) {
		switch ( $this->resolved_mode ) {
			case self::MODE_THEME_MODAL:
				return array(
					'tag'   => 'button',
					'attrs' => array(
						'type'             => 'button',
						'class'            => 'wcpk-card__cta wcpk-cta',
						'data-bs-toggle'   => 'modal',
						'data-bs-target'   => '#petInquiry',
						'data-ref-id'      => $ref,
						'data-wcpk-mode'   => 'theme_modal',
					),
				);

			case self::MODE_GRAVITYFORMS:
			case self::MODE_CONTACTFORM7:
			case self::MODE_WPFORMS:
			case self::MODE_SHORTCODE:
				return array(
					'tag'   => 'button',
					'attrs' => array(
						'type'             => 'button',
						'class'            => 'wcpk-card__cta wcpk-cta',
						'data-wcpk-open'   => 'wcpk-inquire-dialog',
						'data-ref-id'      => $ref,
						'data-wcpk-mode'   => $this->resolved_mode,
					),
				);

			case self::MODE_LINK:
				$url   = $this->resolved_payload['url'];
				$query = ! empty( $hidden['reference'] ) ? array( 'pet' => $hidden['reference'] ) : array();
				if ( ! empty( $hidden['breed'] ) ) {
					$query['breed'] = $hidden['breed'];
				}
				if ( ! empty( $query ) ) {
					$url = add_query_arg( $query, $url );
				}
				return array(
					'tag'   => 'a',
					'attrs' => array(
						'href'           => $url,
						'class'          => 'wcpk-card__cta wcpk-cta',
						'data-ref-id'    => $ref,
						'data-wcpk-mode' => 'link',
					),
				);

			default:
				return null;
		}
	}

	/**
	 * Render the resolved CTA next to a card.
	 *
	 * $context expects: post_id, label (optional override), ref (optional override).
	 */
	public function render_card_cta( $context ) {
		if ( ! $this->has_cta() ) {
			return '';
		}

		$post_id = isset( $context['post_id'] ) ? (int) $context['post_id'] : 0;
		$label   = ! empty( $context['label'] )
			? $context['label']
			: ( ! empty( $this->opts['card_cta_label'] ) ? $this->opts['card_cta_label'] : __( 'Get one like this', 'petkey-gallery' ) );

		$hidden = $post_id ? self::hidden_fields_for_post( $post_id ) : array();
		$ref    = ! empty( $context['ref'] ) ? $context['ref'] : ( ! empty( $hidden['reference'] ) ? $hidden['reference'] : '' );

		$trigger = $this->get_trigger_attributes( $ref, $hidden );
		if ( ! $trigger ) {
			return '';
		}

		$out  = '';
		if ( $ref ) {
			$out .= self::render_helper_form( $ref, $hidden );
		}

		$attrs = '';
		foreach ( $trigger['attrs'] as $k => $v ) {
			$attrs .= ' ' . $k . '="' . esc_attr( $v ) . '"';
		}

		$icon = '<svg class="wcpk-cta__icon" width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M21 8.5V18a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="m3 8 9 6 9-6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M3 8V6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
		$out .= '<' . $trigger['tag'] . $attrs . '>' . $icon . '<span>' . esc_html( $label ) . '</span></' . $trigger['tag'] . '>';
		return $out;
	}

	/**
	 * Render a primary "Inquire about a pup" CTA for the hero (no specific pet).
	 */
	public function render_hero_cta() {
		if ( ! $this->has_cta() ) {
			return '';
		}
		$label = ! empty( $this->opts['cta_label'] )
			? $this->opts['cta_label']
			: __( 'Inquire about a pup', 'petkey-gallery' );

		switch ( $this->resolved_mode ) {
			case self::MODE_THEME_MODAL:
				$attrs = ' type="button" class="wcpk-cta wcpk-cta--primary" data-bs-toggle="modal" data-bs-target="#petInquiry" data-wcpk-mode="theme_modal"';
				$tag   = 'button';
				break;

			case self::MODE_GRAVITYFORMS:
			case self::MODE_CONTACTFORM7:
			case self::MODE_WPFORMS:
			case self::MODE_SHORTCODE:
				$attrs = ' type="button" class="wcpk-cta wcpk-cta--primary" data-wcpk-open="wcpk-inquire-dialog" data-wcpk-mode="' . esc_attr( $this->resolved_mode ) . '"';
				$tag   = 'button';
				break;

			case self::MODE_LINK:
				$attrs = ' href="' . esc_url( $this->resolved_payload['url'] ) . '" class="wcpk-cta wcpk-cta--primary"';
				$tag   = 'a';
				break;

			default:
				return '';
		}

		$icon = '<svg class="wcpk-cta__icon" width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M21 8.5V18a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="m3 8 9 6 9-6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M3 8V6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
		return '<' . $tag . $attrs . '>' . $icon . '<span>' . esc_html( $label ) . '</span></' . $tag . '>';
	}

	/**
	 * Render the plugin's own <dialog> if one is needed. Empty string otherwise.
	 */
	public function render_dialog() {
		if ( ! $this->needs_plugin_dialog() ) {
			return '';
		}
		return wcpk_gallery_render_template( 'dialog', array(
			'resolver' => $this,
			'opts'     => $this->opts,
		) );
	}

	/**
	 * The form markup that goes inside the plugin dialog.
	 */
	public function render_form_markup() {
		switch ( $this->resolved_mode ) {
			case self::MODE_GRAVITYFORMS:
				$id = (int) $this->resolved_payload['id'];
				if ( ! $id ) return '';
				return do_shortcode( '[gravityform id="' . $id . '" title="false" description="false" ajax="true"]' );

			case self::MODE_CONTACTFORM7:
				$id = (int) $this->resolved_payload['id'];
				if ( ! $id ) return '';
				return do_shortcode( '[contact-form-7 id="' . $id . '"]' );

			case self::MODE_WPFORMS:
				$id = (int) $this->resolved_payload['id'];
				if ( ! $id ) return '';
				return do_shortcode( '[wpforms id="' . $id . '"]' );

			case self::MODE_SHORTCODE:
				$sc = (string) $this->resolved_payload['shortcode'];
				if ( $sc === '' ) return '';
				return do_shortcode( $sc );
		}
		return '';
	}

	/* ---------------- internal ---------------- */

	protected function resolve() {
		$source = isset( $this->opts['form_source'] ) ? $this->opts['form_source'] : 'auto';

		if ( $source === 'none' ) {
			$this->resolved_mode    = self::MODE_NONE;
			$this->resolved_payload = array();
			return;
		}

		if ( $source === 'theme' ) {
			$this->resolved_mode    = self::MODE_THEME_MODAL;
			$this->resolved_payload = array();
			return;
		}

		if ( $source === 'gravityforms' && ! empty( $this->opts['form_id'] ) ) {
			$this->resolved_mode    = self::MODE_GRAVITYFORMS;
			$this->resolved_payload = array( 'id' => (int) $this->opts['form_id'] );
			return;
		}

		if ( $source === 'contactform7' && ! empty( $this->opts['form_id'] ) ) {
			$this->resolved_mode    = self::MODE_CONTACTFORM7;
			$this->resolved_payload = array( 'id' => (int) $this->opts['form_id'] );
			return;
		}

		if ( $source === 'wpforms' && ! empty( $this->opts['form_id'] ) ) {
			$this->resolved_mode    = self::MODE_WPFORMS;
			$this->resolved_payload = array( 'id' => (int) $this->opts['form_id'] );
			return;
		}

		if ( $source === 'shortcode' && ! empty( $this->opts['form_shortcode'] ) ) {
			$this->resolved_mode    = self::MODE_SHORTCODE;
			$this->resolved_payload = array( 'shortcode' => $this->opts['form_shortcode'] );
			return;
		}

		// auto resolution.
		if ( $this->detect_theme_modal() ) {
			$this->resolved_mode    = self::MODE_THEME_MODAL;
			$this->resolved_payload = array();
			return;
		}

		if ( ! empty( $this->opts['form_id'] ) && class_exists( 'RGFormsModel' ) ) {
			$this->resolved_mode    = self::MODE_GRAVITYFORMS;
			$this->resolved_payload = array( 'id' => (int) $this->opts['form_id'] );
			return;
		}

		if ( ! empty( $this->opts['form_shortcode'] ) ) {
			$this->resolved_mode    = self::MODE_SHORTCODE;
			$this->resolved_payload = array( 'shortcode' => $this->opts['form_shortcode'] );
			return;
		}

		$page_url = $this->find_inquiry_page_url();
		if ( $page_url ) {
			$this->resolved_mode    = self::MODE_LINK;
			$this->resolved_payload = array( 'url' => $page_url );
			return;
		}

		$this->resolved_mode    = self::MODE_NONE;
		$this->resolved_payload = array();
	}

	protected function detect_theme_modal() {
		if ( ! function_exists( 'get_field' ) ) {
			return false;
		}
		$petland_form = get_field( 'gform_pet_inquiry_popup', 'option' );
		if ( ! empty( $petland_form ) ) {
			return true;
		}
		return false;
	}

	protected function find_inquiry_page_url() {
		if ( ! empty( $this->opts['inquiry_page'] ) ) {
			$slug = sanitize_title( $this->opts['inquiry_page'] );
			$page = get_page_by_path( $slug );
			if ( $page && $page->post_status === 'publish' ) {
				return get_permalink( $page );
			}
		}

		foreach ( array( 'contact', 'contact-us', 'inquire', 'contact-us/' ) as $slug ) {
			$page = get_page_by_path( $slug );
			if ( $page && $page->post_status === 'publish' ) {
				return get_permalink( $page );
			}
		}

		$contact = get_posts( array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'title'          => 'Contact',
		) );
		if ( ! empty( $contact ) ) {
			return get_permalink( $contact[0] );
		}

		return '';
	}
}
