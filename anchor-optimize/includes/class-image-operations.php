<?php
/**
 * Anchor Optimize — Image Operations.
 *
 * Handles pre-optimization image transforms such as resize and crop.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Optimize_Image_Operations {

    /**
     * Build normalized operation options from request data.
     *
     * @param array $input
     * @return array
     */
    public static function sanitize_options( $input ) {
        $operation = sanitize_key( $input['operation'] ?? 'optimize' );
        if ( ! in_array( $operation, [ 'optimize', 'resize', 'crop' ], true ) ) {
            $operation = 'optimize';
        }

        $save_mode = sanitize_key( $input['save_mode'] ?? 'inplace' );
        if ( ! in_array( $save_mode, [ 'inplace', 'duplicate' ], true ) ) {
            $save_mode = 'inplace';
        }

        $resize_mode = sanitize_key( $input['resize_mode'] ?? 'width' );
        if ( ! in_array( $resize_mode, [ 'percentage', 'width', 'height' ], true ) ) {
            $resize_mode = 'width';
        }

        $crop_position = sanitize_key( $input['crop_position'] ?? 'center' );
        $allowed_positions = [
            'center',
            'top',
            'bottom',
            'left',
            'right',
            'top-left',
            'top-right',
            'bottom-left',
            'bottom-right',
        ];
        if ( ! in_array( $crop_position, $allowed_positions, true ) ) {
            $crop_position = 'center';
        }

        return [
            'operation'      => $operation,
            'save_mode'      => $save_mode,
            'resize_mode'    => $resize_mode,
            'resize_value'   => max( 0, (int) ( $input['resize_value'] ?? 0 ) ),
            'crop_width'     => max( 0, (int) ( $input['crop_width'] ?? 0 ) ),
            'crop_height'    => max( 0, (int) ( $input['crop_height'] ?? 0 ) ),
            'crop_position'  => $crop_position,
        ];
    }

    /**
     * Transform an attachment before optimization.
     *
     * @param int   $attachment_id
     * @param array $options
     * @return array|\WP_Error
     */
    public static function process_attachment( $attachment_id, $options ) {
        $options = self::sanitize_options( $options );

        $result = [
            'attachment_id'      => (int) $attachment_id,
            'source_attachment'  => (int) $attachment_id,
            'baseline_size'      => 0,
            'operation'          => $options['operation'],
            'save_mode'          => $options['save_mode'],
            'operation_applied'  => false,
            'created_duplicate'  => false,
            'message'            => '',
            'file'               => '',
        ];

        if ( 'optimize' === $options['operation'] ) {
            $file = get_attached_file( $attachment_id );
            $result['file'] = $file ?: '';
            $result['baseline_size'] = ( $file && file_exists( $file ) ) ? (int) filesize( $file ) : 0;
            $result['message'] = __( 'Optimize only.', 'anchor-schema' );
            return $result;
        }

        $file = get_attached_file( $attachment_id );
        if ( ! $file || ! file_exists( $file ) ) {
            return new WP_Error( 'missing_file', __( 'Original image file is missing.', 'anchor-schema' ) );
        }

        if ( ! wp_attachment_is_image( $attachment_id ) ) {
            return new WP_Error( 'not_image', __( 'Selected attachment is not an image.', 'anchor-schema' ) );
        }

        $result['baseline_size'] = (int) filesize( $file );

        require_once ABSPATH . 'wp-admin/includes/image.php';

        $editor = wp_get_image_editor( $file );
        if ( is_wp_error( $editor ) ) {
            return $editor;
        }

        $size = $editor->get_size();
        $width = isset( $size['width'] ) ? (int) $size['width'] : 0;
        $height = isset( $size['height'] ) ? (int) $size['height'] : 0;

        if ( $width < 1 || $height < 1 ) {
            return new WP_Error( 'invalid_dimensions', __( 'Could not determine image dimensions.', 'anchor-schema' ) );
        }

        if ( 'resize' === $options['operation'] ) {
            $resize = self::calculate_resize_dimensions( $width, $height, $options );
            if ( is_wp_error( $resize ) ) {
                return $resize;
            }

            $resize_result = $editor->resize( $resize['width'], $resize['height'], false );
            if ( is_wp_error( $resize_result ) ) {
                return $resize_result;
            }

            $result['message'] = sprintf(
                __( 'Resized to %1$d × %2$d.', 'anchor-schema' ),
                $resize['width'],
                $resize['height']
            );
        } else {
            $crop = self::calculate_crop_area( $width, $height, $options );
            if ( is_wp_error( $crop ) ) {
                return $crop;
            }

            $crop_result = $editor->crop(
                $crop['src_x'],
                $crop['src_y'],
                $crop['src_w'],
                $crop['src_h'],
                $crop['dst_w'],
                $crop['dst_h']
            );
            if ( is_wp_error( $crop_result ) ) {
                return $crop_result;
            }

            $result['message'] = sprintf(
                __( 'Cropped to %1$d × %2$d (%3$s).', 'anchor-schema' ),
                $crop['dst_w'],
                $crop['dst_h'],
                str_replace( '-', ' ', $options['crop_position'] )
            );
        }

        if ( 'inplace' === $options['save_mode'] ) {
            $settings = Anchor_Optimize_Settings::get_settings();
            if ( ! empty( $settings['backup_originals'] ) ) {
                Anchor_Optimize_Module::backup_original_file( $file );
            }

            $saved = $editor->save( $file );
            if ( is_wp_error( $saved ) ) {
                return $saved;
            }

            Anchor_Optimize_Module::cleanup_generated_assets( $attachment_id, false );

            update_attached_file( $attachment_id, $file );
            wp_update_post( [
                'ID' => $attachment_id,
                'post_mime_type' => $saved['mime-type'] ?? get_post_mime_type( $attachment_id ),
            ] );

            $meta = wp_generate_attachment_metadata( $attachment_id, $file );
            if ( ! is_wp_error( $meta ) && is_array( $meta ) ) {
                wp_update_attachment_metadata( $attachment_id, $meta );
            }

            $result['file'] = $file;
        } else {
            $duplicate = self::save_duplicate_attachment( $attachment_id, $file, $editor, $options['operation'] );
            if ( is_wp_error( $duplicate ) ) {
                return $duplicate;
            }

            $result['attachment_id'] = $duplicate['attachment_id'];
            $result['created_duplicate'] = true;
            $result['file'] = $duplicate['file'];
        }

        $result['operation_applied'] = true;
        return $result;
    }

    /**
     * Calculate resize target dimensions.
     *
     * @param int   $width
     * @param int   $height
     * @param array $options
     * @return array|\WP_Error
     */
    private static function calculate_resize_dimensions( $width, $height, $options ) {
        $value = (int) $options['resize_value'];
        if ( $value < 1 ) {
            return new WP_Error( 'invalid_resize', __( 'Enter a resize value greater than zero.', 'anchor-schema' ) );
        }

        switch ( $options['resize_mode'] ) {
            case 'percentage':
                if ( $value >= 100 ) {
                    return new WP_Error( 'invalid_resize', __( 'Percentage resize must be less than 100%.', 'anchor-schema' ) );
                }
                $target_width  = max( 1, (int) floor( $width * ( $value / 100 ) ) );
                $target_height = max( 1, (int) floor( $height * ( $value / 100 ) ) );
                break;

            case 'height':
                if ( $value >= $height ) {
                    return new WP_Error( 'invalid_resize', __( 'Target height must be smaller than the current image height.', 'anchor-schema' ) );
                }
                $target_height = $value;
                $target_width  = max( 1, (int) round( $width * ( $target_height / $height ) ) );
                break;

            case 'width':
            default:
                if ( $value >= $width ) {
                    return new WP_Error( 'invalid_resize', __( 'Target width must be smaller than the current image width.', 'anchor-schema' ) );
                }
                $target_width  = $value;
                $target_height = max( 1, (int) round( $height * ( $target_width / $width ) ) );
                break;
        }

        return [
            'width'  => $target_width,
            'height' => $target_height,
        ];
    }

    /**
     * Calculate crop coordinates from a target width, height, and anchor position.
     *
     * @param int   $width
     * @param int   $height
     * @param array $options
     * @return array|\WP_Error
     */
    private static function calculate_crop_area( $width, $height, $options ) {
        $dst_w = (int) $options['crop_width'];
        $dst_h = (int) $options['crop_height'];

        if ( $dst_w < 1 || $dst_h < 1 ) {
            return new WP_Error( 'invalid_crop', __( 'Enter crop width and height.', 'anchor-schema' ) );
        }

        $target_ratio = $dst_w / $dst_h;
        $source_ratio = $width / $height;

        if ( $source_ratio > $target_ratio ) {
            $src_h = $height;
            $src_w = (int) round( $height * $target_ratio );
        } else {
            $src_w = $width;
            $src_h = (int) round( $width / $target_ratio );
        }

        $src_x = self::calculate_anchor_offset( $width, $src_w, $options['crop_position'], true );
        $src_y = self::calculate_anchor_offset( $height, $src_h, $options['crop_position'], false );

        return [
            'src_x' => $src_x,
            'src_y' => $src_y,
            'src_w' => $src_w,
            'src_h' => $src_h,
            'dst_w' => $dst_w,
            'dst_h' => $dst_h,
        ];
    }

    /**
     * Calculate anchored crop offset for one axis.
     *
     * @param int    $full
     * @param int    $crop
     * @param string $position
     * @param bool   $horizontal
     * @return int
     */
    private static function calculate_anchor_offset( $full, $crop, $position, $horizontal ) {
        $delta = max( 0, $full - $crop );

        if ( $horizontal ) {
            if ( false !== strpos( $position, 'left' ) ) {
                return 0;
            }
            if ( false !== strpos( $position, 'right' ) ) {
                return $delta;
            }
            if ( 'left' === $position ) {
                return 0;
            }
            if ( 'right' === $position ) {
                return $delta;
            }
        } else {
            if ( false !== strpos( $position, 'top' ) ) {
                return 0;
            }
            if ( false !== strpos( $position, 'bottom' ) ) {
                return $delta;
            }
            if ( 'top' === $position ) {
                return 0;
            }
            if ( 'bottom' === $position ) {
                return $delta;
            }
        }

        return (int) floor( $delta / 2 );
    }

    /**
     * Save a transformed image as a new attachment.
     *
     * @param int             $attachment_id
     * @param string          $source_file
     * @param WP_Image_Editor $editor
     * @param string          $operation
     * @return array|\WP_Error
     */
    private static function save_duplicate_attachment( $attachment_id, $source_file, $editor, $operation ) {
        $uploads = wp_upload_dir();
        $relative = get_post_meta( $attachment_id, '_wp_attached_file', true );
        $subdir = dirname( $relative );
        if ( '.' === $subdir ) {
            $subdir = '';
        }

        $base_dir = trailingslashit( $uploads['basedir'] ) . ( $subdir ? trailingslashit( $subdir ) : '' );
        $info = pathinfo( $source_file );
        $extension = $info['extension'] ?? 'jpg';

        $suffix = ( 'crop' === $operation ) ? 'crop' : 'resized';
        $filename = $info['filename'] . '-' . $suffix . '-' . wp_generate_password( 6, false, false ) . '.' . $extension;
        $path = $base_dir . $filename;

        $saved = $editor->save( $path );
        if ( is_wp_error( $saved ) ) {
            return $saved;
        }

        $attachment = [
            'post_mime_type' => $saved['mime-type'] ?? get_post_mime_type( $attachment_id ),
            'post_title'     => get_the_title( $attachment_id ) . ' (' . ucfirst( $suffix ) . ')',
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];

        $new_attachment_id = wp_insert_attachment( $attachment, $path );
        if ( is_wp_error( $new_attachment_id ) || ! $new_attachment_id ) {
            return new WP_Error( 'create_attachment', __( 'Failed to create duplicate attachment.', 'anchor-schema' ) );
        }

        $meta = wp_generate_attachment_metadata( $new_attachment_id, $path );
        if ( ! is_wp_error( $meta ) && is_array( $meta ) ) {
            wp_update_attachment_metadata( $new_attachment_id, $meta );
        }

        return [
            'attachment_id' => (int) $new_attachment_id,
            'file'          => $path,
        ];
    }
}
