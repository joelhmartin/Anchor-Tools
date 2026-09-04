<?php
/**
 * Parent -> child meta inheritance (MODEL-D7, MODEL-D37).
 *
 * Inheritance used to be defined as "every key in get_meta_defaults() minus
 * two exclusion lists", read through get_meta(). That had two consequences
 * these tests pin against:
 *
 *   1. Every event meta key OUTSIDE the schema was invisible to the copy, so
 *      the registration questions and the whole per-event email override set
 *      never reached an occurrence child — a child sent the site-wide
 *      confirmation while its parent's page advertised a custom one.
 *   2. get_meta() fills missing keys with the schema DEFAULT, and the copy
 *      wrote that default down as a REAL meta row on the child. The seven
 *      production children were the only posts on the site carrying
 *      `_anchor_event_registration_type = internal`, and eight events carried
 *      a `_anchor_event_timezone = "UTC-6"` string nobody ever authored.
 *
 * Inheritance is now an explicit allow-list (Occurrences::INHERITED_KEYS plus
 * the enumerated non-schema keys, filterable via
 * `anchor_events_inherited_meta_keys`) and copies only keys the parent has a
 * real row for.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Module;

/**
 * @group occurrences
 */
class Test_Inheritance extends Anchor_Events_TestCase {

	/** @return \Anchor\Events\Occurrences */
	protected function occurrences() {
		return $this->module()->occurrences;
	}

	protected function one_row() {
		return [
			[ 'date' => '2027-05-04', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Session A', 'capacity' => 10 ],
		];
	}

	/**
	 * A group parent carrying ONLY the meta the test names — no timezone, no
	 * registration_type — so a synthesized default is visible as a new row.
	 *
	 * @param array $meta Extra parent meta (keys WITHOUT the prefix).
	 * @return int Parent event id.
	 */
	protected function make_parent( array $meta = [] ) {
		$parent_id = $this->make_event( array_merge( [ 'title' => 'Workshop' ], $meta ) );
		update_post_meta( $parent_id, '_anchor_event_offering_dates', $this->one_row() );
		return $parent_id;
	}

	/** Reconcile and return the single child. */
	protected function one_child( $parent_id ) {
		$live = $this->occurrences()->reconcile( $parent_id );
		$this->assertCount( 1, $live, 'Expected exactly one occurrence child.' );
		return (int) $live[0];
	}

	/* ------------------------------------------------------------------
	 * 1. Non-schema keys now inherit
	 * ------------------------------------------------------------------ */

	public function test_parent_registration_questions_reach_the_child() {
		$questions = [
			[ 'id' => 'license', 'label' => 'License number', 'type' => 'text', 'required' => true ],
		];
		$parent_id = $this->make_parent();
		update_post_meta( $parent_id, Module::QUESTIONS_META, $questions );

		$child_id = $this->one_child( $parent_id );

		$this->assertSame(
			$questions,
			get_post_meta( $child_id, Module::QUESTIONS_META, true ),
			'The parent\'s registration questions must reach every occurrence child.'
		);
	}

	public function test_parent_email_subject_override_reaches_the_child() {
		$parent_id = $this->make_parent();
		update_post_meta( $parent_id, '_anchor_event_email_subject_confirmation', 'Your seat at the Workshop' );

		$child_id = $this->one_child( $parent_id );

		$this->assertSame(
			'Your seat at the Workshop',
			get_post_meta( $child_id, '_anchor_event_email_subject_confirmation', true )
		);
		// And the accessor the senders actually read must resolve it, so the
		// child's confirmation goes out with the parent's wording rather than
		// the site-wide fallback.
		$this->assertSame(
			'Your seat at the Workshop',
			$this->module()->get_email_field( $child_id, 'confirmation', 'subject', 'SITE DEFAULT' )
		);
	}

	public function test_parent_email_switch_and_sender_overrides_reach_the_child() {
		$parent_id = $this->make_parent();
		update_post_meta( $parent_id, '_anchor_event_email_off_reminder', '1' );
		update_post_meta( $parent_id, '_anchor_event_email_from_address', 'courses@example.test' );

		$child_id = $this->one_child( $parent_id );

		$this->assertFalse(
			$this->module()->is_email_enabled( $child_id, 'reminder' ),
			'A reminder switched off on the parent must stay off on every occurrence.'
		);
		$headers = $this->module()->email_headers( [], $child_id );
		$this->assertContains( 'From: courses@example.test', $headers );
	}

	public function test_inherited_keys_are_filterable() {
		add_filter(
			'anchor_events_inherited_meta_keys',
			static function ( $keys ) {
				$keys[] = '_anchor_event_custom_thing';
				return $keys;
			}
		);

		$parent_id = $this->make_parent();
		update_post_meta( $parent_id, '_anchor_event_custom_thing', 'copied' );

		$child_id = $this->one_child( $parent_id );

		$this->assertSame( 'copied', get_post_meta( $child_id, '_anchor_event_custom_thing', true ) );
	}

	/* ------------------------------------------------------------------
	 * 2. No synthesized default is ever written as a real row
	 * ------------------------------------------------------------------ */

	public function test_no_timezone_row_is_minted_on_a_child_when_the_parent_has_none() {
		$parent_id = $this->make_parent();
		$this->assertFalse( metadata_exists( 'post', $parent_id, '_anchor_event_timezone' ), 'Precondition: the parent has no timezone row.' );

		$child_id = $this->one_child( $parent_id );

		$this->assertFalse(
			metadata_exists( 'post', $child_id, '_anchor_event_timezone' ),
			'A child must not be given a timezone row its parent never authored.'
		);
	}

	public function test_no_registration_type_row_is_minted_on_a_child() {
		$parent_id = $this->make_parent();
		$this->assertFalse( metadata_exists( 'post', $parent_id, '_anchor_event_registration_type' ) );

		$child_id = $this->one_child( $parent_id );

		$this->assertFalse(
			metadata_exists( 'post', $child_id, '_anchor_event_registration_type' ),
			'registration_type=internal is a read-time default, not an authored value.'
		);
	}

	/**
	 * The site has no timezone_string and a -6 gmt_offset (production's exact
	 * shape). get_meta() used to mint the literal "UTC-6" — a string
	 * DateTimeZone rejects — as the default; it is now '' meaning "the site's
	 * zone", which normalize_timezone() resolves.
	 */
	public function test_meta_default_timezone_is_the_empty_site_zone() {
		update_option( 'timezone_string', '' );
		update_option( 'gmt_offset', -6 );

		$event_id = $this->make_event();

		$this->assertSame( '', $this->module()->get_meta( $event_id )['timezone'] );
	}

	/* ------------------------------------------------------------------
	 * 3. A cleared parent value propagates
	 * ------------------------------------------------------------------ */

	public function test_clearing_the_parents_venue_clears_the_childs() {
		$parent_id = $this->make_parent( [ 'venue' => 'Main Hall' ] );
		$child_id  = $this->one_child( $parent_id );
		$this->assertSame( 'Main Hall', get_post_meta( $child_id, '_anchor_event_venue', true ), 'Precondition: the venue inherited.' );

		delete_post_meta( $parent_id, '_anchor_event_venue' );
		$this->occurrences()->reconcile( $parent_id );

		$this->assertFalse(
			metadata_exists( 'post', $child_id, '_anchor_event_venue' ),
			'Clearing an inherited value on the parent must clear it on the child, not leave the old one behind.'
		);
	}

	/* ------------------------------------------------------------------
	 * 4. Per-occurrence keys are still never overwritten
	 * ------------------------------------------------------------------ */

	public function test_per_occurrence_keys_survive_a_reconcile() {
		$parent_id = $this->make_parent( [ 'registration_enabled' => true ] );
		$child_id  = $this->one_child( $parent_id );

		// This one date sold out and was closed by hand; the group as a whole
		// is still open.
		update_post_meta( $child_id, '_anchor_event_registration_enabled', false );
		update_post_meta( $child_id, '_anchor_event_sold_out', true );

		$this->occurrences()->reconcile( $parent_id );

		$this->assertFalse( (bool) get_post_meta( $child_id, '_anchor_event_registration_enabled', true ) );
		$this->assertTrue( (bool) get_post_meta( $child_id, '_anchor_event_sold_out', true ) );
	}
}
