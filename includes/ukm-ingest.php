<?php
/**
 * Native UKM ingest: mirror sold pets into the gallery.
 *
 * Why this file exists
 * --------------------
 * On a Unified KM site the plugin registered no ingest at all. The cron hook is
 * only wired in petkey mode and is actively cleared otherwise, and readme.txt
 * said the UKM path was "suppressed (UKM has its own via the wc-ukm-gallery
 * plugin)" — which is only true on sites that have that plugin installed. Where
 * it is absent, nothing has ever created a gallery post.
 *
 * Why it is a separate file rather than a branch in the existing code
 * ------------------------------------------------------------------
 * create_gallery_post_from_product() is Petkey-shaped in six places, and the
 * last thing it does is flip the source product to the `gallery_moved` status.
 * On a UKM site a pet must stay in `sold_puppy` — the warranty, certificate,
 * my-account and bulk tools all key off it — so a branch inside that function
 * would put a 2,882-pet status change one misplaced brace away. Nothing here
 * calls it, and nothing here ever calls wp_update_post() on a product.
 *
 * For the same reason `sold_puppy` is never added to the
 * `wcpk_gallery_pending_product_statuses` filter: that one-line "fix" routes
 * UKM pets straight through the function this file exists to avoid.
 *
 * @package WooCommerce Pet Gallery
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'WCPK_Gallery_UKM_Ingest' ) ) {
	return;
}

final class WCPK_Gallery_UKM_Ingest {

	/* Meta written on the PET (a WooCommerce product). */
	const LINK_META     = '_wcpk_gallery_id';
	const ATTEMPTS_META = '_wcpk_gallery_attempts';
	const ATTEMPT_META  = '_wcpk_gallery_last_attempt';
	const SKIP_META     = '_wcpk_gallery_skip_reason';
	const PREV_META     = '_wcpk_gallery_prev_id';

	/* Meta written on the GALLERY POST. */
	const BACK_META   = '_wcpk_gallery_pet_id';
	const SOURCE_META = '_wcpk_gallery_source';
	const RUN_META    = '_wcpk_run_id';
	const SOLD_META   = '_wcpk_gallery_sold_date';

	/* Cached on the PET, so the backfill window can be applied in SQL. */
	const SOLD_TS_META = '_wcpk_gallery_sold_ts';

	const PERMANENT_SKIP = -1;
	const RETRY_LIMIT    = 5;

	const MODE_OPTION     = 'wcpk_gallery_ukm_ingest_mode';
	const SINCE_OPTION    = 'wcpk_gallery_ukm_ingest_since';
	const BACKFILL_OPTION = 'wcpk_gallery_ukm_backfill';

	const HOOK_SCAN     = 'wcpk_gallery_ukm_scan';
	const HOOK_BACKFILL = 'wcpk_gallery_ukm_backfill_batch';
	const HOOK_ONE      = 'wcpk_gallery_ukm_mirror_pet';
	const HOOK_DEMIRROR = 'wcpk_gallery_ukm_demirror_pet';

	const BATCH = 100;

	/** @var bool Set while a batch runs, so cache listeners can stand down. */
	protected static $bulk = false;

	/** @var array<int, array{0:string,1:callable,2:int,3:int}> What begin_bulk() detached. */
	protected static $detached = array();

	/** @var array<string, bool> Locks this process currently owns. */
	protected static $held = array();

	/* ============================================================
	 * Wiring
	 * ============================================================ */

	public static function boot() {
		add_action( 'init', array( __CLASS__, 'register_schedule' ), 20 );

		add_action( self::HOOK_SCAN, array( __CLASS__, 'run_scan' ) );
		add_action( self::HOOK_BACKFILL, array( __CLASS__, 'run_backfill_batch' ) );
		add_action( self::HOOK_ONE, array( __CLASS__, 'mirror_one_async' ) );
		add_action( self::HOOK_DEMIRROR, array( __CLASS__, 'demirror_one_async' ) );

		// Live path: a pet entering or leaving a source status.
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition' ), 20, 3 );

		// Repair paths for a gallery post removed by hand.
		add_action( 'trashed_post', array( __CLASS__, 'on_gallery_trashed' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'on_gallery_untrashed' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'on_gallery_deleted' ) );
	}

	/**
	 * The three independent gates, re-checked at every entry point.
	 *
	 * The stand-down against wc-ukm-gallery is total rather than "both run and
	 * deduplicate": the two selection sets are different, not overlapping. That
	 * plugin is order-driven (completed orders older than N days); this one is
	 * product-status driven. Running both would mirror different pets by
	 * different rules into the same CPT.
	 *
	 * @return bool
	 */
	public static function enabled() {

		if ( ! defined( 'WCPK_GALLERY_PARENT_MODE' ) || 'ukm' !== WCPK_GALLERY_PARENT_MODE ) {
			return false;
		}

		/*
		 * function_exists because this file is now required regardless of the
		 * detected mode, so that Action Scheduler always finds a callback. The
		 * helper lives in template-loader.php; treat its absence as "the other
		 * plugin is not running" rather than fatalling inside a queue runner.
		 */
		if ( function_exists( 'wcpk_gallery_ukm_gallery_active' ) && wcpk_gallery_ukm_gallery_active() ) {
			return false;
		}

		return 'on' === get_option( self::MODE_OPTION, 'off' );
	}

	/**
	 * Registered on `init`, not `admin_init`. wc-ukm-gallery schedules on
	 * admin_init only, which means that on a store nobody logs into, its job
	 * is never scheduled at all — a failure mode worth not repeating.
	 */
	public static function register_schedule() {

		// Throttled in BOTH directions. Running the teardown unconditionally
		// meant an as_unschedule_all_actions() call — a database write — on
		// every front-end request of every UKM site with mirroring off, which
		// is most of them.
		if ( get_transient( 'wcpk_gallery_ukm_sched' ) ) {
			return;
		}

		set_transient( 'wcpk_gallery_ukm_sched', 1, HOUR_IN_SECONDS );

		if ( ! self::enabled() ) {
			// Clears the WP-Cron fallback too, not just Action Scheduler.
			self::unschedule_all();
			return;
		}

		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
			if ( ! as_has_scheduled_action( self::HOOK_SCAN ) ) {
				as_schedule_recurring_action( time() + 300, 12 * HOUR_IN_SECONDS, self::HOOK_SCAN, array(), 'wcpk-gallery' );
			}
		} elseif ( ! wp_next_scheduled( self::HOOK_SCAN ) ) {
			wp_schedule_event( time() + 300, 'twicedaily', self::HOOK_SCAN );
		}
	}

	/* ============================================================
	 * Switches
	 * ============================================================ */

	/**
	 * Arm or disarm the live path.
	 *
	 * Turning it on stamps a watermark. The recurring scan only looks at pets
	 * sold after that moment, so arming mirroring does NOT quietly start
	 * working through the entire historical backlog — that is the backfill's
	 * job, and the backfill only runs when someone presses the button.
	 *
	 * @param bool $on
	 */
	public static function set_mode( $on ) {

		update_option( self::MODE_OPTION, $on ? 'on' : 'off' );

		if ( $on && ! get_option( self::SINCE_OPTION ) ) {
			update_option( self::SINCE_OPTION, time(), false );
		}

		// register_schedule() throttles itself with this transient, so without
		// clearing it a toggle would not take effect for up to an hour.
		delete_transient( 'wcpk_gallery_ukm_sched' );

		// Switching mirroring off strands any backfill mid-run: the batch
		// runner early-returns on enabled(), so the state would sit at
		// 'running' forever and the UI would keep offering buttons for a job
		// that can never advance.
		if ( ! $on ) {
			$state = self::backfill_state();

			if ( 'running' === $state['status'] ) {
				$state['status'] = 'cancelled';
				self::save_backfill_state( $state );
			}
		}
	}

	/**
	 * Remove every schedule this class owns.
	 */
	protected static function unschedule_all() {

		foreach ( array( self::HOOK_SCAN, self::HOOK_BACKFILL, self::HOOK_ONE, self::HOOK_DEMIRROR ) as $hook ) {
			$timestamp = wp_next_scheduled( $hook );

			while ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
				$timestamp = wp_next_scheduled( $hook );
			}

			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $hook );
			}
		}
	}

	/**
	 * @return array
	 */
	public static function backfill_state() {
		$state = get_option( self::BACKFILL_OPTION, array() );

		return wp_parse_args(
			is_array( $state ) ? $state : array(),
			array(
				'status'        => 'idle',
				'run_id'        => '',
				'cursor'        => 0,
				'done'          => 0,
				'total'         => 0,
				'last_progress' => 0,
				'started'       => 0,
			)
		);
	}

	protected static function save_backfill_state( array $state ) {
		update_option( self::BACKFILL_OPTION, $state, false );
	}

	/**
	 * Start a backfill. Explicit, operator-initiated, never automatic.
	 *
	 * @param int $cap Optional hard limit, for a capped first run on staging.
	 */
	public static function start_backfill( $cap = 0 ) {

		$state = self::backfill_state();

		$state['status']        = 'running';
		$state['run_id']        = 'bf' . time();
		$state['cursor']        = 0;
		$state['done']          = 0;
		$state['cap']           = max( 0, (int) $cap );
		$state['total']         = self::count_candidates();
		$state['started']       = time();
		$state['last_progress'] = time();

		self::save_backfill_state( $state );
		self::enqueue_backfill();
	}

	public static function cancel_backfill() {
		$state           = self::backfill_state();
		$state['status'] = 'cancelled';
		self::save_backfill_state( $state );
	}

	protected static function enqueue_backfill() {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK_BACKFILL, array(), 'wcpk-gallery' );
		} elseif ( ! wp_next_scheduled( self::HOOK_BACKFILL ) ) {
			wp_schedule_single_event( time() + 10, self::HOOK_BACKFILL );
		}
	}

	/* ============================================================
	 * Selection
	 * ============================================================ */

	/**
	 * Product statuses that mean "this pet found a home".
	 *
	 * @return string[]
	 */
	public static function source_statuses() {
		$statuses = WCPK_Gallery_Status::source_statuses();

		return ! empty( $statuses ) ? $statuses : array( 'sold_puppy' );
	}

	/**
	 * How far back the backfill reaches, in months. 0 means no limit.
	 *
	 * @return int
	 */
	public static function window_months() {
		return (int) apply_filters( 'wcpk_gallery_ukm_backfill_months', (int) get_option( 'wcpk_gallery_ukm_backfill_months', 12 ) );
	}

	/**
	 * Candidate pet IDs.
	 *
	 * IDs only, and deliberately not wc_get_products(): UKM maps UKM_Pet onto
	 * every simple product and its constructor resolves terms, ancestry,
	 * location and age, so hydrating a few thousand of those per tick is a
	 * non-starter.
	 *
	 * The featured-image requirement is part of the query, so LIMIT applies to
	 * rows that actually qualify rather than to rows we then throw away.
	 *
	 * Note what is NOT in the anti-join: `ukm_gallery_id`. Excluding a pet
	 * merely because that meta is present would make a pet with a stale pointer
	 * — its gallery post deleted by hand — invisible to the backfill, the scan
	 * and the pending count simultaneously, which is precisely the "no pets and
	 * nothing pending" state this whole change exists to fix. mirror_pet()
	 * adjudicates it instead, at the cost of one pass.
	 *
	 * @param int $limit
	 * @param int $cursor Only IDs below this (descending walk). 0 = no cursor.
	 * @param bool $windowed Apply the date window.
	 * @return int[]
	 */
	public static function candidates( $limit = self::BATCH, $cursor = 0, $windowed = true ) {

		global $wpdb;

		$statuses = self::source_statuses();

		if ( empty( $statuses ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$where        = '';
		$params       = $statuses;

		$join = '';

		if ( $windowed ) {
			$months = self::window_months();

			if ( $months > 0 ) {
				/*
				 * Judge the window on the cached sold date where we have one,
				 * and let anything we have not measured yet through so it can
				 * be measured. A post_date prefilter alone was wrong in both
				 * directions: a pet created 14 months ago can have sold last
				 * week, and one created last week can have sold today.
				 *
				 * Because the comparison is against the CURRENT cutoff rather
				 * than a stamped verdict, widening the window recovers pets a
				 * narrower one skipped.
				 */
				$join     = " LEFT JOIN {$wpdb->postmeta} sold
				                    ON sold.post_id = p.ID
				                   AND sold.meta_key = '" . self::SOLD_TS_META . "'";
				$where   .= ' AND ( sold.post_id IS NULL OR CAST( sold.meta_value AS UNSIGNED ) >= %d )';
				$params[] = (int) strtotime( '-' . $months . ' months' );
			}
		}

		if ( $cursor > 0 ) {
			$where   .= ' AND p.ID < %d';
			$params[] = (int) $cursor;
		}

		$params[] = max( 1, (int) $limit );

		$sql = "SELECT p.ID
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} thumb
			        ON thumb.post_id = p.ID
			       AND thumb.meta_key = '_thumbnail_id'
			       AND thumb.meta_value > 0
			LEFT JOIN {$wpdb->postmeta} lnk
			       ON lnk.post_id = p.ID
			      AND lnk.meta_key = '" . self::LINK_META . "'
			{$join}
			WHERE p.post_type = 'product'
			  AND p.post_status IN ( {$placeholders} )
			  AND lnk.post_id IS NULL
			  {$where}
			ORDER BY p.ID DESC
			LIMIT %d";

		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $params ) );

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * @return int
	 */
	public static function count_candidates() {

		global $wpdb;

		$statuses = self::source_statuses();

		if ( empty( $statuses ) ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$params       = $statuses;
		$where        = '';
		$join         = '';
		$months       = self::window_months();

		if ( $months > 0 ) {
			$join     = " LEFT JOIN {$wpdb->postmeta} sold
			                    ON sold.post_id = p.ID
			                   AND sold.meta_key = '" . self::SOLD_TS_META . "'";
			$where   .= ' AND ( sold.post_id IS NULL OR CAST( sold.meta_value AS UNSIGNED ) >= %d )';
			$params[] = (int) strtotime( '-' . $months . ' months' );
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				   FROM {$wpdb->posts} p
				   INNER JOIN {$wpdb->postmeta} thumb
				           ON thumb.post_id = p.ID AND thumb.meta_key = '_thumbnail_id' AND thumb.meta_value > 0
				   LEFT JOIN {$wpdb->postmeta} lnk
				          ON lnk.post_id = p.ID AND lnk.meta_key = '" . self::LINK_META . "'
				   {$join}
				  WHERE p.post_type = 'product'
				    AND p.post_status IN ( {$placeholders} )
				    AND lnk.post_id IS NULL
				    {$where}",
				$params
			)
		);
	}

	/* ============================================================
	 * Per-pet mutex
	 * ============================================================ */

	/**
	 * Claim a pet.
	 *
	 * Per pet, not per batch: the live path fires from transition_post_status
	 * and holds no batch lock, so without this two processes can both clear all
	 * four duplicate nets before either of them writes.
	 *
	 * add_option() is the claim — it returns false when the row exists, which
	 * makes it atomic in a way update_option() is not.
	 *
	 * @param int $pet_id
	 * @return bool
	 */
	protected static function claim( $pet_id ) {

		global $wpdb;

		$key   = 'wcpk_gallery_ukm_lock_' . (int) $pet_id;
		$stale = self::read_lock( $key );

		if ( $stale ) {
			if ( ( time() - $stale ) <= 600 ) {
				return false;
			}

			/*
			 * Steal a lock left behind by a killed process — conditionally, so
			 * two processes noticing the same stale lock cannot both win. A
			 * delete-then-add pair would let both succeed: one deletes, both
			 * add, and the second add fails only if it loses a race it was never
			 * forced into. A compare-and-swap has exactly one winner.
			 */
			$stolen = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
					(string) time(),
					$key,
					(string) $stale
				)
			);

			if ( 1 !== (int) $stolen ) {
				return false;
			}

			wp_cache_delete( $key, 'options' );
			self::$held[ $key ] = true;

			register_shutdown_function(
				static function () use ( $key ) {
					if ( ! empty( self::$held[ $key ] ) ) {
						unset( self::$held[ $key ] );
						delete_option( $key );
					}
				}
			);

			return true;
		}

		if ( ! add_option( $key, time(), '', 'no' ) ) {
			return false;
		}

		self::$held[ $key ] = true;

		/*
		 * A max_execution_time kill does not run `finally`, and that is exactly
		 * the case that strands a lock.
		 *
		 * Guarded on still holding it: release() runs first in the normal case
		 * and clears the flag, and once it has, another process may legitimately
		 * own this key. An unconditional delete here would hand that process's
		 * lock away at the end of our request.
		 */
		register_shutdown_function(
			static function () use ( $key ) {
				if ( ! empty( self::$held[ $key ] ) ) {
					unset( self::$held[ $key ] );
					delete_option( $key );
				}
			}
		);

		return true;
	}

	/**
	 * Read a lock with direct SQL.
	 *
	 * get_option() would consult the cached `notoptions` list, and because the
	 * row is created by add_option() in another process, a stale negative cache
	 * would make this return 0 forever and the steal path would never fire.
	 *
	 * @param string $key
	 * @return int
	 */
	protected static function read_lock( $key ) {

		global $wpdb;

		$value = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $key )
		);

		return $value ? (int) $value : 0;
	}

	protected static function release( $pet_id ) {
		$key = 'wcpk_gallery_ukm_lock_' . (int) $pet_id;

		unset( self::$held[ $key ] );
		delete_option( $key );
	}

	/* ============================================================
	 * Mirror
	 * ============================================================ */

	/**
	 * Mirror one pet into the gallery.
	 *
	 * Never touches the product's post_status. That is the hard constraint of
	 * this whole feature: UKM pets must stay in `sold_puppy`.
	 *
	 * @param int    $pet_id
	 * @param string $run_id
	 * @return int|false Gallery post ID, or false when skipped.
	 */
	public static function mirror_pet( $pet_id, $run_id = '' ) {

		$pet_id = (int) $pet_id;

		if ( $pet_id <= 0 || 'product' !== get_post_type( $pet_id ) ) {
			return false;
		}

		if ( ! self::claim( $pet_id ) ) {
			return false;
		}

		try {
			return self::mirror_pet_locked( $pet_id, $run_id );
		} catch ( \Throwable $e ) {
			error_log( sprintf( '[wc-pet-gallery] mirror of pet %d failed: %s', $pet_id, $e->getMessage() ) );
			return false;
		} finally {
			self::release( $pet_id );
		}
	}

	/**
	 * @param int    $pet_id
	 * @param string $run_id
	 * @return int|false
	 */
	protected static function mirror_pet_locked( $pet_id, $run_id ) {

		/*
		 * Write-ahead. Bumped BEFORE the work, so a hard fatal still leaves
		 * evidence and the retry limit eventually flips the pet to a permanent
		 * skip rather than re-fataling on every run forever.
		 */
		$attempts = (int) get_post_meta( $pet_id, self::ATTEMPTS_META, true ) + 1;
		update_post_meta( $pet_id, self::ATTEMPTS_META, $attempts );
		update_post_meta( $pet_id, self::ATTEMPT_META, time() );

		if ( $attempts > self::RETRY_LIMIT ) {
			update_post_meta( $pet_id, self::LINK_META, self::PERMANENT_SKIP );
			update_post_meta( $pet_id, self::SKIP_META, 'retry_limit' );
			return false;
		}

		/* ---- Net 1: our own link ---------------------------------------- */
		$linked = get_post_meta( $pet_id, self::LINK_META, true );

		if ( (string) $linked === (string) self::PERMANENT_SKIP ) {
			return false;
		}

		if ( $linked && (int) $linked > 0 ) {
			/*
			 * Honoured only when the target post points back at THIS pet.
			 *
			 * UKM's duplicator copies a pet's meta with NOT LIKE exclusions
			 * that this key does not match, while micro_chip IS stripped and
			 * reference_number is regenerated — so a cloned littermate would
			 * inherit the link, be excluded from the gallery forever, and not
			 * even appear as pending. Validating the back-pointer catches it.
			 */
			$back = (int) get_post_meta( (int) $linked, self::BACK_META, true );

			if ( $back === $pet_id && get_post( (int) $linked ) instanceof WP_Post ) {
				self::settle( $pet_id );
				return (int) $linked;
			}

			delete_post_meta( $pet_id, self::LINK_META );
		}

		/* ---- Net 2: wc-ukm-gallery's link ------------------------------- */
		$theirs = (int) get_post_meta( $pet_id, 'ukm_gallery_id', true );

		if ( $theirs > 0 ) {
			$their_back = (int) get_post_meta( $theirs, self::BACK_META, true );

			/*
			 * Adopt their post only when it is unclaimed, or already claimed by
			 * this pet. `ukm_gallery_id` is copied by UKM's duplicator exactly
			 * like our own key is, so a cloned littermate inherits a pointer to
			 * its sibling's card — and linking to it would exclude the clone
			 * from the gallery permanently while silently giving two pets one
			 * card.
			 */
			/*
			 * Nets 3 and 4 pin `post_type` in their SQL; this one read a stored id
			 * and adopted whatever it named. A stale pointer -- and a cloned or
			 * re-imported store has plenty -- can therefore name a PRODUCT, which
			 * link() would stamp with our back-pointer and demirror_pet() would
			 * later wp_trash_post(). That is a product post_status write, the one
			 * thing the UKM path must never do. Anything that is not a live
			 * pet-gallery post falls through to net 3, which already knows how to
			 * untrash and republish.
			 */
			$their_ok = get_post( $theirs ) instanceof WP_Post
				&& 'pet-gallery' === get_post_type( $theirs )
				&& ! in_array( get_post_status( $theirs ), array( 'trash', 'auto-draft' ), true );

			if ( $their_ok && ( 0 === $their_back || $their_back === $pet_id ) ) {
				self::link( $pet_id, $theirs );
				self::settle( $pet_id );
				return $theirs;
			}

			delete_post_meta( $pet_id, 'ukm_gallery_id' );
		}

		/* ---- Net 3: reverse lookup (crash recovery) --------------------- */
		$existing = self::find_gallery_by_pet( $pet_id );

		if ( $existing > 0 ) {
			/*
			 * The lookup deliberately includes trashed posts, because that is
			 * how a crashed run is recovered — but linking a pet to a trashed
			 * card would leave it pointing at something invisible, with nothing
			 * that ever untrashes it. A pet re-sold after being de-mirrored
			 * would simply never come back.
			 */
			if ( 'trash' === get_post_status( $existing ) ) {
				wp_untrash_post( $existing );

				// Untrashing restores to the pre-trash status, which on modern
				// WP is 'draft' unless _wp_trash_meta_status says otherwise.
				if ( 'publish' !== get_post_status( $existing ) ) {
					wp_update_post(
						array(
							'ID'          => $existing,
							'post_status' => 'publish',
						)
					);
				}
			}

			if ( 'publish' === get_post_status( $existing ) ) {
				self::link( $pet_id, $existing );
				self::settle( $pet_id );

				return $existing;
			}

			// Could not be revived — fall through and build a fresh card.
		}

		/* ---- Net 4: microchip ------------------------------------------- */
		$chip = self::normalize_chip( get_post_meta( $pet_id, 'micro_chip', true ) );

		if ( '' !== $chip ) {
			$by_chip = self::find_gallery_by_chip( $chip );

			if ( $by_chip > 0 ) {
				self::link( $pet_id, $by_chip );
				self::settle( $pet_id );
				update_post_meta( $pet_id, self::SKIP_META, 'microchip_duplicate:' . $by_chip );
				return $by_chip;
			}
		}

		/* ---- Create ------------------------------------------------------ */
		$fields = self::collect_fields( $pet_id );

		if ( empty( $fields['thumbnail_id'] ) ) {
			update_post_meta( $pet_id, self::SKIP_META, 'no_image' );
			return false;
		}

		// Sold date drives both post_date and the "Adopted <Month> <Year>"
		// stamp on the card. Without a real one, a backfill gives thousands of
		// posts the same timestamp, the cards all claim the same month, and
		// LIMIT/OFFSET paging over the ties is not stable.
		$sold_ts = $fields['sold_ts'];

		/*
		 * Remember the sold date on the pet. It is what the window is judged
		 * against, and caching it here lets candidates() apply the window in
		 * SQL instead of re-deriving it per pet on every pass.
		 */
		if ( $sold_ts > 0 ) {
			update_post_meta( $pet_id, self::SOLD_TS_META, $sold_ts );
		}

		if ( self::window_months() > 0 && $sold_ts > 0 ) {
			$cutoff = strtotime( '-' . self::window_months() . ' months' );

			if ( $sold_ts < $cutoff ) {
				/*
				 * Deliberately NOT a permanent skip. The window is a setting,
				 * and stamping LINK_META here would mean widening it later
				 * could never recover these pets — they would be invisible to
				 * the backfill, the scan and the pending count at once, which
				 * is the exact failure this feature exists to fix. The cached
				 * sold date keeps them cheap to skip while leaving them
				 * reachable.
				 */
				update_post_meta( $pet_id, self::SKIP_META, 'outside_window' );
				return false;
			}
		}

		$post_date = $sold_ts > 0 ? $sold_ts : (int) get_post_time( 'U', true, $pet_id );
		$post_date = min( $post_date, time() );

		/*
		 * Slug-collision guard. None of the four nets can see a legacy card
		 * whose pet-side pointer was lost: candidates() anti-joins only our own
		 * key, net 1 reads that same key, net 2 reads `ukm_gallery_id`, net 3 is
		 * keyed on a back-pointer wc-ukm-gallery never wrote, and net 4 needs a
		 * microchip on both sides. The slug is the one thing both plugins derive
		 * identically, so it is what catches the rest -- including a re-imported
		 * product, whose new ID would defeat any id-based pointer.
		 */
		$twin = self::find_gallery_by_slug( $fields['slug'] );

		if ( $twin > 0 ) {
			$twin_back = (int) get_post_meta( $twin, self::BACK_META, true );

			if ( 0 === $twin_back || $twin_back === $pet_id ) {
				self::link( $pet_id, $twin );
				self::settle( $pet_id );

				return $twin;
			}

			/*
			 * The slug belongs to a DIFFERENT pet. Publishing `<slug>-2` would put
			 * two cards on the archive for what the public sees as one animal, so
			 * record the conflict for the operator instead. Not a permanent skip --
			 * SKIP_META leaves the pet reachable once the clash is resolved.
			 */
			update_post_meta( $pet_id, self::SKIP_META, 'slug_conflict:' . $twin );

			return false;
		}

		$gallery_id = wp_insert_post(
			array(
				'post_title'   => $fields['title'],
				'post_name'    => $fields['slug'],
				'post_status'  => 'publish',
				'post_type'    => 'pet-gallery',
				'post_date'    => wp_date( 'Y-m-d H:i:s', $post_date ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $post_date ),
				// The back-pointer goes in with the insert, so it can never be
				// later than the row itself — that is what makes net 3 a
				// reliable crash-recovery path.
				'meta_input'   => array(
					self::BACK_META   => $pet_id,
					self::SOURCE_META => 'ukm',
					self::RUN_META    => $run_id,
				),
			),
			true
		);

		if ( is_wp_error( $gallery_id ) || ! $gallery_id ) {
			update_post_meta( $pet_id, self::SKIP_META, 'insert_failed' );
			return false;
		}

		$gallery_id = (int) $gallery_id;

		self::write_meta( $gallery_id, $fields, $post_date );
		set_post_thumbnail( $gallery_id, (int) $fields['thumbnail_id'] );

		if ( function_exists( 'wcpk_gallery_stamp_type_code' ) ) {
			wcpk_gallery_stamp_type_code( $gallery_id, true );
		}

		// The pet is linked LAST, so an interruption leaves an orphan the
		// reverse lookup finds rather than a link to a half-built post.
		self::link( $pet_id, $gallery_id );

		self::settle( $pet_id );

		do_action( 'wcpk_gallery_ukm_mirrored', $gallery_id, $pet_id );

		return $gallery_id;
	}

	/**
	 * Clear the retry bookkeeping after a pet resolves successfully.
	 *
	 * Every path that ends in "this pet has a card" has to do this, not just
	 * the one that creates one. The counter is bumped write-ahead at the top of
	 * mirror_pet_locked(), so leaving it set on the four already-mirrored
	 * returns meant it accumulated across unsold/re-sold cycles: on the sixth
	 * such cycle a perfectly healthy pet tripped the retry limit, got stamped
	 * PERMANENT_SKIP, vanished from candidates() forever, and could no longer
	 * even be de-mirrored.
	 *
	 * @param int $pet_id
	 */
	protected static function settle( $pet_id ) {
		delete_post_meta( $pet_id, self::ATTEMPTS_META );
		delete_post_meta( $pet_id, self::ATTEMPT_META );
		delete_post_meta( $pet_id, self::SKIP_META );
	}

	/**
	 * Point the pet at its gallery post, both ways.
	 *
	 * `ukm_gallery_id` is written as well as our own key, because it is what
	 * makes wc-ukm-gallery stand down if it is ever activated here: its
	 * add_to_gallery() links to an existing post instead of inserting when that
	 * meta points at a live one, so a later activation inherits every mirror
	 * rather than duplicating it.
	 *
	 * That key is not underscore-prefixed, so UKM's pet change log would
	 * otherwise record a bogus "Pet Update" row per pet. Its ignore list is not
	 * filterable, so the listener is detached around the write.
	 *
	 * @param int $pet_id
	 * @param int $gallery_id
	 */
	protected static function link( $pet_id, $gallery_id ) {

		update_post_meta( $pet_id, self::LINK_META, (int) $gallery_id );
		update_post_meta( $gallery_id, self::BACK_META, (int) $pet_id );

		/*
		 * Capture the real priority instead of assuming one. UKM registers this
		 * at 10 with 4 args today (includes/admin/pet-change-log.php), but
		 * hard-coding that means a future change to either number turns the
		 * restore into a duplicate registration or a broken one — the same
		 * mistake that made end_bulk() plant a fatal.
		 *
		 * Only `ukm_gallery_id` needs suppressing: UKM's change log ignores
		 * every underscore-prefixed key outright, so our own `_wcpk_*` writes
		 * are already invisible to it. Without this, a backfill would write one
		 * bogus "Pet Update" row per pet into the staff-facing change log.
		 */
		$detached = array();

		foreach ( array( 'added_post_meta', 'updated_post_meta' ) as $hook ) {
			$priority = has_action( $hook, 'ukm_buffer_pet_meta_change' );

			if ( false !== $priority ) {
				remove_action( $hook, 'ukm_buffer_pet_meta_change', $priority );
				$detached[] = array( $hook, $priority );
			}
		}

		try {
			update_post_meta( $pet_id, 'ukm_gallery_id', (int) $gallery_id );
		} finally {
			foreach ( $detached as $entry ) {
				add_action( $entry[0], 'ukm_buffer_pet_meta_change', $entry[1], 4 );
			}
		}
	}

	/**
	 * @param int $pet_id
	 * @return int
	 */
	protected static function find_gallery_by_pet( $pet_id ) {

		global $wpdb;

		// An explicit status list including `trash`. WP expands 'any' to
		// exclude every status registered exclude_from_search, and that
		// includes trash — so 'any' would miss exactly the posts this lookup
		// exists to find.
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT pm.post_id
				   FROM {$wpdb->postmeta} pm
				   INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				  WHERE pm.meta_key = %s
				    AND pm.meta_value = %d
				    AND p.post_type = 'pet-gallery'
				    AND p.post_status IN ( 'publish', 'draft', 'pending', 'private', 'trash' )
				  ORDER BY p.ID ASC
				  LIMIT 1",
				self::BACK_META,
				(int) $pet_id
			)
		);

		return $id ? (int) $id : 0;
	}

	/**
	 * Find a live pet-gallery post already occupying a slug.
	 *
	 * wc-ukm-gallery built its slug exactly the way we do, so on a site it
	 * populated and then left behind, a pet whose pointer went missing would
	 * otherwise get a second card at `<slug>-2`.
	 *
	 * @param string $slug
	 * @return int
	 */
	protected static function find_gallery_by_slug( $slug ) {

		global $wpdb;

		$slug = (string) $slug;

		if ( '' === $slug ) {
			return 0;
		}

		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID
					   FROM {$wpdb->posts} p
					  WHERE p.post_name = %s
					    AND p.post_type = 'pet-gallery'
					    AND p.post_status NOT IN ( 'trash', 'auto-draft' )
					  ORDER BY p.ID ASC
					  LIMIT 1",
				$slug
			)
		);

		return $id ? (int) $id : 0;
	}

	/**
	 * Microchip dedupe, using wc-ukm-gallery's own normalisation so both
	 * plugins agree on what "the same chip" means.
	 *
	 * @param string $chip
	 * @return int
	 */
	protected static function find_gallery_by_chip( $chip ) {

		if ( '' === $chip ) {
			return 0;
		}

		global $wpdb;

		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT pm.post_id
				   FROM {$wpdb->postmeta} pm
				   INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				  WHERE pm.meta_key = 'micro_chip'
				    AND p.post_type = 'pet-gallery'
				    AND p.post_status NOT IN ( 'trash', 'auto-draft' )
				    AND UPPER( REPLACE( REPLACE( pm.meta_value, ' ', '' ), '-', '' ) ) = %s
				  LIMIT 1",
				$chip
			)
		);

		return $id ? (int) $id : 0;
	}

	/**
	 * Empty stays empty. Without this guard every chipless pet collapses onto
	 * whichever chipless card happens to exist first.
	 *
	 * @param string $chip
	 * @return string
	 */
	protected static function normalize_chip( $chip ) {
		return strtoupper( preg_replace( '/[\s\-]+/', '', (string) $chip ) );
	}

	/* ============================================================
	 * Field mapping
	 * ============================================================ */

	/**
	 * Read everything the gallery post needs, straight off the product.
	 *
	 * Two corrections to the obvious approach, both of which would have
	 * mirrored every pet with an empty breed and type:
	 *
	 * - UKM writes NO `_breed` or `_pet_type` on the product. Those keys exist
	 *   only because wc-ukm-gallery writes them onto the GALLERY post. The breed
	 *   is the last `product_cat` term (the same array_pop rule UKM_Pet uses),
	 *   and the type comes from wc_ukm_get_pet_type(), which reads the
	 *   `pet_type` taxonomy.
	 * - wc_ukm_pet_age() returns an object with an ->age string, not a string,
	 *   and returns false outright when birth_date is empty.
	 *
	 * @param int $pet_id
	 * @return array
	 */
	protected static function collect_fields( $pet_id ) {

		$pet_id = (int) $pet_id;
		$post   = get_post( $pet_id );

		$breed = '';
		$cats  = wp_get_object_terms( $pet_id, 'product_cat', array( 'fields' => 'all' ) );

		if ( ! is_wp_error( $cats ) && ! empty( $cats ) ) {
			$last  = end( $cats );
			$breed = $last ? (string) $last->name : '';
		}

		$type = function_exists( 'wc_ukm_get_pet_type' ) ? (string) wc_ukm_get_pet_type( $pet_id ) : '';

		$reference = (string) get_post_meta( $pet_id, 'reference_number', true );
		$gender    = (string) get_post_meta( $pet_id, 'gender', true );
		$color     = (string) get_post_meta( $pet_id, 'coloration', true );
		$birth     = (string) get_post_meta( $pet_id, 'birth_date', true );
		$chip      = (string) get_post_meta( $pet_id, 'micro_chip', true );
		$location  = (string) get_post_meta( $pet_id, 'location_slug', true );
		$pet_name  = (string) get_post_meta( $pet_id, 'pet_name', true );

		$sold_ts = self::sold_timestamp( $pet_id );

		// Frozen at adoption, not recomputed on every page view — these pets
		// do not keep getting older once they are in the album.
		$age = '';

		if ( '' !== $birth && function_exists( 'wc_ukm_pet_age' ) ) {
			$age_obj = wc_ukm_pet_age( $pet_id, $sold_ts > 0 ? gmdate( 'Y-m-d', $sold_ts ) : 'today' );

			if ( is_object( $age_obj ) && isset( $age_obj->age ) ) {
				$age = (string) $age_obj->age;
			} elseif ( is_string( $age_obj ) ) {
				$age = $age_obj;
			}
		}

		$slug = sanitize_title_with_dashes( trim( $reference . ' ' . $type . ' ' . $breed ) );

		if ( '' === $slug ) {
			$slug = sanitize_title_with_dashes( 'pet-' . $pet_id );
		}

		return array(
			'title'        => $post ? $post->post_title : $breed,
			'slug'         => $slug,
			'breed'        => $breed,
			'type'         => $type,
			'reference'    => $reference,
			'gender'       => $gender,
			'color'        => $color,
			'birth_date'   => $birth,
			'chip'         => $chip,
			'location'     => $location,
			'pet_name'     => $pet_name,
			'age'          => $age,
			'sold_ts'      => $sold_ts,
			'thumbnail_id' => (int) get_post_thumbnail_id( $pet_id ),
			'gallery_ids'  => (string) get_post_meta( $pet_id, '_product_image_gallery', true ),
		);
	}

	/**
	 * When the pet was sold.
	 *
	 * `sold_order` is written when the order is created, before the status
	 * flip, so it is already readable by the time the transition fires.
	 *
	 * @param int $pet_id
	 * @return int Unix timestamp, or 0.
	 */
	protected static function sold_timestamp( $pet_id ) {

		$order_id = (int) get_post_meta( $pet_id, 'sold_order', true );

		if ( $order_id > 0 && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );

			if ( $order && is_callable( array( $order, 'get_date_created' ) ) ) {
				$created = $order->get_date_created();

				if ( $created ) {
					return (int) $created->getTimestamp();
				}
			}
		}

		// Fall back to when the product was last touched. UKM writes the status
		// change with a raw $wpdb->update and never bumps post_modified, so this
		// is approximate — but it is better than "now" for every pet.
		$modified = get_post_modified_time( 'U', true, $pet_id );

		return $modified ? (int) $modified : 0;
	}

	/**
	 * Write BOTH meta schemas onto the gallery post.
	 *
	 * This is the highest-leverage decision in the whole ingest. The
	 * petland-florida theme chain — the one petlandtexas actually runs —
	 * filters the gallery archive with a pre_get_posts meta_query on
	 * `breed_name`, `gender` and `animal_type`, and in one place with raw SQL.
	 * A get_post_metadata read shim cannot fix either of those, so the
	 * petkey-canonical keys have to physically exist on the row.
	 *
	 * ~10 extra meta rows per pet is the price, and it is worth paying.
	 *
	 * Deliberately NOT written: `petkey_gallery_ID`. The pinogy bridge matches
	 * that key against a SKU and trashes whatever it finds, so putting a UKM
	 * pet id into that namespace is a live mis-trash vector on any site that
	 * later gains pinogy.
	 *
	 * @param int   $gallery_id
	 * @param array $f
	 * @param int   $sold_ts
	 */
	protected static function write_meta( $gallery_id, array $f, $sold_ts ) {

		$meta = array(
			// Petkey-canonical — what the themes and the front end read.
			'petid'            => $f['reference'],
			'name'             => $f['pet_name'] !== '' ? $f['pet_name'] : $f['breed'],
			'age'              => $f['age'],
			'animal_type'      => $f['type'],
			'breed_name'       => $f['breed'],
			'coloring'         => $f['color'],
			'gender'           => $f['gender'],
			'birth_date'       => $f['birth_date'],
			'status'           => 'sold',
			'reference_id'     => $f['reference'],
			'location'         => $f['location'],
			'petkey_gallery'   => 'Y',
			'actual_product'   => (int) get_post_meta( $gallery_id, self::BACK_META, true ),

			// UKM-shaped — what wc-ukm-gallery writes, so a site that later
			// gains that plugin, or a theme written against it, still reads.
			//
			// _petid is the PRODUCT ID in that plugin's schema, not the
			// reference number: it writes `_petid => $pet->get_id()`. Putting a
			// reference number here would make any consumer that treats it as a
			// post id look up the wrong post, or none.
			'_petid'           => (int) get_post_meta( $gallery_id, self::BACK_META, true ),
			'_breed'           => $f['breed'],
			'_pet_type'        => $f['type'],
			'pet_name'         => $f['pet_name'],
			'reference_number' => $f['reference'],
			'coloration'       => $f['color'],
			'location_slug'    => $f['location'],
			'micro_chip'       => $f['chip'],

			// Ours.
			self::SOLD_META    => $sold_ts,
		);

		foreach ( $meta as $key => $value ) {
			if ( '' === $value || null === $value ) {
				continue;
			}

			update_post_meta( $gallery_id, $key, $value );
		}

		if ( ! empty( $f['gallery_ids'] ) ) {
			update_post_meta( $gallery_id, '_product_image_gallery', $f['gallery_ids'] );
		}
	}

	/* ============================================================
	 * Bulk-mode cache suppression
	 * ============================================================ */

	/**
	 * Detach the listeners that would otherwise fire once per created post.
	 *
	 * This has to happen inside EVERY batch callback, not once at kickoff:
	 * each Action Scheduler batch is its own PHP process, so a remove_action()
	 * during the request that started the run protects only that request.
	 *
	 * The expensive one is the theme's full-site purge. petland-florida hooks
	 * `transition_post_status` to pep_purge_complete_site_cache(), which calls
	 * AWS_Cache::clear_cache() and Kinsta\Cache_Purge::purge_complete_caches();
	 * wp_insert_post() fires that hook, so an unguarded backfill is one
	 * synchronous full-site edge purge per pet. (It is commented out in the
	 * `petland` parent theme, which is why auditing only that theme misses it.)
	 *
	 * The cheap-but-numerous ones are our own transient busts: two per post,
	 * each of which invalidates `alloptions` on a site with no persistent
	 * object cache.
	 */
	protected static function begin_bulk() {

		if ( self::$bulk ) {
			return;
		}

		self::$bulk = true;

		if ( ! defined( 'WCPK_GALLERY_BULK' ) ) {
			define( 'WCPK_GALLERY_BULK', true );
		}

		add_filter( 'wcpk_gallery_bulk_in_progress', '__return_true' );

		self::$detached = array();

		/*
		 * Detach by identity, and record exactly what was detached — including
		 * the priority it was registered at — so end_bulk() can restore the
		 * same thing rather than a guess at it.
		 *
		 * Restoring from a hard-coded list is subtly wrong in three ways, all of
		 * which bite: a callback registered as a bound method is not the same
		 * identity as its bare name (so removing by name is a no-op and adding
		 * by name invents a registration for a function that may not exist); a
		 * callback registered at a non-default priority is not removed at all,
		 * and re-adding it creates a duplicate; and add_action() defaults to one
		 * accepted argument, so a restored `transition_post_status` listener
		 * declared with three parameters would fatal on its next call.
		 */
		$targets = array(
			// The theme's full-site edge purge. One per created post would be
			// thousands of synchronous CDN purges across a backfill.
			array( 'transition_post_status', 'pep_purge_complete_site_cache', 3 ),
			array( 'woocommerce_update_product', 'pep_purge_complete_site_cache', 2 ),

			// Our own transient busts: two per post, each invalidating
			// alloptions on a site with no persistent object cache.
			array( 'save_post_pet-gallery', 'wcpk_gallery_clear_count_cache', 1 ),
			array( 'wc_petgallery_post_created', 'wcpk_gallery_clear_count_cache', 1 ),

			// Yoast rebuilds its sitemap cache on every save.
			array( 'save_post', array( 'WPSEO_Sitemaps_Cache', 'invalidate_post' ), 1 ),
		);

		global $petkey_gallery_functions;

		if ( is_object( $petkey_gallery_functions ) ) {
			// Registered as a bound method, so that is the identity to remove.
			$targets[] = array( 'save_post_pet-gallery', array( $petkey_gallery_functions, 'clear_breeds_cache' ), 1 );
			$targets[] = array( 'wc_petgallery_post_created', array( $petkey_gallery_functions, 'clear_breeds_cache' ), 1 );
		}

		foreach ( $targets as $target ) {
			list( $hook, $callback, $accepted ) = $target;

			$priority = has_action( $hook, $callback );

			if ( false === $priority ) {
				continue; // Never registered — nothing to remove, nothing to restore.
			}

			remove_action( $hook, $callback, $priority );

			self::$detached[] = array( $hook, $callback, $priority, $accepted );
		}
	}

	/**
	 * Re-attach exactly what begin_bulk() detached, then clear every cache ONCE
	 * for the whole run.
	 */
	protected static function end_bulk() {

		if ( ! self::$bulk ) {
			return;
		}

		self::$bulk = false;

		remove_filter( 'wcpk_gallery_bulk_in_progress', '__return_true' );

		foreach ( self::$detached as $detached ) {
			list( $hook, $callback, $priority, $accepted ) = $detached;

			add_action( $hook, $callback, $priority, $accepted );
		}

		self::$detached = array();

		if ( function_exists( 'wcpk_gallery_clear_count_cache' ) ) {
			wcpk_gallery_clear_count_cache();
		}

		global $petkey_gallery_functions;

		if ( is_object( $petkey_gallery_functions ) && method_exists( $petkey_gallery_functions, 'clear_breeds_cache' ) ) {
			$petkey_gallery_functions->clear_breeds_cache();
		}

		WCPK_Gallery_Status::flush();
	}

	/* ============================================================
	 * Runners
	 * ============================================================ */

	/**
	 * One backfill batch, self-draining.
	 *
	 * @return array{moved:int,examined:int,remaining:int}
	 */
	public static function run_backfill_batch() {

		$result = array( 'moved' => 0, 'examined' => 0, 'remaining' => 0 );

		if ( ! self::enabled() ) {
			return $result;
		}

		$state = self::backfill_state();

		if ( 'running' !== $state['status'] ) {
			return $result;
		}

		$limit = self::BATCH;

		if ( ! empty( $state['cap'] ) ) {
			$left = (int) $state['cap'] - (int) $state['done'];

			if ( $left <= 0 ) {
				$state['status'] = 'complete';
				self::save_backfill_state( $state );
				return $result;
			}

			$limit = min( $limit, $left );
		}

		$ids = self::candidates( $limit, (int) $state['cursor'] );

		if ( empty( $ids ) ) {
			$state['status']        = 'complete';
			$state['last_progress'] = time();
			self::save_backfill_state( $state );
			self::end_bulk();

			return $result;
		}

		self::begin_bulk();

		try {
			foreach ( $ids as $pet_id ) {
				$result['examined']++;

				// Per pet, so one bad record cannot take down the other 99 or
				// stop the chain.
				try {
					if ( self::mirror_pet( $pet_id, (string) $state['run_id'] ) ) {
						$result['moved']++;
					}
				} catch ( \Throwable $e ) {
					error_log( sprintf( '[wc-pet-gallery] backfill skipped pet %d: %s', $pet_id, $e->getMessage() ) );
				}

				$state['cursor'] = $pet_id;
				$state['done']++;
			}
		} finally {
			$state['last_progress'] = time();
			self::save_backfill_state( $state );
			self::end_bulk();
		}

		self::enqueue_backfill();

		return $result;
	}

	/**
	 * The twice-daily scan — and the backfill's watchdog.
	 *
	 * Action Scheduler marks a thrown or timed-out single action `failed` and
	 * does NOT retry it, so a self-enqueued chain that dies simply stops, at
	 * whatever pet it died on, with no admin signal at all. Re-enqueuing an
	 * unfinished backfill from here is what makes the chain recoverable.
	 */
	public static function run_scan() {

		if ( ! self::enabled() ) {
			return;
		}

		$state = self::backfill_state();

		if ( 'running' === $state['status'] ) {
			self::enqueue_backfill();
		}

		/*
		 * The safety net for pets the live listener missed. Deliberately
		 * uncursored and unwindowed by post_modified: UKM writes a status
		 * change with a raw $wpdb->update and never touches post_modified, and
		 * meta writes never touch the posts row at all — so a "changed
		 * recently" window would make this a near no-op.
		 */
		$ids = self::candidates( 200, 0, true );

		if ( empty( $ids ) ) {
			return;
		}

		$since = (int) get_option( self::SINCE_OPTION, 0 );

		self::begin_bulk();

		try {
			foreach ( $ids as $pet_id ) {
				// Outside a backfill, only pets sold since mirroring was armed.
				if ( $since > 0 && 'running' !== $state['status'] ) {
					$sold = self::sold_timestamp( $pet_id );

					if ( $sold > 0 && $sold < $since ) {
						continue;
					}
				}

				try {
					self::mirror_pet( $pet_id, 'scan' );
				} catch ( \Throwable $e ) {
					error_log( sprintf( '[wc-pet-gallery] scan skipped pet %d: %s', $pet_id, $e->getMessage() ) );
				}
			}
		} finally {
			self::end_bulk();
		}
	}

	/**
	 * Single-pet async hook.
	 *
	 * @param int $pet_id
	 */
	public static function mirror_one_async( $pet_id ) {
		if ( ! self::enabled() ) {
			return;
		}

		/*
		 * The batch paths wrap themselves; this one did not. Unwrapped, every
		 * real sale fired the theme's transition_post_status purge, and that
		 * purge constructs a fresh Kinsta\Cache_Purge -- defeating Kinsta's own
		 * once-per-request guard, which is an instance property -- so each sale
		 * paid a wp_cache_flush() plus an opcache_reset(). UKM's bulk tools flip
		 * hundreds of pets in one request.
		 */
		self::begin_bulk();

		try {
			self::mirror_pet( (int) $pet_id, 'live' );
		} finally {
			self::end_bulk();
		}
	}

	/**
	 * Deferred de-mirror, for pets past the per-request cap.
	 *
	 * Not gated on enabled(): a pet that is no longer sold must have its card
	 * taken down even if mirroring has since been switched off, or the gallery
	 * keeps advertising a pet the store no longer sold.
	 *
	 * @param int $pet_id
	 */
	public static function demirror_one_async( $pet_id ) {
		self::begin_bulk();

		try {
			self::demirror_pet( (int) $pet_id, 'pet_unsold' );
		} finally {
			self::end_bulk();
		}
	}

	/**
	 * Run one batch synchronously, under a wall-clock budget, and hand the
	 * rest to the scheduler. This is what the admin button calls, so it must
	 * not sit on a request until PHP kills it.
	 *
	 * @return array
	 */
	public static function run_now() {

		$budget  = (int) apply_filters( 'wcpk_gallery_ukm_run_now_budget', 20 );
		$started = microtime( true );
		$moved   = 0;
		$seen    = 0;

		while ( ( microtime( true ) - $started ) < $budget ) {
			$batch = self::run_backfill_batch();

			$moved += $batch['moved'];
			$seen  += $batch['examined'];

			if ( 0 === $batch['examined'] ) {
				break;
			}
		}

		return array(
			'moved'     => $moved,
			'examined'  => $seen,
			'remaining' => self::count_candidates(),
		);
	}

	/* ============================================================
	 * Live transitions and repair
	 * ============================================================ */

	/**
	 * A pet entering or leaving a source status.
	 *
	 * @param string  $new
	 * @param string  $old
	 * @param WP_Post $post
	 */
	public static function on_transition( $new, $old, $post ) {

		if ( ! self::enabled() || ! $post instanceof WP_Post || 'product' !== $post->post_type ) {
			return;
		}

		if ( $new === $old ) {
			return;
		}

		$sources = self::source_statuses();

		// Entering a source status: queue it rather than mirroring inline, so a
		// bulk status change in the admin does not do N inserts in one request.
		if ( in_array( $new, $sources, true ) ) {
			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action( self::HOOK_ONE, array( (int) $post->ID ), 'wcpk-gallery' );
			} else {
				wp_schedule_single_event( time() + 20, self::HOOK_ONE, array( (int) $post->ID ) );
			}

			return;
		}

		// Leaving one: the pet is no longer adopted, so its card comes down.
		if ( in_array( $old, $sources, true ) ) {
			self::demirror_pet( (int) $post->ID, 'pet_unsold' );
		}
	}

	/**
	 * Take a pet's card down.
	 *
	 * Trashed, never force-deleted, and BOTH link keys are removed. Keeping the
	 * link would poison both plugins' guards — wc-ukm-gallery's check is
	 * `get_post( $id ) instanceof WP_Post`, which is true for a trashed post —
	 * so the pet could never return to the gallery if it were re-sold.
	 *
	 * The previous post id is remembered so a re-sale untrashes the same card
	 * instead of creating a second one.
	 *
	 * @param int    $pet_id
	 * @param string $reason
	 */
	public static function demirror_pet( $pet_id, $reason = '' ) {

		static $trashed_this_request = 0;

		$pet_id = (int) $pet_id;

		// UKM's bulk tools can flip hundreds of products in a single request,
		// and trashing hundreds of posts inline would make that request crawl.
		$cap = (int) apply_filters( 'wcpk_gallery_ukm_demirror_cap', 50 );

		if ( $trashed_this_request >= $cap ) {
			/*
			 * Defer, never drop. Simply returning here left the pet's card
			 * published for a pet that is no longer sold, with nothing that
			 * would ever revisit it — the scan only looks for pets to ADD.
			 */
			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action( self::HOOK_DEMIRROR, array( $pet_id ), 'wcpk-gallery' );
			} else {
				wp_schedule_single_event( time() + 30, self::HOOK_DEMIRROR, array( $pet_id ) );
			}

			return;
		}

		$gallery_id = (int) get_post_meta( $pet_id, self::LINK_META, true );

		if ( $gallery_id <= 0 ) {
			return;
		}

		/*
		 * Defence in depth for the hard constraint. This is the only place in
		 * the plugin where an id read out of stored meta -- rather than one
		 * returned by a post_type-constrained lookup or by our own insert --
		 * reaches a post-row write. If anything ever leaves a non-gallery id in
		 * LINK_META, trashing it would change another post's status, and on a
		 * product that is forbidden outright. Drop the pointers and leave the
		 * other post untouched.
		 */
		if ( get_post( $gallery_id ) instanceof WP_Post && 'pet-gallery' !== get_post_type( $gallery_id ) ) {
			delete_post_meta( $pet_id, self::LINK_META );
			delete_post_meta( $pet_id, 'ukm_gallery_id' );

			return;
		}

		if ( get_post( $gallery_id ) instanceof WP_Post ) {
			wp_trash_post( $gallery_id );
			$trashed_this_request++;
		}

		update_post_meta( $pet_id, self::PREV_META, $gallery_id );
		update_post_meta( $pet_id, self::SKIP_META, $reason );

		delete_post_meta( $pet_id, self::LINK_META );
		delete_post_meta( $pet_id, 'ukm_gallery_id' );

		do_action( 'wcpk_gallery_ukm_demirrored', $gallery_id, $pet_id, $reason );
	}

	/**
	 * A gallery post trashed by hand: stop re-creating it.
	 *
	 * @param int $post_id
	 */
	public static function on_gallery_trashed( $post_id ) {

		if ( 'pet-gallery' !== get_post_type( $post_id ) ) {
			return;
		}

		$pet_id = (int) get_post_meta( $post_id, self::BACK_META, true );

		if ( $pet_id > 0 ) {
			update_post_meta( $pet_id, self::LINK_META, self::PERMANENT_SKIP );
			update_post_meta( $pet_id, self::PREV_META, (int) $post_id );
			update_post_meta( $pet_id, self::SKIP_META, 'trashed_by_hand' );
		}
	}

	/**
	 * Untrashed again: re-point rather than duplicate.
	 *
	 * @param int $post_id
	 */
	public static function on_gallery_untrashed( $post_id ) {

		if ( 'pet-gallery' !== get_post_type( $post_id ) ) {
			return;
		}

		$pet_id = (int) get_post_meta( $post_id, self::BACK_META, true );

		if ( $pet_id > 0 ) {
			self::link( $pet_id, (int) $post_id );
			delete_post_meta( $pet_id, self::SKIP_META );
		}
	}

	/**
	 * Force-deleted. Read the meta here, on before_delete_post — by
	 * `deleted_post` the meta rows are already gone.
	 *
	 * @param int $post_id
	 */
	public static function on_gallery_deleted( $post_id ) {

		if ( 'pet-gallery' !== get_post_type( $post_id ) ) {
			return;
		}

		$pet_id = (int) get_post_meta( $post_id, self::BACK_META, true );

		if ( $pet_id <= 0 ) {
			return;
		}

		/*
		 * A card an admin trashed on purpose already carries the permanent-skip
		 * sentinel, written by on_gallery_trashed(). Emptying the trash must not
		 * wipe that decision — otherwise the next scan happily re-creates the
		 * card the admin just removed twice over.
		 */
		$linked = get_post_meta( $pet_id, self::LINK_META, true );
		$reason = (string) get_post_meta( $pet_id, self::SKIP_META, true );

		if ( (string) $linked === (string) self::PERMANENT_SKIP || 'trashed_by_hand' === $reason ) {
			delete_post_meta( $pet_id, 'ukm_gallery_id' );
			update_post_meta( $pet_id, self::LINK_META, self::PERMANENT_SKIP );
			return;
		}

		delete_post_meta( $pet_id, self::LINK_META );
		delete_post_meta( $pet_id, 'ukm_gallery_id' );
		update_post_meta( $pet_id, self::SKIP_META, 'gallery_post_deleted' );
	}

	/**
	 * Un-mirror and re-mirror, for the admin repair actions. Neither touches
	 * the product's post_status.
	 *
	 * @param int $pet_id
	 * @return int|false
	 */
	public static function remirror_pet( $pet_id ) {

		$pet_id = (int) $pet_id;
		$prev   = (int) get_post_meta( $pet_id, self::PREV_META, true );

		delete_post_meta( $pet_id, self::LINK_META );
		delete_post_meta( $pet_id, self::SKIP_META );
		delete_post_meta( $pet_id, self::ATTEMPTS_META );

		if ( $prev > 0 && get_post( $prev ) instanceof WP_Post ) {
			wp_delete_post( $prev, true );
		}

		return self::mirror_pet( $pet_id, 'remirror' );
	}
}

WCPK_Gallery_UKM_Ingest::boot();
