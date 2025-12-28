<?php
/**
 * Anchor Tools module: Anchor Video Slider.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Anchor_Video_Slider_Module {
    const OPTION_KEY = 'anchor_video_slider_items';

    public function __construct() {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_post_anchor_video_slider_save', [$this, 'handle_save']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_shortcode('anchor_video_slider', [$this, 'render_slider']);
    }

    public function enqueue_assets() {
        $slider_css = ANCHOR_TOOLS_PLUGIN_URL . 'anchor-video-slider/assets/anchor-video-slider.css';
        $slider_js = ANCHOR_TOOLS_PLUGIN_URL . 'anchor-video-slider/assets/anchor-video-slider.js';

        $up_css_path = ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-universal-popups/assets/frontend.css';
        if (file_exists($up_css_path)) {
            wp_enqueue_style('up-frontend', ANCHOR_TOOLS_PLUGIN_URL . 'anchor-universal-popups/assets/frontend.css', [], '1.0.1');
        }

        wp_enqueue_style('anchor-video-slider', $slider_css, [], '1.0.0');
        wp_enqueue_script('anchor-video-slider', $slider_js, [], '1.0.0', true);
    }

    public function register_menu() {
        add_options_page(
            __('Anchor Video Slider', 'anchor-schema'),
            __('Anchor Video Slider', 'anchor-schema'),
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
        wp_enqueue_style(
            'anchor-video-slider-admin',
            ANCHOR_TOOLS_PLUGIN_URL . 'anchor-video-slider/assets/admin.css',
            [],
            '1.0.0'
        );
        wp_enqueue_script(
            'anchor-video-slider-admin',
            ANCHOR_TOOLS_PLUGIN_URL . 'anchor-video-slider/assets/admin.js',
            ['jquery'],
            '1.0.0',
            true
        );
        wp_localize_script('anchor-video-slider-admin', 'ANCHOR_VIDEO_SLIDER', [
            'mediaTitle' => __('Select or upload image', 'anchor-schema'),
            'mediaButton' => __('Use this image', 'anchor-schema'),
        ]);
    }

    public function handle_save() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('anchor_video_slider_save', 'anchor_video_slider_nonce');

        $raw = isset($_POST['sliders']) && is_array($_POST['sliders']) ? $_POST['sliders'] : [];
        $sliders = $this->sanitize_sliders($raw);
        update_option(self::OPTION_KEY, $sliders, false);

        $url = add_query_arg('updated', '1', menu_page_url('anchor-video-slider', false));
        wp_safe_redirect($url);
        exit;
    }

    public function render_admin_page() {
        $sliders = $this->get_sliders();
        if (isset($_GET['updated'])) {
            echo '<div class="notice notice-success"><p>' . esc_html__('Video sliders saved.', 'anchor-schema') . '</p></div>';
        }
        echo '<div class="wrap"><h1>' . esc_html__('Anchor Video Slider', 'anchor-schema') . '</h1>';
        echo '<p>' . esc_html__('Create sliders and use them with: [anchor_video_slider id="your-slider-id"]', 'anchor-schema') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="anchor_video_slider_save" />';
        wp_nonce_field('anchor_video_slider_save', 'anchor_video_slider_nonce');
        echo '<div id="avs-sliders">';

        if (empty($sliders)) {
            $sliders = [
                [
                    'id' => '',
                    'title' => '',
                    'autoplay' => 1,
                    'videos' => [],
                ],
            ];
        }

        foreach ($sliders as $idx => $slider) {
            $this->render_slider_block($idx, $slider);
        }

        echo '</div>';
        echo '<p><button type="button" class="button" id="avs-add-slider">' . esc_html__('Add Slider', 'anchor-schema') . '</button></p>';
        submit_button();
        echo '</form></div>';

        $this->render_templates();
    }

    private function render_slider_block($idx, $slider) {
        $id = $slider['id'] ?? '';
        $title = $slider['title'] ?? '';
        $autoplay = !empty($slider['autoplay']);
        $videos = $slider['videos'] ?? [];
        ?>
        <div class="avs-slider" data-index="<?php echo esc_attr($idx); ?>">
            <div class="avs-slider-head">
                <h2><?php esc_html_e('Slider', 'anchor-schema'); ?></h2>
                <button type="button" class="button avs-remove-slider"><?php esc_html_e('Remove Slider', 'anchor-schema'); ?></button>
            </div>
            <div class="avs-field-row">
                <label><?php esc_html_e('Slider ID (shortcode uses this)', 'anchor-schema'); ?></label>
                <input type="text" name="sliders[<?php echo esc_attr($idx); ?>][id]" value="<?php echo esc_attr($id); ?>" class="regular-text" />
            </div>
            <div class="avs-field-row">
                <label><?php esc_html_e('Slider Title (admin only)', 'anchor-schema'); ?></label>
                <input type="text" name="sliders[<?php echo esc_attr($idx); ?>][title]" value="<?php echo esc_attr($title); ?>" class="regular-text" />
            </div>
            <div class="avs-field-row">
                <label>
                    <input type="checkbox" name="sliders[<?php echo esc_attr($idx); ?>][autoplay]" value="1" <?php checked($autoplay, true); ?> />
                    <?php esc_html_e('Autoplay on popup open', 'anchor-schema'); ?>
                </label>
            </div>
            <table class="widefat avs-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Video URL', 'anchor-schema'); ?></th>
                        <th><?php esc_html_e('Custom Thumbnail (ID or URL)', 'anchor-schema'); ?></th>
                        <th><?php esc_html_e('Custom Title (optional)', 'anchor-schema'); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php
                if (empty($videos)) {
                    $videos = [
                        [ 'url' => '', 'thumb' => '', 'title' => '' ],
                    ];
                }
                foreach ($videos as $v_idx => $video) {
                    $this->render_video_row($idx, $v_idx, $video);
                }
                ?>
                </tbody>
            </table>
            <p><button type="button" class="button avs-add-video"><?php esc_html_e('Add Video', 'anchor-schema'); ?></button></p>
        </div>
        <?php
    }

    private function render_video_row($idx, $v_idx, $video) {
        $url = $video['url'] ?? '';
        $thumb = $video['thumb'] ?? '';
        $title = $video['title'] ?? '';
        ?>
        <tr>
            <td>
                <input type="text" name="sliders[<?php echo esc_attr($idx); ?>][videos][<?php echo esc_attr($v_idx); ?>][url]" value="<?php echo esc_attr($url); ?>" class="regular-text" />
            </td>
            <td>
                <div class="avs-thumb-wrap">
                    <input type="text" name="sliders[<?php echo esc_attr($idx); ?>][videos][<?php echo esc_attr($v_idx); ?>][thumb]" value="<?php echo esc_attr($thumb); ?>" class="regular-text avs-thumb-field" />
                    <button type="button" class="button avs-thumb-pick"><?php esc_html_e('Choose Image', 'anchor-schema'); ?></button>
                </div>
            </td>
            <td>
                <input type="text" name="sliders[<?php echo esc_attr($idx); ?>][videos][<?php echo esc_attr($v_idx); ?>][title]" value="<?php echo esc_attr($title); ?>" class="regular-text" />
            </td>
            <td>
                <button type="button" class="button avs-remove-video"><?php esc_html_e('Remove', 'anchor-schema'); ?></button>
            </td>
        </tr>
        <?php
    }

    private function render_templates() {
        ?>
        <script type="text/template" id="avs-slider-template">
            <?php $this->render_slider_block('__INDEX__', ['id' => '', 'title' => '', 'autoplay' => 1, 'videos' => []]); ?>
        </script>
        <script type="text/template" id="avs-video-template">
            <tr>
                <td>
                    <input type="text" name="sliders[__INDEX__][videos][__VIDX__][url]" value="" class="regular-text" />
                </td>
                <td>
                    <div class="avs-thumb-wrap">
                        <input type="text" name="sliders[__INDEX__][videos][__VIDX__][thumb]" value="" class="regular-text avs-thumb-field" />
                        <button type="button" class="button avs-thumb-pick"><?php esc_html_e('Choose Image', 'anchor-schema'); ?></button>
                    </div>
                </td>
                <td>
                    <input type="text" name="sliders[__INDEX__][videos][__VIDX__][title]" value="" class="regular-text" />
                </td>
                <td>
                    <button type="button" class="button avs-remove-video"><?php esc_html_e('Remove', 'anchor-schema'); ?></button>
                </td>
            </tr>
        </script>
        <?php
    }

    private function sanitize_sliders($raw) {
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
                    ];
                }
            }
            $out[$id] = [
                'id' => $id,
                'title' => sanitize_text_field($item['title'] ?? ''),
                'autoplay' => !empty($item['autoplay']),
                'videos' => $videos,
            ];
        }
        return $out;
    }

    private function get_sliders() {
        $items = get_option(self::OPTION_KEY, []);
        if (!is_array($items)) {
            return [];
        }
        return array_values($items);
    }

    private function find_slider($id) {
        $items = get_option(self::OPTION_KEY, []);
        if (!is_array($items)) {
            return null;
        }
        $id = sanitize_title($id);
        return $items[$id] ?? null;
    }

    public function render_slider($atts) {
        $atts = shortcode_atts([
            'autoplay' => '',
            'videos' => '',
            'id' => '',
        ], $atts);

        $slider_id = trim((string) $atts['id']);
        $videos = [];
        $autoplay = '1';

        if ($slider_id !== '') {
            $slider = $this->find_slider($slider_id);
            if (!$slider) {
                return '';
            }
            $videos = $this->parse_videos_from_rows($slider['videos'] ?? []);
            $autoplay = !empty($slider['autoplay']) ? '1' : '0';
            if ($atts['autoplay'] !== '') {
                $autoplay = ($atts['autoplay'] === '1' || $atts['autoplay'] === 'true') ? '1' : '0';
            }
        } else {
            $videos_raw = trim((string) $atts['videos']);
            if ($videos_raw === '') {
                return '';
            }
            $videos = $this->parse_videos($videos_raw);
            $autoplay = ($atts['autoplay'] === '' || $atts['autoplay'] === '1' || $atts['autoplay'] === 'true') ? '1' : '0';
        }
        if (empty($videos)) {
            return '';
        }

        $videos = $this->hydrate_video_metadata($videos);

        $uid = uniqid('anchor-video-slider-');

        ob_start();
        ?>
        <div class="anchor-video-slider" id="<?php echo esc_attr($uid); ?>" data-autoplay="<?php echo esc_attr($autoplay); ?>">
            <div class="anchor-video-track">
                <?php foreach ($videos as $video): ?>
                    <button type="button"
                        class="anchor-video-tile"
                        data-provider="<?php echo esc_attr($video['provider']); ?>"
                        data-video-id="<?php echo esc_attr($video['id']); ?>"
                        aria-label="<?php echo esc_attr($video['label']); ?>">
                        <span class="anchor-video-thumb"<?php echo $video['thumb'] ? ' style="background-image:url(' . esc_url($video['thumb']) . ')"' : ''; ?>></span>
                        <span class="anchor-video-play" aria-hidden="true">▶</span>
                        <span class="anchor-video-label"><?php echo esc_html($video['label']); ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
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
            if ($thumb !== '') {
                $video['custom_thumb'] = $thumb;
            }
            if ($title !== '') {
                $video['custom_title'] = $title;
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
            }

            if ($video['provider'] === 'vimeo' && isset($vm_details[$video['id']])) {
                $meta = $vm_details[$video['id']];
                if ($video['thumb'] === '') {
                    $video['thumb'] = $meta['thumb'] ?? $video['thumb'];
                }
                if ($custom_title === '') {
                    $video['label'] = $meta['title'] ?? $video['label'];
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
                'part' => 'snippet',
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
                $batch[$vid] = [
                    'title' => $sn['title'] ?? '',
                    'thumb' => $thumb ?: 'https://i.ytimg.com/vi/' . $vid . '/hqdefault.jpg',
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
            $meta = [
                'title' => $data['title'] ?? '',
                'thumb' => $data['thumbnail_url'] ?? '',
            ];
            $out[$id] = $meta;
            set_transient($cache_key, $meta, 24 * HOUR_IN_SECONDS);
        }
        return $out;
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
