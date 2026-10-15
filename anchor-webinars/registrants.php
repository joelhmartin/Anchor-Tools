<?php
/**
 * Anchor Webinars — form registrants.
 *
 * Lets an Anchor Forms submission grant webinar access WITHOUT a WordPress account:
 *
 *  1. The form's outbound webhook (Anchor dashboard, ctm_forms.webhook_url/webhook_secret) POSTs
 *     each accepted submission here, signed. We store it as a registrant keyed by the
 *     submission id, matched to a webinar by the submitted `webinar` value (= webinar title).
 *  2. The form redirects to a page holding [anchor_webinar_registered], with
 *     `?r={submission_id}&webinar={webinar}` — the autoresponder links to the same page with `?r=`.
 *     The shortcode shows that webinar's player, tagged with the registrant key.
 *  3. player.js reports watch time with the key, so Webinar Analytics shows who watched what and
 *     how far — by name, with no login.
 *
 * The key is a server-issued UUID (unguessable) and carries no personal data, so it is safe in a
 * URL. Settings → Anchor Tools → Webinars shows the endpoint URL and the signing secret.
 */
namespace Anchor\Webinars;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Registrants {
    const TABLE         = 'anchor_webinar_registrants';
    const SECRET_OPTION = 'anchor_webinars_webhook_secret';
    const MAX_SKEW      = 300; // seconds a signed request stays valid

    private static $instance = null;
    /** @var Module */
    private $module;
    private $cache = [];

    public static function instance( $module = null ) {
        if ( null === self::$instance ) {
            self::$instance = new self( $module ? $module : Module::instance() );
        }
        return self::$instance;
    }

    private function __construct( $module ) {
        $this->module = $module;
        \add_action( 'rest_api_init', [ $this, 'register_route' ] );
        \add_shortcode( 'anchor_webinar_registered', [ $this, 'render_registered' ] );
        \add_action( 'admin_init', [ $this, 'register_settings_section' ], 20 );
    }

    public static function schema_sql( $prefix, $charset ) {
        $table = $prefix . self::TABLE;
        return "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            registrant_key VARCHAR(64) NOT NULL,
            webinar_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            webinar_label VARCHAR(255) NOT NULL DEFAULT '',
            first_name VARCHAR(100) NOT NULL DEFAULT '',
            last_name VARCHAR(100) NOT NULL DEFAULT '',
            email VARCHAR(190) NOT NULL DEFAULT '',
            practice VARCHAR(190) NOT NULL DEFAULT '',
            payload LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY registrant_key (registrant_key),
            KEY webinar_id (webinar_id),
            KEY email (email)
        ) {$charset};";
    }

    /** A registrant key as accepted from a URL/beacon: UUID-like, else ''. */
    public static function clean_key( $value ) {
        $value = \trim( (string) $value );
        return \preg_match( '/^[A-Za-z0-9-]{8,64}$/', $value ) ? $value : '';
    }

    public static function format_duration( $seconds ) {
        $seconds = \max( 0, (int) $seconds );
        return $seconds >= 3600
            ? \sprintf( '%d:%02d:%02d', \intdiv( $seconds, 3600 ), \intdiv( $seconds % 3600, 60 ), $seconds % 60 )
            : \sprintf( '%d:%02d', \intdiv( $seconds, 60 ), $seconds % 60 );
    }

    public static function percent( $watched, $duration ) {
        return $duration > 0 ? \min( 100, (int) \round( 100 * $watched / $duration ) ) . '%' : '—';
    }

    /** The webhook signing secret, generated on first use. */
    public function secret() {
        $secret = (string) \get_option( self::SECRET_OPTION, '' );
        if ( $secret === '' ) {
            $secret = \wp_generate_password( 48, false, false );
            \add_option( self::SECRET_OPTION, $secret, '', false );
        }
        return $secret;
    }

    public function endpoint_url() {
        return \rest_url( 'anchor-webinars/v1/registrations' );
    }

    /* ── Webhook receiver ─────────────────────────────────────────────── */

    public function register_route() {
        \register_rest_route( 'anchor-webinars/v1', '/registrations', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_webhook' ],
            'permission_callback' => '__return_true', // authenticated by the HMAC signature below
        ] );
    }

    public function handle_webhook( \WP_REST_Request $request ) {
        $body = (string) $request->get_body();
        $ts   = (int) $request->get_header( 'x_anchor_timestamp' );
        $sig  = (string) $request->get_header( 'x_anchor_signature' );

        $expected = 'sha256=' . \hash_hmac( 'sha256', $ts . '.' . $body, $this->secret() );
        if ( ! $ts || $sig === '' || ! \hash_equals( $expected, $sig ) ) {
            return new \WP_REST_Response( [ 'error' => 'bad_signature' ], 401 );
        }
        if ( \abs( \time() - $ts ) > self::MAX_SKEW ) {
            return new \WP_REST_Response( [ 'error' => 'stale' ], 401 );
        }

        $data = \json_decode( $body, true );
        $key  = self::clean_key( $data['submission_id'] ?? '' );
        if ( ! \is_array( $data ) || $key === '' ) {
            return new \WP_REST_Response( [ 'error' => 'invalid_payload' ], 400 );
        }
        $fields = \is_array( $data['fields'] ?? null ) ? $data['fields'] : [];
        $label  = \sanitize_text_field( (string) ( $fields['webinar'] ?? '' ) );
        $webinar_id = $this->match_webinar( $label );

        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}" . self::TABLE . " (registrant_key, webinar_id, webinar_label, first_name, last_name, email, practice, payload, created_at)
             VALUES (%s, %d, %s, %s, %s, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE webinar_id = VALUES(webinar_id), webinar_label = VALUES(webinar_label)",
            $key,
            $webinar_id,
            $label,
            \sanitize_text_field( (string) ( $fields['first_name'] ?? '' ) ),
            \sanitize_text_field( (string) ( $fields['last_name'] ?? '' ) ),
            \sanitize_email( (string) ( $fields['email'] ?? '' ) ),
            \sanitize_text_field( (string) ( $fields['practice_name'] ?? $fields['practice'] ?? '' ) ),
            \wp_json_encode( [
                'fields'       => $fields,
                'attribution'  => \is_array( $data['attribution'] ?? null ) ? $data['attribution'] : [],
                'submitted_at' => (string) ( $data['submitted_at'] ?? '' ),
                'form_name'    => (string) ( $data['form_name'] ?? '' ),
            ] ),
            \gmdate( 'Y-m-d H:i:s' )
        ) );

        /** Fires after a form registrant is stored (e.g. to push to a CRM). */
        \do_action( 'anchor_webinar_registrant_saved', $key, $webinar_id, $fields, $data );

        return new \WP_REST_Response( [ 'ok' => true, 'webinar_id' => $webinar_id, 'matched' => $webinar_id > 0 ], 200 );
    }

    /**
     * Webinar post for a submitted label. Matches the webinar's title (case/punctuation-insensitive,
     * so "Let’s" == "Let's"), or its optional `_anchor_webinar_form_label` meta when the form's
     * option text differs from the page title.
     */
    public function match_webinar( $label ) {
        $needle = \sanitize_title( \html_entity_decode( (string) $label, ENT_QUOTES ) );
        if ( $needle === '' ) {
            return 0;
        }
        $posts = \get_posts( [
            'post_type'   => Module::CPT,
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields'      => 'ids',
        ] );
        foreach ( $posts as $id ) {
            $candidates = [ \get_the_title( $id ), (string) \get_post_meta( $id, '_anchor_webinar_form_label', true ) ];
            foreach ( $candidates as $c ) {
                if ( $c !== '' && \sanitize_title( \html_entity_decode( $c, ENT_QUOTES ) ) === $needle ) {
                    return (int) $id;
                }
            }
        }
        return 0;
    }

    public function get( $key ) {
        $key = self::clean_key( $key );
        if ( $key === '' ) {
            return null;
        }
        if ( ! \array_key_exists( $key, $this->cache ) ) {
            global $wpdb;
            $this->cache[ $key ] = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}" . self::TABLE . ' WHERE registrant_key = %s',
                $key
            ) );
        }
        return $this->cache[ $key ];
    }

    public function is_registered( $key, $webinar_id ) {
        $row = $this->get( $key );
        return $row && (int) $row->webinar_id === (int) $webinar_id;
    }

    /* ── Confirmation-page shortcode ──────────────────────────────────── */

    /**
     * [anchor_webinar_registered title_tag="h2" show_title="1"]shown when no webinar resolves[/anchor_webinar_registered]
     *
     * Resolves the webinar from `?r=<registrant key>` (personal link), else `?webinar=<title>`
     * (the form redirect carries both, because the webhook may land a moment after the redirect).
     */
    public function render_registered( $atts, $content = '' ) {
        $atts = \shortcode_atts( [ 'title_tag' => 'h2', 'show_title' => '1' ], $atts, 'anchor_webinar_registered' );
        $key  = self::clean_key( \wp_unslash( $_GET['r'] ?? '' ) );
        $reg  = $this->get( $key );

        $webinar_id = $reg && $reg->webinar_id ? (int) $reg->webinar_id : 0;
        if ( ! $webinar_id ) {
            $webinar_id = $this->match_webinar( \sanitize_text_field( \wp_unslash( $_GET['webinar'] ?? '' ) ) );
        }

        $access  = $webinar_id ? \get_post_meta( $webinar_id, '_anchor_webinar_access', true ) : '';
        $allowed = $webinar_id && ( $access === '' || $access === 'public' || $this->is_registered( $key, $webinar_id ) || $this->module->can_user_access( $webinar_id ) );
        if ( ! $allowed || ! $this->module->video_source( $webinar_id ) ) {
            return \do_shortcode( (string) $content );
        }

        $tag = \in_array( $atts['title_tag'], [ 'h1', 'h2', 'h3', 'h4', 'p' ], true ) ? $atts['title_tag'] : 'h2';
        $out = '<div class="anchor-webinar-registered" data-webinar-title="' . \esc_attr( \get_the_title( $webinar_id ) ) . '">';
        if ( $atts['show_title'] !== '0' ) {
            $out .= \sprintf( '<%1$s class="anchor-webinar-registered__title">%2$s</%1$s>', $tag, \esc_html( \get_the_title( $webinar_id ) ) );
        }
        $out .= $this->module->render_player( $webinar_id, '', $key );
        $out .= '</div>';
        return $out;
    }

    /* ── Admin ─────────────────────────────────────────────────────────── */

    public function register_settings_section() {
        \add_settings_section( 'anchor_webinars_form_registrations', \__( 'Form Registrations', 'anchor-schema' ), function () {
            echo '<p>' . \esc_html__( 'Let an Anchor Forms form grant webinar access without an account. Set these on the form (webhook URL + secret). The form\'s "webinar" field must match a webinar title.', 'anchor-schema' ) . '</p>';
            echo '<p><strong>' . \esc_html__( 'Webhook URL', 'anchor-schema' ) . ':</strong> <code>' . \esc_html( $this->endpoint_url() ) . '</code></p>';
            echo '<p><strong>' . \esc_html__( 'Signing secret', 'anchor-schema' ) . ':</strong> <code>' . \esc_html( $this->secret() ) . '</code></p>';
            echo '<p class="description">' . \esc_html__( 'Confirmation page: add [anchor_webinar_registered] and redirect the form to it with ?r={submission_id}&webinar={webinar}.', 'anchor-schema' ) . '</p>';
        }, 'anchor_webinars_settings' );
    }

    /** Registrations table on the Webinar Analytics screen: who registered, and how much they watched. */
    public function render_report( $webinar_filter = 0 ) {
        global $wpdb;
        $reg  = $wpdb->prefix . self::TABLE;
        $logs = $wpdb->prefix . Module::LOG_TABLE;
        $where = $webinar_filter ? $wpdb->prepare( 'WHERE r.webinar_id = %d', $webinar_filter ) : '';
        $rows = $wpdb->get_results(
            "SELECT r.*, COALESCE(SUM(l.seconds_watched), 0) AS watched, COALESCE(MAX(l.duration_seconds), 0) AS duration, MAX(l.viewed_at) AS last_view
             FROM {$reg} r LEFT JOIN {$logs} l ON l.registrant_key = r.registrant_key AND l.webinar_id = r.webinar_id
             {$where} GROUP BY r.id ORDER BY r.created_at DESC LIMIT 200"
        );

        echo '<h2>' . \esc_html__( 'Form registrations', 'anchor-schema' ) . '</h2>';
        echo '<table class="widefat striped" style="margin-bottom:24px"><thead><tr>';
        foreach ( [ 'Name', 'Email', 'Practice', 'Webinar', 'Registered', 'Watched', 'Watched %', 'Last viewed', 'Source' ] as $h ) {
            echo '<th>' . \esc_html__( $h, 'anchor-schema' ) . '</th>';
        }
        echo '</tr></thead><tbody>';
        if ( ! $rows ) {
            echo '<tr><td colspan="9">' . \esc_html__( 'No form registrations yet.', 'anchor-schema' ) . '</td></tr>';
        }
        foreach ( (array) $rows as $r ) {
            $payload = \json_decode( (string) $r->payload, true );
            $attr    = \is_array( $payload['attribution'] ?? null ) ? $payload['attribution'] : [];
            $source  = \trim( ( $attr['utm_source'] ?? '' ) . ' / ' . ( $attr['utm_campaign'] ?? '' ), ' /' );
            \printf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                \esc_html( \trim( $r->first_name . ' ' . $r->last_name ) ),
                \esc_html( $r->email ),
                \esc_html( $r->practice ),
                \esc_html( $r->webinar_id ? \get_the_title( (int) $r->webinar_id ) : $r->webinar_label . ' (unmatched)' ),
                \esc_html( \get_date_from_gmt( $r->created_at, 'M j, Y g:i a' ) ),
                \esc_html( (int) $r->watched ? self::format_duration( (int) $r->watched ) : '—' ),
                \esc_html( self::percent( (int) $r->watched, (int) $r->duration ) ),
                \esc_html( $r->last_view ? \get_date_from_gmt( $r->last_view, 'M j, Y g:i a' ) : '—' ),
                \esc_html( $source !== '' ? $source : '—' )
            );
        }
        echo '</tbody></table>';
    }
}
