<?php
/**
 * The settings screen.
 *
 * Rewritten in 1.7.0. The previous version was a single ~400-line form holding
 * 31 fields with no tabs and no provider gating: the Petkey-only cron section,
 * the Petkey-worded accept-list, "Run cron now" and a 100-row diagnostic table
 * all rendered on UKM and Pinogy sites where they did nothing. Template-routing
 * controls were filed under a heading called "Grid", and "Items per page" — the
 * live page size — sat under "Legacy options" below a hundred rows of table.
 *
 * Three structural changes, beyond moving things around:
 *
 * 1. Five template/theming switches (archive_override, single_override,
 *    single_show_breed_info, single_show_related, force_clean_layout) become
 *    ONE "Layout" choice with a readout of what is actually rendering. They
 *    interacted invisibly and the only way to learn their effect was to save
 *    and go look at the front end. All five option keys are still written.
 *
 * 2. POST moves out of the render callback into `load-{$hook}`, so there is a
 *    real POST-redirect-GET and `add_settings_error()` works. Previously a
 *    refresh re-submitted the form and nothing could report a validation
 *    failure — a bad colour was discarded in silence.
 *
 * 3. The landing tab answers "is this working?" instead of saying
 *    "No sync recorded yet."
 *
 * The hand-rolled saver is kept deliberately. register_setting()/options.php
 * nulls any registered option absent from the submitted form, which is the same
 * class of bug as the one that blanked the grid.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PetkeyGallerySettings' ) ) :

class PetkeyGallerySettings {

	/*
	 * Public properties are theme contract: child themes read
	 * $petkey_gallery_settings->petkey_gallery_enquiry and friends directly.
	 * None may be removed or renamed.
	 */
	public $petkey_gallery_enquiry  = '';
	public $petkey_gallery_numposts = '';
	public $petkey_gallery_types    = '';

	public $page_title         = 'Pet Photo Gallery';
	public $form_source        = 'auto';
	public $form_id            = '';
	public $form_shortcode     = '';
	public $inquiry_page       = '';
	public $hero_enabled       = 'yes';
	public $hero_eyebrow       = 'The Family Album';
	public $hero_heading       = 'Pups who found their forever happily-ever-after.';
	public $hero_heading_accent = 'forever';
	public $hero_intro         = '';
	public $cta_label          = 'Inquire about a pup';
	public $card_cta_label     = 'Get one like this';
	public $sticker_label      = 'Adopted';
	public $sticker_eyebrow    = 'Forever';
	public $stats_enabled      = 'yes';
	public $forever_homes_base = 0;
	public $grid_eyebrow       = 'Recently Adopted';
	public $grid_heading       = 'Meet the latest love stories';
	public $grid_heading_accent = 'love stories';
	public $color              = '';
	public $columns            = 4;
	public $archive_override   = 'yes';
	public $single_override    = 'auto';
	public $single_show_breed_info = 'yes';
	public $single_show_related = 'yes';
	public $force_clean_layout = 'no';
	public $accepted_statuses_raw = 'adopted, sold';
	public $skip_cooling_off = 'no';

	/*
	 * Declared because three themes read them off the global today, where they
	 * are undefined properties and emit a deprecation notice on PHP 8.2+.
	 */
	public $custom_form      = '';
	public $custom_form_code = '';

	/** @var string Admin page hook suffix, captured from add_*_page(). */
	protected $hook = '';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ), 50 );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
		$this->get_settings();
	}

	public function admin_menu() {
		global $admin_page_hooks;

		// Attach under whichever parent sync plugin registered a menu.
		// wc-petkey-sync uses `wc-petkey-setting`, wc-unified-km `wc-ukm-setting`,
		// wc-pinogy-sync `pinogy-setting`. Fall back to a top-level menu so the
		// page stays reachable if none of them is present.
		$candidates = array( 'wc-petkey-setting', 'wc-ukm-setting', 'pinogy-setting' );
		$parent     = null;

		foreach ( $candidates as $slug ) {
			if ( is_array( $admin_page_hooks ) && isset( $admin_page_hooks[ $slug ] ) ) {
				$parent = $slug;
				break;
			}
		}

		if ( $parent !== null ) {
			$this->hook = add_submenu_page(
				$parent,
				__( 'Gallery', 'petkey-gallery' ),
				__( 'Gallery', 'petkey-gallery' ),
				'manage_options',
				'petkey-setting-gallery',
				array( $this, 'settings_page' )
			);
		} else {
			$this->hook = add_menu_page(
				__( 'WooCommerce Pet Gallery', 'petkey-gallery' ),
				__( 'Pet Gallery', 'petkey-gallery' ),
				'manage_options',
				'petkey-setting-gallery',
				array( $this, 'settings_page' ),
				'dashicons-images-alt',
				58
			);
		}

		if ( $this->hook ) {
			// Saving here rather than inside settings_page() is what makes
			// POST-redirect-GET possible: `load-{$hook}` runs before any output.
			add_action( 'load-' . $this->hook, array( $this, 'maybe_save' ) );
		}
	}

	public function admin_assets( $hook ) {
		if ( strpos( (string) $hook, 'petkey-setting-gallery' ) === false ) {
			return;
		}

		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
	}

	/* ============================================================
	 * Tabs
	 * ============================================================ */

	public function tabs() {
		return array(
			'status'    => __( 'Status', 'petkey-gallery' ),
			'gallery'   => __( 'Gallery', 'petkey-gallery' ),
			'inquiries' => __( 'Inquiries', 'petkey-gallery' ),
			'advanced'  => __( 'Advanced', 'petkey-gallery' ),
		);
	}

	public function current_tab() {
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'status';
		$tabs = $this->tabs();

		return isset( $tabs[ $tab ] ) ? $tab : 'status';
	}

	protected function tab_url( $tab ) {
		return add_query_arg(
			array(
				'page' => 'petkey-setting-gallery',
				'tab'  => $tab,
			),
			admin_url( 'admin.php' )
		);
	}

	/* ============================================================
	 * Save
	 * ============================================================ */

	public function maybe_save() {

		if ( empty( $_POST['petkey_gallery_settings'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'petkey-gallery' ) );
		}

		$tab = isset( $_POST['wcpk_tab'] ) ? sanitize_key( wp_unslash( $_POST['wcpk_tab'] ) ) : 'gallery';
		check_admin_referer( 'petkey_gallery_settings_' . $tab );

		$registry = wcpk_gallery_option_registry();
		$provider = WCPK_Gallery_Status::provider();

		// Raw posted values, used by show_if so a condition is evaluated against
		// what is being saved rather than what is already stored.
		$posted = array();
		foreach ( array_keys( $registry ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$posted[ $key ] = is_array( $_POST[ $key ] )
					? wp_unslash( $_POST[ $key ] )
					: wp_unslash( (string) $_POST[ $key ] );
			}
		}

		$pre_save_form_id = get_option( 'petkey_gallery_form_id', '' );

		foreach ( $registry as $key => $field ) {

			if ( ( isset( $field['tab'] ) ? $field['tab'] : 'gallery' ) !== $tab ) {
				continue;
			}

			if ( ! wcpk_gallery_option_applies( $key, $provider ) ) {
				continue;
			}

			/*
			 * Skip a field whose condition is not met. Without this, choosing
			 * "Theme modal" as the form source and pressing Save would wipe
			 * petkey_gallery_form_id — the input is not rendered, so nothing is
			 * posted, and an unconditional saver reads that as "empty".
			 */
			if ( ! wcpk_gallery_option_visible( $key, $posted ) ) {
				continue;
			}

			$type = isset( $field['type'] ) ? $field['type'] : 'text';

			if ( 'checkbox' === $type ) {
				update_option( $key, isset( $_POST[ $key ] ) ? 'yes' : 'no' );
				continue;
			}

			if ( 'types' === $type ) {
				$this->save_types( $key );
				continue;
			}

			if ( ! array_key_exists( $key, $posted ) ) {
				continue;
			}

			$sanitize = isset( $field['sanitize'] ) ? $field['sanitize'] : 'sanitize_text_field';
			$value    = is_callable( $sanitize ) ? call_user_func( $sanitize, $posted[ $key ] ) : $posted[ $key ];

			// A value the sanitizer threw away entirely is a rejection, not a
			// clearing. Report it and keep what was there — silently discarding
			// a mistyped colour is one of the things this rewrite set out to fix.
			if ( '' === $value && '' !== trim( (string) $posted[ $key ] ) && 'color' === $type ) {
				add_settings_error(
					'wcpk_gallery',
					'bad_color',
					__( 'That is not a valid colour, so the previous one was kept. Use a hex value like #1a2a44.', 'petkey-gallery' ),
					'error'
				);
				continue;
			}

			update_option( $key, $value );
		}

		if ( 'gallery' === $tab && isset( $_POST['wcpk_layout_preset'] ) ) {
			$preset = sanitize_key( wp_unslash( $_POST['wcpk_layout_preset'] ) );

			/*
			 * Only write when the choice actually changed. A preset is a lossy
			 * summary of three options, so re-applying the detected value on
			 * every unrelated Gallery save would quietly normalise any
			 * configuration that does not map cleanly onto one — including a
			 * deliberate half-and-half setup.
			 */
			if ( $preset === wcpk_gallery_layout_preset() ) {
				$preset = '';
			}

			if ( '' !== $preset && ! wcpk_gallery_apply_layout_preset( $preset ) ) {
				add_settings_error(
					'wcpk_gallery',
					'bad_layout',
					__( 'That layout option was not recognised, so the layout was left unchanged.', 'petkey-gallery' ),
					'error'
				);
			}
		}

		if ( 'advanced' === $tab && isset( $_POST['petkey_gallery_source_statuses_present'] ) ) {
			$statuses = isset( $_POST['petkey_gallery_source_statuses'] )
				? array_map( 'sanitize_key', (array) wp_unslash( $_POST['petkey_gallery_source_statuses'] ) )
				: array();

			/*
			 * The whitelist has to include what is already saved. The chooser
			 * only lists statuses that currently have at least one product, so
			 * a seasonal status that happens to be empty today would otherwise
			 * be invisible in the UI AND stripped on the next save of an
			 * unrelated field.
			 */
			$valid = array_unique(
				array_merge(
					array_keys( WCPK_Gallery_Status::product_status_choices() ),
					WCPK_Gallery_Status::source_statuses()
				)
			);

			$statuses = array_values( array_intersect( $statuses, $valid ) );

			if ( empty( $statuses ) ) {
				add_settings_error(
					'wcpk_gallery',
					'no_source_status',
					__( 'At least one source status is required, so the previous selection was kept.', 'petkey-gallery' ),
					'error'
				);
			} else {
				update_option( 'petkey_gallery_source_statuses', $statuses );
			}
		}

		if ( 'advanced' === $tab && class_exists( 'WCPK_Gallery_UKM_Ingest' ) && ! wcpk_gallery_ukm_gallery_active() ) {
			// Routed through set_mode() rather than update_option() so that
			// arming mirroring also stamps the watermark — without it, the
			// recurring scan would treat the entire historical backlog as new
			// work and defeat the "backfill only when asked" gate.
			WCPK_Gallery_UKM_Ingest::set_mode( ! empty( $_POST['wcpk_gallery_ukm_ingest_mode'] ) );

			if ( isset( $_POST['wcpk_gallery_ukm_backfill_months'] ) ) {
				update_option(
					'wcpk_gallery_ukm_backfill_months',
					max( 0, (int) wp_unslash( $_POST['wcpk_gallery_ukm_backfill_months'] ) ),
					false
				);
			}
		}

		$this->sync_legacy_enquiry( $tab, $pre_save_form_id );

		if ( function_exists( 'wcpk_gallery_clear_count_cache' ) ) {
			wcpk_gallery_clear_count_cache();
		}

		WCPK_Gallery_Status::flush();

		if ( ! get_settings_errors( 'wcpk_gallery' ) ) {
			add_settings_error( 'wcpk_gallery', 'saved', __( 'Settings saved.', 'petkey-gallery' ), 'success' );
		}

		// add_settings_error() writes to a global that does not survive the
		// redirect; WP's own settings pages persist it in a transient keyed to
		// the user, and so do we.
		set_transient( 'settings_errors', get_settings_errors(), 30 );

		wp_safe_redirect( add_query_arg( 'settings-updated', 'true', $this->tab_url( $tab ) ) );
		exit;
	}

	/**
	 * Pet types. "Show every type" stores an empty array; "Only these" with
	 * nothing ticked is a validation error rather than a silent write.
	 *
	 * The old code wrote the CODE => LABEL map in that case, which matched no
	 * stored animal_type and emptied the entire front-end grid — and then drew
	 * every checkbox unticked, so the next save repeated it.
	 */
	protected function save_types( $key ) {

		$mode = isset( $_POST['wcpk_types_mode'] ) ? sanitize_key( wp_unslash( $_POST['wcpk_types_mode'] ) ) : 'all';

		if ( 'all' === $mode ) {
			update_option( $key, array() );
			return;
		}

		$selected = isset( $_POST[ $key ] ) ? (array) wp_unslash( $_POST[ $key ] ) : array();
		$codes    = wcpk_gallery_sanitize_types( $selected );

		if ( empty( $codes ) && ! empty( $selected ) ) {
			// Everything was ticked, which means the same as "show every type".
			update_option( $key, array() );
			return;
		}

		if ( empty( $selected ) ) {
			add_settings_error(
				'wcpk_gallery',
				'no_types',
				__( 'Pick at least one pet type, or choose "Show every pet type". Your previous selection was kept.', 'petkey-gallery' ),
				'error'
			);
			return;
		}

		update_option( $key, $codes );
	}

	/**
	 * `petkey_gallery_enquiry` duplicates `petkey_gallery_form_id`, and seven
	 * theme files read the legacy key straight off this object. Mirror forward
	 * only when the legacy value is empty or still agrees with the pre-save
	 * form id — when they have deliberately diverged, leave it alone and say so
	 * rather than overwriting someone's override.
	 */
	protected function sync_legacy_enquiry( $tab, $pre_save_form_id ) {

		if ( 'inquiries' !== $tab ) {
			return;
		}

		$legacy  = (string) get_option( 'petkey_gallery_enquiry', '' );
		$form_id = (string) get_option( 'petkey_gallery_form_id', '' );

		// Never overwrite a non-empty legacy value with an empty one: seven
		// theme files read it, and losing it breaks their inquiry forms.
		if ( '' === $form_id && '' !== $legacy ) {
			return;
		}

		if ( '' === $legacy || $legacy === (string) $pre_save_form_id ) {
			update_option( 'petkey_gallery_enquiry', $form_id );
			return;
		}

		if ( $legacy !== $form_id ) {
			add_settings_error(
				'wcpk_gallery',
				'legacy_enquiry_mismatch',
				sprintf(
					/* translators: 1: legacy form id, 2: current form id. */
					__( 'The legacy form ID (%1$s) differs from the Form ID (%2$s) and was left as-is. Older themes read the legacy value — check Advanced → Compatibility if an inquiry form is showing the wrong fields.', 'petkey-gallery' ),
					$legacy,
					$form_id
				),
				'warning'
			);
		}
	}

	/* ============================================================
	 * Read
	 * ============================================================ */

	public function get_settings() {

		$this->petkey_gallery_enquiry  = get_option( 'petkey_gallery_enquiry', '' );
		$this->petkey_gallery_numposts = get_option( 'petkey_gallery_numposts', 12 );
		$this->petkey_gallery_types    = get_option( 'petkey_gallery_types', array() );

		$this->page_title          = get_option( 'petkey_gallery_page_title', 'Pet Photo Gallery' );
		$this->form_source         = get_option( 'petkey_gallery_form_source', 'auto' );
		$this->form_id             = get_option( 'petkey_gallery_form_id', $this->petkey_gallery_enquiry );
		$this->form_shortcode      = get_option( 'petkey_gallery_form_shortcode', '' );
		$this->inquiry_page        = get_option( 'petkey_gallery_inquiry_page', '' );
		$this->hero_enabled        = get_option( 'petkey_gallery_hero_enabled', 'yes' );
		$this->hero_eyebrow        = get_option( 'petkey_gallery_hero_eyebrow', $this->hero_eyebrow );
		$this->hero_heading        = get_option( 'petkey_gallery_hero_heading', $this->hero_heading );
		$this->hero_heading_accent = get_option( 'petkey_gallery_hero_heading_accent', $this->hero_heading_accent );
		$this->hero_intro          = get_option( 'petkey_gallery_hero_intro', wcpk_gallery_option_default( 'petkey_gallery_hero_intro' ) );
		$this->cta_label           = get_option( 'petkey_gallery_cta_label', $this->cta_label );
		$this->card_cta_label      = get_option( 'petkey_gallery_card_cta_label', $this->card_cta_label );
		$this->sticker_label       = get_option( 'petkey_gallery_sticker_label', $this->sticker_label );
		$this->sticker_eyebrow     = get_option( 'petkey_gallery_sticker_eyebrow', $this->sticker_eyebrow );
		$this->stats_enabled       = get_option( 'petkey_gallery_stats_enabled', 'yes' );
		$this->forever_homes_base  = (int) get_option( 'petkey_gallery_forever_homes_base', 0 );
		$this->grid_eyebrow        = get_option( 'petkey_gallery_grid_eyebrow', $this->grid_eyebrow );
		$this->grid_heading        = get_option( 'petkey_gallery_grid_heading', $this->grid_heading );
		$this->grid_heading_accent = get_option( 'petkey_gallery_grid_heading_accent', $this->grid_heading_accent );
		$this->color               = get_option( 'petkey_gallery_color', '' );
		$this->columns             = (int) get_option( 'petkey_gallery_columns', 4 );

		$this->archive_override        = get_option( 'petkey_gallery_archive_override', 'yes' );
		$this->single_override         = get_option( 'petkey_gallery_single_override', 'auto' );
		$this->single_show_breed_info  = get_option( 'petkey_gallery_single_show_breed_info', 'yes' );
		$this->single_show_related     = get_option( 'petkey_gallery_single_show_related', 'yes' );
		$this->force_clean_layout      = get_option( 'petkey_gallery_force_clean_layout', 'no' );

		$this->accepted_statuses_raw = get_option( 'petkey_gallery_accepted_statuses', 'adopted, sold' );
		$this->skip_cooling_off      = get_option( 'petkey_gallery_skip_cooling_off', 'no' );

		$this->custom_form      = get_option( 'petkey_gallery_form_shortcode', '' );
		$this->custom_form_code = $this->custom_form;
	}

	/* ============================================================
	 * Render
	 * ============================================================ */

	public function settings_page() {

		$this->get_settings();
		$tab = $this->current_tab();

		?>
		<div class="wrap wcpk-gallery-admin">
			<h1><?php esc_html_e( 'Pet Gallery', 'petkey-gallery' ); ?></h1>

			<nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'Settings tabs', 'petkey-gallery' ); ?>">
				<?php foreach ( $this->tabs() as $slug => $label ) : ?>
					<a href="<?php echo esc_url( $this->tab_url( $slug ) ); ?>"
						class="nav-tab<?php echo $slug === $tab ? ' nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach ?>
			</nav>

			<?php settings_errors( 'wcpk_gallery' ); ?>

			<?php
			switch ( $tab ) {
				case 'gallery':
					$this->render_gallery_tab();
					break;
				case 'inquiries':
					$this->render_inquiries_tab();
					break;
				case 'advanced':
					$this->render_advanced_tab();
					break;
				default:
					$this->render_status_tab();
			}
			?>
		</div>
		<script>
			(function ($) {
				$(function () {
					if ($.fn.wpColorPicker) { $('.wcpk-color-picker').wpColorPicker(); }

					// Conditional fields: mirror the server's show_if rules so the
					// form reflects a change before it is saved.
					function sync() {
						$('[data-wcpk-show-if]').each(function () {
							var rule = $(this).data('wcpk-show-if');
							var el = $('[name="' + rule.key + '"]');
							var value = el.is(':checkbox') ? (el.is(':checked') ? 'yes' : 'no') : el.val();
							$(this).toggle($.inArray(String(value), rule.values.map(String)) !== -1);
						});
						$('[data-wcpk-types-list]').toggle($('[name="wcpk_types_mode"]:checked').val() === 'some');
					}
					$(document).on('change', 'select, input[type=checkbox], input[type=radio]', sync);
					sync();
				});
			})(jQuery);
		</script>
		<?php
	}

	protected function form_open( $tab ) {
		?>
		<form method="post" action="">
			<input type="hidden" name="petkey_gallery_settings" value="1">
			<input type="hidden" name="wcpk_tab" value="<?php echo esc_attr( $tab ); ?>">
			<?php wp_nonce_field( 'petkey_gallery_settings_' . $tab ); ?>
		<?php
	}

	protected function form_close() {
		submit_button( __( 'Save changes', 'petkey-gallery' ) );
		echo '</form>';
	}

	/**
	 * Wrapper for one row, carrying the show_if rule for the inline script.
	 */
	protected function row_open( $key, $label, $id = '' ) {

		$registry = wcpk_gallery_option_registry();
		$attr     = '';

		if ( ! empty( $registry[ $key ]['show_if'] ) ) {
			$rule = array(
				'key'    => $registry[ $key ]['show_if'][0],
				'values' => array_values( (array) $registry[ $key ]['show_if'][1] ),
			);
			$attr = " data-wcpk-show-if='" . esc_attr( wp_json_encode( $rule ) ) . "'";
		}

		echo '<tr' . $attr . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<th scope="row"><label for="' . esc_attr( $id ? $id : $key ) . '">' . esc_html( $label ) . '</label></th><td>';
	}

	protected function row_close( $help = '' ) {
		if ( $help ) {
			echo '<p class="description">' . wp_kses_post( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	protected function text_row( $key, $label, $help = '', $type = 'text', $extra = '' ) {
		$value = get_option( $key, wcpk_gallery_option_default( $key ) );
		$this->row_open( $key, $label );
		printf(
			'<input type="%s" class="regular-text" id="%s" name="%s" value="%s"%s>',
			esc_attr( $type ),
			esc_attr( $key ),
			esc_attr( $key ),
			esc_attr( (string) $value ),
			$extra // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
		$this->row_close( $help );
	}

	protected function textarea_row( $key, $label, $help = '' ) {
		$value = get_option( $key, wcpk_gallery_option_default( $key ) );
		$this->row_open( $key, $label );
		printf(
			'<textarea id="%s" name="%s" rows="4" class="large-text">%s</textarea>',
			esc_attr( $key ),
			esc_attr( $key ),
			esc_textarea( (string) $value )
		);
		$this->row_close( $help );
	}

	protected function checkbox_row( $key, $label, $caption, $help = '' ) {
		$value = get_option( $key, wcpk_gallery_option_default( $key ) );
		$this->row_open( $key, $label );
		printf(
			'<label><input type="checkbox" id="%s" name="%s" value="yes" %s> %s</label>',
			esc_attr( $key ),
			esc_attr( $key ),
			checked( 'yes', $value, false ),
			esc_html( $caption )
		);
		$this->row_close( $help );
	}

	protected function select_row( $key, $label, $choices, $help = '' ) {
		$value = (string) get_option( $key, wcpk_gallery_option_default( $key ) );
		$this->row_open( $key, $label );
		echo '<select id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '">';
		foreach ( $choices as $choice_value => $choice_label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( (string) $choice_value ),
				selected( (string) $choice_value, $value, false ),
				esc_html( $choice_label )
			);
		}
		echo '</select>';
		$this->row_close( $help );
	}

	/* ============================================================
	 * Tab: Status — no settings, just the answer to "is this working?"
	 * ============================================================ */

	protected function render_status_tab() {

		$counts   = WCPK_Gallery_Status::counts();
		$schedule = WCPK_Gallery_Status::schedule();
		$updates  = WCPK_Gallery_Status::updates();
		$problems = WCPK_Gallery_Status::problems();
		$health   = WCPK_Gallery_Status::last_health();
		$archive  = get_post_type_archive_link( 'pet-gallery' );
		$fmt      = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		?>
		<?php if ( ! empty( $problems ) ) : ?>
			<?php foreach ( $problems as $problem ) : ?>
			<div class="notice notice-<?php echo esc_attr( 'error' === $problem['level'] ? 'error' : 'warning' ); ?> inline">
				<p>
					<?php echo esc_html( $problem['text'] ); ?>
					<?php if ( ! empty( $problem['action'] ) ) : ?>
						<a class="button button-small" href="<?php echo esc_url( $problem['action'] ); ?>"><?php echo esc_html( $problem['action_label'] ); ?></a>
					<?php endif ?>
				</p>
			</div>
			<?php endforeach ?>
		<?php else : ?>
			<div class="notice notice-success inline"><p><?php esc_html_e( 'No problems detected.', 'petkey-gallery' ); ?></p></div>
		<?php endif ?>

		<table class="widefat striped" style="max-width:860px;margin-top:1rem">
			<tbody>
				<tr>
					<th style="width:230px"><?php esc_html_e( 'Pet records come from', 'petkey-gallery' ); ?></th>
					<td>
						<strong><?php echo esc_html( WCPK_Gallery_Status::provider_label() ); ?></strong>
						<?php if ( $counts['ukm_gallery'] ) : ?>
							<br><span class="description"><?php esc_html_e( 'The separate UnifiedKM Pet Gallery plugin is also active and owns the mirroring on this site.', 'petkey-gallery' ); ?></span>
						<?php endif ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Pets in the gallery', 'petkey-gallery' ); ?></th>
					<td>
						<strong><?php echo esc_html( number_format_i18n( $counts['in_gallery'] ) ); ?></strong>
						<?php if ( $counts['hidden'] > 0 ) : ?>
							<?php
							printf(
								/* translators: %s: number of pets hidden by the type filter. */
								' &middot; ' . esc_html__( '%s hidden by the pet-type filter', 'petkey-gallery' ),
								esc_html( number_format_i18n( $counts['hidden'] ) )
							);
							?>
						<?php endif ?>
					</td>
				</tr>
				<?php if ( null !== $counts['eligible'] ) : ?>
				<tr>
					<th><?php esc_html_e( 'Pets that qualify', 'petkey-gallery' ); ?></th>
					<td>
						<?php echo esc_html( number_format_i18n( (int) $counts['eligible'] ) ); ?>
						<span class="description">
							<?php
							printf(
								/* translators: %s: comma-separated list of product statuses. */
								esc_html__( '(status: %s, and has a photo)', 'petkey-gallery' ),
								esc_html( implode( ', ', (array) $counts['statuses'] ) )
							);
							?>
						</span>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Not yet in the gallery', 'petkey-gallery' ); ?></th>
					<td>
						<?php if ( $counts['ukm_gallery'] ) : ?>
							<span class="description"><?php esc_html_e( 'Managed by the UnifiedKM Pet Gallery plugin.', 'petkey-gallery' ); ?></span>
						<?php else : ?>
							<?php echo esc_html( number_format_i18n( (int) $counts['unmirrored'] ) ); ?>
						<?php endif ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Skipped — no photo', 'petkey-gallery' ); ?></th>
					<td><?php echo esc_html( number_format_i18n( (int) $counts['no_photo'] ) ); ?></td>
				</tr>
				<?php endif ?>
				<tr>
					<th><?php esc_html_e( 'Next automatic run', 'petkey-gallery' ); ?></th>
					<td>
						<?php
						if ( 'order_hooks' === $schedule['mode'] ) {
							esc_html_e( 'When an order completes (no schedule needed).', 'petkey-gallery' );
						} elseif ( $schedule['next'] > 0 ) {
							echo esc_html( wp_date( $fmt, $schedule['next'] ) );
						} else {
							esc_html_e( 'Not scheduled.', 'petkey-gallery' );
						}
						?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Last run', 'petkey-gallery' ); ?></th>
					<td><?php echo $schedule['last'] ? esc_html( wp_date( $fmt, $schedule['last'] ) ) : esc_html__( 'Never', 'petkey-gallery' ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Gallery page', 'petkey-gallery' ); ?></th>
					<td>
						<?php if ( $archive ) : ?>
							<a href="<?php echo esc_url( $archive ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $archive ); ?></a>
						<?php else : ?>
							<?php esc_html_e( 'No public URL.', 'petkey-gallery' ); ?>
						<?php endif ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Live check', 'petkey-gallery' ); ?></th>
					<td>
						<?php if ( is_array( $health ) ) : ?>
							<?php
							printf(
								/* translators: 1: HTTP status, 2: number of cards, 3: date. */
								esc_html__( 'HTTP %1$d, %2$s cards &middot; %3$s', 'petkey-gallery' ),
								(int) $health['status'],
								esc_html( number_format_i18n( (int) $health['cards'] ) ),
								esc_html( wp_date( $fmt, (int) $health['time'] ) )
							);
							?>
						<?php else : ?>
							<span class="description"><?php esc_html_e( 'Not run yet.', 'petkey-gallery' ); ?></span>
						<?php endif ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-left:.5rem">
							<input type="hidden" name="action" value="wcpk_gallery_health_check">
							<?php wp_nonce_field( 'wcpk_gallery_health_check' ); ?>
							<button type="submit" class="button button-small"><?php esc_html_e( 'Load the gallery and count the cards', 'petkey-gallery' ); ?></button>
						</form>
						<p class="description"><?php esc_html_e( 'Fetches the public gallery page exactly as a visitor would. Configuration can look perfect while the page is blank — this is the check that tells them apart.', 'petkey-gallery' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Updates', 'petkey-gallery' ); ?></th>
					<td>
						<?php
						printf(
							/* translators: 1: version, 2: release channel. */
							esc_html__( 'Version %1$s on the %2$s channel.', 'petkey-gallery' ),
							esc_html( $updates['version'] ),
							esc_html( $updates['channel'] )
						);
						?>
						<?php if ( ! $updates['has_key'] ) : ?>
							<br><span class="description"><?php esc_html_e( 'Updates are disabled: COSMICK_AUTH_KEY is not defined in wp-config.php.', 'petkey-gallery' ); ?></span>
						<?php elseif ( ! empty( $updates['last_error']['message'] ) ) : ?>
							<br><span class="description" style="color:#b32d2e"><?php echo esc_html( $updates['last_error']['message'] ); ?></span>
						<?php elseif ( ! empty( $updates['last_check']['version'] ) ) : ?>
							<br><span class="description">
								<?php
								printf(
									/* translators: 1: version found, 2: date. */
									esc_html__( 'Last check found %1$s on %2$s.', 'petkey-gallery' ),
									esc_html( $updates['last_check']['version'] ),
									esc_html( wp_date( $fmt, (int) $updates['last_check']['time'] ) )
								);
								?>
							</span>
						<?php endif ?>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/* ============================================================
	 * Tab: Gallery
	 * ============================================================ */

	protected function render_gallery_tab() {

		$templates = wcpk_gallery_detect_theme_templates();
		$preset    = wcpk_gallery_layout_preset();
		$theme     = wp_get_theme();
		$archive   = get_post_type_archive_link( 'pet-gallery' );

		$this->form_open( 'gallery' );
		?>
		<table class="form-table" role="presentation">
			<?php $this->text_row( 'petkey_gallery_page_title', __( 'Page heading', 'petkey-gallery' ), __( 'Shown as the h1 above the gallery. Leave blank to hide it.', 'petkey-gallery' ) ); ?>

			<tr>
				<th scope="row"><?php esc_html_e( 'Layout', 'petkey-gallery' ); ?></th>
				<td>
					<p class="description" style="margin:0 0 .75rem">
						<?php
						if ( $templates['archive'] || $templates['single'] ) {
							printf(
								/* translators: %s: active theme name. */
								esc_html__( 'Your theme (%s) ships its own gallery templates.', 'petkey-gallery' ),
								'<strong>' . esc_html( $theme->get( 'Name' ) ) . '</strong>'
							);
						} else {
							printf(
								/* translators: %s: active theme name. */
								esc_html__( 'Your theme (%s) has no gallery templates of its own.', 'petkey-gallery' ),
								'<strong>' . esc_html( $theme->get( 'Name' ) ) . '</strong>'
							);
						}

						echo ' ';

						$name = array(
							'theme'  => __( 'your theme’s design', 'petkey-gallery' ),
							'plugin' => __( 'the plugin’s design', 'petkey-gallery' ),
							'wp'     => __( 'WordPress defaults', 'petkey-gallery' ),
						);

						$archive_name = isset( $name[ $templates['archive_active'] ] ) ? $name[ $templates['archive_active'] ] : '';
						$single_name  = isset( $name[ $templates['single_active'] ] ) ? $name[ $templates['single_active'] ] : '';

						// Reported per route. The gallery listing and a single
						// pet page genuinely can render different designs — a
						// theme often ships one template and not the other — so
						// one combined sentence had to be wrong about one of them.
						if ( $archive_name === $single_name ) {
							printf(
								/* translators: %s: which design is rendering. */
								esc_html__( 'Currently rendering: %s.', 'petkey-gallery' ),
								esc_html( $archive_name )
							);
						} else {
							printf(
								/* translators: 1: design used for the listing, 2: design used for a single pet page. */
								esc_html__( 'Currently rendering: the gallery listing uses %1$s, a single pet page uses %2$s.', 'petkey-gallery' ),
								esc_html( $archive_name ),
								esc_html( $single_name )
							);
						}

						if ( $archive ) {
							echo ' <a href="' . esc_url( $archive ) . '" target="_blank" rel="noopener">' . esc_html__( 'Preview →', 'petkey-gallery' ) . '</a>';
						}
						?>
					</p>

					<?php
					$options = array(
						'theme'        => array(
							__( 'Use my theme’s design', 'petkey-gallery' ),
							__( 'Your theme’s gallery templates where it has them, the plugin’s where it does not.', 'petkey-gallery' ),
						),
						'plugin'       => array(
							__( 'Use the plugin’s design', 'petkey-gallery' ),
							__( 'Always the plugin’s gallery, inside your theme’s normal page layout.', 'petkey-gallery' ),
						),
						'plugin_clean' => array(
							__( 'Plugin’s design, without the theme’s page banner and sidebar', 'petkey-gallery' ),
							__( 'Renders header, gallery and footer only. Use this if your theme wraps the gallery in chrome you don’t want.', 'petkey-gallery' ),
						),
					);

					// Only offered when a site is already in it, so an existing
					// deliberate configuration is not silently rewritten by the
					// first save on this screen.
					if ( 'wp_default' === $preset ) {
						$options['wp_default'] = array(
							__( 'Leave WordPress alone (advanced)', 'petkey-gallery' ),
							__( 'Neither the theme’s nor the plugin’s gallery template is applied. This site is currently set this way.', 'petkey-gallery' ),
						);
					}

					// Same reasoning: a site whose two routes are configured
					// differently keeps that configuration until someone
					// deliberately picks one of the three above.
					if ( 'mixed' === $preset ) {
						$options['mixed'] = array(
							__( 'Keep my current mixed setup', 'petkey-gallery' ),
							__( 'The gallery listing and the single pet page are set differently. This site is currently set this way.', 'petkey-gallery' ),
						);
					}

					foreach ( $options as $value => $copy ) :
						?>
						<label style="display:block;margin-bottom:.55rem">
							<input type="radio" name="wcpk_layout_preset" value="<?php echo esc_attr( $value ); ?>" <?php checked( $value, $preset ); ?>>
							<strong><?php echo esc_html( $copy[0] ); ?></strong><br>
							<span class="description" style="margin-left:1.9rem"><?php echo esc_html( $copy[1] ); ?></span>
						</label>
						<?php
					endforeach;
					?>
				</td>
			</tr>

			<?php
			$this->select_row( 'petkey_gallery_columns', __( 'Columns', 'petkey-gallery' ), array( 2 => '2', 3 => '3', 4 => '4' ) );
			?>

			<tr>
				<th scope="row"><label for="petkey_gallery_color"><?php esc_html_e( 'Accent colour', 'petkey-gallery' ); ?></label></th>
				<td>
					<input type="text" class="wcpk-color-picker" id="petkey_gallery_color" name="petkey_gallery_color" value="<?php echo esc_attr( get_option( 'petkey_gallery_color', '' ) ); ?>">
					<p class="description"><?php esc_html_e( 'Leave blank to inherit your theme’s primary colour.', 'petkey-gallery' ); ?></p>
				</td>
			</tr>

			<?php
			$this->text_row(
				'petkey_gallery_numposts',
				__( 'Pets per page', 'petkey-gallery' ),
				__( 'How many cards load at a time before "Load more".', 'petkey-gallery' ),
				'number',
				' min="1" step="1"'
			);
			?>

			<tr>
				<th scope="row"><?php esc_html_e( 'Pet types to show', 'petkey-gallery' ); ?></th>
				<td>
					<?php
					$selected = wcpk_gallery_get_allowed_type_codes();
					$mode     = empty( $selected ) ? 'all' : 'some';
					$counts   = WCPK_Gallery_Status::counts();

					$labels = array(
						'DOG'     => __( 'Dogs', 'petkey-gallery' ),
						'CAT'     => __( 'Cats', 'petkey-gallery' ),
						'BIRD'    => __( 'Birds', 'petkey-gallery' ),
						'REPTILE' => __( 'Reptiles', 'petkey-gallery' ),
						'OTHER'   => __( 'Small animals &amp; everything else', 'petkey-gallery' ),
					);
					?>
					<label style="display:block;margin-bottom:.4rem">
						<input type="radio" name="wcpk_types_mode" value="all" <?php checked( 'all', $mode ); ?>>
						<?php esc_html_e( 'Show every pet type', 'petkey-gallery' ); ?>
					</label>
					<label style="display:block;margin-bottom:.4rem">
						<input type="radio" name="wcpk_types_mode" value="some" <?php checked( 'some', $mode ); ?>>
						<?php esc_html_e( 'Only these types', 'petkey-gallery' ); ?>
					</label>

					<div data-wcpk-types-list style="margin:.4rem 0 0 1.9rem">
						<?php foreach ( $labels as $code => $label ) : ?>
							<label style="display:block">
								<input type="checkbox" name="petkey_gallery_types[]" value="<?php echo esc_attr( $code ); ?>" <?php checked( in_array( $code, $selected, true ) ); ?>>
								<?php echo wp_kses_post( $label ); ?>
								<span class="description">(<?php echo esc_html( number_format_i18n( isset( $counts['by_type'][ $code ] ) ? $counts['by_type'][ $code ] : 0 ) ); ?>)</span>
							</label>
						<?php endforeach ?>
					</div>

					<p class="description"><?php esc_html_e( 'This controls what the gallery displays. It is not the visitor-facing "Type" menu, which is driven by the gallery’s own categories.', 'petkey-gallery' ); ?></p>
				</td>
			</tr>

			<?php
			$this->checkbox_row(
				'petkey_gallery_hero_enabled',
				__( 'Hero section', 'petkey-gallery' ),
				__( 'Show the editorial hero above the grid', 'petkey-gallery' )
			);
			$this->text_row( 'petkey_gallery_hero_heading', __( 'Hero heading', 'petkey-gallery' ) );
			$this->textarea_row( 'petkey_gallery_hero_intro', __( 'Hero intro', 'petkey-gallery' ) );
			?>
		</table>
		<?php
		$this->form_close();
	}

	/* ============================================================
	 * Tab: Inquiries
	 * ============================================================ */

	protected function render_inquiries_tab() {

		$source = get_option( 'petkey_gallery_form_source', 'auto' );

		$this->form_open( 'inquiries' );
		?>
		<table class="form-table" role="presentation">
			<?php
			$this->select_row(
				'petkey_gallery_form_source',
				__( 'How visitors inquire', 'petkey-gallery' ),
				array(
					'auto'         => __( 'Automatic (recommended)', 'petkey-gallery' ),
					'theme'        => __( 'My theme’s inquiry modal', 'petkey-gallery' ),
					'gravityforms' => __( 'Gravity Forms', 'petkey-gallery' ),
					'contactform7' => __( 'Contact Form 7', 'petkey-gallery' ),
					'wpforms'      => __( 'WPForms', 'petkey-gallery' ),
					'shortcode'    => __( 'A shortcode I’ll provide', 'petkey-gallery' ),
					'none'         => __( 'No inquiry button at all', 'petkey-gallery' ),
				),
				__( 'Automatic tries your theme’s pet-inquiry modal first, then the form below, then a link to a contact page.', 'petkey-gallery' )
			);

			// One contextual field instead of three always-visible ones, at most
			// one of which ever applied.
			$this->row_open( 'petkey_gallery_form_id', __( 'Which form', 'petkey-gallery' ) );
			$forms = $this->form_choices();

			if ( ! empty( $forms ) ) {
				$current = (string) get_option( 'petkey_gallery_form_id', '' );
				echo '<select id="petkey_gallery_form_id" name="petkey_gallery_form_id">';
				echo '<option value="">' . esc_html__( '— Select a form —', 'petkey-gallery' ) . '</option>';

				/*
				 * Carry the stored value even when the form plugin no longer
				 * reports it — deactivated, deleted, or simply not listed for
				 * the current source. Without this the select has no option
				 * matching the saved id, the browser posts the empty option,
				 * and saving any other field on this tab silently wipes the
				 * form id (and, through the legacy mirror, the one older themes
				 * read).
				 */
				if ( '' !== $current && ! array_key_exists( $current, $forms ) ) {
					printf(
						'<option value="%s" selected>%s</option>',
						esc_attr( $current ),
						esc_html( sprintf( /* translators: %s: form id. */ __( 'Form #%s (not found)', 'petkey-gallery' ), $current ) )
					);
				}

				foreach ( $forms as $id => $title ) {
					printf(
						'<option value="%s" %s>%s</option>',
						esc_attr( (string) $id ),
						selected( (string) $id, $current, false ),
						esc_html( $title )
					);
				}
				echo '</select>';
			} else {
				printf(
					'<input type="text" class="regular-text" id="petkey_gallery_form_id" name="petkey_gallery_form_id" value="%s">',
					esc_attr( (string) get_option( 'petkey_gallery_form_id', '' ) )
				);
			}
			$this->row_close( __( 'Used when the inquiry method is a form plugin.', 'petkey-gallery' ) );

			$this->textarea_row(
				'petkey_gallery_form_shortcode',
				__( 'Inquiry shortcode', 'petkey-gallery' ),
				__( 'Pasted exactly as-is into the inquiry dialog.', 'petkey-gallery' )
			);

			$this->row_open( 'petkey_gallery_inquiry_page', __( 'If no form is available', 'petkey-gallery' ) );
			$pages   = get_pages( array( 'sort_column' => 'menu_order' ) );
			$current = (string) get_option( 'petkey_gallery_inquiry_page', '' );
			echo '<select id="petkey_gallery_inquiry_page" name="petkey_gallery_inquiry_page">';
			echo '<option value="">' . esc_html__( '— Detect my contact page —', 'petkey-gallery' ) . '</option>';
			foreach ( (array) $pages as $page ) {
				printf(
					'<option value="%s" %s>%s</option>',
					esc_attr( $page->post_name ),
					selected( $page->post_name, $current, false ),
					esc_html( $page->post_title )
				);
			}
			echo '</select>';
			$this->row_close( __( 'Visitors are sent here with ?pet={ref} so the enquiry can be attributed.', 'petkey-gallery' ) );

			$this->text_row( 'petkey_gallery_cta_label', __( 'Hero button text', 'petkey-gallery' ) );
			$this->text_row( 'petkey_gallery_card_cta_label', __( 'Card button text', 'petkey-gallery' ) );
			?>
		</table>
		<?php
		$this->form_close();
	}

	/**
	 * Forms offered by whichever plugin the current source names.
	 *
	 * @return array<int|string, string>
	 */
	protected function form_choices() {

		$source = get_option( 'petkey_gallery_form_source', 'auto' );
		$out    = array();

		if ( in_array( $source, array( 'auto', 'gravityforms' ), true ) && class_exists( 'RGFormsModel' ) ) {
			foreach ( (array) RGFormsModel::get_forms( null, 'title' ) as $form ) {
				$out[ (int) $form->id ] = (string) $form->title;
			}
		}

		if ( in_array( $source, array( 'auto', 'contactform7' ), true ) && class_exists( 'WPCF7_ContactForm' ) ) {
			foreach ( (array) WPCF7_ContactForm::find( array( 'posts_per_page' => 100 ) ) as $form ) {
				$out[ (int) $form->id() ] = (string) $form->title();
			}
		}

		if ( in_array( $source, array( 'auto', 'wpforms' ), true ) && function_exists( 'wpforms' ) ) {
			$forms = get_posts(
				array(
					'post_type'      => 'wpforms',
					'posts_per_page' => 100,
					'post_status'    => 'publish',
				)
			);

			foreach ( $forms as $form ) {
				$out[ (int) $form->ID ] = (string) $form->post_title;
			}
		}

		return $out;
	}



	/**
	 * The Unified KM mirroring controls.
	 *
	 * Two separate switches on purpose. A single on/off would arm both the live
	 * listener and a recurring job whose candidate set is the entire backlog —
	 * which means switching mirroring on would quietly start a multi-thousand
	 * post backfill, defeating the whole point of making the backfill a button.
	 */
	protected function render_ukm_mirroring_rows() {

		$on     = 'on' === get_option( WCPK_Gallery_UKM_Ingest::MODE_OPTION, 'off' );
		$state  = WCPK_Gallery_UKM_Ingest::backfill_state();
		$counts = WCPK_Gallery_Status::counts();
		$fmt    = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$stood_down = wcpk_gallery_ukm_gallery_active();

		if ( $stood_down ) :
			?>
			<tr><td colspan="2">
				<div class="notice notice-info inline"><p>
					<?php esc_html_e( 'The separate UnifiedKM Pet Gallery plugin is active on this site and owns the mirroring. These controls are disabled so the two cannot both create gallery posts.', 'petkey-gallery' ); ?>
				</p></div>
			</td></tr>
			<?php
		endif;
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Mirroring', 'petkey-gallery' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="wcpk_gallery_ukm_ingest_mode" value="1" <?php checked( $on ); ?> <?php disabled( $stood_down ); ?>>
					<?php esc_html_e( 'Copy sold pets into the gallery as they sell', 'petkey-gallery' ); ?>
				</label>
				<p class="description">
					<?php esc_html_e( 'Creates a gallery entry for each pet. The pet itself is never changed — it stays in its Sold status, which the warranty, certificate and my-account tools rely on.', 'petkey-gallery' ); ?>
				</p>
			</td>
		</tr>

		<tr>
			<th scope="row"><label for="wcpk_gallery_ukm_backfill_months"><?php esc_html_e( 'How far back to go', 'petkey-gallery' ); ?></label></th>
			<td>
				<input type="number" min="0" step="1" id="wcpk_gallery_ukm_backfill_months" name="wcpk_gallery_ukm_backfill_months"
					value="<?php echo esc_attr( (string) WCPK_Gallery_UKM_Ingest::window_months() ); ?>" class="small-text">
				<?php esc_html_e( 'months', 'petkey-gallery' ); ?>
				<p class="description"><?php esc_html_e( '0 means no limit. Pets sold before this are left alone.', 'petkey-gallery' ); ?></p>
			</td>
		</tr>

		<tr>
			<th scope="row"><?php esc_html_e( 'Fill in past adoptions', 'petkey-gallery' ); ?></th>
			<td>
				<?php if ( 'running' === $state['status'] ) : ?>
					<p>
						<strong><?php esc_html_e( 'Running.', 'petkey-gallery' ); ?></strong>
						<?php
						printf(
							/* translators: 1: done, 2: total. */
							esc_html__( '%1$s of about %2$s processed.', 'petkey-gallery' ),
							esc_html( number_format_i18n( (int) $state['done'] ) ),
							esc_html( number_format_i18n( (int) $state['total'] ) )
						);
						?>
					</p>

					<?php
					// Kinsta commonly sets DISABLE_WP_CRON and runs a system
					// cron instead, so a stalled chain needs a way forward that
					// does not depend on cron firing at all.
					$stale = ( time() - (int) $state['last_progress'] ) > 900;

					if ( $stale ) :
						?>
						<div class="notice notice-warning inline"><p>
							<?php
							printf(
								/* translators: %s: formatted date. */
								esc_html__( 'No progress since %s. Use the button below to continue it by hand.', 'petkey-gallery' ),
								esc_html( wp_date( $fmt, (int) $state['last_progress'] ) )
							);
							?>
						</p></div>
					<?php endif ?>

					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wcpk_gallery_ukm_backfill_step' ), 'wcpk_gallery_ukm_backfill_step' ) ); ?>">
						<?php esc_html_e( 'Run the next batch now', 'petkey-gallery' ); ?>
					</a>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wcpk_gallery_ukm_backfill_cancel' ), 'wcpk_gallery_ukm_backfill_cancel' ) ); ?>">
						<?php esc_html_e( 'Stop', 'petkey-gallery' ); ?>
					</a>
				<?php elseif ( ! $on || $stood_down ) : ?>
					<p class="description">
						<?php esc_html_e( 'Switch mirroring on and save before backfilling.', 'petkey-gallery' ); ?>
					</p>
				<?php else : ?>
					<p>
						<?php
						printf(
							/* translators: %s: number of pets. */
							esc_html__( '%s pets qualify and are not in the gallery yet.', 'petkey-gallery' ),
							'<strong>' . esc_html( number_format_i18n( (int) $counts['unmirrored'] ) ) . '</strong>'
						);
						?>
					</p>

					<?php if ( 'complete' === $state['status'] && $state['done'] > 0 ) : ?>
						<p class="description">
							<?php
							printf(
								/* translators: 1: number processed, 2: date. */
								esc_html__( 'Last backfill processed %1$s pets, finishing %2$s.', 'petkey-gallery' ),
								esc_html( number_format_i18n( (int) $state['done'] ) ),
								esc_html( wp_date( $fmt, (int) $state['last_progress'] ) )
							);
							?>
						</p>
					<?php endif ?>

					<p>
						<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wcpk_gallery_ukm_backfill_start' ), 'wcpk_gallery_ukm_backfill_start' ) ); ?>"
							onclick="return confirm('<?php echo esc_js( __( 'This creates one gallery entry per qualifying pet. It runs in the background and can be stopped. Continue?', 'petkey-gallery' ) ); ?>');">
							<?php esc_html_e( 'Backfill now', 'petkey-gallery' ); ?>
						</a>
						<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wcpk_gallery_ukm_backfill_start&cap=25' ), 'wcpk_gallery_ukm_backfill_start' ) ); ?>">
							<?php esc_html_e( 'Try 25 first', 'petkey-gallery' ); ?>
						</a>
					</p>
					<p class="description"><?php esc_html_e( 'Nothing happens until you press this. Updating the plugin never starts a backfill on its own.', 'petkey-gallery' ); ?></p>
				<?php endif ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * The Petkey/Pinogy promotion tools: "Run cron now", the pending list, and
	 * the force-move buttons.
	 *
	 * Rendered only where they do something. On a UKM site every row of this
	 * table read "No API status returned" — a verdict about Petkey's
	 * `pet_Datadetail` meta, which UKM pets simply do not have — and the buttons
	 * were no-ops. It is also the one piece of UI that can reach the code path
	 * which flips a product to `gallery_moved`, and UKM pets must stay in
	 * `sold_puppy`.
	 *
	 * Note the separate <form action="admin-post.php">: HTML forbids nested
	 * forms, and the bulk nonce and the settings nonce cannot share one submit.
	 * The settings form is closed before this renders.
	 */
	protected function render_promotion_tools() {

		if ( ! wcpk_gallery_promotion_tools_enabled() ) {
			return;
		}

		global $petkey_gallery_functions;

		$rows = array();

		if ( isset( $petkey_gallery_functions )
			&& method_exists( $petkey_gallery_functions, 'get_pending_pets_diagnostic' )
			&& function_exists( 'wc_get_products' ) ) {
			$rows = (array) $petkey_gallery_functions->get_pending_pets_diagnostic( 100 );
		}

		$summary = get_transient( 'wcpk_gallery_run_now_summary' );

		?>
		<details style="margin:1rem 0">
			<summary><strong><?php esc_html_e( 'Promotion tools', 'petkey-gallery' ); ?></strong></summary>

			<?php if ( is_array( $summary ) ) : ?>
			<div class="notice notice-info inline"><p>
				<?php
				printf(
					/* translators: 1-5: counts from the last run. */
					esc_html__( 'Last run — examined %1$d, moved %2$d, waiting %3$d, skipped %4$d, no API data %5$d.', 'petkey-gallery' ),
					(int) $summary['examined'],
					(int) $summary['moved'],
					(int) $summary['waiting'],
					(int) $summary['skipped'],
					(int) $summary['no_data']
				);
				?>
			</p></div>
			<?php endif ?>

			<p>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wcpk_gallery_run_now' ), 'wcpk_gallery_run_now' ) ); ?>">
					<?php esc_html_e( 'Run the promotion sweep now', 'petkey-gallery' ); ?>
				</a>
				<span class="description"><?php esc_html_e( 'Save any changes above first — the sweep uses the saved settings.', 'petkey-gallery' ); ?></span>
			</p>

			<?php if ( empty( $rows ) ) : ?>
				<p class="description"><?php esc_html_e( 'No pets are currently waiting to be promoted.', 'petkey-gallery' ); ?></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="wcpk_gallery_move_bulk">
					<?php wp_nonce_field( 'wcpk_gallery_move_bulk' ); ?>

					<table class="widefat striped">
						<thead>
							<tr>
								<td class="check-column"></td>
								<th><?php esc_html_e( 'Pet', 'petkey-gallery' ); ?></th>
								<th><?php esc_html_e( 'API status', 'petkey-gallery' ); ?></th>
								<th><?php esc_html_e( 'Days waiting', 'petkey-gallery' ); ?></th>
								<th><?php esc_html_e( 'What happens next', 'petkey-gallery' ); ?></th>
								<th></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<th class="check-column"><input type="checkbox" name="pet_ids[]" value="<?php echo (int) $row['id']; ?>"></th>
								<td><?php echo esc_html( $row['title'] ); ?> <span class="description">#<?php echo (int) $row['id']; ?></span></td>
								<td><code><?php echo esc_html( '' !== $row['api_status'] ? $row['api_status'] : '—' ); ?></code></td>
								<td><?php echo null === $row['days'] ? '—' : esc_html( (string) (int) $row['days'] ); ?></td>
								<td><?php echo esc_html( $row['verdict'] ); ?></td>
								<td>
									<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wcpk_gallery_move_one&product_id=' . (int) $row['id'] ), 'wcpk_gallery_move_one_' . (int) $row['id'] ) ); ?>">
										<?php esc_html_e( 'Move now', 'petkey-gallery' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach ?>
						</tbody>
					</table>

					<p>
						<button type="submit" class="button"><?php esc_html_e( 'Force-move the selected pets', 'petkey-gallery' ); ?></button>
						<span class="description"><?php esc_html_e( 'Force-moving skips the hold period and the accepted-status check.', 'petkey-gallery' ); ?></span>
					</p>
				</form>
			<?php endif ?>
		</details>
		<?php
	}

	/* ============================================================
	 * Tab: Advanced
	 * ============================================================ */

	protected function render_advanced_tab() {

		$provider = WCPK_Gallery_Status::provider();

		$this->form_open( 'advanced' );
		?>
		<details open style="margin:1rem 0">
			<summary><strong><?php esc_html_e( 'Search engines', 'petkey-gallery' ); ?></strong></summary>
			<table class="form-table" role="presentation">
				<?php
				$this->select_row(
					'petkey_gallery_seo_mode',
					__( 'Individual pet pages', 'petkey-gallery' ),
					array(
						'hide_pages'            => __( 'Keep them out of search results (recommended)', 'petkey-gallery' ),
						'hide_pages_and_images' => __( 'Keep them and their photos out of search results', 'petkey-gallery' ),
						'index_all'             => __( 'Let search engines index everything', 'petkey-gallery' ),
					),
					__( 'Every mirrored pet gets its own URL. The recommended setting marks those pages noindex and keeps them out of the sitemap, while the main gallery page stays indexed and is the page that ranks. The second option also keeps the pet photos out of image search.', 'petkey-gallery' )
				);
				?>
			</table>
		</details>

		<details style="margin:1rem 0">
			<summary><strong><?php esc_html_e( 'Wording', 'petkey-gallery' ); ?></strong></summary>
			<table class="form-table" role="presentation">
				<?php
				$this->text_row( 'petkey_gallery_hero_eyebrow', __( 'Hero eyebrow', 'petkey-gallery' ) );
				$this->text_row( 'petkey_gallery_hero_heading_accent', __( 'Accented word in the hero heading', 'petkey-gallery' ), __( 'The first match in the heading is italicised in the accent colour.', 'petkey-gallery' ) );
				$this->text_row( 'petkey_gallery_grid_eyebrow', __( 'Grid eyebrow', 'petkey-gallery' ) );
				$this->text_row( 'petkey_gallery_grid_heading', __( 'Grid heading', 'petkey-gallery' ) );
				$this->text_row( 'petkey_gallery_grid_heading_accent', __( 'Accented word in the grid heading', 'petkey-gallery' ) );
				$this->text_row( 'petkey_gallery_sticker_eyebrow', __( 'Photo sticker — top line', 'petkey-gallery' ) );
				$this->text_row( 'petkey_gallery_sticker_label', __( 'Photo sticker — bottom line', 'petkey-gallery' ) );
				$this->checkbox_row( 'petkey_gallery_stats_enabled', __( 'Statistics', 'petkey-gallery' ), __( 'Show the Forever Homes / Unique Breeds counters', 'petkey-gallery' ) );
				$this->text_row( 'petkey_gallery_forever_homes_base', __( 'Forever Homes baseline', 'petkey-gallery' ), __( 'Added to the live count, for adoptions that predate the gallery.', 'petkey-gallery' ), 'number', ' min="0" step="1"' );
				?>
			</table>
		</details>

		<details style="margin:1rem 0">
			<summary><strong><?php esc_html_e( 'Sync', 'petkey-gallery' ); ?></strong></summary>
			<table class="form-table" role="presentation">
				<?php if ( 'ukm' === $provider && class_exists( 'WCPK_Gallery_UKM_Ingest' ) ) : ?>
					<?php $this->render_ukm_mirroring_rows(); ?>
				<?php endif ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Pets come from', 'petkey-gallery' ); ?></th>
					<td>
						<?php
						$chosen  = WCPK_Gallery_Status::source_statuses();
						$choices = WCPK_Gallery_Status::product_status_choices();

						// Without this, an all-unticked submission posts nothing at
						// all and is indistinguishable from "this tab wasn't
						// rendered" — so the validation branch was unreachable
						// and the page cheerfully reported "Settings saved."
						echo '<input type="hidden" name="petkey_gallery_source_statuses_present" value="1">';

						if ( empty( $choices ) ) {
							echo '<p class="description">' . esc_html__( 'WooCommerce products are not available on this site.', 'petkey-gallery' ) . '</p>';
						} else {
							foreach ( $choices as $slug => $info ) {
								printf(
									'<label style="display:block"><input type="checkbox" name="petkey_gallery_source_statuses[]" value="%s" %s> %s <span class="description">(%s)</span></label>',
									esc_attr( $slug ),
									checked( in_array( $slug, $chosen, true ), true, false ),
									esc_html( $info['label'] ),
									esc_html( number_format_i18n( $info['count'] ) )
								);
							}
						}
						?>
						<p class="description"><?php esc_html_e( 'Which product statuses count as "this pet found a home". A pet also needs a photo before it appears.', 'petkey-gallery' ); ?></p>
					</td>
				</tr>
				<?php
				if ( wcpk_gallery_option_applies( 'petkey_gallery_accepted_statuses', $provider ) ) {
					$this->text_row(
						'petkey_gallery_accepted_statuses',
						__( 'Accepted API statuses', 'petkey-gallery' ),
						__( 'Comma-separated Petkey API status values that qualify. Case-insensitive.', 'petkey-gallery' )
					);
				}

				if ( wcpk_gallery_option_applies( 'petkey_gallery_wait_days', $provider ) ) {
					$this->text_row(
						'petkey_gallery_wait_days',
						__( 'Hold for', 'petkey-gallery' ),
						__( 'Days to wait after a pet changes status before it appears, so a pet that briefly leaves inventory and comes back does not end up in the gallery. 0 means no wait.', 'petkey-gallery' ),
						'number',
						' min="0" step="1"'
					);
				}
				?>
			</table>
		</details>

		<details style="margin:1rem 0">
			<summary><strong><?php esc_html_e( 'Compatibility', 'petkey-gallery' ); ?></strong></summary>
			<table class="form-table" role="presentation">
				<?php
				$this->select_row(
					'petkey_gallery_cat_all_behavior',
					__( 'The "All Pets" view', 'petkey-gallery' ),
					array(
						'include'             => __( 'Shows every pet (recommended)', 'petkey-gallery' ),
						'exclude_categorised' => __( 'Hides any pet that has a category', 'petkey-gallery' ),
					),
					__( 'The second option is how versions before 1.7.0 behaved. It is here only for sites that were deliberately using a category to hide pets.', 'petkey-gallery' )
				);

				$this->select_row(
					'wcpk_gallery_release_channel',
					__( 'Plugin updates', 'petkey-gallery' ),
					array(
						'stable' => __( 'Stable releases only', 'petkey-gallery' ),
						'beta'   => __( 'Also offer pre-releases', 'petkey-gallery' ),
					),
					__( 'Put one site on pre-releases to try a fix before it reaches everywhere else.', 'petkey-gallery' )
				);

				$this->text_row(
					'petkey_gallery_enquiry',
					__( 'Legacy form ID', 'petkey-gallery' ),
					__( 'Older themes read this value directly. It normally mirrors the Form ID on the Inquiries tab; if you set it to something different, it is left alone.', 'petkey-gallery' )
				);
				?>
			</table>
		</details>
		<?php
		$this->form_close();

		// Outside the settings form on purpose: this renders a form of its own.
		$this->render_promotion_tools();
	}
}

endif;

/*
 * settings.php is require'd, not require_once'd, and the class guard above used
 * to double as the file's include guard. Now that the plain functions below sit
 * outside it, each one carries its own guard and this instantiation is
 * idempotent — a second include is a no-op rather than a fatal redeclare that
 * also re-registers every admin_post handler.
 */
if ( ! isset( $petkey_gallery_settings ) || ! $petkey_gallery_settings instanceof PetkeyGallerySettings ) {
	$petkey_gallery_settings = new PetkeyGallerySettings();
}

if ( ! function_exists( 'wcpk_gallery_promotion_tools_enabled' ) ) {
	/**
	 * Whether the Petkey-shaped promotion tools apply to this site.
	 *
	 * They are gated off UKM entirely. On a UKM site they render a table in which
	 * every row reads "No API status returned" — a verdict about Petkey's
	 * `pet_Datadetail` meta, which UKM pets do not have — and the buttons beneath
	 * it call create_gallery_post_from_product(), which bails before doing anything
	 * because that same meta is missing. Nothing but confusion.
	 *
	 * It also matters for safety going forward: that function ends by flipping the
	 * source product to the `gallery_moved` status, and UKM pets must stay in
	 * `sold_puppy` — the warranty, certificate, my-account and bulk tools all key
	 * off it.
	 *
	 * @return bool
	 */
	function wcpk_gallery_promotion_tools_enabled() {
		$provider = defined( 'WCPK_GALLERY_PARENT_MODE' ) ? WCPK_GALLERY_PARENT_MODE : 'none';

		return (bool) apply_filters(
			'wcpk_gallery_promotion_tools_enabled',
			in_array( $provider, array( 'petkey', 'pinogy' ), true ),
			$provider
		);
	}
}

if ( ! function_exists( 'wcpk_gallery_guard_promotion_request' ) ) {
	/**
	 * Shared guard for the three promotion handlers.
	 */
	function wcpk_gallery_guard_promotion_request( $nonce_action ) {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'petkey-gallery' ) );
		}

		check_admin_referer( $nonce_action );

		if ( ! wcpk_gallery_promotion_tools_enabled() ) {
			wp_die(
				esc_html__( 'These controls only apply to Petkey and Pinogy sites. On a Unified KM site, pets reach the gallery through mirroring, and their product status is never changed.', 'petkey-gallery' ),
				esc_html__( 'Not available', 'petkey-gallery' ),
				array( 'response' => 403, 'back_link' => true )
			);
		}
	}
}

if ( ! function_exists( 'wcpk_gallery_promotion_redirect' ) ) {
	function wcpk_gallery_promotion_redirect( array $summary ) {
		set_transient( 'wcpk_gallery_run_now_summary', $summary, MINUTE_IN_SECONDS );

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => 'petkey-setting-gallery', 'tab' => 'advanced' ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}

/**
 * admin-post: "Run cron now".
 */
if ( ! has_action( 'admin_post_wcpk_gallery_run_now' ) ) :

add_action( 'admin_post_wcpk_gallery_run_now', function () {

	wcpk_gallery_guard_promotion_request( 'wcpk_gallery_run_now' );

	global $petkey_gallery_functions;

	$summary = array( 'examined' => 0, 'moved' => 0, 'waiting' => 0, 'skipped' => 0, 'no_data' => 0 );

	if ( isset( $petkey_gallery_functions ) && method_exists( $petkey_gallery_functions, 'run_cron_now' ) ) {
		$result = $petkey_gallery_functions->run_cron_now();

		if ( is_array( $result ) ) {
			$summary = array_merge( $summary, $result );
		}
	}

	wcpk_gallery_promotion_redirect( $summary );
} );

/**
 * admin-post: bulk "Force move selected" from the diagnostic table.
 *
 * Force = no cooling-off wait and no accept-list check. The pet still needs an
 * upstream record (`pet_Datadetail`) to mirror from.
 */
add_action( 'admin_post_wcpk_gallery_move_bulk', function () {

	wcpk_gallery_guard_promotion_request( 'wcpk_gallery_move_bulk' );

	global $petkey_gallery_functions;

	$ids     = isset( $_POST['pet_ids'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['pet_ids'] ) ) : array();
	$summary = array( 'examined' => count( $ids ), 'moved' => 0, 'waiting' => 0, 'skipped' => 0, 'no_data' => 0 );

	if ( ! empty( $ids ) && isset( $petkey_gallery_functions ) && method_exists( $petkey_gallery_functions, 'add_to_gallery_for_product' ) ) {
		foreach ( $ids as $product_id ) {
			if ( $product_id <= 0 ) {
				continue;
			}

			if ( $petkey_gallery_functions->add_to_gallery_for_product( $product_id ) ) {
				$summary['moved']++;
			} else {
				$summary['skipped']++;
			}
		}
	}

	wcpk_gallery_promotion_redirect( $summary );
} );

/**
 * admin-post: per-row "Move now".
 */
add_action( 'admin_post_wcpk_gallery_move_one', function () {

	$product_id = isset( $_GET['product_id'] ) ? (int) $_GET['product_id'] : 0;

	wcpk_gallery_guard_promotion_request( 'wcpk_gallery_move_one_' . $product_id );

	global $petkey_gallery_functions;

	$summary = array( 'examined' => 1, 'moved' => 0, 'waiting' => 0, 'skipped' => 0, 'no_data' => 0 );

	if ( $product_id > 0 && isset( $petkey_gallery_functions ) && method_exists( $petkey_gallery_functions, 'add_to_gallery_for_product' ) ) {
		if ( $petkey_gallery_functions->add_to_gallery_for_product( $product_id ) ) {
			$summary['moved'] = 1;
		} else {
			$summary['skipped'] = 1;
		}
	}

	wcpk_gallery_promotion_redirect( $summary );
} );

if ( ! function_exists( 'wcpk_gallery_guard_ukm_request' ) ) {
	/**
	 * admin-post: run the live gallery check from the Status tab.
	 */
	add_action( 'admin_post_wcpk_gallery_health_check', function () {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'petkey-gallery' ) );
		}

		check_admin_referer( 'wcpk_gallery_health_check' );

		WCPK_Gallery_Status::health_check();
		WCPK_Gallery_Status::flush();

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => 'petkey-setting-gallery', 'tab' => 'status' ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	} );

	/* ------------------------------------------------------------------
	 * Unified KM backfill controls.
	 * ------------------------------------------------------------------ */

	function wcpk_gallery_guard_ukm_request( $nonce_action ) {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'petkey-gallery' ) );
		}

		check_admin_referer( $nonce_action );

		if ( ! class_exists( 'WCPK_Gallery_UKM_Ingest' ) ) {
			wp_die(
				esc_html__( 'Mirroring is not available on this site.', 'petkey-gallery' ),
				esc_html__( 'Not available', 'petkey-gallery' ),
				array( 'response' => 403, 'back_link' => true )
			);
		}

		/*
		 * Starting a run requires mirroring to be on. Stopping one must not — a
		 * backfill left 'running' when mirroring was switched off (or when
		 * wc-ukm-gallery was activated mid-run) cannot advance, and gating Stop on
		 * enabled() meant the only way out of that state was a database edit.
		 */
		$state   = WCPK_Gallery_UKM_Ingest::backfill_state();
		$running = ( 'running' === $state['status'] );

		if ( ! WCPK_Gallery_UKM_Ingest::enabled() && ! $running ) {
			wp_die(
				esc_html__( 'Mirroring is not switched on for this site.', 'petkey-gallery' ),
				esc_html__( 'Not available', 'petkey-gallery' ),
				array( 'response' => 403, 'back_link' => true )
			);
		}

		if ( 'wcpk_gallery_ukm_backfill_start' === $nonce_action && ! WCPK_Gallery_UKM_Ingest::enabled() ) {
			wp_die(
				esc_html__( 'Switch mirroring on before starting a backfill.', 'petkey-gallery' ),
				esc_html__( 'Not available', 'petkey-gallery' ),
				array( 'response' => 403, 'back_link' => true )
			);
		}
	}
}

if ( ! function_exists( 'wcpk_gallery_ukm_redirect' ) ) {
	function wcpk_gallery_ukm_redirect() {
		wp_safe_redirect(
			add_query_arg(
				array( 'page' => 'petkey-setting-gallery', 'tab' => 'advanced' ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}

add_action( 'admin_post_wcpk_gallery_ukm_backfill_start', function () {
	wcpk_gallery_guard_ukm_request( 'wcpk_gallery_ukm_backfill_start' );

	$cap = isset( $_GET['cap'] ) ? max( 0, (int) $_GET['cap'] ) : 0;

	WCPK_Gallery_UKM_Ingest::start_backfill( $cap );

	// Run one batch inline so the operator sees movement immediately rather
	// than a screen that says "running" and never changes on a site where cron
	// is disabled.
	WCPK_Gallery_UKM_Ingest::run_now();

	wcpk_gallery_ukm_redirect();
} );

add_action( 'admin_post_wcpk_gallery_ukm_backfill_step', function () {
	wcpk_gallery_guard_ukm_request( 'wcpk_gallery_ukm_backfill_step' );
	WCPK_Gallery_UKM_Ingest::run_now();
	wcpk_gallery_ukm_redirect();
} );

add_action( 'admin_post_wcpk_gallery_ukm_backfill_cancel', function () {
	wcpk_gallery_guard_ukm_request( 'wcpk_gallery_ukm_backfill_cancel' );
	WCPK_Gallery_UKM_Ingest::cancel_backfill();
	wcpk_gallery_ukm_redirect();
} );

endif;
