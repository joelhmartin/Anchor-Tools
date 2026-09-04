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

	/**
	 * …and when the value it clears is AUTHORED content, the author hears
	 * about it. The delete itself is the intended semantics; being silent
	 * about it is how a date quietly stopped asking for a licence number.
	 */
	public function test_deleting_a_childs_authored_email_override_queues_a_notice() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$parent_id = $this->make_parent();
		$child_id  = $this->one_child( $parent_id );

		// Authored ON THE DATE: the parent has no subject of its own.
		update_post_meta( $child_id, '_anchor_event_email_subject_confirmation', 'See you Tuesday' );
		$this->assertFalse( metadata_exists( 'post', $parent_id, '_anchor_event_email_subject_confirmation' ) );

		$this->occurrences()->reconcile( $parent_id );

		$this->assertFalse(
			metadata_exists( 'post', $child_id, '_anchor_event_email_subject_confirmation' ),
			'Inheritance stays symmetric: the parent has none, so the date has none.'
		);
		$this->assertContains(
			'inherited_child_data_removed',
			wp_list_pluck( $this->module()->queued_group_notices( $parent_id ), 'code' ),
			'…but the author is told that it went.'
		);
	}

	/** A child's own registration questions count too. */
	public function test_deleting_a_childs_authored_questions_queues_a_notice() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$parent_id = $this->make_parent();
		$child_id  = $this->one_child( $parent_id );
		update_post_meta( $child_id, Module::QUESTIONS_META, [
			[ 'key' => 'license', 'label' => 'License number', 'type' => 'text', 'required' => true ],
		] );

		$this->occurrences()->reconcile( $parent_id );

		$this->assertFalse( metadata_exists( 'post', $child_id, Module::QUESTIONS_META ) );
		$this->assertContains(
			'inherited_child_data_removed',
			wp_list_pluck( $this->module()->queued_group_notices( $parent_id ), 'code' )
		);
	}

	/** An ordinary reconcile that deletes nothing says nothing. */
	public function test_no_notice_when_the_reconcile_deletes_no_authored_data() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$parent_id = $this->make_parent( [ 'venue' => 'Main Hall' ] );
		$child_id  = $this->one_child( $parent_id );

		// A SHARED schema fact cleared on the parent still propagates silently:
		// it is the parent's own value either way, never the date's.
		delete_post_meta( $parent_id, '_anchor_event_venue' );
		$this->occurrences()->reconcile( $parent_id );

		$this->assertFalse( metadata_exists( 'post', $child_id, '_anchor_event_venue' ) );
		$this->assertNotContains(
			'inherited_child_data_removed',
			wp_list_pluck( $this->module()->queued_group_notices( $parent_id ), 'code' ),
			'Only authored questions/email wording are worth interrupting a save for.'
		);
	}

	/* ------------------------------------------------------------------
	 * 3b. The save that writes a value is the save that propagates it.
	 *
	 * Both save paths reconciled BEFORE their email/questions sub-savers had
	 * run, so sync_shared_meta() copied down the parent's PREVIOUS rows and the
	 * new ones sat one post away until something reconciled again. An author
	 * changed the confirmation subject, opened an occurrence, and saw the old
	 * wording — which reads as "inheritance is broken", not "save twice".
	 * ------------------------------------------------------------------ */

	/** The classic metabox: a template edited in this request reaches the child. */
	public function test_metabox_save_propagates_the_email_template_it_just_wrote() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$parent_id = $this->make_parent();
		$child_id  = $this->one_child( $parent_id );

		$_POST = [
			Module::NONCE                      => wp_create_nonce( Module::NONCE ),
			'anchor_event_start_date'          => '2027-05-04',
			'anchor_event_type'                => 'offering',
			'anchor_event_registration_mode'   => 'free',
			'anchor_event_offering_dates'      => $this->one_row(),
			'anchor_email_tpl_confirmation'    => '<p>Booked: {event_title}</p>',
		];
		$this->module()->save_meta( $parent_id );
		unset( $_POST );

		$this->assertSame(
			get_post_meta( $parent_id, '_anchor_event_email_tpl_confirmation', true ),
			get_post_meta( $child_id, '_anchor_event_email_tpl_confirmation', true ),
			'One save must be enough for the parent and its dates to agree.'
		);
		$this->assertStringContainsString(
			'Booked:',
			get_post_meta( $child_id, '_anchor_event_email_tpl_confirmation', true )
		);
	}

	/** …and the front-end manager form, which owns the subject/intro fields. */
	public function test_manager_save_propagates_the_email_subject_it_just_wrote() {
		$parent_id = $this->make_parent();
		$child_id  = $this->one_child( $parent_id );

		$_POST = [
			'anchor_event_type'                        => 'offering',
			'anchor_event_registration_mode'           => 'free',
			'anchor_event_offering_dates'              => $this->one_row(),
			'anchor_event_email_subject_confirmation'  => 'Your seat at the Workshop',
			'anchor_event_questions'                   => [
				[ 'label' => 'Dietary needs', 'type' => 'text', 'required' => '0' ],
			],
		];

		$method = new ReflectionMethod( Module::class, 'save_event_manager_fields' );
		$method->setAccessible( true );
		$method->invoke( $this->module(), $parent_id, '2027-05-04', $this->module()->registration_mode( $parent_id ) );
		unset( $_POST );

		$this->assertSame(
			'Your seat at the Workshop',
			get_post_meta( $child_id, '_anchor_event_email_subject_confirmation', true ),
			'The subject written by this save must reach the date this save reconciled.'
		);
		$this->assertSame(
			get_post_meta( $parent_id, Module::QUESTIONS_META, true ),
			get_post_meta( $child_id, Module::QUESTIONS_META, true ),
			'…and so must the questions it wrote.'
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
