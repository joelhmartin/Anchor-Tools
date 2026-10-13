<?php
use Anchor\Shipping\Admin\OrdersColumn;
use Anchor\Shipping\Frontend\ConsoleAjax;
use Anchor\Shipping\Frontend\ShopManager;
use Anchor\Shipping\Module;
use Anchor\Shipping\Services\LabelService;
use Anchor\Shipping\Support\TrackingLinks;

/** Final whole-branch review fixes: stale needs_attention, bulk chunking, email links, product caps. */
class Test_Shipping_Final_Fixes extends Anchor_Shipping_TestCase {

	private function flagged( string $status ): WC_Order {
		$o = $this->make_order( [], $status );
		$o->update_meta_data( LabelService::STATE_META, 'needs_attention' );
		$o->save();
		return $o;
	}

	private function listed( string $tab ): array {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );
		$m    = Module::instance();
		$html = ( new ShopManager( $m->labels, $m->shipments, $m->carriers, $m->packer ) )->render( [ 'tab' => $tab ] );
		preg_match_all( '/data-order-id="(\d+)"/', $html, $found );
		return array_map( 'intval', $found[1] );
	}

	private function column(): OrdersColumn {
		$m = Module::instance();
		return new OrdersColumn( new TrackingLinks( $m->shipments, $m->carriers ), $m->labels );
	}

	private function label_it( WC_Order $o ): void {
		$this->configure_ups();
		$this->queue_token();
		$this->queue_response( 200, $this->fixture( 'ups-ship-single' ) );
		Module::instance()->labels->create_for_order( $o, [ new \Anchor\Shipping\Domain\Parcel( 1, 10, 10, 10 ) ], 'manual' );
	}

	public function test_needs_label_tab_and_column_agree_on_stale_flags() {
		$open  = $this->flagged( 'on-hold' );
		$stale = $this->flagged( 'completed' );
		$gone  = $this->flagged( 'cancelled' );
		$held  = $this->flagged( 'cancelled' ); // cancelled, but a (void-refused) label is still active
		Module::instance()->shipments->insert( [ 'order_id' => $held->get_id(), 'carrier' => 'ups', 'shipment_id' => 'SX', 'tracking_number' => '1ZX' ] );

		$ids = $this->listed( 'needs-label' );
		$this->assertContains( $open->get_id(), $ids );
		$this->assertContains( $held->get_id(), $ids );
		$this->assertNotContains( $stale->get_id(), $ids );
		$this->assertNotContains( $gone->get_id(), $ids );

		$col = $this->column();
		$this->assertStringContainsString( 'Needs attention', $col->render_cell( wc_get_order( $held->get_id() ) ) );
		$this->assertSame( '', $col->render_cell( wc_get_order( $stale->get_id() ) ), 'a finished order with a stale flag shows nothing' );
	}

	public function test_completing_or_cancelling_clears_the_flag_when_no_label_is_active() {
		foreach ( [ 'completed', 'cancelled', 'refunded' ] as $to ) {
			$o = $this->flagged( 'processing' );
			$o->update_status( $to );
			$this->assertSame( '', (string) wc_get_order( $o->get_id() )->get_meta( LabelService::STATE_META ), $to );
		}
	}

	public function test_a_refused_void_keeps_the_flag_through_the_cancel() {
		$o = $this->make_order( [], 'processing' );
		$this->label_it( $o );
		$this->queue_response( 400, [ 'response' => [ 'errors' => [ [ 'code' => '190102', 'message' => 'No shipment found within the allowed void period' ] ] ] ] );
		$o->update_status( 'cancelled' );
		$this->assertSame( 'needs_attention', wc_get_order( $o->get_id() )->get_meta( LabelService::STATE_META ) );
	}

	public function test_bulk_request_is_capped_at_ten_orders() {
		$rows = [];
		for ( $i = 1; $i <= 11; $i++ ) {
			$rows[] = [ 'order_id' => $i, 'box' => 'small', 'weight' => '1' ];
		}
		$this->expectException( RuntimeException::class );
		( new ConsoleAjax() )->handle_bulk_create( [ 'rows' => $rows ] );
	}

	public function test_chunks_skip_the_merge_and_finish_merges_labels_from_every_chunk() {
		$this->configure_ups();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );
		$a = $this->make_order( [], 'processing' );
		$b = $this->make_order( [], 'processing' );
		$ajax = new ConsoleAjax();
		$created = [];
		foreach ( [ $a, $b ] as $o ) {
			if ( ! $created ) {
				$this->queue_token(); // the access token is cached after the first call
			}
			$this->queue_response( 200, $this->fixture( 'ups-ship-single' ) );
			$res = $ajax->handle_bulk_create( [ 'chunk' => 1, 'rows' => [ [ 'order_id' => $o->get_id(), 'box' => 'small', 'weight' => '1' ] ] ] );
			$this->assertNull( $res['pdf'], 'a chunk does not merge' );
			$this->assertNotEmpty( $res['created'][ $o->get_id() ] );
			$created += $res['created'];
		}
		$fin = $ajax->handle_bulk_finish( [ 'created' => $created, 'skipped' => [ 99 => 'Already has a label.' ], 'failed' => [] ] );
		$this->assertNotNull( $fin['pdf'] );
		$this->assertStringContainsString( 'anchor_shipping_bulk_label', $fin['download'] );
		$this->assertStringContainsString( '#' . $a->get_order_number(), $fin['html'] );
		$this->assertStringContainsString( '#' . $b->get_order_number(), $fin['html'] );
		$this->assertStringContainsString( 'Already has a label.', $fin['html'] );
		$merged = file_get_contents( Module::instance()->store->absolute( $fin['pdf'] ) );
		$this->assertSame( 2, preg_match_all( '#/Type\s*/Page\b(?!s)#', $merged ), 'both chunks\' labels are in the one file' );
	}

	public function test_finish_with_nothing_is_an_error() {
		$this->expectException( RuntimeException::class );
		( new ConsoleAjax() )->handle_bulk_finish( [] );
	}

	public function test_alert_emails_link_to_the_console_order_view_when_configured() {
		$this->configure_ups();
		$page = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish' ] );
		$this->configure_ups();
		\Anchor\Shipping\Support\Settings::save( array_merge( \Anchor\Shipping\Support\Settings::all(), [ 'console_page' => $page ] ) );
		delete_option( 'woocommerce_anchor_shipping_ready_to_ship_settings' );
		WC()->mailer()->emails['Anchor_Shipping_Ready_To_Ship_Email']->enabled = 'yes'; // another test switches this shared object off
		$order = $this->make_order( [], 'pending' );
		reset_phpmailer_instance();
		Module::instance()->labels->mark_needs_attention( $order, 'Needs a box.' );
		$body = tests_retrieve_phpmailer_instance()->mock_sent[0]['body'];
		$this->assertStringContainsString( 'order=' . $order->get_id(), $body );
		$this->assertMatchesRegularExpression( '/href="[^"]*order=' . $order->get_id() . '"[^>]*>Create label/', $body );
	}

	public function test_product_save_requires_the_per_product_capability() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );
		$pid = $this->make_product();
		$this->assertTrue( current_user_can( 'edit_product', $pid ), 'baseline: the role may edit products' );
		add_filter( 'user_has_cap', static function ( $allcaps, $caps, $args ) use ( $pid ) {
			if ( 'edit_product' === ( $args[0] ?? '' ) && (int) ( $args[2] ?? 0 ) === $pid ) {
				// edit_product is a post-type meta cap: core maps it to edit_products / edit_others_products ...
				foreach ( $caps as $c ) {
					$allcaps[ $c ] = false;
				}
			}
			return $allcaps;
		}, 20, 3 );
		$this->assertFalse( current_user_can( 'edit_product', $pid ), 'filter in effect' );
		$this->expectException( RuntimeException::class );
		( new ConsoleAjax() )->handle_product_save( [ 'product_id' => $pid, 'regular_price' => '5' ] );
	}
}
