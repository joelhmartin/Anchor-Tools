<?php
/**
 * Google Sheets v4 adapter. All writes are RAW so nothing is evaluated as a formula.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Anchor_Sheet_Google' ) ) {
    class Anchor_Sheet_Google implements Anchor_Sheet_Adapter {
        const API = 'https://sheets.googleapis.com/v4/spreadsheets/';

        /** A1 notation tab reference: 'Bob''s List' */
        private static function q( $tab ) {
            return "'" . str_replace( "'", "''", (string) $tab ) . "'";
        }

        private function base( array $ref ) {
            return self::API . rawurlencode( (string) ( $ref['id'] ?? '' ) );
        }

        private function call( $method, $url, $body = null ) {
            return Anchor_Sheet_Adapters::call( 'google', $method, $url, $body );
        }

        /** @return array|WP_Error sheets[].properties list */
        private function tabs( $id ) {
            $res = $this->call( 'GET', self::API . rawurlencode( $id ) . '?fields=sheets.properties' );
            if ( is_wp_error( $res ) ) {
                return $res;
            }
            $tabs = array();
            foreach ( (array) ( $res['sheets'] ?? array() ) as $s ) {
                if ( ! empty( $s['properties'] ) ) {
                    $tabs[] = $s['properties'];
                }
            }
            return $tabs;
        }

        public function resolve( $url ) {
            $parts = wp_parse_url( trim( (string) $url ) );
            if ( 'docs.google.com' !== strtolower( (string) ( $parts['host'] ?? '' ) )
                || ! preg_match( '#/spreadsheets/(?:u/\d+/)?d/([A-Za-z0-9_-]+)#', (string) ( $parts['path'] ?? '' ), $m ) ) {
                return new WP_Error( 'anchor_sheet_bad_url', __( 'That is not a Google Sheets link.', 'anchor-schema' ) );
            }
            $gid = null;
            foreach ( array( $parts['fragment'] ?? '', $parts['query'] ?? '' ) as $chunk ) {
                if ( preg_match( '/(?:^|[&#])gid=(\d+)/', (string) $chunk, $g ) ) {
                    $gid = (int) $g[1];
                    break;
                }
            }
            $tabs = $this->tabs( $m[1] );
            if ( is_wp_error( $tabs ) ) {
                return $tabs;
            }
            if ( ! $tabs ) {
                return new WP_Error( 'anchor_sheet_no_tab', __( 'That spreadsheet has no tabs.', 'anchor-schema' ) );
            }
            $pick = $tabs[0];
            if ( null !== $gid ) {
                foreach ( $tabs as $t ) {
                    if ( isset( $t['sheetId'] ) && (int) $t['sheetId'] === $gid ) {
                        $pick = $t;
                        break;
                    }
                }
            }
            return array( 'provider' => 'google', 'id' => $m[1], 'tab' => (string) $pick['title'] );
        }

        public function read( array $ref ) {
            $res = $this->call( 'GET', $this->base( $ref ) . '/values/' . rawurlencode( self::q( $ref['tab'] ?? '' ) ) );
            return is_wp_error( $res ) ? $res : Anchor_Sheet_Adapters::stringify( $res['values'] ?? array() );
        }

        public function write_cells( array $ref, array $cells ) {
            if ( ! $cells ) {
                return true;
            }
            $data = array();
            foreach ( $cells as $c ) {
                $data[] = array(
                    'range'  => self::q( $ref['tab'] ?? '' ) . '!' . Anchor_Sheet_Adapters::col_letter( (int) $c[1] ) . (int) $c[0],
                    'values' => array( array( (string) $c[2] ) ),
                );
            }
            $res = $this->call( 'POST', $this->base( $ref ) . '/values:batchUpdate', array( 'valueInputOption' => 'RAW', 'data' => $data ) );
            return is_wp_error( $res ) ? $res : true;
        }

        public function append_rows( array $ref, array $rows ) {
            if ( ! $rows ) {
                return true;
            }
            $values = array_map( static function ( $r ) {
                return array_map( 'strval', array_values( $r ) );
            }, $rows );
            $url    = $this->base( $ref ) . '/values/' . rawurlencode( self::q( $ref['tab'] ?? '' ) . '!A1' ) . ':append?valueInputOption=RAW&insertDataOption=INSERT_ROWS';
            $res    = $this->call( 'POST', $url, array( 'values' => $values ) );
            return is_wp_error( $res ) ? $res : true;
        }

        public function ensure_columns( array $ref, $count ) {
            $tabs = $this->tabs( (string) ( $ref['id'] ?? '' ) );
            if ( is_wp_error( $tabs ) ) {
                return $tabs;
            }
            foreach ( $tabs as $t ) {
                if ( ( $t['title'] ?? null ) !== ( $ref['tab'] ?? null ) ) {
                    continue;
                }
                $have = (int) ( $t['gridProperties']['columnCount'] ?? 0 );
                if ( $have >= (int) $count ) {
                    return true;
                }
                $res = $this->call( 'POST', $this->base( $ref ) . ':batchUpdate', array( 'requests' => array( array(
                    'appendDimension' => array( 'sheetId' => (int) $t['sheetId'], 'dimension' => 'COLUMNS', 'length' => (int) $count - $have ),
                ) ) ) );
                return is_wp_error( $res ) ? $res : true;
            }
            return new WP_Error( 'anchor_sheet_no_tab', __( 'That tab no longer exists in the spreadsheet.', 'anchor-schema' ) );
        }
    }
}
