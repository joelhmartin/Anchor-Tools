<?php
/**
 * Every cached aggregate names what invalidates it.
 *
 * Audit entries closed here:
 *   REG-D18 / WOO-D47 — the `anchor_evt_caps_{id}` / `anchor_evt_tier_caps_{id}`
 *     capacity transients were busted only by a seat write that went through
 *     Registrations, so trashing or deleting a seat post left a permanently
 *     wrong count for the rest of the hour. Production carried
 *     `_transient_anchor_evt_caps_7909 = {refunded:{seats:1}}` against zero
 *     `anchor_event_reg` posts.
 *   WOO-D56 — counts() minted a caps transient for ANY positive integer, so
 *     `_transient_anchor_evt_caps_7980` outlived a post id that is not in
 *     wp_posts at all.
 *   RENDER-D19 — nothing invalidated the `[events_list]` / `[event_calendar]`
 *     id cache when an event was trashed, and render_event_card() never
 *     checked that a cached id was still a published event, so a trashed
 *     event kept rendering a card that 404s for up to an hour.
 *   RENDER-D20 — the `anchor_events_cache_keys` option registry was written
 *     AFTER set_transient() (a clear_caches() interleaving there orphaned the
 *     key forever) and grew one row per query-arg hash with no cap. It is
 *     replaced by an `anchor_events_cache_ver` counter folded into the key.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Module;
use Anchor\Events\Registrations;

/**
 * @group cache
 */
class Test_Cache extends Anchor_Events_TestCase {

	/** A future-dated, listable event. */
	private function listable_event( $title = 'Cache Fixture Event' ) {
		return $this->make_event(
			[
				'title'      => $title,
				'start_date' => '2030-10-23',
				'end_date'   => '2030-10-23',
				'start_time' => '08:00',
				'end_time'   => '17:00',
				'timezone'   => 'UTC',
				// Every listing query orders by (and therefore joins on) start_ts.
				'start_ts'   => strtotime( '2030-10-23 08:00 UTC' ),
				'end_ts'     => strtotime( '2030-10-23 17:00 UTC' ),
			]
		);
	}

	/* ------------------------------------------------------------------
	 * REG-D18 / WOO-D47 — seat lifecycle busts the capacity transients.
	 * ------------------------------------------------------------------ */

	/** Permanently deleting a seat post changes the count on the very next read. */
	public function test_deleting_a_seat_post_changes_counts_immediately() {
		$event = $this->listable_event();
		$seat  = $this->make_seat( $event );

		// Warm the caps transient through the cached path.
		$this->assertSame( 1, $this->registrations()->count_reserved_seats( $event ) );
		$this->assertIsArray( get_transient( 'anchor_evt_caps_' . $event ) );

		wp_delete_post( $seat, true );

		$this->assertSame(
			0,
			$this->registrations()->count_reserved_seats( $event ),
			'A deleted seat post must not survive in the cached capacity count.'
		);
	}

	/** Trashing a seat drops it out of the counts query, so it must bust too. */
	public function test_trashing_a_seat_changes_counts_immediately() {
		$event = $this->listable_event();
		$seat  = $this->make_seat( $event );

		$this->assertSame( 1, $this->registrations()->count_reserved_seats( $event ) );

		wp_trash_post( $seat );

		$this->assertSame(
			0,
			$this->registrations()->count_reserved_seats( $event ),
			'A trashed seat is invisible to counts() — the cache must follow.'
		);
	}

	/** …and restoring it brings the seat back without waiting out the hour. */
	public function test_untrashing_a_seat_changes_counts_immediately() {
		$event = $this->listable_event();
		$seat  = $this->make_seat( $event );

		wp_trash_post( $seat );
		$this->assertSame( 0, $this->registrations()->count_reserved_seats( $event ) );

		wp_untrash_post( $seat );
		wp_publish_post( $seat );

		$this->assertSame(
			1,
			$this->registrations()->count_reserved_seats( $event ),
			'A restored seat must reappear in the cached capacity count.'
		);
	}

	/** The per-tier transient is busted by the same lifecycle, not just the total. */
	public function test_deleting_a_seat_changes_tier_counts_immediately() {
		$event = $this->make_event(
			[ 'start_date' => '2030-10-23', 'capacity' => 10 ],
			[ [ 'label' => 'General', 'price' => 0, 'quota' => 5 ] ]
		);
		$tiers = $this->ticket_types()->get( $event );
		$tier  = $tiers[0];
		$seat  = $this->make_seat( $event, [ 'tier_id' => $tier['id'], 'ticket_type_id' => $tier['id'] ] );

		$before = $this->registrations()->count_reserved_for_tier( $event, $tier['id'] );
		$this->assertSame( 1, $before );

		wp_delete_post( $seat, true );

		$this->assertSame(
			0,
			$this->registrations()->count_reserved_for_tier( $event, $tier['id'] ),
			'anchor_evt_tier_caps_{id} must be busted on seat deletion too.'
		);
	}

	/** Deleting the EVENT clears its own caps transients, not just the list cache. */
	public function test_deleting_an_event_clears_its_capacity_transients() {
		$event = $this->listable_event();
		$this->make_seat( $event );
		$this->registrations()->count_reserved_seats( $event );
		$this->registrations()->count_reserved_for_tier( $event, 'primary' );

		$this->assertIsArray( get_transient( 'anchor_evt_caps_' . $event ) );

		wp_delete_post( $event, true );

		$this->assertFalse( get_transient( 'anchor_evt_caps_' . $event ) );
		$this->assertFalse( get_transient( 'anchor_evt_tier_caps_' . $event ) );
	}

	/* ------------------------------------------------------------------
	 * WOO-D56 — a caps transient is never minted for a non-event id.
	 * ------------------------------------------------------------------ */

	/** counts() on a post that is not an event returns zeros and caches nothing. */
	public function test_counts_on_a_non_event_id_return_zero_and_cache_nothing() {
		$page = self::factory()->post->create( [ 'post_type' => 'page' ] );

		$this->assertSame( 0, $this->registrations()->count_reserved_seats( $page ) );
		$this->assertSame( 0, $this->registrations()->count_waitlist_seats( $page ) );
		$this->assertSame( 0, $this->registrations()->count_reserved_for_tier( $page, 'primary' ) );

		$this->assertFalse(
			get_transient( 'anchor_evt_caps_' . $page ),
			'A capacity transient must never be minted for a non-event id (WOO-D56).'
		);
		$this->assertFalse( get_transient( 'anchor_evt_tier_caps_' . $page ) );
	}

	/** Nor for an id that is not a post at all. */
	public function test_counts_on_a_missing_id_return_zero_and_cache_nothing() {
		$ghost = 99999123;

		$this->assertSame( 0, $this->registrations()->count_reserved_seats( $ghost ) );
		$this->assertFalse( get_transient( 'anchor_evt_caps_' . $ghost ) );
	}

	/* ------------------------------------------------------------------
	 * RENDER-D19 — a trashed event leaves the listing at once.
	 * ------------------------------------------------------------------ */

	/** Trashing an event removes its card from [events_list] with no stale window. */
	public function test_trashing_an_event_removes_it_from_the_list_immediately() {
		$event = $this->listable_event( 'Trashable Listing Event' );
		$title = esc_html( get_the_title( $event ) );

		// Warm the cached id list.
		$this->assertStringContainsString( $title, do_shortcode( '[events_list limit="50"]' ) );

		wp_trash_post( $event );

		$this->assertStringNotContainsString(
			$title,
			do_shortcode( '[events_list limit="50"]' ),
			'A trashed event must not survive in the cached listing (RENDER-D19).'
		);
	}

	/** Belt and braces: even a warm id list can never render a non-published card. */
	public function test_a_card_is_never_rendered_for_a_trashed_event() {
		$event = $this->listable_event();

		$this->assertNotSame( '', $this->module()->render_event_card( $event, 'test' ) );

		wp_trash_post( $event );

		$this->assertSame(
			'',
			$this->module()->render_event_card( $event, 'test' ),
			'render_event_card() must refuse an id that is no longer published.'
		);
	}

	/** …or for an id that is not an event post type. */
	public function test_a_card_is_never_rendered_for_a_non_event_id() {
		$page = self::factory()->post->create( [ 'post_type' => 'page' ] );

		$this->assertSame( '', $this->module()->render_event_card( $page, 'test' ) );
		$this->assertSame( '', $this->module()->render_event_card( 99999123, 'test' ) );
	}

	/* ------------------------------------------------------------------
	 * RENDER-D20 — the key registry becomes a version counter.
	 * ------------------------------------------------------------------ */

	/** clear_caches() bumps the version, and the next lookup misses on a new key. */
	public function test_clear_caches_bumps_the_version_and_the_next_lookup_misses() {
		$args = [
			'post_type'      => Module::CPT,
			'post_status'    => 'publish',
			'posts_per_page' => 5,
		];

		$before = (int) get_option( 'anchor_events_cache_ver', 1 );
		$key_before = $this->cached_ids_key( $args );
		$this->call_get_cached_ids( $args );
		$this->assertNotFalse( get_transient( $key_before ), 'The first lookup must store a transient.' );

		$this->module()->clear_caches();

		$after = (int) get_option( 'anchor_events_cache_ver', 1 );
		$this->assertGreaterThan( $before, $after, 'clear_caches() must increment anchor_events_cache_ver.' );

		$key_after = $this->cached_ids_key( $args );
		$this->assertNotSame( $key_before, $key_after, 'The version must be part of the transient key.' );
		$this->assertFalse( get_transient( $key_after ), 'The bumped version must miss.' );
	}

	/** The retired option-based registry is gone — no key list is written. */
	public function test_the_legacy_key_registry_is_no_longer_written() {
		$this->call_get_cached_ids(
			[ 'post_type' => Module::CPT, 'post_status' => 'publish', 'posts_per_page' => 3 ]
		);

		$this->assertFalse(
			get_option( 'anchor_events_cache_keys' ),
			'anchor_events_cache_keys must no longer be maintained (RENDER-D20).'
		);
	}

	/** A new event appears in the list at once — clear_caches() is wired to saves. */
	public function test_a_newly_published_event_appears_in_the_list_immediately() {
		$this->listable_event( 'First Cached Event' );
		do_shortcode( '[events_list limit="50"]' ); // Warm.

		$second = $this->listable_event( 'Second Cached Event' );

		$this->assertStringContainsString(
			esc_html( get_the_title( $second ) ),
			do_shortcode( '[events_list limit="50"]' )
		);
	}

	/* ------------------------------------------------------------------
	 * Fleet safety — a site that never used events pays nothing.
	 *
	 * transition_post_status is a site-wide hook: it fires for every post of
	 * every type on every install running Anchor-Tools. These pin the property
	 * that the handler is inert outside the two event post types.
	 * ------------------------------------------------------------------ */

	/** A page's whole draft -> publish -> trash life leaves the version alone. */
	public function test_an_unrelated_page_never_bumps_the_cache_version() {
		$before = (int) get_option( 'anchor_events_cache_ver', 1 );

		$page = wp_insert_post( [ 'post_type' => 'page', 'post_title' => 'Unrelated', 'post_status' => 'draft' ] );
		$this->assertSame( $before, (int) get_option( 'anchor_events_cache_ver', 1 ), 'Creating a page must not bump the version.' );

		wp_publish_post( $page );
		$this->assertSame( $before, (int) get_option( 'anchor_events_cache_ver', 1 ), 'Publishing a page must not bump the version.' );

		wp_trash_post( $page );
		$this->assertSame( $before, (int) get_option( 'anchor_events_cache_ver', 1 ), 'Trashing a page must not bump the version.' );

		wp_untrash_post( $page );
		wp_delete_post( $page, true );
		$this->assertSame(
			$before,
			(int) get_option( 'anchor_events_cache_ver', 1 ),
			'Untrashing or deleting a page must not bump the version.'
		);
	}

	/** …and mints no capacity transient for its id. */
	public function test_an_unrelated_post_mints_no_capacity_transient() {
		$before = (int) get_option( 'anchor_events_cache_ver', 1 );

		$post = wp_insert_post( [ 'post_type' => 'post', 'post_title' => 'Blog post', 'post_status' => 'publish' ] );
		wp_update_post( [ 'ID' => $post, 'post_status' => 'draft' ] );
		wp_publish_post( $post );
		wp_trash_post( $post );

		$this->assertSame( $before, (int) get_option( 'anchor_events_cache_ver', 1 ) );
		$this->assertFalse( get_transient( 'anchor_evt_caps_' . $post ) );
		$this->assertFalse( get_transient( 'anchor_evt_tier_caps_' . $post ) );
	}

	/**
	 * A brand-new event saved as a DRAFT bumps nothing: it is in no listing
	 * and no count, so there is nothing cached for it to invalidate.
	 */
	public function test_a_new_draft_event_does_not_bump_the_cache_version() {
		$before = (int) get_option( 'anchor_events_cache_ver', 1 );

		$draft = wp_insert_post(
			[ 'post_type' => Module::CPT, 'post_title' => 'Draft Event', 'post_status' => 'draft' ]
		);
		$this->assertGreaterThan( 0, $draft );

		$this->assertSame(
			$before,
			(int) get_option( 'anchor_events_cache_ver', 1 ),
			'A draft event has never been in a listing — do not throw away every warm cache for it.'
		);
	}

	/** …but the moment that draft is published, the listing generation moves. */
	public function test_publishing_a_draft_event_does_bump_the_cache_version() {
		$draft = wp_insert_post(
			[ 'post_type' => Module::CPT, 'post_title' => 'Draft Event', 'post_status' => 'draft' ]
		);

		$before = (int) get_option( 'anchor_events_cache_ver', 1 );
		wp_publish_post( $draft );

		$this->assertGreaterThan(
			$before,
			(int) get_option( 'anchor_events_cache_ver', 1 ),
			'Crossing publish is exactly the transition every cached read is scoped to.'
		);
	}

	/**
	 * finding-3 (carry-over; already closed by RENDER-D20 —
	 * get_cached_ids() keys on anchor_events_cache_ver + the query args, not
	 * time()/a per-minute bucket; see test_clear_caches_bumps_the_version_and_the_next_lookup_misses()
	 * above). Two calls with identical args in the same version must hit the
	 * cache rather than re-run the WP_Query.
	 */
	public function test_two_calls_in_the_same_version_hit_the_cache_not_a_second_query() {
		$args = [
			'post_type'      => Module::CPT,
			'post_status'    => 'publish',
			'posts_per_page' => 5,
		];

		$queries = 0;
		$count   = static function ( $query ) use ( &$queries ) {
			$queries++;
		};
		add_action( 'pre_get_posts', $count );
		try {
			$first  = $this->call_get_cached_ids( $args );
			$second = $this->call_get_cached_ids( $args );
		} finally {
			remove_action( 'pre_get_posts', $count );
		}

		$this->assertSame( $first, $second, 'A cache hit must return the same ids.' );
		$this->assertSame( 1, $queries, 'The second call in the same request/version must hit the cache, not run a second query.' );
	}

	/* ------------------------------------------------------------------
	 * Helpers.
	 * ------------------------------------------------------------------ */

	/** The versioned transient key get_cached_ids() would use for $args. */
	private function cached_ids_key( array $args ) {
		return 'anchor_events_' . (int) get_option( 'anchor_events_cache_ver', 1 )
			. '_' . md5( wp_json_encode( $args ) );
	}

	/** get_cached_ids() is private; the two public callers wrap it in a full render. */
	private function call_get_cached_ids( array $args ) {
		$method = new ReflectionMethod( Module::class, 'get_cached_ids' );
		$method->setAccessible( true );
		return $method->invoke( $this->module(), $args );
	}
}
