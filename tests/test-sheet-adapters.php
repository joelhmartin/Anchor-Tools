<?php
/**
 * Google Sheets / Excel adapters. All HTTP mocked via pre_http_request.
 */
class Test_Sheet_Adapters extends WP_UnitTestCase {

    private $calls = array();
    private $responder;

    public function set_up() {
        parent::set_up();
        $this->calls     = array();
        $this->responder = null;
        $this->clean();
        add_filter( 'pre_http_request', array( $this, 'mock_http' ), 10, 3 );
    }

    public function tear_down() {
        remove_filter( 'pre_http_request', array( $this, 'mock_http' ), 10 );
        $this->clean();
        parent::tear_down();
    }

    private function clean() {
        foreach ( array( 'google', 'microsoft' ) as $p ) {
            delete_option( 'anchor_connector_' . $p );
            delete_transient( 'anchor_oauth_at_' . $p );
        }
    }

    private function connect( $provider ) {
        update_option( 'anchor_connector_' . $provider, array( 'refresh_token' => Anchor_Secret_Box::seal( 'refresh-x' ) ), false );
        set_transient( 'anchor_oauth_at_' . $provider, 'access-x', 3600 );
    }

    public function mock_http( $pre, $args, $url ) {
        $this->calls[] = array( 'method' => $args['method'], 'url' => $url, 'body' => isset( $args['body'] ) ? json_decode( $args['body'], true ) : null );
        $r = is_callable( $this->responder ) ? call_user_func( $this->responder, $url, $args ) : null;
        if ( null === $r ) {
            $r = array( 200, array() );
        }
        return array( 'headers' => array(), 'body' => wp_json_encode( $r[1] ), 'response' => array( 'code' => $r[0], 'message' => '' ), 'cookies' => array() );
    }

    private function tabs_json( $cols = 26 ) {
        return array( 'sheets' => array(
            array( 'properties' => array( 'sheetId' => 0, 'title' => 'Main', 'gridProperties' => array( 'columnCount' => $cols ) ) ),
            array( 'properties' => array( 'sheetId' => 77, 'title' => "Bob's List", 'gridProperties' => array( 'columnCount' => $cols ) ) ),
        ) );
    }

    public function test_col_letter() {
        $this->assertSame( 'A', Anchor_Sheet_Adapters::col_letter( 0 ) );
        $this->assertSame( 'Z', Anchor_Sheet_Adapters::col_letter( 25 ) );
        $this->assertSame( 'AA', Anchor_Sheet_Adapters::col_letter( 26 ) );
        $this->assertSame( 'ZZ', Anchor_Sheet_Adapters::col_letter( 701 ) );
        $this->assertSame( 'AAA', Anchor_Sheet_Adapters::col_letter( 702 ) );
    }

    public function test_for_url_and_for_ref_dispatch() {
        $this->assertInstanceOf( 'Anchor_Sheet_Google', Anchor_Sheet_Adapters::for_url( 'https://docs.google.com/spreadsheets/d/abc123/edit' ) );
        $this->assertInstanceOf( 'Anchor_Sheet_Excel', Anchor_Sheet_Adapters::for_url( 'https://acme.sharepoint.com/:x:/s/a/b' ) );
        $this->assertInstanceOf( 'Anchor_Sheet_Excel', Anchor_Sheet_Adapters::for_url( 'https://1drv.ms/x/s!abc' ) );
        $this->assertNull( Anchor_Sheet_Adapters::for_url( 'https://example.com/x' ) );
        $this->assertInstanceOf( 'Anchor_Sheet_Google', Anchor_Sheet_Adapters::for_ref( array( 'provider' => 'google' ) ) );
        $this->assertInstanceOf( 'Anchor_Sheet_Excel', Anchor_Sheet_Adapters::for_ref( array( 'provider' => 'microsoft' ) ) );
        $stub = new Anchor_Sheet_Excel();
        add_filter( 'anchor_sheet_adapter_for_ref', $f = function () use ( $stub ) { return $stub; } );
        $this->assertSame( $stub, Anchor_Sheet_Adapters::for_ref( array( 'provider' => 'google' ) ) );
        remove_filter( 'anchor_sheet_adapter_for_ref', $f );
    }

    public function test_google_resolve_uses_gid_tab_or_first_tab() {
        $this->connect( 'google' );
        $this->responder = function () { return array( 200, $this->tabs_json() ); };
        $a   = new Anchor_Sheet_Google();
        $ref = $a->resolve( 'https://docs.google.com/spreadsheets/d/SHEETID/edit#gid=77' );
        $this->assertSame( array( 'provider' => 'google', 'id' => 'SHEETID', 'tab' => "Bob's List" ), $ref );
        $this->assertCount( 1, $this->calls );
        $this->assertStringContainsString( 'fields=sheets.properties', $this->calls[0]['url'] );
        $ref = $a->resolve( 'https://docs.google.com/spreadsheets/d/SHEETID/edit' );
        $this->assertSame( 'Main', $ref['tab'] );
        $ref = $a->resolve( 'https://docs.google.com/spreadsheets/d/SHEETID/edit?gid=999#gid=999' );
        $this->assertSame( 'Main', $ref['tab'] );
    }

    public function test_google_resolve_rejects_non_sheet_url() {
        $this->connect( 'google' );
        $r = ( new Anchor_Sheet_Google() )->resolve( 'https://example.com/spreadsheets/d/abc' );
        $this->assertWPError( $r );
        $this->assertCount( 0, $this->calls );
    }

    public function test_google_writes_are_raw() {
        $this->connect( 'google' );
        $a   = new Anchor_Sheet_Google();
        $ref = array( 'provider' => 'google', 'id' => 'S1', 'tab' => 'Main' );
        $this->assertTrue( $a->write_cells( $ref, array( array( 2, 0, '=HYPERLINK("x")' ), array( 1, 27, 'Hdr' ) ) ) );
        $c = $this->calls[0];
        $this->assertSame( 'POST', $c['method'] );
        $this->assertStringContainsString( '/spreadsheets/S1/values:batchUpdate', $c['url'] );
        $this->assertSame( 'RAW', $c['body']['valueInputOption'] );
        $this->assertSame( "'Main'!A2", $c['body']['data'][0]['range'] );
        $this->assertSame( array( array( '=HYPERLINK("x")' ) ), $c['body']['data'][0]['values'] );
        $this->assertSame( "'Main'!AB1", $c['body']['data'][1]['range'] );

        $this->assertTrue( $a->append_rows( $ref, array( array( 'a', 1 ) ) ) );
        $c = $this->calls[1];
        $this->assertStringContainsString( 'valueInputOption=RAW', $c['url'] );
        $this->assertStringContainsString( 'insertDataOption=INSERT_ROWS', $c['url'] );
        $this->assertStringContainsString( ':append', $c['url'] );
        $this->assertSame( array( array( 'a', '1' ) ), $c['body']['values'] );
    }

    public function test_google_tab_names_are_quoted_in_ranges() {
        $this->connect( 'google' );
        $a   = new Anchor_Sheet_Google();
        $ref = array( 'provider' => 'google', 'id' => 'S1', 'tab' => "Bob's List" );
        $a->write_cells( $ref, array( array( 1, 0, 'x' ) ) );
        $this->assertSame( "'Bob''s List'!A1", $this->calls[0]['body']['data'][0]['range'] );
        $this->responder = function () { return array( 200, array( 'values' => array( array( 'h', 2 ), array() ) ) ); };
        $rows = $a->read( $ref );
        $this->assertSame( array( array( 'h', '2' ), array() ), $rows );
        $this->assertStringContainsString( rawurlencode( "'Bob''s List'" ), $this->calls[1]['url'] );
    }

    public function test_google_grows_grid_before_widening_header() {
        $this->connect( 'google' );
        $this->responder = function () { return array( 200, $this->tabs_json( 26 ) ); };
        $a   = new Anchor_Sheet_Google();
        $ref = array( 'provider' => 'google', 'id' => 'S1', 'tab' => "Bob's List" );
        $this->assertTrue( $a->ensure_columns( $ref, 30 ) );
        $this->assertCount( 2, $this->calls );
        $b = $this->calls[1]['body']['requests'][0]['appendDimension'];
        $this->assertSame( array( 'sheetId' => 77, 'dimension' => 'COLUMNS', 'length' => 4 ), $b );
        $this->assertStringContainsString( ':batchUpdate', $this->calls[1]['url'] );
        // Already wide enough: no grow request.
        $this->calls = array();
        $this->assertTrue( $a->ensure_columns( $ref, 26 ) );
        $this->assertCount( 1, $this->calls );
    }

    public function test_excel_resolve_encodes_share_url() {
        $this->connect( 'microsoft' );
        $url = 'https://acme.sharepoint.com/:x:/s/site/Eabc?e=1';
        $this->responder = function ( $u ) {
            if ( false !== strpos( $u, '/driveItem' ) ) {
                return array( 200, array( 'id' => 'ITEM1', 'parentReference' => array( 'driveId' => 'DRV1' ) ) );
            }
            return array( 200, array( 'value' => array( array( 'name' => 'Sheet1' ), array( 'name' => 'Other' ) ) ) );
        };
        $ref = ( new Anchor_Sheet_Excel() )->resolve( $url );
        $this->assertSame( array( 'provider' => 'microsoft', 'drive_id' => 'DRV1', 'item_id' => 'ITEM1', 'worksheet' => 'Sheet1' ), $ref );
        $enc = rtrim( strtr( base64_encode( $url ), '+/', '-_' ), '=' );
        $this->assertStringContainsString( '/shares/u!' . $enc . '/driveItem', $this->calls[0]['url'] );
        $this->assertStringContainsString( '/drives/DRV1/items/ITEM1/workbook/worksheets', $this->calls[1]['url'] );
    }

    public function test_excel_write_cells_patches_text_number_format() {
        $this->connect( 'microsoft' );
        $a   = new Anchor_Sheet_Excel();
        $ref = array( 'provider' => 'microsoft', 'drive_id' => 'D', 'item_id' => 'I', 'worksheet' => 'Sheet1' );
        $this->assertTrue( $a->write_cells( $ref, array( array( 2, 0, '=1+1' ), array( 2, 1, 'b' ), array( 2, 3, 'd' ), array( 5, 2, 'x' ) ) ) );
        $this->assertCount( 6, $this->calls ); // A2:B2, D2:D2, C5:C5, each format then values
        $this->assertSame( 'PATCH', $this->calls[0]['method'] );
        $this->assertStringContainsString( "/worksheets/Sheet1/range(address='A2:B2')", $this->calls[0]['url'] );
        $this->assertSame( array( 'numberFormat' => array( array( '@', '@' ) ) ), $this->calls[0]['body'] );
        $this->assertSame( $this->calls[0]['url'], $this->calls[1]['url'] );
        $this->assertSame( array( 'values' => array( array( '=1+1', 'b' ) ) ), $this->calls[1]['body'] );
        $this->assertStringContainsString( "range(address='D2:D2')", $this->calls[2]['url'] );
        $this->assertStringContainsString( "range(address='C5:C5')", $this->calls[4]['url'] );
    }

    public function test_excel_append_writes_after_used_range() {
        $this->connect( 'microsoft' );
        $this->responder = function ( $u, $args ) {
            return 'GET' === $args['method'] ? array( 200, array( 'rowIndex' => 0, 'rowCount' => 4 ) ) : array( 200, array() );
        };
        $a   = new Anchor_Sheet_Excel();
        $ref = array( 'provider' => 'microsoft', 'drive_id' => 'D', 'item_id' => 'I', 'worksheet' => 'Sheet1' );
        $this->assertTrue( $a->append_rows( $ref, array( array( 'a', 'b', 'c' ), array( 'd' ) ) ) );
        $this->assertStringContainsString( "range(address='A5:C6')", $this->calls[1]['url'] );
        $this->assertSame( '@', $this->calls[1]['body']['numberFormat'][1][2] );
        $this->assertSame( array( array( 'a', 'b', 'c' ), array( 'd', '', '' ) ), $this->calls[2]['body']['values'] );
        $this->assertTrue( $a->ensure_columns( $ref, 99 ) );
    }

    public function test_excel_read_stringifies_and_empty_sheet_is_empty() {
        $this->connect( 'microsoft' );
        $a   = new Anchor_Sheet_Excel();
        $ref = array( 'provider' => 'microsoft', 'drive_id' => 'D', 'item_id' => 'I', 'worksheet' => 'S' );
        $this->responder = function () { return array( 200, array( 'values' => array( array( 'h', 5 ), array( '', true ) ) ) ); };
        $this->assertSame( array( array( 'h', '5' ), array( '', 'TRUE' ) ), $a->read( $ref ) );
        $this->responder = function () { return array( 200, array( 'values' => array( array( '' ) ) ) ); };
        $this->assertSame( array(), $a->read( $ref ) );
    }

    public function test_403_maps_to_cannot_open_message() {
        $this->connect( 'google' );
        $this->responder = function () { return array( 403, array( 'error' => array( 'message' => 'nope' ) ) ); };
        $r = ( new Anchor_Sheet_Google() )->read( array( 'provider' => 'google', 'id' => 'S', 'tab' => 'T' ) );
        $this->assertWPError( $r );
        $this->assertSame( 'anchor_sheet_forbidden', $r->get_error_code() );
        $this->responder = function () { return array( 404, array() ); };
        $this->connect( 'microsoft' );
        $r = ( new Anchor_Sheet_Excel() )->read( array( 'provider' => 'microsoft', 'drive_id' => 'D', 'item_id' => 'I', 'worksheet' => 'S' ) );
        $this->assertSame( 'anchor_sheet_forbidden', $r->get_error_code() );
    }

    public function test_not_connected_returns_error_without_http() {
        $r = ( new Anchor_Sheet_Google() )->read( array( 'provider' => 'google', 'id' => 'S', 'tab' => 'T' ) );
        $this->assertSame( 'anchor_sheet_not_connected', $r->get_error_code() );
        $this->assertStringContainsString( 'Google', $r->get_error_message() );
        $this->connect( 'microsoft' );
        $o = get_option( 'anchor_connector_microsoft' );
        $o['needs_reconnect'] = true;
        update_option( 'anchor_connector_microsoft', $o, false );
        $r = ( new Anchor_Sheet_Excel() )->write_cells( array( 'worksheet' => 'S' ), array( array( 1, 0, 'x' ) ) );
        $this->assertSame( 'anchor_sheet_not_connected', $r->get_error_code() );
        $this->assertCount( 0, $this->calls );
    }

    public function test_excel_read_pads_used_range_offset() {
        $this->connect( 'microsoft' );
        $this->responder = function () { return array( 200, array( 'rowIndex' => 1, 'columnIndex' => 2, 'values' => array( array( 'h1', 'h2' ), array( 'a', 'b' ) ) ) ); };
        $rows = ( new Anchor_Sheet_Excel() )->read( array( 'provider' => 'microsoft', 'drive_id' => 'D', 'item_id' => 'I', 'worksheet' => 'S' ) );
        $this->assertSame( array( array(), array( '', '', 'h1', 'h2' ), array( '', '', 'a', 'b' ) ), $rows );
        $this->assertStringContainsString( 'select=values,rowIndex,columnIndex', urldecode( $this->calls[0]['url'] ) );
    }

    public function test_excel_append_on_empty_sheet_starts_at_row_one() {
        $this->connect( 'microsoft' );
        $this->responder = function ( $u, $args ) {
            return 'GET' === $args['method'] ? array( 200, array( 'rowIndex' => 0, 'columnIndex' => 0, 'rowCount' => 1, 'values' => array( array( '' ) ) ) ) : array( 200, array() );
        };
        $ref = array( 'provider' => 'microsoft', 'drive_id' => 'D', 'item_id' => 'I', 'worksheet' => 'S' );
        $this->assertTrue( ( new Anchor_Sheet_Excel() )->append_rows( $ref, array( array( 'a', 'b' ) ) ) );
        $patch = array_values( array_filter( $this->calls, function ( $c ) { return 'PATCH' === $c['method']; } ) );
        $this->assertStringContainsString( "range(address='A1:B1')", $patch[0]['url'] );
    }
}
