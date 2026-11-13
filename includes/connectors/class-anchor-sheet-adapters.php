<?php
/**
 * Adapter factory plus the helpers both adapters share (connector calls, error mapping, column letters).
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Anchor_Sheet_Adapters' ) ) {
    class Anchor_Sheet_Adapters {

        /** @return Anchor_Sheet_Adapter|null */
        public static function for_url( $url ) {
            $parts = wp_parse_url( trim( (string) $url ) );
            $host  = strtolower( (string) ( $parts['host'] ?? '' ) );
            $path  = (string) ( $parts['path'] ?? '' );
            if ( 'docs.google.com' === $host && preg_match( '#^/spreadsheets/(?:u/\d+/)?d/[A-Za-z0-9_-]+#', $path ) ) {
                return new Anchor_Sheet_Google();
            }
            if ( 'onedrive.live.com' === $host || '1drv.ms' === $host || preg_match( '/(^|\.)sharepoint\.com$/', $host ) ) {
                return new Anchor_Sheet_Excel();
            }
            return null;
        }

        /** @return Anchor_Sheet_Adapter|null */
        public static function for_ref( array $ref ) {
            $override = apply_filters( 'anchor_sheet_adapter_for_ref', null, $ref ); // Test seam: inject an in-memory adapter.
            if ( null !== $override ) {
                return $override;
            }
            switch ( $ref['provider'] ?? '' ) {
                case 'google':
                    return new Anchor_Sheet_Google();
                case 'microsoft':
                    return new Anchor_Sheet_Excel();
            }
            return null;
        }

        /** 0 -> A, 25 -> Z, 26 -> AA, 701 -> ZZ, 702 -> AAA. */
        public static function col_letter( $index0 ) {
            $n = (int) $index0 + 1;
            $s = '';
            while ( $n > 0 ) {
                $r = ( $n - 1 ) % 26;
                $s = chr( 65 + $r ) . $s;
                $n = intdiv( $n - 1, 26 );
            }
            return $s;
        }

        /** Authenticated provider call. Not-connected -> anchor_sheet_not_connected (no HTTP); 403/404 -> anchor_sheet_forbidden. @return array|WP_Error */
        public static function call( $provider, $method, $url, $body = null ) {
            $conn = Anchor_OAuth_Connector::get( $provider );
            if ( ! $conn || ! $conn->is_connected() || $conn->needs_reconnect() ) {
                return self::not_connected( $conn ? $conn->label() : (string) $provider );
            }
            $res = $conn->request( $method, $url, $body );
            if ( ! is_wp_error( $res ) ) {
                return $res;
            }
            if ( in_array( $res->get_error_code(), array( 'anchor_oauth_reconnect', 'anchor_oauth_not_connected' ), true ) ) {
                return self::not_connected( $conn->label() );
            }
            $status = (int) ( is_array( $res->get_error_data() ) ? ( $res->get_error_data()['status'] ?? 0 ) : 0 );
            if ( 403 === $status || 404 === $status ) {
                return new WP_Error( 'anchor_sheet_forbidden', sprintf(
                    /* translators: %s provider */
                    __( 'Cannot open that spreadsheet. Check the link, and that the connected %s account can edit it.', 'anchor-schema' ),
                    $conn->label()
                ), array( 'status' => $status ) );
            }
            return $res;
        }

        private static function not_connected( $label ) {
            return new WP_Error( 'anchor_sheet_not_connected', sprintf(
                /* translators: %s provider */
                __( '%s is not connected. Connect it in Settings > Anchor Tools > Connectors.', 'anchor-schema' ),
                $label
            ) );
        }

        /** Every cell to a string; null -> ''. */
        public static function stringify( $values ) {
            $out = array();
            foreach ( (array) $values as $row ) {
                $out[] = array_map( static function ( $v ) {
                    return is_bool( $v ) ? ( $v ? 'TRUE' : 'FALSE' ) : (string) $v;
                }, array_values( (array) $row ) );
            }
            return $out;
        }
    }
}
