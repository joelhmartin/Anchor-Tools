<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Site_Config_Admin {

    /** @var Anchor_Site_Config_Module */
    private $module;

    public function __construct( Anchor_Site_Config_Module $module ) {
        $this->module = $module;
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_filter( 'anchor_settings_tabs', [ $this, 'register_tab' ], 20 );
        add_action( 'anchor_settings_enqueue_site_config', [ $this, 'enqueue_assets' ] );
    }

    public function register_tab( $tabs ) {
        $tabs['site_config'] = [
            'label'    => __( 'Site Config', 'anchor-schema' ),
            'callback' => [ $this, 'render_tab_content' ],
        ];
        return $tabs;
    }

    public function enqueue_assets( $hook ) {
        wp_enqueue_media();
        wp_enqueue_style( 'wp-color-picker' );

        wp_enqueue_style(
            'anchor-site-config-admin',
            ANCHOR_TOOLS_PLUGIN_URL . 'anchor-site-config/assets/css/admin.css',
            [],
            '1.0.0'
        );

        wp_enqueue_script(
            'anchor-site-config-admin',
            ANCHOR_TOOLS_PLUGIN_URL . 'anchor-site-config/assets/js/admin.js',
            [ 'jquery', 'wp-color-picker' ],
            '1.0.0',
            true
        );
    }

    public function render_tab_content() {
        $opts = $this->module->get_options();
        ?>
        <form method="post" action="options.php" class="anchor-site-config-form">
            <?php settings_fields( Anchor_Site_Config_Module::OPTION_GROUP ); ?>

            <details class="anchor-site-config-section" open>
                <summary><?php esc_html_e( 'Brand Colors', 'anchor-schema' ); ?></summary>
                <?php $this->render_colors_section( $opts ); ?>
            </details>

            <details class="anchor-site-config-section">
                <summary><?php esc_html_e( 'Fonts', 'anchor-schema' ); ?></summary>
                <?php $this->render_fonts_section( $opts ); ?>
            </details>

            <details class="anchor-site-config-section">
                <summary><?php esc_html_e( 'Brand Assets', 'anchor-schema' ); ?></summary>
                <?php $this->render_brand_section( $opts ); ?>
            </details>

            <details class="anchor-site-config-section">
                <summary><?php esc_html_e( 'Business Identity', 'anchor-schema' ); ?></summary>
                <?php $this->render_business_section( $opts ); ?>
            </details>

            <details class="anchor-site-config-section">
                <summary><?php esc_html_e( 'Location', 'anchor-schema' ); ?></summary>
                <?php $this->render_location_section( $opts ); ?>
            </details>

            <details class="anchor-site-config-section">
                <summary><?php esc_html_e( 'Business Hours', 'anchor-schema' ); ?></summary>
                <?php $this->render_hours_section( $opts ); ?>
            </details>

            <details class="anchor-site-config-section">
                <summary><?php esc_html_e( 'Social', 'anchor-schema' ); ?></summary>
                <?php $this->render_social_section( $opts ); ?>
            </details>

            <details class="anchor-site-config-section">
                <summary><?php esc_html_e( 'Custom Shortcodes', 'anchor-schema' ); ?></summary>
                <?php $this->render_custom_shortcodes_section( $opts ); ?>
            </details>

            <?php submit_button(); ?>
        </form>
        <?php
    }

    private function render_fonts_section( $opts ) {
        $opt_key = Anchor_Site_Config_Module::OPTION_KEY;
        $roles   = [
            'heading' => __( 'Heading', 'anchor-schema' ),
            'body'    => __( 'Body',    'anchor-schema' ),
            'accent'  => __( 'Accent',  'anchor-schema' ),
        ];
        ?>
        <table class="form-table"><tbody>
        <?php foreach ( $roles as $role => $label ) :
            $family = $opts['fonts'][ $role ]['family'] ?? '';
            $source = $opts['fonts'][ $role ]['source'] ?? 'system';
        ?>
            <tr>
                <th scope="row"><label><?php echo esc_html( $label ); ?></label></th>
                <td>
                    <input type="text" class="regular-text"
                           name="<?php echo esc_attr( $opt_key . '[fonts][' . $role . '][family]' ); ?>"
                           value="<?php echo esc_attr( $family ); ?>"
                           placeholder="e.g. Inter, Georgia, …" />
                    <select name="<?php echo esc_attr( $opt_key . '[fonts][' . $role . '][source]' ); ?>">
                        <option value="system"         <?php selected( $source, 'system' ); ?>><?php esc_html_e( 'System / web-safe',    'anchor-schema' ); ?></option>
                        <option value="google"         <?php selected( $source, 'google' ); ?>><?php esc_html_e( 'Google Fonts',         'anchor-schema' ); ?></option>
                        <option value="adobe-deferred" disabled><?php esc_html_e( 'Adobe Fonts (Phase 3.5)', 'anchor-schema' ); ?></option>
                    </select>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
        <?php
    }

    private function render_colors_section( $opts ) {
        $defaults = Anchor_Site_Config_Module::get_defaults();
        $opt_key  = Anchor_Site_Config_Module::OPTION_KEY;
        ?>
        <table class="form-table"><tbody>
        <?php foreach ( $defaults['colors'] as $color_key => $default ) :
            $value = $opts['colors'][ $color_key ] ?? $default;
            $label = ucfirst( $color_key );
        ?>
            <tr>
                <th scope="row"><label><?php echo esc_html( $label ); ?></label></th>
                <td>
                    <input type="text"
                           class="anchor-color-field"
                           name="<?php echo esc_attr( $opt_key . '[colors][' . $color_key . ']' ); ?>"
                           value="<?php echo esc_attr( $value ); ?>"
                           data-default-color="<?php echo esc_attr( $default ); ?>" />
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
        <?php
    }

    public function register_settings() {
        register_setting(
            Anchor_Site_Config_Module::OPTION_GROUP,
            Anchor_Site_Config_Module::OPTION_KEY,
            [
                'type'              => 'array',
                'sanitize_callback' => [ $this, 'sanitize_options' ],
                'default'           => Anchor_Site_Config_Module::get_defaults(),
            ]
        );

        // Force autoload=false. register_setting() doesn't accept an autoload
        // arg before WP 6.4+, and Anchor Tools convention requires false.
        // pre_update_option lets us flip the flag at save time.
        add_filter( 'pre_update_option_' . Anchor_Site_Config_Module::OPTION_KEY,
            [ $this, 'force_autoload_false' ], 10, 2 );
    }

    /**
     * On first save of this option, ensure autoload is set to 'no'.
     */
    public function force_autoload_false( $new_value, $old_value ) {
        $existing = get_option( Anchor_Site_Config_Module::OPTION_KEY, null );
        if ( null === $existing ) {
            // First-ever save: add_option with autoload=no, then return
            // $old_value to prevent the default update_option path from
            // re-creating it with autoload=yes.
            add_option( Anchor_Site_Config_Module::OPTION_KEY, $new_value, '', 'no' );
            return $old_value; // signals "no change needed" to update_option
        }
        return $new_value;
    }

    /**
     * Sanitize the entire option array. Merges into the current stored
     * value so partial submissions don't wipe other groups.
     */
    public function sanitize_options( $input ) {
        if ( ! is_array( $input ) ) {
            return $this->module->get_options();
        }

        $out      = $this->module->get_options();
        $defaults = Anchor_Site_Config_Module::get_defaults();

        // ─── colors ───
        if ( isset( $input['colors'] ) && is_array( $input['colors'] ) ) {
            foreach ( $defaults['colors'] as $key => $default ) {
                if ( ! isset( $input['colors'][ $key ] ) ) continue;
                $val = trim( (string) $input['colors'][ $key ] );
                if ( preg_match( '/^#[0-9a-fA-F]{6}$/', $val ) ) {
                    $out['colors'][ $key ] = strtolower( $val );
                } else {
                    $out['colors'][ $key ] = $default;
                }
            }
        }

        // ─── fonts ───
        $allowed_sources = [ 'system', 'google', 'adobe-deferred' ];
        if ( isset( $input['fonts'] ) && is_array( $input['fonts'] ) ) {
            foreach ( [ 'heading', 'body', 'accent' ] as $role ) {
                if ( ! isset( $input['fonts'][ $role ] ) ) continue;
                $family = sanitize_text_field( $input['fonts'][ $role ]['family'] ?? '' );
                $source = $input['fonts'][ $role ]['source'] ?? 'system';
                if ( ! in_array( $source, $allowed_sources, true ) ) {
                    $source = 'system';
                }
                $out['fonts'][ $role ] = [
                    'family' => $family !== '' ? $family : $defaults['fonts'][ $role ]['family'],
                    'source' => $source,
                ];
            }
        }

        // ─── brand assets (attachment IDs) ───
        if ( isset( $input['brand'] ) && is_array( $input['brand'] ) ) {
            foreach ( $defaults['brand'] as $key => $default ) {
                if ( isset( $input['brand'][ $key ] ) ) {
                    $out['brand'][ $key ] = absint( $input['brand'][ $key ] );
                }
            }
        }

        // ─── business ───
        if ( isset( $input['business'] ) && is_array( $input['business'] ) ) {
            if ( isset( $input['business']['name'] ) ) {
                $out['business']['name'] = sanitize_text_field( $input['business']['name'] );
            }
            if ( isset( $input['business']['tagline'] ) ) {
                $out['business']['tagline'] = sanitize_text_field( $input['business']['tagline'] );
            }
            if ( isset( $input['business']['phone'] ) ) {
                $out['business']['phone'] = sanitize_text_field( $input['business']['phone'] );
            }
            if ( isset( $input['business']['email'] ) ) {
                $email = sanitize_email( $input['business']['email'] );
                if ( $email || $input['business']['email'] === '' ) {
                    $out['business']['email'] = $email;
                }
            }
        }

        // ─── location ───
        if ( isset( $input['location'] ) && is_array( $input['location'] ) ) {
            foreach ( $defaults['location'] as $key => $default ) {
                if ( isset( $input['location'][ $key ] ) ) {
                    $out['location'][ $key ] = sanitize_text_field( $input['location'][ $key ] );
                }
            }
        }

        // ─── hours ───
        if ( isset( $input['hours'] ) && is_array( $input['hours'] ) ) {
            foreach ( $defaults['hours'] as $day => $default ) {
                if ( ! isset( $input['hours'][ $day ] ) ) continue;
                $row    = $input['hours'][ $day ];
                $open   = preg_match( '/^[0-9]{1,2}:[0-9]{2}$/', $row['open'] ?? '' )
                    ? $row['open']
                    : '';
                $close  = preg_match( '/^[0-9]{1,2}:[0-9]{2}$/', $row['close'] ?? '' )
                    ? $row['close']
                    : '';
                $closed = ! empty( $row['closed'] );
                $out['hours'][ $day ] = [
                    'open'   => $open,
                    'close'  => $close,
                    'closed' => $closed,
                ];
            }
        }

        // ─── social ───
        if ( isset( $input['social'] ) && is_array( $input['social'] ) ) {
            foreach ( $defaults['social'] as $key => $default ) {
                if ( isset( $input['social'][ $key ] ) ) {
                    $out['social'][ $key ] = esc_url_raw( $input['social'][ $key ] );
                }
            }
        }

        // ─── custom shortcodes ───
        if ( isset( $input['custom_shortcodes'] ) && is_array( $input['custom_shortcodes'] ) ) {
            $rows = [];
            foreach ( $input['custom_shortcodes'] as $row ) {
                $tag = sanitize_key( $row['shortcode'] ?? '' );
                if ( $tag === '' ) continue;
                $rows[] = [
                    'shortcode' => $tag,
                    'title'     => sanitize_text_field( $row['title'] ?? '' ),
                    'content'   => wp_kses_post( $row['content'] ?? '' ),
                ];
            }
            $out['custom_shortcodes'] = $rows;
        }

        return $out;
    }
}
