<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class ACG_Admin {

    // New option key, but read legacy if em
    const OPTION_KEY = 'acg_settings';
    // Keep legacy meta key to preserve existing data
    const META_KEY   = '_anchor_schema_items';

    public function __construct(){
        add_action('admin_menu', [ $this, 'add_settings_page' ]);
        add_action('admin_init', [ $this, 'register_settings' ]);
        add_action('add_meta_boxes', [ $this, 'register_metabox' ]);
        add_action('admin_enqueue_scripts', [ $this, 'enqueue_assets' ]);

        // AJAX for meta box
        add_action('wp_ajax_acg_generate', [ $this, 'ajax_generate' ]);
        add_action('wp_ajax_acg_update_item', [ $this, 'ajax_update_item' ]);
        add_action('wp_ajax_acg_delete_item', [ $this, 'ajax_delete_item' ]);
        add_action('wp_ajax_acg_upload',   [ $this, 'ajax_upload' ]);

        // Wizard page and AJAX endpoints
        add_action('admin_menu', [ $this, 'add_wizard_page' ]);
        add_action('admin_menu', [ $this, 'add_llms_tool_page' ]);
        add_action('wp_ajax_acg_list_posts', [ $this, 'ajax_list_posts' ]);
        add_action('wp_ajax_acg_scan_post',  [ $this, 'ajax_scan_post' ]);
        add_action('wp_ajax_acg_generate_for_post', [ $this, 'ajax_generate_for_post' ]);
        add_action('wp_ajax_acg_save_for_post', [ $this, 'ajax_save_for_post' ]);
    }

    /* Settings */
    public function add_settings_page(){
        add_options_page(
            __('Anchor Tools', 'anchor-tools'),
            __('Anchor Tools', 'anchor-tools'),
            'manage_options',
            'anchor-tools',
            [ $this, 'render_settings_page' ]
        );
    }

    public function register_settings(){
        register_setting( 'acg_group', self::OPTION_KEY, [ $this, 'sanitize_settings' ] );

        add_settings_section('acg_main', __('API Configuration', 'anchor-tools'), function(){
            echo '<p>' . esc_html__('Configure the OpenAI API key and model. Enable debug to log to PHP error log.', 'anchor-tools') . '</p>';
        }, 'acg_settings');

        add_settings_field('api_key', __('OpenAI API Key', 'anchor-tools'), function(){
            $opts = $this->get_settings();
            $val  = isset($opts['api_key']) ? $opts['api_key'] : '';
            printf('<input type="password" name="%s[api_key]" value="%s" class="regular-text" autocomplete="off" />', esc_attr(self::OPTION_KEY), esc_attr($val));
        }, 'acg_settings', 'acg_main');

        add_settings_field('model', __('Model', 'anchor-tools'), function(){
            $opts = $this->get_settings();
            $val  = isset($opts['model']) ? $opts['model'] : 'gpt-4o-mini';
            printf('<input type="text" name="%s[model]" value="%s" class="regular-text" />', esc_attr(self::OPTION_KEY), esc_attr($val));
            echo '<p class="description">Example: gpt-4o-mini, gpt-4.1, o4-mini.</p>';
        }, 'acg_settings', 'acg_main');

        add_settings_field('debug', __('Debug logging', 'anchor-tools'), function(){
            $opts = $this->get_settings();
            $val  = ! empty($opts['debug']);
            printf('<label><input type="checkbox" name="%s[debug]" value="1" %s> %s</label>',
                esc_attr(self::OPTION_KEY),
                checked($val, true, false),
                esc_html__('Write plugin events to error.log, useful on Kinsta', 'anchor-tools')
            );
        }, 'acg_settings', 'acg_main');
    }

    private function get_settings(){
        $opts = get_option(self::OPTION_KEY, []);
        if ( empty($opts) ) {
            // back-compat, read old option if present
            $legacy = get_option('anchor_schema_settings', []);
            if ( ! empty($legacy) ) { $opts = $legacy; }
        }
        return $opts;
    }

    public function sanitize_settings($input){
        $out = [];
        $out['api_key'] = isset($input['api_key']) ? sanitize_text_field($input['api_key']) : '';
        $out['model']   = isset($input['model']) ? sanitize_text_field($input['model']) : 'gpt-4o-mini';
        $out['debug']   = ! empty($input['debug']);
        return $out;
    }

    public function render_settings_page(){
        echo '<div class="wrap"><h1>' . esc_html__('Anchor Tools Settings', 'anchor-tools') . '</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields('acg_group');
        do_settings_sections('acg_settings');
        submit_button();
        echo '</form></div>';
    }

    /* Meta box */
    public function register_metabox(){
        $screens = apply_filters('acg_metabox_screens', [ 'post', 'page' ]);
        foreach($screens as $screen){
            add_meta_box(
                'acg_schema_box',
                __('Anchor Tools', 'anchor-tools'),
                [ $this, 'render_metabox' ],
                $screen,
                'normal',
                'high'
            );
        }
    }

    public function render_metabox($post){
        wp_nonce_field('acg_metabox', 'acg_nonce');
        $types = $this->get_schema_types();
        $items = get_post_meta($post->ID, self::META_KEY, true);
        if (!is_array($items)) { $items = []; }
        ?>
        <div id="acg-root" class="anchor-schema-wrap" data-post-id="<?php echo esc_attr($post->ID); ?>">
            <div class="anchor-schema-row">
                <label for="acg-type"><strong><?php esc_html_e('Schema type', 'anchor-tools'); ?></strong></label>
                <select id="acg-type" class="anchor-schema-control">
                    <?php foreach($types as $t): ?>
                        <option value="<?php echo esc_attr($t); ?>"><?php echo esc_html($t); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" id="acg-custom-type" class="anchor-schema-control" placeholder="<?php esc_attr_e('Custom type (optional)', 'anchor-tools'); ?>" />
            </div>
            <div class="anchor-schema-row">
                <label for="acg-raw"><strong><?php esc_html_e('Raw text input', 'anchor-tools'); ?></strong></label>
                <textarea id="acg-raw" class="widefat" rows="6" placeholder="<?php esc_attr_e('Paste plain text here, for example an FAQ list or product details...', 'anchor-tools'); ?>"></textarea>
            </div>
            <div class="anchor-schema-row">
                <button type="button" class="button button-primary" id="acg-generate"><?php esc_html_e('Generate schema', 'anchor-tools'); ?></button>
                <span class="anchor-schema-spinner" style="display:none;"></span>
            </div>

            <div class="anchor-schema-row" style="align-items:flex-start">
                <div style="min-width:120px"><strong><?php esc_html_e('Upload schema', 'anchor-tools'); ?></strong></div>
                <div style="flex:1">
                    <input type="file" id="acg-file" accept=".json,application/json" />
                    <button type="button" class="button" id="acg-upload"><?php esc_html_e('Validate and add', 'anchor-tools'); ?></button>
                    <div id="acg-upload-messages" style="margin-top:6px"></div>
                    <p class="description"><?php esc_html_e('Upload a JSON or JSON-LD file. It will be validated, then added without calling the generator.', 'anchor-tools'); ?></p>
                </div>
            </div>

            <hr />
            <h3><?php esc_html_e('Schemas for this post', 'anchor-tools'); ?></h3>
            <div id="acg-list" data-items='<?php echo esc_attr(wp_json_encode($items)); ?>'></div>
        </div>
        <?php
    }

    public function enqueue_assets($hook){
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ( $screen && (in_array($screen->base, [ 'post', 'page' ], true) || $screen->id === 'settings_page_anchor-tools' || $screen->id === 'tools_page_acg-wizard') ) {
            wp_enqueue_style('acg-admin', ACG_URL . 'assets/admin.css', [], ACG_VERSION);
        }
        if ( $screen && in_array($screen->base, [ 'post', 'page' ], true) ) {
            wp_enqueue_script('acg-admin', ACG_URL . 'assets/admin.js', [ 'jquery' ], ACG_VERSION, true);
            wp_localize_script('acg-admin', 'ACG', [
                'ajax'   => admin_url('admin-ajax.php'),
                'nonce'  => wp_create_nonce('acg_ajax'),
                'strings'=> [
                    'saving'   => __('Saving...', 'anchor-tools'),
                    'error'    => __('There was an error. See console for details.', 'anchor-tools'),
                    'validOk'  => __('Schema looks valid and has been added.', 'anchor-tools'),
                ]
            ]);
        }
        if ( $screen && $screen->id === 'tools_page_acg-wizard' ) {
            wp_enqueue_script('acg-wizard', ACG_URL . 'assets/wizard.js', [ 'jquery' ], ACG_VERSION, true);
            wp_localize_script('acg-wizard', 'ACG_WIZ', [
                'ajax'   => admin_url('admin-ajax.php'),
                'nonce'  => wp_create_nonce('acg_ajax'),
            ]);
        }
    }

    /* Wizard page */
    public function add_wizard_page(){
        add_submenu_page(
            'tools.php',
            __('Anchor Tools Wizard', 'anchor-tools'),
            __('Anchor Tools Wizard', 'anchor-tools'),
            'manage_options',
            'acg-wizard',
            [ $this, 'render_wizard_page' ]
        );
    }

    public function add_llms_tool_page(){
        add_submenu_page(
            'tools.php',
            __('LLMS.txt Tool', 'anchor-tools'),
            __('LLMS.txt Tool', 'anchor-tools'),
            'manage_options',
            'acg-llms',
            [ $this, 'render_llms_page' ]
        );
    }

    public function render_wizard_page(){
        $post_types = get_post_types([ 'public' => true ], 'objects');
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Anchor Tools Wizard', 'anchor-tools') . '</h1>';
        echo '<p>' . esc_html__('Scan posts, auto detect content from Divi modules and ACF fields, generate JSON-LD, edit, validate, then save per page.', 'anchor-tools') . '</p>';
        echo '<div id="acg-wizard">';
        echo '<div class="anchor-schema-row"><label>Post type</label><select id="wiz-post-type">';
        foreach($post_types as $pt){
            printf('<option value="%s">%s</option>', esc_attr($pt->name), esc_html($pt->labels->singular_name));
        }
        echo '</select>';
        echo '<label>Status</label><select id="wiz-status"><option value="publish">publish</option><option value="draft">draft</option></select>';
        echo '<label>Limit</label><input type="number" id="wiz-limit" value="20" min="1" max="200" style="width:80px" />';
        echo '<button class="button button-primary" id="wiz-load">Load posts</button></div>';
        echo '<div id="wiz-posts"></div>';
        echo '</div></div>';
    }

    public function render_llms_page(){
        if ( ! current_user_can('manage_options') ) { wp_die( esc_html__('You do not have permission to access this page.', 'anchor-tools') ); }

        $path    = $this->get_llms_file_path();
        $exists  = file_exists($path);
        $message = '';
        $error   = '';

        $contents = $this->get_llms_file_contents();
        $action   = isset($_POST['acg_llms_action']) ? sanitize_text_field($_POST['acg_llms_action']) : '';

        if ( isset($_POST['acg_llms_nonce']) && $action ) {
            check_admin_referer('acg_llms_save', 'acg_llms_nonce');
            if ( 'save' === $action ) {
                $content = isset($_POST['acg_llms_content']) ? wp_unslash($_POST['acg_llms_content']) : '';
                $content = str_replace([ "\r\n", "\r" ], "\n", $content);
                $result  = $this->save_llms_file( $content );
                if ( is_wp_error($result) ) {
                    $error = $result->get_error_message();
                } else {
                    $message = __('LLMS.txt has been saved.', 'anchor-tools');
                    $exists = true;
                    $contents = $content;
                }
            } elseif ( 'generate' === $action ) {
                $generated = $this->generate_llms_from_site();
                if ( is_wp_error($generated) ) {
                    $error = $generated->get_error_message();
                } else {
                    $contents = $generated;
                    $message  = __('Draft generated from site content. Review and click Save to publish.', 'anchor-tools');
                }
            }
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('LLMS.txt Tool', 'anchor-tools') . '</h1>';
        if ( $message ) {
            echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';
        }
        if ( $error ) {
            echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
        }
        echo '<p>' . esc_html__('Use this tool to create or edit the LLMS.txt file exposed from the root of your site. This file can describe how large language model crawlers may use your content.', 'anchor-tools') . '</p>';
        printf(
            '<p><strong>%s</strong> %s</p>',
            esc_html__('File location:', 'anchor-tools'),
            esc_html($path)
        );
        if ( $exists ) {
            echo '<p>' . esc_html__('An LLMS.txt file currently exists. Saving will overwrite it.', 'anchor-tools') . '</p>';
        } else {
            echo '<p>' . esc_html__('No LLMS.txt file detected. Saving will create a new one.', 'anchor-tools') . '</p>';
        }
        echo '<form method="post">';
        wp_nonce_field('acg_llms_save', 'acg_llms_nonce');
        echo '<textarea name="acg_llms_content" rows="15" class="large-text code" spellcheck="false">';
        echo esc_textarea($contents);
        echo '</textarea>';
        echo '<p class="submit">';
        echo '<button type="submit" name="acg_llms_action" value="save" class="button button-primary">' . esc_html__('Save LLMS.txt', 'anchor-tools') . '</button> ';
        echo '<button type="submit" name="acg_llms_action" value="generate" class="button">' . esc_html__('Generate from site content', 'anchor-tools') . '</button>';
        echo '</p>';
        echo '</form>';
        echo '</div>';
    }

    /* AJAX: posts list */
    public function ajax_list_posts(){
        check_ajax_referer('acg_ajax', 'nonce');
        if ( ! current_user_can('edit_posts') ) { wp_send_json_error('no_cap'); }
        $pt = sanitize_text_field($_POST['post_type'] ?? 'page');
        $status = sanitize_text_field($_POST['status'] ?? 'publish');
        $limit = max(1, min(200, intval($_POST['limit'] ?? 20)));
        $q = new WP_Query([
            'post_type' => $pt,
            'post_status' => $status,
            'posts_per_page' => $limit,
            'orderby' => 'date',
            'order' => 'DESC',
            'no_found_rows' => true,
        ]);
        $rows = [];
        foreach($q->posts as $p){
            $rows[] = [ 'ID' => $p->ID, 'title' => $p->post_title, 'type' => $p->post_type, 'status' => $p->post_status, 'edit' => get_edit_post_link($p->ID, '') ];
        }
        wp_send_json_success([ 'posts' => $rows ]);
    }

    /* AJAX: scan a post */
    public function ajax_scan_post(){
        check_ajax_referer('acg_ajax', 'nonce');
        if ( ! current_user_can('edit_posts') ) { wp_send_json_error('no_cap'); }
        $post_id = absint($_POST['post_id'] ?? 0);
        if ( ! $post_id ) { wp_send_json_error([ 'message' => 'Missing post_id' ]); }
        $scan = $this->detect_content_for_post($post_id);
        wp_send_json_success( $scan );
    }

    /* AJAX: generate for a post */
    public function ajax_generate_for_post(){
        check_ajax_referer('acg_ajax', 'nonce');
        if ( ! current_user_can('edit_posts') ) { wp_send_json_error('no_cap'); }
        $post_id = absint($_POST['post_id'] ?? 0);
        $type    = sanitize_text_field($_POST['type'] ?? '');
        $raw     = wp_kses_post($_POST['raw'] ?? '');
        if ( ! $post_id || ! $type || ! $raw ) { wp_send_json_error([ 'message' => 'Missing fields' ]); }

        $settings = $this->get_settings();
        $api_key  = isset($settings['api_key']) ? trim($settings['api_key']) : '';
        $model    = isset($settings['model']) ? trim($settings['model']) : 'gpt-4o-mini';
        if ( empty($api_key) ) { wp_send_json_error([ 'message' => 'API key not configured' ]); }

        $prompt = $this->build_prompt($type, wp_strip_all_tags($raw));
        $response = $this->call_openai($api_key, $model, $prompt);
        if ( is_wp_error($response) ) { wp_send_json_error([ 'message' => $response->get_error_message() ]); }
        $json = $this->extract_json($response);
        if ( empty($json) ) { wp_send_json_error([ 'message' => 'No JSON returned from model' ]); }
        $min = $this->minify_json($json);
        if ( empty($min) ) { $min = $json; }
        wp_send_json_success([ 'json' => $json, 'min' => $min ]);
    }

    /* AJAX: validate and save */
    public function ajax_save_for_post(){
        check_ajax_referer('acg_ajax', 'nonce');
        if ( ! current_user_can('edit_posts') ) { wp_send_json_error('no_cap'); }
        $post_id = absint($_POST['post_id'] ?? 0);
        $label   = sanitize_text_field($_POST['label'] ?? '');
        $json    = wp_unslash($_POST['json'] ?? '');
        if ( ! $post_id || ! $json ) { wp_send_json_error([ 'message' => 'Missing fields' ]); }

        $valid = $this->validate_schema_json($json);
        if ( ! empty($valid['errors']) ) {
            wp_send_json_error([ 'errors' => $valid['errors'], 'warnings' => $valid['warnings'] ]);
        }
        $min = $this->minify_json( $valid['normalized_json'] );
        $type = $valid['primary_type'] ?: 'Thing';
        $items = get_post_meta($post_id, self::META_KEY, true);
        if ( ! is_array($items) ) { $items = []; }
        $id = wp_generate_uuid4();
        $items[] = [
            'id' => $id,
            'type' => $type,
            'raw_text' => '',
            'json' => $valid['normalized_json'],
            'min_json' => $min ?: $valid['normalized_json'],
            'updated' => current_time('mysql'),
            'enabled' => true,
            'label' => $label ?: ($type . ' schema'),
        ];
        update_post_meta($post_id, self::META_KEY, $items);
        wp_send_json_success([ 'id' => $id ]);
    }

    /* Meta box AJAX */
    public function ajax_generate(){
        check_ajax_referer('acg_ajax', 'nonce');
        if ( ! current_user_can('edit_posts') ) { wp_send_json_error('no_cap'); }

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        $type    = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : '';
        $custom  = isset($_POST['custom']) ? sanitize_text_field($_POST['custom']) : '';
        $raw     = isset($_POST['raw']) ? wp_kses_post($_POST['raw']) : '';

        if ( empty($post_id) || empty($raw) ) { wp_send_json_error([ 'message' => 'Missing required fields' ]); }
        if ( ! empty($custom) ) { $type = $custom; }
        if ( empty($type) ) { $type = 'Thing'; }

        $settings = $this->get_settings();
        $api_key  = isset($settings['api_key']) ? trim($settings['api_key']) : '';
        $model    = isset($settings['model']) ? trim($settings['model']) : 'gpt-4o-mini';
        if ( empty($api_key) ) { wp_send_json_error([ 'message' => 'API key not configured' ]); }

        $prompt = $this->build_prompt($type, wp_strip_all_tags($raw));
        $response = $this->call_openai($api_key, $model, $prompt);

        if ( is_wp_error($response) ) { wp_send_json_error([ 'message' => $response->get_error_message() ]); }

        $json = $this->extract_json($response);
        if ( empty($json) ) { wp_send_json_error([ 'message' => 'No JSON returned from model' ]); }

        $min = $this->minify_json($json);
        if ( empty($min) ) { $min = $json; }

        $items = get_post_meta($post_id, self::META_KEY, true);
        if (!is_array($items)) { $items = []; }

        $id = wp_generate_uuid4();
        $now = current_time('mysql');
        $item = [
            'id'        => $id,
            'type'      => $type,
            'raw_text'  => $raw,
            'json'      => $json,
            'min_json'  => $min,
            'updated'   => $now,
            'enabled'   => true,
            'label'     => $type . ' schema',
        ];
        $items[] = $item;
        update_post_meta($post_id, self::META_KEY, $items);

        ACG_Logger::log('ajax_generate:success', [ 'post_id' => $post_id, 'item_id' => $id ]);
        wp_send_json_success([ 'item' => $item ]);
    }

    public function ajax_update_item(){
        check_ajax_referer('acg_ajax', 'nonce');
        if ( ! current_user_can('edit_posts') ) { wp_send_json_error('no_cap'); }

        $post_id = absint($_POST['post_id'] ?? 0);
        $id      = sanitize_text_field($_POST['id'] ?? '');
        $data    = wp_unslash($_POST['data'] ?? []);

        $items = get_post_meta($post_id, self::META_KEY, true);
        if (!is_array($items)) { wp_send_json_error([ 'message' => 'No items' ]); }

        foreach($items as &$it){
            if ($it['id'] === $id){
                if ( isset($data['label']) )   { $it['label'] = sanitize_text_field($data['label']); }
                if ( isset($data['enabled']) ) { $it['enabled'] = filter_var($data['enabled'], FILTER_VALIDATE_BOOLEAN); }
                if ( isset($data['json']) )    { $it['json'] = wp_kses_post($data['json']); $it['min_json'] = $this->minify_json($it['json']); }
                $it['updated'] = current_time('mysql');
                break;
            }
        }
        update_post_meta($post_id, self::META_KEY, $items);
        wp_send_json_success([ 'items' => $items ]);
    }

    public function ajax_delete_item(){
        check_ajax_referer('acg_ajax', 'nonce');
        if ( ! current_user_can('edit_posts') ) { wp_send_json_error('no_cap'); }
        $post_id = absint($_POST['post_id'] ?? 0);
        $id      = sanitize_text_field($_POST['id'] ?? '');
        $items = get_post_meta($post_id, self::META_KEY, true);
        if (!is_array($items)) { $items = []; }
        $items = array_values(array_filter($items, function($it) use ($id){ return $it['id'] !== $id; }));
        update_post_meta($post_id, self::META_KEY, $items);
        wp_send_json_success([ 'items' => $items ]);
    }

    /* Detection and helpers */
    private function detect_content_for_post($post_id){
        $p = get_post($post_id);
        if ( ! $p ) { return [ 'type' => 'Thing', 'raw' => '', 'sources' => [] ]; }
        $sources = [];

        $title = $p->post_title;
        $excerpt = $p->post_excerpt;
        $content = $p->post_content;

        // Divi toggles as FAQ
        $faq_blocks = [];
        if ( strpos($content, '[et_pb_toggle') !== false ){
            if ( preg_match_all('/\[et_pb_toggle[^\]]*title=\"([^\"]+)\"[^\]]*\](.*?)\[\/et_pb_toggle\]/s', $content, $m, PREG_SET_ORDER) ){
                foreach($m as $match){
                    $q = wp_strip_all_tags($match[1]);
                    $a = wp_strip_all_tags($match[2]);
                    if ($q && $a){ $faq_blocks[] = [ 'question' => $q, 'answer' => $a ]; }
                }
            }
        }

        // Strip shortcodes for general text
        $stripped = strip_shortcodes($content);
        $plain = trim( wp_strip_all_tags( $stripped ) );

        // ACF fields
        $acf = [];
        if ( function_exists('get_fields') ){
            $fields = get_fields($post_id);
            if ( is_array($fields) ){
                foreach($fields as $k => $v){
                    if ( is_string($v) && $v ){ $acf[$k] = wp_strip_all_tags($v); }
                    if ( is_array($v) && isset($v['address']) ){ $acf[$k] = $v['address']; }
                }
            }
        }

        // Heuristic type detection
        $type = 'WebPage';
        if ( count($faq_blocks) >= 2 ) { $type = 'FAQPage'; }
        elseif ( $p->post_type === 'product' || get_post_meta($post_id, '_sku', true) ) { $type = 'Product'; }
        elseif ( ! empty($acf['business_name']) || ! empty($acf['address']) || ! empty($acf['phone']) ) { $type = 'LocalBusiness'; }
        elseif ( $p->post_type === 'post' ) { $type = 'Article'; }

        // Build raw text
        $raw_parts = [];
        if ( $title )   { $raw_parts[] = "Title: " . $title; }
        if ( $excerpt ) { $raw_parts[] = "Excerpt: " . $excerpt; }
        if ( $plain )   { $raw_parts[] = "Body: " . substr($plain, 0, 4000); }
        if ( $acf )     { $raw_parts[] = "ACF: " . wp_json_encode($acf, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
        if ( $faq_blocks ){
            $raw_parts[] = "FAQ:\n" . implode("\n", array_map(function($qa){ return "Q: " . $qa['question'] . "\nA: " . $qa['answer']; }, $faq_blocks));
        }
        $raw = implode("\n\n", $raw_parts);

        $sources['title'] = $title;
        $sources['excerpt'] = $excerpt;
        $sources['has_divi_faq'] = (bool)$faq_blocks;
        $sources['acf_fields'] = array_keys($acf);

        return [ 'type' => $type, 'raw' => $raw, 'sources' => $sources ];
    }

    private function get_schema_types(){
        $defaults = [
            'FAQPage','Article','BlogPosting','NewsArticle','LocalBusiness','Organization','Person','WebSite','WebPage','AboutPage','ContactPage','MedicalWebPage','CollectionPage','SearchAction','BreadcrumbList','Event','Product','Offer','Review','HowTo','VideoObject','AudioObject','ImageObject','Service','Course','Recipe','SoftwareApplication','JobPosting','Book','Movie','PodcastEpisode','PodcastSeries','Dentist','Clinic','MedicalCondition','MedicalProcedure','MedicalTherapy','Physician','Place','Restaurant','Store','Thing'
        ];
        return apply_filters('acg_schema_types', $defaults);
    }

    private function get_llms_file_path(){
        return trailingslashit( ABSPATH ) . 'LLMS.txt';
    }

    private function get_llms_file_contents(){
        $path = $this->get_llms_file_path();
        if ( file_exists($path) && is_readable($path) ) {
            $contents = file_get_contents($path);
            if ( false !== $contents ) {
                return $contents;
            }
        }
        return '';
    }

    private function save_llms_file( $content ){
        $path = $this->get_llms_file_path();
        $dir  = dirname($path);
        if ( ! wp_is_writable($dir) ) {
            return new WP_Error('acg_llms_unwritable', sprintf(__('Directory %s is not writable.', 'anchor-tools'), $dir));
        }
        $result = file_put_contents($path, $content);
        if ( false === $result ) {
            return new WP_Error('acg_llms_write_failed', __('Could not write LLMS.txt. Check file permissions.', 'anchor-tools'));
        }
        if ( function_exists('chmod') ) {
            @chmod($path, 0644);
        }
        return true;
    }

    private function generate_llms_from_site(){
        $home_url   = home_url('/');
        $site_name  = get_bloginfo('name');
        $owner      = $site_name ? $site_name : wp_parse_url( $home_url, PHP_URL_HOST );
        $contact    = get_option('admin_email');
        $home_post  = $this->get_home_page_post();
        $tagline    = get_bloginfo('description');
        $home_text  = $home_post ? $this->extract_text_from_post( $home_post ) : '';
        if ( empty( $home_text ) && $tagline ) {
            $home_text = $tagline;
        }

        $purpose     = $this->summarize_text_block( $home_text, 200 );
        $description = $this->summarize_text_block( $home_text, 320 );

        if ( ! $purpose ) {
            $purpose = $tagline ? $tagline : sprintf( __('Provide trustworthy information from %s.', 'anchor-tools'), $owner ? $owner : __('this site', 'anchor-tools') );
        }
        if ( ! $description ) {
            $description = $purpose;
        }

        $sections   = $this->detect_high_value_sections();
        $low_value  = $this->get_low_value_sections();
        $llm_use    = __('permitted for summarization, search, question answering, and educational output.', 'anchor-tools');
        $llm_limits = __('Do not generate content claiming official partnerships, legal advice, or medical advice.', 'anchor-tools');
        $citation   = '"' . ( $owner ? $owner : __('Website', 'anchor-tools') ) . ', ' . untrailingslashit( $home_url ) . '"';
        $frequency  = $this->estimate_update_frequency_label();

        $lines = [];
        $lines[] = '# LLMS.txt';
        $lines[] = '# Guidelines for Large Language Model indexing';
        $lines[] = '';
        $lines[] = 'website: ' . untrailingslashit( $home_url );
        $lines[] = 'owner: ' . ( $owner ? $owner : __('Website owner', 'anchor-tools') );
        if ( $contact ) {
            $lines[] = 'contact: ' . $contact;
        }
        $lines[] = 'purpose: ' . $purpose;
        $lines[] = '';
        $lines[] = 'indexing: allow';
        $lines[] = '';
        $lines[] = '# High value content sections';
        foreach ( $sections as $section ) {
            $lines[] = 'allow: ' . $section;
        }
        $lines[] = '';
        $lines[] = '# Low value or private sections';
        foreach ( $low_value as $section ) {
            $lines[] = 'disallow: ' . $section;
        }
        $lines[] = '';
        $lines[] = '# Content description';
        $lines[] = 'description: ' . $description;
        $lines[] = '';
        $lines[] = '# Content usage policy';
        $lines[] = 'llm_use: ' . $llm_use;
        $lines[] = 'llm_use_restrictions: ' . $llm_limits;
        $lines[] = '';
        $lines[] = '# Preferred citation form when referencing this site';
        $lines[] = 'citation: ' . $citation;
        $lines[] = '';
        $lines[] = '# Update frequency notice';
        $lines[] = 'update_frequency: ' . $frequency;

        return trim( implode( "\n", $lines ) );
    }

    private function get_home_page_post(){
        $front = (int) get_option('page_on_front');
        if ( $front ) {
            $page = get_post( $front );
            if ( $page ) {
                return $page;
            }
        }
        $posts_page = (int) get_option('page_for_posts');
        if ( $posts_page ) {
            $page = get_post( $posts_page );
            if ( $page ) {
                return $page;
            }
        }
        $pages = get_pages([
            'sort_column' => 'menu_order',
            'sort_order'  => 'ASC',
            'number'      => 1,
        ]);
        if ( ! empty( $pages ) ) {
            return $pages[0];
        }
        $recent_post = get_posts([
            'post_type'        => 'post',
            'post_status'      => 'publish',
            'posts_per_page'   => 1,
            'orderby'          => 'date',
            'order'            => 'DESC',
            'suppress_filters' => true,
        ]);
        return $recent_post ? $recent_post[0] : null;
    }

    private function extract_text_from_post( $post ){
        $parts = [];
        $divi_text = $this->extract_divi_layout_text( $post->ID );
        if ( $divi_text ) {
            $parts[] = $divi_text;
        }
        if ( ! empty( $post->post_excerpt ) ) {
            $parts[] = $post->post_excerpt;
        }
        if ( ! empty( $post->post_content ) ) {
            $parts[] = strip_shortcodes( $post->post_content );
        }
        $acf_values = $this->collect_acf_text( $post->ID );
        if ( $acf_values ) {
            $parts[] = implode( "\n", $acf_values );
        }
        $parts = array_filter( array_map( function( $value ){
            return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $value ) ) );
        }, $parts ) );
        return trim( implode( "\n", $parts ) );
    }

    private function collect_acf_text( $post_id ){
        if ( ! function_exists('get_fields') ) {
            return [];
        }
        $fields = get_fields( $post_id );
        if ( ! is_array( $fields ) ) {
            return [];
        }
        $chunks = [];
        foreach ( $fields as $value ) {
            $this->flatten_text_value( $value, $chunks );
        }
        return $chunks;
    }

    private function flatten_text_value( $value, array &$chunks ){
        if ( is_string( $value ) ) {
            $clean = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $value ) ) );
            if ( '' !== $clean ) {
                $chunks[] = $clean;
            }
            return;
        }
        if ( is_array( $value ) ) {
            foreach ( $value as $child ) {
                $this->flatten_text_value( $child, $chunks );
            }
        }
    }

    private function extract_divi_layout_text( $post_id ){
        $raw = get_post_meta( $post_id, '_et_pb_layout_data', true );
        if ( empty( $raw ) ) {
            return '';
        }
        $decoded = json_decode( $raw, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            $decoded = json_decode( base64_decode( $raw ), true );
        }
        if ( ! is_array( $decoded ) ) {
            return '';
        }
        $chunks = [];
        $this->walk_divi_layout( $decoded, $chunks );
        if ( empty( $chunks ) ) {
            return '';
        }
        $unique = array_values( array_unique( $chunks ) );
        return trim( implode( "\n", $unique ) );
    }

    private function walk_divi_layout( $node, array &$chunks ){
        if ( is_string( $node ) ) {
            $clean = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $node ) ) );
            if ( '' !== $clean ) {
                $chunks[] = $clean;
            }
            return;
        }
        if ( ! is_array( $node ) ) {
            return;
        }
        foreach ( $node as $key => $value ) {
            if ( is_string( $value ) && $this->is_textual_divi_key( $key ) ) {
                $clean = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $value ) ) );
                if ( '' !== $clean ) {
                    $chunks[] = $clean;
                }
                continue;
            }
            if ( is_array( $value ) || is_string( $value ) ) {
                $this->walk_divi_layout( $value, $chunks );
            }
        }
    }

    private function is_textual_divi_key( $key ){
        $key = strtolower( (string) $key );
        $explicit = [
            'content','text','title','subtitle','description','body','body_content','button_text','quote','quote_content','address','excerpt','heading','subheading','blurb','blurb_content','cta_text','cta_content','price','name','job_title','caption','alt','content_new','main_content','list_content','link_text','label','paragraph_text'
        ];
        if ( in_array( $key, $explicit, true ) ) {
            return true;
        }
        if ( preg_match( '/(^|_)(content|text|description|body|caption|blurb)(_|\b)/', $key ) ) {
            return true;
        }
        return false;
    }

    private function summarize_text_block( $text, $limit = 200 ){
        $clean = trim( preg_replace( '/\s+/', ' ', $text ) );
        if ( '' === $clean ) {
            return '';
        }
        if ( function_exists( 'wp_html_excerpt' ) ) {
            return trim( wp_html_excerpt( $clean, $limit, '...' ) );
        }
        if ( strlen( $clean ) > $limit ) {
            return substr( $clean, 0, $limit ) . '...';
        }
        return $clean;
    }

    private function detect_high_value_sections(){
        $home_url = home_url('/');
        $home_host = wp_parse_url( $home_url, PHP_URL_HOST );
        $sections = [];

        if ( function_exists('get_nav_menu_locations') && function_exists('wp_get_nav_menu_items') ) {
            $locations = get_nav_menu_locations();
            if ( is_array( $locations ) ) {
                $menu_ids = array_filter( array_unique( array_values( $locations ) ) );
                foreach ( $menu_ids as $menu_id ) {
                    $items = wp_get_nav_menu_items( $menu_id );
                    if ( empty( $items ) ) {
                        continue;
                    }
                    foreach ( $items as $item ) {
                        if ( empty( $item->url ) ) {
                            continue;
                        }
                        $path = $this->normalize_section_path( $item->url, $home_host );
                        if ( $path && ! in_array( $path, $sections, true ) ) {
                            $sections[] = $path;
                        }
                    }
                }
            }
        }

        if ( count( $sections ) < 3 ) {
            $pages = get_pages([
                'parent'      => 0,
                'sort_column' => 'menu_order',
                'sort_order'  => 'ASC',
                'number'      => 6,
            ]);
            foreach ( $pages as $page ) {
                $path = $this->normalize_section_path( get_permalink( $page ), $home_host );
                if ( $path && ! in_array( $path, $sections, true ) ) {
                    $sections[] = $path;
                }
            }
        }

        $posts_page = (int) get_option('page_for_posts');
        if ( $posts_page ) {
            $path = $this->normalize_section_path( get_permalink( $posts_page ), $home_host );
            if ( $path && ! in_array( $path, $sections, true ) ) {
                $sections[] = $path;
            }
        } elseif ( get_option('show_on_front') !== 'page' ) {
            $sections[] = '/blog/';
        }

        if ( empty( $sections ) ) {
            $sections[] = '/';
        }

        return array_slice( $sections, 0, 10 );
    }

    private function normalize_section_path( $url, $home_host ){
        if ( empty( $url ) ) {
            return '';
        }
        $parts = wp_parse_url( $url );
        if ( ! $parts ) {
            return '';
        }
        if ( isset( $parts['host'] ) && $parts['host'] && $home_host && $parts['host'] !== $home_host ) {
            return '';
        }
        $path = isset( $parts['path'] ) ? $parts['path'] : '/';
        if ( empty( $path ) || '/' === $path ) {
            return '';
        }
        $segments = explode( '/', trim( $path, '/' ) );
        $first = isset( $segments[0] ) ? $segments[0] : '';
        if ( '' === $first ) {
            return '';
        }
        return '/' . $first . '/';
    }

    private function get_low_value_sections(){
        $defaults = [
            '/wp-admin/',
            '/wp-login.php',
            '/wp-json/',
            '/xmlrpc.php',
            '/cart/',
            '/checkout/',
            '/my-account/',
            '/account/',
            '/private/',
            '/temp/',
            '/tmp/',
            '/feed/',
            '/search/',
        ];
        return apply_filters( 'acg_llms_low_value_sections', $defaults );
    }

    private function estimate_update_frequency_label(){
        $latest = get_posts([
            'post_type'        => 'any',
            'post_status'      => 'publish',
            'posts_per_page'   => 1,
            'orderby'          => 'modified',
            'order'            => 'DESC',
            'suppress_filters' => true,
        ]);
        if ( empty( $latest ) ) {
            return 'Monthly';
        }
        $timestamp = get_post_modified_time( 'U', true, $latest[0] );
        if ( ! $timestamp ) {
            $timestamp = get_post_time( 'U', true, $latest[0] );
        }
        if ( ! $timestamp ) {
            return 'Monthly';
        }
        $diff = time() - $timestamp;
        if ( $diff <= WEEK_IN_SECONDS * 2 ) {
            return 'Weekly';
        }
        if ( $diff <= MONTH_IN_SECONDS * 2 ) {
            return 'Monthly';
        }
        if ( $diff <= DAY_IN_SECONDS * 120 ) {
            return 'Quarterly';
        }
        if ( $diff <= DAY_IN_SECONDS * 240 ) {
            return 'Biannually';
        }
        return 'Annually';
    }

    private function build_prompt($type, $raw){
        $instructions = "You are a strict JSON-LD generator. Produce only valid JSON, no code fences, no comments. Output a single JSON object for schema.org with @context https://schema.org and @type $type. Infer properties from the provided raw text. Validate against schema.org vocabulary. Use English keys. Do not include HTML. Ensure it can be embedded in a <script type=\"application/ld+json\"> tag.\n\nRaw text:\n$raw";
        return $instructions;
    }

    private function call_openai($api_key, $model, $prompt){
        $body = [
            'model' => $model,
            'messages' => [
                [ 'role' => 'system', 'content' => 'You generate clean JSON-LD only.' ],
                [ 'role' => 'user', 'content' => $prompt ],
            ],
            'temperature' => 0.1,
            'response_format' => [ 'type' => 'json_object' ],
        ];
        ACG_Logger::log('openai:request', [ 'endpoint' => 'chat.completions', 'model' => $model ]);
        $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body'    => wp_json_encode( $body ),
            'timeout' => 45,
        ]);
        if ( is_wp_error($response) ) {
            ACG_Logger::log('openai:error', [ 'wp_error' => $response->get_error_message() ]);
            return $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        $raw  = wp_remote_retrieve_body($response);
        ACG_Logger::log('openai:response', [ 'code' => $code, 'body_preview' => substr($raw, 0, 400) ]);
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error('openai_http', 'OpenAI error ' . $code . ': ' . $raw);
        }
        $data = json_decode($raw, true);
        if ( ! $data || empty($data['choices'][0]['message']['content']) ) {
            return new WP_Error('openai_parse', 'Invalid response from model');
        }
        return $data['choices'][0]['message']['content'];
    }

    private function extract_json($content){
        $trim = trim($content);
        json_decode($trim);
        if ( json_last_error() === JSON_ERROR_NONE ) { return $trim; }
        if ( preg_match('/\{[\s\S]*\}/', $content, $m) ) { return $m[0]; }
        return '';
    }

    private function minify_json($json){
        $decoded = json_decode($json, true);
        if ( json_last_error() !== JSON_ERROR_NONE ) { return ''; }
        return wp_json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function validate_schema_json( $content ){
        $errors = [];
        $warnings = [];

        $decoded = json_decode( $content, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            $errors[] = 'JSON parse error: ' . json_last_error_msg();
            return [ 'errors' => $errors, 'warnings' => $warnings, 'normalized_json' => '', 'primary_type' => '' ];
        }

        $objects = [];
        if ( is_array($decoded) && isset($decoded['@context']) ) {
            $objects = [ $decoded ];
        } elseif ( is_array($decoded) && array_keys($decoded) === range(0, count($decoded) - 1) ) {
            $objects = $decoded;
        } else {
            $errors[] = 'Expected a JSON object with @context and @type or an array of such objects.';
        }

        $allowed_types = $this->get_schema_types();
        $primary_type  = '';

        foreach ( $objects as $idx => &$obj ) {
            if ( ! is_array($obj) ) { $errors[] = 'Item ' . ($idx+1) . ' is not an object'; continue; }
            if ( empty($obj['@context']) ) {
                $warnings[] = 'Item ' . ($idx+1) . ': @context missing, defaulting to https://schema.org';
                $obj['@context'] = 'https://schema.org';
            }
            if ( empty($obj['@type']) ) {
                $errors[] = 'Item ' . ($idx+1) . ': @type is required';
                continue;
            }
            if ( ! $primary_type ) {
                $primary_type = is_array($obj['@type']) ? (string)reset($obj['@type']) : (string)$obj['@type'];
            }
            $tlist = (array)$obj['@type'];
            foreach($tlist as $t){
                if ( ! in_array($t, $allowed_types, true) ) {
                    $warnings[] = 'Item ' . ($idx+1) . ': @type ' . $t . ' not in default list, verify against schema.org.';
                }
            }
        }
        unset($obj);

        if ( ! empty($errors) ) {
            return [ 'errors' => $errors, 'warnings' => $warnings, 'normalized_json' => '', 'primary_type' => $primary_type ];
        }

        $normalized = wp_json_encode( $objects, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( is_array($decoded) && isset($decoded['@context']) ) {
            $normalized = wp_json_encode( $objects[0], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        }

        return [ 'errors' => [], 'warnings' => $warnings, 'normalized_json' => $normalized, 'primary_type' => $primary_type ];
    }
}
