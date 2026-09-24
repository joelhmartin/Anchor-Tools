<?php
/**
 * Pure meta read/write for a testimonial post. Used by the admin metabox
 * save handler and by import scripts, so all validation and normalization
 * lives here, not scattered across save_post callbacks.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Testimonial_Meta {
	const P = '_at_';

	public static function save( $post_id, array $in ) {
		$post_id = (int) $post_id;
		update_post_meta( $post_id, self::P . 'person_name', sanitize_text_field( $in['person_name'] ?? '' ) );
		update_post_meta( $post_id, self::P . 'person_meta', sanitize_text_field( $in['person_meta'] ?? '' ) );

		$url = esc_url_raw( trim( (string) ( $in['video_url'] ?? '' ) ) );
		$v   = $url !== '' ? Anchor_Video_URL::parse( $url ) : null;
		update_post_meta( $post_id, self::P . 'video_url', $v ? $url : '' );
		update_post_meta( $post_id, self::P . 'video_provider', $v ? $v['provider'] : '' );
		update_post_meta( $post_id, self::P . 'video_id', $v ? $v['id'] : '' );
		update_post_meta( $post_id, self::P . 'video_start', $v ? (int) $v['start'] : 0 );
		update_post_meta( $post_id, self::P . 'video_thumb', $v ? Anchor_Video_URL::thumbnail( $v['provider'], $v['id'] ) : '' );

		update_post_meta( $post_id, self::P . 'rating', max( 0, min( 5, (int) ( $in['rating'] ?? 0 ) ) ) );
		if ( ! empty( $in['featured'] ) ) update_post_meta( $post_id, self::P . 'featured', '1' );
		else delete_post_meta( $post_id, self::P . 'featured' );

		$related = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $in['related'] ?? [] ) ) ) ) );
		update_post_meta( $post_id, self::P . 'related', $related );
		delete_post_meta( $post_id, self::P . 'related_id' );
		foreach ( $related as $rid ) add_post_meta( $post_id, self::P . 'related_id', (string) $rid );
	}

	public static function get( $post_id ) {
		$g = function ( $k ) use ( $post_id ) { return get_post_meta( $post_id, self::P . $k, true ); };
		$related = $g( 'related' );
		return [
			'person_name'    => (string) $g( 'person_name' ),
			'person_meta'    => (string) $g( 'person_meta' ),
			'video_url'      => (string) $g( 'video_url' ),
			'video_provider' => (string) $g( 'video_provider' ),
			'video_id'       => (string) $g( 'video_id' ),
			'video_start'    => (int) $g( 'video_start' ),
			'video_thumb'    => (string) $g( 'video_thumb' ),
			'rating'         => (int) $g( 'rating' ),
			'featured'       => $g( 'featured' ) === '1',
			'related'        => is_array( $related ) ? array_map( 'intval', $related ) : [],
		];
	}
}
