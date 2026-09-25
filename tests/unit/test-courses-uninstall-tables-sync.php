<?php
/**
 * Anchor Courses - uninstall table list stays in sync with Migrations::TABLES.
 *
 * uninstall.php runs with no plugin code loaded, so uninstall-tables.php keeps
 * its own literal copy of the five table suffixes instead of referencing
 * \Anchor\Courses\Database\Migrations::TABLES. This test is the guard against
 * that copy silently drifting - a future rename in one place and not the
 * other fails here instead of at uninstall time.
 *
 * @package Anchor\Courses\Tests\Unit
 */

use Anchor\Courses\Database\Migrations;
use PHPUnit\Framework\TestCase;

/** @group courses-unit */
class Test_Courses_Unit_Uninstall_Table_Sync extends TestCase {

	public function test_uninstall_table_suffixes_match_migrations() {
		require_once dirname( __DIR__, 2 ) . '/anchor-courses/uninstall-tables.php';

		$this->assertSame(
			Migrations::TABLES,
			anchor_courses_uninstall_table_suffixes(),
			'anchor-courses/uninstall-tables.php has drifted from Migrations::TABLES - update both.'
		);
	}
}
