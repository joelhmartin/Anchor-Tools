<?php
declare(strict_types=1);

namespace Anchor\Shipping\Support;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Concatenates label PDFs without a PDF library. It understands ONLY the files ImagePdf writes
 * (one gray FlateDecode image per page, a MediaBox per page) and refuses anything else.
 */
final class PdfMerge {

	/**
	 * @param string[] $paths absolute paths of ImagePdf files, in output order
	 * @return string PDF bytes
	 * @throws \RuntimeException when a file is not an ImagePdf document
	 */
	public static function merge( array $paths ): string {
		$pages = [];
		foreach ( $paths as $path ) {
			$pages = array_merge( $pages, self::pages( $path ) );
		}
		if ( ! $pages ) {
			throw new \InvalidArgumentException( 'At least one PDF is required.' );
		}
		return ImagePdf::from_streams( $pages );
	}

	/** True when the file is one this class can merge. */
	public static function can_merge( string $path ): bool {
		try {
			self::pages( $path );
			return true;
		} catch ( \RuntimeException $e ) {
			return false;
		}
	}

	/** @return array<int, array{w:int, h:int, data:string, pw:float, ph:float}> */
	private static function pages( string $path ): array {
		$bytes = is_file( $path ) ? (string) file_get_contents( $path ) : '';
		$boxes = [];
		$imgs  = [];
		if ( ! str_starts_with( $bytes, '%PDF-1.4' )
			|| ! preg_match_all( '#/Type /Page /Parent 2 0 R /MediaBox \[0 0 ([0-9.]+) ([0-9.]+)\]#', $bytes, $boxes, PREG_SET_ORDER )
			|| ! preg_match_all( '#/Subtype /Image /Width (\d+) /Height (\d+) /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length (\d+) >>\nstream\n#', $bytes, $imgs, PREG_SET_ORDER | PREG_OFFSET_CAPTURE )
			|| count( $boxes ) !== count( $imgs ) ) {
			throw new \RuntimeException( 'Not a label PDF this plugin created.' );
		}
		$pages = [];
		foreach ( $imgs as $i => $m ) {
			$len   = (int) $m[3][0];
			$start = $m[0][1] + strlen( $m[0][0] );
			$data  = substr( $bytes, $start, $len );
			if ( strlen( $data ) !== $len || "\nendstream" !== substr( $bytes, $start + $len, 10 ) ) {
				throw new \RuntimeException( 'Not a label PDF this plugin created.' );
			}
			$pages[] = [ 'w' => (int) $m[1][0], 'h' => (int) $m[2][0], 'data' => $data, 'pw' => (float) $boxes[ $i ][1], 'ph' => (float) $boxes[ $i ][2] ];
		}
		return $pages;
	}
}
