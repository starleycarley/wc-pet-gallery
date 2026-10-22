<?php

function register_petgallery_widget() {
    register_widget( 'Petgallery_Widget' );
}

add_action( 'widgets_init', 'register_petgallery_widget' );

class Petgallery_Widget extends WP_Widget {

	
	function __construct() {
		parent::__construct(
			'petgallery_widget', // Base ID
			esc_html__( 'Pet Gallery', 'petgallery_widget' ), // Name
			array( 'description' => esc_html__( 'Pet Gallery Widget', 'petgallery_widget' ), ) // Args
		);
	}

	public function widget( $args, $instance ) {

		echo $args['before_widget'];

		if ( ! empty( $instance['title'] ) ) {
			echo $args['before_title'] . apply_filters( 'widget_title', $instance['title'] ) . $args['after_title'];
		}

		$number_of_images = isset( $instance['number_of_images'] ) ? max( 1, (int) $instance['number_of_images'] ) : 2;
		$pet_breed        = isset( $instance['pet_breed'] ) ? sanitize_text_field( $instance['pet_breed'] ) : '';
		$orderby          = isset( $instance['orderby'] ) ? sanitize_key( $instance['orderby'] ) : 'rand';
		$order            = ( isset( $instance['order'] ) && in_array( strtoupper( $instance['order'] ), array( 'ASC', 'DESC' ), true ) ) ? strtoupper( $instance['order'] ) : 'DESC';

		$gallery_args = array(
			'post_type'      => 'pet-gallery',
			'posts_per_page' => $number_of_images,
			'orderby'        => $orderby,
			'order'          => $order,
			'no_found_rows'  => true,
		);

		if ( ! empty( $pet_breed ) && $pet_breed !== 'ALL' ) {
			if ( function_exists( 'wcpk_gallery_meta_or_clause' ) ) {
				$gallery_args['meta_query'][] = wcpk_gallery_meta_or_clause( 'breed_name', $pet_breed );
			} else {
				$gallery_args['meta_query'][] = array(
					'key'   => 'breed_name',
					'value' => $pet_breed,
				);
			}
		}

		// Honour the "Pet types to show" setting here too, in SQL, so the widget
		// returns the number of pets it was asked for.
		if ( function_exists( 'wcpk_gallery_allowed_types_clause' ) ) {
			$type_clause = wcpk_gallery_allowed_types_clause();

			if ( null !== $type_clause ) {
				$gallery_args['meta_query'][] = $type_clause;
			}
		}

		if ( function_exists( 'wcpk_gallery_enqueue_assets' ) ) {
			wcpk_gallery_enqueue_assets();
		}

		$breeds_query = new WP_Query( $gallery_args );

                if ( $breeds_query->have_posts() ) {

                    echo '<div class="wcpk-gallery wcpk-gallery--widget"><div class="wcpk-grid wcpk-grid--widget">';

                    while ( $breeds_query->have_posts() ) {

                        $breeds_query->the_post();
                        $post_id    = get_the_ID();
                        $post_link  = get_permalink( $post_id );
                        $post_image = get_the_post_thumbnail_url( $post_id, 'medium' );
                        $breed_name = function_exists( 'wcpk_gallery_get_pet_field' )
                            ? wcpk_gallery_get_pet_field( $post_id, 'breed_name' )
                            : get_post_meta( $post_id, 'breed_name', true );
                        $gender     = function_exists( 'wcpk_gallery_get_pet_field' )
                            ? wcpk_gallery_get_pet_field( $post_id, 'gender' )
                            : get_post_meta( $post_id, 'gender', true );

                        $alt = trim( $breed_name . ' ' . $gender );

                        echo '<article class="wcpk-card wcpk-card--widget">';
                        echo '<a href="' . esc_url( $post_link ) . '" class="wcpk-card__photo">';
                        if ( $post_image ) {
                            echo '<img src="' . esc_url( $post_image ) . '" alt="' . esc_attr( $alt ) . '" loading="lazy" decoding="async">';
                        }
                        echo '<span class="wcpk-sticker"><small>Forever</small><strong>Adopted</strong></span>';
                        echo '</a>';
                        if ( $breed_name ) {
                            echo '<div class="wcpk-card__body"><h4 class="wcpk-card__title"><a href="' . esc_url( $post_link ) . '">' . esc_html( $breed_name ) . '</a></h4></div>';
                        }
                        echo '</article>';
                    }

                    echo '</div></div>';

                    wp_reset_postdata();
                }
		
		echo $args['after_widget'];
	}
	
	public function form( $instance ) {
		global $petkey_gallery_functions;

		$title            = ! empty( $instance['title'] ) ? $instance['title'] : esc_html__( 'New title', 'petgallery_widget' );
		$number_of_images = ! empty( $instance['number_of_images'] ) ? $instance['number_of_images'] : '2';
		$pet_breed        = ! empty( $instance['pet_breed'] ) ? $instance['pet_breed'] : '';
		$orderby          = ! empty( $instance['orderby'] ) ? $instance['orderby'] : '';
		$order            = ! empty( $instance['order'] ) ? $instance['order'] : '';

		$breeds = ( isset( $petkey_gallery_functions ) && is_object( $petkey_gallery_functions ) )
			? $petkey_gallery_functions->get_breeds()
			: array();
		?>
		<p>
                    <label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_attr_e( 'Title:', 'petgallery_widget' ); ?></label> 
                    <input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>">
		</p>
		<p>
                    <label for="<?php echo esc_attr( $this->get_field_id( 'number_of_images' ) ); ?>"><?php esc_attr_e( 'Number of Images:', 'petgallery_widget' ); ?></label> 
                    <input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'number_of_images' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'number_of_images' ) ); ?>" type="text" value="<?php echo esc_attr( $number_of_images ); ?>">
		</p>
		<p>
                    <label for="<?php echo esc_attr( $this->get_field_id( 'pet_breed' ) ); ?>"><?php esc_attr_e( 'Breed:', 'petgallery_widget' ); ?></label>                     
                    
                    <select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'pet_breed' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'pet_breed' ) ); ?>">
                        <option value="ALL">ALL</option>
                        <?php if ( ! empty( $breeds ) ) : ?>
                            <?php foreach ( $breeds as $value ) : ?>
                            <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, $pet_breed ); ?>><?php echo esc_html( $value ); ?></option>
                            <?php endforeach ?>
                        <?php endif ?>
                    </select>
		</p>
                <p>
                    <label for="<?php echo esc_attr( $this->get_field_id( 'orderby' ) ); ?>"><?php esc_attr_e( 'Order By:', 'petgallery_widget' ); ?></label>                     
                    <select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'orderby' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'orderby' ) ); ?>"> 
                        <option value=""></option>
                        <option <?php echo ($orderby == 'rand' )?"selected='selected'":""; ?> value="rand">Random</option>
                        <option <?php echo ($orderby == 'title' )?"selected='selected'":""; ?> value="title">Name</option>
                        <option <?php echo ($orderby == 'post_date' )?"selected='selected'":""; ?> value="post_date">Date</option>
                    </select>
		</p>
                <p>
                    <label for="<?php echo esc_attr( $this->get_field_id( 'order' ) ); ?>"><?php esc_attr_e( 'Order:', 'petgallery_widget' ); ?></label>                     
                    <select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'order' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'order' ) ); ?>"> 
                        <option value=""></option>
                        <option <?php echo ($order == 'ASC' )?"selected='selected'":""; ?> value="ASC">Ascending</option>
                        <option <?php echo ($order == 'DESC' )?"selected='selected'":""; ?> value="DESC">Descending</option>
                    </select>
		</p>
                
		<?php 
	}

	public function update( $new_instance, $old_instance ) {
		$instance = array();
		$instance['title']            = ! empty( $new_instance['title'] ) ? sanitize_text_field( $new_instance['title'] ) : '';
		$instance['number_of_images'] = ! empty( $new_instance['number_of_images'] ) ? max( 1, (int) $new_instance['number_of_images'] ) : 2;
		$instance['pet_breed']        = ! empty( $new_instance['pet_breed'] ) ? sanitize_text_field( $new_instance['pet_breed'] ) : '';
		$instance['orderby']          = ! empty( $new_instance['orderby'] ) ? sanitize_key( $new_instance['orderby'] ) : 'rand';
		$order_raw                    = ! empty( $new_instance['order'] ) ? strtoupper( sanitize_text_field( $new_instance['order'] ) ) : 'DESC';
		$instance['order']            = in_array( $order_raw, array( 'ASC', 'DESC' ), true ) ? $order_raw : 'DESC';
		return $instance;
	}

} // class Petgallery_Widget
