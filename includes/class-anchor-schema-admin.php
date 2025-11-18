<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Anchor_Schema_Admin {

    const OPTION_KEY = 'anchor_schema_settings';
    const META_KEY   = '_anchor_schema_items';

    public function __construct(){
        add_action('admin_menu', [ $this, 'add_settings_page' ]);
        add_action('admin_init', [ $this, 'register_settings' ]);
        add_action('add_meta_boxes', [ $this, 'register_metabox' ]);
        add_action('admin_enqueue_scripts', [ $this, 'enqueue_assets' ]);

        add_action('wp_ajax_anchor_schema_generate', [ $this, 'ajax_generate' ]);
        add_action('wp_ajax_anchor_schema_update_item', [ $this, 'ajax_update_item' ]);
        add_action('wp_ajax_anchor_schema_delete_item', [ $this, 'ajax_delete_item' ]);
        add_action('wp_ajax_anchor_schema_upload',   [ $this, 'ajax_upload' ]);
    }

    public function add_settings_page(){
        add_options_page(
            __('Anchor Schema', 'anchor-schema'),
            __('Anchor Schema', 'anchor-schema'),
            'manage_options',
            'anchor-schema',
            [ $this, 'render_settings_page' ]
        );
    }

    public function register_settings(){
        register_setting( 'anchor_schema_group', self::OPTION_KEY, [ $this, 'sanitize_settings' ] );

        add_settings_section('anchor_schema_main', __('API Configuration', 'anchor-schema'), function(){
            echo '<p>' . esc_html__('Configure the OpenAI API key and model. Enable debug to log to PHP error log.', 'anchor-schema') . '</p>';
        }, 'anchor_schema_settings');

        add_settings_field('api_key', __('OpenAI API Key', 'anchor-schema'), function(){
            $opts = get_option(self::OPTION_KEY, []);
            $val  = isset($opts['api_key']) ? $opts['api_key'] : '';
            printf('<input type="password" name="%s[api_key]" value="%s" class="regular-text" autocomplete="off" />', esc_attr(self::OPTION_KEY), esc_attr($val));
        }, 'anchor_schema_settings', 'anchor_schema_main');

        add_settings_field('model', __('Model', 'anchor-schema'), function(){
            $opts = get_option(self::OPTION_KEY, []);
            $val  = isset($opts['model']) ? $opts['model'] : 'gpt-4o-mini';
            printf('<input type="text" name="%s[model]" value="%s" class="regular-text" />', esc_attr(self::OPTION_KEY), esc_attr($val));
            echo '<p class="description">Example: gpt-4o-mini, gpt-4.1, o4-mini.</p>';
        }, 'anchor_schema_settings', 'anchor_schema_main');

        add_settings_field('debug', __('Debug logging to Kinsta', 'anchor-schema'), function(){
            $opts = get_option(self::OPTION_KEY, []);
            $val  = ! empty($opts['debug']);
            printf('<label><input type="checkbox" name="%s[debug]" value="1" %s> %s</label>',
                esc_attr(self::OPTION_KEY),
                checked($val, true, false),
                esc_html__('Write plugin events to error.log. View in MyKinsta Logs tab.', 'anchor-schema')
            );
        }, 'anchor_schema_settings', 'anchor_schema_main');
    }

    public function sanitize_settings($input){
        $out = [];
        $out['api_key'] = isset($input['api_key']) ? sanitize_text_field($input['api_key']) : '';
        $out['model']   = isset($input['model']) ? sanitize_text_field($input['model']) : 'gpt-4o-mini';
        $out['debug']   = ! empty($input['debug']);
        return $out;
    }

    public function render_settings_page(){
        echo '<div class="wrap"><h1>' . esc_html__('Anchor Schema Settings', 'anchor-schema') . '</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields('anchor_schema_group');
        do_settings_sections('anchor_schema_settings');
        submit_button();
        echo '</form></div>';
    }

    public function register_metabox(){
        $screens = apply_filters('anchor_schema_metabox_screens', [ 'post', 'page' ]);
        foreach($screens as $screen){
            add_meta_box(
                'anchor_schema_box',
                __('Anchor Schema', 'anchor-schema'),
                [ $this, 'render_metabox' ],
                $screen,
                'normal',
                'high'
            );
        }
    }

    public function render_metabox($post){
        wp_nonce_field('anchor_schema_metabox', 'anchor_schema_nonce');
        $types = $this->get_schema_types();
        $items = get_post_meta($post->ID, self::META_KEY, true);
        if (!is_array($items)) { $items = []; }
        ?>
        <div id="anchor-schema-root" class="anchor-schema-wrap" data-post-id="<?php echo esc_attr($post->ID); ?>">
            <div class="anchor-schema-row">
                <label for="anchor-schema-type"><strong><?php esc_html_e('Schema type', 'anchor-schema'); ?></strong></label>
                <select id="anchor-schema-type" class="anchor-schema-control">
                    <?php foreach($types as $t): ?>
                        <option value="<?php echo esc_attr($t); ?>"><?php echo esc_html($t); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" id="anchor-schema-custom-type" class="anchor-schema-control" placeholder="<?php esc_attr_e('Custom type (optional)', 'anchor-schema'); ?>" />
            </div>
            <div class="anchor-schema-row">
                <label for="anchor-schema-raw"><strong><?php esc_html_e('Raw text input', 'anchor-schema'); ?></strong></label>
                <textarea id="anchor-schema-raw" class="widefat" rows="6" placeholder="<?php esc_attr_e('Paste plain text here, for example an FAQ list or product details...', 'anchor-schema'); ?>"></textarea>
            </div>
            <div class="anchor-schema-row">
                <button type="button" class="button button-primary" id="anchor-schema-generate"><?php esc_html_e('Generate schema', 'anchor-schema'); ?></button>
                <span class="anchor-schema-spinner" style="display:none;"></span>
            </div>

            <div class="anchor-schema-row" style="align-items:flex-start">
                <div style="min-width:120px"><strong><?php esc_html_e('Upload schema', 'anchor-schema'); ?></strong></div>
                <div style="flex:1">
                    <input type="file" id="anchor-schema-file" accept=".json,application/json" />
                    <button type="button" class="button" id="anchor-schema-upload"><?php esc_html_e('Validate and add', 'anchor-schema'); ?></button>
                    <div id="anchor-schema-upload-messages" style="margin-top:6px"></div>
                    <p class="description"><?php esc_html_e('Upload a JSON or JSON-LD file. It will be validated, then added without calling the generator.', 'anchor-schema'); ?></p>
                </div>
            </div>

            <hr />
            <h3><?php esc_html_e('Generated or uploaded schemas for this post', 'anchor-schema'); ?></h3>
            <div id="anchor-schema-list" data-items='<?php echo esc_attr(wp_json_encode($items)); ?>'></div>
        </div>
        <?php
    }

    public function enqueue_assets($hook){
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ( $screen && (in_array($screen->base, [ 'post', 'page' ], true) || $screen->id === 'settings_page_anchor-schema') ) {
            wp_enqueue_style('anchor-schema-admin', ANCHOR_SCHEMA_URL . 'assets/admin.css', [], ANCHOR_SCHEMA_VERSION);
        }
        if ( $screen && in_array($screen->base, [ 'post', 'page' ], true) ) {
            wp_enqueue_script('anchor-schema-admin', ANCHOR_SCHEMA_URL . 'assets/admin.js', [ 'jquery' ], ANCHOR_SCHEMA_VERSION, true);
            wp_localize_script('anchor-schema-admin', 'ANCHOR_SCHEMA', [
                'ajax'   => admin_url('admin-ajax.php'),
                'nonce'  => wp_create_nonce('anchor_schema_ajax'),
                'strings'=> [
                    'saving'   => __('Saving...', 'anchor-schema'),
                    'error'    => __('There was an error. See console for details.', 'anchor-schema'),
                    'validOk'  => __('Schema looks valid and has been added.', 'anchor-schema'),
                ]
            ]);
        }
    }

    public function ajax_generate(){
        check_ajax_referer('anchor_schema_ajax', 'nonce');
        if ( ! current_user_can('edit_posts') ) { wp_send_json_error('no_cap'); }

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        $type    = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : '';
        $custom  = isset($_POST['custom']) ? sanitize_text_field($_POST['custom']) : '';
        $raw     = isset($_POST['raw']) ? wp_kses_post($_POST['raw']) : '';

        Anchor_Schema_Logger::log('ajax_generate:start', [ 'post_id' => $post_id, 'type' => $type, 'custom' => $custom, 'raw_len' => strlen($raw) ]);

        if ( empty($post_id) || empty($raw) ) { wp_send_json_error([ 'message' => 'Missing required fields' ]); }
        if ( ! empty($custom) ) { $type = $custom; }
        if ( empty($type) ) { $type = 'Thing'; }

        $settings = get_option(self::OPTION_KEY, []);
        $api_key  = isset($settings['api_key']) ? trim($settings['api_key']) : '';
        $model    = isset($settings['model']) ? trim($settings['model']) : 'gpt-4o-mini';
        if ( empty($api_key) ) { wp_send_json_error([ 'message' => 'API key not configured' ]); }

        $prompt = $this->build_prompt($type, wp_strip_all_tags($raw));
        $response = $this->call_openai($api_key, $model, $prompt);

        if ( is_wp_error($response) ) {
            Anchor_Schema_Logger::log('ajax_generate:error', [ 'error' => $response->get_error_message() ]);
            wp_send_json_error([ 'message' => $response->get_error_message() ]);
        }

        $json = $this->extract_json($response);
        if ( empty($json) ) {
            wp_send_json_error([ 'message' => 'No JSON returned from model' ]);
        }

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

        Anchor_Schema_Logger::log('ajax_generate:success', [ 'post_id' => $post_id, 'item_id' => $id ]);
        wp_send_json_success([ 'item' => $item ]);
    }

    public function ajax_update_item(){
        check_ajax_referer('anchor_schema_ajax', 'nonce');
        if ( ! current_user_can('edit_posts') ) { wp_send_json_error('no_cap'); }

        $post_id = absint($_POST['post_id'] ?? 0);
        $id      = sanitize_text_field($_POST['id'] ?? '');
        $data    = wp_unslash($_POST['data'] ?? []);
        Anchor_Schema_Logger::log('ajax_update_item', [ 'post_id' => $post_id, 'id' => $id ]);

        if ( empty($post_id) || empty($id) ) { wp_send_json_error([ 'message' => 'Missing id' ]); }

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
        check_ajax_referer('anchor_schema_ajax', 'nonce');
        if ( ! current_user_can('edit_posts') ) { wp_send_json_error('no_cap'); }
        $post_id = absint($_POST['post_id'] ?? 0);
        $id      = sanitize_text_field($_POST['id'] ?? '');
        Anchor_Schema_Logger::log('ajax_delete_item', [ 'post_id' => $post_id, 'id' => $id ]);
        $items = get_post_meta($post_id, self::META_KEY, true);
        if (!is_array($items)) { $items = []; }
        $items = array_values(array_filter($items, function($it) use ($id){ return $it['id'] !== $id; }));
        update_post_meta($post_id, self::META_KEY, $items);
        wp_send_json_success([ 'items' => $items ]);
    }

    public function ajax_upload(){
        check_ajax_referer('anchor_schema_ajax', 'nonce');
        if ( ! current_user_can('edit_posts') ) { wp_send_json_error('no_cap'); }

        $post_id  = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        $filename = isset($_POST['filename']) ? sanitize_file_name($_POST['filename']) : '';
        $content  = isset($_POST['content']) ? wp_unslash($_POST['content']) : '';

        Anchor_Schema_Logger::log('ajax_upload:start', [ 'post_id' => $post_id, 'filename' => $filename, 'len' => strlen($content) ]);

        if ( empty($post_id) || empty($content) ) { wp_send_json_error([ 'message' => 'Missing required fields' ]); }

        $validation = $this->validate_schema_json( $content );
        if ( ! empty($validation['errors']) ) {
            Anchor_Schema_Logger::log('ajax_upload:invalid', [ 'errors' => $validation['errors'], 'warnings' => $validation['warnings'] ]);
            wp_send_json_error( [ 'errors' => $validation['errors'], 'warnings' => $validation['warnings'] ] );
        }

        $json = $validation['normalized_json'];
        $min  = $this->minify_json($json);
        $type = $validation['primary_type'];
        $label = $filename ? ($filename . ' (uploaded)') : ( $type ? ($type . ' (uploaded)') : 'Uploaded schema' );

        $items = get_post_meta($post_id, self::META_KEY, true);
        if (!is_array($items)) { $items = []; }
        $id = wp_generate_uuid4();
        $now = current_time('mysql');
        $item = [
            'id'        => $id,
            'type'      => $type ?: 'Thing',
            'raw_text'  => '',
            'json'      => $json,
            'min_json'  => $min ?: $json,
            'updated'   => $now,
            'enabled'   => true,
            'label'     => $label,
        ];
        $items[] = $item;
        update_post_meta($post_id, self::META_KEY, $items);

        Anchor_Schema_Logger::log('ajax_upload:success', [ 'post_id' => $post_id, 'item_id' => $id ]);
        wp_send_json_success( [ 'item' => $item, 'warnings' => $validation['warnings'] ] );
    }

    private function get_schema_types(){
        return apply_filters('anchor_schema_types', Anchor_Schema_Helper::get_schema_types());
    }

    private function build_prompt($type, $raw){
        return Anchor_Schema_Helper::build_prompt($type, $raw);
    }

    private function call_openai($api_key, $model, $prompt){
        return Anchor_Schema_Helper::call_openai($api_key, $model, $prompt);
    }

    private function extract_json($content){
        return Anchor_Schema_Helper::extract_json($content);
    }

    private function minify_json($json){
        return Anchor_Schema_Helper::minify_json($json);
    }

    private function validate_schema_json( $content ){
        return Anchor_Schema_Helper::validate_schema_json($content);
    }
}
