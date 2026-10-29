<?php
declare(strict_types=1);

namespace Anchor\CheckoutQuestions\Support;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Read access to question sets (the anchor_question_set CPT and its meta). */
final class QuestionSets {

	public const CPT           = 'anchor_question_set';
	public const META_QUESTIONS = '_anchor_cq_questions';
	public const META_PRODUCTS  = '_anchor_cq_product_ids';
	public const META_CATEGORIES = '_anchor_cq_category_ids';

	/** @return array[] Normalized question rows. */
	public static function questions( int $set_id ): array {
		$raw = \get_post_meta( $set_id, self::META_QUESTIONS, true );
		return \is_array( $raw ) ? \Anchor_Questions::normalize( $raw ) : [];
	}

	/** @return int[] */
	public static function product_ids( int $set_id ): array {
		return self::ids( \get_post_meta( $set_id, self::META_PRODUCTS, true ) );
	}

	/** @return int[] */
	public static function category_ids( int $set_id ): array {
		return self::ids( \get_post_meta( $set_id, self::META_CATEGORIES, true ) );
	}

	/** @return int[] Published sets only, by menu_order then ID. */
	public static function published_set_ids(): array {
		$ids = \get_posts( [
			'post_type'        => self::CPT,
			'post_status'      => 'publish',
			'numberposts'      => -1,
			'fields'           => 'ids',
			'orderby'          => [ 'menu_order' => 'ASC', 'ID' => 'ASC' ],
			'suppress_filters' => true,
		] );
		return \array_map( 'intval', $ids );
	}

	/** Positive, de-duplicated ints from anything. @return int[] */
	public static function ids( $raw ): array {
		if ( ! \is_array( $raw ) ) {
			return [];
		}
		$out = [];
		foreach ( $raw as $v ) {
			if ( \is_numeric( $v ) && (int) $v > 0 ) {
				$out[ (int) $v ] = (int) $v;
			}
		}
		return \array_values( $out );
	}
}
