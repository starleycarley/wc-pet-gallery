<?php

add_action( 'init', 'wc_petkey_gallery_init' );
add_action( 'save_post', 'wc_petkey_gallery_save_meta', 10, 2 );
add_filter( 'manage_pet-gallery_posts_columns' , 'wc_petkey_gallery_image_columns' );
add_action( 'manage_pet-gallery_posts_custom_column' , 'wc_petkey_gallery_image_column_data', 10, 2 );
add_action( 'admin_head', 'petkey_gallery_custom_css' );

// On sites where wc-ukm-gallery is also active, defer admin metabox rendering
// to its UI (it ships a richer one). We check the class at admin_init time so
// the detection runs AFTER all plugins have loaded — not at bootstrap.
add_action( 'admin_init', 'wc_petkey_gallery_maybe_defer_metabox', 5 );
function wc_petkey_gallery_maybe_defer_metabox() {
	if ( class_exists( 'UKMGalleryFunctions' ) || defined( 'UKMGALLERY' ) ) {
		remove_action( 'save_post', 'wc_petkey_gallery_save_meta', 10 );
		remove_filter( 'manage_pet-gallery_posts_columns', 'wc_petkey_gallery_image_columns' );
		remove_action( 'manage_pet-gallery_posts_custom_column', 'wc_petkey_gallery_image_column_data', 10 );
	}
}

function petkey_gallery_custom_css() {

    // Column widths for the pet-gallery list table only. This used to be echoed
    // into the <head> of every single admin page on the site.
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

    if ( ! $screen || 'edit' !== $screen->base || 'pet-gallery' !== $screen->post_type ) {
        return;
    }

    echo '<style>
            th#featured_image { width: 60px; }
            th#pet_gender { width: 150px; }
        </style>';
}

function wc_petkey_gallery_init() {

    $labels = array(
        'name' => _x('Gallery', 'post type general name', 'petkey-gallery'),
        'singular_name' => _x('Gallery', 'post type singular name', 'petkey-gallery'),
        'menu_name' => _x('Puppy Gallery', 'admin menu', 'petkey-gallery'),
        'name_admin_bar' => _x('Gallery', 'add new on admin bar', 'petkey-gallery'),
        'add_new' => _x('Add New', 'breed', 'petkey-gallery'),
        'add_new_item' => __('Add New Gallery', 'petkey-gallery'),
        'new_item' => __('New Gallery', 'petkey-gallery'),
        'edit_item' => __('Edit Gallery', 'petkey-gallery'),
        'view_item' => __('View Gallery', 'petkey-gallery'),
        'all_items' => __('All Gallery', 'petkey-gallery'),
        'search_items' => __('Search Gallery', 'petkey-gallery'),
        'parent_item_colon' => __('Parent Gallery:', 'petkey-gallery'),
        'not_found' => __('No gallery found.', 'petkey-gallery'),
        'not_found_in_trash' => __('No gallery found in Trash.', 'petkey-gallery')
    );

    $args = array(
        'labels' => $labels,
        'description' => __('Description.', 'petkey-gallery'),
        'public' => true,
        'publicly_queryable' => true,
        'show_ui' => true,
        'show_in_menu' => true,
        'menu_icon' => 'dashicons-images-alt',
        'query_var' => true,
        'rewrite' => array('slug' => 'pet-gallery'),
        'capability_type' => 'post',
        'has_archive' => true,
        'hierarchical' => false,
        'menu_position' => null,
        'supports' => array( 'title', 'thumbnail', 'editor' ),
        'register_meta_box_cb' => 'pet_gallery_meta_info',
        'taxonomies' => array('petgallery_tag', 'petgallery_cat' )
    );

    /*
     * wc-ukm-gallery registers this same CPT on init/10 with a thinner args set
     * — no 'editor' support and no register_meta_box_cb — and whichever call
     * runs second wins outright. Registering only when nobody has, and then
     * reconciling the args through the filter below, makes the outcome the same
     * whatever the load order. (Moving to init priority 9 does NOT help: their
     * init/10 still runs after ours and still overwrites the args.)
     *
     * Note this guards the register_post_type() call only. An early return from
     * this function would also skip the two register_taxonomy() calls below,
     * and nothing else on the site registers petgallery_cat — the category
     * filter and every tax_query that uses it would silently disappear.
     */
    if ( ! post_type_exists( 'pet-gallery' ) ) {
        register_post_type( 'pet-gallery', $args );
    }
    
    $cat_labels = array(
		'name'              => _x( 'Categories', 'taxonomy general name', 'pet-gallery' ),
		'singular_name'     => _x( 'Category', 'taxonomy singular name', 'pet-gallery' ),
		'search_items'      => __( 'Search Categories', 'pet-gallery' ),
		'all_items'         => __( 'All Categories', 'pet-gallery' ),
		'parent_item'       => __( 'Parent Category', 'pet-gallery' ),
		'parent_item_colon' => __( 'Parent Category:', 'pet-gallery' ),
		'edit_item'         => __( 'Edit Category', 'pet-gallery' ),
		'update_item'       => __( 'Update Category', 'pet-gallery' ),
		'add_new_item'      => __( 'Add New Category', 'pet-gallery' ),
		'new_item_name'     => __( 'New Category Name', 'pet-gallery' ),
		'menu_name'         => __( 'Category', 'pet-gallery' ),
	);
    $petgallery_tag_args = array(
        'hierarchical'      => false,
        //'labels'            => $labels,
        'show_ui'           => true,
        'show_admin_column' => true,
        'query_var'         => true
    );
    
    $petgallery_cat_args = array(
        'hierarchical'      => true,
        'labels'            => $cat_labels,
        'show_ui'           => true,
        'show_admin_column' => true,
        'query_var'         => true
    );
    
    register_taxonomy( 'petgallery_tag', 'pet-gallery', $petgallery_tag_args );
    register_taxonomy( 'petgallery_cat', 'pet-gallery', $petgallery_cat_args );    
    //register_taxonomy( 'podcast_category', 'podcasts', $petgallery_tag_args );
}

/**
 * Union our CPT requirements into whichever registration actually won.
 *
 * Additive on purpose: it only ever adds supports and taxonomies, and only
 * fills has_archive / rewrite when they are absent, so a site that has
 * deliberately customised either keeps its choice. It does not flush rewrite
 * rules — doing that on every init is a well-known way to make a site crawl.
 *
 * @param array  $args
 * @param string $post_type
 * @return array
 */
function wcpk_gallery_reconcile_post_type_args( $args, $post_type ) {

    if ( 'pet-gallery' !== $post_type ) {
        return $args;
    }

    $supports         = ( isset( $args['supports'] ) && is_array( $args['supports'] ) ) ? $args['supports'] : array();
    $args['supports'] = array_values( array_unique( array_merge( $supports, array( 'title', 'thumbnail', 'editor' ) ) ) );

    $taxonomies         = ( isset( $args['taxonomies'] ) && is_array( $args['taxonomies'] ) ) ? $args['taxonomies'] : array();
    $args['taxonomies'] = array_values( array_unique( array_merge( $taxonomies, array( 'petgallery_tag', 'petgallery_cat' ) ) ) );

    if ( ! isset( $args['has_archive'] ) ) {
        $args['has_archive'] = true;
    }

    if ( ! isset( $args['rewrite'] ) ) {
        $args['rewrite'] = array( 'slug' => 'pet-gallery' );
    }

    /*
     * Re-attach our metabox only when wc-ukm-gallery is absent. It ships a
     * richer one, and when it is present our save handler is already unhooked
     * (see wc_petkey_gallery_maybe_defer_metabox) — so rendering ours alongside
     * theirs would let an admin fill in nine fields that are then silently
     * discarded on save.
     */
    if ( empty( $args['register_meta_box_cb'] )
        && function_exists( 'wcpk_gallery_ukm_gallery_active' )
        && ! wcpk_gallery_ukm_gallery_active() ) {
        $args['register_meta_box_cb'] = 'pet_gallery_meta_info';
    }

    return (array) apply_filters( 'wcpk_gallery_post_type_args', $args );
}
add_filter( 'register_post_type_args', 'wcpk_gallery_reconcile_post_type_args', 20, 2 );

function pet_gallery_meta_info( $post ) {
    add_meta_box( 'petkey_gallery_meta', 'Gallery Information', 'pet_gallery_meta_info_fun', 'pet-gallery' );    
}

function pet_gallery_meta_info_fun() {

    global $post;

    wp_nonce_field( 'petkey_gallery_meta_save', 'petkey_gallery_meta_noncename' );

    $fields = array(
        'petid'        => array( 'Pet ID',     'pet_id',           false ),
        'reference_id' => array( 'Pet Refe#',  'pet_reference_id', false ),
        'name'         => array( 'Pet Name',   'pet_name',         false ),
        'age'          => array( 'Pet Age',    'pet_age',          false ),
        'animal_type'  => array( 'Pet Type',   'pet_animal_type',  false ),
        'breed_name'   => array( 'Pet Breed',  'pet_breed_name',   false ),
        'coloring'     => array( 'Pet Color',  'pet_coloring',     false ),
        'gender'       => array( 'Pet Gender', 'pet_gender',       false ),
        'birth_date'   => array( 'Pet DOB',    'pet_birth_date',   false ),
    );

    foreach ( $fields as $meta_key => $field ) {
        list( $label, $input_name, $readonly ) = $field;
        $value = get_post_meta( $post->ID, $meta_key, true );
        printf(
            '<p>%s:</p><input type="text" name="%s" value="%s" class="widefat"%s />',
            esc_html( $label ),
            esc_attr( $input_name ),
            esc_attr( $value ),
            $readonly ? ' readonly' : ''
        );
    }

    $pet_status = get_post_meta( $post->ID, 'status_returned', true );
    if ( ! empty( $pet_status ) ) {
        printf(
            '<p>Pet Status:</p><input type="text" name="status_ret" readonly value="%s" class="widefat" />',
            esc_attr( $pet_status )
        );
    }

    $pet_stat_time = get_post_meta( $post->ID, 'status_checkedon', true );
    if ( ! empty( $pet_stat_time ) ) {
        printf(
            '<p>Pet Status Checked on:</p><input type="text" name="status_ret_time" readonly value="%s" class="widefat" />',
            esc_attr( date_i18n( 'm/d/Y H:i:s', (int) $pet_stat_time ) )
        );
    }
}

function wc_petkey_gallery_save_meta( $post_id, $post ) {

    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }

    if ( ! isset( $_POST['post_type'] ) || 'pet-gallery' !== $_POST['post_type'] ) {
        return;
    }

    if ( $post->post_type === 'revision' ) {
        return;
    }

    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    if ( empty( $_POST['petkey_gallery_meta_noncename'] )
        || ! wp_verify_nonce( $_POST['petkey_gallery_meta_noncename'], 'petkey_gallery_meta_save' ) ) {
        return;
    }

    $map = array(
        'petid'        => 'pet_id',
        'reference_id' => 'pet_reference_id',
        'name'         => 'pet_name',
        'age'          => 'pet_age',
        'animal_type'  => 'pet_animal_type',
        'breed_name'   => 'pet_breed_name',
        'coloring'     => 'pet_coloring',
        'gender'       => 'pet_gender',
        'birth_date'   => 'pet_birth_date',
    );

    foreach ( $map as $meta_key => $input_name ) {
        if ( ! isset( $_POST[ $input_name ] ) ) {
            continue;
        }
        $value = sanitize_text_field( wp_unslash( $_POST[ $input_name ] ) );
        update_post_meta( $post_id, $meta_key, $value );
    }
}

function wc_petkey_gallery_image_columns( $columns ) {
    $columns = array(
        'cb' => '<input type="checkbox" />',
        'featured_image' => 'Image',
        'title' => 'Title',
        'pet_gender' => 'Gender',
        'date' => 'Date'
     );
    return $columns;
}

function wc_petkey_gallery_image_column_data( $column, $post_id ) {
    switch ( $column ) {
        case 'featured_image':
            echo the_post_thumbnail( array(50, 50) );
            break;
        
        case 'pet_gender':
            echo get_post_meta( $post_id, 'gender', true );
            break;
    }
}
