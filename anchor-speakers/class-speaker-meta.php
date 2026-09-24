<?php
/**
 * Pure meta read/write for a speaker post. Used by the admin metabox save
 * handler, so all validation and normalization lives here, not scattered
 * across save_post callbacks. Mirrors Anchor_Testimonial_Meta's shape.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Speaker_Meta {
	const P = '_as_';

	public static function save( $post_id, array $in ) {
		$post_id = (int) $post_id;
		update_post_meta( $post_id, self::P . 'credentials', sanitize_text_field( $in['credentials'] ?? '' ) );
		update_post_meta( $post_id, self::P . 'title', sanitize_text_field( $in['title'] ?? '' ) );
		update_post_meta( $post_id, self::P . 'location', sanitize_text_field( $in['location'] ?? '' ) );

		if ( ! empty( $in['featured'] ) ) update_post_meta( $post_id, self::P . 'featured', '1' );
		else delete_post_meta( $post_id, self::P . 'featured' );

		$links = [];
		foreach ( (array) ( $in['links'] ?? [] ) as $link ) {
			$url = esc_url_raw( trim( (string) ( $link['url'] ?? '' ) ) );
			if ( $url === '' ) continue;
			$links[] = [
				'label' => sanitize_text_field( $link['label'] ?? '' ),
				'url'   => $url,
			];
		}
		update_post_meta( $post_id, self::P . 'links', $links );
	}

	public static function get( $post_id ) {
		$g = function ( $k ) use ( $post_id ) { return get_post_meta( $post_id, self::P . $k, true ); };
		$links = $g( 'links' );
		return [
			'credentials' => (string) $g( 'credentials' ),
			'title'       => (string) $g( 'title' ),
			'location'    => (string) $g( 'location' ),
			'featured'    => $g( 'featured' ) === '1',
			'links'       => is_array( $links ) ? $links : [],
		];
	}
}
