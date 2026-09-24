<?php
/**
 * Speakers <-> Anchor Events integration (Task 10).
 *
 * @package Anchor\Events\Tests
 */

/** @group speakers */
class Test_Speakers_Events extends Anchor_Events_TestCase {

	public function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\Anchor\\Events\\Module' ) ) {
			$this->markTestSkipped( 'The events module is not active in this run.' );
		}
	}

	public function test_inherited_keys_filter_includes_speakers() {
		$keys = apply_filters( 'anchor_events_inherited_keys', \Anchor\Events\Occurrences::INHERITED_KEYS );
		$this->assertContains( '_anchor_event_speaker_ids', $keys );
	}

	public function test_schema_performer_added() {
		$s  = self::factory()->post->create( [ 'post_type' => 'anchor_speaker', 'post_title' => 'Dr. Steven Olmos', 'post_status' => 'publish' ] );
		Anchor_Speaker_Meta::save( $s, [ 'title' => 'Founder' ] );
		$ev = self::factory()->post->create( [ 'post_type' => 'event' ] );
		update_post_meta( $ev, '_anchor_event_speaker_ids', [ $s ] );
		$node = apply_filters( 'anchor_events_schema_node', [ '@type' => 'Event' ], $ev );
		$this->assertSame( 'Person', $node['performer'][0]['@type'] );
		$this->assertSame( 'Dr. Steven Olmos', $node['performer'][0]['name'] );
		$this->assertSame( 'Founder', $node['performer'][0]['jobTitle'] );
	}

	public function test_child_without_own_list_uses_parent() {
		$s      = self::factory()->post->create( [ 'post_type' => 'anchor_speaker', 'post_status' => 'publish' ] );
		$parent = self::factory()->post->create( [ 'post_type' => 'event' ] );
		$child  = self::factory()->post->create( [ 'post_type' => 'event' ] );
		update_post_meta( $parent, '_anchor_event_speaker_ids', [ $s ] );
		update_post_meta( $child, '_anchor_event_group_role', 'child' );
		update_post_meta( $child, '_anchor_event_group_id', $parent );
		$this->assertSame( [ $s ], Anchor_Speakers_Module::event_speaker_ids( $child ) );
	}

	/**
	 * Reconcile test modeled on Test_Inheritance::make_parent()/one_child() in
	 * tests/test-inheritance.php: an `offering` parent with two dates, the new
	 * key set on the parent, and a reconcile must copy it to both children
	 * exactly the way every other INHERITED_KEYS entry does.
	 */
	public function test_speaker_ids_inherit_like_existing_keys() {
		$speaker_a = self::factory()->post->create( [ 'post_type' => 'anchor_speaker', 'post_status' => 'publish' ] );
		$speaker_b = self::factory()->post->create( [ 'post_type' => 'anchor_speaker', 'post_status' => 'publish' ] );

		$parent_id = $this->make_event( [ 'title' => 'Workshop' ] );
		update_post_meta(
			$parent_id,
			'_anchor_event_offering_dates',
			[
				[ 'date' => '2027-06-01', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Day 1', 'capacity' => 10 ],
				[ 'date' => '2027-06-02', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Day 2', 'capacity' => 10 ],
			]
		);
		update_post_meta( $parent_id, '_anchor_event_speaker_ids', [ $speaker_a, $speaker_b ] );

		$occurrences = \Anchor\Events\Module::instance()->occurrences;
		$children    = $occurrences->reconcile( $parent_id );
		$this->assertCount( 2, $children, 'Expected two occurrence children.' );

		foreach ( $children as $child_id ) {
			$this->assertSame(
				[ $speaker_a, $speaker_b ],
				get_post_meta( (int) $child_id, '_anchor_event_speaker_ids', true ),
				'Every occurrence child must inherit the parent speaker list, same as any other inherited key.'
			);
		}

		// A cleared parent value propagates, same as test_clearing_the_parents_venue_clears_the_childs().
		delete_post_meta( $parent_id, '_anchor_event_speaker_ids' );
		$occurrences->reconcile( $parent_id );

		foreach ( $children as $child_id ) {
			$this->assertFalse(
				metadata_exists( 'post', (int) $child_id, '_anchor_event_speaker_ids' ),
				'Clearing the parent speaker list must clear it on the child, not leave the old one behind.'
			);
		}
	}
}
