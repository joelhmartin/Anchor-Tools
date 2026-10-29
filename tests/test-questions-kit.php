<?php
/**
 * Anchor_Questions: the shared question model, on plain arrays (no event).
 *
 * @package Anchor\Tests
 */

/**
 * @group questions
 */
class Test_Questions_Kit extends WP_UnitTestCase {

	private function q( array $over = [] ) {
		return array_merge( [ 'key' => 'k', 'label' => 'K', 'type' => 'text', 'options' => [], 'required' => false, 'admin_email' => false ], $over );
	}

	public function test_normalize_drops_blank_labels_and_derives_keys() {
		$rows = Anchor_Questions::normalize( [
			[ 'label' => '' ],
			'junk',
			[ 'label' => 'How did you hear about us?' ],
		] );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'how-did-you-hear-about-us', $rows[0]['key'] );
		$this->assertFalse( $rows[0]['admin_email'] );
		$this->assertFalse( $rows[0]['required'] );
	}

	public function test_normalize_dedupes_keys() {
		$rows = Anchor_Questions::normalize( [ [ 'label' => 'A', 'key' => 'phone' ], [ 'label' => 'B', 'key' => 'phone' ] ] );
		$this->assertSame( [ 'phone', 'phone_2' ], wp_list_pluck( $rows, 'key' ) );
	}

	public function test_normalize_type_rules() {
		$rows = Anchor_Questions::normalize( [
			[ 'label' => 'A', 'type' => 'bogus' ],
			[ 'label' => 'B', 'type' => 'select', 'options' => '' ],
			[ 'label' => 'C', 'type' => 'multiselect', 'options' => "Peanuts, tree nuts\nOther" ],
		] );
		$this->assertSame( 'text', $rows[0]['type'] );
		$this->assertSame( 'text', $rows[1]['type'] );
		$this->assertSame( 'multiselect', $rows[2]['type'] );
		$this->assertSame( [ 'Peanuts / tree nuts', 'Other' ], $rows[2]['options'] );
	}

	public function test_sanitize_answers_by_type() {
		$qs  = [
			$this->q( [ 'key' => 'ta', 'type' => 'textarea' ] ),
			$this->q( [ 'key' => 'tx' ] ),
			$this->q( [ 'key' => 'sel', 'type' => 'select', 'options' => [ 'A', 'B' ] ] ),
			$this->q( [ 'key' => 'cb', 'type' => 'checkbox' ] ),
			$this->q( [ 'key' => 'cb0', 'type' => 'checkbox' ] ),
			$this->q( [ 'key' => 'ms', 'type' => 'multiselect', 'options' => [ 'Vegan', 'Other' ] ] ),
		];
		$out = Anchor_Questions::sanitize_answers( $qs, [
			'ta' => "a\nb", 'tx' => "a\nb", 'sel' => 'C', 'cb' => '1', 'cb0' => '0',
			'ms' => [ 'Vegan', 'Other' ], 'ms__other' => 'shellfish',
		] );
		$this->assertSame( "a\nb", $out['answers']['ta'] );
		$this->assertStringNotContainsString( "\n", $out['answers']['tx'] );
		$this->assertSame( '', $out['answers']['sel'] );
		$this->assertSame( 'yes', $out['answers']['cb'] );
		$this->assertSame( '', $out['answers']['cb0'] );
		$this->assertSame( 'Vegan, Other: shellfish', $out['answers']['ms'] );
	}

	public function test_sanitize_answers_reports_missing_required() {
		$qs  = [
			$this->q( [ 'key' => 'a', 'required' => true ] ),
			$this->q( [ 'key' => 'b', 'type' => 'checkbox', 'required' => true ] ),
			$this->q( [ 'key' => 'c', 'required' => true ] ),
		];
		$out = Anchor_Questions::sanitize_answers( $qs, [ 'a' => '  ', 'b' => '0', 'c' => 'ok' ] );
		$this->assertSame( [ 'a', 'b' ], wp_list_pluck( $out['missing'], 'key' ) );
	}

	public function test_render_control_escapes_values() {
		$html = Anchor_Questions::render_control( $this->q(), [ 'name' => 'x', 'value' => '"><script>' ] );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function test_resolve_answers_maps_legacy_label_keys() {
		$qs  = [ $this->q( [ 'key' => 'heard', 'label' => 'How did you hear about us?' ] ) ];
		$out = Anchor_Questions::resolve_answers( $qs, [ 'How did you hear about us?' => 'A friend' ] );
		$this->assertSame( [ 'heard' => 'A friend' ], $out );
		// A current key wins over a legacy label on the same seat.
		$both = Anchor_Questions::resolve_answers( $qs, [ 'heard' => 'new', 'How did you hear about us?' => 'old' ] );
		$this->assertSame( 'new', $both['heard'] );
	}

	public function test_answer_label_falls_back_to_the_key() {
		$qs = [ $this->q( [ 'key' => 'heard', 'label' => 'Heard?' ] ) ];
		$this->assertSame( 'Heard?', Anchor_Questions::answer_label( $qs, 'heard' ) );
		$this->assertSame( 'gone', Anchor_Questions::answer_label( $qs, 'gone' ) );
	}

	public function test_other_helpers() {
		$this->assertTrue( Anchor_Questions::is_other_choice( 'Other (please specify)' ) );
		$this->assertFalse( Anchor_Questions::is_other_choice( 'Mother' ) );
		$this->assertSame( 'x[a__other]', Anchor_Questions::other_field_name( 'x[a]' ) );
		$this->assertSame( 'a__other', Anchor_Questions::other_field_name( 'a' ) );
	}

	public function test_editor_row_posts_under_the_given_prefix_with_admin_email_help() {
		$html = Anchor_Questions_Editor::row_html( 'anchor_event_questions', 0, [ 'label' => 'Q', 'admin_email' => true ] );
		$this->assertStringContainsString( 'name="anchor_event_questions[0][admin_email]" value="1"  checked=\'checked\'', $html );
		$this->assertStringContainsString( 'Answers are sent by plain email. Don&#039;t tick this for health information.', $html );
		$other = Anchor_Questions_Editor::row_html( 'anchor_cq_questions', 0, [ 'label' => 'Q' ] );
		$this->assertStringContainsString( 'name="anchor_cq_questions[0][label]"', $other );
		$this->assertStringNotContainsString( 'anchor_event_questions', $other );
	}

	public function test_editor_repeater_carries_marker_and_template() {
		$html = Anchor_Questions_Editor::repeater_html( 'anchor_event_questions', [ $this->q( [ 'label' => 'Diet' ] ) ] );
		$this->assertStringContainsString( 'name="anchor_event_questions_present"', $html );
		$this->assertStringContainsString( 'anchor-questions-editor', $html );
		$this->assertStringContainsString( 'data-prefix="anchor_event_questions"', $html );
		$this->assertStringContainsString( 'anchor_event_questions[__INDEX__][label]', $html );
		$this->assertStringContainsString( 'value="Diet"', $html );
	}
}
