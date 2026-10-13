<?php
declare(strict_types=1);

namespace Anchor\Shipping\Services;

use Anchor\Shipping\Database\ShipmentRepository;
use Anchor\Shipping\Domain\AlreadyLabelled;
use Anchor\Shipping\Support\ParcelFactory;
use Anchor\Shipping\Support\PdfMerge;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Label many orders in one go. Sequential on purpose (each order locks and bills on its own);
 * one order failing never stops the rest. Every purchase goes through LabelService, source "bulk".
 */
final class BulkLabels {

	public function __construct(
		private LabelService $labels,
		private ShipmentRepository $shipments,
		private LabelStore $store
	) {}

	/**
	 * @param array<int, array> $rows each: order_id, box, weight, and length/width/height for a custom box
	 * @return array{created: array<int,int[]>, skipped: array<int,string>, failed: array<int,string>, pdf: ?string, merge_error: string}
	 *         `pdf` is the merged file (a .pdf, or a .zpl when the labels are ZPL) relative to the label folder.
	 */
	public function create( array $rows ): array {
		$out = [ 'created' => [], 'skipped' => [], 'failed' => [], 'pdf' => null, 'merge_error' => '' ];
		foreach ( $rows as $row ) {
			$id = (int) ( $row['order_id'] ?? 0 );
			if ( isset( $out['created'][ $id ] ) || isset( $out['skipped'][ $id ] ) || isset( $out['failed'][ $id ] ) ) {
				continue; // the same order twice must never buy two labels
			}
			$order = $id > 0 ? \wc_get_order( $id ) : false;
			if ( ! $order instanceof \WC_Order ) {
				$out['failed'][ $id ] = \__( 'Order not found.', 'anchor-schema' );
				continue;
			}
			if ( $this->labels->is_labelled( $order ) ) {
				$out['skipped'][ $id ] = \__( 'Already has a label.', 'anchor-schema' );
				continue;
			}
			try {
				$out['created'][ $id ] = $this->labels->create_for_order( $order, [ ParcelFactory::from_row( $row ) ], 'bulk' );
			} catch ( AlreadyLabelled $e ) {
				$out['skipped'][ $id ] = $e->getMessage();
			} catch ( \RuntimeException $e ) { // CarrierError, LabelNotSaved, bad row
				$out['failed'][ $id ] = $e->getMessage();
			}
		}
		if ( $out['created'] ) {
			try {
				$out['pdf'] = $this->merged_file( array_merge( ...array_values( $out['created'] ) ) );
			} catch ( \Throwable $e ) {
				$out['merge_error'] = $e->getMessage(); // labels exist and print one by one; only the combined file is missing
			}
		}
		return $out;
	}

	/** @param int[] $row_ids */
	private function merged_file( array $row_ids ): ?string {
		$pdfs = [];
		$zpl  = '';
		foreach ( $row_ids as $row_id ) {
			$row  = $this->shipments->find( $row_id );
			$path = $row ? $this->store->absolute( (string) $row['label_path'] ) : null;
			if ( ! $path ) {
				continue;
			}
			if ( 'ZPL' === $row['label_format'] ) {
				$zpl .= rtrim( (string) file_get_contents( $path ) ) . "\n";
			} else {
				$pdfs[] = $path;
			}
		}
		// One carrier setting drives the format, so a batch is all-PDF or all-ZPL; PDF wins if it ever mixes.
		if ( $pdfs ) {
			return $this->store->save_bulk( PdfMerge::merge( $pdfs ), 'pdf' );
		}
		return '' !== $zpl ? $this->store->save_bulk( $zpl, 'zpl' ) : null;
	}
}
