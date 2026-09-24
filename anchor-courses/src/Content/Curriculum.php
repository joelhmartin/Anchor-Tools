<?php
declare(strict_types=1);

namespace Anchor\Courses\Content;

use Anchor\Courses\Support\Uuid;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Course curriculum: ordered modules of ordered lesson/quiz references (brief 6.2).
 *
 * Modules are NOT posts. They live as structured meta on the course, each with a
 * stable UUID so drag-reordering never breaks a reference. Removing an item from
 * a module removes the REFERENCE only - the lesson/quiz post is untouched
 * (brief section 21.1).
 *
 * sanitize() is pure (no get_post_meta, no DB) so it is unit-testable without
 * WordPress; get()/save() are the only I/O.
 */
final class Curriculum {

	public const META       = '_anchor_course_curriculum';
	public const ITEM_TYPES = [ 'lesson', 'quiz' ];

	/**
	 * Normalise authored input to the canonical shape. Pure.
	 *
	 * A module with an empty (or all-invalid) `items` array is preserved as an
	 * empty module rather than dropped - an author may be mid-build.
	 *
	 * @param array $modules Raw module rows.
	 * @return array<int,array{id:string,title:string,description:string,items:array<int,array{type:string,id:int,required:bool}>}>
	 */
	public static function sanitize( array $modules ): array {
		$clean = [];
		$seen  = []; // "type:id" => true, so one item cannot appear twice in a course.

		foreach ( $modules as $module ) {
			if ( ! \is_array( $module ) ) {
				continue;
			}

			$items = [];
			foreach ( (array) ( $module['items'] ?? [] ) as $item ) {
				if ( ! \is_array( $item ) ) {
					continue;
				}
				$type = \sanitize_key( (string) ( $item['type'] ?? '' ) );
				$id   = (int) ( $item['id'] ?? 0 );
				if ( ! \in_array( $type, self::ITEM_TYPES, true ) || $id <= 0 ) {
					continue;
				}
				$fingerprint = $type . ':' . $id;
				if ( isset( $seen[ $fingerprint ] ) ) {
					continue;
				}
				$seen[ $fingerprint ] = true;

				$items[] = [
					'type'     => $type,
					'id'       => $id,
					// Default required: an item is part of the course unless said otherwise.
					'required' => ! \array_key_exists( 'required', $item ) || (bool) $item['required'],
				];
			}

			$id = (string) ( $module['id'] ?? '' );
			if ( ! \preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id ) ) {
				$id = Uuid::v4();
			}

			$clean[] = [
				'id'          => $id,
				'title'       => \sanitize_text_field( (string) ( $module['title'] ?? '' ) ),
				'description' => \wp_kses_post( (string) ( $module['description'] ?? '' ) ),
				'items'       => $items,
			];
		}

		return $clean;
	}

	/** @return array Canonical modules for a course (empty when unauthored). */
	public static function get( int $course_id ): array {
		$stored = \get_post_meta( $course_id, self::META, true );
		return \is_array( $stored ) ? self::sanitize( $stored ) : [];
	}

	/** Persist and return the canonical modules actually stored. */
	public static function save( int $course_id, array $modules ): array {
		$clean = self::sanitize( $modules );
		\update_post_meta( $course_id, self::META, $clean );
		\do_action( 'anchor_courses_curriculum_saved', $course_id, $clean );
		return $clean;
	}

	/**
	 * Flat, ordered item list.
	 *
	 * @return array<int,array{type:string,id:int,required:bool,module_id:string,index:int}>
	 */
	public static function items( int $course_id ): array {
		$flat  = [];
		$index = 0;
		foreach ( self::get( $course_id ) as $module ) {
			foreach ( $module['items'] as $item ) {
				$item['module_id'] = $module['id'];
				$item['index']     = $index++;
				$flat[]            = $item;
			}
		}
		return $flat;
	}

	/** @return array Only the items that count toward completion. */
	public static function required_items( int $course_id ): array {
		return \array_values(
			\array_filter( self::items( $course_id ), static fn( array $i ): bool => $i['required'] )
		);
	}

	/** Zero-based position in the flattened curriculum, or -1. */
	public static function position( int $course_id, int $item_id, string $type ): int {
		foreach ( self::items( $course_id ) as $item ) {
			if ( $item['id'] === $item_id && $item['type'] === $type ) {
				return $item['index'];
			}
		}
		return -1;
	}

	/** Every item ordered before the given one. Empty when the item is absent. */
	public static function items_before( int $course_id, int $item_id, string $type ): array {
		$position = self::position( $course_id, $item_id, $type );
		if ( $position < 0 ) {
			return [];
		}
		return \array_slice( self::items( $course_id ), 0, $position );
	}

	public static function contains( int $course_id, int $item_id, string $type ): bool {
		return self::position( $course_id, $item_id, $type ) >= 0;
	}

	/**
	 * The course that lists this item, or 0.
	 *
	 * Scans every course's curriculum meta directly via `get_posts()` with
	 * `meta_key` (rather than loading each course through `get()` twice), so
	 * courses with no curriculum meta at all are excluded from the candidate
	 * list before `contains()` walks their items.
	 *
	 * Nothing enforces that an item belongs to only one course's curriculum,
	 * so sharing is possible. When an item is shared by several courses this
	 * resolves to the LOWEST course id, deterministically, by ordering the
	 * candidate query by `ID ASC`: sharing is allowed, but the owner used for
	 * progress purposes is always the same one, regardless of save order.
	 */
	public static function course_for_item( int $item_id, string $type ): int {
		$courses = \get_posts(
			[
				'post_type'      => CoursePostType::CPT,
				'post_status'    => [ 'publish', 'draft', 'private', 'pending' ],
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_key'       => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery
			]
		);

		foreach ( $courses as $course_id ) {
			if ( self::contains( (int) $course_id, $item_id, $type ) ) {
				return (int) $course_id;
			}
		}

		return 0;
	}
}
