<?php
use Anchor\Announcements\Admin\Reports;
use Anchor\Announcements\Content\AnnouncementPostType as PT;
use Anchor\Announcements\Database\Migrations;
use Anchor\Announcements\Tracking\Endpoints;

class Test_Announcements_Reports extends Anchor_Announcements_TestCase {

	private function row( int $id, string $email, string $status, int $opens = 0, int $clicks = 0 ): object {
		global $wpdb;
		$wpdb->insert( Migrations::table( 'sends' ), [ 'announcement_id' => $id, 'email' => $email, 'name' => ucfirst( strtok( $email, '@' ) ), 'token' => bin2hex( random_bytes( 16 ) ), 'status' => $status, 'queued_at' => '2026-01-01 00:00:00', 'sent_at' => 'sent' === $status ? '2026-01-01 00:00:00' : null ] );
		$send = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Migrations::table( 'sends' ) . ' WHERE id = %d', $wpdb->insert_id ) );
		for ( $i = 0; $i < $opens; $i++ ) { Endpoints::record( $send, 'open' ); }
		for ( $i = 0; $i < $clicks; $i++ ) { Endpoints::record( $send, 'click', 0 ); }
		return $send;
	}

	public function test_stats_and_rates() {
		$id = $this->make_announcement( [ PT::META_STATE => PT::STATE_SENT ] );
		$this->row( $id, 'a@x.com', 'sent', 2, 1 );
		$this->row( $id, 'b@x.com', 'sent', 1 );
		$this->row( $id, 'c@x.com', 'sent' );
		$this->row( $id, 'd@x.com', 'sent' );
		$this->row( $id, 'e@x.com', 'failed' );
		$u = $this->row( $id, 'f@x.com', 'skipped' );
		Endpoints::unsubscribe( $this->row( $id, 'g@x.com', 'sent' ) );
		$s = Reports::stats( $id );
		$this->assertSame( 7, $s['total'] );
		$this->assertSame( 5, $s['sent'] );
		$this->assertSame( 1, $s['failed'] );
		$this->assertSame( 1, $s['skipped'] );
		$this->assertSame( 2, $s['opened'] );
		$this->assertSame( 1, $s['clicked'] );
		$this->assertSame( 1, $s['unsubscribed'] );
		$this->assertEqualsWithDelta( 0.4, $s['open_rate'], 0.0001 );
		$this->assertEqualsWithDelta( 0.2, $s['click_rate'], 0.0001 );
		unset( $u );
	}

	public function test_recipient_filters_and_search() {
		$id = $this->make_announcement( [ PT::META_STATE => PT::STATE_SENT ] );
		$this->row( $id, 'opened@x.com', 'sent', 1 );
		$this->row( $id, 'quiet@x.com', 'sent' );
		$this->row( $id, 'broken@x.com', 'failed' );
		$this->assertSame( [ 'opened@x.com' ], array_column( Reports::recipients( $id, 'opened' )['rows'], 'email' ) );
		$this->assertSame( [ 'quiet@x.com' ], array_column( Reports::recipients( $id, 'not_opened' )['rows'], 'email' ) );
		$this->assertSame( [ 'broken@x.com' ], array_column( Reports::recipients( $id, 'failed' )['rows'], 'email' ) );
		$this->assertSame( 1, Reports::recipients( $id, 'all', 'quiet' )['total'] );
	}

	public function test_links_exclude_scanner_clicks() {
		$id = $this->make_announcement( [ PT::META_STATE => PT::STATE_SENT ] );
		update_post_meta( $id, PT::META_LINKS, [ 'https://one.test/', 'https://two.test/' ] );
		$a = $this->row( $id, 'a@x.com', 'sent', 0, 2 );
		$this->row( $id, 'b@x.com', 'sent', 0, 1 );
		Endpoints::record( $a, 'click', 1, true );
		$links = Reports::links( $id );
		$this->assertSame( [ 'url' => 'https://one.test/', 'clicks' => 3, 'unique' => 2 ], $links[0] );
		$this->assertSame( [ 'url' => 'https://two.test/', 'clicks' => 0, 'unique' => 0 ], $links[1] );
	}

	public function test_csv_rows() {
		$id = $this->make_announcement( [ PT::META_STATE => PT::STATE_SENT ] );
		$this->row( $id, 'a@x.com', 'sent', 1 );
		$rows = Reports::csv_rows( $id );
		$this->assertSame( [ 'name', 'email', 'status', 'skip_reason', 'error', 'sent_at', 'first_opened_at', 'open_count', 'first_clicked_at', 'click_count', 'unsubscribed' ], $rows[0] );
		$this->assertSame( 'a@x.com', $rows[1][1] );
		$this->assertSame( '1', (string) $rows[1][7] );
	}

	public function test_csv_neutralises_formula_injection() {
		$id = $this->make_announcement( [ PT::META_STATE => PT::STATE_SENT ] );
		global $wpdb;
		$wpdb->insert( Migrations::table( 'sends' ), [ 'announcement_id' => $id, 'email' => 'f@x.com', 'name' => '=HYPERLINK("x")', 'token' => bin2hex( random_bytes( 16 ) ), 'status' => 'sent', 'queued_at' => '2026-01-01 00:00:00' ] );
		$this->assertSame( "'=HYPERLINK(\"x\")", Reports::csv_rows( $id )[1][0] );
	}
}
