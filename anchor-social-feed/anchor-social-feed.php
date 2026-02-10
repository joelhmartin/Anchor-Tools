<?php
/**
 * Anchor Tools module: Anchor Social Feed.
 * Displays social feeds for YouTube, Facebook, X (Twitter), and Spotify via shortcodes.
 */

if (!defined('ABSPATH')) exit;

class Anchor_Social_Feed_Module {
    const OPT_KEY = 'anchor_social_feed_options';
    const LEGACY_KEYS = [ 'ssfs_options_v1' ];
    const PAGE_SLUG = 'anchor-social-feed';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_shortcode('social_feed', [$this, 'shortcode_handler']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function get_options() {
        $defaults = [
            'youtube_channel_id'         => '',    // UC id, @handle, or channel URL
            'facebook_page_url'          => '',
            'instagram_username'         => '',
            'twitter_username'           => '',
            'spotify_artist_id'          => '',
            'layout'                     => 'grid',
            // Facebook Graph API
            'facebook_app_id'            => '',
            'facebook_app_secret'        => '',
            'facebook_page_access_token' => '',
            'facebook_page_id'           => '',
            // Instagram Graph API
            'instagram_access_token'     => '',
            'instagram_user_id'          => '',
            // TikTok Display API
            'tiktok_client_key'          => '',
            'tiktok_client_secret'       => '',
            'tiktok_access_token'        => '',
            'tiktok_username'            => '',
        ];
        $opts = get_option(self::OPT_KEY, []);
        if ( empty($opts) ) {
            foreach (self::LEGACY_KEYS as $legacy_key) {
                $legacy = get_option($legacy_key, []);
                if ( ! empty($legacy) ) {
                    $opts = $legacy;
                    break;
                }
            }
        }
        return wp_parse_args($opts, $defaults);
    }

    private function get_google_api_key() {
        $global = get_option( Anchor_Schema_Admin::OPTION_KEY, [] );
        return trim( $global['google_api_key'] ?? '' );
    }

    public function enqueue_assets() {
        $css = '
        .ssfs-wrap {
    display: grid;
    gap: 20px;
}

.ssfs-wrap.grid {
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
}

.ssfs-embed {
    position: relative;
    overflow: hidden;
    background: linear-gradient(135deg, #153b37 0%, #3c8a81 100%);
    border-radius: 16px;
    padding: 24px;
}

.ssfs-note {
    font-size: 12px;
    color: #9ca3af;
}

.ssfs-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
}

.ssfs-card {
    overflow: hidden;
}

.ssfs-item {
    cursor: pointer;
    border-radius: 12px;
    overflow: hidden;
    border: 2px solid rgba(255, 255, 255, 0.1);
    background: rgba(255, 255, 255, 0.95);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 4px 6px rgba(21, 59, 55, 0.1);
}

.ssfs-item:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 24px rgba(21, 59, 55, 0.2);
    border-color: #3c8a81;
}

.ssfs-thumb {
    aspect-ratio: 16/9;
    width: 100%;
    object-fit: cover;
    display: block;
    transition: transform 0.3s ease;
}

.ssfs-item:hover .ssfs-thumb {
    transform: scale(1.05);
}

.ssfs-meta {
    padding: 14px;
    background: #fff;
}

.ssfs-title {
    font-size: 14px;
    margin: 0 0 6px;
    color: #153b37;
    font-weight: 600;
    line-height: 1.4;
}

.ssfs-date {
    font-size: 12px;
    color: #3c8a81;
    font-weight: 500;
}

.ssfs-modal {
    position: fixed;
    inset: 0;
    background: rgba(21, 59, 55, 0.95);
    backdrop-filter: blur(8px);
    display: none;
    align-items: center;
    justify-content: center;
    padding: 24px;
    z-index: 999999;
}

.ssfs-modal.is-open {
    display: flex;
}

.ssfs-modal-inner {
    width: min(100%, 980px);
    aspect-ratio: 16/9;
    position: relative;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 24px 48px rgba(0, 0, 0, 0.3);
}

.ssfs-close {
    position: absolute;
    top: -40px;
    right: 0;
    background: #fff;
    border: none;
    border-radius: 999px;
    width: 36px;
    height: 36px;
    cursor: pointer;
    font-weight: bold;
    transition: all 0.2s ease;
    color: #153b37;
}

.ssfs-close:hover {
    background: #3c8a81;
    color: #fff;
    transform: rotate(90deg);
}

.ssfs-no-scroll {
    overflow: hidden;
}

.ssfs-carousel {
    display: flex;
    gap: 16px;
    overflow-x: auto;
    overflow-y: hidden;
    scroll-snap-type: x mandatory;
    padding-bottom: 12px;
    scrollbar-width: thin;
    scrollbar-color: #3c8a81 rgba(255, 255, 255, 0.2);
}

.ssfs-carousel::-webkit-scrollbar {
    height: 8px;
}

.ssfs-carousel::-webkit-scrollbar-track {
    background: rgba(255, 255, 255, 0.2);
    border-radius: 4px;
}

.ssfs-carousel::-webkit-scrollbar-thumb {
    background: #3c8a81;
    border-radius: 4px;
}

.ssfs-carousel::-webkit-scrollbar-thumb:hover {
    background: #153b37;
}

.ssfs-carousel .ssfs-item {
    min-width: 320px;
    scroll-snap-align: start;
}

.ssfs-nav {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    margin-bottom: 16px;
}

.ssfs-btn {
    padding: 10px 20px;
    border: 2px solid rgba(255, 255, 255, 0.3);
    background: rgba(255, 255, 255, 0.1);
    backdrop-filter: blur(10px);
    border-radius: 8px;
    cursor: pointer;
    color: #fff;
    font-weight: 600;
    font-size: 14px;
    transition: all 0.2s ease;
}

.ssfs-btn:hover {
    background: #3c8a81;
    border-color: #3c8a81;
    transform: translateX(0);
}

.ssfs-btn:active {
    transform: scale(0.95);
}

a.ssfs-item {
    text-decoration: none;
    color: inherit;
    display: block;
}

.ssfs-item--video {
    cursor: pointer;
}

.ssfs-thumb-wrap {
    position: relative;
    overflow: hidden;
}

.ssfs-play-badge {
    position: absolute;
    bottom: 10px;
    right: 10px;
    width: 32px;
    height: 32px;
    background: rgba(0, 0, 0, 0.7);
    color: #fff;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    line-height: 1;
    pointer-events: none;
}

.ssfs-carousel-badge {
    position: absolute;
    top: 10px;
    right: 10px;
    width: 28px;
    height: 28px;
    background: rgba(0, 0, 0, 0.6);
    color: #fff;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    line-height: 1;
    pointer-events: none;
}

.ssfs-thumb--portrait {
    aspect-ratio: 9/16;
}

.ssfs-item--facebook .ssfs-meta:first-child {
    min-height: 80px;
}

.ssfs-video-modal-inner {
    width: min(100%, 480px);
    position: relative;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 24px 48px rgba(0, 0, 0, 0.3);
}

.ssfs-video-modal-inner video {
    width: 100%;
    display: block;
    max-height: 85vh;
}
        ';
        wp_register_style('ssfs-inline', false);
        wp_enqueue_style('ssfs-inline');
        wp_add_inline_style('ssfs-inline', $css);

        $js = "
        (function(){
            function openModal(videoId){
                var modal = document.querySelector('#ssfs-modal');
                if(!modal){
                    modal = document.createElement('div');
                    modal.id = 'ssfs-modal';
                    modal.className = 'ssfs-modal';
                    modal.innerHTML = '<div class=\"ssfs-modal-inner\"><button id=\"ssfs-close\" class=\"ssfs-close\" aria-label=\"Close\">×</button><iframe width=\"100%\" height=\"100%\" src=\"\" frameborder=\"0\" allow=\"autoplay; encrypted-media\" allowfullscreen></iframe></div>';
                    document.body.appendChild(modal);
                }
                var iframe = modal.querySelector('iframe');
                iframe.src = 'https://www.youtube.com/embed/' + encodeURIComponent(videoId) + '?autoplay=1&rel=0';
                // Show iframe inner, hide video inner if present
                var iframeInner = modal.querySelector('.ssfs-modal-inner');
                if(iframeInner) iframeInner.style.display = '';
                var vidInner = modal.querySelector('.ssfs-video-modal-inner');
                if(vidInner) vidInner.style.display = 'none';
                modal.classList.add('is-open');
                document.documentElement.classList.add('ssfs-no-scroll');
                document.body.classList.add('ssfs-no-scroll');
            }
            function closeModal(){
                var modal = document.querySelector('#ssfs-modal');
                if(!modal) return;
                var iframe = modal.querySelector('iframe');
                if(iframe) iframe.src = '';
                var vid = modal.querySelector('video');
                if(vid){ vid.pause(); vid.removeAttribute('src'); vid.load(); }
                modal.classList.remove('is-open');
                document.documentElement.classList.remove('ssfs-no-scroll');
                document.body.classList.remove('ssfs-no-scroll');
            }
            function openVideoModal(videoUrl){
                var modal = document.querySelector('#ssfs-modal');
                if(!modal){
                    modal = document.createElement('div');
                    modal.id = 'ssfs-modal';
                    modal.className = 'ssfs-modal';
                    modal.innerHTML = '<div class=\"ssfs-video-modal-inner\"><button id=\"ssfs-close\" class=\"ssfs-close\" aria-label=\"Close\">\u00d7</button><video controls playsinline></video></div>';
                    document.body.appendChild(modal);
                }
                var inner = modal.querySelector('.ssfs-video-modal-inner');
                if(!inner){
                    inner = document.createElement('div');
                    inner.className = 'ssfs-video-modal-inner';
                    inner.innerHTML = '<button id=\"ssfs-close\" class=\"ssfs-close\" aria-label=\"Close\">\u00d7</button><video controls playsinline></video>';
                    // Remove existing inner (iframe-based) and replace
                    var old = modal.querySelector('.ssfs-modal-inner');
                    if(old) modal.removeChild(old);
                    modal.appendChild(inner);
                }
                var vid = inner.querySelector('video');
                vid.src = videoUrl;
                // Hide iframe inner if present
                var iframeInner = modal.querySelector('.ssfs-modal-inner');
                if(iframeInner) iframeInner.style.display = 'none';
                inner.style.display = '';
                modal.classList.add('is-open');
                document.documentElement.classList.add('ssfs-no-scroll');
                document.body.classList.add('ssfs-no-scroll');
                vid.play();
            }
            document.addEventListener('click', function(e){
                var t = e.target.closest('[data-ssfs-video]');
                if(t){ e.preventDefault(); openModal(t.getAttribute('data-ssfs-video')); }
                var igv = e.target.closest('[data-ssfs-ig-video]');
                if(igv){ e.preventDefault(); openVideoModal(igv.getAttribute('data-ssfs-ig-video')); }
                if(e.target.matches('#ssfs-close') || e.target.matches('#ssfs-modal')){ closeModal(); }
                var btn = e.target.closest('[data-ssfs-scroll]');
                if(btn){
                    var dir = btn.getAttribute('data-ssfs-scroll');
                    var track = btn.closest('.ssfs-card').querySelector('.ssfs-carousel');
                    if(track){
                        var delta = dir === 'next' ? track.clientWidth : -track.clientWidth;
                        track.scrollBy({left: delta, behavior: 'smooth'});
                    }
                }
            });
            document.addEventListener('keydown', function(e){ if(e.key==='Escape'){ var m=document.querySelector('#ssfs-modal'); if(m){ m.click(); } } });
        })();
        ";
        wp_register_script('ssfs-inline', false);
        wp_enqueue_script('ssfs-inline');
        wp_add_inline_script('ssfs-inline', $js);
    }

    public function add_settings_page() {
        $parent     = apply_filters('anchor_social_feed_parent_menu_slug', 'options-general.php');
        $menu_title = apply_filters('anchor_social_feed_menu_title', __('Anchor Social Feed', 'anchor-tools'));
        $callback   = [$this, 'render_settings_page'];

        if ('options-general.php' === $parent) {
            add_options_page(
                __('Anchor Social Feed', 'anchor-tools'),
                $menu_title,
                'manage_options',
                self::PAGE_SLUG,
                $callback
            );
        } else {
            add_submenu_page(
                $parent,
                __('Anchor Social Feed', 'anchor-tools'),
                $menu_title,
                'manage_options',
                self::PAGE_SLUG,
                $callback
            );
        }
    }

    public function register_settings() {
        register_setting('anchor_social_feed_group', self::OPT_KEY, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_options'],
            'default' => [],
        ]);

        add_settings_section('ssfs_main', 'Platform Settings', function () {
            echo '<p>Enter the required IDs or URLs, then save. Use the provided shortcodes to display feeds.</p>';
            echo '<p class="ssfs-note">YouTube API key is managed in Anchor Tools settings.</p>';
        }, self::PAGE_SLUG);

        $fields = [
            'youtube_channel_id' => 'YouTube Channel, UC id, @handle, or channel URL',
            'facebook_page_url'  => 'Facebook Page URL',
            'instagram_username' => 'Instagram Username',
            'twitter_username'   => 'X, Twitter Username',
            'spotify_artist_id'  => 'Spotify Artist ID',
            'layout'             => 'Default Layout, grid or stack',
        ];

        foreach ($fields as $key => $label) {
            add_settings_field(
                $key,
                esc_html($label),
                [$this, 'render_field'],
                self::PAGE_SLUG,
                'ssfs_main',
                ['key' => $key]
            );
        }

        // Facebook Graph API section
        add_settings_section('ssfs_facebook', 'Facebook Graph API', function () {
            echo '<p>Provide a long-lived Page Access Token and Page ID to pull Facebook posts via the Graph API (v22.0).</p>';
            echo '<p class="ssfs-note">Generate tokens at <a href="https://developers.facebook.com/tools/explorer/" target="_blank" rel="noopener">Graph API Explorer</a>. Required permissions: <code>pages_read_engagement</code>.</p>';
        }, self::PAGE_SLUG);

        $fb_fields = [
            'facebook_app_id'            => ['label' => 'Facebook App ID',            'type' => 'password'],
            'facebook_app_secret'        => ['label' => 'Facebook App Secret',        'type' => 'password'],
            'facebook_page_access_token' => ['label' => 'Page Access Token',          'type' => 'password'],
            'facebook_page_id'           => ['label' => 'Facebook Page ID (numeric)', 'type' => 'text'],
        ];
        foreach ($fb_fields as $key => $meta) {
            add_settings_field($key, esc_html($meta['label']), [$this, 'render_field'], self::PAGE_SLUG, 'ssfs_facebook', ['key' => $key, 'type' => $meta['type']]);
        }

        // Instagram Graph API section
        add_settings_section('ssfs_instagram', 'Instagram Graph API', function () {
            echo '<p>Uses the same Facebook App. Requires a Business or Creator Instagram account linked to a Facebook Page.</p>';
            echo '<p class="ssfs-note">Required permission: <code>instagram_basic</code>. The User ID is the numeric IG Business Account ID.</p>';
        }, self::PAGE_SLUG);

        $ig_fields = [
            'instagram_access_token' => ['label' => 'Instagram Access Token', 'type' => 'password'],
            'instagram_user_id'      => ['label' => 'Instagram User ID (numeric)', 'type' => 'text'],
        ];
        foreach ($ig_fields as $key => $meta) {
            add_settings_field($key, esc_html($meta['label']), [$this, 'render_field'], self::PAGE_SLUG, 'ssfs_instagram', ['key' => $key, 'type' => $meta['type']]);
        }

        // TikTok Display API section
        add_settings_section('ssfs_tiktok', 'TikTok Display API', function () {
            echo '<p>Provide TikTok app credentials and a user access token to display TikTok videos.</p>';
            echo '<p class="ssfs-note">Register your app at <a href="https://developers.tiktok.com/" target="_blank" rel="noopener">developers.tiktok.com</a>.</p>';
        }, self::PAGE_SLUG);

        $tt_fields = [
            'tiktok_client_key'    => ['label' => 'TikTok Client Key',    'type' => 'password'],
            'tiktok_client_secret' => ['label' => 'TikTok Client Secret', 'type' => 'password'],
            'tiktok_access_token'  => ['label' => 'TikTok Access Token',  'type' => 'password'],
            'tiktok_username'      => ['label' => 'TikTok Username',      'type' => 'text'],
        ];
        foreach ($tt_fields as $key => $meta) {
            add_settings_field($key, esc_html($meta['label']), [$this, 'render_field'], self::PAGE_SLUG, 'ssfs_tiktok', ['key' => $key, 'type' => $meta['type']]);
        }
    }

    public function sanitize_options($input) {
        $out = $this->get_options();

        // Original platform fields
        foreach ([
            'youtube_channel_id',
            'facebook_page_url',
            'instagram_username',
            'twitter_username',
            'spotify_artist_id',
            'layout',
        ] as $k) {
            $val = $input[$k] ?? '';
            switch ($k) {
                case 'facebook_page_url':
                    $out[$k] = esc_url_raw($val);
                    break;
                case 'layout':
                    $val = strtolower(sanitize_text_field($val));
                    $out[$k] = in_array($val, ['grid', 'stack'], true) ? $val : 'grid';
                    break;
                default:
                    $out[$k] = preg_replace('/[^A-Za-z0-9_\-.@:\/]/', '', sanitize_text_field($val));
            }
        }

        // Token/key/secret fields — sanitize_text_field only
        foreach ([
            'facebook_app_id',
            'facebook_app_secret',
            'facebook_page_access_token',
            'instagram_access_token',
            'tiktok_client_key',
            'tiktok_client_secret',
            'tiktok_access_token',
        ] as $k) {
            $out[$k] = sanitize_text_field($input[$k] ?? '');
        }

        // Numeric ID fields — strip non-numeric
        foreach (['facebook_page_id', 'instagram_user_id'] as $k) {
            $out[$k] = preg_replace('/[^0-9]/', '', sanitize_text_field($input[$k] ?? ''));
        }

        // TikTok username — alphanumeric, underscore, period only
        $out['tiktok_username'] = preg_replace('/[^A-Za-z0-9_.]/', '', sanitize_text_field($input['tiktok_username'] ?? ''));

        return $out;
    }

    public function render_field($args) {
        $key  = $args['key'];
        $type = $args['type'] ?? 'text';
        $opts = $this->get_options();
        $val  = $opts[$key] ?? '';

        if ($key === 'layout') {
            echo '<select name="' . esc_attr(self::OPT_KEY . '[' . $key . ']') . '">';
            foreach (['grid' => 'Grid', 'stack' => 'Stack'] as $c_key => $label) {
                printf('<option value="%1$s" %2$s>%3$s</option>', esc_attr($c_key), selected($val, $c_key, false), esc_html($label));
            }
            echo '</select>';
            echo '<p class="description">Default layout when not overridden by the shortcode. Carousel is available per shortcode.</p>';
            return;
        }

        printf(
            '<input type="%3$s" class="regular-text" name="%1$s" value="%2$s" autocomplete="off" />',
            esc_attr(self::OPT_KEY . '[' . $key . ']'),
            esc_attr($val),
            esc_attr($type)
        );

        $descriptions = [
            'youtube_channel_id'         => 'Accepts UC id, @handle, or channel URL.',
            'facebook_page_url'          => 'Legacy embed fallback. Example: https://www.facebook.com/YourPage',
            'spotify_artist_id'          => 'Example: 66CXWjxzNUsdJxJ2JdwvnR',
            'facebook_app_id'            => 'From your Meta App dashboard.',
            'facebook_app_secret'        => 'From your Meta App dashboard.',
            'facebook_page_access_token' => 'Long-lived Page Access Token with pages_read_engagement permission.',
            'facebook_page_id'           => 'Numeric Page ID (found in Page About section).',
            'instagram_access_token'     => 'User Access Token with instagram_basic scope.',
            'instagram_user_id'          => 'Numeric IG Business/Creator Account ID.',
            'tiktok_client_key'          => 'From your TikTok developer app.',
            'tiktok_client_secret'       => 'From your TikTok developer app.',
            'tiktok_access_token'        => 'User Access Token with video.list scope.',
            'tiktok_username'            => 'Display only, shown in feed header.',
        ];
        if (isset($descriptions[$key])) {
            echo '<p class="description">' . esc_html($descriptions[$key]) . '</p>';
        }
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) return;
        echo '<div class="wrap"><h1>' . esc_html__('Anchor Social Feed', 'anchor-tools') . '</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields('anchor_social_feed_group');
        do_settings_sections(self::PAGE_SLUG);
        submit_button(__('Save Changes', 'anchor-tools'));
        echo '</form>';

        echo '<hr />';
        echo '<h2>Shortcodes</h2>';
        echo '<p>Use <code>[social_feed]</code> to render all configured platforms.</p>';
        echo '<p>Limit to specific platforms with <code>[social_feed platforms="youtube,facebook,twitter,spotify"]</code>.</p>';
        echo '<p>Override layout with <code>[social_feed layout="grid|stack|carousel"]</code>.</p>';
        echo '<p>YouTube options, <code>youtube_api</code> auto default or on or off, <code>youtube_limit</code> empty means no cap, <code>youtube_type</code> videos default or shorts or all, <code>exclude_hashtags</code> defaults to <code>short,shorts,testimonial</code>, <code>include_hashtags</code> optional, <code>min_seconds</code>, <code>max_seconds</code>, <code>since</code>, <code>until</code>, <code>max_age_days</code>, and <code>youtube_fetch_pages</code> default 10.</p>';
        echo '<p>Examples, <code>[social_feed platform="youtube"]</code>, <code>[social_feed platform="youtube" exclude_hashtags=""]</code>, <code>[social_feed platform="youtube" layout="carousel" min_seconds="400"]</code>.</p>';
        echo '<p>Facebook options: <code>[social_feed platform="facebook" facebook_limit="5"]</code>.</p>';
        echo '<p>Instagram options: <code>[social_feed platform="instagram" instagram_limit="9"]</code>.</p>';
        echo '<p>TikTok options: <code>[social_feed platform="tiktok" tiktok_limit="6"]</code>.</p>';
        echo '</div>';
    }

    public function shortcode_handler($atts = [], $content = null) {
        $opts = $this->get_options();
        $atts = shortcode_atts([
            'platform'           => '',
            'platforms'          => '',
            'layout'             => $opts['layout'],
            // YouTube controls
            'youtube_api'        => 'auto',
            'youtube_limit'      => '',       // empty means no cap
            'youtube_type'       => 'videos',
            'exclude_hashtags'   => 'short,shorts,testimonial',
            'include_hashtags'   => '',
            'min_seconds'        => '',
            'max_seconds'        => '',
            'since'              => '',
            'until'              => '',
            'max_age_days'       => '',
            'youtube_fetch_pages'=> 10,
            // Facebook / Instagram / TikTok limits
            'facebook_limit'     => 10,
            'instagram_limit'    => 12,
            'tiktok_limit'       => 10,
        ], $atts, 'social_feed');

        $layout = in_array($atts['layout'], ['grid','stack','carousel'], true) ? $atts['layout'] : 'grid';

        $targets = [];
        if (!empty($atts['platform'])) {
            $targets = [strtolower($atts['platform'])];
        } elseif (!empty($atts['platforms'])) {
            $targets = array_filter(array_map('trim', explode(',', strtolower($atts['platforms']))));
        } else {
            $targets = ['youtube', 'facebook', 'instagram', 'tiktok', 'twitter', 'spotify'];
        }

        $html_parts = [];
        foreach ($targets as $p) {
            switch ($p) {
                case 'youtube':
                    $embed = $this->render_youtube_api_feed($opts, $atts);
                    if ($embed) $html_parts[] = $this->card('YouTube', $embed);
                    break;
                case 'facebook':
                    $embed = $this->render_facebook_feed($opts, $atts);
                    if ($embed) $html_parts[] = $this->card('Facebook', $embed);
                    break;
                case 'instagram':
                    $embed = $this->render_instagram_feed($opts, $atts);
                    if ($embed) $html_parts[] = $this->card('Instagram', $embed);
                    break;
                case 'tiktok':
                    $embed = $this->render_tiktok_feed($opts, $atts);
                    if ($embed) $html_parts[] = $this->card('TikTok', $embed);
                    break;
                case 'twitter':
                case 'x':
                    $embed = $this->embed_twitter($opts['twitter_username']);
                    if ($embed) $html_parts[] = $this->card('X, Twitter', $embed);
                    break;
                case 'spotify':
                    $embed = $this->embed_spotify($opts['spotify_artist_id']);
                    if ($embed) $html_parts[] = $this->card('Spotify', $embed);
                    break;
            }
        }

        if (empty($html_parts)) {
            return '<div class="ssfs-wrap"><p>No platforms configured yet.</p></div>';
        }

        // ================================================================= //
        // == CORRECTED CODE BLOCK STARTS HERE == //
        // This logic correctly applies the 'grid' class only when the
        // layout is 'grid', preventing it from breaking the carousel.
        // ================================================================= //
        $wrap_class = 'ssfs-wrap';
        if ($layout === 'grid') {
            $wrap_class .= ' grid';
        }

        $out = '<div class="' . esc_attr($wrap_class) . '">';
        $out .= implode("\n", $html_parts);
        $out .= '</div>';
        // ================================================================= //
        // == CORRECTED CODE BLOCK ENDS HERE == //
        // ================================================================= //

        if (strpos($out, 'twitter-timeline') !== false) {
            add_action('wp_footer', function () {
                echo '<script async src="https://platform.twitter.com/widgets.js" charset="utf-8"></script>';
            });
        }
        if (strpos($out, 'fb-page') !== false) {
            add_action('wp_footer', function () {
                echo '<div id="fb-root"></div><script async defer crossorigin="anonymous" src="https://connect.facebook.net/en_US/sdk.js#xfbml=1&version=v19.0"></script>';
            });
        }

        return $out;
    }

    private function card($title, $embed_html) {
        return '<div class="ssfs-card"><div class="ssfs-embed">' . $embed_html . '</div></div>';
    }

/* ===================== YouTube, API only ===================== */

    private function render_youtube_api_feed($opts, $atts) {
    $api_key    = trim($opts['youtube_api_key'] ?? '') ?: $this->get_google_api_key();
    $channel_in = trim($opts['youtube_channel_id'] ?? '');
    $mode       = strtolower($atts['youtube_api'] ?? 'auto');
    $use_api    = ($mode === 'on') || ($mode === 'auto' && $api_key && $channel_in);

    if (!$use_api) return '<p class="ssfs-note">YouTube API is not enabled. Add a Google API key in Anchor Tools settings or pass youtube_api="on".</p>';
    if (!$api_key || !$channel_in) return '<p class="ssfs-note">Missing YouTube API key or channel value.</p>';

    // Resolve UC id from handle or URL
    $resolved = $this->ytapi_resolve_channel_id($channel_in, $api_key);
    if (is_wp_error($resolved)) return '<p class="ssfs-note">YouTube API error, ' . esc_html($resolved->get_error_message()) . '</p>';
    $channel_id = $resolved;

    // Controls
    $type    = in_array(strtolower($atts['youtube_type'] ?? 'videos'), ['videos','shorts','all'], true) ? strtolower($atts['youtube_type']) : 'videos';
    $limit   = (string)($atts['youtube_limit'] ?? '') === '' ? 0 : max(1, intval($atts['youtube_limit']));
    $min_sec = is_numeric($atts['min_seconds'] ?? null) ? max(0, intval($atts['min_seconds'])) : null;
    $max_sec = is_numeric($atts['max_seconds'] ?? null) ? max(0, intval($atts['max_seconds'])) : null;

    $ex_tags = array_filter(array_map('trim', explode(',', strtolower($atts['exclude_hashtags'] ?? 'short,shorts,testimonial'))));
    $in_tags = array_filter(array_map('trim', explode(',', strtolower($atts['include_hashtags'] ?? ''))));

    $since = preg_match('/^\d{4}-\d{2}-\d{2}$/', $atts['since'] ?? '') ? $atts['since'] : '';
    $until = preg_match('/^\d{4}-\d{2}-\d{2}$/', $atts['until'] ?? '') ? $atts['until'] : '';
    $max_age_days = is_numeric($atts['max_age_days'] ?? null) ? max(0, intval($atts['max_age_days'])) : 0;

    $today    = current_time('timestamp');
    $since_ts = $since ? strtotime($since . ' 00:00:00', $today) : 0;
    $until_ts = $until ? strtotime($until . ' 23:59:59', $today) : 0;

    $pages = max(1, min(20, intval($atts['youtube_fetch_pages'] ?? 10)));

    // Fetch uploads, then details
    $items = $this->ytapi_get_uploads($channel_id, $api_key, $pages);
    if (is_wp_error($items)) return '<p class="ssfs-note">' . esc_html($items->get_error_message()) . '</p>';
    if (!$items) return '<p>No videos found.</p>';

    $ids = array_map(function($it){ return $it['videoId']; }, $items);
    $details = $this->ytapi_get_videos_details($ids, $api_key);
    $byId = [];
    foreach ($details as $d) $byId[$d['id']] = $d;

    // Filter and build list
    $kept = [];
    foreach ($items as $it) {
        $vid  = $it['videoId'];
        $date = $it['publishedAt'];
        if (!isset($byId[$vid])) continue;

        $d = $byId[$vid];
        $title = $d['title'];
        $desc  = $d['description'];
        $tags  = $d['tags'];
        $sec   = $d['seconds'];
        $thumb = $d['thumb'];
        $pub_ts = $date ? strtotime($date) : 0;

        // Date filters
        if ($since_ts && $pub_ts && $pub_ts < $since_ts) continue;
        if ($until_ts && $pub_ts && $pub_ts > $until_ts) continue;
        if ($max_age_days && $pub_ts && ($today - $pub_ts) > ($max_age_days * DAY_IN_SECONDS)) continue;

        // Shorts detection
        $is_short = ($sec > 0 && $sec <= 65);
        if ($type === 'videos' && $is_short) continue;
        if ($type === 'shorts' && !$is_short) continue;

        // Hashtag include or exclude
        $hay = strtolower($title . ' ' . $desc . ' ' . implode(' ', $tags));
        $has_hashtag = function($needle) use ($hay, $tags){
            $needle = ltrim($needle, '#');
            if ($needle === '') return false;
            if (preg_match('/(^|\s)#'.preg_quote($needle,'/').'(\s|$|\W)/i', $hay)) return true;
            foreach ($tags as $t) if (strtolower($t) === strtolower($needle)) return true;
            return false;
        };

        if (!empty($in_tags)) {
            $ok = false;
            foreach ($in_tags as $kw) { if ($has_hashtag($kw)) { $ok = true; break; } }
            if (!$ok) continue;
        } else {
            $hit = false;
            foreach ($ex_tags as $kw) { if ($has_hashtag($kw)) { $hit = true; break; } }
            if ($hit) continue;
        }

        // Duration gates
        if ($min_sec !== null && $sec > 0 && $sec < $min_sec) continue;
        if ($max_sec !== null && $sec > 0 && $sec > $max_sec) continue;

        $kept[] = [
            'id'    => $vid,
            'title' => $title,
            'date'  => $date,
            'thumb' => $thumb,
        ];
    }

    // Optional visual cap
    if ($limit > 0 && count($kept) > $limit) $kept = array_slice($kept, 0, $limit);
    if (!$kept) return '<p>No videos match your filters.</p>';

    // Build layout
    $layout = in_array($atts['layout'] ?? 'grid', ['grid','stack','carousel'], true) ? $atts['layout'] : 'grid';
    $items_html = '';
    foreach ($kept as $v) {
        $items_html .= '<div class="ssfs-item" data-ssfs-item data-title="'.esc_attr($v['title']).'" data-date="'.esc_attr(substr($v['date'],0,10)).'" data-ssfs-video="'.esc_attr($v['id']).'">'
            .'<img class="ssfs-thumb" loading="lazy" src="'.esc_url($v['thumb']).'" alt="'.esc_attr($v['title']).'" />'
            .'<div class="ssfs-meta">'
            .'<div class="ssfs-title">'.esc_html($v['title']).'</div>'
            .'<div class="ssfs-date">'.esc_html(date_i18n(get_option('date_format'), strtotime($v['date']))).'</div>'
            .'</div>'
            .'</div>';
    }

    if ($layout === 'carousel'){
        // nav sibling, then track
        $nav = '<div class="ssfs-nav"><button class="ssfs-btn" data-ssfs-scroll="prev">Prev</button><button class="ssfs-btn" data-ssfs-scroll="next">Next</button></div>';
        return $nav.'<div class="ssfs-carousel">'.$items_html.'</div>';
    }
    if ($layout === 'stack') {
        return '<div class="ssfs-grid" style="grid-template-columns:1fr">'.$items_html.'</div>';
    }
    return '<div class="ssfs-grid">'.$items_html.'</div>';
}

private function ytapi_get_uploads($channel_id, $api_key, $pages = 10) {
    // cache uploads playlist ID
    $ckey = 'ssfs_yt_uploads_' . md5($channel_id);
    if (isset($_GET['ssfs_refresh']) && $_GET['ssfs_refresh'] == '1') delete_transient($ckey);
    $uploads = get_transient($ckey);

    if (!$uploads) {
        $url = add_query_arg([
            'part' => 'contentDetails',
            'id'   => $channel_id,
            'key'  => $api_key,
        ], 'https://www.googleapis.com/youtube/v3/channels');

        $res = wp_remote_get($url, ['timeout' => 12]);
        if (is_wp_error($res)) return $res;

        $code = wp_remote_retrieve_response_code($res);
        $body = wp_remote_retrieve_body($res);
        $data = json_decode($body, true);

        if ($code === 403) {
            return new WP_Error('ssfs_api_403', 'Access denied, check API key restrictions. Use None or IP restrictions, and allow YouTube Data API v3.');
        }
        if (empty($data['items'][0]['contentDetails']['relatedPlaylists']['uploads'])) {
            return new WP_Error('ssfs_api', 'YouTube API error, cannot read uploads playlist for this channel.');
        }
        $uploads = $data['items'][0]['contentDetails']['relatedPlaylists']['uploads'];
        set_transient($ckey, $uploads, DAY_IN_SECONDS);
    }

    // fetch up to $pages pages of playlistItems, newest first
    $items = [];
    $pageToken = '';
    $scanned = 0;
    do {
        $url = add_query_arg([
            'part'       => 'snippet,contentDetails',
            'playlistId' => $uploads,
            'maxResults' => 50,
            'pageToken'  => $pageToken,
            'key'        => $api_key,
        ], 'https://www.googleapis.com/youtube/v3/playlistItems');

        $cache_key = 'ssfs_yt_pitems_' . md5($uploads.$pageToken);
        if (isset($_GET['ssfs_refresh']) && $_GET['ssfs_refresh'] == '1') delete_transient($cache_key);
        $page = get_transient($cache_key);
        if (!$page) {
            $res = wp_remote_get($url, ['timeout' => 12]);
            if (is_wp_error($res)) return $res;
            $page = json_decode(wp_remote_retrieve_body($res), true);
            set_transient($cache_key, $page, 10 * MINUTE_IN_SECONDS);
        }

        if (empty($page['items'])) break;
        foreach ($page['items'] as $it) {
            if (!isset($it['contentDetails']['videoId'])) continue;
            $items[] = [
                'videoId'     => $it['contentDetails']['videoId'],
                'publishedAt' => $it['contentDetails']['videoPublishedAt'] ?? ($it['snippet']['publishedAt'] ?? ''),
            ];
        }

        $pageToken = $page['nextPageToken'] ?? '';
        $scanned++;
    } while ($pageToken && $scanned < $pages);

    usort($items, function($a,$b){ return strcmp($b['publishedAt'], $a['publishedAt']); });
    return $items;
}

private function ytapi_get_videos_details($ids, $api_key) {
    $ids = array_values(array_unique(array_filter($ids)));
    $chunks = array_chunk($ids, 50);
    $details = [];

    foreach ($chunks as $chunk) {
        $url = add_query_arg([
            'part' => 'snippet,contentDetails',
            'id'   => implode(',', $chunk),
            'key'  => $api_key,
        ], 'https://www.googleapis.com/youtube/v3/videos');

        $cache_key = 'ssfs_yt_vmeta_' . md5(implode(',', $chunk));
        if (isset($_GET['ssfs_refresh']) && $_GET['ssfs_refresh'] == '1') delete_transient($cache_key);
        $data = get_transient($cache_key);
        if (!$data) {
            $res = wp_remote_get($url, ['timeout' => 12]);
            if (is_wp_error($res)) continue;
            $data = json_decode(wp_remote_retrieve_body($res), true);
            set_transient($cache_key, $data, 30 * MINUTE_IN_SECONDS);
        }

        foreach (($data['items'] ?? []) as $it) {
            $id    = $it['id'];
            $sn    = $it['snippet'] ?? [];
            $cd    = $it['contentDetails'] ?? [];
            $sec   = self::iso8601_to_seconds($cd['duration'] ?? '');
            $thumb = '';
            if (!empty($sn['thumbnails']['high']['url'])) $thumb = $sn['thumbnails']['high']['url'];
            elseif (!empty($sn['thumbnails'])) { $first = reset($sn['thumbnails']); $thumb = $first['url'] ?? ''; }

            $details[] = [
                'id'          => $id,
                'title'       => $sn['title'] ?? '',
                'description' => $sn['description'] ?? '',
                'tags'        => $sn['tags'] ?? [],
                'publishedAt' => $sn['publishedAt'] ?? '',
                'seconds'     => $sec,
                'thumb'       => $thumb ?: 'https://i.ytimg.com/vi/'.$id.'/hqdefault.jpg',
            ];
        }
    }
    return $details;
}

private static function iso8601_to_seconds($dur) {
    if (!$dur) return 0;
    if (!preg_match('/PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?/i', $dur, $m)) return 0;
    $h = isset($m[1]) ? (int)$m[1] : 0;
    $i = isset($m[2]) ? (int)$m[2] : 0;
    $s = isset($m[3]) ? (int)$m[3] : 0;
    return $h * 3600 + $i * 60 + $s;
}

private function ytapi_resolve_channel_id($input, $api_key){
    $input = trim($input);
    if ($input === '') return new WP_Error('ssfs_input', 'No channel provided');
    if (preg_match('/^UC[0-9A-Za-z_-]{22}$/', $input)) return $input;

    if ($input[0] === '@' || stripos($input, 'youtube.com/@') !== false) {
        $handle = $input[0] === '@' ? $input : preg_replace('~^.*?/@([^/?#]+).*$~', '@$1', $input);
        $handle = ltrim(trim($handle), '@/');
        if ($handle === '') return new WP_Error('ssfs_handle', 'Empty handle provided');

        $cache_key = 'ssfs_yt_handle_' . md5(strtolower($handle));
        if (isset($_GET['ssfs_refresh']) && $_GET['ssfs_refresh'] == '1') delete_transient($cache_key);
        $cached = get_transient($cache_key);
        if (is_string($cached) && preg_match('/^UC[0-9A-Za-z_-]{22}$/', $cached)) return $cached;
        if (is_array($cached)) {
            $cid = $cached['items'][0]['id']['channelId'] ?? ($cached['items'][0]['id'] ?? '');
            if ($cid && preg_match('/^UC[0-9A-Za-z_-]{22}$/', $cid)) return $cid;
        }

        // Prefer channels.list forHandle for accurate resolution, fall back to search.
        $url = add_query_arg([
            'part'      => 'id',
            'forHandle' => $handle,
            'key'       => $api_key,
        ], 'https://www.googleapis.com/youtube/v3/channels');
        $res = wp_remote_get($url, ['timeout' => 12]);
        if (!is_wp_error($res)) {
            $data = json_decode(wp_remote_retrieve_body($res), true);
            $cid = $data['items'][0]['id'] ?? '';
            if ($cid && preg_match('/^UC[0-9A-Za-z_-]{22}$/', $cid)) {
                set_transient($cache_key, $cid, DAY_IN_SECONDS);
                return $cid;
            }
        } else {
            return $res;
        }

        $url = add_query_arg([
            'part'       => 'snippet',
            'q'          => '@' . $handle,
            'type'       => 'channel',
            'maxResults' => 1,
            'key'        => $api_key,
        ], 'https://www.googleapis.com/youtube/v3/search');

        $res = wp_remote_get($url, ['timeout' => 12]);
        if (is_wp_error($res)) return $res;
        $data = json_decode(wp_remote_retrieve_body($res), true);
        $cid = $data['items'][0]['id']['channelId'] ?? '';
        if ($cid && preg_match('/^UC[0-9A-Za-z_-]{22}$/', $cid)) {
            set_transient($cache_key, $cid, DAY_IN_SECONDS);
            return $cid;
        }
        return new WP_Error('ssfs_handle', 'Could not resolve handle to a channel ID');
    }

    if (stripos($input, 'youtube.com') !== false) {
        if (preg_match('~youtube\.com/channel/([A-Za-z0-9_\-]+)~i', $input, $m) && preg_match('/^UC[0-9A-Za-z_-]{22}$/', $m[1])) {
            return $m[1];
        }
        $url = add_query_arg([
            'part'       => 'snippet',
            'q'          => $input,
            'type'       => 'channel',
            'maxResults' => 1,
            'key'        => $api_key,
        ], 'https://www.googleapis.com/youtube/v3/search');
        $res = wp_remote_get($url, ['timeout' => 12]);
        if (is_wp_error($res)) return $res;
        $data = json_decode(wp_remote_retrieve_body($res), true);
        $cid = $data['items'][0]['id']['channelId'] ?? '';
        if ($cid && preg_match('/^UC[0-9A-Za-z_-]{22}$/', $cid)) return $cid;
        return new WP_Error('ssfs_url', 'Could not resolve the channel URL to a channel ID');
    }

    $url = add_query_arg([
        'part'        => 'id',
        'forUsername' => $input,
        'key'         => $api_key,
    ], 'https://www.googleapis.com/youtube/v3/channels');
    $res = wp_remote_get($url, ['timeout' => 12]);
    if (!is_wp_error($res)) {
        $data = json_decode(wp_remote_retrieve_body($res), true);
        $cid = $data['items'][0]['id'] ?? '';
        if ($cid && preg_match('/^UC[0-9A-Za-z_-]{22}$/', $cid)) return $cid;
    }

    return new WP_Error('ssfs_input', 'Unrecognized channel input');
}

/* ===================== Facebook Graph API ===================== */

private function fetch_facebook_posts($page_id, $access_token, $limit = 10) {
    $cache_key = 'ssfs_fb_posts_' . md5($page_id);
    if (isset($_GET['ssfs_refresh']) && $_GET['ssfs_refresh'] == '1') delete_transient($cache_key);
    $cached = get_transient($cache_key);
    if (is_array($cached)) return $cached;

    $url = add_query_arg([
        'fields'       => 'id,message,full_picture,created_time,permalink_url,attachments{media_type,media,url}',
        'limit'        => min(absint($limit), 100),
        'access_token' => $access_token,
    ], 'https://graph.facebook.com/v22.0/' . rawurlencode($page_id) . '/posts');

    $res = wp_remote_get($url, ['timeout' => 15]);
    if (is_wp_error($res)) return $res;

    $code = wp_remote_retrieve_response_code($res);
    $body = json_decode(wp_remote_retrieve_body($res), true);

    if ($code !== 200 || isset($body['error'])) {
        $msg = $body['error']['message'] ?? 'Facebook API error (HTTP ' . $code . ')';
        return new WP_Error('ssfs_fb_api', $msg);
    }

    $posts = [];
    foreach (($body['data'] ?? []) as $item) {
        $media_type = '';
        if (!empty($item['attachments']['data'][0]['media_type'])) {
            $media_type = $item['attachments']['data'][0]['media_type'];
        }
        $posts[] = [
            'id'         => $item['id'] ?? '',
            'message'    => $item['message'] ?? '',
            'image'      => $item['full_picture'] ?? '',
            'date'       => $item['created_time'] ?? '',
            'permalink'  => $item['permalink_url'] ?? '',
            'media_type' => $media_type,
        ];
    }

    set_transient($cache_key, $posts, 30 * MINUTE_IN_SECONDS);
    return $posts;
}

/* ===================== Instagram Graph API ===================== */

private function fetch_instagram_media($user_id, $access_token, $limit = 12) {
    $cache_key = 'ssfs_ig_media_' . md5($user_id);
    if (isset($_GET['ssfs_refresh']) && $_GET['ssfs_refresh'] == '1') delete_transient($cache_key);
    $cached = get_transient($cache_key);
    if (is_array($cached)) return $cached;

    $url = add_query_arg([
        'fields'       => 'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp',
        'limit'        => min(absint($limit), 100),
        'access_token' => $access_token,
    ], 'https://graph.facebook.com/v22.0/' . rawurlencode($user_id) . '/media');

    $res = wp_remote_get($url, ['timeout' => 15]);
    if (is_wp_error($res)) return $res;

    $code = wp_remote_retrieve_response_code($res);
    $body = json_decode(wp_remote_retrieve_body($res), true);

    if ($code !== 200 || isset($body['error'])) {
        $msg = $body['error']['message'] ?? 'Instagram API error (HTTP ' . $code . ')';
        return new WP_Error('ssfs_ig_api', $msg);
    }

    $media = [];
    foreach (($body['data'] ?? []) as $item) {
        $type  = $item['media_type'] ?? 'IMAGE';
        $thumb = ($type === 'VIDEO') ? ($item['thumbnail_url'] ?? '') : ($item['media_url'] ?? '');
        $media[] = [
            'id'         => $item['id'] ?? '',
            'caption'    => $item['caption'] ?? '',
            'media_type' => $type,
            'media_url'  => $item['media_url'] ?? '',
            'thumb'      => $thumb,
            'permalink'  => $item['permalink'] ?? '',
            'date'       => $item['timestamp'] ?? '',
        ];
    }

    set_transient($cache_key, $media, 30 * MINUTE_IN_SECONDS);
    return $media;
}

/* ===================== TikTok Display API ===================== */

private function fetch_tiktok_videos($access_token, $limit = 10) {
    $cache_key = 'ssfs_tt_videos_' . md5($access_token);
    if (isset($_GET['ssfs_refresh']) && $_GET['ssfs_refresh'] == '1') delete_transient($cache_key);
    $cached = get_transient($cache_key);
    if (is_array($cached)) return $cached;

    $res = wp_remote_post('https://open.tiktokapis.com/v2/video/list/?fields=id,title,cover_image_url,share_url,create_time,duration,like_count', [
        'timeout' => 15,
        'headers' => [
            'Authorization' => 'Bearer ' . $access_token,
            'Content-Type'  => 'application/json',
        ],
        'body' => wp_json_encode(['max_count' => min(absint($limit), 20)]),
    ]);
    if (is_wp_error($res)) return $res;

    $code = wp_remote_retrieve_response_code($res);
    $body = json_decode(wp_remote_retrieve_body($res), true);

    if ($code !== 200 || !empty($body['error']['code'])) {
        $msg = $body['error']['message'] ?? 'TikTok API error (HTTP ' . $code . ')';
        return new WP_Error('ssfs_tt_api', $msg);
    }

    $videos = [];
    foreach (($body['data']['videos'] ?? []) as $item) {
        $videos[] = [
            'id'        => $item['id'] ?? '',
            'title'     => $item['title'] ?? '',
            'thumb'     => $item['cover_image_url'] ?? '',
            'share_url' => $item['share_url'] ?? '',
            'date'      => isset($item['create_time']) ? date('c', $item['create_time']) : '',
            'duration'  => $item['duration'] ?? 0,
            'likes'     => $item['like_count'] ?? 0,
        ];
    }

    set_transient($cache_key, $videos, 15 * MINUTE_IN_SECONDS);
    return $videos;
}

/* ===================== Layout helper ===================== */

private function wrap_layout($items_html, $layout) {
    if ($layout === 'carousel') {
        $nav = '<div class="ssfs-nav"><button class="ssfs-btn" data-ssfs-scroll="prev">Prev</button><button class="ssfs-btn" data-ssfs-scroll="next">Next</button></div>';
        return $nav . '<div class="ssfs-carousel">' . $items_html . '</div>';
    }
    if ($layout === 'stack') {
        return '<div class="ssfs-grid" style="grid-template-columns:1fr">' . $items_html . '</div>';
    }
    return '<div class="ssfs-grid">' . $items_html . '</div>';
}

/* ===================== Facebook feed render ===================== */

private function render_facebook_feed($opts, $atts) {
    $page_id = trim($opts['facebook_page_id'] ?? '');
    $token   = trim($opts['facebook_page_access_token'] ?? '');

    // Fall back to legacy XFBML embed if no Graph API credentials
    if (!$page_id || !$token) {
        $legacy = $this->embed_facebook($opts['facebook_page_url'] ?? '');
        if ($legacy) return $legacy;
        return '<p class="ssfs-note">Facebook Graph API credentials not configured. Add a Page ID and Access Token in settings.</p>';
    }

    $limit = max(1, intval($atts['facebook_limit'] ?? 10));
    $posts = $this->fetch_facebook_posts($page_id, $token, $limit);

    if (is_wp_error($posts)) {
        return '<p class="ssfs-note">Facebook API error: ' . esc_html($posts->get_error_message()) . '</p>';
    }
    if (empty($posts)) {
        return '<p class="ssfs-note">No Facebook posts found.</p>';
    }

    $posts = array_slice($posts, 0, $limit);
    $layout = in_array($atts['layout'] ?? 'grid', ['grid', 'stack', 'carousel'], true) ? $atts['layout'] : 'grid';
    $items_html = '';

    foreach ($posts as $post) {
        $caption = $post['message'] ? wp_trim_words($post['message'], 20, '&hellip;') : '';
        $date    = $post['date'] ? date_i18n(get_option('date_format'), strtotime($post['date'])) : '';
        $href    = esc_url($post['permalink']);

        $items_html .= '<a class="ssfs-item ssfs-item--facebook" href="' . $href . '" target="_blank" rel="noopener">';
        if ($post['image']) {
            $items_html .= '<img class="ssfs-thumb" loading="lazy" src="' . esc_url($post['image']) . '" alt="' . esc_attr($caption) . '" />';
        }
        $items_html .= '<div class="ssfs-meta">';
        if ($caption) $items_html .= '<div class="ssfs-title">' . esc_html($caption) . '</div>';
        if ($date)    $items_html .= '<div class="ssfs-date">' . esc_html($date) . '</div>';
        $items_html .= '</div></a>';
    }

    return $this->wrap_layout($items_html, $layout);
}

/* ===================== Instagram feed render ===================== */

private function render_instagram_feed($opts, $atts) {
    $user_id = trim($opts['instagram_user_id'] ?? '');
    $token   = trim($opts['instagram_access_token'] ?? '');

    // Fall back to legacy profile link if no Graph API credentials
    if (!$user_id || !$token) {
        $legacy = $this->embed_instagram($opts['instagram_username'] ?? '');
        if ($legacy) return $legacy;
        return '<p class="ssfs-note">Instagram Graph API credentials not configured. Add a User ID and Access Token in settings.</p>';
    }

    $limit = max(1, intval($atts['instagram_limit'] ?? 12));
    $media = $this->fetch_instagram_media($user_id, $token, $limit);

    if (is_wp_error($media)) {
        return '<p class="ssfs-note">Instagram API error: ' . esc_html($media->get_error_message()) . '</p>';
    }
    if (empty($media)) {
        return '<p class="ssfs-note">No Instagram media found.</p>';
    }

    $media  = array_slice($media, 0, $limit);
    $layout = in_array($atts['layout'] ?? 'grid', ['grid', 'stack', 'carousel'], true) ? $atts['layout'] : 'grid';
    $items_html = '';

    foreach ($media as $item) {
        $caption = $item['caption'] ? wp_trim_words($item['caption'], 20, '&hellip;') : '';
        $date    = $item['date'] ? date_i18n(get_option('date_format'), strtotime($item['date'])) : '';
        $is_video = ($item['media_type'] === 'VIDEO');
        $is_carousel = ($item['media_type'] === 'CAROUSEL_ALBUM');

        if ($is_video) {
            // Video items open in modal
            $items_html .= '<div class="ssfs-item ssfs-item--instagram ssfs-item--video" data-ssfs-ig-video="' . esc_attr($item['media_url']) . '">';
        } else {
            // Image/Carousel items link to Instagram
            $items_html .= '<a class="ssfs-item ssfs-item--instagram" href="' . esc_url($item['permalink']) . '" target="_blank" rel="noopener">';
        }

        $items_html .= '<div class="ssfs-thumb-wrap">';
        $items_html .= '<img class="ssfs-thumb" loading="lazy" src="' . esc_url($item['thumb']) . '" alt="' . esc_attr($caption) . '" />';
        if ($is_video) {
            $items_html .= '<span class="ssfs-play-badge" aria-hidden="true">&#9654;</span>';
        } elseif ($is_carousel) {
            $items_html .= '<span class="ssfs-carousel-badge" aria-hidden="true">&#10064;</span>';
        }
        $items_html .= '</div>';

        $items_html .= '<div class="ssfs-meta">';
        if ($caption) $items_html .= '<div class="ssfs-title">' . esc_html($caption) . '</div>';
        if ($date)    $items_html .= '<div class="ssfs-date">' . esc_html($date) . '</div>';
        $items_html .= '</div>';

        $items_html .= $is_video ? '</div>' : '</a>';
    }

    return $this->wrap_layout($items_html, $layout);
}

/* ===================== TikTok feed render ===================== */

private function render_tiktok_feed($opts, $atts) {
    $token = trim($opts['tiktok_access_token'] ?? '');

    if (!$token) {
        return '<p class="ssfs-note">TikTok API credentials not configured. Add an Access Token in settings.</p>';
    }

    $limit  = max(1, intval($atts['tiktok_limit'] ?? 10));
    $videos = $this->fetch_tiktok_videos($token, $limit);

    if (is_wp_error($videos)) {
        return '<p class="ssfs-note">TikTok API error: ' . esc_html($videos->get_error_message()) . '</p>';
    }
    if (empty($videos)) {
        return '<p class="ssfs-note">No TikTok videos found.</p>';
    }

    $videos = array_slice($videos, 0, $limit);
    $layout = in_array($atts['layout'] ?? 'grid', ['grid', 'stack', 'carousel'], true) ? $atts['layout'] : 'grid';
    $items_html = '';

    foreach ($videos as $video) {
        $title = $video['title'] ?: 'TikTok video';
        $date  = $video['date'] ? date_i18n(get_option('date_format'), strtotime($video['date'])) : '';

        $items_html .= '<a class="ssfs-item ssfs-item--tiktok" href="' . esc_url($video['share_url']) . '" target="_blank" rel="noopener">';
        $items_html .= '<div class="ssfs-thumb-wrap">';
        $items_html .= '<img class="ssfs-thumb ssfs-thumb--portrait" loading="lazy" src="' . esc_url($video['thumb']) . '" alt="' . esc_attr($title) . '" />';
        $items_html .= '<span class="ssfs-play-badge" aria-hidden="true">&#9654;</span>';
        $items_html .= '</div>';
        $items_html .= '<div class="ssfs-meta">';
        $items_html .= '<div class="ssfs-title">' . esc_html(wp_trim_words($title, 15, '&hellip;')) . '</div>';
        if ($date) $items_html .= '<div class="ssfs-date">' . esc_html($date) . '</div>';
        $items_html .= '</div></a>';
    }

    return $this->wrap_layout($items_html, $layout);
}

    /* ===================== Other platforms (legacy) ===================== */

    private function embed_facebook($page_url) {
        $page_url = trim($page_url);
        if (!$page_url) return '';
        $page_url = esc_url($page_url);
        $attrs = [
            'data-href' => $page_url,
            'data-tabs' => 'timeline',
            'data-width' => '500',
            'data-height' => '600',
            'data-small-header' => 'false',
            'data-adapt-container-width' => 'true',
            'data-hide-cover' => 'false',
            'data-show-facepile' => 'true',
        ];
        $attr_html = '';
        foreach ($attrs as $k => $v) $attr_html .= ' ' . esc_attr($k) . '="' . esc_attr($v) . '"';
        return '<div class="fb-page"' . $attr_html . '><blockquote cite="' . $page_url . '" class="fb-xfbml-parse-ignore"><a href="' . $page_url . '">Facebook Page</a></blockquote></div>';
    }

    private function embed_twitter($username) {
        $username = trim($username);
        if (!$username) return '';
        $href = 'https://twitter.com/' . rawurlencode($username);
        return '<a class="twitter-timeline" href="' . esc_url($href) . '">Tweets by ' . esc_html($username) . '</a>';
    }

    private function embed_spotify($artist_id) {
        $artist_id = trim($artist_id);
        if (!$artist_id) return '';
        $src = 'https://open.spotify.com/embed/artist/' . rawurlencode($artist_id);
        return '<iframe style="border-radius:12px" src="' . esc_url($src) . '" width="100%" height="380" frameborder="0" allow="autoplay; clipboard-write; encrypted-media; fullscreen; picture-in-picture" loading="lazy"></iframe>';
    }

    private function embed_instagram($username) {
        $username = trim($username);
        if (!$username) return '';
        $url = 'https://www.instagram.com/' . rawurlencode($username) . '/';
        $note = '<p class="ssfs-note">Instagram profile feed embed is not available without API permission. This is a profile link.</p>';
        return $note . '<p><a href="' . esc_url($url) . '" target="_blank" rel="noopener">Follow @' . esc_html($username) . ' on Instagram</a></p>';
    }
}
