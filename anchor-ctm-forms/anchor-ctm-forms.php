<?php
/**
 * Anchor Tools module: Anchor CTM Forms.
 * Pick a CTM Form Reactor, generate a minimal starter form.
 * Submissions are posted server-side to CTM's FormReactor API.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_CTM_Forms_Module {
    const OPTION_KEY    = 'anchor_ctm_forms_options';
    const NONCE_ACTION  = 'anchor_ctm_forms_nonce';
    const NONCE_NAME    = 'anchor_ctm_nonce';

    /** Meta keys safe to copy/export (excludes reactor IDs and fields hash). */
    private static $portable_meta_keys = [
        '_ctm_form_mode',
        '_ctm_form_config',
        '_ctm_form_html',
        '_ctm_multi_step',
        '_ctm_title_page',
        '_ctm_title_heading',
        '_ctm_title_desc',
        '_ctm_start_text',
        '_ctm_success_message',
        '_ctm_analytics_override',
        '_ctm_analytics',
    ];

    public function __construct() {
        add_action( 'init', [ $this, 'register_cpt' ] );
        add_action( 'admin_menu', [ $this, 'admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'add_meta_boxes', [ $this, 'add_variant_metabox' ] );
        add_action( 'save_post_ctm_form_variant', [ $this, 'save_variant' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

        add_shortcode( 'ctm_form_variant', [ $this, 'render_form_shortcode' ] );

        add_filter( 'manage_ctm_form_variant_posts_columns', [ $this, 'add_shortcode_column' ] );
        add_action( 'manage_ctm_form_variant_posts_custom_column', [ $this, 'render_shortcode_column' ], 10, 2 );

        add_action( 'wp_ajax_anchor_ctm_generate', [ $this, 'ajax_generate_starter' ] );
        add_action( 'wp_ajax_anchor_ctm_submit', [ $this, 'ajax_submit' ] );
        add_action( 'wp_ajax_nopriv_anchor_ctm_submit', [ $this, 'ajax_submit' ] );
        add_action( 'wp_ajax_ctm_builder_preview', [ $this, 'ajax_builder_preview' ] );
        add_action( 'wp_ajax_anchor_ctm_ai_assist', [ $this, 'ajax_ai_assist' ] );
        add_action( 'admin_notices', [ $this, 'admin_notices' ] );

        /* ── Duplicate / Export / Import ── */
        add_filter( 'post_row_actions', [ $this, 'row_actions' ], 10, 2 );
        add_action( 'admin_post_anchor_ctm_duplicate', [ $this, 'handle_duplicate' ] );
        add_action( 'admin_post_anchor_ctm_export', [ $this, 'handle_export' ] );
        add_action( 'admin_post_anchor_ctm_import', [ $this, 'handle_import' ] );
        add_action( 'admin_notices', [ $this, 'render_import_ui' ] );

        /* ── Multi-Step: frontend asset registration ── */
        add_action( 'wp_enqueue_scripts', [ $this, 'register_frontend_assets' ] );
    }

    /* ========================= Admin: Enqueue Builder Assets ========================= */
    public function enqueue_admin_assets( $hook ) {
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
            return;
        }
        $screen = get_current_screen();
        if ( ! $screen || $screen->post_type !== 'ctm_form_variant' ) {
            return;
        }

        $base = plugin_dir_url( __FILE__ ) . 'assets/';
        wp_enqueue_style( 'ctm-builder', $base . 'builder.css', [], '1.5.0' );
        wp_enqueue_script( 'ctm-builder', $base . 'builder.js', [ 'jquery', 'jquery-ui-sortable' ], '1.5.0', true );

        $reactors = $this->fetch_reactors_list();
        wp_localize_script( 'ctm-builder', 'CTM_BUILDER', [
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( self::NONCE_ACTION ),
            'nonceName' => self::NONCE_NAME,
            'reactors'  => $reactors,
            'hasApiKey' => ! empty( $this->get_openai_api_key() ),
        ] );
    }

    /* ========================= Multi-Step & Form Logic: Register Frontend Assets ========================= */
    public function register_frontend_assets() {
        $base = plugin_dir_url( __FILE__ ) . 'assets/';
        wp_register_style( 'ctm-multi-step', $base . 'multi-step.css', [], '1.0.0' );
        wp_register_script( 'ctm-multi-step', $base . 'multi-step.js', [], '1.0.0', true );
        wp_register_style( 'ctm-form-logic', $base . 'form-logic.css', [], '1.1.0' );
        wp_register_script( 'ctm-form-logic', $base . 'form-logic.js', [], '1.1.0', true );
    }

    /* ========================= Settings ========================= */
    public function admin_menu() {
        $parent = apply_filters( 'anchor_ctm_forms_parent_menu_slug', 'options-general.php' );
        if ( 'options-general.php' === $parent ) {
            add_options_page(
                __( 'CTM Forms', 'anchor-schema' ),
                __( 'CTM Forms', 'anchor-schema' ),
                'manage_options',
                'anchor-ctm-forms',
                [ $this, 'settings_page' ]
            );
        } else {
            add_submenu_page(
                $parent,
                __( 'CTM Forms', 'anchor-schema' ),
                __( 'CTM Forms', 'anchor-schema' ),
                'manage_options',
                'anchor-ctm-forms',
                [ $this, 'settings_page' ]
            );
        }
    }

    public function register_settings() {
        register_setting( 'anchor_ctm_forms_group', self::OPTION_KEY, [
            'type' => 'array',
            'sanitize_callback' => [ $this, 'sanitize_options' ],
            'default' => [],
        ] );

        add_settings_section(
            'anchor_ctm_main',
            __( 'CTM API Credentials', 'anchor-schema' ),
            function() {
                echo '<p>' . esc_html__( 'Enter your CallTrackingMetrics API credentials. Find these in your CTM account under Settings > API.', 'anchor-schema' ) . '</p>';
            },
            'anchor-ctm-forms'
        );

        add_settings_field( 'access_key', __( 'Access Key', 'anchor-schema' ), [ $this, 'field_text' ], 'anchor-ctm-forms', 'anchor_ctm_main', [ 'key' => 'access_key' ] );
        add_settings_field( 'secret_key', __( 'Secret Key', 'anchor-schema' ), [ $this, 'field_password' ], 'anchor-ctm-forms', 'anchor_ctm_main', [ 'key' => 'secret_key' ] );
        add_settings_field( 'account_id', __( 'Account ID', 'anchor-schema' ), [ $this, 'field_text' ], 'anchor-ctm-forms', 'anchor_ctm_main', [ 'key' => 'account_id' ] );

        // Analytics Settings Section
        add_settings_section(
            'anchor_ctm_analytics',
            __( 'Analytics & Conversion Tracking', 'anchor-schema' ),
            function() {
                echo '<p>' . esc_html__( 'Configure default conversion tracking events. These fire on successful form submission. Leave blank to disable a platform. Individual forms can override these settings.', 'anchor-schema' ) . '</p>';
            },
            'anchor-ctm-forms'
        );

        // Google Analytics 4
        add_settings_field( 'analytics_ga4_event', __( 'GA4 Event Name', 'anchor-schema' ), [ $this, 'field_text' ], 'anchor-ctm-forms', 'anchor_ctm_analytics', [
            'key' => 'analytics_ga4_event',
            'description' => __( 'Event name for gtag (e.g., "form_submit", "generate_lead"). Requires gtag.js installed.', 'anchor-schema' )
        ] );
        add_settings_field( 'analytics_ga4_params', __( 'GA4 Event Parameters', 'anchor-schema' ), [ $this, 'field_text' ], 'anchor-ctm-forms', 'anchor_ctm_analytics', [
            'key' => 'analytics_ga4_params',
            'description' => __( 'Optional JSON params, e.g.: {"event_category": "contact"}', 'anchor-schema' ),
            'class' => 'large-text'
        ] );

        // Google Ads
        add_settings_field( 'analytics_gads_conversion', __( 'Google Ads Conversion', 'anchor-schema' ), [ $this, 'field_text' ], 'anchor-ctm-forms', 'anchor_ctm_analytics', [
            'key' => 'analytics_gads_conversion',
            'description' => __( 'Conversion ID/Label, e.g.: AW-123456789/AbCdEf. Leave blank to disable.', 'anchor-schema' )
        ] );

        // Facebook Pixel
        add_settings_field( 'analytics_fb_event', __( 'Facebook Pixel Event', 'anchor-schema' ), [ $this, 'field_text' ], 'anchor-ctm-forms', 'anchor_ctm_analytics', [
            'key' => 'analytics_fb_event',
            'description' => __( 'Event name for fbq() (e.g., "Lead", "CompleteRegistration"). Requires FB Pixel installed.', 'anchor-schema' )
        ] );
        add_settings_field( 'analytics_fb_params', __( 'Facebook Event Parameters', 'anchor-schema' ), [ $this, 'field_text' ], 'anchor-ctm-forms', 'anchor_ctm_analytics', [
            'key' => 'analytics_fb_params',
            'description' => __( 'Optional JSON params, e.g.: {"content_name": "Contact Form"}', 'anchor-schema' ),
            'class' => 'large-text'
        ] );

        // TikTok Pixel
        add_settings_field( 'analytics_tiktok_event', __( 'TikTok Pixel Event', 'anchor-schema' ), [ $this, 'field_text' ], 'anchor-ctm-forms', 'anchor_ctm_analytics', [
            'key' => 'analytics_tiktok_event',
            'description' => __( 'Event name for ttq.track() (e.g., "SubmitForm", "Contact"). Requires TikTok Pixel installed.', 'anchor-schema' )
        ] );
        add_settings_field( 'analytics_tiktok_params', __( 'TikTok Event Parameters', 'anchor-schema' ), [ $this, 'field_text' ], 'anchor-ctm-forms', 'anchor_ctm_analytics', [
            'key' => 'analytics_tiktok_params',
            'description' => __( 'Optional JSON params.', 'anchor-schema' ),
            'class' => 'large-text'
        ] );

        // Bing UET
        add_settings_field( 'analytics_bing_event', __( 'Bing UET Event', 'anchor-schema' ), [ $this, 'field_text' ], 'anchor-ctm-forms', 'anchor_ctm_analytics', [
            'key' => 'analytics_bing_event',
            'description' => __( 'Event action for uetq (e.g., "submit_lead_form"). Requires Bing UET tag installed.', 'anchor-schema' )
        ] );
        add_settings_field( 'analytics_bing_params', __( 'Bing Event Parameters', 'anchor-schema' ), [ $this, 'field_text' ], 'anchor-ctm-forms', 'anchor_ctm_analytics', [
            'key' => 'analytics_bing_params',
            'description' => __( 'Optional JSON params, e.g.: {"event_category": "lead"}', 'anchor-schema' ),
            'class' => 'large-text'
        ] );
    }

    public function field_text( $args ) {
        $opts = $this->get_options();
        $val = esc_attr( $opts[ $args['key'] ] ?? '' );
        $class = ! empty( $args['class'] ) ? esc_attr( $args['class'] ) : 'regular-text';
        printf( '<input type="text" name="%s[%s]" value="%s" class="%s" />', esc_attr( self::OPTION_KEY ), esc_attr( $args['key'] ), $val, $class );
        if ( ! empty( $args['description'] ) ) {
            printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
        }
    }

    public function field_password( $args ) {
        $opts = $this->get_options();
        $val = esc_attr( $opts[ $args['key'] ] ?? '' );
        printf( '<input type="password" name="%s[%s]" value="%s" class="regular-text" autocomplete="off" />', esc_attr( self::OPTION_KEY ), esc_attr( $args['key'] ), $val );
    }

    public function sanitize_options( $input ) {
        $out = $this->get_options();
        $out['access_key'] = isset( $input['access_key'] ) ? sanitize_text_field( $input['access_key'] ) : '';
        $out['secret_key'] = isset( $input['secret_key'] ) ? sanitize_text_field( $input['secret_key'] ) : '';
        $out['account_id'] = isset( $input['account_id'] ) ? preg_replace( '/[^0-9]/', '', $input['account_id'] ) : '';

        // Analytics fields
        $analytics_fields = [
            'analytics_ga4_event',
            'analytics_ga4_params',
            'analytics_gads_conversion',
            'analytics_fb_event',
            'analytics_fb_params',
            'analytics_tiktok_event',
            'analytics_tiktok_params',
            'analytics_bing_event',
            'analytics_bing_params',
        ];
        foreach ( $analytics_fields as $field ) {
            $out[ $field ] = isset( $input[ $field ] ) ? sanitize_text_field( $input[ $field ] ) : '';
        }

        return $out;
    }

    public function get_options() {
        $defaults = [
            'access_key' => '',
            'secret_key' => '',
            'account_id' => '',
            // Analytics - Global defaults
            'analytics_ga4_event'       => 'form_submit',
            'analytics_ga4_params'      => '',
            'analytics_gads_conversion' => '',
            'analytics_fb_event'        => 'Lead',
            'analytics_fb_params'       => '',
            'analytics_tiktok_event'    => 'SubmitForm',
            'analytics_tiktok_params'   => '',
            'analytics_bing_event'      => 'submit_lead_form',
            'analytics_bing_params'     => '',
        ];
        return wp_parse_args( get_option( self::OPTION_KEY, [] ), $defaults );
    }

    public function settings_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'CTM Forms Settings', 'anchor-schema' ); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'anchor_ctm_forms_group' ); ?>
                <?php do_settings_sections( 'anchor-ctm-forms' ); ?>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /* ========================= CPT ========================= */
    public function register_cpt() {
        register_post_type( 'ctm_form_variant', [
            'label' => __( 'CTM Form Variants', 'anchor-schema' ),
            'public' => false,
            'show_ui' => true,
            'supports' => [ 'title' ],
            'show_in_menu' => true,
            'menu_icon' => 'dashicons-feedback',
        ] );
    }

    /* ========================= Admin list columns ========================= */
    public function add_shortcode_column( $columns ) {
        $columns['ctm_shortcode'] = __( 'Shortcode', 'anchor-schema' );
        return $columns;
    }

    public function render_shortcode_column( $column, $post_id ) {
        if ( $column === 'ctm_shortcode' ) {
            echo '<code>[ctm_form_variant id="' . intval( $post_id ) . '"]</code>';
        }
    }

    /* ========================= Duplicate / Export / Import ========================= */

    /**
     * Add Duplicate + Export JSON links to row actions on the CPT list table.
     */
    public function row_actions( $actions, $post ) {
        if ( $post->post_type !== 'ctm_form_variant' || ! current_user_can( 'edit_posts' ) ) {
            return $actions;
        }

        $dup_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=anchor_ctm_duplicate&post_id=' . $post->ID ),
            'anchor_ctm_duplicate_' . $post->ID
        );
        $actions['duplicate'] = '<a href="' . esc_url( $dup_url ) . '">' . esc_html__( 'Duplicate', 'anchor-schema' ) . '</a>';

        $exp_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=anchor_ctm_export&post_id=' . $post->ID ),
            'anchor_ctm_export_' . $post->ID
        );
        $actions['export_json'] = '<a href="' . esc_url( $exp_url ) . '">' . esc_html__( 'Export JSON', 'anchor-schema' ) . '</a>';

        return $actions;
    }

    /**
     * Duplicate a form as a new draft.
     */
    public function handle_duplicate() {
        $post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;

        if ( ! $post_id || ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'anchor_ctm_duplicate_' . $post_id ) ) {
            wp_die( esc_html__( 'Invalid request.', 'anchor-schema' ) );
        }
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'anchor-schema' ) );
        }

        $source = get_post( $post_id );
        if ( ! $source || $source->post_type !== 'ctm_form_variant' ) {
            wp_die( esc_html__( 'Form not found.', 'anchor-schema' ) );
        }

        $new_id = wp_insert_post( [
            'post_type'   => 'ctm_form_variant',
            'post_status' => 'draft',
            'post_title'  => $source->post_title . ' (Copy)',
        ] );

        if ( is_wp_error( $new_id ) ) {
            wp_die( esc_html( $new_id->get_error_message() ) );
        }

        foreach ( self::$portable_meta_keys as $key ) {
            $value = get_post_meta( $post_id, $key, true );
            if ( $value !== '' && $value !== false ) {
                update_post_meta( $new_id, $key, $value );
            }
        }

        wp_safe_redirect( admin_url( 'post.php?action=edit&post=' . $new_id ) );
        exit;
    }

    /**
     * Export a form's config as a JSON file download.
     */
    public function handle_export() {
        $post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;

        if ( ! $post_id || ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'anchor_ctm_export_' . $post_id ) ) {
            wp_die( esc_html__( 'Invalid request.', 'anchor-schema' ) );
        }
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'anchor-schema' ) );
        }

        $source = get_post( $post_id );
        if ( ! $source || $source->post_type !== 'ctm_form_variant' ) {
            wp_die( esc_html__( 'Form not found.', 'anchor-schema' ) );
        }

        $meta = [];
        foreach ( self::$portable_meta_keys as $key ) {
            $value = get_post_meta( $post_id, $key, true );
            // Decode _ctm_form_config so the JSON is readable (not double-encoded).
            if ( $key === '_ctm_form_config' && is_string( $value ) ) {
                $decoded = json_decode( $value, true );
                $meta[ $key ] = is_array( $decoded ) ? $decoded : $value;
            } else {
                $meta[ $key ] = $value;
            }
        }

        $payload = [
            'anchor_ctm_form_export' => 1,
            'version'                => '1.0',
            'title'                  => $source->post_title,
            'meta'                   => $meta,
        ];

        $filename = sanitize_file_name( $source->post_title ) . '.json';

        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Cache-Control: no-cache, no-store, must-revalidate' );

        echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        exit;
    }

    /**
     * Render the import UI panel on the CTM Forms list page.
     */
    public function render_import_ui() {
        $screen = get_current_screen();
        if ( ! $screen || $screen->id !== 'edit-ctm_form_variant' ) {
            return;
        }

        // Success / error notices from redirect.
        if ( isset( $_GET['ctm_imported'] ) ) {
            $id = absint( $_GET['ctm_imported'] );
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
                esc_html__( 'Form imported as draft.', 'anchor-schema' ),
                esc_url( admin_url( 'post.php?action=edit&post=' . $id ) ),
                esc_html__( 'Edit form', 'anchor-schema' )
            );
        }
        if ( isset( $_GET['ctm_import_error'] ) ) {
            $messages = [
                'file'    => __( 'No file uploaded.', 'anchor-schema' ),
                'parse'   => __( 'Invalid JSON file.', 'anchor-schema' ),
                'format'  => __( 'File is not a valid CTM Form export.', 'anchor-schema' ),
                'create'  => __( 'Could not create form post.', 'anchor-schema' ),
            ];
            $code = sanitize_key( $_GET['ctm_import_error'] );
            $msg  = $messages[ $code ] ?? __( 'Import failed.', 'anchor-schema' );
            printf(
                '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                esc_html( $msg )
            );
        }

        ?>
        <div class="wrap" style="margin: 0 0 20px; padding: 12px 16px; background: #fff; border: 1px solid #c3c4c7; border-radius: 4px;">
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                <?php wp_nonce_field( 'anchor_ctm_import', '_wpnonce' ); ?>
                <input type="hidden" name="action" value="anchor_ctm_import" />
                <strong style="margin-right: 4px;"><?php esc_html_e( 'Import Form:', 'anchor-schema' ); ?></strong>
                <input type="file" name="ctm_form_json" accept=".json,application/json" required />
                <button type="submit" class="button button-secondary"><?php esc_html_e( 'Import JSON', 'anchor-schema' ); ?></button>
            </form>
        </div>
        <?php
    }

    /**
     * Handle form JSON import.
     */
    public function handle_import() {
        if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'anchor_ctm_import' ) ) {
            wp_die( esc_html__( 'Invalid request.', 'anchor-schema' ) );
        }
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'anchor-schema' ) );
        }

        $list_url = admin_url( 'edit.php?post_type=ctm_form_variant' );

        if ( empty( $_FILES['ctm_form_json']['tmp_name'] ) ) {
            wp_safe_redirect( add_query_arg( 'ctm_import_error', 'file', $list_url ) );
            exit;
        }

        $raw  = file_get_contents( $_FILES['ctm_form_json']['tmp_name'] );
        $data = json_decode( $raw, true );

        if ( ! is_array( $data ) ) {
            wp_safe_redirect( add_query_arg( 'ctm_import_error', 'parse', $list_url ) );
            exit;
        }
        if ( empty( $data['anchor_ctm_form_export'] ) || ! isset( $data['meta'] ) ) {
            wp_safe_redirect( add_query_arg( 'ctm_import_error', 'format', $list_url ) );
            exit;
        }

        $title  = ! empty( $data['title'] ) ? sanitize_text_field( $data['title'] ) : __( 'Imported Form', 'anchor-schema' );
        $new_id = wp_insert_post( [
            'post_type'   => 'ctm_form_variant',
            'post_status' => 'draft',
            'post_title'  => $title . ' (Imported)',
        ] );

        if ( is_wp_error( $new_id ) ) {
            wp_safe_redirect( add_query_arg( 'ctm_import_error', 'create', $list_url ) );
            exit;
        }

        foreach ( self::$portable_meta_keys as $key ) {
            if ( ! array_key_exists( $key, $data['meta'] ) ) {
                continue;
            }
            $value = $data['meta'][ $key ];
            // Re-encode _ctm_form_config back to a JSON string for storage.
            if ( $key === '_ctm_form_config' && is_array( $value ) ) {
                $value = wp_json_encode( $value );
            }
            update_post_meta( $new_id, $key, $value );
        }

        wp_safe_redirect( add_query_arg( 'ctm_imported', $new_id, $list_url ) );
        exit;
    }

    /* ========================= API helpers ========================= */
    private function auth_headers() {
        $opts = $this->get_options();
        if ( empty( $opts['access_key'] ) || empty( $opts['secret_key'] ) ) {
            return false;
        }
        return [
            'Authorization' => 'Basic ' . base64_encode( $opts['access_key'] . ':' . $opts['secret_key'] ),
            'Accept'        => 'application/json',
            'User-Agent'    => 'Anchor-CTM-Forms/1.0 (+WordPress)'
        ];
    }

    private function account_id() {
        $opts = $this->get_options();
        return $opts['account_id'] ?? '';
    }

    private function fetch_reactors_list() {
        $acc = $this->account_id();
        $headers = $this->auth_headers();
        if ( ! $acc || ! $headers ) {
            return [];
        }

        $url = "https://api.calltrackingmetrics.com/api/v1/accounts/{$acc}/form_reactors";
        $res = wp_remote_get( $url, [ 'headers' => $headers, 'timeout' => 20 ] );

        if ( is_wp_error( $res ) ) {
            return [];
        }

        $code = wp_remote_retrieve_response_code( $res );
        $body = json_decode( wp_remote_retrieve_body( $res ), true );

        if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
            return [];
        }

        $items = $body['forms'] ?? $body['form_reactors'] ?? $body;
        $out = [];

        foreach ( (array) $items as $r ) {
            if ( empty( $r['id'] ) ) continue;
            $out[] = [
                'id' => (string) $r['id'],
                'name' => (string) ( $r['name'] ?? $r['title'] ?? $r['id'] )
            ];
        }

        return $out;
    }

    /* ========================= AI Form Assistant: Helpers ========================= */

    /**
     * Retrieve the OpenAI API key from Anchor Tools settings, env, or constant.
     */
    private function get_openai_api_key() {
        $key = '';

        if ( class_exists( 'Anchor_Schema_Admin' ) ) {
            $global = get_option( Anchor_Schema_Admin::OPTION_KEY, [] );
            $key = $global['api_key'] ?? '';
            if ( ! $key && isset( $global['openai_api_key'] ) ) {
                $key = $global['openai_api_key'];
            }
        }

        if ( ! $key ) {
            $key = getenv( 'OPENAI_API_KEY' ) ?: ( defined( 'OPENAI_API_KEY' ) ? OPENAI_API_KEY : '' );
        }

        return $key;
    }

    /**
     * Make a chat completion request to OpenAI.
     *
     * @param string $api_key      OpenAI API key.
     * @param string $system_prompt System message.
     * @param string $user_message  User message.
     * @param int    $max_tokens    Max response tokens.
     * @return string|WP_Error      Response content or error.
     */
    private function call_openai_chat( $api_key, $system_prompt, $user_message, $max_tokens = 8192 ) {
        $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
            'timeout' => 60,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body' => wp_json_encode( [
                'model'       => 'gpt-4o-mini',
                'temperature' => 0,
                'max_tokens'  => $max_tokens,
                'messages'    => [
                    [ 'role' => 'system',  'content' => $system_prompt ],
                    [ 'role' => 'user',    'content' => $user_message ],
                ],
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 ) {
            $msg = $body['error']['message'] ?? "OpenAI API error (HTTP {$code})";
            return new \WP_Error( 'openai_error', $msg );
        }

        $content = $body['choices'][0]['message']['content'] ?? '';
        if ( empty( $content ) ) {
            return new \WP_Error( 'openai_empty', 'OpenAI returned an empty response.' );
        }

        return $content;
    }

    /**
     * Build the system prompt describing the form config schema for the AI.
     */
    private function build_ai_system_prompt() {
        return <<<'PROMPT'
You are an AI form-building assistant for a WordPress form builder that submits to CallTrackingMetrics (CTM) FormReactors. You receive the current form configuration as JSON and a natural-language instruction. You return an updated JSON configuration.

## Config Structure
```json
{
  "settings": {
    "labelStyle": "above"|"floating"|"hidden",
    "submitText": "Submit",
    "successMessage": "Thanks! We'll be in touch shortly.",
    "multiStep": false,
    "progressBar": true,
    "titlePage": { "enabled": false, "heading": "", "description": "", "buttonText": "Get Started" },
    "scoring": { "enabled": false, "showTotal": false, "totalLabel": "Your Score", "sendAs": "custom_total_score" }
  },
  "fields": [ ... ]
}
```

## Field Types
Input: text, email, tel, number, url, textarea, select, checkbox, radio, hidden
Layout: heading, paragraph, divider, score_display

## Field Properties
Every field has: id, type, label, name, placeholder, helpText, defaultValue, required (bool), isCustom (bool), width ("full"|"half"|"third"|"quarter"), labelStyle ("inherit"|"above"|"floating"|"hidden"), cssClass, step (0-indexed int), conditions (array), conditionLogic ("all"|"any"), logVisible (bool).

Option-based fields (select, checkbox, radio) also have: options (array of {label, value, score}).
Number fields also have: min, max, numStep.
Score display fields only need: label, name.

## Core CTM Field Names (CRITICAL)
These are the ONLY field names recognized natively by the CTM FormReactor API. They MUST have `isCustom: false`:
- `caller_name` — the caller/contact name (use type "text")
- `email` — email address (use type "email")
- `phone_number` — phone number (use type "tel", ALWAYS required)
- `phone` — alternative phone (use type "tel")
- `country_code` — country code (use type "text")

EVERY other field — including message, service type, appointment reason, consent checkboxes, etc. — is a CUSTOM field and MUST have `isCustom: true`.

## Field Name Rules (CRITICAL)
Field names are used as HTML `name` attributes and as CTM API field identifiers. They MUST be:
- Lowercase
- Snake_case (underscores only, no spaces, no hyphens)
- No special characters (no question marks, periods, quotes, etc.)
- Descriptive and concise

CORRECT examples: `message`, `service_type`, `new_patient`, `sms_consent`, `preferred_date`
WRONG examples: `Are You New?`, `How Can We Help You?`, `SMS Consent`, `Service-Type`

The `label` property holds the human-readable display text (e.g. "Are You New to Our Practice?").
The `name` property holds the machine-safe identifier (e.g. "new_patient").
Never use the label text as the name.

## CTM FormReactor Type Mapping
The builder uses standard HTML field types, but CTM maps them differently:
- radio → CTM type "list" (single-select from options)
- select → CTM type "list" (single-select from options)
- checkbox → CTM type "checklist" (multi-select from options)
- textarea → CTM type "textarea"
- text, email, tel, number, url, hidden → CTM type "text"

Option-based fields (select, radio, checkbox) have their options sent to CTM as a newline-separated string, not a JSON array. This is handled automatically — just provide the options array normally.

## logVisible (Important)
Set `logVisible: true` on custom fields so their values appear in CTM's activity log. Default to `true` for all fields unless there's a specific reason to hide them (e.g. hidden tracking fields). Without this, submitted data won't be visible in CTM's call/form log.

## Field ID Format
Generate IDs as `f_` followed by 8 random alphanumeric characters (e.g. `f_a3b9c2d1`). Never reuse existing IDs.

## Multi-Step Forms
Set `settings.multiStep = true` and assign a `step` property (0-indexed) to each field. Fields with the same step number appear on the same page.

## Scoring
Set `settings.scoring.enabled = true`. Add `score` values to option objects (e.g. `{"label": "Yes", "value": "yes", "score": 10}`). Add a `score_display` field to show the total.

## Conditional Logic
Add conditions to a field's `conditions` array: `{"field": "<field_id>", "operator": "equals"|"not_equals"|"contains"|"is_empty"|"is_not_empty"|"greater_than"|"less_than", "value": "<value>"}`. Set `conditionLogic` to "all" or "any".

## Rules
1. Return ONLY the complete JSON config (settings + fields). No explanations, no markdown fences.
2. Preserve existing field IDs — do not regenerate IDs for fields that already exist.
3. Only modify what the instruction asks for. Keep unrelated fields and settings unchanged.
4. When adding a contact/lead form, always include: caller_name (text, isCustom:false, required:true), email (email, isCustom:false), phone_number (tel, isCustom:false, required:true). Add message as a custom textarea (name:"message", isCustom:true, logVisible:true).
5. When asked to make a multi-step form, distribute fields logically across steps.
6. Default new fields to: required: false, width: "full", labelStyle: "inherit", step: 0, logVisible: true.
7. phone_number should ALWAYS be required — CTM requires it for every FormReactor submission.
8. For consent/opt-in checkboxes, use a single option with the consent text as the label and "Yes" as the value. Set required: false (consent should be voluntary).
PROMPT;
    }

    /**
     * AJAX handler for AI form assistant.
     */
    public function ajax_ai_assist() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }
        check_ajax_referer( self::NONCE_ACTION, self::NONCE_NAME );

        $api_key = $this->get_openai_api_key();
        if ( empty( $api_key ) ) {
            wp_send_json_error( 'No OpenAI API key configured. Add one in Settings → Anchor Tools.' );
        }

        $instruction = isset( $_POST['instruction'] ) ? sanitize_textarea_field( wp_unslash( $_POST['instruction'] ) ) : '';
        if ( empty( $instruction ) ) {
            wp_send_json_error( 'Please enter an instruction.' );
        }

        $raw_config = isset( $_POST['config'] ) ? wp_unslash( $_POST['config'] ) : '{}';
        $current_config = json_decode( $raw_config, true );
        if ( ! is_array( $current_config ) ) {
            $current_config = [ 'settings' => [], 'fields' => [] ];
        }

        $system_prompt = $this->build_ai_system_prompt();
        $user_message  = "Current form config:\n```json\n" . wp_json_encode( $current_config, JSON_PRETTY_PRINT ) . "\n```\n\nInstruction: " . $instruction;

        $result = $this->call_openai_chat( $api_key, $system_prompt, $user_message );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        // Strip markdown fences if present
        $result = trim( $result );
        $result = preg_replace( '/^```(?:json)?\s*/i', '', $result );
        $result = preg_replace( '/\s*```$/', '', $result );

        $new_config = json_decode( $result, true );
        if ( ! is_array( $new_config ) || ! isset( $new_config['settings'] ) || ! isset( $new_config['fields'] ) ) {
            wp_send_json_error( 'AI returned an invalid config. Please try rephrasing your instruction.' );
        }

        wp_send_json_success( [ 'config' => $new_config ] );
    }

    private function fetch_reactor_detail( $reactor_id ) {
        $acc = $this->account_id();
        $headers = $this->auth_headers();
        if ( ! $acc || ! $headers ) {
            return [];
        }

        $url = "https://api.calltrackingmetrics.com/api/v1/accounts/{$acc}/form_reactors/" . rawurlencode( $reactor_id );
        $res = wp_remote_get( $url, [ 'headers' => $headers, 'timeout' => 20 ] );

        if ( is_wp_error( $res ) ) {
            return [];
        }

        $code = wp_remote_retrieve_response_code( $res );
        $body = json_decode( wp_remote_retrieve_body( $res ), true );

        if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
            return [];
        }

        return $body;
    }

    /**
     * Fetch tracking numbers from the CTM API.
     */
    private function fetch_tracking_numbers() {
        $acc     = $this->account_id();
        $headers = $this->auth_headers();
        if ( ! $acc || ! $headers ) {
            return [];
        }

        $url = "https://api.calltrackingmetrics.com/api/v1/accounts/{$acc}/numbers.json";
        $res = wp_remote_get( $url, [ 'headers' => $headers, 'timeout' => 20 ] );

        if ( is_wp_error( $res ) ) {
            return [];
        }

        $code = wp_remote_retrieve_response_code( $res );
        $body = json_decode( wp_remote_retrieve_body( $res ), true );

        if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
            return [];
        }

        // CTM may nest numbers under various keys
        $items = $body['numbers'] ?? $body['tracking_numbers'] ?? $body;

        // If it's a paginated response, look for the array inside
        if ( isset( $items['page'] ) || isset( $items['total_entries'] ) ) {
            $items = $items['numbers'] ?? $items['tracking_numbers'] ?? [];
        }

        $out = [];

        foreach ( (array) $items as $n ) {
            if ( ! is_array( $n ) || empty( $n['id'] ) ) continue;
            // Try common CTM field names for the display number and source name
            $display_number = $n['display_number'] ?? $n['tracking_number'] ?? $n['number'] ?? $n['formatted'] ?? '';
            $source_name    = $n['source'] ?? $n['tracking_source_name'] ?? $n['name'] ?? '';
            if ( is_array( $source_name ) ) {
                $source_name = $source_name['name'] ?? '';
            }

            if ( $source_name && $display_number ) {
                $label = $source_name . ' (' . $display_number . ')';
            } elseif ( $source_name ) {
                $label = $source_name;
            } elseif ( $display_number ) {
                $label = $display_number;
            } else {
                $label = (string) $n['id'];
            }

            $out[] = [
                'id'    => (string) $n['id'],
                'label' => (string) $label,
            ];
        }

        return $out;
    }

    /**
     * Build the shared reactor body fields from builder config (without number-specific data).
     */
    /**
     * Sanitize a field name to a safe machine identifier (lowercase, underscores).
     */
    private static function sanitize_field_name( $name ) {
        $name = preg_replace( '/[^a-zA-Z0-9_\s-]/', '', $name );
        $name = strtolower( trim( $name ) );
        $name = preg_replace( '/[\s-]+/', '_', $name );
        $name = preg_replace( '/_+/', '_', $name );
        return trim( $name, '_' );
    }

    private function build_reactor_fields( $config ) {
        $fields         = $config['fields'] ?? [];
        $include_name   = false;
        $name_required  = false;
        $include_email  = false;
        $email_required = false;
        $custom_fields  = [];

        $type_aliases = [ 'fullname' => 'text', 'message' => 'textarea' ];

        foreach ( $fields as $f ) {
            $fname = $f['name'] ?? '';
            $ftype = $f['type'] ?? 'text';
            $ftype = $type_aliases[ $ftype ] ?? $ftype;

            // Sanitize custom field names to safe identifiers (core names are already clean)
            $core_names = [ 'caller_name', 'email', 'phone_number', 'phone', 'country_code' ];
            if ( ! in_array( $fname, $core_names, true ) ) {
                $fname = self::sanitize_field_name( $fname );
            }

            if ( in_array( $ftype, [ 'heading', 'paragraph', 'divider' ], true ) ) {
                continue;
            }

            if ( $fname === 'caller_name' ) {
                $include_name  = true;
                $name_required = ! empty( $f['required'] );
            } elseif ( $fname === 'email' ) {
                $include_email  = true;
                $email_required = ! empty( $f['required'] );
            } elseif ( in_array( $fname, [ 'phone_number', 'phone', 'country_code' ], true ) ) {
                continue;
            } else {
                $ctm_type_map = [
                    'email'    => 'text',
                    'tel'      => 'text',
                    'number'   => 'text',
                    'url'      => 'text',
                    'hidden'   => 'text',
                    'radio'    => 'list',
                    'select'   => 'list',
                    'checkbox' => 'checklist',
                ];
                $ctm_type = $ctm_type_map[ $ftype ] ?? $ftype;

                $cf = [
                    'name'     => $fname,
                    'type'     => $ctm_type,
                    'label'    => $f['label'] ?? ucfirst( $fname ),
                    'required' => ! empty( $f['required'] ),
                ];

                // CTM expects options as a newline-separated "items" string, not a JSON array.
                if ( ! empty( $f['options'] ) && is_array( $f['options'] ) ) {
                    $labels = array_map( function( $opt ) {
                        return $opt['label'] ?? $opt['value'] ?? '';
                    }, $f['options'] );
                    $cf['items'] = implode( "\n", array_filter( $labels ) );
                }

                // Default to log-visible; only hide if explicitly disabled
                $cf['log_visible'] = ! isset( $f['logVisible'] ) || ! empty( $f['logVisible'] );

                $custom_fields[] = $cf;
            }
        }

        return compact( 'include_name', 'name_required', 'include_email', 'email_required', 'custom_fields' );
    }

    /**
     * Create a single FormReactor in CTM for a specific tracking number.
     *
     * @return string|WP_Error  Reactor ID on success.
     */
    private function create_single_reactor( $name, $phone_number_id, $reactor_fields ) {
        $acc     = $this->account_id();
        $headers = $this->auth_headers();

        $body = [
            'name'                    => $name,
            'virtual_phone_number_id' => $phone_number_id,
            'include_name'            => $reactor_fields['include_name'],
            'name_required'           => $reactor_fields['name_required'],
            'include_email'           => $reactor_fields['include_email'],
            'email_required'          => $reactor_fields['email_required'],
        ];

        if ( ! empty( $reactor_fields['custom_fields'] ) ) {
            $body['custom_fields'] = $reactor_fields['custom_fields'];
        }

        $url      = "https://api.calltrackingmetrics.com/api/v1/accounts/{$acc}/form_reactors";
        $response = wp_remote_post( $url, [
            'headers' => array_merge( $headers, [ 'Content-Type' => 'application/json' ] ),
            'timeout' => 30,
            'body'    => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'ctm_api_error', 'CTM API request failed: ' . $response->get_error_message() );
        }

        $code     = wp_remote_retrieve_response_code( $response );
        $raw_body = wp_remote_retrieve_body( $response );
        $data     = json_decode( $raw_body, true );

        if ( $code >= 200 && $code < 300 ) {
            // Response may nest under 'form_reactor' key
            $reactor_id = $data['form_reactor']['id'] ?? $data['id'] ?? '';
            if ( $reactor_id ) {
                return (string) $reactor_id;
            }
        }

        // Build a useful error message
        $error_msg = 'CTM API returned ' . $code;
        if ( ! empty( $data['error'] ) ) {
            $error_msg .= ': ' . ( is_array( $data['error'] ) ? wp_json_encode( $data['error'] ) : $data['error'] );
        } elseif ( ! empty( $data['message'] ) ) {
            $error_msg .= ': ' . ( is_array( $data['message'] ) ? wp_json_encode( $data['message'] ) : $data['message'] );
        } elseif ( ! empty( $data['errors'] ) ) {
            if ( is_array( $data['errors'] ) ) {
                $parts = [];
                foreach ( $data['errors'] as $key => $e ) {
                    $val = is_array( $e ) ? implode( ', ', $e ) : $e;
                    $parts[] = is_string( $key ) ? "{$key} {$val}" : $val;
                }
                $error_msg .= ': ' . implode( '; ', $parts );
            } else {
                $error_msg .= ': ' . $data['errors'];
            }
        } else {
            $error_msg .= '. Response: ' . substr( $raw_body, 0, 400 );
        }

        return new WP_Error( 'ctm_api_error', $error_msg );
    }

    /**
     * Create FormReactors for ALL tracking numbers in the account.
     *
     * Each number gets its own reactor so submissions can be dynamically
     * routed based on visitor attribution (Google Ads, Facebook, organic, etc.).
     *
     * @return array|WP_Error  Reactor map on success, WP_Error on failure.
     */
    /**
     * Create a single FormReactor on publish using the default/website tracking number.
     * CTM's visitor_sid (from their JS) handles source attribution automatically.
     *
     * @return string|WP_Error  Reactor ID on success.
     */
    private function create_form_reactor( $post_id, $config ) {
        $acc     = $this->account_id();
        $headers = $this->auth_headers();
        if ( ! $acc || ! $headers ) {
            return new WP_Error( 'ctm_missing_creds', 'CTM API credentials not configured. Set them under CTM Forms settings.' );
        }

        $numbers = $this->fetch_tracking_numbers();
        if ( empty( $numbers ) ) {
            return new WP_Error( 'ctm_no_numbers', 'No tracking numbers found in CTM account. Add at least one number in your CTM dashboard.' );
        }

        // Pick the website/default tracking number
        $chosen = end( $numbers ); // fallback to last
        foreach ( $numbers as $n ) {
            $name = strtolower( $n['label'] );
            if ( strpos( $name, 'website' ) !== false || strpos( $name, 'default' ) !== false || strpos( $name, 'direct' ) !== false ) {
                $chosen = $n;
                break;
            }
        }

        $post_title     = get_the_title( $post_id );
        $reactor_name   = $post_title ?: 'Form #' . $post_id;
        $reactor_fields = $this->build_reactor_fields( $config );

        return $this->create_single_reactor( $reactor_name, $chosen['id'], $reactor_fields );
    }

    /**
     * Display admin notices for builder errors.
     */
    public function admin_notices() {
        $screen = get_current_screen();
        if ( ! $screen || $screen->post_type !== 'ctm_form_variant' ) {
            return;
        }

        global $post;
        if ( ! $post ) {
            return;
        }

        $error = get_transient( 'ctm_builder_error_' . $post->ID );
        if ( $error ) {
            delete_transient( 'ctm_builder_error_' . $post->ID );
            printf(
                '<div class="notice notice-error is-dismissible"><p><strong>%s</strong> %s</p></div>',
                esc_html__( 'CTM Form Reactor creation failed:', 'anchor-schema' ),
                esc_html( $error )
            );
        }
    }

    /* ========================= Metaboxes ========================= */
    public function add_variant_metabox() {
        add_meta_box( 'anchor_ctm_variant', __( 'CTM Form Builder', 'anchor-schema' ), [ $this, 'variant_metabox_cb' ], 'ctm_form_variant', 'normal', 'high' );
        add_meta_box( 'anchor_ctm_builder_sidebar', __( 'Builder Settings', 'anchor-schema' ), [ $this, 'builder_sidebar_cb' ], 'ctm_form_variant', 'side', 'default' );
    }

    /* ========================= Metabox: Builder Sidebar ========================= */
    public function builder_sidebar_cb( $post ) {
        $reactor_id = get_post_meta( $post->ID, '_ctm_builder_reactor_id', true );
        ?>
        <div id="ctm-builder-sidebar">
            <?php if ( $reactor_id ) : ?>
                <p><strong><?php esc_html_e( 'FormReactor', 'anchor-schema' ); ?></strong></p>
                <p style="font-size:12px;"><code><?php echo esc_html( $reactor_id ); ?></code></p>
                <p class="description"><?php esc_html_e( 'CTM visitor tracking handles source attribution automatically.', 'anchor-schema' ); ?></p>
            <?php else : ?>
                <p class="ctm-sidebar-empty"><?php esc_html_e( 'A FormReactor will be created automatically when you publish.', 'anchor-schema' ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ========================= Metabox: Form Builder (Tabbed) ========================= */
    public function variant_metabox_cb( $post ) {
        $reactor_id = get_post_meta( $post->ID, '_ctm_reactor_id', true );
        $html = get_post_meta( $post->ID, '_ctm_form_html', true );
        $form_mode = get_post_meta( $post->ID, '_ctm_form_mode', true ) ?: 'reactor';
        $form_config = get_post_meta( $post->ID, '_ctm_form_config', true );
        $reactors = $this->fetch_reactors_list();

        // Get per-form analytics overrides
        $analytics_override = get_post_meta( $post->ID, '_ctm_analytics_override', true ) ? true : false;
        $analytics = $this->get_form_analytics( $post->ID );

        // Multi-step meta (reactor tab)
        $ms_enabled = get_post_meta( $post->ID, '_ctm_multi_step', true );
        $tp_enabled = get_post_meta( $post->ID, '_ctm_title_page', true );

        wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
        ?>
        <input type="hidden" name="ctm_form_mode" id="ctm_form_mode" value="<?php echo esc_attr( $form_mode ); ?>" />

        <!-- Tab bar -->
        <div class="ctm-tabs">
            <button type="button" class="ctm-tab-btn<?php echo $form_mode !== 'builder' ? ' active' : ''; ?>" data-tab="reactor"><?php esc_html_e( 'Use Existing Reactor', 'anchor-schema' ); ?></button>
            <button type="button" class="ctm-tab-btn<?php echo $form_mode === 'builder' ? ' active' : ''; ?>" data-tab="builder"><?php esc_html_e( 'Build Custom Form', 'anchor-schema' ); ?></button>
        </div>

        <!-- ═══════════════ TAB 1: Reactor Mode ═══════════════ -->
        <div id="ctm-tab-reactor" class="ctm-tab-panel<?php echo $form_mode !== 'builder' ? ' active' : ''; ?>">
            <div class="ctm-box">
                <p><label><strong><?php esc_html_e( 'Choose Form Reactor', 'anchor-schema' ); ?></strong><br>
                    <select name="ctm_reactor_id" id="ctm_reactor_id_reactor" style="width:100%">
                        <option value=""><?php esc_html_e( '— Select —', 'anchor-schema' ); ?></option>
                        <?php foreach ( $reactors as $r ): ?>
                        <option value="<?php echo esc_attr( $r['id'] ); ?>" <?php selected( $reactor_id, $r['id'] ); ?>>
                            <?php echo esc_html( $r['name'] . ' — ' . $r['id'] ); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </label></p>

                <p class="ctm-reactor-row">
                    <button type="button" class="button" id="ctm-generate"><?php esc_html_e( 'Generate Starter Form', 'anchor-schema' ); ?></button>
                    <label>
                        <input type="checkbox" id="ctm_floating_labels" value="1" />
                        <?php esc_html_e( 'Floating labels', 'anchor-schema' ); ?>
                    </label>
                    <label>
                        <input type="checkbox" name="ctm_multi_step" id="ctm_multi_step_reactor" value="1" <?php checked( $ms_enabled ); ?> />
                        <?php esc_html_e( 'Multi-Step Form', 'anchor-schema' ); ?>
                    </label>
                    <label id="ctm-title-page-label-reactor">
                        <input type="checkbox" name="ctm_title_page" id="ctm_title_page_reactor" value="1" <?php checked( $tp_enabled ); ?> />
                        <?php esc_html_e( 'Add Title Page?', 'anchor-schema' ); ?>
                    </label>
                </p>

                <?php $tp_visible = $ms_enabled && $tp_enabled; ?>
                <div id="ctm-title-page-fields-reactor" class="ctm-title-page-fields" style="<?php echo $tp_visible ? '' : 'display:none;'; ?>">
                    <p><label><?php esc_html_e( 'Title Page Heading', 'anchor-schema' ); ?><br>
                        <input type="text" name="ctm_title_heading" value="<?php echo esc_attr( get_post_meta( $post->ID, '_ctm_title_heading', true ) ); ?>" class="large-text" placeholder="e.g. Welcome to Our Quiz" />
                    </label></p>
                    <p><label><?php esc_html_e( 'Title Page Description', 'anchor-schema' ); ?><br>
                        <textarea name="ctm_title_desc" rows="3" class="large-text" placeholder="Brief description shown on the title page..."><?php echo esc_textarea( get_post_meta( $post->ID, '_ctm_title_desc', true ) ); ?></textarea>
                    </label></p>
                    <p><label><?php esc_html_e( 'Start Button Text', 'anchor-schema' ); ?><br>
                        <input type="text" name="ctm_start_text" value="<?php echo esc_attr( get_post_meta( $post->ID, '_ctm_start_text', true ) ?: 'Get Started' ); ?>" class="regular-text" />
                    </label></p>
                </div>

                <div id="ctm-ms-instructions" class="ctm-ms-instructions" style="<?php echo $ms_enabled ? '' : 'display:none;'; ?>">
                    <p class="ctm-ms-title"><?php esc_html_e( 'Multi-Step Setup', 'anchor-schema' ); ?></p>
                    <p class="ctm-ms-desc"><?php esc_html_e( 'Wrap each group of fields in a step div. Each div becomes one step. The submit button must be inside the last step.', 'anchor-schema' ); ?></p>
<pre>&lt;form id="ctmForm" novalidate&gt;

  &lt;!-- Step 1 --&gt;
  &lt;div class="ctm-multi-step-item"&gt;
    &lt;label&gt;Name&lt;input type="text" name="caller_name"&gt;&lt;/label&gt;
  &lt;/div&gt;

  &lt;!-- Step 2 --&gt;
  &lt;div class="ctm-multi-step-item"&gt;
    &lt;label&gt;Phone&lt;input type="tel" name="phone_number" required&gt;&lt;/label&gt;
    &lt;label&gt;Email&lt;input type="email" name="email"&gt;&lt;/label&gt;
    &lt;button type="submit"&gt;Submit&lt;/button&gt;
  &lt;/div&gt;

&lt;/form&gt;</pre>
                    <p class="ctm-ms-note"><?php esc_html_e( 'Progress bar, step counter, and Back/Continue buttons are added automatically. The title page (if enabled) is injected before Step 1.', 'anchor-schema' ); ?></p>
                </div>

                <p><label><strong><?php esc_html_e( 'Form HTML', 'anchor-schema' ); ?></strong></label></p>
                <p><textarea name="ctm_form_html" id="ctm_form_html" class="ctm-form-html-editor" placeholder="<?php esc_attr_e( 'Starter HTML will appear here after you choose a reactor and click Generate.', 'anchor-schema' ); ?>"><?php echo esc_textarea( $html ); ?></textarea></p>
            </div>
        </div>

        <!-- ═══════════════ TAB 2: Builder Mode ═══════════════ -->
        <div id="ctm-tab-builder" class="ctm-tab-panel<?php echo $form_mode === 'builder' ? ' active' : ''; ?>">
            <?php
            $existing_rid = get_post_meta( $post->ID, '_ctm_builder_reactor_id', true );
            if ( $existing_rid ) : ?>
                <div class="ctm-builder-info">
                    <?php echo esc_html( sprintf( __( 'FormReactor: %s', 'anchor-schema' ), $existing_rid ) ); ?>
                </div>
            <?php endif; ?>

            <!-- AI Form Assistant -->
            <div class="ctm-ai-panel" id="ctm-ai-panel">
                <button type="button" class="ctm-ai-toggle" id="ctm-ai-toggle">
                    <span class="dashicons dashicons-admin-generic"></span>
                    <span class="ctm-ai-toggle-text"><?php esc_html_e( 'AI Form Assistant', 'anchor-schema' ); ?></span>
                    <span class="ctm-ai-arrow dashicons dashicons-arrow-down-alt2"></span>
                </button>
                <div class="ctm-ai-body" id="ctm-ai-body" style="display:none;">
                    <p class="ctm-ai-desc"><?php esc_html_e( 'Describe what you want and the AI will update your form. Examples: "Add fields for name, email, and phone" or "Make it a 2-step form".', 'anchor-schema' ); ?></p>
                    <textarea id="ctm-ai-instruction" class="ctm-ai-instruction" rows="3" placeholder="<?php esc_attr_e( 'e.g. Add a contact form with name, email, phone, and message fields', 'anchor-schema' ); ?>"></textarea>
                    <div class="ctm-ai-actions">
                        <button type="button" class="button button-primary" id="ctm-ai-apply"><?php esc_html_e( 'Apply', 'anchor-schema' ); ?></button>
                        <span class="spinner" id="ctm-ai-spinner"></span>
                        <span class="ctm-ai-status" id="ctm-ai-status"></span>
                    </div>
                    <div class="ctm-ai-error" id="ctm-ai-error" style="display:none;"></div>
                </div>
            </div>

            <!-- Field palette -->
            <div class="ctm-builder-palette">
                <span class="palette-group-label"><?php esc_html_e( 'Input Fields', 'anchor-schema' ); ?></span>
                <button type="button" class="ctm-palette-btn" data-type="fullname"><span class="dashicons dashicons-admin-users"></span> <?php esc_html_e( 'Full Name', 'anchor-schema' ); ?></button>
                <button type="button" class="ctm-palette-btn" data-type="email"><span class="dashicons dashicons-email"></span> <?php esc_html_e( 'Email', 'anchor-schema' ); ?></button>
                <button type="button" class="ctm-palette-btn" data-type="tel"><span class="dashicons dashicons-phone"></span> <?php esc_html_e( 'Phone', 'anchor-schema' ); ?></button>
                <button type="button" class="ctm-palette-btn" data-type="message"><span class="dashicons dashicons-format-chat"></span> <?php esc_html_e( 'Message', 'anchor-schema' ); ?></button>
                <?php
                $input_types = [ 'text', 'number', 'url', 'textarea', 'select', 'checkbox', 'radio', 'hidden' ];
                $icons = [
                    'text' => 'dashicons-editor-textcolor', 'number' => 'dashicons-calculator',
                    'url' => 'dashicons-admin-links', 'textarea' => 'dashicons-editor-paragraph',
                    'select' => 'dashicons-arrow-down-alt2', 'checkbox' => 'dashicons-yes-alt', 'radio' => 'dashicons-marker',
                    'hidden' => 'dashicons-hidden',
                ];
                foreach ( $input_types as $t ):
                    $label = ucfirst( $t );
                ?>
                    <button type="button" class="ctm-palette-btn" data-type="<?php echo esc_attr( $t ); ?>">
                        <span class="dashicons <?php echo esc_attr( $icons[ $t ] ); ?>"></span> <?php echo esc_html( $label ); ?>
                    </button>
                <?php endforeach; ?>

                <span class="palette-group-label"><?php esc_html_e( 'Layout', 'anchor-schema' ); ?></span>
                <button type="button" class="ctm-palette-btn" data-type="heading"><span class="dashicons dashicons-heading"></span> <?php esc_html_e( 'Heading', 'anchor-schema' ); ?></button>
                <button type="button" class="ctm-palette-btn" data-type="paragraph"><span class="dashicons dashicons-editor-alignleft"></span> <?php esc_html_e( 'Paragraph', 'anchor-schema' ); ?></button>
                <button type="button" class="ctm-palette-btn" data-type="divider"><span class="dashicons dashicons-minus"></span> <?php esc_html_e( 'Divider', 'anchor-schema' ); ?></button>
                <span id="ctm-palette-score-display" style="display:none;"><button type="button" class="ctm-palette-btn" data-type="score_display"><span class="dashicons dashicons-chart-bar"></span> <?php esc_html_e( 'Score Display', 'anchor-schema' ); ?></button></span>
            </div>

            <!-- Multi-step controls (shown when multi-step enabled in builder settings) -->
            <div id="ctm-multistep-controls" class="ctm-multistep-controls" style="display:none;"></div>

            <!-- Sortable field canvas -->
            <div id="ctm-field-canvas" class="empty"><span><?php esc_html_e( 'Click a button above to add fields', 'anchor-schema' ); ?></span></div>

            <!-- Hidden config textarea -->
            <textarea name="ctm_form_config" id="ctm_form_config" style="display:none;"><?php echo esc_textarea( $form_config ); ?></textarea>

            <!-- Live preview -->
            <div class="ctm-builder-preview">
                <h4><?php esc_html_e( 'Preview', 'anchor-schema' ); ?></h4>
                <div id="ctm-builder-preview-frame" class="ctm-builder-preview-frame">
                    <div class="ctm-preview-empty"><?php esc_html_e( 'Preview will appear here', 'anchor-schema' ); ?></div>
                </div>
            </div>
        </div>

        <!-- ═══════════════ SHARED: Shortcode + Analytics ═══════════════ -->
        <div class="ctm-shared-section">
            <div class="ctm-box">
                <p><strong><?php esc_html_e( 'Shortcode', 'anchor-schema' ); ?></strong></p>
                <div class="ctm-shortcode-row">
                    <input type="text" readonly value="[ctm_form_variant id=&quot;<?php echo intval( $post->ID ); ?>&quot;]" id="ctm-shortcode-field" />
                    <button type="button" class="button" id="ctm-copy-sc"><?php esc_html_e( 'Copy', 'anchor-schema' ); ?></button>
                </div>
                <p class="description"><?php esc_html_e( 'Embed this saved form variant using the shortcode.', 'anchor-schema' ); ?></p>
            </div>

            <!-- Analytics Override Section -->
            <div class="ctm-box">
                <p>
                    <label>
                        <input type="checkbox" name="ctm_analytics_override" id="ctm_analytics_override" value="1" <?php checked( $analytics_override ); ?> />
                        <strong><?php esc_html_e( 'Override Global Analytics Settings', 'anchor-schema' ); ?></strong>
                    </label>
                </p>
                <p class="description"><?php esc_html_e( 'Check to use custom tracking settings for this form instead of the global defaults.', 'anchor-schema' ); ?></p>

                <div id="ctm-analytics-fields" class="ctm-analytics-fields" style="<?php echo $analytics_override ? '' : 'display:none;'; ?>">
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e( 'GA4 Event Name', 'anchor-schema' ); ?></th>
                            <td>
                                <input type="text" name="ctm_analytics[ga4_event]" value="<?php echo esc_attr( $analytics['ga4_event'] ); ?>" class="regular-text" />
                                <p class="description"><?php esc_html_e( 'Leave blank to disable GA4 tracking for this form.', 'anchor-schema' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'GA4 Parameters', 'anchor-schema' ); ?></th>
                            <td>
                                <input type="text" name="ctm_analytics[ga4_params]" value="<?php echo esc_attr( $analytics['ga4_params'] ); ?>" class="large-text" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Google Ads Conversion', 'anchor-schema' ); ?></th>
                            <td>
                                <input type="text" name="ctm_analytics[gads_conversion]" value="<?php echo esc_attr( $analytics['gads_conversion'] ); ?>" class="regular-text" />
                                <p class="description"><?php esc_html_e( 'e.g., AW-123456789/AbCdEf', 'anchor-schema' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Facebook Pixel Event', 'anchor-schema' ); ?></th>
                            <td>
                                <input type="text" name="ctm_analytics[fb_event]" value="<?php echo esc_attr( $analytics['fb_event'] ); ?>" class="regular-text" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Facebook Parameters', 'anchor-schema' ); ?></th>
                            <td>
                                <input type="text" name="ctm_analytics[fb_params]" value="<?php echo esc_attr( $analytics['fb_params'] ); ?>" class="large-text" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'TikTok Pixel Event', 'anchor-schema' ); ?></th>
                            <td>
                                <input type="text" name="ctm_analytics[tiktok_event]" value="<?php echo esc_attr( $analytics['tiktok_event'] ); ?>" class="regular-text" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'TikTok Parameters', 'anchor-schema' ); ?></th>
                            <td>
                                <input type="text" name="ctm_analytics[tiktok_params]" value="<?php echo esc_attr( $analytics['tiktok_params'] ); ?>" class="large-text" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Bing UET Event', 'anchor-schema' ); ?></th>
                            <td>
                                <input type="text" name="ctm_analytics[bing_event]" value="<?php echo esc_attr( $analytics['bing_event'] ); ?>" class="regular-text" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Bing Parameters', 'anchor-schema' ); ?></th>
                            <td>
                                <input type="text" name="ctm_analytics[bing_params]" value="<?php echo esc_attr( $analytics['bing_params'] ); ?>" class="large-text" />
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <?php
    }

    /**
     * Get analytics settings for a specific form variant.
     * Returns per-form settings if override is enabled, otherwise global defaults.
     */
    public function get_form_analytics( $post_id ) {
        $opts = $this->get_options();
        $defaults = [
            'ga4_event'       => $opts['analytics_ga4_event'] ?? 'form_submit',
            'ga4_params'      => $opts['analytics_ga4_params'] ?? '',
            'gads_conversion' => $opts['analytics_gads_conversion'] ?? '',
            'fb_event'        => $opts['analytics_fb_event'] ?? 'Lead',
            'fb_params'       => $opts['analytics_fb_params'] ?? '',
            'tiktok_event'    => $opts['analytics_tiktok_event'] ?? 'SubmitForm',
            'tiktok_params'   => $opts['analytics_tiktok_params'] ?? '',
            'bing_event'      => $opts['analytics_bing_event'] ?? 'submit_lead_form',
            'bing_params'     => $opts['analytics_bing_params'] ?? '',
        ];

        // Check if override is enabled
        $override = get_post_meta( $post_id, '_ctm_analytics_override', true );
        if ( ! $override ) {
            return $defaults;
        }

        // Get per-form settings
        $form_analytics = get_post_meta( $post_id, '_ctm_analytics', true );
        if ( ! is_array( $form_analytics ) ) {
            return $defaults;
        }

        return wp_parse_args( $form_analytics, $defaults );
    }

    /* Build starter HTML */
    private function build_starter_html_from_detail( $detail, $floating_labels = false ) {
        $fields = [];

        if ( ! empty( $detail['include_name'] ) ) {
            $fields[] = [ 'name' => 'caller_name', 'type' => 'text', 'label' => 'Name', 'required' => ! empty( $detail['name_required'] ) ];
        }
        if ( ! empty( $detail['include_email'] ) ) {
            $fields[] = [ 'name' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => ! empty( $detail['email_required'] ) ];
        }
        $fields[] = [ 'name' => 'phone_number', 'type' => 'tel', 'label' => 'Phone', 'required' => true ];

        if ( ! empty( $detail['custom_fields'] ) && is_array( $detail['custom_fields'] ) ) {
            foreach ( $detail['custom_fields'] as $cf ) {
                if ( is_array( $cf ) && ! empty( $cf['name'] ) ) {
                    $type = strtolower( (string) ( $cf['type'] ?? 'text' ) );

                    // Supported field types
                    $allowed_types = [ 'textarea', 'email', 'tel', 'text', 'number', 'url', 'select', 'checkbox', 'radio' ];
                    if ( ! in_array( $type, $allowed_types, true ) ) {
                        $type = 'text';
                    }

                    // Parse options for select/checkbox/radio fields
                    $options = [];
                    if ( in_array( $type, [ 'select', 'checkbox', 'radio' ], true ) ) {
                        // CTM may provide options as 'options', 'choices', or 'values'
                        $raw_options = $cf['options'] ?? $cf['choices'] ?? $cf['values'] ?? [];
                        if ( is_string( $raw_options ) ) {
                            // Options might be comma-separated or newline-separated
                            $raw_options = preg_split( '/[\n,]+/', $raw_options );
                        }
                        if ( is_array( $raw_options ) ) {
                            foreach ( $raw_options as $opt ) {
                                if ( is_array( $opt ) ) {
                                    // Option might be { value: 'x', label: 'X' } or { name: 'X', value: 'x' }
                                    $options[] = [
                                        'value' => (string) ( $opt['value'] ?? $opt['name'] ?? $opt['label'] ?? '' ),
                                        'label' => (string) ( $opt['label'] ?? $opt['name'] ?? $opt['value'] ?? '' ),
                                    ];
                                } else {
                                    $opt = trim( (string) $opt );
                                    if ( $opt !== '' ) {
                                        $options[] = [ 'value' => $opt, 'label' => $opt ];
                                    }
                                }
                            }
                        }
                    }

                    $fields[] = [
                        'name'     => (string) $cf['name'],
                        'type'     => $type,
                        'label'    => (string) ( $cf['label'] ?? ucfirst( $cf['name'] ) ),
                        'required' => ! empty( $cf['required'] ),
                        'custom'   => true,
                        'options'  => $options,
                    ];
                }
            }
        }

        $html = "<form id=\"ctmForm\" novalidate>\n";
        foreach ( $fields as $f ) {
            $label = esc_html( $f['label'] ?? $f['name'] );
            $name = esc_attr( $f['name'] );
            $type = esc_attr( $f['type'] ?? 'text' );
            $req = ! empty( $f['required'] ) ? ' required' : '';
            $is_custom = ! empty( $f['custom'] );
            $options = $f['options'] ?? [];

            // Floating labels only apply to text, email, tel, number, url, textarea
            $use_floating = $floating_labels && in_array( $type, [ 'text', 'email', 'tel', 'number', 'url', 'textarea' ], true );

            if ( $use_floating ) {
                // Floating label markup
                $input_cls = $is_custom ? 'ctm-custom input-field' : 'input-field';
                if ( $type === 'textarea' ) {
                    $html .= "  <div class=\"input\">\n";
                    $html .= "    <textarea name=\"{$name}\" class=\"{$input_cls}\"{$req}></textarea>\n";
                    $html .= "    <label class=\"input-label\">{$label}</label>\n";
                    $html .= "  </div>\n";
                } else {
                    $html .= "  <div class=\"input\">\n";
                    $html .= "    <input class=\"{$input_cls}\" type=\"{$type}\" name=\"{$name}\"{$req}>\n";
                    $html .= "    <label class=\"input-label\">{$label}</label>\n";
                    $html .= "  </div>\n";
                }
            } elseif ( $type === 'textarea' ) {
                $cls = $is_custom ? ' class="ctm-custom"' : '';
                $html .= "  <label>{$label}<textarea name=\"{$name}\"{$cls}{$req}></textarea></label>\n";
            } elseif ( $type === 'select' ) {
                $cls = $is_custom ? ' class="ctm-custom"' : '';
                $html .= "  <label>{$label}\n";
                $html .= "    <select name=\"{$name}\"{$cls}{$req}>\n";
                $html .= "      <option value=\"\">— Select —</option>\n";
                foreach ( $options as $opt ) {
                    $optVal = esc_attr( $opt['value'] );
                    $optLabel = esc_html( $opt['label'] );
                    $html .= "      <option value=\"{$optVal}\">{$optLabel}</option>\n";
                }
                $html .= "    </select>\n";
                $html .= "  </label>\n";
            } elseif ( $type === 'checkbox' ) {
                $cls = $is_custom ? ' class="ctm-custom"' : '';
                $html .= "  <fieldset>\n";
                $html .= "    <legend>{$label}</legend>\n";
                foreach ( $options as $i => $opt ) {
                    $optVal = esc_attr( $opt['value'] );
                    $optLabel = esc_html( $opt['label'] );
                    $html .= "    <label><input type=\"checkbox\" name=\"{$name}[]\" value=\"{$optVal}\"{$cls}> {$optLabel}</label>\n";
                }
                $html .= "  </fieldset>\n";
            } elseif ( $type === 'radio' ) {
                $cls = $is_custom ? ' class="ctm-custom"' : '';
                $html .= "  <fieldset>\n";
                $html .= "    <legend>{$label}</legend>\n";
                foreach ( $options as $opt ) {
                    $optVal = esc_attr( $opt['value'] );
                    $optLabel = esc_html( $opt['label'] );
                    $html .= "    <label><input type=\"radio\" name=\"{$name}\" value=\"{$optVal}\"{$cls}{$req}> {$optLabel}</label>\n";
                }
                $html .= "  </fieldset>\n";
            } else {
                $cls = $is_custom ? ' class="ctm-custom"' : '';
                $html .= "  <label>{$label}<input type=\"{$type}\" name=\"{$name}\"{$cls}{$req}></label>\n";
            }
        }
        $html .= "  <button type=\"submit\">Submit</button>\n";
        $html .= "</form>";

        return $html;
    }

    /* ========================= Save ========================= */
    public function save_variant( $post_id ) {
        if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( $_POST[ self::NONCE_NAME ], self::NONCE_ACTION ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // Save form mode
        $mode = isset( $_POST['ctm_form_mode'] ) ? sanitize_text_field( $_POST['ctm_form_mode'] ) : 'reactor';
        update_post_meta( $post_id, '_ctm_form_mode', $mode );

        if ( $mode === 'builder' ) {
            // ── Builder mode ──
            $config = null;

            // Save config JSON
            if ( isset( $_POST['ctm_form_config'] ) ) {
                $raw_config = wp_unslash( $_POST['ctm_form_config'] );
                update_post_meta( $post_id, '_ctm_form_config', $raw_config );

                $config = json_decode( $raw_config, true );
                if ( is_array( $config ) ) {
                    // Render config → HTML and store in _ctm_form_html
                    $rendered_html = $this->render_config_to_html( $config );
                    update_post_meta( $post_id, '_ctm_form_html', $rendered_html );

                    // Set multi-step meta from builder config
                    $settings = $config['settings'] ?? [];
                    update_post_meta( $post_id, '_ctm_multi_step', ! empty( $settings['multiStep'] ) ? 1 : 0 );
                    update_post_meta( $post_id, '_ctm_title_page', ! empty( $settings['titlePage']['enabled'] ) ? 1 : 0 );
                    if ( ! empty( $settings['titlePage'] ) ) {
                        update_post_meta( $post_id, '_ctm_title_heading', sanitize_text_field( $settings['titlePage']['heading'] ?? '' ) );
                        update_post_meta( $post_id, '_ctm_title_desc', wp_kses_post( $settings['titlePage']['description'] ?? '' ) );
                        $start = sanitize_text_field( $settings['titlePage']['buttonText'] ?? 'Get Started' );
                        update_post_meta( $post_id, '_ctm_start_text', $start ?: 'Get Started' );
                    }

                    // Store success message from builder config
                    if ( ! empty( $settings['successMessage'] ) ) {
                        update_post_meta( $post_id, '_ctm_success_message', sanitize_text_field( $settings['successMessage'] ) );
                    }
                }
            }

            // ── Create / recreate FormReactor on publish when fields change ──
            $post_status   = get_post_status( $post_id );

            if ( $post_status === 'publish' && is_array( $config ) ) {
                $reactor_fields = $this->build_reactor_fields( $config );
                $fields_hash    = md5( wp_json_encode( $reactor_fields ) );
                $stored_hash    = get_post_meta( $post_id, '_ctm_reactor_fields_hash', true );

                if ( $fields_hash !== $stored_hash ) {
                    $result = $this->create_form_reactor( $post_id, $config );

                    if ( is_wp_error( $result ) ) {
                        // Revert to draft on failure
                        remove_action( 'save_post_ctm_form_variant', [ $this, 'save_variant' ] );
                        wp_update_post( [
                            'ID'          => $post_id,
                            'post_status' => 'draft',
                        ] );
                        add_action( 'save_post_ctm_form_variant', [ $this, 'save_variant' ] );

                        set_transient( 'ctm_builder_error_' . $post_id, $result->get_error_message(), 60 );
                    } else {
                        update_post_meta( $post_id, '_ctm_builder_reactor_id', $result );
                        update_post_meta( $post_id, '_ctm_reactor_id', $result );
                        update_post_meta( $post_id, '_ctm_reactor_fields_hash', $fields_hash );
                    }
                }
            }

        } else {
            // ── Reactor mode (existing behavior) ──
            if ( isset( $_POST['ctm_form_html'] ) ) {
                update_post_meta( $post_id, '_ctm_form_html', wp_unslash( $_POST['ctm_form_html'] ) );
            }
            if ( isset( $_POST['ctm_reactor_id'] ) ) {
                update_post_meta( $post_id, '_ctm_reactor_id', sanitize_text_field( $_POST['ctm_reactor_id'] ) );
            }

            /* ── Multi-Step fields (reactor tab) ── */
            update_post_meta( $post_id, '_ctm_multi_step', isset( $_POST['ctm_multi_step'] ) ? 1 : 0 );
            update_post_meta( $post_id, '_ctm_title_page', isset( $_POST['ctm_title_page'] ) ? 1 : 0 );
            if ( isset( $_POST['ctm_title_heading'] ) ) {
                update_post_meta( $post_id, '_ctm_title_heading', sanitize_text_field( $_POST['ctm_title_heading'] ) );
            }
            if ( isset( $_POST['ctm_title_desc'] ) ) {
                update_post_meta( $post_id, '_ctm_title_desc', wp_kses_post( wp_unslash( $_POST['ctm_title_desc'] ) ) );
            }
            if ( isset( $_POST['ctm_start_text'] ) ) {
                $start = sanitize_text_field( $_POST['ctm_start_text'] );
                update_post_meta( $post_id, '_ctm_start_text', $start ?: 'Get Started' );
            }
        }

        // Save analytics override setting (shared between modes)
        $override = isset( $_POST['ctm_analytics_override'] ) ? 1 : 0;
        update_post_meta( $post_id, '_ctm_analytics_override', $override );

        // Save per-form analytics settings
        if ( isset( $_POST['ctm_analytics'] ) && is_array( $_POST['ctm_analytics'] ) ) {
            $analytics = [];
            $allowed_keys = [ 'ga4_event', 'ga4_params', 'gads_conversion', 'fb_event', 'fb_params', 'tiktok_event', 'tiktok_params', 'bing_event', 'bing_params' ];
            foreach ( $allowed_keys as $key ) {
                $analytics[ $key ] = isset( $_POST['ctm_analytics'][ $key ] ) ? sanitize_text_field( $_POST['ctm_analytics'][ $key ] ) : '';
            }
            update_post_meta( $post_id, '_ctm_analytics', $analytics );
        }
    }

    /* ========================= AJAX: Generate starter ========================= */
    public function ajax_generate_starter() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        check_ajax_referer( self::NONCE_ACTION, self::NONCE_NAME );

        $rid = isset( $_POST['reactor_id'] ) ? sanitize_text_field( $_POST['reactor_id'] ) : '';
        if ( ! $rid ) {
            wp_send_json_error( 'Missing reactor_id' );
        }

        $floating_labels = isset( $_POST['floating_labels'] ) && $_POST['floating_labels'] === '1';

        $detail = $this->fetch_reactor_detail( $rid );
        if ( empty( $detail ) ) {
            wp_send_json_error( 'Could not load reactor details' );
        }

        $html = $this->build_starter_html_from_detail( $detail, $floating_labels );
        wp_send_json_success( [ 'html' => $html ] );
    }

    /* ========================= Builder: Config → HTML Renderer ========================= */
    public function render_config_to_html( $config ) {
        $settings = $config['settings'] ?? [];
        $fields   = $config['fields'] ?? [];
        if ( empty( $fields ) ) {
            return '';
        }

        $label_style  = $settings['labelStyle'] ?? 'above';
        $submit_text  = $settings['submitText'] ?? 'Submit';
        $is_multi     = ! empty( $settings['multiStep'] );
        $scoring      = $settings['scoring'] ?? [];
        $scoring_on   = ! empty( $scoring['enabled'] );

        // Core field names that should NOT get ctm-custom class
        $core_names = [ 'caller_name', 'email', 'phone_number', 'phone', 'country_code' ];

        // Group fields by step if multi-step
        $steps = [];
        if ( $is_multi ) {
            foreach ( $fields as $f ) {
                $s = (int) ( $f['step'] ?? 0 );
                $steps[ $s ][] = $f;
            }
            ksort( $steps );
        } else {
            $steps[0] = $fields;
        }

        $html = "<form id=\"ctmForm\" novalidate>\n";

        // Scoring data attribute on form if scoring enabled
        if ( $scoring_on ) {
            $scoring_data = wp_json_encode( [ 'enabled' => true ] );
            $html = "<form id=\"ctmForm\" novalidate data-scoring='" . esc_attr( $scoring_data ) . "'>\n";
        }

        foreach ( $steps as $step_idx => $step_fields ) {
            if ( $is_multi ) {
                $html .= "  <div class=\"ctm-multi-step-item\">\n";
            }

            $html .= $this->render_fields_html( $step_fields, $label_style, $core_names, $scoring_on );

            // Submit button in last step (or single step)
            $step_keys = array_keys( $steps );
            if ( $step_idx === end( $step_keys ) ) {
                $html .= "    <button type=\"submit\">" . esc_html( $submit_text ) . "</button>\n";
            }

            if ( $is_multi ) {
                $html .= "  </div>\n";
            }
        }

        $html .= "</form>";
        return $html;
    }

    /**
     * Render an array of field configs into HTML.
     */
    private function render_fields_html( $fields, $global_label_style, $core_names, $scoring_on ) {
        $html = '';
        $row_open = false;
        $prev_width = 'full';

        // Map palette-only types to their real HTML types
        $type_aliases = [ 'fullname' => 'text', 'message' => 'textarea' ];

        foreach ( $fields as $i => $f ) {
            $type       = $f['type'] ?? 'text';
            $type       = $type_aliases[ $type ] ?? $type;
            $label      = $f['label'] ?? '';
            $name       = $f['name'] ?? '';
            $placeholder = $f['placeholder'] ?? '';
            $help_text  = $f['helpText'] ?? '';
            $default    = $f['defaultValue'] ?? '';
            $required   = ! empty( $f['required'] );

            // Sanitize custom field names to safe identifiers
            if ( ! in_array( $name, $core_names, true ) ) {
                $name = self::sanitize_field_name( $name );
            }

            // If the name isn't a CTM core field, it must be custom regardless of config
            $is_core    = in_array( $name, $core_names, true );
            $is_custom  = $is_core ? false : true;
            $width      = $f['width'] ?? 'full';
            $css_class  = $f['cssClass'] ?? '';
            $field_id   = $f['id'] ?? '';

            // Determine label style for this field
            $ls = ( $f['labelStyle'] ?? 'inherit' ) === 'inherit' ? $global_label_style : $f['labelStyle'];

            // Conditions
            $conditions  = $f['conditions'] ?? [];
            $cond_logic  = $f['conditionLogic'] ?? 'all';
            $has_conds   = ! empty( $conditions ) && ! empty( $conditions[0]['field'] );

            // Condition data attributes
            $cond_attrs = '';
            if ( $has_conds ) {
                $cond_attrs .= ' data-conditions="' . esc_attr( wp_json_encode( $conditions ) ) . '"';
                $cond_attrs .= ' data-condition-logic="' . esc_attr( $cond_logic ) . '"';
                $cond_attrs .= ' style="display:none;"';
            }

            // Field ID attribute
            $fid_attr = $field_id ? ' data-field-id="' . esc_attr( $field_id ) . '"' : '';

            // Column layout: wrap adjacent non-full fields in ctm-row
            $width_class = 'ctm-col-' . $width;

            if ( $width !== 'full' ) {
                if ( ! $row_open ) {
                    $html .= "    <div class=\"ctm-row\">\n";
                    $row_open = true;
                }
            } else {
                if ( $row_open ) {
                    $html .= "    </div>\n";
                    $row_open = false;
                }
            }

            $wrapper_class = $width_class;
            if ( $css_class ) {
                $wrapper_class .= ' ' . $css_class;
            }

            // Layout elements
            if ( $type === 'heading' ) {
                $html .= "    <div class=\"{$wrapper_class}\"{$fid_attr}{$cond_attrs}><h3>" . esc_html( $label ) . "</h3></div>\n";
                continue;
            }
            if ( $type === 'paragraph' ) {
                $html .= "    <div class=\"{$wrapper_class}\"{$fid_attr}{$cond_attrs}><p>" . esc_html( $label ) . "</p></div>\n";
                continue;
            }
            if ( $type === 'divider' ) {
                $html .= "    <div class=\"{$wrapper_class}\"{$fid_attr}{$cond_attrs}><hr /></div>\n";
                continue;
            }
            if ( $type === 'score_display' ) {
                $score_label = esc_html( $label ?: 'Your Score' );
                $score_name  = esc_attr( $name ?: 'custom_total_score' );
                $html .= "    <div class=\"{$wrapper_class}\"{$fid_attr}{$cond_attrs}>\n";
                $html .= "      <div class=\"ctm-score-wrap\">\n";
                $html .= "        <span class=\"ctm-score-label\">{$score_label}:</span>\n";
                $html .= "        <span class=\"ctm-score-display\">0</span>\n";
                $html .= "      </div>\n";
                $html .= "      <input type=\"hidden\" name=\"{$score_name}\" class=\"ctm-custom ctm-score-input\" value=\"0\" />\n";
                $html .= "    </div>\n";
                continue;
            }

            // Hidden field
            if ( $type === 'hidden' ) {
                $cls = $is_custom ? ' class="ctm-custom"' : '';
                $html .= "    <input type=\"hidden\" name=\"" . esc_attr( $name ) . "\" value=\"" . esc_attr( $default ) . "\"{$cls}{$fid_attr} />\n";
                continue;
            }

            $req_attr = $required ? ' required' : '';
            $ph_attr = $placeholder ? ' placeholder="' . esc_attr( $placeholder ) . '"' : '';
            $val_attr = $default !== '' ? ' value="' . esc_attr( $default ) . '"' : '';

            // Use floating labels?
            $use_floating = ( $ls === 'floating' ) && in_array( $type, [ 'text', 'email', 'tel', 'number', 'url', 'textarea' ], true );
            $hide_label = ( $ls === 'hidden' );

            // Options for select/checkbox/radio
            $options = $f['options'] ?? [];

            // Wrapper open
            $html .= "    <div class=\"{$wrapper_class}\"{$fid_attr}{$cond_attrs}>\n";

            if ( $use_floating ) {
                $input_cls = $is_custom ? 'ctm-custom input-field' : 'input-field';
                $html .= "      <div class=\"input\">\n";
                if ( $type === 'textarea' ) {
                    $html .= "        <textarea name=\"" . esc_attr( $name ) . "\" class=\"{$input_cls}\"{$req_attr}{$ph_attr}>" . esc_textarea( $default ) . "</textarea>\n";
                } else {
                    $num_attrs = '';
                    if ( $type === 'number' ) {
                        if ( isset( $f['min'] ) && $f['min'] !== null && $f['min'] !== '' ) $num_attrs .= ' min="' . esc_attr( $f['min'] ) . '"';
                        if ( isset( $f['max'] ) && $f['max'] !== null && $f['max'] !== '' ) $num_attrs .= ' max="' . esc_attr( $f['max'] ) . '"';
                        if ( isset( $f['numStep'] ) && $f['numStep'] !== null && $f['numStep'] !== '' ) $num_attrs .= ' step="' . esc_attr( $f['numStep'] ) . '"';
                    }
                    $html .= "        <input class=\"{$input_cls}\" type=\"" . esc_attr( $type ) . "\" name=\"" . esc_attr( $name ) . "\"{$req_attr}{$ph_attr}{$val_attr}{$num_attrs} />\n";
                }
                $html .= "        <label class=\"input-label\">" . esc_html( $label ) . "</label>\n";
                $html .= "      </div>\n";

            } elseif ( $type === 'select' ) {
                $cls = $is_custom ? ' class="ctm-custom"' : '';
                if ( ! $hide_label ) {
                    $html .= "      <label>" . esc_html( $label ) . "\n";
                }
                $html .= "      <select name=\"" . esc_attr( $name ) . "\"{$cls}{$req_attr}>\n";
                $html .= "        <option value=\"\">&mdash; Select &mdash;</option>\n";
                foreach ( $options as $opt ) {
                    $score_attr = $scoring_on && ! empty( $opt['score'] ) ? ' data-score="' . esc_attr( $opt['score'] ) . '"' : '';
                    $html .= "        <option value=\"" . esc_attr( $opt['value'] ?? '' ) . "\"{$score_attr}>" . esc_html( $opt['label'] ?? '' ) . "</option>\n";
                }
                $html .= "      </select>\n";
                if ( ! $hide_label ) {
                    $html .= "      </label>\n";
                }

            } elseif ( $type === 'checkbox' ) {
                $cls = $is_custom ? ' class="ctm-custom"' : '';
                $html .= "      <fieldset>\n";
                if ( ! $hide_label ) {
                    $html .= "        <legend>" . esc_html( $label ) . "</legend>\n";
                }
                foreach ( $options as $opt ) {
                    $score_attr = $scoring_on && ! empty( $opt['score'] ) ? ' data-score="' . esc_attr( $opt['score'] ) . '"' : '';
                    $html .= "        <label><input type=\"checkbox\" name=\"" . esc_attr( $name ) . "[]\" value=\"" . esc_attr( $opt['value'] ?? '' ) . "\"{$cls}{$score_attr} /> " . esc_html( $opt['label'] ?? '' ) . "</label>\n";
                }
                $html .= "      </fieldset>\n";

            } elseif ( $type === 'radio' ) {
                $cls = $is_custom ? ' class="ctm-custom"' : '';
                $html .= "      <fieldset>\n";
                if ( ! $hide_label ) {
                    $html .= "        <legend>" . esc_html( $label ) . "</legend>\n";
                }
                foreach ( $options as $opt ) {
                    $score_attr = $scoring_on && ! empty( $opt['score'] ) ? ' data-score="' . esc_attr( $opt['score'] ) . '"' : '';
                    $html .= "        <label><input type=\"radio\" name=\"" . esc_attr( $name ) . "\" value=\"" . esc_attr( $opt['value'] ?? '' ) . "\"{$cls}{$req_attr}{$score_attr} /> " . esc_html( $opt['label'] ?? '' ) . "</label>\n";
                }
                $html .= "      </fieldset>\n";

            } elseif ( $type === 'textarea' ) {
                $cls = $is_custom ? ' class="ctm-custom"' : '';
                if ( ! $hide_label ) {
                    $html .= "      <label>" . esc_html( $label ) . "\n";
                }
                $html .= "      <textarea name=\"" . esc_attr( $name ) . "\"{$cls}{$req_attr}{$ph_attr}>" . esc_textarea( $default ) . "</textarea>\n";
                if ( ! $hide_label ) {
                    $html .= "      </label>\n";
                }

            } else {
                // text, email, tel, number, url
                $cls = $is_custom ? ' class="ctm-custom"' : '';
                $num_attrs = '';
                if ( $type === 'number' ) {
                    if ( isset( $f['min'] ) && $f['min'] !== null && $f['min'] !== '' ) $num_attrs .= ' min="' . esc_attr( $f['min'] ) . '"';
                    if ( isset( $f['max'] ) && $f['max'] !== null && $f['max'] !== '' ) $num_attrs .= ' max="' . esc_attr( $f['max'] ) . '"';
                    if ( isset( $f['numStep'] ) && $f['numStep'] !== null && $f['numStep'] !== '' ) $num_attrs .= ' step="' . esc_attr( $f['numStep'] ) . '"';
                }
                if ( ! $hide_label ) {
                    $html .= "      <label>" . esc_html( $label ) . "\n";
                }
                $html .= "      <input type=\"" . esc_attr( $type ) . "\" name=\"" . esc_attr( $name ) . "\"{$cls}{$req_attr}{$ph_attr}{$val_attr}{$num_attrs} />\n";
                if ( ! $hide_label ) {
                    $html .= "      </label>\n";
                }
            }

            // Help text
            if ( $help_text ) {
                $html .= "      <small class=\"ctm-help-text\">" . esc_html( $help_text ) . "</small>\n";
            }

            // Wrapper close
            $html .= "    </div>\n";

            // Close row if next field is full width or end of fields
            $next = $fields[ $i + 1 ] ?? null;
            if ( $row_open && ( ! $next || ( $next['width'] ?? 'full' ) === 'full' ) ) {
                $html .= "    </div>\n";
                $row_open = false;
            }
        }

        // Close any open row
        if ( $row_open ) {
            $html .= "    </div>\n";
        }

        return $html;
    }

    /* ========================= AJAX: Builder Preview ========================= */
    public function ajax_builder_preview() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        check_ajax_referer( self::NONCE_ACTION, self::NONCE_NAME );

        $raw = isset( $_POST['config'] ) ? wp_unslash( $_POST['config'] ) : '';
        $config = json_decode( $raw, true );
        if ( ! is_array( $config ) ) {
            wp_send_json_error( 'Invalid config' );
        }

        $html         = $this->render_config_to_html( $config );
        $settings     = $config['settings'] ?? [];
        $color_scheme = $settings['colorScheme'] ?? 'light';
        $colors       = $settings['colors'] ?? [];
        wp_send_json_success( [
            'html'        => $html,
            'colorScheme' => $color_scheme,
            'colors'      => (object) $colors,
        ] );
    }

    /* ========================= Shortcode ========================= */
    public function render_form_shortcode( $atts ) {
        $atts = shortcode_atts( [ 'id' => 0 ], $atts );
        $post_id = intval( $atts['id'] );
        if ( ! $post_id ) {
            return '';
        }

        $reactor_id = get_post_meta( $post_id, '_ctm_reactor_id', true );
        $html = get_post_meta( $post_id, '_ctm_form_html', true );
        if ( ! $reactor_id || ! $html ) {
            return '';
        }

        $is_multi_step = (bool) get_post_meta( $post_id, '_ctm_multi_step', true );
        $form_mode     = get_post_meta( $post_id, '_ctm_form_mode', true ) ?: 'reactor';
        $ajax_url      = admin_url( 'admin-ajax.php' );
        $nonce         = wp_create_nonce( self::NONCE_ACTION );

        // Get analytics settings for this form
        $analytics = $this->get_form_analytics( $post_id );

        // Enqueue form-logic assets if builder mode (conditionals + scoring + column layout)
        if ( $form_mode === 'builder' ) {
            wp_enqueue_style( 'ctm-form-logic' );
            wp_enqueue_script( 'ctm-form-logic' );
        }

        // Success message (builder mode stores custom message, reactor mode uses default)
        $success_message = get_post_meta( $post_id, '_ctm_success_message', true );
        if ( ! $success_message ) {
            $success_message = "Thanks! We'll be in touch shortly.";
        }

        // If multi-step: enqueue assets, add class to the <form> tag
        if ( $is_multi_step ) {
            wp_enqueue_style( 'ctm-multi-step' );
            wp_enqueue_script( 'ctm-multi-step' );

            // Add 'ctm-multi-step' class to the form tag
            if ( preg_match( '/<form\b[^>]*class=["\']/', $html ) ) {
                $html = preg_replace( '/(<form\b[^>]*class=["\'])/', '$1ctm-multi-step ', $html );
            } else {
                $html = preg_replace( '/(<form\b)/', '$1 class="ctm-multi-step"', $html );
            }
        }

        // If title page enabled: prepend title div inside the form (after opening <form> tag)
        if ( $is_multi_step && get_post_meta( $post_id, '_ctm_title_page', true ) ) {
            $heading    = get_post_meta( $post_id, '_ctm_title_heading', true );
            $desc       = get_post_meta( $post_id, '_ctm_title_desc', true );
            $start_text = get_post_meta( $post_id, '_ctm_start_text', true ) ?: 'Get Started';

            $title_html = '<div class="ctm-multi-step-title">'
                . '<h2>' . esc_html( $heading ) . '</h2>'
                . '<div class="ctm-ms-title-desc">' . wp_kses_post( $desc ) . '</div>'
                . '<button type="button" class="ctm-ms-start">' . esc_html( $start_text ) . '</button>'
                . '</div>';
            $html = preg_replace( '/(<form[^>]*>)/', '$1' . $title_html, $html, 1 );
        }

        // Color settings from builder config
        $raw_config   = get_post_meta( $post_id, '_ctm_form_config', true );
        $form_config  = $raw_config ? json_decode( $raw_config, true ) : [];
        $color_scheme = $form_config['settings']['colorScheme'] ?? 'light';
        $colors       = $form_config['settings']['colors'] ?? [];

        $wrap_class = 'ctm-form-wrap';
        if ( $color_scheme === 'dark' ) {
            $wrap_class .= ' ctm-scheme-dark';
        }

        // Map config keys to CSS custom properties
        $color_var_map = [
            'bg'          => '--ctm-bg',
            'text'        => '--ctm-text',
            'label'       => '--ctm-label',
            'inputBg'     => '--ctm-input-bg',
            'inputBorder' => '--ctm-input-border',
            'inputText'   => '--ctm-input-text',
            'focus'       => '--ctm-focus',
            'btnBg'       => '--ctm-btn-bg',
            'btnText'     => '--ctm-btn-text',
        ];

        $style_parts = [];
        foreach ( $color_var_map as $key => $var ) {
            if ( ! empty( $colors[ $key ] ) ) {
                $style_parts[] = $var . ':' . esc_attr( $colors[ $key ] );
            }
        }

        $wrap_style = '';
        if ( $style_parts ) {
            $wrap_style = ' style="' . implode( ';', $style_parts ) . ';"';
        }

        ob_start();
        ?>
        <div class="<?php echo esc_attr( $wrap_class ); ?>"<?php echo $wrap_style; ?> data-variant="<?php echo esc_attr( $post_id ); ?>">
            <?php echo str_replace( 'id="ctmForm"', 'id="ctmForm-' . esc_attr( $post_id ) . '"', $html ); ?>
        </div>
        <script>
        (function(){
            var wrap = document.currentScript.previousElementSibling;
            if (!wrap) return;
            var form = wrap.querySelector('form');
            if (!form) return;

            var CFG = {
                ajax: <?php echo wp_json_encode( $ajax_url ); ?>,
                nonce: <?php echo wp_json_encode( $nonce ); ?>,
                variantId: <?php echo wp_json_encode( $post_id ); ?>,
                reactorId: <?php echo wp_json_encode( $reactor_id ); ?>,
                analytics: <?php echo wp_json_encode( $analytics ); ?>,
                successMessage: <?php echo wp_json_encode( $success_message ); ?>
            };

            // Fire analytics tracking events on successful submission
            function fireAnalyticsEvents() {
                var a = CFG.analytics;

                // Google Analytics 4 (gtag.js)
                if (a.ga4_event && typeof gtag === 'function') {
                    try {
                        var ga4Params = a.ga4_params ? JSON.parse(a.ga4_params) : {};
                        gtag('event', a.ga4_event, ga4Params);
                    } catch(e) {
                        gtag('event', a.ga4_event);
                    }
                }

                // Google Ads Conversion
                if (a.gads_conversion && typeof gtag === 'function') {
                    gtag('event', 'conversion', { 'send_to': a.gads_conversion });
                }

                // Facebook Pixel
                if (a.fb_event && typeof fbq === 'function') {
                    try {
                        var fbParams = a.fb_params ? JSON.parse(a.fb_params) : {};
                        fbq('track', a.fb_event, fbParams);
                    } catch(e) {
                        fbq('track', a.fb_event);
                    }
                }

                // TikTok Pixel
                if (a.tiktok_event && typeof ttq !== 'undefined' && ttq.track) {
                    try {
                        var ttParams = a.tiktok_params ? JSON.parse(a.tiktok_params) : {};
                        ttq.track(a.tiktok_event, ttParams);
                    } catch(e) {
                        ttq.track(a.tiktok_event);
                    }
                }

                // Bing UET
                if (a.bing_event && typeof window.uetq !== 'undefined') {
                    try {
                        var bingParams = a.bing_params ? JSON.parse(a.bing_params) : {};
                        window.uetq.push('event', a.bing_event, bingParams);
                    } catch(e) {
                        window.uetq.push('event', a.bing_event, {});
                    }
                }
            }

            // --- Attribution: collect UTMs + click IDs at page load, SID at submit ---
            function getParam(name) {
                try { return (new URLSearchParams(window.location.search)).get(name) || ''; }
                catch(e) { return ''; }
            }
            var attribution = {};
            var ref = document.referrer; if (ref) attribution.referring_url = ref;
            attribution.page_url = window.location.href;
            var paramKeys = ['utm_source','utm_medium','utm_campaign','utm_term','utm_content','gclid','fbclid','msclkid'];
            for (var p = 0; p < paramKeys.length; p++) {
                var v = getParam(paramKeys[p]);
                if (v) attribution[paramKeys[p]] = v;
            }

            if (!form.getAttribute('method')) form.setAttribute('method', 'POST');
            if (!form.getAttribute('action')) form.setAttribute('action', CFG.ajax);

            form.addEventListener('submit', function(e) {
                e.preventDefault();

                // Capture CTM visitor SID at submit time (async script may not be ready at page load)
                try { if (window.__ctm && __ctm.config && __ctm.config.sid) attribution.visitor_sid = __ctm.config.sid; } catch(ex) {}

                var core = {};
                var custom = {};
                var els = form.querySelectorAll('[name]');

                for (var i = 0; i < els.length; i++) {
                    var el = els[i];
                    var name = el.getAttribute('name');
                    if (!name) continue;

                    var val;
                    var isCustom = el.classList.contains('ctm-custom');
                    var target = isCustom ? custom : core;

                    // Handle checkbox arrays (name ends with [])
                    if (el.type === 'checkbox') {
                        if (!el.checked) continue;
                        val = el.value;

                        // Check if it's an array field (name ends with [])
                        if (name.endsWith('[]')) {
                            var baseName = name.slice(0, -2);
                            if (!target[baseName]) {
                                target[baseName] = [];
                            }
                            target[baseName].push(val);
                            continue;
                        }
                    } else if (el.type === 'radio') {
                        if (!el.checked) continue;
                        val = el.value;
                    } else {
                        val = el.value;
                    }

                    if (val === '') continue;

                    if (isCustom) {
                        custom[name] = val;
                    } else {
                        core[name] = val;
                    }
                }

                var fd = new FormData();
                fd.append('action', 'anchor_ctm_submit');
                fd.append('<?php echo esc_js( self::NONCE_NAME ); ?>', CFG.nonce);
                fd.append('variant_id', CFG.variantId);
                fd.append('core_json', JSON.stringify(core));
                fd.append('custom_json', JSON.stringify(custom));
                fd.append('attribution_json', JSON.stringify(attribution));

                fetch(CFG.ajax, { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        var msg = form.querySelector('.ctm-form-msg') || (function() {
                            var m = document.createElement('div');
                            m.className = 'ctm-form-msg';
                            form.appendChild(m);
                            return m;
                        })();

                        if (data && data.success) {
                            msg.textContent = CFG.successMessage;
                            msg.style.color = '#00a32a';
                            try { form.reset(); } catch(e) {}

                            // Fire analytics events on successful submission
                            fireAnalyticsEvents();
                        } else {
                            msg.textContent = (data && data.data && (data.data.message || data.data)) || 'Something went wrong.';
                            msg.style.color = '#d63638';
                        }
                    })
                    .catch(function() {
                        var msg = form.querySelector('.ctm-form-msg');
                        if (msg) msg.textContent = 'Network error. Please try again.';
                    });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /* ========================= AJAX Submit (server → CTM) ========================= */
    public function ajax_submit() {
        check_ajax_referer( self::NONCE_ACTION, self::NONCE_NAME );

        $variant_id = isset( $_POST['variant_id'] ) ? intval( $_POST['variant_id'] ) : 0;
        if ( ! $variant_id ) {
            wp_send_json_error( [ 'type' => 'validation', 'message' => 'Missing variant.' ] );
        }

        // Parse JSON blobs from JS
        $core = [];
        $custom = [];

        if ( isset( $_POST['core_json'] ) ) {
            $tmp = json_decode( wp_unslash( $_POST['core_json'] ), true );
            if ( is_array( $tmp ) ) {
                $core = map_deep( $tmp, 'sanitize_text_field' );
            }
        }
        if ( isset( $_POST['custom_json'] ) ) {
            $tmp = json_decode( wp_unslash( $_POST['custom_json'] ), true );
            if ( is_array( $tmp ) ) {
                $custom = map_deep( $tmp, 'sanitize_text_field' );
            }
        }

        // Normalize keys
        if ( ! empty( $core['phone'] ) && empty( $core['phone_number'] ) ) {
            $core['phone_number'] = $core['phone'];
            unset( $core['phone'] );
        }
        if ( empty( $core['phone_number'] ) ) {
            wp_send_json_error( [ 'type' => 'validation', 'message' => 'Phone number is required.' ] );
        }

        $core['phone_number'] = preg_replace( '/\D+/', '', $core['phone_number'] );

        if ( ! isset( $core['country_code'] ) ) {
            $core['country_code'] = '1'; // default US
        }

        // Map "name" to "caller_name" if needed
        if ( empty( $core['caller_name'] ) && ! empty( $core['name'] ) ) {
            $core['caller_name'] = $core['name'];
            unset( $core['name'] );
        }

        // Parse attribution data from JS and enrich with server-side fields
        $attribution = [];
        if ( isset( $_POST['attribution_json'] ) ) {
            $tmp = json_decode( wp_unslash( $_POST['attribution_json'] ), true );
            if ( is_array( $tmp ) ) {
                $allowed_attr = [ 'visitor_sid', 'referring_url', 'page_url', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid', 'msclkid' ];
                foreach ( $allowed_attr as $key ) {
                    if ( ! empty( $tmp[ $key ] ) ) {
                        $attribution[ $key ] = sanitize_text_field( $tmp[ $key ] );
                    }
                }
            }
        }
        $attribution['visitor_ip'] = $this->get_visitor_ip();
        if ( ! empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
            $attribution['user_agent'] = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
        }

        // Single reactor — CTM's visitor_sid handles source attribution
        $reactor_id = get_post_meta( $variant_id, '_ctm_reactor_id', true );

        if ( ! $reactor_id ) {
            wp_send_json_error( [ 'type' => 'validation', 'message' => 'Variant not configured.' ] );
        }

        $res = $this->send_submission_to_ctm( $reactor_id, $core, $custom, $attribution );

        if ( is_wp_error( $res ) ) {
            error_log( '[Anchor CTM Forms] Submission failed for variant ' . $variant_id . ': ' . $res->get_error_message() );
            wp_send_json_error( [ 'type' => 'server', 'message' => $res->get_error_message() ] );
        }

        wp_send_json_success( [ 'message' => 'Success' ] );
    }

    /**
     * Resolve the visitor's real IP address from proxy headers.
     */
    private function get_visitor_ip() {
        $headers = [ 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ];
        foreach ( $headers as $header ) {
            if ( ! empty( $_SERVER[ $header ] ) ) {
                // X-Forwarded-For may contain comma-separated list; take the first
                $ip = trim( explode( ',', $_SERVER[ $header ] )[0] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }
        return '';
    }

    /**
     * Send form submission to CTM FormReactor API.
     *
     * IMPORTANT: The submission endpoint is different from the list/detail endpoints!
     * - List/Detail: /api/v1/accounts/{account_id}/form_reactors/{id}
     * - Submit:      /api/v1/formreactor/{id}  (no account_id, singular "formreactor")
     */
    private function send_submission_to_ctm( $reactor_id, $core, $custom = [], $attribution = [] ) {
        $headers = $this->auth_headers();
        if ( ! $headers ) {
            return new WP_Error( 'ctm_missing_creds', 'API credentials not configured.' );
        }

        // Build the submission body
        $body = $core;

        // Custom fields must be prefixed with "custom_" and sent at top level
        // NOT nested under a "custom" key
        if ( ! empty( $custom ) ) {
            foreach ( $custom as $key => $value ) {
                // Ensure key has custom_ prefix
                $prefixed_key = strpos( $key, 'custom_' ) === 0 ? $key : 'custom_' . $key;
                // CTM expects flat string values — flatten checkbox arrays.
                $body[ $prefixed_key ] = is_array( $value ) ? implode( ', ', $value ) : $value;
            }
        }

        // Attribution fields — sent as top-level keys (NOT custom_ prefixed).
        // CTM recognizes these natively for session/source tracking.
        $top_level_attr = [ 'visitor_sid', 'referring_url', 'page_url', 'visitor_ip', 'user_agent' ];
        foreach ( $top_level_attr as $key ) {
            if ( ! empty( $attribution[ $key ] ) ) {
                $body[ $key ] = $attribution[ $key ];
            }
        }

        // CORRECT ENDPOINT: /api/v1/formreactor/{reactor_id}
        // Note: This is different from the listing endpoint which uses /form_reactors/
        $url = "https://api.calltrackingmetrics.com/api/v1/formreactor/" . rawurlencode( $reactor_id );

        $args = [
            'headers' => array_merge( $headers, [ 'Content-Type' => 'application/json' ] ),
            'timeout' => 20,
            'body'    => wp_json_encode( $body ),
            'method'  => 'POST',
        ];

        $response = wp_remote_post( $url, $args );

        if ( is_wp_error( $response ) ) {
            error_log( '[Anchor CTM Forms] wp_remote_post failed: ' . $response->get_error_message() );
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );

        if ( $code >= 200 && $code < 300 ) {
            // CTM may return HTTP 200 with a JSON error body — check for that.
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) && isset( $decoded['status'] ) && $decoded['status'] === 'error' ) {
                $err_msg = $decoded['text'] ?? $decoded['message'] ?? 'Unknown CTM error';
                error_log( '[Anchor CTM Forms] CTM rejected submission (HTTP ' . $code . '): ' . $err_msg );
                return new WP_Error( 'ctm_rejected', $err_msg );
            }
            return true;
        }

        error_log( '[Anchor CTM Forms] CTM API error (HTTP ' . $code . '): ' . substr( $raw, 0, 600 ) );
        return new WP_Error( 'ctm_api_error', 'CTM API error (' . $code . '): ' . substr( $raw, 0, 600 ) );
    }
}
