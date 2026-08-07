<?php
/**
 * Anchor Compliance — consent REST endpoint.
 */
class Test_Compliance_Rest extends WP_UnitTestCase {

	protected $server;

	public function set_up() {
		parent::set_up();
		Anchor_Compliance_Consent_Log::install();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );
		$GLOBALS['wpdb']->query( 'TRUNCATE TABLE ' . Anchor_Compliance_Consent_Log::table() );
	}

	private function post( array $body ) {
		$req = new WP_REST_Request( 'POST', '/anchor-compliance/v1/consent' );
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( wp_json_encode( $body ) );
		return $this->server->dispatch( $req );
	}

	public function test_route_is_registered() {
		$this->assertArrayHasKey( '/anchor-compliance/v1/consent', $this->server->get_routes() );
	}

	public function test_valid_post_records_consent() {
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'US';
		$res = $this->post( [
			'consent_id' => '11111111-0000-4000-8000-000000000000',
			'categories' => [ 'necessary', 'analytics' ],
			'method'     => 'banner',
		] );

		$this->assertSame( 200, $res->get_status() );
		$this->assertTrue( $res->get_data()['ok'] );

		$rows = ( new Anchor_Compliance_Consent_Log() )->query();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'US', $rows[0]->region );
		$this->assertSame( 'optout', $rows[0]->posture );
		unset( $_SERVER['HTTP_CF_IPCOUNTRY'] );
	}

	public function test_invalid_category_is_rejected() {
		$res = $this->post( [
			'consent_id' => '22222222-0000-4000-8000-000000000000',
			'categories' => [ 'necessary', 'evil' ],
			'method'     => 'banner',
		] );
		$this->assertSame( 400, $res->get_status() );
	}

	public function test_missing_consent_id_is_rejected() {
		$res = $this->post( [ 'categories' => [ 'necessary' ], 'method' => 'banner' ] );
		$this->assertSame( 400, $res->get_status() );
	}

	public function test_malformed_consent_id_is_rejected() {
		$res = $this->post( [
			'consent_id' => 'not a uuid',
			'categories' => [ 'necessary' ],
			'method'     => 'banner',
		] );
		$this->assertSame( 400, $res->get_status() );
	}
}
