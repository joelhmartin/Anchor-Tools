<?php
/**
 * Pure unit test: curriculum sanitisation and UUID stability (brief section 6.2).
 *
 * @package Anchor\Courses\Tests\Unit
 */

use Anchor\Courses\Content\Curriculum;
use PHPUnit\Framework\TestCase;

/** @group courses-unit */
class Test_Courses_Unit_Curriculum extends TestCase {

	public function test_a_module_without_an_id_is_given_a_uuid() {
		$out = Curriculum::sanitize( [ [ 'title' => 'Module 1', 'items' => [] ] ] );
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			$out[0]['id']
		);
	}

	public function test_an_existing_module_uuid_survives_reordering() {
		$first  = Curriculum::sanitize( [ [ 'title' => 'A', 'items' => [] ], [ 'title' => 'B', 'items' => [] ] ] );
		$uuid_a = $first[0]['id'];
		$uuid_b = $first[1]['id'];

		$reordered = Curriculum::sanitize( [ $first[1], $first[0] ] );

		$this->assertSame( $uuid_b, $reordered[0]['id'] );
		$this->assertSame( $uuid_a, $reordered[1]['id'] );
	}

	public function test_items_are_coerced_to_the_canonical_shape() {
		$out = Curriculum::sanitize(
			[ [ 'title' => 'M', 'items' => [
				[ 'type' => 'lesson', 'id' => '124' ],
				[ 'type' => 'quiz', 'id' => 150, 'required' => '0' ],
			] ] ]
		);
		$this->assertSame(
			[ [ 'type' => 'lesson', 'id' => 124, 'required' => true ], [ 'type' => 'quiz', 'id' => 150, 'required' => false ] ],
			$out[0]['items']
		);
	}

	public function test_unknown_item_types_and_non_positive_ids_are_dropped() {
		$out = Curriculum::sanitize(
			[ [ 'title' => 'M', 'items' => [
				[ 'type' => 'video', 'id' => 5 ],
				[ 'type' => 'lesson', 'id' => 0 ],
				[ 'type' => 'lesson', 'id' => -3 ],
				[ 'type' => 'lesson', 'id' => 7 ],
			] ] ]
		);
		$this->assertSame( [ [ 'type' => 'lesson', 'id' => 7, 'required' => true ] ], $out[0]['items'] );
	}

	public function test_duplicate_items_within_a_course_are_collapsed() {
		$out = Curriculum::sanitize(
			[
				[ 'title' => 'M1', 'items' => [ [ 'type' => 'lesson', 'id' => 9 ] ] ],
				[ 'title' => 'M2', 'items' => [ [ 'type' => 'lesson', 'id' => 9 ], [ 'type' => 'quiz', 'id' => 9 ] ] ],
			]
		);
		$this->assertCount( 1, $out[0]['items'] );
		$this->assertSame(
			[ [ 'type' => 'quiz', 'id' => 9, 'required' => true ] ],
			$out[1]['items'],
			'A lesson and a quiz may share an id; the same lesson twice may not.'
		);
	}

	public function test_titles_are_text_and_descriptions_keep_safe_html() {
		$out = Curriculum::sanitize(
			[ [ 'title' => '<b>Bold</b> title', 'description' => '<p>Keep <em>this</em></p>', 'items' => [] ] ]
		);
		$this->assertSame( 'Bold title', $out[0]['title'] );
		$this->assertSame( '<p>Keep <em>this</em></p>', $out[0]['description'] );
	}

	public function test_non_array_input_yields_an_empty_curriculum() {
		$this->assertSame( [], Curriculum::sanitize( [] ) );
		$this->assertSame( [], Curriculum::sanitize( [ 'not-a-module' ] ) );
	}
}
