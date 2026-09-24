<?php
/**
 * Pure unit test: server-side grading (brief 8.2, 8.4, rule 4).
 *
 * @package Anchor\Courses\Tests\Unit
 */

use Anchor\Courses\Support\Grading;
use PHPUnit\Framework\TestCase;

/** @group courses-unit */
class Test_Courses_Unit_Grading extends TestCase {

	private function questions(): array {
		return [
			[
				'id' => 'q1', 'type' => 'single_choice', 'prompt' => 'One?', 'points' => 1.0,
				'answers' => [
					[ 'id' => 'a1', 'text' => 'A', 'correct' => false ],
					[ 'id' => 'a2', 'text' => 'B', 'correct' => true ],
				],
			],
			[
				'id' => 'q2', 'type' => 'multiple_choice', 'prompt' => 'Many?', 'points' => 2.0,
				'answers' => [
					[ 'id' => 'b1', 'text' => 'A', 'correct' => true ],
					[ 'id' => 'b2', 'text' => 'B', 'correct' => true ],
					[ 'id' => 'b3', 'text' => 'C', 'correct' => false ],
				],
			],
			[
				'id' => 'q3', 'type' => 'true_false', 'prompt' => 'True?', 'points' => 1.0,
				'answers' => [
					[ 'id' => 'true', 'text' => 'True', 'correct' => true ],
					[ 'id' => 'false', 'text' => 'False', 'correct' => false ],
				],
			],
		];
	}

	public function test_a_perfect_run_scores_one_hundred() {
		$result = Grading::grade(
			$this->questions(),
			[ 'q1' => 'a2', 'q2' => [ 'b1', 'b2' ], 'q3' => 'true' ]
		);

		$this->assertSame( 4.0, $result['points_earned'] );
		$this->assertSame( 4.0, $result['points_possible'] );
		$this->assertSame( 100.0, $result['score'] );
		$this->assertTrue( $result['per_question']['q2']['correct'] );
	}

	public function test_multiple_choice_is_all_or_nothing() {
		$partial = Grading::grade( $this->questions(), [ 'q2' => [ 'b1' ] ] );
		$this->assertSame( 0.0, $partial['per_question']['q2']['points'] );
		$this->assertFalse( $partial['per_question']['q2']['correct'] );

		$extra = Grading::grade( $this->questions(), [ 'q2' => [ 'b1', 'b2', 'b3' ] ] );
		$this->assertSame( 0.0, $extra['per_question']['q2']['points'] );
	}

	public function test_answer_order_does_not_matter() {
		$a = Grading::grade( $this->questions(), [ 'q2' => [ 'b1', 'b2' ] ] );
		$b = Grading::grade( $this->questions(), [ 'q2' => [ 'b2', 'b1' ] ] );
		$this->assertSame( $a['points_earned'], $b['points_earned'] );
	}

	public function test_unanswered_questions_score_zero_and_are_reported() {
		$result = Grading::grade( $this->questions(), [ 'q1' => 'a2' ] );

		$this->assertSame( 1.0, $result['points_earned'] );
		$this->assertSame( 25.0, $result['score'] );
		$this->assertFalse( $result['per_question']['q3']['correct'] );
		$this->assertSame( [], $result['per_question']['q3']['given_ids'] );
	}

	public function test_answers_for_unknown_questions_are_ignored() {
		$result = Grading::grade( $this->questions(), [ 'q1' => 'a2', 'q99' => 'anything' ] );
		$this->assertArrayNotHasKey( 'q99', $result['per_question'] );
		$this->assertSame( 4.0, $result['points_possible'] );
	}

	public function test_a_single_choice_answer_given_as_an_array_takes_the_first_id_only() {
		$result = Grading::grade( $this->questions(), [ 'q1' => [ 'a2', 'a1' ] ] );
		$this->assertFalse(
			$result['per_question']['q1']['correct'],
			'Two ids for a single-choice question is not a correct answer.'
		);
	}

	public function test_zero_point_questions_do_not_break_the_score() {
		$questions = $this->questions();
		$questions[0]['points'] = 0.0;

		$result = Grading::grade( $questions, [ 'q1' => 'a2', 'q2' => [ 'b1', 'b2' ], 'q3' => 'true' ] );

		$this->assertSame( 3.0, $result['points_possible'] );
		$this->assertSame( 100.0, $result['score'] );
	}

	public function test_an_empty_quiz_scores_zero_rather_than_dividing_by_zero() {
		$result = Grading::grade( [], [] );
		$this->assertSame( 0.0, $result['points_possible'] );
		$this->assertSame( 0.0, $result['score'] );
	}

	public function test_score_rounds_to_two_decimals() {
		$questions = [
			[ 'id' => 'q1', 'type' => 'true_false', 'points' => 1.0, 'prompt' => '',
			  'answers' => [ [ 'id' => 'true', 'text' => 'T', 'correct' => true ], [ 'id' => 'false', 'text' => 'F', 'correct' => false ] ] ],
			[ 'id' => 'q2', 'type' => 'true_false', 'points' => 1.0, 'prompt' => '',
			  'answers' => [ [ 'id' => 'true', 'text' => 'T', 'correct' => true ], [ 'id' => 'false', 'text' => 'F', 'correct' => false ] ] ],
			[ 'id' => 'q3', 'type' => 'true_false', 'points' => 1.0, 'prompt' => '',
			  'answers' => [ [ 'id' => 'true', 'text' => 'T', 'correct' => true ], [ 'id' => 'false', 'text' => 'F', 'correct' => false ] ] ],
		];

		$this->assertSame( 33.33, Grading::grade( $questions, [ 'q1' => 'true' ] )['score'] );
	}

	public function test_passed_compares_against_the_threshold_inclusively() {
		$this->assertTrue( Grading::passed( 80.0, 80 ) );
		$this->assertTrue( Grading::passed( 80.01, 80 ) );
		$this->assertFalse( Grading::passed( 79.99, 80 ) );
	}

	public function test_normalize_answer_handles_scalars_arrays_and_junk() {
		$this->assertSame( [ 'a2' ], Grading::normalize_answer( 'single_choice', 'a2' ) );
		$this->assertSame( [ 'b1', 'b2' ], Grading::normalize_answer( 'multiple_choice', [ 'b2', 'b1' ] ) );
		$this->assertSame( [], Grading::normalize_answer( 'single_choice', null ) );
		$this->assertSame( [], Grading::normalize_answer( 'multiple_choice', [ '', null ] ) );
	}
}
