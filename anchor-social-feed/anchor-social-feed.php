<?php
/**
 * Anchor Tools module: Anchor Social Feed.
 * CPT-based social feed manager for YouTube, Facebook, Instagram, TikTok, X (Twitter), and Spotify.
 */

if (!defined('ABSPATH')) exit;

class Anchor_Social_Feed_Module {
    const CPT            = 'anchor_social_feed';
    const NONCE          = 'asf_nonce';
    const GLOBAL_OPT     = 'anchor_social_feed_credentials';
    const LEGACY_OPT     = 'anchor_social_feed_options';
    const LEGACY_KEYS    = ['ssfs_options_v1'];
    const MIGRATION_FLAG = 'anchor_social_feed_cpt_migrated';

    public function __construct() {
        add_action('init',                  [$this, 'register_cpt']);
        add_action('add_meta_boxes',        [$this, 'add_metaboxes']);
        add_action('save_post',             [$this, 'save_meta'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'admin_enqueue']);
        add_action('wp_enqueue_scripts',    [$this, 'frontend_enqueue']);
        add_action('admin_menu',            [$this, 'add_credentials_page']);

        add_shortcode('social_feed',        [$this, 'shortcode_handler']);
        add_shortcode('anchor_social_feed', [$this, 'shortcode_handler']);

        add_action('wp_ajax_anchor_social_feed_preview',       [$this, 'ajax_preview']);
        add_action('wp_ajax_anchor_social_feed_fetch_profile', [$this, 'ajax_fetch_profile']);

        add_filter('manage_' . self::CPT . '_posts_columns',       [$this, 'admin_columns']);
        add_action('manage_' . self::CPT . '_posts_custom_column',  [$this, 'admin_column_content'], 10, 2);
    }

    /* ══════════════════════════════════════════════════════════
       CPT Registration + Migration
       ══════════════════════════════════════════════════════════ */

    public function register_cpt() {
        register_post_type(self::CPT, [
            'labels' => [
                'name'               => __('Social Feeds', 'anchor-schema'),
                'singular_name'      => __('Social Feed', 'anchor-schema'),
                'add_new'            => __('Add New Feed', 'anchor-schema'),
                'add_new_item'       => __('Add New Social Feed', 'anchor-schema'),
                'edit_item'          => __('Edit Social Feed', 'anchor-schema'),
                'new_item'           => __('New Social Feed', 'anchor-schema'),
                'view_item'          => __('View Social Feed', 'anchor-schema'),
                'search_items'       => __('Search Social Feeds', 'anchor-schema'),
                'not_found'          => __('No feeds found.', 'anchor-schema'),
                'not_found_in_trash' => __('No feeds found in Trash.', 'anchor-schema'),
                'menu_name'          => __('Social Feeds', 'anchor-schema'),
            ],
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => apply_filters('anchor_social_feed_parent_menu', true),
            'supports'     => ['title'],
            'menu_icon'    => 'dashicons-share',
            'has_archive'  => false,
            'rewrite'      => false,
        ]);

        $this->maybe_migrate_legacy();
    }

    private function maybe_migrate_legacy() {
        if (get_option(self::MIGRATION_FLAG)) return;

        $opts = get_option(self::LEGACY_OPT, []);
        if (empty($opts)) {
            foreach (self::LEGACY_KEYS as $key) {
                $legacy = get_option($key, []);
                if (!empty($legacy)) { $opts = $legacy; break; }
            }
        }
        if (empty($opts)) {
            update_option(self::MIGRATION_FLAG, 1, false);
            return;
        }

        // Extract credentials to global option
        $creds = [];
        $cred_keys = [
            'facebook_app_id', 'facebook_app_secret', 'facebook_page_access_token', 'facebook_page_id',
            'tiktok_client_key', 'tiktok_client_secret', 'tiktok_access_token',
        ];
        foreach ($cred_keys as $k) {
            if (!empty($opts[$k])) $creds[$k] = $opts[$k];
        }
        if (!empty($creds)) {
            update_option(self::GLOBAL_OPT, $creds, false);
        }

        // Create CPT posts for each configured platform
        $platform_map = [
            'youtube'   => ['check' => 'youtube_channel_id',  'meta' => ['asf_yt_channel_id' => 'youtube_channel_id']],
            'facebook'  => ['check' => 'facebook_page_id',    'meta' => ['asf_fb_page_id' => 'facebook_page_id', 'asf_fb_page_url' => 'facebook_page_url']],
            'instagram' => ['check' => 'instagram_username',  'meta' => ['asf_ig_username' => 'instagram_username']],
            'tiktok'    => ['check' => 'tiktok_username',     'meta' => ['asf_tt_username' => 'tiktok_username']],
            'twitter'   => ['check' => 'twitter_username',    'meta' => ['asf_tw_username' => 'twitter_username']],
            'spotify'   => ['check' => 'spotify_artist_id',   'meta' => ['asf_sp_artist_id' => 'spotify_artist_id']],
        ];

        $layout = !empty($opts['layout']) ? $opts['layout'] : 'grid';

        foreach ($platform_map as $platform => $cfg) {
            $val = trim($opts[$cfg['check']] ?? '');
            if ($val === '') continue;

            $post_id = wp_insert_post([
                'post_type'   => self::CPT,
                'post_status' => 'publish',
                'post_title'  => ucfirst($platform) . ' Feed',
            ]);
            if (!$post_id || is_wp_error($post_id)) continue;

            update_post_meta($post_id, 'asf_platform', $platform);
            update_post_meta($post_id, 'asf_layout', $layout);
            foreach ($cfg['meta'] as $meta_key => $opt_key) {
                $v = trim($opts[$opt_key] ?? '');
                if ($v !== '') update_post_meta($post_id, $meta_key, $v);
            }
        }

        update_option(self::MIGRATION_FLAG, 1, false);
    }

    /* ══════════════════════════════════════════════════════════
       Global Credentials Page
       ══════════════════════════════════════════════════════════ */

    public function add_credentials_page() {
        add_submenu_page(
            'edit.php?post_type=' . self::CPT,
            __('API Credentials', 'anchor-schema'),
            __('API Credentials', 'anchor-schema'),
            'manage_options',
            'asf-credentials',
            [$this, 'render_credentials_page']
        );
    }

    public function render_credentials_page() {
        if (!current_user_can('manage_options')) return;

        if (isset($_POST['asf_creds_nonce']) && wp_verify_nonce($_POST['asf_creds_nonce'], 'asf_save_creds')) {
            $creds = [];
            $fields = [
                'facebook_app_id', 'facebook_app_secret', 'facebook_page_access_token', 'facebook_page_id',
                'tiktok_client_key', 'tiktok_client_secret', 'tiktok_access_token',
            ];
            foreach ($fields as $f) {
                $creds[$f] = sanitize_text_field($_POST['asf_cred_' . $f] ?? '');
            }
            update_option(self::GLOBAL_OPT, $creds, false);
            echo '<div class="notice notice-success"><p>' . esc_html__('Credentials saved.', 'anchor-schema') . '</p></div>';
        }

        $creds = get_option(self::GLOBAL_OPT, []);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Social Feed API Credentials', 'anchor-schema'); ?></h1>
            <p><?php esc_html_e('These credentials are shared across all Social Feed posts. YouTube API key is managed in the main Anchor Tools settings.', 'anchor-schema'); ?></p>
            <form method="post">
                <?php wp_nonce_field('asf_save_creds', 'asf_creds_nonce'); ?>
                <h2><?php esc_html_e('Facebook & Instagram Graph API', 'anchor-schema'); ?></h2>
                <p class="description"><?php esc_html_e('Provide your App credentials and a User Access Token. The token is automatically exchanged for a long-lived Page token. Instagram is resolved from the linked Page.', 'anchor-schema'); ?></p>
                <table class="form-table"><tbody>
                    <?php
                    $fb_fields = [
                        'facebook_app_id'            => ['label' => 'Facebook App ID',   'type' => 'password'],
                        'facebook_app_secret'        => ['label' => 'Facebook App Secret','type' => 'password'],
                        'facebook_page_access_token' => ['label' => 'Page Access Token',  'type' => 'password'],
                        'facebook_page_id'           => ['label' => 'Facebook Page ID',   'type' => 'text'],
                    ];
                    foreach ($fb_fields as $key => $meta) :
                    ?>
                    <tr>
                        <th scope="row"><label for="asf_cred_<?php echo esc_attr($key); ?>"><?php echo esc_html($meta['label']); ?></label></th>
                        <td><input type="<?php echo esc_attr($meta['type']); ?>" id="asf_cred_<?php echo esc_attr($key); ?>" name="asf_cred_<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($creds[$key] ?? ''); ?>" class="regular-text" autocomplete="off" /></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody></table>

                <h2><?php esc_html_e('TikTok Display API', 'anchor-schema'); ?></h2>
                <table class="form-table"><tbody>
                    <?php
                    $tt_fields = [
                        'tiktok_client_key'    => ['label' => 'TikTok Client Key',   'type' => 'password'],
                        'tiktok_client_secret' => ['label' => 'TikTok Client Secret','type' => 'password'],
                        'tiktok_access_token'  => ['label' => 'TikTok Access Token', 'type' => 'password'],
                    ];
                    foreach ($tt_fields as $key => $meta) :
                    ?>
                    <tr>
                        <th scope="row"><label for="asf_cred_<?php echo esc_attr($key); ?>"><?php echo esc_html($meta['label']); ?></label></th>
                        <td><input type="<?php echo esc_attr($meta['type']); ?>" id="asf_cred_<?php echo esc_attr($key); ?>" name="asf_cred_<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($creds[$key] ?? ''); ?>" class="regular-text" autocomplete="off" /></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody></table>
                <?php submit_button(__('Save Credentials', 'anchor-schema')); ?>
            </form>
        </div>
        <?php
    }

    /* ══════════════════════════════════════════════════════════
       Setting Definitions
       ══════════════════════════════════════════════════════════ */

    private function get_setting_defs() {
        return [
            'asf_layout' => [
                'type' => 'select', 'label' => 'Layout', 'default' => 'grid',
                'options' => ['grid' => 'Grid', 'stack' => 'Stack', 'carousel' => 'Carousel', 'hover_overlay' => 'Hover Overlay'],
            ],
            'asf_theme' => [
                'type' => 'select', 'label' => 'Theme', 'default' => 'dark',
                'options' => ['dark' => 'Dark', 'light' => 'Light'],
            ],
            'asf_gradient' => [
                'type' => 'text', 'label' => 'Gradient', 'default' => '',
                'description' => 'Two colors (e.g. #1a1a2e,#16213e), one color, or "none".',
            ],
            'asf_columns_desktop' => [
                'type' => 'number', 'label' => 'Desktop Columns', 'default' => 4,
                'min' => 1, 'max' => 8, 'show_for_layout' => 'grid,hover_overlay',
            ],
            'asf_columns_tablet' => [
                'type' => 'number', 'label' => 'Tablet Columns', 'default' => 3,
                'min' => 1, 'max' => 6, 'show_for_layout' => 'grid,hover_overlay',
            ],
            'asf_columns_mobile' => [
                'type' => 'number', 'label' => 'Mobile Columns', 'default' => 2,
                'min' => 1, 'max' => 4, 'show_for_layout' => 'grid,hover_overlay',
            ],
            'asf_gap' => [
                'type' => 'number', 'label' => 'Gap (px)', 'default' => 16,
                'min' => 0, 'max' => 60, 'step' => 4,
            ],
            'asf_item_limit' => [
                'type' => 'number', 'label' => 'Item Limit', 'default' => 12,
                'min' => 1, 'max' => 100,
            ],
            'asf_show_header' => [
                'type' => 'checkbox', 'label' => 'Show Profile Header', 'default' => 1,
            ],
            'asf_custom_css' => [
                'type' => 'textarea', 'label' => 'Custom CSS', 'default' => '',
            ],
        ];
    }

    private function get_youtube_setting_defs() {
        return [
            'asf_yt_type' => [
                'type' => 'select', 'label' => 'Video Type', 'default' => 'videos',
                'options' => ['videos' => 'Videos', 'shorts' => 'Shorts', 'all' => 'All'],
            ],
            'asf_yt_exclude_hashtags' => [
                'type' => 'text', 'label' => 'Exclude Hashtags', 'default' => 'short,shorts,testimonial',
                'description' => 'Comma-separated. Leave empty to disable.',
            ],
            'asf_yt_include_hashtags' => [
                'type' => 'text', 'label' => 'Include Hashtags', 'default' => '',
                'description' => 'Only show videos matching these hashtags.',
            ],
            'asf_yt_min_seconds' => ['type' => 'number', 'label' => 'Min Duration (sec)', 'default' => '', 'min' => 0],
            'asf_yt_max_seconds' => ['type' => 'number', 'label' => 'Max Duration (sec)', 'default' => '', 'min' => 0],
            'asf_yt_since' => ['type' => 'text', 'label' => 'Since Date', 'default' => '', 'description' => 'YYYY-MM-DD'],
            'asf_yt_until' => ['type' => 'text', 'label' => 'Until Date', 'default' => '', 'description' => 'YYYY-MM-DD'],
            'asf_yt_max_age_days' => ['type' => 'number', 'label' => 'Max Age (days)', 'default' => '', 'min' => 0],
            'asf_yt_fetch_pages' => ['type' => 'number', 'label' => 'Fetch Pages', 'default' => 10, 'min' => 1, 'max' => 20],
        ];
    }

    /* ══════════════════════════════════════════════════════════
       Metaboxes
       ══════════════════════════════════════════════════════════ */

    public function add_metaboxes() {
        add_meta_box('asf_source',   __('Feed Source', 'anchor-schema'),     [$this, 'metabox_source'],   self::CPT, 'normal', 'high');
        add_meta_box('asf_settings', __('Display Settings', 'anchor-schema'),[$this, 'metabox_settings'], self::CPT, 'side',   'default');
        add_meta_box('asf_preview',  __('Live Preview', 'anchor-schema'),    [$this, 'metabox_preview'],  self::CPT, 'normal', 'default');
    }

    public function metabox_source($post) {
        wp_nonce_field('asf_save', self::NONCE);
        $platform = get_post_meta($post->ID, 'asf_platform', true) ?: '';
        $platforms = [
            'youtube'   => 'YouTube',
            'facebook'  => 'Facebook',
            'instagram' => 'Instagram',
            'tiktok'    => 'TikTok',
            'twitter'   => 'X (Twitter)',
            'spotify'   => 'Spotify',
        ];

        echo '<div class="asf-platform-list">';
        foreach ($platforms as $val => $label) {
            printf(
                '<label><input type="radio" name="asf_platform" value="%s" %s /><span>%s</span></label>',
                esc_attr($val), checked($platform, $val, false), esc_html($label)
            );
        }
        echo '</div>';

        // YouTube fields
        echo '<div class="asf-platform-fields" data-platform="youtube">';
        $this->render_meta_field($post->ID, 'asf_yt_channel_id', 'Channel ID / @handle / URL', 'text');
        foreach ($this->get_youtube_setting_defs() as $key => $def) {
            $this->render_meta_field_from_def($post->ID, $key, $def);
        }
        echo '</div>';

        // Facebook fields
        echo '<div class="asf-platform-fields" data-platform="facebook">';
        $this->render_meta_field($post->ID, 'asf_fb_page_id', 'Page ID (numeric)', 'text');
        $this->render_meta_field($post->ID, 'asf_fb_page_url', 'Page URL (legacy fallback)', 'url');
        echo '</div>';

        // Instagram fields
        echo '<div class="asf-platform-fields" data-platform="instagram">';
        $this->render_meta_field($post->ID, 'asf_ig_username', 'Instagram Username', 'text');
        echo '<p class="description">' . esc_html__('Instagram is resolved from your Facebook App credentials. The username is for display only.', 'anchor-schema') . '</p>';
        echo '</div>';

        // TikTok fields
        echo '<div class="asf-platform-fields" data-platform="tiktok">';
        $this->render_meta_field($post->ID, 'asf_tt_username', 'TikTok Username', 'text');
        echo '</div>';

        // Twitter fields
        echo '<div class="asf-platform-fields" data-platform="twitter">';
        $this->render_meta_field($post->ID, 'asf_tw_username', 'X / Twitter Username', 'text');
        echo '</div>';

        // Spotify fields
        echo '<div class="asf-platform-fields" data-platform="spotify">';
        $this->render_meta_field($post->ID, 'asf_sp_artist_id', 'Spotify Artist ID', 'text');
        echo '</div>';

        // Profile header overrides
        echo '<div class="asf-profile-section">';
        echo '<h4>' . esc_html__('Profile Header Overrides', 'anchor-schema') . '</h4>';
        echo '<p class="description">' . esc_html__('Override auto-detected profile data. Leave blank to use API data.', 'anchor-schema') . '</p>';
        echo '<div class="asf-profile-grid">';
        $this->render_meta_field($post->ID, 'asf_profile_avatar_url',    'Avatar URL', 'url');
        $this->render_meta_field($post->ID, 'asf_profile_display_name',  'Display Name', 'text');
        $this->render_meta_field($post->ID, 'asf_profile_handle',        'Handle', 'text');
        $this->render_meta_field($post->ID, 'asf_profile_url',           'Profile URL', 'url');
        $this->render_meta_field($post->ID, 'asf_profile_followers',     'Followers', 'text');
        $this->render_meta_field($post->ID, 'asf_profile_posts',         'Posts', 'text');
        $this->render_meta_field($post->ID, 'asf_profile_following',     'Following', 'text');
        $this->render_meta_field($post->ID, 'asf_follow_button_text',    'Follow Button Text', 'text', 'Follow');
        echo '</div>';
        echo '<div class="asf-fetch-profile-btn"><button type="button" class="button">' . esc_html__('Fetch Profile from API', 'anchor-schema') . '</button><span class="spinner"></span></div>';
        echo '</div>';
    }

    private function render_meta_field($post_id, $name, $label, $type = 'text', $default = '') {
        $val = get_post_meta($post_id, $name, true);
        if ($val === '' && $default !== '') $val = $default;
        printf(
            '<p><label>%s</label><br/><input type="%s" name="%s" value="%s" class="widefat" /></p>',
            esc_html($label), esc_attr($type), esc_attr($name), esc_attr($val)
        );
    }

    private function render_meta_field_from_def($post_id, $key, $def) {
        $val = get_post_meta($post_id, $key, true);
        if ($val === '' && isset($def['default'])) $val = $def['default'];

        $attrs = '';
        if (!empty($def['show_for_layout']))   $attrs .= ' data-show-for-layout="' . esc_attr($def['show_for_layout']) . '"';
        if (!empty($def['show_for_platform'])) $attrs .= ' data-show-for-platform="' . esc_attr($def['show_for_platform']) . '"';

        echo '<div class="asf-setting-row"' . $attrs . '>';
        echo '<label>' . esc_html($def['label']) . '</label> ';

        switch ($def['type']) {
            case 'select':
                echo '<select name="' . esc_attr($key) . '">';
                foreach ($def['options'] as $k => $l) {
                    printf('<option value="%s" %s>%s</option>', esc_attr($k), selected($val, $k, false), esc_html($l));
                }
                echo '</select>';
                break;
            case 'number':
                $extra = '';
                if (isset($def['min']))  $extra .= ' min="' . intval($def['min']) . '"';
                if (isset($def['max']))  $extra .= ' max="' . intval($def['max']) . '"';
                if (isset($def['step'])) $extra .= ' step="' . intval($def['step']) . '"';
                echo '<input type="number" name="' . esc_attr($key) . '" value="' . esc_attr($val) . '" class="small-text"' . $extra . ' />';
                break;
            case 'checkbox':
                echo '<input type="checkbox" name="' . esc_attr($key) . '" value="1" ' . checked($val, 1, false) . ' />';
                break;
            case 'textarea':
                echo '<textarea name="' . esc_attr($key) . '" rows="3" class="widefat">' . esc_textarea($val) . '</textarea>';
                break;
            default:
                echo '<input type="text" name="' . esc_attr($key) . '" value="' . esc_attr($val) . '" class="regular-text" />';
        }
        if (!empty($def['description'])) {
            echo '<p class="description">' . esc_html($def['description']) . '</p>';
        }
        echo '</div>';
    }

    public function metabox_settings($post) {
        $defs = $this->get_setting_defs();
        foreach ($defs as $key => $def) {
            $this->render_meta_field_from_def($post->ID, $key, $def);
        }
    }

    public function metabox_preview($post) {
        echo '<div id="asf-preview-wrap"><p class="asf-preview-note">' . esc_html__('Save the post or change settings to see a preview.', 'anchor-schema') . '</p></div>';
    }

    /* ══════════════════════════════════════════════════════════
       Save Meta
       ══════════════════════════════════════════════════════════ */

    public function save_meta($post_id, $post) {
        if (!isset($_POST[self::NONCE]) || !wp_verify_nonce($_POST[self::NONCE], 'asf_save')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if ($post->post_type !== self::CPT) return;
        if (!current_user_can('edit_post', $post_id)) return;

        // Platform
        $platform = sanitize_text_field($_POST['asf_platform'] ?? '');
        $valid_platforms = ['youtube', 'facebook', 'instagram', 'tiktok', 'twitter', 'spotify'];
        if (in_array($platform, $valid_platforms, true)) {
            update_post_meta($post_id, 'asf_platform', $platform);
        }

        // Platform-specific fields
        $text_fields = [
            'asf_yt_channel_id', 'asf_yt_exclude_hashtags', 'asf_yt_include_hashtags',
            'asf_yt_since', 'asf_yt_until',
            'asf_fb_page_id', 'asf_fb_page_url',
            'asf_ig_username', 'asf_tt_username', 'asf_tw_username', 'asf_sp_artist_id',
            'asf_profile_display_name', 'asf_profile_handle',
            'asf_profile_followers', 'asf_profile_posts', 'asf_profile_following',
            'asf_follow_button_text', 'asf_gradient',
        ];
        foreach ($text_fields as $k) {
            if (isset($_POST[$k])) {
                update_post_meta($post_id, $k, sanitize_text_field($_POST[$k]));
            }
        }

        // URL fields
        $url_fields = ['asf_profile_avatar_url', 'asf_profile_url'];
        foreach ($url_fields as $k) {
            if (isset($_POST[$k])) {
                update_post_meta($post_id, $k, esc_url_raw($_POST[$k]));
            }
        }

        // Setting defs (select, number, checkbox, textarea)
        $all_defs = array_merge($this->get_setting_defs(), $this->get_youtube_setting_defs());
        foreach ($all_defs as $key => $def) {
            if ($key === 'asf_gradient') continue; // already handled above
            switch ($def['type']) {
                case 'select':
                    $val = sanitize_text_field($_POST[$key] ?? '');
                    if (isset($def['options'][$val])) {
                        update_post_meta($post_id, $key, $val);
                    }
                    break;
                case 'number':
                    $val = $_POST[$key] ?? '';
                    if ($val !== '') {
                        update_post_meta($post_id, $key, intval($val));
                    } else {
                        update_post_meta($post_id, $key, '');
                    }
                    break;
                case 'checkbox':
                    update_post_meta($post_id, $key, !empty($_POST[$key]) ? 1 : 0);
                    break;
                case 'textarea':
                    if (isset($_POST[$key])) {
                        update_post_meta($post_id, $key, wp_strip_all_tags($_POST[$key]));
                    }
                    break;
            }
        }
    }

    /* ══════════════════════════════════════════════════════════
       Admin Columns
       ══════════════════════════════════════════════════════════ */

    public function admin_columns($columns) {
        $new = [];
        foreach ($columns as $k => $v) {
            $new[$k] = $v;
            if ($k === 'title') {
                $new['asf_shortcode'] = __('Shortcode', 'anchor-schema');
                $new['asf_platform']  = __('Platform', 'anchor-schema');
                $new['asf_layout']    = __('Layout', 'anchor-schema');
            }
        }
        return $new;
    }

    public function admin_column_content($column, $post_id) {
        switch ($column) {
            case 'asf_shortcode':
                echo '<code>[anchor_social_feed id="' . intval($post_id) . '"]</code>';
                break;
            case 'asf_platform':
                echo esc_html(ucfirst(get_post_meta($post_id, 'asf_platform', true)));
                break;
            case 'asf_layout':
                echo esc_html(ucfirst(str_replace('_', ' ', get_post_meta($post_id, 'asf_layout', true) ?: 'grid')));
                break;
        }
    }

    /* ══════════════════════════════════════════════════════════
       Admin Enqueue
       ══════════════════════════════════════════════════════════ */

    public function admin_enqueue($hook) {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) return;
        if (get_post_type() !== self::CPT) return;

        wp_enqueue_style('asf-admin', ANCHOR_TOOLS_PLUGIN_URL . 'anchor-social-feed/assets/admin.css', [], '1.0.0');
        wp_enqueue_script('asf-admin', ANCHOR_TOOLS_PLUGIN_URL . 'anchor-social-feed/assets/admin.js', ['jquery'], '1.0.0', true);
        wp_localize_script('asf-admin', 'ASF', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('asf_ajax'),
            'postId'  => get_the_ID(),
        ]);
    }

    /* ══════════════════════════════════════════════════════════
       Frontend Enqueue
       ══════════════════════════════════════════════════════════ */

    public function frontend_enqueue() {
        wp_enqueue_style('asf-front', ANCHOR_TOOLS_PLUGIN_URL . 'anchor-social-feed/assets/anchor-social-feed.css', [], '1.0.0');
        wp_enqueue_script('asf-front', ANCHOR_TOOLS_PLUGIN_URL . 'anchor-social-feed/assets/anchor-social-feed.js', [], '1.0.0', true);
    }

    /* ══════════════════════════════════════════════════════════
       Helpers
       ══════════════════════════════════════════════════════════ */

    private function get_google_api_key() {
        $global = get_option(Anchor_Schema_Admin::OPTION_KEY, []);
        return trim($global['google_api_key'] ?? '');
    }

    private function get_credentials() {
        return get_option(self::GLOBAL_OPT, []);
    }

    /**
     * Build the effective options array for a CPT post, merging global creds + per-post meta.
     */
    private function build_opts_from_post($post_id) {
        $creds = $this->get_credentials();
        $meta_keys = [
            'asf_platform', 'asf_layout', 'asf_theme', 'asf_gradient',
            'asf_columns_desktop', 'asf_columns_tablet', 'asf_columns_mobile',
            'asf_gap', 'asf_item_limit', 'asf_show_header', 'asf_custom_css',
            'asf_yt_channel_id', 'asf_yt_type', 'asf_yt_exclude_hashtags', 'asf_yt_include_hashtags',
            'asf_yt_min_seconds', 'asf_yt_max_seconds', 'asf_yt_since', 'asf_yt_until',
            'asf_yt_max_age_days', 'asf_yt_fetch_pages',
            'asf_fb_page_id', 'asf_fb_page_url',
            'asf_ig_username', 'asf_tt_username', 'asf_tw_username', 'asf_sp_artist_id',
            'asf_profile_avatar_url', 'asf_profile_display_name', 'asf_profile_handle',
            'asf_profile_url', 'asf_profile_followers', 'asf_profile_posts',
            'asf_profile_following', 'asf_follow_button_text',
        ];
        $settings = [];
        foreach ($meta_keys as $k) {
            $settings[$k] = get_post_meta($post_id, $k, true);
        }

        // Map to legacy-compatible opts structure for API methods
        return [
            'youtube_channel_id'         => $settings['asf_yt_channel_id'] ?? '',
            'facebook_page_url'          => $settings['asf_fb_page_url'] ?? '',
            'instagram_username'         => $settings['asf_ig_username'] ?? '',
            'twitter_username'           => $settings['asf_tw_username'] ?? '',
            'spotify_artist_id'          => $settings['asf_sp_artist_id'] ?? '',
            'tiktok_username'            => $settings['asf_tt_username'] ?? '',
            'layout'                     => $settings['asf_layout'] ?: 'grid',
            'facebook_app_id'            => $creds['facebook_app_id'] ?? '',
            'facebook_app_secret'        => $creds['facebook_app_secret'] ?? '',
            'facebook_page_access_token' => $creds['facebook_page_access_token'] ?? '',
            'facebook_page_id'           => $settings['asf_fb_page_id'] ?: ($creds['facebook_page_id'] ?? ''),
            'tiktok_client_key'          => $creds['tiktok_client_key'] ?? '',
            'tiktok_client_secret'       => $creds['tiktok_client_secret'] ?? '',
            'tiktok_access_token'        => $creds['tiktok_access_token'] ?? '',
            '_settings'                  => $settings,
        ];
    }

    /**
     * Build shortcode-compatible atts from post meta.
     */
    private function build_atts_from_post($post_id, $overrides = []) {
        $s = [];
        $meta_keys = [
            'asf_layout', 'asf_item_limit', 'asf_yt_type', 'asf_yt_exclude_hashtags',
            'asf_yt_include_hashtags', 'asf_yt_min_seconds', 'asf_yt_max_seconds',
            'asf_yt_since', 'asf_yt_until', 'asf_yt_max_age_days', 'asf_yt_fetch_pages',
            'asf_theme', 'asf_gradient', 'asf_show_header', 'asf_columns_desktop',
            'asf_columns_tablet', 'asf_columns_mobile', 'asf_gap',
        ];
        foreach ($meta_keys as $k) {
            $s[$k] = get_post_meta($post_id, $k, true);
        }

        $atts = [
            'layout'             => $s['asf_layout'] ?: 'grid',
            'youtube_api'        => 'auto',
            'youtube_limit'      => '',
            'youtube_type'       => $s['asf_yt_type'] ?: 'videos',
            'exclude_hashtags'   => $s['asf_yt_exclude_hashtags'] !== '' ? $s['asf_yt_exclude_hashtags'] : 'short,shorts,testimonial',
            'include_hashtags'   => $s['asf_yt_include_hashtags'] ?? '',
            'min_seconds'        => $s['asf_yt_min_seconds'] ?? '',
            'max_seconds'        => $s['asf_yt_max_seconds'] ?? '',
            'since'              => $s['asf_yt_since'] ?? '',
            'until'              => $s['asf_yt_until'] ?? '',
            'max_age_days'       => $s['asf_yt_max_age_days'] ?? '',
            'youtube_fetch_pages'=> $s['asf_yt_fetch_pages'] ?: 10,
            'facebook_limit'     => $s['asf_item_limit'] ?: 12,
            'instagram_limit'    => $s['asf_item_limit'] ?: 12,
            'tiktok_limit'       => $s['asf_item_limit'] ?: 12,
            'youtube_limit'      => $s['asf_item_limit'] ?: '',
            'show_title'         => 'yes',
            'gradient'           => $s['asf_gradient'] ?? '',
            'theme'              => $s['asf_theme'] ?: 'dark',
            'columns_desktop'    => $s['asf_columns_desktop'] ?: 4,
            'columns_tablet'     => $s['asf_columns_tablet'] ?: 3,
            'columns_mobile'     => $s['asf_columns_mobile'] ?: 2,
            'gap'                => $s['asf_gap'] !== '' ? $s['asf_gap'] : 16,
        ];

        return array_merge($atts, $overrides);
    }

    /* ══════════════════════════════════════════════════════════
       Shortcode Handler
       ══════════════════════════════════════════════════════════ */

    public function shortcode_handler($atts = [], $content = null) {
        $atts = shortcode_atts([
            'id'                 => '',
            'slug'               => '',
            'platform'           => '',
            'platforms'          => '',
            'layout'             => '',
            'youtube_api'        => 'auto',
            'youtube_limit'      => '',
            'youtube_type'       => '',
            'exclude_hashtags'   => '',
            'include_hashtags'   => '',
            'min_seconds'        => '',
            'max_seconds'        => '',
            'since'              => '',
            'until'              => '',
            'max_age_days'       => '',
            'youtube_fetch_pages'=> '',
            'facebook_limit'     => '',
            'instagram_limit'    => '',
            'tiktok_limit'       => '',
            'show_title'         => '',
            'gradient'           => '',
            'theme'              => '',
        ], $atts, 'anchor_social_feed');

        // ── Route 1: Specific CPT post by ID or slug ──
        if (!empty($atts['id']) || !empty($atts['slug'])) {
            $post_id = 0;
            if (!empty($atts['id'])) {
                $post_id = intval($atts['id']);
            } elseif (!empty($atts['slug'])) {
                $found = get_posts(['post_type' => self::CPT, 'name' => sanitize_title($atts['slug']), 'posts_per_page' => 1, 'fields' => 'ids']);
                $post_id = $found ? $found[0] : 0;
            }
            if (!$post_id || get_post_type($post_id) !== self::CPT) {
                return '<p class="ssfs-note">Social feed not found.</p>';
            }
            // Build overrides from shortcode atts (non-empty only)
            $overrides = array_filter($atts, function($v, $k) {
                return $v !== '' && !in_array($k, ['id', 'slug'], true);
            }, ARRAY_FILTER_USE_BOTH);
            return $this->render_feed_post($post_id, $overrides);
        }

        // ── Route 2: Legacy mode (platform/platforms attributes, no id) ──
        if (!empty($atts['platform']) || !empty($atts['platforms'])) {
            return $this->render_legacy_mode($atts);
        }

        // ── Route 3: No attributes — render all published CPT posts ──
        $posts = get_posts(['post_type' => self::CPT, 'posts_per_page' => 50, 'orderby' => 'menu_order title', 'order' => 'ASC']);
        if (empty($posts)) {
            return '<div class="ssfs-wrap"><p>No social feeds configured yet.</p></div>';
        }

        $output = '';
        foreach ($posts as $p) {
            $output .= $this->render_feed_post($p->ID, array_filter($atts, function($v) { return $v !== ''; }));
        }
        return $output;
    }

    /**
     * Render a single CPT feed post.
     */
    private function render_feed_post($post_id, $overrides = []) {
        $opts = $this->build_opts_from_post($post_id);
        $atts = $this->build_atts_from_post($post_id, $overrides);
        $settings = $opts['_settings'];
        $platform = $settings['asf_platform'] ?? '';

        if (!$platform) return '';

        $layout = in_array($atts['layout'], ['grid', 'stack', 'carousel', 'hover_overlay'], true) ? $atts['layout'] : 'grid';

        // Theme
        $theme = strtolower(trim($atts['theme']));
        if (!in_array($theme, ['dark', 'light'], true)) $theme = 'dark';

        // Gradient
        $gradient_style = '';
        $grad_val = trim($atts['gradient']);
        if (strtolower($grad_val) === 'none') {
            $gradient_style = 'background:none;';
            if ($theme === 'dark') $theme = 'light';
        } elseif ($grad_val !== '') {
            $colors = array_map('trim', explode(',', $grad_val));
            if (count($colors) >= 2) {
                $gradient_style = 'background:linear-gradient(135deg,' . esc_attr($colors[0]) . ' 0%,' . esc_attr($colors[1]) . ' 100%);';
            } elseif (count($colors) === 1) {
                $gradient_style = 'background:' . esc_attr($colors[0]) . ';';
            }
        }

        $show_title = true;
        if (isset($atts['show_title']) && in_array(strtolower($atts['show_title']), ['no','false','0','off'], true)) {
            $show_title = false;
        }

        // Render platform content
        $embed = '';
        $title = '';
        $handle = '';
        $profile_url = '';

        switch ($platform) {
            case 'youtube':
                $embed = $this->render_youtube_api_feed($opts, $atts);
                $h = trim($opts['youtube_channel_id']);
                if ($h) {
                    if (stripos($h, 'youtube.com') !== false) { $profile_url = $h; }
                    elseif ($h[0] === '@') { $profile_url = 'https://www.youtube.com/' . $h; }
                    elseif (strpos($h, 'UC') === 0) { $profile_url = 'https://www.youtube.com/channel/' . $h; }
                    else { $profile_url = 'https://www.youtube.com/@' . $h; }
                }
                $title = 'YouTube';
                $handle = $h;
                break;
            case 'facebook':
                $embed = $this->render_facebook_feed($opts, $atts);
                $fb_url = trim($opts['facebook_page_url']);
                if (!$fb_url && trim($opts['facebook_page_id'])) {
                    $fb_url = 'https://www.facebook.com/' . trim($opts['facebook_page_id']);
                }
                $title = 'Facebook';
                $handle = $fb_url ? basename(rtrim($fb_url, '/')) : '';
                $profile_url = $fb_url;
                break;
            case 'instagram':
                $embed = $this->render_instagram_feed($opts, $atts);
                $ig_user = trim($opts['instagram_username']);
                $title = 'Instagram';
                $handle = $ig_user ? '@' . $ig_user : '';
                $profile_url = $ig_user ? 'https://www.instagram.com/' . rawurlencode($ig_user) . '/' : '';
                break;
            case 'tiktok':
                $embed = $this->render_tiktok_feed($opts, $atts);
                $tt_user = trim($opts['tiktok_username']);
                $title = 'TikTok';
                $handle = $tt_user ? '@' . $tt_user : '';
                $profile_url = $tt_user ? 'https://www.tiktok.com/@' . rawurlencode($tt_user) : '';
                break;
            case 'twitter':
                $embed = $this->embed_twitter($opts['twitter_username']);
                $tw_user = trim($opts['twitter_username']);
                $title = 'X';
                $handle = $tw_user ? '@' . $tw_user : '';
                $profile_url = $tw_user ? 'https://x.com/' . rawurlencode($tw_user) : '';
                break;
            case 'spotify':
                $embed = $this->embed_spotify($opts['spotify_artist_id']);
                $sp_id = trim($opts['spotify_artist_id']);
                $title = 'Spotify';
                $profile_url = $sp_id ? 'https://open.spotify.com/artist/' . rawurlencode($sp_id) : '';
                break;
        }

        if (!$embed) return '';

        // Profile header
        $header_html = '';
        $show_header = intval($settings['asf_show_header'] ?? 1);
        if ($show_header) {
            $header_html = $this->render_profile_header($post_id, $platform, $settings, $theme);
        }

        // Custom CSS
        $custom_css = trim($settings['asf_custom_css'] ?? '');
        $css_block = $custom_css ? '<style>' . wp_strip_all_tags($custom_css) . '</style>' : '';

        // Wrap in card
        $out = $this->card($title, $header_html . $embed, $show_title, $handle, $profile_url, $gradient_style, $theme);

        // Footer scripts
        if (strpos($out, 'twitter-timeline') !== false) {
            add_action('wp_footer', function() {
                echo '<script async src="https://platform.twitter.com/widgets.js" charset="utf-8"></script>';
            });
        }

        return $css_block . $out;
    }

    /**
     * Legacy multi-platform mode — reads from old option.
     */
    private function render_legacy_mode($atts) {
        $opts = $this->get_legacy_options();
        $layout = in_array($atts['layout'] ?: ($opts['layout'] ?? 'grid'), ['grid', 'stack', 'carousel'], true) ? ($atts['layout'] ?: $opts['layout']) : 'grid';
        if (empty($atts['layout'])) $atts['layout'] = $layout;

        // Fill defaults for legacy atts
        $defaults = [
            'youtube_api' => 'auto', 'youtube_limit' => '', 'youtube_type' => 'videos',
            'exclude_hashtags' => 'short,shorts,testimonial', 'include_hashtags' => '',
            'min_seconds' => '', 'max_seconds' => '', 'since' => '', 'until' => '',
            'max_age_days' => '', 'youtube_fetch_pages' => 10,
            'facebook_limit' => 10, 'instagram_limit' => 12, 'tiktok_limit' => 10,
            'show_title' => 'yes', 'gradient' => '', 'theme' => 'dark',
        ];
        foreach ($defaults as $k => $v) {
            if ($atts[$k] === '') $atts[$k] = $v;
        }

        $targets = [];
        if (!empty($atts['platform'])) {
            $targets = [strtolower($atts['platform'])];
        } elseif (!empty($atts['platforms'])) {
            $targets = array_filter(array_map('trim', explode(',', strtolower($atts['platforms']))));
        }

        $show_title = in_array(strtolower($atts['show_title']), ['yes','true','1','on'], true);
        $theme = strtolower(trim($atts['theme']));
        if (!in_array($theme, ['dark', 'light'], true)) $theme = 'dark';

        $gradient_style = '';
        $grad_val = trim($atts['gradient']);
        if (strtolower($grad_val) === 'none') {
            $gradient_style = 'background:none;';
            if ($theme === 'dark') $theme = 'light';
        } elseif ($grad_val !== '') {
            $colors = array_map('trim', explode(',', $grad_val));
            if (count($colors) >= 2) {
                $gradient_style = 'background:linear-gradient(135deg,' . esc_attr($colors[0]) . ' 0%,' . esc_attr($colors[1]) . ' 100%);';
            } elseif (count($colors) === 1) {
                $gradient_style = 'background:' . esc_attr($colors[0]) . ';';
            }
        }

        $html_parts = [];
        foreach ($targets as $p) {
            switch ($p) {
                case 'youtube':
                    $embed = $this->render_youtube_api_feed($opts, $atts);
                    $h = trim($opts['youtube_channel_id'] ?? '');
                    $yt_url = '';
                    if ($h) {
                        if (stripos($h, 'youtube.com') !== false) { $yt_url = $h; }
                        elseif ($h[0] === '@') { $yt_url = 'https://www.youtube.com/' . $h; }
                        elseif (strpos($h, 'UC') === 0) { $yt_url = 'https://www.youtube.com/channel/' . $h; }
                        else { $yt_url = 'https://www.youtube.com/@' . $h; }
                    }
                    if ($embed) $html_parts[] = $this->card('YouTube', $embed, $show_title, $h, $yt_url, $gradient_style, $theme);
                    break;
                case 'facebook':
                    $embed = $this->render_facebook_feed($opts, $atts);
                    $fb_url = trim($opts['facebook_page_url'] ?? '');
                    if (!$fb_url && trim($opts['facebook_page_id'] ?? '')) $fb_url = 'https://www.facebook.com/' . trim($opts['facebook_page_id']);
                    if ($embed) $html_parts[] = $this->card('Facebook', $embed, $show_title, $fb_url ? basename(rtrim($fb_url, '/')) : '', $fb_url, $gradient_style, $theme);
                    break;
                case 'instagram':
                    $embed = $this->render_instagram_feed($opts, $atts);
                    $ig_user = trim($opts['instagram_username'] ?? '');
                    $ig_url  = $ig_user ? 'https://www.instagram.com/' . rawurlencode($ig_user) . '/' : '';
                    if ($embed) $html_parts[] = $this->card('Instagram', $embed, $show_title, $ig_user ? '@' . $ig_user : '', $ig_url, $gradient_style, $theme);
                    break;
                case 'tiktok':
                    $embed = $this->render_tiktok_feed($opts, $atts);
                    $tt_user = trim($opts['tiktok_username'] ?? '');
                    $tt_url  = $tt_user ? 'https://www.tiktok.com/@' . rawurlencode($tt_user) : '';
                    if ($embed) $html_parts[] = $this->card('TikTok', $embed, $show_title, $tt_user ? '@' . $tt_user : '', $tt_url, $gradient_style, $theme);
                    break;
                case 'twitter': case 'x':
                    $embed = $this->embed_twitter($opts['twitter_username'] ?? '');
                    $tw_user = trim($opts['twitter_username'] ?? '');
                    $tw_url  = $tw_user ? 'https://x.com/' . rawurlencode($tw_user) : '';
                    if ($embed) $html_parts[] = $this->card('X', $embed, $show_title, $tw_user ? '@' . $tw_user : '', $tw_url, $gradient_style, $theme);
                    break;
                case 'spotify':
                    $embed = $this->embed_spotify($opts['spotify_artist_id'] ?? '');
                    $sp_id  = trim($opts['spotify_artist_id'] ?? '');
                    $sp_url = $sp_id ? 'https://open.spotify.com/artist/' . rawurlencode($sp_id) : '';
                    if ($embed) $html_parts[] = $this->card('Spotify', $embed, $show_title, '', $sp_url, $gradient_style, $theme);
                    break;
            }
        }

        if (empty($html_parts)) return '<div class="ssfs-wrap"><p>No platforms configured yet.</p></div>';

        $wrap_class = 'ssfs-wrap';
        if ($layout === 'grid') $wrap_class .= ' grid';
        $out = '<div class="' . esc_attr($wrap_class) . '">' . implode("\n", $html_parts) . '</div>';

        if (strpos($out, 'twitter-timeline') !== false) {
            add_action('wp_footer', function() { echo '<script async src="https://platform.twitter.com/widgets.js" charset="utf-8"></script>'; });
        }
        if (strpos($out, 'fb-page') !== false) {
            add_action('wp_footer', function() { echo '<div id="fb-root"></div><script async defer crossorigin="anonymous" src="https://connect.facebook.net/en_US/sdk.js#xfbml=1&version=v19.0"></script>'; });
        }

        return $out;
    }

    private function get_legacy_options() {
        $defaults = [
            'youtube_channel_id' => '', 'facebook_page_url' => '', 'instagram_username' => '',
            'twitter_username' => '', 'spotify_artist_id' => '', 'layout' => 'grid',
            'facebook_app_id' => '', 'facebook_app_secret' => '', 'facebook_page_access_token' => '',
            'facebook_page_id' => '', 'tiktok_client_key' => '', 'tiktok_client_secret' => '',
            'tiktok_access_token' => '', 'tiktok_username' => '',
        ];
        $opts = get_option(self::LEGACY_OPT, []);
        if (empty($opts)) {
            foreach (self::LEGACY_KEYS as $key) {
                $legacy = get_option($key, []);
                if (!empty($legacy)) { $opts = $legacy; break; }
            }
        }
        // Merge in global credentials (migration may have moved them)
        $creds = $this->get_credentials();
        foreach ($creds as $k => $v) {
            if (!empty($v) && empty($opts[$k])) $opts[$k] = $v;
        }
        return wp_parse_args($opts, $defaults);
    }

    /* ══════════════════════════════════════════════════════════
       Card Wrapper
       ══════════════════════════════════════════════════════════ */

    private function card($title, $embed_html, $show_title = false, $handle = '', $profile_url = '', $gradient_style = '', $theme = 'dark') {
        $header = '';
        if ($show_title) {
            $nav = '';
            if (preg_match('/<div class="ssfs-nav">.*?<\/div>/s', $embed_html, $m)) {
                $nav = $m[0];
                $embed_html = str_replace($nav, '', $embed_html);
            }
            $header .= '<div class="ssfs-card-header">';
            $header .= '<div class="ssfs-card-header-text">';
            $header .= '<div class="ssfs-card-title">' . esc_html($title) . '</div>';
            if ($handle && $profile_url) {
                $header .= '<a class="ssfs-card-handle" href="' . esc_url($profile_url) . '" target="_blank" rel="noopener">' . esc_html($handle) . '</a>';
            } elseif ($profile_url) {
                $header .= '<a class="ssfs-card-handle" href="' . esc_url($profile_url) . '" target="_blank" rel="noopener">View Profile</a>';
            }
            $header .= '</div>';
            $header .= $nav;
            $header .= '</div>';
        }
        $style_attr = $gradient_style ? ' style="' . esc_attr($gradient_style) . '"' : '';
        $theme_class = ($theme === 'light') ? ' ssfs-light' : '';
        return '<div class="ssfs-card"><div class="ssfs-embed' . $theme_class . '"' . $style_attr . '>' . $header . $embed_html . '</div></div>';
    }

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

    /* ══════════════════════════════════════════════════════════
       AJAX Preview
       ══════════════════════════════════════════════════════════ */

    public function ajax_preview() {
        check_ajax_referer('asf_ajax', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('Permission denied.');

        $post_id = intval($_POST['post_id'] ?? 0);
        if (!$post_id) wp_send_json_error('No post ID.');

        $html = $this->render_feed_post($post_id);
        wp_send_json_success($html ?: '<p class="asf-preview-note">No content to preview. Save the feed source first.</p>');
    }

    /* ══════════════════════════════════════════════════════════
       AJAX Fetch Profile
       ══════════════════════════════════════════════════════════ */

    public function ajax_fetch_profile() {
        check_ajax_referer('asf_ajax', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('Permission denied.');

        $platform = sanitize_text_field($_POST['platform'] ?? '');
        $data = [];

        switch ($platform) {
            case 'youtube':
                $api_key = $this->get_google_api_key();
                $channel_in = sanitize_text_field($_POST['asf_yt_channel_id'] ?? '');
                if (!$api_key || !$channel_in) { wp_send_json_error('Missing API key or channel ID.'); return; }
                $channel_id = $this->ytapi_resolve_channel_id($channel_in, $api_key);
                if (is_wp_error($channel_id)) { wp_send_json_error($channel_id->get_error_message()); return; }
                $url = add_query_arg(['part' => 'snippet,statistics', 'id' => $channel_id, 'key' => $api_key], 'https://www.googleapis.com/youtube/v3/channels');
                $res = wp_remote_get($url, ['timeout' => 12]);
                if (is_wp_error($res)) { wp_send_json_error($res->get_error_message()); return; }
                $body = json_decode(wp_remote_retrieve_body($res), true);
                $ch = $body['items'][0] ?? [];
                if ($ch) {
                    $data['avatar_url']    = $ch['snippet']['thumbnails']['default']['url'] ?? '';
                    $data['display_name']  = $ch['snippet']['title'] ?? '';
                    $data['handle']        = $ch['snippet']['customUrl'] ?? '';
                    $data['profile_url']   = 'https://www.youtube.com/channel/' . $channel_id;
                    $data['followers']     = $ch['statistics']['subscriberCount'] ?? '';
                    $data['posts']         = $ch['statistics']['videoCount'] ?? '';
                }
                break;
            case 'facebook':
                $creds = $this->get_credentials();
                $page_id = sanitize_text_field($_POST['asf_fb_page_id'] ?? '') ?: ($creds['facebook_page_id'] ?? '');
                $opts = array_merge($creds, ['facebook_page_id' => $page_id]);
                $page_token = $this->get_facebook_page_token($opts);
                if (is_wp_error($page_token)) { wp_send_json_error($page_token->get_error_message()); return; }
                $url = add_query_arg(['fields' => 'name,picture,fan_count', 'access_token' => $page_token], 'https://graph.facebook.com/v22.0/' . rawurlencode($page_id));
                $res = wp_remote_get($url, ['timeout' => 15]);
                if (!is_wp_error($res)) {
                    $body = json_decode(wp_remote_retrieve_body($res), true);
                    $data['display_name'] = $body['name'] ?? '';
                    $data['avatar_url']   = $body['picture']['data']['url'] ?? '';
                    $data['followers']    = $body['fan_count'] ?? '';
                    $data['profile_url']  = 'https://www.facebook.com/' . $page_id;
                }
                break;
            case 'instagram':
                $creds = $this->get_credentials();
                $opts = $creds;
                $opts['facebook_page_id'] = $creds['facebook_page_id'] ?? '';
                $fb_ig = $this->get_instagram_from_facebook($opts);
                if (is_wp_error($fb_ig)) { wp_send_json_error($fb_ig->get_error_message()); return; }
                $url = add_query_arg(['fields' => 'username,profile_picture_url,media_count,followers_count,follows_count', 'access_token' => $fb_ig['token']], 'https://graph.facebook.com/v22.0/' . rawurlencode($fb_ig['user_id']));
                $res = wp_remote_get($url, ['timeout' => 15]);
                if (!is_wp_error($res)) {
                    $body = json_decode(wp_remote_retrieve_body($res), true);
                    $data['display_name'] = $body['username'] ?? '';
                    $data['handle']       = '@' . ($body['username'] ?? '');
                    $data['avatar_url']   = $body['profile_picture_url'] ?? '';
                    $data['followers']    = $body['followers_count'] ?? '';
                    $data['posts']        = $body['media_count'] ?? '';
                    $data['following']    = $body['follows_count'] ?? '';
                    $data['profile_url']  = 'https://www.instagram.com/' . ($body['username'] ?? '') . '/';
                }
                break;
            case 'tiktok':
                $creds = $this->get_credentials();
                $token = $creds['tiktok_access_token'] ?? '';
                if (!$token) { wp_send_json_error('No TikTok access token.'); return; }
                $res = wp_remote_get('https://open.tiktokapis.com/v2/user/info/?fields=display_name,avatar_url,follower_count,following_count,video_count', [
                    'timeout' => 15,
                    'headers' => ['Authorization' => 'Bearer ' . $token],
                ]);
                if (!is_wp_error($res)) {
                    $body = json_decode(wp_remote_retrieve_body($res), true);
                    $u = $body['data']['user'] ?? [];
                    $data['display_name'] = $u['display_name'] ?? '';
                    $data['avatar_url']   = $u['avatar_url'] ?? '';
                    $data['followers']    = $u['follower_count'] ?? '';
                    $data['following']    = $u['following_count'] ?? '';
                    $data['posts']        = $u['video_count'] ?? '';
                }
                break;
            default:
                wp_send_json_error('Profile fetch is not supported for this platform. Use manual entry.');
                return;
        }

        if (empty($data)) { wp_send_json_error('No profile data returned.'); return; }
        wp_send_json_success($data);
    }

    /* ══════════════════════════════════════════════════════════
       Profile Header Render
       ══════════════════════════════════════════════════════════ */

    private function render_profile_header($post_id, $platform, $settings, $theme = 'dark') {
        $avatar   = $settings['asf_profile_avatar_url'] ?? '';
        $name     = $settings['asf_profile_display_name'] ?? '';
        $handle   = $settings['asf_profile_handle'] ?? '';
        $url      = $settings['asf_profile_url'] ?? '';
        $followers = $settings['asf_profile_followers'] ?? '';
        $posts    = $settings['asf_profile_posts'] ?? '';
        $following = $settings['asf_profile_following'] ?? '';
        $btn_text = $settings['asf_follow_button_text'] ?? 'Follow';

        // If no manual data, try cached API data
        if (!$name && !$avatar) {
            $cached = get_transient('asf_profile_data_' . $post_id);
            if (is_array($cached)) {
                $avatar    = $avatar ?: ($cached['avatar_url'] ?? '');
                $name      = $name ?: ($cached['display_name'] ?? '');
                $handle    = $handle ?: ($cached['handle'] ?? '');
                $url       = $url ?: ($cached['profile_url'] ?? '');
                $followers = $followers ?: ($cached['followers'] ?? '');
                $posts     = $posts ?: ($cached['posts'] ?? '');
                $following = $following ?: ($cached['following'] ?? '');
            }
        }

        if (!$name && !$avatar) return '';

        $html = '<div class="asf-profile-header">';
        if ($avatar) {
            $html .= '<img class="asf-profile-avatar" src="' . esc_url($avatar) . '" alt="' . esc_attr($name) . '" loading="lazy" />';
        }
        $html .= '<div class="asf-profile-info">';
        if ($name) $html .= '<div class="asf-profile-name">' . esc_html($name) . '</div>';
        if ($handle && $url) {
            $html .= '<a class="asf-profile-handle" href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($handle) . '</a>';
        } elseif ($handle) {
            $html .= '<span class="asf-profile-handle">' . esc_html($handle) . '</span>';
        }
        if ($followers || $posts || $following) {
            $html .= '<div class="asf-profile-stats">';
            if ($posts)     $html .= '<span class="asf-profile-stat"><strong>' . esc_html($this->format_number($posts)) . '</strong> Posts</span>';
            if ($followers) $html .= '<span class="asf-profile-stat"><strong>' . esc_html($this->format_number($followers)) . '</strong> Followers</span>';
            if ($following) $html .= '<span class="asf-profile-stat"><strong>' . esc_html($this->format_number($following)) . '</strong> Following</span>';
            $html .= '</div>';
        }
        $html .= '</div>';
        if ($url && $btn_text) {
            $html .= '<a class="asf-follow-btn" href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($btn_text) . '</a>';
        }
        $html .= '</div>';
        return $html;
    }

    private function format_number($n) {
        $n = intval(str_replace(',', '', $n));
        if ($n >= 1000000) return round($n / 1000000, 1) . 'M';
        if ($n >= 1000)    return round($n / 1000, 1) . 'K';
        return number_format($n);
    }

    /* ══════════════════════════════════════════════════════════
       YouTube API
       ══════════════════════════════════════════════════════════ */

    private function render_youtube_api_feed($opts, $atts) {
        $api_key    = trim($opts['youtube_api_key'] ?? '') ?: $this->get_google_api_key();
        $channel_in = trim($opts['youtube_channel_id'] ?? '');
        $mode       = strtolower($atts['youtube_api'] ?? 'auto');
        $use_api    = ($mode === 'on') || ($mode === 'auto' && $api_key && $channel_in);

        if (!$use_api) return '<p class="ssfs-note">YouTube API is not enabled. Add a Google API key in Anchor Tools settings or pass youtube_api="on".</p>';
        if (!$api_key || !$channel_in) return '<p class="ssfs-note">Missing YouTube API key or channel value.</p>';

        $resolved = $this->ytapi_resolve_channel_id($channel_in, $api_key);
        if (is_wp_error($resolved)) return '<p class="ssfs-note">YouTube API error, ' . esc_html($resolved->get_error_message()) . '</p>';
        $channel_id = $resolved;

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
        $pages    = max(1, min(20, intval($atts['youtube_fetch_pages'] ?? 10)));

        $items = $this->ytapi_get_uploads($channel_id, $api_key, $pages);
        if (is_wp_error($items)) return '<p class="ssfs-note">' . esc_html($items->get_error_message()) . '</p>';
        if (!$items) return '<p>No videos found.</p>';

        $ids = array_map(function($it){ return $it['videoId']; }, $items);
        $details = $this->ytapi_get_videos_details($ids, $api_key);
        $byId = [];
        foreach ($details as $d) $byId[$d['id']] = $d;

        $kept = [];
        foreach ($items as $it) {
            $vid  = $it['videoId'];
            $date = $it['publishedAt'];
            if (!isset($byId[$vid])) continue;

            $d = $byId[$vid];
            $title_str = $d['title'];
            $desc  = $d['description'];
            $tags  = $d['tags'];
            $sec   = $d['seconds'];
            $thumb = $d['thumb'];
            $pub_ts = $date ? strtotime($date) : 0;

            if ($since_ts && $pub_ts && $pub_ts < $since_ts) continue;
            if ($until_ts && $pub_ts && $pub_ts > $until_ts) continue;
            if ($max_age_days && $pub_ts && ($today - $pub_ts) > ($max_age_days * DAY_IN_SECONDS)) continue;

            $is_short = ($sec > 0 && $sec <= 65);
            if ($type === 'videos' && $is_short) continue;
            if ($type === 'shorts' && !$is_short) continue;

            $hay = strtolower($title_str . ' ' . $desc . ' ' . implode(' ', $tags));
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

            if ($min_sec !== null && $sec > 0 && $sec < $min_sec) continue;
            if ($max_sec !== null && $sec > 0 && $sec > $max_sec) continue;

            $kept[] = ['id' => $vid, 'title' => $title_str, 'date' => $date, 'thumb' => $thumb];
        }

        if ($limit > 0 && count($kept) > $limit) $kept = array_slice($kept, 0, $limit);
        if (!$kept) return '<p>No videos match your filters.</p>';

        $layout = in_array($atts['layout'] ?? 'grid', ['grid','stack','carousel','hover_overlay'], true) ? $atts['layout'] : 'grid';

        if ($layout === 'hover_overlay') {
            return $this->render_hover_overlay_items($kept, 'youtube', $atts);
        }

        $items_html = '';
        foreach ($kept as $v) {
            $items_html .= '<div class="ssfs-item" data-ssfs-item data-title="'.esc_attr($v['title']).'" data-date="'.esc_attr(substr($v['date'],0,10)).'" data-ssfs-video="'.esc_attr($v['id']).'">'
                .'<img class="ssfs-thumb" loading="lazy" src="'.esc_url($v['thumb']).'" alt="'.esc_attr($v['title']).'" />'
                .'<div class="ssfs-meta">'
                .'<div class="ssfs-title">'.esc_html($v['title']).'</div>'
                .'<div class="ssfs-date">'.esc_html(date_i18n(get_option('date_format'), strtotime($v['date']))).'</div>'
                .'</div></div>';
        }

        return $this->wrap_layout($items_html, $layout);
    }

    private function ytapi_get_uploads($channel_id, $api_key, $pages = 10) {
        $ckey = 'ssfs_yt_uploads_' . md5($channel_id);
        if (isset($_GET['ssfs_refresh']) && $_GET['ssfs_refresh'] == '1') delete_transient($ckey);
        $uploads = get_transient($ckey);

        if (!$uploads) {
            $url = add_query_arg(['part' => 'contentDetails', 'id' => $channel_id, 'key' => $api_key], 'https://www.googleapis.com/youtube/v3/channels');
            $res = wp_remote_get($url, ['timeout' => 12]);
            if (is_wp_error($res)) return $res;
            $code = wp_remote_retrieve_response_code($res);
            $data = json_decode(wp_remote_retrieve_body($res), true);
            if ($code === 403) return new \WP_Error('ssfs_api_403', 'Access denied, check API key restrictions.');
            if (empty($data['items'][0]['contentDetails']['relatedPlaylists']['uploads'])) {
                return new \WP_Error('ssfs_api', 'YouTube API error, cannot read uploads playlist for this channel.');
            }
            $uploads = $data['items'][0]['contentDetails']['relatedPlaylists']['uploads'];
            set_transient($ckey, $uploads, DAY_IN_SECONDS);
        }

        $items = [];
        $pageToken = '';
        $scanned = 0;
        do {
            $url = add_query_arg(['part' => 'snippet,contentDetails', 'playlistId' => $uploads, 'maxResults' => 50, 'pageToken' => $pageToken, 'key' => $api_key], 'https://www.googleapis.com/youtube/v3/playlistItems');
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
            $url = add_query_arg(['part' => 'snippet,contentDetails', 'id' => implode(',', $chunk), 'key' => $api_key], 'https://www.googleapis.com/youtube/v3/videos');
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
                $sn = $it['snippet'] ?? [];
                $cd = $it['contentDetails'] ?? [];
                $sec = self::iso8601_to_seconds($cd['duration'] ?? '');
                $thumb = '';
                if (!empty($sn['thumbnails']['high']['url'])) $thumb = $sn['thumbnails']['high']['url'];
                elseif (!empty($sn['thumbnails'])) { $first = reset($sn['thumbnails']); $thumb = $first['url'] ?? ''; }
                $details[] = [
                    'id' => $it['id'], 'title' => $sn['title'] ?? '', 'description' => $sn['description'] ?? '',
                    'tags' => $sn['tags'] ?? [], 'publishedAt' => $sn['publishedAt'] ?? '',
                    'seconds' => $sec, 'thumb' => $thumb ?: 'https://i.ytimg.com/vi/'.$it['id'].'/hqdefault.jpg',
                ];
            }
        }
        return $details;
    }

    private static function iso8601_to_seconds($dur) {
        if (!$dur) return 0;
        if (!preg_match('/PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?/i', $dur, $m)) return 0;
        return (isset($m[1]) ? (int)$m[1] : 0) * 3600 + (isset($m[2]) ? (int)$m[2] : 0) * 60 + (isset($m[3]) ? (int)$m[3] : 0);
    }

    private function ytapi_resolve_channel_id($input, $api_key) {
        $input = trim($input);
        if ($input === '') return new \WP_Error('ssfs_input', 'No channel provided');
        if (preg_match('/^UC[0-9A-Za-z_-]{22}$/', $input)) return $input;

        if ($input[0] === '@' || stripos($input, 'youtube.com/@') !== false) {
            $handle = $input[0] === '@' ? $input : preg_replace('~^.*?/@([^/?#]+).*$~', '@$1', $input);
            $handle = ltrim(trim($handle), '@/');
            if ($handle === '') return new \WP_Error('ssfs_handle', 'Empty handle provided');

            $cache_key = 'ssfs_yt_handle_' . md5(strtolower($handle));
            if (isset($_GET['ssfs_refresh']) && $_GET['ssfs_refresh'] == '1') delete_transient($cache_key);
            $cached = get_transient($cache_key);
            if (is_string($cached) && preg_match('/^UC[0-9A-Za-z_-]{22}$/', $cached)) return $cached;

            $url = add_query_arg(['part' => 'id', 'forHandle' => $handle, 'key' => $api_key], 'https://www.googleapis.com/youtube/v3/channels');
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

            $url = add_query_arg(['part' => 'snippet', 'q' => '@' . $handle, 'type' => 'channel', 'maxResults' => 1, 'key' => $api_key], 'https://www.googleapis.com/youtube/v3/search');
            $res = wp_remote_get($url, ['timeout' => 12]);
            if (is_wp_error($res)) return $res;
            $data = json_decode(wp_remote_retrieve_body($res), true);
            $cid = $data['items'][0]['id']['channelId'] ?? '';
            if ($cid && preg_match('/^UC[0-9A-Za-z_-]{22}$/', $cid)) {
                set_transient($cache_key, $cid, DAY_IN_SECONDS);
                return $cid;
            }
            return new \WP_Error('ssfs_handle', 'Could not resolve handle to a channel ID');
        }

        if (stripos($input, 'youtube.com') !== false) {
            if (preg_match('~youtube\.com/channel/([A-Za-z0-9_\-]+)~i', $input, $m) && preg_match('/^UC[0-9A-Za-z_-]{22}$/', $m[1])) {
                return $m[1];
            }
            $url = add_query_arg(['part' => 'snippet', 'q' => $input, 'type' => 'channel', 'maxResults' => 1, 'key' => $api_key], 'https://www.googleapis.com/youtube/v3/search');
            $res = wp_remote_get($url, ['timeout' => 12]);
            if (is_wp_error($res)) return $res;
            $data = json_decode(wp_remote_retrieve_body($res), true);
            $cid = $data['items'][0]['id']['channelId'] ?? '';
            if ($cid && preg_match('/^UC[0-9A-Za-z_-]{22}$/', $cid)) return $cid;
            return new \WP_Error('ssfs_url', 'Could not resolve the channel URL to a channel ID');
        }

        $url = add_query_arg(['part' => 'id', 'forUsername' => $input, 'key' => $api_key], 'https://www.googleapis.com/youtube/v3/channels');
        $res = wp_remote_get($url, ['timeout' => 12]);
        if (!is_wp_error($res)) {
            $data = json_decode(wp_remote_retrieve_body($res), true);
            $cid = $data['items'][0]['id'] ?? '';
            if ($cid && preg_match('/^UC[0-9A-Za-z_-]{22}$/', $cid)) return $cid;
        }
        return new \WP_Error('ssfs_input', 'Unrecognized channel input');
    }

    /* ══════════════════════════════════════════════════════════
       Facebook Graph API
       ══════════════════════════════════════════════════════════ */

    private function get_facebook_page_token($opts) {
        $app_id     = trim($opts['facebook_app_id'] ?? '');
        $app_secret = trim($opts['facebook_app_secret'] ?? '');
        $token      = trim($opts['facebook_page_access_token'] ?? '');
        $page_id    = trim($opts['facebook_page_id'] ?? '');
        if (!$token || !$page_id) return new \WP_Error('ssfs_fb_creds', 'Missing Access Token or Page ID.');
        if (!$app_id || !$app_secret) return $token;

        $cache_key = 'ssfs_fb_page_token_' . md5($page_id . $app_id);
        if (isset($_GET['ssfs_refresh']) && $_GET['ssfs_refresh'] == '1') delete_transient($cache_key);
        $cached = get_transient($cache_key);
        if ($cached) return $cached;

        $url = add_query_arg(['grant_type' => 'fb_exchange_token', 'client_id' => $app_id, 'client_secret' => $app_secret, 'fb_exchange_token' => $token], 'https://graph.facebook.com/v22.0/oauth/access_token');
        $res = wp_remote_get($url, ['timeout' => 15]);
        if (is_wp_error($res)) return $res;
        $body = json_decode(wp_remote_retrieve_body($res), true);
        if (isset($body['error'])) return new \WP_Error('ssfs_fb_exchange', $body['error']['message'] ?? 'Token exchange failed.');
        $long_token = $body['access_token'] ?? '';
        if (!$long_token) return new \WP_Error('ssfs_fb_exchange', 'No access token returned from exchange.');

        $url = add_query_arg(['access_token' => $long_token], 'https://graph.facebook.com/v22.0/me/accounts');
        $res = wp_remote_get($url, ['timeout' => 15]);
        if (is_wp_error($res)) return $res;
        $body = json_decode(wp_remote_retrieve_body($res), true);
        if (isset($body['error'])) return new \WP_Error('ssfs_fb_pages', $body['error']['message'] ?? 'Could not retrieve page tokens.');

        $page_token = '';
        foreach (($body['data'] ?? []) as $page) {
            if (($page['id'] ?? '') === $page_id) { $page_token = $page['access_token'] ?? ''; break; }
        }
        if (!$page_token) return $token;

        set_transient($cache_key, $page_token, 60 * DAY_IN_SECONDS);
        return $page_token;
    }

    private function fetch_facebook_posts($page_id, $access_token, $limit = 10) {
        $cache_key = 'ssfs_fb_posts_' . md5($page_id);
        if (isset($_GET['ssfs_refresh']) && $_GET['ssfs_refresh'] == '1') delete_transient($cache_key);
        $cached = get_transient($cache_key);
        if (is_array($cached)) return $cached;

        $url = add_query_arg([
            'fields' => 'id,message,full_picture,created_time,permalink_url,attachments{media_type,media,url}',
            'limit' => min(absint($limit), 100), 'access_token' => $access_token,
        ], 'https://graph.facebook.com/v22.0/' . rawurlencode($page_id) . '/posts');

        $res = wp_remote_get($url, ['timeout' => 15]);
        if (is_wp_error($res)) return $res;
        $code = wp_remote_retrieve_response_code($res);
        $body = json_decode(wp_remote_retrieve_body($res), true);
        if ($code !== 200 || isset($body['error'])) {
            return new \WP_Error('ssfs_fb_api', $body['error']['message'] ?? 'Facebook API error (HTTP ' . $code . ')');
        }

        $posts = [];
        foreach (($body['data'] ?? []) as $item) {
            $media_type = !empty($item['attachments']['data'][0]['media_type']) ? $item['attachments']['data'][0]['media_type'] : '';
            $posts[] = [
                'id' => $item['id'] ?? '', 'message' => $item['message'] ?? '',
                'image' => $item['full_picture'] ?? '', 'date' => $item['created_time'] ?? '',
                'permalink' => $item['permalink_url'] ?? '', 'media_type' => $media_type,
            ];
        }
        set_transient($cache_key, $posts, 30 * MINUTE_IN_SECONDS);
        return $posts;
    }

    private function render_facebook_feed($opts, $atts) {
        $page_id = trim($opts['facebook_page_id'] ?? '');
        $token   = trim($opts['facebook_page_access_token'] ?? '');
        if (!$page_id || !$token) {
            $legacy = $this->embed_facebook($opts['facebook_page_url'] ?? '');
            if ($legacy) return $legacy;
            return '<p class="ssfs-note">Facebook Graph API credentials not configured.</p>';
        }
        $page_token = $this->get_facebook_page_token($opts);
        if (is_wp_error($page_token)) return '<p class="ssfs-note">Facebook token error: ' . esc_html($page_token->get_error_message()) . '</p>';

        $limit = max(1, intval($atts['facebook_limit'] ?? 10));
        $posts = $this->fetch_facebook_posts($page_id, $page_token, $limit);
        if (is_wp_error($posts)) return '<p class="ssfs-note">Facebook API error: ' . esc_html($posts->get_error_message()) . '</p>';
        if (empty($posts)) return '<p class="ssfs-note">No Facebook posts found.</p>';

        $posts  = array_slice($posts, 0, $limit);
        $layout = in_array($atts['layout'] ?? 'grid', ['grid','stack','carousel','hover_overlay'], true) ? $atts['layout'] : 'grid';

        if ($layout === 'hover_overlay') {
            $items = [];
            foreach ($posts as $post) {
                $items[] = ['thumb' => $post['image'], 'caption' => $post['message'] ? wp_trim_words($post['message'], 20, '&hellip;') : '', 'url' => $post['permalink'], 'media_type' => 'IMAGE'];
            }
            return $this->render_hover_overlay_generic($items, $atts);
        }

        $items_html = '';
        foreach ($posts as $post) {
            $caption = $post['message'] ? wp_trim_words($post['message'], 20, '&hellip;') : '';
            $date    = $post['date'] ? date_i18n(get_option('date_format'), strtotime($post['date'])) : '';
            $href    = esc_url($post['permalink']);
            $items_html .= '<a class="ssfs-item ssfs-item--facebook" href="' . $href . '" target="_blank" rel="noopener">';
            if ($post['image']) $items_html .= '<img class="ssfs-thumb" loading="lazy" src="' . esc_url($post['image']) . '" alt="' . esc_attr($caption) . '" />';
            $items_html .= '<div class="ssfs-meta">';
            if ($caption) $items_html .= '<div class="ssfs-title">' . esc_html($caption) . '</div>';
            if ($date)    $items_html .= '<div class="ssfs-date">' . esc_html($date) . '</div>';
            $items_html .= '</div></a>';
        }
        return $this->wrap_layout($items_html, $layout);
    }

    /* ══════════════════════════════════════════════════════════
       Instagram Graph API
       ══════════════════════════════════════════════════════════ */

    private function get_instagram_from_facebook($opts) {
        $page_id = trim($opts['facebook_page_id'] ?? '');
        if (!$page_id) return new \WP_Error('ssfs_ig_fb', 'No Facebook Page ID configured.');
        $page_token = $this->get_facebook_page_token($opts);
        if (is_wp_error($page_token)) return $page_token;

        $cache_key = 'ssfs_ig_from_fb_' . md5($page_id);
        if (isset($_GET['ssfs_refresh']) && $_GET['ssfs_refresh'] == '1') delete_transient($cache_key);
        $cached = get_transient($cache_key);
        if (is_array($cached)) return $cached;

        $url = add_query_arg(['fields' => 'instagram_business_account', 'access_token' => $page_token], 'https://graph.facebook.com/v22.0/' . rawurlencode($page_id));
        $res = wp_remote_get($url, ['timeout' => 15]);
        if (is_wp_error($res)) return $res;
        $body = json_decode(wp_remote_retrieve_body($res), true);
        if (isset($body['error'])) return new \WP_Error('ssfs_ig_fb', $body['error']['message'] ?? 'Could not look up Instagram account.');
        $ig_id = $body['instagram_business_account']['id'] ?? '';
        if (!$ig_id) return new \WP_Error('ssfs_ig_fb', 'No Instagram Business account linked to this Facebook Page.');

        $result = ['user_id' => $ig_id, 'token' => $page_token];
        set_transient($cache_key, $result, 60 * DAY_IN_SECONDS);
        return $result;
    }

    private function fetch_instagram_media($user_id, $access_token, $limit = 12) {
        $cache_key = 'ssfs_ig_media_' . md5($user_id);
        if (isset($_GET['ssfs_refresh']) && $_GET['ssfs_refresh'] == '1') delete_transient($cache_key);
        $cached = get_transient($cache_key);
        if (is_array($cached)) return $cached;

        $url = add_query_arg([
            'fields' => 'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp',
            'limit' => min(absint($limit), 100), 'access_token' => $access_token,
        ], 'https://graph.facebook.com/v22.0/' . rawurlencode($user_id) . '/media');

        $res = wp_remote_get($url, ['timeout' => 15]);
        if (is_wp_error($res)) return $res;
        $code = wp_remote_retrieve_response_code($res);
        $body = json_decode(wp_remote_retrieve_body($res), true);
        if ($code !== 200 || isset($body['error'])) return new \WP_Error('ssfs_ig_api', $body['error']['message'] ?? 'Instagram API error (HTTP ' . $code . ')');

        $media = [];
        foreach (($body['data'] ?? []) as $item) {
            $type  = $item['media_type'] ?? 'IMAGE';
            $thumb = ($type === 'VIDEO') ? ($item['thumbnail_url'] ?? '') : ($item['media_url'] ?? '');
            $media[] = [
                'id' => $item['id'] ?? '', 'caption' => $item['caption'] ?? '', 'media_type' => $type,
                'media_url' => $item['media_url'] ?? '', 'thumb' => $thumb,
                'permalink' => $item['permalink'] ?? '', 'date' => $item['timestamp'] ?? '',
            ];
        }
        set_transient($cache_key, $media, 30 * MINUTE_IN_SECONDS);
        return $media;
    }

    private function render_instagram_feed($opts, $atts) {
        $user_id = trim($opts['instagram_user_id'] ?? '');
        $token   = trim($opts['instagram_access_token'] ?? '');
        if (!$user_id || !$token) {
            $fb_ig = $this->get_instagram_from_facebook($opts);
            if (!is_wp_error($fb_ig)) { $user_id = $fb_ig['user_id']; $token = $fb_ig['token']; }
        }
        if (!$user_id || !$token) {
            $legacy = $this->embed_instagram($opts['instagram_username'] ?? '');
            if ($legacy) return $legacy;
            return '<p class="ssfs-note">Instagram Graph API credentials not configured.</p>';
        }

        $limit = max(1, intval($atts['instagram_limit'] ?? 12));
        $media = $this->fetch_instagram_media($user_id, $token, $limit);
        if (is_wp_error($media)) return '<p class="ssfs-note">Instagram API error: ' . esc_html($media->get_error_message()) . '</p>';
        if (empty($media)) return '<p class="ssfs-note">No Instagram media found.</p>';

        $media  = array_slice($media, 0, $limit);
        $layout = in_array($atts['layout'] ?? 'grid', ['grid','stack','carousel','hover_overlay'], true) ? $atts['layout'] : 'grid';

        if ($layout === 'hover_overlay') {
            $items = [];
            foreach ($media as $item) {
                $items[] = [
                    'thumb' => $item['thumb'], 'caption' => $item['caption'] ? wp_trim_words($item['caption'], 20, '&hellip;') : '',
                    'url' => $item['permalink'], 'media_type' => $item['media_type'],
                    'video_url' => ($item['media_type'] === 'VIDEO') ? $item['media_url'] : '',
                ];
            }
            return $this->render_hover_overlay_generic($items, $atts);
        }

        $items_html = '';
        foreach ($media as $item) {
            $caption = $item['caption'] ? wp_trim_words($item['caption'], 20, '&hellip;') : '';
            $date    = $item['date'] ? date_i18n(get_option('date_format'), strtotime($item['date'])) : '';
            $is_video = ($item['media_type'] === 'VIDEO');
            $is_carousel = ($item['media_type'] === 'CAROUSEL_ALBUM');

            if ($is_video) {
                $items_html .= '<div class="ssfs-item ssfs-item--instagram ssfs-item--video" data-ssfs-ig-video="' . esc_attr($item['media_url']) . '">';
            } else {
                $items_html .= '<a class="ssfs-item ssfs-item--instagram" href="' . esc_url($item['permalink']) . '" target="_blank" rel="noopener">';
            }
            $items_html .= '<div class="ssfs-thumb-wrap">';
            $items_html .= '<img class="ssfs-thumb" loading="lazy" src="' . esc_url($item['thumb']) . '" alt="' . esc_attr($caption) . '" />';
            if ($is_video) $items_html .= '<span class="ssfs-play-badge" aria-hidden="true">&#9654;</span>';
            elseif ($is_carousel) $items_html .= '<span class="ssfs-carousel-badge" aria-hidden="true">&#10064;</span>';
            $items_html .= '</div>';
            $items_html .= '<div class="ssfs-meta">';
            if ($caption) $items_html .= '<div class="ssfs-title">' . esc_html($caption) . '</div>';
            if ($date)    $items_html .= '<div class="ssfs-date">' . esc_html($date) . '</div>';
            $items_html .= '</div>';
            $items_html .= $is_video ? '</div>' : '</a>';
        }
        return $this->wrap_layout($items_html, $layout);
    }

    /* ══════════════════════════════════════════════════════════
       TikTok Display API
       ══════════════════════════════════════════════════════════ */

    private function fetch_tiktok_videos($access_token, $limit = 10) {
        $cache_key = 'ssfs_tt_videos_' . md5($access_token);
        if (isset($_GET['ssfs_refresh']) && $_GET['ssfs_refresh'] == '1') delete_transient($cache_key);
        $cached = get_transient($cache_key);
        if (is_array($cached)) return $cached;

        $res = wp_remote_post('https://open.tiktokapis.com/v2/video/list/?fields=id,title,cover_image_url,share_url,create_time,duration,like_count', [
            'timeout' => 15,
            'headers' => ['Authorization' => 'Bearer ' . $access_token, 'Content-Type' => 'application/json'],
            'body' => wp_json_encode(['max_count' => min(absint($limit), 20)]),
        ]);
        if (is_wp_error($res)) return $res;
        $code = wp_remote_retrieve_response_code($res);
        $body = json_decode(wp_remote_retrieve_body($res), true);
        if ($code !== 200 || !empty($body['error']['code'])) {
            return new \WP_Error('ssfs_tt_api', $body['error']['message'] ?? 'TikTok API error (HTTP ' . $code . ')');
        }

        $videos = [];
        foreach (($body['data']['videos'] ?? []) as $item) {
            $videos[] = [
                'id' => $item['id'] ?? '', 'title' => $item['title'] ?? '',
                'thumb' => $item['cover_image_url'] ?? '', 'share_url' => $item['share_url'] ?? '',
                'date' => isset($item['create_time']) ? date('c', $item['create_time']) : '',
                'duration' => $item['duration'] ?? 0, 'likes' => $item['like_count'] ?? 0,
            ];
        }
        set_transient($cache_key, $videos, 15 * MINUTE_IN_SECONDS);
        return $videos;
    }

    private function render_tiktok_feed($opts, $atts) {
        $token = trim($opts['tiktok_access_token'] ?? '');
        if (!$token) return '<p class="ssfs-note">TikTok API credentials not configured.</p>';

        $limit  = max(1, intval($atts['tiktok_limit'] ?? 10));
        $videos = $this->fetch_tiktok_videos($token, $limit);
        if (is_wp_error($videos)) return '<p class="ssfs-note">TikTok API error: ' . esc_html($videos->get_error_message()) . '</p>';
        if (empty($videos)) return '<p class="ssfs-note">No TikTok videos found.</p>';

        $videos = array_slice($videos, 0, $limit);
        $layout = in_array($atts['layout'] ?? 'grid', ['grid','stack','carousel','hover_overlay'], true) ? $atts['layout'] : 'grid';

        if ($layout === 'hover_overlay') {
            $items = [];
            foreach ($videos as $v) {
                $items[] = ['thumb' => $v['thumb'], 'caption' => $v['title'] ?: 'TikTok video', 'url' => $v['share_url'], 'media_type' => 'VIDEO'];
            }
            return $this->render_hover_overlay_generic($items, $atts);
        }

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

    /* ══════════════════════════════════════════════════════════
       Other Platforms (Legacy Embeds)
       ══════════════════════════════════════════════════════════ */

    private function embed_facebook($page_url) {
        $page_url = trim($page_url);
        if (!$page_url) return '';
        $page_url = esc_url($page_url);
        $attrs = ['data-href' => $page_url, 'data-tabs' => 'timeline', 'data-width' => '500', 'data-height' => '600', 'data-small-header' => 'false', 'data-adapt-container-width' => 'true', 'data-hide-cover' => 'false', 'data-show-facepile' => 'true'];
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
        return '<p class="ssfs-note">Instagram profile feed embed is not available without API permission.</p><p><a href="' . esc_url($url) . '" target="_blank" rel="noopener">Follow @' . esc_html($username) . ' on Instagram</a></p>';
    }

    /* ══════════════════════════════════════════════════════════
       Hover Overlay Grid Layout
       ══════════════════════════════════════════════════════════ */

    private function render_hover_overlay_items($kept, $platform, $atts) {
        $cols_d = intval($atts['columns_desktop'] ?? 4) ?: 4;
        $cols_t = intval($atts['columns_tablet'] ?? 3) ?: 3;
        $cols_m = intval($atts['columns_mobile'] ?? 2) ?: 2;
        $gap    = intval($atts['gap'] ?? 16);

        $style = sprintf('--asf-cols-desktop:%d;--asf-cols-tablet:%d;--asf-cols-mobile:%d;--asf-gap:%dpx;', $cols_d, $cols_t, $cols_m, $gap);
        $html = '<div class="asf-hover-grid" style="' . esc_attr($style) . '">';

        foreach ($kept as $v) {
            $html .= '<div class="asf-hover-tile" data-ssfs-video="' . esc_attr($v['id']) . '">';
            $html .= '<img src="' . esc_url($v['thumb']) . '" alt="' . esc_attr($v['title']) . '" loading="lazy" />';
            $html .= '<div class="asf-hover-overlay"><span class="asf-hover-caption">' . esc_html($v['title']) . '</span></div>';
            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
    }

    private function render_hover_overlay_generic($items, $atts) {
        $cols_d = intval($atts['columns_desktop'] ?? 4) ?: 4;
        $cols_t = intval($atts['columns_tablet'] ?? 3) ?: 3;
        $cols_m = intval($atts['columns_mobile'] ?? 2) ?: 2;
        $gap    = intval($atts['gap'] ?? 16);

        $style = sprintf('--asf-cols-desktop:%d;--asf-cols-tablet:%d;--asf-cols-mobile:%d;--asf-gap:%dpx;', $cols_d, $cols_t, $cols_m, $gap);
        $html = '<div class="asf-hover-grid" style="' . esc_attr($style) . '">';

        foreach ($items as $item) {
            $is_video = ($item['media_type'] ?? '') === 'VIDEO';
            $video_url = $item['video_url'] ?? '';
            $url = $item['url'] ?? '';

            if ($is_video && $video_url) {
                $html .= '<div class="asf-hover-tile" data-ssfs-ig-video="' . esc_attr($video_url) . '">';
            } elseif ($url) {
                $html .= '<a class="asf-hover-tile" href="' . esc_url($url) . '" target="_blank" rel="noopener">';
            } else {
                $html .= '<div class="asf-hover-tile">';
            }

            if (!empty($item['thumb'])) {
                $html .= '<img src="' . esc_url($item['thumb']) . '" alt="' . esc_attr($item['caption'] ?? '') . '" loading="lazy" />';
            }
            if ($is_video) $html .= '<span class="asf-hover-badge" aria-hidden="true">&#9654;</span>';
            elseif (($item['media_type'] ?? '') === 'CAROUSEL_ALBUM') $html .= '<span class="asf-hover-badge" aria-hidden="true">&#10064;</span>';

            $html .= '<div class="asf-hover-overlay"><span class="asf-hover-caption">' . esc_html($item['caption'] ?? '') . '</span></div>';

            if ($is_video && $video_url) {
                $html .= '</div>';
            } elseif ($url) {
                $html .= '</a>';
            } else {
                $html .= '</div>';
            }
        }

        $html .= '</div>';
        return $html;
    }

} // end class
