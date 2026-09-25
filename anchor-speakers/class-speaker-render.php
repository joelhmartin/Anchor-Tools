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
			$cards .= $layout === 'list' ? self::list_card( $post, $show, $link ) : self::card( $post, $show, $link );
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

			$html .= $has_photo
				? get_the_post_thumbnail( $post->ID, 'thumbnail', [
					'class'   => 'anchor-speaker-avatar__img',
					'alt'     => $name,
					'loading' => 'lazy',
				] )
				: self::photo_placeholder( $name );

			$html .= '</' . $tag . '>';
		}
		return $html;
	}

	/**
	 * The initial-letter placeholder circle shown when a speaker has no
	 * photo. Shared by `layout="avatars"` and `layout="list"` - the two
	 * layouts that always keep a photo/avatar slot in the row even without
	 * a real photo, so a photo-less speaker doesn't shift out of alignment
	 * with the rest - so there is one implementation of "no photo, show an
	 * initial" rather than two. Reuses the avatars layout's own classes
	 * (`anchor-speaker-avatar__img`, `anchor-speaker-avatar__img--placeholder`)
	 * and therefore its `--as-avatar-placeholder-bg`/`--as-avatar-placeholder-fg`
	 * custom properties, regardless of which layout's wrapper it ends up in.
	 */
	private static function photo_placeholder( $name ) {
		$initial = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 1 ) : substr( $name, 0, 1 );
		return '<span class="anchor-speaker-avatar__img anchor-speaker-avatar__img--placeholder" aria-hidden="true">' . esc_html( $initial ) . '</span>';
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

	/**
	 * `layout="list"`: a compact row (owner feedback round 2, 2.png) - round
	 * photo left, a tight two-(or more-)line text block vertically centered
	 * beside it, and a trailing "View" CTA vertically centered at the row's
	 * edge. All non-photo, non-CTA text (name, the role line, location,
	 * excerpt) is wrapped in one `.anchor-speaker__body` element instead of
	 * being scattered as separate grid items: that both collapses the row to
	 * a single grid row (so `align-items: center` on the row centers photo/
	 * body/CTA against each other directly, instead of the photo spanning
	 * across several loosely-spaced implicit rows) and means the compact
	 * ~4px line spacing comes from `.anchor-speaker__body`'s own flex `gap`
	 * rather than each child's margin, which a theme's own heading/paragraph
	 * margins could otherwise widen or override.
	 *
	 * The role line prefers the title; when a speaker has no title, its
	 * credentials become the role line instead (reusing the
	 * `.anchor-speaker__title` class, so it's styled identically - plain
	 * body text, not the inline "name, credentials" treatment) rather than
	 * leaving the line empty. Credentials appear in exactly one place per
	 * card: inline after the name (only when a title also exists, so the
	 * role line beneath it is the title) or as the role line itself (only
	 * when there is no title) - never both, which would repeat them.
	 *
	 * The photo column always renders, even for a speaker with no featured
	 * image (owner feedback round 3): a real photo, or the shared
	 * `photo_placeholder()` initial-letter circle otherwise. Without that,
	 * a photo-less speaker had no `.anchor-speaker__photo` element at all,
	 * so the CSS's `:has( > .anchor-speaker__photo )` column never matched
	 * and that one row's text shifted left out of alignment with every
	 * other row.
	 */
	private static function list_card( WP_Post $post, array $show, $link ) {
		$meta      = Anchor_Speaker_Meta::get( $post->ID );
		$permalink = get_permalink( $post );
		$name      = get_the_title( $post );

		$has_credentials = in_array( 'credentials', $show, true ) && $meta['credentials'] !== '';
		$has_title       = in_array( 'title', $show, true ) && $meta['title'] !== '';
		$role_line       = $has_title ? $meta['title'] : ( $has_credentials ? $meta['credentials'] : '' );
		$credentials_inline = $has_title && $has_credentials;

		$html = '<article class="anchor-speaker">';

		$photo_inner = has_post_thumbnail( $post->ID )
			? get_the_post_thumbnail( $post->ID, 'medium', [ 'loading' => 'lazy' ] )
			: self::photo_placeholder( $name );
		if ( $link ) {
			$html .= '<a class="anchor-speaker__photo" href="' . esc_url( $permalink ) . '">' . $photo_inner . '</a>';
		} else {
			$html .= '<span class="anchor-speaker__photo">' . $photo_inner . '</span>';
		}

		$html .= '<div class="anchor-speaker__body">';

		$html .= '<h3 class="anchor-speaker__name">';
		$html .= $link ? '<a href="' . esc_url( $permalink ) . '">' . esc_html( $name ) . '</a>' : esc_html( $name );
		if ( $credentials_inline ) {
			$html .= '<span class="anchor-speaker__credentials">, ' . esc_html( $meta['credentials'] ) . '</span>';
		}
		$html .= '</h3>';

		if ( $role_line !== '' ) {
			$html .= '<p class="anchor-speaker__title">' . esc_html( $role_line ) . '</p>';
		}
		if ( in_array( 'location', $show, true ) && $meta['location'] !== '' ) {
			$html .= '<p class="anchor-speaker__location">' . esc_html( $meta['location'] ) . '</p>';
		}
		if ( in_array( 'excerpt', $show, true ) ) {
			$excerpt = get_the_excerpt( $post );
			if ( trim( (string) $excerpt ) !== '' ) {
				$html .= '<p class="anchor-speaker__excerpt">' . wp_kses_post( $excerpt ) . '</p>';
			}
		}

		$html .= '</div>';

		if ( $link ) {
			// The row design's trailing "View" link with an arrow glyph;
			// __cta is an additional modifier on the same __link part (same
			// href, same underlying link), not a replacement.
			$html .= '<a class="anchor-speaker__link anchor-speaker__cta" href="' . esc_url( $permalink ) . '">' . esc_html__( 'View', 'anchor-schema' ) . '<span aria-hidden="true"> &#8594;</span></a>';
		}

		$html .= '</article>';

		return $html;
	}
}
