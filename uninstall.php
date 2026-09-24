<?php
/**
 * Anchor Tools — uninstall cleanup.
 *
 * WordPress loads this file on plugin DELETION (not deactivation), with no
 * plugin code loaded — so everything here uses literal table/option/hook
 * names rather than class constants.
 *
 * Structure: one clearly-bounded section per module that owns persistent
 * artifacts (custom tables, options, cron events). Modules that only store
 * post types / post meta are intentionally left alone — deleting a site's
 * content on uninstall is more destructive than leaving it. When a new
 * module grows persistent artifacts, add a section for it here.
 *
 * @package Anchor_Tools
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

/*
 * ── Anchor Compliance ────────────────────────────────────────────────────
 * A privacy module must not leave privacy data behind: the consent audit
 * log (salted IP/UA hashes) and the DSAR request table (names + emails =
 * PII) are dropped outright, along with the module's options, cron events,
 * and cached transients.
 *
 * Mirrors Anchor_Compliance_Module::cron_hooks() and the modules' const
 * names — keep in sync with:
 *   anchor-compliance/includes/class-consent-log.php (table, DB option, cron)
 *   anchor-compliance/includes/class-dsar.php        (table, DB option)
 *   anchor-compliance/includes/class-settings.php    (EXPORT_AUDIT_OPTION)
 *   anchor-compliance/anchor-compliance.php          (OPTION_KEY, cron_hooks())
 */

// Custom tables.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}anchor_consent_log" );      // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}anchor_privacy_requests" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Options.
delete_option( 'anchor_compliance_options' );
delete_option( 'anchor_compliance_log_db_version' );
delete_option( 'anchor_compliance_dsar_db_version' );
delete_option( 'anchor_compliance_export_audit' ); // D028 consent-log export audit trail.

// Cron events. (The DSAR purge shares this daily hook — one clear covers both.)
wp_clear_scheduled_hook( 'anchor_compliance_purge_log' );

// Transients (geo lookup cache, consent-POST dedupe, DSAR rate limits) all
// share the anchor_cmp_ prefix. They expire on their own, but a clean
// uninstall should not rely on that.
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_anchor\_cmp\_%'
	    OR option_name LIKE '\_transient\_timeout\_anchor\_cmp\_%'"
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

/*
 * -- Anchor Courses ------------------------------------------------------
 * Learner history (enrolments, progress, quiz attempts, CE credits,
 * certificates) is the site's record of what people earned. It is NOT dropped
 * by default, even on delete - brief rule 9 and design spec section 4. A site
 * that genuinely wants it gone sets the opt-in flag first:
 *
 *   update_option( 'anchor_courses_delete_data_on_uninstall', 1, false );
 *
 * Options and the minted capabilities go with the tables, never before them.
 */
if ( get_option( 'anchor_courses_delete_data_on_uninstall' ) ) {
	require_once __DIR__ . '/anchor-courses/uninstall-tables.php';
	anchor_courses_drop_tables( $wpdb );

	delete_option( 'anchor_courses_db_version' );
	delete_option( 'anchor_courses_delete_data_on_uninstall' );

	// The eight minted capabilities live in wp_user_roles; strip them from
	// every role. Literal names: no plugin classes are loaded here.
	$anchor_courses_caps = array(
		'manage_anchor_courses', 'edit_anchor_courses', 'edit_anchor_lessons',
		'edit_anchor_quizzes', 'view_anchor_course_reports', 'manage_anchor_enrollments',
		'manage_anchor_credits', 'manage_anchor_certificates',
	);
	$anchor_courses_roles = wp_roles();
	foreach ( array_keys( $anchor_courses_roles->roles ) as $anchor_courses_role_slug ) {
		$anchor_courses_role = get_role( $anchor_courses_role_slug );
		if ( ! $anchor_courses_role instanceof WP_Role ) {
			continue;
		}
		foreach ( $anchor_courses_caps as $anchor_courses_cap ) {
			$anchor_courses_role->remove_cap( $anchor_courses_cap );
		}
	}
}

/*
 * -- (next module with persistent artifacts goes here) -------------------
 */
