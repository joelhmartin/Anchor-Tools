<?php
/**
 * Connectors settings tab.
 */
class Test_Connectors_Admin extends WP_UnitTestCase {

    private $admin;

    public function set_up() {
        parent::set_up();
        foreach ( array( 'google', 'microsoft' ) as $p ) {
            delete_option( 'anchor_connector_' . $p );
        }
        $this->admin = new Anchor_Connectors_Admin();
        $id          = self::factory()->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $id );
        add_filter( 'wp_die_handler', array( $this, 'die_handler' ) );
        add_filter( 'wp_redirect', array( $this, 'capture_redirect' ), 10, 1 );
    }

    public function tear_down() {
        remove_filter( 'wp_die_handler', array( $this, 'die_handler' ) );
        remove_filter( 'wp_redirect', array( $this, 'capture_redirect' ), 10 );
        $_POST = $_REQUEST = array();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function die_handler() {
        return function ( $m ) {
            throw new Exception( 'wp_die' );
        };
    }

    public function capture_redirect( $url ) {
        throw new Exception( 'redirect:' . $url );
    }

    private function post( $action, $provider, array $extra = array(), $nonce = true ) {
        $_POST = $_REQUEST = array_merge( array( 'provider' => $provider ), $extra );
        if ( $nonce ) {
            $_POST['_wpnonce'] = $_REQUEST['_wpnonce'] = wp_create_nonce( "anchor_connector_{$action}_{$provider}" );
        }
        $_REQUEST['_wp_http_referer'] = $_POST['_wp_http_referer'] = '/wp-admin/';
    }

    private function run_handler( $method ) {
        try {
            $this->admin->$method();
        } catch ( Exception $e ) {
            return $e->getMessage();
        }
        return '';
    }

    private function render() {
        ob_start();
        $this->admin->render_tab();
        return ob_get_clean();
    }

    public function test_tab_is_registered() {
        $tabs = apply_filters( 'anchor_settings_tabs', array() );
        $this->assertArrayHasKey( 'connectors', $tabs );
        $this->assertSame( 15, has_filter( 'anchor_settings_tabs', array( $this->admin, 'register_tab' ) ) );
    }

    public function test_render_shows_callback_url_and_never_the_secret() {
        Anchor_OAuth_Connector::get( 'google' )->save_app_credentials( 'cid-123', 'SUPERSECRETVALUE' );
        $html = $this->render();
        $this->assertStringContainsString( esc_attr( Anchor_OAuth_Connector::get( 'google' )->callback_url() ), $html );
        $this->assertStringNotContainsString( 'SUPERSECRETVALUE', $html );
        $this->assertStringContainsString( 'cid-123', $html );
        $this->assertStringContainsString( 'Ready to connect', $html );
    }

    public function test_save_requires_nonce_and_manage_options() {
        $this->post( 'save', 'google', array( 'client_id' => 'x', 'client_secret' => 'y' ), false );
        $this->assertSame( 'wp_die', $this->run_handler( 'handle_save' ) );
        $this->assertFalse( Anchor_OAuth_Connector::get( 'google' )->has_app_credentials() );

        wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
        $this->post( 'save', 'google', array( 'client_id' => 'x', 'client_secret' => 'y' ) );
        $this->assertSame( 'wp_die', $this->run_handler( 'handle_save' ) );
        $this->assertFalse( Anchor_OAuth_Connector::get( 'google' )->has_app_credentials() );

        wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        $this->post( 'save', 'google', array( 'client_id' => 'x', 'client_secret' => 'y' ) );
        $this->assertStringStartsWith( 'redirect:', $this->run_handler( 'handle_save' ) );
        $this->assertTrue( Anchor_OAuth_Connector::get( 'google' )->has_app_credentials() );
    }

    public function test_blank_secret_keeps_existing() {
        $c = Anchor_OAuth_Connector::get( 'google' );
        $c->save_app_credentials( 'cid', 'keepme' );
        $this->post( 'save', 'google', array( 'client_id' => 'cid2', 'client_secret' => '' ) );
        $this->run_handler( 'handle_save' );
        $this->assertTrue( $c->has_app_credentials() );
        $this->assertStringContainsString( 'cid2', $this->render() );
    }

    public function test_plain_permalinks_shows_warning() {
        update_option( 'permalink_structure', '' );
        $this->assertStringContainsString( 'pretty permalinks', $this->render() );
        update_option( 'permalink_structure', '/%postname%/' );
        $this->assertStringNotContainsString( 'pretty permalinks', $this->render() );
    }

    public function test_connect_redirects_to_provider() {
        Anchor_OAuth_Connector::get( 'google' )->save_app_credentials( 'cid', 'sec' );
        $this->post( 'connect', 'google' );
        $r = $this->run_handler( 'handle_connect' );
        $this->assertStringStartsWith( 'redirect:https://accounts.google.com/o/oauth2/v2/auth', $r );
    }

    public function test_disconnect_clears_connection() {
        $c = Anchor_OAuth_Connector::get( 'google' );
        update_option( 'anchor_connector_google', array( 'client_id' => 'a', 'refresh_token' => 'r', 'account' => 'me@x.com' ), false );
        $this->assertTrue( $c->is_connected() );
        $this->post( 'disconnect', 'google' );
        $this->run_handler( 'handle_disconnect' );
        $this->assertFalse( $c->is_connected() );
    }

    public function test_notices_render() {
        $_GET['anchor_connected'] = 'google';
        $this->assertStringContainsString( 'Google connected', $this->render() );
        unset( $_GET['anchor_connected'] );
        $_GET['anchor_connect_error'] = 'microsoft';
        $this->assertStringContainsString( 'Microsoft', $this->render() );
        unset( $_GET['anchor_connect_error'] );
    }
}
