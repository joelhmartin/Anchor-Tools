<?php
/** @group time */
class Test_Timestamps extends Anchor_Events_TestCase {
	public function test_date_only_event_ends_at_end_of_day() {
		$event = $this->make_event( [ 'start_date' => '2030-12-05', 'end_date' => '', 'start_time' => '', 'end_time' => '', 'all_day' => false ] );
		$ts = $this->module()->compute_timestamps( $this->module()->get_meta( $event ) );
		$this->assertSame( 23 * 3600 + 59 * 60, $ts['end'] - $ts['start'] );
	}
	public function test_start_time_without_end_time_still_ends_at_end_of_day() {
		$event = $this->make_event( [ 'start_date' => '2030-12-05', 'start_time' => '09:00', 'end_time' => '', 'all_day' => false ] );
		$ts = $this->module()->compute_timestamps( $this->module()->get_meta( $event ) );
		$this->assertGreaterThan( $ts['start'], $ts['end'] );
	}

	/**
	 * RENDER-D31: an event whose `end_ts` meta row was never written is UNDATED,
	 * not past — build_visibility_clause() must not drop it from a
	 * "hide past events" listing.
	 *
	 * `start_ts` is written here because every listing query still orders by it
	 * (meta_key + orderby=meta_value_num INNER-JOINs postmeta); the legacy event
	 * that has neither row is MODEL-D2's case, covered by the back-fill test below.
	 */
	public function test_event_with_no_end_ts_row_is_listed_as_upcoming() {
		$event = $this->make_event( [ 'title' => 'Undated End Event', 'start_date' => '2030-12-05' ] );
		$ts    = $this->module()->compute_timestamps( $this->module()->get_meta( $event ) );
		update_post_meta( $event, '_anchor_event_start_ts', $ts['start'] );
		delete_post_meta( $event, '_anchor_event_end_ts' );

		$this->module()->clear_caches();
		$html = do_shortcode( '[events_list show_past="no"]' );

		$this->assertStringContainsString( esc_url( get_permalink( $event ) ), $html );
	}

	/**
	 * MODEL-D2: legacy events never had `_ts` rows written, so the one-time
	 * back-fill has to mint them — otherwise the event is absent from every
	 * plugin-rendered list, not merely unsorted.
	 */
	public function test_backfill_writes_ts_for_legacy_events() {
		$this->login_as_admin();
		$event = $this->make_event( [ 'title' => 'Legacy Event', 'start_date' => '2030-12-05' ] );
		delete_post_meta( $event, '_anchor_event_start_ts' );
		delete_post_meta( $event, '_anchor_event_end_ts' );
		delete_option( 'anchor_events_ts_backfilled' );

		$this->module()->backfill_timestamps();

		$this->assertNotEmpty( get_post_meta( $event, '_anchor_event_start_ts', true ) );
		$this->assertNotEmpty( get_post_meta( $event, '_anchor_event_end_ts', true ) );
		$this->assertSame( '1', get_option( 'anchor_events_ts_backfilled' ) );

		// The visible symptom: the back-filled event now appears in a listing.
		$this->module()->clear_caches();
		$this->assertStringContainsString(
			esc_url( get_permalink( $event ) ),
			do_shortcode( '[events_list show_past="no"]' )
		);
	}

	/**
	 * admin_init is not an authenticated hook — wp-admin/admin-post.php fires it
	 * before it validates the auth cookie, and this module has nopriv handlers
	 * registered there. A logged-out visitor must not run the migration inline.
	 */
	public function test_backfill_ignores_a_caller_without_edit_posts() {
		wp_set_current_user( 0 );
		$event = $this->make_event( [ 'start_date' => '2030-12-05' ] );
		delete_option( 'anchor_events_ts_backfilled' );

		$this->module()->backfill_timestamps();

		$this->assertSame( '', get_post_meta( $event, '_anchor_event_start_ts', true ) );
		$this->assertFalse( get_option( 'anchor_events_ts_backfilled' ) );
	}

	/** A quick-edit publish never runs the meta box save, so the transition must write the rows. */
	public function test_publishing_without_the_meta_box_still_writes_both_ts_rows() {
		$event = $this->make_event( [ 'start_date' => '2030-12-05' ] );
		delete_post_meta( $event, '_anchor_event_start_ts' );
		delete_post_meta( $event, '_anchor_event_end_ts' );

		wp_update_post( [ 'ID' => $event, 'post_status' => 'draft' ] );
		wp_update_post( [ 'ID' => $event, 'post_status' => 'publish' ] );

		$this->assertNotEmpty( get_post_meta( $event, '_anchor_event_start_ts', true ) );
		$this->assertNotEmpty( get_post_meta( $event, '_anchor_event_end_ts', true ) );
	}

	/** Once the flag is set the migration is over: a later call touches nothing. */
	public function test_backfill_is_idempotent_once_the_flag_is_set() {
		$this->login_as_admin();
		$event = $this->make_event( [ 'start_date' => '2030-12-05' ] );
		delete_option( 'anchor_events_ts_backfilled' );

		$this->module()->backfill_timestamps();
		$this->assertNotEmpty( get_post_meta( $event, '_anchor_event_start_ts', true ) );

		// Blank the rows the first pass wrote: a second pass must NOT rewrite them.
		delete_post_meta( $event, '_anchor_event_start_ts' );
		delete_post_meta( $event, '_anchor_event_end_ts' );
		$this->module()->backfill_timestamps();

		$this->assertSame( '', get_post_meta( $event, '_anchor_event_start_ts', true ) );
		$this->assertSame( '', get_post_meta( $event, '_anchor_event_end_ts', true ) );
		$this->assertSame( '1', get_option( 'anchor_events_ts_backfilled' ) );
	}

	/**
	 * An event whose start_date row is an empty string can never be filled. It
	 * must not occupy a slot in the batch window (that is what strands the older
	 * fillable events), and it must not hold the done-flag open either.
	 */
	public function test_backfill_skips_an_empty_start_date_and_still_finishes() {
		$this->login_as_admin();
		$dateless = $this->make_event( [ 'title' => 'Dateless', 'start_date' => '' ] );
		$dated    = $this->make_event( [ 'title' => 'Dated', 'start_date' => '2030-12-05' ] );
		delete_option( 'anchor_events_ts_backfilled' );

		$this->module()->backfill_timestamps();

		$this->assertSame( '', get_post_meta( $dateless, '_anchor_event_start_ts', true ) );
		$this->assertNotEmpty( get_post_meta( $dated, '_anchor_event_start_ts', true ) );
		$this->assertSame( '1', get_option( 'anchor_events_ts_backfilled' ) );
	}

	/** backfill_timestamps() is capability-gated, so its tests need a user who could edit events by hand. */
	private function login_as_admin() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}
}
