<?php
/**
 * Group authoring save-path tests (spec Phase 2, Task 2.3): the offering-dates
 * repeater + recurrence rule builder metabox controls, wired through the
 * validated persist_group_authoring() step into Occurrences::reconcile().
 *
 * Exercises Module::save_meta() (classic metabox path) and the reflected
 * Module::save_event_manager_fields() (front-end manager-form path — see
 * Test_Event_Manager_Save's docblock for why Reflection is used) directly,
 * simulating a real POST exactly as render_group_authoring_sections()'s
 * markup submits it.
 *
 * The critical coverage is the VALIDATION GUARD: an offering with zero valid
 * dates, or a recurrence rule with neither `count` nor `until`, must NEVER
 * reach Occurrences::reconcile() — a rule with no terminator expands to the
 * RECURRENCE_MAX_ROWS (104) safety cap, so silently reconciling it would
 * create up to 104 child posts from one save. A second focus is proving the
 * save -> reconcile() wiring cannot recurse: reconcile() creates/updates/
 * trashes CHILD event posts, each of which fires save_post_event again, and
 * persist_group_authoring() must not re-enter itself for those nested calls.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Module;

/**
 * @group event-save
 * @group occurrences
 */
class Test_Group_Authoring_Save extends Anchor_Events_TestCase {

	/** @var int */
	private $admin_id;

	public function set_up() {
		parent::set_up();
		// save_meta() requires current_user_can( 'edit_post', $post_id ).
		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_id );
	}

	public function tear_down() {
		unset( $_POST );
		parent::tear_down();
	}

	/** @return \Anchor\Events\Occurrences */
	private function occurrences() {
		return $this->module()->occurrences;
	}

	/**
	 * Build a valid $_POST payload for the classic metabox save (save_meta()),
	 * type=offering with two valid dates — mirrors exactly what
	 * render_group_authoring_sections()'s offering repeater submits.
	 */
	private function offering_post_payload( array $overrides = [] ) {
		return array_merge(
			[
				Module::NONCE => wp_create_nonce( Module::NONCE ),
				'anchor_event_start_date' => '2027-04-01',
				'anchor_event_type' => 'offering',
				'anchor_event_registration_mode' => 'free',
				'anchor_event_offering_dates' => [
					[ 'date' => '2027-04-01', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Session A', 'capacity' => 10 ],
					[ 'date' => '2027-04-08', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Session B', 'capacity' => 10 ],
				],
			],
			$overrides
		);
	}

	/**
	 * Build a valid $_POST payload for type=recurring, weekly, with NO
	 * terminator (no count, no until) — the incomplete-rule guard case.
	 */
	private function recurring_post_payload( array $overrides = [] ) {
		return array_merge(
			[
				Module::NONCE => wp_create_nonce( Module::NONCE ),
				'anchor_event_start_date' => '2027-03-01', // a Monday.
				'anchor_event_type' => 'recurring',
				'anchor_event_registration_mode' => 'free',
				'anchor_event_recurrence' => [
					'freq' => 'weekly',
					'interval' => 1,
					'start_time' => '09:00',
					'end_time' => '10:00',
					'capacity' => 8,
				],
			],
			$overrides
		);
	}

	/* ------------------------------------------------------------------
	 * Offering: persist + reconcile.
	 * ------------------------------------------------------------------ */

	/** RED-before-GREEN baseline: saving an offering parent with 2 dates persists offering_dates, sets group_role=parent, and reconciles 2 children. */
	public function test_save_meta_offering_two_dates_persists_and_reconciles_two_children() {
		$event_id = $this->make_event();

		$_POST = $this->offering_post_payload();
		$this->module()->save_meta( $event_id );

		$stored = get_post_meta( $event_id, '_anchor_event_offering_dates', true );
		$this->assertCount( 2, $stored, 'offering_dates must be persisted via the dedicated validated step.' );
		$this->assertSame( '2027-04-01', $stored[0]['date'] );
		$this->assertSame( 10, $stored[0]['capacity'] );

		$this->assertSame( 'parent', get_post_meta( $event_id, '_anchor_event_group_role', true ) );
		$this->assertCount( 2, $this->occurrences()->children( $event_id ) );
	}

	/** Editing an offering parent from 2 dates to 3 re-reconciles to 3 live children. */
	public function test_save_meta_offering_edit_to_three_dates_reconciles_to_three() {
		$event_id = $this->make_event();

		$_POST = $this->offering_post_payload();
		$this->module()->save_meta( $event_id );
		$this->assertCount( 2, $this->occurrences()->children( $event_id ) );

		$_POST = $this->offering_post_payload( [
			'anchor_event_offering_dates' => [
				[ 'date' => '2027-04-01', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'A', 'capacity' => 10 ],
				[ 'date' => '2027-04-08', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'B', 'capacity' => 10 ],
				[ 'date' => '2027-04-15', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'C', 'capacity' => 10 ],
			],
		] );
		$this->module()->save_meta( $event_id );

		$this->assertCount( 3, $this->occurrences()->children( $event_id ) );
	}

	/** Rows with an empty date are dropped by the sanitizer, matching the sessions-repeater convention. */
	public function test_save_meta_offering_drops_rows_with_empty_date() {
		$event_id = $this->make_event();

		$_POST = $this->offering_post_payload( [
			'anchor_event_offering_dates' => [
				[ 'date' => '2027-04-01', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'A', 'capacity' => 10 ],
				[ 'date' => '', 'start_time' => '10:00', 'end_time' => '12:00', 'label' => 'Bad row', 'capacity' => 5 ],
			],
		] );
		$this->module()->save_meta( $event_id );

		$stored = get_post_meta( $event_id, '_anchor_event_offering_dates', true );
		$this->assertCount( 1, $stored, 'The row with an empty date must be dropped.' );
		$this->assertCount( 1, $this->occurrences()->children( $event_id ) );
	}

	/* ------------------------------------------------------------------
	 * Offering: validation guard (zero valid dates -> never reconcile).
	 * ------------------------------------------------------------------ */

	/** A brand-new offering event saved with zero valid dates creates NO children and is never stamped a group parent. */
	public function test_save_meta_offering_zero_dates_on_new_event_creates_no_children() {
		$event_id = $this->make_event();

		$_POST = $this->offering_post_payload( [
			'anchor_event_offering_dates' => [ [ 'date' => '' ] ],
		] );
		$this->module()->save_meta( $event_id );

		$this->assertCount( 0, $this->occurrences()->children( $event_id ) );
		$this->assertNotSame( 'parent', get_post_meta( $event_id, '_anchor_event_group_role', true ) );
	}

	/** GUARD: saving an already-reconciled offering parent with the repeater emptied out must NOT reconcile away the existing children. */
	public function test_save_meta_offering_zero_dates_does_not_reconcile_existing_children_away() {
		$event_id = $this->make_event();

		$_POST = $this->offering_post_payload();
		$this->module()->save_meta( $event_id );
		$this->assertCount( 2, $this->occurrences()->children( $event_id ) );

		$_POST = $this->offering_post_payload( [ 'anchor_event_offering_dates' => [] ] );
		$this->module()->save_meta( $event_id );

		$this->assertCount(
			2,
			$this->occurrences()->children( $event_id ),
			'The guard must skip reconcile() entirely rather than trash/soft-close every existing child.'
		);
		$this->assertSame( [], get_post_meta( $event_id, '_anchor_event_offering_dates', true ) );
	}

	/* ------------------------------------------------------------------
	 * Recurring: validation guard (no count/until -> never reconcile).
	 * ------------------------------------------------------------------ */

	/** CRITICAL GUARD: an incomplete recurrence rule (no count AND no until) must NEVER reach reconcile() / create children. */
	public function test_save_meta_incomplete_recurrence_rule_does_not_reconcile() {
		$event_id = $this->make_event();

		$_POST = $this->recurring_post_payload(); // No count, no until.
		$this->module()->save_meta( $event_id );

		$this->assertCount(
			0,
			$this->occurrences()->children( $event_id ),
			'An incomplete recurrence rule must never reconcile — this is the 104-child-explosion guard.'
		);
		$this->assertNotSame( 'parent', get_post_meta( $event_id, '_anchor_event_group_role', true ) );

		// The sanitized-but-incomplete rule is still persisted (so the builder
		// shows back what was typed), but WITHOUT a count/until key.
		$stored = get_post_meta( $event_id, '_anchor_event_recurrence', true );
		$this->assertSame( 'weekly', $stored['freq'] );
		$this->assertArrayNotHasKey( 'count', $stored );
		$this->assertArrayNotHasKey( 'until', $stored );
	}

	/** A complete recurrence rule (weekly, count=3) reconciles to exactly 3 children. */
	public function test_save_meta_complete_recurrence_rule_creates_children() {
		$event_id = $this->make_event();

		$_POST = $this->recurring_post_payload( [
			'anchor_event_recurrence' => [
				'freq' => 'weekly',
				'interval' => 1,
				'count' => 3,
				'start_time' => '09:00',
				'end_time' => '10:00',
				'capacity' => 8,
			],
		] );
		$this->module()->save_meta( $event_id );

		$this->assertCount( 3, $this->occurrences()->children( $event_id ) );
		$this->assertSame( 'parent', get_post_meta( $event_id, '_anchor_event_group_role', true ) );

		$stored_rule = get_post_meta( $event_id, '_anchor_event_recurrence', true );
		$this->assertSame( 3, $stored_rule['count'] );
	}

	/** A recurrence rule with only an `until` date (no count) is ALSO a complete, reconcilable rule. */
	public function test_save_meta_recurrence_rule_with_only_until_reconciles() {
		$event_id = $this->make_event();

		$_POST = $this->recurring_post_payload( [
			'anchor_event_recurrence' => [
				'freq' => 'weekly',
				'interval' => 1,
				'until' => '2027-03-22', // 2027-03-01, 08, 15, 22 -> 4 Mondays.
			],
		] );
		$this->module()->save_meta( $event_id );

		$this->assertCount( 4, $this->occurrences()->children( $event_id ) );
	}

	/* ------------------------------------------------------------------
	 * Re-entrancy: no infinite loop / no runaway recursion.
	 * ------------------------------------------------------------------ */

	/**
	 * Proves save_meta() -> persist_group_authoring() -> reconcile() cannot
	 * recurse: reconcile() creates 2 CHILD posts via wp_insert_post(), each of
	 * which fires save_post_event again. If that re-entered
	 * persist_group_authoring() (and therefore reconcile()) the total event
	 * post count would balloon far past parent+2; completing synchronously
	 * with EXACTLY parent+2 posts, and every created post correctly flagged a
	 * CHILD (never itself treated as a parent), is the proof of no infinite
	 * loop.
	 */
	public function test_save_meta_offering_save_does_not_recurse() {
		$event_id = $this->make_event();
		$before = count( get_posts( [
			'post_type' => Module::CPT,
			'post_status' => 'any',
			'posts_per_page' => -1,
			'fields' => 'ids',
			'no_found_rows' => true,
		] ) );

		$_POST = $this->offering_post_payload();
		$this->module()->save_meta( $event_id );

		$after = get_posts( [
			'post_type' => Module::CPT,
			'post_status' => 'any',
			'posts_per_page' => -1,
			'fields' => 'ids',
			'no_found_rows' => true,
		] );

		$this->assertCount( $before + 2, $after, 'Exactly 2 new child posts — a recursive reconcile would create far more.' );

		$children = $this->occurrences()->children( $event_id );
		$this->assertCount( 2, $children );
		foreach ( $children as $child_id ) {
			$this->assertTrue(
				$this->occurrences()->is_group_child( $child_id ),
				'Every created post must be a CHILD, never itself treated as an authored parent.'
			);
		}
	}

	/* ------------------------------------------------------------------
	 * Front-end manager-form save path parity.
	 * ------------------------------------------------------------------ */

	/**
	 * The front-end manager form's save path (save_event_manager_fields(), the
	 * front-end-editor equivalent of save_meta() — see Test_Event_Manager_Save
	 * for why Reflection is used) reuses the SAME persist_group_authoring()
	 * step and also reconciles offering children.
	 */
	public function test_front_end_offering_save_creates_children() {
		$event_id = $this->make_event();

		$_POST = [
			'anchor_event_type' => 'offering',
			'anchor_event_registration_mode' => 'free',
			'anchor_event_offering_dates' => [
				[ 'date' => '2027-05-01', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'A', 'capacity' => 5 ],
				[ 'date' => '2027-05-08', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'B', 'capacity' => 5 ],
			],
		];

		$fallback = $this->module()->registration_mode( $event_id );
		$method = new ReflectionMethod( Module::class, 'save_event_manager_fields' );
		$method->setAccessible( true );
		$method->invoke( $this->module(), $event_id, '2027-05-01', $fallback );

		$this->assertCount( 2, $this->occurrences()->children( $event_id ) );
		$this->assertSame( 'parent', get_post_meta( $event_id, '_anchor_event_group_role', true ) );
	}

	/* ------------------------------------------------------------------
	 * Type change away from offering/recurring.
	 * ------------------------------------------------------------------ */

	/** Changing type away from offering soft-closes a seated child, trashes an unseated one, and clears group_role. */
	public function test_type_change_away_from_offering_retires_children_and_clears_group_role() {
		$event_id = $this->make_event();

		$_POST = $this->offering_post_payload();
		$this->module()->save_meta( $event_id );
		$children = $this->occurrences()->children( $event_id );
		$this->assertCount( 2, $children );

		[ $seated, $unseated ] = $children;
		$this->make_seat( $seated );

		$_POST = array_merge( $this->offering_post_payload(), [ 'anchor_event_type' => 'single' ] );
		$this->module()->save_meta( $event_id );

		$this->assertSame( '', get_post_meta( $event_id, '_anchor_event_group_role', true ), 'group_role must be cleared once the type is no longer offering/recurring.' );
		$this->assertSame( [], get_post_meta( $event_id, '_anchor_event_offering_dates', true ) );
		$this->assertCount( 0, $this->occurrences()->children( $event_id ), 'No live children once the type changed away.' );
		$this->assertNotSame( 'trash', get_post_status( $seated ), 'A seated child must be soft-closed, not trashed.' );
		$this->assertSame( 'trash', get_post_status( $unseated ), 'An unseated child must be trashed.' );
	}

	/** A plain event that was never a group parent has nothing to reconcile away when saved as type=single (no-op, no spurious reconcile call). */
	public function test_ordinary_single_event_save_is_a_group_authoring_no_op() {
		$event_id = $this->make_event();

		$_POST = [
			Module::NONCE => wp_create_nonce( Module::NONCE ),
			'anchor_event_start_date' => '2027-04-01',
			'anchor_event_type' => 'single',
			'anchor_event_registration_mode' => 'free',
		];
		$this->module()->save_meta( $event_id );

		$this->assertSame( '', get_post_meta( $event_id, '_anchor_event_group_role', true ) );
		$this->assertCount( 0, $this->occurrences()->children( $event_id ) );
	}
}
