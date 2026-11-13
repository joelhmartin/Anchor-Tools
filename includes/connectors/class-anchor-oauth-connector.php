<?php
/**
 * Generic per-site OAuth connector (authorization code + PKCE). One instance per provider
 * defined in anchor_oauth_providers(). Tokens and client secrets are sealed with Anchor_Secret_Box.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Anchor_OAuth_Connector' ) ) {
    class Anchor_OAuth_Connector {
        const REST_NS    = 'anchor-tools/v1';
        const REST_ROUTE = '/connectors/callback';
        const RELAY_ROUTE = '/connectors/relay';
        /** Anchor's relay: the one redirect URI registered with Google/Microsoft for every site. */
        const DEFAULT_RELAY_URL = 'https://anchoryourpractice.com/wp-json/anchor-tools/v1/connectors/relay';

        private $provider;
        private $def;
        private static $instances = array();

        private function __construct( $provider, array $def ) {
            $this->provider = $provider;
            $this->def      = $def;
        }

        /** @return Anchor_OAuth_Connector|null */
        public static function get( $provider ) {
            $defs = anchor_oauth_providers();
            if ( ! isset( $defs[ $provider ] ) ) {
                return null;
            }
            if ( ! isset( self::$instances[ $provider ] ) ) {
                self::$instances[ $provider ] = new self( $provider, $defs[ $provider ] );
            }
            return self::$instances[ $provider ];
        }

        public static function bootstrap() {
            add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
            add_filter( 'rest_pre_serve_request', array( __CLASS__, 'serve_html_page' ), 10, 4 );
        }

        public static function register_routes() {
            register_rest_route( self::REST_NS, self::REST_ROUTE, array(
                'methods'             => 'GET',
                'callback'            => array( __CLASS__, 'handle_callback' ),
                'permission_callback' => '__return_true', // Browser redirect carries no REST nonce; state + capability check protect it.
            ) );
            register_rest_route( self::REST_NS, self::RELAY_ROUTE, array(
                'methods'             => 'GET',
                'callback'            => array( __CLASS__, 'handle_relay' ),
                'permission_callback' => '__return_true', // Forwards only states signed with the shared relay key.
            ) );
        }

        /* ---------- relay ---------- */

        /**
         * Shared relay key (wp-config ANCHOR_OAUTH_RELAY_KEY, same on every site). Signs the
         * return address inside state so the relay forwards only logins our own sites started.
         */
        private static function relay_key() {
            $v = defined( 'ANCHOR_OAUTH_RELAY_KEY' ) ? (string) constant( 'ANCHOR_OAUTH_RELAY_KEY' ) : '';
            return trim( (string) apply_filters( 'anchor_connector_relay_key', $v ) );
        }

        private static function relay_url() {
            $v = defined( 'ANCHOR_OAUTH_RELAY_URL' ) ? (string) constant( 'ANCHOR_OAUTH_RELAY_URL' ) : self::DEFAULT_RELAY_URL;
            return trim( (string) apply_filters( 'anchor_connector_relay_url', $v ) );
        }

        /** True when logins go through the relay (shared app + relay key), so no per-site redirect URI is needed. */
        public function uses_relay() {
            return $this->uses_shared_app() && '' !== self::relay_key() && '' !== self::relay_url();
        }

        /** The redirect_uri sent to the provider (authorize and token exchange must match). */
        public function redirect_uri() {
            return $this->uses_relay() ? self::relay_url() : $this->callback_url();
        }

        private static function b64url( $s ) {
            return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' );
        }

        private static function sign_state( $nonce, $return_url ) {
            $body = $nonce . '.' . self::b64url( $return_url );
            return $body . '.' . self::b64url( hash_hmac( 'sha256', $body, self::relay_key(), true ) );
        }

        /** @return string|false The verified return URL, or false. */
        private static function verify_state( $state ) {
            $parts = explode( '.', (string) $state );
            if ( 3 !== count( $parts ) || '' === self::relay_key() ) {
                return false;
            }
            $want = self::b64url( hash_hmac( 'sha256', $parts[0] . '.' . $parts[1], self::relay_key(), true ) );
            if ( ! hash_equals( $want, $parts[2] ) ) {
                return false;
            }
            $url = base64_decode( strtr( $parts[1], '-_', '+/' ), true );
            if ( ! is_string( $url ) ) {
                return false;
            }
            $u = wp_parse_url( $url );
            // Only ever a site's own connector callback (pretty or ?rest_route= form).
            $route = self::REST_NS . self::REST_ROUTE;
            $ok_path = isset( $u['path'] ) && '/' . $route === substr( $u['path'], -strlen( '/' . $route ) );
            $ok_qs   = isset( $u['query'] ) && false !== strpos( rawurldecode( $u['query'] ), 'rest_route=/' . $route );
            if ( empty( $u['host'] ) || ! in_array( $u['scheme'] ?? '', array( 'https', 'http' ), true ) || ! ( $ok_path || $ok_qs ) ) {
                return false;
            }
            return $url;
        }

        /** GET /connectors/relay: verify the signed state, then forward the provider's reply to the site that asked. */
        public static function handle_relay( WP_REST_Request $req ) {
            $state  = (string) $req->get_param( 'state' );
            $return = self::verify_state( $state );
            if ( false === $return ) {
                return self::error_page( __( 'This sign-in link is invalid or was not started by an Anchor site.', 'anchor-schema' ), 400 );
            }
            $args = array( 'state' => $state );
            foreach ( array( 'code', 'error', 'error_description' ) as $k ) {
                $v = $req->get_param( $k );
                if ( is_string( $v ) && '' !== $v ) {
                    $args[ $k ] = $v;
                }
            }
            $res = new WP_REST_Response( null, 302 );
            $res->header( 'Location', $return . ( false === strpos( $return, '?' ) ? '?' : '&' ) . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 ) );
            return $res;
        }

        /** The nonce part of a (possibly relay-signed) state. */
        private static function state_nonce( $state ) {
            $nonce = explode( '.', (string) $state )[0];
            return preg_match( '/^[a-f0-9]{64}$/', $nonce ) ? $nonce : '';
        }

        /** Plain HTML error page (status carried on the response; served raw by serve_html_page). */
        private static function error_page( $message, $status ) {
            $back = admin_url( 'options-general.php?page=anchor-schema&tab=connectors' );
            $html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'
                . esc_html__( 'Connection problem', 'anchor-schema' ) . '</title></head><body style="font-family:sans-serif;max-width:32em;margin:4em auto;padding:0 1em"><h1>'
                . esc_html__( 'Connection problem', 'anchor-schema' ) . '</h1><p>' . esc_html( $message ) . '</p><p><a href="'
                . esc_url( $back ) . '">' . esc_html__( 'Back to Connectors', 'anchor-schema' ) . '</a></p></body></html>';
            $res  = new WP_REST_Response( $html, $status );
            $res->header( 'Content-Type', 'text/html; charset=utf-8' );
            $res->header( 'X-Anchor-Html', '1' );
            return $res;
        }

        /** rest_pre_serve_request: emit our HTML error pages instead of JSON-encoding them. */
        public static function serve_html_page( $served, $result, $request, $server ) {
            if ( $result instanceof WP_REST_Response && $result->get_headers() && ! empty( $result->get_headers()['X-Anchor-Html'] ) ) {
                echo $result->get_data(); // Built by error_page(); every dynamic part is escaped.
                return true;
            }
            return $served;
        }

        /* ---------- stored state ---------- */

        private function opt_name() {
            return 'anchor_connector_' . $this->provider;
        }

        private function opt() {
            $o = get_option( $this->opt_name(), array() );
            return is_array( $o ) ? $o : array();
        }

        private function save_opt( array $o ) {
            update_option( $this->opt_name(), $o, false );
        }

        private function at_key() {
            return 'anchor_oauth_at_' . $this->provider;
        }

        public function label() {
            return $this->def['label'];
        }

        public function save_app_credentials( $client_id, $client_secret ) {
            $o              = $this->opt();
            $o['client_id'] = trim( (string) $client_id );
            $client_secret  = trim( (string) $client_secret );
            if ( '' !== $client_secret ) {
                $o['client_secret'] = Anchor_Secret_Box::seal( $client_secret );
            }
            $this->save_opt( $o );
        }

        /**
         * Anchor's shared app: wp-config.php constants ANCHOR_{PROVIDER}_CLIENT_ID and
         * ANCHOR_{PROVIDER}_CLIENT_SECRET. Kept out of the plugin because the repo and
         * release ZIP are public; bin/push-connector-credentials.sh writes them to sites.
         */
        private function shared( $part ) {
            $name = 'ANCHOR_' . strtoupper( $this->provider ) . '_CLIENT_' . $part;
            $value = defined( $name ) ? (string) constant( $name ) : '';
            /** Filters a shared-app credential ($part is 'ID' or 'SECRET'); also the test seam. */
            return trim( (string) apply_filters( 'anchor_connector_shared_credential', $value, $this->provider, $part ) );
        }

        /** True when wp-config.php supplies the app, so the site needs no credentials of its own. */
        public function uses_shared_app() {
            return '' !== $this->shared( 'ID' );
        }

        public function client_id() {
            if ( $this->uses_shared_app() ) {
                return $this->shared( 'ID' );
            }
            $o = $this->opt();
            return isset( $o['client_id'] ) ? (string) $o['client_id'] : '';
        }

        private function client_secret() {
            if ( $this->uses_shared_app() ) {
                return $this->shared( 'SECRET' );
            }
            $o = $this->opt();
            return empty( $o['client_secret'] ) ? '' : (string) Anchor_Secret_Box::open( $o['client_secret'] );
        }

        public function has_app_credentials() {
            return '' !== $this->client_id() && ( '' !== $this->client_secret() || ! empty( $this->def['secret_optional'] ) );
        }

        public function is_connected() {
            $o = $this->opt();
            return ! empty( $o['refresh_token'] );
        }

        public function needs_reconnect() {
            $o = $this->opt();
            return ! empty( $o['needs_reconnect'] );
        }

        public function account() {
            $o = $this->opt();
            return isset( $o['account'] ) ? (string) $o['account'] : '';
        }

        public function has_scope( $scope ) {
            $o = $this->opt();
            return in_array( $scope, preg_split( '/[\s,]+/', (string) ( $o['scope'] ?? '' ), -1, PREG_SPLIT_NO_EMPTY ), true );
        }

        public function callback_url() {
            return rest_url( self::REST_NS . self::REST_ROUTE );
        }

        public function disconnect() {
            $o = $this->opt();
            unset( $o['refresh_token'], $o['account'], $o['connected_at'], $o['needs_reconnect'], $o['scope'] );
            $this->save_opt( $o );
            delete_transient( $this->at_key() );
        }

        /* ---------- authorize / callback ---------- */

        public function begin_authorize( $user_id ) {
            $state    = bin2hex( random_bytes( 32 ) );
            $verifier = rtrim( strtr( base64_encode( random_bytes( 48 ) ), '+/', '-_' ), '=' );
            $sent     = $this->uses_relay() ? self::sign_state( $state, $this->callback_url() ) : $state;
            set_transient( 'anchor_oauth_state_' . $state, array(
                'provider' => $this->provider,
                'verifier' => $verifier,
                'user_id'  => (int) $user_id,
            ), 600 );
            $params = array_merge( array(
                'client_id'             => $this->client_id(),
                'redirect_uri'          => $this->redirect_uri(),
                'response_type'         => 'code',
                'scope'                 => $this->def['scopes'],
                'state'                 => $sent,
                'code_challenge'        => rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ),
                'code_challenge_method' => 'S256',
            ), $this->def['auth_params'] );
            return $this->def['auth_url'] . '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
        }

        /** @param string $error_code OAuth error code (e.g. invalid_client); only ever sanitize_key()'d, never a description. */
        private static function redirect( $provider, $arg, $error_code = '' ) {
            $url  = admin_url( 'options-general.php?page=anchor-schema&tab=connectors&' . $arg . '=' . rawurlencode( $provider ) );
            $code = substr( sanitize_key( (string) $error_code ), 0, 64 );
            if ( '' !== $code ) {
                $url .= '&anchor_connect_error_code=' . rawurlencode( $code );
            }
            $res = new WP_REST_Response( null, 302 );
            $res->header( 'Location', $url );
            return $res;
        }

        public static function handle_callback( WP_REST_Request $req ) {
            $state = self::state_nonce( $req->get_param( 'state' ) );
            $st    = '' === $state ? false : get_transient( 'anchor_oauth_state_' . $state );
            if ( ! is_array( $st ) || empty( $st['provider'] ) ) {
                return self::error_page( __( 'This connection link is invalid or has expired. Start again from Settings > Anchor Tools > Connectors.', 'anchor-schema' ), 400 );
            }
            delete_transient( 'anchor_oauth_state_' . $state ); // Single use.
            $conn = self::get( $st['provider'] );
            if ( ! $conn || ! user_can( (int) $st['user_id'], 'manage_options' ) ) {
                return self::error_page( __( 'You do not have permission to connect accounts.', 'anchor-schema' ), 403 );
            }
            $code = (string) $req->get_param( 'code' );
            if ( $req->get_param( 'error' ) || '' === $code ) {
                return self::redirect( $conn->provider, 'anchor_connect_error', (string) $req->get_param( 'error' ) );
            }
            $tok = $conn->token_request( array(
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'redirect_uri'  => $conn->redirect_uri(),
                'code_verifier' => (string) $st['verifier'],
            ) );
            if ( is_wp_error( $tok ) ) {
                $d = $tok->get_error_data();
                return self::redirect( $conn->provider, 'anchor_connect_error', is_array( $d ) && ! empty( $d['oauth_error'] ) ? $d['oauth_error'] : $tok->get_error_code() );
            }
            if ( empty( $tok['refresh_token'] ) ) {
                return self::redirect( $conn->provider, 'anchor_connect_error', 'no_refresh_token' );
            }
            $required = (string) ( $conn->def['required_scope'] ?? '' );
            if ( '' !== $required && ! in_array( $required, preg_split( '/[\s,]+/', (string) ( $tok['scope'] ?? '' ), -1, PREG_SPLIT_NO_EMPTY ), true ) ) {
                return self::redirect( $conn->provider, 'anchor_connect_error', 'missing_scope' );
            }
            $o                    = $conn->opt();
            $o['refresh_token']   = Anchor_Secret_Box::seal( $tok['refresh_token'] );
            $o['scope']           = (string) ( $tok['scope'] ?? '' );
            $o['connected_at']    = time();
            $o['account']         = $conn->fetch_account( $tok['access_token'] );
            unset( $o['needs_reconnect'] );
            $conn->save_opt( $o );
            $conn->cache_access_token( $tok );
            /** Fires after a provider connects; lets features resume work that was waiting for it. */
            do_action( 'anchor_connector_connected', $conn->provider );
            return self::redirect( $conn->provider, 'anchor_connected' );
        }

        /* ---------- tokens ---------- */

        /** POST to the provider's (hard-coded) token endpoint. @return array|WP_Error */
        private function token_request( array $body ) {
            $creds  = array( 'client_id' => $this->client_id() );
            $secret = $this->client_secret();
            if ( '' !== $secret ) {
                $creds['client_secret'] = $secret; // Omitted for a public client; PKCE proves the caller.
            }
            $body = array_merge( $creds, $body );
            if ( $this->def['token_scope'] ) {
                $body['scope'] = $this->def['scopes'];
            }
            $res = wp_remote_request( $this->def['token_url'], array(
                'method'  => 'POST',
                'timeout' => 20,
                'headers' => array( 'Accept' => 'application/json' ),
                'body'    => $body,
            ) );
            if ( is_wp_error( $res ) ) {
                return $res;
            }
            $code = (int) wp_remote_retrieve_response_code( $res );
            $data = json_decode( wp_remote_retrieve_body( $res ), true );
            $data = is_array( $data ) ? $data : array();
            if ( $code < 200 || $code >= 300 || empty( $data['access_token'] ) ) {
                $err  = isset( $data['error'] ) && is_string( $data['error'] ) ? $data['error'] : 'token_error';
                $desc = isset( $data['error_description'] ) && is_string( $data['error_description'] ) ? ': ' . sanitize_text_field( $data['error_description'] ) : '';
                return new WP_Error( 'anchor_oauth_token', $err . $desc, array( 'status' => $code, 'oauth_error' => $err ) );
            }
            return $data;
        }

        private function cache_access_token( array $tok ) {
            $ttl = max( 60, (int) ( $tok['expires_in'] ?? 3600 ) - 120 );
            // Sealed: without an object cache the transient lives in wp_options.
            set_transient( $this->at_key(), Anchor_Secret_Box::seal( $tok['access_token'] ), $ttl );
        }

        private function fetch_account( $access_token ) {
            $res = wp_remote_request( $this->def['account_url'], array(
                'method'  => 'GET',
                'timeout' => 15,
                'headers' => array( 'Authorization' => 'Bearer ' . $access_token, 'Accept' => 'application/json' ),
            ) );
            if ( is_wp_error( $res ) ) {
                return '';
            }
            $data = json_decode( wp_remote_retrieve_body( $res ), true );
            foreach ( $this->def['account_fields'] as $f ) {
                if ( is_array( $data ) && ! empty( $data[ $f ] ) && is_string( $data[ $f ] ) ) {
                    return sanitize_text_field( $data[ $f ] );
                }
            }
            return '';
        }

        private function mark_needs_reconnect() {
            $o                    = $this->opt();
            $o['needs_reconnect'] = true;
            $this->save_opt( $o );
            delete_transient( $this->at_key() );
        }

        /** @return string|WP_Error */
        public function access_token() {
            if ( $this->needs_reconnect() ) {
                return new WP_Error( 'anchor_oauth_reconnect', sprintf( /* translators: %s provider */ __( 'The %s connection needs to be reconnected.', 'anchor-schema' ), $this->label() ) );
            }
            $cached = get_transient( $this->at_key() );
            $cached = is_string( $cached ) && '' !== $cached ? Anchor_Secret_Box::open( $cached ) : false;
            if ( is_string( $cached ) && '' !== $cached ) {
                return $cached;
            }
            $o       = $this->opt();
            $refresh = empty( $o['refresh_token'] ) ? false : Anchor_Secret_Box::open( $o['refresh_token'] );
            if ( ! $refresh ) {
                if ( ! empty( $o['refresh_token'] ) ) {
                    $this->mark_needs_reconnect(); // Undecryptable (salts changed).
                }
                return new WP_Error( 'anchor_oauth_not_connected', sprintf( /* translators: %s provider */ __( '%s is not connected.', 'anchor-schema' ), $this->label() ) );
            }
            $tok = $this->token_request( array( 'grant_type' => 'refresh_token', 'refresh_token' => $refresh ) );
            if ( is_wp_error( $tok ) ) {
                $d = $tok->get_error_data();
                if ( is_array( $d ) && in_array( $d['oauth_error'] ?? '', array( 'invalid_grant', 'invalid_client', 'unauthorized_client' ), true ) ) {
                    $this->mark_needs_reconnect();
                }
                return $tok;
            }
            $o = $this->opt();
            if ( ! empty( $tok['refresh_token'] ) ) {
                $o['refresh_token'] = Anchor_Secret_Box::seal( $tok['refresh_token'] );
            }
            if ( ! empty( $tok['scope'] ) ) {
                $o['scope'] = (string) $tok['scope'];
            }
            $this->save_opt( $o );
            $this->cache_access_token( $tok );
            return $tok['access_token'];
        }

        /** @return array|WP_Error Decoded JSON ([] for an empty body). */
        public function request( $method, $url, $json_body = null, array $headers = array() ) {
            $res = $this->request_once( $method, $url, $json_body, $headers );
            if ( is_wp_error( $res ) && 'anchor_oauth_http' === $res->get_error_code() && 401 === ( $res->get_error_data()['status'] ?? 0 ) ) {
                // Stale/revoked access token: refresh once and retry once. A refresh failure marks needs_reconnect itself.
                delete_transient( $this->at_key() );
                return $this->request_once( $method, $url, $json_body, $headers );
            }
            return $res;
        }

        private function request_once( $method, $url, $json_body, array $headers ) {
            $token = $this->access_token();
            if ( is_wp_error( $token ) ) {
                return $token;
            }
            $headers = array_merge( array( 'Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json' ), $headers );
            $args    = array( 'method' => strtoupper( $method ), 'timeout' => 30, 'headers' => $headers );
            if ( null !== $json_body ) {
                $args['headers']['Content-Type'] = 'application/json';
                $args['body']                    = wp_json_encode( $json_body );
            }
            $res = wp_remote_request( $url, $args );
            if ( is_wp_error( $res ) ) {
                return $res;
            }
            $code = (int) wp_remote_retrieve_response_code( $res );
            $data = json_decode( wp_remote_retrieve_body( $res ), true );
            if ( $code >= 200 && $code < 300 ) {
                return is_array( $data ) ? $data : array();
            }
            $msg = '';
            if ( is_array( $data ) ) {
                if ( isset( $data['error']['message'] ) ) {
                    $msg = (string) $data['error']['message'];
                } elseif ( isset( $data['error_description'] ) ) {
                    $msg = (string) $data['error_description'];
                } elseif ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
                    $msg = $data['error'];
                }
            }
            if ( '' === $msg ) {
                $msg = sprintf( /* translators: %d HTTP status */ __( 'Request failed (HTTP %d).', 'anchor-schema' ), $code );
            }
            return new WP_Error( 'anchor_oauth_http', $msg, array( 'status' => $code ) );
        }
    }

    Anchor_OAuth_Connector::bootstrap();
}
