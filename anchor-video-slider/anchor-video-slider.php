<?php
/**
 * Anchor Tools module: Anchor Video Display Suite.
 *
 * Supports multiple display modes: slider, grid, carousel, masonry
 * Multiple popup styles: lightbox, inline, theater, side panel
 * Comprehensive customization options for appearance and behavior
 */

if (!defined('ABSPATH')) {
    exit;
}

class Anchor_Video_Slider_Module {
    const OPTION_KEY = 'anchor_video_slider_items';

    // Sample videos for preview
    private $sample_videos = [
        ['provider' => 'youtube', 'id' => 'dQw4w9WgXcQ', 'label' => 'Sample Video 1', 'thumb' => 'https://img.youtube.com/vi/dQw4w9WgXcQ/hqdefault.jpg', 'duration' => '3:33', 'channel' => 'Demo Channel'],
        ['provider' => 'youtube', 'id' => 'jNQXAC9IVRw', 'label' => 'Sample Video 2', 'thumb' => 'https://img.youtube.com/vi/jNQXAC9IVRw/hqdefault.jpg', 'duration' => '0:19', 'channel' => 'Demo Channel'],
        ['provider' => 'youtube', 'id' => '9bZkp7q19f0', 'label' => 'Sample Video 3', 'thumb' => 'https://img.youtube.com/vi/9bZkp7q19f0/hqdefault.jpg', 'duration' => '4:13', 'channel' => 'Demo Channel'],
        ['provider' => 'youtube', 'id' => 'kJQP7kiw5Fk', 'label' => 'Sample Video 4', 'thumb' => 'https://img.youtube.com/vi/kJQP7kiw5Fk/hqdefault.jpg', 'duration' => '4:42', 'channel' => 'Demo Channel'],
        ['provider' => 'youtube', 'id' => 'RgKAFK5djSk', 'label' => 'Sample Video 5', 'thumb' => 'https://img.youtube.com/vi/RgKAFK5djSk/hqdefault.jpg', 'duration' => '4:57', 'channel' => 'Demo Channel'],
        ['provider' => 'youtube', 'id' => 'JGwWNGJdvx8', 'label' => 'Sample Video 6', 'thumb' => 'https://img.youtube.com/vi/JGwWNGJdvx8/hqdefault.jpg', 'duration' => '4:24', 'channel' => 'Demo Channel'],
    ];

    // Default settings for new galleries
    private $default_settings = [
        // Layout
        'layout' => 'slider', // slider, grid, carousel, masonry
        'columns_desktop' => 4,
        'columns_tablet' => 3,
        'columns_mobile' => 1,
        'gap' => 16,

        // Pagination (for grid/masonry)
        'pagination_enabled' => false,
        'videos_per_page' => 12,
        'pagination_style' => 'numbered', // numbered, load_more, infinite

        // Popup/Display
        'popup_style' => 'lightbox', // lightbox, inline, theater, side_panel, none
        'autoplay' => true,

        // Appearance
        'theme' => 'dark', // light, dark, auto
        'tile_style' => 'card', // card, minimal, overlay, cinematic
        'show_title' => true,
        'show_duration' => true,
        'show_channel' => false,
        'hover_effect' => 'lift', // none, zoom, lift, glow
        'play_button_style' => 'circle', // circle, square, youtube, minimal, none
        'border_radius' => 12,

        // Slider specific
        'slider_arrows' => true,
        'slider_dots' => false,
        'slider_autoplay' => false,
        'slider_autoplay_speed' => 5000,

        // Carousel specific
        'carousel_loop' => true,
        'carousel_center' => false,
    ];

    public function __construct() {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_post_anchor_video_slider_save', [$this, 'handle_save']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_ajax_avg_preview', [$this, 'ajax_preview']);
        add_shortcode('anchor_video_slider', [$this, 'render_gallery']);
        add_shortcode('anchor_video_gallery', [$this, 'render_gallery']); // Alias
    }

    public function enqueue_assets() {
        $base_dir = ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-video-slider/assets/';
        $base_url = ANCHOR_TOOLS_PLUGIN_URL . 'anchor-video-slider/assets/';

        $up_css_path = ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-universal-popups/assets/frontend.css';
        if (file_exists($up_css_path)) {
            wp_enqueue_style('up-frontend', ANCHOR_TOOLS_PLUGIN_URL . 'anchor-universal-popups/assets/frontend.css', [], filemtime($up_css_path));
        }

        wp_enqueue_style('anchor-video-gallery', $base_url . 'anchor-video-slider.css', [], filemtime($base_dir . 'anchor-video-slider.css'));
        wp_enqueue_script('anchor-video-gallery', $base_url . 'anchor-video-slider.js', [], filemtime($base_dir . 'anchor-video-slider.js'), true);
    }

    public function register_menu() {
        add_options_page(
            __('Anchor Video Gallery', 'anchor-schema'),
            __('Anchor Video Gallery', 'anchor-schema'),
            'manage_options',
            'anchor-video-slider',
            [$this, 'render_admin_page']
        );
    }

    public function enqueue_admin_assets($hook) {
        if ($hook !== 'settings_page_anchor-video-slider') {
            return;
        }
        wp_enqueue_media();

        // Enqueue frontend styles for preview
        $base_dir = ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-video-slider/assets/';
        $base_url = ANCHOR_TOOLS_PLUGIN_URL . 'anchor-video-slider/assets/';
        wp_enqueue_style('anchor-video-gallery', $base_url . 'anchor-video-slider.css', [], filemtime($base_dir . 'anchor-video-slider.css'));
        wp_enqueue_script('anchor-video-gallery', $base_url . 'anchor-video-slider.js', [], filemtime($base_dir . 'anchor-video-slider.js'), true);

        wp_enqueue_style(
            'anchor-video-gallery-admin',
            ANCHOR_TOOLS_PLUGIN_URL . 'anchor-video-slider/assets/admin.css',
            ['anchor-video-gallery'],
            filemtime($base_dir . 'admin.css')
        );
        wp_enqueue_script(
            'anchor-video-gallery-admin',
            ANCHOR_TOOLS_PLUGIN_URL . 'anchor-video-slider/assets/admin.js',
            ['jquery', 'anchor-video-gallery'],
            filemtime($base_dir . 'admin.js'),
            true
        );
        wp_localize_script('anchor-video-gallery-admin', 'ANCHOR_VIDEO_GALLERY', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('avg_preview'),
            'mediaTitle' => __('Select or upload image', 'anchor-schema'),
            'mediaButton' => __('Use this image', 'anchor-schema'),
            'defaults' => $this->default_settings,
        ]);
    }

    /**
     * AJAX handler for live preview
     */
    public function ajax_preview() {
        check_ajax_referer('avg_preview', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        // Get settings from the nested 'settings' array
        $posted = isset($_POST['settings']) && is_array($_POST['settings']) ? $_POST['settings'] : [];

        $settings = [];
        foreach ($this->default_settings as $key => $default) {
            if (isset($posted[$key])) {
                $value = $posted[$key];
                if (is_bool($default)) {
                    $settings[$key] = ($value === '1' || $value === 'true' || $value === true);
                } elseif (is_int($default)) {
                    $settings[$key] = intval($value);
                } else {
                    $settings[$key] = sanitize_text_field($value);
                }
            } else {
                $settings[$key] = $default;
            }
        }

        // Add sample video data
        $videos = $this->sample_videos;
        foreach ($videos as &$v) {
            $v['raw_url'] = 'https://youtube.com/watch?v=' . $v['id'];
        }

        $html = $this->render_output('avg-preview-' . uniqid(), $videos, $settings);

        wp_send_json_success(['html' => $html]);
    }

    public function handle_save() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('anchor_video_slider_save', 'anchor_video_slider_nonce');

        $raw = isset($_POST['galleries']) && is_array($_POST['galleries']) ? $_POST['galleries'] : [];
        $galleries = $this->sanitize_galleries($raw);
        update_option(self::OPTION_KEY, $galleries, false);

        $url = add_query_arg('updated', '1', menu_page_url('anchor-video-slider', false));
        wp_safe_redirect($url);
        exit;
    }

    public function render_admin_page() {
        $galleries = $this->get_galleries();
        if (isset($_GET['updated'])) {
            echo '<div class="notice notice-success"><p>' . esc_html__('Video galleries saved.', 'anchor-schema') . '</p></div>';
        }
        ?>
        <div class="wrap avs-admin-wrap">
            <h1><?php esc_html_e('Anchor Video Gallery', 'anchor-schema'); ?></h1>
            <p class="avs-intro"><?php esc_html_e('Create video galleries with multiple display modes. Use shortcode: [anchor_video_gallery id="your-gallery-id"]', 'anchor-schema'); ?></p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="avs-main-form">
                <input type="hidden" name="action" value="anchor_video_slider_save" />
                <?php wp_nonce_field('anchor_video_slider_save', 'anchor_video_slider_nonce'); ?>

                <div id="avs-galleries">
                    <?php
                    if (empty($galleries)) {
                        $galleries = [$this->get_empty_gallery()];
                    }
                    foreach ($galleries as $idx => $gallery) {
                        $this->render_gallery_block($idx, $gallery);
                    }
                    ?>
                </div>

                <p class="avs-actions">
                    <button type="button" class="button button-secondary" id="avs-add-gallery">
                        <?php esc_html_e('+ Add New Gallery', 'anchor-schema'); ?>
                    </button>
                </p>

                <?php submit_button(__('Save All Galleries', 'anchor-schema')); ?>
            </form>
        </div>
        <?php
        $this->render_admin_templates();
    }

    private function get_empty_gallery() {
        return array_merge([
            'id' => '',
            'title' => '',
            'videos' => [],
        ], $this->default_settings);
    }

    private function render_gallery_block($idx, $gallery) {
        $gallery = wp_parse_args($gallery, $this->default_settings);
        $id = $gallery['id'] ?? '';
        $title = $gallery['title'] ?? '';
        $videos = $gallery['videos'] ?? [];
        ?>
        <div class="avs-gallery" data-index="<?php echo esc_attr($idx); ?>" data-layout="<?php echo esc_attr($gallery['layout']); ?>">
            <div class="avs-gallery-header">
                <div class="avs-gallery-header-left">
                    <span class="avs-toggle-icon dashicons dashicons-arrow-down-alt2"></span>
                    <h2 class="avs-gallery-title">
                        <?php echo $title ? esc_html($title) : esc_html__('New Gallery', 'anchor-schema'); ?>
                        <?php if ($id): ?>
                            <code class="avs-shortcode-preview">[anchor_video_gallery id="<?php echo esc_attr($id); ?>"]</code>
                        <?php endif; ?>
                    </h2>
                </div>
                <button type="button" class="button button-link-delete avs-remove-gallery"><?php esc_html_e('Delete Gallery', 'anchor-schema'); ?></button>
            </div>

            <div class="avs-gallery-body">
                <div class="avs-main-content">
                    <!-- Basic Info -->
                    <div class="avs-section">
                        <h3><?php esc_html_e('Basic Info', 'anchor-schema'); ?></h3>
                        <div class="avs-field-grid">
                            <div class="avs-field">
                                <label><?php esc_html_e('Gallery ID', 'anchor-schema'); ?> <span class="required">*</span></label>
                                <input type="text" name="galleries[<?php echo esc_attr($idx); ?>][id]" value="<?php echo esc_attr($id); ?>" class="regular-text avs-gallery-id" placeholder="my-video-gallery" />
                                <p class="description"><?php esc_html_e('Used in shortcode. Letters, numbers, hyphens only.', 'anchor-schema'); ?></p>
                            </div>
                            <div class="avs-field">
                                <label><?php esc_html_e('Gallery Title', 'anchor-schema'); ?></label>
                                <input type="text" name="galleries[<?php echo esc_attr($idx); ?>][title]" value="<?php echo esc_attr($title); ?>" class="regular-text avs-gallery-title-input" placeholder="My Video Gallery" />
                                <p class="description"><?php esc_html_e('Admin reference only, not displayed.', 'anchor-schema'); ?></p>
                            </div>
                        </div>
                    </div>

                    <!-- Layout Settings -->
                    <div class="avs-section">
                        <h3><?php esc_html_e('Layout', 'anchor-schema'); ?></h3>
                        <div class="avs-field-grid">
                            <div class="avs-field avs-field-full">
                                <label><?php esc_html_e('Display Mode', 'anchor-schema'); ?></label>
                                <div class="avs-layout-options">
                                    <?php
                                    $layouts = [
                                        'slider' => ['icon' => 'slides', 'label' => __('Slider', 'anchor-schema'), 'desc' => __('Horizontal scrolling', 'anchor-schema')],
                                        'grid' => ['icon' => 'grid-view', 'label' => __('Grid', 'anchor-schema'), 'desc' => __('Responsive columns', 'anchor-schema')],
                                        'carousel' => ['icon' => 'images-alt', 'label' => __('Carousel', 'anchor-schema'), 'desc' => __('With arrows & dots', 'anchor-schema')],
                                        'masonry' => ['icon' => 'tagcloud', 'label' => __('Masonry', 'anchor-schema'), 'desc' => __('Pinterest-style', 'anchor-schema')],
                                    ];
                                    foreach ($layouts as $value => $layout):
                                    ?>
                                    <label class="avs-layout-option">
                                        <input type="radio" name="galleries[<?php echo esc_attr($idx); ?>][layout]" value="<?php echo esc_attr($value); ?>" <?php checked($gallery['layout'], $value); ?> class="avs-preview-trigger" />
                                        <span class="avs-layout-option-inner">
                                            <span class="dashicons dashicons-<?php echo esc_attr($layout['icon']); ?>"></span>
                                            <span class="avs-layout-label"><?php echo esc_html($layout['label']); ?></span>
                                            <span class="avs-layout-desc"><?php echo esc_html($layout['desc']); ?></span>
                                        </span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Grid/Masonry Columns -->
                        <div class="avs-field-grid avs-columns-settings" data-show-for="grid,masonry">
                            <div class="avs-field">
                                <label><?php esc_html_e('Desktop Columns', 'anchor-schema'); ?></label>
                                <select name="galleries[<?php echo esc_attr($idx); ?>][columns_desktop]" class="avs-preview-trigger">
                                    <?php for ($i = 2; $i <= 6; $i++): ?>
                                        <option value="<?php echo $i; ?>" <?php selected($gallery['columns_desktop'], $i); ?>><?php echo $i; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="avs-field">
                                <label><?php esc_html_e('Tablet Columns', 'anchor-schema'); ?></label>
                                <select name="galleries[<?php echo esc_attr($idx); ?>][columns_tablet]" class="avs-preview-trigger">
                                    <?php for ($i = 1; $i <= 4; $i++): ?>
                                        <option value="<?php echo $i; ?>" <?php selected($gallery['columns_tablet'], $i); ?>><?php echo $i; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="avs-field">
                                <label><?php esc_html_e('Mobile Columns', 'anchor-schema'); ?></label>
                                <select name="galleries[<?php echo esc_attr($idx); ?>][columns_mobile]" class="avs-preview-trigger">
                                    <?php for ($i = 1; $i <= 2; $i++): ?>
                                        <option value="<?php echo $i; ?>" <?php selected($gallery['columns_mobile'], $i); ?>><?php echo $i; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="avs-field">
                                <label><?php esc_html_e('Gap (px)', 'anchor-schema'); ?></label>
                                <input type="number" name="galleries[<?php echo esc_attr($idx); ?>][gap]" value="<?php echo esc_attr($gallery['gap']); ?>" min="0" max="60" step="4" class="avs-preview-trigger" />
                            </div>
                        </div>

                        <!-- Pagination (for grid/masonry) -->
                        <div class="avs-field-grid avs-pagination-settings" data-show-for="grid,masonry">
                            <div class="avs-field">
                                <label>
                                    <input type="checkbox" name="galleries[<?php echo esc_attr($idx); ?>][pagination_enabled]" value="1" <?php checked(!empty($gallery['pagination_enabled'])); ?> class="avs-preview-trigger" />
                                    <?php esc_html_e('Enable Pagination', 'anchor-schema'); ?>
                                </label>
                            </div>
                            <div class="avs-field avs-pagination-options">
                                <label><?php esc_html_e('Videos Per Page', 'anchor-schema'); ?></label>
                                <input type="number" name="galleries[<?php echo esc_attr($idx); ?>][videos_per_page]" value="<?php echo esc_attr($gallery['videos_per_page']); ?>" min="1" max="100" class="avs-preview-trigger" />
                            </div>
                            <div class="avs-field avs-pagination-options">
                                <label><?php esc_html_e('Pagination Style', 'anchor-schema'); ?></label>
                                <select name="galleries[<?php echo esc_attr($idx); ?>][pagination_style]" class="avs-preview-trigger">
                                    <option value="numbered" <?php selected($gallery['pagination_style'], 'numbered'); ?>><?php esc_html_e('Numbered Pages', 'anchor-schema'); ?></option>
                                    <option value="load_more" <?php selected($gallery['pagination_style'], 'load_more'); ?>><?php esc_html_e('Load More Button', 'anchor-schema'); ?></option>
                                    <option value="infinite" <?php selected($gallery['pagination_style'], 'infinite'); ?>><?php esc_html_e('Infinite Scroll', 'anchor-schema'); ?></option>
                                </select>
                            </div>
                        </div>

                        <!-- Slider/Carousel specific -->
                        <div class="avs-field-grid avs-slider-settings" data-show-for="slider,carousel">
                            <div class="avs-field">
                                <label>
                                    <input type="checkbox" name="galleries[<?php echo esc_attr($idx); ?>][slider_arrows]" value="1" <?php checked(!empty($gallery['slider_arrows'])); ?> class="avs-preview-trigger" />
                                    <?php esc_html_e('Show Navigation Arrows', 'anchor-schema'); ?>
                                </label>
                            </div>
                            <div class="avs-field">
                                <label>
                                    <input type="checkbox" name="galleries[<?php echo esc_attr($idx); ?>][slider_dots]" value="1" <?php checked(!empty($gallery['slider_dots'])); ?> class="avs-preview-trigger" />
                                    <?php esc_html_e('Show Dots Navigation', 'anchor-schema'); ?>
                                </label>
                            </div>
                            <div class="avs-field">
                                <label>
                                    <input type="checkbox" name="galleries[<?php echo esc_attr($idx); ?>][slider_autoplay]" value="1" <?php checked(!empty($gallery['slider_autoplay'])); ?> class="avs-toggle-autoplay avs-preview-trigger" />
                                    <?php esc_html_e('Auto-advance Slides', 'anchor-schema'); ?>
                                </label>
                            </div>
                            <div class="avs-field avs-autoplay-speed">
                                <label><?php esc_html_e('Autoplay Speed (ms)', 'anchor-schema'); ?></label>
                                <input type="number" name="galleries[<?php echo esc_attr($idx); ?>][slider_autoplay_speed]" value="<?php echo esc_attr($gallery['slider_autoplay_speed']); ?>" min="1000" max="15000" step="500" class="avs-preview-trigger" />
                            </div>
                        </div>

                        <!-- Carousel specific -->
                        <div class="avs-field-grid avs-carousel-settings" data-show-for="carousel">
                            <div class="avs-field">
                                <label>
                                    <input type="checkbox" name="galleries[<?php echo esc_attr($idx); ?>][carousel_loop]" value="1" <?php checked(!empty($gallery['carousel_loop'])); ?> class="avs-preview-trigger" />
                                    <?php esc_html_e('Loop Continuously', 'anchor-schema'); ?>
                                </label>
                            </div>
                            <div class="avs-field">
                                <label>
                                    <input type="checkbox" name="galleries[<?php echo esc_attr($idx); ?>][carousel_center]" value="1" <?php checked(!empty($gallery['carousel_center'])); ?> class="avs-preview-trigger" />
                                    <?php esc_html_e('Center Active Slide', 'anchor-schema'); ?>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Popup/Playback Settings -->
                    <div class="avs-section">
                        <h3><?php esc_html_e('Video Popup', 'anchor-schema'); ?></h3>
                        <div class="avs-field-grid">
                            <div class="avs-field avs-field-full">
                                <label><?php esc_html_e('Popup Style', 'anchor-schema'); ?></label>
                                <div class="avs-popup-options">
                                    <?php
                                    $popups = [
                                        'lightbox' => ['icon' => 'format-image', 'label' => __('Lightbox', 'anchor-schema'), 'desc' => __('Centered modal overlay', 'anchor-schema')],
                                        'inline' => ['icon' => 'editor-expand', 'label' => __('Inline Expand', 'anchor-schema'), 'desc' => __('Expands in place', 'anchor-schema')],
                                        'theater' => ['icon' => 'fullscreen-alt', 'label' => __('Theater Mode', 'anchor-schema'), 'desc' => __('Immersive fullscreen', 'anchor-schema')],
                                        'side_panel' => ['icon' => 'align-pull-right', 'label' => __('Side Panel', 'anchor-schema'), 'desc' => __('Slides in from right', 'anchor-schema')],
                                        'none' => ['icon' => 'external', 'label' => __('Direct Link', 'anchor-schema'), 'desc' => __('Opens in new tab', 'anchor-schema')],
                                    ];
                                    foreach ($popups as $value => $popup):
                                    ?>
                                    <label class="avs-popup-option">
                                        <input type="radio" name="galleries[<?php echo esc_attr($idx); ?>][popup_style]" value="<?php echo esc_attr($value); ?>" <?php checked($gallery['popup_style'], $value); ?> class="avs-preview-trigger" />
                                        <span class="avs-popup-option-inner">
                                            <span class="dashicons dashicons-<?php echo esc_attr($popup['icon']); ?>"></span>
                                            <span class="avs-popup-label"><?php echo esc_html($popup['label']); ?></span>
                                            <span class="avs-popup-desc"><?php echo esc_html($popup['desc']); ?></span>
                                        </span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="avs-field">
                                <label>
                                    <input type="checkbox" name="galleries[<?php echo esc_attr($idx); ?>][autoplay]" value="1" <?php checked(!empty($gallery['autoplay'])); ?> class="avs-preview-trigger" />
                                    <?php esc_html_e('Autoplay video when popup opens', 'anchor-schema'); ?>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Appearance Settings -->
                    <div class="avs-section">
                        <h3><?php esc_html_e('Appearance', 'anchor-schema'); ?></h3>
                        <div class="avs-field-grid">
                            <div class="avs-field">
                                <label><?php esc_html_e('Theme', 'anchor-schema'); ?></label>
                                <select name="galleries[<?php echo esc_attr($idx); ?>][theme]" class="avs-preview-trigger">
                                    <option value="dark" <?php selected($gallery['theme'], 'dark'); ?>><?php esc_html_e('Dark', 'anchor-schema'); ?></option>
                                    <option value="light" <?php selected($gallery['theme'], 'light'); ?>><?php esc_html_e('Light', 'anchor-schema'); ?></option>
                                    <option value="auto" <?php selected($gallery['theme'], 'auto'); ?>><?php esc_html_e('Auto (match system)', 'anchor-schema'); ?></option>
                                </select>
                            </div>
                            <div class="avs-field">
                                <label><?php esc_html_e('Tile Style', 'anchor-schema'); ?></label>
                                <select name="galleries[<?php echo esc_attr($idx); ?>][tile_style]" class="avs-preview-trigger">
                                    <option value="card" <?php selected($gallery['tile_style'], 'card'); ?>><?php esc_html_e('Card (with title below)', 'anchor-schema'); ?></option>
                                    <option value="minimal" <?php selected($gallery['tile_style'], 'minimal'); ?>><?php esc_html_e('Minimal (clean edges)', 'anchor-schema'); ?></option>
                                    <option value="overlay" <?php selected($gallery['tile_style'], 'overlay'); ?>><?php esc_html_e('Overlay (title on image)', 'anchor-schema'); ?></option>
                                    <option value="cinematic" <?php selected($gallery['tile_style'], 'cinematic'); ?>><?php esc_html_e('Cinematic (letterbox)', 'anchor-schema'); ?></option>
                                </select>
                            </div>
                            <div class="avs-field">
                                <label><?php esc_html_e('Hover Effect', 'anchor-schema'); ?></label>
                                <select name="galleries[<?php echo esc_attr($idx); ?>][hover_effect]" class="avs-preview-trigger">
                                    <option value="lift" <?php selected($gallery['hover_effect'], 'lift'); ?>><?php esc_html_e('Lift (raise up)', 'anchor-schema'); ?></option>
                                    <option value="zoom" <?php selected($gallery['hover_effect'], 'zoom'); ?>><?php esc_html_e('Zoom (scale up)', 'anchor-schema'); ?></option>
                                    <option value="glow" <?php selected($gallery['hover_effect'], 'glow'); ?>><?php esc_html_e('Glow (shadow)', 'anchor-schema'); ?></option>
                                    <option value="none" <?php selected($gallery['hover_effect'], 'none'); ?>><?php esc_html_e('None', 'anchor-schema'); ?></option>
                                </select>
                            </div>
                            <div class="avs-field">
                                <label><?php esc_html_e('Play Button', 'anchor-schema'); ?></label>
                                <select name="galleries[<?php echo esc_attr($idx); ?>][play_button_style]" class="avs-preview-trigger">
                                    <option value="circle" <?php selected($gallery['play_button_style'], 'circle'); ?>><?php esc_html_e('Circle', 'anchor-schema'); ?></option>
                                    <option value="square" <?php selected($gallery['play_button_style'], 'square'); ?>><?php esc_html_e('Square', 'anchor-schema'); ?></option>
                                    <option value="youtube" <?php selected($gallery['play_button_style'], 'youtube'); ?>><?php esc_html_e('YouTube Style', 'anchor-schema'); ?></option>
                                    <option value="minimal" <?php selected($gallery['play_button_style'], 'minimal'); ?>><?php esc_html_e('Minimal', 'anchor-schema'); ?></option>
                                    <option value="none" <?php selected($gallery['play_button_style'], 'none'); ?>><?php esc_html_e('Hidden', 'anchor-schema'); ?></option>
                                </select>
                            </div>
                            <div class="avs-field">
                                <label><?php esc_html_e('Border Radius (px)', 'anchor-schema'); ?></label>
                                <input type="number" name="galleries[<?php echo esc_attr($idx); ?>][border_radius]" value="<?php echo esc_attr($gallery['border_radius']); ?>" min="0" max="32" step="2" class="avs-preview-trigger" />
                            </div>
                        </div>
                        <div class="avs-field-grid">
                            <div class="avs-field">
                                <label>
                                    <input type="checkbox" name="galleries[<?php echo esc_attr($idx); ?>][show_title]" value="1" <?php checked(!empty($gallery['show_title'])); ?> class="avs-preview-trigger" />
                                    <?php esc_html_e('Show Video Titles', 'anchor-schema'); ?>
                                </label>
                            </div>
                            <div class="avs-field">
                                <label>
                                    <input type="checkbox" name="galleries[<?php echo esc_attr($idx); ?>][show_duration]" value="1" <?php checked(!empty($gallery['show_duration'])); ?> class="avs-preview-trigger" />
                                    <?php esc_html_e('Show Duration', 'anchor-schema'); ?>
                                </label>
                            </div>
                            <div class="avs-field">
                                <label>
                                    <input type="checkbox" name="galleries[<?php echo esc_attr($idx); ?>][show_channel]" value="1" <?php checked(!empty($gallery['show_channel'])); ?> class="avs-preview-trigger" />
                                    <?php esc_html_e('Show Channel Name', 'anchor-schema'); ?>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Videos -->
                    <div class="avs-section avs-videos-section">
                        <h3><?php esc_html_e('Videos', 'anchor-schema'); ?></h3>
                        <div class="avs-videos-grid" data-gallery-index="<?php echo esc_attr($idx); ?>">
                            <?php
                            if (empty($videos)) {
                                $videos = [['url' => '', 'thumb' => '', 'title' => '', 'description' => '']];
                            }
                            foreach ($videos as $v_idx => $video) {
                                $this->render_video_card($idx, $v_idx, $video);
                            }
                            ?>
                        </div>
                        <div class="avs-video-actions">
                            <button type="button" class="button avs-add-video"><?php esc_html_e('+ Add Video', 'anchor-schema'); ?></button>
                            <button type="button" class="button avs-bulk-add-video"><?php esc_html_e('Bulk Add URLs', 'anchor-schema'); ?></button>
                        </div>

                        <!-- Bulk Add Modal -->
                        <div class="avs-bulk-modal" hidden>
                            <div class="avs-bulk-modal-content">
                                <div class="avs-bulk-modal-header">
                                    <h4><?php esc_html_e('Bulk Add Videos', 'anchor-schema'); ?></h4>
                                    <button type="button" class="avs-bulk-modal-close">&times;</button>
                                </div>
                                <div class="avs-bulk-modal-body">
                                    <p class="avs-bulk-instructions"><?php esc_html_e('Paste video URLs below, one per line. Supports YouTube and Vimeo.', 'anchor-schema'); ?></p>
                                    <textarea class="avs-bulk-urls" rows="10" placeholder="https://youtube.com/watch?v=...
https://vimeo.com/...
https://youtu.be/..."></textarea>
                                    <p class="avs-bulk-hint"><?php esc_html_e('Tip: You can paste a list directly from a spreadsheet or text file.', 'anchor-schema'); ?></p>
                                </div>
                                <div class="avs-bulk-modal-footer">
                                    <button type="button" class="button avs-bulk-cancel"><?php esc_html_e('Cancel', 'anchor-schema'); ?></button>
                                    <button type="button" class="button button-primary avs-bulk-import"><?php esc_html_e('Import Videos', 'anchor-schema'); ?></button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Live Preview Panel -->
                <div class="avs-preview-panel">
                    <div class="avs-preview-header">
                        <h3><?php esc_html_e('Live Preview', 'anchor-schema'); ?></h3>
                        <span class="avs-preview-status"></span>
                    </div>
                    <div class="avs-preview-container">
                        <div class="avs-preview-content">
                            <?php
                            // Render initial preview with sample videos
                            $preview_videos = $this->sample_videos;
                            foreach ($preview_videos as &$v) {
                                $v['raw_url'] = 'https://youtube.com/watch?v=' . $v['id'];
                            }
                            echo $this->render_output('avg-preview-init-' . $idx, $preview_videos, $gallery);
                            ?>
                        </div>
                    </div>
                    <p class="avs-preview-note"><?php esc_html_e('Preview uses sample videos. Your actual videos will appear on the front end.', 'anchor-schema'); ?></p>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_video_card($idx, $v_idx, $video) {
        $url = $video['url'] ?? '';
        $thumb = $video['thumb'] ?? '';
        $title = $video['title'] ?? '';
        $description = $video['description'] ?? '';
        ?>
        <div class="avs-video-card" data-video-index="<?php echo esc_attr($v_idx); ?>">
            <div class="avs-video-card-header">
                <span class="avs-video-drag-handle dashicons dashicons-move"></span>
                <span class="avs-video-number">#<?php echo ($v_idx + 1); ?></span>
                <button type="button" class="avs-remove-video" aria-label="<?php esc_attr_e('Remove video', 'anchor-schema'); ?>">&times;</button>
            </div>
            <div class="avs-video-card-body">
                <div class="avs-video-field">
                    <label><?php esc_html_e('Video URL', 'anchor-schema'); ?></label>
                    <input type="url" name="galleries[<?php echo esc_attr($idx); ?>][videos][<?php echo esc_attr($v_idx); ?>][url]" value="<?php echo esc_attr($url); ?>" placeholder="https://youtube.com/watch?v=..." class="avs-video-url" />
                </div>
                <div class="avs-video-field">
                    <label><?php esc_html_e('Custom Thumbnail', 'anchor-schema'); ?></label>
                    <div class="avs-thumb-wrap">
                        <input type="text" name="galleries[<?php echo esc_attr($idx); ?>][videos][<?php echo esc_attr($v_idx); ?>][thumb]" value="<?php echo esc_attr($thumb); ?>" class="avs-thumb-field" placeholder="<?php esc_attr_e('Image ID or URL', 'anchor-schema'); ?>" />
                        <button type="button" class="button avs-thumb-pick"><?php esc_html_e('Browse', 'anchor-schema'); ?></button>
                    </div>
                </div>
                <div class="avs-video-field">
                    <label><?php esc_html_e('Custom Title', 'anchor-schema'); ?></label>
                    <input type="text" name="galleries[<?php echo esc_attr($idx); ?>][videos][<?php echo esc_attr($v_idx); ?>][title]" value="<?php echo esc_attr($title); ?>" placeholder="<?php esc_attr_e('Leave blank to auto-fetch', 'anchor-schema'); ?>" />
                </div>
                <div class="avs-video-field">
                    <label><?php esc_html_e('Description', 'anchor-schema'); ?></label>
                    <textarea name="galleries[<?php echo esc_attr($idx); ?>][videos][<?php echo esc_attr($v_idx); ?>][description]" rows="2" placeholder="<?php esc_attr_e('Optional description', 'anchor-schema'); ?>"><?php echo esc_textarea($description); ?></textarea>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_admin_templates() {
        ?>
        <script type="text/template" id="avs-gallery-template">
            <?php $this->render_gallery_block('__INDEX__', $this->get_empty_gallery()); ?>
        </script>
        <script type="text/template" id="avs-video-template">
            <?php $this->render_video_card('__GIDX__', '__VIDX__', ['url' => '', 'thumb' => '', 'title' => '', 'description' => '']); ?>
        </script>
        <?php
    }

    private function sanitize_galleries($raw) {
        $out = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = sanitize_title($item['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $videos = [];
            if (!empty($item['videos']) && is_array($item['videos'])) {
                foreach ($item['videos'] as $video) {
                    if (!is_array($video)) {
                        continue;
                    }
                    $url = trim((string) ($video['url'] ?? ''));
                    if ($url === '') {
                        continue;
                    }
                    $videos[] = [
                        'url' => esc_url_raw($url),
                        'thumb' => sanitize_text_field($video['thumb'] ?? ''),
                        'title' => sanitize_text_field($video['title'] ?? ''),
                        'description' => sanitize_textarea_field($video['description'] ?? ''),
                    ];
                }
            }

            $out[$id] = [
                'id' => $id,
                'title' => sanitize_text_field($item['title'] ?? ''),
                'videos' => $videos,
                // Layout
                'layout' => in_array($item['layout'] ?? '', ['slider', 'grid', 'carousel', 'masonry']) ? $item['layout'] : 'slider',
                'columns_desktop' => max(2, min(6, intval($item['columns_desktop'] ?? 4))),
                'columns_tablet' => max(1, min(4, intval($item['columns_tablet'] ?? 3))),
                'columns_mobile' => max(1, min(2, intval($item['columns_mobile'] ?? 1))),
                'gap' => max(0, min(60, intval($item['gap'] ?? 16))),
                // Pagination
                'pagination_enabled' => !empty($item['pagination_enabled']),
                'videos_per_page' => max(1, min(100, intval($item['videos_per_page'] ?? 12))),
                'pagination_style' => in_array($item['pagination_style'] ?? '', ['numbered', 'load_more', 'infinite']) ? $item['pagination_style'] : 'numbered',
                // Popup
                'popup_style' => in_array($item['popup_style'] ?? '', ['lightbox', 'inline', 'theater', 'side_panel', 'none']) ? $item['popup_style'] : 'lightbox',
                'autoplay' => !empty($item['autoplay']),
                // Appearance
                'theme' => in_array($item['theme'] ?? '', ['light', 'dark', 'auto']) ? $item['theme'] : 'dark',
                'tile_style' => in_array($item['tile_style'] ?? '', ['card', 'minimal', 'overlay', 'cinematic']) ? $item['tile_style'] : 'card',
                'show_title' => !empty($item['show_title']),
                'show_duration' => !empty($item['show_duration']),
                'show_channel' => !empty($item['show_channel']),
                'hover_effect' => in_array($item['hover_effect'] ?? '', ['none', 'zoom', 'lift', 'glow']) ? $item['hover_effect'] : 'lift',
                'play_button_style' => in_array($item['play_button_style'] ?? '', ['circle', 'square', 'youtube', 'minimal', 'none']) ? $item['play_button_style'] : 'circle',
                'border_radius' => max(0, min(32, intval($item['border_radius'] ?? 12))),
                // Slider/Carousel
                'slider_arrows' => !empty($item['slider_arrows']),
                'slider_dots' => !empty($item['slider_dots']),
                'slider_autoplay' => !empty($item['slider_autoplay']),
                'slider_autoplay_speed' => max(1000, min(15000, intval($item['slider_autoplay_speed'] ?? 5000))),
                'carousel_loop' => !empty($item['carousel_loop']),
                'carousel_center' => !empty($item['carousel_center']),
            ];
        }
        return $out;
    }

    private function get_galleries() {
        $items = get_option(self::OPTION_KEY, []);
        if (!is_array($items)) {
            return [];
        }
        return array_values($items);
    }

    private function find_gallery($id) {
        $items = get_option(self::OPTION_KEY, []);
        if (!is_array($items)) {
            return null;
        }
        $id = sanitize_title($id);
        return $items[$id] ?? null;
    }

    public function render_gallery($atts) {
        $atts = shortcode_atts([
            'id' => '',
            'videos' => '',
            'autoplay' => '',
            'layout' => '',
            'columns' => '',
            'theme' => '',
            'popup' => '',
        ], $atts);

        $gallery_id = trim((string) $atts['id']);
        $videos = [];
        $settings = $this->default_settings;

        if ($gallery_id !== '') {
            $gallery = $this->find_gallery($gallery_id);
            if (!$gallery) {
                return '';
            }
            $videos = $this->parse_videos_from_rows($gallery['videos'] ?? []);
            $settings = wp_parse_args($gallery, $this->default_settings);

            // Allow shortcode overrides
            if ($atts['autoplay'] !== '') {
                $settings['autoplay'] = ($atts['autoplay'] === '1' || $atts['autoplay'] === 'true');
            }
            if ($atts['layout'] !== '' && in_array($atts['layout'], ['slider', 'grid', 'carousel', 'masonry'])) {
                $settings['layout'] = $atts['layout'];
            }
            if ($atts['columns'] !== '') {
                $settings['columns_desktop'] = max(2, min(6, intval($atts['columns'])));
            }
            if ($atts['theme'] !== '' && in_array($atts['theme'], ['light', 'dark', 'auto'])) {
                $settings['theme'] = $atts['theme'];
            }
            if ($atts['popup'] !== '' && in_array($atts['popup'], ['lightbox', 'inline', 'theater', 'side_panel', 'none'])) {
                $settings['popup_style'] = $atts['popup'];
            }
        } else {
            // Inline videos mode
            $videos_raw = trim((string) $atts['videos']);
            if ($videos_raw === '') {
                return '';
            }
            $videos = $this->parse_videos($videos_raw);
            if ($atts['autoplay'] !== '') {
                $settings['autoplay'] = ($atts['autoplay'] === '1' || $atts['autoplay'] === 'true');
            }
        }

        if (empty($videos)) {
            return '';
        }

        $videos = $this->hydrate_video_metadata($videos);
        $uid = 'avg-' . uniqid();

        return $this->render_output($uid, $videos, $settings);
    }

    private function render_output($uid, $videos, $settings) {
        $layout = $settings['layout'];
        $classes = [
            'anchor-video-gallery',
            'avg-layout-' . $layout,
            'avg-theme-' . $settings['theme'],
            'avg-tiles-' . $settings['tile_style'],
            'avg-hover-' . $settings['hover_effect'],
            'avg-play-' . $settings['play_button_style'],
        ];

        $data_attrs = [
            'data-autoplay' => $settings['autoplay'] ? '1' : '0',
            'data-popup' => $settings['popup_style'],
            'data-layout' => $layout,
        ];

        if (in_array($layout, ['grid', 'masonry'])) {
            $data_attrs['data-columns-desktop'] = $settings['columns_desktop'];
            $data_attrs['data-columns-tablet'] = $settings['columns_tablet'];
            $data_attrs['data-columns-mobile'] = $settings['columns_mobile'];
            $data_attrs['data-gap'] = $settings['gap'];

            if ($settings['pagination_enabled']) {
                $data_attrs['data-pagination'] = $settings['pagination_style'];
                $data_attrs['data-per-page'] = $settings['videos_per_page'];
            }
        }

        if (in_array($layout, ['slider', 'carousel'])) {
            $data_attrs['data-arrows'] = $settings['slider_arrows'] ? '1' : '0';
            $data_attrs['data-dots'] = $settings['slider_dots'] ? '1' : '0';
            $data_attrs['data-slider-autoplay'] = $settings['slider_autoplay'] ? '1' : '0';
            $data_attrs['data-autoplay-speed'] = $settings['slider_autoplay_speed'];

            if ($layout === 'carousel') {
                $data_attrs['data-loop'] = $settings['carousel_loop'] ? '1' : '0';
                $data_attrs['data-center'] = $settings['carousel_center'] ? '1' : '0';
            }
        }

        $style_vars = [
            '--avg-gap: ' . intval($settings['gap']) . 'px',
            '--avg-radius: ' . intval($settings['border_radius']) . 'px',
            '--avg-cols-desktop: ' . intval($settings['columns_desktop']),
            '--avg-cols-tablet: ' . intval($settings['columns_tablet']),
            '--avg-cols-mobile: ' . intval($settings['columns_mobile']),
        ];

        ob_start();
        ?>
        <div class="<?php echo esc_attr(implode(' ', $classes)); ?>"
             id="<?php echo esc_attr($uid); ?>"
             <?php foreach ($data_attrs as $key => $val): ?>
                <?php echo esc_attr($key); ?>="<?php echo esc_attr($val); ?>"
             <?php endforeach; ?>
             style="<?php echo esc_attr(implode('; ', $style_vars)); ?>">

            <?php if (in_array($layout, ['slider', 'carousel']) && $settings['slider_arrows']): ?>
            <button type="button" class="avg-nav avg-nav-prev" aria-label="<?php esc_attr_e('Previous', 'anchor-schema'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15,6 9,12 15,18"></polyline></svg>
            </button>
            <?php endif; ?>

            <div class="avg-track">
                <?php
                $total = count($videos);
                $per_page = $settings['pagination_enabled'] ? $settings['videos_per_page'] : $total;
                $show_count = min($per_page, $total);

                foreach ($videos as $i => $video):
                    $hidden = $settings['pagination_enabled'] && $i >= $per_page;
                ?>
                <div class="avg-tile<?php echo $hidden ? ' avg-hidden' : ''; ?>"
                     data-index="<?php echo esc_attr($i); ?>"
                     data-provider="<?php echo esc_attr($video['provider']); ?>"
                     data-video-id="<?php echo esc_attr($video['id']); ?>"
                     data-url="<?php echo esc_attr($video['raw_url'] ?? ''); ?>"
                     <?php if (!empty($video['duration'])): ?>data-duration="<?php echo esc_attr($video['duration']); ?>"<?php endif; ?>>
                    <div class="avg-tile-inner">
                        <div class="avg-thumb"<?php echo !empty($video['thumb']) ? ' style="background-image:url(' . esc_url($video['thumb']) . ')"' : ''; ?>>
                            <?php if ($settings['play_button_style'] !== 'none'): ?>
                            <span class="avg-play" aria-hidden="true">
                                <?php echo $this->get_play_button_svg($settings['play_button_style']); ?>
                            </span>
                            <?php endif; ?>
                            <?php if ($settings['show_duration'] && !empty($video['duration'])): ?>
                            <span class="avg-duration"><?php echo esc_html($video['duration']); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($settings['show_title'] || $settings['show_channel']): ?>
                        <div class="avg-meta">
                            <?php if ($settings['show_title']): ?>
                            <span class="avg-title"><?php echo esc_html($video['label']); ?></span>
                            <?php endif; ?>
                            <?php if ($settings['show_channel'] && !empty($video['channel'])): ?>
                            <span class="avg-channel"><?php echo esc_html($video['channel']); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if (in_array($layout, ['slider', 'carousel']) && $settings['slider_arrows']): ?>
            <button type="button" class="avg-nav avg-nav-next" aria-label="<?php esc_attr_e('Next', 'anchor-schema'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9,6 15,12 9,18"></polyline></svg>
            </button>
            <?php endif; ?>

            <?php if (in_array($layout, ['slider', 'carousel']) && $settings['slider_dots']): ?>
            <div class="avg-dots"></div>
            <?php endif; ?>

            <?php if ($settings['pagination_enabled'] && $total > $per_page): ?>
            <div class="avg-pagination" data-total="<?php echo esc_attr($total); ?>" data-per-page="<?php echo esc_attr($per_page); ?>">
                <?php if ($settings['pagination_style'] === 'numbered'): ?>
                    <div class="avg-pagination-numbers"></div>
                <?php elseif ($settings['pagination_style'] === 'load_more'): ?>
                    <button type="button" class="avg-load-more"><?php esc_html_e('Load More', 'anchor-schema'); ?></button>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private function get_play_button_svg($style) {
        switch ($style) {
            case 'youtube':
                return '<svg viewBox="0 0 68 48"><path fill="#f00" d="M66.52 7.74c-.78-2.93-2.49-5.41-5.42-6.19C55.79.13 34 0 34 0S12.21.13 6.9 1.55c-2.93.78-4.63 3.26-5.42 6.19C.06 13.05 0 24 0 24s.06 10.95 1.48 16.26c.78 2.93 2.49 5.41 5.42 6.19C12.21 47.87 34 48 34 48s21.79-.13 27.1-1.55c2.93-.78 4.63-3.26 5.42-6.19C67.94 34.95 68 24 68 24s-.06-10.95-1.48-16.26z"/><path fill="#fff" d="M45 24L27 14v20"/></svg>';
            case 'square':
                return '<svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2" fill="currentColor" opacity="0.8"/><polygon points="10,8 16,12 10,16" fill="#fff"/></svg>';
            case 'minimal':
                return '<svg viewBox="0 0 24 24"><polygon points="8,5 19,12 8,19" fill="currentColor"/></svg>';
            case 'circle':
            default:
                return '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="11" fill="currentColor" opacity="0.85"/><polygon points="10,8 16,12 10,16" fill="#fff"/></svg>';
        }
    }

    private function parse_videos($raw) {
        $urls = preg_split('/[\r\n,]+/', $raw);
        $out = [];
        foreach ($urls as $row) {
            $row = trim((string) $row);
            if ($row === '') {
                continue;
            }

            $parts = array_map('trim', explode('|', $row));
            $url = $parts[0] ?? '';
            if ($url === '') {
                continue;
            }
            $thumb_override = $parts[1] ?? '';
            $title_override = $parts[2] ?? '';

            $video = $this->normalize_video_url($url);
            if (!$video) {
                continue;
            }
            if ($thumb_override !== '') {
                $video['custom_thumb'] = $thumb_override;
            }
            if ($title_override !== '') {
                $video['custom_title'] = $title_override;
            }
            $out[] = $video;
        }
        return $out;
    }

    private function parse_videos_from_rows($rows) {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $url = trim((string) ($row['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $video = $this->normalize_video_url($url);
            if (!$video) {
                continue;
            }
            $thumb = trim((string) ($row['thumb'] ?? ''));
            $title = trim((string) ($row['title'] ?? ''));
            $desc = trim((string) ($row['description'] ?? ''));
            if ($thumb !== '') {
                $video['custom_thumb'] = $thumb;
            }
            if ($title !== '') {
                $video['custom_title'] = $title;
            }
            if ($desc !== '') {
                $video['description'] = $desc;
            }
            $out[] = $video;
        }
        return $out;
    }

    private function normalize_video_url($url) {
        $id = '';
        if (preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\\?v=|embed/|shorts/))([A-Za-z0-9_-]{6,})~', $url, $matches)) {
            $id = $matches[1];
            return [
                'provider' => 'youtube',
                'id' => $id,
                'thumb' => '',
                'fallback_thumb' => 'https://img.youtube.com/vi/' . $id . '/hqdefault.jpg',
                'label' => 'YouTube Video',
                'raw_url' => $url,
                'duration' => '',
                'channel' => '',
            ];
        }

        if (preg_match('~vimeo\.com/(?:video/)?([0-9]+)~', $url, $matches)) {
            $id = $matches[1];
            return [
                'provider' => 'vimeo',
                'id' => $id,
                'thumb' => '',
                'fallback_thumb' => '',
                'label' => 'Vimeo Video',
                'raw_url' => $url,
                'duration' => '',
                'channel' => '',
            ];
        }

        return null;
    }

    private function hydrate_video_metadata($videos) {
        if (empty($videos)) {
            return [];
        }

        $yt_ids = [];
        $vm_ids = [];
        foreach ($videos as $video) {
            if ($video['provider'] === 'youtube') {
                $yt_ids[] = $video['id'];
            } elseif ($video['provider'] === 'vimeo') {
                $vm_ids[] = $video['id'];
            }
        }

        $yt_details = $this->fetch_youtube_details($yt_ids);
        $vm_details = $this->fetch_vimeo_details($vm_ids);

        foreach ($videos as &$video) {
            $custom_title = isset($video['custom_title']) ? (string) $video['custom_title'] : '';
            $custom_thumb = isset($video['custom_thumb']) ? (string) $video['custom_thumb'] : '';

            if ($custom_thumb !== '') {
                $video['thumb'] = $this->resolve_custom_thumb($custom_thumb);
            }

            if ($video['provider'] === 'youtube' && isset($yt_details[$video['id']])) {
                $meta = $yt_details[$video['id']];
                if ($video['thumb'] === '') {
                    $video['thumb'] = $meta['thumb'] ?? $video['thumb'];
                }
                if ($custom_title === '') {
                    $video['label'] = $meta['title'] ?? $video['label'];
                }
                if (!empty($meta['duration'])) {
                    $video['duration'] = $meta['duration'];
                }
                if (!empty($meta['channel'])) {
                    $video['channel'] = $meta['channel'];
                }
            }

            if ($video['provider'] === 'vimeo' && isset($vm_details[$video['id']])) {
                $meta = $vm_details[$video['id']];
                if ($video['thumb'] === '') {
                    $video['thumb'] = $meta['thumb'] ?? $video['thumb'];
                }
                if ($custom_title === '') {
                    $video['label'] = $meta['title'] ?? $video['label'];
                }
                if (!empty($meta['duration'])) {
                    $video['duration'] = $meta['duration'];
                }
                if (!empty($meta['channel'])) {
                    $video['channel'] = $meta['channel'];
                }
            }

            if ($custom_title !== '') {
                $video['label'] = $custom_title;
            }

            if ($video['thumb'] === '' && !empty($video['fallback_thumb'])) {
                $video['thumb'] = $video['fallback_thumb'];
            }
        }
        unset($video);

        return $videos;
    }

    private function resolve_custom_thumb($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        if (ctype_digit($value)) {
            $url = wp_get_attachment_image_url((int) $value, 'large');
            return $url ? $url : '';
        }
        if (strpos($value, 'http://') === 0 || strpos($value, 'https://') === 0) {
            return $value;
        }
        return '';
    }

    private function fetch_youtube_details($ids) {
        $ids = array_values(array_unique(array_filter($ids)));
        if (empty($ids)) {
            return [];
        }
        $api_key = $this->get_google_api_key();
        if (!$api_key) {
            return [];
        }

        $out = [];
        foreach (array_chunk($ids, 50) as $chunk) {
            $cache_key = 'anchor_vs_yt_' . md5(implode(',', $chunk));
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                $out = array_merge($out, $cached);
                continue;
            }

            $url = add_query_arg([
                'part' => 'snippet,contentDetails',
                'id' => implode(',', $chunk),
                'key' => $api_key,
            ], 'https://www.googleapis.com/youtube/v3/videos');

            $res = wp_remote_get($url, ['timeout' => 12]);
            if (is_wp_error($res)) {
                continue;
            }
            $data = json_decode(wp_remote_retrieve_body($res), true);
            $batch = [];
            foreach (($data['items'] ?? []) as $item) {
                $sn = $item['snippet'] ?? [];
                $cd = $item['contentDetails'] ?? [];
                $vid = $item['id'] ?? '';
                if (!$vid) {
                    continue;
                }
                $thumb = '';
                if (!empty($sn['thumbnails']['high']['url'])) {
                    $thumb = $sn['thumbnails']['high']['url'];
                } elseif (!empty($sn['thumbnails'])) {
                    $first = reset($sn['thumbnails']);
                    $thumb = $first['url'] ?? '';
                }

                $duration = '';
                if (!empty($cd['duration'])) {
                    $duration = $this->format_iso_duration($cd['duration']);
                }

                $batch[$vid] = [
                    'title' => $sn['title'] ?? '',
                    'thumb' => $thumb ?: 'https://i.ytimg.com/vi/' . $vid . '/hqdefault.jpg',
                    'duration' => $duration,
                    'channel' => $sn['channelTitle'] ?? '',
                ];
            }
            set_transient($cache_key, $batch, 12 * HOUR_IN_SECONDS);
            $out = array_merge($out, $batch);
        }
        return $out;
    }

    private function fetch_vimeo_details($ids) {
        $ids = array_values(array_unique(array_filter($ids)));
        if (empty($ids)) {
            return [];
        }

        $out = [];
        foreach ($ids as $id) {
            $cache_key = 'anchor_vs_vm_' . md5($id);
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                $out[$id] = $cached;
                continue;
            }

            $url = 'https://vimeo.com/' . rawurlencode($id);
            $oembed = add_query_arg([
                'url' => $url,
                'dnt' => '1',
            ], 'https://vimeo.com/api/oembed.json');

            $res = wp_remote_get($oembed, ['timeout' => 12]);
            if (is_wp_error($res)) {
                continue;
            }
            $data = json_decode(wp_remote_retrieve_body($res), true);
            if (!is_array($data)) {
                continue;
            }

            $duration = '';
            if (!empty($data['duration'])) {
                $duration = $this->format_seconds_duration((int) $data['duration']);
            }

            $meta = [
                'title' => $data['title'] ?? '',
                'thumb' => $data['thumbnail_url'] ?? '',
                'duration' => $duration,
                'channel' => $data['author_name'] ?? '',
            ];
            $out[$id] = $meta;
            set_transient($cache_key, $meta, 24 * HOUR_IN_SECONDS);
        }
        return $out;
    }

    private function format_iso_duration($iso) {
        // Convert ISO 8601 duration (PT1H2M3S) to human readable (1:02:03)
        preg_match('/PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?/', $iso, $m);
        $h = (int) ($m[1] ?? 0);
        $min = (int) ($m[2] ?? 0);
        $s = (int) ($m[3] ?? 0);

        if ($h > 0) {
            return sprintf('%d:%02d:%02d', $h, $min, $s);
        }
        return sprintf('%d:%02d', $min, $s);
    }

    private function format_seconds_duration($seconds) {
        $h = floor($seconds / 3600);
        $m = floor(($seconds % 3600) / 60);
        $s = $seconds % 60;

        if ($h > 0) {
            return sprintf('%d:%02d:%02d', $h, $m, $s);
        }
        return sprintf('%d:%02d', $m, $s);
    }

    private function get_google_api_key() {
        if (class_exists('Anchor_Schema_Admin')) {
            $opts = get_option(Anchor_Schema_Admin::OPTION_KEY, []);
            $key = trim($opts['google_api_key'] ?? '');
            if ($key !== '') {
                return $key;
            }
            $legacy = trim($opts['youtube_api_key'] ?? '');
            if ($legacy !== '') {
                return $legacy;
            }
        }
        return '';
    }
}
