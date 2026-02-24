<?php
/**
 * Anchor Optimize — Frontend Rewriter.
 *
 * Serves next-gen image formats (WebP/AVIF) to supported browsers.
 * Two delivery methods:
 *   1. <picture> tag wrapping — HTML rewriting via output buffer.
 *   2. .htaccess rewrite rules — transparent server-side delivery.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Optimize_Frontend_Rewriter {

    const HTACCESS_MARKER = 'Anchor Optimize WebP';

    /** @var array Settings from Anchor_Optimize_Settings. */
    private $settings;

    public function __construct() {
        $this->settings = Anchor_Optimize_Settings::get_settings();

        $method = $this->settings['delivery_method'] ?? 'none';

        if ( 'picture_tags' === $method ) {
            $this->init_picture_tags();
        } elseif ( 'htaccess' === $method ) {
            $this->maybe_write_htaccess_rules();
        }
    }

    /* ════════════════════════════════════════════════════════
       Method 1: <picture> Tag Wrapping
       ════════════════════════════════════════════════════════ */

    private function init_picture_tags() {
        // Don't rewrite in admin, REST, AJAX, or cron contexts.
        if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || defined( 'REST_REQUEST' ) ) {
            return;
        }

        // Rewrite individual attachment images.
        add_filter( 'wp_get_attachment_image', [ $this, 'rewrite_attachment_image' ], 999, 5 );

        // Rewrite images in post content.
        add_filter( 'the_content', [ $this, 'rewrite_content_images' ], 999 );

        // Output buffer for anything we miss (Divi builder output, widgets, etc.).
        add_action( 'template_redirect', [ $this, 'start_output_buffer' ], 1 );
    }

    /**
     * Wrap a single wp_get_attachment_image output in <picture>.
     *
     * @param string $html
     * @param int    $attachment_id
     * @param string|int[] $size
     * @param bool   $icon
     * @param array  $attr
     * @return string
     */
    public function rewrite_attachment_image( $html, $attachment_id, $size, $icon, $attr ) {
        if ( empty( $html ) || false === strpos( $html, '<img' ) ) {
            return $html;
        }

        // Skip if already wrapped in <picture>.
        if ( false !== strpos( $html, '<picture' ) ) {
            return $html;
        }

        return $this->wrap_img_in_picture( $html );
    }

    /**
     * Rewrite <img> tags in post content.
     *
     * @param string $content
     * @return string
     */
    public function rewrite_content_images( $content ) {
        if ( empty( $content ) || false === strpos( $content, '<img' ) ) {
            return $content;
        }
        return $this->rewrite_img_tags( $content );
    }

    /**
     * Start output buffering on the frontend.
     */
    public function start_output_buffer() {
        ob_start( [ $this, 'process_output_buffer' ] );
    }

    /**
     * Process the full-page output buffer.
     *
     * @param string $html
     * @return string
     */
    public function process_output_buffer( $html ) {
        if ( empty( $html ) || false === strpos( $html, '<img' ) ) {
            return $html;
        }
        return $this->rewrite_img_tags( $html );
    }

    /**
     * Find all <img> tags in HTML and wrap them in <picture> elements.
     *
     * @param string $html
     * @return string
     */
    private function rewrite_img_tags( $html ) {
        // Match <img> tags that are NOT already inside <picture> elements.
        // Use a regex to find standalone <img> tags.
        return preg_replace_callback(
            '/<img\s[^>]+>/i',
            function ( $matches ) {
                $img = $matches[0];

                // Skip if this img is already inside a <picture> (check preceding context).
                // We can't reliably detect this in a regex callback, so check for data attribute.
                if ( false !== strpos( $img, 'data-ao-skip' ) ) {
                    return $img;
                }

                return $this->wrap_img_in_picture( $img );
            },
            $html
        );
    }

    /**
     * Wrap a single <img> tag in a <picture> element with WebP/AVIF sources.
     *
     * @param string $img_html The <img> tag HTML.
     * @return string
     */
    private function wrap_img_in_picture( $img_html ) {
        $sources = [];

        // Extract src.
        if ( ! preg_match( '/\bsrc=["\']([^"\']+)["\']/i', $img_html, $src_match ) ) {
            return $img_html;
        }
        $src = $src_match[1];

        // Extract srcset if present.
        $srcset = '';
        if ( preg_match( '/\bsrcset=["\']([^"\']+)["\']/i', $img_html, $srcset_match ) ) {
            $srcset = $srcset_match[1];
        }

        // Extract sizes if present.
        $sizes = '';
        if ( preg_match( '/\bsizes=["\']([^"\']+)["\']/i', $img_html, $sizes_match ) ) {
            $sizes = $sizes_match[1];
        }

        // Only rewrite URLs that point to our uploads directory.
        $uploads = wp_upload_dir();
        $upload_url = $uploads['baseurl'];
        if ( false === strpos( $src, $upload_url ) ) {
            return $img_html;
        }

        // Build AVIF <source> if enabled.
        if ( ! empty( $this->settings['avif_enabled'] ) ) {
            $avif_srcset = $this->convert_srcset_urls( $srcset ?: $src, 'avif' );
            if ( $avif_srcset ) {
                $source = '<source type="image/avif" srcset="' . esc_attr( $avif_srcset ) . '"';
                if ( $sizes ) {
                    $source .= ' sizes="' . esc_attr( $sizes ) . '"';
                }
                $source .= '>';
                $sources[] = $source;
            }
        }

        // Build WebP <source> if enabled.
        if ( ! empty( $this->settings['webp_enabled'] ) ) {
            $webp_srcset = $this->convert_srcset_urls( $srcset ?: $src, 'webp' );
            if ( $webp_srcset ) {
                $source = '<source type="image/webp" srcset="' . esc_attr( $webp_srcset ) . '"';
                if ( $sizes ) {
                    $source .= ' sizes="' . esc_attr( $sizes ) . '"';
                }
                $source .= '>';
                $sources[] = $source;
            }
        }

        // If no next-gen sources, return original.
        if ( empty( $sources ) ) {
            return $img_html;
        }

        // Mark the img so the output buffer doesn't double-wrap it.
        $img_html = str_replace( '<img ', '<img data-ao-skip="1" ', $img_html );

        return '<picture>' . implode( '', $sources ) . $img_html . '</picture>';
    }

    /**
     * Convert a srcset string (or single URL) to next-gen format URLs.
     * Only includes URLs where the .webp/.avif file actually exists on disk.
     *
     * @param string $srcset  Comma-separated srcset or single URL.
     * @param string $format  'webp' or 'avif'.
     * @return string|false   Converted srcset string, or false if none exist.
     */
    private function convert_srcset_urls( $srcset, $format ) {
        $uploads  = wp_upload_dir();
        $base_url = $uploads['baseurl'];
        $base_dir = $uploads['basedir'];

        $entries    = array_map( 'trim', explode( ',', $srcset ) );
        $converted  = [];

        foreach ( $entries as $entry ) {
            // Each entry is "url descriptor" (e.g., "https://…/image-480x320.jpg 480w").
            $parts = preg_split( '/\s+/', $entry, 2 );
            $url   = $parts[0];
            $desc  = $parts[1] ?? '';

            if ( false === strpos( $url, $base_url ) ) {
                continue;
            }

            // Convert URL to file path.
            $relative  = str_replace( $base_url . '/', '', $url );
            $file_path = $base_dir . '/' . $relative;
            $next_gen  = $file_path . '.' . $format;

            if ( file_exists( $next_gen ) ) {
                $next_gen_url = $url . '.' . $format;
                $converted[]  = $desc ? $next_gen_url . ' ' . $desc : $next_gen_url;
            }
        }

        return ! empty( $converted ) ? implode( ', ', $converted ) : false;
    }

    /* ════════════════════════════════════════════════════════
       Method 2: .htaccess Rewrite Rules
       ════════════════════════════════════════════════════════ */

    /**
     * Write mod_rewrite rules for transparent WebP/AVIF delivery.
     * Only writes once (or when settings change).
     */
    private function maybe_write_htaccess_rules() {
        // Only on Apache.
        if ( ! $this->is_apache() ) {
            return;
        }

        $htaccess = $this->get_htaccess_path();
        if ( ! $htaccess ) {
            return;
        }

        $rules = $this->build_htaccess_rules();

        // Use WordPress's insert_with_markers() for safe, idempotent writes.
        insert_with_markers( $htaccess, self::HTACCESS_MARKER, $rules );
    }

    /**
     * Remove our .htaccess rules. Called when delivery method changes away from htaccess.
     */
    public static function remove_htaccess_rules() {
        $htaccess = self::get_htaccess_path_static();
        if ( $htaccess && file_exists( $htaccess ) ) {
            insert_with_markers( $htaccess, self::HTACCESS_MARKER, [] );
        }
    }

    /**
     * Build the rewrite rules array.
     *
     * @return array Lines of .htaccess rules.
     */
    private function build_htaccess_rules() {
        $rules = [];
        $rules[] = '<IfModule mod_rewrite.c>';
        $rules[] = 'RewriteEngine On';
        $rules[] = '';

        // AVIF: serve .avif if browser supports it and file exists.
        if ( ! empty( $this->settings['avif_enabled'] ) ) {
            $rules[] = '# Serve AVIF if browser supports it and file exists';
            $rules[] = 'RewriteCond %{HTTP_ACCEPT} image/avif';
            $rules[] = 'RewriteCond %{REQUEST_URI} \.(jpe?g|png|gif|webp)$';
            $rules[] = 'RewriteCond %{DOCUMENT_ROOT}%{REQUEST_URI}.avif -f';
            $rules[] = 'RewriteRule ^(.+)\.(jpe?g|png|gif|webp)$ $1.$2.avif [T=image/avif,E=REQUEST_image,L]';
            $rules[] = '';
        }

        // WebP: serve .webp if browser supports it and file exists.
        if ( ! empty( $this->settings['webp_enabled'] ) ) {
            $rules[] = '# Serve WebP if browser supports it and file exists';
            $rules[] = 'RewriteCond %{HTTP_ACCEPT} image/webp';
            $rules[] = 'RewriteCond %{REQUEST_URI} \.(jpe?g|png|gif)$';
            $rules[] = 'RewriteCond %{DOCUMENT_ROOT}%{REQUEST_URI}.webp -f';
            $rules[] = 'RewriteRule ^(.+)\.(jpe?g|png|gif)$ $1.$2.webp [T=image/webp,E=REQUEST_image,L]';
            $rules[] = '';
        }

        $rules[] = '</IfModule>';
        $rules[] = '';

        // Prevent caching issues — tell CDNs/proxies the response varies by Accept header.
        $rules[] = '<IfModule mod_headers.c>';
        $rules[] = '  <FilesMatch "\.(jpe?g|png|gif|webp)$">';
        $rules[] = '    Header append Vary Accept';
        $rules[] = '  </FilesMatch>';
        $rules[] = '</IfModule>';

        // Serve correct MIME types for next-gen formats.
        $rules[] = '';
        $rules[] = '<IfModule mod_mime.c>';
        $rules[] = '  AddType image/webp .webp';
        $rules[] = '  AddType image/avif .avif';
        $rules[] = '</IfModule>';

        return $rules;
    }

    /**
     * Check if running on Apache.
     *
     * @return bool
     */
    private function is_apache() {
        global $is_apache;
        return ! empty( $is_apache );
    }

    /**
     * Get .htaccess path (instance method).
     *
     * @return string|false
     */
    private function get_htaccess_path() {
        return self::get_htaccess_path_static();
    }

    /**
     * Get .htaccess path (static).
     *
     * @return string|false
     */
    private static function get_htaccess_path_static() {
        $path = get_home_path() . '.htaccess';
        return file_exists( $path ) || is_writable( dirname( $path ) ) ? $path : false;
    }
}
