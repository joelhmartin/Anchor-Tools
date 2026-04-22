<?php
/**
 * Anchor Optimize — Media Library UI.
 *
 * Adds optimization stats to:
 *   1. Media list view (custom columns)
 *   2. Attachment detail modal (fields + optimize button)
 *   3. AJAX endpoint for one-click optimize
 *
 * The attachment modal fields also appear in Divi VB's media picker
 * since it uses the standard wp.media frame.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Optimize_Media_Library_UI {

    public function __construct() {
        // List view columns.
        add_filter( 'manage_media_columns', [ $this, 'add_column' ] );
        add_action( 'manage_media_custom_column', [ $this, 'render_column' ], 10, 2 );

        // Attachment detail modal fields.
        add_filter( 'attachment_fields_to_edit', [ $this, 'attachment_fields' ], 10, 2 );

        // AJAX: one-click optimize.
        add_action( 'wp_ajax_anchor_optimize_single', [ $this, 'ajax_optimize_single' ] );

        // Load media.js wherever the media modal is used (admin AND frontend builders).
        add_action( 'wp_enqueue_media', [ $this, 'enqueue_media_script' ] );

        // Admin-only assets (column width CSS).
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_styles' ] );
    }

    /* ────────────────────────────────────────────────────────
       List View Column
       ──────────────────────────────────────────────────────── */

    public function add_column( $columns ) {
        $columns['anchor_optimize'] = __( 'Optimization', 'anchor-schema' );
        return $columns;
    }

    public function render_column( $column_name, $post_id ) {
        if ( 'anchor_optimize' !== $column_name ) {
            return;
        }

        $mime = get_post_mime_type( $post_id );
        if ( ! $mime || 0 !== strpos( $mime, 'image/' ) ) {
            echo '—';
            return;
        }

        $meta = get_post_meta( $post_id, Anchor_Optimize_Module::META_KEY, true );

        if ( empty( $meta ) || ( empty( $meta['optimized'] ) && empty( $meta['compressed'] ) ) ) {
            printf(
                '<span style="color:#999;">%s</span>',
                esc_html__( 'Not optimized', 'anchor-schema' )
            );
            return;
        }

        $full_size_savings = (int) ( $meta['full_size_savings'] ?? 0 );
        $pct = (float) ( $meta['full_size_savings_pct'] ?? 0 );

        if ( $full_size_savings > 0 ) {
            printf(
                '<span style="color:#46b450; font-weight:600;">-%s%%</span>',
                esc_html( $pct )
            );
        } else {
            echo '<span style="color:#646970;">' . esc_html__( 'No full-size change', 'anchor-schema' ) . '</span>';
        }

        // Format tags.
        $tags = [];
        if ( ! empty( $meta['webp_files'] ) ) {
            $tags[] = 'WebP';
        }
        if ( ! empty( $meta['avif_files'] ) ) {
            $tags[] = 'AVIF';
        }
        if ( $tags ) {
            printf( '<br><small>%s</small>', esc_html( implode( ' + ', $tags ) ) );
        }
    }

    /* ────────────────────────────────────────────────────────
       Attachment Detail Modal
       ──────────────────────────────────────────────────────── */

    public function attachment_fields( $form_fields, $post ) {
        $mime = get_post_mime_type( $post->ID );
        if ( ! $mime || 0 !== strpos( $mime, 'image/' ) ) {
            return $form_fields;
        }

        $meta = get_post_meta( $post->ID, Anchor_Optimize_Module::META_KEY, true );
        $ajax_url = admin_url( 'admin-ajax.php' );
        $nonce    = wp_create_nonce( 'anchor_optimize_nonce' );

        $html  = '<div class="ao-media-panel">';
        $html .= '<div class="ao-current-status">' . $this->render_status_html( $meta ) . '</div>';
        $html .= '<div class="ao-action-grid">';
        $html .= '<p><label><strong>' . esc_html__( 'Action', 'anchor-schema' ) . '</strong><br>';
        $html .= '<select class="ao-operation">';
        $html .= '<option value="optimize">' . esc_html__( 'Optimize only', 'anchor-schema' ) . '</option>';
        $html .= '<option value="replace">' . esc_html__( 'Replace image at same URL', 'anchor-schema' ) . '</option>';
        $html .= '<option value="resize">' . esc_html__( 'Resize then optimize', 'anchor-schema' ) . '</option>';
        $html .= '<option value="crop">' . esc_html__( 'Crop then optimize', 'anchor-schema' ) . '</option>';
        $html .= '</select></label></p>';

        $html .= '<p><label><strong>' . esc_html__( 'Save Mode', 'anchor-schema' ) . '</strong><br>';
        $html .= '<select class="ao-save-mode">';
        $html .= '<option value="inplace">' . esc_html__( 'Modify in place', 'anchor-schema' ) . '</option>';
        $html .= '<option value="duplicate">' . esc_html__( 'Create duplicate', 'anchor-schema' ) . '</option>';
        $html .= '</select></label></p>';

        $html .= '<div class="ao-resize-controls" style="display:none;">';
        $html .= '<p><label><strong>' . esc_html__( 'Resize By', 'anchor-schema' ) . '</strong><br>';
        $html .= '<select class="ao-resize-mode">';
        $html .= '<option value="width">' . esc_html__( 'Specific width', 'anchor-schema' ) . '</option>';
        $html .= '<option value="height">' . esc_html__( 'Specific height', 'anchor-schema' ) . '</option>';
        $html .= '<option value="percentage">' . esc_html__( 'Percentage', 'anchor-schema' ) . '</option>';
        $html .= '</select></label></p>';
        $html .= '<p><label><strong>' . esc_html__( 'Resize Value', 'anchor-schema' ) . '</strong><br>';
        $html .= '<input type="number" class="small-text ao-resize-value" min="1" value="1600" /></label></p>';
        $html .= '</div>';

        $html .= '<div class="ao-replace-controls" style="display:none;">';
        $html .= '<p><label><strong>' . esc_html__( 'Replacement Upload', 'anchor-schema' ) . '</strong><br>';
        $html .= '<input type="file" class="ao-replacement-file" accept="image/*" /></label></p>';
        $html .= '<p class="ao-help-text">' . esc_html__( 'Uploads a new image and writes it over the existing attachment path so the current URL keeps working.', 'anchor-schema' ) . '</p>';
        $html .= '</div>';

        $html .= '<div class="ao-crop-controls" style="display:none;">';
        $html .= '<p><label><strong>' . esc_html__( 'Crop Width', 'anchor-schema' ) . '</strong><br>';
        $html .= '<input type="number" class="small-text ao-crop-width" min="1" value="1200" /></label></p>';
        $html .= '<p><label><strong>' . esc_html__( 'Crop Height', 'anchor-schema' ) . '</strong><br>';
        $html .= '<input type="number" class="small-text ao-crop-height" min="1" value="1200" /></label></p>';
        $html .= '<p><label><strong>' . esc_html__( 'Crop Position', 'anchor-schema' ) . '</strong><br>';
        $html .= '<select class="ao-crop-position">';
        foreach ( $this->get_crop_positions() as $value => $label ) {
            $html .= '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>';
        }
        $html .= '</select></label></p>';
        $html .= '</div>';
        $html .= '</div>';

        $html .= sprintf(
            '<p><button type="button" class="button button-small ao-optimize-btn" data-id="%1$d" data-ajax="%2$s" data-nonce="%3$s">%4$s</button> <span class="ao-optimize-status" style="margin-left:8px;"></span></p>',
            $post->ID,
            esc_attr( $ajax_url ),
            esc_attr( $nonce ),
            esc_html__( 'Run Image Action', 'anchor-schema' )
        );
        $html .= '</div>';

        $form_fields['anchor_optimize'] = [
            'label' => __( 'Optimization', 'anchor-schema' ),
            'input' => 'html',
            'html'  => $html,
        ];

        return $form_fields;
    }

    /* ────────────────────────────────────────────────────────
       AJAX: Single Optimize
       ──────────────────────────────────────────────────────── */

    public function ajax_optimize_single() {
        check_ajax_referer( 'anchor_optimize_nonce', 'nonce' );

        if ( ! current_user_can( 'upload_files' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'anchor-schema' ) ] );
        }

        $attachment_id = (int) ( $_POST['attachment_id'] ?? 0 );
        if ( ! $attachment_id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid attachment.', 'anchor-schema' ) ] );
        }

        $mime = get_post_mime_type( $attachment_id );
        if ( ! $mime || 0 !== strpos( $mime, 'image/' ) ) {
            wp_send_json_error( [ 'message' => __( 'Not an image.', 'anchor-schema' ) ] );
        }

        $operation = Anchor_Optimize_Image_Operations::process_attachment(
            $attachment_id,
            [
                'operation'     => $_POST['operation'] ?? 'optimize',
                'save_mode'     => $_POST['save_mode'] ?? 'inplace',
                'resize_mode'   => $_POST['resize_mode'] ?? 'width',
                'resize_value'  => $_POST['resize_value'] ?? 0,
                'crop_width'    => $_POST['crop_width'] ?? 0,
                'crop_height'   => $_POST['crop_height'] ?? 0,
                'crop_position' => $_POST['crop_position'] ?? 'center',
                'replacement_upload' => $_FILES['replacement_file'] ?? null,
            ]
        );

        if ( is_wp_error( $operation ) ) {
            wp_send_json_error( [ 'message' => $operation->get_error_message() ] );
        }

        // Run the optimization pipeline (static method — no module re-instantiation).
        $stats = Anchor_Optimize_Module::optimize_attachment( $operation['attachment_id'], null, $operation );

        if ( false === $stats ) {
            wp_send_json_error( [ 'message' => __( 'Optimization failed — file not found or not an image.', 'anchor-schema' ) ] );
        }

        $settings = Anchor_Optimize_Settings::get_settings();

        wp_send_json_success( [
            'attachment_id'  => (int) $operation['attachment_id'],
            'savings_pct'    => (float) ( $stats['full_size_savings_pct'] ?? 0 ),
            'savings_size'   => size_format( (int) ( $stats['full_size_savings'] ?? 0 ) ),
            'has_webp'       => ! empty( $stats['webp_files'] ),
            'has_avif'       => ! empty( $stats['avif_files'] ),
            'compressed'     => ! empty( $stats['optimized'] ),
            'created_duplicate' => ! empty( $operation['created_duplicate'] ),
            'operation_message' => $operation['message'] ?? '',
            'webp_enabled'   => ! empty( $settings['webp_enabled'] ),
            'avif_enabled'   => ! empty( $settings['avif_enabled'] ),
            'errors'         => $stats['errors'] ?? [],
            'engine'         => $stats['engine'] ?? 'unknown',
            'status_html'    => $this->render_status_html( $stats ),
        ] );
    }

    /* ────────────────────────────────────────────────────────
       Assets
       ──────────────────────────────────────────────────────── */

    /**
     * Enqueue the media.js click handler wherever wp.media is loaded.
     *
     * Fires on the wp_enqueue_media action, which triggers on admin pages
     * AND frontend page builders (Divi VB, Elementor, etc.) — anywhere
     * the media modal can appear.
     */
    public function enqueue_media_script() {
        // Prevent double-enqueue in the same request.
        static $enqueued = false;
        if ( $enqueued ) {
            return;
        }
        $enqueued = true;

        wp_enqueue_script(
            'anchor-optimize-media',
            ANCHOR_TOOLS_PLUGIN_URL . 'anchor-optimize/assets/media.js',
            [ 'jquery' ],
            '1.1.0',
            true
        );
        wp_localize_script( 'anchor-optimize-media', 'AO_Media', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'anchor_optimize_nonce' ),
        ] );
    }

    /**
     * Admin-only styles (media list column width).
     */
    public function enqueue_admin_styles( $hook ) {
        if ( 'upload.php' !== $hook ) {
            return;
        }
        wp_add_inline_style( 'wp-admin', '.column-anchor_optimize { width: 120px; }' );
    }

    /**
     * Render the current optimization summary.
     *
     * @param array $meta
     * @return string
     */
    private function render_status_html( $meta ) {
        if ( empty( $meta ) ) {
            return '<span style="color:#999;">' . esc_html__( 'Not optimized yet.', 'anchor-schema' ) . '</span>';
        }

        $html = '';
        $full_size_savings = (int) ( $meta['full_size_savings'] ?? 0 );
        $pct = (float) ( $meta['full_size_savings_pct'] ?? 0 );
        $tags = [];

        if ( ! empty( $meta['webp_files'] ) ) {
            $tags[] = 'WebP';
        }
        if ( ! empty( $meta['avif_files'] ) ) {
            $tags[] = 'AVIF';
        }

        if ( $full_size_savings > 0 ) {
            $html .= '<span style="color:#46b450; font-weight:600;">';
            $html .= sprintf( esc_html__( 'Saved %s%%', 'anchor-schema' ), esc_html( $pct ) );
            $html .= '</span> <span style="color:#666;">(' . esc_html( size_format( $full_size_savings ) ) . ')</span>';
        } else {
            $html .= '<span style="color:#646970;">' . esc_html__( 'No full-size reduction on the last run.', 'anchor-schema' ) . '</span>';
        }

        if ( $tags ) {
            $html .= '<br><small>' . esc_html( implode( ' + ', $tags ) ) . ' ' . esc_html__( 'generated', 'anchor-schema' ) . '</small>';
        }

        if ( ! empty( $meta['operation_message'] ) ) {
            $html .= '<br><small>' . esc_html( $meta['operation_message'] ) . '</small>';
        }

        if ( ! empty( $meta['created_duplicate'] ) ) {
            $html .= '<br><small>' . esc_html__( 'Last run created a duplicate attachment.', 'anchor-schema' ) . '</small>';
        }

        if ( ! empty( $meta['errors'] ) ) {
            $html .= '<br><small style="color:#dc3232;">' . esc_html( implode( '; ', (array) $meta['errors'] ) ) . '</small>';
        }

        $html .= '<br><small>' . esc_html(
            sprintf( __( 'Engine: %s', 'anchor-schema' ), strtoupper( $meta['engine'] ?? 'unknown' ) )
        ) . '</small>';

        return $html;
    }

    /**
     * Get crop position labels.
     *
     * @return array
     */
    private function get_crop_positions() {
        return [
            'center'       => __( 'Center', 'anchor-schema' ),
            'top'          => __( 'Top', 'anchor-schema' ),
            'bottom'       => __( 'Bottom', 'anchor-schema' ),
            'left'         => __( 'Left', 'anchor-schema' ),
            'right'        => __( 'Right', 'anchor-schema' ),
            'top-left'     => __( 'Top Left', 'anchor-schema' ),
            'top-right'    => __( 'Top Right', 'anchor-schema' ),
            'bottom-left'  => __( 'Bottom Left', 'anchor-schema' ),
            'bottom-right' => __( 'Bottom Right', 'anchor-schema' ),
        ];
    }
}
