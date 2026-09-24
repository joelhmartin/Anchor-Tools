<?php
/**
 * Pure unit test: question sanitisation and learner projection (brief 8.3, 25).
 *
 * @package Anchor\Courses\Tests\Unit
 */

use Anchor\Courses\Content\Questions;
use PHPUnit\Framework\TestCase;

/** @group courses-unit */
class Test_Courses_Unit_Questions extends TestCase {

	private function q( array $over = [] ): array {
		return array_merge(
			[
				'type'    => 'single_choice',
				'prompt'  => 'Which one?',
				'points'  => 1,
				'answers' => [
					[ 'text' => 'A', 'correct' => false ],
					[ 'text' => 'B', 'correct' => true ],
				],
			],
			$over
		);
	}

	public function test_questions_and_answers_get_stable_ids() {
		$out = Questions::sanitize( [ $this->q() ] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', $out[0]['id'] );
		$this->assertNotSame( '', $out[0]['answers'][0]['id'] );
		$this->assertNotSame( $out[0]['answers'][0]['id'], $out[0]['answers'][1]['id'] );
	}

	public function test_existing_ids_are_preserved() {
		$first = Questions::sanitize( [ $this->q() ] );
		$again = Questions::sanitize( $first );
		$this->assertSame( $first[0]['id'], $again[0]['id'] );
		$this->assertSame( $first[0]['answers'][1]['id'], $again[0]['answers'][1]['id'] );
	}

	public function test_unknown_types_are_dropped() {
		$this->assertSame( [], Questions::sanitize( [ $this->q( [ 'type' => 'essay' ] ) ] ) );
		$this->assertSame( [], Questions::sanitize( [ $this->q( [ 'type' => 'matching' ] ) ] ) );
	}

	public function test_a_question_with_no_correct_answer_is_dropped() {
		$this->assertSame(
			[],
			Questions::sanitize( [ $this->q( [ 'answers' => [ [ 'text' => 'A', 'correct' => false ] ] ] ) ] )
		);
	}

	public function test_single_choice_keeps_only_the_first_correct_answer() {
		$out = Questions::sanitize(
			[ $this->q( [ 'answers' => [
				[ 'text' => 'A', 'correct' => true ],
				[ 'text' => 'B', 'correct' => true ],
			] ] ) ]
		);
		$this->assertTrue( $out[0]['answers'][0]['correct'] );
		$this->assertFalse( $out[0]['answers'][1]['correct'] );
	}

	public function test_multiple_choice_keeps_every_correct_answer() {
		$out = Questions::sanitize(
			[ $this->q( [ 'type' => 'multiple_choice', 'answers' => [
				[ 'text' => 'A', 'correct' => true ],
				[ 'text' => 'B', 'correct' => true ],
				[ 'text' => 'C', 'correct' => false ],
			] ] ) ]
		);
		$this->assertSame( [ true, true, false ], array_column( $out[0]['answers'], 'correct' ) );
	}

	public function test_true_false_is_normalised_to_exactly_two_answers() {
		$out = Questions::sanitize(
			[ $this->q( [ 'type' => 'true_false', 'answers' => [ [ 'text' => 'True', 'correct' => true ] ] ] ) ]
		);
		$this->assertCount( 2, $out[0]['answers'] );
		$this->assertSame( 'true', $out[0]['answers'][0]['id'] );
		$this->assertSame( 'false', $out[0]['answers'][1]['id'] );
		$this->assertTrue( $out[0]['answers'][0]['correct'] );
		$this->assertFalse( $out[0]['answers'][1]['correct'] );
	}

	public function test_true_false_can_mark_false_as_the_key() {
		$out = Questions::sanitize(
			[ $this->q( [ 'type' => 'true_false', 'answers' => [
				[ 'text' => 'True', 'correct' => false ],
				[ 'text' => 'False', 'correct' => true ],
			] ] ) ]
		);
		$this->assertFalse( $out[0]['answers'][0]['correct'] );
		$this->assertTrue( $out[0]['answers'][1]['correct'] );
	}

	public function test_points_default_to_one_and_are_never_negative() {
		$this->assertSame( 1.0, Questions::sanitize( [ $this->q( [ 'points' => null ] ) ] )[0]['points'] );
		$this->assertSame( 0.0, Questions::sanitize( [ $this->q( [ 'points' => -5 ] ) ] )[0]['points'] );
		$this->assertSame( 2.5, Questions::sanitize( [ $this->q( [ 'points' => '2.5' ] ) ] )[0]['points'] );
	}

	/** Brief 8.3 / 25: the single most important rule in the quiz engine. */
	public function test_for_learner_strips_every_correct_key() {
		$stored = Questions::sanitize( [ $this->q(), $this->q( [ 'prompt' => 'Second?' ] ) ] );

		$projected = Questions::for_learner( $stored, false, false, 'seed' );

		$this->assertStringNotContainsString( 'correct', (string) json_encode( $projected ) );
		$this->assertSame( 'Which one?', $projected[0]['prompt'] );
		$this->assertSame( $stored[0]['answers'][0]['id'], $projected[0]['answers'][0]['id'] );
	}

	public function test_for_learner_shuffle_is_deterministic_per_seed() {
		$stored = Questions::sanitize(
			array_map( fn( $n ) => $this->q( [ 'prompt' => "Q{$n}" ] ), range( 1, 8 ) )
		);

		$a = Questions::for_learner( $stored, true, true, 'attempt-7' );
		$b = Questions::for_learner( $stored, true, true, 'attempt-7' );
		$c = Questions::for_learner( $stored, true, true, 'attempt-8' );

		$this->assertSame( array_column( $a, 'id' ), array_column( $b, 'id' ), 'Same seed must reproduce the same order.' );
		$this->assertNotSame( array_column( $a, 'id' ), array_column( $c, 'id' ), 'A different attempt must shuffle differently.' );
		$this->assertCount( 8, $a );
	}
}
