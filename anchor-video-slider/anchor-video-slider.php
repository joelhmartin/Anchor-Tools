<?php
/**
 * Anchor Tools module: Anchor Video Slider.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Anchor_Video_Slider_Module {
    public function __construct() {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
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

    public function render_slider($atts) {
        $atts = shortcode_atts([
            'autoplay' => '1',
            'videos' => '',
        ], $atts);

        $videos_raw = trim((string) $atts['videos']);
        if ($videos_raw === '') {
            return '';
        }

        $videos = $this->parse_videos($videos_raw);
        if (empty($videos)) {
            return '';
        }

        $videos = $this->hydrate_video_metadata($videos);

        $uid = uniqid('anchor-video-slider-');
        $autoplay = ($atts['autoplay'] === '1' || $atts['autoplay'] === 'true') ? '1' : '0';

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
