<?php
use Anchor\Shipping\Support\ImagePdf;

class Test_Shipping_Image_Pdf extends Anchor_Shipping_TestCase {

	private function black( int $w, int $h ) {
		$im = imagecreatetruecolor( $w, $h );
		imagefill( $im, 0, 0, imagecolorallocate( $im, 0, 0, 0 ) );
		return $im;
	}

	public function test_two_images_make_two_pages() {
		$pdf = ImagePdf::from_images( [ $this->black( 10, 15 ), $this->black( 20, 30 ) ], 4, 6 );
		$this->assertStringStartsWith( '%PDF-1.4', $pdf );
		$this->assertStringContainsString( '/Count 2', $pdf );
		$this->assertSame( 2, substr_count( $pdf, '/Subtype /Image' ) );
		$this->assertStringContainsString( '/MediaBox [0 0 288.00 432.00]', $pdf );
	}

	public function test_empty_list_throws() {
		$this->expectException( \InvalidArgumentException::class );
		ImagePdf::from_images( [], 4, 6 );
	}

	public function test_xref_offsets_point_at_their_objects() {
		$pdf = ImagePdf::from_images( [ $this->black( 10, 15 ), $this->black( 8, 8 ) ], 4, 6 );
		preg_match( '/startxref\n(\d+)\n%%EOF\n?$/', $pdf, $m );
		$xref = (int) $m[1];
		$this->assertSame( 'xref', substr( $pdf, $xref, 4 ) );
		preg_match( '/^xref\n0 (\d+)\n/', substr( $pdf, $xref ), $h );
		$count = (int) $h[1];
		preg_match_all( '/(\d{10}) (\d{5}) ([nf]) \n/', substr( $pdf, $xref ), $rows, PREG_SET_ORDER );
		$this->assertCount( $count, $rows );
		$this->assertSame( 'f', $rows[0][3] );
		for ( $n = 1; $n < $count; $n++ ) {
			$this->assertSame( "$n 0 obj", substr( $pdf, (int) $rows[ $n ][1], strlen( "$n 0 obj" ) ), "object $n" );
		}
		$this->assertStringContainsString( "/Size $count", $pdf );
	}

	public function test_image_is_scaled_to_page_width_and_top_aligned() {
		$pdf = ImagePdf::from_images( [ $this->black( 100, 150 ) ], 4, 6 );
		$this->assertStringContainsString( 'q 288.00 0 0 432.00 0 0.00 cm /Im0 Do Q', $pdf );
		$pdf = ImagePdf::from_images( [ $this->black( 100, 75 ) ], 4, 6 );
		$this->assertStringContainsString( 'q 288.00 0 0 216.00 0 216.00 cm /Im0 Do Q', $pdf );
	}
}
