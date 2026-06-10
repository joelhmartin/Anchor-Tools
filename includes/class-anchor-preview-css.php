<?php
/**
 * Shared editor-preview CSS source. Harvests the live site's stylesheets +
 * inline head styles from a reference URL so module preview iframes resolve
 * theme variables, fonts and plugin CSS regardless of the active theme.
 *
 * Editor-preview only. Enqueues nothing on the front end.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Anchor_Preview_CSS {
    const OPTION_KEY   = 'anchor_preview_settings';
    const TRANSIENT    = 'anchor_preview_harvest';
    const CACHE_TTL    = HOUR_IN_SECONDS;
    const ASSET_VER    = '1.0.0';
    const NONCE_ACTION = 'anchor_preview_refresh';

    public function __construct() {
        add_filter( 'anchor_settings_tabs', [ $this, 'register_tab' ], 12 );
        add_action( 'admin_init',           [ $this, 'register_settings' ] );
        add_action( 'admin_init',           [ $this, 'migrate_legacy_urls' ] );
        add_action( 'wp_ajax_anchor_preview_refresh', [ $this, 'ajax_refresh' ] );
    }

    /* ---- settings ---- */

    private function settings() {
        $o = get_option( self::OPTION_KEY, [] );
        return [
            'reference_url'  => isset( $o['reference_url'] ) ? (string) $o['reference_url'] : '',
            'extra_css_urls' => isset( $o['extra_css_urls'] ) ? (string) $o['extra_css_urls'] : '',
        ];
    }

    private function reference_url() {
        $s   = $this->settings();
        $url = trim( $s['reference_url'] );
        return $url !== '' ? $url : home_url( '/' );
    }

    private function lines_to_urls( $text ) {
        $out = [];
        foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
            $line = trim( $line );
            if ( $line !== '' ) { $out[] = $line; }
        }
        return $out;
    }

    private function theme_fallback() {
        $urls   = [];
        $child  = get_stylesheet_uri();
        $parent = get_template_directory_uri() . '/style.css';
        if ( $child )  { $urls[] = $child; }
        if ( $parent && $parent !== $child ) { $urls[] = $parent; }
        return $urls;
    }

    /* ---- harvest ---- */

    private function harvest( $url ) {
        $empty = [ 'urls' => [], 'inline' => '', 'count' => 0, 'time' => time(), 'ok' => false ];
        $resp  = wp_remote_get( $url, [ 'timeout' => 10, 'redirection' => 3 ] );
        if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) {
            if ( class_exists( 'Anchor_Schema_Logger' ) ) {
                Anchor_Schema_Logger::log( 'preview_css_harvest_failed', [ 'url' => $url ] );
            }
            return $empty;
        }
        $html = (string) wp_remote_retrieve_body( $resp );
        $head = $html;
        if ( preg_match( '/<head\b[^>]*>(.*?)<\/head>/is', $html, $m ) ) { $head = $m[1]; }

        $urls = [];
        if ( preg_match_all( '/<link\b[^>]*>/i', $head, $links ) ) {
            foreach ( $links[0] as $tag ) {
                if ( ! preg_match( '/rel\s*=\s*["\']?[^"\'>]*stylesheet/i', $tag ) ) { continue; }
                if ( preg_match( '/href\s*=\s*["\']([^"\']+)["\']/i', $tag, $h ) ) {
                    $urls[] = $this->absolutize( html_entity_decode( $h[1] ), $url );
                }
            }
        }
        $inline = '';
        if ( preg_match_all( '/<style\b[^>]*>(.*?)<\/style>/is', $head, $styles ) ) {
            $inline = implode( "\n", $styles[1] );
        }
        $urls = array_values( array_unique( array_filter( $urls ) ) );
        return [ 'urls' => $urls, 'inline' => $inline, 'count' => count( $urls ), 'time' => time(), 'ok' => true ];
    }

    private function absolutize( $href, $base ) {
        $href = trim( $href );
        if ( $href === '' ) { return ''; }
        if ( preg_match( '#^https?://#i', $href ) ) { return $href; }
        if ( strpos( $href, '//' ) === 0 ) {
            $scheme = parse_url( $base, PHP_URL_SCHEME ) ?: 'https';
            return $scheme . ':' . $href;
        }
        $p = parse_url( $base );
        if ( empty( $p['scheme'] ) || empty( $p['host'] ) ) { return $href; }
        $origin = $p['scheme'] . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
        if ( strpos( $href, '/' ) === 0 ) { return $origin . $href; }
        $dir = isset( $p['path'] ) ? preg_replace( '#/[^/]*$#', '/', $p['path'] ) : '/';
        return $origin . $dir . $href;
    }

    private function cached_harvest( $force = false ) {
        if ( ! $force ) {
            $cached = get_transient( self::TRANSIENT );
            if ( is_array( $cached ) ) { return $cached; }
        }
        $data = $this->harvest( $this->reference_url() );
        set_transient( self::TRANSIENT, $data, self::CACHE_TTL );
        return $data;
    }

    /** Public payload for localization: merged harvest + theme fallback + global extras. */
    public function get_payload( $force = false ) {
        $h    = $this->cached_harvest( $force );
        $urls = is_array( $h['urls'] ?? null ) ? $h['urls'] : [];
        $urls = array_merge( $urls, $this->theme_fallback(), $this->lines_to_urls( $this->settings()['extra_css_urls'] ) );
        $urls = array_values( array_unique( array_filter( $urls ) ) );
        return [
            'urls'   => $urls,
            'inline' => is_string( $h['inline'] ?? null ) ? $h['inline'] : '',
            'time'   => (int) ( $h['time'] ?? 0 ),
            'count'  => (int) ( $h['count'] ?? 0 ),
        ];
    }

    /** Enqueue the shared preview glue + localized payload on a module edit screen. */
    public static function enqueue_for_admin() {
        static $done = false;
        if ( $done ) { return; }
        $done = true;
        wp_enqueue_script(
            'anchor-preview',
            Anchor_Asset_Loader::url( 'assets/anchor-preview.js' ),
            [ 'jquery' ], self::ASSET_VER, true
        );
        $instance = new self();
        $p        = $instance->get_payload();
        wp_localize_script( 'anchor-preview', 'ANCHOR_PREVIEW', [
            'urls'   => $p['urls'],
            'inline' => $p['inline'],
        ] );
    }

    /* ---- settings UI / migration / ajax ---- */

    public function register_settings() {
        register_setting( 'anchor_preview_group', self::OPTION_KEY, [
            'type'              => 'array',
            'sanitize_callback' => [ $this, 'sanitize_settings' ],
            'default'           => [],
        ] );
    }

    public function sanitize_settings( $input ) {
        $out = [];
        $ref = isset( $input['reference_url'] ) ? trim( (string) $input['reference_url'] ) : '';
        $out['reference_url'] = $ref === '' ? '' : esc_url_raw( $ref );
        $clean = [];
        foreach ( $this->lines_to_urls( $input['extra_css_urls'] ?? '' ) as $u ) { $clean[] = esc_url_raw( $u ); }
        $out['extra_css_urls'] = implode( "\n", array_filter( $clean ) );
        delete_transient( self::TRANSIENT ); // settings changed → re-harvest next preview
        return $out;
    }

    /** One-time, non-destructive copy of the old Blocks-tab URL list. */
    public function migrate_legacy_urls() {
        $cur = get_option( self::OPTION_KEY, [] );
        if ( ! empty( $cur['extra_css_urls'] ) ) { return; }
        $blocks = get_option( 'anchor_blocks_settings', [] );
        $legacy = isset( $blocks['preview_css_urls'] ) ? trim( (string) $blocks['preview_css_urls'] ) : '';
        if ( $legacy === '' ) { return; }
        $cur = is_array( $cur ) ? $cur : [];
        $cur['extra_css_urls'] = $legacy;
        update_option( self::OPTION_KEY, $cur, false );
    }

    public function ajax_refresh() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'forbidden', 403 ); }
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        $p = $this->get_payload( true );
        wp_send_json_success( [ 'count' => $p['count'], 'time' => $p['time'] ] );
    }

    public function register_tab( $tabs ) {
        $tabs['preview'] = [ 'label' => __( 'Preview', 'anchor-schema' ), 'callback' => [ $this, 'render_tab_content' ] ];
        return $tabs;
    }

    public function render_tab_content() {
        $s     = $this->settings();
        $h     = get_transient( self::TRANSIENT );
        $when  = ( is_array( $h ) && ! empty( $h['time'] ) )
            ? sprintf( '%s ago', human_time_diff( (int) $h['time'], time() ) ) : 'never';
        $count = ( is_array( $h ) && isset( $h['count'] ) ) ? (int) $h['count'] : 0;
        ?>
        <form method="post" action="options.php">
            <?php settings_fields( 'anchor_preview_group' ); ?>
            <h2><?php esc_html_e( 'Preview Stylesheets', 'anchor-schema' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Module editor previews load the live site\'s CSS so they resemble the front end. The reference URL below is fetched and its stylesheets (plus inline :root styles) are reused in every preview. Editor-only — nothing here affects published pages.', 'anchor-schema' ); ?></p>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="anchor_preview_reference_url"><?php esc_html_e( 'Reference URL', 'anchor-schema' ); ?></label></th>
                    <td>
                        <input type="url" class="regular-text" id="anchor_preview_reference_url"
                               name="<?php echo esc_attr( self::OPTION_KEY ); ?>[reference_url]"
                               value="<?php echo esc_attr( $s['reference_url'] ); ?>"
                               placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>">
                        <p class="description"><?php esc_html_e( 'Page to harvest CSS from. Defaults to your homepage.', 'anchor-schema' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="anchor_preview_extra_css_urls"><?php esc_html_e( 'Extra stylesheets', 'anchor-schema' ); ?></label></th>
                    <td>
                        <textarea id="anchor_preview_extra_css_urls" rows="4" class="large-text code"
                                  name="<?php echo esc_attr( self::OPTION_KEY ); ?>[extra_css_urls]"
                                  placeholder="https://example.com/extra.css"><?php echo esc_textarea( $s['extra_css_urls'] ); ?></textarea>
                        <p class="description"><?php esc_html_e( 'One URL per line, added on top of the harvested set.', 'anchor-schema' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Harvest status', 'anchor-schema' ); ?></th>
                    <td>
                        <p id="anchor-preview-status"><?php echo esc_html( sprintf( __( 'Last harvested: %1$s — %2$d stylesheets found.', 'anchor-schema' ), $when, $count ) ); ?></p>
                        <button type="button" class="button" id="anchor-preview-refresh"
                                data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>"><?php esc_html_e( 'Refresh now', 'anchor-schema' ); ?></button>
                        <script>
                        (function($){$('#anchor-preview-refresh').on('click',function(){
                          var $b=$(this).prop('disabled',true);
                          $.post(ajaxurl,{action:'anchor_preview_refresh',nonce:$b.data('nonce')})
                           .done(function(r){ if(r&&r.success){ $('#anchor-preview-status').text('Last harvested: just now — '+r.data.count+' stylesheets found.'); } })
                           .always(function(){ $b.prop('disabled',false); });
                        });})(jQuery);
                        </script>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <?php
    }
}
