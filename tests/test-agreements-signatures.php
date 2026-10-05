<?php
// tests/test-agreements-signatures.php
use Anchor\Agreements\Database\SignatureRepository;
use Anchor\Agreements\Database\Migrations;

class Test_Agreements_Signatures extends WP_UnitTestCase {
	const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

	private function data( array $over = [] ): array {
		return array_merge( [
			'version_id' => 1, 'agreement_id' => 10, 'product_id' => 5, 'user_id' => null,
			'signer_name' => 'Pari Example', 'signer_email' => 'p@example.com', 'method' => 'draw',
			'font' => null, 'image' => base64_decode( self::PNG ), 'ip' => '1.2.3.4',
			'user_agent' => 'phpunit', 'session_key' => 'sess1',
		], $over );
	}

	public function test_insert_and_find_by_token_roundtrips_image() {
		$repo = new SignatureRepository();
		$id   = $repo->insert( $this->data() );
		$row  = $repo->get( $id );
		$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', $row['token'] );
		$this->assertSame( base64_decode( self::PNG ), $repo->find_by_token( $row['token'] )['image'] );
		$this->assertNull( $row['order_id'] );
	}

	public function test_attach_is_idempotent_and_refuses_other_order() {
		$repo = new SignatureRepository();
		$id   = $repo->insert( $this->data() );
		$this->assertTrue( $repo->attach( $id, 100 ) );
		$this->assertTrue( $repo->attach( $id, 100 ) );
		$this->assertFalse( $repo->attach( $id, 200 ) );
		$this->assertSame( 100, $repo->get( $id )['order_id'] );
		$this->assertCount( 1, $repo->for_order( 100 ) );
	}

	public function test_purge_only_removes_old_unattached() {
		global $wpdb;
		$repo = new SignatureRepository();
		$old  = $repo->insert( $this->data() );
		$kept = $repo->insert( $this->data() );
		$att  = $repo->insert( $this->data() );
		$repo->attach( $att, 7 );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Migrations::table( 'signatures' ) . ' SET signed_at = %s WHERE id IN (%d,%d)', gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ), $old, $att ) );
		$this->assertSame( 1, $repo->purge_unattached( 30 * DAY_IN_SECONDS ) );
		$this->assertNull( $repo->get( $old ) );
		$this->assertNotNull( $repo->get( $kept ) );
		$this->assertNotNull( $repo->get( $att ) );
	}

	public function test_count_recent_for_session() {
		$repo = new SignatureRepository();
		$repo->insert( $this->data() );
		$repo->insert( $this->data( [ 'session_key' => 'other' ] ) );
		$this->assertSame( 1, $repo->count_recent_for_session( 'sess1', HOUR_IN_SECONDS ) );
	}

	public function test_search_by_name_excludes_image() {
		$repo = new SignatureRepository();
		$repo->insert( $this->data() );
		$res = $repo->search( [ 's' => 'Pari' ] );
		$this->assertSame( 1, $res['total'] );
		$this->assertArrayNotHasKey( 'image', $res['rows'][0] );
	}

	public function test_search_numeric_prefix_does_not_match_order_id() {
		$repo = new SignatureRepository();
		$id   = $repo->insert( $this->data() );
		$repo->attach( $id, 12 );
		$this->assertSame( 1, $repo->search( [ 's' => '12' ] )['total'] );
		$this->assertSame( 0, $repo->search( [ 's' => '12abc' ] )['total'] );
	}

	public function test_attach_moves_only_from_the_named_order() {
		$repo = new SignatureRepository();
		$id   = $repo->insert( $this->data() );
		$repo->attach( $id, 100 );
		$this->assertFalse( $repo->attach( $id, 200, 300 ) );
		$this->assertSame( 100, $repo->get( $id )['order_id'] );
		$this->assertTrue( $repo->attach( $id, 200, 100 ) );
		$this->assertSame( 200, $repo->get( $id )['order_id'] );
	}

	public function test_for_user_never_matches_by_email() {
		$repo  = new SignatureRepository();
		$guest = $repo->insert( $this->data( [ 'user_id' => null, 'signer_email' => 'p@example.com' ] ) );
		$own   = $repo->insert( $this->data( [ 'user_id' => 9, 'signer_email' => 'other@example.com' ] ) );
		$repo->attach( $guest, 1 );
		$repo->attach( $own, 2 );
		// User 9 registered with the guest's address p@example.com: still only their own row.
		$this->assertSame( [ $own ], array_column( $repo->for_user( 9 ), 'id' ) );
		$this->assertSame( [], $repo->for_user( 0 ) );
	}
}
