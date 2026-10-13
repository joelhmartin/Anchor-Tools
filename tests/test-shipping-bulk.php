<?php
use Anchor\Shipping\Frontend\ConsoleAjax;
use Anchor\Shipping\Module;
use Anchor\Shipping\Admin\Ajax;
use Anchor\Shipping\Admin\OrdersColumn;
use Anchor\Shipping\Services\BulkLabels;
use Anchor\Shipping\Services\LabelService;
use Anchor\Shipping\Support\PdfMerge;
use Anchor\Shipping\Support\TrackingLinks;

class Test_Shipping_Bulk extends Anchor_Shipping_TestCase {

	private function bulk(): BulkLabels {
		$m = Module::instance();
		return new BulkLabels( $m->labels, $m->shipments, $m->store );
	}

	private function row( WC_Order $o, array $extra = [] ): array {
		return [ 'order_id' => $o->get_id(), 'box' => 'small', 'weight' => '1' ] + $extra;
	}

	private function page_count( string $bytes ): int {
		return preg_match_all( '#/Type\s*/Page\b(?!s)#', $bytes );
	}

	public function test_merging_two_fixture_pdfs_gives_two_pages() {
		$dir   = __DIR__ . '/fixtures/shipping/';
		$bytes = PdfMerge::merge( [ $dir . 'page-a.pdf', $dir . 'page-b.pdf' ] );
		$this->assertStringStartsWith( '%PDF', $bytes );
		$this->assertSame( 2, $this->page_count( $bytes ) );
	}

	public function test_merge_rejects_a_file_that_is_not_a_pdf() {
		$f = wp_tempnam( 'notpdf' );
		file_put_contents( $f, 'hello' );
		$this->expectException( RuntimeException::class );
		PdfMerge::merge( [ $f ] );
	}

	public function test_mixed_batch_sorts_into_created_skipped_and_failed() {
		$this->configure_ups();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );
		$ok1 = $this->make_order( [], 'processing' );
		$ok2 = $this->make_order( [], 'processing' );
		$had = $this->make_order( [], 'processing' );
		$bad = $this->make_order( [], 'processing' );
		$no_weight = $this->make_order( [], 'processing' );

		$this->queue_token();
		$this->queue_response( 200, $this->fixture( 'ups-ship-single' ) );
		Module::instance()->labels->create_for_order( $had, [ new \Anchor\Shipping\Domain\Parcel( 1, 10, 10, 10 ) ], 'manual' );

		$this->queue_response( 200, $this->fixture( 'ups-ship-single' ) ); // ok1
		$this->queue_response( 400, [ 'response' => [ 'errors' => [ [ 'code' => '120100', 'message' => 'Missing or invalid shipper number' ] ] ] ] ); // bad
		$this->queue_response( 200, $this->fixture( 'ups-ship-single' ) ); // ok2

		$res = $this->bulk()->create( [
			$this->row( $ok1 ),
			$this->row( $had ),
			$this->row( $bad ),
			$this->row( $ok2 ),
			$this->row( $no_weight, [ 'weight' => '' ] ),
			[ 'order_id' => 999999, 'box' => 'small', 'weight' => '1' ],
		] );

		$this->assertSame( [ $ok1->get_id(), $ok2->get_id() ], array_keys( $res['created'] ) );
		$this->assertCount( 1, $res['created'][ $ok1->get_id() ] );
		$this->assertSame( [ $had->get_id() ], array_keys( $res['skipped'] ) );
		$this->assertEqualsCanonicalizing( [ $bad->get_id(), $no_weight->get_id(), 999999 ], array_keys( $res['failed'] ) );
		$this->assertStringContainsString( 'shipper number', $res['failed'][ $bad->get_id() ] );
		$this->assertCount( 1, Module::instance()->shipments->active_for_order( $ok1->get_id() ) );
		$this->assertSame( 'bulk', Module::instance()->shipments->active_for_order( $ok1->get_id() )[0]['source'] );

		// Merged PDF holds one page per created label, is stored privately and downloadable.
		$this->assertNotNull( $res['pdf'] );
		$path = Module::instance()->store->absolute( $res['pdf'] );
		$this->assertNotNull( $path );
		$this->assertSame( 2, $this->page_count( (string) file_get_contents( $path ) ) );
	}

	public function test_nothing_created_means_no_merged_file() {
		$this->configure_ups();
		$had = $this->make_order( [], 'processing' );
		$this->queue_token();
		$this->queue_response( 200, $this->fixture( 'ups-ship-single' ) );
		Module::instance()->labels->create_for_order( $had, [ new \Anchor\Shipping\Domain\Parcel( 1, 10, 10, 10 ) ], 'manual' );
		$res = $this->bulk()->create( [ $this->row( $had ) ] );
		$this->assertSame( [], $res['created'] );
		$this->assertNull( $res['pdf'] );
	}

	public function test_zpl_labels_are_concatenated_into_one_zpl_file() {
		$this->configure_ups( [ 'label_format' => 'ZPL' ] );
		$f = $this->fixture( 'ups-ship-single' );
		$f['ShipmentResponse']['ShipmentResults']['PackageResults']['ShippingLabel']['GraphicImage'] = base64_encode( '^XA^FDone^FS^XZ' );
		$f['ShipmentResponse']['ShipmentResults']['PackageResults']['ShippingLabel']['ImageFormat']['Code'] = 'ZPL';
		$a = $this->make_order( [], 'processing' );
		$b = $this->make_order( [], 'processing' );
		$this->queue_token();
		$this->queue_response( 200, $f );
		$this->queue_response( 200, $f );
		$res = $this->bulk()->create( [ $this->row( $a ), $this->row( $b ) ] );
		$this->assertCount( 2, $res['created'] );
		$this->assertStringEndsWith( '.zpl', (string) $res['pdf'] );
		$this->assertSame( 2, substr_count( (string) file_get_contents( Module::instance()->store->absolute( $res['pdf'] ) ), '^XA' ) );
	}

	public function test_bulk_download_requires_a_real_bulk_file() {
		$m   = Module::instance();
		$aj  = new Ajax( $m->labels, $m->shipments, $m->store, $m->packer, $m->carriers );
		$rel = $m->store->save_bulk( '%PDF-1.4 x', 'pdf' );
		$dl  = $aj->handle_bulk_download( $rel );
		$this->assertSame( 'application/pdf', $dl['type'] );
		$this->assertFileExists( $dl['path'] );
		$this->assertStringContainsString( 'anchor_shipping_bulk_label', Ajax::bulk_download_url( $rel ) );
		foreach ( [ '../wp-config.php', '1-1ZABC-token.pdf', 'bulk-missing.pdf' ] as $bad ) {
			try {
				$aj->handle_bulk_download( $bad );
				$this->fail( "accepted $bad" );
			} catch ( RuntimeException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}

	public function test_console_bulk_create_handler_returns_result_html_and_download() {
		$this->configure_ups();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );
		$o = $this->make_order( [], 'processing' );
		$this->queue_token();
		$this->queue_response( 200, $this->fixture( 'ups-ship-single' ) );
		$out = ( new ConsoleAjax( $this->bulk() ) )->handle_bulk_create( [ 'rows' => [ [ 'order_id' => $o->get_id(), 'box' => 'small', 'weight' => '1' ] ] ] );
		$this->assertSame( [ $o->get_id() ], array_keys( $out['created'] ) );
		$this->assertStringContainsString( 'anchor_shipping_bulk_label', $out['download'] );
		$this->assertStringContainsString( '#' . $o->get_order_number(), $out['html'] );
		$this->expectException( RuntimeException::class );
		( new ConsoleAjax( $this->bulk() ) )->handle_bulk_create( [ 'rows' => [] ] );
	}

	public function test_orders_column_renders_each_state() {
		$this->configure_ups();
		$m   = Module::instance();
		$col = new OrdersColumn( new TrackingLinks( $m->shipments, $m->carriers ), $m->labels );

		$none = $this->make_order( [], 'processing' );
		$this->assertStringContainsString( 'Needs label', $col->render_cell( $none ) );

		$flag = $this->make_order( [], 'processing' );
		$flag->update_meta_data( LabelService::STATE_META, 'needs_attention' );
		$flag->save();
		$this->assertStringContainsString( 'Needs attention', $col->render_cell( $flag ) );

		$done = $this->make_order( [], 'processing' );
		$this->queue_token();
		$this->queue_response( 200, $this->fixture( 'ups-ship-single' ) );
		$m->labels->create_for_order( $done, [ new \Anchor\Shipping\Domain\Parcel( 1, 10, 10, 10 ) ], 'manual' );
		$html = $col->render_cell( wc_get_order( $done->get_id() ) );
		$this->assertStringContainsString( 'Labelled', $html );
		$this->assertStringContainsString( '1ZK877V90300000001', $html );

		$pending = $this->make_order( [], 'pending' );
		$this->assertSame( '', $col->render_cell( $pending ) );
		$virtual = $this->make_order( [], 'processing', false );
		$this->assertSame( '', $col->render_cell( $virtual ) );
	}

	public function test_bulk_action_redirects_to_the_console_view_when_a_page_is_set() {
		$page = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '[shop_manager]' ] );
		$this->configure_ups( [ 'console_page' => $page ] );
		$url = \Anchor\Shipping\Admin\BulkActions::redirect_url( [ 5, 7 ] );
		$this->assertStringContainsString( get_permalink( $page ), $url );
		$this->assertStringContainsString( 'bulk=1', $url );
		$this->assertStringContainsString( 'ids=5,7', $url );
	}

	public function test_bulk_action_falls_back_to_the_admin_screen_without_a_console_page() {
		$this->configure_ups();
		$this->assertStringContainsString( 'page=anchor-shipping-bulk', \Anchor\Shipping\Admin\BulkActions::redirect_url( [ 5, 7 ] ) );
	}

	public function test_bulk_view_prefills_rows_and_blocks_labelled_orders() {
		$this->configure_ups();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );
		$ready = $this->make_order( [], 'processing' );
		$had   = $this->make_order( [], 'processing' );
		$this->queue_token();
		$this->queue_response( 200, $this->fixture( 'ups-ship-single' ) );
		Module::instance()->labels->create_for_order( $had, [ new \Anchor\Shipping\Domain\Parcel( 1, 10, 10, 10 ) ], 'manual' );
		$html = Module::instance()->console->bulk_view( [ $ready->get_id(), $had->get_id(), 999999 ], 'http://example.org/back' );
		$this->assertStringContainsString( 'data-order-id="' . $ready->get_id() . '"', $html );
		$this->assertMatchesRegularExpression( '/<option value="small"\s+selected/', $html );
		$this->assertStringContainsString( 'Already has a label.', $html );
		$this->assertSame( 1, substr_count( $html, 'asm-bulk-row is-blocked' ) );
		$this->assertSame( 2, preg_match_all( '/<tr class="asm-bulk-row/', $html ) );
	}
}
