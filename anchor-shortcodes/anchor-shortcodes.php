<?php
/**
 * Anchor Tools module: Anchor Shortcodes.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Shortcodes_Module {
    const OPTION_KEY = 'anchor_shortcodes_options';
    const LEGACY_KEYS = [ 'cgsl_options' ];
    const PAGE_SLUG = 'anchor-shortcodes';

    public function __construct() {
        add_filter( 'anchor_settings_tabs', [ $this, 'register_tab' ], 20 );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'init', [ $this, 'register_static_shortcodes' ] );
        add_action( 'init', [ $this, 'register_custom_shortcodes' ] );
        add_action( 'admin_footer', [ $this, 'admin_inline_js' ] );
        add_action( 'anchor_settings_enqueue_shortcodes', function() {
            wp_enqueue_media();
        } );
    }

    /* ---------------- Admin UI ---------------- */
    public function register_tab( $tabs ) {
        $tabs['shortcodes'] = [
            'label'    => __( 'Shortcodes', 'anchor-schema' ),
            'callback' => [ $this, 'render_tab_content' ],
        ];
        return $tabs;
    }

    public function register_settings() {
        register_setting( 'anchor_shortcodes_group', self::OPTION_KEY, [
            'type' => 'array',
            'sanitize_callback' => [ $this, 'sanitize_options' ],
            'default' => [],
        ] );

        // Section: Business Info
        add_settings_section(
            'cgsl_business_section',
            'Business Details',
            fn() => print '<p>Fill in your general business info to use with shortcodes.</p>',
            self::PAGE_SLUG
        );

$fields = [
    [ 'business_name', 'Business Name', 'text', get_bloginfo( 'name' ) ],
    [ 'address', 'Address', 'textarea', '' ],
    [ 'phone', 'Phone', 'text', '+1 (555) 123-4567' ],
    [ 'email', 'Email', 'text', get_option( 'admin_email' ) ],
    [ 'business_hours', 'Business Hours', 'textarea', 'Mon–Fri 9–5' ],
    [ 'site_icon_url', 'Site Icon URL', 'image_url', '' ],
    [ 'site_image_url', 'Site Image URL', 'image_url', '' ],
    [ 'site_image_white', 'Site Image White', 'image_url', '' ],
    [ 'site_image_horizontal', 'Site Image Horizontal', 'image_url', '' ],
    [ 'site_image_horizontal_white', 'Site Image Horizontal White', 'image_url', '' ],
];

        foreach ( $fields as $f ) {
            add_settings_field(
                $f[0], $f[1],
                [ $this, "field_{$f[2]}" ],
                self::PAGE_SLUG, 'cgsl_business_section',
                [ 'key' => $f[0], 'placeholder' => $f[3] ]
            );
        }

        // Section: Custom Shortcodes
        add_settings_section(
            'cgsl_custom_section',
            'Custom Shortcodes',
            fn() => print '<p>Add any simple custom shortcodes here. Each one will output plain text.</p>',
            self::PAGE_SLUG
        );

        add_settings_field(
            'custom_shortcodes',
            'Shortcodes',
            [ $this, 'field_custom_shortcodes' ],
            self::PAGE_SLUG,
            'cgsl_custom_section'
        );
    }

    public function sanitize_options( $input ) {
        $out = $this->get_options();

        foreach ( [
            'business_name', 'address', 'phone', 'email', 'business_hours',
            'site_image_url', 'site_icon_url', 'site_image_horizontal',
            'site_image_horizontal_white', 'site_image_white'
        ] as $key ) {
            $val = $input[$key] ?? '';
            if ( in_array( $key, [ 'address', 'business_hours' ], true ) ) {
                $out[$key] = wp_kses_post( $val );
            } elseif ( str_contains( $key, 'site_image' ) || $key === 'site_icon_url' ) {
                $out[$key] = esc_url_raw( $val );
            } else {
                $out[$key] = sanitize_text_field( $val );
            }
        }

        // Custom shortcodes
        if ( isset( $input['custom_shortcodes'] ) && is_array( $input['custom_shortcodes'] ) ) {
            $out['custom_shortcodes'] = [];
            foreach ( $input['custom_shortcodes'] as $row ) {
                if ( empty( $row['shortcode'] ) ) continue;
                $out['custom_shortcodes'][] = [
                    'shortcode' => sanitize_key( $row['shortcode'] ),
                    'title'     => sanitize_text_field( $row['title'] ?? '' ),
                    'content'   => wp_kses_post( $row['content'] ?? '' ),
                ];
            }
        }

        return $out;
    }

    public function get_options() {
        $defaults = [
            'business_name' => '',
            'address' => '',
            'phone' => '',
            'email' => '',
            'business_hours' => '',
            'site_image_url' => '',
            'site_icon_url' => '',
            'site_image_horizontal' => '',
            'site_image_horizontal_white' => '',
            'site_image_white' => '',
            'custom_shortcodes' => [],
        ];
        $stored = get_option( self::OPTION_KEY, [] );
        if ( empty( $stored ) ) {
            foreach ( self::LEGACY_KEYS as $legacy_key ) {
                $legacy = get_option( $legacy_key, [] );
                if ( ! empty( $legacy ) ) {
                    $stored = $legacy;
                    break;
                }
            }
        }
        return wp_parse_args( $stored, $defaults );
    }

    /* ---------------- Fields ---------------- */
    public function field_text( $args ) {
        $opts = $this->get_options();
        $val = esc_attr( $opts[$args['key']] ?? '' );
        $ph  = esc_attr( $args['placeholder'] ?? '' );
        echo "<input type='text' class='regular-text' name='" . self::OPTION_KEY . "[{$args['key']}]' value='$val' placeholder='$ph' />";
    }

    public function field_textarea( $args ) {
        $opts = $this->get_options();
        $val = esc_textarea( $opts[$args['key']] ?? '' );
        $ph  = esc_attr( $args['placeholder'] ?? '' );
        echo "<textarea class='large-text code' rows='3' name='" . self::OPTION_KEY . "[{$args['key']}]' placeholder='$ph'>$val</textarea>";
    }

    public function field_image_url( $args ) {
        $opts    = $this->get_options();
        $key     = $args['key'];
        $val     = esc_attr( $opts[$key] ?? '' );
        $name    = self::OPTION_KEY . "[$key]";
        $preview = $opts[$key] ? '<img src="' . esc_url( $opts[$key] ) . '" style="display:block;max-width:200px;max-height:80px;object-fit:contain;margin-top:6px;border:1px solid #ddd;border-radius:4px;padding:3px;background:#f9f9f9;" />' : '';
        echo "
        <div class='cgsl-image-field' data-key='$key'>
            <input type='text' class='regular-text cgsl-image-url' name='$name' value='$val' />
            <button type='button' class='button cgsl-select-image' style='margin-left:4px;'>Select Image</button>
            <button type='button' class='button-link cgsl-remove-image' style='margin-left:6px;color:#b32d2e;" . ( $val ? '' : 'display:none;' ) . "'>Remove</button>
            <div class='cgsl-image-preview'>$preview</div>
        </div>";
    }

    public function field_custom_shortcodes() {
        $opts = $this->get_options();
        $rows = $opts['custom_shortcodes'] ?? [];
        ?>
        <div id="cgsl-shortcodes">
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th>Shortcode</th>
                        <th>Title</th>
                        <th>Content</th>
                        <th>Remove</th>
                    </tr>
                </thead>
                <tbody class="cgsl-rows">
                    <?php foreach ( $rows as $i => $row ) : $this->render_shortcode_row( $i, $row ); endforeach; ?>
                </tbody>
            </table>
            <p><button type="button" class="button button-secondary" id="cgsl-add-row">+ Add Shortcode</button></p>
        </div>
        <script type="text/html" id="tmpl-cgsl-row">
            <?php $this->render_shortcode_row( '{{index}}', [ 'shortcode' => '', 'title' => '', 'content' => '' ] ); ?>
        </script>
        <?php
    }

    private function render_shortcode_row( $i, $row ) {
        $short = esc_attr( $row['shortcode'] ?? '' );
        $title = esc_attr( $row['title'] ?? '' );
        $content = esc_textarea( $row['content'] ?? '' );
        ?>
        <tr class="cgsl-row">
            <td><input type="text" name="<?php echo self::OPTION_KEY; ?>[custom_shortcodes][<?php echo $i; ?>][shortcode]" value="<?php echo $short; ?>" placeholder="doctor_name" /></td>
            <td><input type="text" name="<?php echo self::OPTION_KEY; ?>[custom_shortcodes][<?php echo $i; ?>][title]" value="<?php echo $title; ?>" placeholder="Doctor Name" /></td>
            <td><textarea rows="2" name="<?php echo self::OPTION_KEY; ?>[custom_shortcodes][<?php echo $i; ?>][content]"><?php echo $content; ?></textarea></td>
            <td><button type="button" class="button-link-delete cgsl-remove-row">Remove</button></td>
        </tr>
        <?php
    }

    /* ---------------- Shortcodes ---------------- */
    public function register_static_shortcodes() {
        $opts = $this->get_options();

        add_shortcode( 'current_year', fn() => date_i18n( 'Y' ) );
        add_shortcode( 'site_title', fn() => esc_html( get_bloginfo( 'name' ) ) );

        // Site image and icon URLs
        add_shortcode( 'site_image_url', fn() => esc_url( $opts['site_image_url'] ) );
        add_shortcode( 'site_icon_url', fn() => esc_url( $opts['site_icon_url'] ?: get_site_icon_url() ) );
        add_shortcode( 'site_image_horizontal', fn() => esc_url( $opts['site_image_horizontal'] ) );
        add_shortcode( 'site_image_horizontal_white', fn() => esc_url( $opts['site_image_horizontal_white'] ) );
        add_shortcode( 'site_image_white', fn() => esc_url( $opts['site_image_white'] ) );

        add_shortcode( 'business_name', fn() => esc_html( $opts['business_name'] ?: get_bloginfo( 'name' ) ) );
        add_shortcode( 'address', fn() => wpautop( wp_kses_post( $opts['address'] ) ) );
        add_shortcode( 'phone', fn() => esc_html( $opts['phone'] ) );
        add_shortcode( 'email', fn() => esc_html( $opts['email'] ?: get_option( 'admin_email' ) ) );
        add_shortcode( 'business_hours', fn() => nl2br( esc_html( $opts['business_hours'] ) ) );

        add_shortcode( 'phone_href', function() use ( $opts ) {
            $raw = preg_replace( '/[^\d\+]/', '', $opts['phone'] );
            return $raw ? 'tel:' . esc_attr( $raw ) : '';
        });

        add_shortcode( 'page_title', function() {
            if ( is_singular() ) {
                return esc_html( get_the_title() );
            }
            if ( is_post_type_archive() ) {
                return esc_html( post_type_archive_title( '', false ) );
            }
            if ( is_category() || is_tag() || is_tax() ) {
                return esc_html( single_term_title( '', false ) );
            }
            if ( is_author() ) {
                return esc_html( get_the_author() );
            }
            if ( is_search() ) {
                return esc_html( get_search_query() );
            }
            if ( is_home() && get_option( 'page_for_posts' ) ) {
                return esc_html( get_the_title( get_option( 'page_for_posts' ) ) );
            }
            return esc_html( wp_get_document_title() );
        });
    }

    public function register_custom_shortcodes() {
        $opts = $this->get_options();
        if ( empty( $opts['custom_shortcodes'] ) ) return;
        foreach ( $opts['custom_shortcodes'] as $row ) {
            if ( empty( $row['shortcode'] ) ) continue;
            add_shortcode( $row['shortcode'], fn() => esc_html( $row['content'] ?? '' ) );
        }
    }

    public function render_tab_content() {
        ?>
        <form method="post" action="options.php">
            <?php settings_fields( 'anchor_shortcodes_group' ); ?>
            <?php do_settings_sections( self::PAGE_SLUG ); ?>
            <?php submit_button(); ?>
        </form>
        <hr>
        <h2>Available Default Shortcodes</h2>
        <ul>
            <li><code>[current_year]</code></li>
            <li><code>[site_title]</code></li>
            <li><code>[site_image_url]</code></li>
            <li><code>[site_icon_url]</code></li>
            <li><code>[site_image_horizontal]</code></li>
            <li><code>[site_image_horizontal_white]</code></li>
            <li><code>[site_image_white]</code></li>
            <li><code>[business_name]</code></li>
            <li><code>[address]</code></li>
            <li><code>[phone]</code></li>
            <li><code>[phone_href]</code></li>
            <li><code>[email]</code></li>
            <li><code>[business_hours]</code></li>
        </ul>
        <?php
    }
    public function admin_inline_js() {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        $is_shortcodes_tab = $screen
            && $screen->id === 'settings_page_anchor-schema'
            && isset( $_GET['tab'] ) && $_GET['tab'] === 'shortcodes';
        if ( ! $is_shortcodes_tab ) {
            return;
        }
        ?>
        <script>
        (function($){
            $(function(){
                // Custom shortcodes table
                const $tbody = $('.cgsl-rows'), tmpl = $('#tmpl-cgsl-row').html();
                $('#cgsl-add-row').on('click',function(){
                    let index=$tbody.find('tr.cgsl-row').length;
                    $tbody.append(tmpl.replace(/\{\{index\}\}/g,index));
                });
                $tbody.on('click','.cgsl-remove-row',function(){
                    $(this).closest('tr').remove();
                });

                // Image URL media picker
                var mediaFrame;
                $(document).on('click', '.cgsl-select-image', function(e) {
                    e.preventDefault();
                    var $field = $(this).closest('.cgsl-image-field');

                    mediaFrame = wp.media({
                        title: 'Select Image',
                        button: { text: 'Use this image' },
                        multiple: false,
                        library: { type: 'image' }
                    });

                    mediaFrame.on('select', function() {
                        var attachment = mediaFrame.state().get('selection').first().toJSON();
                        var url = attachment.url;
                        $field.find('.cgsl-image-url').val(url);
                        $field.find('.cgsl-remove-image').show();
                        var $preview = $field.find('.cgsl-image-preview');
                        $preview.html('<img src="' + url + '" style="display:block;max-width:200px;max-height:80px;object-fit:contain;margin-top:6px;border:1px solid #ddd;border-radius:4px;padding:3px;background:#f9f9f9;" />');
                    });

                    mediaFrame.open();
                });

                $(document).on('click', '.cgsl-remove-image', function(e) {
                    e.preventDefault();
                    var $field = $(this).closest('.cgsl-image-field');
                    $field.find('.cgsl-image-url').val('');
                    $field.find('.cgsl-image-preview').html('');
                    $(this).hide();
                });
            });
        })(jQuery);
        </script>
        <?php
    }
}
