<?php

use Anchor\Events\Module;

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
		delete_post_meta( $event, '_anchor_event_ts_version' );
		$this->reset_backfill_state();

		// The selection clause is an OR: an explicit `NOT EXISTS` arm plus a
		// `<` comparison on the version key. A value-level `!=` alone would
		// join only rows that exist and would skip this event entirely.
		// Assert the row is genuinely ABSENT, so this test proves the
		// never-stamped event is still selected rather than quietly relying on
		// a stale row matching.
		$this->assertSame( [], get_post_meta( $event, '_anchor_event_ts_version' ), 'Precondition: no version row at all.' );

		$this->module()->backfill_timestamps();

		$this->assertNotEmpty( get_post_meta( $event, '_anchor_event_start_ts', true ) );
		$this->assertNotEmpty( get_post_meta( $event, '_anchor_event_end_ts', true ) );
		$this->assertSame( Module::TS_SCHEMA_VERSION, (int) get_option( 'anchor_events_ts_version' ) );
		$this->assertSame( Module::TS_SCHEMA_VERSION, (int) get_post_meta( $event, '_anchor_event_ts_version', true ) );

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
		$this->reset_backfill_state();

		$this->module()->backfill_timestamps();

		$this->assertSame( '', get_post_meta( $event, '_anchor_event_start_ts', true ) );
		$this->assertFalse( get_option( 'anchor_events_ts_version' ) );
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

	/** Once the option records the current version the migration is over: a later call touches nothing. */
	public function test_backfill_is_idempotent_once_the_version_is_recorded() {
		$this->login_as_admin();
		$event = $this->make_event( [ 'start_date' => '2030-12-05' ] );
		$this->reset_backfill_state();

		$this->module()->backfill_timestamps();
		$this->assertNotEmpty( get_post_meta( $event, '_anchor_event_start_ts', true ) );

		// Blank the rows the first pass wrote: a second pass must NOT rewrite them.
		delete_post_meta( $event, '_anchor_event_start_ts' );
		delete_post_meta( $event, '_anchor_event_end_ts' );
		$this->module()->backfill_timestamps();

		$this->assertSame( '', get_post_meta( $event, '_anchor_event_start_ts', true ) );
		$this->assertSame( '', get_post_meta( $event, '_anchor_event_end_ts', true ) );
		$this->assertSame( Module::TS_SCHEMA_VERSION, (int) get_option( 'anchor_events_ts_version' ) );
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
		$this->reset_backfill_state();

		$this->module()->backfill_timestamps();

		$this->assertSame( '', get_post_meta( $dateless, '_anchor_event_start_ts', true ) );
		$this->assertNotEmpty( get_post_meta( $dated, '_anchor_event_start_ts', true ) );
		$this->assertSame( Module::TS_SCHEMA_VERSION, (int) get_option( 'anchor_events_ts_version' ) );
		// Unfillable, but still stamped — otherwise it holds the batch window
		// open forever and strands the fillable events behind it.
		$this->assertSame( Module::TS_SCHEMA_VERSION, (int) get_post_meta( $dateless, '_anchor_event_ts_version', true ) );
	}

	/**
	 * The back-fill changes what every cached listing should contain, so it
	 * has to invalidate them.
	 *
	 * get_cached_ids() stores the ID list for an hour keyed by query args, and
	 * an event with no `start_ts` row is dropped by the `meta_key => start_ts`
	 * ordering join — so the list cached a moment before the back-fill is a
	 * list the back-fill just made wrong. Without clear_caches() the newly
	 * dated events stayed invisible for up to an hour after the migration that
	 * was supposed to reveal them, on the exact admin page load that ran it.
	 *
	 * show_past="yes" on purpose: build_visibility_clause() embeds time() in
	 * the meta_query, so a show_past="no" cache key changes every second and
	 * could never be observed going stale.
	 */
	public function test_backfill_invalidates_the_listing_cache() {
		$this->login_as_admin();
		$event = $this->make_event( [ 'title' => 'Legacy Event', 'start_date' => '2030-12-05' ] );
		delete_post_meta( $event, '_anchor_event_start_ts' );
		delete_post_meta( $event, '_anchor_event_end_ts' );
		$this->reset_backfill_state();
		$this->module()->clear_caches();

		// Prime the cache while the event is still invisible to the ordering join.
		$this->assertStringNotContainsString(
			esc_url( get_permalink( $event ) ),
			do_shortcode( '[events_list show_past="yes" limit="50"]' ),
			'Precondition: an event with no start_ts row is dropped by the ordering join.'
		);

		$this->module()->backfill_timestamps();

		// Deliberately NO manual clear_caches() here — the back-fill owns it.
		$this->assertStringContainsString(
			esc_url( get_permalink( $event ) ),
			do_shortcode( '[events_list show_past="yes" limit="50"]' ),
			'The back-fill must clear the listing cache it just invalidated.'
		);
	}

	/**
	 * MODEL-D2 (shipping regression): the rows can be STALE without being
	 * missing.
	 *
	 * Before this schema version a date-only event ended at `end == start` —
	 * midnight on its own start date. Those rows are present, so a back-fill
	 * keyed on "has no start_ts" never sees them, and the past-event guard in
	 * Registrations::capacity_decision() closes the event at 00:00 on the
	 * morning it runs. The versioned back-fill has to recompute them.
	 */
	public function test_backfill_recomputes_a_stale_date_only_end_ts() {
		$this->login_as_admin();
		$event = $this->make_event( [ 'title' => 'Legacy Midnight', 'start_date' => '2030-12-05' ] );
		$ts    = $this->module()->compute_timestamps( $this->module()->get_meta( $event ) );

		// The pre-versioning shape: both rows written, end == start at midnight.
		update_post_meta( $event, '_anchor_event_start_ts', $ts['start'] );
		update_post_meta( $event, '_anchor_event_end_ts', $ts['start'] );
		delete_post_meta( $event, '_anchor_event_ts_version' );
		$this->reset_backfill_state();

		$this->module()->backfill_timestamps();

		$this->assertSame(
			23 * 3600 + 59 * 60,
			(int) get_post_meta( $event, '_anchor_event_end_ts', true ) - $ts['start'],
			'A stale v1 date-only event must be recomputed to a 23:59 end.'
		);
		$this->assertSame( Module::TS_SCHEMA_VERSION, (int) get_post_meta( $event, '_anchor_event_ts_version', true ) );
	}

	/** An event stamped with an OLDER version is re-processed by the next bump. */
	public function test_a_version_older_than_the_current_schema_is_reprocessed() {
		$this->login_as_admin();
		$event = $this->make_event( [ 'title' => 'Stamped v1', 'start_date' => '2030-12-05' ] );
		update_post_meta( $event, '_anchor_event_start_ts', 1 );
		update_post_meta( $event, '_anchor_event_end_ts', 1 );
		update_post_meta( $event, '_anchor_event_ts_version', Module::TS_SCHEMA_VERSION - 1 );
		$this->reset_backfill_state();

		$this->module()->backfill_timestamps();

		$expected = $this->module()->compute_timestamps( $this->module()->get_meta( $event ) );
		$this->assertSame( $expected['start'], (int) get_post_meta( $event, '_anchor_event_start_ts', true ) );
		$this->assertSame( $expected['end'], (int) get_post_meta( $event, '_anchor_event_end_ts', true ) );
	}

	/**
	 * A save stamps the current version, so the back-fill has no work to do on
	 * a freshly saved event — the migration never re-touches current rows.
	 */
	public function test_a_saved_event_is_stamped_and_then_skipped() {
		$this->login_as_admin();
		$event = $this->make_event( [ 'start_date' => '2030-12-05' ] );
		wp_update_post( [ 'ID' => $event, 'post_status' => 'draft' ] );
		wp_update_post( [ 'ID' => $event, 'post_status' => 'publish' ] );

		$this->assertSame(
			Module::TS_SCHEMA_VERSION,
			(int) get_post_meta( $event, '_anchor_event_ts_version', true ),
			'The save path must stamp the schema version alongside the rows it writes.'
		);

		// A value the calculators would never produce: if the back-fill picked
		// this event up it would be overwritten.
		update_post_meta( $event, '_anchor_event_end_ts', 1 );
		$this->reset_backfill_state();

		$this->module()->backfill_timestamps();

		$this->assertSame( 1, (int) get_post_meta( $event, '_anchor_event_end_ts', true ) );
	}

	/**
	 * The pre-versioning boolean flag means "ran under the v1 rules", which is
	 * precisely the state that needs migrating — it must not read as done, and
	 * it is cleared once the versioned option supersedes it.
	 */
	public function test_the_legacy_boolean_flag_is_treated_as_needing_migration() {
		$this->login_as_admin();
		$event = $this->make_event( [ 'title' => 'Flagged Legacy', 'start_date' => '2030-12-05' ] );
		$ts    = $this->module()->compute_timestamps( $this->module()->get_meta( $event ) );
		update_post_meta( $event, '_anchor_event_start_ts', $ts['start'] );
		update_post_meta( $event, '_anchor_event_end_ts', $ts['start'] );
		delete_post_meta( $event, '_anchor_event_ts_version' );

		delete_option( 'anchor_events_ts_version' );
		update_option( 'anchor_events_ts_backfilled', '1', false );

		$this->module()->backfill_timestamps();

		$this->assertSame( $ts['end'], (int) get_post_meta( $event, '_anchor_event_end_ts', true ) );
		$this->assertSame( Module::TS_SCHEMA_VERSION, (int) get_option( 'anchor_events_ts_version' ) );
		$this->assertFalse( get_option( 'anchor_events_ts_backfilled' ), 'The superseded flag must be deleted.' );
	}

	/** A zone an author actually picked survives the pass untouched. */
	public function test_backfill_keeps_an_authored_named_timezone() {
		$this->login_as_admin();
		update_option( 'timezone_string', '' );
		update_option( 'gmt_offset', -6 );

		$event = $this->make_event( [ 'title' => 'Authored Zone', 'start_date' => '2030-12-05', 'timezone' => 'America/Chicago' ] );
		delete_post_meta( $event, '_anchor_event_ts_version' );
		$this->reset_backfill_state();

		$this->module()->backfill_timestamps();

		$this->assertSame( 'America/Chicago', get_post_meta( $event, '_anchor_event_timezone', true ) );
	}

	/**
	 * An offset row that is NOT the site's own offset cannot have come from
	 * gmt_offset, so somebody chose it — deleting it would silently move the
	 * event.
	 */
	public function test_backfill_keeps_an_offset_that_is_not_the_sites_own() {
		$this->login_as_admin();
		update_option( 'timezone_string', '' );
		update_option( 'gmt_offset', -6 );

		$event = $this->make_event( [ 'title' => 'Elsewhere', 'start_date' => '2030-12-05', 'timezone' => 'UTC-5' ] );
		delete_post_meta( $event, '_anchor_event_ts_version' );
		$this->reset_backfill_state();

		$this->module()->backfill_timestamps();

		$this->assertSame( 'UTC-5', get_post_meta( $event, '_anchor_event_timezone', true ) );
	}

	/**
	 * The minted row is deleted on group CHILDREN only. sync_shared_meta() is
	 * what wrote it — the parent's read-time default, copied down as real data
	 * — so a child is the one post whose "UTC-6" provably nobody typed. On a
	 * single event or a parent the same string is an author's own pick from the
	 * timezone field, and deleting it would quietly hand that event to whatever
	 * the site setting becomes next.
	 */
	public function test_backfill_keeps_a_fixed_offset_on_a_single_event() {
		$this->login_as_admin();
		update_option( 'timezone_string', '', false );
		update_option( 'gmt_offset', -6, false );

		$event = $this->make_event( [ 'title' => 'Authored Offset', 'start_date' => '2030-12-05', 'timezone' => 'UTC-6' ] );
		delete_post_meta( $event, '_anchor_event_ts_version' );
		$this->reset_backfill_state();

		$this->module()->backfill_timestamps();

		$this->assertSame(
			'UTC-6',
			get_post_meta( $event, '_anchor_event_timezone', true ),
			'A single event\'s fixed offset is an authored choice, not a minted row.'
		);
	}

	/** …and on a group child it goes, which is the row the v3 pass exists for. */
	public function test_backfill_deletes_the_minted_offset_on_a_group_child() {
		$this->login_as_admin();
		update_option( 'timezone_string', '', false );
		update_option( 'gmt_offset', -6, false );

		$parent = $this->make_event( [ 'title' => 'Offering', 'start_date' => '2030-12-05' ] );
		update_post_meta(
			$parent,
			'_anchor_event_offering_dates',
			[ [ 'date' => '2030-12-05', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => '', 'capacity' => 0 ] ]
		);
		$children = $this->module()->occurrences->reconcile( $parent );
		$child    = (int) $children[0];

		// The row sync_shared_meta() used to write down from the parent's
		// read-time default.
		update_post_meta( $child, '_anchor_event_timezone', 'UTC-6' );
		delete_post_meta( $child, '_anchor_event_ts_version' );
		$this->reset_backfill_state();

		$this->module()->backfill_timestamps();

		$this->assertSame(
			[],
			get_post_meta( $child, '_anchor_event_timezone' ),
			'A child\'s offset row was minted by the inheritance copy, never typed.'
		);
		$this->assertNotEmpty( get_post_meta( $child, '_anchor_event_start_ts', true ) );
	}

	/**
	 * Changing the site's timezone changes what every stored timestamp SHOULD
	 * be, and nothing recomputed them: an event authored at 09:00 kept the UTC
	 * instant of its old zone, so it sorted, reminded, closed registration and
	 * emitted JSON-LD an hour (or six) away from the time on its own page. The
	 * option write drops the migration's finished-marker AND the per-event
	 * stamps, so the next capability-gated admin load re-runs the pass.
	 */
	public function test_changing_the_site_timezone_recomputes_stored_timestamps() {
		$this->login_as_admin();
		update_option( 'timezone_string', 'America/Chicago', false );

		$event = $this->make_event( [ 'title' => 'Zone Move', 'start_date' => '2030-12-05', 'start_time' => '09:00' ] );
		$this->module()->persist_timestamps( $event, $this->module()->get_meta( $event ) );
		$before = (int) get_post_meta( $event, '_anchor_event_start_ts', true );
		$this->assertGreaterThan( 0, $before );

		// The migration has finished: nothing would run again on its own.
		update_option( 'anchor_events_ts_version', Module::TS_SCHEMA_VERSION, false );
		$this->assertSame( Module::TS_SCHEMA_VERSION, (int) get_post_meta( $event, '_anchor_event_ts_version', true ) );

		update_option( 'timezone_string', 'America/New_York', false );

		$this->assertFalse(
			get_option( 'anchor_events_ts_version' ),
			'The finished-marker must be dropped, or the pass never runs again.'
		);

		$this->module()->backfill_timestamps();

		$this->assertSame(
			$before - HOUR_IN_SECONDS,
			(int) get_post_meta( $event, '_anchor_event_start_ts', true ),
			'09:00 in Chicago and 09:00 in New York are an hour apart.'
		);
		$this->assertSame( Module::TS_SCHEMA_VERSION, (int) get_option( 'anchor_events_ts_version' ) );
	}

	/** The gmt_offset half of the same setting does it too. */
	public function test_changing_the_site_gmt_offset_reruns_the_backfill() {
		$this->login_as_admin();
		update_option( 'timezone_string', '', false );
		update_option( 'gmt_offset', -6, false );
		update_option( 'anchor_events_ts_version', Module::TS_SCHEMA_VERSION, false );

		update_option( 'gmt_offset', -5, false );

		$this->assertFalse( get_option( 'anchor_events_ts_version' ) );
	}

	/** Both the current option and the flag it replaced, so a run starts from a clean slate. */
	private function reset_backfill_state() {
		delete_option( 'anchor_events_ts_version' );
		delete_option( 'anchor_events_ts_backfilled' );
	}

	/** backfill_timestamps() is capability-gated, so its tests need a user who could edit events by hand. */
	private function login_as_admin() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}
}
