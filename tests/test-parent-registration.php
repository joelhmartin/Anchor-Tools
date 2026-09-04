<?php
/**
 * The group parent's registration switch (audit MODEL-D40 / THEME-D8).
 *
 * `registration_enabled` is a PER-OCCURRENCE fact: it is seeded onto a child
 * once, at creation, and never re-synced — closing the September date must not
 * close November. The failure that bought was the parent's metabox presenting
 * its own copy as if it were the group's setting: ticking it, saving, and
 * seeing every existing date keep its old value with no error and no notice
 * (production parent 7258 registration_enabled=1, its child 7528 =0).
 *
 * The fix is an EXPLICIT action rather than an implicit sync: on a parent that
 * has live dates the form renders a second, always-unchecked checkbox
 * ("Apply to all N scheduled dates"), and only when that box is ticked does
 * the save write the parent's value down to every LIVE child. Soft-closed
 * children are never touched — registration off is half of what "closed"
 * means.
 *
 * These tests pin both halves plus the creation seed (a child born after the
 * parent turned registration on must be born on, read through get_meta()'s
 * cast, not raw meta — THEME-D8's plugin half).
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Module;
use Anchor\Events\Registrations;

/**
 * @group event-save
 * @group occurrences
 */
class Test_Parent_Registration extends Anchor_Events_TestCase {

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
	 * A metabox POST for an offering parent with two dates. Registration is
	 * OFF unless $overrides turns it on, so the children are created off.
	 */
	private function offering_payload( array $overrides = [] ) {
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

	/** Build the offering parent + its two children, all with registration OFF. */
	private function parent_with_two_closed_children() {
		$parent_id = $this->make_event( [ 'registration_enabled' => false ] );

		$_POST = $this->offering_payload();
		$this->module()->save_meta( $parent_id );

		$children = $this->occurrences()->children( $parent_id );
		$this->assertCount( 2, $children, 'Precondition: two live children.' );
		foreach ( $children as $child_id ) {
			$this->assertFalse(
				(bool) get_post_meta( $child_id, '_anchor_event_registration_enabled', true ),
				'Precondition: a child of a registration-off parent is created off.'
			);
		}

		return [ $parent_id, $children ];
	}

	/* ------------------------------------------------------------------
	 * (a) The explicit action writes every live child.
	 * ------------------------------------------------------------------ */

	/** Ticking "apply to all dates" alongside registration ON flips every live child on. */
	public function test_apply_box_writes_registration_to_every_live_child() {
		list( $parent_id, $children ) = $this->parent_with_two_closed_children();

		$_POST = $this->offering_payload( [
			'anchor_event_registration_enabled' => '1',
			'anchor_event_registration_apply_to_dates' => '1',
		] );
		$this->module()->save_meta( $parent_id );

		$this->assertTrue( (bool) get_post_meta( $parent_id, '_anchor_event_registration_enabled', true ) );
		foreach ( $children as $child_id ) {
			$this->assertTrue(
				(bool) get_post_meta( $child_id, '_anchor_event_registration_enabled', true ),
				'The applied setting must reach every live date, not only dates added later.'
			);
		}
	}

	/** The action works in the OFF direction too: applying registration-off closes every live date. */
	public function test_apply_box_writes_registration_off_to_every_live_child() {
		$parent_id = $this->make_event( [ 'registration_enabled' => true ] );

		$_POST = $this->offering_payload( [ 'anchor_event_registration_enabled' => '1' ] );
		$this->module()->save_meta( $parent_id );
		$children = $this->occurrences()->children( $parent_id );
		$this->assertCount( 2, $children );

		$_POST = $this->offering_payload( [ 'anchor_event_registration_apply_to_dates' => '1' ] );
		$this->module()->save_meta( $parent_id );

		foreach ( $children as $child_id ) {
			$this->assertFalse(
				(bool) get_post_meta( $child_id, '_anchor_event_registration_enabled', true ),
				'Applying an off setting must close every live date.'
			);
		}
	}

	/** Without the action box, the parent's own flag changes and existing dates keep theirs. */
	public function test_without_the_apply_box_existing_children_keep_their_own_setting() {
		list( $parent_id, $children ) = $this->parent_with_two_closed_children();

		$_POST = $this->offering_payload( [ 'anchor_event_registration_enabled' => '1' ] );
		$this->module()->save_meta( $parent_id );

		$this->assertTrue( (bool) get_post_meta( $parent_id, '_anchor_event_registration_enabled', true ) );
		foreach ( $children as $child_id ) {
			$this->assertFalse(
				(bool) get_post_meta( $child_id, '_anchor_event_registration_enabled', true ),
				'A per-occurrence value must never be re-synced implicitly.'
			);
		}
	}

	/** A soft-closed child is never flipped on — registration off is half of what "closed" means. */
	public function test_soft_closed_child_is_never_flipped_on() {
		list( $parent_id, $children ) = $this->parent_with_two_closed_children();
		$closed = (int) $children[0];
		$live   = (int) $children[1];

		// Seat the first date, then drop it -> soft-closed (post + roster kept).
		$this->make_seat( $closed, [ 'status' => Registrations::STATUS_CONFIRMED ] );
		$_POST = $this->offering_payload( [
			'anchor_event_offering_dates' => [
				[ 'date' => '2027-04-08', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Session B', 'capacity' => 10 ],
			],
		] );
		$this->module()->save_meta( $parent_id );
		$this->assertTrue( $this->occurrences()->is_closed( $closed ), 'Precondition: the dropped seated date is soft-closed.' );

		// Now turn registration on for the whole group.
		$_POST = $this->offering_payload( [
			'anchor_event_offering_dates' => [
				[ 'date' => '2027-04-08', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Session B', 'capacity' => 10 ],
			],
			'anchor_event_registration_enabled' => '1',
			'anchor_event_registration_apply_to_dates' => '1',
		] );
		$this->module()->save_meta( $parent_id );

		$this->assertTrue(
			(bool) get_post_meta( $live, '_anchor_event_registration_enabled', true ),
			'The still-offered date must be opened.'
		);
		$this->assertFalse(
			(bool) get_post_meta( $closed, '_anchor_event_registration_enabled', true ),
			'A soft-closed occurrence must never be re-opened by a group-wide apply.'
		);
		$this->assertTrue( $this->occurrences()->is_closed( $closed ), 'And it must stay closed.' );
	}

	/** The action box is inert on a plain single event (nothing to apply to). */
	public function test_apply_box_on_a_single_event_is_inert() {
		$event_id = $this->make_event( [ 'registration_enabled' => false ] );

		$_POST = [
			Module::NONCE => wp_create_nonce( Module::NONCE ),
			'anchor_event_start_date' => '2027-04-01',
			'anchor_event_type' => 'single',
			'anchor_event_registration_mode' => 'free',
			'anchor_event_registration_enabled' => '1',
			'anchor_event_registration_apply_to_dates' => '1',
		];
		$this->module()->save_meta( $event_id );

		$this->assertTrue( (bool) get_post_meta( $event_id, '_anchor_event_registration_enabled', true ) );
		$this->assertSame( [], $this->occurrences()->children( $event_id ) );
	}

	/** The front-end manager form's save path applies the same way (one shared step). */
	public function test_front_end_manager_save_applies_to_every_live_child() {
		list( $parent_id, $children ) = $this->parent_with_two_closed_children();

		$_POST = [
			'anchor_event_type' => 'offering',
			'anchor_event_registration_mode' => 'free',
			'anchor_event_registration_enabled' => '1',
			'anchor_event_registration_apply_to_dates' => '1',
			'anchor_event_offering_dates' => $this->offering_payload()['anchor_event_offering_dates'],
		];

		$fallback = $this->module()->registration_mode( $parent_id );
		$method   = new ReflectionMethod( Module::class, 'save_event_manager_fields' );
		$method->setAccessible( true );
		$method->invoke( $this->module(), $parent_id, '2027-04-01', $fallback );

		foreach ( $children as $child_id ) {
			$this->assertTrue(
				(bool) get_post_meta( $child_id, '_anchor_event_registration_enabled', true ),
				'Both save paths must share one apply-to-dates step.'
			);
		}
	}

	/* ------------------------------------------------------------------
	 * (b) Creation seed — read through get_meta()'s cast (THEME-D8).
	 * ------------------------------------------------------------------ */

	/** A date added AFTER the parent turned registration on is created on. */
	public function test_child_created_after_the_parent_enabled_registration_is_created_on() {
		list( $parent_id, $children ) = $this->parent_with_two_closed_children();

		$_POST = $this->offering_payload( [
			'anchor_event_registration_enabled' => '1',
			'anchor_event_offering_dates' => array_merge(
				$this->offering_payload()['anchor_event_offering_dates'],
				[ [ 'date' => '2027-04-15', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Session C', 'capacity' => 10 ] ]
			),
		] );
		$this->module()->save_meta( $parent_id );

		$all = $this->occurrences()->children( $parent_id );
		$this->assertCount( 3, $all );
		$new = array_values( array_diff( $all, $children ) );
		$this->assertCount( 1, $new );

		$this->assertTrue(
			(bool) get_post_meta( (int) $new[0], '_anchor_event_registration_enabled', true ),
			'A new date must inherit the parent\'s CURRENT registration setting at creation.'
		);
	}

	/** create_child() reads the flag through get_meta()'s cast, not raw post meta. */
	public function test_child_creation_reads_the_parent_flag_through_the_cast_layer() {
		$parent_id = $this->make_event();
		// The raw row a checkbox save leaves behind is the string '1', not a bool.
		update_post_meta( $parent_id, '_anchor_event_registration_enabled', '1' );
		update_post_meta( $parent_id, '_anchor_event_offering_dates', [
			[ 'date' => '2027-06-01', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => '', 'capacity' => 0 ],
		] );

		$live = $this->occurrences()->reconcile( $parent_id );
		$this->assertCount( 1, $live );

		$this->assertTrue(
			(bool) get_post_meta( (int) $live[0], '_anchor_event_registration_enabled', true ),
			'get_meta() is the cast layer; a raw truthy row must still seed the child on.'
		);
	}

	/* ------------------------------------------------------------------
	 * The control itself: only rendered when there are live dates.
	 * ------------------------------------------------------------------ */

	/** A parent with live children renders the action + the "existing dates keep theirs" note. */
	public function test_apply_control_renders_for_a_parent_with_live_children() {
		list( $parent_id, ) = $this->parent_with_two_closed_children();

		$html = $this->module()->render_registration_apply_to_dates( $parent_id );

		$this->assertStringContainsString( 'name="anchor_event_registration_apply_to_dates"', $html );
		$this->assertStringContainsString( 'Apply to all 2 scheduled dates', $html );
		$this->assertStringContainsString( 'Existing dates keep their own setting unless you apply.', $html );
		$this->assertStringNotContainsString( 'checked', $html, 'The action must never be pre-ticked.' );
	}

	/** A plain single event renders nothing — there is no group to apply to. */
	public function test_apply_control_renders_nothing_for_a_single_event() {
		$event_id = $this->make_event();

		$this->assertSame( '', $this->module()->render_registration_apply_to_dates( $event_id ) );
	}

	/** A parent whose only date is soft-closed has no live dates, so no action is offered. */
	public function test_apply_control_renders_nothing_when_every_date_is_closed() {
		$parent_id = $this->make_event();
		update_post_meta( $parent_id, '_anchor_event_offering_dates', [
			[ 'date' => '2027-07-01', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => '', 'capacity' => 0 ],
		] );
		$live = $this->occurrences()->reconcile( $parent_id );
		$this->make_seat( (int) $live[0], [ 'status' => Registrations::STATUS_CONFIRMED ] );

		update_post_meta( $parent_id, '_anchor_event_offering_dates', [] );
		// reconcile() with an empty desired set retires (soft-closes) the seated date.
		$this->occurrences()->reconcile( $parent_id );
		$this->assertTrue( $this->occurrences()->is_closed( (int) $live[0] ) );

		$this->assertSame( '', $this->module()->render_registration_apply_to_dates( $parent_id ) );
	}

	/** Both authoring forms render the shared control — one implementation, two call sites. */
	public function test_both_forms_render_the_shared_control() {
		$source = file_get_contents( dirname( __DIR__ ) . '/anchor-events-manager/anchor-events-manager.php' );
		$this->assertSame(
			2,
			substr_count( $source, '$this->render_registration_apply_to_dates(' ),
			'Exactly two call sites: the metabox and the front-end manager form — one shared renderer, never a second copy.'
		);
	}
}
