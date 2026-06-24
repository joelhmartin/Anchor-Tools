<?php
/**
 * Anchor Tools module: Anchor Blocks.
 *
 * Reusable HTML/CSS/JS content blocks placed via [anchor_block id=N].
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Anchor_Blocks_Module {
    const CPT          = 'anchor_block';
    const TAX          = 'anchor_block_group';
    const NONCE        = 'anchor_block_nonce';
    const OPTION_KEY   = 'anchor_blocks_settings';
    const ASSET_VER    = '1.0.0';

    /** Per-request render state. */
    private $queued       = [];   // id => ['css' => string, 'js' => string]
    private $instances    = [];   // id => int (placement counter)
    private $printed_base = false;
    private $rendering    = [];   // id => true (recursion guard for nested embeds)

    public function __construct() {
        add_action( 'init',                 [ $this, 'register_cpt' ] );
        add_action( 'init',                 [ $this, 'register_groups' ] );
        add_action( 'add_meta_boxes',       [ $this, 'add_metaboxes' ] );
        add_action( 'save_post',            [ $this, 'save_meta' ] );
        add_action( 'admin_enqueue_scripts',[ $this, 'admin_assets' ] );
        add_action( 'wp_footer',            [ $this, 'print_footer_assets' ], 20 );

        add_shortcode( 'anchor_block', [ $this, 'shortcode_render' ] );

        add_filter( 'manage_' . self::CPT . '_posts_columns',       [ $this, 'add_admin_columns' ] );
        add_action( 'manage_' . self::CPT . '_posts_custom_column', [ $this, 'render_admin_column' ], 10, 2 );
    }

    public function register_cpt() {
        $labels = [
            'name'          => 'Anchor Blocks',
            'singular_name' => 'Anchor Block',
            'add_new_item'  => 'Add New Block',
            'edit_item'     => 'Edit Block',
            'menu_name'     => 'Anchor Blocks',
        ];
        register_post_type( self::CPT, [
            'labels'       => $labels,
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => apply_filters( 'anchor_blocks_parent_menu', true ),
            'menu_icon'    => 'dashicons-layout',
            'supports'     => [ 'title' ],
        ] );
    }

    public function register_groups() {
        Anchor_Groups::register( self::TAX, self::CPT, [
            'name'          => __( 'Block Groups', 'anchor-schema' ),
            'singular_name' => __( 'Block Group', 'anchor-schema' ),
            'menu_name'     => __( 'Groups', 'anchor-schema' ),
        ] );
    }

    /* Metaboxes, save, assets, shortcode, columns, settings — added in later tasks. */

    private function get_meta( $post_id ) {
        $defaults = [
            'html'             => '',
            'css'              => '',
            'js'               => '',
            'full_width'       => '',
            'preview_css_urls' => '',
        ];
        $meta = [];
        foreach ( $defaults as $k => $v ) {
            $val = get_post_meta( $post_id, "ab_$k", true );
            $meta[ $k ] = ( $val === '' || $val === false ) ? $v : $val;
        }
        return $meta;
    }

    public function add_metaboxes() {
        add_meta_box( 'ab_code',     'Block Code (HTML / CSS / JS)', [ $this, 'render_box_code' ],     self::CPT, 'normal', 'high' );
        add_meta_box( 'ab_settings', 'Block Settings',               [ $this, 'render_box_settings' ], self::CPT, 'side' );
        add_meta_box( 'ab_preview',  'Live Preview',                 [ $this, 'render_box_preview' ],  self::CPT, 'normal', 'default' );
    }

    public function render_box_code( $post ) {
        wp_nonce_field( self::NONCE, self::NONCE );
        $m = $this->get_meta( $post->ID );
        ?>
        <div class="anchor-monaco" data-anchor-monaco='<?php echo esc_attr( wp_json_encode( [
            [ 'id' => 'ab_html', 'label' => __( 'HTML', 'anchor-schema' ), 'lang' => 'html' ],
            [ 'id' => 'ab_css',  'label' => __( 'CSS', 'anchor-schema' ),  'lang' => 'css' ],
            [ 'id' => 'ab_js',   'label' => __( 'JS', 'anchor-schema' ),   'lang' => 'javascript' ],
        ] ) ); ?>'>
        <div class="ab-fields">
            <div class="ab-field">
                <label for="ab_html"><strong>HTML</strong></label>
                <textarea id="ab_html" name="ab_html" rows="10" class="widefat code"><?php echo esc_textarea( $m['html'] ); ?></textarea>
            </div>
            <div class="ab-field">
                <label for="ab_css"><strong>CSS</strong></label>
                <textarea id="ab_css" name="ab_css" rows="8" class="widefat code"><?php echo esc_textarea( $m['css'] ); ?></textarea>
            </div>
            <div class="ab-field">
                <label for="ab_js"><strong>JavaScript</strong></label>
                <textarea id="ab_js" name="ab_js" rows="8" class="widefat code"><?php echo esc_textarea( $m['js'] ); ?></textarea>
                <p class="description">
                    Runs once per page. To target every placement of this block, iterate over all instances:<br>
                    <code>document.querySelectorAll('.anchor-block--<?php echo (int) $post->ID; ?>').forEach(function(el){ /* init using el */ });</code>
                </p>
            </div>
        </div>
        </div>
        <?php
    }

    public function render_box_settings( $post ) {
        $m = $this->get_meta( $post->ID );
        ?>
        <style>
            .ab-side label { display:block; margin-top:8px; font-weight:600; }
            .ab-side textarea { width:100%; }
            .ab-side .description { color:#666; font-size:12px; }
        </style>
        <div class="ab-side">
            <?php if ( $post->post_status !== 'auto-draft' ) : ?>
                <label>Shortcode</label>
                <div class="ab-shortcode-copy">
                    <input type="text" readonly class="widefat" id="ab-shortcode-value"
                           value="<?php echo esc_attr( '[anchor_block id="' . (int) $post->ID . '"]' ); ?>">
                    <button type="button" class="button" id="ab-shortcode-copy-btn">Copy</button>
                </div>
            <?php else : ?>
                <label>Shortcode</label>
                <p class="description">Save the block to get its shortcode.</p>
            <?php endif; ?>

            <label>
                <input type="checkbox" name="ab_full_width" value="1" <?php checked( $m['full_width'], '1' ); ?>>
                Break out to full viewport width
            </label>
            <p class="description">Wraps output edge-to-edge (100vw). Leave off for inline elements like a button.</p>

            <label for="ab_preview_css_urls">Preview stylesheets (extra)</label>
            <textarea id="ab_preview_css_urls" name="ab_preview_css_urls" rows="4" placeholder="https://example.com/css/global.css"><?php echo esc_textarea( $m['preview_css_urls'] ); ?></textarea>
            <p class="description">One URL per line. Loaded in this block's preview only (in addition to the harvested site CSS and any site-wide defaults set in Settings &gt; Anchor Tools &gt; Preview).</p>
        </div>
        <?php
    }

    public function render_box_preview( $post ) {
        ?>
        <p class="description">Live preview renders inside an isolated frame that loads your theme stylesheet, so <code>:root</code> variables, colors and fonts resolve as on the front end.</p>
        <iframe id="ab-preview-frame" style="width:100%; min-height:360px; border:1px solid #ccd0d4; border-radius:8px; background:#fff;"></iframe>
        <?php
    }
    public function save_meta( $post_id ) {
        if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( $_POST[ self::NONCE ], self::NONCE ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        // Raw code fields — intentionally stored unescaped (rendered as authored, like Mega Menu).
        foreach ( [ 'html', 'css', 'js' ] as $f ) {
            update_post_meta( $post_id, "ab_$f", isset( $_POST[ "ab_$f" ] ) ? $_POST[ "ab_$f" ] : '' );
        }

        update_post_meta( $post_id, 'ab_full_width', isset( $_POST['ab_full_width'] ) ? '1' : '' );

        $urls = isset( $_POST['ab_preview_css_urls'] ) ? (string) $_POST['ab_preview_css_urls'] : '';
        $clean = [];
        foreach ( preg_split( '/\r\n|\r|\n/', $urls ) as $line ) {
            $line = trim( $line );
            if ( $line !== '' ) { $clean[] = esc_url_raw( $line ); }
        }
        update_post_meta( $post_id, 'ab_preview_css_urls', implode( "\n", array_filter( $clean ) ) );
    }

    public function admin_assets( $hook ) {
        global $post;
        if ( ( $hook === 'post.php' || $hook === 'post-new.php' ) && isset( $post ) && $post->post_type === self::CPT ) {
            if ( class_exists( 'Anchor_Preview_CSS' ) ) {
                Anchor_Preview_CSS::enqueue_for_admin();
            }
            Anchor_Monaco::enqueue( self::CPT );
            $adir = plugin_dir_path( __FILE__ ) . 'assets/';
            wp_enqueue_style( 'ab-admin', Anchor_Asset_Loader::url( 'anchor-blocks/assets/admin.css' ), [], (string) filemtime( $adir . 'admin.css' ) );
            wp_enqueue_script( 'ab-admin', Anchor_Asset_Loader::url( 'anchor-blocks/assets/admin.js' ), [ 'jquery', 'anchor-monaco', 'anchor-preview' ], (string) filemtime( $adir . 'admin.js' ), true );
        }
    }
    public function print_footer_assets() {
        if ( empty( $this->queued ) ) { return; }

        // Base full-bleed style, printed once if any block rendered.
        if ( ! $this->printed_base ) {
            echo "<style id=\"anchor-blocks-base\">.anchor-block-fullwidth{width:100vw;max-width:100vw;margin-left:calc(50% - 50vw);margin-right:calc(50% - 50vw);}</style>\n";
            $this->printed_base = true;
        }

        foreach ( $this->queued as $id => $assets ) {
            if ( $assets['css'] !== '' ) {
                echo "<style id=\"anchor-block-css-{$id}\">\n/* Anchor Block {$id} */\n" . $assets['css'] . "\n</style>\n";
            }
        }
        foreach ( $this->queued as $id => $assets ) {
            if ( $assets['js'] !== '' ) {
                echo "<script id=\"anchor-block-js-{$id}\">(function(){try{" . $assets['js'] . "}catch(e){console.error(e);}})();</script>\n";
            }
        }
    }

    private function resolve_block( $id_or_slug ) {
        $id_or_slug = trim( (string) $id_or_slug );
        if ( $id_or_slug === '' ) { return null; }

        $post = null;
        if ( is_numeric( $id_or_slug ) ) {
            $candidate = get_post( (int) $id_or_slug );
            if ( $candidate && $candidate->post_type === self::CPT && $candidate->post_status === 'publish' ) {
                $post = $candidate;
            }
        }
        if ( ! $post ) {
            $found = get_posts( [
                'post_type'      => self::CPT,
                'name'           => $id_or_slug,
                'posts_per_page' => 1,
                'post_status'    => 'publish',
            ] );
            $post = $found[0] ?? null;
        }
        return $post;
    }

    public function shortcode_render( $atts ) {
        $atts = shortcode_atts( [ 'id' => '', 'slug' => '' ], $atts );
        $ref  = $atts['id'] !== '' ? $atts['id'] : $atts['slug'];
        $post = $this->resolve_block( $ref );
        if ( ! $post ) { return ''; }

        $id = (int) $post->ID;

        // Prevent infinite loops when a block embeds itself (directly or via a cycle).
        if ( isset( $this->rendering[ $id ] ) ) { return ''; }
        $this->rendering[ $id ] = true;

        $m  = $this->get_meta( $id );

        // Queue CSS/JS once per page (printed in wp_footer).
        if ( ! isset( $this->queued[ $id ] ) ) {
            $this->queued[ $id ] = [ 'css' => trim( $m['css'] ), 'js' => trim( $m['js'] ) ];
        }

        // Per-placement instance index.
        $this->instances[ $id ] = ( $this->instances[ $id ] ?? 0 ) + 1;
        $instance = $this->instances[ $id ];

        // Run nested shortcodes inside the block HTML (e.g. [anchor_reviews], forms).
        $inner = '<div class="anchor-block anchor-block--' . $id . '" data-anchor-block="' . $id . '" data-instance="' . $instance . '">'
               . do_shortcode( $m['html'] )
               . '</div>';

        if ( $m['full_width'] === '1' ) {
            $inner = '<div class="anchor-block-fullwidth">' . $inner . '</div>';
        }

        unset( $this->rendering[ $id ] );
        return $inner;
    }

    public function add_admin_columns( $columns ) {
        $new = [];
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            if ( $key === 'title' ) {
                $new['ab_shortcode'] = __( 'Shortcode', 'anchor-schema' );
            }
        }
        return $new;
    }

    public function render_admin_column( $column, $post_id ) {
        if ( $column === 'ab_shortcode' ) {
            echo '<code>' . esc_html( '[anchor_block id="' . (int) $post_id . '"]' ) . '</code>';
        }
    }
}
