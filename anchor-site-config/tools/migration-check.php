<?php
/**
 * Anchor Site Config — pre-migration safety check.
 *
 * Run on a production site BEFORE enabling site_config / disabling shortcodes.
 *
 *   wp eval-file anchor-site-config/tools/migration-check.php
 *
 * Reports:
 *   1. Current anchor-shortcodes option contents (values you'll re-enter in site-config).
 *   2. Which legacy shortcodes from anchor-shortcodes actually appear in your site's
 *      post/page content, and how many times.
 *   3. Replacement check: for every used shortcode, which config_* Site Config
 *      shortcode should replace it.
 *   4. Custom shortcodes from the repeater that you'll need to re-add in site-config.
 *
 * Read-only — does not modify anything in the database or on disk.
 */

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "Run this via wp eval-file, not directly.\n" );
    exit( 1 );
}

function anchor_site_config_migration_check_print( $line = '' ) {
    echo $line . "\n";
}

function anchor_site_config_migration_check_config_tag( $tag ) {
    $tag = sanitize_key( (string) $tag );
    if ( $tag === '' ) {
        return '';
    }
    return strpos( $tag, 'config_' ) === 0 ? $tag : 'config_' . $tag;
}

anchor_site_config_migration_check_print( '════════════════════════════════════════════════════════════════' );
anchor_site_config_migration_check_print( ' Anchor Site Config — pre-migration safety check' );
anchor_site_config_migration_check_print( '════════════════════════════════════════════════════════════════' );
anchor_site_config_migration_check_print( ' Site: ' . home_url() );
anchor_site_config_migration_check_print( ' Date: ' . date_i18n( 'Y-m-d H:i:s' ) );
anchor_site_config_migration_check_print( '' );

// ─── 1. Current anchor-shortcodes data ─────────────────────────────────────
anchor_site_config_migration_check_print( '── 1. Current anchor-shortcodes data (re-enter these in Site Config) ──' );
anchor_site_config_migration_check_print( '' );

$opts = get_option( 'anchor_shortcodes_options', null );

// Legacy fallback (anchor-shortcodes also reads cgsl_options as a backup).
if ( empty( $opts ) ) {
    $opts = get_option( 'cgsl_options', null );
    if ( ! empty( $opts ) ) {
        anchor_site_config_migration_check_print( '   (Pulled from legacy cgsl_options — original key was migrated.)' );
    }
}

if ( empty( $opts ) || ! is_array( $opts ) ) {
    anchor_site_config_migration_check_print( '   No anchor-shortcodes option found on this site.' );
    anchor_site_config_migration_check_print( '   This module may not be in use; migration may be a no-op.' );
} else {
    $business_fields = [
        'business_name'     => 'Business name',
        'business_phone'    => 'Phone',
        'business_email'    => 'Email',
        'business_address'  => 'Address',
        'business_hours'    => 'Business hours',
        'site_image_url'    => 'Site image URL',
        'site_image_horizontal'       => 'Site image (horizontal)',
        'site_image_horizontal_white' => 'Site image (horizontal white)',
        'site_image_white'  => 'Site image (white)',
        'site_icon_url'     => 'Site icon URL',
    ];

    foreach ( $business_fields as $key => $label ) {
        if ( ! isset( $opts[ $key ] ) || $opts[ $key ] === '' ) {
            continue;
        }
        $value = is_string( $opts[ $key ] ) ? $opts[ $key ] : wp_json_encode( $opts[ $key ] );
        anchor_site_config_migration_check_print( '   • ' . str_pad( $label, 36 ) . $value );
    }

    // Custom shortcodes from the repeater.
    if ( ! empty( $opts['custom_shortcodes'] ) && is_array( $opts['custom_shortcodes'] ) ) {
        anchor_site_config_migration_check_print( '' );
        anchor_site_config_migration_check_print( '   Custom shortcodes (' . count( $opts['custom_shortcodes'] ) . ' total — re-add in Site Config tab):' );
        foreach ( $opts['custom_shortcodes'] as $row ) {
            $tag     = $row['shortcode'] ?? '';
            $title   = $row['title']     ?? '';
            $content = $row['content']   ?? '';
            $preview = trim( wp_strip_all_tags( $content ) );
            if ( strlen( $preview ) > 80 ) {
                $preview = substr( $preview, 0, 80 ) . '…';
            }
            anchor_site_config_migration_check_print( '     [' . $tag . ']  ' . ( $title !== '' ? "({$title}) " : '' ) . $preview );
        }
    }
}

// ─── 2. Shortcode usage scan ───────────────────────────────────────────────
anchor_site_config_migration_check_print( '' );
anchor_site_config_migration_check_print( '── 2. Shortcode usage in post/page content ──' );
anchor_site_config_migration_check_print( '' );

$legacy_shortcodes = [
    'business_name'               => '[config_business_name]',
    'business_phone'              => '[config_business_phone]',
    'business_email'              => '[config_business_email]',
    'business_address'            => '[config_business_address]',
    'business_hours'              => '[config_business_hours]',
    'phone'                       => '[config_phone]',
    'phone_href'                  => '[config_phone_href]',
    'email'                       => '[config_email]',
    'address'                     => '[config_address]',
    'site_image_url'              => '[config_site_image_url]',
    'site_image_horizontal'       => '[config_site_image_horizontal]',
    'site_image_horizontal_white' => '[config_site_image_horizontal_white]',
    'site_image_white'            => '[config_site_image_white]',
    'site_icon_url'               => '[config_site_icon_url]',
    'current_year'                => '[config_current_year]',
    'site_title'                  => '[config_site_title]',
    'page_title'                  => '[config_page_title]',
];

// Scan all post types (published only) for shortcode mentions in content.
$counts   = array_fill_keys( array_keys( $legacy_shortcodes ), 0 );
$usage    = array_fill_keys( array_keys( $legacy_shortcodes ), [] );

global $wpdb;
// Select post_content up-front so the loop is one DB query instead of N+1.
$rows = $wpdb->get_results( "SELECT ID, post_title, post_type, post_content FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_content != ''" );

foreach ( $rows as $row ) {
    $content = (string) $row->post_content;
    if ( $content === '' ) continue;
    foreach ( $legacy_shortcodes as $tag => $coverage ) {
        // Match [tag] or [tag with attrs] or [tag/]
        if ( preg_match_all( '/\[\s*' . preg_quote( $tag, '/' ) . '(\s[^\]]*)?\s*\/?\s*\]/', $content, $matches ) ) {
            $n = count( $matches[0] );
            $counts[ $tag ] += $n;
            $usage[ $tag ][] = sprintf( '%s #%d "%s" (%dx)', $row->post_type, $row->ID, $row->post_title, $n );
        }
    }
}

// Print summary.
$any_used = false;
foreach ( $counts as $tag => $n ) {
    if ( $n === 0 ) continue;
    $any_used = true;
    $replacement = $legacy_shortcodes[ $tag ];
    anchor_site_config_migration_check_print( '   ✓ [' . $tag . '] used ' . $n . 'x → legacy tag still works; optional replacement: ' . $replacement );
}
if ( ! $any_used ) {
    anchor_site_config_migration_check_print( '   No anchor-shortcodes shortcodes found in any published post/page content.' );
    anchor_site_config_migration_check_print( '   (Note: this scan does not see shortcodes used in template files or theme PHP.)' );
}

// ─── 3. Custom shortcodes from repeater — usage check ──────────────────────
if ( ! empty( $opts['custom_shortcodes'] ) && is_array( $opts['custom_shortcodes'] ) ) {
    anchor_site_config_migration_check_print( '' );
    anchor_site_config_migration_check_print( '── 3. Custom shortcode usage ──' );
    anchor_site_config_migration_check_print( '' );
    foreach ( $opts['custom_shortcodes'] as $row ) {
        $tag = $row['shortcode'] ?? '';
        if ( $tag === '' ) continue;
        $config_tag = anchor_site_config_migration_check_config_tag( $tag );
        $custom_count = 0;
        foreach ( $rows as $r ) {
            // post_content was selected in the initial query — reuse it (no per-row DB hit).
            if ( preg_match_all( '/\[\s*' . preg_quote( $tag, '/' ) . '(\s[^\]]*)?\s*\/?\s*\]/', (string) $r->post_content, $m ) ) {
                $custom_count += count( $m[0] );
            }
        }
        $marker = $custom_count > 0 ? '⚠' : ' ';
        anchor_site_config_migration_check_print( '   ' . $marker . ' [' . $tag . '] — used ' . $custom_count . 'x in content (Site Config registers [' . $config_tag . '] and keeps [' . sanitize_key( $tag ) . '] if available)' );
    }
}

// ─── 4. Theme-file scan (best-effort) ──────────────────────────────────────
anchor_site_config_migration_check_print( '' );
anchor_site_config_migration_check_print( '── 4. Theme file scan for shortcode tokens ──' );
anchor_site_config_migration_check_print( '' );

$theme_dirs = array_unique( [
    get_template_directory(),
    get_stylesheet_directory(),
] );

$theme_hits = [];
foreach ( $theme_dirs as $dir ) {
    if ( ! is_dir( $dir ) ) continue;
    $iter = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ) );
    foreach ( $iter as $file ) {
        if ( ! $file->isFile() ) continue;
        if ( $file->getExtension() !== 'php' ) continue;
        $contents = @file_get_contents( $file->getPathname() );
        if ( $contents === false ) continue;
        foreach ( array_keys( $legacy_shortcodes ) as $tag ) {
            // Token-precise match: '[' + tag must NOT be followed by another identifier
            // character (so [business_name] doesn't false-positive on [business_name_extended]).
            $shortcode_re = '/\[' . preg_quote( $tag, '/' ) . '(?![A-Za-z0-9_])/';
            // Quoted-string matches require word boundaries on both sides.
            $quoted_re    = '/[\'"]' . preg_quote( $tag, '/' ) . '[\'"]/';
            if ( preg_match( $shortcode_re, $contents ) || preg_match( $quoted_re, $contents ) ) {
                $rel = str_replace( ABSPATH, '', $file->getPathname() );
                $theme_hits[ $tag ][] = $rel;
            }
        }
    }
}
if ( empty( $theme_hits ) ) {
    anchor_site_config_migration_check_print( '   No shortcode tags referenced in theme PHP files.' );
} else {
    foreach ( $theme_hits as $tag => $files ) {
        anchor_site_config_migration_check_print( '   [' . $tag . '] referenced in:' );
        foreach ( array_unique( $files ) as $f ) {
            anchor_site_config_migration_check_print( '     • ' . $f );
        }
    }
    anchor_site_config_migration_check_print( '' );
    anchor_site_config_migration_check_print( '   (Legacy references still work unless another shortcode has already claimed the same tag.)' );
}

// ─── Done ──────────────────────────────────────────────────────────────────
anchor_site_config_migration_check_print( '' );
anchor_site_config_migration_check_print( '════════════════════════════════════════════════════════════════' );
anchor_site_config_migration_check_print( ' Next steps:' );
anchor_site_config_migration_check_print( '   1. Pull the latest Anchor Tools (must include the site_config module).' );
anchor_site_config_migration_check_print( '   2. Settings → Anchor Tools → Modules → enable "Site Config".' );
anchor_site_config_migration_check_print( '   3. Settings → Anchor Tools → Site Config tab → re-enter every value' );
anchor_site_config_migration_check_print( '      from section 1 above + every custom shortcode from section 3.' );
anchor_site_config_migration_check_print( '   4. Existing shortcode tags from section 2 should keep working; replacing' );
anchor_site_config_migration_check_print( '      them with config_* equivalents is optional when you want explicit ownership.' );
anchor_site_config_migration_check_print( '   5. Settings → Anchor Tools → Modules → disable "Shortcodes".' );
anchor_site_config_migration_check_print( '   6. Re-spot-check the same pages — output should be identical.' );
anchor_site_config_migration_check_print( '   7. If anything renders wrong, re-enable Shortcodes; Site Config avoids' );
anchor_site_config_migration_check_print( '      overriding existing tags and no data is destroyed.' );
anchor_site_config_migration_check_print( '════════════════════════════════════════════════════════════════' );
