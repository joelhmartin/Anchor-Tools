<?php
/**
 * Anchor Courses - table drop helper.
 *
 * Deliberately WordPress-free and deliberately NOT namespaced: uninstall.php
 * runs with no plugin code loaded (see uninstall.php's header), so it can only
 * require a file with zero dependencies. Table names are literals here for the
 * same reason - class constants are unavailable at uninstall time.
 *
 * @package Anchor_Tools
 */

if ( ! function_exists( 'anchor_courses_drop_tables' ) ) {
	/**
	 * Drop the five Anchor Courses learner tables.
	 *
	 * @param wpdb $wpdb WordPress database handle.
	 * @return string[] Fully-qualified names of the tables that were dropped.
	 */
	function anchor_courses_drop_tables( $wpdb ) {
		$names   = [ 'enrollments', 'progress', 'quiz_attempts', 'ce_credits', 'certificates' ];
		$dropped = [];

		foreach ( $names as $name ) {
			$table = $wpdb->prefix . 'anchor_courses_' . $name;
			// Built from the hard-coded allowlist above, never from input.
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$dropped[] = $table;
		}

		return $dropped;
	}
}
