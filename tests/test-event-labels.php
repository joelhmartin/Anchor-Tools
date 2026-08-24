<?php
/**
 * Event-level typed labels ("2 Day Course", "14 CE Credits", ...).
 *
 * Covers the `labels` meta key end to end: the shared sanitizer's vocabulary
 * clamping and empty-row dropping, both authoring save paths (classic metabox
 * and the front-end manager form), the theme-facing accessors, occurrence-child
 * inheritance, and output escaping on the card renderer.
 *
 * Duration is deliberately a free-text LABEL, not a derived value: an event
 * spanning 2026-03-05 -> 2026-03-06 could be a "1.5 Day Course" or a "2 Day
 * Course", and "2.5 Day Course" can never be computed from dates at all.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Module;

/**
 * @group event-labels
 */
class Test_Event_Labels extends Anchor_Events_TestCase {

	/** @var int */
	private $admin_id;

	public function set_up() {
		parent::set_up();
		// Both save paths require current_user_can( 'edit_post', $post_id ).
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
	 * A metabox POST carrying label rows, shaped exactly as the labels
	 * repeater in render_meta_box() submits them.
	 */
	private function post_payload( array $overrides = [] ) {
		return array_merge(
			[
				Module::NONCE               => wp_create_nonce( Module::NONCE ),
				'anchor_event_start_date'   => '2027-05-03',
				'anchor_event_labels'       => [
					[ 'key' => 'duration', 'label' => '', 'value' => '2 Day Course' ],
					[ 'key' => 'credits', 'label' => '', 'value' => '14 CE Credits' ],
				],
			],
			$overrides
		);
	}

	/* ------------------------------------------------------------------
	 * Sanitizer: vocabulary, empty rows, custom captions.
	 * ------------------------------------------------------------------ */

	/** RED-before-GREEN baseline: save_meta() persists label rows in author order. */
	public function test_save_meta_persists_label_rows_in_order() {
		$event_id = $this->make_event();

		$_POST = $this->post_payload();
		$this->module()->save_meta( $event_id );

		$stored = get_post_meta( $event_id, '_anchor_event_labels', true );

		$this->assertIsArray( $stored );
		$this->assertCount( 2, $stored );
		$this->assertSame( 'duration', $stored[0]['key'] );
		$this->assertSame( '2 Day Course', $stored[0]['value'] );
		$this->assertSame( 'credits', $stored[1]['key'] );
		$this->assertSame( '14 CE Credits', $stored[1]['value'] );
	}

	/** A row with an empty value is dropped — same rule the sessions repeater uses for an empty date. */
	public function test_rows_with_empty_value_are_dropped() {
		$event_id = $this->make_event();

		$_POST = $this->post_payload(
			[
				'anchor_event_labels' => [
					[ 'key' => 'duration', 'label' => '', 'value' => '2 Day Course' ],
					[ 'key' => 'format', 'label' => '', 'value' => '' ],
					[ 'key' => 'level', 'label' => '', 'value' => '   ' ],
				],
			]
		);
		$this->module()->save_meta( $event_id );

		$stored = get_post_meta( $event_id, '_anchor_event_labels', true );

		$this->assertCount( 1, $stored, 'Empty and whitespace-only values must not be persisted.' );
		$this->assertSame( 'duration', $stored[0]['key'] );
	}

	/** An unknown key falls back to `custom` rather than being persisted as-is. */
	public function test_unknown_key_is_clamped_to_custom() {
		$event_id = $this->make_event();

		$_POST = $this->post_payload(
			[
				'anchor_event_labels' => [
					[ 'key' => 'not-a-real-key', 'label' => 'Cohort', 'value' => 'Spring 2027' ],
				],
			]
		);
		$this->module()->save_meta( $event_id );

		$stored = get_post_meta( $event_id, '_anchor_event_labels', true );

		$this->assertSame( 'custom', $stored[0]['key'] );
		$this->assertSame( 'Cohort', $stored[0]['label'], 'A custom row keeps its author-typed caption.' );
		$this->assertSame( 'Spring 2027', $stored[0]['value'] );
	}

	/**
	 * A known key never stores a caption. Captions for the fixed vocabulary are
	 * resolved at render time so they stay translatable — storing a translated
	 * string would freeze it in whatever locale the author happened to save in.
	 */
	public function test_known_key_does_not_store_a_caption() {
		$event_id = $this->make_event();

		$_POST = $this->post_payload(
			[
				'anchor_event_labels' => [
					[ 'key' => 'duration', 'label' => 'Ignore me', 'value' => '2 Day Course' ],
				],
			]
		);
		$this->module()->save_meta( $event_id );

		$stored = get_post_meta( $event_id, '_anchor_event_labels', true );

		$this->assertSame( '', $stored[0]['label'] );
	}

	/** Non-array junk in the repeater slot is ignored, not fatal. */
	public function test_malformed_rows_are_ignored() {
		$event_id = $this->make_event();

		$_POST = $this->post_payload(
			[
				'anchor_event_labels' => [
					'not-an-array',
					[ 'key' => 'duration', 'label' => '', 'value' => '2 Day Course' ],
				],
			]
		);
		$this->module()->save_meta( $event_id );

		$stored = get_post_meta( $event_id, '_anchor_event_labels', true );

		$this->assertCount( 1, $stored );
	}

	/* ------------------------------------------------------------------
	 * Theme-facing accessors.
	 * ------------------------------------------------------------------ */

	/** anchor_event_label() returns the value for a key, '' when absent. */
	public function test_label_accessor_returns_value_or_empty_string() {
		$event_id = $this->make_event();

		$_POST = $this->post_payload();
		$this->module()->save_meta( $event_id );

		$this->assertSame( '2 Day Course', anchor_event_label( $event_id, 'duration' ) );
		$this->assertSame( '14 CE Credits', anchor_event_label( $event_id, 'credits' ) );
		$this->assertSame( '', anchor_event_label( $event_id, 'level' ) );
		$this->assertSame( '', anchor_event_label( $event_id, 'bogus' ) );
	}

	/** anchor_event_labels() resolves a display caption for every row. */
	public function test_labels_accessor_resolves_captions() {
		$event_id = $this->make_event();

		$_POST = $this->post_payload(
			[
				'anchor_event_labels' => [
					[ 'key' => 'duration', 'label' => '', 'value' => '2 Day Course' ],
					[ 'key' => 'custom', 'label' => 'Cohort', 'value' => 'Spring 2027' ],
				],
			]
		);
		$this->module()->save_meta( $event_id );

		$rows = anchor_event_labels( $event_id );

		$this->assertCount( 2, $rows );
		$this->assertSame( 'Duration', $rows[0]['caption'], 'Known keys take their caption from the vocabulary.' );
		$this->assertSame( 'Cohort', $rows[1]['caption'], 'Custom rows use the author-typed caption.' );
	}

	/** An event with no labels yields an empty array, never null. */
	public function test_labels_accessor_on_unlabelled_event_returns_empty_array() {
		$event_id = $this->make_event();

		$this->assertSame( [], anchor_event_labels( $event_id ) );
		$this->assertSame( '', anchor_event_label( $event_id, 'duration' ) );
	}

	/** The anchor_events_labels filter can inject or rewrite rows. */
	public function test_labels_filter_applies() {
		$event_id = $this->make_event();

		$_POST = $this->post_payload();
		$this->module()->save_meta( $event_id );

		add_filter(
			'anchor_events_labels',
			static function ( $rows ) {
				$rows[] = [ 'key' => 'format', 'label' => '', 'value' => 'Hands-on', 'caption' => 'Format' ];
				return $rows;
			}
		);

		$this->assertSame( 'Hands-on', anchor_event_label( $event_id, 'format' ) );
	}

	/* ------------------------------------------------------------------
	 * Front-end manager form save path.
	 * ------------------------------------------------------------------ */

	/**
	 * The manager form must persist labels identically to the metabox — both
	 * paths route through labels_input(), the same way they share
	 * sanitize_event_type_input(), so they cannot drift.
	 *
	 * save_event_manager_fields() is protected (it writes meta from $_POST and
	 * must not be a public entry point), so it is reached by reflection exactly
	 * as test-event-manager-save.php does.
	 */
	public function test_manager_form_save_persists_labels() {
		$event_id = $this->make_event();

		$_POST = $this->post_payload(
			[
				'anchor_event_labels' => [
					[ 'key' => 'duration', 'label' => '', 'value' => '2.5 Day Course' ],
					[ 'key' => 'bogus', 'label' => 'Cohort', 'value' => 'Spring 2027' ],
					[ 'key' => 'level', 'label' => '', 'value' => '' ],
				],
			]
		);

		$method = new ReflectionMethod( Module::class, 'save_event_manager_fields' );
		$method->setAccessible( true );
		$method->invoke( $this->module(), $event_id, '2027-05-03', 'free' );

		$stored = get_post_meta( $event_id, '_anchor_event_labels', true );

		$this->assertCount( 2, $stored, 'The empty-value row must be dropped on this path too.' );
		$this->assertSame( '2.5 Day Course', $stored[0]['value'] );
		$this->assertSame( 'custom', $stored[1]['key'], 'Unknown keys clamp identically on both save paths.' );
		$this->assertSame( 'Cohort', $stored[1]['label'] );
	}

	/* ------------------------------------------------------------------
	 * Occurrence inheritance.
	 * ------------------------------------------------------------------ */

	/**
	 * Occurrences::sync_shared_meta() is a deny-list, so `labels` propagates to
	 * every offering child with no engine change. "2 Day Course" describes each
	 * date of a pick-one offering, so inheriting is the correct default.
	 */
	public function test_offering_children_inherit_parent_labels() {
		$event_id = $this->make_event();

		$_POST = $this->post_payload(
			[
				'anchor_event_start_date'       => '2027-04-01',
				'anchor_event_type'             => 'offering',
				'anchor_event_registration_mode' => 'free',
				'anchor_event_offering_dates'   => [
					[ 'date' => '2027-04-01', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'A', 'capacity' => 10 ],
					[ 'date' => '2027-04-08', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'B', 'capacity' => 10 ],
				],
			]
		);
		$this->module()->save_meta( $event_id );

		$children = $this->occurrences()->children( $event_id );
		$this->assertCount( 2, $children );

		foreach ( $children as $child ) {
			$child_id = is_object( $child ) ? (int) $child->ID : (int) $child;
			$this->assertSame(
				'2 Day Course',
				anchor_event_label( $child_id, 'duration' ),
				'Each occurrence child must inherit the parent event-level labels.'
			);
		}
	}

	/* ------------------------------------------------------------------
	 * Rendering.
	 * ------------------------------------------------------------------ */

	/** The card emits a per-key class so a theme can position one badge specifically. */
	public function test_card_renders_labels_with_per_key_class() {
		$event_id = $this->make_event();

		$_POST = $this->post_payload();
		$this->module()->save_meta( $event_id );

		$html = $this->module()->render_event_card( $event_id, 'test' );

		$this->assertStringContainsString( 'anchor-event-labels', $html );
		$this->assertStringContainsString( 'anchor-event-label-duration', $html );
		$this->assertStringContainsString( '2 Day Course', $html );
		$this->assertStringContainsString( 'anchor-event-label-credits', $html );
	}

	/** A card for an unlabelled event emits no labels markup at all. */
	public function test_card_omits_labels_markup_when_none() {
		$event_id = $this->make_event();

		$html = $this->module()->render_event_card( $event_id, 'test' );

		$this->assertStringNotContainsString( 'anchor-event-labels', $html );
	}

	/** Label values are plain text and must be escaped on output. */
	public function test_label_values_are_escaped_on_output() {
		$event_id = $this->make_event();

		$_POST = $this->post_payload(
			[
				'anchor_event_labels' => [
					[ 'key' => 'custom', 'label' => 'X', 'value' => '<script>alert(1)</script>' ],
				],
			]
		);
		$this->module()->save_meta( $event_id );

		$html = $this->module()->render_event_card( $event_id, 'test' );

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
	}
}
