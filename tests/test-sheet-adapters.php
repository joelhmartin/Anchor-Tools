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
        set_transient( 'anchor_oauth_at_' . $provider, Anchor_Secret_Box::seal( 'access-x' ), 3600 );
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
    }

    public function test_google_resolve_rejects_gid_of_a_deleted_tab() {
        $this->connect( 'google' );
        $this->responder = function () { return array( 200, $this->tabs_json() ); };
        $ref = ( new Anchor_Sheet_Google() )->resolve( 'https://docs.google.com/spreadsheets/d/SHEETID/edit?gid=999#gid=999' );
        $this->assertWPError( $ref );
        $this->assertSame( 'anchor_sheet_no_tab', $ref->get_error_code() );
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

    /** The sub-requests of every $batch POST, in order. */
    private function batch_requests() {
        $out = array();
        foreach ( $this->calls as $c ) {
            if ( 'POST' === $c['method'] && false !== strpos( $c['url'], '/$batch' ) ) {
                $out = array_merge( $out, $c['body']['requests'] );
            }
        }
        return $out;
    }

    public function test_excel_write_cells_formats_then_writes_each_block_in_one_batch() {
        $this->connect( 'microsoft' );
        $a   = new Anchor_Sheet_Excel();
        $ref = array( 'provider' => 'microsoft', 'drive_id' => 'D', 'item_id' => 'I', 'worksheet' => 'Sheet1' );
        $this->assertTrue( $a->write_cells( $ref, array( array( 2, 0, '=1+1' ), array( 2, 1, 'b' ), array( 2, 3, 'd' ), array( 5, 2, 'x' ) ) ) );
        $this->assertCount( 1, $this->calls, 'one $batch POST' );
        $this->assertSame( 'https://graph.microsoft.com/v1.0/$batch', $this->calls[0]['url'] );
        $req = $this->batch_requests();
        $this->assertCount( 6, $req ); // A2:B2, D2:D2, C5:C5, each format then values
        $this->assertSame( 'PATCH', $req[0]['method'] );
        $this->assertSame( "/drives/D/items/I/workbook/worksheets/Sheet1/range(address='A2:B2')", $req[0]['url'] );
        $this->assertSame( array( 'numberFormat' => array( array( '@', '@' ) ) ), $req[0]['body'] );
        $this->assertSame( $req[0]['url'], $req[1]['url'] );
        $this->assertSame( array( 'values' => array( array( '=1+1', 'b' ) ) ), $req[1]['body'] );
        $this->assertSame( array( $req[0]['id'] ), $req[1]['dependsOn'], 'values only after its format' );
        $this->assertArrayNotHasKey( 'dependsOn', $req[0] );
        $this->assertSame( array( $req[1]['id'] ), $req[2]['dependsOn'], 'blocks run in sequence' );
        $urls = array_column( $req, 'url' );
        $this->assertContains( "/drives/D/items/I/workbook/worksheets/Sheet1/range(address='D2:D2')", $urls );
        $this->assertContains( "/drives/D/items/I/workbook/worksheets/Sheet1/range(address='C5:C5')", $urls );
    }

    public function test_excel_new_column_over_50_rows_is_one_block_one_call() {
        $this->connect( 'microsoft' );
        $cells = array( array( 1, 7, 'New question' ) );
        for ( $r = 2; $r <= 51; $r++ ) {
            $cells[] = array( $r, 7, 'answer ' . $r );
        }
        $ref = array( 'provider' => 'microsoft', 'drive_id' => 'D', 'item_id' => 'I', 'worksheet' => 'S' );
        $this->assertTrue( ( new Anchor_Sheet_Excel() )->write_cells( $ref, $cells ) );
        $this->assertCount( 1, $this->calls );
        $req = $this->batch_requests();
        $this->assertCount( 2, $req );
        $this->assertStringContainsString( "range(address='H1:H51')", $req[0]['url'] );
        $this->assertCount( 51, $req[1]['body']['values'] );
        $this->assertSame( array( 'answer 51' ), $req[1]['body']['values'][50] );
        $this->assertSame( array( '@' ), $req[0]['body']['numberFormat'][50] );
    }

    public function test_excel_scattered_cells_batch_at_most_20_requests_per_call() {
        $this->connect( 'microsoft' );
        $cells = array();
        for ( $i = 0; $i < 25; $i++ ) {
            $cells[] = array( 2 + $i * 2, $i % 3 * 2, 'v' . $i ); // No two cells touch.
        }
        $ref = array( 'provider' => 'microsoft', 'drive_id' => 'D', 'item_id' => 'I', 'worksheet' => 'S' );
        $this->assertTrue( ( new Anchor_Sheet_Excel() )->write_cells( $ref, $cells ) );
        $this->assertCount( 3, $this->calls, '50 sub-requests = 20 + 20 + 10' );
        foreach ( $this->calls as $c ) {
            $this->assertLessThanOrEqual( 20, count( $c['body']['requests'] ) );
            $first = $c['body']['requests'][0];
            $this->assertArrayHasKey( 'numberFormat', $first['body'], 'a batch never starts with a values write' );
        }
        $this->assertCount( 50, $this->batch_requests() );
    }

    public function test_excel_batch_sub_request_failure_is_an_error() {
        $this->connect( 'microsoft' );
        $this->responder = function () {
            return array( 200, array( 'responses' => array(
                array( 'id' => '1', 'status' => 403, 'body' => array( 'error' => array( 'message' => 'Access denied' ) ) ),
                array( 'id' => '2', 'status' => 424, 'body' => array() ),
            ) ) );
        };
        $ref = array( 'provider' => 'microsoft', 'drive_id' => 'D', 'item_id' => 'I', 'worksheet' => 'S' );
        $r   = ( new Anchor_Sheet_Excel() )->write_cells( $ref, array( array( 2, 0, 'x' ) ) );
        $this->assertWPError( $r );
        $this->assertSame( 'anchor_sheet_forbidden', $r->get_error_code() );
        $this->responder = function () {
            return array( 200, array( 'responses' => array(
                array( 'id' => '1', 'status' => 200, 'body' => array() ),
                array( 'id' => '2', 'status' => 500, 'body' => array( 'error' => array( 'message' => 'Busy' ) ) ),
            ) ) );
        };
        $r = ( new Anchor_Sheet_Excel() )->write_cells( $ref, array( array( 2, 0, 'x' ) ) );
        $this->assertWPError( $r );
        $this->assertStringContainsString( 'Busy', $r->get_error_message() );
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
        $this->assertStringContainsString( 'needs to be reconnected', $r->get_error_message() );
        $this->assertStringNotContainsString( 'is not connected', $r->get_error_message() );
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

    public function test_share_adds_only_missing_permissions() {
        $this->connect( 'google' );
        $this->responder = function ( $u, $args ) {
            return 'GET' === $args['method'] ? array( 200, array( 'permissions' => array( array( 'emailAddress' => 'a@x.test', 'role' => 'writer', 'type' => 'user' ) ) ) ) : array( 200, array( 'id' => 'p1' ) );
        };
        $added = ( new Anchor_Sheet_Google() )->share( array( 'provider' => 'google', 'id' => 'F1' ), array( 'a@x.test', 'b@x.test' ), 'writer', false );
        $this->assertSame( array( 'b@x.test' ), $added );
        $posts = array_values( array_filter( $this->calls, function ( $c ) { return 'POST' === $c['method']; } ) );
        $this->assertCount( 1, $posts );
        $this->assertStringContainsString( '/drive/v3/files/F1/permissions', $posts[0]['url'] );
        $this->assertStringContainsString( 'sendNotificationEmail=false', $posts[0]['url'] );
        $this->assertStringContainsString( 'supportsAllDrives=true', $posts[0]['url'] );
        $this->assertSame( array( 'type' => 'user', 'role' => 'writer', 'emailAddress' => 'b@x.test' ), $posts[0]['body'] );
        $get = $this->calls[0];
        $this->assertStringContainsString( 'supportsAllDrives=true', $get['url'] );
    }

    public function test_share_emails_are_case_insensitive() {
        $this->connect( 'google' );
        $this->responder = function ( $u, $args ) {
            return 'GET' === $args['method'] ? array( 200, array( 'permissions' => array( array( 'emailAddress' => 'Alice@X.test' ) ) ) ) : array( 200, array() );
        };
        $added = ( new Anchor_Sheet_Google() )->share( array( 'id' => 'F1' ), array( 'ALICE@x.test', 'Bob@x.test' ), 'reader', true );
        $this->assertSame( array( 'bob@x.test' ), $added );
        $posts = array_values( array_filter( $this->calls, function ( $c ) { return 'POST' === $c['method']; } ) );
        $this->assertStringContainsString( 'sendNotificationEmail=true', $posts[0]['url'] );
        $this->assertSame( 'reader', $posts[0]['body']['role'] );
    }

    public function test_share_follows_list_pagination() {
        $this->connect( 'google' );
        $this->responder = function ( $u, $args ) {
            if ( 'GET' !== $args['method'] ) {
                return array( 200, array() );
            }
            return false === strpos( $u, 'pageToken=T2' )
                ? array( 200, array( 'permissions' => array( array( 'emailAddress' => 'a@x.test' ) ), 'nextPageToken' => 'T2' ) )
                : array( 200, array( 'permissions' => array( array( 'emailAddress' => 'b@x.test' ) ) ) );
        };
        $added = ( new Anchor_Sheet_Google() )->share( array( 'id' => 'F1' ), array( 'a@x.test', 'b@x.test' ), 'writer', true );
        $this->assertSame( array(), $added );
    }

    public function test_create_posts_title_and_moves_into_folder() {
        $this->connect( 'google' );
        $this->responder = function ( $u, $args ) {
            if ( 'POST' === $args['method'] ) {
                return array( 200, array( 'spreadsheetId' => 'NEW1', 'spreadsheetUrl' => 'https://docs.google.com/spreadsheets/d/NEW1/edit', 'sheets' => array( array( 'properties' => array( 'title' => 'Sheet1' ) ) ) ) );
            }
            return array( 200, array( 'id' => 'NEW1' ) );
        };
        $res = ( new Anchor_Sheet_Google() )->create( "Spring Gala \xE2\x80\x94 Registrants", 'FOLD9' );
        $this->assertSame( 'https://docs.google.com/spreadsheets/d/NEW1/edit', $res['url'] );
        $this->assertSame( array( 'provider' => 'google', 'id' => 'NEW1', 'tab' => 'Sheet1' ), $res['ref'] );
        $this->assertCount( 2, $this->calls );
        $this->assertSame( 'POST', $this->calls[0]['method'] );
        $this->assertSame( 'https://sheets.googleapis.com/v4/spreadsheets', $this->calls[0]['url'] );
        $this->assertSame( array( 'properties' => array( 'title' => "Spring Gala \xE2\x80\x94 Registrants" ) ), $this->calls[0]['body'] );
        $this->assertSame( 'PATCH', $this->calls[1]['method'] );
        $this->assertStringContainsString( '/drive/v3/files/NEW1?', $this->calls[1]['url'] );
        $this->assertStringContainsString( 'addParents=FOLD9', $this->calls[1]['url'] );
        $this->assertStringContainsString( 'removeParents=root', $this->calls[1]['url'] );
        $this->assertStringContainsString( 'supportsAllDrives=true', $this->calls[1]['url'] );

        $this->calls = array();
        ( new Anchor_Sheet_Google() )->create( 'T' );
        $this->assertCount( 1, $this->calls );
    }

    public function test_autocreate_settings_parse_folder_url() {
        update_option( 'anchor_connector_google', array( 'autocreate' => true, 'autocreate_folder_id' => 'abc_1-2' ), false );
        $s = Anchor_Sheet_Google::autocreate_settings();
        $this->assertTrue( $s['enabled'] );
        $this->assertSame( 'abc_1-2', $s['folder_id'] );
        $this->assertSame( 'abc_1-2', Anchor_Sheet_Google::parse_folder_id( 'https://drive.google.com/drive/u/0/folders/abc_1-2?usp=sharing' ) );
        $this->assertSame( '', Anchor_Sheet_Google::parse_folder_id( 'https://example.com/x' ) );
        delete_option( 'anchor_connector_google' );
        $this->assertFalse( Anchor_Sheet_Google::autocreate_settings()['enabled'] );
    }
}
