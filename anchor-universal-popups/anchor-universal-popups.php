<?php
/**
 * Anchor Tools module: Anchor Universal Popups.
 */

if (!defined('ABSPATH')) exit;

class Anchor_Universal_Popups_Module {
    const CPT = 'anchor_popup';
    const LEGACY_CPT = 'up_popup';
    const MIGRATION_FLAG = 'anchor_universal_popups_migrated';
    const NONCE = 'anchor_popup_nonce';

    public function __construct(){
        add_action('init', [$this, 'register_cpt']);
        add_action('add_meta_boxes', [$this, 'add_metaboxes']);
        add_action('save_post', [$this, 'save_meta']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('wp_enqueue_scripts', [$this, 'frontend_assets']);
        add_shortcode('up_popup', [$this, 'shortcode_render']);
        add_shortcode('anchor_popup', [$this, 'shortcode_render']);
        add_filter('manage_' . self::CPT . '_posts_columns', [$this, 'admin_columns']);
        add_action('manage_' . self::CPT . '_posts_custom_column', [$this, 'admin_column_content'], 10, 2);
    }

    public function register_cpt(){
        $this->maybe_migrate_legacy_posts();
        register_post_type(self::CPT, [
            'labels' => [
                'name' => 'Anchor Universal Popups',
                'singular_name' => 'Anchor Universal Popup',
                'add_new_item' => 'Add New Anchor Popup',
                'edit_item' => 'Edit Anchor Popup',
                'menu_name' => 'Anchor Universal Popups',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => apply_filters('anchor_universal_popups_parent_menu', true),
            'menu_icon' => 'dashicons-welcome-widgets-menus',
            'supports' => ['title'],
        ]);
    }

    public function admin_columns($columns) {
        $new = [];
        foreach ($columns as $k => $v) {
            $new[$k] = $v;
            if ($k === 'title') {
                $new['up_shortcode'] = 'Shortcode';
                $new['up_mode'] = 'Mode';
            }
        }
        return $new;
    }

    public function admin_column_content($column, $post_id) {
        if ($column === 'up_shortcode') {
            echo '<code>[anchor_popup id="' . esc_html($post_id) . '"]</code>';
        } elseif ($column === 'up_mode') {
            $mode = get_post_meta($post_id, 'up_mode', true);
            if (in_array($mode, ['youtube', 'vimeo'], true)) $mode = 'video';
            echo esc_html(ucfirst($mode ?: 'html'));
        }
    }

    private function maybe_migrate_legacy_posts(){
        if ( get_option(self::MIGRATION_FLAG) ) {
            return;
        }
        global $wpdb;
        $updated = $wpdb->update(
            $wpdb->posts,
            ['post_type' => self::CPT],
            ['post_type' => self::LEGACY_CPT]
        );
        if ( false !== $updated ) {
            update_option(self::MIGRATION_FLAG, 1);
        }
    }

    private function defaults(){
        return [
            // content
            'mode' => 'html',               // html, video, shortcode (legacy: youtube, vimeo)
            'video_url' => '',              // full YouTube/Vimeo URL
            'video_id' => '',               // id for youtube or vimeo (derived from URL or legacy)
            'aspect_ratio' => '16:9',       // thumbnail aspect ratio
            'tile_style' => 'card',         // card, minimal, overlay, cinematic
            'theme' => 'dark',              // dark, light, auto
            'show_title' => '1',            // 0 or 1
            'show_duration' => '0',         // 0 or 1
            'show_channel' => '0',          // 0 or 1
            'hover_effect' => 'lift',       // lift, zoom, glow, none
            'play_button_style' => 'circle', // circle, square, youtube, minimal, none
            'border_radius' => '12',        // 0-32
            'autoplay' => '0',              // 0 or 1 for video popups
            'html' => '',
            'shortcode' => '',              // shortcode content to be rendered with do_shortcode()
            'close_color' => '#ffffff',     // close icon color
            'css'  => '',
            'js'   => '',

            // trigger
            'trigger_type' => 'page_load',  // page_load, class, id
            'trigger_value' => '',          // class without dot, or id without hash
            'delay_ms' => 0,                // used for page_load

            // frequency
            'frequency_mode' => 'session',  // session or cooldown
            'cooldown_minutes' => 1440,     // used when frequency_mode=cooldown, 1440 = 24h

            // exclusions
            'exclude_urls' => '',           // comma separated list, full or relative
            'exclude_cats' => '',           // comma separated list of slugs or IDs
        ];
    }

    private function get_meta($post_id){
        $d = $this->defaults();
        $meta = [];
        foreach ($d as $k => $v){
            $meta[$k] = get_post_meta($post_id, "up_$k", true);
            if ($meta[$k] === '') $meta[$k] = $v;
        }
        return $meta;
    }

    /**
     * Parse a video URL to extract provider and ID.
     * @return array|null ['provider' => 'youtube'|'vimeo', 'id' => '...'] or null
     */
    private function parse_video_url($url) {
        $url = trim((string) $url);
        if ($url === '') return null;

        // YouTube patterns
        if (preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\\?v=|embed/|shorts/|live/))([A-Za-z0-9_-]{6,})~', $url, $matches)) {
            return ['provider' => 'youtube', 'id' => $matches[1]];
        }

        // Vimeo patterns
        if (preg_match('~vimeo\.com/(?:video/)?([0-9]+)~', $url, $matches)) {
            return ['provider' => 'vimeo', 'id' => $matches[1]];
        }

        return null;
    }

    /**
     * Reconstruct URL from provider and ID for legacy compatibility.
     */
    private function reconstruct_video_url($provider, $id) {
        if ($provider === 'youtube' && $id) {
            return 'https://www.youtube.com/watch?v=' . $id;
        }
        if ($provider === 'vimeo' && $id) {
            return 'https://vimeo.com/' . $id;
        }
        return '';
    }

    /**
     * Fetch video metadata (thumbnail, title) for a given provider and ID.
     * @return array ['thumb' => '...', 'title' => '...']
     */
    private function fetch_video_meta($provider, $id) {
        if (!$provider || !$id) return ['thumb' => '', 'title' => '', 'duration' => '', 'channel' => ''];

        if ($provider === 'youtube') {
            return $this->fetch_youtube_meta($id);
        }
        if ($provider === 'vimeo') {
            return $this->fetch_vimeo_meta($id);
        }

        return ['thumb' => '', 'title' => '', 'duration' => '', 'channel' => ''];
    }

    private function fetch_youtube_meta($id) {
        $cache_key = 'up_yt_' . $id;
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $meta = [
            'thumb' => 'https://img.youtube.com/vi/' . $id . '/hqdefault.jpg',
            'title' => '',
            'duration' => '',
            'channel' => '',
        ];

        // Try API if key available
        $api_key = $this->get_google_api_key();
        if ($api_key) {
            $url = add_query_arg([
                'part' => 'snippet,contentDetails',
                'id'   => $id,
                'key'  => $api_key,
            ], 'https://www.googleapis.com/youtube/v3/videos');

            $res = wp_remote_get($url, ['timeout' => 10]);
            if (!is_wp_error($res)) {
                $data = json_decode(wp_remote_retrieve_body($res), true);
                if (!empty($data['items'][0]['snippet'])) {
                    $sn = $data['items'][0]['snippet'];
                    $meta['title'] = $sn['title'] ?? '';
                    $meta['channel'] = $sn['channelTitle'] ?? '';
                    if (!empty($sn['thumbnails']['high']['url'])) {
                        $meta['thumb'] = $sn['thumbnails']['high']['url'];
                    } elseif (!empty($sn['thumbnails']['medium']['url'])) {
                        $meta['thumb'] = $sn['thumbnails']['medium']['url'];
                    }
                }
                if (!empty($data['items'][0]['contentDetails']['duration'])) {
                    $meta['duration'] = $this->format_iso_duration($data['items'][0]['contentDetails']['duration']);
                }
            }
        }

        set_transient($cache_key, $meta, 12 * HOUR_IN_SECONDS);
        return $meta;
    }

    private function fetch_vimeo_meta($id) {
        $cache_key = 'up_vm_' . $id;
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $meta = ['thumb' => '', 'title' => '', 'duration' => '', 'channel' => ''];

        $oembed_url = add_query_arg([
            'url' => 'https://vimeo.com/' . rawurlencode($id),
            'dnt' => '1',
        ], 'https://vimeo.com/api/oembed.json');

        $res = wp_remote_get($oembed_url, ['timeout' => 10]);
        if (!is_wp_error($res)) {
            $data = json_decode(wp_remote_retrieve_body($res), true);
            if (is_array($data)) {
                $meta['title'] = $data['title'] ?? '';
                $meta['thumb'] = $data['thumbnail_url'] ?? '';
                $meta['channel'] = $data['author_name'] ?? '';
                if (!empty($data['duration'])) {
                    $meta['duration'] = $this->format_seconds_duration((int) $data['duration']);
                }
            }
        }

        set_transient($cache_key, $meta, 24 * HOUR_IN_SECONDS);
        return $meta;
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

    public function add_metaboxes(){
        add_meta_box('up_popup_code', 'Popup Content (HTML, CSS, JS or Video)', [$this, 'render_box_code'], self::CPT, 'normal', 'high');
        add_meta_box('up_popup_settings', 'Trigger, Frequency, Exclusions', [$this, 'render_box_settings'], self::CPT, 'side');
        add_meta_box('up_popup_preview', 'Live Preview', [$this, 'render_box_preview'], self::CPT, 'normal', 'default');
    }

    public function render_box_code($post){
        wp_nonce_field(self::NONCE, self::NONCE);
        $m = $this->get_meta($post->ID);
        wp_enqueue_code_editor(array('type' => 'text/html'));
        wp_enqueue_code_editor(array('type' => 'text/css'));
        wp_enqueue_code_editor(array('type' => 'application/javascript'));
        wp_enqueue_script('code-editor');
        wp_enqueue_style('code-editor');
        ?>
        <div class="up-fields">
            <style>.up-fields .up-field{ margin-bottom:12px; }</style>

            <div class="up-field">
              <label><strong>Mode</strong></label>
              <select name="up_mode" id="up_mode">
                <option value="html" <?php selected($m['mode'], 'html'); ?>>HTML</option>
                <option value="shortcode" <?php selected($m['mode'], 'shortcode'); ?>>Shortcode</option>
                <option value="video" <?php selected(in_array($m['mode'], ['video', 'youtube', 'vimeo'], true)); ?>>Video</option>
              </select>
            </div>

            <div class="up-field up-field-video" data-up-show-when-mode="video">
              <label><strong>Video URL</strong></label>
              <?php
              // For legacy youtube/vimeo modes, reconstruct the URL from video_id
              $video_url_display = $m['video_url'];
              if ($video_url_display === '' && $m['video_id'] !== '' && in_array($m['mode'], ['youtube', 'vimeo'], true)) {
                  $video_url_display = $this->reconstruct_video_url($m['mode'], $m['video_id']);
              }
              ?>
              <input type="url" name="up_video_url" value="<?php echo esc_attr($video_url_display); ?>" placeholder="https://youtube.com/watch?v=... or https://vimeo.com/..." class="widefat"/>
              <p class="description">Paste the full YouTube or Vimeo URL. The video ID will be extracted automatically.</p>
            </div>

            <div class="up-field" data-up-show-when-mode="video">
              <label><strong>Card Aspect Ratio</strong></label>
              <select name="up_aspect_ratio" id="up_aspect_ratio">
                <option value="16:9" <?php selected($m['aspect_ratio'], '16:9'); ?>>16:9 (Widescreen)</option>
                <option value="4:3" <?php selected($m['aspect_ratio'], '4:3'); ?>>4:3 (Classic)</option>
                <option value="1:1" <?php selected($m['aspect_ratio'], '1:1'); ?>>1:1 (Square)</option>
                <option value="9:16" <?php selected($m['aspect_ratio'], '9:16'); ?>>9:16 (Portrait)</option>
                <option value="21:9" <?php selected($m['aspect_ratio'], '21:9'); ?>>21:9 (Cinematic)</option>
              </select>
              <p class="description">Aspect ratio for the video card thumbnail displayed via shortcode.</p>
            </div>

            <div data-up-show-when-mode="video">
              <h4 style="margin:12px 0 8px;">Card Appearance</h4>

              <div class="up-field">
                <label><strong>Tile Style</strong></label>
                <select name="up_tile_style" id="up_tile_style">
                  <option value="card" <?php selected($m['tile_style'], 'card'); ?>>Card</option>
                  <option value="minimal" <?php selected($m['tile_style'], 'minimal'); ?>>Minimal</option>
                  <option value="overlay" <?php selected($m['tile_style'], 'overlay'); ?>>Overlay</option>
                  <option value="cinematic" <?php selected($m['tile_style'], 'cinematic'); ?>>Cinematic</option>
                </select>
              </div>

              <div class="up-field">
                <label><strong>Theme</strong></label>
                <select name="up_theme">
                  <option value="dark" <?php selected($m['theme'], 'dark'); ?>>Dark</option>
                  <option value="light" <?php selected($m['theme'], 'light'); ?>>Light</option>
                  <option value="auto" <?php selected($m['theme'], 'auto'); ?>>Auto (system)</option>
                </select>
              </div>

              <div class="up-field">
                <label><strong>Hover Effect</strong></label>
                <select name="up_hover_effect">
                  <option value="lift" <?php selected($m['hover_effect'], 'lift'); ?>>Lift</option>
                  <option value="zoom" <?php selected($m['hover_effect'], 'zoom'); ?>>Zoom</option>
                  <option value="glow" <?php selected($m['hover_effect'], 'glow'); ?>>Glow</option>
                  <option value="none" <?php selected($m['hover_effect'], 'none'); ?>>None</option>
                </select>
              </div>

              <div class="up-field">
                <label><strong>Play Button</strong></label>
                <select name="up_play_button_style">
                  <option value="circle" <?php selected($m['play_button_style'], 'circle'); ?>>Circle</option>
                  <option value="square" <?php selected($m['play_button_style'], 'square'); ?>>Square</option>
                  <option value="youtube" <?php selected($m['play_button_style'], 'youtube'); ?>>YouTube</option>
                  <option value="minimal" <?php selected($m['play_button_style'], 'minimal'); ?>>Minimal</option>
                  <option value="none" <?php selected($m['play_button_style'], 'none'); ?>>None</option>
                </select>
              </div>

              <div class="up-field">
                <label><strong>Border Radius</strong></label>
                <input type="number" name="up_border_radius" value="<?php echo esc_attr($m['border_radius']); ?>" min="0" max="32" step="1" style="width:80px;" /> px
              </div>

              <div class="up-field">
                <label>
                  <input type="hidden" name="up_show_title" value="0" />
                  <input type="checkbox" name="up_show_title" value="1" <?php checked($m['show_title'], '1'); ?> />
                  Show Title
                </label>
              </div>

              <div class="up-field">
                <label>
                  <input type="hidden" name="up_show_duration" value="0" />
                  <input type="checkbox" name="up_show_duration" value="1" <?php checked($m['show_duration'], '1'); ?> />
                  Show Duration
                </label>
              </div>

              <div class="up-field">
                <label>
                  <input type="hidden" name="up_show_channel" value="0" />
                  <input type="checkbox" name="up_show_channel" value="1" <?php checked($m['show_channel'], '1'); ?> />
                  Show Channel
                </label>
              </div>
            </div>

            <div class="up-field up-field-shortcode" data-up-show-when-mode="shortcode">
                <label for="up_shortcode"><strong>Shortcode</strong></label>
                <textarea id="up_shortcode" name="up_shortcode" rows="4" class="widefat code"><?php echo esc_textarea($m['shortcode']); ?></textarea>
                <p class="description">Enter your shortcode(s) here. They will be processed and rendered. Example: [contact-form-7 id="123" title="Contact"]</p>
            </div>

            <div class="up-field up-field-html" data-up-show-when-mode="html">
                <label for="up_html"><strong>HTML</strong></label>
                <textarea id="up_html" name="up_html" rows="8" class="widefat code"><?php echo esc_textarea($m['html']); ?></textarea>
            </div>
            <div class="up-field">
                <label for="up_css"><strong>CSS</strong></label>
                <textarea id="up_css" name="up_css" rows="6" class="widefat code"><?php echo esc_textarea($m['css']); ?></textarea>
            </div>
            <div class="up-field">
                <label for="up_js"><strong>JavaScript</strong></label>
                <textarea id="up_js" name="up_js" rows="6" class="widefat code"><?php echo esc_textarea($m['js']); ?></textarea>
            </div>
        </div>
        <?php
    }

    public function render_box_settings($post){
        $m = $this->get_meta($post->ID);
        ?>
        <div class="up-side">
          <style>
            .up-side label{ display:block; margin-top:8px; font-weight:600; }
            .up-side input[type="number"], .up-side input[type="text"], .up-side select, .up-side textarea{ width:100%; }
            .up-side input[type="color"]{ width:50px; height:30px; padding:0; border:1px solid #8c8f94; cursor:pointer; }
            .up-side textarea{ min-height:70px; }
            .description{ color:#666; font-size:12px; }
            .up-color-row{ display:flex; align-items:center; gap:8px; }
            .up-color-row input[type="text"]{ flex:1; }
          </style>

          <label>Close Icon Color</label>
          <div class="up-color-row">
            <input type="color" id="up_close_color_picker" value="<?php echo esc_attr($m['close_color']); ?>" />
            <input type="text" name="up_close_color" id="up_close_color" value="<?php echo esc_attr($m['close_color']); ?>" placeholder="#ffffff" />
          </div>

          <label>Autoplay (videos only)</label>
          <select name="up_autoplay">
            <option value="0" <?php selected($m['autoplay'],'0'); ?>>No</option>
            <option value="1" <?php selected($m['autoplay'],'1'); ?>>Yes</option>
          </select>

          <label>Trigger type</label>
          <select name="up_trigger_type">
            <option value="page_load" <?php selected($m['trigger_type'],'page_load'); ?>>Page load</option>
            <option value="class" <?php selected($m['trigger_type'],'class'); ?>>Click on class</option>
            <option value="id" <?php selected($m['trigger_type'],'id'); ?>>Click on ID</option>
          </select>
          <p class="description">For class or ID, enter the selector value below.</p>

          <div data-up-show-when-trigger="class,id">
            <label>Trigger value</label>
            <input type="text" name="up_trigger_value" value="<?php echo esc_attr($m['trigger_value']); ?>" placeholder="class without dot, or id without hash"/>
          </div>

          <div data-up-show-when-trigger="page_load">
            <label>Delay on page load (ms)</label>
            <input type="number" min="0" step="100" name="up_delay_ms" value="<?php echo esc_attr($m['delay_ms']); ?>" />
          </div>

          <div data-up-hide-when-trigger="class">
            <hr/>
            <h4 style="margin:6px 0;">Frequency</h4>
            <label>Mode</label>
            <select name="up_frequency_mode">
              <option value="session" <?php selected($m['frequency_mode'],'session'); ?>>Once per session</option>
              <option value="cooldown" <?php selected($m['frequency_mode'],'cooldown'); ?>>Cooldown (minutes)</option>
            </select>

            <label>Cooldown minutes</label>
            <input type="number" min="1" step="1" name="up_cooldown_minutes" value="<?php echo esc_attr($m['cooldown_minutes']); ?>" />
          </div>

          <div data-up-hide-when-trigger="class">
            <hr/>
            <h4 style="margin:6px 0;">Exclusions</h4>
            <label>Exclude on URLs (comma separated)</label>
            <textarea name="up_exclude_urls" placeholder="/thank-you, /landing/special, https://example.com/exact-path"><?php
                echo esc_textarea($m['exclude_urls']);
            ?></textarea>
            <p class="description">Use full URLs or relative paths. Prefix match is allowed.</p>

            <label>Exclude on categories (comma separated slugs or IDs)</label>
            <textarea name="up_exclude_cats" placeholder="news, events, 42"><?php
                echo esc_textarea($m['exclude_cats']);
            ?></textarea>
            <p class="description">If the singular post has any of these categories, the popup will not load.</p>
          </div>
        </div>
        <?php
    }

    public function render_box_preview($post){
        ?>
        <p class="description">Live preview renders your HTML, CSS, and JS below inside a contained viewport.</p>
        <div id="up-preview-wrap">
            <div id="up-preview-viewport" style="border:1px solid #ccd0d4; border-radius:8px; overflow:auto; max-height:400px; background:#fff;">
                <div id="up-preview-content" style="padding:16px;"></div>
            </div>
        </div>
        <?php
    }

    public function save_meta($post_id){
        if (!isset($_POST[self::NONCE]) || !wp_verify_nonce($_POST[self::NONCE], self::NONCE)) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $fields = [
            'mode','video_url','video_id','aspect_ratio',
            'tile_style','theme','show_title','show_duration','show_channel',
            'hover_effect','play_button_style','border_radius',
            'autoplay','close_color',
            'html','shortcode','css','js',
            'trigger_type','trigger_value','delay_ms',
            'frequency_mode','cooldown_minutes',
            'exclude_urls','exclude_cats'
        ];
        foreach ($fields as $f){
            $key = "up_$f";
            $val = isset($_POST[$key]) ? $_POST[$key] : '';
            if (in_array($f, ['html','shortcode','css','js'], true)){
                // allow markup in these fields in admin
                update_post_meta($post_id, $key, $val);
            } else {
                update_post_meta($post_id, $key, sanitize_text_field($val));
            }
        }

        // When mode is video, derive video_id from URL for backward compatibility
        $mode = isset($_POST['up_mode']) ? sanitize_text_field($_POST['up_mode']) : '';
        $video_url = isset($_POST['up_video_url']) ? esc_url_raw($_POST['up_video_url']) : '';
        if ($mode === 'video' && $video_url !== '') {
            $parsed = $this->parse_video_url($video_url);
            if ($parsed) {
                update_post_meta($post_id, 'up_video_id', $parsed['id']);
            }
        }
    }

    private function parse_list($str){
        if (!is_string($str) || $str === '') return [];
        $parts = array_filter(array_map('trim', explode(',', $str)));
        return array_values($parts);
    }

    private function request_url_matches_any(array $needles){
        if (empty($needles)) return false;

        global $wp;
        $req_path = is_object($wp) && isset($wp->request) ? $wp->request : '';
        $current_full = home_url($req_path ? '/' . ltrim($req_path, '/') : '/');
        $cur = rtrim(strtolower($current_full), '/');

        foreach ($needles as $n){
            $n = trim($n);
            if ($n === '') continue;
            // make full URL if relative
            if (strpos($n, 'http://') !== 0 && strpos($n, 'https://') !== 0){
                $n = home_url($n);
            }
            $n = rtrim(strtolower($n), '/');
            if ($cur === $n) return true;
            if (strpos($cur, $n) === 0) return true;
        }
        return false;
    }

    private function post_has_excluded_category(array $cats){
        if (empty($cats) || !is_singular()) return false;

        $post_id = get_queried_object_id();
        if (!$post_id) return false;

        $wanted_ids = [];
        foreach ($cats as $c){
            $c = trim($c);
            if ($c === '') continue;
            if (ctype_digit($c)){
                $wanted_ids[] = (int)$c;
            } else {
                $term = get_term_by('slug', $c, 'category');
                if ($term && !is_wp_error($term)) $wanted_ids[] = (int)$term->term_id;
            }
        }
        if (empty($wanted_ids)) return false;

        $post_terms = wp_get_post_categories($post_id, ['fields' => 'ids']);
        if (empty($post_terms)) return false;

        return count(array_intersect($post_terms, $wanted_ids)) > 0;
    }

    private function is_excluded_for_request(array $meta){
        $urls = $this->parse_list($meta['exclude_urls']);
        $cats = $this->parse_list($meta['exclude_cats']);

        if ($this->request_url_matches_any($urls)) return true;
        if ($this->post_has_excluded_category($cats)) return true;

        return false;
    }

    private function get_published_popups(){
        $q = new WP_Query([
            'post_type' => self::CPT,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'no_found_rows' => true,
        ]);
        $items = [];
        foreach ($q->posts as $p){
            $m = $this->get_meta($p->ID);

            // For shortcode mode, process the shortcode content server-side
            $rendered_shortcode = '';
            if ( $m['mode'] === 'shortcode' && ! empty( $m['shortcode'] ) ) {
                $rendered_shortcode = do_shortcode( $m['shortcode'] );
            }

            // Normalize legacy youtube/vimeo modes to video
            $mode = $m['mode'];
            $provider = '';
            $video_id = $m['video_id'];
            $video_url = $m['video_url'];
            $video_thumb = '';
            $video_title = '';
            $video_duration = '';
            $video_channel = '';
            $aspect_ratio = $m['aspect_ratio'];

            if (in_array($mode, ['youtube', 'vimeo', 'video'], true)) {
                // Legacy modes: provider is the mode itself
                if ($mode === 'youtube' || $mode === 'vimeo') {
                    $provider = $mode;
                    // Reconstruct URL if not set
                    if ($video_url === '' && $video_id !== '') {
                        $video_url = $this->reconstruct_video_url($provider, $video_id);
                    }
                    $mode = 'video'; // normalize
                } else {
                    // Video mode: parse URL to get provider and ID
                    $parsed = $this->parse_video_url($video_url);
                    if ($parsed) {
                        $provider = $parsed['provider'];
                        $video_id = $parsed['id'];
                    }
                }

                // Fetch metadata
                if ($provider && $video_id) {
                    $meta = $this->fetch_video_meta($provider, $video_id);
                    $video_thumb = $meta['thumb'];
                    $video_title = $meta['title'];
                    $video_duration = $meta['duration'];
                    $video_channel = $meta['channel'];
                }
            }

            $items[] = [
                'id' => (int)$p->ID,
                'title' => $p->post_title,
                'mode' => $mode,
                'provider' => $provider,
                'video_id' => $video_id,
                'video_url' => $video_url,
                'video_thumb' => $video_thumb,
                'video_title' => $video_title,
                'video_duration' => $video_duration,
                'video_channel' => $video_channel,
                'aspect_ratio' => $aspect_ratio,
                'autoplay' => ($m['autoplay'] === '1'),
                'close_color' => $m['close_color'],
                'html' => $m['html'],
                'shortcode_content' => $rendered_shortcode, // pre-rendered shortcode content
                'css' => $m['css'],
                'js' => $m['js'],
                'trigger' => [
                    'type' => $m['trigger_type'],
                    'value' => $m['trigger_value'],
                    'delay' => (int)$m['delay_ms'],
                ],
                'frequency' => [
                    'mode' => $m['frequency_mode'], // session or cooldown
                    'cooldownMinutes' => (int)$m['cooldown_minutes'],
                ],
                'exclude_urls' => $m['exclude_urls'],
                'exclude_cats' => $m['exclude_cats'],
            ];
        }
        return $items;
    }

    public function admin_assets($hook){
        global $post;
        if (($hook === 'post-new.php' || $hook === 'post.php') && isset($post) && $post->post_type === self::CPT){
            wp_enqueue_style('up-admin', plugins_url('assets/admin.css', __FILE__), [], '1.1.0');
            wp_enqueue_script('up-admin', plugins_url('assets/admin.js', __FILE__), ['jquery','code-editor'], '1.1.0', true);
        }
    }

    public function frontend_assets(){
        $snippets = $this->get_published_popups();
        if (empty($snippets)) return;

        // Filter out items excluded for this request at server level
        $snippets = array_values(array_filter($snippets, function($sn){
            return !$this->is_excluded_for_request($sn);
        }));
        if (empty($snippets)) return;

        wp_enqueue_style('up-frontend', plugins_url('assets/frontend.css', __FILE__), [], '1.1.0');
        wp_enqueue_script('up-frontend', plugins_url('assets/frontend.js', __FILE__), [], '1.0.5', true);
        wp_localize_script('up-frontend', 'UP_SNIPPETS', $snippets);
    }

    public function shortcode_render($atts){
        $atts = shortcode_atts(['id' => 0, 'width' => ''], $atts);
        $post_id = (int)$atts['id'];
        if (!$post_id) return '';
        $m = $this->get_meta($post_id);

        // Video mode: render a clickable video card
        if (in_array($m['mode'], ['video', 'youtube', 'vimeo'], true)) {
            return $this->render_video_card($post_id, $m, $atts['width']);
        }

        // Determine content based on mode
        $content = '';
        if ( $m['mode'] === 'shortcode' && ! empty( $m['shortcode'] ) ) {
            $content = do_shortcode( $m['shortcode'] );
        } else {
            $content = $m['html'];
        }

        ob_start(); ?>
        <div class="up-embed-popup" data-up-embed="<?php echo esc_attr($post_id); ?>">
            <style><?php echo $m['css']; ?></style>
            <div class="up-embed-viewport"><?php echo $content; ?></div>
            <script>(function(){ try{ <?php echo $m['js']; ?> }catch(e){ console && console.error && console.error(e); } })();</script>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render a video card for shortcode embedding.
     */
    private function render_video_card($post_id, $m, $width = '') {
        // Resolve provider and video ID
        $provider = '';
        $video_id = $m['video_id'];
        $video_url = $m['video_url'];

        if ($m['mode'] === 'youtube' || $m['mode'] === 'vimeo') {
            $provider = $m['mode'];
            if ($video_url === '' && $video_id !== '') {
                $video_url = $this->reconstruct_video_url($provider, $video_id);
            }
        } else {
            $parsed = $this->parse_video_url($video_url);
            if ($parsed) {
                $provider = $parsed['provider'];
                $video_id = $parsed['id'];
            }
        }

        if (!$provider || !$video_id) {
            return '<!-- Universal Popup: Invalid video URL -->';
        }

        // Fetch metadata
        $meta = $this->fetch_video_meta($provider, $video_id);
        $thumb = $meta['thumb'];
        $title = $meta['title'];
        $duration = $meta['duration'];
        $channel = $meta['channel'];

        // Card settings
        $tile_style = $m['tile_style'];
        $theme = $m['theme'];
        $hover = $m['hover_effect'];
        $play_style = $m['play_button_style'];
        $radius = (int) $m['border_radius'];
        $show_title = $m['show_title'] === '1';
        $show_duration = $m['show_duration'] === '1' && $duration !== '';
        $show_channel = $m['show_channel'] === '1' && $channel !== '';

        // Convert aspect ratio to CSS value (cinematic forces 2.35:1)
        $ratio_map = [
            '16:9' => '16 / 9',
            '4:3'  => '4 / 3',
            '1:1'  => '1 / 1',
            '9:16' => '9 / 16',
            '21:9' => '21 / 9',
        ];
        if ($tile_style === 'cinematic') {
            $ratio_val = '2.35 / 1';
        } else {
            $ratio_val = isset($ratio_map[$m['aspect_ratio']]) ? $ratio_map[$m['aspect_ratio']] : '16 / 9';
        }

        // Build CSS classes
        $classes = [
            'up-video-card',
            'up-tiles-' . $tile_style,
            'up-theme-' . $theme,
            'up-hover-' . $hover,
            'up-play-' . $play_style,
        ];

        // Play button SVG
        $play_svg = $play_style !== 'none' ? $this->get_play_button_svg($play_style) : '';

        $has_meta = $show_title || $show_channel;

        // Build inline style
        $style = '--up-card-ratio: ' . esc_attr($ratio_val) . '; --up-card-radius: ' . esc_attr($radius) . 'px';
        if ($width !== '') {
            // Append px if numeric only
            $w = trim($width);
            if (is_numeric($w)) $w .= 'px';
            $style .= '; max-width: ' . esc_attr($w);
        }

        ob_start();
        ?>
        <div class="<?php echo esc_attr(implode(' ', $classes)); ?>" data-up-popup-id="<?php echo esc_attr($post_id); ?>" style="<?php echo $style; ?>" tabindex="0" role="button" aria-label="<?php echo esc_attr($title ?: 'Play video'); ?>">
            <div class="up-video-card__thumb" style="background-image: url('<?php echo esc_url($thumb); ?>')">
                <?php if ($play_svg): ?>
                <span class="up-video-card__play"><?php echo $play_svg; ?></span>
                <?php endif; ?>
                <?php if ($show_duration): ?>
                <span class="up-video-card__duration"><?php echo esc_html($duration); ?></span>
                <?php endif; ?>
            </div>
            <?php if ($has_meta): ?>
            <div class="up-video-card__meta">
                <?php if ($show_title && $title): ?>
                <span class="up-video-card__title"><?php echo esc_html($title); ?></span>
                <?php endif; ?>
                <?php if ($show_channel): ?>
                <span class="up-video-card__channel"><?php echo esc_html($channel); ?></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
