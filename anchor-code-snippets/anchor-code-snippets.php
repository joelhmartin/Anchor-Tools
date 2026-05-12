<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Anchor_Code_Snippets_Module {

    const CPT   = 'anchor_snippet';
    const NONCE = 'anchor_snippet_nonce';
    const MU_PREFIX = 'anchor-snippet-';

    public function __construct() {
        add_action( 'init',                  [ $this, 'register_cpt' ] );
        add_action( 'add_meta_boxes',        [ $this, 'add_metaboxes' ] );
        add_action( 'edit_form_after_title',  [ $this, 'render_code_after_title' ] );
        add_action( 'save_post',             [ $this, 'save_meta' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'admin_assets' ] );

        add_action( 'wp_head',        [ $this, 'output_head' ] );
        add_action( 'wp_body_open',   [ $this, 'output_body' ] );
        add_action( 'wp_footer',      [ $this, 'output_footer' ] );

        add_action( 'wp_ajax_acs_search_pages',    [ $this, 'ajax_search_pages' ] );
        add_action( 'wp_ajax_acs_ai_generate',     [ $this, 'ajax_ai_generate' ] );
        add_action( 'wp_ajax_acs_toggle_enabled',  [ $this, 'ajax_toggle_enabled' ] );

        add_filter( 'manage_' . self::CPT . '_posts_columns',        [ $this, 'admin_columns' ] );
        add_action( 'manage_' . self::CPT . '_posts_custom_column',  [ $this, 'admin_column_content' ], 10, 2 );

        // MU-plugin lifecycle
        add_action( 'before_delete_post', [ $this, 'on_post_delete' ] );
        add_action( 'wp_trash_post',      [ $this, 'on_post_trash' ] );
        add_action( 'untrashed_post',     [ $this, 'on_post_untrash' ] );

        // MU runtime fault recovery (detect renamed .disabled files from shutdown handler)
        add_action( 'admin_init', [ $this, 'reconcile_mu_files' ] );
        add_action( 'admin_notices', [ $this, 'render_admin_notices' ] );
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
        add_meta_box( 'acs_settings', 'Settings', [ $this, 'render_box_settings' ], self::CPT, 'side' );
    }

    /**
     * Render code editor fields directly after the title field,
     * so they appear immediately visible (same pattern as ACF).
     */
    public function render_code_after_title( $post ) {
        if ( $post->post_type !== self::CPT ) return;

        wp_nonce_field( self::NONCE, self::NONCE );

        $language = get_post_meta( $post->ID, 'acs_language', true ) ?: 'javascript';
        $code     = get_post_meta( $post->ID, 'acs_code', true );
        ?>
        <div id="acs-code-editor-wrap" class="acs-code-editor-wrap">
            <div class="acs-field acs-lang-row">
                <label for="acs_language"><strong>Language</strong></label>
                <select name="acs_language" id="acs_language">
                    <option value="javascript" <?php selected( $language, 'javascript' ); ?>>JavaScript</option>
                    <option value="css"        <?php selected( $language, 'css' ); ?>>CSS</option>
                    <option value="html"       <?php selected( $language, 'html' ); ?>>HTML</option>
                    <option value="php"        <?php selected( $language, 'php' ); ?>>PHP</option>
                    <option value="shortcode"  <?php selected( $language, 'shortcode' ); ?>>Shortcode</option>
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
                <textarea name="acs_code" id="acs_code" rows="18" class="large-text code"><?php echo esc_textarea( $code ); ?></textarea>
            </div>
        </div>
        <?php
    }

    public function render_box_settings( $post ) {
        $language     = get_post_meta( $post->ID, 'acs_language', true ) ?: 'javascript';
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
            <select name="acs_location" id="acs_location">
                <option value="wp_head"     <?php selected( $location, 'wp_head' ); ?>>Header (wp_head)</option>
                <option value="wp_body_open" <?php selected( $location, 'wp_body_open' ); ?>>Body (wp_body_open)</option>
                <option value="wp_footer"   <?php selected( $location, 'wp_footer' ); ?>>Footer (wp_footer)</option>
                <option value="mu_plugin"   data-php-only="1" <?php selected( $location, 'mu_plugin' ); ?>>MU-plugin (early load, PHP only)</option>
            </select>
            <p class="description acs-mu-hint" style="<?php echo $location === 'mu_plugin' ? '' : 'display:none;'; ?>">
                Loads from <code>wp-content/mu-plugins/</code> before themes and plugins. Validated on save; auto-disables on fatal.
            </p>
        </div>

        <div class="acs-field acs-non-mu-field" style="<?php echo $location === 'mu_plugin' ? 'display:none;' : ''; ?>">
            <label><strong>Scope</strong></label>
            <label><input type="radio" name="acs_scope" value="global" <?php checked( $scope, 'global' ); ?>> Global (all pages)</label><br>
            <label><input type="radio" name="acs_scope" value="specific" <?php checked( $scope, 'specific' ); ?>> Only on specific pages</label><br>
            <label><input type="radio" name="acs_scope" value="exclude" <?php checked( $scope, 'exclude' ); ?>> Everywhere except specific pages</label>
        </div>

        <div class="acs-field acs-non-mu-field acs-page-search-wrap" style="<?php echo ( $location === 'mu_plugin' || ! in_array( $scope, [ 'specific', 'exclude' ], true ) ) ? 'display:none;' : ''; ?>">
            <label><strong class="acs-pages-label"><?php echo $scope === 'exclude' ? 'Excluded Pages' : 'Target Pages'; ?></strong></label>
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

        <div class="acs-field acs-non-mu-field" style="<?php echo $location === 'mu_plugin' ? 'display:none;' : ''; ?>">
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

        $previous_location = get_post_meta( $post_id, 'acs_location', true ) ?: 'wp_head';

        $text_fields = [ 'acs_language', 'acs_location', 'acs_scope', 'acs_target_pages', 'acs_priority' ];
        $incoming = [];
        foreach ( $text_fields as $key ) {
            $incoming[ $key ] = isset( $_POST[ $key ] ) ? sanitize_text_field( $_POST[ $key ] ) : '';
        }
        $code    = isset( $_POST['acs_code'] ) ? (string) $_POST['acs_code'] : '';
        $enabled = isset( $_POST['acs_enabled'] ) ? '1' : '';

        // Gate the MU location: must be PHP and must pass syntax validation.
        if ( $incoming['acs_location'] === 'mu_plugin' ) {
            $reject = '';

            if ( $incoming['acs_language'] !== 'php' ) {
                $reject = 'MU-plugin location requires PHP language. Reverted to ' . $previous_location . '.';
            } else {
                $check = $this->validate_php_syntax( $code );
                if ( $check !== true ) {
                    $reject = 'PHP syntax error — MU-plugin save blocked: ' . $check;
                }
            }

            if ( $reject ) {
                $incoming['acs_location'] = $previous_location === 'mu_plugin' ? 'wp_head' : $previous_location;
                $enabled = '';
                $this->push_admin_notice( $post_id, 'error', $reject );
            }
        }

        foreach ( $text_fields as $key ) {
            update_post_meta( $post_id, $key, $incoming[ $key ] );
        }
        update_post_meta( $post_id, 'acs_code', $code );
        update_post_meta( $post_id, 'acs_enabled', $enabled );

        $this->sync_mu_file( $post_id, $previous_location );
    }

    /* ─── Admin Assets ─────────────────────────────────────── */

    public function admin_assets( $hook ) {
        global $post, $typenow;

        $is_editor = in_array( $hook, [ 'post.php', 'post-new.php' ], true ) && isset( $post ) && $post->post_type === self::CPT;
        $is_list   = $hook === 'edit.php' && $typenow === self::CPT;

        if ( ! $is_editor && ! $is_list ) return;

        $base_dir = ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-code-snippets/assets/';
        $ver      = filemtime( $base_dir . 'admin.js' );

        wp_enqueue_style( 'acs-admin', Anchor_Asset_Loader::url( 'anchor-code-snippets/assets/admin.css' ), [], $ver );
        wp_enqueue_script( 'acs-admin', Anchor_Asset_Loader::url( 'anchor-code-snippets/assets/admin.js' ), [ 'jquery' ], $ver, true );

        $localize = [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'acs_ajax' ),
        ];

        if ( $is_editor ) {
            $localize['editorSettings'] = wp_enqueue_code_editor( [ 'type' => $this->language_to_mime( get_post_meta( $post->ID, 'acs_language', true ) ?: 'javascript' ) ] );
        }

        wp_localize_script( 'acs-admin', 'ACS', $localize );
    }

    private function language_to_mime( $lang ) {
        $map = [
            'javascript' => 'text/javascript',
            'css'        => 'text/css',
            'html'       => 'text/html',
            'php'        => 'application/x-httpd-php',
            'shortcode'  => 'text/html',
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

    /* ─── AJAX: Toggle Enabled ─────────────────────────────── */

    public function ajax_toggle_enabled() {
        check_ajax_referer( 'acs_ajax', 'nonce' );

        $post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
        if ( ! $post_id || get_post_type( $post_id ) !== self::CPT ) {
            wp_send_json_error( [ 'message' => 'Invalid snippet.' ] );
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized.' ] );
        }

        $current = get_post_meta( $post_id, 'acs_enabled', true ) === '1';
        $new     = $current ? '' : '1';

        // If we're enabling an MU snippet, syntax-validate first.
        if ( $new === '1' && get_post_meta( $post_id, 'acs_location', true ) === 'mu_plugin' ) {
            $code  = get_post_meta( $post_id, 'acs_code', true );
            $check = $this->validate_php_syntax( $code );
            if ( $check !== true ) {
                wp_send_json_error( [ 'message' => 'Cannot enable: PHP syntax error — ' . $check ] );
            }
        }

        update_post_meta( $post_id, 'acs_enabled', $new );
        $this->sync_mu_file( $post_id );

        wp_send_json_success( [ 'enabled' => $new === '1' ] );
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
        if ( $language === 'shortcode' ) $lang_label = 'WordPress Shortcode';
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
            } elseif ( $scope === 'exclude' ) {
                $pages = get_post_meta( $snippet->ID, 'acs_target_pages', true );
                if ( $pages ) {
                    $ids = array_map( 'intval', explode( ',', $pages ) );
                    if ( in_array( $current_id, $ids, true ) ) continue;
                }
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
                try {
                    $code = preg_replace( '/^\s*<\?(?:php)?\s*/i', '', $code );
                    $code = preg_replace( '/\s*\?>\s*$/', '', $code );
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

            case 'shortcode':
                echo do_shortcode( $code ) . "\n";
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
                    'shortcode'  => '#21759b',
                    'universal'  => '#888',
                ];
                $bg = isset( $colors[ $lang ] ) ? $colors[ $lang ] : '#888';
                printf( '<span class="acs-badge" style="background:%s;color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;">%s</span>', esc_attr( $bg ), esc_html( strtoupper( $lang ) ) );
                break;

            case 'acs_location':
                $loc  = get_post_meta( $post_id, 'acs_location', true ) ?: 'wp_head';
                $map  = [ 'wp_head' => 'Header', 'wp_body_open' => 'Body', 'wp_footer' => 'Footer', 'mu_plugin' => 'MU-plugin' ];
                echo esc_html( isset( $map[ $loc ] ) ? $map[ $loc ] : $loc );
                break;

            case 'acs_scope':
                $scope = get_post_meta( $post_id, 'acs_scope', true ) ?: 'global';
                $map = [ 'global' => 'Global', 'specific' => 'Specific', 'exclude' => 'Exclude' ];
                echo esc_html( $map[ $scope ] ?? 'Global' );
                break;

            case 'acs_enabled':
                $on = get_post_meta( $post_id, 'acs_enabled', true ) === '1';
                printf(
                    '<button type="button" class="button button-small acs-toggle-enabled" data-id="%d" data-enabled="%d" aria-pressed="%s" style="min-width:54px;color:%s;border-color:%s;">%s</button>',
                    (int) $post_id,
                    $on ? 1 : 0,
                    $on ? 'true' : 'false',
                    $on ? '#1a7f37' : '#999',
                    $on ? '#1a7f37' : '#ccc',
                    $on ? 'On' : 'Off'
                );
                break;
        }
    }

    /* ─── MU-plugin: paths ─────────────────────────────────── */

    private function mu_dir() {
        return defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
    }

    private function mu_file_path( $post_id ) {
        return $this->mu_dir() . '/' . self::MU_PREFIX . (int) $post_id . '.php';
    }

    private function strip_php_tags( $code ) {
        $code = preg_replace( '/^\s*<\?(?:php)?\s*/i', '', $code );
        $code = preg_replace( '/\s*\?>\s*$/', '', $code );
        return $code;
    }

    /* ─── MU-plugin: syntax validation ─────────────────────── */

    /**
     * Returns true on clean parse, error message string otherwise.
     * Layered: php -l subprocess if available, then token_get_all(TOKEN_PARSE) fallback.
     */
    private function validate_php_syntax( $code ) {
        $code = trim( $this->strip_php_tags( (string) $code ) );
        if ( $code === '' ) return true;

        // Method 1: php -l subprocess. Validates the exact envelope we write to disk.
        if ( function_exists( 'shell_exec' ) && defined( 'PHP_BINARY' ) && PHP_BINARY ) {
            $disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
            if ( ! in_array( 'shell_exec', $disabled, true ) ) {
                $wrapped = "<?php\ntry {\n" . $code . "\n} catch ( \\Throwable \$_e ) {}\n";
                $tmp = function_exists( 'wp_tempnam' ) ? wp_tempnam( 'acs-lint' ) : tempnam( sys_get_temp_dir(), 'acs-lint-' );
                if ( $tmp && @file_put_contents( $tmp, $wrapped ) !== false ) {
                    $cmd = escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $tmp ) . ' 2>&1';
                    $out = @shell_exec( $cmd );
                    @unlink( $tmp );
                    if ( is_string( $out ) && $out !== '' ) {
                        if ( stripos( $out, 'No syntax errors' ) !== false ) {
                            return true;
                        }
                        $out = str_replace( $tmp, 'snippet', $out );
                        $out = preg_replace( '/^Errors parsing.*$/m', '', $out );
                        return trim( $out ) ?: 'PHP syntax error detected.';
                    }
                }
            }
        }

        // Method 2: eval the code inside a never-called function. ParseError is catchable.
        // This mirrors the runtime envelope (try/catch inside the body) without executing.
        $lint_fn = '__acs_lint_' . substr( md5( uniqid( '', true ) ), 0, 12 );
        try {
            eval( "function {$lint_fn}() { try {\n" . $code . "\n} catch ( \\Throwable \$_e ) {} }" );
            return true;
        } catch ( \ParseError $e ) {
            return $e->getMessage() . ' on line ' . max( 1, (int) $e->getLine() - 2 );
        } catch ( \Error $e ) {
            return $e->getMessage();
        }
    }

    /* ─── MU-plugin: file writer ───────────────────────────── */

    private function generate_mu_contents( $post_id, $title, $code ) {
        $clean       = trim( $this->strip_php_tags( (string) $code ) );
        $edit_url    = admin_url( 'post.php?post=' . (int) $post_id . '&action=edit' );
        $safe_title  = str_replace( [ '*/', "\r", "\n" ], [ '* /', ' ', ' ' ], (string) $title );
        $self_marker = self::MU_PREFIX . (int) $post_id . '.php';
        $body        = "    " . str_replace( "\n", "\n    ", $clean );

        return <<<PHP
<?php
/**
 * Plugin Name: Anchor Snippet — {$safe_title}
 * Description: Auto-generated by Anchor Tools. Edit at {$edit_url}
 * Snippet ID: {$post_id}
 *
 * DO NOT EDIT THIS FILE DIRECTLY — changes are overwritten on save.
 */
if ( ! defined( 'ABSPATH' ) ) { return; }

register_shutdown_function( function() {
    \$e = error_get_last();
    if ( ! \$e ) { return; }
    \$fatal_types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR );
    if ( ! in_array( \$e['type'], \$fatal_types, true ) ) { return; }
    if ( strpos( (string) \$e['file'], '{$self_marker}' ) === false ) { return; }

    \$self = __FILE__;
    if ( file_exists( \$self ) ) { @rename( \$self, \$self . '.disabled' ); }
    @file_put_contents(
        dirname( \$self ) . '/{$self_marker}.error',
        gmdate( 'c' ) . " | " . \$e['message'] . " in " . \$e['file'] . ':' . \$e['line']
    );
    error_log( 'Anchor Snippet {$post_id} FATAL: ' . \$e['message'] . ' in ' . \$e['file'] . ':' . \$e['line'] );
} );

try {
{$body}
} catch ( \\Throwable \$anchor_snippet_e ) {
    error_log( 'Anchor Snippet {$post_id} runtime error: ' . \$anchor_snippet_e->getMessage() );
    if ( function_exists( 'set_transient' ) ) {
        set_transient( 'anchor_snippet_error_{$post_id}', \$anchor_snippet_e->getMessage(), DAY_IN_SECONDS );
    }
}
PHP;
    }

    /* ─── MU-plugin: lifecycle ─────────────────────────────── */

    /**
     * Idempotent. Reads current post state and writes or deletes the MU file accordingly.
     * Also cleans up any file left over from a previous location.
     */
    private function sync_mu_file( $post_id, $previous_location = null ) {
        $post_id = (int) $post_id;
        if ( ! $post_id || get_post_type( $post_id ) !== self::CPT ) return;

        $path = $this->mu_file_path( $post_id );

        // Always clean stale .disabled/.error sidecars when we re-sync.
        @unlink( $path . '.disabled' );
        @unlink( $path . '.error' );

        $should = $this->should_have_mu_file( $post_id );

        if ( ! $should ) {
            if ( file_exists( $path ) ) { @unlink( $path ); }
            return;
        }

        $dir = $this->mu_dir();
        if ( ! wp_mkdir_p( $dir ) ) {
            $this->push_admin_notice( $post_id, 'error', 'Could not create mu-plugins directory: ' . $dir );
            update_post_meta( $post_id, 'acs_enabled', '' );
            return;
        }

        $post     = get_post( $post_id );
        $title    = $post ? $post->post_title : ( 'Snippet ' . $post_id );
        $code     = get_post_meta( $post_id, 'acs_code', true );
        $contents = $this->generate_mu_contents( $post_id, $title, $code );

        $written = @file_put_contents( $path, $contents );
        if ( $written === false ) {
            $this->push_admin_notice( $post_id, 'error', 'Could not write MU file: ' . $path . ' (check filesystem permissions).' );
            update_post_meta( $post_id, 'acs_enabled', '' );
            return;
        }
        @chmod( $path, 0644 );
    }

    private function should_have_mu_file( $post_id ) {
        if ( get_post_status( $post_id ) !== 'publish' ) return false;
        if ( get_post_meta( $post_id, 'acs_location', true ) !== 'mu_plugin' ) return false;
        if ( get_post_meta( $post_id, 'acs_language', true ) !== 'php' ) return false;
        if ( get_post_meta( $post_id, 'acs_enabled', true ) !== '1' ) return false;
        return true;
    }

    private function delete_mu_file( $post_id ) {
        $path = $this->mu_file_path( $post_id );
        @unlink( $path );
        @unlink( $path . '.disabled' );
        @unlink( $path . '.error' );
    }

    public function on_post_delete( $post_id ) {
        if ( get_post_type( $post_id ) !== self::CPT ) return;
        $this->delete_mu_file( $post_id );
    }

    public function on_post_trash( $post_id ) {
        if ( get_post_type( $post_id ) !== self::CPT ) return;
        $this->delete_mu_file( $post_id );
    }

    public function on_post_untrash( $post_id ) {
        if ( get_post_type( $post_id ) !== self::CPT ) return;
        $this->sync_mu_file( $post_id );
    }

    /**
     * Runs on admin_init. If the runtime shutdown handler renamed a file to .disabled
     * (because it fatal'd), flip the corresponding snippet's acs_enabled off so we
     * don't immediately regenerate the broken file on next save.
     */
    public function reconcile_mu_files() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $dir = $this->mu_dir();
        if ( ! is_dir( $dir ) ) return;

        foreach ( (array) glob( $dir . '/' . self::MU_PREFIX . '*.php.disabled' ) as $disabled ) {
            if ( ! preg_match( '#' . preg_quote( self::MU_PREFIX, '#' ) . '(\d+)\.php\.disabled$#', $disabled, $m ) ) continue;
            $post_id = (int) $m[1];
            if ( ! $post_id || get_post_type( $post_id ) !== self::CPT ) continue;

            update_post_meta( $post_id, 'acs_enabled', '' );

            $error_file = substr( $disabled, 0, -9 ) . '.error';
            $msg = file_exists( $error_file ) ? trim( (string) @file_get_contents( $error_file ) ) : 'Fatal error in MU snippet.';
            $this->push_admin_notice( $post_id, 'error', 'Snippet auto-disabled — fatal error: ' . $msg );
        }
    }

    /* ─── Admin notices for save-time and runtime errors ──── */

    private function push_admin_notice( $post_id, $type, $message ) {
        $key = 'anchor_snippet_notice_' . get_current_user_id();
        $queue = get_transient( $key );
        if ( ! is_array( $queue ) ) $queue = [];
        $queue[] = [ 'id' => (int) $post_id, 'type' => $type, 'message' => $message ];
        set_transient( $key, $queue, 5 * MINUTE_IN_SECONDS );
    }

    public function render_admin_notices() {
        $key = 'anchor_snippet_notice_' . get_current_user_id();
        $queue = get_transient( $key );
        if ( ! is_array( $queue ) || empty( $queue ) ) return;
        delete_transient( $key );

        foreach ( $queue as $n ) {
            $cls = $n['type'] === 'error' ? 'notice-error' : 'notice-warning';
            $link = $n['id']
                ? sprintf( ' <a href="%s">Edit snippet #%d</a>', esc_url( get_edit_post_link( $n['id'] ) ), (int) $n['id'] )
                : '';
            printf(
                '<div class="notice %s"><p><strong>Anchor Snippets:</strong> %s%s</p></div>',
                esc_attr( $cls ),
                esc_html( $n['message'] ),
                $link
            );
        }
    }
}
