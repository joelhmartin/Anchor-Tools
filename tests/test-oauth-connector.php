<?php
/**
 * OAuth connector primitive + secret box. All HTTP mocked via pre_http_request.
 */
class Test_OAuth_Connector extends WP_UnitTestCase {

    private $calls = array();
    private $responder;

    public function set_up() {
        parent::set_up();
        $this->calls     = array();
        $this->responder = null;
        add_filter( 'pre_http_request', array( $this, 'mock_http' ), 10, 3 );
        $this->cleanup_state();
    }

    public function tear_down() {
        remove_filter( 'pre_http_request', array( $this, 'mock_http' ), 10 );
        $this->cleanup_state();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    private function cleanup_state() {
        foreach ( array( 'google', 'microsoft' ) as $p ) {
            delete_option( 'anchor_connector_' . $p );
            delete_transient( 'anchor_oauth_at_' . $p );
        }
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_anchor\_oauth\_state\_%' OR option_name LIKE '\_transient\_timeout\_anchor\_oauth\_state\_%'" );
    }

    public function mock_http( $pre, $args, $url ) {
        $this->calls[] = array( 'method' => $args['method'], 'url' => $url, 'body' => $args['body'], 'headers' => $args['headers'] );
        $r = is_callable( $this->responder ) ? call_user_func( $this->responder, $url, $args ) : null;
        if ( null === $r ) {
            $r = array( 200, array() );
        }
        return array(
            'headers'  => array(),
            'body'     => wp_json_encode( $r[1] ),
            'response' => array( 'code' => $r[0], 'message' => '' ),
            'cookies'  => array(),
        );
    }

    private function admin() {
        $id = self::factory()->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $id );
        return $id;
    }

    private function google() {
        $c = Anchor_OAuth_Connector::get( 'google' );
        $c->save_app_credentials( 'cid-123', 'SECRET-VALUE-XYZ' );
        return $c;
    }

    private function token_responder( array $overrides = array() ) {
        $this->responder = function ( $url, $args ) use ( $overrides ) {
            if ( false !== strpos( $url, '/token' ) ) {
                return array( 200, array_merge( array(
                    'access_token'  => 'AT-1',
                    'refresh_token' => 'RT-SECRET-1',
                    'expires_in'    => 3600,
                    'scope'         => 'openid email',
                ), $overrides ) );
            }
            if ( false !== strpos( $url, 'userinfo' ) ) {
                return array( 200, array( 'email' => 'owner@example.com' ) );
            }
            return null;
        };
    }

    /** Run begin_authorize + callback; returns connector. */
    private function connect() {
        $uid = $this->admin();
        $c   = $this->google();
        $this->token_responder();
        $url = $c->begin_authorize( $uid );
        parse_str( wp_parse_url( $url, PHP_URL_QUERY ), $q );
        $req = new WP_REST_Request( 'GET', '/anchor-tools/v1/connectors/callback' );
        $req->set_param( 'state', $q['state'] );
        $req->set_param( 'code', 'the-code' );
        $res = Anchor_OAuth_Connector::handle_callback( $req );
        $this->assertNotWPError( $res );
        return $c;
    }

    private function token_posts() {
        return array_values( array_filter( $this->calls, function ( $c ) {
            return 'POST' === $c['method'] && false !== strpos( $c['url'], '/token' );
        } ) );
    }

    public function test_secret_box_round_trips_and_rejects_tampering() {
        $sealed = Anchor_Secret_Box::seal( 'hello secret' );
        $this->assertNotSame( 'hello secret', $sealed );
        $this->assertSame( 'hello secret', Anchor_Secret_Box::open( $sealed ) );
        $raw    = base64_decode( $sealed );
        $raw[ strlen( $raw ) - 1 ] = chr( ord( $raw[ strlen( $raw ) - 1 ] ) ^ 1 );
        $this->assertFalse( Anchor_Secret_Box::open( base64_encode( $raw ) ) );
        $this->assertFalse( Anchor_Secret_Box::open( 'not-base64!!' ) );
        $this->assertFalse( Anchor_Secret_Box::open( '' ) );
    }

    public function test_secrets_are_not_stored_in_plaintext() {
        $c = $this->connect();
        $raw = wp_json_encode( get_option( 'anchor_connector_google' ) );
        $this->assertStringNotContainsString( 'SECRET-VALUE-XYZ', $raw );
        $this->assertStringNotContainsString( 'RT-SECRET-1', $raw );
        $this->assertTrue( $c->has_app_credentials() );
        $this->assertTrue( $c->is_connected() );
    }

    public function test_authorize_url_has_pkce_state_and_provider_params() {
        $uid = $this->admin();
        $c   = $this->google();
        $url = $c->begin_authorize( $uid );
        $this->assertStringStartsWith( 'https://accounts.google.com/o/oauth2/v2/auth', $url );
        $this->assertStringContainsString( 'code_challenge_method=S256', $url );
        $this->assertStringContainsString( 'access_type=offline', $url );
        $this->assertStringContainsString( 'prompt=consent', $url );
        $this->assertStringContainsString( 'redirect_uri=' . rawurlencode( rest_url( 'anchor-tools/v1/connectors/callback' ) ), $url );
        parse_str( wp_parse_url( $url, PHP_URL_QUERY ), $q );
        $this->assertSame( 'openid email https://www.googleapis.com/auth/spreadsheets https://www.googleapis.com/auth/drive', $q['scope'] );
        $st = get_transient( 'anchor_oauth_state_' . $q['state'] );
        $this->assertSame( 'google', $st['provider'] );
        $this->assertSame( $uid, $st['user_id'] );
        $this->assertNotEmpty( $st['verifier'] );
    }

    public function test_callback_rejects_unknown_state_and_makes_no_http() {
        $this->admin();
        $this->google();
        $req = new WP_REST_Request( 'GET', '/anchor-tools/v1/connectors/callback' );
        $req->set_param( 'state', 'bogus' );
        $req->set_param( 'code', 'x' );
        $res = Anchor_OAuth_Connector::handle_callback( $req );
        $this->assertWPError( $res );
        $this->assertCount( 0, $this->calls );
    }

    public function test_callback_state_is_single_use() {
        $uid = $this->admin();
        $c   = $this->google();
        $this->token_responder();
        parse_str( wp_parse_url( $c->begin_authorize( $uid ), PHP_URL_QUERY ), $q );
        $mk = function () use ( $q ) {
            $r = new WP_REST_Request( 'GET', '/anchor-tools/v1/connectors/callback' );
            $r->set_param( 'state', $q['state'] );
            $r->set_param( 'code', 'c' );
            return $r;
        };
        $this->assertNotWPError( Anchor_OAuth_Connector::handle_callback( $mk() ) );
        $this->assertWPError( Anchor_OAuth_Connector::handle_callback( $mk() ) );
        $this->assertCount( 1, $this->token_posts() );
    }

    public function test_callback_rejects_user_without_manage_options() {
        $uid = self::factory()->user->create( array( 'role' => 'subscriber' ) );
        $c   = $this->google();
        $this->token_responder();
        parse_str( wp_parse_url( $c->begin_authorize( $uid ), PHP_URL_QUERY ), $q );
        $req = new WP_REST_Request( 'GET', '/anchor-tools/v1/connectors/callback' );
        $req->set_param( 'state', $q['state'] );
        $req->set_param( 'code', 'c' );
        $this->assertWPError( Anchor_OAuth_Connector::handle_callback( $req ) );
        $this->assertCount( 0, $this->calls );
        $this->assertFalse( $c->is_connected() );
    }

    public function test_callback_exchanges_code_and_stores_account() {
        $uid = $this->admin();
        $c   = $this->google();
        $this->token_responder( array( 'scope' => 'openid email https://www.googleapis.com/auth/drive' ) );
        parse_str( wp_parse_url( $c->begin_authorize( $uid ), PHP_URL_QUERY ), $q );
        $verifier = get_transient( 'anchor_oauth_state_' . $q['state'] )['verifier'];
        $req = new WP_REST_Request( 'GET', '/anchor-tools/v1/connectors/callback' );
        $req->set_param( 'state', $q['state'] );
        $req->set_param( 'code', 'the-code' );
        $res = Anchor_OAuth_Connector::handle_callback( $req );
        $this->assertInstanceOf( 'WP_REST_Response', $res );
        $this->assertSame( 302, $res->get_status() );
        $this->assertSame( admin_url( 'options-general.php?page=anchor-schema&tab=connectors&anchor_connected=google' ), $res->get_headers()['Location'] );
        $post = $this->token_posts()[0];
        $this->assertSame( 'authorization_code', $post['body']['grant_type'] );
        $this->assertSame( $verifier, $post['body']['code_verifier'] );
        $this->assertSame( 'the-code', $post['body']['code'] );
        $this->assertArrayNotHasKey( 'scope', $post['body'] );
        $this->assertSame( 'owner@example.com', $c->account() );
        $this->assertTrue( $c->has_scope( 'https://www.googleapis.com/auth/drive' ) );
        $this->assertFalse( $c->has_scope( 'https://www.googleapis.com/auth/spreadsheets' ) );
    }

    public function test_access_token_is_cached_until_near_expiry() {
        $c = $this->connect();
        $before = count( $this->token_posts() );
        $this->assertSame( 'AT-1', $c->access_token() );
        $this->assertSame( 'AT-1', $c->access_token() );
        $this->assertCount( $before, $this->token_posts(), 'connect cached the access token' );
        delete_transient( 'anchor_oauth_at_google' );
        $this->token_responder( array( 'access_token' => 'AT-2', 'refresh_token' => null ) );
        $this->assertSame( 'AT-2', $c->access_token() );
        $this->assertSame( 'AT-2', $c->access_token() );
        $this->assertCount( $before + 1, $this->token_posts() );
        $this->assertSame( 'refresh_token', end( $this->calls )['body']['grant_type'] ?? 'refresh_token' );
    }

    public function test_rotated_refresh_token_is_persisted() {
        $c = $this->connect();
        delete_transient( 'anchor_oauth_at_google' );
        $this->token_responder( array( 'access_token' => 'AT-2', 'refresh_token' => 'RT-ROTATED' ) );
        $c->access_token();
        $opt = get_option( 'anchor_connector_google' );
        $this->assertSame( 'RT-ROTATED', Anchor_Secret_Box::open( $opt['refresh_token'] ) );
        $this->assertStringNotContainsString( 'RT-ROTATED', wp_json_encode( $opt ) );
    }

    public function test_invalid_grant_marks_needs_reconnect() {
        $c = $this->connect();
        delete_transient( 'anchor_oauth_at_google' );
        $this->responder = function () {
            return array( 400, array( 'error' => 'invalid_grant', 'error_description' => 'Token revoked' ) );
        };
        $n = count( $this->calls );
        $r = $c->access_token();
        $this->assertWPError( $r );
        $this->assertTrue( $c->needs_reconnect() );
        $this->assertCount( $n + 1, $this->calls );
        $this->assertWPError( $c->access_token() );
        $this->assertCount( $n + 1, $this->calls, 'no HTTP while needs_reconnect' );
    }

    public function test_request_maps_google_and_graph_error_shapes() {
        $c = $this->connect();
        $this->responder = function () {
            return array( 403, array( 'error' => array( 'code' => 403, 'message' => 'The caller does not have permission' ) ) );
        };
        $r = $c->request( 'GET', 'https://sheets.googleapis.com/v4/spreadsheets/x' );
        $this->assertWPError( $r );
        $this->assertSame( 'anchor_oauth_http', $r->get_error_code() );
        $this->assertSame( 'The caller does not have permission', $r->get_error_message() );
        $this->assertSame( 403, $r->get_error_data()['status'] );
        $last = end( $this->calls );
        $this->assertSame( 'Bearer AT-1', $last['headers']['Authorization'] );

        $this->responder = function () {
            return array( 404, array( 'error' => array( 'code' => 'itemNotFound', 'message' => 'Item missing' ) ) );
        };
        $r = $c->request( 'GET', 'https://graph.microsoft.com/v1.0/x' );
        $this->assertSame( 'Item missing', $r->get_error_message() );
        $this->assertSame( 404, $r->get_error_data()['status'] );

        $this->responder = function () {
            return array( 200, array( 'ok' => 1 ) );
        };
        $this->assertSame( array( 'ok' => 1 ), $c->request( 'POST', 'https://x.test/y', array( 'a' => 1 ) ) );
    }

    public function test_microsoft_token_request_includes_scope_and_disconnect_clears() {
        $uid = $this->admin();
        $c   = Anchor_OAuth_Connector::get( 'microsoft' );
        $c->save_app_credentials( 'ms-id', 'ms-secret' );
        $this->responder = function ( $url ) {
            if ( false !== strpos( $url, '/token' ) ) {
                return array( 200, array( 'access_token' => 'M-AT', 'refresh_token' => 'M-RT', 'expires_in' => 3600, 'scope' => 'User.Read' ) );
            }
            return array( 200, array( 'mail' => null, 'userPrincipalName' => 'u@tenant.com' ) );
        };
        parse_str( wp_parse_url( $c->begin_authorize( $uid ), PHP_URL_QUERY ), $q );
        $req = new WP_REST_Request( 'GET', '/x' );
        $req->set_param( 'state', $q['state'] );
        $req->set_param( 'code', 'c' );
        Anchor_OAuth_Connector::handle_callback( $req );
        $this->assertSame( 'offline_access openid email User.Read Files.ReadWrite.All', $this->token_posts()[0]['body']['scope'] );
        $this->assertSame( 'u@tenant.com', $c->account() );
        $c->disconnect();
        $this->assertFalse( $c->is_connected() );
        $this->assertFalse( get_transient( 'anchor_oauth_at_microsoft' ) );
        $this->assertTrue( $c->has_app_credentials() );
    }

    public function test_blank_secret_keeps_existing() {
        $c = $this->google();
        $c->save_app_credentials( 'new-id', '' );
        $this->assertTrue( $c->has_app_credentials() );
        $this->assertSame( 'SECRET-VALUE-XYZ', Anchor_Secret_Box::open( get_option( 'anchor_connector_google' )['client_secret'] ) );
    }
}
