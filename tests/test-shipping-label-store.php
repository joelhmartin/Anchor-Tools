<?php
use Anchor\Shipping\Domain\PackageLabel;
use Anchor\Shipping\Services\LabelStore;

class Test_Shipping_Label_Store extends Anchor_Shipping_TestCase {

	private string $base;

	public function set_up() {
		parent::set_up();
		$this->base = get_temp_dir() . 'anchor-shipping-test-' . wp_generate_password( 6, false );
	}

	public function tear_down() {
		array_map( 'unlink', array_filter( glob( $this->base . '/{,.}*', GLOB_BRACE ) ?: [], 'is_file' ) );
		@rmdir( $this->base );
		parent::tear_down();
	}

	private function landscape_gif(): string {
		$im = imagecreatetruecolor( 1400, 800 );
		imagefill( $im, 0, 0, imagecolorallocate( $im, 255, 255, 255 ) );
		ob_start();
		imagegif( $im );
		return (string) ob_get_clean();
	}

	public function test_gif_label_becomes_a_portrait_4x6_pdf() {
		$store = new LabelStore( $this->base );
		$saved = $store->save( 42, new PackageLabel( '1ZTEST', $this->landscape_gif(), 'GIF' ) );
		$this->assertSame( 'PDF', $saved['format'] );
		$this->assertMatchesRegularExpression( '/^42-1ZTEST-[a-zA-Z0-9]{32}\.pdf$/', $saved['path'] );
		$pdf = (string) file_get_contents( $store->absolute( $saved['path'] ) );
		$this->assertStringStartsWith( '%PDF', $pdf );
		$this->assertMatchesRegularExpression( '/MediaBox \[0 0 288\.00 432\.00\]/', $pdf, '4in x 6in portrait' );
	}

	public function test_zpl_is_stored_verbatim() {
		$store = new LabelStore( $this->base );
		$saved = $store->save( 7, new PackageLabel( '1ZZPL', "^XA^FDtest^FS^XZ", 'ZPL' ) );
		$this->assertSame( 'ZPL', $saved['format'] );
		$this->assertSame( "^XA^FDtest^FS^XZ", file_get_contents( $store->absolute( $saved['path'] ) ) );
		$this->assertSame( 'application/pdf', $store->content_type( 'PDF' ) );
		$this->assertSame( 'text/plain; charset=utf-8', $store->content_type( 'ZPL' ) );
	}

	public function test_dir_is_locked_down_and_paths_cannot_escape() {
		$store = new LabelStore( $this->base );
		$store->dir();
		$this->assertFileExists( $this->base . '/.htaccess' );
		$this->assertFileExists( $this->base . '/index.php' );
		$this->assertNull( $store->absolute( '../../wp-config.php' ) );
		$this->assertNull( $store->absolute( 'missing.pdf' ) );
	}
}
