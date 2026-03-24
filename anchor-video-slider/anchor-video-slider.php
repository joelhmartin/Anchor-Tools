<?php
/**
 * Anchor Tools module: Anchor Gallery.
 *
 * Custom Post Type based gallery with multiple display modes.
 * Supports: videos (YouTube/Vimeo), images (WP media library).
 * Layouts: slider, grid, carousel, masonry, logo_carousel.
 * Popup styles: lightbox, inline, theater, side panel, none.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Anchor_Video_Slider_Module {
    const CPT        = 'anchor_gallery';
    const OLD_CPT    = 'anchor_video_gallery';
    const NONCE      = 'avg_nonce';
    const LEGACY_KEY = 'anchor_video_slider_items';

    private $sample_videos = [
        ['provider' => 'youtube', 'id' => 'dQw4w9WgXcQ', 'label' => 'Sample Video 1', 'thumb' => 'https://img.youtube.com/vi/dQw4w9WgXcQ/hqdefault.jpg', 'duration' => '3:33', 'channel' => 'Demo Channel'],
        ['provider' => 'youtube', 'id' => 'jNQXAC9IVRw', 'label' => 'Sample Video 2', 'thumb' => 'https://img.youtube.com/vi/jNQXAC9IVRw/hqdefault.jpg', 'duration' => '0:19', 'channel' => 'Demo Channel'],
        ['provider' => 'youtube', 'id' => '9bZkp7q19f0', 'label' => 'Sample Video 3', 'thumb' => 'https://img.youtube.com/vi/9bZkp7q19f0/hqdefault.jpg', 'duration' => '4:13', 'channel' => 'Demo Channel'],
        ['provider' => 'youtube', 'id' => 'kJQP7kiw5Fk', 'label' => 'Sample Video 4', 'thumb' => 'https://img.youtube.com/vi/kJQP7kiw5Fk/hqdefault.jpg', 'duration' => '4:42', 'channel' => 'Demo Channel'],
        ['provider' => 'youtube', 'id' => 'RgKAFK5djSk', 'label' => 'Sample Video 5', 'thumb' => 'https://img.youtube.com/vi/RgKAFK5djSk/hqdefault.jpg', 'duration' => '4:57', 'channel' => 'Demo Channel'],
        ['provider' => 'youtube', 'id' => 'JGwWNGJdvx8', 'label' => 'Sample Video 6', 'thumb' => 'https://img.youtube.com/vi/JGwWNGJdvx8/hqdefault.jpg', 'duration' => '4:24', 'channel' => 'Demo Channel'],
    ];

    private $default_settings = [
        'layout' => 'slider',
        'columns_desktop' => 4,
        'columns_tablet' => 3,
        'columns_mobile' => 1,
        'gap' => 16,
        'pagination_enabled' => false,
        'videos_per_page' => 12,
        'pagination_style' => 'numbered',
        'popup_style' => 'lightbox',
        'autoplay' => true,
        'theme' => 'dark',
        'tile_style' => 'card',
        'thumb_aspect_ratio' => '16:9',
        'show_title' => true,
        'show_duration' => true,
        'show_channel' => false,
        'hover_effect' => 'lift',
        'play_button_style' => 'circle',
        'border_radius' => 12,
        'slider_arrows' => true,
        'slider_dots' => false,
        'slider_autoplay' => false,
        'slider_autoplay_speed' => 5000,
        'carousel_loop' => true,
        'carousel_center' => false,
        'equal_height' => false,
        'max_height' => 0,
        'thumb_size' => 'maxres',
        'object_fit' => 'cover',
        'title_position' => 'hidden',
        'marquee_speed' => 30,
        'marquee_gap' => 40,
        'marquee_item_width' => 150,
        'marquee_pause_on_hover' => true,
        'marquee_direction' => 'left',
        'marquee_reverse_row' => false,
    ];

    /* ── Setting definitions for metabox rendering & save ── */

    private function get_setting_defs() {
        return [
            'layout' => ['type' => 'select', 'label' => 'Layout', 'options' => ['slider' => 'Slider', 'grid' => 'Grid', 'carousel' => 'Carousel', 'masonry' => 'Masonry', 'gallery' => 'Gallery', 'logo_carousel' => 'Logo Carousel']],
            'popup_style' => ['type' => 'select', 'label' => 'Popup Style', 'options' => ['lightbox' => 'Lightbox', 'inline' => 'Inline Expand', 'theater' => 'Theater Mode', 'side_panel' => 'Side Panel', 'none' => 'Direct Link'], 'show_for' => 'slider,grid,carousel,masonry,gallery'],
            'theme' => ['type' => 'select', 'label' => 'Theme', 'options' => ['dark' => 'Dark', 'light' => 'Light', 'auto' => 'Auto'], 'show_for' => 'slider,grid,carousel,masonry,gallery'],
            'tile_style' => ['type' => 'select', 'label' => 'Tile Style', 'options' => ['card' => 'Card', 'minimal' => 'Minimal', 'overlay' => 'Overlay', 'cinematic' => 'Cinematic'], 'show_for' => 'slider,grid,carousel,masonry'],
            'thumb_aspect_ratio' => ['type' => 'select', 'label' => 'Thumbnail Aspect Ratio', 'options' => [
                '16:9' => '16:9 (Widescreen)',
                '4:3'  => '4:3 (Classic)',
                '1:1'  => '1:1 (Square)',
                '9:16' => '9:16 (Portrait)',
                '21:9' => '21:9 (Cinematic)',
            ], 'show_for' => 'slider,grid,carousel,masonry'],
            'hover_effect' => ['type' => 'select', 'label' => 'Hover Effect', 'options' => ['lift' => 'Lift', 'zoom' => 'Zoom', 'glow' => 'Glow', 'none' => 'None'], 'show_for' => 'slider,grid,carousel,masonry'],
            'play_button_style' => ['type' => 'select', 'label' => 'Play Button', 'options' => ['circle' => 'Circle', 'square' => 'Square', 'youtube' => 'YouTube', 'minimal' => 'Minimal', 'none' => 'Hidden'], 'show_for' => 'slider,grid,carousel,masonry,gallery'],
            'border_radius' => ['type' => 'number', 'label' => 'Border Radius (px)', 'min' => 0, 'max' => 32, 'step' => 2],
            'columns_desktop' => ['type' => 'number', 'label' => 'Desktop Columns', 'min' => 2, 'max' => 6, 'show_for' => 'grid,masonry'],
            'columns_tablet' => ['type' => 'number', 'label' => 'Tablet Columns', 'min' => 1, 'max' => 4, 'show_for' => 'grid,masonry'],
            'columns_mobile' => ['type' => 'number', 'label' => 'Mobile Columns', 'min' => 1, 'max' => 2, 'show_for' => 'grid,masonry'],
            'gap' => ['type' => 'number', 'label' => 'Gap (px)', 'min' => 0, 'max' => 60, 'step' => 4],
            'show_title' => ['type' => 'checkbox', 'label' => 'Show Title', 'show_for' => 'slider,grid,carousel,masonry,gallery'],
            'show_duration' => ['type' => 'checkbox', 'label' => 'Show Duration', 'show_for' => 'slider,grid,carousel,masonry,gallery'],
            'show_channel' => ['type' => 'checkbox', 'label' => 'Show Channel', 'show_for' => 'slider,grid,carousel,masonry'],
            'title_position' => ['type' => 'select', 'label' => 'Title Position', 'options' => ['hidden' => 'Hidden', 'below' => 'Below Image', 'overlay' => 'Overlay on Image'], 'show_for' => 'grid,masonry,slider,carousel'],
            'equal_height' => ['type' => 'checkbox', 'label' => 'Equal Height Tiles', 'show_for' => 'grid,masonry,slider,carousel'],
            'max_height' => ['type' => 'number', 'label' => 'Max Thumbnail Height (px)', 'min' => 0, 'max' => 1200, 'step' => 10, 'show_for' => 'grid,masonry,slider,carousel'],
            'thumb_size' => ['type' => 'select', 'label' => 'Thumbnail Resolution', 'options' => [
                'maxres'   => 'Max (1280×720)',
                'standard' => 'Standard (640×480)',
                'high'     => 'High (480×360)',
                'medium'   => 'Medium (320×180)',
            ], 'show_for' => 'slider,grid,carousel,masonry,gallery'],
            'object_fit' => ['type' => 'select', 'label' => 'Object Fit', 'options' => ['cover' => 'Cover', 'contain' => 'Contain', 'fill' => 'Fill', 'scale-down' => 'Scale Down', 'none' => 'None']],
            'autoplay' => ['type' => 'checkbox', 'label' => 'Autoplay on popup open', 'show_for' => 'slider,grid,carousel,masonry,gallery'],
            'pagination_enabled' => ['type' => 'checkbox', 'label' => 'Enable Pagination', 'show_for' => 'grid,masonry'],
            'videos_per_page' => ['type' => 'number', 'label' => 'Items Per Page', 'min' => 1, 'max' => 100, 'show_for' => 'grid,masonry'],
            'pagination_style' => ['type' => 'select', 'label' => 'Pagination Style', 'options' => ['numbered' => 'Numbered', 'load_more' => 'Load More', 'infinite' => 'Infinite Scroll'], 'show_for' => 'grid,masonry'],
            'slider_arrows' => ['type' => 'checkbox', 'label' => 'Navigation Arrows', 'show_for' => 'slider,carousel'],
            'slider_dots' => ['type' => 'checkbox', 'label' => 'Dots Navigation', 'show_for' => 'slider,carousel'],
            'slider_autoplay' => ['type' => 'checkbox', 'label' => 'Auto-advance', 'show_for' => 'slider,carousel'],
            'slider_autoplay_speed' => ['type' => 'number', 'label' => 'Autoplay Speed (ms)', 'min' => 1000, 'max' => 15000, 'step' => 500, 'show_for' => 'slider,carousel'],
            'carousel_loop' => ['type' => 'checkbox', 'label' => 'Loop Continuously', 'show_for' => 'carousel'],
            'carousel_center' => ['type' => 'checkbox', 'label' => 'Center Active Slide', 'show_for' => 'carousel'],
            'marquee_speed' => ['type' => 'number', 'label' => 'Scroll Speed (seconds)', 'min' => 5, 'max' => 120, 'step' => 5, 'show_for' => 'logo_carousel'],
            'marquee_gap' => ['type' => 'number', 'label' => 'Item Gap (px)', 'min' => 0, 'max' => 120, 'step' => 4, 'show_for' => 'logo_carousel'],
            'marquee_item_width' => ['type' => 'number', 'label' => 'Item Width (px)', 'min' => 50, 'max' => 400, 'step' => 10, 'show_for' => 'logo_carousel'],
            'marquee_pause_on_hover' => ['type' => 'checkbox', 'label' => 'Pause on Hover', 'show_for' => 'logo_carousel'],
            'marquee_direction' => ['type' => 'select', 'label' => 'Scroll Direction', 'options' => ['left' => 'Left', 'right' => 'Right'], 'show_for' => 'logo_carousel'],
            'marquee_reverse_row' => ['type' => 'checkbox', 'label' => 'Add Reverse Row', 'show_for' => 'logo_carousel'],
        ];
    }

    /* ══════════════════════════════════════════════════════════
       Constructor & hooks
       ══════════════════════════════════════════════════════════ */

    public function __construct() {
        add_action('init', [$this, 'register_cpt']);
        add_action('add_meta_boxes', [$this, 'add_metaboxes']);
        add_action('save_post', [$this, 'save_meta']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_avg_preview', [$this, 'ajax_preview']);
        add_shortcode('anchor_video_slider', [$this, 'render_gallery']);
        add_shortcode('anchor_video_gallery', [$this, 'render_gallery']);
        add_shortcode('anchor_gallery', [$this, 'render_gallery']);

        add_filter('manage_' . self::CPT . '_posts_columns', [$this, 'admin_columns']);
        add_action('manage_' . self::CPT . '_posts_custom_column', [$this, 'admin_column_content'], 10, 2);
    }

    /* ══════════════════════════════════════════════════════════
       CPT Registration
       ══════════════════════════════════════════════════════════ */

    public function register_cpt() {
        $this->migrate_cpt_slug();
        $this->migrate_legacy_data();

        register_post_type(self::CPT, [
            'labels' => [
                'name'          => 'Anchor Galleries',
                'singular_name' => 'Anchor Gallery',
                'add_new_item'  => 'Add New Gallery',
                'edit_item'     => 'Edit Gallery',
                'menu_name'     => 'Anchor Galleries',
            ],
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => apply_filters('anchor_video_gallery_parent_menu', true),
            'menu_icon'    => 'dashicons-format-gallery',
            'supports'     => ['title'],
        ]);
    }

    /* ══════════════════════════════════════════════════════════
       CPT Slug Migration (anchor_video_gallery → anchor_gallery)
       ══════════════════════════════════════════════════════════ */

    private function migrate_cpt_slug() {
        if (get_option('avg_cpt_migrated')) return;

        global $wpdb;
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
            self::OLD_CPT
        ));

        if ($count > 0) {
            $wpdb->update(
                $wpdb->posts,
                ['post_type' => self::CPT],
                ['post_type' => self::OLD_CPT],
                ['%s'],
                ['%s']
            );
            clean_post_cache(0);
        }

        update_option('avg_cpt_migrated', 1, false);
    }

    /* ══════════════════════════════════════════════════════════
       Admin Columns
       ══════════════════════════════════════════════════════════ */

    public function admin_columns($columns) {
        $new = [];
        foreach ($columns as $k => $v) {
            $new[$k] = $v;
            if ($k === 'title') {
                $new['avg_shortcode'] = 'Shortcode';
                $new['avg_layout']    = 'Layout';
                $new['avg_items']     = 'Items';
            }
        }
        return $new;
    }

    public function admin_column_content($column, $post_id) {
        if ($column === 'avg_shortcode') {
            echo '<code>[anchor_gallery id="' . esc_attr($post_id) . '"]</code>';
        } elseif ($column === 'avg_layout') {
            echo esc_html(ucfirst(str_replace('_', ' ', get_post_meta($post_id, 'avg_layout', true) ?: 'slider')));
        } elseif ($column === 'avg_items') {
            $videos = get_post_meta($post_id, 'avg_videos', true);
            echo is_array($videos) ? count($videos) : 0;
        }
    }

    /* ══════════════════════════════════════════════════════════
       Metaboxes
       ══════════════════════════════════════════════════════════ */

    public function add_metaboxes() {
        add_meta_box('avg_videos', 'Gallery Items', [$this, 'render_box_videos'], self::CPT, 'normal', 'high');
        add_meta_box('avg_settings', 'Gallery Settings', [$this, 'render_box_settings'], self::CPT, 'side');
        add_meta_box('avg_preview', 'Live Preview', [$this, 'render_box_preview'], self::CPT, 'normal', 'default');
    }

    public function render_box_videos($post) {
        wp_nonce_field(self::NONCE, self::NONCE);
        $items = get_post_meta($post->ID, 'avg_videos', true);
        if (!is_array($items) || empty($items)) {
            $items = [['type' => 'video', 'url' => '', 'title' => '', 'attachment_id' => 0]];
        }
        ?>
        <div class="avg-bulk-wrap">
            <textarea id="avg-bulk-urls" rows="3" placeholder="Paste video URLs here, one per line (YouTube &amp; Vimeo)"></textarea>
            <button type="button" class="button button-primary" id="avg-bulk-import">Import URLs</button>
        </div>

        <div id="avg-video-list">
            <?php foreach ($items as $i => $v):
                $type = $v['type'] ?? 'video';
                $att_id = intval($v['attachment_id'] ?? 0);
                $img_preview = $att_id ? wp_get_attachment_image_url($att_id, 'thumbnail') : '';
            ?>
            <div class="avg-video-row" data-index="<?php echo esc_attr($i); ?>">
                <select name="avg_videos[<?php echo esc_attr($i); ?>][type]" class="avg-item-type">
                    <option value="video"<?php selected($type, 'video'); ?>>Video</option>
                    <option value="image"<?php selected($type, 'image'); ?>>Image</option>
                </select>
                <div class="avg-video-fields"<?php echo $type === 'image' ? ' style="display:none"' : ''; ?>>
                    <input type="url" name="avg_videos[<?php echo esc_attr($i); ?>][url]" value="<?php echo esc_attr($v['url'] ?? ''); ?>" placeholder="https://youtube.com/watch?v=..." class="avg-video-url" />
                </div>
                <div class="avg-image-fields"<?php echo $type === 'video' ? ' style="display:none"' : ''; ?>>
                    <input type="hidden" name="avg_videos[<?php echo esc_attr($i); ?>][attachment_id]" value="<?php echo esc_attr($att_id); ?>" class="avg-attachment-id" />
                    <button type="button" class="button avg-choose-image">Choose Image</button>
                    <?php if ($img_preview): ?>
                    <img src="<?php echo esc_url($img_preview); ?>" class="avg-image-preview" />
                    <?php endif; ?>
                </div>
                <input type="text" name="avg_videos[<?php echo esc_attr($i); ?>][title]" value="<?php echo esc_attr($v['title'] ?? ''); ?>" placeholder="Optional title" class="avg-video-title" />
                <button type="button" class="button avg-remove-video">&times;</button>
            </div>
            <?php endforeach; ?>
        </div>

        <p>
            <button type="button" class="button" id="avg-add-video">+ Add Video</button>
            <button type="button" class="button" id="avg-add-image">+ Add Image</button>
        </p>
        <?php
    }

    public function render_box_settings($post) {
        $defs = $this->get_setting_defs();
        foreach ($defs as $key => $def) {
            $meta_key = 'avg_' . $key;
            $saved = get_post_meta($post->ID, $meta_key, true);
            $value = ($saved !== '' && $saved !== false) ? $saved : $this->default_settings[$key];
            $show_for = isset($def['show_for']) ? $def['show_for'] : '';
            $wrap_class = 'avg-setting-row';
            if ($show_for) {
                $wrap_class .= ' avg-show-for';
            }
            ?>
            <p class="<?php echo esc_attr($wrap_class); ?>"<?php if ($show_for): ?> data-show-for="<?php echo esc_attr($show_for); ?>"<?php endif; ?>>
            <?php if ($def['type'] === 'select'): ?>
                <label for="<?php echo esc_attr($meta_key); ?>"><strong><?php echo esc_html($def['label']); ?></strong></label><br>
                <select name="<?php echo esc_attr($meta_key); ?>" id="<?php echo esc_attr($meta_key); ?>" class="widefat avg-setting">
                    <?php foreach ($def['options'] as $opt_val => $opt_label): ?>
                        <option value="<?php echo esc_attr($opt_val); ?>" <?php selected($value, $opt_val); ?>><?php echo esc_html($opt_label); ?></option>
                    <?php endforeach; ?>
                </select>
            <?php elseif ($def['type'] === 'number'): ?>
                <label for="<?php echo esc_attr($meta_key); ?>"><strong><?php echo esc_html($def['label']); ?></strong></label><br>
                <input type="number" name="<?php echo esc_attr($meta_key); ?>" id="<?php echo esc_attr($meta_key); ?>" value="<?php echo esc_attr($value); ?>" min="<?php echo esc_attr($def['min'] ?? 0); ?>" max="<?php echo esc_attr($def['max'] ?? 999); ?>" step="<?php echo esc_attr($def['step'] ?? 1); ?>" class="widefat avg-setting" />
            <?php elseif ($def['type'] === 'checkbox'): ?>
                <label>
                    <input type="checkbox" name="<?php echo esc_attr($meta_key); ?>" value="1" <?php checked($value); ?> class="avg-setting" />
                    <strong><?php echo esc_html($def['label']); ?></strong>
                </label>
            <?php endif; ?>
            </p>
            <?php
        }
    }

    public function render_box_preview($post) {
        $preview_videos = $this->sample_videos;
        foreach ($preview_videos as &$v) {
            $v['raw_url'] = 'https://youtube.com/watch?v=' . $v['id'];
        }
        $settings = [];
        foreach ($this->default_settings as $key => $default) {
            $saved = get_post_meta($post->ID, 'avg_' . $key, true);
            $settings[$key] = ($saved !== '' && $saved !== false) ? $saved : $default;
            if (is_bool($default)) {
                $settings[$key] = (bool) $settings[$key];
            } elseif (is_int($default)) {
                $settings[$key] = (int) $settings[$key];
            }
        }
        ?>
        <div class="avg-preview-wrap">
            <div class="avg-preview-content">
                <?php echo $this->render_dispatch('avg-preview-init', $preview_videos, $settings); ?>
            </div>
            <p class="avg-preview-note">Preview uses sample videos. Your actual content appears on the front end.</p>
        </div>
        <?php
    }

    /* ══════════════════════════════════════════════════════════
       Save Meta
       ══════════════════════════════════════════════════════════ */

    public function save_meta($post_id) {
        if (!isset($_POST[self::NONCE]) || !wp_verify_nonce($_POST[self::NONCE], self::NONCE)) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        // Save items (videos + images)
        $raw_items = isset($_POST['avg_videos']) && is_array($_POST['avg_videos']) ? $_POST['avg_videos'] : [];
        $items = [];
        foreach ($raw_items as $v) {
            if (!is_array($v)) continue;
            $type = sanitize_text_field($v['type'] ?? 'video');
            if (!in_array($type, ['video', 'image'])) $type = 'video';

            if ($type === 'video') {
                $url = esc_url_raw(trim($v['url'] ?? ''));
                if ($url === '') continue;
                $items[] = [
                    'type'          => 'video',
                    'url'           => $url,
                    'title'         => sanitize_text_field($v['title'] ?? ''),
                    'attachment_id' => 0,
                ];
            } else {
                $att_id = absint($v['attachment_id'] ?? 0);
                if ($att_id === 0) continue;
                $items[] = [
                    'type'          => 'image',
                    'url'           => '',
                    'title'         => sanitize_text_field($v['title'] ?? ''),
                    'attachment_id' => $att_id,
                ];
            }
        }
        update_post_meta($post_id, 'avg_videos', $items);

        // Save settings
        $defs = $this->get_setting_defs();
        foreach ($defs as $key => $def) {
            $meta_key = 'avg_' . $key;
            if ($def['type'] === 'checkbox') {
                $val = isset($_POST[$meta_key]) ? '1' : '0';
            } elseif ($def['type'] === 'number') {
                $val = isset($_POST[$meta_key]) ? intval($_POST[$meta_key]) : $this->default_settings[$key];
                if (isset($def['min'])) $val = max($def['min'], $val);
                if (isset($def['max'])) $val = min($def['max'], $val);
            } elseif ($def['type'] === 'select') {
                $val = isset($_POST[$meta_key]) ? sanitize_text_field($_POST[$meta_key]) : $this->default_settings[$key];
                if (isset($def['options']) && !array_key_exists($val, $def['options'])) {
                    $val = $this->default_settings[$key];
                }
            } else {
                $val = isset($_POST[$meta_key]) ? sanitize_text_field($_POST[$meta_key]) : '';
            }
            update_post_meta($post_id, $meta_key, $val);
        }

        // Clear cached thumbnails so the next page load re-fetches at the
        // current resolution and picks up any changes on the provider side.
        $this->clear_video_transients($post_id);
    }

    private function clear_video_transients($post_id) {
        $items = get_post_meta($post_id, 'avg_videos', true);
        if (!is_array($items)) return;

        $sizes = ['maxres', 'standard', 'high', 'medium', 'default'];

        foreach ($items as $item) {
            $parsed = $this->normalize_video_url($item['url'] ?? '');
            if (!$parsed || empty($parsed['id'])) continue;
            $id       = $parsed['id'];
            $provider = $parsed['provider'];

            foreach ($sizes as $size) {
                if ($provider === 'youtube') {
                    delete_transient('anchor_vs_yt_' . $size . '_' . $id);
                } elseif ($provider === 'vimeo') {
                    delete_transient('anchor_vs_vm_' . $size . '_' . md5($id));
                }
            }
        }
    }

    /* ══════════════════════════════════════════════════════════
       Admin Assets (proven post.php / post-new.php pattern)
       ══════════════════════════════════════════════════════════ */

    public function enqueue_admin_assets($hook) {
        global $post;
        if (($hook === 'post-new.php' || $hook === 'post.php') && isset($post) && $post->post_type === self::CPT) {
            wp_enqueue_media();

            $base_dir = ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-video-slider/assets/';
            $base_url = ANCHOR_TOOLS_PLUGIN_URL . 'anchor-video-slider/assets/';
            $ver = filemtime($base_dir . 'admin.js');

            // Frontend styles/script for preview
            wp_enqueue_style('anchor-video-gallery', $base_url . 'anchor-video-slider.css', [], filemtime($base_dir . 'anchor-video-slider.css'));
            wp_enqueue_script('anchor-video-gallery', $base_url . 'anchor-video-slider.js', [], filemtime($base_dir . 'anchor-video-slider.js'), true);

            // Admin
            wp_enqueue_style('anchor-video-gallery-admin', $base_url . 'admin.css', [], $ver);
            wp_enqueue_script('anchor-video-gallery-admin', $base_url . 'admin.js', ['jquery'], $ver, true);
            wp_localize_script('anchor-video-gallery-admin', 'AVG', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('avg_preview'),
            ]);
        }
    }

    /* ══════════════════════════════════════════════════════════
       Frontend Assets
       ══════════════════════════════════════════════════════════ */

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

    /* ══════════════════════════════════════════════════════════
       AJAX Preview
       ══════════════════════════════════════════════════════════ */

    public function ajax_preview() {
        check_ajax_referer('avg_preview', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

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

        $videos = $this->sample_videos;
        foreach ($videos as &$v) {
            $v['raw_url'] = 'https://youtube.com/watch?v=' . $v['id'];
        }

        $html = $this->render_dispatch('avg-preview-' . uniqid(), $videos, $settings);
        wp_send_json_success(['html' => $html]);
    }

    /* ══════════════════════════════════════════════════════════
       Shortcode
       ══════════════════════════════════════════════════════════ */

    public function render_gallery($atts) {
        $atts = shortcode_atts([
            'id'       => '',
            'videos'   => '',
            'autoplay' => '',
            'layout'   => '',
            'columns'  => '',
            'theme'    => '',
            'popup'    => '',
        ], $atts);

        $gallery_id = trim((string) $atts['id']);
        $videos = [];
        $settings = $this->default_settings;

        if ($gallery_id !== '') {
            // Resolve CPT post: by ID, slug, or legacy option fallback
            $post = null;
            if (is_numeric($gallery_id)) {
                $post = get_post((int) $gallery_id);
                if ($post && $post->post_type !== self::CPT) {
                    $post = null;
                }
            }
            if (!$post) {
                $found = get_posts([
                    'post_type'      => [self::CPT, self::OLD_CPT],
                    'name'           => $gallery_id,
                    'posts_per_page' => 1,
                    'post_status'    => 'publish',
                ]);
                $post = $found[0] ?? null;
            }

            // Legacy option fallback
            if (!$post) {
                $legacy = get_option(self::LEGACY_KEY, []);
                if (is_array($legacy) && isset($legacy[$gallery_id])) {
                    $gallery = $legacy[$gallery_id];
                    $videos  = $this->parse_videos_from_rows($gallery['videos'] ?? []);
                    $settings = wp_parse_args($gallery, $this->default_settings);
                    return $this->apply_shortcode_overrides_and_render($atts, $videos, $settings);
                }
                return '';
            }

            // Load from CPT meta
            $raw_videos = get_post_meta($post->ID, 'avg_videos', true);
            $videos = $this->parse_videos_from_rows(is_array($raw_videos) ? $raw_videos : []);
            foreach ($this->default_settings as $key => $default) {
                $saved = get_post_meta($post->ID, 'avg_' . $key, true);
                if ($saved !== '' && $saved !== false) {
                    if (is_bool($default)) {
                        $settings[$key] = (bool) $saved;
                    } elseif (is_int($default)) {
                        $settings[$key] = (int) $saved;
                    } else {
                        $settings[$key] = $saved;
                    }
                }
            }
        } else {
            // Inline videos mode
            $videos_raw = trim((string) $atts['videos']);
            if ($videos_raw === '') return '';
            $videos = $this->parse_videos($videos_raw);
        }

        return $this->apply_shortcode_overrides_and_render($atts, $videos, $settings);
    }

    private function apply_shortcode_overrides_and_render($atts, $videos, $settings) {
        if (empty($videos)) return '';

        if ($atts['autoplay'] !== '') {
            $settings['autoplay'] = ($atts['autoplay'] === '1' || $atts['autoplay'] === 'true');
        }
        if ($atts['layout'] !== '' && in_array($atts['layout'], ['slider', 'grid', 'carousel', 'masonry', 'logo_carousel'])) {
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

        $videos = $this->hydrate_video_metadata($videos, $settings['thumb_size'] ?? 'maxres');

        return $this->render_dispatch('avg-' . uniqid(), $videos, $settings);
    }

    /* ══════════════════════════════════════════════════════════
       Legacy Data Migration
       ══════════════════════════════════════════════════════════ */

    private function migrate_legacy_data() {
        $legacy = get_option(self::LEGACY_KEY, []);
        if (!is_array($legacy) || empty($legacy)) return;

        // Only migrate once — check transient flag
        if (get_transient('avg_migrated')) return;

        foreach ($legacy as $id => $gallery) {
            // Skip if a CPT with this slug already exists
            $existing = get_posts([
                'post_type'      => self::CPT,
                'name'           => sanitize_title($id),
                'posts_per_page' => 1,
                'post_status'    => 'any',
            ]);
            if (!empty($existing)) continue;

            $post_id = wp_insert_post([
                'post_type'   => self::CPT,
                'post_title'  => $gallery['title'] ?: $id,
                'post_name'   => sanitize_title($id),
                'post_status' => 'publish',
            ]);

            if (!$post_id || is_wp_error($post_id)) continue;

            // Save videos
            $videos = [];
            foreach (($gallery['videos'] ?? []) as $v) {
                if (!is_array($v) || empty($v['url'])) continue;
                $videos[] = [
                    'type'          => 'video',
                    'url'           => $v['url'],
                    'title'         => $v['title'] ?? '',
                    'attachment_id' => 0,
                ];
            }
            update_post_meta($post_id, 'avg_videos', $videos);

            // Save each setting
            foreach ($this->default_settings as $key => $default) {
                if (isset($gallery[$key])) {
                    $val = $gallery[$key];
                    if (is_bool($default)) {
                        $val = $val ? '1' : '';
                    }
                    update_post_meta($post_id, 'avg_' . $key, $val);
                }
            }
        }

        delete_option(self::LEGACY_KEY);
        set_transient('avg_migrated', 1, HOUR_IN_SECONDS);
    }

    /* ══════════════════════════════════════════════════════════
       Frontend Rendering: Standard Layouts
       ══════════════════════════════════════════════════════════ */

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

        if (!empty($settings['equal_height'])) {
            $classes[] = 'avg-equal-height';
        }

        $title_position = $settings['title_position'] ?? 'hidden';

        $data_attrs = [
            'data-autoplay' => $settings['autoplay'] ? '1' : '0',
            'data-popup'    => $settings['popup_style'],
            'data-layout'   => $layout,
        ];

        if (in_array($layout, ['grid', 'masonry'])) {
            $data_attrs['data-columns-desktop'] = $settings['columns_desktop'];
            $data_attrs['data-columns-tablet']  = $settings['columns_tablet'];
            $data_attrs['data-columns-mobile']  = $settings['columns_mobile'];
            $data_attrs['data-gap']             = $settings['gap'];

            if ($settings['pagination_enabled']) {
                $data_attrs['data-pagination'] = $settings['pagination_style'];
                $data_attrs['data-per-page']   = $settings['videos_per_page'];
            }
        }

        if (in_array($layout, ['slider', 'carousel'])) {
            $data_attrs['data-arrows']           = $settings['slider_arrows'] ? '1' : '0';
            $data_attrs['data-dots']             = $settings['slider_dots'] ? '1' : '0';
            $data_attrs['data-slider-autoplay']  = $settings['slider_autoplay'] ? '1' : '0';
            $data_attrs['data-autoplay-speed']   = $settings['slider_autoplay_speed'];

            if ($layout === 'carousel') {
                $data_attrs['data-loop']   = $settings['carousel_loop'] ? '1' : '0';
                $data_attrs['data-center'] = $settings['carousel_center'] ? '1' : '0';
            }
        }

        // Aspect ratio: cinematic tile style forces 2.35:1, otherwise use setting
        $ratio_map = ['16:9' => '16 / 9', '4:3' => '4 / 3', '1:1' => '1 / 1', '9:16' => '9 / 16', '21:9' => '21 / 9'];
        $thumb_ratio = ($settings['tile_style'] === 'cinematic')
            ? '2.35 / 1'
            : ($ratio_map[$settings['thumb_aspect_ratio']] ?? '16 / 9');

        $style_vars = [
            '--avg-gap: ' . intval($settings['gap']) . 'px',
            '--avg-radius: ' . intval($settings['border_radius']) . 'px',
            '--avg-cols-desktop: ' . intval($settings['columns_desktop']),
            '--avg-cols-tablet: ' . intval($settings['columns_tablet']),
            '--avg-cols-mobile: ' . intval($settings['columns_mobile']),
            '--avg-thumb-ratio: ' . $thumb_ratio,
            '--avg-object-fit: ' . esc_attr($settings['object_fit']),
        ];

        $max_height = intval($settings['max_height']);
        if ($max_height > 0) {
            $style_vars[] = '--avg-max-height: ' . $max_height . 'px';
        }

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
                $paginate = $settings['pagination_enabled'] && in_array($layout, ['grid', 'masonry']);
                $per_page = $paginate ? $settings['videos_per_page'] : $total;

                foreach ($videos as $i => $video):
                    $hidden = $paginate && $i >= $per_page;
                    $item_type = $video['provider'] === 'image' ? 'image' : 'video';
                    $is_image = ($item_type === 'image');
                ?>
                <div class="avg-tile<?php echo $hidden ? ' avg-hidden' : ''; ?>"
                     data-index="<?php echo esc_attr($i); ?>"
                     data-type="<?php echo esc_attr($item_type); ?>"
                     <?php if (!$is_image): ?>
                     data-provider="<?php echo esc_attr($video['provider']); ?>"
                     data-video-id="<?php echo esc_attr($video['id']); ?>"
                     data-url="<?php echo esc_attr($video['raw_url'] ?? ''); ?>"
                     <?php if (!empty($video['duration'])): ?>data-duration="<?php echo esc_attr($video['duration']); ?>"<?php endif; ?>
                     <?php else: ?>
                     data-full-url="<?php echo esc_url($video['full_url'] ?? $video['thumb']); ?>"
                     <?php endif; ?>>
                    <div class="avg-tile-inner">
                        <div class="avg-thumb"<?php echo !empty($video['thumb']) ? ' style="background-image:url(\'' . esc_url($video['thumb']) . '\')"' : ''; ?>>
                            <?php if (!$is_image && $settings['play_button_style'] !== 'none'): ?>
                            <span class="avg-play" aria-hidden="true">
                                <?php echo $this->get_play_button_svg($settings['play_button_style']); ?>
                            </span>
                            <?php endif; ?>
                            <?php if (!$is_image && $settings['show_duration'] && !empty($video['duration'])): ?>
                            <span class="avg-duration"><?php echo esc_html($video['duration']); ?></span>
                            <?php endif; ?>
                            <?php if ($title_position === 'overlay' && !empty($video['label'])): ?>
                            <div class="avg-title-overlay">
                                <span class="avg-title"><?php echo esc_html($video['label']); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php
                        $show_meta_below = false;
                        if ($title_position === 'below' && !empty($video['label'])) {
                            $show_meta_below = true;
                        } elseif ($title_position === 'hidden' && ($settings['show_title'] || $settings['show_channel'])) {
                            $show_meta_below = true;
                        }
                        ?>
                        <?php if ($show_meta_below): ?>
                        <div class="avg-meta">
                            <?php if ($title_position === 'below'): ?>
                            <span class="avg-title"><?php echo esc_html($video['label']); ?></span>
                            <?php else: ?>
                                <?php if ($settings['show_title']): ?>
                                <span class="avg-title"><?php echo esc_html($video['label']); ?></span>
                                <?php endif; ?>
                                <?php if ($settings['show_channel'] && !empty($video['channel'])): ?>
                                <span class="avg-channel"><?php echo esc_html($video['channel']); ?></span>
                                <?php endif; ?>
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

            <?php if ($paginate && $total > $per_page): ?>
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

    /* ══════════════════════════════════════════════════════════
       Frontend Rendering: Dispatch
       ══════════════════════════════════════════════════════════ */

    private function render_dispatch($uid, $videos, $settings) {
        if ($settings['layout'] === 'logo_carousel') {
            return $this->render_logo_carousel($uid, $videos, $settings);
        }
        if ($settings['layout'] === 'gallery') {
            return $this->render_gallery_layout($uid, $videos, $settings);
        }
        return $this->render_output($uid, $videos, $settings);
    }

    /* ══════════════════════════════════════════════════════════
       Frontend Rendering: Gallery Layout (Featured + Strip)
       ══════════════════════════════════════════════════════════ */

    private function render_gallery_layout($uid, $videos, $settings) {
        if (empty($videos)) return '';

        $classes = [
            'anchor-video-gallery',
            'avg-layout-gallery',
            'avg-theme-' . $settings['theme'],
            'avg-play-' . $settings['play_button_style'],
        ];

        $style_vars = [
            '--avg-gap: '    . intval($settings['gap']) . 'px',
            '--avg-radius: ' . intval($settings['border_radius']) . 'px',
        ];

        $data_attrs = [
            'data-layout'   => 'gallery',
            'data-popup'    => $settings['popup_style'],
            'data-autoplay' => $settings['autoplay'] ? '1' : '0',
        ];

        $first      = $videos[0];
        $first_type = $first['provider'] === 'image' ? 'image' : 'video';
        $first_img  = $first_type === 'image';

        ob_start();
        ?>
        <div class="<?php echo esc_attr(implode(' ', $classes)); ?>"
             id="<?php echo esc_attr($uid); ?>"
             <?php foreach ($data_attrs as $key => $val): ?>
                <?php echo esc_attr($key); ?>="<?php echo esc_attr($val); ?>"
             <?php endforeach; ?>
             style="<?php echo esc_attr(implode('; ', $style_vars)); ?>">

            <!-- Featured video -->
            <div class="avg-tile avg-gallery-featured"
                 tabindex="0" role="button"
                 data-index="0"
                 data-type="<?php echo esc_attr($first_type); ?>"
                 <?php if (!$first_img): ?>
                 data-provider="<?php echo esc_attr($first['provider']); ?>"
                 data-video-id="<?php echo esc_attr($first['id']); ?>"
                 data-url="<?php echo esc_attr($first['raw_url'] ?? ''); ?>"
                 <?php else: ?>
                 data-full-url="<?php echo esc_url($first['full_url'] ?? $first['thumb']); ?>"
                 <?php endif; ?>>
                <div class="avg-thumb"<?php echo !empty($first['thumb']) ? ' style="background-image:url(\'' . esc_url($first['thumb']) . '\')"' : ''; ?>>
                    <?php if (!$first_img && $settings['play_button_style'] !== 'none'): ?>
                    <span class="avg-play" aria-hidden="true">
                        <?php echo $this->get_play_button_svg($settings['play_button_style']); ?>
                    </span>
                    <?php endif; ?>
                    <?php if (!$first_img && $settings['show_duration'] && !empty($first['duration'])): ?>
                    <span class="avg-duration"><?php echo esc_html($first['duration']); ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($settings['show_title'] && !empty($first['label'])): ?>
                <div class="avg-gallery-featured-title"><?php echo esc_html($first['label']); ?></div>
                <?php endif; ?>
            </div>

            <!-- Thumbnail strip -->
            <div class="avg-gallery-strip-wrapper">
                <button type="button" class="avg-nav avg-nav-prev" aria-label="<?php esc_attr_e('Previous', 'anchor-schema'); ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15,6 9,12 15,18"></polyline></svg>
                </button>
                <div class="avg-gallery-strip">
                    <?php foreach ($videos as $i => $video):
                        $item_type = $video['provider'] === 'image' ? 'image' : 'video';
                        $is_image  = $item_type === 'image';
                    ?>
                    <div class="avg-gallery-thumb<?php echo $i === 0 ? ' active' : ''; ?>"
                         data-index="<?php echo esc_attr($i); ?>"
                         data-type="<?php echo esc_attr($item_type); ?>"
                         <?php if (!$is_image): ?>
                         data-provider="<?php echo esc_attr($video['provider']); ?>"
                         data-video-id="<?php echo esc_attr($video['id']); ?>"
                         data-url="<?php echo esc_attr($video['raw_url'] ?? ''); ?>"
                         <?php else: ?>
                         data-full-url="<?php echo esc_url($video['full_url'] ?? $video['thumb']); ?>"
                         <?php endif; ?>
                         data-thumb="<?php echo esc_url($video['thumb'] ?? ''); ?>"
                         data-label="<?php echo esc_attr($video['label'] ?? ''); ?>"
                         data-duration="<?php echo esc_attr($video['duration'] ?? ''); ?>"
                         title="<?php echo esc_attr($video['label'] ?? ''); ?>">
                        <div class="avg-gallery-thumb-img"<?php echo !empty($video['thumb']) ? ' style="background-image:url(\'' . esc_url($video['thumb']) . '\')"' : ''; ?>>
                            <?php if (!$is_image && $settings['play_button_style'] !== 'none'): ?>
                            <span class="avg-play" aria-hidden="true">
                                <?php echo $this->get_play_button_svg($settings['play_button_style']); ?>
                            </span>
                            <?php endif; ?>
                            <?php if (!$is_image && $settings['show_duration'] && !empty($video['duration'])): ?>
                            <span class="avg-duration"><?php echo esc_html($video['duration']); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="avg-nav avg-nav-next" aria-label="<?php esc_attr_e('Next', 'anchor-schema'); ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9,6 15,12 9,18"></polyline></svg>
                </button>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ══════════════════════════════════════════════════════════
       Frontend Rendering: Logo Carousel (Marquee)
       ══════════════════════════════════════════════════════════ */

    private function render_logo_carousel($uid, $items, $settings) {
        if (empty($items)) return '';

        $pause_class = !empty($settings['marquee_pause_on_hover']) ? ' avg-marquee-pause' : '';
        $direction = ($settings['marquee_direction'] === 'right') ? 'reverse' : 'normal';

        $style_vars = [
            '--avg-marquee-speed: ' . intval($settings['marquee_speed']) . 's',
            '--avg-marquee-gap: ' . intval($settings['marquee_gap']) . 'px',
            '--avg-marquee-item-width: ' . intval($settings['marquee_item_width']) . 'px',
            '--avg-radius: ' . intval($settings['border_radius']) . 'px',
            '--avg-object-fit: ' . esc_attr($settings['object_fit']),
        ];

        ob_start();
        ?>
        <div class="anchor-video-gallery avg-layout-logo-carousel"
             id="<?php echo esc_attr($uid); ?>"
             data-layout="logo_carousel"
             style="<?php echo esc_attr(implode('; ', $style_vars)); ?>">

            <div class="avg-marquee-row<?php echo esc_attr($pause_class); ?>">
                <div class="avg-marquee" style="animation-direction: <?php echo esc_attr($direction); ?>">
                    <div class="avg-marquee-group">
                        <?php foreach ($items as $item): ?>
                        <div class="avg-marquee-item">
                            <?php if (!empty($item['thumb'])): ?>
                            <img src="<?php echo esc_url($item['thumb']); ?>" alt="<?php echo esc_attr($item['label'] ?? ''); ?>" loading="lazy" />
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="avg-marquee-group" aria-hidden="true">
                        <?php foreach ($items as $item): ?>
                        <div class="avg-marquee-item">
                            <?php if (!empty($item['thumb'])): ?>
                            <img src="<?php echo esc_url($item['thumb']); ?>" alt="" loading="lazy" />
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <?php if (!empty($settings['marquee_reverse_row'])): ?>
            <div class="avg-marquee-row avg-marquee-reverse<?php echo esc_attr($pause_class); ?>">
                <div class="avg-marquee" style="animation-direction: <?php echo esc_attr($direction === 'normal' ? 'reverse' : 'normal'); ?>">
                    <div class="avg-marquee-group">
                        <?php foreach ($items as $item): ?>
                        <div class="avg-marquee-item">
                            <?php if (!empty($item['thumb'])): ?>
                            <img src="<?php echo esc_url($item['thumb']); ?>" alt="<?php echo esc_attr($item['label'] ?? ''); ?>" loading="lazy" />
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="avg-marquee-group" aria-hidden="true">
                        <?php foreach ($items as $item): ?>
                        <div class="avg-marquee-item">
                            <?php if (!empty($item['thumb'])): ?>
                            <img src="<?php echo esc_url($item['thumb']); ?>" alt="" loading="lazy" />
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
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

    /* ══════════════════════════════════════════════════════════
       Video Parsing & Hydration
       ══════════════════════════════════════════════════════════ */

    private function parse_videos($raw) {
        $urls = preg_split('/[\r\n,]+/', $raw);
        $out = [];
        foreach ($urls as $row) {
            $row = trim((string) $row);
            if ($row === '') continue;
            $parts = array_map('trim', explode('|', $row));
            $url = $parts[0] ?? '';
            if ($url === '') continue;
            $video = $this->normalize_video_url($url);
            if (!$video) continue;
            if (isset($parts[1]) && $parts[1] !== '') $video['custom_thumb'] = $parts[1];
            if (isset($parts[2]) && $parts[2] !== '') $video['custom_title'] = $parts[2];
            $out[] = $video;
        }
        return $out;
    }

    private function parse_videos_from_rows($rows) {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $type = $row['type'] ?? 'video';

            if ($type === 'image') {
                $att_id = absint($row['attachment_id'] ?? 0);
                if ($att_id === 0) continue;
                $img_url = wp_get_attachment_image_url($att_id, 'large');
                $full_url = wp_get_attachment_image_url($att_id, 'full');
                if (!$img_url) continue;
                $title = trim((string) ($row['title'] ?? ''));
                if ($title === '') {
                    $title = get_the_title($att_id) ?: basename(get_attached_file($att_id) ?: '');
                }
                $out[] = [
                    'provider'  => 'image',
                    'id'        => (string) $att_id,
                    'thumb'     => $img_url,
                    'full_url'  => $full_url ?: $img_url,
                    'label'     => $title,
                    'raw_url'   => '',
                    'duration'  => '',
                    'channel'   => '',
                ];
                continue;
            }

            // Video type
            $url = trim((string) ($row['url'] ?? ''));
            if ($url === '') continue;
            $video = $this->normalize_video_url($url);
            if (!$video) continue;
            $title = trim((string) ($row['title'] ?? ''));
            if ($title !== '') $video['custom_title'] = $title;
            $thumb = trim((string) ($row['thumb'] ?? ''));
            if ($thumb !== '') $video['custom_thumb'] = $thumb;
            $desc = trim((string) ($row['description'] ?? ''));
            if ($desc !== '') $video['description'] = $desc;
            $out[] = $video;
        }
        return $out;
    }

    private function normalize_video_url($url) {
        if (preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\\?v=|embed/|shorts/|live/))([A-Za-z0-9_-]{6,})~', $url, $matches)) {
            return [
                'provider'       => 'youtube',
                'id'             => $matches[1],
                'thumb'          => '',
                'fallback_thumb' => 'https://img.youtube.com/vi/' . $matches[1] . '/hqdefault.jpg',
                'label'          => 'YouTube Video',
                'raw_url'        => $url,
                'duration'       => '',
                'channel'        => '',
            ];
        }

        if (preg_match('~vimeo\.com/(?:video/)?([0-9]+)~', $url, $matches)) {
            return [
                'provider'       => 'vimeo',
                'id'             => $matches[1],
                'thumb'          => '',
                'fallback_thumb' => '',
                'label'          => 'Vimeo Video',
                'raw_url'        => $url,
                'duration'       => '',
                'channel'        => '',
            ];
        }

        return null;
    }

    private function hydrate_video_metadata($videos, $thumb_size = 'maxres') {
        if (empty($videos)) return [];

        $yt_ids = [];
        $vm_ids = [];
        foreach ($videos as $video) {
            if ($video['provider'] === 'image') continue;
            if ($video['provider'] === 'youtube') $yt_ids[] = $video['id'];
            elseif ($video['provider'] === 'vimeo') $vm_ids[] = $video['id'];
        }

        $yt_details = $this->fetch_youtube_details($yt_ids, $thumb_size);
        $vm_details = $this->fetch_vimeo_details($vm_ids, $thumb_size);

        foreach ($videos as &$video) {
            if ($video['provider'] === 'image') continue;

            $custom_title = isset($video['custom_title']) ? (string) $video['custom_title'] : '';
            $custom_thumb = isset($video['custom_thumb']) ? (string) $video['custom_thumb'] : '';

            if ($custom_thumb !== '') {
                $video['thumb'] = $this->resolve_custom_thumb($custom_thumb);
            }

            $details = [];
            if ($video['provider'] === 'youtube' && isset($yt_details[$video['id']])) {
                $details = $yt_details[$video['id']];
            } elseif ($video['provider'] === 'vimeo' && isset($vm_details[$video['id']])) {
                $details = $vm_details[$video['id']];
            }

            if ($details) {
                if ($video['thumb'] === '') $video['thumb'] = $details['thumb'] ?? '';
                if ($custom_title === '') $video['label'] = $details['title'] ?? $video['label'];
                if (!empty($details['duration'])) $video['duration'] = $details['duration'];
                if (!empty($details['channel'])) $video['channel'] = $details['channel'];
            }

            if ($custom_title !== '') $video['label'] = $custom_title;
            if ($video['thumb'] === '' && !empty($video['fallback_thumb'])) $video['thumb'] = $video['fallback_thumb'];
        }
        unset($video);
        return $videos;
    }

    private function resolve_custom_thumb($value) {
        $value = trim((string) $value);
        if ($value === '') return '';
        if (ctype_digit($value)) {
            $url = wp_get_attachment_image_url((int) $value, 'large');
            return $url ? $url : '';
        }
        if (strpos($value, 'http://') === 0 || strpos($value, 'https://') === 0) return $value;
        return '';
    }

    private function fetch_youtube_details($ids, $thumb_size = 'maxres') {
        $ids = array_values(array_unique(array_filter($ids)));
        if (empty($ids)) return [];
        $api_key = $this->get_google_api_key();

        // YouTube direct-URL filenames per size (used for fallback without API key)
        $yt_filenames = [
            'maxres'   => 'maxresdefault.jpg',
            'standard' => 'sddefault.jpg',
            'high'     => 'hqdefault.jpg',
            'medium'   => 'mqdefault.jpg',
        ];
        $fallback_file = $yt_filenames[$thumb_size] ?? 'maxresdefault.jpg';

        if (!$api_key) {
            $out = [];
            foreach ($ids as $id) {
                $out[$id] = ['thumb' => 'https://img.youtube.com/vi/' . $id . '/' . $fallback_file, 'title' => '', 'duration' => '', 'channel' => ''];
            }
            return $out;
        }

        // Check per-video cache first, collect what's missing
        $out = [];
        $uncached = [];
        foreach ($ids as $id) {
            $cached = get_transient('anchor_vs_yt_' . $thumb_size . '_' . $id);
            if (is_array($cached)) {
                $out[$id] = $cached;
            } else {
                $uncached[] = $id;
            }
        }

        // Fetch uncached IDs in batches of 50 (API limit)
        foreach (array_chunk($uncached, 50) as $chunk) {
            $url = add_query_arg([
                'part' => 'snippet,contentDetails',
                'id'   => implode(',', $chunk),
                'key'  => $api_key,
            ], 'https://www.googleapis.com/youtube/v3/videos');

            $res  = wp_remote_get($url, ['timeout' => 12]);
            $data = !is_wp_error($res) ? json_decode(wp_remote_retrieve_body($res), true) : [];

            $priority = ['maxres', 'standard', 'high', 'medium', 'default'];
            $start    = array_search($thumb_size, $priority, true) ?: 0;

            foreach (($data['items'] ?? []) as $item) {
                $sn  = $item['snippet'] ?? [];
                $cd  = $item['contentDetails'] ?? [];
                $vid = $item['id'] ?? '';
                if (!$vid) continue;

                $thumb = '';
                for ($pi = $start; $pi < count($priority); $pi++) {
                    if (!empty($sn['thumbnails'][$priority[$pi]]['url'])) {
                        $thumb = $sn['thumbnails'][$priority[$pi]]['url'];
                        break;
                    }
                }
                $meta = [
                    'title'    => $sn['title'] ?? '',
                    'thumb'    => $thumb ?: 'https://img.youtube.com/vi/' . $vid . '/' . $fallback_file,
                    'duration' => !empty($cd['duration']) ? $this->format_iso_duration($cd['duration']) : '',
                    'channel'  => $sn['channelTitle'] ?? '',
                ];
                $out[$vid] = $meta;
                set_transient('anchor_vs_yt_' . $thumb_size . '_' . $vid, $meta, 12 * HOUR_IN_SECONDS);
            }

            // Any IDs the API didn't return get the direct-URL fallback
            foreach ($chunk as $vid) {
                if (!isset($out[$vid])) {
                    $meta = ['title' => '', 'thumb' => 'https://img.youtube.com/vi/' . $vid . '/' . $fallback_file, 'duration' => '', 'channel' => ''];
                    $out[$vid] = $meta;
                    set_transient('anchor_vs_yt_' . $thumb_size . '_' . $vid, $meta, 12 * HOUR_IN_SECONDS);
                }
            }
        }
        return $out;
    }

    private function fetch_vimeo_details($ids, $thumb_size = 'maxres') {
        $ids = array_values(array_unique(array_filter($ids)));
        if (empty($ids)) return [];

        $vimeo_widths = [
            'maxres'   => '1280',
            'standard' => '640',
            'high'     => '480',
            'medium'   => '320',
            'default'  => '120',
        ];
        $width = $vimeo_widths[$thumb_size] ?? '1280';

        $out = [];
        foreach ($ids as $id) {
            $cache_key = 'anchor_vs_vm_' . $thumb_size . '_' . md5($id);
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                $out[$id] = $cached;
                continue;
            }

            $oembed = add_query_arg([
                'url'   => 'https://vimeo.com/' . rawurlencode($id),
                'width' => $width,
                'dnt'   => '1',
            ], 'https://vimeo.com/api/oembed.json');

            $res = wp_remote_get($oembed, ['timeout' => 12]);
            if (is_wp_error($res)) continue;
            $data = json_decode(wp_remote_retrieve_body($res), true);
            if (!is_array($data)) continue;

            $thumb_url = $data['thumbnail_url'] ?? '';
            if ($thumb_url) {
                // Vimeo thumbnail URLs end with _WIDTHxHEIGHT — replace with requested width
                $thumb_url = preg_replace('/_\d+x\d+/', '_' . $width, $thumb_url);
            }

            $meta = [
                'title'    => $data['title'] ?? '',
                'thumb'    => $thumb_url,
                'duration' => !empty($data['duration']) ? $this->format_seconds_duration((int) $data['duration']) : '',
                'channel'  => $data['author_name'] ?? '',
            ];
            $out[$id] = $meta;
            set_transient($cache_key, $meta, 24 * HOUR_IN_SECONDS);
        }
        return $out;
    }

    private function format_iso_duration($iso) {
        preg_match('/PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?/', $iso, $m);
        $h = (int) ($m[1] ?? 0);
        $min = (int) ($m[2] ?? 0);
        $s = (int) ($m[3] ?? 0);
        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $min, $s) : sprintf('%d:%02d', $min, $s);
    }

    private function format_seconds_duration($seconds) {
        $h = floor($seconds / 3600);
        $m = floor(($seconds % 3600) / 60);
        $s = $seconds % 60;
        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    }

    private function get_google_api_key() {
        if (class_exists('Anchor_Schema_Admin')) {
            $opts = get_option(Anchor_Schema_Admin::OPTION_KEY, []);
            $key = trim($opts['google_api_key'] ?? '');
            if ($key !== '') return $key;
            $legacy = trim($opts['youtube_api_key'] ?? '');
            if ($legacy !== '') return $legacy;
        }
        return '';
    }
}
