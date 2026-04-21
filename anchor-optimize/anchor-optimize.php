<?php
/**
 * Anchor Tools module: Anchor Optimize.
 *
 * Local image compression + WebP/AVIF conversion on upload.
 * No external APIs, no monthly limits, no data leaving the server.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Optimize_Module {

    const META_KEY = '_anchor_optimize_data';

    /** @var Anchor_Optimize_Optimizer */
    private $optimizer;

    /** @var array Cached settings. */
    private $settings;

    public function __construct() {
        // Load dependencies.
        require_once __DIR__ . '/includes/class-optimizer.php';
        require_once __DIR__ . '/includes/class-webp-converter.php';
        require_once __DIR__ . '/includes/class-settings.php';
        require_once __DIR__ . '/includes/class-image-operations.php';
        require_once __DIR__ . '/includes/class-frontend-rewriter.php';
        require_once __DIR__ . '/includes/class-media-library-ui.php';
        require_once __DIR__ . '/includes/class-bulk-processor.php';
        require_once __DIR__ . '/includes/class-background-image.php';

        $this->optimizer = new Anchor_Optimize_Optimizer();
        $this->settings  = Anchor_Optimize_Settings::get_settings();

        // Settings page.
        new Anchor_Optimize_Settings();

        // Frontend rewriter (serves WebP/AVIF to browsers).
        new Anchor_Optimize_Frontend_Rewriter();

        // Media library UI (columns + attachment modal + AJAX optimize).
        new Anchor_Optimize_Media_Library_UI();

        // Bulk processor (Media > Bulk Optimize page).
        new Anchor_Optimize_Bulk_Processor();

        // Background image handler (responsive Divi bg images + WebP swap).
        new Anchor_Optimize_Background_Image();

        // Auto-optimize on upload — fires after WP generates all thumbnails.
        add_filter( 'wp_generate_attachment_metadata', [ $this, 'process_on_upload' ], 10, 2 );

        // Clean up WebP/AVIF files when an attachment is deleted.
        add_action( 'delete_attachment', [ $this, 'cleanup_on_delete' ] );
    }

    /* ════════════════════════════════════════════════════════
       Auto-Optimize on Upload
       ════════════════════════════════════════════════════════ */

    /**
     * Process an attachment after WP generates all thumbnail sizes.
     * This is a filter callback — delegates to the static method.
     *
     * @param array $metadata  Attachment metadata (sizes, file, etc.)
     * @param int   $attachment_id
     * @return array Unmodified metadata.
     */
    public function process_on_upload( $metadata, $attachment_id ) {
        self::optimize_attachment( $attachment_id, $metadata );
        return $metadata;
    }

    /**
     * Optimize a single attachment: compress + generate WebP/AVIF.
     *
     * This is the central processing method. It can be called from:
     *   - The wp_generate_attachment_metadata filter (auto on upload)
     *   - The AJAX single-optimize handler
     *   - The AJAX bulk-optimize handler
     *
     * It does NOT re-instantiate the module or register any hooks.
     *
     * @param int        $attachment_id
     * @param array|null $metadata  Optional. If null, fetched via wp_get_attachment_metadata().
     * @return array|false  Stats array on success, false on failure.
     */
    public static function optimize_attachment( $attachment_id, $metadata = null, $context = [] ) {
        $file = get_attached_file( $attachment_id );
        if ( ! $file || ! file_exists( $file ) ) {
            self::log( "Skipped ID {$attachment_id}: file not found (" . ( $file ?: 'no path' ) . ")" );
            return false;
        }

        $mime = get_post_mime_type( $attachment_id );
        if ( ! $mime || 0 !== strpos( $mime, 'image/' ) ) {
            return false;
        }

        if ( null === $metadata ) {
            $metadata = wp_get_attachment_metadata( $attachment_id );
        }

        $settings  = Anchor_Optimize_Settings::get_settings();
        $optimizer = new Anchor_Optimize_Optimizer();
        $baseline_size = max( 0, (int) ( $context['baseline_size'] ?? filesize( $file ) ) );

        $upload_dir = dirname( $file );
        $stats      = [
            'original_file'    => $file,
            'optimized'        => false,
            'compressed'       => false,
            'original_size'    => filesize( $file ),
            'compressed_size'  => 0,
            'full_size_savings' => 0,
            'full_size_savings_pct' => 0,
            'thumbnail_savings' => 0,
            'total_savings'    => 0,
            'webp_files'       => [],
            'avif_files'       => [],
            'backup_path'      => '',
            'sizes_processed'  => 0,
            'engine'           => $optimizer->get_engine(),
            'timestamp'        => current_time( 'mysql' ),
            'baseline_size'    => $baseline_size,
            'source_attachment_id' => (int) ( $context['source_attachment'] ?? $attachment_id ),
            'operation'        => sanitize_key( $context['operation'] ?? 'optimize' ),
            'operation_applied' => ! empty( $context['operation_applied'] ),
            'created_duplicate' => ! empty( $context['created_duplicate'] ),
            'operation_message' => sanitize_text_field( $context['message'] ?? '' ),
            'errors'           => [],
        ];

        self::log( "Starting optimization for ID {$attachment_id}: {$file} (engine: {$stats['engine']})" );

        // 1. Backup the original full-size file (before any compression).
        if ( ! empty( $settings['backup_originals'] ) ) {
            $stats['backup_path'] = self::backup_original_file( $file );
        }

        // 2. Compress the full-size original.
        $compress_args = self::build_compress_args( $settings );
        $result        = $optimizer->compress( $file, $compress_args );

        if ( $result['success'] ) {
            $stats['compressed']      = $result['new_size'] < $result['original_size'];
            $stats['compressed_size'] = $result['new_size'];
            $stats['full_size_savings'] = max( 0, $baseline_size - $result['new_size'] );
            $stats['full_size_savings_pct'] = $baseline_size > 0
                ? round( $stats['full_size_savings'] / $baseline_size * 100, 1 )
                : 0;
            $stats['total_savings']  += $stats['full_size_savings'];
            if ( $stats['full_size_savings'] > 0 ) {
                $stats['optimized'] = true;
            }
            self::log( "Compressed full-size: {$result['original_size']} → {$result['new_size']} bytes" );
        } else {
            $stats['errors'][] = 'Compression failed for full-size image.';
            self::log( "Compression FAILED for {$file}" );
        }

        // 3. Generate WebP/AVIF for the full-size original.
        self::convert_next_gen_formats( $file, $settings, $stats );

        // 4. Process each thumbnail size.
        if ( ! empty( $metadata['sizes'] ) ) {
            foreach ( $metadata['sizes'] as $size_name => $size_data ) {
                $thumb_path = $upload_dir . '/' . $size_data['file'];
                if ( ! file_exists( $thumb_path ) ) {
                    continue;
                }

                // Compress thumbnail.
                $thumb_result = $optimizer->compress( $thumb_path, $compress_args );
                if ( $thumb_result['success'] ) {
                    $thumb_savings = max( 0, $thumb_result['original_size'] - $thumb_result['new_size'] );
                    $stats['thumbnail_savings'] += $thumb_savings;
                    $stats['total_savings'] += $thumb_savings;
                    if ( $thumb_savings > 0 ) {
                        $stats['optimized'] = true;
                    }
                }

                // Generate WebP/AVIF for thumbnail.
                self::convert_next_gen_formats( $thumb_path, $settings, $stats );

                $stats['sizes_processed']++;
            }
        }

        self::log( "Done ID {$attachment_id}: savings={$stats['total_savings']}b, webp=" . count( $stats['webp_files'] ) . ", avif=" . count( $stats['avif_files'] ) );

        // Store stats in post meta.
        update_post_meta( $attachment_id, self::META_KEY, $stats );

        return $stats;
    }

    /* ════════════════════════════════════════════════════════
       Cleanup on Delete
       ════════════════════════════════════════════════════════ */

    /**
     * Remove WebP/AVIF siblings and backup when an attachment is deleted.
     *
     * @param int $attachment_id
     */
    public function cleanup_on_delete( $attachment_id ) {
        self::cleanup_generated_assets( $attachment_id, true );
    }

    /* ════════════════════════════════════════════════════════
       Internal Helpers
       ════════════════════════════════════════════════════════ */

    /**
     * Build compression args array from settings.
     *
     * @param array $settings
     * @return array
     */
    private static function build_compress_args( $settings ) {
        return [
            'quality'        => (int) $settings['quality'],
            'png_quality'    => (int) $settings['png_quality'],
            'mode'           => $settings['mode'],
            'strip_metadata' => ! empty( $settings['strip_metadata'] ),
            'max_width'      => (int) $settings['max_width'],
        ];
    }

    /**
     * Generate WebP and/or AVIF for a single image file.
     *
     * @param string $file_path
     * @param array  $settings
     * @param array  &$stats  Stats array (modified in place).
     */
    private static function convert_next_gen_formats( $file_path, $settings, &$stats ) {
        if ( ! empty( $settings['webp_enabled'] ) ) {
            $webp = Anchor_Optimize_WebP_Converter::convert(
                $file_path,
                (int) $settings['webp_quality']
            );
            if ( $webp['success'] ) {
                $stats['webp_files'][] = $webp['path'];
                $stats['optimized'] = true;
            } else {
                $stats['errors'][] = 'WebP conversion failed: ' . basename( $file_path );
                self::log( "WebP conversion FAILED for {$file_path}" );
            }
        }

        if ( ! empty( $settings['avif_enabled'] ) ) {
            $avif = Anchor_Optimize_WebP_Converter::convert_avif(
                $file_path,
                (int) $settings['avif_quality']
            );
            if ( $avif['success'] ) {
                $stats['avif_files'][] = $avif['path'];
                $stats['optimized'] = true;
            } else {
                $stats['errors'][] = 'AVIF conversion failed: ' . basename( $file_path );
                self::log( "AVIF conversion FAILED for {$file_path}" );
            }
        }
    }

    /**
     * Backup the original full-size image before compression.
     *
     * @param string $file_path
     * @return string Backup path, or empty string on failure.
     */
    public static function backup_original_file( $file_path ) {
        $uploads   = wp_upload_dir();
        $base_dir  = $uploads['basedir'];
        $relative  = str_replace( $base_dir . '/', '', $file_path );

        $backup_dir  = $base_dir . '/anchor-optimize-backups';
        $backup_path = $backup_dir . '/' . $relative;
        $backup_parent = dirname( $backup_path );

        if ( ! wp_mkdir_p( $backup_parent ) ) {
            return '';
        }

        // Only backup if we haven't already (avoid overwriting a backup with an already-compressed file).
        if ( ! file_exists( $backup_path ) ) {
            if ( copy( $file_path, $backup_path ) ) {
                return $backup_path;
            }
        }

        return file_exists( $backup_path ) ? $backup_path : '';
    }

    /**
     * Clean up WebP/AVIF sibling files for all thumbnails of an attachment.
     *
     * @param string $file          Full-size file path.
     * @param int    $attachment_id
     */
    public static function cleanup_generated_assets( $attachment_id, $remove_backup = false ) {
        $meta = get_post_meta( $attachment_id, self::META_KEY, true );

        if ( ! empty( $meta['webp_files'] ) && is_array( $meta['webp_files'] ) ) {
            foreach ( $meta['webp_files'] as $webp_path ) {
                self::delete_if_exists( $webp_path );
            }
        }

        if ( ! empty( $meta['avif_files'] ) && is_array( $meta['avif_files'] ) ) {
            foreach ( $meta['avif_files'] as $avif_path ) {
                self::delete_if_exists( $avif_path );
            }
        }

        if ( $remove_backup && ! empty( $meta['backup_path'] ) ) {
            self::delete_if_exists( $meta['backup_path'] );
        }

        $file = get_attached_file( $attachment_id );
        if ( $file ) {
            self::cleanup_sibling_files( $file, $attachment_id );
        }
    }

    private static function cleanup_sibling_files( $file, $attachment_id ) {
        $metadata   = wp_get_attachment_metadata( $attachment_id );
        $upload_dir = dirname( $file );

        // Full-size siblings.
        self::delete_if_exists( $file . '.webp' );
        self::delete_if_exists( $file . '.avif' );

        // Thumbnail siblings.
        if ( ! empty( $metadata['sizes'] ) ) {
            foreach ( $metadata['sizes'] as $size_data ) {
                $thumb_path = $upload_dir . '/' . $size_data['file'];
                self::delete_if_exists( $thumb_path . '.webp' );
                self::delete_if_exists( $thumb_path . '.avif' );
            }
        }
    }

    /**
     * Delete a file if it exists.
     *
     * @param string $path
     */
    private static function delete_if_exists( $path ) {
        if ( file_exists( $path ) ) {
            @unlink( $path );
        }
    }

    /**
     * Log a message to the PHP error log when WP_DEBUG is on.
     *
     * @param string $message
     */
    private static function log( $message ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[Anchor Optimize] ' . $message );
        }
    }
}
