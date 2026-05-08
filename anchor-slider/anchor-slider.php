<?php
/**
 * Anchor Tools module: Anchor Slider.
 *
 * Slide-deck style slider with HTML / video / image slides, full-width
 * support, background images and overlays. Distinct from the Gallery
 * module: galleries are item collections (filter, masonry, lightbox);
 * sliders are layered slide decks (hero banners, testimonial decks,
 * shortcode-rich content slides).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Anchor_Slider_Module {
    const CPT   = 'anchor_slider';
    const NONCE = 'as_nonce';

    private $default_settings = [
        'autoplay'         => false,
        'autoplay_speed'   => 5000,
        'loop'             => true,
        'arrows'           => true,
        'dots'             => true,
        'transition'       => 'slide', // slide | fade
        'height_mode'      => 'auto',  // auto | fixed | viewport
        'fixed_height'     => 480,
        'viewport_pct'     => 80,
        'pause_on_hover'   => true,
        'theme'            => 'auto',
    ];

    public function __construct() {
        add_action( 'init', [ $this, 'register_cpt' ] );
        add_action( 'add_meta_boxes', [ $this, 'add_metaboxes' ] );
        add_action( 'edit_form_after_title', [ $this, 'render_builder_after_title' ] );
        add_action( 'save_post', [ $this, 'save_meta' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        add_shortcode( 'anchor_slider', [ $this, 'render_shortcode' ] );

        add_action( 'wp_ajax_as_get_attachment_url', [ $this, 'ajax_attachment_url' ] );

        add_filter( 'manage_' . self::CPT . '_posts_columns', [ $this, 'admin_columns' ] );
        add_action( 'manage_' . self::CPT . '_posts_custom_column', [ $this, 'admin_column_content' ], 10, 2 );
    }

    public function register_cpt() {
        register_post_type( self::CPT, [
            'labels' => [
                'name'          => 'Anchor Sliders',
                'singular_name' => 'Anchor Slider',
                'add_new'       => 'Add New Slider',
                'add_new_item'  => 'Add New Slider',
                'edit_item'     => 'Edit Slider',
                'menu_name'     => 'Sliders',
            ],
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => apply_filters( 'anchor_slider_parent_menu', true ),
            'menu_icon'           => 'dashicons-slides',
            'menu_position'       => 26,
            'supports'            => [ 'title' ],
            'capability_type'     => 'post',
            'has_archive'         => false,
            'rewrite'             => false,
            'exclude_from_search' => true,
            'publicly_queryable'  => false,
        ] );
    }

    /* ── Setting definitions ─────────────────────── */

    private function get_setting_defs() {
        return [
            'theme'          => [ 'type' => 'select', 'label' => 'Theme', 'section' => 'style', 'options' => [ 'dark' => 'Dark', 'light' => 'Light', 'auto' => 'Auto' ] ],
            'transition'     => [ 'type' => 'select', 'label' => 'Transition', 'section' => 'behavior', 'options' => [ 'slide' => 'Slide', 'fade' => 'Fade' ] ],
            'height_mode'    => [ 'type' => 'select', 'label' => 'Height Mode', 'section' => 'layout', 'options' => [ 'auto' => 'Auto (slide content)', 'fixed' => 'Fixed pixel height', 'viewport' => 'Percent of viewport' ] ],
            'fixed_height'   => [ 'type' => 'number', 'label' => 'Fixed Height (px)', 'section' => 'layout', 'min' => 100, 'max' => 1200, 'step' => 10, 'show_for' => 'fixed' ],
            'viewport_pct'   => [ 'type' => 'number', 'label' => 'Viewport Height %', 'section' => 'layout', 'min' => 20, 'max' => 100, 'step' => 5, 'show_for' => 'viewport' ],
            'autoplay'       => [ 'type' => 'checkbox', 'label' => 'Auto-advance slides', 'section' => 'behavior' ],
            'autoplay_speed' => [ 'type' => 'number', 'label' => 'Auto-advance speed (ms)', 'section' => 'behavior', 'min' => 1500, 'max' => 20000, 'step' => 500 ],
            'loop'           => [ 'type' => 'checkbox', 'label' => 'Loop continuously', 'section' => 'behavior' ],
            'pause_on_hover' => [ 'type' => 'checkbox', 'label' => 'Pause on hover', 'section' => 'behavior' ],
            'arrows'         => [ 'type' => 'checkbox', 'label' => 'Show navigation arrows', 'section' => 'behavior' ],
            'dots'           => [ 'type' => 'checkbox', 'label' => 'Show dots / indicators', 'section' => 'behavior' ],
        ];
    }

    private function get_settings_by_section() {
        $defs    = $this->get_setting_defs();
        $grouped = [];
        foreach ( $defs as $key => $def ) {
            $section = $def['section'] ?? 'advanced';
            $grouped[ $section ][ $key ] = $def;
        }
        return $grouped;
    }

    /* ── Admin: builder ──────────────────────────── */

    public function add_metaboxes() {
        // Builder is rendered via edit_form_after_title; no metaboxes needed.
    }

    public function render_builder_after_title( $post ) {
        if ( ! ( $post instanceof WP_Post ) || $post->post_type !== self::CPT ) {
            return;
        }
        wp_nonce_field( self::NONCE, self::NONCE );

        $tabs = [
            'slides'     => 'Slides',
            'style'      => 'Style',
            'behavior'   => 'Behavior',
            'responsive' => 'Responsive',
            'advanced'   => 'Advanced',
        ];

        $panels = [];
        $panels['slides'] = [ $this, 'render_pane_slides' ];
        foreach ( [ 'style', 'behavior', 'responsive', 'advanced' ] as $key ) {
            $panels[ $key ] = function ( $p ) use ( $key ) {
                $this->render_pane_section( $p, $key );
            };
        }

        Anchor_Builder_Shell::render( [
            'id'        => 'anchor-slider-builder',
            'post'      => $post,
            'title'     => $post->post_title ?: 'Untitled slider',
            'shortcode' => '[anchor_slider id="' . $post->ID . '"]',
            'view_url'  => get_permalink( $post ),
            'tabs'      => $tabs,
            'panels'    => $panels,
            'preview'   => [ $this, 'render_pane_preview' ],
            'utility'   => [ $this, 'render_pane_utility' ],
        ] );

        // Side panel is rendered once and reused for any slide.
        $this->render_slide_side_panel();
    }

    public function render_pane_slides( $post ) {
        $slides = get_post_meta( $post->ID, 'as_slides', true );
        if ( ! is_array( $slides ) ) {
            $slides = [];
        }
        ?>
        <div class="anchor-builder__items">
            <div class="anchor-builder__item-list" id="as-slide-list">
                <?php foreach ( $slides as $i => $slide ) {
                    $this->render_slide_card( $i, $slide );
                } ?>
            </div>
            <div class="anchor-builder__add-item">
                <button type="button" class="button" data-action="add-slide" data-type="html">+ HTML Slide</button>
                <button type="button" class="button" data-action="add-slide" data-type="image">+ Image Slide</button>
                <button type="button" class="button" data-action="add-slide" data-type="video">+ Video Slide</button>
            </div>
        </div>
        <?php
    }

    private function render_slide_card( $index, $slide ) {
        $type      = $slide['type'] ?? 'html';
        $title     = $slide['title'] ?? '';
        $thumb     = '';
        $att_id    = intval( $slide['background']['attachment_id'] ?? 0 );
        if ( $att_id ) {
            $img = wp_get_attachment_image_url( $att_id, 'thumbnail' );
            if ( $img ) {
                $thumb = $img;
            }
        }
        $label = $title !== '' ? $title : ( ucfirst( $type ) . ' slide' );
        ?>
        <div class="anchor-builder__item-card" data-index="<?php echo esc_attr( $index ); ?>" draggable="true">
            <span class="anchor-builder__item-handle">⋮⋮</span>
            <div class="anchor-builder__item-thumb"<?php if ( $thumb ) : ?> style="background-image:url(<?php echo esc_url( $thumb ); ?>)"<?php endif; ?>></div>
            <div class="anchor-builder__item-body">
                <div class="anchor-builder__item-title"><?php echo esc_html( $label ); ?></div>
                <div class="anchor-builder__item-meta"><?php echo esc_html( ucfirst( $type ) ); ?></div>
            </div>
            <div class="anchor-builder__item-actions">
                <button type="button" class="button-link" data-action="edit-slide" data-index="<?php echo esc_attr( $index ); ?>">Edit</button>
                <button type="button" class="button-link button-link-delete" data-action="remove-slide" data-index="<?php echo esc_attr( $index ); ?>">Remove</button>
            </div>
            <input type="hidden" class="as-slide-data" name="as_slides[<?php echo esc_attr( $index ); ?>]" value="<?php echo esc_attr( wp_json_encode( $slide ) ); ?>" />
        </div>
        <?php
    }

    /**
     * The single, reusable side panel used to edit any slide.
     */
    private function render_slide_side_panel() {
        ?>
        <div class="anchor-builder__side-panel" id="as-slide-panel" aria-hidden="true">
            <div class="anchor-builder__side-panel-header">
                <h2 class="anchor-builder__side-panel-title">Edit slide</h2>
                <button type="button" class="anchor-builder__side-panel-close" aria-label="Close">×</button>
            </div>
            <div class="anchor-builder__side-panel-body" id="as-slide-form">
                <input type="hidden" id="as-edit-index" value="" />
                <p class="anchor-builder__field">
                    <label><strong>Title</strong></label>
                    <input type="text" id="as-f-title" class="widefat" />
                </p>
                <p class="anchor-builder__field">
                    <label><strong>Slide type</strong></label>
                    <select id="as-f-type" class="widefat">
                        <option value="html">Custom HTML</option>
                        <option value="image">Image</option>
                        <option value="video">Video</option>
                    </select>
                </p>

                <div class="as-fields-html" data-type-fields="html">
                    <p class="anchor-builder__field">
                        <label><strong>HTML / shortcodes</strong></label>
                        <textarea id="as-f-html" rows="6" class="widefat"></textarea>
                        <span class="anchor-builder__help">Shortcodes will be processed (e.g. [anchor_reviews], [contact-form-7]).</span>
                    </p>
                </div>

                <div class="as-fields-image" data-type-fields="image">
                    <p class="anchor-builder__field">
                        <label><strong>Image</strong></label>
                        <input type="hidden" id="as-f-image-id" />
                        <button type="button" class="button" id="as-f-image-pick">Choose Image</button>
                        <button type="button" class="button-link" id="as-f-image-clear">Clear</button>
                        <img id="as-f-image-preview" style="display:block;max-width:100%;margin-top:8px" />
                    </p>
                </div>

                <div class="as-fields-video" data-type-fields="video">
                    <p class="anchor-builder__field">
                        <label><strong>Video URL (YouTube/Vimeo or .mp4)</strong></label>
                        <input type="url" id="as-f-video-url" class="widefat" placeholder="https://..." />
                    </p>
                </div>

                <h3 style="margin-top:24px;font-size:13px;text-transform:uppercase;color:#757575">Background</h3>
                <p class="anchor-builder__field">
                    <label><strong>Background type</strong></label>
                    <select id="as-f-bg-type" class="widefat">
                        <option value="none">None</option>
                        <option value="color">Solid color</option>
                        <option value="image">Image</option>
                    </select>
                </p>
                <p class="anchor-builder__field" data-bg-fields="color">
                    <label><strong>Background color</strong></label>
                    <input type="text" id="as-f-bg-color" class="widefat" placeholder="#000000 or rgba(0,0,0,0.5)" />
                </p>
                <p class="anchor-builder__field" data-bg-fields="image">
                    <label><strong>Background image</strong></label>
                    <input type="hidden" id="as-f-bg-image-id" />
                    <button type="button" class="button" id="as-f-bg-image-pick">Choose Image</button>
                    <button type="button" class="button-link" id="as-f-bg-image-clear">Clear</button>
                    <img id="as-f-bg-image-preview" style="display:block;max-width:100%;margin-top:8px" />
                </p>
                <p class="anchor-builder__field">
                    <label><strong>Overlay (rgba)</strong></label>
                    <input type="text" id="as-f-bg-overlay" class="widefat" placeholder="rgba(0,0,0,0.4)" />
                </p>

                <h3 style="margin-top:24px;font-size:13px;text-transform:uppercase;color:#757575">Layout</h3>
                <p class="anchor-builder__field">
                    <label class="anchor-builder__checkbox">
                        <input type="checkbox" id="as-f-fullwidth" />
                        <strong>Full-width slide (break out of container)</strong>
                    </label>
                </p>
                <p class="anchor-builder__field">
                    <label><strong>Horizontal align</strong></label>
                    <select id="as-f-align" class="widefat">
                        <option value="left">Left</option>
                        <option value="center" selected>Center</option>
                        <option value="right">Right</option>
                    </select>
                </p>
                <p class="anchor-builder__field">
                    <label><strong>Vertical align</strong></label>
                    <select id="as-f-vertical" class="widefat">
                        <option value="top">Top</option>
                        <option value="middle" selected>Middle</option>
                        <option value="bottom">Bottom</option>
                    </select>
                </p>

                <h3 style="margin-top:24px;font-size:13px;text-transform:uppercase;color:#757575">Link (optional)</h3>
                <p class="anchor-builder__field">
                    <label><strong>Link URL</strong></label>
                    <input type="url" id="as-f-link-url" class="widefat" />
                </p>
                <p class="anchor-builder__field">
                    <label><strong>Button text</strong></label>
                    <input type="text" id="as-f-link-text" class="widefat" />
                </p>

                <h3 style="margin-top:24px;font-size:13px;text-transform:uppercase;color:#757575">Visibility</h3>
                <p class="anchor-builder__field">
                    <label class="anchor-builder__checkbox"><input type="checkbox" id="as-f-hide-desktop" /> <strong>Hide on desktop</strong></label>
                </p>
                <p class="anchor-builder__field">
                    <label class="anchor-builder__checkbox"><input type="checkbox" id="as-f-hide-tablet" /> <strong>Hide on tablet</strong></label>
                </p>
                <p class="anchor-builder__field">
                    <label class="anchor-builder__checkbox"><input type="checkbox" id="as-f-hide-mobile" /> <strong>Hide on mobile</strong></label>
                </p>
            </div>
            <div class="anchor-builder__side-panel-footer">
                <button type="button" class="button" data-action="close-panel">Cancel</button>
                <button type="button" class="button button-primary" id="as-f-save">Apply</button>
            </div>
        </div>
        <?php
    }

    public function render_pane_section( $post, $section ) {
        $grouped = $this->get_settings_by_section();
        if ( empty( $grouped[ $section ] ) ) {
            echo '<p class="anchor-builder__empty">No settings in this section.</p>';
            return;
        }
        foreach ( $grouped[ $section ] as $key => $def ) {
            $meta_key = 'as_' . $key;
            $saved    = get_post_meta( $post->ID, $meta_key, true );
            $value    = ( $saved !== '' && $saved !== false ) ? $saved : ( $this->default_settings[ $key ] ?? '' );
            Anchor_Builder_Shell::render_field( $key, $def, $value, $meta_key );
        }
    }

    public function render_pane_preview( $post ) {
        ?>
        <div class="as-preview-wrap">
            <?php echo $this->render_slider_html( $post->ID, true ); ?>
        </div>
        <?php
    }

    public function render_pane_utility( $post ) {
        $slides = get_post_meta( $post->ID, 'as_slides', true );
        $count  = is_array( $slides ) ? count( $slides ) : 0;
        ?>
        <div class="anchor-builder__util-row"><span class="anchor-builder__util-label">Status</span><span class="anchor-builder__util-value"><?php echo esc_html( ucfirst( $post->post_status ) ); ?></span></div>
        <div class="anchor-builder__util-row"><span class="anchor-builder__util-label">Slides</span><span class="anchor-builder__util-value"><?php echo intval( $count ); ?></span></div>
        <div class="anchor-builder__util-row"><span class="anchor-builder__util-label">ID</span><span class="anchor-builder__util-value"><?php echo intval( $post->ID ); ?></span></div>
        <?php
    }

    /* ── Save ────────────────────────────────────── */

    public function save_meta( $post_id ) {
        if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( $_POST[ self::NONCE ], self::NONCE ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // Slides — sent as a JSON-encoded hidden input per slide.
        $raw_slides = isset( $_POST['as_slides'] ) && is_array( $_POST['as_slides'] ) ? $_POST['as_slides'] : [];
        $slides     = [];
        foreach ( $raw_slides as $raw ) {
            if ( is_array( $raw ) ) {
                $slide = $raw;
            } else {
                $decoded = json_decode( wp_unslash( $raw ), true );
                if ( ! is_array( $decoded ) ) {
                    continue;
                }
                $slide = $decoded;
            }
            $slides[] = $this->sanitize_slide( $slide );
        }
        update_post_meta( $post_id, 'as_slides', $slides );

        // Settings
        $defs = $this->get_setting_defs();
        foreach ( $defs as $key => $def ) {
            $meta_key = 'as_' . $key;
            if ( $def['type'] === 'checkbox' ) {
                $val = isset( $_POST[ $meta_key ] ) ? '1' : '0';
            } elseif ( $def['type'] === 'number' ) {
                $val = isset( $_POST[ $meta_key ] ) ? intval( $_POST[ $meta_key ] ) : ( $this->default_settings[ $key ] ?? 0 );
                if ( isset( $def['min'] ) ) $val = max( $def['min'], $val );
                if ( isset( $def['max'] ) ) $val = min( $def['max'], $val );
            } elseif ( $def['type'] === 'select' ) {
                $val = isset( $_POST[ $meta_key ] ) ? sanitize_text_field( $_POST[ $meta_key ] ) : ( $this->default_settings[ $key ] ?? '' );
                if ( isset( $def['options'] ) && ! array_key_exists( $val, $def['options'] ) ) {
                    $val = $this->default_settings[ $key ] ?? '';
                }
            } else {
                $val = isset( $_POST[ $meta_key ] ) ? sanitize_text_field( $_POST[ $meta_key ] ) : '';
            }
            update_post_meta( $post_id, $meta_key, $val );
        }
    }

    private function sanitize_slide( $slide ) {
        $type = in_array( $slide['type'] ?? '', [ 'html', 'image', 'video' ], true ) ? $slide['type'] : 'html';
        $out  = [
            'type'      => $type,
            'title'     => sanitize_text_field( $slide['title'] ?? '' ),
            'fullwidth' => ! empty( $slide['fullwidth'] ),
            'align'     => in_array( $slide['align'] ?? '', [ 'left', 'center', 'right' ], true ) ? $slide['align'] : 'center',
            'vertical'  => in_array( $slide['vertical'] ?? '', [ 'top', 'middle', 'bottom' ], true ) ? $slide['vertical'] : 'middle',
        ];

        if ( $type === 'html' ) {
            $out['html'] = wp_kses_post( $slide['html'] ?? '' );
        } elseif ( $type === 'image' ) {
            $out['attachment_id'] = absint( $slide['attachment_id'] ?? 0 );
        } elseif ( $type === 'video' ) {
            $out['url'] = esc_url_raw( $slide['url'] ?? '' );
        }

        $bg                = is_array( $slide['background'] ?? null ) ? $slide['background'] : [];
        $bg_type           = in_array( $bg['type'] ?? '', [ 'none', 'color', 'image' ], true ) ? $bg['type'] : 'none';
        $out['background'] = [
            'type'          => $bg_type,
            'color'         => sanitize_text_field( $bg['color'] ?? '' ),
            'attachment_id' => absint( $bg['attachment_id'] ?? 0 ),
            'overlay'       => sanitize_text_field( $bg['overlay'] ?? '' ),
        ];

        $link         = is_array( $slide['link'] ?? null ) ? $slide['link'] : [];
        $out['link'] = [
            'url'  => esc_url_raw( $link['url'] ?? '' ),
            'text' => sanitize_text_field( $link['text'] ?? '' ),
        ];

        $vis              = is_array( $slide['visibility'] ?? null ) ? $slide['visibility'] : [];
        $out['visibility'] = [
            'desktop' => ! empty( $vis['desktop'] ),
            'tablet'  => ! empty( $vis['tablet'] ),
            'mobile'  => ! empty( $vis['mobile'] ),
        ];

        return $out;
    }

    /* ── Frontend rendering ──────────────────────── */

    public function render_shortcode( $atts ) {
        $atts = shortcode_atts( [ 'id' => 0 ], $atts, 'anchor_slider' );
        $id   = absint( $atts['id'] );
        if ( ! $id ) {
            return '';
        }
        if ( get_post_type( $id ) !== self::CPT || get_post_status( $id ) !== 'publish' ) {
            return '';
        }
        return $this->render_slider_html( $id, false );
    }

    public function render_slider_html( $post_id, $is_preview = false ) {
        $slides = get_post_meta( $post_id, 'as_slides', true );
        if ( ! is_array( $slides ) || empty( $slides ) ) {
            if ( $is_preview ) {
                return '<p style="text-align:center;color:#757575;padding:40px">Add slides to see a preview.</p>';
            }
            return '';
        }

        $settings = [];
        foreach ( $this->default_settings as $key => $default ) {
            $saved = get_post_meta( $post_id, 'as_' . $key, true );
            $val   = ( $saved !== '' && $saved !== false ) ? $saved : $default;
            if ( is_bool( $default ) ) {
                $val = (bool) $val;
            } elseif ( is_int( $default ) ) {
                $val = (int) $val;
            }
            $settings[ $key ] = $val;
        }

        $uid     = 'as-' . $post_id . '-' . wp_rand( 1000, 9999 );
        $classes = [
            'anchor-slider',
            'anchor-slider--theme-' . $settings['theme'],
            'anchor-slider--transition-' . $settings['transition'],
            'anchor-slider--height-' . $settings['height_mode'],
        ];

        $style = '';
        if ( $settings['height_mode'] === 'fixed' ) {
            $style = 'min-height:' . intval( $settings['fixed_height'] ) . 'px;';
        } elseif ( $settings['height_mode'] === 'viewport' ) {
            $style = 'min-height:' . intval( $settings['viewport_pct'] ) . 'vh;';
        }

        $config = [
            'autoplay'      => $settings['autoplay'],
            'autoplaySpeed' => intval( $settings['autoplay_speed'] ),
            'loop'          => $settings['loop'],
            'pauseOnHover'  => $settings['pause_on_hover'],
            'transition'    => $settings['transition'],
        ];

        ob_start();
        ?>
        <div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
             id="<?php echo esc_attr( $uid ); ?>"
             style="<?php echo esc_attr( $style ); ?>"
             data-config='<?php echo esc_attr( wp_json_encode( $config ) ); ?>'>

            <div class="anchor-slider__track">
                <?php foreach ( $slides as $i => $slide ) : ?>
                    <?php echo $this->render_slide( $slide, $i ); ?>
                <?php endforeach; ?>
            </div>

            <?php if ( $settings['arrows'] && count( $slides ) > 1 ) : ?>
                <button type="button" class="anchor-slider__arrow anchor-slider__arrow--prev" aria-label="Previous">‹</button>
                <button type="button" class="anchor-slider__arrow anchor-slider__arrow--next" aria-label="Next">›</button>
            <?php endif; ?>

            <?php if ( $settings['dots'] && count( $slides ) > 1 ) : ?>
                <div class="anchor-slider__dots">
                    <?php foreach ( $slides as $i => $_ ) : ?>
                        <button type="button" class="anchor-slider__dot<?php echo $i === 0 ? ' is-active' : ''; ?>" data-slide="<?php echo esc_attr( $i ); ?>" aria-label="Go to slide <?php echo intval( $i + 1 ); ?>"></button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_slide( $slide, $index ) {
        $type      = $slide['type'] ?? 'html';
        $bg        = $slide['background'] ?? [];
        $bg_type   = $bg['type'] ?? 'none';
        $align     = $slide['align'] ?? 'center';
        $vertical  = $slide['vertical'] ?? 'middle';
        $fullwidth = ! empty( $slide['fullwidth'] );
        $vis       = $slide['visibility'] ?? [];

        $classes = [
            'anchor-slider__slide',
            'anchor-slider__slide--type-' . $type,
            'anchor-slider__slide--align-' . $align,
            'anchor-slider__slide--vertical-' . $vertical,
        ];
        if ( $fullwidth ) {
            $classes[] = 'anchor-slider__slide--fullwidth';
        }
        if ( ! empty( $vis['desktop'] ) ) $classes[] = 'anchor-slider__slide--hide-desktop';
        if ( ! empty( $vis['tablet'] ) )  $classes[] = 'anchor-slider__slide--hide-tablet';
        if ( ! empty( $vis['mobile'] ) )  $classes[] = 'anchor-slider__slide--hide-mobile';
        if ( $index === 0 ) $classes[] = 'is-active';

        $style = '';
        if ( $bg_type === 'color' && ! empty( $bg['color'] ) ) {
            $style .= 'background-color:' . esc_attr( $bg['color'] ) . ';';
        } elseif ( $bg_type === 'image' && ! empty( $bg['attachment_id'] ) ) {
            $img_url = wp_get_attachment_image_url( $bg['attachment_id'], 'full' );
            if ( $img_url ) {
                $style .= 'background-image:url(\'' . esc_url( $img_url ) . '\');';
            }
        }

        ob_start();
        ?>
        <div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" style="<?php echo esc_attr( $style ); ?>" data-index="<?php echo esc_attr( $index ); ?>">
            <?php if ( $bg_type !== 'none' && ! empty( $bg['overlay'] ) ) : ?>
                <div class="anchor-slider__overlay" style="background:<?php echo esc_attr( $bg['overlay'] ); ?>"></div>
            <?php endif; ?>
            <div class="anchor-slider__slide-inner">
                <?php
                if ( $type === 'html' ) {
                    echo do_shortcode( wp_kses_post( $slide['html'] ?? '' ) );
                } elseif ( $type === 'image' ) {
                    $att = absint( $slide['attachment_id'] ?? 0 );
                    if ( $att ) {
                        echo wp_get_attachment_image( $att, 'large', false, [ 'class' => 'anchor-slider__image' ] );
                    }
                } elseif ( $type === 'video' ) {
                    echo $this->render_video_embed( $slide['url'] ?? '' );
                }
                ?>
                <?php if ( ! empty( $slide['link']['url'] ) && ! empty( $slide['link']['text'] ) ) : ?>
                    <a class="anchor-slider__cta button button-primary" href="<?php echo esc_url( $slide['link']['url'] ); ?>"><?php echo esc_html( $slide['link']['text'] ); ?></a>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_video_embed( $url ) {
        if ( empty( $url ) ) {
            return '';
        }
        // YouTube
        if ( preg_match( '~youtu(?:\.be/|be\.com/(?:watch\?v=|embed/|v/))([A-Za-z0-9_-]{11})~', $url, $m ) ) {
            return '<iframe class="anchor-slider__iframe" src="https://www.youtube.com/embed/' . esc_attr( $m[1] ) . '?rel=0" allowfullscreen></iframe>';
        }
        // Vimeo
        if ( preg_match( '~vimeo\.com/(\d+)~', $url, $m ) ) {
            return '<iframe class="anchor-slider__iframe" src="https://player.vimeo.com/video/' . esc_attr( $m[1] ) . '" allowfullscreen></iframe>';
        }
        // mp4
        if ( preg_match( '~\.(mp4|webm|ogg)(\?.*)?$~i', $url ) ) {
            return '<video class="anchor-slider__video" src="' . esc_url( $url ) . '" controls playsinline></video>';
        }
        return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $url ) . '</a>';
    }

    /* ── Asset enqueue ───────────────────────────── */

    public function enqueue_admin_assets( $hook ) {
        global $post;
        if ( ( $hook === 'post-new.php' || $hook === 'post.php' ) && isset( $post ) && $post->post_type === self::CPT ) {
            wp_enqueue_media();

            $base_dir = ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-slider/assets/';
            $ver_admin = filemtime( $base_dir . 'admin.js' );

            wp_enqueue_style( 'anchor-slider', Anchor_Asset_Loader::url( 'anchor-slider/assets/slider.css' ), [], filemtime( $base_dir . 'slider.css' ) );
            wp_enqueue_script( 'anchor-slider', Anchor_Asset_Loader::url( 'anchor-slider/assets/slider.js' ), [], filemtime( $base_dir . 'slider.js' ), true );

            wp_enqueue_style( 'anchor-slider-admin', Anchor_Asset_Loader::url( 'anchor-slider/assets/admin.css' ), [], $ver_admin );
            wp_enqueue_script( 'anchor-slider-admin', Anchor_Asset_Loader::url( 'anchor-slider/assets/admin.js' ), [ 'jquery' ], $ver_admin, true );

            $builder_dir = ANCHOR_TOOLS_PLUGIN_DIR . 'includes/builder/assets/';
            wp_enqueue_style( 'anchor-builder', Anchor_Asset_Loader::url( 'includes/builder/assets/builder.css' ), [], filemtime( $builder_dir . 'builder.css' ) );
            wp_enqueue_script( 'anchor-builder', Anchor_Asset_Loader::url( 'includes/builder/assets/builder.js' ), [ 'jquery' ], filemtime( $builder_dir . 'builder.js' ), true );
        }
    }

    public function enqueue_assets() {
        $base_dir = ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-slider/assets/';
        if ( file_exists( $base_dir . 'slider.css' ) ) {
            wp_enqueue_style( 'anchor-slider', Anchor_Asset_Loader::url( 'anchor-slider/assets/slider.css' ), [], filemtime( $base_dir . 'slider.css' ) );
            wp_enqueue_script( 'anchor-slider', Anchor_Asset_Loader::url( 'anchor-slider/assets/slider.js' ), [], filemtime( $base_dir . 'slider.js' ), true );
        }
    }

    /* ── Admin columns ───────────────────────────── */

    public function admin_columns( $cols ) {
        $new = [];
        foreach ( $cols as $k => $v ) {
            $new[ $k ] = $v;
            if ( $k === 'title' ) {
                $new['as_slides_count'] = 'Slides';
                $new['as_shortcode']    = 'Shortcode';
            }
        }
        return $new;
    }

    public function ajax_attachment_url() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error();
        }
        $id = absint( $_GET['id'] ?? 0 );
        $url = $id ? wp_get_attachment_image_url( $id, 'thumbnail' ) : '';
        wp_send_json( [ 'url' => $url ?: '' ] );
    }

    public function admin_column_content( $col, $post_id ) {
        if ( $col === 'as_slides_count' ) {
            $slides = get_post_meta( $post_id, 'as_slides', true );
            echo intval( is_array( $slides ) ? count( $slides ) : 0 );
        } elseif ( $col === 'as_shortcode' ) {
            echo '<code>[anchor_slider id="' . intval( $post_id ) . '"]</code>';
        }
    }
}

