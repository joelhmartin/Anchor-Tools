<?php
declare(strict_types=1);

namespace Anchor\Courses\Support;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Quiz grading. Pure functions, no WordPress, no database (brief rule 4).
 *
 * The authoritative score is computed here and nowhere else - never in
 * JavaScript, never in a template. QuizService is the only caller.
 *
 * Phase 1 question types only (brief 8.2): single_choice, multiple_choice,
 * true_false. Every one of them is exact-set matching; short_answer/number/
 * matching/ordering are brief Phase 2 and are out of scope here - Questions.php
 * (the sanitised-storage source of truth this engine grades against) does not
 * emit them either.
 */
final class Grading {

	/**
	 * Coerce a submitted answer into a sorted list of answer ids.
	 *
	 * Scalars become a one-element list; arrays are filtered and sorted so that
	 * answer ORDER never affects a multiple-choice comparison.
	 *
	 * @param mixed $value
	 * @return string[]
	 */
	public static function normalize_answer( string $type, $value ): array {
		if ( null === $value ) {
			return [];
		}

		$ids = \is_array( $value ) ? $value : [ $value ];
		$ids = \array_values(
			\array_filter(
				\array_map(
					static fn( $id ): string => \is_scalar( $id ) ? \trim( (string) $id ) : '',
					$ids
				),
				static fn( string $id ): bool => '' !== $id
			)
		);

		$ids = \array_values( \array_unique( $ids ) );
		\sort( $ids, SORT_STRING );

		return $ids;
	}

	/**
	 * Grade a submission.
	 *
	 * @param array $questions Canonical questions from Content\Questions::get().
	 * @param array $answers   question_id => answer id | answer id[].
	 * @return array{points_earned:float,points_possible:float,score:float,per_question:array}
	 */
	public static function grade( array $questions, array $answers ): array {
		$earned       = 0.0;
		$possible     = 0.0;
		$per_question = [];

		foreach ( $questions as $question ) {
			$qid    = (string) ( $question['id'] ?? '' );
			$type   = (string) ( $question['type'] ?? '' );
			$points = (float) ( $question['points'] ?? 0 );

			if ( '' === $qid ) {
				continue;
			}

			$correct_ids = [];
			foreach ( (array) ( $question['answers'] ?? [] ) as $answer ) {
				if ( ! empty( $answer['correct'] ) ) {
					$correct_ids[] = (string) $answer['id'];
				}
			}
			\sort( $correct_ids, SORT_STRING );

			$given = self::normalize_answer( $type, $answers[ $qid ] ?? null );

			// Every question type in Phase 1 is exact-set matching. Multiple
			// choice is all-or-nothing on purpose: brief 8.2 defines no partial
			// credit, and inventing one would silently change published scores.
			$is_correct = [] !== $correct_ids && $given === $correct_ids;

			$possible += $points;
			if ( $is_correct ) {
				$earned += $points;
			}

			$per_question[ $qid ] = [
				'correct'         => $is_correct,
				'points'          => $is_correct ? $points : 0.0,
				'points_possible' => $points,
				'correct_ids'     => $correct_ids,
				'given_ids'       => $given,
			];
		}

		$score = $possible > 0 ? \round( ( $earned / $possible ) * 100, 2 ) : 0.0;

		return [
			'points_earned'   => \round( $earned, 2 ),
			'points_possible' => \round( $possible, 2 ),
			'score'           => $score,
			'per_question'    => $per_question,
		];
	}

	/** Inclusive: scoring exactly the passing score passes. */
	public static function passed( float $score, int $passing_score ): bool {
		return $score >= (float) $passing_score;
	}
}
