<?php
/**
 * Renders a WP_Post[] of anchor_speaker posts (from Anchor_Speakers_Module's
 * shortcode query) into the [anchor_speakers] shortcode markup: grid, list
 * or compact layout sharing card markup so themes only style one contract.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Speaker_Render {
	const ALLOWED_LAYOUTS = [ 'grid', 'list', 'compact' ];
	const ALLOWED_SHOW    = [ 'credentials', 'title', 'location', 'excerpt' ];

	private static function layout( array $atts ) {
		$layout = $atts['layout'] ?? 'grid';
		return in_array( $layout, self::ALLOWED_LAYOUTS, true ) ? $layout : 'grid';
	}

	private static function columns( array $atts ) {
		return max( 1, min( 6, (int) ( $atts['columns'] ?? 3 ) ) );
	}

	private static function show( array $atts ) {
		$requested = isset( $atts['show'] ) ? array_map( 'trim', explode( ',', (string) $atts['show'] ) ) : self::ALLOWED_SHOW;
		return array_values( array_intersect( self::ALLOWED_SHOW, $requested ) );
	}

	private static function link_enabled( array $atts ) {
		return ! isset( $atts['link'] ) || (string) $atts['link'] !== '0';
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
		$show    = self::show( $atts );
		$link    = self::link_enabled( $atts );

		$style = '--as-cols:' . $columns . ';';

		$cards = '';
		foreach ( $posts as $post ) {
			$cards .= self::card( $post, $show, $link );
		}

		return '<div class="anchor-speakers anchor-speakers--' . esc_attr( $layout ) . '" style="' . esc_attr( $style ) . '">' . $cards . '</div>';
	}

	private static function card( WP_Post $post, array $show, $link ) {
		$meta      = Anchor_Speaker_Meta::get( $post->ID );
		$permalink = get_permalink( $post );
		$name      = get_the_title( $post );

		$html = '<article class="anchor-speaker">';

		if ( has_post_thumbnail( $post->ID ) ) {
			$img = get_the_post_thumbnail( $post->ID, 'medium', [ 'loading' => 'lazy' ] );
			if ( $link ) {
				$html .= '<a class="anchor-speaker__photo" href="' . esc_url( $permalink ) . '">' . $img . '</a>';
			} else {
				$html .= '<span class="anchor-speaker__photo">' . $img . '</span>';
			}
		}

		$html .= '<h3 class="anchor-speaker__name">';
		$html .= $link ? '<a href="' . esc_url( $permalink ) . '">' . esc_html( $name ) . '</a>' : esc_html( $name );
		$html .= '</h3>';

		if ( in_array( 'credentials', $show, true ) && $meta['credentials'] !== '' ) {
			$html .= '<p class="anchor-speaker__credentials">' . esc_html( $meta['credentials'] ) . '</p>';
		}
		if ( in_array( 'title', $show, true ) && $meta['title'] !== '' ) {
			$html .= '<p class="anchor-speaker__title">' . esc_html( $meta['title'] ) . '</p>';
		}
		if ( in_array( 'location', $show, true ) && $meta['location'] !== '' ) {
			$html .= '<p class="anchor-speaker__location">' . esc_html( $meta['location'] ) . '</p>';
		}
		if ( in_array( 'excerpt', $show, true ) ) {
			$excerpt = get_the_excerpt( $post );
			if ( trim( (string) $excerpt ) !== '' ) {
				// get_the_excerpt() is already HTML, not plain text: a manual
				// excerpt may intentionally contain simple inline markup, the
				// same way testimonials treats post_content as already-HTML
				// via wp_kses_post( wpautop( ... ) ) rather than esc_html().
				// esc_html() would strip that markup down to visible tag text.
				$html .= '<p class="anchor-speaker__excerpt">' . wp_kses_post( $excerpt ) . '</p>';
			}
		}

		if ( $link ) {
			$html .= '<a class="anchor-speaker__link" href="' . esc_url( $permalink ) . '">' . esc_html__( 'View profile', 'anchor-schema' ) . '</a>';
		}

		$html .= '</article>';

		return $html;
	}
}
