<?php
/**
 * Renders a WP_Post[] of anchor_speaker posts (from Anchor_Speakers_Module's
 * shortcode query) into the [anchor_speakers] shortcode markup: grid, list
 * or compact layout sharing card markup so themes only style one contract.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Speaker_Render {
	const ALLOWED_LAYOUTS = [ 'grid', 'list', 'compact', 'avatars' ];
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
		$link    = self::link_enabled( $atts );

		if ( $layout === 'avatars' ) {
			return '<div class="anchor-speakers anchor-speakers--avatars">' . self::avatars( $posts, $link ) . '</div>';
		}

		$columns = self::columns( $atts );
		$show    = self::show( $atts );
		$style   = '--as-cols:' . $columns . ';';

		$cards = '';
		foreach ( $posts as $post ) {
			$cards .= self::card( $post, $show, $link, $layout );
		}

		return '<div class="anchor-speakers anchor-speakers--' . esc_attr( $layout ) . '" style="' . esc_attr( $style ) . '">' . $cards . '</div>';
	}

	/**
	 * `layout="avatars"`: a compact overlapping stack of circular headshots,
	 * no names/credentials/title visible - just the photo, with the name as
	 * its accessible label (the `<img>` alt, or an aria-label on the wrapper
	 * when there is no photo to carry an alt attribute).
	 */
	private static function avatars( array $posts, $link ) {
		$html = '';
		foreach ( $posts as $post ) {
			$permalink   = get_permalink( $post );
			$name        = get_the_title( $post );
			$has_photo   = has_post_thumbnail( $post->ID );
			$tag         = $link ? 'a' : 'span';

			$html .= '<' . $tag . ' class="anchor-speaker-avatar"';
			if ( $link ) {
				$html .= ' href="' . esc_url( $permalink ) . '"';
			}
			if ( ! $has_photo ) {
				// No <img> to carry an alt attribute, so the accessible name
				// lives on the wrapper instead.
				$html .= ' aria-label="' . esc_attr( $name ) . '"';
			}
			$html .= '>';

			if ( $has_photo ) {
				$html .= get_the_post_thumbnail( $post->ID, 'thumbnail', [
					'class'   => 'anchor-speaker-avatar__img',
					'alt'     => $name,
					'loading' => 'lazy',
				] );
			} else {
				$initial = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 1 ) : substr( $name, 0, 1 );
				$html   .= '<span class="anchor-speaker-avatar__img anchor-speaker-avatar__img--placeholder" aria-hidden="true">' . esc_html( $initial ) . '</span>';
			}

			$html .= '</' . $tag . '>';
		}
		return $html;
	}

	private static function card( WP_Post $post, array $show, $link, $layout = 'grid' ) {
		$meta      = Anchor_Speaker_Meta::get( $post->ID );
		$permalink = get_permalink( $post );
		$name      = get_the_title( $post );
		$is_list   = $layout === 'list';
		$has_credentials = in_array( 'credentials', $show, true ) && $meta['credentials'] !== '';

		$html = '<article class="anchor-speaker">';

		if ( has_post_thumbnail( $post->ID ) ) {
			$img = get_the_post_thumbnail( $post->ID, 'medium', [ 'loading' => 'lazy' ] );
			if ( $link ) {
				$html .= '<a class="anchor-speaker__photo" href="' . esc_url( $permalink ) . '">' . $img . '</a>';
			} else {
				$html .= '<span class="anchor-speaker__photo">' . $img . '</span>';
			}
		}

		// List layout puts "Name, credentials" on one visual line: the
		// credentials part nests inside the same heading (as
		// .anchor-speaker__credentials, same class other layouts render as a
		// sibling <p>) so it flows inline after the name instead of wrapping
		// to its own line.
		$html .= '<h3 class="anchor-speaker__name">';
		$html .= $link ? '<a href="' . esc_url( $permalink ) . '">' . esc_html( $name ) . '</a>' : esc_html( $name );
		if ( $is_list && $has_credentials ) {
			$html .= '<span class="anchor-speaker__credentials">, ' . esc_html( $meta['credentials'] ) . '</span>';
		}
		$html .= '</h3>';

		if ( ! $is_list && $has_credentials ) {
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
			if ( $is_list ) {
				// The row design's trailing "View" link with an arrow glyph;
				// __cta is an additional modifier on the same __link part
				// (same href, same underlying link), not a replacement.
				$html .= '<a class="anchor-speaker__link anchor-speaker__cta" href="' . esc_url( $permalink ) . '">' . esc_html__( 'View', 'anchor-schema' ) . '<span aria-hidden="true"> &#8594;</span></a>';
			} else {
				$html .= '<a class="anchor-speaker__link" href="' . esc_url( $permalink ) . '">' . esc_html__( 'View profile', 'anchor-schema' ) . '</a>';
			}
		}

		$html .= '</article>';

		return $html;
	}
}
