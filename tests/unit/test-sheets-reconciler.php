<?php
require_once dirname( __DIR__, 2 ) . '/anchor-events-manager/class-sheets-reconciler.php';

use Anchor\Events\Sheets_Reconciler as R;

/** @group events-unit */
class Test_Sheets_Reconciler extends PHPUnit\Framework\TestCase {

	private function t( array $header, array $rows ): array {
		return array( 'header' => $header, 'rows' => $rows );
	}

	public function test_empty_sheet_gets_full_header_and_all_rows_oldest_first() {
		$r = R::reconcile( array(), $this->t( array( 'Seat ID', 'Name', 'Status' ), array( array( 3, 'C', 'confirmed' ), array( 2, 'B', 'confirmed' ) ) ), 'Seat ID' );
		$this->assertSame( array( 'Seat ID', 'Name', 'Status' ), $r['header_append'] );
		$this->assertSame( 0, $r['header_start_col'] );
		$this->assertSame( array(), $r['cells'] );
		$this->assertSame( array( array( '2', 'B', 'confirmed' ), array( '3', 'C', 'confirmed' ) ), $r['appends'] );
	}

	public function test_header_only_sheet_gets_all_rows() {
		$r = R::reconcile( array( array( 'Seat ID', 'Name' ) ), $this->t( array( 'Seat ID', 'Name' ), array( array( 1, 'A' ) ) ), 'Seat ID' );
		$this->assertSame( array(), $r['header_append'] );
		$this->assertSame( array( array( '1', 'A' ) ), $r['appends'] );
	}

	public function test_status_change_updates_only_that_cell() {
		$r = R::reconcile( array( array( 'Seat ID', 'Name', 'Status' ), array( '2', 'B', 'confirmed' ) ), $this->t( array( 'Seat ID', 'Name', 'Status' ), array( array( 2, 'B', 'cancelled' ) ) ), 'Seat ID' );
		$this->assertSame( array( array( 2, 2, 'cancelled' ) ), $r['cells'] );
		$this->assertSame( array(), $r['appends'] );
	}

	public function test_unchanged_sheet_produces_no_writes() {
		$r = R::reconcile( array( array( 'Seat ID', 'Name', 'Status' ), array( '2', 'B', 'confirmed' ) ), $this->t( array( 'Seat ID', 'Name', 'Status' ), array( array( 2, 'B', 'confirmed' ) ) ), 'Seat ID' );
		$this->assertSame( array(), $r['header_append'] );
		$this->assertSame( array(), $r['cells'] );
		$this->assertSame( array(), $r['appends'] );
	}

	public function test_new_question_column_is_appended_to_header_end() {
		$r = R::reconcile( array( array( 'Seat ID', 'Name' ), array( '2', 'B' ) ), $this->t( array( 'Seat ID', 'Name', 'Diet' ), array( array( 2, 'B', 'vegan' ) ) ), 'Seat ID' );
		$this->assertSame( array( 'Diet' ), $r['header_append'] );
		$this->assertSame( 2, $r['header_start_col'] );
		$this->assertSame( array( array( 2, 2, 'vegan' ) ), $r['cells'] );
	}

	public function test_hand_added_middle_column_is_never_written() {
		$existing = array( array( 'Seat ID', 'Notes', 'Name', 'Status' ), array( '2', 'vip', 'B', 'confirmed' ) );
		$r        = R::reconcile( $existing, $this->t( array( 'Seat ID', 'Name', 'Status' ), array( array( 3, 'C', 'confirmed' ), array( 2, 'B', 'cancelled' ) ) ), 'Seat ID' );
		$this->assertSame( array( array( 2, 3, 'cancelled' ) ), $r['cells'] );
		$this->assertSame( array( array( '3', '', 'C', 'confirmed' ) ), $r['appends'] );
	}

	public function test_reordered_sheet_columns_are_matched_by_header_text() {
		$r = R::reconcile( array( array( 'Status', 'Name', 'Seat ID' ), array( 'confirmed', 'B', '2' ) ), $this->t( array( 'Seat ID', 'Name', 'Status' ), array( array( 2, 'B', 'cancelled' ) ) ), 'Seat ID' );
		$this->assertSame( array( array( 2, 0, 'cancelled' ) ), $r['cells'] );
	}

	public function test_sheet_row_for_unknown_seat_is_untouched() {
		$r = R::reconcile( array( array( 'Seat ID', 'Name' ), array( '99', 'Z' ) ), $this->t( array( 'Seat ID', 'Name' ), array( array( 2, 'B' ) ) ), 'Seat ID' );
		$this->assertSame( array(), $r['cells'] );
		$this->assertSame( array( array( '2', 'B' ) ), $r['appends'] );
	}

	public function test_duplicate_seat_id_only_first_is_maintained() {
		$r = R::reconcile( array( array( 'Seat ID', 'Name' ), array( '2', 'old' ), array( '2', 'dup' ) ), $this->t( array( 'Seat ID', 'Name' ), array( array( 2, 'new' ) ) ), 'Seat ID' );
		$this->assertSame( array( array( 2, 1, 'new' ) ), $r['cells'] );
	}

	public function test_ragged_rows_compare_missing_cells_as_empty() {
		$r = R::reconcile( array( array( 'Seat ID', 'Name', 'Status' ), array( '2', 'B' ) ), $this->t( array( 'Seat ID', 'Name', 'Status' ), array( array( 2, 'B', '' ) ) ), 'Seat ID' );
		$this->assertSame( array(), $r['cells'] );
	}

	public function test_ints_and_strings_compare_equal() {
		$r = R::reconcile( array( array( 'Seat ID', 'Name' ), array( '2', 'B' ) ), $this->t( array( 'Seat ID', 'Name' ), array( array( 2, 'B' ) ) ), 'Seat ID' );
		$this->assertSame( array(), $r['cells'] );
	}

	public function test_duplicate_header_labels_get_their_own_columns() {
		$table = $this->t( array( 'Seat ID', 'Email', 'Phone', 'Email', 'Phone', 'Phone (2)', 'Seat ID' ), array( array( 1, 'a@x', '111', 'q-email', 'q-phone', 'already', 'q-seat' ) ) );
		$r     = R::reconcile( array(), $table, 'Seat ID' );
		$this->assertSame( array( 'Seat ID', 'Email', 'Phone', 'Email (2)', 'Phone (3)', 'Phone (2)', 'Seat ID (2)' ), $r['header_append'] );
		$this->assertSame( array( array( '1', 'a@x', '111', 'q-email', 'q-phone', 'already', 'q-seat' ) ), $r['appends'] );
		// Next run: the sheet already has the de-duplicated header; nothing collapses, nothing rewrites.
		$sheet = array( $r['header_append'], $r['appends'][0] );
		$again = R::reconcile( $sheet, $table, 'Seat ID' );
		$this->assertSame( array(), $again['header_append'] );
		$this->assertSame( array(), $again['cells'] );
		$this->assertSame( array(), $again['appends'] );
	}
}
