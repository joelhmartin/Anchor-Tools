<?php
/**
 * Renders a WP_Post[] of anchor_testimonial posts (from Anchor_Testimonial_Query)
 * into the shortcode's markup: grid, slider or video-grid layout, sharing card
 * markup across the three so themes only style against one contract.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Testimonial_Render {

	private static function layout( array $atts ) {
		$layout = $atts['layout'] ?? 'grid';
		return in_array( $layout, [ 'grid', 'slider', 'video-grid' ], true ) ? $layout : 'grid';
	}

	private static function columns( array $atts ) {
		return max( 1, min( 4, (int) ( $atts['columns'] ?? 3 ) ) );
	}

	/**
	 * @param WP_Post[] $posts
	 * @param array     $atts
	 * @return string
	 */
	public static function html( array $posts, array $atts ) {
		if ( empty( $posts ) ) {
			return '';
		}

		$layout  = self::layout( $atts );
		$columns = self::columns( $atts );

		$root_classes = [ 'anchor-testimonials', 'anchor-testimonials--' . $layout ];
		$style        = '--at-cols:' . $columns . ';--at-gap:24px;';

		$track_classes = [ 'anchor-testimonials__track' ];
		if ( $layout === 'slider' ) {
			$track_classes[] = 'anchor-carousel__track';
		}

		$cards = '';
		foreach ( $posts as $post ) {
			$cards .= self::card( $post, $layout );
		}

		$html  = '<div class="' . esc_attr( implode( ' ', $root_classes ) ) . '" style="' . esc_attr( $style ) . '" data-layout="' . esc_attr( $layout ) . '">';
		$html .= '<div class="anchor-testimonials__viewport"><div class="' . esc_attr( implode( ' ', $track_classes ) ) . '">' . $cards . '</div></div>';

		if ( $layout === 'slider' ) {
			$html .= '<div class="anchor-testimonials__controls">';
			$html .= '<button type="button" class="anchor-testimonials__prev" aria-label="' . esc_attr__( 'Previous', 'anchor-schema' ) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="15,6 9,12 15,18"></polyline></svg></button>';
			$html .= '<div class="anchor-testimonials__dots"></div>';
			$html .= '<button type="button" class="anchor-testimonials__next" aria-label="' . esc_attr__( 'Next', 'anchor-schema' ) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="9,6 15,12 9,18"></polyline></svg></button>';
			$html .= '</div>';
		}

		$html .= '</div>';

		return $html;
	}

	private static function card( WP_Post $post, $layout ) {
		$meta      = Anchor_Testimonial_Meta::get( $post->ID );
		$has_video = $meta['video_id'] !== '';
		$modifier  = $has_video ? 'anchor-testimonial--video' : 'anchor-testimonial--quote';

		$html = '<figure class="anchor-testimonial ' . esc_attr( $modifier ) . '">';

		if ( $has_video ) {
			$label = sprintf( /* translators: %s: person name */ __( 'Play video: %s', 'anchor-schema' ), $meta['person_name'] );
			$html .= '<button type="button" class="anchor-testimonial__media" data-provider="' . esc_attr( $meta['video_provider'] ) . '" data-video-id="' . esc_attr( $meta['video_id'] ) . '" data-start="' . esc_attr( $meta['video_start'] ) . '" aria-label="' . esc_attr( $label ) . '">';
			$html .= '<img src="' . esc_url( $meta['video_thumb'] ) . '" alt="" loading="lazy">';
			$html .= '<span class="anchor-testimonial__play" aria-hidden="true"></span>';
			$html .= '</button>';
		}

		if ( $layout === 'video-grid' ) {
			$html .= '<figcaption class="anchor-testimonial__person">';
			$html .= '<span class="anchor-testimonial__name">' . esc_html( $meta['person_name'] ) . '</span>';
			$html .= '</figcaption>';
			$html .= '</figure>';
			return $html;
		}

		if ( trim( (string) $post->post_content ) !== '' ) {
			$html .= '<blockquote class="anchor-testimonial__quote">' . wp_kses_post( wpautop( $post->post_content ) ) . '</blockquote>';
		}

		$html .= '<figcaption class="anchor-testimonial__person">';
		if ( has_post_thumbnail( $post->ID ) ) {
			$html .= get_the_post_thumbnail( $post->ID, 'thumbnail', [
				'class'   => 'anchor-testimonial__photo',
				'loading' => 'lazy',
			] );
		}
		$html .= '<span class="anchor-testimonial__name">' . esc_html( $meta['person_name'] ) . '</span>';
		if ( $meta['person_meta'] !== '' ) {
			$html .= '<span class="anchor-testimonial__meta">' . esc_html( $meta['person_meta'] ) . '</span>';
		}
		if ( $meta['rating'] > 0 ) {
			$rating_label = sprintf( /* translators: %d: rating out of 5 */ __( '%d out of 5', 'anchor-schema' ), $meta['rating'] );
			$html        .= '<span class="anchor-testimonial__rating" aria-label="' . esc_attr( $rating_label ) . '">' . str_repeat( '★', $meta['rating'] ) . str_repeat( '☆', 5 - $meta['rating'] ) . '</span>';
		}
		$html .= '</figcaption>';

		$html .= '</figure>';

		return $html;
	}
}
