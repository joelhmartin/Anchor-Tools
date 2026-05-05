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
        add_filter( 'bulk_actions-upload', [ $this, 'register_bulk_action' ] );
        add_filter( 'handle_bulk_actions-upload', [ $this, 'handle_bulk_action' ], 10, 3 );

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
            Anchor_Asset_Loader::url( 'anchor-optimize/assets/bulk.css' ),
            [],
            '1.0.0'
        );
        wp_enqueue_script(
            'anchor-optimize-bulk',
            Anchor_Asset_Loader::url( 'anchor-optimize/assets/bulk.js' ),
            [ 'jquery' ],
            '1.1.0',
            true
        );
        wp_localize_script( 'anchor-optimize-bulk', 'AO_Bulk', [
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'anchor_optimize_bulk_nonce' ),
            'batchSize' => self::BATCH_SIZE,
            'selectedIds' => $this->get_selected_ids_from_request(),
            'i18n'      => [
                'scanning'  => __( 'Scanning media library…', 'anchor-schema' ),
                'processing'=> __( 'Processing…', 'anchor-schema' ),
                'complete'  => __( 'Bulk optimization complete!', 'anchor-schema' ),
                'error'     => __( 'An error occurred.', 'anchor-schema' ),
                'noImages'  => __( 'All images are already optimized.', 'anchor-schema' ),
                'selectedReady' => __( 'selected images ready to process.', 'anchor-schema' ),
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

            <div class="ao-bulk-options">
                <h2><?php esc_html_e( 'Bulk Action Options', 'anchor-schema' ); ?></h2>
                <div class="ao-bulk-option-grid">
                    <p>
                        <label for="ao-bulk-operation"><strong><?php esc_html_e( 'Action', 'anchor-schema' ); ?></strong></label><br>
                        <select id="ao-bulk-operation">
                            <option value="optimize"><?php esc_html_e( 'Optimize only', 'anchor-schema' ); ?></option>
                            <option value="resize"><?php esc_html_e( 'Resize then optimize', 'anchor-schema' ); ?></option>
                        </select>
                    </p>
                    <p class="ao-bulk-resize-controls">
                        <label for="ao-bulk-resize-mode"><strong><?php esc_html_e( 'Resize By', 'anchor-schema' ); ?></strong></label><br>
                        <select id="ao-bulk-resize-mode">
                            <option value="width"><?php esc_html_e( 'Specific width', 'anchor-schema' ); ?></option>
                            <option value="height"><?php esc_html_e( 'Specific height', 'anchor-schema' ); ?></option>
                            <option value="percentage"><?php esc_html_e( 'Percentage', 'anchor-schema' ); ?></option>
                        </select>
                    </p>
                    <p class="ao-bulk-resize-controls">
                        <label for="ao-bulk-resize-value"><strong><?php esc_html_e( 'Resize Value', 'anchor-schema' ); ?></strong></label><br>
                        <input type="number" id="ao-bulk-resize-value" class="small-text" min="1" value="1600" />
                    </p>
                </div>
                <?php if ( $this->get_selected_ids_from_request() ) : ?>
                    <p class="description"><?php esc_html_e( 'This page was opened from a media-library selection. Scan will use only those selected images.', 'anchor-schema' ); ?></p>
                <?php endif; ?>
            </div>

            <div id="ao-bulk-controls">
                <button type="button" class="button button-primary" id="ao-bulk-scan">
                    <?php echo $this->get_selected_ids_from_request() ? esc_html__( 'Load Selected Images', 'anchor-schema' ) : esc_html__( 'Scan for Unoptimized Images', 'anchor-schema' ); ?>
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

    /**
     * Add a Media Library bulk action that forwards selected images here.
     *
     * @param array $actions
     * @return array
     */
    public function register_bulk_action( $actions ) {
        $actions['anchor_optimize_transform'] = __( 'Anchor Optimize / Transform', 'anchor-schema' );
        return $actions;
    }

    /**
     * Redirect selected IDs into the bulk optimizer page.
     *
     * @param string $redirect_to
     * @param string $action
     * @param array  $items
     * @return string
     */
    public function handle_bulk_action( $redirect_to, $action, $items ) {
        if ( 'anchor_optimize_transform' !== $action ) {
            return $redirect_to;
        }

        $ids = array_filter( array_map( 'intval', (array) $items ) );
        if ( empty( $ids ) ) {
            return $redirect_to;
        }

        return add_query_arg(
            [
                'page' => self::PAGE_SLUG,
                'ids'  => implode( ',', $ids ),
            ],
            admin_url( 'upload.php' )
        );
    }

    /* ────────────────────────────────────────────────────────
       AJAX: Scan for Unoptimized Images
       ──────────────────────────────────────────────────────── */

    public function ajax_scan() {
        check_ajax_referer( 'anchor_optimize_bulk_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'anchor-schema' ) ] );
        }

        $requested_ids = isset( $_POST['ids'] ) ? array_filter( array_map( 'intval', (array) $_POST['ids'] ) ) : [];

        if ( ! empty( $requested_ids ) ) {
            $all_images = $requested_ids;
        } else {
            // Query all image attachments.
            $all_images = get_posts( [
                'post_type'      => 'attachment',
                'post_mime_type' => 'image',
                'post_status'    => 'inherit',
                'posts_per_page' => -1,
                'fields'         => 'ids',
            ] );
        }

        $selected_mode = ! empty( $requested_ids );
        $total      = count( $all_images );
        $optimized  = 0;
        $unoptimized_ids = [];
        $processable_ids = [];

        foreach ( $all_images as $id ) {
            $meta = get_post_meta( $id, Anchor_Optimize_Module::META_KEY, true );
            if ( ! empty( $meta ) && ( ! empty( $meta['optimized'] ) || ! empty( $meta['compressed'] ) ) ) {
                $optimized++;
            }

            if ( $selected_mode ) {
                $processable_ids[] = $id;
            } else {
                if ( ! empty( $meta ) && ( ! empty( $meta['optimized'] ) || ! empty( $meta['compressed'] ) ) ) {
                    continue;
                }
                $unoptimized_ids[] = $id;
            }
        }

        wp_send_json_success( [
            'total'           => $total,
            'optimized'       => $optimized,
            'unoptimized'     => count( $unoptimized_ids ),
            'ready'           => $selected_mode ? count( $processable_ids ) : count( $unoptimized_ids ),
            'selected_mode'   => $selected_mode,
            'unoptimized_ids' => $unoptimized_ids,
            'processable_ids' => $selected_mode ? $processable_ids : $unoptimized_ids,
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
        $operation_options = Anchor_Optimize_Image_Operations::sanitize_options( [
            'operation'     => $_POST['operation'] ?? 'optimize',
            'save_mode'     => 'inplace',
            'resize_mode'   => $_POST['resize_mode'] ?? 'width',
            'resize_value'  => $_POST['resize_value'] ?? 0,
        ] );

        foreach ( $ids as $attachment_id ) {
            $operation = Anchor_Optimize_Image_Operations::process_attachment( $attachment_id, $operation_options );
            if ( is_wp_error( $operation ) ) {
                $results[] = [
                    'id'      => $attachment_id,
                    'success' => false,
                    'message' => $operation->get_error_message(),
                ];
                continue;
            }

            // Run the optimization pipeline (static method — no module re-instantiation).
            $stats = Anchor_Optimize_Module::optimize_attachment( $operation['attachment_id'], null, $operation );

            if ( false === $stats ) {
                $results[] = [
                    'id'      => $attachment_id,
                    'success' => false,
                    'message' => __( 'File not found or not an image.', 'anchor-schema' ),
                ];
                continue;
            }

            $savings  = (int) ( $stats['full_size_savings'] ?? 0 );
            $pct      = (float) ( $stats['full_size_savings_pct'] ?? 0 );
            $title = get_the_title( $operation['attachment_id'] );

            $results[] = [
                'id'           => $operation['attachment_id'],
                'success'      => true,
                'title'        => $title,
                'savings_pct'  => $pct,
                'savings_size' => size_format( $savings ),
                'has_webp'     => ! empty( $stats['webp_files'] ),
                'has_avif'     => ! empty( $stats['avif_files'] ),
                'operation_message' => $operation['message'] ?? '',
                'errors'       => $stats['errors'] ?? [],
            ];
        }

        wp_send_json_success( [ 'results' => $results ] );
    }

    /**
     * Read selected IDs passed in from the Media Library bulk action.
     *
     * @return array
     */
    private function get_selected_ids_from_request() {
        if ( empty( $_GET['ids'] ) ) {
            return [];
        }

        $ids = array_filter( array_map( 'intval', explode( ',', sanitize_text_field( wp_unslash( $_GET['ids'] ) ) ) ) );
        return array_values( array_unique( $ids ) );
    }
}
