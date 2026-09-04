<?php
/**
 * Event STATUS: the persisted `_anchor_event_status` row, the queries that
 * read it, and the 'draft' -> 'undated' rename (audit MODEL-D18, MODEL-D19).
 *
 * Two facts drive every case here:
 *
 *  1. An ABSENT `_anchor_event_status` row is indistinguishable from
 *     'upcoming' to get_meta() — which falls back to the schema default — but
 *     invisible to every meta_query that matches the VALUE, because a value
 *     comparison INNER-JOINs postmeta. Production carried 6 published, future
 *     events with no row at all: the sweep computed 'upcoming', compared it to
 *     get_meta()'s default of 'upcoming', found no difference and wrote
 *     nothing, for ever, while the admin counts and filters under-reported
 *     them. So the writer must ask whether the ROW exists, and the readers
 *     must use the NOT-EXISTS-or-equals shape build_hide_clause() already uses.
 *
 *  2. 'draft' was a legal value of this enum meaning "no start date", which
 *     collides with WordPress's own post_status. It is now 'undated'; legacy
 *     stored rows still READ as 'undated' and are rewritten by a batched,
 *     versioned back-fill, and 'draft' is no longer offered as a manual choice.
 *
 * @package Anchor\Events\Tests
 * @group status
 */

use Anchor\Events\Module;

/**
 * @group status
 */
class Test_Status extends Anchor_Events_TestCase {

	/** A date far enough out that no test-run clock skew can make it 'ongoing'. */
	const FUTURE = '2099-06-01';

	/** The status meta key, spelled once. */
	private function key() {
		return '_anchor_event_status';
	}

	/** Call one of the module's private status helpers. */
	private function call_private( $method, array $args = [] ) {
		$ref = new ReflectionMethod( Module::class, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $this->module(), $args );
	}

	/**
	 * An event with dates but NO status row — the production shape this whole
	 * file is about.
	 *
	 * The row has to be deleted explicitly rather than merely not written: the
	 * factory publishes the post BEFORE make_event() attaches its meta, so
	 * persist_status_on_transition() sees a date-less event and mints a row.
	 * The 6 production events predate that hook, which is exactly why they
	 * still have none.
	 */
	private function make_rowless_event( $start = self::FUTURE ) {
		$event = $this->make_event( [ 'start_date' => $start, 'status_mode' => 'auto' ] );
		delete_post_meta( $event, $this->key() );
		$this->assertFalse(
			metadata_exists( 'post', $event, $this->key() ),
			'Fixture precondition: the event must start with no status row.'
		);
		return $event;
	}

	/* -----------------------------------------------------------------
	 * MODEL-D18 — the sweep must write an ABSENT row
	 * --------------------------------------------------------------- */

	/**
	 * The sweep writes the computed status when the row does not exist, even
	 * though the computed value equals get_meta()'s default. This is the
	 * success-on-no-op the audit found: nothing errored, nothing was written.
	 */
	public function test_sweep_writes_a_missing_row_for_a_future_event() {
		$event = $this->make_rowless_event();

		$this->module()->run_status_sweep();

		$this->assertTrue(
			metadata_exists( 'post', $event, $this->key() ),
			'The sweep must write the status row when it is absent.'
		);
		$this->assertSame( 'upcoming', get_post_meta( $event, $this->key(), true ) );
	}

	/** The sweep still corrects a row whose value has gone stale. */
	public function test_sweep_corrects_a_stale_row() {
		$event = $this->make_event( [ 'start_date' => self::FUTURE, 'status_mode' => 'auto', 'status' => 'past' ] );

		$this->module()->run_status_sweep();

		$this->assertSame( 'upcoming', get_post_meta( $event, $this->key(), true ) );
	}

	/** A manually pinned status is the author's, and the sweep leaves it alone. */
	public function test_sweep_leaves_a_manual_cancelled_alone() {
		$event = $this->make_event(
			[ 'start_date' => self::FUTURE, 'status_mode' => 'manual', 'status' => 'cancelled' ]
		);

		$this->module()->run_status_sweep();

		$this->assertSame( 'cancelled', get_post_meta( $event, $this->key(), true ) );
	}

	/** The sweep rewrites a legacy 'draft' row under its new name. */
	public function test_sweep_rewrites_a_legacy_draft_row() {
		$event = $this->make_event( [ 'start_date' => '', 'status_mode' => 'auto', 'status' => 'draft' ] );

		$this->module()->run_status_sweep();

		$this->assertSame( 'undated', get_post_meta( $event, $this->key(), true ) );
	}

	/* -----------------------------------------------------------------
	 * MODEL-D18 — the readers must count a missing row
	 * --------------------------------------------------------------- */

	/** count_events_by_status('upcoming') counts an event that has no row. */
	public function test_count_by_status_counts_an_event_with_no_row() {
		$this->make_rowless_event();

		$this->assertSame(
			1,
			(int) $this->call_private( 'count_events_by_status', [ 'upcoming' ] ),
			'An absent status row must count as the default status, not as nothing.'
		);
	}

	/** ...and still counts one that HAS the row, without double counting. */
	public function test_count_by_status_counts_a_written_row_once() {
		$this->make_rowless_event();
		$this->make_event( [ 'start_date' => self::FUTURE, 'status' => 'upcoming' ] );

		$this->assertSame( 2, (int) $this->call_private( 'count_events_by_status', [ 'upcoming' ] ) );
	}

	/** A non-default status is still an exact match — no rowless events leak in. */
	public function test_count_by_status_does_not_leak_rowless_events_into_cancelled() {
		$this->make_rowless_event();
		$this->make_event(
			[ 'start_date' => self::FUTURE, 'status_mode' => 'manual', 'status' => 'cancelled' ]
		);

		$this->assertSame( 1, (int) $this->call_private( 'count_events_by_status', [ 'cancelled' ] ) );
	}

	/** The "Undated" count includes legacy 'draft' rows the back-fill has not reached. */
	public function test_count_by_status_undated_includes_legacy_draft_rows() {
		$this->make_event( [ 'start_date' => '', 'status' => 'draft' ] );
		$this->make_event( [ 'start_date' => '', 'status' => 'undated' ] );

		$this->assertSame( 2, (int) $this->call_private( 'count_events_by_status', [ 'undated' ] ) );
	}

	/**
	 * The admin "Upcoming" quick filter LISTS a rowless event.
	 *
	 * Driven through apply_quick_filters() itself rather than a hand-built
	 * clause, because the filter is what a real click runs: it needs an admin
	 * screen and the main query, which is what the globals below stand in for.
	 */
	public function test_quick_filter_upcoming_lists_an_event_with_no_row() {
		$event = $this->make_rowless_event();

		$ids = $this->run_quick_filter( 'upcoming' );

		$this->assertContains( $event, $ids, 'The Upcoming filter must list an event with no status row.' );
	}

	/** The quick filters offer an Undated view, which MODEL-D19 says did not exist. */
	public function test_quick_filters_offer_undated() {
		set_current_screen( 'edit-' . Module::CPT );
		$views = $this->module()->add_quick_filters( [] );
		set_current_screen( 'front' );

		$this->assertArrayHasKey( 'anchor_event_undated', $views );
		$this->assertStringContainsString( 'Undated', $views['anchor_event_undated'] );
		$this->assertArrayNotHasKey( 'anchor_event_draft', $views );
	}

	/** Run one quick filter and return the ids it would list. */
	private function run_quick_filter( $status ) {
		global $wp_the_query;

		$previous            = $wp_the_query;
		$query               = new WP_Query();
		$query->init();
		$query->set( 'post_type', Module::CPT );
		$wp_the_query        = $query;
		$_GET['event_status'] = $status;
		set_current_screen( 'edit-' . Module::CPT );

		$this->module()->apply_quick_filters( $query );

		set_current_screen( 'front' );
		unset( $_GET['event_status'] );
		$wp_the_query = $previous;

		$meta_query = $query->get( 'meta_query' );
		$this->assertNotEmpty( $meta_query, 'The quick filter must set a meta_query.' );

		return get_posts(
			[
				'post_type'      => Module::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => $meta_query,
			]
		);
	}

	/* -----------------------------------------------------------------
	 * MODEL-D19 — 'draft' becomes 'undated'
	 * --------------------------------------------------------------- */

	/** An event with no start date computes 'undated', never 'draft'. */
	public function test_an_event_with_no_start_date_computes_undated() {
		$this->assertSame( 'undated', $this->module()->compute_status( [ 'start_date' => '' ] ) );
	}

	/** A dated event is unaffected by the rename. */
	public function test_a_future_event_still_computes_upcoming() {
		$this->assertSame(
			'upcoming',
			$this->module()->compute_status(
				[ 'start_date' => self::FUTURE, 'end_date' => '', 'start_time' => '', 'end_time' => '', 'all_day' => false, 'timezone' => '' ]
			)
		);
	}

	/** A legacy stored 'draft' READS as 'undated' everywhere get_meta() feeds. */
	public function test_a_legacy_draft_row_reads_as_undated() {
		$event = $this->make_event( [ 'start_date' => '', 'status_mode' => 'manual', 'status' => 'draft' ] );

		$this->assertSame( 'undated', $this->module()->get_meta( $event )['status'] );
	}

	/** The card exposes the renamed status, not the post-status collision. */
	public function test_the_card_exposes_the_renamed_status() {
		$event = $this->make_event( [ 'start_date' => '', 'status_mode' => 'manual', 'status' => 'draft' ] );

		$html = $this->module()->render_event_card( $event, 'test' );

		$this->assertStringContainsString( 'anchor-event-status-undated', $html );
		$this->assertStringContainsString( 'data-status="undated"', $html );
		$this->assertStringNotContainsString( 'anchor-event-status-draft', $html );
	}

	/** 'draft' is no longer a manual choice an author can pin an event to. */
	public function test_draft_is_not_a_manual_status_option() {
		$options = $this->call_private( 'get_status_options' );

		$this->assertArrayNotHasKey( 'draft', $options );
		$this->assertArrayNotHasKey( 'undated', $options, 'Undated is computed, not something an author pins.' );
		$this->assertArrayHasKey( 'cancelled', $options );
	}

	/**
	 * A submitted status that is not an offered choice — 'draft' now being one
	 * such — falls back to what the DATES say, not to a hardcoded 'upcoming'.
	 */
	public function test_a_retired_manual_status_falls_back_to_the_computed_one() {
		$event = $this->make_event( [ 'start_date' => '', 'status_mode' => 'manual', 'status' => 'draft' ] );

		$_POST = [ 'anchor_event_status' => 'draft', 'anchor_event_start_date' => '' ];
		$input = ( new ReflectionMethod( Module::class, 'save_event_manager_fields' ) );
		$input->setAccessible( true );
		$saved = $input->invoke( $this->module(), $event, '', 'internal' );
		unset( $_POST );

		$this->assertSame( 'undated', $saved['status'] );
		$this->assertSame( 'undated', get_post_meta( $event, $this->key(), true ) );
	}

	/** The admin Status column labels the computed state, not "Draft". */
	public function test_status_column_labels_undated() {
		$event = $this->make_event( [ 'start_date' => '', 'status_mode' => 'auto' ] );

		ob_start();
		$this->module()->render_column( 'anchor_event_status', $event );
		$this->assertSame( 'Undated', ob_get_clean() );
	}

	/* -----------------------------------------------------------------
	 * MODEL-D19 — the one-time back-fill
	 * --------------------------------------------------------------- */

	/** The back-fill rewrites every legacy row and records that it finished. */
	public function test_backfill_rewrites_legacy_draft_rows() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$legacy = $this->make_event( [ 'start_date' => '', 'status' => 'draft' ] );
		$other  = $this->make_event( [ 'start_date' => self::FUTURE, 'status' => 'upcoming' ] );

		$this->module()->backfill_status_values();

		$this->assertSame( 'undated', get_post_meta( $legacy, $this->key(), true ) );
		$this->assertSame( 'upcoming', get_post_meta( $other, $this->key(), true ) );
		$this->assertSame(
			Module::STATUS_SCHEMA_VERSION,
			(int) get_option( 'anchor_events_status_version' )
		);
	}

	/** It is idempotent: a second pass is a no-op and writes nothing new. */
	public function test_backfill_is_idempotent() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$legacy = $this->make_event( [ 'start_date' => '', 'status' => 'draft' ] );

		$this->module()->backfill_status_values();
		$this->module()->backfill_status_values();

		$this->assertSame( 'undated', get_post_meta( $legacy, $this->key(), true ) );
	}

	/**
	 * It never runs inline on a public request. admin_init is NOT an
	 * authenticated hook — admin-post.php fires it before the auth cookie is
	 * validated, and this module registers nopriv handlers — so a logged-out
	 * visitor's POST reaches it.
	 */
	public function test_backfill_is_capability_gated() {
		wp_set_current_user( 0 );
		$legacy = $this->make_event( [ 'start_date' => '', 'status' => 'draft' ] );

		$this->module()->backfill_status_values();

		$this->assertSame( 'draft', get_post_meta( $legacy, $this->key(), true ) );
		$this->assertSame( 0, (int) get_option( 'anchor_events_status_version' ) );
	}
}
