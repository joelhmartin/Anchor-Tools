<?php
/**
 * A passed occurrence is not "live", and a child inherits integrator meta.
 *
 * Covers the second half of the 2026-08-28 defect report: AE-4 (children()
 * ignored dates entirely and trusted a hand-ticked flag) and AE-6 (reconcile
 * copied only the plugin's own schema, so a theme's fields never reached a
 * generated child).
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Module;

/**
 * @group occurrences
 */
class Test_Occurrence_Liveness extends Anchor_Events_TestCase {

	private function occ() {
		return $this->module()->occurrences;
	}

	/** A parent with one generated child on $date. */
	private function make_group( $date ) {
		$parent = $this->make_event( [ 'title' => 'Group Parent', 'start_date' => $date ] );
		update_post_meta( $parent, '_anchor_event_group_role', 'parent' );

		$child = $this->make_event( [ 'title' => 'Group Parent — ' . $date, 'start_date' => $date ] );
		update_post_meta( $child, '_anchor_event_group_role', 'child' );
		update_post_meta( $child, '_anchor_event_group_id', $parent );
		update_post_meta( $child, '_anchor_event_occurrence_key', $date );
		update_post_meta( $child, '_anchor_event_occurrence_closed', false );

		return [ $parent, $child ];
	}

	/**
	 * AE-4. Nobody ticked the box; the date went by on its own.
	 */
	public function test_a_passed_occurrence_is_not_live() {
		$past = gmdate( 'Y-m-d', time() - 10 * DAY_IN_SECONDS );
		list( $parent, $child ) = $this->make_group( $past );

		$this->assertNotContains(
			$child,
			$this->occ()->children( $parent ),
			'A passed occurrence still counted as live, so its parent advertises a date that has gone.'
		);
	}

	/** A future occurrence is of course still live. */
	public function test_a_future_occurrence_is_live() {
		$future = gmdate( 'Y-m-d', time() + 10 * DAY_IN_SECONDS );
		list( $parent, $child ) = $this->make_group( $future );

		$this->assertContains( $child, $this->occ()->children( $parent ) );
	}

	/**
	 * The date rule must not swallow the archival view: asking for closed
	 * occurrences too still returns the passed one.
	 */
	public function test_include_closed_still_returns_passed_occurrences() {
		$past = gmdate( 'Y-m-d', time() - 10 * DAY_IN_SECONDS );
		list( $parent, $child ) = $this->make_group( $past );

		$this->assertContains( $child, $this->occ()->children( $parent, true ) );
	}

	/**
	 * The reason the date rule lives in children() and not is_closed():
	 * soft_close() early-returns on an already-closed child, so a date-aware
	 * is_closed() would make closing a past occurrence a silent no-op and the
	 * cancelled status would never be written.
	 */
	public function test_closing_a_passed_occurrence_still_writes_its_state() {
		$past = gmdate( 'Y-m-d', time() - 10 * DAY_IN_SECONDS );
		list( $parent, $child ) = $this->make_group( $past );

		$soft_close = new ReflectionMethod( $this->occ(), 'soft_close' );
		$soft_close->setAccessible( true );
		$soft_close->invoke( $this->occ(), $child );

		$this->assertTrue( (bool) get_post_meta( $child, '_anchor_event_occurrence_closed', true ) );
		$this->assertSame( 'cancelled', get_post_meta( $child, '_anchor_event_status', true ) );
	}

	/** AE-6. A theme's own field reaches the child. */
	public function test_a_child_inherits_filtered_meta_keys() {
		$future = gmdate( 'Y-m-d', time() + 10 * DAY_IN_SECONDS );
		list( $parent, $child ) = $this->make_group( $future );

		update_post_meta( $parent, '_deka_instructor', 'Dr. Alvarez' );

		$filter = function ( $keys ) {
			$keys[] = '_deka_instructor';
			return $keys;
		};
		add_filter( 'anchor_events_inherited_meta_keys', $filter );

		$sync = new ReflectionMethod( $this->occ(), 'sync_shared_meta' );
		$sync->setAccessible( true );
		$sync->invoke( $this->occ(), $parent, $child, null );

		remove_filter( 'anchor_events_inherited_meta_keys', $filter );

		$this->assertSame( 'Dr. Alvarez', get_post_meta( $child, '_deka_instructor', true ) );
	}

	/**
	 * The filter must not be able to reinstate a key the engine deliberately
	 * keeps off a child — occurrence_closed is per-occurrence state, and
	 * inheriting the parent's would un-close every closed child on reconcile.
	 */
	public function test_the_filter_cannot_override_the_plugins_own_namespace() {
		$future = gmdate( 'Y-m-d', time() + 10 * DAY_IN_SECONDS );
		list( $parent, $child ) = $this->make_group( $future );

		update_post_meta( $child, '_anchor_event_occurrence_closed', true );
		update_post_meta( $parent, '_anchor_event_occurrence_closed', false );

		$filter = function ( $keys ) {
			$keys[] = '_anchor_event_occurrence_closed';
			return $keys;
		};
		add_filter( 'anchor_events_inherited_meta_keys', $filter );

		$sync = new ReflectionMethod( $this->occ(), 'sync_shared_meta' );
		$sync->setAccessible( true );
		$sync->invoke( $this->occ(), $parent, $child, null );

		remove_filter( 'anchor_events_inherited_meta_keys', $filter );

		$this->assertTrue(
			(bool) get_post_meta( $child, '_anchor_event_occurrence_closed', true ),
			'The filter reached into the plugin namespace and un-closed a closed child.'
		);
	}

	/** AE-5. The public front door returns the same list the archive shows. */
	public function test_get_events_is_a_usable_public_api() {
		$future = gmdate( 'Y-m-d', time() + 20 * DAY_IN_SECONDS );
		list( $parent, $child ) = $this->make_group( $future );
		$plain = $this->make_event( [ 'title' => 'Plain Event', 'start_date' => $future ] );

		$ids = wp_list_pluck( $this->module()->get_events(), 'ID' );

		$this->assertContains( $plain, $ids );
		$this->assertContains( $parent, $ids );
		$this->assertNotContains( $child, $ids, 'get_events() should collapse a group to its parent.' );

		$with_children = wp_list_pluck( $this->module()->get_events( [ 'include_children' => true ] ), 'ID' );
		$this->assertContains( $child, $with_children );
	}
}
