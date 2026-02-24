<?php
/**
 * Anchor Optimize — Bulk Processor.
 *
 * Admin page for bulk-optimizing existing media library images.
 * Uses AJAX-driven batching (not WP-Cron) for real-time progress.
 * Configurable batch size for shared hosting compatibility.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Optimize_Bulk_Processor {

    const PAGE_SLUG  = 'anchor-optimize-bulk';
    const BATCH_SIZE = 5;

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_page' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // AJAX endpoints.
        add_action( 'wp_ajax_anchor_optimize_bulk_scan', [ $this, 'ajax_scan' ] );
        add_action( 'wp_ajax_anchor_optimize_bulk_process', [ $this, 'ajax_process_batch' ] );
    }

    /* ────────────────────────────────────────────────────────
       Admin Page
       ──────────────────────────────────────────────────────── */

    public function add_page() {
        add_media_page(
            __( 'Bulk Optimize', 'anchor-schema' ),
            __( 'Bulk Optimize', 'anchor-schema' ),
            'manage_options',
            self::PAGE_SLUG,
            [ $this, 'render_page' ]
        );
    }

    public function enqueue_assets( $hook ) {
        if ( 'media_page_' . self::PAGE_SLUG !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'anchor-optimize-bulk',
            ANCHOR_TOOLS_PLUGIN_URL . 'anchor-optimize/assets/bulk.css',
            [],
            '1.0.0'
        );
        wp_enqueue_script(
            'anchor-optimize-bulk',
            ANCHOR_TOOLS_PLUGIN_URL . 'anchor-optimize/assets/bulk.js',
            [ 'jquery' ],
            '1.0.0',
            true
        );
        wp_localize_script( 'anchor-optimize-bulk', 'AO_Bulk', [
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'anchor_optimize_bulk_nonce' ),
            'batchSize' => self::BATCH_SIZE,
            'i18n'      => [
                'scanning'  => __( 'Scanning media library…', 'anchor-schema' ),
                'processing'=> __( 'Processing…', 'anchor-schema' ),
                'complete'  => __( 'Bulk optimization complete!', 'anchor-schema' ),
                'error'     => __( 'An error occurred.', 'anchor-schema' ),
                'noImages'  => __( 'All images are already optimized.', 'anchor-schema' ),
            ],
        ] );
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Bulk Optimize Images', 'anchor-schema' ); ?></h1>
            <p><?php esc_html_e( 'Scan your media library for unoptimized images and compress them in batches.', 'anchor-schema' ); ?></p>

            <div id="ao-bulk-controls">
                <button type="button" class="button button-primary" id="ao-bulk-scan">
                    <?php esc_html_e( 'Scan for Unoptimized Images', 'anchor-schema' ); ?>
                </button>
            </div>

            <div id="ao-bulk-status" style="display:none; margin-top:20px;">
                <div id="ao-bulk-summary" class="anchor-optimize-stats"></div>

                <div id="ao-bulk-progress-wrap" style="display:none; margin-top:15px;">
                    <div class="ao-progress-bar">
                        <div class="ao-progress-fill" style="width:0%"></div>
                    </div>
                    <p id="ao-bulk-progress-text"></p>

                    <button type="button" class="button button-primary" id="ao-bulk-start" style="display:none;">
                        <?php esc_html_e( 'Start Optimization', 'anchor-schema' ); ?>
                    </button>
                    <button type="button" class="button" id="ao-bulk-stop" style="display:none;">
                        <?php esc_html_e( 'Stop', 'anchor-schema' ); ?>
                    </button>
                </div>

                <div id="ao-bulk-log" style="margin-top:15px; max-height:300px; overflow-y:auto;"></div>
            </div>
        </div>
        <?php
    }

    /* ────────────────────────────────────────────────────────
       AJAX: Scan for Unoptimized Images
       ──────────────────────────────────────────────────────── */

    public function ajax_scan() {
        check_ajax_referer( 'anchor_optimize_bulk_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'anchor-schema' ) ] );
        }

        // Query all image attachments.
        $all_images = get_posts( [
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'post_status'    => 'inherit',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ] );

        $total      = count( $all_images );
        $optimized  = 0;
        $unoptimized_ids = [];

        foreach ( $all_images as $id ) {
            $meta = get_post_meta( $id, Anchor_Optimize_Module::META_KEY, true );
            if ( ! empty( $meta ) && ! empty( $meta['compressed'] ) ) {
                $optimized++;
            } else {
                $unoptimized_ids[] = $id;
            }
        }

        wp_send_json_success( [
            'total'           => $total,
            'optimized'       => $optimized,
            'unoptimized'     => count( $unoptimized_ids ),
            'unoptimized_ids' => $unoptimized_ids,
        ] );
    }

    /* ────────────────────────────────────────────────────────
       AJAX: Process a Batch
       ──────────────────────────────────────────────────────── */

    public function ajax_process_batch() {
        check_ajax_referer( 'anchor_optimize_bulk_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'anchor-schema' ) ] );
        }

        $ids = isset( $_POST['ids'] ) ? array_map( 'intval', (array) $_POST['ids'] ) : [];
        if ( empty( $ids ) ) {
            wp_send_json_error( [ 'message' => __( 'No images to process.', 'anchor-schema' ) ] );
        }

        $results = [];

        foreach ( $ids as $attachment_id ) {
            $metadata = wp_get_attachment_metadata( $attachment_id );
            if ( ! $metadata ) {
                $results[] = [
                    'id'      => $attachment_id,
                    'success' => false,
                    'message' => __( 'No metadata.', 'anchor-schema' ),
                ];
                continue;
            }

            // Use the module's upload processor.
            $module = new Anchor_Optimize_Module();
            $module->process_on_upload( $metadata, $attachment_id );

            $meta = get_post_meta( $attachment_id, Anchor_Optimize_Module::META_KEY, true );
            $original = $meta['original_size'] ?? 0;
            $savings  = $meta['total_savings'] ?? 0;
            $pct      = $original > 0 ? round( $savings / $original * 100, 1 ) : 0;

            $title = get_the_title( $attachment_id );

            $results[] = [
                'id'           => $attachment_id,
                'success'      => true,
                'title'        => $title,
                'savings_pct'  => $pct,
                'savings_size' => size_format( $savings ),
            ];
        }

        wp_send_json_success( [ 'results' => $results ] );
    }
}
