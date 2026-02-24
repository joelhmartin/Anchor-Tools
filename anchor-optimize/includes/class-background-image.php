<?php
/**
 * Anchor Optimize — Background Image Handler.
 *
 * Addresses Divi's blind spot: CSS background-image declarations have no
 * srcset equivalent. This class:
 *   1. Parses background-image URLs from rendered page output
 *   2. Injects responsive @media queries serving smaller thumbnails at
 *      tablet (980px) and mobile (480px) breakpoints
 *   3. Swaps to WebP/AVIF versions when the file exists on disk
 *
 * Only processes URLs that point to the WordPress uploads directory.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Optimize_Background_Image {

    /** Divi breakpoints. */
    const BREAKPOINT_TABLET = 980;
    const BREAKPOINT_MOBILE = 480;

    /** @var array Cached settings. */
    private $settings;

    /** @var array Upload dir info. */
    private $uploads;

    public function __construct() {
        $this->settings = Anchor_Optimize_Settings::get_settings();

        if ( empty( $this->settings['bg_images_enabled'] ) ) {
            return;
        }

        // Don't rewrite in admin/AJAX/cron contexts.
        if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || defined( 'REST_REQUEST' ) ) {
            return;
        }

        $this->uploads = wp_upload_dir();

        // Hook late into wp_footer to inject responsive CSS.
        add_action( 'wp_footer', [ $this, 'inject_responsive_css' ], 999 );
    }

    /**
     * Scan the output buffer for background-image URLs and inject responsive CSS.
     *
     * We use wp_footer instead of output buffering to avoid conflicts with
     * the frontend rewriter's buffer. We use a secondary ob to capture
     * only the page's rendered HTML up to this point.
     */
    public function inject_responsive_css() {
        // Get the page HTML rendered so far from WordPress's buffered output.
        $levels = ob_get_level();
        if ( $levels < 1 ) {
            return;
        }

        // Peek at the output without flushing.
        $html = ob_get_contents();
        if ( empty( $html ) ) {
            return;
        }

        $responsive_rules = $this->generate_responsive_rules( $html );
        if ( empty( $responsive_rules ) ) {
            return;
        }

        echo "\n<style id=\"anchor-optimize-bg-responsive\">\n";
        echo $responsive_rules;
        echo "</style>\n";
    }

    /**
     * Parse HTML for inline background-image CSS and generate responsive rules.
     *
     * @param string $html Full page HTML.
     * @return string CSS rules or empty string.
     */
    private function generate_responsive_rules( $html ) {
        $base_url = $this->uploads['baseurl'];
        $base_dir = $this->uploads['basedir'];

        // Find all background-image declarations in style attributes and <style> blocks.
        // Pattern: background-image: url(...)  or  background: ... url(...) ...
        $pattern = '/background(?:-image)?\s*:[^;]*url\(\s*[\'"]?(' . preg_quote( $base_url, '/' ) . '[^\'"\)]+)[\'"]?\s*\)/i';

        if ( ! preg_match_all( $pattern, $html, $matches ) ) {
            return '';
        }

        $urls = array_unique( $matches[1] );
        $rules_tablet = [];
        $rules_mobile = [];

        foreach ( $urls as $url ) {
            $relative  = str_replace( $base_url . '/', '', $url );
            $file_path = $base_dir . '/' . $relative;

            if ( ! file_exists( $file_path ) ) {
                continue;
            }

            // Find the attachment ID for this URL.
            $attachment_id = $this->url_to_attachment_id( $url );
            if ( ! $attachment_id ) {
                continue;
            }

            $metadata = wp_get_attachment_metadata( $attachment_id );
            if ( empty( $metadata['sizes'] ) ) {
                continue;
            }

            // Find best thumbnail for tablet and mobile breakpoints.
            $tablet_url = $this->find_size_for_width( $metadata, $attachment_id, self::BREAKPOINT_TABLET );
            $mobile_url = $this->find_size_for_width( $metadata, $attachment_id, self::BREAKPOINT_MOBILE );

            // Build CSS selector that targets this specific background-image URL.
            // We use an attribute selector on the style containing the URL.
            $escaped_url = $this->css_escape_url( $url );

            if ( $tablet_url && $tablet_url !== $url ) {
                $tablet_final = $this->maybe_webp_url( $tablet_url );
                $rules_tablet[] = $this->build_bg_rule( $escaped_url, $tablet_final );
            }

            if ( $mobile_url && $mobile_url !== $url && $mobile_url !== $tablet_url ) {
                $mobile_final = $this->maybe_webp_url( $mobile_url );
                $rules_mobile[] = $this->build_bg_rule( $escaped_url, $mobile_final );
            }
        }

        $css = '';

        if ( ! empty( $rules_tablet ) ) {
            $css .= "@media (max-width: " . self::BREAKPOINT_TABLET . "px) {\n";
            $css .= implode( "\n", $rules_tablet );
            $css .= "}\n";
        }

        if ( ! empty( $rules_mobile ) ) {
            $css .= "@media (max-width: " . self::BREAKPOINT_MOBILE . "px) {\n";
            $css .= implode( "\n", $rules_mobile );
            $css .= "}\n";
        }

        return $css;
    }

    /**
     * Find the best image size URL for a given max width.
     *
     * @param array $metadata      Attachment metadata.
     * @param int   $attachment_id
     * @param int   $target_width  Desired max width.
     * @return string|false URL or false if no suitable size.
     */
    private function find_size_for_width( $metadata, $attachment_id, $target_width ) {
        $sizes = $metadata['sizes'];
        $candidates = [];

        foreach ( $sizes as $size_name => $size_data ) {
            $w = $size_data['width'] ?? 0;
            if ( $w >= $target_width && $w < ( $metadata['width'] ?? PHP_INT_MAX ) ) {
                $candidates[ $size_name ] = $w;
            }
        }

        if ( empty( $candidates ) ) {
            // Pick the largest available size that's smaller than the original.
            foreach ( $sizes as $size_name => $size_data ) {
                $w = $size_data['width'] ?? 0;
                if ( $w > 0 ) {
                    $candidates[ $size_name ] = $w;
                }
            }
        }

        if ( empty( $candidates ) ) {
            return false;
        }

        // Sort by width ascending and pick the smallest that satisfies the target.
        asort( $candidates );

        // Pick the first one that's >= target, or the largest available.
        $chosen = null;
        foreach ( $candidates as $size_name => $w ) {
            if ( $w >= $target_width ) {
                $chosen = $size_name;
                break;
            }
        }
        if ( ! $chosen ) {
            // Use the largest available thumbnail.
            $chosen = array_key_last( $candidates );
        }

        $src = wp_get_attachment_image_src( $attachment_id, $chosen );
        return $src ? $src[0] : false;
    }

    /**
     * If a WebP version of the URL exists, return the WebP URL; otherwise return original.
     *
     * @param string $url
     * @return string
     */
    private function maybe_webp_url( $url ) {
        if ( empty( $this->settings['webp_enabled'] ) ) {
            return $url;
        }

        $base_url = $this->uploads['baseurl'];
        $base_dir = $this->uploads['basedir'];
        $relative = str_replace( $base_url . '/', '', $url );
        $webp_path = $base_dir . '/' . $relative . '.webp';

        if ( file_exists( $webp_path ) ) {
            return $url . '.webp';
        }

        return $url;
    }

    /**
     * Build a CSS rule targeting elements with a specific background-image URL.
     *
     * Uses [style*="..."] attribute selector to match inline styles.
     *
     * @param string $original_url_escaped CSS-escaped original URL.
     * @param string $replacement_url      New URL to use.
     * @return string CSS rule.
     */
    private function build_bg_rule( $original_url_escaped, $replacement_url ) {
        // Match elements whose inline style contains this background URL.
        return sprintf(
            '  [style*="%s"] { background-image: url("%s") !important; }' . "\n",
            $original_url_escaped,
            esc_url( $replacement_url )
        );
    }

    /**
     * Escape a URL for use inside a CSS attribute selector.
     *
     * @param string $url
     * @return string
     */
    private function css_escape_url( $url ) {
        // Extract just the filename portion for a shorter, more reliable selector.
        $parts = wp_parse_url( $url );
        $path  = $parts['path'] ?? $url;
        return addcslashes( basename( $path ), '"\\' );
    }

    /**
     * Get attachment ID from URL.
     *
     * @param string $url
     * @return int|false
     */
    private function url_to_attachment_id( $url ) {
        return attachment_url_to_postid( $url );
    }
}
