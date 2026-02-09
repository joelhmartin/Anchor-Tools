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
        if (!$provider || !$id) return ['thumb' => '', 'title' => ''];

        if ($provider === 'youtube') {
            return $this->fetch_youtube_meta($id);
        }
        if ($provider === 'vimeo') {
            return $this->fetch_vimeo_meta($id);
        }

        return ['thumb' => '', 'title' => ''];
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
        ];

        // Try API if key available
        $api_key = $this->get_google_api_key();
        if ($api_key) {
            $url = add_query_arg([
                'part' => 'snippet',
                'id'   => $id,
                'key'  => $api_key,
            ], 'https://www.googleapis.com/youtube/v3/videos');

            $res = wp_remote_get($url, ['timeout' => 10]);
            if (!is_wp_error($res)) {
                $data = json_decode(wp_remote_retrieve_body($res), true);
                if (!empty($data['items'][0]['snippet'])) {
                    $sn = $data['items'][0]['snippet'];
                    $meta['title'] = $sn['title'] ?? '';
                    if (!empty($sn['thumbnails']['high']['url'])) {
                        $meta['thumb'] = $sn['thumbnails']['high']['url'];
                    } elseif (!empty($sn['thumbnails']['medium']['url'])) {
                        $meta['thumb'] = $sn['thumbnails']['medium']['url'];
                    }
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

        $meta = ['thumb' => '', 'title' => ''];

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
            }
        }

        set_transient($cache_key, $meta, 24 * HOUR_IN_SECONDS);
        return $meta;
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
              <select name="up_aspect_ratio">
                <option value="16:9" <?php selected($m['aspect_ratio'], '16:9'); ?>>16:9 (Widescreen)</option>
                <option value="4:3" <?php selected($m['aspect_ratio'], '4:3'); ?>>4:3 (Classic)</option>
                <option value="1:1" <?php selected($m['aspect_ratio'], '1:1'); ?>>1:1 (Square)</option>
                <option value="9:16" <?php selected($m['aspect_ratio'], '9:16'); ?>>9:16 (Portrait)</option>
                <option value="21:9" <?php selected($m['aspect_ratio'], '21:9'); ?>>21:9 (Cinematic)</option>
              </select>
              <p class="description">Aspect ratio for the video card thumbnail displayed via shortcode.</p>
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
            'mode','video_url','video_id','aspect_ratio','autoplay','close_color',
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
            wp_enqueue_style('up-admin', plugins_url('assets/admin.css', __FILE__), [], '1.0.4');
            wp_enqueue_script('up-admin', plugins_url('assets/admin.js', __FILE__), ['jquery','code-editor'], '1.0.5', true);
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

        wp_enqueue_style('up-frontend', plugins_url('assets/frontend.css', __FILE__), [], '1.0.2');
        wp_enqueue_script('up-frontend', plugins_url('assets/frontend.js', __FILE__), [], '1.0.5', true);
        wp_localize_script('up-frontend', 'UP_SNIPPETS', $snippets);
    }

    public function shortcode_render($atts){
        $atts = shortcode_atts(['id' => 0], $atts);
        $post_id = (int)$atts['id'];
        if (!$post_id) return '';
        $m = $this->get_meta($post_id);

        // Video mode: render a clickable video card
        if (in_array($m['mode'], ['video', 'youtube', 'vimeo'], true)) {
            return $this->render_video_card($post_id, $m);
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
    private function render_video_card($post_id, $m) {
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

        // Convert aspect ratio to CSS value
        $ratio_map = [
            '16:9' => '16 / 9',
            '4:3'  => '4 / 3',
            '1:1'  => '1 / 1',
            '9:16' => '9 / 16',
            '21:9' => '21 / 9',
        ];
        $ratio_val = isset($ratio_map[$m['aspect_ratio']]) ? $ratio_map[$m['aspect_ratio']] : '16 / 9';

        // Play button SVG
        $play_svg = '<svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="11" fill="currentColor" opacity="0.85"/><polygon points="10,8 16,12 10,16" fill="#fff"/></svg>';

        ob_start();
        ?>
        <div class="up-video-card" data-up-popup-id="<?php echo esc_attr($post_id); ?>" style="--up-card-ratio: <?php echo esc_attr($ratio_val); ?>" tabindex="0" role="button" aria-label="<?php echo esc_attr($title ?: 'Play video'); ?>">
            <div class="up-video-card__thumb" style="background-image: url('<?php echo esc_url($thumb); ?>')">
                <span class="up-video-card__play"><?php echo $play_svg; ?></span>
            </div>
            <?php if ($title): ?>
            <div class="up-video-card__meta">
                <span class="up-video-card__title"><?php echo esc_html($title); ?></span>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
