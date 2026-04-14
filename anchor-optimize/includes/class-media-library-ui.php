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
            $ajax_url = admin_url( 'admin-ajax.php' );
            $nonce    = wp_create_nonce( 'anchor_optimize_nonce' );

            $html  = '<span style="color:#999;">' . esc_html__( 'Not optimized', 'anchor-schema' ) . '</span>';
            $html .= sprintf(
                ' <button type="button" class="button button-small ao-optimize-btn"'
                . ' data-id="%d" data-ajax="%s" data-nonce="%s"'
                . ' onclick="var b=this;b.disabled=true;b.textContent=\'Optimizing…\';'
                . 'jQuery.post(b.dataset.ajax,{action:\'anchor_optimize_single\','
                . 'nonce:b.dataset.nonce,attachment_id:b.dataset.id},function(r){'
                . 'if(r.success){var d=r.data,t=[];if(d.has_webp)t.push(\'WebP\');'
                . 'if(d.has_avif)t.push(\'AVIF\');var h=\'<span style=color:#46b450;font-weight:600>Saved \''
                . '+d.savings_pct+\'%%</span> <span style=color:#666>(\'+d.savings_size+\')</span>\';'
                . 'if(t.length)h+=\'<br><small>\'+t.join(\' + \')+\' generated</small>\';'
                . 'if(d.errors&&d.errors.length)h+=\'<br><small style=color:#dc3232>\'+d.errors.join(\'; \')+\'</small>\';'
                . 'b.parentNode.innerHTML=h;}'
                . 'else{b.disabled=false;b.textContent=\'Optimize Now\';'
                . 'b.nextElementSibling.style.color=\'#dc3232\';'
                . 'b.nextElementSibling.textContent=r.data.message||\'Error\';}}'
                . ').fail(function(){b.disabled=false;b.textContent=\'Optimize Now\';'
                . 'b.nextElementSibling.textContent=\'Request failed\';});"'
                . '>%s</button>',
                $post->ID,
                esc_attr( $ajax_url ),
                esc_attr( $nonce ),
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
}
