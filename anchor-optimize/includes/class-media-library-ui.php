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

        // Admin assets for media pages.
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_media_assets' ] );
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

        if ( empty( $meta ) || empty( $meta['compressed'] ) ) {
            printf(
                '<span style="color:#999;">%s</span>',
                esc_html__( 'Not optimized', 'anchor-schema' )
            );
            return;
        }

        // Savings percentage.
        $original = $meta['original_size'] ?? 0;
        $savings  = $meta['total_savings'] ?? 0;
        $pct      = $original > 0 ? round( $savings / $original * 100, 1 ) : 0;

        printf(
            '<span style="color:#46b450; font-weight:600;">-%s%%</span>',
            esc_html( $pct )
        );

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

        if ( ! empty( $meta ) && ! empty( $meta['compressed'] ) ) {
            $original = $meta['original_size'] ?? 0;
            $savings  = $meta['total_savings'] ?? 0;
            $pct      = $original > 0 ? round( $savings / $original * 100, 1 ) : 0;

            $tags = [];
            if ( ! empty( $meta['webp_files'] ) ) {
                $tags[] = 'WebP';
            }
            if ( ! empty( $meta['avif_files'] ) ) {
                $tags[] = 'AVIF';
            }

            $html  = '<span style="color:#46b450; font-weight:600;">';
            $html .= sprintf( __( 'Saved %s%%', 'anchor-schema' ), $pct );
            $html .= '</span>';
            $html .= ' <span style="color:#666;">(' . size_format( $savings ) . ')</span>';

            if ( $tags ) {
                $html .= '<br><small>' . esc_html( implode( ' + ', $tags ) ) . ' generated</small>';
            }

            $html .= '<br><small>' . esc_html(
                sprintf( __( 'Engine: %s', 'anchor-schema' ), strtoupper( $meta['engine'] ?? 'unknown' ) )
            ) . '</small>';
        } else {
            $html  = '<span style="color:#999;">' . esc_html__( 'Not optimized', 'anchor-schema' ) . '</span>';
            $html .= sprintf(
                ' <button type="button" class="button button-small ao-optimize-btn" data-id="%d">%s</button>',
                $post->ID,
                esc_html__( 'Optimize Now', 'anchor-schema' )
            );
            $html .= '<span class="ao-optimize-status" style="margin-left:8px;"></span>';
        }

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

        // Run the optimization pipeline (static method — no module re-instantiation).
        $stats = Anchor_Optimize_Module::optimize_attachment( $attachment_id );

        if ( false === $stats ) {
            wp_send_json_error( [ 'message' => __( 'Optimization failed — file not found or not an image.', 'anchor-schema' ) ] );
        }

        $original = $stats['original_size'] ?? 0;
        $savings  = $stats['total_savings'] ?? 0;
        $pct      = $original > 0 ? round( $savings / $original * 100, 1 ) : 0;

        $settings = Anchor_Optimize_Settings::get_settings();

        wp_send_json_success( [
            'savings_pct'    => $pct,
            'savings_size'   => size_format( $savings ),
            'has_webp'       => ! empty( $stats['webp_files'] ),
            'has_avif'       => ! empty( $stats['avif_files'] ),
            'compressed'     => ! empty( $stats['compressed'] ),
            'webp_enabled'   => ! empty( $settings['webp_enabled'] ),
            'avif_enabled'   => ! empty( $settings['avif_enabled'] ),
            'errors'         => $stats['errors'] ?? [],
            'engine'         => $stats['engine'] ?? 'unknown',
        ] );
    }

    /* ────────────────────────────────────────────────────────
       Admin Assets
       ──────────────────────────────────────────────────────── */

    public function enqueue_media_assets( $hook ) {
        // Load on media library pages and any page that uses wp.media (post editors, etc.).
        if ( ! in_array( $hook, [ 'upload.php', 'post.php', 'post-new.php' ], true ) ) {
            return;
        }

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

        // Inline CSS for the column width.
        wp_add_inline_style( 'wp-admin', '.column-anchor_optimize { width: 120px; }' );
    }
}
