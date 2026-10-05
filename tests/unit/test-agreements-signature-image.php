<?php
// tests/unit/test-agreements-signature-image.php
use Anchor\Agreements\Support\SignatureImage;
use PHPUnit\Framework\TestCase;

class Test_Agreements_Signature_Image extends TestCase {
	// Valid 1x1 PNG.
	const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

	public function test_valid_png_decodes() {
		$bytes = SignatureImage::decode( 'data:image/png;base64,' . self::PNG );
		$this->assertSame( "\x89PNG\r\n\x1a\n", substr( (string) $bytes, 0, 8 ) );
	}

	/** @dataProvider bad_inputs */
	public function test_rejects( string $input ) {
		$this->assertNull( SignatureImage::decode( $input ) );
	}

	public function bad_inputs(): array {
		$svg  = base64_encode( '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>' );
		$jpeg = base64_encode( "\xFF\xD8\xFF\xE0" . str_repeat( 'x', 50 ) );
		$big  = base64_encode( "\x89PNG\r\n\x1a\n" . str_repeat( 'A', 300000 ) );
		return [
			'empty'          => [ '' ],
			'svg mime'       => [ 'data:image/svg+xml;base64,' . $svg ],
			'html mime'      => [ 'data:text/html;base64,' . base64_encode( '<b>x</b>' ) ],
			'jpeg bytes'     => [ 'data:image/png;base64,' . $jpeg ],
			'png magic only' => [ 'data:image/png;base64,' . base64_encode( "\x89PNG\r\n\x1a\nnot-a-real-png" ) ],
			'too big'        => [ 'data:image/png;base64,' . $big ],
			'bad base64'     => [ 'data:image/png;base64,@@@!!!' ],
			'truncated'      => [ 'data:image/png;base64,' . substr( self::PNG, 0, 20 ) ],
		];
	}

	public function test_rejects_oversized_dimensions() {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			$this->markTestSkipped( 'GD not available' );
		}
		$im = imagecreatetruecolor( 2500, 10 );
		ob_start();
		imagepng( $im );
		$png = ob_get_clean();
		$this->assertNull( SignatureImage::decode( 'data:image/png;base64,' . base64_encode( $png ) ) );
	}
}
