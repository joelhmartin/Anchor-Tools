<?php
/**
 * One listing-exclusion builder, applied by every list query, plus the
 * reminder sweep's group-parent / cancelled-date guards.
 *
 * Audit entries closed here:
 *   RENDER-D15 / MODEL-D3 — build_closed_clause() (the soft-closed-occurrence
 *     exclusion) had ONE caller (filter_archive_query) while its sibling
 *     build_hide_clause() had five, so a soft-closed group child still showed
 *     up as a bookable card in [events_list], [event_calendar], the two
 *     manager shortcodes and the series archive, linking to a page whose only
 *     registration UI is "This date is no longer available."
 *   MODEL-D17 — run_reminder_sweep() selected events purely by start_ts, so a
 *     future-dated soft-closed (cancelled) occurrence still emailed every
 *     confirmed attendee "Reminder: <course> is coming up".
 *   REG-D2 — the sweep had no group-parent guard while compute_email_schedule()
 *     explicitly refuses parents, so the two disagreed about who gets reminded.
 *
 * occurrence = event post. These tests drive the public surfaces only
 * (shortcodes, Series::render_archive(), run_reminder_sweep()) and build
 * groups through Occurrences::reconcile(), never through engine internals.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Module;
use Anchor\Events\Registrations;
use Anchor\Events\Series;

/**
 * @group listing
 */
class Test_Listing_Exclusions extends Anchor_Events_TestCase {

	/** @return \Anchor\Events\Occurrences */
	protected function occurrences() {
		return $this->module()->occurrences;
	}

	protected function two_rows() {
		return [
			[ 'date' => '2030-10-23', 'end_date' => '2030-10-24', 'start_time' => '08:00', 'end_time' => '18:00', 'label' => 'October', 'capacity' => 0, 'tier_id' => '' ],
			[ 'date' => '2030-11-13', 'end_date' => '2030-11-14', 'start_time' => '08:00', 'end_time' => '18:00', 'label' => 'November', 'capacity' => 0, 'tier_id' => '' ],
		];
	}

	/**
	 * A reconciled offering parent with two future dates.
	 *
	 * @param string $title Parent title; children become "<title> — October"/"— November".
	 * @return int Parent post id.
	 */
	private function parent_with_two_dates( $title = 'Test Event' ) {
		$parent = $this->make_event( [
			'title'                => $title,
			'type'                 => 'offering',
			'registration_enabled' => true,
			'registration_mode'    => 'free',
			'timezone'             => 'UTC',
		] );
		update_post_meta( $parent, '_anchor_event_offering_dates', $this->two_rows() );
		$this->occurrences()->reconcile( $parent );
		$this->assertTrue( $this->occurrences()->is_group_parent( $parent ) );
		return $parent;
	}

	/**
	 * Soft-close one child the way production does: drop its row from the
	 * parent's offering_dates while it holds a seat, so reconcile() preserves
	 * the post (status manual/cancelled, registration off, occurrence_closed)
	 * instead of trashing it.
	 *
	 * @param int $parent Parent post id.
	 * @param int $index  Index into the date-ascending child list.
	 * @return int The soft-closed child's post id.
	 */
	private function soft_close_child( $parent, $index ) {
		$children = $this->occurrences()->children( $parent, true );
		$this->assertArrayHasKey( $index, $children );
		$closed = (int) $children[ $index ];
		$this->make_seat( $closed );

		$rows = $this->two_rows();
		unset( $rows[ $index ] );
		update_post_meta( $parent, '_anchor_event_offering_dates', array_values( $rows ) );
		$this->occurrences()->reconcile( $parent );

		$this->assertSame( 'publish', get_post_status( $closed ), 'A soft-closed child stays published — that is the whole defect.' );
		$this->assertTrue( (bool) get_post_meta( $closed, '_anchor_event_occurrence_closed', true ) );
		return $closed;
	}

	/* ------------------------------------------------------------------
	 * A. One builder (RENDER-D15 / MODEL-D3).
	 * ------------------------------------------------------------------ */

	public function test_hide_clause_also_excludes_soft_closed_occurrences() {
		$clause = $this->module()->build_hide_clause();

		$this->assertSame( 'AND', $clause['relation'] ?? '', 'The listing exclusion must AND the hide and closed predicates together.' );

		$keys = [];
		foreach ( $clause as $name => $sub ) {
			if ( 'relation' === $name ) {
				continue;
			}
			foreach ( $sub as $inner_name => $inner ) {
				if ( 'relation' === $inner_name ) {
					continue;
				}
				$keys[] = $inner['key'];
			}
		}
		$this->assertContains( '_anchor_event_hide_from_archive', $keys );
		$this->assertContains( '_anchor_event_occurrence_closed', $keys );
	}

	public function test_build_closed_clause_is_gone() {
		$this->assertFalse(
			method_exists( Module::class, 'build_closed_clause' ),
			'build_closed_clause() must be folded into build_hide_clause(), not kept as a second, separately-applied predicate.'
		);
	}

	/* ------------------------------------------------------------------
	 * B. Every listing query gets the exclusion.
	 * ------------------------------------------------------------------ */

	public function test_soft_closed_child_is_absent_from_events_list_shortcode() {
		$parent = $this->parent_with_two_dates();
		$closed = $this->soft_close_child( $parent, 1 );
		$live   = $this->occurrences()->children( $parent );

		$html = do_shortcode( '[events_list limit="50"]' );

		$this->assertStringNotContainsString( get_permalink( $closed ), $html, 'A soft-closed date must not be listed as bookable.' );
		$this->assertStringContainsString( get_permalink( $live[0] ), $html, 'The surviving date must still be listed.' );
	}

	public function test_soft_closed_child_is_absent_from_event_calendar_shortcode() {
		$parent = $this->parent_with_two_dates();
		$closed = $this->soft_close_child( $parent, 1 );

		$html = do_shortcode( '[event_calendar month="2030-11"]' );

		$this->assertStringNotContainsString( get_permalink( $closed ), $html, 'A soft-closed date must not fill a calendar cell.' );
	}

	public function test_hidden_event_is_absent_from_series_archive() {
		$parent = $this->parent_with_two_dates();
		$terms  = wp_get_object_terms( $parent, Series::TAXONOMY );
		$this->assertNotEmpty( $terms, 'reconcile() must have assigned a series term.' );

		$hidden = $this->make_event( [
			'title'             => 'Hidden session',
			'timezone'          => 'UTC',
			'start_date'        => '2030-12-01',
			'end_date'          => '2030-12-01',
			'start_time'        => '09:00',
			'end_time'          => '17:00',
			'hide_from_archive' => '1',
		] );
		$ts = $this->module()->compute_timestamps( $this->module()->get_meta( $hidden ) );
		update_post_meta( $hidden, '_anchor_event_start_ts', $ts['start'] );
		update_post_meta( $hidden, '_anchor_event_end_ts', $ts['end'] );
		wp_set_object_terms( $hidden, [ (int) $terms[0]->term_id ], Series::TAXONOMY );

		$this->go_to( get_term_link( $terms[0] ) );
		$html = $this->module()->series->render_archive();

		$this->assertStringNotContainsString( 'href="' . esc_url( get_permalink( $hidden ) ) . '"', $html, 'The series archive must honour hide_from_archive like every other listing.' );
		$this->assertStringContainsString( 'href="' . esc_url( get_permalink( $parent ) ) . '"', $html, 'The live group row must survive.' );
	}

	/* ------------------------------------------------------------------
	 * B2. ...but a STAFF surface still has to reach a cancelled roster.
	 *
	 * Folding occurrence_closed into build_hide_clause() gave every caller the
	 * public rule, including the two capability-gated surfaces. A soft close
	 * exists precisely BECAUSE the date still holds seats — somebody has to
	 * email or refund those people — so hiding the date from the only two
	 * screens built to manage it made that roster unreachable. Both now pass
	 * $public = false, which drops the occurrence_closed half and keeps the
	 * hide_from_archive half.
	 * ------------------------------------------------------------------ */

	/** Log in as somebody who passes the edit_others_posts gate on both surfaces. */
	private function login_as_staff() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertTrue( current_user_can( 'edit_others_posts' ) );
	}

	public function test_staff_clause_keeps_soft_closed_dates_and_still_drops_hidden_ones() {
		$staff  = $this->module()->build_hide_clause( false );
		$public = $this->module()->build_hide_clause();

		$keys = [];
		foreach ( $staff as $name => $sub ) {
			if ( 'relation' === $name ) {
				continue;
			}
			$keys[] = $sub['key'];
		}
		$this->assertSame( [ '_anchor_event_hide_from_archive', '_anchor_event_hide_from_archive' ], $keys, 'The staff clause is the hide_from_archive half only.' );
		$this->assertNotEquals( $public, $staff, 'The public clause must still carry the occurrence_closed half.' );
	}

	public function test_soft_closed_child_is_listed_by_the_registrants_shortcode_for_staff() {
		$this->login_as_staff();

		$parent = $this->parent_with_two_dates();
		$closed = $this->soft_close_child( $parent, 1 );
		$live   = $this->occurrences()->children( $parent );

		$html = do_shortcode( '[event_registrants_list limit="50"]' );

		$this->assertStringContainsString(
			esc_html( get_the_title( $closed ) ),
			$html,
			'A cancelled date must stay listed for staff — its roster is the reason it was soft-closed rather than deleted.'
		);
		$this->assertStringContainsString( 'attendee@example.test', $html, 'The stranded registrant has to be reachable, not just the row.' );
		$this->assertStringContainsString( esc_html( get_the_title( $live[0] ) ), $html, 'The live date is still listed too.' );
	}

	public function test_soft_closed_child_is_listed_by_the_front_end_event_manager_for_staff() {
		$this->login_as_staff();

		$parent = $this->parent_with_two_dates();
		$closed = $this->soft_close_child( $parent, 1 );
		$live   = $this->occurrences()->children( $parent );

		$html = do_shortcode( '[event_manager limit="50"]' );

		$this->assertStringContainsString(
			esc_html( get_the_title( $closed ) ),
			$html,
			'The Events Manager console must still show a cancelled date so its roster can be emailed or refunded.'
		);
		$this->assertStringContainsString( esc_html( get_the_title( $live[0] ) ), $html );
	}

	/** The staff exemption is for occurrence_closed ONLY — hide_from_archive still binds. */
	public function test_hidden_event_stays_hidden_from_the_staff_surfaces() {
		$this->login_as_staff();

		$hidden = $this->make_event( [
			'title'             => 'Hidden staff session',
			'timezone'          => 'UTC',
			'start_date'        => '2030-12-01',
			'start_time'        => '09:00',
			'hide_from_archive' => '1',
		] );
		update_post_meta( $hidden, '_anchor_event_start_ts', strtotime( '2030-12-01 09:00 UTC' ) );

		$this->assertStringNotContainsString( 'Hidden staff session', do_shortcode( '[event_registrants_list limit="50"]' ) );
		$this->assertStringNotContainsString( 'Hidden staff session', do_shortcode( '[event_manager limit="50"]' ) );
	}

	/** ...and the public list is unchanged: the cancelled date is still absent there. */
	public function test_public_list_still_excludes_the_soft_closed_child_while_staff_see_it() {
		$parent = $this->parent_with_two_dates();
		$closed = $this->soft_close_child( $parent, 1 );

		$public = do_shortcode( '[events_list limit="50"]' );
		$this->assertStringNotContainsString( get_permalink( $closed ), $public );

		$this->login_as_staff();
		$this->assertStringContainsString( esc_html( get_the_title( $closed ) ), do_shortcode( '[event_registrants_list limit="50"]' ) );
	}

	/* ------------------------------------------------------------------
	 * C. The reminder sweep (MODEL-D17, REG-D2).
	 * ------------------------------------------------------------------ */

	/** Capture every wp_mail() recipient without sending. */
	private function capture_mail( array &$sent ) {
		add_filter( 'pre_wp_mail', function ( $null, $atts ) use ( &$sent ) {
			$sent[] = $atts['to'];
			return true;
		}, 10, 2 );
	}

	/**
	 * Capture every wp_mail() subject without sending — the roster digest goes
	 * to one organizer address for every event, so the subject ("Final roster
	 * for {event_title}") is what says WHICH event was digested.
	 */
	private function capture_mail_subjects( array &$sent ) {
		add_filter( 'pre_wp_mail', function ( $null, $atts ) use ( &$sent ) {
			$sent[] = $atts['subject'];
			return true;
		}, 10, 2 );
	}

	private function configure_sweep( array $overrides ) {
		update_option( 'anchor_events_settings', array_merge(
			$this->module()->get_settings(),
			$overrides
		), false );
	}

	private function enable_reminders() {
		$this->configure_sweep( [ 'reminder_enabled' => true, 'reminder_offsets' => '1', 'organizer_roster_email' => false ] );
	}

	public function test_reminder_sweep_skips_group_parent_and_cancelled_date() {
		$this->enable_reminders();

		$parent   = $this->parent_with_two_dates();
		$children = $this->occurrences()->children( $parent, true );
		$due      = time() + 20 * HOUR_IN_SECONDS;

		// Move the first date (and the parent) into the 1-day reminder window.
		update_post_meta( $children[0], '_anchor_event_start_ts', $due );
		update_post_meta( $parent, '_anchor_event_start_ts', $due );

		// ...and cancel that date the way soft_close() does.
		update_post_meta( $children[0], '_anchor_event_status_mode', 'manual' );
		update_post_meta( $children[0], '_anchor_event_status', 'cancelled' );

		$this->make_seat( $children[0], [ 'status' => Registrations::STATUS_CONFIRMED, 'email' => 'a@example.com' ] );
		$this->make_seat( $parent, [ 'status' => Registrations::STATUS_CONFIRMED, 'email' => 'b@example.com' ] );

		$sent = [];
		$this->capture_mail( $sent );

		$this->module()->run_reminder_sweep();

		$this->assertSame( [], $sent, 'Neither a cancelled date nor a group parent may be reminded.' );
	}

	/**
	 * The OTHER half of REG-D2: maybe_send_scheduled_roster() runs inside the
	 * same per-event loop, so a group parent used to earn the organizer a
	 * digest of its own ("0 confirmed") on top of one digest per child, and a
	 * cancelled date still digested its stranded roster. Both guards must sit
	 * ABOVE the roster pass, not just above the reminder pass — this test fails
	 * if the parent `continue` is moved below maybe_send_scheduled_roster().
	 */
	public function test_roster_digest_skips_group_parent_and_cancelled_date() {
		$this->configure_sweep( [
			'reminder_enabled'       => false, // isolate the roster pass
			'organizer_roster_email' => true,
			'roster_auto_offset'     => 1,
			'organizer_email'        => 'organizer@example.com',
		] );

		$parent   = $this->parent_with_two_dates( 'Container Course' );
		$children = $this->occurrences()->children( $parent, true );
		$due      = time() + 20 * HOUR_IN_SECONDS;

		// Parent + BOTH dates land inside the 1-day roster window.
		foreach ( [ $parent, $children[0], $children[1] ] as $id ) {
			update_post_meta( $id, '_anchor_event_start_ts', $due );
		}

		// The November date is cancelled the way soft_close() writes it.
		update_post_meta( $children[1], '_anchor_event_status_mode', 'manual' );
		update_post_meta( $children[1], '_anchor_event_status', 'cancelled' );

		$this->make_seat( $parent, [ 'status' => Registrations::STATUS_CONFIRMED, 'email' => 'p@example.com' ] );
		$this->make_seat( $children[0], [ 'status' => Registrations::STATUS_CONFIRMED, 'email' => 'a@example.com' ] );
		$this->make_seat( $children[1], [ 'status' => Registrations::STATUS_CONFIRMED, 'email' => 'b@example.com' ] );

		$subjects = [];
		$this->capture_mail_subjects( $subjects );

		$this->module()->run_reminder_sweep();

		$this->assertSame(
			[ 'Final roster for Container Course — October' ],
			$subjects,
			'Only the live date earns an organizer digest — never the container, never a cancelled date.'
		);
		$this->assertSame( '', get_post_meta( $parent, '_anchor_event_roster_sent', true ), 'A parent must not even be marked as digested.' );
	}

	public function test_reminder_sweep_still_sends_for_a_live_single_event() {
		$this->enable_reminders();

		$event = $this->make_event( [
			'title'      => 'Live course',
			'timezone'   => 'UTC',
			'start_date' => gmdate( 'Y-m-d', time() + 20 * HOUR_IN_SECONDS ),
			'start_time' => '09:00',
		] );
		update_post_meta( $event, '_anchor_event_start_ts', time() + 20 * HOUR_IN_SECONDS );
		$this->make_seat( $event, [ 'status' => Registrations::STATUS_CONFIRMED, 'email' => 'live@example.com' ] );

		$sent = [];
		$this->capture_mail( $sent );

		$this->module()->run_reminder_sweep();

		$this->assertSame( [ 'live@example.com' ], $sent, 'The guards must not silence reminders for ordinary live events.' );
	}
}
