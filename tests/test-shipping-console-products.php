<?php
use Anchor\Shipping\Admin\ProductFields;
use Anchor\Shipping\Frontend\ConsoleAjax;
use Anchor\Shipping\Frontend\ConsoleProducts;
use Anchor\Shipping\Frontend\ShopManager;
use Anchor\Shipping\Module;
use Anchor\Shipping\Support\Settings;

class Test_Shipping_Console_Products extends Anchor_Shipping_TestCase {

	private function as_role( string $role ): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => $role ] ) );
	}

	private function console(): ShopManager {
		$m = Module::instance();
		return new ShopManager( $m->labels, $m->shipments, $m->carriers, $m->packer );
	}

	private function ajax(): ConsoleAjax {
		return new ConsoleAjax();
	}

	private function save( array $post ): array {
		return $this->ajax()->handle_product_save( $post );
	}

	private function expect_error( array $post ): string {
		try {
			$this->save( $post );
			$this->fail( 'expected RuntimeException' );
		} catch ( RuntimeException $e ) {
			$this->assertNotSame( '', $e->getMessage() );
			return $e->getMessage();
		}
		return '';
	}

	private function boxes(): void {
		$s          = Settings::all();
		$s['boxes'] = [ [ 'id' => 'small', 'name' => 'Small', 'length' => 10, 'width' => 8, 'height' => 4, 'weight' => 0.5, 'max_weight' => 20 ] ];
		Settings::save( $s );
	}

	private function listed( array $args = [] ): string {
		return $this->console()->render( [ 'tab' => 'products' ] + $args );
	}

	private function variable_product(): array {
		$p = new WC_Product_Variable();
		$p->set_name( 'Handpiece' );
		$attr = new WC_Product_Attribute();
		$attr->set_name( 'Colour' );
		$attr->set_options( [ 'Red', 'Blue' ] );
		$attr->set_visible( true );
		$attr->set_variation( true );
		$p->set_attributes( [ $attr ] );
		$p->save();
		$ids = [];
		foreach ( [ 'Red', 'Blue' ] as $c ) {
			$v = new WC_Product_Variation();
			$v->set_parent_id( $p->get_id() );
			$v->set_attributes( [ 'colour' => $c ] );
			$v->set_regular_price( '50' );
			$v->save();
			$ids[] = $v->get_id();
		}
		return [ $p->get_id(), $ids ];
	}

	public function test_products_tab_requires_console_access_and_edit_products() {
		$id = $this->make_product( [ 'name' => 'Secret tip' ] );
		$this->as_role( 'customer' );
		$html = $this->listed();
		$this->assertStringNotContainsString( 'Secret tip', $html );
		// A user with console access via the filter but no edit_products still sees no product data.
		add_filter( 'anchor_shipping_console_capability', static fn() => 'read' );
		$html = $this->listed();
		$this->assertStringNotContainsString( 'Secret tip', $html );
		$this->assertStringNotContainsString( 'asm-products', $html );
		remove_all_filters( 'anchor_shipping_console_capability' );
		$this->as_role( 'shop_manager' );
		$this->assertStringContainsString( 'Secret tip', $this->listed() );
		$this->assertTrue( ConsoleProducts::can_use() );
		$this->assertGreaterThan( 0, $id );
	}

	public function test_nav_shows_products_link_only_when_permitted() {
		$this->as_role( 'shop_manager' );
		$this->assertStringContainsString( 'tab=products', $this->console()->render() );
		add_filter( 'anchor_shipping_console_products_capability', static fn() => 'manage_options' );
		$this->assertStringNotContainsString( 'tab=products', $this->console()->render() );
		$this->assertStringNotContainsString( 'asm-products', $this->listed() );
		remove_all_filters( 'anchor_shipping_console_products_capability' );
	}

	public function test_lists_simple_products_and_variations_but_not_drafts() {
		$this->boxes();
		$this->as_role( 'shop_manager' );
		$simple = $this->make_product( [ 'name' => 'Simple tip', 'weight' => '0.4', 'box' => 'small' ] );
		$draft  = new WC_Product_Simple();
		$draft->set_name( 'Hidden draft' );
		$draft->set_status( 'draft' );
		$draft->save();
		[ $parent, $vars ] = $this->variable_product();
		$html = $this->listed();
		$this->assertStringContainsString( 'data-product-id="' . $simple . '"', $html );
		$this->assertStringNotContainsString( 'Hidden draft', $html );
		foreach ( $vars as $v ) {
			$this->assertStringContainsString( 'data-product-id="' . $v . '"', $html );
		}
		$this->assertStringContainsString( 'Handpiece', $html );
		$this->assertStringContainsString( 'Red', $html );
		// parent row exists but has no editable controls.
		preg_match( '/<tr[^>]*data-product-id="' . $parent . '".*?<\/tr>/s', $html, $m );
		$this->assertNotEmpty( $m );
		$this->assertStringNotContainsString( '<input', $m[0] );
		$this->assertStringNotContainsString( 'asm-product-save', $m[0] );
		// simple row is prefilled with weight and box.
		preg_match( '/<tr[^>]*data-product-id="' . $simple . '".*?<\/tr>/s', $html, $m );
		$this->assertStringContainsString( 'value="0.4"', $m[0] );
		$this->assertMatchesRegularExpression( '/<option value="small" selected/', $m[0] );
	}

	public function test_search_by_name_and_sku_and_pagination() {
		$this->as_role( 'shop_manager' );
		$a = $this->make_product( [ 'name' => 'Alpha widget' ] );
		$b = new WC_Product_Simple();
		$b->set_name( 'Beta thing' );
		$b->set_sku( 'ZED-99' );
		$b->save();
		$this->assertStringContainsString( 'Alpha widget', $this->listed( [ 's' => 'Alpha' ] ) );
		$this->assertStringNotContainsString( 'Beta thing', $this->listed( [ 's' => 'Alpha' ] ) );
		$by_sku = $this->listed( [ 's' => 'ZED-99' ] );
		$this->assertStringContainsString( 'data-product-id="' . $b->get_id() . '"', $by_sku );
		$this->assertStringNotContainsString( 'data-product-id="' . $a . '"', $by_sku );

		for ( $i = 0; $i < ConsoleProducts::PER_PAGE; $i++ ) {
			$this->make_product( [ 'name' => 'Filler ' . $i ] );
		}
		$p1 = $this->listed();
		preg_match_all( '/data-product-id="(\d+)"/', $p1, $m1 );
		$this->assertCount( ConsoleProducts::PER_PAGE, $m1[1] );
		$this->assertStringContainsString( 'rel="next"', $p1 );
		preg_match_all( '/data-product-id="(\d+)"/', $this->listed( [ 'paged' => 2 ] ), $m2 );
		$this->assertNotEmpty( $m2[1] );
		$this->assertSame( [], array_intersect( $m1[1], $m2[1] ) );
	}

	public function test_save_updates_everything_through_the_woo_api() {
		$this->boxes();
		$this->as_role( 'shop_manager' );
		$id  = $this->make_product( [ 'price' => '100' ] );
		$out = $this->save( [
			'product_id' => $id, 'regular_price' => '120.50', 'sale_price' => '99', 'manage_stock' => '1',
			'stock_quantity' => '7', 'stock_status' => 'instock', 'weight' => '1.25', 'box' => 'small',
		] );
		$p = wc_get_product( $id );
		$this->assertSame( '120.50', $p->get_regular_price() );
		$this->assertSame( '99', $p->get_sale_price() );
		$this->assertSame( '99', $p->get_price(), '_price follows the sale price' );
		$this->assertTrue( $p->get_manage_stock() );
		$this->assertSame( 7, $p->get_stock_quantity() );
		$this->assertSame( '1.25', $p->get_weight() );
		$this->assertSame( 'small', $p->get_meta( ProductFields::META ) );
		$this->assertStringContainsString( 'data-product-id="' . $id . '"', $out['html'] );
		$this->assertStringContainsString( 'value="120.50"', $out['html'] );
	}

	public function test_empty_sale_clears_it_and_price_returns_to_regular() {
		$this->as_role( 'shop_manager' );
		$id = $this->make_product( [ 'price' => '100' ] );
		$this->save( [ 'product_id' => $id, 'regular_price' => '100', 'sale_price' => '80' ] );
		$this->assertSame( '80', wc_get_product( $id )->get_price() );
		$this->save( [ 'product_id' => $id, 'regular_price' => '100', 'sale_price' => '' ] );
		$p = wc_get_product( $id );
		$this->assertSame( '', $p->get_sale_price() );
		$this->assertSame( '100', $p->get_price() );
	}

	public function test_stock_off_sets_status_and_clears_management() {
		$this->as_role( 'shop_manager' );
		$id = $this->make_product();
		$this->save( [ 'product_id' => $id, 'regular_price' => '110', 'manage_stock' => '1', 'stock_quantity' => '3', 'stock_status' => 'instock' ] );
		$this->save( [ 'product_id' => $id, 'regular_price' => '110', 'manage_stock' => '0', 'stock_status' => 'outofstock' ] );
		$p = wc_get_product( $id );
		$this->assertFalse( $p->get_manage_stock() );
		$this->assertSame( 'outofstock', $p->get_stock_status() );
	}

	public function test_empty_weight_and_box_clear_them() {
		$this->boxes();
		$this->as_role( 'shop_manager' );
		$id = $this->make_product( [ 'weight' => '2', 'box' => 'small' ] );
		$this->save( [ 'product_id' => $id, 'regular_price' => '110', 'weight' => '', 'box' => '' ] );
		$p = wc_get_product( $id );
		$this->assertSame( '', $p->get_weight() );
		$this->assertSame( '', $p->get_meta( ProductFields::META ) );
	}

	public function test_rejections_leave_the_product_untouched() {
		$this->boxes();
		$this->as_role( 'shop_manager' );
		$id   = $this->make_product( [ 'price' => '100', 'weight' => '2' ] );
		$base = [ 'product_id' => $id, 'regular_price' => '100' ];
		$bad  = [
			[ 'sale_price' => '100' ],
			[ 'sale_price' => '150' ],
			[ 'regular_price' => '-5' ],
			[ 'regular_price' => 'abc' ],
			[ 'sale_price' => '-1' ],
			[ 'weight' => '-2' ],
			[ 'weight' => 'heavy' ],
			[ 'manage_stock' => '1', 'stock_quantity' => '-3', 'stock_status' => 'instock' ],
			[ 'manage_stock' => '1', 'stock_quantity' => '1.5', 'stock_status' => 'instock' ],
			[ 'manage_stock' => '0', 'stock_status' => 'bogus' ],
			[ 'box' => 'no-such-box' ],
		];
		foreach ( $bad as $extra ) {
			$this->expect_error( $extra + $base );
		}
		$p = wc_get_product( $id );
		$this->assertSame( '100', $p->get_regular_price() );
		$this->assertSame( '', $p->get_sale_price() );
		$this->assertSame( '2', $p->get_weight() );
		$this->assertFalse( $p->get_manage_stock() );
	}

	public function test_sale_rejected_message_names_the_problem() {
		$this->as_role( 'shop_manager' );
		$id = $this->make_product( [ 'price' => '100' ] );
		$this->assertStringContainsStringIgnoringCase( 'sale', $this->expect_error( [ 'product_id' => $id, 'regular_price' => '100', 'sale_price' => '100' ] ) );
	}

	public function test_variations_are_editable_but_variable_parents_are_not() {
		$this->as_role( 'shop_manager' );
		[ $parent, $vars ] = $this->variable_product();
		$this->expect_error( [ 'product_id' => $parent, 'regular_price' => '1' ] );
		$out = $this->save( [ 'product_id' => $vars[0], 'regular_price' => '60', 'weight' => '0.2' ] );
		$v   = wc_get_product( $vars[0] );
		$this->assertSame( '60', $v->get_regular_price() );
		$this->assertSame( '0.2', $v->get_weight() );
		$this->assertStringContainsString( 'data-product-id="' . $vars[0] . '"', $out['html'] );
	}

	public function test_unknown_product_and_non_products_error() {
		$this->as_role( 'shop_manager' );
		$this->expect_error( [ 'product_id' => 999999, 'regular_price' => '1' ] );
		$order = $this->make_order( [], 'processing' );
		$this->expect_error( [ 'product_id' => $order->get_id(), 'regular_price' => '1' ] );
	}

	public function test_endpoint_enforces_nonce_and_edit_products() {
		$id = $this->make_product( [ 'price' => '100' ] );
		$this->ajax();
		$this->assertNotFalse( has_action( 'wp_ajax_anchor_shipping_console_product_save' ) );
		$call = function ( array $post ): array {
			$_POST    = $post;
			$_REQUEST = $post;
			add_filter( 'wp_doing_ajax', '__return_true' );
			add_filter( 'wp_die_ajax_handler', fn() => static function () { throw new WPDieException( 'die' ); } );
			ob_start();
			try {
				$this->ajax()->ajax_product_save();
			} catch ( WPDieException $e ) {
				// wp_send_json dies after printing.
			}
			return json_decode( (string) ob_get_clean(), true ) ?: [];
		};
		$post = fn() => [ 'product_id' => $id, 'regular_price' => '55', 'nonce' => wp_create_nonce( 'anchor_shipping' ) ];

		$this->as_role( 'customer' );
		$res = $call( $post() );
		$this->assertFalse( $res['success'] ?? true );
		$this->assertStringNotContainsString( 'Tip pack', wp_json_encode( $res ) );

		// Console access alone (filter) is not enough without edit_products.
		add_filter( 'anchor_shipping_console_capability', static fn() => 'read' );
		$res = $call( $post() );
		$this->assertFalse( $res['success'] ?? true );
		remove_all_filters( 'anchor_shipping_console_capability' );

		$this->as_role( 'shop_manager' );
		$res = $call( [ 'nonce' => 'bad' ] + $post() );
		$this->assertFalse( $res['success'] ?? true );
		$this->assertSame( '100', wc_get_product( $id )->get_regular_price() );

		$res = $call( $post() );
		$this->assertTrue( $res['success'] ?? false );
		$this->assertSame( '55', wc_get_product( $id )->get_regular_price() );
		$this->assertArrayHasKey( 'html', $res['data'] );
		$_POST = $_REQUEST = [];
	}
}
