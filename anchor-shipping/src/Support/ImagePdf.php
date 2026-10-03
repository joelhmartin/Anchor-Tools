<?php
declare(strict_types=1);

namespace Anchor\Shipping\Support;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Minimal lossless image→PDF: one page per GD image, scaled to the page width, top-aligned. Grayscale + FlateDecode keeps barcodes crisp. */
final class ImagePdf {

	/**
	 * @param \GdImage[] $images
	 * @return string PDF bytes
	 */
	public static function from_images( array $images, float $width_in, float $height_in ): string {
		if ( ! $images ) {
			throw new \InvalidArgumentException( 'At least one image is required.' );
		}
		$pw = $width_in * 72;
		$ph = $height_in * 72;
		$n  = count( $images );

		$objs = [];
		$kids = [];
		foreach ( array_values( $images ) as $i => $im ) {
			$page_id = 3 + $i * 3;
			$kids[]  = $page_id . ' 0 R';
			$w       = \imagesx( $im );
			$h       = \imagesy( $im );

			$draw_w = $pw;
			$draw_h = $draw_w * $h / $w;
			if ( $draw_h > $ph ) {
				$draw_h = $ph;
				$draw_w = $draw_h * $w / $h;
			}
			$content = sprintf( 'q %.2F 0 0 %.2F 0 %.2F cm /Im0 Do Q', $draw_w, $draw_h, $ph - $draw_h );
			$data    = \gzcompress( self::gray_bytes( $im, $w, $h ) );

			$objs[ $page_id ]     = sprintf( '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /XObject << /Im0 %d 0 R >> >> /Contents %d 0 R >>', $pw, $ph, $page_id + 2, $page_id + 1 );
			$objs[ $page_id + 1 ] = sprintf( "<< /Length %d >>\nstream\n%s\nendstream", strlen( $content ), $content );
			$objs[ $page_id + 2 ] = sprintf( "<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length %d >>\nstream\n%s\nendstream", $w, $h, strlen( $data ), $data );
		}
		$objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
		$objs[2] = sprintf( '<< /Type /Pages /Kids [%s] /Count %d >>', implode( ' ', $kids ), $n );
		ksort( $objs );

		$out     = "%PDF-1.4\n";
		$offsets = [];
		foreach ( $objs as $id => $body ) {
			$offsets[ $id ] = strlen( $out );
			$out           .= "$id 0 obj\n$body\nendobj\n";
		}
		$size = count( $objs ) + 1;
		$xref = strlen( $out );
		$out .= "xref\n0 $size\n0000000000 65535 f \n";
		foreach ( $offsets as $off ) {
			$out .= sprintf( "%010d 00000 n \n", $off );
		}
		return $out . "trailer\n<< /Size $size /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
	}

	/** One gray byte per pixel, row-major, top to bottom. */
	private static function gray_bytes( $im, int $w, int $h ): string {
		$true = \imageistruecolor( $im );
		$rows = [];
		for ( $y = 0; $y < $h; $y++ ) {
			$row = '';
			for ( $x = 0; $x < $w; $x++ ) {
				$c = \imagecolorat( $im, $x, $y );
				if ( $true ) {
					$r = ( $c >> 16 ) & 0xFF;
					$g = ( $c >> 8 ) & 0xFF;
					$b = $c & 0xFF;
				} else {
					$p = \imagecolorsforindex( $im, $c );
					$r = $p['red'];
					$g = $p['green'];
					$b = $p['blue'];
				}
				$row .= chr( (int) round( 0.299 * $r + 0.587 * $g + 0.114 * $b ) );
			}
			$rows[] = $row;
		}
		return implode( '', $rows );
	}
}
