<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Anchor_Code_Snippets_Module {

    const CPT   = 'anchor_snippet';
    const NONCE = 'anchor_snippet_nonce';

    public function __construct() {
        add_action( 'init',                  [ $this, 'register_cpt' ] );
        add_action( 'add_meta_boxes',        [ $this, 'add_metaboxes' ] );
        add_action( 'save_post',             [ $this, 'save_meta' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'admin_assets' ] );

        add_action( 'wp_head',        [ $this, 'output_head' ] );
        add_action( 'wp_body_open',   [ $this, 'output_body' ] );
        add_action( 'wp_footer',      [ $this, 'output_footer' ] );

        add_action( 'wp_ajax_acs_search_pages', [ $this, 'ajax_search_pages' ] );
        add_action( 'wp_ajax_acs_ai_generate',  [ $this, 'ajax_ai_generate' ] );

        add_filter( 'manage_' . self::CPT . '_posts_columns',        [ $this, 'admin_columns' ] );
        add_action( 'manage_' . self::CPT . '_posts_custom_column',  [ $this, 'admin_column_content' ], 10, 2 );
    }

    /* ─── CPT ──────────────────────────────────────────────── */

    public function register_cpt() {
        register_post_type( self::CPT, [
            'labels' => [
                'name'          => 'Code Snippets',
                'singular_name' => 'Code Snippet',
                'add_new_item'  => 'Add New Code Snippet',
                'edit_item'     => 'Edit Code Snippet',
                'menu_name'     => 'Code Snippets',
            ],
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => apply_filters( 'anchor_code_snippets_parent_menu', true ),
            'menu_icon'    => 'dashicons-editor-code',
            'supports'     => [ 'title' ],
        ] );
    }

    /* ─── Metaboxes ────────────────────────────────────────── */

    public function add_metaboxes() {
        add_meta_box( 'acs_code',     'Code Editor', [ $this, 'render_box_code' ],     self::CPT, 'normal', 'high' );
        add_meta_box( 'acs_settings', 'Settings',    [ $this, 'render_box_settings' ], self::CPT, 'side' );
    }

    public function render_box_code( $post ) {
        wp_nonce_field( self::NONCE, self::NONCE );

        $language = get_post_meta( $post->ID, 'acs_language', true ) ?: 'javascript';
        $code     = get_post_meta( $post->ID, 'acs_code', true );
        ?>
        <div class="acs-field">
            <label><strong>Language</strong></label>
            <select name="acs_language" id="acs_language">
                <option value="javascript" <?php selected( $language, 'javascript' ); ?>>JavaScript</option>
                <option value="css"        <?php selected( $language, 'css' ); ?>>CSS</option>
                <option value="html"       <?php selected( $language, 'html' ); ?>>HTML</option>
                <option value="php"        <?php selected( $language, 'php' ); ?>>PHP</option>
                <option value="universal"  <?php selected( $language, 'universal' ); ?>>Universal (raw output)</option>
            </select>
        </div>

        <div class="acs-field acs-ai-panel">
            <label><strong>AI Generate</strong></label>
            <textarea id="acs_ai_prompt" rows="2" placeholder="Describe the code you want to generate&hellip;"></textarea>
            <button type="button" id="acs_ai_btn" class="button">Generate</button>
            <span id="acs_ai_spinner" class="spinner" style="float:none;"></span>
            <span id="acs_ai_error" class="acs-ai-error"></span>
        </div>

        <div class="acs-field">
            <label><strong>Code</strong></label>
            <textarea name="acs_code" id="acs_code" rows="18" class="large-text code"><?php echo esc_textarea( $code ); ?></textarea>
        </div>
        <?php
    }

    public function render_box_settings( $post ) {
        $location     = get_post_meta( $post->ID, 'acs_location', true ) ?: 'wp_head';
        $scope        = get_post_meta( $post->ID, 'acs_scope', true ) ?: 'global';
        $target_pages = get_post_meta( $post->ID, 'acs_target_pages', true );
        $priority     = get_post_meta( $post->ID, 'acs_priority', true );
        $enabled      = get_post_meta( $post->ID, 'acs_enabled', true );

        if ( $priority === '' || $priority === false ) $priority = 10;
        if ( $enabled === '' && get_post_status( $post->ID ) === 'auto-draft' ) $enabled = '1';
        ?>
        <div class="acs-field">
            <label><strong>Location</strong></label>
            <select name="acs_location">
                <option value="wp_head"     <?php selected( $location, 'wp_head' ); ?>>Header (wp_head)</option>
                <option value="wp_body_open" <?php selected( $location, 'wp_body_open' ); ?>>Body (wp_body_open)</option>
                <option value="wp_footer"   <?php selected( $location, 'wp_footer' ); ?>>Footer (wp_footer)</option>
            </select>
        </div>

        <div class="acs-field">
            <label><strong>Scope</strong></label>
            <label><input type="radio" name="acs_scope" value="global" <?php checked( $scope, 'global' ); ?>> Global (all pages)</label><br>
            <label><input type="radio" name="acs_scope" value="specific" <?php checked( $scope, 'specific' ); ?>> Specific Pages</label>
        </div>

        <div class="acs-field acs-page-search-wrap" style="<?php echo $scope !== 'specific' ? 'display:none;' : ''; ?>">
            <label><strong>Target Pages</strong></label>
            <input type="text" id="acs_page_search" placeholder="Search pages&hellip;" autocomplete="off">
            <div id="acs_search_results" class="acs-search-results"></div>
            <div id="acs_page_tags" class="acs-page-tags">
                <?php
                if ( $target_pages ) {
                    $ids = array_filter( array_map( 'intval', explode( ',', $target_pages ) ) );
                    foreach ( $ids as $pid ) {
                        $title = get_the_title( $pid );
                        if ( ! $title ) continue;
                        printf(
                            '<span class="acs-tag" data-id="%d">%s <button type="button" class="acs-tag-remove">&times;</button></span>',
                            $pid,
                            esc_html( $title )
                        );
                    }
                }
                ?>
            </div>
            <input type="hidden" name="acs_target_pages" id="acs_target_pages" value="<?php echo esc_attr( $target_pages ); ?>">
        </div>

        <div class="acs-field">
            <label><strong>Priority</strong></label>
            <input type="number" name="acs_priority" value="<?php echo esc_attr( $priority ); ?>" min="1" max="999" style="width:70px;">
            <p class="description">Lower = earlier. Default 10.</p>
        </div>

        <div class="acs-field">
            <label>
                <input type="checkbox" name="acs_enabled" value="1" <?php checked( $enabled, '1' ); ?>>
                <strong>Enabled</strong>
            </label>
        </div>
        <?php
    }

    /* ─── Save ─────────────────────────────────────────────── */

    public function save_meta( $post_id ) {
        if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( $_POST[ self::NONCE ], self::NONCE ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        $text_fields = [ 'acs_language', 'acs_location', 'acs_scope', 'acs_target_pages', 'acs_priority' ];
        foreach ( $text_fields as $key ) {
            $val = isset( $_POST[ $key ] ) ? sanitize_text_field( $_POST[ $key ] ) : '';
            update_post_meta( $post_id, $key, $val );
        }

        // Code saved unfiltered (same as Universal Popups html/css/js)
        $code = isset( $_POST['acs_code'] ) ? $_POST['acs_code'] : '';
        update_post_meta( $post_id, 'acs_code', $code );

        // Checkbox: absent = off
        $enabled = isset( $_POST['acs_enabled'] ) ? '1' : '';
        update_post_meta( $post_id, 'acs_enabled', $enabled );
    }

    /* ─── Admin Assets ─────────────────────────────────────── */

    public function admin_assets( $hook ) {
        global $post;
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) return;
        if ( ! isset( $post ) || $post->post_type !== self::CPT ) return;

        $base_dir = ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-code-snippets/assets/';
        $base_url = ANCHOR_TOOLS_PLUGIN_URL . 'anchor-code-snippets/assets/';
        $ver      = filemtime( $base_dir . 'admin.js' );

        wp_enqueue_style( 'acs-admin', $base_url . 'admin.css', [], $ver );
        wp_enqueue_script( 'acs-admin', $base_url . 'admin.js', [ 'jquery' ], $ver, true );

        // WordPress code editor
        $editor_settings = wp_enqueue_code_editor( [ 'type' => $this->language_to_mime( get_post_meta( $post->ID, 'acs_language', true ) ?: 'javascript' ) ] );

        wp_localize_script( 'acs-admin', 'ACS', [
            'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
            'nonce'          => wp_create_nonce( 'acs_ajax' ),
            'editorSettings' => $editor_settings,
        ] );
    }

    private function language_to_mime( $lang ) {
        $map = [
            'javascript' => 'text/javascript',
            'css'        => 'text/css',
            'html'       => 'text/html',
            'php'        => 'application/x-httpd-php',
            'universal'  => 'text/html',
        ];
        return isset( $map[ $lang ] ) ? $map[ $lang ] : 'text/html';
    }

    /* ─── AJAX: Page Search ────────────────────────────────── */

    public function ajax_search_pages() {
        check_ajax_referer( 'acs_ajax', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error();

        $search = isset( $_GET['search'] ) ? sanitize_text_field( $_GET['search'] ) : '';
        if ( strlen( $search ) < 2 ) wp_send_json_success( [] );

        $query = new WP_Query( [
            's'              => $search,
            'post_type'      => [ 'page', 'post' ],
            'post_status'    => 'publish',
            'posts_per_page' => 20,
        ] );

        $results = [];
        foreach ( $query->posts as $p ) {
            $results[] = [
                'id'    => $p->ID,
                'title' => $p->post_title ?: '(no title)',
                'type'  => $p->post_type,
            ];
        }
        wp_send_json_success( $results );
    }

    /* ─── AJAX: AI Generate ────────────────────────────────── */

    public function ajax_ai_generate() {
        check_ajax_referer( 'acs_ajax', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( [ 'message' => 'Unauthorized' ] );

        $prompt   = isset( $_POST['prompt'] )   ? sanitize_textarea_field( $_POST['prompt'] )   : '';
        $language = isset( $_POST['language'] )  ? sanitize_text_field( $_POST['language'] )     : 'javascript';

        if ( empty( $prompt ) ) wp_send_json_error( [ 'message' => 'Prompt is required.' ] );

        // Read API key + model from ACG settings (shared OpenAI config)
        $settings = get_option( 'acg_settings', [] );
        $api_key  = isset( $settings['api_key'] ) ? trim( $settings['api_key'] ) : '';
        $model    = isset( $settings['model'] )   ? trim( $settings['model'] )   : 'gpt-4o-mini';

        if ( empty( $api_key ) ) {
            wp_send_json_error( [ 'message' => 'OpenAI API key not configured. Set it under Anchor Schema &rarr; ACG Settings.' ] );
        }

        $lang_label = ucfirst( $language );
        if ( $language === 'universal' ) $lang_label = 'HTML/JS/CSS';

        $system_prompt = "You are a code generator. Output only clean {$lang_label} code with no explanation, no markdown fences, no commentary. Just the raw code.";

        $body = [
            'model'       => $model,
            'messages'    => [
                [ 'role' => 'system', 'content' => $system_prompt ],
                [ 'role' => 'user',   'content' => $prompt ],
            ],
            'temperature' => 0.2,
            'max_tokens'  => 2000,
        ];

        $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body'    => wp_json_encode( $body ),
            'timeout' => 60,
        ] );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => $response->get_error_message() ] );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );

        if ( $code < 200 || $code >= 300 ) {
            $data = json_decode( $raw, true );
            $msg  = isset( $data['error']['message'] ) ? $data['error']['message'] : ( 'HTTP ' . $code );
            wp_send_json_error( [ 'message' => 'OpenAI error: ' . $msg ] );
        }

        $data = json_decode( $raw, true );
        $text = isset( $data['choices'][0]['message']['content'] ) ? $data['choices'][0]['message']['content'] : '';

        if ( empty( $text ) ) {
            wp_send_json_error( [ 'message' => 'OpenAI returned empty content.' ] );
        }

        wp_send_json_success( [ 'code' => $text ] );
    }

    /* ─── Frontend Output ──────────────────────────────────── */

    public function output_head()   { $this->render_snippets( 'wp_head' ); }
    public function output_body()   { $this->render_snippets( 'wp_body_open' ); }
    public function output_footer() { $this->render_snippets( 'wp_footer' ); }

    private function render_snippets( $location ) {
        if ( is_admin() ) return;

        $snippets = get_posts( [
            'post_type'      => self::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_query'     => [
                [
                    'key'   => 'acs_location',
                    'value' => $location,
                ],
                [
                    'key'   => 'acs_enabled',
                    'value' => '1',
                ],
            ],
            'orderby'  => 'meta_value_num',
            'meta_key' => 'acs_priority',
            'order'    => 'ASC',
        ] );

        if ( empty( $snippets ) ) return;

        $current_id = get_queried_object_id();

        foreach ( $snippets as $snippet ) {
            $scope = get_post_meta( $snippet->ID, 'acs_scope', true );

            if ( $scope === 'specific' ) {
                $pages = get_post_meta( $snippet->ID, 'acs_target_pages', true );
                if ( ! $pages ) continue;
                $ids = array_map( 'intval', explode( ',', $pages ) );
                if ( ! in_array( $current_id, $ids, true ) ) continue;
            }

            $language = get_post_meta( $snippet->ID, 'acs_language', true );
            $code     = get_post_meta( $snippet->ID, 'acs_code', true );
            if ( $code === '' || $code === false ) continue;

            $this->output_snippet( $language, $code );
        }
    }

    private function output_snippet( $language, $code ) {
        switch ( $language ) {
            case 'php':
                if ( ! current_user_can( 'unfiltered_html' ) ) return;
                try {
                    ob_start();
                    eval( $code );
                    echo ob_get_clean();
                } catch ( \Throwable $e ) {
                    ob_end_clean();
                    error_log( 'Anchor Code Snippets PHP error: ' . $e->getMessage() );
                }
                break;

            case 'javascript':
                echo '<script>' . $code . '</script>' . "\n";
                break;

            case 'css':
                echo '<style>' . $code . '</style>' . "\n";
                break;

            case 'html':
            case 'universal':
            default:
                echo $code . "\n";
                break;
        }
    }

    /* ─── Admin Columns ────────────────────────────────────── */

    public function admin_columns( $columns ) {
        $new = [];
        foreach ( $columns as $k => $v ) {
            $new[ $k ] = $v;
            if ( $k === 'title' ) {
                $new['acs_language'] = 'Language';
                $new['acs_location'] = 'Location';
                $new['acs_scope']    = 'Scope';
                $new['acs_enabled']  = 'Enabled';
            }
        }
        unset( $new['date'] );
        $new['date'] = $columns['date'];
        return $new;
    }

    public function admin_column_content( $column, $post_id ) {
        switch ( $column ) {
            case 'acs_language':
                $lang = get_post_meta( $post_id, 'acs_language', true ) ?: 'javascript';
                $colors = [
                    'javascript' => '#f0db4f',
                    'css'        => '#264de4',
                    'html'       => '#e34c26',
                    'php'        => '#777bb4',
                    'universal'  => '#888',
                ];
                $bg = isset( $colors[ $lang ] ) ? $colors[ $lang ] : '#888';
                printf( '<span class="acs-badge" style="background:%s;color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;">%s</span>', esc_attr( $bg ), esc_html( strtoupper( $lang ) ) );
                break;

            case 'acs_location':
                $loc  = get_post_meta( $post_id, 'acs_location', true ) ?: 'wp_head';
                $map  = [ 'wp_head' => 'Header', 'wp_body_open' => 'Body', 'wp_footer' => 'Footer' ];
                echo esc_html( isset( $map[ $loc ] ) ? $map[ $loc ] : $loc );
                break;

            case 'acs_scope':
                $scope = get_post_meta( $post_id, 'acs_scope', true ) ?: 'global';
                echo esc_html( $scope === 'specific' ? 'Specific' : 'Global' );
                break;

            case 'acs_enabled':
                $on = get_post_meta( $post_id, 'acs_enabled', true );
                echo $on === '1' ? '<span style="color:green;">Yes</span>' : '<span style="color:#999;">No</span>';
                break;
        }
    }
}
