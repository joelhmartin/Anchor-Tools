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
        }

        public static function register_routes() {
            register_rest_route( self::REST_NS, self::REST_ROUTE, array(
                'methods'             => 'GET',
                'callback'            => array( __CLASS__, 'handle_callback' ),
                'permission_callback' => '__return_true', // Browser redirect carries no REST nonce; state + capability check protect it.
            ) );
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

        private function client_secret() {
            $o = $this->opt();
            return empty( $o['client_secret'] ) ? '' : (string) Anchor_Secret_Box::open( $o['client_secret'] );
        }

        public function has_app_credentials() {
            $o = $this->opt();
            return ! empty( $o['client_id'] ) && '' !== $this->client_secret();
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
            $o        = $this->opt();
            set_transient( 'anchor_oauth_state_' . $state, array(
                'provider' => $this->provider,
                'verifier' => $verifier,
                'user_id'  => (int) $user_id,
            ), 600 );
            $params = array_merge( array(
                'client_id'             => $o['client_id'] ?? '',
                'redirect_uri'          => $this->callback_url(),
                'response_type'         => 'code',
                'scope'                 => $this->def['scopes'],
                'state'                 => $state,
                'code_challenge'        => rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ),
                'code_challenge_method' => 'S256',
            ), $this->def['auth_params'] );
            return $this->def['auth_url'] . '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
        }

        private static function redirect( $provider, $arg ) {
            $url = admin_url( 'options-general.php?page=anchor-schema&tab=connectors&' . $arg . '=' . rawurlencode( $provider ) );
            $res = new WP_REST_Response( null, 302 );
            $res->header( 'Location', $url );
            return $res;
        }

        public static function handle_callback( WP_REST_Request $req ) {
            $state = (string) $req->get_param( 'state' );
            $st    = '' === $state ? false : get_transient( 'anchor_oauth_state_' . $state );
            if ( ! is_array( $st ) || empty( $st['provider'] ) ) {
                return new WP_Error( 'anchor_oauth_state', __( 'This connection link is invalid or has expired. Start again from Settings > Anchor Tools > Connectors.', 'anchor-schema' ), array( 'status' => 400 ) );
            }
            delete_transient( 'anchor_oauth_state_' . $state ); // Single use.
            $conn = self::get( $st['provider'] );
            if ( ! $conn || ! user_can( (int) $st['user_id'], 'manage_options' ) ) {
                return new WP_Error( 'anchor_oauth_forbidden', __( 'You do not have permission to connect accounts.', 'anchor-schema' ), array( 'status' => 403 ) );
            }
            $code = (string) $req->get_param( 'code' );
            if ( $req->get_param( 'error' ) || '' === $code ) {
                return self::redirect( $conn->provider, 'anchor_connect_error' );
            }
            $tok = $conn->token_request( array(
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'redirect_uri'  => $conn->callback_url(),
                'code_verifier' => (string) $st['verifier'],
            ) );
            if ( is_wp_error( $tok ) || empty( $tok['refresh_token'] ) ) {
                return self::redirect( $conn->provider, 'anchor_connect_error' );
            }
            $o                    = $conn->opt();
            $o['refresh_token']   = Anchor_Secret_Box::seal( $tok['refresh_token'] );
            $o['scope']           = (string) ( $tok['scope'] ?? '' );
            $o['connected_at']    = time();
            $o['account']         = $conn->fetch_account( $tok['access_token'] );
            unset( $o['needs_reconnect'] );
            $conn->save_opt( $o );
            $conn->cache_access_token( $tok );
            return self::redirect( $conn->provider, 'anchor_connected' );
        }

        /* ---------- tokens ---------- */

        /** POST to the provider's (hard-coded) token endpoint. @return array|WP_Error */
        private function token_request( array $body ) {
            $o    = $this->opt();
            $body = array_merge( array(
                'client_id'     => $o['client_id'] ?? '',
                'client_secret' => $this->client_secret(),
            ), $body );
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
                $err = isset( $data['error'] ) && is_string( $data['error'] ) ? $data['error'] : 'token_error';
                return new WP_Error( 'anchor_oauth_token', $err, array( 'status' => $code, 'oauth_error' => $err ) );
            }
            return $data;
        }

        private function cache_access_token( array $tok ) {
            $ttl = max( 60, (int) ( $tok['expires_in'] ?? 3600 ) - 120 );
            set_transient( $this->at_key(), $tok['access_token'], $ttl );
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
                if ( is_array( $d ) && 'invalid_grant' === ( $d['oauth_error'] ?? '' ) ) {
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
            if ( 401 === $code ) {
                $this->mark_needs_reconnect();
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
