<?php
declare(strict_types=1);

namespace Anchor\Shipping\Support;

use setasign\Fpdi\Fpdi;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Concatenates PDF files into one document, keeping each page's own size. */
final class PdfMerge {

	/**
	 * @param string[] $paths absolute paths of the PDFs, in output order
	 * @return string PDF bytes
	 * @throws \RuntimeException when a file cannot be read as a PDF
	 */
	public static function merge( array $paths ): string {
		if ( ! $paths ) {
			throw new \InvalidArgumentException( 'At least one PDF is required.' );
		}
		$pdf = new Fpdi();
		$pdf->SetAutoPageBreak( false );
		try {
			foreach ( $paths as $path ) {
				$count = $pdf->setSourceFile( $path );
				for ( $n = 1; $n <= $count; $n++ ) {
					$tpl  = $pdf->importPage( $n );
					$size = $pdf->getTemplateSize( $tpl );
					$pdf->AddPage( $size['orientation'], [ $size['width'], $size['height'] ] );
					$pdf->useTemplate( $tpl );
				}
			}
			return $pdf->Output( 'S' );
		} catch ( \Throwable $e ) {
			throw new \RuntimeException( 'Could not merge the label PDFs: ' . $e->getMessage(), 0, $e );
		}
	}
}
