<?php
/**
 * Query builder for the [anchor_testimonials] shortcode: turns shortcode
 * attributes into a WP_Post[] of anchor_testimonial posts, with audience,
 * type and featured filters, "related to" scoping (including the current
 * post's event-group parent), and an all-testimonials fallback.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Testimonial_Query {
	public static function defaults() {
		return [ 'audience' => '', 'related' => '', 'type' => 'any', 'featured' => '', 'limit' => 12, 'orderby' => 'menu_order', 'fallback' => 'all' ];
	}

	/**
	 * Resolves the shortcode's `related` attribute into a list of post IDs.
	 * `current` expands to the current post plus, when the current post is
	 * an events-manager occurrence that belongs to a group, its group parent.
	 */
	public static function resolve_related( $related, $current_id ) {
		$related = trim( (string) $related );
		if ( $related === '' ) return [];
		$ids = [];
		foreach ( array_map( 'trim', explode( ',', $related ) ) as $part ) {
			if ( $part === 'current' ) {
				if ( $current_id ) {
					$ids[] = (int) $current_id;
					if ( get_post_meta( $current_id, '_anchor_event_group_role', true ) === 'child' ) {
						$parent = (int) get_post_meta( $current_id, '_anchor_event_group_id', true );
						if ( $parent ) $ids[] = $parent;
					}
				}
			} elseif ( ctype_digit( $part ) ) {
				$ids[] = (int) $part;
			}
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	public static function find( array $atts, $current_id = 0 ) {
		$a = wp_parse_args( $atts, self::defaults() );
		$args = [
			'post_type'      => Anchor_Testimonials_Module::CPT,
			'post_status'    => 'publish',
			'posts_per_page' => max( 1, min( 100, (int) $a['limit'] ) ),
			'no_found_rows'  => true,
			'meta_query'     => [],
			'tax_query'      => [],
		];
		switch ( $a['orderby'] ) {
			case 'date': $args['orderby'] = 'date'; $args['order'] = 'DESC'; break;
			case 'rand': $args['orderby'] = 'rand'; break;
			default:     $args['orderby'] = [ 'menu_order' => 'ASC', 'date' => 'DESC' ];
		}
		if ( $a['audience'] !== '' ) {
			$args['tax_query'][] = [ 'taxonomy' => Anchor_Testimonials_Module::TAX, 'field' => 'slug', 'terms' => array_map( 'sanitize_title', explode( ',', $a['audience'] ) ) ];
		}
		if ( (string) $a['featured'] === '1' ) {
			$args['meta_query'][] = [ 'key' => '_at_featured', 'value' => '1' ];
		}
		if ( $a['type'] === 'video' ) {
			$args['meta_query'][] = [ 'key' => '_at_video_id', 'value' => '', 'compare' => '!=' ];
		} elseif ( $a['type'] === 'quote' ) {
			$args['meta_query'][] = [ 'relation' => 'OR', [ 'key' => '_at_video_id', 'value' => '' ], [ 'key' => '_at_video_id', 'compare' => 'NOT EXISTS' ] ];
		}

		$related = self::resolve_related( $a['related'], (int) $current_id );
		if ( $related ) {
			$scoped = $args;
			$scoped['meta_query'][] = [ 'key' => '_at_related_id', 'value' => array_map( 'strval', $related ), 'compare' => 'IN' ];
			$posts = self::dedupe_by_id( get_posts( $scoped ) );
			if ( $posts || $a['fallback'] !== 'all' ) return $posts;
		} elseif ( $a['related'] !== '' && $a['fallback'] !== 'all' ) {
			return [];
		}
		return self::dedupe_by_id( get_posts( $args ) );
	}

	/**
	 * A testimonial with two `_at_related_id` rows both matching the `IN`
	 * meta query would otherwise be returned once per matching row.
	 */
	private static function dedupe_by_id( array $posts ) {
		$seen = [];
		$out  = [];
		foreach ( $posts as $post ) {
			if ( isset( $seen[ $post->ID ] ) ) continue;
			$seen[ $post->ID ] = true;
			$out[]             = $post;
		}
		return $out;
	}
}
