<?php
namespace Anchor\Webinars;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Module {
    const CPT = 'anchor_webinar';
    const OPTION_KEY = 'anchor_webinars_settings';
    const NONCE = 'anchor_webinar_nonce';
    const LOG_TABLE = 'anchor_webinar_logs';
    const DB_VERSION = '1.0.0';

    private static $instance = null;
    private $filling_content = false;

    public function __construct() {
        self::$instance = $this;

        \add_action( 'init', [ $this, 'register_cpt' ] );
        \add_action( 'add_meta_boxes', [ $this, 'add_metaboxes' ] );
        \add_action( 'save_post_' . self::CPT, [ $this, 'save_meta' ] );

        \add_action( 'admin_menu', [ $this, 'register_settings_page' ] );
        \add_action( 'admin_init', [ $this, 'register_settings' ] );
        \add_action( 'admin_menu', [ $this, 'register_analytics_page' ] );
        \add_action( 'admin_notices', [ $this, 'admin_notices' ] );

        \add_action( 'admin_enqueue_scripts', [ $this, 'admin_assets' ] );
        \add_action( 'wp_enqueue_scripts', [ $this, 'frontend_assets' ] );

        \add_shortcode( 'anchor_webinar', [ $this, 'render_shortcode' ] );

        \add_filter( 'template_include', [ $this, 'template_include' ] );

        \add_action( 'wp_ajax_anchor_webinar_log', [ $this, 'handle_watch_log' ] );

        \add_action( 'init', [ $this, 'maybe_create_table' ] );
    }

    public static function instance() {
        return self::$instance;
    }

    public function register_cpt() {
        $labels = [
            'name' => \__( 'Webinars', 'anchor-schema' ),
            'singular_name' => \__( 'Webinar', 'anchor-schema' ),
            'add_new_item' => \__( 'Add New Webinar', 'anchor-schema' ),
            'edit_item' => \__( 'Edit Webinar', 'anchor-schema' ),
            'new_item' => \__( 'New Webinar', 'anchor-schema' ),
            'view_item' => \__( 'View Webinar', 'anchor-schema' ),
            'search_items' => \__( 'Search Webinars', 'anchor-schema' ),
            'not_found' => \__( 'No webinars found.', 'anchor-schema' ),
            'not_found_in_trash' => \__( 'No webinars found in Trash.', 'anchor-schema' ),
            'menu_name' => \__( 'Webinars', 'anchor-schema' ),
        ];

        \register_post_type( self::CPT, [
            'labels' => $labels,
            'public' => true,
            'show_in_rest' => true,
            'has_archive' => true,
            'supports' => [ 'title', 'editor', 'excerpt', 'thumbnail' ],
            'menu_icon' => 'dashicons-video-alt3',
        ] );
    }

    public function add_metaboxes() {
        \add_meta_box(
            'anchor_webinar_details',
            \__( 'Webinar Details', 'anchor-schema' ),
            [ $this, 'render_metabox' ],
            self::CPT,
            'normal',
            'high'
        );
    }

    public function render_metabox( $post ) {
        \wp_nonce_field( self::NONCE, self::NONCE );
        $vimeo_id = \get_post_meta( $post->ID, '_anchor_webinar_vimeo_id', true );
        $webinar_date = \get_post_meta( $post->ID, '_anchor_webinar_date', true );
        ?>
        <div class="anchor-webinar-meta">
            <p>
                <label for="anchor_webinar_vimeo_id"><strong><?php echo \esc_html__( 'Vimeo Video ID', 'anchor-schema' ); ?></strong></label><br />
                <input type="text" id="anchor_webinar_vimeo_id" name="anchor_webinar_vimeo_id" value="<?php echo \esc_attr( $vimeo_id ); ?>" class="regular-text" required />
            </p>
            <p>
                <label for="anchor_webinar_date"><strong><?php echo \esc_html__( 'Webinar Date', 'anchor-schema' ); ?></strong></label><br />
                <input type="date" id="anchor_webinar_date" name="anchor_webinar_date" value="<?php echo \esc_attr( $webinar_date ); ?>" />
            </p>
        </div>
        <?php
    }

    public function save_meta( $post_id ) {
        if ( ! isset( $_POST[ self::NONCE ] ) || ! \wp_verify_nonce( $_POST[ self::NONCE ], self::NONCE ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! \current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $vimeo_id = \sanitize_text_field( $_POST['anchor_webinar_vimeo_id'] ?? '' );
        $webinar_date = \sanitize_text_field( $_POST['anchor_webinar_date'] ?? '' );

        \update_post_meta( $post_id, '_anchor_webinar_vimeo_id', $vimeo_id );
        \update_post_meta( $post_id, '_anchor_webinar_date', $webinar_date );

        $this->maybe_fill_content_with_embed( $post_id, $vimeo_id, $webinar_date );

        $settings  = $this->get_settings();
        $thumb_url = null;

        if ( $settings['vimeo_api_key'] && $vimeo_id ) {
            $response = \wp_remote_get(
                'https://api.vimeo.com/videos/' . \rawurlencode( $vimeo_id ),
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $settings['vimeo_api_key'],
                    ],
                    'timeout' => 8,
                ]
            );
            $code = \wp_remote_retrieve_response_code( $response );
            if ( \is_wp_error( $response ) || $code >= 300 ) {
                \add_filter( 'redirect_post_location', function( $location ) {
                    return \add_query_arg( 'anchor_webinar_notice', 'vimeo_invalid', $location );
                } );
            } else {
                $body = json_decode( \wp_remote_retrieve_body( $response ), true );
                if ( ! empty( $body['pictures']['sizes'] ) ) {
                    $best = null;
                    foreach ( $body['pictures']['sizes'] as $size ) {
                        if ( ! $best || $size['width'] > $best['width'] ) {
                            $best = $size;
                        }
                    }
                    if ( $best && ! empty( $best['link'] ) ) {
                        $thumb_url = $best['link'];
                    }
                }
            }
        } elseif ( $vimeo_id ) {
            // oEmbed fallback — no API key required.
            $oembed_url = 'https://vimeo.com/api/oembed.json?url=' . \rawurlencode( 'https://vimeo.com/' . $vimeo_id );
            $response   = \wp_remote_get( $oembed_url, [ 'timeout' => 8 ] );
            if ( ! \is_wp_error( $response ) && \wp_remote_retrieve_response_code( $response ) < 300 ) {
                $body = json_decode( \wp_remote_retrieve_body( $response ), true );
                if ( ! empty( $body['thumbnail_url'] ) ) {
                    $thumb_url = $body['thumbnail_url'];
                }
            }
        }

        if ( $thumb_url ) {
            $this->maybe_set_vimeo_thumbnail( $post_id, $thumb_url );
        }
    }

    private function maybe_set_vimeo_thumbnail( $post_id, $thumbnail_url ) {
        if ( \has_post_thumbnail( $post_id ) ) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachment_id = \media_sideload_image( $thumbnail_url, $post_id, '', 'id' );
        if ( ! \is_wp_error( $attachment_id ) ) {
            \set_post_thumbnail( $post_id, $attachment_id );
        }
    }

    public function admin_notices() {
        if ( empty( $_GET['anchor_webinar_notice'] ) ) {
            return;
        }
        $notice = \sanitize_text_field( $_GET['anchor_webinar_notice'] );
        if ( $notice === 'vimeo_invalid' ) {
            echo '<div class="notice notice-error"><p>' . \esc_html__( 'Vimeo video ID could not be validated. Check the ID and API key.', 'anchor-schema' ) . '</p></div>';
        }
    }

    public function register_settings_page() {
        \add_options_page(
            \__( 'Anchor Webinars Settings', 'anchor-schema' ),
            \__( 'Anchor Webinars', 'anchor-schema' ),
            'manage_options',
            'anchor-webinars',
            [ $this, 'render_settings_page' ]
        );
    }

    public function register_analytics_page() {
        \add_submenu_page(
            'edit.php?post_type=' . self::CPT,
            \__( 'Webinar Analytics', 'anchor-schema' ),
            \__( 'Analytics', 'anchor-schema' ),
            'manage_options',
            'anchor-webinar-analytics',
            [ $this, 'render_analytics_page' ]
        );
    }

    public function register_settings() {
        \register_setting( 'anchor_webinars_group', self::OPTION_KEY, [ $this, 'sanitize_settings' ] );

        \add_settings_section( 'anchor_webinars_main', \__( 'Vimeo Settings', 'anchor-schema' ), function() {
            echo '<p>' . \esc_html__( 'Store your Vimeo API key for use with the player SDK.', 'anchor-schema' ) . '</p>';
        }, 'anchor_webinars_settings' );

        \add_settings_field( 'vimeo_api_key', \__( 'Vimeo API Key', 'anchor-schema' ), function() {
            $opts = $this->get_settings();
            $value = $opts['vimeo_api_key'];
            printf(
                '<input type="password" name="%1$s[vimeo_api_key]" value="%2$s" class="regular-text" autocomplete="off" />',
                \esc_attr( self::OPTION_KEY ),
                \esc_attr( $value )
            );
        }, 'anchor_webinars_settings', 'anchor_webinars_main' );
    }

    public function sanitize_settings( $input ) {
        return [
            'vimeo_api_key' => \sanitize_text_field( $input['vimeo_api_key'] ?? '' ),
        ];
    }

    public function render_settings_page() {
        echo '<div class="wrap"><h1>' . \esc_html__( 'Anchor Webinars Settings', 'anchor-schema' ) . '</h1>';
        echo '<form method="post" action="options.php">';
        \settings_fields( 'anchor_webinars_group' );
        \do_settings_sections( 'anchor_webinars_settings' );
        \submit_button();
        echo '</form></div>';
    }

    public function render_analytics_page() {
        if ( ! \current_user_can( 'manage_options' ) ) {
            return;
        }
        $current_page = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
        $per_page = 20;
        $offset = ( $current_page - 1 ) * $per_page;
        $webinar_filter = (int) ( $_GET['webinar_id'] ?? 0 );

        $logs = $this->get_logs( $per_page, $offset, $webinar_filter );
        $total = $this->get_logs_count( $webinar_filter );
        $total_pages = $total > 0 ? (int) ceil( $total / $per_page ) : 1;

        $webinars = \get_posts( [
            'post_type' => self::CPT,
            'numberposts' => -1,
            'post_status' => 'publish',
        ] );

        echo '<div class="wrap"><h1>' . \esc_html__( 'Webinar Analytics', 'anchor-schema' ) . '</h1>';
        echo '<form method="get" style="margin-bottom:12px;">';
        echo '<input type="hidden" name="post_type" value="' . \esc_attr( self::CPT ) . '" />';
        echo '<input type="hidden" name="page" value="anchor-webinar-analytics" />';
        echo '<label for="webinar-filter" style="margin-right:8px;">' . \esc_html__( 'Filter by webinar:', 'anchor-schema' ) . '</label>';
        echo '<select id="webinar-filter" name="webinar_id">';
        echo '<option value="0">' . \esc_html__( 'All Webinars', 'anchor-schema' ) . '</option>';
        foreach ( $webinars as $webinar ) {
            printf(
                '<option value="%1$d" %2$s>%3$s</option>',
                $webinar->ID,
                \selected( $webinar_filter, $webinar->ID, false ),
                \esc_html( $webinar->post_title )
            );
        }
        echo '</select> ';
        echo '<button class="button">' . \esc_html__( 'Filter', 'anchor-schema' ) . '</button>';
        echo '</form>';

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . \esc_html__( 'User', 'anchor-schema' ) . '</th>';
        echo '<th>' . \esc_html__( 'Webinar', 'anchor-schema' ) . '</th>';
        echo '<th>' . \esc_html__( 'Time Watched (seconds)', 'anchor-schema' ) . '</th>';
        echo '<th>' . \esc_html__( 'Date', 'anchor-schema' ) . '</th>';
        echo '</tr></thead><tbody>';

        if ( empty( $logs ) ) {
            echo '<tr><td colspan="4">' . \esc_html__( 'No watch logs found.', 'anchor-schema' ) . '</td></tr>';
        } else {
            foreach ( $logs as $log ) {
                $user = \get_user_by( 'id', $log->user_id );
                $user_label = $user ? $user->display_name : \__( 'Unknown', 'anchor-schema' );
                $webinar_title = \get_the_title( $log->webinar_id );
                printf(
                    '<tr><td>%1$s</td><td>%2$s</td><td>%3$d</td><td>%4$s</td></tr>',
                    \esc_html( $user_label ),
                    \esc_html( $webinar_title ),
                    (int) $log->seconds_watched,
                    \esc_html( $log->viewed_at )
                );
            }
        }
        echo '</tbody></table>';

        if ( $total_pages > 1 ) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            for ( $i = 1; $i <= $total_pages; $i++ ) {
                $url = \add_query_arg( [
                    'post_type' => self::CPT,
                    'page' => 'anchor-webinar-analytics',
                    'paged' => $i,
                    'webinar_id' => $webinar_filter,
                ], \admin_url( 'edit.php' ) );
                $class = $i === $current_page ? ' class="current-page"' : '';
                echo '<a' . $class . ' href="' . \esc_url( $url ) . '">' . \esc_html( $i ) . '</a> ';
            }
            echo '</div></div>';
        }

        echo '</div>';
    }

    public function admin_assets( $hook ) {
        $screen = \get_current_screen();
        if ( ! $screen || $screen->post_type !== self::CPT ) {
            return;
        }
        if ( $hook === 'post-new.php' || $hook === 'post.php' ) {
            \wp_enqueue_style( 'anchor-webinars-admin', \plugins_url( 'assets/admin.css', __FILE__ ), [], '1.0.0' );
        }
    }

    public function frontend_assets() {
        if ( \is_singular( self::CPT ) ) {
            \wp_enqueue_style( 'anchor-webinars-frontend', ANCHOR_TOOLS_PLUGIN_URL . 'anchor-webinars/assets/frontend.css', [], '1.0.0' );
        }
        if ( \is_post_type_archive( self::CPT ) ) {
            \wp_enqueue_style( 'anchor-webinars-frontend', ANCHOR_TOOLS_PLUGIN_URL . 'anchor-webinars/assets/frontend.css', [], '1.0.0' );
        }

        if ( ! \is_singular( self::CPT ) || ! \is_user_logged_in() ) {
            return;
        }

        $post_id  = \get_the_ID();
        $vimeo_id = \get_post_meta( $post_id, '_anchor_webinar_vimeo_id', true );
        if ( ! $vimeo_id ) {
            return;
        }

        \wp_enqueue_script( 'vimeo-player', 'https://player.vimeo.com/api/player.js', [], null, true );
        \wp_enqueue_script( 'anchor-webinar-player', ANCHOR_TOOLS_PLUGIN_URL . 'anchor-webinars/assets/player.js', [ 'vimeo-player' ], '1.0.0', true );

        \wp_localize_script( 'anchor-webinar-player', 'ANCHOR_WEBINAR', [
            'ajaxUrl'   => \admin_url( 'admin-ajax.php' ),
            'nonce'     => \wp_create_nonce( 'anchor_webinar_log' ),
            'webinarId' => $post_id,
            'vimeoId'   => $vimeo_id,
            'userId'    => \get_current_user_id(),
        ] );
    }

    public function template_include( $template ) {
        if ( \is_singular( self::CPT ) ) {
            if ( ! \is_user_logged_in() ) {
                \auth_redirect();
            }
            return $this->locate_template( 'single-webinar.php' );
        }
        if ( \is_post_type_archive( self::CPT ) ) {
            return $this->locate_template( 'archive-webinar.php' );
        }
        return $template;
    }

    public function render_shortcode( $atts ) {
        $atts = \shortcode_atts( [
            'id' => 0,
        ], $atts, 'anchor_webinar' );

        $post_id = (int) $atts['id'];
        if ( ! $post_id ) {
            $post_id = \get_the_ID();
        }

        $post = $post_id ? \get_post( $post_id ) : null;
        if ( ! $post || $post->post_status !== 'publish' ) {
            return '';
        }

        $vimeo_id     = \get_post_meta( $post->ID, '_anchor_webinar_vimeo_id', true );
        $webinar_date = \get_post_meta( $post->ID, '_anchor_webinar_date', true );

        if ( ! $vimeo_id ) {
            return '';
        }

        \wp_enqueue_style( 'anchor-webinars-frontend', ANCHOR_TOOLS_PLUGIN_URL . 'anchor-webinars/assets/frontend.css', [], '1.0.0' );

        $embed_url = 'https://player.vimeo.com/video/' . \rawurlencode( $vimeo_id ) . '?dnt=1';

        \ob_start();
        ?>
        <div class="anchor-webinar-block">
            <div class="anchor-webinar-block__player">
                <iframe src="<?php echo \esc_url( $embed_url ); ?>"
                    frameborder="0"
                    allow="autoplay; fullscreen; picture-in-picture"
                    allowfullscreen></iframe>
            </div>
            <?php if ( $webinar_date ) : ?>
                <p class="anchor-webinar-block__date"><?php echo \esc_html( \date_i18n( 'F j, Y', \strtotime( $webinar_date ) ) ); ?></p>
            <?php endif; ?>
            <?php if ( $post->post_excerpt ) : ?>
                <div class="anchor-webinar-block__excerpt"><?php echo \wp_kses_post( \wpautop( $post->post_excerpt ) ); ?></div>
            <?php endif; ?>
        </div>
        <?php
        return \ob_get_clean();
    }

    public function content_has_player_container( $post_id ) {
        $content = \get_post_field( 'post_content', $post_id );
        return ( \strpos( $content, 'anchor-webinar-player' ) !== false );
    }

    private function locate_template( $file ) {
        $theme_template = \locate_template( 'webinars/' . $file );
        if ( $theme_template ) {
            return $theme_template;
        }
        return \plugin_dir_path( __FILE__ ) . 'templates/' . $file;
    }

    public function handle_watch_log() {
        if ( ! \check_ajax_referer( 'anchor_webinar_log', 'nonce', false ) ) {
            \wp_send_json_error( [ 'message' => 'invalid_nonce' ] );
        }
        if ( ! \is_user_logged_in() ) {
            \wp_send_json_error( [ 'message' => 'not_logged_in' ] );
        }

        $user_id = \get_current_user_id();
        $webinar_id = (int) ( $_POST['webinar_id'] ?? 0 );
        $seconds = (int) ( $_POST['seconds'] ?? 0 );
        $session = \sanitize_text_field( $_POST['session'] ?? '' );

        if ( ! $webinar_id || $seconds <= 0 || ! $session ) {
            \wp_send_json_error( [ 'message' => 'invalid_data' ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . self::LOG_TABLE;

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table} (user_id, webinar_id, seconds_watched, viewed_at, session_key) VALUES (%d, %d, %d, %s, %s)
                ON DUPLICATE KEY UPDATE seconds_watched = VALUES(seconds_watched), viewed_at = VALUES(viewed_at)",
                $user_id,
                $webinar_id,
                $seconds,
                \gmdate( 'Y-m-d H:i:s' ),
                $session
            )
        );

        \wp_send_json_success();
    }

    private function maybe_fill_content_with_embed( $post_id, $vimeo_id, $webinar_date ) {
        if ( $this->filling_content ) {
            return;
        }
        if ( ! $vimeo_id ) {
            return;
        }
        $current = \get_post_field( 'post_content', $post_id );
        if ( $current && \trim( \wp_strip_all_tags( $current ) ) !== '' ) {
            return;
        }

        $this->filling_content = true;
        \wp_update_post( [
            'ID' => $post_id,
            'post_content' => $this->build_embed_content( $vimeo_id, $webinar_date ),
        ] );
        $this->filling_content = false;
    }

    private function build_embed_content( $vimeo_id, $webinar_date ) {
        $lines = [];
        if ( $webinar_date ) {
            $lines[] = '<p>' . \esc_html( \sprintf( \__( 'Recorded on %s', 'anchor-schema' ), \date_i18n( 'F j, Y', \strtotime( $webinar_date ) ) ) ) . '</p>';
        }
        $lines[] = '<div class="anchor-webinar-embed">';
        $lines[] = '<div id="anchor-webinar-player"></div>';
        $lines[] = '</div>';
        $lines[] = '<p><a href="' . \esc_url( 'https://vimeo.com/' . \rawurlencode( $vimeo_id ) ) . '" target="_blank" rel="noopener">' . \esc_html__( 'Watch on Vimeo', 'anchor-schema' ) . '</a></p>';
        return \implode( "\n", $lines );
    }

    private function get_logs( $limit, $offset, $webinar_id ) {
        global $wpdb;
        $table = $wpdb->prefix . self::LOG_TABLE;
        $where = '';
        $params = [];
        if ( $webinar_id ) {
            $where = 'WHERE webinar_id = %d';
            $params[] = $webinar_id;
        }
        $params[] = $limit;
        $params[] = $offset;

        $sql = "SELECT * FROM {$table} {$where} ORDER BY viewed_at DESC LIMIT %d OFFSET %d";
        return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
    }

    private function get_logs_count( $webinar_id ) {
        global $wpdb;
        $table = $wpdb->prefix . self::LOG_TABLE;
        if ( $webinar_id ) {
            return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE webinar_id = %d", $webinar_id ) );
        }
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
    }

    public function maybe_create_table() {
        $installed = \get_option( 'anchor_webinar_db_version' );
        if ( $installed === self::DB_VERSION ) {
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . self::LOG_TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            webinar_id BIGINT UNSIGNED NOT NULL,
            seconds_watched INT UNSIGNED NOT NULL DEFAULT 0,
            viewed_at DATETIME NOT NULL,
            session_key VARCHAR(100) NOT NULL,
            PRIMARY KEY  (id),
            KEY webinar_id (webinar_id),
            KEY user_id (user_id),
            UNIQUE KEY session_key (session_key)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        \dbDelta( $sql );
        \update_option( 'anchor_webinar_db_version', self::DB_VERSION );
    }

    private function get_settings() {
        $defaults = [
            'vimeo_api_key' => '',
        ];
        $settings = \get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $settings ) ) {
            $settings = [];
        }
        return \wp_parse_args( $settings, $defaults );
    }
}
