<?php
// tests/test-agreements-admin.php
use Anchor\Agreements\Admin\ProductListFlag;
use Anchor\Agreements\Admin\SignaturesPage;
use Anchor\Agreements\Content\AgreementPostType;
use Anchor\Agreements\Database\SignatureRepository;
use Anchor\Agreements\Database\VersionRepository;
use Anchor\Agreements\Services\Cleanup;
use Anchor\Agreements\Services\Notifier;

class Test_Agreements_Admin extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		add_filter( 'woocommerce_set_cookie_enabled', '__return_false' );
	}

	const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

	private function signed_order(): WC_Order {
		$order = wc_create_order();
		$order->set_billing_first_name( 'Pari' );
		$order->set_billing_last_name( 'Example' );
		$order->save();
		$aid  = self::factory()->post->create( [ 'post_type' => AgreementPostType::CPT, 'post_status' => 'publish', 'post_title' => 'Cancellation Policy', 'post_content' => 'x' ] );
		$v    = ( new VersionRepository() )->current_for( $aid );
		$repo = new SignatureRepository();
		$id   = $repo->insert( [ 'version_id' => $v['id'], 'agreement_id' => $aid, 'product_id' => 1, 'user_id' => null, 'signer_name' => 'Pari Example', 'signer_email' => 'p@example.com', 'method' => 'generate', 'font' => 'allura', 'image' => base64_decode( self::PNG ), 'ip' => '1.1.1.1', 'user_agent' => 'x', 'session_key' => 's' ] );
		$repo->attach( $id, $order->get_id() );
		return $order;
	}

	public function test_notifier_sends_once_with_expected_subject() {
		reset_phpmailer_instance();
		$order = $this->signed_order();
		$this->assertTrue( Notifier::maybe_send( $order->get_id() ) );
		$this->assertFalse( Notifier::maybe_send( $order->get_id() ) );
		$mail = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertSame( 'Signed: Cancellation Policy – Pari Example, order #' . $order->get_id(), $mail->subject );
	}

	public function test_notifier_respects_settings() {
		$order = $this->signed_order();
		update_option( \Anchor\Agreements\Support\Settings::OPTION, [ 'notify_enabled' => false ] );
		$this->assertFalse( Notifier::maybe_send( $order->get_id() ) );
		update_option( \Anchor\Agreements\Support\Settings::OPTION, [ 'notify' => 'staff@example.com', 'notify_subject' => 'Agreement signed by {name}' ] );
		reset_phpmailer_instance();
		$this->assertTrue( Notifier::maybe_send( $order->get_id() ) );
		$mail = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertSame( 'Agreement signed by Pari Example', $mail->subject );
		$this->assertSame( 'staff@example.com', $mail->to[0][0] );
	}

	public function test_notifier_skips_orders_without_signatures() {
		$order = wc_create_order();
		$this->assertFalse( Notifier::maybe_send( $order->get_id() ) );
	}

	public function test_csv_has_header_and_no_image() {
		$this->signed_order();
		$rows = SignaturesPage::csv_rows( [] );
		$this->assertSame( [ 'Signature ID', 'Document', 'Version date', 'Signer', 'Email', 'Method', 'Font', 'Order', 'Signed (UTC)', 'IP', 'User agent' ], $rows[0] );
		$this->assertSame( 'Pari Example', $rows[1][3] );
		$this->assertCount( 11, $rows[1] );
	}

	public function test_csv_neutralises_formula_injection() {
		$order = wc_create_order();
		$aid   = self::factory()->post->create( [ 'post_type' => AgreementPostType::CPT, 'post_status' => 'publish', 'post_title' => 'P', 'post_content' => 'x' ] );
		$v     = ( new VersionRepository() )->current_for( $aid );
		$repo  = new SignatureRepository();
		$id    = $repo->insert( [ 'version_id' => $v['id'], 'agreement_id' => $aid, 'product_id' => 1, 'user_id' => null, 'signer_name' => '=HYPERLINK("x")', 'signer_email' => '', 'method' => 'draw', 'font' => null, 'image' => base64_decode( self::PNG ), 'ip' => '', 'user_agent' => '', 'session_key' => 's' ] );
		$repo->attach( $id, $order->get_id() );
		$this->assertSame( "'=HYPERLINK(\"x\")", SignaturesPage::csv_rows( [] )[1][3] );
	}

	public function test_cleanup_is_scheduled() {
		$this->assertNotFalse( wp_next_scheduled( Cleanup::HOOK ) );
	}

	public function test_subject_decodes_html_entities_in_titles() {
		reset_phpmailer_instance();
		$order = wc_create_order();
		$aid   = self::factory()->post->create( [ 'post_type' => AgreementPostType::CPT, 'post_status' => 'publish', 'post_title' => 'Terms &amp; Conditions &#8211; 2026', 'post_content' => 'x' ] );
		$v     = ( new VersionRepository() )->current_for( $aid );
		$repo  = new SignatureRepository();
		$id    = $repo->insert( [ 'version_id' => $v['id'], 'agreement_id' => $aid, 'product_id' => 1, 'user_id' => null, 'signer_name' => 'Pari Example', 'signer_email' => '', 'method' => 'draw', 'font' => null, 'image' => base64_decode( self::PNG ), 'ip' => '', 'user_agent' => '', 'session_key' => 's' ] );
		$repo->attach( $id, $order->get_id() );
		$this->assertTrue( Notifier::maybe_send( $order->get_id() ) );
		$this->assertSame( 'Signed: Terms & Conditions – 2026 – Pari Example, order #' . $order->get_id(), tests_retrieve_phpmailer_instance()->get_sent()->subject );
	}

	public function test_product_list_flags_required_product_without_usable_document() {
		$p = new WC_Product_Simple();
		$p->update_meta_data( '_anchor_agreement_required', 'yes' );
		$p->save();
		ob_start();
		( new ProductListFlag() )->render( 'name', $p->get_id() );
		$this->assertStringContainsString( 'aagr-flag', ob_get_clean() );

		$aid = self::factory()->post->create( [ 'post_type' => AgreementPostType::CPT, 'post_status' => 'publish', 'post_title' => 'T', 'post_content' => 'x' ] );
		update_option( \Anchor\Agreements\Support\Settings::OPTION, [ 'default_agreement_id' => $aid ] );
		ob_start();
		( new ProductListFlag() )->render( 'name', $p->get_id() );
		$this->assertSame( '', ob_get_clean() );

		ob_start();
		( new ProductListFlag() )->render( 'price', $p->get_id() );
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_product_list_flag_is_hooked_after_woocommerce_name_cell() {
		new ProductListFlag();
		$found = false;
		foreach ( $GLOBALS['wp_filter']['manage_product_posts_custom_column']->callbacks[20] ?? [] as $cb ) {
			if ( is_array( $cb['function'] ) && $cb['function'][0] instanceof ProductListFlag ) { $found = true; }
		}
		$this->assertTrue( $found );
	}

	public function test_order_metabox_registers_on_the_active_order_screen() {
		global $wp_meta_boxes;
		( new \Anchor\Agreements\Admin\OrderMetabox() )->register();
		$screen = wc_get_page_screen_id( 'shop-order' );
		$this->assertArrayHasKey( 'anchor-agreements', $wp_meta_boxes[ $screen ]['side']['default'] );
	}

	public function test_switching_module_off_clears_the_cleanup_cron() {
		$this->assertNotFalse( wp_next_scheduled( Cleanup::HOOK ) );
		update_option( \Anchor\Agreements\Frontend\SignedCopyPage::REWRITE_OPTION, '1', false );
		anchor_tools_agreements_maybe_clear_cleanup( [ 'modules' => [ 'agreements' => true ] ], [ 'modules' => [] ] );
		$this->assertFalse( wp_next_scheduled( Cleanup::HOOK ) );
		$this->assertFalse( get_option( \Anchor\Agreements\Frontend\SignedCopyPage::REWRITE_OPTION ) ); // Re-enabling re-flushes.
	}

	public function test_module_without_woocommerce_registers_only_a_notice() {
		$instance = \Anchor\Agreements\Module::instance();
		$before   = has_action( 'woocommerce_checkout_order_created' );
		$module   = new \Anchor\Agreements\Module( false );
		$this->assertSame( $instance, \Anchor\Agreements\Module::instance() );
		$this->assertSame( $before, has_action( 'woocommerce_checkout_order_created' ) );
		$this->assertNotFalse( has_action( 'admin_notices', [ \Anchor\Agreements\Module::class, 'missing_woocommerce_notice' ] ) );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		ob_start();
		\Anchor\Agreements\Module::missing_woocommerce_notice();
		$this->assertStringContainsString( 'requires WooCommerce', ob_get_clean() );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		ob_start();
		\Anchor\Agreements\Module::missing_woocommerce_notice();
		$this->assertSame( '', ob_get_clean() );
		remove_action( 'admin_notices', [ \Anchor\Agreements\Module::class, 'missing_woocommerce_notice' ] );
		unset( $module );
	}

	public function test_block_checkout_notice_only_when_checkout_page_uses_the_block() {
		$page = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '<!-- wp:shortcode -->[woocommerce_checkout]<!-- /wp:shortcode -->' ] );
		update_option( 'woocommerce_checkout_page_id', $page );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		ob_start();
		\Anchor\Agreements\Admin\BlockCheckoutNotice::render();
		$this->assertSame( '', ob_get_clean() );
		wp_update_post( [ 'ID' => $page, 'post_content' => '<!-- wp:woocommerce/checkout --><div class="wp-block-woocommerce-checkout"></div><!-- /wp:woocommerce/checkout -->' ] );
		ob_start();
		\Anchor\Agreements\Admin\BlockCheckoutNotice::render();
		$this->assertStringContainsString( 'Checkout block', ob_get_clean() );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		ob_start();
		\Anchor\Agreements\Admin\BlockCheckoutNotice::render();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_settings_save_capability_matches_page_capability() {
		new \Anchor\Agreements\Admin\SettingsPage();
		$this->assertSame( 'manage_woocommerce', apply_filters( 'option_page_capability_anchor_agreements', 'manage_options' ) );
	}
}
