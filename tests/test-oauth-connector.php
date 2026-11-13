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
                    'scope'         => 'openid email https://www.googleapis.com/auth/spreadsheets',
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
        $this->assertInstanceOf( 'WP_REST_Response', $res );
        $this->assertSame( 400, $res->get_status() );
        $this->assertCount( 0, $this->calls );
    }

    public function test_unknown_state_serves_plain_html_page_with_link_back() {
        $res = Anchor_OAuth_Connector::handle_callback( $this->callback_req( 'bogus', 'x' ) );
        ob_start();
        $served = Anchor_OAuth_Connector::serve_html_page( false, $res, new WP_REST_Request(), rest_get_server() );
        $out    = ob_get_clean();
        $this->assertTrue( $served );
        $this->assertStringStartsWith( '<!doctype html>', $out );
        $this->assertStringContainsString( 'tab=connectors', $out );
        $this->assertStringNotContainsString( '{"', $out );
        $this->assertFalse( Anchor_OAuth_Connector::serve_html_page( false, new WP_REST_Response( array( 'a' => 1 ) ), new WP_REST_Request(), rest_get_server() ) );
    }

    private function callback_req( $state, $code, array $extra = array() ) {
        $r = new WP_REST_Request( 'GET', '/anchor-tools/v1/connectors/callback' );
        $r->set_param( 'state', $state );
        $r->set_param( 'code', $code );
        foreach ( $extra as $k => $v ) {
            $r->set_param( $k, $v );
        }
        return $r;
    }

    public function test_route_is_registered_for_get() {
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey( '/anchor-tools/v1/connectors/callback', $routes );
        $this->assertArrayHasKey( 'GET', $routes['/anchor-tools/v1/connectors/callback'][0]['methods'] );
    }

    public function test_callback_with_provider_error_redirects_and_consumes_state() {
        $uid = $this->admin();
        $c   = $this->google();
        parse_str( wp_parse_url( $c->begin_authorize( $uid ), PHP_URL_QUERY ), $q );
        $res = Anchor_OAuth_Connector::handle_callback( $this->callback_req( $q['state'], '', array( 'error' => 'access_denied' ) ) );
        $this->assertSame( 302, $res->get_status() );
        $this->assertStringContainsString( 'anchor_connect_error=google', $res->get_headers()['Location'] );
        $this->assertFalse( get_transient( 'anchor_oauth_state_' . $q['state'] ) );
        $this->assertCount( 0, $this->calls );
    }

    public function test_token_exchange_failure_passes_sanitized_oauth_error_code() {
        $uid = $this->admin();
        $c   = $this->google();
        parse_str( wp_parse_url( $c->begin_authorize( $uid ), PHP_URL_QUERY ), $q );
        $this->responder = function () {
            return array( 401, array( 'error' => 'invalid_client', 'error_description' => 'The OAuth client was not found. SECRET-VALUE-XYZ' ) );
        };
        $res = Anchor_OAuth_Connector::handle_callback( $this->callback_req( $q['state'], 'the-code' ) );
        $this->assertSame( 302, $res->get_status() );
        $loc = $res->get_headers()['Location'];
        $this->assertStringContainsString( 'anchor_connect_error=google', $loc );
        $this->assertStringContainsString( 'anchor_connect_error_code=invalid_client', $loc );
        $this->assertStringNotContainsString( 'SECRET', $loc );
        $this->assertStringNotContainsString( 'not%20found', $loc );
        $this->assertFalse( $c->is_connected() );
    }

    public function test_401_refreshes_and_retries_once() {
        $c = $this->connect();
        $n = 0;
        $this->responder = function ( $url ) use ( &$n ) {
            if ( false !== strpos( $url, '/token' ) ) {
                return array( 200, array( 'access_token' => 'AT-NEW', 'expires_in' => 3600 ) );
            }
            return 1 === ++$n ? array( 401, array( 'error' => array( 'message' => 'expired' ) ) ) : array( 200, array( 'ok' => 1 ) );
        };
        $this->assertSame( array( 'ok' => 1 ), $c->request( 'GET', 'https://sheets.googleapis.com/v4/x' ) );
        $this->assertFalse( $c->needs_reconnect() );
        $this->assertSame( 'Bearer AT-NEW', end( $this->calls )['headers']['Authorization'] );
    }

    public function test_401_on_retry_returns_error_without_marking() {
        $c = $this->connect();
        $this->responder = function ( $url ) {
            if ( false !== strpos( $url, '/token' ) ) {
                return array( 200, array( 'access_token' => 'AT-NEW', 'expires_in' => 3600 ) );
            }
            return array( 401, array( 'error' => array( 'message' => 'nope' ) ) );
        };
        $r = $c->request( 'GET', 'https://sheets.googleapis.com/v4/x' );
        $this->assertWPError( $r );
        $this->assertSame( 401, $r->get_error_data()['status'] );
        $this->assertFalse( $c->needs_reconnect() );
    }

    public function test_401_then_invalid_grant_marks_needs_reconnect() {
        $c = $this->connect();
        $this->responder = function ( $url ) {
            if ( false !== strpos( $url, '/token' ) ) {
                return array( 400, array( 'error' => 'invalid_grant' ) );
            }
            return array( 401, array( 'error' => array( 'message' => 'expired' ) ) );
        };
        $this->assertWPError( $c->request( 'GET', 'https://sheets.googleapis.com/v4/x' ) );
        $this->assertTrue( $c->needs_reconnect() );
    }

    public function test_invalid_client_marks_needs_reconnect() {
        $c = $this->connect();
        delete_transient( 'anchor_oauth_at_google' );
        $this->responder = function () {
            return array( 401, array( 'error' => 'invalid_client', 'error_description' => 'The OAuth client was deleted.' ) );
        };
        $r = $c->access_token();
        $this->assertWPError( $r );
        $this->assertStringContainsString( 'The OAuth client was deleted.', $r->get_error_message() );
        $this->assertTrue( $c->needs_reconnect() );
        $n = count( $this->calls );
        $c->access_token();
        $this->assertCount( $n, $this->calls );
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
        $this->assertSame( 400, Anchor_OAuth_Connector::handle_callback( $mk() )->get_status() );
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
        $this->assertSame( 403, Anchor_OAuth_Connector::handle_callback( $req )->get_status() );
        $this->assertCount( 0, $this->calls );
        $this->assertFalse( $c->is_connected() );
    }

    public function test_callback_exchanges_code_and_stores_account() {
        $uid = $this->admin();
        $c   = $this->google();
        $this->token_responder( array( 'scope' => 'openid email https://www.googleapis.com/auth/spreadsheets' ) );
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
        $this->assertTrue( $c->has_scope( 'https://www.googleapis.com/auth/spreadsheets' ) );
        $this->assertFalse( $c->has_scope( 'https://www.googleapis.com/auth/drive' ) );
    }

    public function test_callback_without_sheets_scope_is_an_error_and_stays_disconnected() {
        $uid = $this->admin();
        $c   = $this->google();
        $this->token_responder( array( 'scope' => 'openid email' ) );
        parse_str( wp_parse_url( $c->begin_authorize( $uid ), PHP_URL_QUERY ), $q );
        $fired = did_action( 'anchor_connector_connected' );
        $req   = new WP_REST_Request( 'GET', '/anchor-tools/v1/connectors/callback' );
        $req->set_param( 'state', $q['state'] );
        $req->set_param( 'code', 'the-code' );
        $res = Anchor_OAuth_Connector::handle_callback( $req );
        $this->assertStringContainsString( 'anchor_connect_error_code=missing_scope', $res->get_headers()['Location'] );
        $this->assertFalse( $c->is_connected() );
        $this->assertSame( $fired, did_action( 'anchor_connector_connected' ) );
    }

    public function test_cached_access_token_is_sealed_and_connect_fires_action() {
        $fired = did_action( 'anchor_connector_connected' );
        $c     = $this->connect();
        $this->assertSame( $fired + 1, did_action( 'anchor_connector_connected' ) );
        $raw = get_transient( 'anchor_oauth_at_google' );
        $this->assertNotSame( 'AT-1', $raw );
        $this->assertStringNotContainsString( 'AT-1', (string) $raw );
        $this->assertSame( 'AT-1', $c->access_token() );
    }

    public function test_undecryptable_cached_token_falls_back_to_refresh() {
        $c = $this->connect();
        set_transient( 'anchor_oauth_at_google', 'garbage', 3600 );
        $this->token_responder( array( 'access_token' => 'AT-2', 'refresh_token' => null ) );
        $this->assertSame( 'AT-2', $c->access_token() );
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
        $post = $this->token_posts();
        $this->assertSame( 'refresh_token', end( $post )['body']['grant_type'] );
        $this->assertSame( 'RT-SECRET-1', end( $post )['body']['refresh_token'] );
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

    private function share_app( array $creds ) {
        add_filter( 'anchor_connector_shared_credential', $f = function ( $v, $provider, $part ) use ( $creds ) {
            return $creds[ $provider ][ $part ] ?? $v;
        }, 10, 3 );
        return $f;
    }

    public function test_shared_app_overrides_site_credentials_and_signs_in() {
        $f   = $this->share_app( array( 'google' => array( 'ID' => 'shared-id', 'SECRET' => 'shared-secret' ) ) );
        $uid = $this->admin();
        $c   = Anchor_OAuth_Connector::get( 'google' );
        $c->save_app_credentials( 'site-id', 'site-secret' );
        $this->assertTrue( $c->uses_shared_app() );
        $this->assertTrue( $c->has_app_credentials() );
        $this->assertSame( 'shared-id', $c->client_id() );
        parse_str( wp_parse_url( $c->begin_authorize( $uid ), PHP_URL_QUERY ), $q );
        $this->assertSame( 'shared-id', $q['client_id'] );
        $this->token_responder();
        $req = new WP_REST_Request( 'GET', '/anchor-tools/v1/connectors/callback' );
        $req->set_param( 'state', $q['state'] );
        $req->set_param( 'code', 'the-code' );
        Anchor_OAuth_Connector::handle_callback( $req );
        $post = $this->token_posts()[0];
        $this->assertSame( 'shared-id', $post['body']['client_id'] );
        $this->assertSame( 'shared-secret', $post['body']['client_secret'] );
        $this->assertTrue( $c->is_connected() );
        remove_filter( 'anchor_connector_shared_credential', $f, 10 );
        $this->assertFalse( $c->uses_shared_app() );
        $this->assertSame( 'site-id', $c->client_id() );
    }

    public function test_microsoft_public_client_needs_no_secret_and_sends_none() {
        $f   = $this->share_app( array( 'microsoft' => array( 'ID' => 'ms-public-id' ) ) );
        $uid = $this->admin();
        $c   = Anchor_OAuth_Connector::get( 'microsoft' );
        $this->assertTrue( $c->has_app_credentials() );
        parse_str( wp_parse_url( $c->begin_authorize( $uid ), PHP_URL_QUERY ), $q );
        $this->responder = function ( $url ) {
            if ( false !== strpos( $url, '/token' ) ) {
                return array( 200, array( 'access_token' => 'AT', 'refresh_token' => 'RT', 'expires_in' => 3600, 'scope' => 'Files.ReadWrite.All User.Read' ) );
            }
            return array( 200, array( 'mail' => 'me@example.com' ) );
        };
        $req = new WP_REST_Request( 'GET', '/anchor-tools/v1/connectors/callback' );
        $req->set_param( 'state', $q['state'] );
        $req->set_param( 'code', 'c' );
        Anchor_OAuth_Connector::handle_callback( $req );
        $post = $this->token_posts()[0];
        $this->assertSame( 'ms-public-id', $post['body']['client_id'] );
        $this->assertArrayNotHasKey( 'client_secret', $post['body'] );
        $this->assertTrue( $c->is_connected() );
        remove_filter( 'anchor_connector_shared_credential', $f, 10 );
    }

    public function test_google_without_secret_is_not_ready() {
        $f = $this->share_app( array( 'google' => array( 'ID' => 'shared-id' ) ) );
        $this->assertFalse( Anchor_OAuth_Connector::get( 'google' )->has_app_credentials() );
        remove_filter( 'anchor_connector_shared_credential', $f, 10 );
    }

    public function test_blank_secret_keeps_existing() {
        $c = $this->google();
        $c->save_app_credentials( 'new-id', '' );
        $this->assertTrue( $c->has_app_credentials() );
        $this->assertSame( 'SECRET-VALUE-XYZ', Anchor_Secret_Box::open( get_option( 'anchor_connector_google' )['client_secret'] ) );
    }
}
