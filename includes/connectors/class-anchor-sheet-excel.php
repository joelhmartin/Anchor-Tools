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

        /** A1-anchored matrix: usedRange values start at rowIndex/columnIndex, so pad the offset. */
        public function read( array $ref ) {
            $res = $this->call( 'GET', $this->ws( $ref ) . '/usedRange(valuesOnly=true)?$select=values,rowIndex,columnIndex' );
            if ( is_wp_error( $res ) ) {
                return $res;
            }
            $rows = Anchor_Sheet_Adapters::stringify( $res['values'] ?? array() );
            if ( self::all_blank( $rows ) ) {
                return array(); // An empty sheet reports a single blank used cell.
            }
            $lead = array_fill( 0, max( 0, (int) ( $res['columnIndex'] ?? 0 ) ), '' );
            foreach ( $rows as $i => $r ) {
                $rows[ $i ] = array_merge( $lead, $r );
            }
            return array_merge( array_fill( 0, max( 0, (int) ( $res['rowIndex'] ?? 0 ) ), array() ), $rows );
        }

        private static function all_blank( array $rows ) {
            foreach ( $rows as $r ) {
                foreach ( $r as $v ) {
                    if ( '' !== $v ) {
                        return false;
                    }
                }
            }
            return true;
        }

        /** One contiguous range: text number format first (so a leading "=" stays text), then the values. */
        private function patch( array $ref, $address, array $values ) {
            $fmt = array();
            foreach ( $values as $row ) {
                $fmt[] = array_fill( 0, count( $row ), '@' );
            }
            $url = $this->ws( $ref ) . "/range(address='" . $address . "')";
            $res = $this->call( 'PATCH', $url, array( 'numberFormat' => $fmt ) );
            if ( is_wp_error( $res ) ) {
                return $res;
            }
            $res = $this->call( 'PATCH', $url, array( 'values' => $values ) );
            return is_wp_error( $res ) ? $res : true;
        }

        const BATCH_MAX = 20; // Graph JSON batching limit per $batch request.

        /**
         * Rectangular blocks covering the cells: each row's contiguous column runs,
         * merged down consecutive rows that share the same column span (a new
         * column over 300 rows is one block, not 300).
         *
         * @return array[] Each ['top' => int, 'first' => int, 'last' => int, 'values' => string[][]].
         */
        private static function blocks( array $cells ) {
            $by_row = array();
            foreach ( $cells as $c ) {
                $by_row[ (int) $c[0] ][ (int) $c[1] ] = (string) $c[2];
            }
            ksort( $by_row );
            $done = array();
            $open = array(); // "first:last" => block still growing downwards.
            foreach ( $by_row as $row => $cols ) {
                ksort( $cols );
                $runs = array();
                $prev = null;
                foreach ( $cols as $col => $val ) {
                    if ( null === $prev || $col !== $prev + 1 ) {
                        $runs[] = array();
                    }
                    $runs[ count( $runs ) - 1 ][ $col ] = $val;
                    $prev                               = $col;
                }
                foreach ( $runs as $run ) {
                    $first = array_key_first( $run );
                    $last  = array_key_last( $run );
                    $key   = $first . ':' . $last;
                    if ( isset( $open[ $key ] ) && $open[ $key ]['top'] + count( $open[ $key ]['values'] ) === $row ) {
                        $open[ $key ]['values'][] = array_values( $run );
                        continue;
                    }
                    if ( isset( $open[ $key ] ) ) {
                        $done[] = $open[ $key ];
                    }
                    $open[ $key ] = array( 'top' => $row, 'first' => $first, 'last' => $last, 'values' => array( array_values( $run ) ) );
                }
            }
            return array_merge( $done, array_values( $open ) );
        }

        /**
         * Writes through Graph JSON batching: per block a text-format PATCH then a
         * values PATCH, every request chained with dependsOn so the workbook sees
         * them strictly in order (format before values; no parallel Excel writes).
         */
        public function write_cells( array $ref, array $cells ) {
            $path = substr( $this->ws( $ref ), strlen( self::API ) );
            $reqs = array();
            foreach ( self::blocks( $cells ) as $b ) {
                $addr  = Anchor_Sheet_Adapters::col_letter( $b['first'] ) . $b['top'] . ':'
                    . Anchor_Sheet_Adapters::col_letter( $b['last'] ) . ( $b['top'] + count( $b['values'] ) - 1 );
                $url   = $path . "/range(address='" . $addr . "')";
                $fmt   = array_fill( 0, count( $b['values'] ), array_fill( 0, $b['last'] - $b['first'] + 1, '@' ) );
                $reqs[] = array( 'url' => $url, 'body' => array( 'numberFormat' => $fmt ) );
                $reqs[] = array( 'url' => $url, 'body' => array( 'values' => $b['values'] ) );
            }
            // BATCH_MAX is even, so a format/values pair never straddles two batches.
            foreach ( array_chunk( $reqs, self::BATCH_MAX ) as $chunk ) {
                $res = $this->batch( $chunk );
                if ( is_wp_error( $res ) ) {
                    return $res;
                }
            }
            return true;
        }

        /** One $batch POST of sequential PATCHes. @return true|WP_Error the first failed sub-request. */
        private function batch( array $chunk ) {
            $requests = array();
            foreach ( $chunk as $i => $r ) {
                $req = array(
                    'id'      => (string) ( $i + 1 ),
                    'method'  => 'PATCH',
                    'url'     => $r['url'],
                    'headers' => array( 'Content-Type' => 'application/json' ),
                    'body'    => $r['body'],
                );
                if ( $i > 0 ) {
                    $req['dependsOn'] = array( (string) $i );
                }
                $requests[] = $req;
            }
            $res = $this->call( 'POST', self::API . '/$batch', array( 'requests' => $requests ) );
            if ( is_wp_error( $res ) ) {
                return $res;
            }
            $failed = null;
            foreach ( (array) ( $res['responses'] ?? array() ) as $sub ) {
                $status = (int) ( $sub['status'] ?? 0 );
                if ( $status >= 200 && $status < 300 ) {
                    continue;
                }
                if ( null === $failed || 424 === (int) ( $failed['status'] ?? 0 ) ) {
                    $failed = $sub; // Prefer the root cause over its "failed dependency" followers.
                }
            }
            return null === $failed ? true : self::sub_error( $failed );
        }

        private static function sub_error( array $sub ) {
            $status = (int) ( $sub['status'] ?? 0 );
            if ( 403 === $status || 404 === $status ) {
                return new WP_Error( 'anchor_sheet_forbidden', sprintf(
                    /* translators: %s provider */
                    __( 'Cannot open that spreadsheet. Check the link, and that the connected %s account can edit it.', 'anchor-schema' ),
                    ( $c = Anchor_OAuth_Connector::get( 'microsoft' ) ) ? $c->label() : 'Microsoft'
                ), array( 'status' => $status ) );
            }
            $msg = isset( $sub['body']['error']['message'] ) ? sanitize_text_field( (string) $sub['body']['error']['message'] ) : '';
            if ( '' === $msg ) {
                $msg = sprintf( /* translators: %d HTTP status */ __( 'Request failed (HTTP %d).', 'anchor-schema' ), $status );
            }
            return new WP_Error( 'anchor_oauth_http', $msg, array( 'status' => $status ) );
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
            if ( 2 === $start && 0 === (int) ( $used['rowIndex'] ?? 0 ) ) { // One used cell: is the sheet really empty?
                $cur = $this->read( $ref );
                if ( is_wp_error( $cur ) ) {
                    return $cur;
                }
                if ( ! $cur ) {
                    $start = 1;
                }
            }
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
