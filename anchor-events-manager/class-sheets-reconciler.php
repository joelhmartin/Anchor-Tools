<?php
/**
 * Pure diff between a spreadsheet's current values and the roster table.
 *
 * No WordPress calls: takes arrays, returns the writes needed. Columns are
 * matched by header text; hand-added sheet columns are never written.
 *
 * @package Anchor\Events
 */

namespace Anchor\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sheets_Reconciler {

	/**
	 * @param array  $existing   Sheet values; row 0 is the header, rows may be ragged.
	 * @param array  $table      ['header' => string[], 'rows' => array[]], newest first.
	 * @param string $key_header Header text of the unique row key column (Seat ID).
	 * @return array{header_append:string[],header_start_col:int,cells:array,appends:string[][]}
	 */
	public static function reconcile( array $existing, array $table, string $key_header ): array {
		$our_header = array_map( 'strval', array_values( $table['header'] ?? array() ) );
		$rows       = $table['rows'] ?? array();

		$sheet_header = array();
		if ( isset( $existing[0] ) && is_array( $existing[0] ) ) {
			$sheet_header = array_map( 'strval', array_values( $existing[0] ) );
		}
		$start_col = count( $sheet_header );

		$final         = $sheet_header;
		$header_append = array();
		foreach ( $our_header as $h ) {
			if ( ! in_array( $h, $final, true ) ) {
				$final[]         = $h;
				$header_append[] = $h;
			}
		}

		// Our column position in the table => position in the final header.
		$col_map = array();
		foreach ( $our_header as $ti => $h ) {
			$col_map[ $ti ] = (int) array_search( $h, $final, true );
		}
		$key_ti = array_search( $key_header, $our_header, true );
		if ( false === $key_ti ) {
			return array( 'header_append' => $header_append, 'header_start_col' => $start_col, 'cells' => array(), 'appends' => array() );
		}
		$key_col = $col_map[ $key_ti ];

		// Index sheet rows by key; first occurrence wins.
		$by_key = array();
		foreach ( $existing as $i => $row ) {
			if ( 0 === $i || ! is_array( $row ) ) {
				continue;
			}
			$k = isset( $row[ $key_col ] ) ? (string) $row[ $key_col ] : '';
			if ( '' !== $k && ! isset( $by_key[ $k ] ) ) {
				$by_key[ $k ] = $i;
			}
		}

		$cells   = array();
		$appends = array();
		$width   = count( $final );
		foreach ( array_reverse( $rows ) as $row ) {
			$k = (string) ( $row[ $key_ti ] ?? '' );
			if ( isset( $by_key[ $k ] ) ) {
				$si = $by_key[ $k ];
				foreach ( $col_map as $ti => $ci ) {
					$want = (string) ( $row[ $ti ] ?? '' );
					$have = isset( $existing[ $si ][ $ci ] ) ? (string) $existing[ $si ][ $ci ] : '';
					if ( $want !== $have ) {
						$cells[] = array( $si + 1, $ci, $want );
					}
				}
			} else {
				$out = array_fill( 0, $width, '' );
				foreach ( $col_map as $ti => $ci ) {
					$out[ $ci ] = (string) ( $row[ $ti ] ?? '' );
				}
				$appends[] = $out;
			}
		}

		return array(
			'header_append'    => $header_append,
			'header_start_col' => $start_col,
			'cells'            => $cells,
			'appends'          => $appends,
		);
	}
}
