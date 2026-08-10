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
 *   anchor-compliance/anchor-compliance.php          (OPTION_KEY, cron_hooks())
 */

// Custom tables.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}anchor_consent_log" );      // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}anchor_privacy_requests" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Options.
delete_option( 'anchor_compliance_options' );
delete_option( 'anchor_compliance_log_db_version' );
delete_option( 'anchor_compliance_dsar_db_version' );

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
 * ── (next module with persistent artifacts goes here) ────────────────────
 */
