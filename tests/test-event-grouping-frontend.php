<?php
/**
 * Front-end "choose your date" presentation tests (Phase 2, Task 2.4).
 *
 * Covers the public render surface a group parent/child single-event page —
 * and the series archive — actually calls:
 *   - Module::render_choose_date_list() / render_registration_form() (parent
 *     picker replaces the parent's own booking form).
 *   - Module::render_sibling_dates() / render_registration_form() (child
 *     keeps its own booking UI + gets an "other dates" nav; a soft-closed
 *     child gets a "no longer available" notice instead of a form).
 *   - Series::render_archive() (a group's children collapse to one parent
 *     row; a soft-closed child is excluded entirely).
 *
 * occurrence = event post — this is read-only presentation over the
 * Occurrences engine's public API (children()/siblings()/is_group_parent()/
 * is_group_child()/parent_of()); it never touches
 * engine/seats/reconcile internals directly.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Module;
use Anchor\Events\Series;

/**
 * @group event-grouping-frontend
 */
class Test_Event_Grouping_Frontend extends Anchor_Events_TestCase {

	/** @return \Anchor\Events\Occurrences */
	protected function occurrences() {
		return $this->module()->occurrences;
	}

	/**
	 * Create + reconcile a group-parent offering with the given dates.
	 *
	 * @param array $rows Offering-dates rows (date/start_time/end_time/label/capacity).
	 * @param array $meta Additional parent meta overrides.
	 * @return array{0:int,1:int[]} [ parent_id, live_child_ids (date-ascending) ]
	 */
	protected function make_offering( array $rows, array $meta = [] ) {
		$parent_id = $this->make_event( array_merge( [
			'title'    => 'Workshop',
			'venue'    => 'Main Hall',
			'timezone' => 'UTC',
		], $meta ) );
		update_post_meta( $parent_id, '_anchor_event_offering_dates', $rows );
		$live = $this->occurrences()->reconcile( $parent_id );
		return [ $parent_id, $live ];
	}

	protected function three_rows() {
		return [
			[ 'date' => '2027-05-01', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Session A', 'capacity' => 5 ],
			[ 'date' => '2027-05-08', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Session B', 'capacity' => 5 ],
			[ 'date' => '2027-05-15', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Session C', 'capacity' => 5 ],
		];
	}

	/* ------------------------------------------------------------------
	 * A. Group PARENT page = choose-your-date, not a registration form.
	 * ------------------------------------------------------------------ */

	public function test_group_parent_registration_form_is_suppressed() {
		[ $parent_id ] = $this->make_offering( $this->three_rows() );

		$html = $this->module()->render_registration_form( $parent_id );

		$this->assertSame( '', $html, 'A group parent must never render its own registration form.' );
	}

	public function test_group_parent_choose_date_list_contains_live_children_and_register_links() {
		[ $parent_id, $live ] = $this->make_offering( $this->three_rows() );

		$html = $this->module()->render_choose_date_list( $parent_id );

		$this->assertStringContainsString( 'anchor-event-choose-date', $html );
		$this->assertStringNotContainsString( '<form', $html, 'The picker must not contain a direct booking form.' );

		foreach ( $live as $child_id ) {
			$this->assertStringContainsString( esc_url( get_permalink( $child_id ) ), $html );
		}
		$this->assertStringContainsString( 'May 1, 2027', $html );
		$this->assertStringContainsString( 'May 8, 2027', $html );
		$this->assertStringContainsString( 'May 15, 2027', $html );
	}

	public function test_group_parent_choose_date_list_excludes_soft_closed_child() {
		[ $parent_id, $live ] = $this->make_offering( $this->three_rows() );
		$to_close = $live[0]; // 2027-05-01.
		$this->make_seat( $to_close ); // give it a seat so retiring soft-closes (not trashes).

		// Drop that date from the parent's offering_dates -> soft-close.
		update_post_meta( $parent_id, '_anchor_event_offering_dates', array_slice( $this->three_rows(), 1 ) );
		$this->occurrences()->reconcile( $parent_id );
		$this->assertTrue( (bool) get_post_meta( $to_close, '_anchor_event_occurrence_closed', true ) );

		$html = $this->module()->render_choose_date_list( $parent_id );

		$this->assertStringNotContainsString( esc_url( get_permalink( $to_close ) ), $html );
		$this->assertStringNotContainsString( 'May 1, 2027', $html );
	}

	public function test_group_parent_with_zero_live_children_shows_empty_state() {
		$parent_id = $this->make_event( [ 'title' => 'Empty Offering', 'timezone' => 'UTC' ] );
		update_post_meta( $parent_id, '_anchor_event_offering_dates', [] );
		$this->occurrences()->reconcile( $parent_id );

		$html = $this->module()->render_choose_date_list( $parent_id );

		$this->assertStringContainsString( 'No dates currently scheduled.', $html );
	}

	/* ------------------------------------------------------------------
	 * B. Group CHILD page = normal booking UI + sibling nav.
	 * ------------------------------------------------------------------ */

	public function test_live_child_renders_normal_registration_form_plus_sibling_dates() {
		[ , $live ] = $this->make_offering( $this->three_rows() );
		[ $a, $b, $c ] = $live;

		$form = $this->module()->render_registration_form( $a );
		$this->assertStringContainsString( '<form class="anchor-event-registration"', $form );

		$siblings_html = $this->module()->render_sibling_dates( $a );
		$this->assertStringContainsString( 'anchor-event-other-dates', $siblings_html );
		$this->assertStringContainsString( esc_url( get_permalink( $b ) ), $siblings_html );
		$this->assertStringContainsString( esc_url( get_permalink( $c ) ), $siblings_html );
		$this->assertStringNotContainsString( esc_url( get_permalink( $a ) ), $siblings_html, 'The sibling list must not include the child itself.' );
	}

	public function test_directly_visited_soft_closed_child_shows_unavailable_not_form() {
		[ $parent_id, $live ] = $this->make_offering( $this->three_rows() );
		$closed = $live[0];
		$this->make_seat( $closed );

		update_post_meta( $parent_id, '_anchor_event_offering_dates', array_slice( $this->three_rows(), 1 ) );
		$this->occurrences()->reconcile( $parent_id );
		$this->assertTrue( (bool) get_post_meta( $closed, '_anchor_event_occurrence_closed', true ) );

		// Proves the closed-notice branch is driven purely by the engine's
		// children($parent_id,false) exclusion (Task 2.1 review note: a
		// closed child's own registration_enabled/occurrence_closed meta must
		// never be trusted directly) — the closed child is excluded from the
		// live set even though it's still directly reachable by its own URL.
		$this->assertNotContains( $closed, $this->occurrences()->children( $parent_id, false ) );

		$html = $this->module()->render_registration_form( $closed );

		$this->assertStringContainsString( 'no longer available', $html );
		$this->assertStringNotContainsString( '<form', $html );
	}

	public function test_soft_closed_child_sibling_dates_still_links_to_live_siblings() {
		[ $parent_id, $live ] = $this->make_offering( $this->three_rows() );
		$closed = $live[0];
		$this->make_seat( $closed );

		update_post_meta( $parent_id, '_anchor_event_offering_dates', array_slice( $this->three_rows(), 1 ) );
		$live2 = $this->occurrences()->reconcile( $parent_id );

		$html = $this->module()->render_sibling_dates( $closed );

		foreach ( $live2 as $live_id ) {
			$this->assertStringContainsString( esc_url( get_permalink( $live_id ) ), $html );
		}
		$this->assertStringContainsString( esc_url( get_permalink( $parent_id ) ), $html, 'Must link back to the parent choose-date page.' );
	}

	/* ------------------------------------------------------------------
	 * Availability hint reflects capacity.
	 * ------------------------------------------------------------------ */

	public function test_choose_date_availability_hint_shows_sold_out_when_full() {
		[ $parent_id, $live ] = $this->make_offering( [
			[ 'date' => '2027-06-01', 'start_time' => '09:00', 'end_time' => '10:00', 'label' => 'Full session', 'capacity' => 1 ],
		] );
		$full_child = $live[0];
		$this->make_seat( $full_child );

		$html = $this->module()->render_choose_date_list( $parent_id );

		$this->assertStringContainsString( 'Sold out', $html );
	}

	public function test_choose_date_availability_hint_shows_remaining_spots() {
		[ $parent_id, $live ] = $this->make_offering( [
			[ 'date' => '2027-06-08', 'start_time' => '09:00', 'end_time' => '10:00', 'label' => 'Roomy session', 'capacity' => 5 ],
		] );
		$child = $live[0];
		$this->make_seat( $child );
		$this->make_seat( $child, [ 'email' => 'second@example.test' ] );

		$html = $this->module()->render_choose_date_list( $parent_id );

		$this->assertStringContainsString( '3 spots left', $html );
	}

	/* ------------------------------------------------------------------
	 * C. Series archive: one row per group, soft-closed children excluded.
	 * ------------------------------------------------------------------ */

	protected function go_to_series_archive( $parent_id ) {
		$terms = wp_get_object_terms( $parent_id, Series::TAXONOMY );
		$this->assertNotEmpty( $terms, 'reconcile() must have assigned a series term.' );
		$term_link = get_term_link( $terms[0] );
		$this->go_to( $term_link );
	}

	public function test_series_archive_collapses_group_to_one_parent_row() {
		[ $parent_id, $live ] = $this->make_offering( $this->three_rows() );
		$this->go_to_series_archive( $parent_id );

		$html = $this->module()->series->render_archive();

		// The parent's own permalink appears exactly once as a link href.
		$needle = 'href="' . esc_url( get_permalink( $parent_id ) ) . '"';
		$this->assertSame( 1, substr_count( $html, $needle ), 'The group must collapse to exactly one parent row.' );

		// None of the individual children get their own row/link.
		foreach ( $live as $child_id ) {
			$this->assertStringNotContainsString( 'href="' . esc_url( get_permalink( $child_id ) ) . '"', $html );
		}

		$this->assertStringContainsString( '3 dates available', $html );
	}

	public function test_series_archive_excludes_soft_closed_child_from_group_row() {
		[ $parent_id, $live ] = $this->make_offering( $this->three_rows() );
		$closed = $live[0];
		$this->make_seat( $closed );

		update_post_meta( $parent_id, '_anchor_event_offering_dates', array_slice( $this->three_rows(), 1 ) );
		$this->occurrences()->reconcile( $parent_id );

		$this->go_to_series_archive( $parent_id );
		$html = $this->module()->series->render_archive();

		$this->assertStringNotContainsString( 'href="' . esc_url( get_permalink( $closed ) ) . '"', $html );
		$this->assertStringContainsString( '2 dates available', $html );
	}

	public function test_series_archive_shows_empty_state_when_all_children_closed() {
		[ $parent_id, $live ] = $this->make_offering( [
			[ 'date' => '2027-07-01', 'start_time' => '09:00', 'end_time' => '10:00', 'label' => 'Only session', 'capacity' => 5 ],
		] );
		$this->make_seat( $live[0] );

		update_post_meta( $parent_id, '_anchor_event_offering_dates', [] );
		$this->occurrences()->reconcile( $parent_id );

		$this->go_to_series_archive( $parent_id );
		$html = $this->module()->series->render_archive();

		$this->assertStringContainsString( 'No sessions found in this series.', $html );
	}

	/* ------------------------------------------------------------------
	 * D. Review-round fixes: FIX 1 (occurrence label on date rows), FIX 2
	 * (guard the sibling "See all dates" link against a trashed parent),
	 * FIX 3 (plain archive excludes soft-closed children).
	 * ------------------------------------------------------------------ */

	public function test_choose_date_parent_picker_row_shows_occurrence_label() {
		[ $parent_id ] = $this->make_offering( $this->three_rows() );

		$html = $this->module()->render_choose_date_list( $parent_id );

		// The brief requires date + time + label; each child's label is baked
		// into its post_title by Occurrences::child_title() as "<parent
		// title> — <label>" — assert the bare label text (not just the date)
		// is surfaced on the row, wrapped in its own element.
		$this->assertStringContainsString( '<span class="anchor-event-choose-date-label">Session A</span>', $html );
		$this->assertStringContainsString( '<span class="anchor-event-choose-date-label">Session B</span>', $html );
		$this->assertStringContainsString( '<span class="anchor-event-choose-date-label">Session C</span>', $html );
	}

	public function test_sibling_dates_row_also_shows_occurrence_label() {
		[ , $live ] = $this->make_offering( $this->three_rows() );
		[ $a ] = $live;

		$html = $this->module()->render_sibling_dates( $a );

		$this->assertStringContainsString( '<span class="anchor-event-choose-date-label">Session B</span>', $html );
		$this->assertStringContainsString( '<span class="anchor-event-choose-date-label">Session C</span>', $html );
	}

	public function test_sibling_dates_omits_link_to_trashed_parent() {
		[ $parent_id, $live ] = $this->make_offering( $this->three_rows() );
		$seated = $live[0];
		$this->make_seat( $seated ); // gives it a seat, so the parent-trash retirement soft-closes it instead of trashing it.

		\wp_trash_post( $parent_id );

		$this->assertSame( 'trash', \get_post_status( $parent_id ), 'The parent itself must actually be trashed for this scenario.' );
		$this->assertSame( 'publish', \get_post_status( $seated ), 'A seated child must stay published (soft-closed, not trashed) when its parent is trashed.' );

		$html = $this->module()->render_sibling_dates( $seated );

		$this->assertStringNotContainsString( 'href="' . esc_url( \get_permalink( $parent_id ) ) . '"', $html, 'Must not link to the trashed parent (would 404).' );
		$this->assertStringNotContainsString( 'See all dates', $html );
	}

	protected function go_to_plain_archive() {
		$this->go_to( \get_post_type_archive_link( Module::CPT ) );
	}

	public function test_plain_archive_excludes_soft_closed_child() {
		[ $parent_id, $live ] = $this->make_offering( $this->three_rows() );
		$closed = $live[0];
		$this->make_seat( $closed ); // give it a seat so retiring soft-closes (not trashes).

		update_post_meta( $parent_id, '_anchor_event_offering_dates', array_slice( $this->three_rows(), 1 ) );
		$this->occurrences()->reconcile( $parent_id );
		$this->assertTrue( (bool) get_post_meta( $closed, '_anchor_event_occurrence_closed', true ) );
		$this->assertSame( 'publish', get_post_status( $closed ), 'A soft-closed occurrence stays published — it must be excluded from the archive query itself, not rely on post_status.' );

		$this->go_to_plain_archive();

		global $wp_query;
		$ids = \wp_list_pluck( $wp_query->posts, 'ID' );

		$this->assertNotContains( $closed, $ids, 'A soft-closed occurrence must not appear as its own "View Event" card on the plain archive.' );
	}
}
