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

    public function __construct() {
        add_action( 'init', [ $this, 'register_cpt' ] );
        add_action( 'admin_menu', [ $this, 'admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'add_meta_boxes', [ $this, 'add_variant_metabox' ] );
        add_action( 'save_post_ctm_form_variant', [ $this, 'save_variant' ] );

        add_shortcode( 'ctm_form_variant', [ $this, 'render_form_shortcode' ] );

        add_filter( 'manage_ctm_form_variant_posts_columns', [ $this, 'add_shortcode_column' ] );
        add_action( 'manage_ctm_form_variant_posts_custom_column', [ $this, 'render_shortcode_column' ], 10, 2 );

        add_action( 'wp_ajax_anchor_ctm_generate', [ $this, 'ajax_generate_starter' ] );
        add_action( 'wp_ajax_anchor_ctm_submit', [ $this, 'ajax_submit' ] );
        add_action( 'wp_ajax_nopriv_anchor_ctm_submit', [ $this, 'ajax_submit' ] );
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

    /* ========================= Metabox (builder) ========================= */
    public function add_variant_metabox() {
        add_meta_box( 'anchor_ctm_variant', __( 'CTM Form Builder', 'anchor-schema' ), [ $this, 'variant_metabox_cb' ], 'ctm_form_variant', 'normal', 'high' );
    }

    public function variant_metabox_cb( $post ) {
        $reactor_id = get_post_meta( $post->ID, '_ctm_reactor_id', true );
        $html = get_post_meta( $post->ID, '_ctm_form_html', true );
        $reactors = $this->fetch_reactors_list();

        // Get per-form analytics overrides
        $analytics_override = get_post_meta( $post->ID, '_ctm_analytics_override', true ) ? true : false;
        $analytics = $this->get_form_analytics( $post->ID );

        wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
        ?>
        <div class="ctm-box" style="border:1px solid #ddd;padding:12px;border-radius:8px;background:#fff;margin-bottom:12px">
            <p><label><strong><?php esc_html_e( 'Choose Form Reactor', 'anchor-schema' ); ?></strong><br>
                <select name="ctm_reactor_id" id="ctm_reactor_id" style="width:100%">
                    <option value=""><?php esc_html_e( '— Select —', 'anchor-schema' ); ?></option>
                    <?php foreach ( $reactors as $r ): ?>
                    <option value="<?php echo esc_attr( $r['id'] ); ?>" <?php selected( $reactor_id, $r['id'] ); ?>>
                        <?php echo esc_html( $r['name'] . ' — ' . $r['id'] ); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </label></p>

            <p style="display:flex;align-items:center;gap:12px;">
                <button type="button" class="button" id="ctm-generate"><?php esc_html_e( 'Generate Starter Form', 'anchor-schema' ); ?></button>
                <label style="display:inline-flex;align-items:center;gap:4px;">
                    <input type="checkbox" id="ctm_floating_labels" value="1" />
                    <?php esc_html_e( 'Floating labels', 'anchor-schema' ); ?>
                </label>
            </p>

            <p><label><strong><?php esc_html_e( 'Form HTML', 'anchor-schema' ); ?></strong></label></p>
            <p><textarea name="ctm_form_html" id="ctm_form_html" style="width:100%;height:420px" placeholder="<?php esc_attr_e( 'Starter HTML will appear here after you choose a reactor and click Generate.', 'anchor-schema' ); ?>"><?php echo esc_textarea( $html ); ?></textarea></p>

            <p><strong><?php esc_html_e( 'Shortcode', 'anchor-schema' ); ?></strong></p>
            <div style="display:flex;gap:8px;align-items:center">
                <input type="text" readonly value="[ctm_form_variant id=&quot;<?php echo intval( $post->ID ); ?>&quot;]" id="ctm-shortcode-field" style="width:100%" />
                <button type="button" class="button" id="ctm-copy-sc"><?php esc_html_e( 'Copy', 'anchor-schema' ); ?></button>
            </div>
            <p class="description"><?php esc_html_e( 'Embed this saved form variant using the shortcode.', 'anchor-schema' ); ?></p>
        </div>

        <!-- Analytics Override Section -->
        <div class="ctm-box" style="border:1px solid #ddd;padding:12px;border-radius:8px;background:#fff;margin-bottom:12px">
            <p>
                <label>
                    <input type="checkbox" name="ctm_analytics_override" id="ctm_analytics_override" value="1" <?php checked( $analytics_override ); ?> />
                    <strong><?php esc_html_e( 'Override Global Analytics Settings', 'anchor-schema' ); ?></strong>
                </label>
            </p>
            <p class="description"><?php esc_html_e( 'Check to use custom tracking settings for this form instead of the global defaults.', 'anchor-schema' ); ?></p>

            <div id="ctm-analytics-fields" style="<?php echo $analytics_override ? '' : 'display:none;'; ?>margin-top:12px;padding-top:12px;border-top:1px solid #eee;">
                <table class="form-table" style="margin:0;">
                    <tr>
                        <th scope="row" style="padding:8px 10px 8px 0;width:180px;"><?php esc_html_e( 'GA4 Event Name', 'anchor-schema' ); ?></th>
                        <td style="padding:8px 0;">
                            <input type="text" name="ctm_analytics[ga4_event]" value="<?php echo esc_attr( $analytics['ga4_event'] ); ?>" class="regular-text" />
                            <p class="description"><?php esc_html_e( 'Leave blank to disable GA4 tracking for this form.', 'anchor-schema' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row" style="padding:8px 10px 8px 0;"><?php esc_html_e( 'GA4 Parameters', 'anchor-schema' ); ?></th>
                        <td style="padding:8px 0;">
                            <input type="text" name="ctm_analytics[ga4_params]" value="<?php echo esc_attr( $analytics['ga4_params'] ); ?>" class="large-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row" style="padding:8px 10px 8px 0;"><?php esc_html_e( 'Google Ads Conversion', 'anchor-schema' ); ?></th>
                        <td style="padding:8px 0;">
                            <input type="text" name="ctm_analytics[gads_conversion]" value="<?php echo esc_attr( $analytics['gads_conversion'] ); ?>" class="regular-text" />
                            <p class="description"><?php esc_html_e( 'e.g., AW-123456789/AbCdEf', 'anchor-schema' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row" style="padding:8px 10px 8px 0;"><?php esc_html_e( 'Facebook Pixel Event', 'anchor-schema' ); ?></th>
                        <td style="padding:8px 0;">
                            <input type="text" name="ctm_analytics[fb_event]" value="<?php echo esc_attr( $analytics['fb_event'] ); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row" style="padding:8px 10px 8px 0;"><?php esc_html_e( 'Facebook Parameters', 'anchor-schema' ); ?></th>
                        <td style="padding:8px 0;">
                            <input type="text" name="ctm_analytics[fb_params]" value="<?php echo esc_attr( $analytics['fb_params'] ); ?>" class="large-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row" style="padding:8px 10px 8px 0;"><?php esc_html_e( 'TikTok Pixel Event', 'anchor-schema' ); ?></th>
                        <td style="padding:8px 0;">
                            <input type="text" name="ctm_analytics[tiktok_event]" value="<?php echo esc_attr( $analytics['tiktok_event'] ); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row" style="padding:8px 10px 8px 0;"><?php esc_html_e( 'TikTok Parameters', 'anchor-schema' ); ?></th>
                        <td style="padding:8px 0;">
                            <input type="text" name="ctm_analytics[tiktok_params]" value="<?php echo esc_attr( $analytics['tiktok_params'] ); ?>" class="large-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row" style="padding:8px 10px 8px 0;"><?php esc_html_e( 'Bing UET Event', 'anchor-schema' ); ?></th>
                        <td style="padding:8px 0;">
                            <input type="text" name="ctm_analytics[bing_event]" value="<?php echo esc_attr( $analytics['bing_event'] ); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row" style="padding:8px 10px 8px 0;"><?php esc_html_e( 'Bing Parameters', 'anchor-schema' ); ?></th>
                        <td style="padding:8px 0;">
                            <input type="text" name="ctm_analytics[bing_params]" value="<?php echo esc_attr( $analytics['bing_params'] ); ?>" class="large-text" />
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <script>
        (function(){
            const genBtn = document.getElementById('ctm-generate');
            const sel = document.getElementById('ctm_reactor_id');
            const ta = document.getElementById('ctm_form_html');
            const copy = document.getElementById('ctm-copy-sc');
            const scfld = document.getElementById('ctm-shortcode-field');

            genBtn?.addEventListener('click', async () => {
                const id = sel.value;
                if (!id) { alert('Please choose a reactor first.'); return; }
                const floatingLabels = document.getElementById('ctm_floating_labels')?.checked ? '1' : '0';
                const fd = new FormData();
                fd.append('action', 'anchor_ctm_generate');
                fd.append('<?php echo esc_js( self::NONCE_NAME ); ?>', '<?php echo esc_js( wp_create_nonce( self::NONCE_ACTION ) ); ?>');
                fd.append('reactor_id', id);
                fd.append('floating_labels', floatingLabels);
                const res = await fetch(ajaxurl, { method: 'POST', body: fd });
                const data = await res.json();
                if (data && data.success) {
                    ta.value = data.data.html || '';
                } else {
                    alert((data && data.data) || 'Failed to generate form.');
                }
            });

            copy?.addEventListener('click', () => {
                scfld.select();
                scfld.setSelectionRange(0, 99999);
                document.execCommand('copy');
                copy.textContent = 'Copied';
                setTimeout(() => copy.textContent = 'Copy', 1200);
            });

            // Toggle analytics override fields
            const overrideChk = document.getElementById('ctm_analytics_override');
            const analyticsFields = document.getElementById('ctm-analytics-fields');
            overrideChk?.addEventListener('change', () => {
                analyticsFields.style.display = overrideChk.checked ? '' : 'none';
            });
        })();
        </script>
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

        if ( isset( $_POST['ctm_form_html'] ) ) {
            update_post_meta( $post_id, '_ctm_form_html', wp_unslash( $_POST['ctm_form_html'] ) );
        }
        if ( isset( $_POST['ctm_reactor_id'] ) ) {
            update_post_meta( $post_id, '_ctm_reactor_id', sanitize_text_field( $_POST['ctm_reactor_id'] ) );
        }

        // Save analytics override setting
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

        $ajax_url = admin_url( 'admin-ajax.php' );
        $nonce = wp_create_nonce( self::NONCE_ACTION );

        // Get analytics settings for this form
        $analytics = $this->get_form_analytics( $post_id );

        ob_start();
        ?>
        <div class="ctm-form-wrap" data-variant="<?php echo esc_attr( $post_id ); ?>">
            <?php echo $html; ?>
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
                analytics: <?php echo wp_json_encode( $analytics ); ?>
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

            if (!form.getAttribute('method')) form.setAttribute('method', 'POST');
            if (!form.getAttribute('action')) form.setAttribute('action', CFG.ajax);

            form.addEventListener('submit', function(e) {
                e.preventDefault();

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
                            msg.textContent = 'Thanks! We\'ll be in touch shortly.';
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

        $reactor_id = get_post_meta( $variant_id, '_ctm_reactor_id', true );
        if ( ! $reactor_id ) {
            wp_send_json_error( [ 'type' => 'validation', 'message' => 'Variant not configured.' ] );
        }

        // Parse JSON blobs from JS
        $core = [];
        $custom = [];

        if ( isset( $_POST['core_json'] ) ) {
            $tmp = json_decode( wp_unslash( $_POST['core_json'] ), true );
            if ( is_array( $tmp ) ) {
                $core = array_map( 'sanitize_text_field', $tmp );
            }
        }
        if ( isset( $_POST['custom_json'] ) ) {
            $tmp = json_decode( wp_unslash( $_POST['custom_json'] ), true );
            if ( is_array( $tmp ) ) {
                $custom = array_map( 'sanitize_text_field', $tmp );
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

        $res = $this->send_submission_to_ctm( $reactor_id, $core, $custom );

        if ( is_wp_error( $res ) ) {
            wp_send_json_error( [ 'type' => 'server', 'message' => $res->get_error_message() ] );
        }

        wp_send_json_success( [ 'message' => 'Success' ] );
    }

    /**
     * Send form submission to CTM FormReactor API.
     *
     * IMPORTANT: The submission endpoint is different from the list/detail endpoints!
     * - List/Detail: /api/v1/accounts/{account_id}/form_reactors/{id}
     * - Submit:      /api/v1/formreactor/{id}  (no account_id, singular "formreactor")
     */
    private function send_submission_to_ctm( $reactor_id, $core, $custom = [] ) {
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
                $body[ $prefixed_key ] = $value;
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
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );

        if ( $code >= 200 && $code < 300 ) {
            return true;
        }

        $raw = wp_remote_retrieve_body( $response );
        return new WP_Error( 'ctm_api_error', 'CTM API error (' . $code . '): ' . substr( $raw, 0, 600 ) );
    }
}
