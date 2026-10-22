<?php
/**
 * The Status panel: what the settings page shows instead of making an operator
 * guess.
 *
 * The page used to say exactly one thing about whether anything worked — "No
 * sync recorded yet." — and then presented a 100-row table whose every row read
 * "No API status returned", a Petkey-shaped verdict that cannot be anything
 * else on a UKM site. Neither told anyone that the gallery was empty because
 * nothing was configured to fill it.
 *
 * @package WooCommerce Pet Gallery
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'WCPK_Gallery_Status' ) ) {
	return;
}

class WCPK_Gallery_Status {

	const COUNT_CACHE_KEY = 'wcpk_gallery_status_counts';
	const COUNT_CACHE_TTL = 300;
	const HEALTH_KEY      = 'wcpk_gallery_status_health';

	/**
	 * The parent sync plugin driving this site.
	 *
	 * @return string petkey|ukm|pinogy|none
	 */
	public static function provider() {
		return defined( 'WCPK_GALLERY_PARENT_MODE' ) ? WCPK_GALLERY_PARENT_MODE : 'none';
	}

	/**
	 * Human label for the provider.
	 *
	 * @return string
	 */
	public static function provider_label() {
		switch ( self::provider() ) {
			case 'petkey':
				return __( 'Petkey Sync', 'petkey-gallery' );
			case 'ukm':
				return __( 'Unified KM', 'petkey-gallery' );
			case 'pinogy':
				return __( 'Pinogy Sync', 'petkey-gallery' );
		}

		return __( 'None detected', 'petkey-gallery' );
	}

	/**
	 * Counts, cached briefly — some are whole-table scans and the panel is a
	 * page an operator reloads while fiddling with settings.
	 *
	 * @param bool $fresh Bypass the cache.
	 * @return array
	 */
	public static function counts( $fresh = false ) {

		if ( ! $fresh ) {
			$cached = get_transient( self::COUNT_CACHE_KEY );

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		global $wpdb;

		$counts = array(
			'in_gallery'   => 0,
			'eligible'     => null,
			'unmirrored'   => null,
			'no_photo'     => null,
			'hidden'       => 0,
			'by_type'      => array(),
			'ukm_gallery'  => wcpk_gallery_ukm_gallery_active(),
		);

		$published            = wp_count_posts( 'pet-gallery' );
		$counts['in_gallery'] = isset( $published->publish ) ? (int) $published->publish : 0;

		/*
		 * Per-type counts must union BOTH schemas through the classifier. Reading
		 * only `animal_type` reports 0 for every bucket on a UKM site, where the
		 * value lives in `_pet_type` and is a term name rather than a code.
		 * `_wcpk_type_code` already is that union, so one grouped read does it.
		 */
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_value AS code, COUNT(*) AS n
				   FROM {$wpdb->postmeta} pm
				   INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				  WHERE pm.meta_key = %s
				    AND p.post_type = 'pet-gallery'
				    AND p.post_status = 'publish'
				  GROUP BY pm.meta_value",
				WCPK_GALLERY_TYPE_META
			)
		);

		foreach ( (array) $rows as $row ) {
			$counts['by_type'][ (string) $row->code ] = (int) $row->n;
		}

		$allowed = wcpk_gallery_get_allowed_type_codes();

		if ( ! empty( $allowed ) ) {
			foreach ( $counts['by_type'] as $code => $n ) {
				if ( ! in_array( $code, $allowed, true ) ) {
					$counts['hidden'] += $n;
				}
			}
		}

		$source = self::source_counts();
		if ( is_array( $source ) ) {
			$counts = array_merge( $counts, $source );
		}

		set_transient( self::COUNT_CACHE_KEY, $counts, self::COUNT_CACHE_TTL );

		return $counts;
	}

	/**
	 * Provider-specific "how many pets could be in the gallery" numbers.
	 *
	 * @return array|null
	 */
	protected static function source_counts() {

		global $wpdb;

		$statuses = self::source_statuses();

		if ( empty( $statuses ) ) {
			return null;
		}

		$in = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		// Eligible = in a source status AND has a featured image. The image
		// requirement is part of the definition, not a later filter, so the
		// number means what it says.
		$eligible = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				   FROM {$wpdb->posts} p
				   INNER JOIN {$wpdb->postmeta} t
				           ON t.post_id = p.ID AND t.meta_key = '_thumbnail_id' AND t.meta_value > 0
				  WHERE p.post_type = 'product'
				    AND p.post_status IN ( {$in} )",
				$statuses
			)
		);

		$no_photo = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				   FROM {$wpdb->posts} p
				   LEFT JOIN {$wpdb->postmeta} t
				          ON t.post_id = p.ID AND t.meta_key = '_thumbnail_id'
				  WHERE p.post_type = 'product'
				    AND p.post_status IN ( {$in} )
				    AND ( t.post_id IS NULL OR t.meta_value = '' OR t.meta_value = '0' )",
				$statuses
			)
		);

		/*
		 * Not yet mirrored must anti-join BOTH link keys: our own
		 * `_wcpk_gallery_id` and wc-ukm-gallery's `ukm_gallery_id`. Checking
		 * only ours would report "Not yet mirrored: 2,882" on a site that
		 * wc-ukm-gallery has already fully populated — a healthy site told it
		 * is completely broken.
		 */
		$unmirrored = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				   FROM {$wpdb->posts} p
				   INNER JOIN {$wpdb->postmeta} t
				           ON t.post_id = p.ID AND t.meta_key = '_thumbnail_id' AND t.meta_value > 0
				   LEFT JOIN {$wpdb->postmeta} ours
				          ON ours.post_id = p.ID AND ours.meta_key = '_wcpk_gallery_id'
				   LEFT JOIN {$wpdb->postmeta} theirs
				          ON theirs.post_id = p.ID AND theirs.meta_key = 'ukm_gallery_id'
				  WHERE p.post_type = 'product'
				    AND p.post_status IN ( {$in} )
				    AND ours.post_id IS NULL
				    AND theirs.post_id IS NULL",
				$statuses
			)
		);

		return array(
			'eligible'   => $eligible,
			'no_photo'   => $no_photo,
			'unmirrored' => $unmirrored,
			'statuses'   => $statuses,
		);
	}

	/**
	 * Which product statuses count as a gallery source on this site.
	 *
	 * @return string[]
	 */
	public static function source_statuses() {

		$saved = get_option( 'petkey_gallery_source_statuses', array() );

		if ( is_array( $saved ) && ! empty( $saved ) ) {
			return array_map( 'sanitize_key', $saved );
		}

		switch ( self::provider() ) {
			case 'ukm':
				// "Sold" — the actual found-a-home signal on UKM. "Removed"
				// (`unavailable`) is a catch-all that also covers returned,
				// deceased and transferred pets.
				return array( 'sold_puppy' );
			case 'petkey':
			case 'pinogy':
				// Unregistered statuses stripped — see
				// wcpk_gallery_registered_statuses_only().
				return wcpk_gallery_registered_statuses_only(
					(array) apply_filters( 'wcpk_gallery_pending_product_statuses', array( 'unavailable' ) )
				);
		}

		return array();
	}

	/**
	 * Every product status that actually exists on this site, with its label
	 * and count, for the source-status chooser.
	 *
	 * Built from wp_count_posts() keys plus get_post_status_object(), because
	 * get_post_stati( ['post_type' => 'product'] ) cannot work: UKM registers
	 * its statuses with 'post_type' => ['product'] but core statuses carry no
	 * post_type property at all, so that query matches nothing useful.
	 *
	 * @return array<string, array{label:string,count:int}>
	 */
	public static function product_status_choices() {

		if ( ! post_type_exists( 'product' ) ) {
			return array();
		}

		$counts = (array) wp_count_posts( 'product' );
		$out    = array();

		foreach ( $counts as $slug => $n ) {
			if ( in_array( $slug, array( 'auto-draft', 'inherit', 'trash' ), true ) || (int) $n < 1 ) {
				continue;
			}

			$object = get_post_status_object( $slug );

			$out[ $slug ] = array(
				'label' => $object && ! empty( $object->label ) ? $object->label : $slug,
				'count' => (int) $n,
			);
		}

		// By count, descending — the status with the most pets is the one an
		// operator is looking for. arsort() here sorted by the array VALUE,
		// which is a sub-array, so it was really comparing labels.
		uasort(
			$out,
			static function ( $a, $b ) {
				return $b['count'] <=> $a['count'];
			}
		);

		return $out;
	}

	/**
	 * When the ingest last ran and when it next will.
	 *
	 * @return array{last:int,next:int,scheduled:bool,mode:string}
	 */
	public static function schedule() {

		$provider = self::provider();
		$next     = 0;
		$mode     = 'none';

		if ( 'petkey' === $provider ) {
			$mode = 'cron';
			$next = (int) wp_next_scheduled( 'wc_petkey_gallery_data' );
		} elseif ( 'pinogy' === $provider ) {
			$mode = 'order_hooks';
		} elseif ( 'ukm' === $provider ) {
			$mode = 'ukm';

			if ( function_exists( 'as_next_scheduled_action' ) ) {
				$scheduled = as_next_scheduled_action( 'wcpk_gallery_ukm_scan' );
				$next      = is_numeric( $scheduled ) ? (int) $scheduled : 0;
			}
		}

		$last_raw = get_option( 'petgallery_check_updated_on', '' );
		$last     = $last_raw ? (int) strtotime( (string) $last_raw ) : 0;

		return array(
			'last'      => $last,
			'next'      => $next,
			'scheduled' => ( $next > 0 || 'order_hooks' === $mode ),
			'mode'      => $mode,
		);
	}

	/**
	 * Updater state, for the Updates row.
	 *
	 * @return array
	 */
	public static function updates() {
		return array(
			'version'    => defined( 'PETGALLERYVERSION' ) ? PETGALLERYVERSION : '',
			'channel'    => function_exists( 'wcpk_gallery_release_channel' ) ? wcpk_gallery_release_channel() : 'stable',
			'repo'       => function_exists( 'wcpk_gallery_update_repo_url' ) ? wcpk_gallery_update_repo_url() : '',
			'last_check' => get_option( 'wcpk_gallery_updater_last_check', array() ),
			'last_error' => get_option( 'wcpk_gallery_updater_last_error', array() ),
			'has_key'    => defined( 'COSMICK_AUTH_KEY' ) && ! empty( COSMICK_AUTH_KEY ),
		);
	}

	/**
	 * Actually fetch the public gallery and count the cards in it.
	 *
	 * This is the check that matters. Every other check here inspects
	 * configuration, and configuration checks can all pass while the page is
	 * blank — which is the literal complaint this panel exists to answer. Run
	 * on demand and cached, because it is a loopback HTTP request.
	 *
	 * @return array{ok:bool,status:int,cards:int,message:string,time:int}
	 */
	public static function health_check() {

		$url = get_post_type_archive_link( 'pet-gallery' );

		if ( ! $url ) {
			$result = array(
				'ok'      => false,
				'status'  => 0,
				'cards'   => 0,
				'message' => __( 'The gallery archive has no URL — the post type is not registered as public.', 'petkey-gallery' ),
				'time'    => time(),
			);

			update_option( self::HEALTH_KEY, $result, false );
			return $result;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 20,
				'redirection' => 3,
				// Skip the page cache so we measure the application, not a
				// stored copy of yesterday's answer.
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$result = array(
				'ok'      => false,
				'status'  => 0,
				'cards'   => 0,
				'message' => $response->get_error_message(),
				'time'    => time(),
			);

			update_option( self::HEALTH_KEY, $result, false );
			return $result;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		$cards  = substr_count( $body, 'wcpk-card ' );

		/*
		 * Whether the page is ours at all. A theme with its own
		 * archive-pet-gallery.php renders its own markup, which carries none of
		 * our classes — counting them there would report a perfectly healthy
		 * site as broken, forever, with no way to clear it.
		 */
		$ours = ( false !== strpos( $body, 'data-wcpk-gallery' ) );

		$result = array(
			'ok'      => ( $status < 400 && ( $cards > 0 || ! $ours ) ),
			'status'  => $status,
			'cards'   => $cards,
			'message' => '',
			'time'    => time(),
		);

		if ( $status >= 400 ) {
			/* translators: %d: HTTP status code. */
			$result['message'] = sprintf( __( 'The gallery page returned HTTP %d.', 'petkey-gallery' ), $status );
		} elseif ( ! $ours ) {
			$result['message'] = __( 'The gallery page loaded. It is rendered by your theme, so the card count cannot be verified from here — open it to check.', 'petkey-gallery' );
		} elseif ( 0 === $cards ) {
			$result['message'] = __( 'The gallery page loaded, but rendered no pet cards.', 'petkey-gallery' );
		}

		update_option( self::HEALTH_KEY, $result, false );

		return $result;
	}

	/**
	 * The last health check, without running a new one.
	 *
	 * @return array|null
	 */
	public static function last_health() {
		$stored = get_option( self::HEALTH_KEY, null );

		return is_array( $stored ) ? $stored : null;
	}

	/**
	 * Problems worth telling the operator about, in plain language, each with
	 * something they can actually do.
	 *
	 * @return array<int, array{level:string,text:string,action:string,action_label:string}>
	 */
	public static function problems() {

		$problems = array();
		$provider = self::provider();
		$counts   = self::counts();
		$updates  = self::updates();

		if ( 0 === $counts['in_gallery'] ) {
			if ( 'ukm' === $provider && ! $counts['ukm_gallery'] && ! self::ukm_ingest_on() ) {
				$problems[] = array(
					'level'        => 'error',
					'text'         => __( 'Nothing is filling the gallery. On a Unified KM site, mirroring has to be switched on before any sold pets appear here.', 'petkey-gallery' ),
					'action'       => admin_url( 'admin.php?page=petkey-setting-gallery&tab=advanced' ),
					'action_label' => __( 'Open sync settings', 'petkey-gallery' ),
				);
			} else {
				$problems[] = array(
					'level'        => 'warning',
					'text'         => __( 'The gallery has no pets in it yet.', 'petkey-gallery' ),
					'action'       => '',
					'action_label' => '',
				);
			}
		}

		if ( $counts['in_gallery'] > 0 && ! empty( $counts['hidden'] ) ) {
			$problems[] = array(
				'level'        => 'warning',
				'text'         => sprintf(
					/* translators: %s: number of pets. */
					__( '%s pets are in the gallery but hidden by the "Pet types to show" setting.', 'petkey-gallery' ),
					number_format_i18n( $counts['hidden'] )
				),
				'action'       => admin_url( 'admin.php?page=petkey-setting-gallery&tab=gallery' ),
				'action_label' => __( 'Review pet types', 'petkey-gallery' ),
			);
		}

		if ( ! $updates['has_key'] ) {
			$problems[] = array(
				'level'        => 'warning',
				'text'         => __( 'Automatic updates are off: no COSMICK_AUTH_KEY is defined in wp-config.php.', 'petkey-gallery' ),
				'action'       => '',
				'action_label' => '',
			);
		}

		if ( ! empty( $updates['last_error']['message'] ) ) {
			$problems[] = array(
				'level'        => 'error',
				'text'         => sprintf(
					/* translators: %s: error message from the update checker. */
					__( 'The last update check failed: %s', 'petkey-gallery' ),
					$updates['last_error']['message']
				),
				'action'       => '',
				'action_label' => '',
			);
		}

		$health = self::last_health();

		if ( is_array( $health ) && ! $health['ok'] && ! empty( $health['message'] ) ) {
			$problems[] = array(
				'level'        => 'error',
				'text'         => $health['message'],
				'action'       => '',
				'action_label' => '',
			);
		}

		if ( 'petkey' === $provider ) {
			$schedule = self::schedule();

			if ( ! $schedule['scheduled'] ) {
				$problems[] = array(
					'level'        => 'error',
					'text'         => __( 'The hourly sync is not scheduled. New adoptions will not reach the gallery on their own.', 'petkey-gallery' ),
					'action'       => '',
					'action_label' => '',
				);
			}
		}

		return (array) apply_filters( 'wcpk_gallery_status_checks', $problems );
	}

	/**
	 * Whether the UKM ingest is armed. Defined here so the panel can ask the
	 * question before the ingest module itself exists.
	 *
	 * @return bool
	 */
	public static function ukm_ingest_on() {
		return 'on' === get_option( 'wcpk_gallery_ukm_ingest_mode', 'off' );
	}

	/**
	 * Drop the cached counts.
	 */
	public static function flush() {
		delete_transient( self::COUNT_CACHE_KEY );
	}
}
