<?php
/**
 * Provider-agnostic spreadsheet surface used by the events spreadsheet mirror.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! interface_exists( 'Anchor_Sheet_Adapter' ) ) {
    interface Anchor_Sheet_Adapter {
        /** URL as pasted -> stored ref. @return array|WP_Error */
        public function resolve( $url );
        /** Whole used range of the tab, row 1 = header, every cell a string. @return string[][]|WP_Error */
        public function read( array $ref );
        /** @param array $cells list of [row (1-based), col (0-based), value]. @return true|WP_Error */
        public function write_cells( array $ref, array $cells );
        /** Appends rows after the last used row. @param string[][] $rows @return true|WP_Error */
        public function append_rows( array $ref, array $rows );
        /** Makes sure the tab is at least $count columns wide. @return true|WP_Error */
        public function ensure_columns( array $ref, $count );
    }
}
