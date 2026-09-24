<?php
declare(strict_types=1);

namespace Anchor\Courses\Content;

use Anchor\Courses\Support\Uuid;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Quiz questions: structured JSON in quiz post meta (brief 8.3).
 *
 * sanitize() and for_learner() are pure so the "never leak `correct`" rule is
 * unit-tested without WordPress. for_learner() is the ONLY projection a
 * learner-facing surface (including Task 26's REST layer) may use.
 */
final class Questions {

	public const META  = '_anchor_quiz_questions';
	public const TYPES = [ 'single_choice', 'multiple_choice', 'true_false' ];

	/** Canonicalise authored questions. Pure. */
	public static function sanitize( array $questions ): array {
		$clean = [];

		foreach ( $questions as $question ) {
			if ( ! \is_array( $question ) ) {
				continue;
			}
			$type = \sanitize_key( (string) ( $question['type'] ?? '' ) );
			if ( ! \in_array( $type, self::TYPES, true ) ) {
				continue;
			}

			$answers = self::sanitize_answers( $type, (array) ( $question['answers'] ?? [] ) );

			// A question nobody can get right is an authoring error, not content.
			$has_correct = false;
			foreach ( $answers as $answer ) {
				$has_correct = $has_correct || $answer['correct'];
			}
			if ( ! $has_correct ) {
				continue;
			}

			$id = (string) ( $question['id'] ?? '' );
			if ( ! \preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id ) ) {
				$id = Uuid::v4();
			}

			$points = $question['points'] ?? 1;
			$points = ( null === $points || '' === $points ) ? 1.0 : (float) $points;

			$clean[] = [
				'id'      => $id,
				'type'    => $type,
				'prompt'  => \wp_kses_post( (string) ( $question['prompt'] ?? '' ) ),
				'points'  => \max( 0.0, $points ),
				'answers' => $answers,
			];
		}

		return $clean;
	}

	private static function sanitize_answers( string $type, array $answers ): array {
		if ( 'true_false' === $type ) {
			// Always exactly two rows with fixed ids, so grading never depends on
			// authored order or wording.
			$true_is_key = true;
			foreach ( $answers as $answer ) {
				$text = \strtolower( \trim( (string) ( $answer['text'] ?? '' ) ) );
				if ( empty( $answer['correct'] ) ) {
					continue;
				}
				if ( \in_array( $text, [ 'false', 'no', '0' ], true ) ) {
					$true_is_key = false;
					break;
				}
				if ( \in_array( $text, [ 'true', 'yes', '1' ], true ) ) {
					$true_is_key = true;
					break;
				}
			}
			return [
				[ 'id' => 'true', 'text' => \__( 'True', 'anchor-schema' ), 'correct' => $true_is_key ],
				[ 'id' => 'false', 'text' => \__( 'False', 'anchor-schema' ), 'correct' => ! $true_is_key ],
			];
		}

		$clean        = [];
		$seen_correct = false;

		foreach ( $answers as $answer ) {
			if ( ! \is_array( $answer ) ) {
				continue;
			}
			$id = \sanitize_key( (string) ( $answer['id'] ?? '' ) );
			if ( '' === $id ) {
				$id = 'a' . \substr( \str_replace( '-', '', Uuid::v4() ), 0, 12 );
			}
			$correct = ! empty( $answer['correct'] );

			// single_choice has exactly one key: keep the first correct row only.
			if ( 'single_choice' === $type && $correct ) {
				if ( $seen_correct ) {
					$correct = false;
				}
				$seen_correct = true;
			}

			$clean[] = [
				'id'      => $id,
				'text'    => \sanitize_text_field( (string) ( $answer['text'] ?? '' ) ),
				'correct' => $correct,
			];
		}

		return $clean;
	}

	public static function get( int $quiz_id ): array {
		$stored = \get_post_meta( $quiz_id, self::META, true );
		return \is_array( $stored ) ? self::sanitize( $stored ) : [];
	}

	public static function save( int $quiz_id, array $questions ): array {
		$clean = self::sanitize( $questions );
		\update_post_meta( $quiz_id, self::META, $clean );
		return $clean;
	}

	public static function points_possible( int $quiz_id ): float {
		$total = 0.0;
		foreach ( self::get( $quiz_id ) as $question ) {
			$total += (float) $question['points'];
		}
		return $total;
	}

	/**
	 * The learner-safe projection. Strips `correct` from every answer and applies
	 * the quiz's shuffle settings deterministically from $seed (the attempt id),
	 * so a page reload during one attempt shows the same order. Pure.
	 */
	public static function for_learner( array $questions, bool $shuffle_questions, bool $shuffle_answers, string $seed ): array {
		$out = [];

		foreach ( $questions as $question ) {
			$answers = [];
			foreach ( (array) $question['answers'] as $answer ) {
				$answers[] = [ 'id' => $answer['id'], 'text' => $answer['text'] ];
			}
			if ( $shuffle_answers ) {
				$answers = self::seeded_shuffle( $answers, $seed . '|' . $question['id'] );
			}
			$out[] = [
				'id'      => $question['id'],
				'type'    => $question['type'],
				'prompt'  => $question['prompt'],
				'points'  => $question['points'],
				'answers' => $answers,
			];
		}

		return $shuffle_questions ? self::seeded_shuffle( $out, $seed ) : $out;
	}

	/** Order a list by a hash of (seed, element id): same seed, same order. Pure. */
	private static function seeded_shuffle( array $list, string $seed ): array {
		$keyed = [];
		foreach ( $list as $index => $element ) {
			$keyed[] = [ \hash( 'sha256', $seed . '|' . ( $element['id'] ?? $index ) ), $element ];
		}
		\usort( $keyed, static fn( array $a, array $b ): int => \strcmp( $a[0], $b[0] ) );
		return \array_column( $keyed, 1 );
	}
}
