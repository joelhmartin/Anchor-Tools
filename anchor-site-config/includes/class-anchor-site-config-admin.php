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

    private function render_custom_shortcodes_section( $opts ) {
        $opt_key = Anchor_Site_Config_Module::OPTION_KEY;
        $rows    = $opts['custom_shortcodes'] ?? [];
        ?>
        <p class="description">
            <?php esc_html_e( 'Define custom values here. Site Config registers [config_your_tag] and keeps [your_tag] working when that tag is not already owned by another shortcode.', 'anchor-schema' ); ?>
        </p>
        <div class="anchor-site-config-rows">
            <?php foreach ( $rows as $i => $row ) : ?>
                <?php $this->render_custom_shortcode_row( $i, $row, $opt_key ); ?>
            <?php endforeach; ?>
        </div>
        <button type="button" class="button" id="anchor-site-config-add-row">
            <?php esc_html_e( '+ Add Shortcode', 'anchor-schema' ); ?>
        </button>

        <script type="text/html" id="tmpl-anchor-site-config-row">
            <?php $this->render_custom_shortcode_row( '{{INDEX}}', [ 'shortcode' => '', 'title' => '', 'content' => '' ], $opt_key ); ?>
        </script>
        <?php
    }

    private function render_custom_shortcode_row( $index, $row, $opt_key ) {
        $name_prefix = $opt_key . '[custom_shortcodes][' . $index . ']';
        ?>
        <div class="anchor-site-config-row" style="margin:8px 0;padding:8px;border:1px solid #ddd;border-radius:4px;">
            <p>
                <label><?php esc_html_e( 'Shortcode tag', 'anchor-schema' ); ?>:</label>
                <input type="text" class="regular-text"
                       name="<?php echo esc_attr( $name_prefix . '[shortcode]' ); ?>"
                       value="<?php echo esc_attr( $row['shortcode'] ?? '' ); ?>"
                       placeholder="my_tag" />
            </p>
            <p>
                <label><?php esc_html_e( 'Title (admin reference)', 'anchor-schema' ); ?>:</label>
                <input type="text" class="regular-text"
                       name="<?php echo esc_attr( $name_prefix . '[title]' ); ?>"
                       value="<?php echo esc_attr( $row['title'] ?? '' ); ?>" />
            </p>
            <p>
                <label><?php esc_html_e( 'Output content', 'anchor-schema' ); ?>:</label>
                <textarea rows="3" class="large-text"
                          name="<?php echo esc_attr( $name_prefix . '[content]' ); ?>"><?php echo esc_textarea( $row['content'] ?? '' ); ?></textarea>
            </p>
            <button type="button" class="button button-link-delete anchor-site-config-remove-row">
                <?php esc_html_e( 'Remove row', 'anchor-schema' ); ?>
            </button>
        </div>
        <?php
    }

    private function render_social_section( $opts ) {
        $opt_key   = Anchor_Site_Config_Module::OPTION_KEY;
        $platforms = [
            'facebook'  => 'Facebook',
            'instagram' => 'Instagram',
            'twitter'   => 'Twitter / X',
            'linkedin'  => 'LinkedIn',
            'youtube'   => 'YouTube',
            'tiktok'    => 'TikTok',
        ];
        ?>
        <table class="form-table"><tbody>
        <?php foreach ( $platforms as $key => $label ) :
            $value = $opts['social'][ $key ] ?? '';
        ?>
            <tr>
                <th scope="row"><label><?php echo esc_html( $label ); ?></label></th>
                <td>
                    <input type="url" class="regular-text"
                           name="<?php echo esc_attr( $opt_key . '[social][' . $key . ']' ); ?>"
                           value="<?php echo esc_attr( $value ); ?>"
                           placeholder="https://..." />
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
        <?php
    }

    private function render_hours_section( $opts ) {
        $opt_key = Anchor_Site_Config_Module::OPTION_KEY;
        $days    = [
            'monday'    => __( 'Monday',    'anchor-schema' ),
            'tuesday'   => __( 'Tuesday',   'anchor-schema' ),
            'wednesday' => __( 'Wednesday', 'anchor-schema' ),
            'thursday'  => __( 'Thursday',  'anchor-schema' ),
            'friday'    => __( 'Friday',    'anchor-schema' ),
            'saturday'  => __( 'Saturday',  'anchor-schema' ),
            'sunday'    => __( 'Sunday',    'anchor-schema' ),
        ];
        ?>
        <table class="form-table anchor-hours-table"><tbody>
        <?php foreach ( $days as $day => $label ) :
            $row    = $opts['hours'][ $day ] ?? [ 'open' => '', 'close' => '', 'closed' => false ];
            $closed = ! empty( $row['closed'] );
        ?>
            <tr class="anchor-hours-row" data-day="<?php echo esc_attr( $day ); ?>">
                <th scope="row"><label><?php echo esc_html( $label ); ?></label></th>
                <td>
                    <input type="time"
                           name="<?php echo esc_attr( $opt_key . '[hours][' . $day . '][open]' ); ?>"
                           value="<?php echo esc_attr( $row['open'] ); ?>"
                           <?php disabled( $closed ); ?> />
                    <?php esc_html_e( 'to', 'anchor-schema' ); ?>
                    <input type="time"
                           name="<?php echo esc_attr( $opt_key . '[hours][' . $day . '][close]' ); ?>"
                           value="<?php echo esc_attr( $row['close'] ); ?>"
                           <?php disabled( $closed ); ?> />
                    &nbsp;
                    <label>
                        <input type="checkbox"
                               class="anchor-hours-closed"
                               name="<?php echo esc_attr( $opt_key . '[hours][' . $day . '][closed]' ); ?>"
                               value="1"
                               <?php checked( $closed ); ?> />
                        <?php esc_html_e( 'Closed', 'anchor-schema' ); ?>
                    </label>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
        <?php
    }

    private function render_business_section( $opts ) {
        $opt_key = Anchor_Site_Config_Module::OPTION_KEY;
        $fields  = [
            'name'    => [ __( 'Business name', 'anchor-schema' ), 'text' ],
            'tagline' => [ __( 'Tagline',       'anchor-schema' ), 'text' ],
            'phone'   => [ __( 'Phone',         'anchor-schema' ), 'tel'  ],
            'email'   => [ __( 'Email',         'anchor-schema' ), 'email' ],
        ];
        $this->render_text_grid( $opts['business'], $fields, $opt_key . '[business]' );
    }

    private function render_location_section( $opts ) {
        $opt_key = Anchor_Site_Config_Module::OPTION_KEY;
        $fields  = [
            'line1'   => [ __( 'Address line 1', 'anchor-schema' ), 'text' ],
            'line2'   => [ __( 'Address line 2', 'anchor-schema' ), 'text' ],
            'city'    => [ __( 'City',           'anchor-schema' ), 'text' ],
            'state'   => [ __( 'State / Region', 'anchor-schema' ), 'text' ],
            'postal'  => [ __( 'Postal code',    'anchor-schema' ), 'text' ],
            'country' => [ __( 'Country',        'anchor-schema' ), 'text' ],
        ];
        $this->render_text_grid( $opts['location'], $fields, $opt_key . '[location]' );
    }

    /**
     * Reusable text-grid renderer.
     * $values: array of stored values keyed by field key
     * $fields: array of [ key => [label, input_type] ]
     * $name_prefix: e.g. 'anchor_site_config_options[business]'
     */
    private function render_text_grid( $values, $fields, $name_prefix ) {
        ?>
        <table class="form-table"><tbody>
        <?php foreach ( $fields as $key => list( $label, $type ) ) :
            $value = $values[ $key ] ?? '';
        ?>
            <tr>
                <th scope="row"><label><?php echo esc_html( $label ); ?></label></th>
                <td>
                    <input type="<?php echo esc_attr( $type ); ?>" class="regular-text"
                           name="<?php echo esc_attr( $name_prefix . '[' . $key . ']' ); ?>"
                           value="<?php echo esc_attr( $value ); ?>" />
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
        <?php
    }

    private function render_brand_section( $opts ) {
        $opt_key = Anchor_Site_Config_Module::OPTION_KEY;
        $fields  = [
            'primary_logo'         => __( 'Primary Logo',                'anchor-schema' ),
            'secondary_logo'       => __( 'Secondary Logo',              'anchor-schema' ),
            'primary_logo_white'   => __( 'Primary Logo (white)',        'anchor-schema' ),
            'secondary_logo_white' => __( 'Secondary Logo (white)',      'anchor-schema' ),
            'favicon'              => __( 'Favicon',                     'anchor-schema' ),
            'og_image'             => __( 'Default Open Graph Image',    'anchor-schema' ),
        ];
        ?>
        <table class="form-table"><tbody>
        <?php foreach ( $fields as $key => $label ) :
            $att_id  = absint( $opts['brand'][ $key ] ?? 0 );
            $thumb   = $att_id ? wp_get_attachment_image_url( $att_id, 'thumbnail' ) : '';
            $input   = $opt_key . '[brand][' . $key . ']';
        ?>
            <tr class="anchor-media-row">
                <th scope="row"><label><?php echo esc_html( $label ); ?></label></th>
                <td>
                    <div class="anchor-media-preview">
                        <?php if ( $thumb ) : ?>
                            <img src="<?php echo esc_url( $thumb ); ?>" alt="" style="max-width:120px;max-height:80px;" />
                        <?php endif; ?>
                    </div>
                    <input type="hidden"
                           class="anchor-media-id"
                           name="<?php echo esc_attr( $input ); ?>"
                           value="<?php echo esc_attr( $att_id ); ?>" />
                    <button type="button" class="button anchor-media-choose">
                        <?php esc_html_e( 'Choose Image', 'anchor-schema' ); ?>
                    </button>
                    <button type="button" class="button anchor-media-remove" <?php disabled( ! $att_id ); ?>>
                        <?php esc_html_e( 'Remove', 'anchor-schema' ); ?>
                    </button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
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
                // Strict 24-hour validation: 00:00-23:59 only.
                $time_re = '/^(?:[01]\d|2[0-3]):[0-5]\d$/';
                $open    = preg_match( $time_re, $row['open']  ?? '' ) ? $row['open']  : '';
                $close   = preg_match( $time_re, $row['close'] ?? '' ) ? $row['close'] : '';
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
