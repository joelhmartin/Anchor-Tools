<?php
/**
 * Excel (OneDrive / SharePoint) adapter over Microsoft Graph. Written ranges get number format "@" (text) so nothing becomes a formula.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Anchor_Sheet_Excel' ) ) {
    class Anchor_Sheet_Excel implements Anchor_Sheet_Adapter {
        const API = 'https://graph.microsoft.com/v1.0';

        private function call( $method, $url, $body = null ) {
            return Anchor_Sheet_Adapters::call( 'microsoft', $method, $url, $body );
        }

        private function ws( array $ref ) {
            return self::API . '/drives/' . rawurlencode( (string) ( $ref['drive_id'] ?? '' ) )
                . '/items/' . rawurlencode( (string) ( $ref['item_id'] ?? '' ) )
                . '/workbook/worksheets/' . rawurlencode( (string) ( $ref['worksheet'] ?? '' ) );
        }

        public function resolve( $url ) {
            $url = trim( (string) $url );
            if ( ! $url || ! Anchor_Sheet_Adapters::for_url( $url ) instanceof self ) {
                return new WP_Error( 'anchor_sheet_bad_url', __( 'That is not an Excel / OneDrive / SharePoint link.', 'anchor-schema' ) );
            }
            $enc  = 'u!' . rtrim( strtr( base64_encode( $url ), '+/', '-_' ), '=' );
            $item = $this->call( 'GET', self::API . '/shares/' . $enc . '/driveItem' );
            if ( is_wp_error( $item ) ) {
                return $item;
            }
            $drive = (string) ( $item['parentReference']['driveId'] ?? '' );
            $id    = (string) ( $item['id'] ?? '' );
            if ( '' === $drive || '' === $id ) {
                return new WP_Error( 'anchor_sheet_forbidden', __( 'Cannot open that spreadsheet. Check the link.', 'anchor-schema' ) );
            }
            $sheets = $this->call( 'GET', self::API . '/drives/' . rawurlencode( $drive ) . '/items/' . rawurlencode( $id ) . '/workbook/worksheets' );
            if ( is_wp_error( $sheets ) ) {
                return $sheets;
            }
            $name = (string) ( $sheets['value'][0]['name'] ?? '' );
            if ( '' === $name ) {
                return new WP_Error( 'anchor_sheet_no_tab', __( 'That workbook has no worksheets.', 'anchor-schema' ) );
            }
            return array( 'provider' => 'microsoft', 'drive_id' => $drive, 'item_id' => $id, 'worksheet' => $name );
        }

        public function read( array $ref ) {
            $res = $this->call( 'GET', $this->ws( $ref ) . '/usedRange(valuesOnly=true)' );
            if ( is_wp_error( $res ) ) {
                return $res;
            }
            $rows = Anchor_Sheet_Adapters::stringify( $res['values'] ?? array() );
            foreach ( $rows as $r ) {
                if ( array_filter( $r, static function ( $v ) { return '' !== $v; } ) ) {
                    return $rows;
                }
            }
            return array(); // An empty sheet reports a single blank used cell.
        }

        /** PATCH one contiguous range with text format. */
        private function patch( array $ref, $address, array $values ) {
            $fmt = array();
            foreach ( $values as $row ) {
                $fmt[] = array_fill( 0, count( $row ), '@' );
            }
            $res = $this->call( 'PATCH', $this->ws( $ref ) . "/range(address='" . $address . "')", array( 'numberFormat' => $fmt, 'values' => $values ) );
            return is_wp_error( $res ) ? $res : true;
        }

        public function write_cells( array $ref, array $cells ) {
            $by_row = array();
            foreach ( $cells as $c ) {
                $by_row[ (int) $c[0] ][ (int) $c[1] ] = (string) $c[2];
            }
            ksort( $by_row );
            foreach ( $by_row as $row => $cols ) {
                ksort( $cols );
                $run = array();
                $prev = null;
                $runs = array();
                foreach ( $cols as $col => $val ) {
                    if ( null !== $prev && $col !== $prev + 1 ) {
                        $runs[] = $run;
                        $run    = array();
                    }
                    $run[ $col ] = $val;
                    $prev        = $col;
                }
                $runs[] = $run;
                foreach ( $runs as $r ) {
                    $first = array_key_first( $r );
                    $last  = array_key_last( $r );
                    $addr  = Anchor_Sheet_Adapters::col_letter( $first ) . $row . ':' . Anchor_Sheet_Adapters::col_letter( $last ) . $row;
                    $res   = $this->patch( $ref, $addr, array( array_values( $r ) ) );
                    if ( is_wp_error( $res ) ) {
                        return $res;
                    }
                }
            }
            return true;
        }

        public function append_rows( array $ref, array $rows ) {
            if ( ! $rows ) {
                return true;
            }
            $used = $this->call( 'GET', $this->ws( $ref ) . '/usedRange(valuesOnly=true)?$select=rowIndex,rowCount' );
            if ( is_wp_error( $used ) ) {
                return $used;
            }
            $start = (int) ( $used['rowIndex'] ?? 0 ) + (int) ( $used['rowCount'] ?? 0 ) + 1;
            $width = max( array_map( 'count', $rows ) );
            $vals  = array();
            foreach ( $rows as $r ) {
                $r      = array_map( 'strval', array_values( $r ) );
                $vals[] = array_pad( $r, $width, '' );
            }
            $addr = 'A' . $start . ':' . Anchor_Sheet_Adapters::col_letter( $width - 1 ) . ( $start + count( $vals ) - 1 );
            return $this->patch( $ref, $addr, $vals );
        }

        public function ensure_columns( array $ref, $count ) {
            return true; // Excel has no fixed grid limit.
        }
    }
}
