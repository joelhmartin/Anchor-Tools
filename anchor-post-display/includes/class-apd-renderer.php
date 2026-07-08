<?php
/**
 * Anchor Post Display — shared render pipeline.
 *
 * Pure, stateless rendering used by BOTH the inline [anchor_post_grid]
 * shortcode and the anchor_post_display CPT. Extracted from
 * Anchor_Post_Display_Module so there is a single code path.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_APD_Renderer {

    /* ================================================================
       Query builder
       ================================================================ */

    public static function build_query_args( $params, $page = 1 ) {
        $post_types = self::resolve_post_types( $params['post_type'] );
        $count      = intval( $params['posts'] );
        $max_posts  = max( 0, intval( $params['max_posts'] ?? 0 ) );

        $args = [
            'post_type'        => ! empty( $post_types ) ? $post_types : 'any',
            'posts_per_page'   => $count,
            'post_status'      => 'publish',
            'suppress_filters' => true,
            'orderby'          => sanitize_key( $params['orderby'] ),
            'order'            => in_array( strtoupper( $params['order'] ), [ 'ASC', 'DESC' ], true ) ? strtoupper( $params['order'] ) : 'DESC',
        ];

        if ( -1 === $count ) {
            $args['posts_per_page'] = $max_posts > 0 ? $max_posts : -1;
            $args['no_found_rows']  = true;
        } elseif ( $max_posts > 0 ) {
            $offset = max( 0, ( max( 1, (int) $page ) - 1 ) * $count );
            if ( $offset >= $max_posts ) {
                $args['post__in']       = [ 0 ];
                $args['posts_per_page'] = 1;
            } else {
                $args['offset']         = $offset;
                $args['posts_per_page'] = min( $count, $max_posts - $offset );
            }
        } else {
            $args['paged'] = $page;
        }

        if ( ! empty( $params['search'] ) ) {
            $args['s'] = sanitize_text_field( $params['search'] );
        }

        // Taxonomy filters.
        $tax_query = [];

        if ( ! empty( $params['terms'] ) ) {
            $include = array_filter( array_map( 'trim', explode( ',', $params['terms'] ) ) );
            if ( $include ) {
                $tax_query[] = [
                    'taxonomy' => sanitize_text_field( $params['taxonomy'] ),
                    'field'    => 'slug',
                    'terms'    => $include,
                    'operator' => 'IN',
                ];
            }
        }

        if ( ! empty( $params['exclude_terms'] ) ) {
            $exclude = array_filter( array_map( 'trim', explode( ',', $params['exclude_terms'] ) ) );
            if ( $exclude ) {
                $tax_query[] = [
                    'taxonomy' => sanitize_text_field( $params['exclude_taxonomy'] ?: 'category' ),
                    'field'    => 'slug',
                    'terms'    => $exclude,
                    'operator' => 'NOT IN',
                ];
            }
        }

        if ( $tax_query ) {
            $args['tax_query'] = array_merge( [ 'relation' => 'AND' ], $tax_query );
        }

        return $args;
    }

    /* ================================================================
       Layout markup helpers (shared by inline + CPT paths)
       ================================================================ */

    public static function render_layout_open( $grid_id, $params, $data_attrs ) {
        $layout = $params['layout'];
        if ( in_array( $layout, [ 'slider', 'carousel' ], true ) ) {
            $h  = '<div class="anchor-post-slider anchor-post-slider--' . esc_attr( $layout ) . '">';
            $h .= '<div class="anchor-post-slider-viewport">';
            $h .= '<div id="' . esc_attr( $grid_id ) . '" class="anchor-post-grid anchor-post-slider-track" data-columns="' . intval( $params['columns'] ) . '" data-layout="' . esc_attr( $layout ) . '"' . $data_attrs . '>';
            return $h;
        }
        return '<div id="' . esc_attr( $grid_id ) . '" class="anchor-post-grid" data-columns="' . intval( $params['columns'] ) . '" data-layout="' . esc_attr( $layout ) . '"' . $data_attrs . '>';
    }

    public static function render_layout_close( $params ) {
        $layout = $params['layout'];
        if ( in_array( $layout, [ 'slider', 'carousel' ], true ) ) {
            $arrows = ! empty( $params['carousel_arrows'] ) && $params['carousel_arrows'] !== '0';
            $dots   = ! empty( $params['carousel_dots'] ) && $params['carousel_dots'] !== '0';
            $h  = '</div></div>'; // .track + .viewport
            if ( $arrows ) {
                $h .= '<div class="anchor-post-slider-nav">';
                $h .= '<button type="button" class="anchor-post-slider-btn anchor-post-slider-prev" aria-label="' . esc_attr__( 'Previous posts', 'anchor-schema' ) . '">&lsaquo;</button>';
                $h .= '<button type="button" class="anchor-post-slider-btn anchor-post-slider-next" aria-label="' . esc_attr__( 'Next posts', 'anchor-schema' ) . '">&rsaquo;</button>';
                $h .= '</div>';
            }
            if ( $dots ) {
                $h .= '<div class="anchor-post-slider-dots" aria-hidden="false"></div>';
            }
            $h .= '</div>'; // .anchor-post-slider
            return $h;
        }
        return '</div>';
    }

    /**
     * Build a <style> block scoped to #$grid_id with the per-display responsive
     * columns / slides-per-view, gap, and lean style keys. Pure string function.
     * Breakpoints: tablet <= 1024px, mobile <= 767px.
     */
    public static function build_scoped_css( $grid_id, $params ) {
        $sel    = '#' . $grid_id;
        $card   = $sel . ' .anchor-post-grid-card';
        $title  = $sel . ' .anchor-post-grid-title';
        $layout = $params['layout'] ?? 'grid';
        $css    = '';

        if ( 'grid' === $layout ) {
            $cd = max( 1, (int) ( $params['columns'] ?? 3 ) );
            $ct = max( 1, (int) ( $params['columns_tablet'] ?? 2 ) );
            $cm = max( 1, (int) ( $params['columns_mobile'] ?? 1 ) );
            $css .= $sel . '{display:grid;grid-template-columns:repeat(' . $cd . ',1fr);}';
            $css .= '@media(max-width:1024px){' . $sel . '{grid-template-columns:repeat(' . $ct . ',1fr);}}';
            $css .= '@media(max-width:767px){' . $sel . '{grid-template-columns:repeat(' . $cm . ',1fr);}}';
        } elseif ( in_array( $layout, [ 'slider', 'carousel' ], true ) ) {
            $pd = max( 1, (int) ( $params['slider_per_view'] ?? 3 ) );
            $pt = max( 1, (int) ( $params['slider_per_view_tablet'] ?? 2 ) );
            $pm = max( 1, (int) ( $params['slider_per_view_mobile'] ?? 1 ) );
            // Structural layout emitted inline so it can't be defeated by a stale
            // cached frontend.css. The track is a single non-wrapping flex row.
            $css .= $sel . '{display:flex;flex-wrap:nowrap;}';
            $css .= $sel . '{--apd-per-view:' . $pd . ';}';
            $css .= $card . '{flex:0 0 calc((100% - (var(--apd-gap,16px) * (' . $pd . ' - 1))) / ' . $pd . ');min-width:0;}';
            $css .= '@media(max-width:1024px){' . $card . '{flex-basis:calc((100% - (var(--apd-gap,16px) * (' . $pt . ' - 1))) / ' . $pt . ');}}';
            $css .= '@media(max-width:767px){' . $card . '{flex-basis:calc((100% - (var(--apd-gap,16px) * (' . $pm . ' - 1))) / ' . $pm . ');}}';
        }

        // Dissolve any wrapper a page builder (Divi, wpautop, etc.) injects between
        // the track and the cards, so the cards stay direct grid/flex items.
        $css .= $sel . ' > *:not(.anchor-post-grid-card):not(.anchor-post-grid-empty){display:contents;}';

        $gap = (int) ( $params['gap'] ?? 16 );
        $css .= $sel . '{--apd-gap:' . $gap . 'px;gap:' . $gap . 'px;}';
        $gm = (int) ( $params['gap_mobile'] ?? 0 );
        if ( $gm > 0 ) {
            $css .= '@media(max-width:767px){' . $sel . '{--apd-gap:' . $gm . 'px;gap:' . $gm . 'px;}}';
        }

        $br = (int) ( $params['border_radius'] ?? 0 );
        if ( $br > 0 ) {
            $css .= $card . '{border-radius:' . $br . 'px;overflow:hidden;}';
        }

        $shadow_map = [
            'soft'   => '0 1px 4px rgba(0,0,0,.08)',
            'medium' => '0 4px 12px rgba(0,0,0,.12)',
            'strong' => '0 8px 24px rgba(0,0,0,.18)',
        ];
        if ( ! empty( $params['tile_shadow'] ) && isset( $shadow_map[ $params['tile_shadow'] ] ) ) {
            $css .= $card . '{box-shadow:' . $shadow_map[ $params['tile_shadow'] ] . ';}';
        }
        if ( ! empty( $params['wrapper_bg'] ) ) {
            $css .= $sel . '{background:' . $params['wrapper_bg'] . ';}';
        }
        if ( ! empty( $params['title_color'] ) ) {
            $css .= $title . '{color:' . $params['title_color'] . ';}';
        }
        if ( ! empty( $params['title_size'] ) && (int) $params['title_size'] > 0 ) {
            $css .= $title . '{font-size:' . (int) $params['title_size'] . 'px;}';
        }
        if ( ! empty( $params['title_weight'] ) ) {
            $css .= $title . '{font-weight:' . preg_replace( '/[^0-9]/', '', $params['title_weight'] ) . ';}';
        }

        if ( ! empty( $params['custom_css'] ) ) {
            $css .= preg_replace( '#</?style[^>]*>#i', '', (string) $params['custom_css'] );
        }

        return '<style id="' . $grid_id . '-css">' . $css . '</style>';
    }

    /* ================================================================
       Card renderer
       ================================================================ */

    public static function render_grid_items( $query, $params ) {
        if ( ! $query->have_posts() ) {
            return '<div class="anchor-post-grid-empty">' . esc_html( $params['no_results'] ) . '</div>';
        }

        $show_date  = ( 'yes' === strtolower( $params['show_date'] ) );
        $show_type  = ( 'yes' === strtolower( $params['show_type'] ) );
        $image_size = sanitize_text_field( $params['image_size'] );
        $word_limit = max( 1, intval( $params['teaser_words'] ) );

        // Custom field order: comma-separated list of field names.
        // Built-in tokens: image, title, date, type, excerpt.
        // Anything else is treated as an ACF / post_meta field key.
        $fields = array_filter( array_map( 'trim', explode( ',', $params['fields'] ?? '' ) ) );
        $use_custom_fields = ! empty( $fields );

        // Default field order when none specified (preserves legacy behavior).
        if ( ! $use_custom_fields ) {
            $fields = [ 'image', 'title', 'meta', 'excerpt' ];
        }

        $html = '';
        while ( $query->have_posts() ) {
            $query->the_post();
            $pid = get_the_ID();
            $pto = get_post_type_object( get_post_type() );

            $html .= '<a class="anchor-post-grid-card" href="' . esc_url( get_permalink() ) . '">';

            $body_started = false;

            foreach ( $fields as $field ) {

                // --- Built-in: image ---
                if ( 'image' === $field ) {
                    if ( has_post_thumbnail() ) {
                        $html .= '<div class="anchor-post-grid-image">';
                        $html .= get_the_post_thumbnail( $pid, $image_size, [ 'alt' => esc_attr( get_the_title() ) ] );
                        $html .= '</div>';
                    }
                    continue;
                }

                // Everything after image goes inside .body wrapper.
                if ( ! $body_started ) {
                    $html .= '<div class="anchor-post-grid-body">';
                    $body_started = true;
                }

                // --- Built-in: title ---
                if ( 'title' === $field ) {
                    $html .= '<h3 class="anchor-post-grid-title">' . esc_html( get_the_title() ) . '</h3>';
                    continue;
                }

                // --- Built-in: date ---
                if ( 'date' === $field ) {
                    $html .= '<span class="anchor-post-grid-date">' . esc_html( get_the_date() ) . '</span>';
                    continue;
                }

                // --- Built-in: type ---
                if ( 'type' === $field ) {
                    if ( $pto ) {
                        $html .= '<span class="anchor-post-grid-type-badge">' . esc_html( $pto->labels->singular_name ) . '</span>';
                    }
                    continue;
                }

                // --- Built-in: meta (legacy combo of date + type) ---
                if ( 'meta' === $field ) {
                    if ( $show_date || $show_type ) {
                        $html .= '<div class="anchor-post-grid-meta">';
                        if ( $show_date ) {
                            $html .= '<span class="anchor-post-grid-date">' . esc_html( get_the_date() ) . '</span>';
                        }
                        if ( $show_type && $pto ) {
                            $html .= '<span class="anchor-post-grid-type-badge">' . esc_html( $pto->labels->singular_name ) . '</span>';
                        }
                        $html .= '</div>';
                    }
                    continue;
                }

                // --- Built-in: excerpt ---
                if ( 'excerpt' === $field ) {
                    $teaser = self::get_teaser( $pid, $word_limit );
                    if ( $teaser ) {
                        $html .= '<p class="anchor-post-grid-excerpt">' . $teaser . '</p>';
                    }
                    continue;
                }

                // --- ACF / custom field (fail silently if empty) ---
                $value = self::get_custom_field_html( $pid, $field, $image_size );
                if ( $value ) {
                    $html .= $value;
                }
            }

            if ( ! $body_started ) {
                $html .= '<div class="anchor-post-grid-body">';
            }
            $html .= '</div>'; // .body
            $html .= '</a>';
        }
        wp_reset_postdata();
        return $html;
    }

    /**
     * Resolve a custom / ACF field value and return HTML.
     * Returns empty string if the field is empty or doesn't exist (fail silently).
     */
    public static function get_custom_field_html( $post_id, $field_name, $image_size = 'medium' ) {
        $value = null;

        // Try ACF first (handles repeaters, groups, images, etc.).
        if ( function_exists( 'get_field' ) ) {
            $value = get_field( $field_name, $post_id );
        }

        // Fallback to raw post_meta.
        if ( null === $value || '' === $value ) {
            $value = get_post_meta( $post_id, $field_name, true );
        }

        if ( empty( $value ) ) {
            return '';
        }

        $safe_class = 'anchor-post-grid-field-' . sanitize_html_class( $field_name );

        // ACF image field — returns array with url/sizes, or attachment ID.
        if ( is_array( $value ) && ! empty( $value['url'] ) ) {
            $url = $value['sizes'][ $image_size ] ?? $value['url'];
            return '<div class="anchor-post-grid-image ' . $safe_class . '"><img src="' . esc_url( $url ) . '" alt="' . esc_attr( $value['alt'] ?? '' ) . '" /></div>';
        }
        if ( is_numeric( $value ) && wp_attachment_is_image( (int) $value ) ) {
            return '<div class="anchor-post-grid-image ' . $safe_class . '">' . wp_get_attachment_image( (int) $value, $image_size ) . '</div>';
        }

        // Scalar text / HTML value.
        if ( is_scalar( $value ) ) {
            return '<div class="' . $safe_class . '">' . wp_kses_post( $value ) . '</div>';
        }

        return '';
    }

    /* ================================================================
       Pagination renderer
       ================================================================ */

    public static function render_pagination( $query, $params, $current_page ) {
        $pagination = sanitize_key( $params['pagination'] );
        $total      = self::get_total_pages( $query, $params );

        if ( 'none' === $pagination || $total <= 1 ) {
            return '';
        }

        if ( 'load_more' === $pagination && $current_page < $total ) {
            return '<button class="anchor-post-grid-load-more" type="button">' . esc_html__( 'Load More', 'anchor-schema' ) . '</button>';
        }

        if ( 'numbered' === $pagination ) {
            $window = max( 1, intval( $params['pagination_window'] ?? 7 ) );
            $half   = (int) floor( $window / 2 );
            $start  = max( 1, (int) $current_page - $half );
            $end    = min( $total, $start + $window - 1 );

            if ( ( $end - $start + 1 ) < $window ) {
                $start = max( 1, $end - $window + 1 );
            }

            $html = '<nav class="anchor-post-grid-pagination" aria-label="' . esc_attr__( 'Post pagination', 'anchor-schema' ) . '">';
            if ( $start > 1 ) {
                $html .= '<span class="page-num" data-page="1">1</span>';
                if ( $start > 2 ) {
                    $html .= '<span class="page-dots" aria-hidden="true">&hellip;</span>';
                }
            }
            for ( $i = $start; $i <= $end; $i++ ) {
                $active = ( $i === (int) $current_page ) ? ' is-current' : '';
                $aria   = $active ? ' aria-current="page"' : '';
                $html .= '<span class="page-num' . $active . '" data-page="' . $i . '"' . $aria . '>' . $i . '</span>';
            }
            if ( $end < $total ) {
                if ( $end < $total - 1 ) {
                    $html .= '<span class="page-dots" aria-hidden="true">&hellip;</span>';
                }
                $html .= '<span class="page-num" data-page="' . $total . '">' . $total . '</span>';
            }
            $html .= '</nav>';
            return $html;
        }

        return '';
    }

    /* ================================================================
       Helpers
       ================================================================ */

    public static function normalize_post_count( $value ) {
        $count = intval( $value );
        if ( -1 === $count ) {
            return -1;
        }
        return max( 1, min( 100, $count ) );
    }

    public static function get_total_pages( $query, $params ) {
        $per_page = intval( $params['posts'] ?? 0 );
        if ( -1 === $per_page ) {
            return 1;
        }

        $total_posts = (int) $query->found_posts;
        $max_posts   = max( 0, intval( $params['max_posts'] ?? 0 ) );
        if ( $max_posts > 0 ) {
            $total_posts = min( $total_posts, $max_posts );
        }

        return max( 1, (int) ceil( $total_posts / max( 1, $per_page ) ) );
    }

    public static function resolve_post_types( $csv ) {
        $csv = trim( (string) $csv );
        if ( '' !== $csv ) {
            return array_filter( array_map( 'trim', explode( ',', $csv ) ) );
        }
        return self::get_searchable_types();
    }

    public static function get_searchable_types() {
        $types = array_values( get_post_types( [ 'exclude_from_search' => false ], 'names' ) );
        return array_diff( $types, [ 'attachment' ] );
    }

    /**
     * Teaser text: ACF short_description > excerpt > SEO meta > content fallback.
     */
    public static function get_teaser( $post_id, $limit = 26 ) {
        // ACF short_description.
        if ( function_exists( 'get_field' ) ) {
            $acf = get_field( 'short_description', $post_id );
            if ( $acf ) {
                return wp_kses_post( $acf );
            }
        }

        // WP excerpt.
        $excerpt = get_the_excerpt( $post_id );
        if ( ! empty( $excerpt ) ) {
            return wp_kses_post( $excerpt );
        }

        // SEO plugin meta descriptions.
        $meta_keys = [
            '_yoast_wpseo_metadesc',
            '_rank_math_description',
            'rank_math_description',
            '_seopress_titles_desc',
            '_aioseo_description',
        ];
        foreach ( $meta_keys as $key ) {
            $val = get_post_meta( $post_id, $key, true );
            if ( ! empty( $val ) ) {
                return esc_html( $val );
            }
        }

        // Clean content fallback.
        $raw   = get_post_field( 'post_content', $post_id );
        $plain = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( strip_shortcodes( (string) $raw ) ) ) );
        if ( '' !== $plain ) {
            $words = preg_split( '/\s+/', $plain );
            if ( count( $words ) > $limit ) {
                $plain = implode( ' ', array_slice( $words, 0, $limit ) ) . "\u{2026}";
            }
            return esc_html( $plain );
        }

        return '';
    }
}
