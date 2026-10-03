<?php
// tests/test-agreements-customer-views.php
use Anchor\Agreements\Content\AgreementPostType;
use Anchor\Agreements\Database\SignatureRepository;
use Anchor\Agreements\Database\VersionRepository;
use Anchor\Agreements\Frontend\CustomerViews;
use Anchor\Agreements\Frontend\SignedCopyPage;

class Test_Agreements_Customer_Views extends WP_UnitTestCase {
	const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

	private function signed( int $order_id, string $name = 'Pari <b>Example</b>' ): array {
		$aid  = self::factory()->post->create( [ 'post_type' => AgreementPostType::CPT, 'post_status' => 'publish', 'post_title' => 'Cancellation Policy', 'post_content' => '<p>Original terms.</p>' ] );
		$v    = ( new VersionRepository() )->current_for( $aid );
		$repo = new SignatureRepository();
		$id   = $repo->insert( [ 'version_id' => $v['id'], 'agreement_id' => $aid, 'product_id' => 1, 'user_id' => 1, 'signer_name' => $name, 'signer_email' => 'p@example.com', 'method' => 'draw', 'font' => null, 'image' => base64_decode( self::PNG ), 'ip' => '1.1.1.1', 'user_agent' => 'x', 'session_key' => 's' ] );
		$repo->attach( $id, $order_id );
		wp_update_post( [ 'ID' => $aid, 'post_content' => '<p>EDITED LATER.</p>' ] );
		return $repo->get( $id );
	}

	public function test_signed_copy_shows_version_as_signed_not_current() {
		$sig  = $this->signed( 42 );
		$html = SignedCopyPage::render_html( $sig );
		$this->assertStringContainsString( 'Original terms.', $html );
		$this->assertStringNotContainsString( 'EDITED LATER', $html );
		$this->assertStringContainsString( 'data:image/png;base64,', $html );
		$this->assertStringContainsString( '#42', $html );
		$this->assertStringNotContainsString( '<b>Example</b>', $html );
	}

	public function test_unknown_token_renders_not_found_and_headers_are_identical() {
		$this->assertStringContainsString( 'aagr-copy--missing', SignedCopyPage::render_html( null ) );
		$h = SignedCopyPage::headers();
		$this->assertSame( 'noindex, nofollow', $h['X-Robots-Tag'] );
		$this->assertStringContainsString( 'no-store', $h['Cache-Control'] );
	}

	public function test_url_shape() {
		$this->assertStringEndsWith( '/signed-agreement/abc/', SignedCopyPage::url( 'abc' ) );
	}

	public function test_email_block_only_for_customer_emails() {
		$order = wc_create_order();
		$sig   = $this->signed( $order->get_id() );
		ob_start();
		CustomerViews::email_block( $order, true, false );
		$this->assertSame( '', ob_get_clean() );
		ob_start();
		CustomerViews::email_block( $order, false, false );
		$this->assertStringContainsString( SignedCopyPage::url( $sig['token'] ), ob_get_clean() );
		update_option( \Anchor\Agreements\Support\Settings::OPTION, [ 'customer_email_links' => false ] );
		ob_start();
		CustomerViews::email_block( $order, false, false );
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_shortcode_lists_for_logged_in_user_only() {
		$this->signed( 7 );
		wp_set_current_user( 0 );
		$this->assertStringNotContainsString( 'Cancellation Policy', do_shortcode( '[anchor_signed_agreements]' ) );
		wp_set_current_user( 1 );
		$this->assertStringContainsString( 'Cancellation Policy', do_shortcode( '[anchor_signed_agreements]' ) );
	}

	public function test_account_endpoint_is_registered_with_woocommerce() {
		$this->assertArrayHasKey( 'signed-documents', apply_filters( 'woocommerce_get_query_vars', [] ) );
		$items = CustomerViews::menu_item( [ 'dashboard' => 'D', 'customer-logout' => 'L' ] );
		$this->assertSame( [ 'dashboard', 'signed-documents', 'customer-logout' ], array_keys( $items ) );
	}

	public function test_plain_text_email_does_not_html_escape_titles() {
		$order = wc_create_order();
		$sig   = $this->signed( $order->get_id() );
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . \Anchor\Agreements\Database\Migrations::table( 'versions' ) . ' SET title = %s WHERE id = %d', 'Terms & Conditions', $sig['version_id'] ) );
		ob_start();
		CustomerViews::email_block( $order, false, true );
		$out = ob_get_clean();
		$this->assertStringContainsString( 'Terms & Conditions:', $out );
		$this->assertStringNotContainsString( '&amp;', $out );
	}
}
