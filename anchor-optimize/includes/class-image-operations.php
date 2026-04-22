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
        if ( ! in_array( $operation, [ 'optimize', 'resize', 'crop', 'replace' ], true ) ) {
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
        $replacement_upload = $options['replacement_upload'] ?? null;
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
            'replace_summary'    => [],
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

        if ( 'replace' === $options['operation'] ) {
            $replaced = self::replace_attachment_file( $attachment_id, $file, $replacement_upload );
            if ( is_wp_error( $replaced ) ) {
                return $replaced;
            }

            $result['operation_applied'] = true;
            $result['file'] = $replaced['file'];
            $result['replace_summary'] = $replaced['summary'];
            $result['message'] = sprintf(
                __( 'Replaced image and updated %d stored URL references.', 'anchor-schema' ),
                (int) ( $replaced['summary']['total_replacements'] ?? 0 )
            );
            return $result;
        }

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
     * Replace the current attachment file with a newly uploaded source image.
     * Allows file type changes and rewrites stored URL references across the site.
     *
     * @param int         $attachment_id
     * @param string      $current_file
     * @param array|null  $upload
     * @return array|\WP_Error
     */
    private static function replace_attachment_file( $attachment_id, $current_file, $upload ) {
        if ( empty( $upload ) || ! is_array( $upload ) ) {
            return new WP_Error( 'missing_upload', __( 'Choose a replacement image to upload.', 'anchor-schema' ) );
        }

        $error_code = (int) ( $upload['error'] ?? UPLOAD_ERR_NO_FILE );
        if ( UPLOAD_ERR_OK !== $error_code ) {
            return new WP_Error( 'upload_error', self::upload_error_message( $error_code ) );
        }

        $tmp_name = $upload['tmp_name'] ?? '';
        if ( ! $tmp_name || ! file_exists( $tmp_name ) ) {
            return new WP_Error( 'missing_tmp', __( 'The uploaded replacement file was not found.', 'anchor-schema' ) );
        }

        $current_mime = get_post_mime_type( $attachment_id );
        if ( ! $current_mime || 0 !== strpos( $current_mime, 'image/' ) ) {
            return new WP_Error( 'invalid_target', __( 'The current attachment is not a supported image.', 'anchor-schema' ) );
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';

        $old_metadata = wp_get_attachment_metadata( $attachment_id );
        $old_url_map  = self::build_attachment_url_map( $attachment_id, $current_file, $old_metadata );

        $editor = wp_get_image_editor( $tmp_name );
        if ( is_wp_error( $editor ) ) {
            return new WP_Error(
                'invalid_upload_image',
                sprintf(
                    __( 'The replacement image could not be processed: %s', 'anchor-schema' ),
                    $editor->get_error_message()
                )
            );
        }

        $filetype = wp_check_filetype_and_ext( $tmp_name, $upload['name'] ?? '' );
        $requested_mime = $filetype['type'] ?? '';
        if ( ! $requested_mime || 0 !== strpos( $requested_mime, 'image/' ) ) {
            $requested_mime = $upload['type'] ?? '';
        }
        if ( ! $requested_mime || 0 !== strpos( $requested_mime, 'image/' ) ) {
            return new WP_Error( 'invalid_upload_type', __( 'The uploaded replacement file is not a valid image.', 'anchor-schema' ) );
        }

        $supported = wp_get_image_editor_output_format( $tmp_name, $requested_mime );
        if ( empty( $supported ) || empty( $supported['mime-type'] ) ) {
            return new WP_Error(
                'unsupported_conversion',
                __( 'The uploaded image could not be saved in a supported format.', 'anchor-schema' )
            );
        }

        $new_mime = $supported['mime-type'];
        $new_ext = self::mime_to_extension( $new_mime );
        if ( ! $new_ext ) {
            return new WP_Error( 'unsupported_extension', __( 'The uploaded image format is not supported for replacement.', 'anchor-schema' ) );
        }

        $dir = dirname( $current_file );
        $base = pathinfo( $current_file, PATHINFO_FILENAME );
        $new_file = trailingslashit( $dir ) . $base . '.' . $new_ext;

        $settings = Anchor_Optimize_Settings::get_settings();
        if ( ! empty( $settings['backup_originals'] ) ) {
            Anchor_Optimize_Module::backup_original_file( $current_file );
        }

        Anchor_Optimize_Module::cleanup_generated_assets( $attachment_id, false );

        $saved = $editor->save( $new_file, $new_mime );
        if ( is_wp_error( $saved ) ) {
            return new WP_Error(
                'replace_failed',
                sprintf(
                    __( 'The replacement image could not be saved: %s', 'anchor-schema' ),
                    $saved->get_error_message()
                )
            );
        }

        update_attached_file( $attachment_id, $new_file );
        $post_update = [
            'ID' => $attachment_id,
            'post_mime_type' => $saved['mime-type'] ?? $new_mime,
        ];
        $new_guid = wp_get_attachment_url( $attachment_id );
        if ( $new_guid ) {
            $post_update['guid'] = $new_guid;
        }
        wp_update_post( $post_update );

        $meta = wp_generate_attachment_metadata( $attachment_id, $new_file );
        if ( ! is_wp_error( $meta ) && is_array( $meta ) ) {
            wp_update_attachment_metadata( $attachment_id, $meta );
        }

        $new_metadata = wp_get_attachment_metadata( $attachment_id );
        $new_url_map  = self::build_attachment_url_map( $attachment_id, $new_file, $new_metadata );
        $summary      = self::search_replace_attachment_urls( $attachment_id, $old_url_map, $new_url_map );

        self::delete_replaced_files( $current_file, $old_metadata, $new_file, $new_metadata );

        return [
            'file'    => $new_file,
            'summary' => $summary,
        ];
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

    /**
     * Convert upload error codes into readable messages.
     *
     * @param int $code
     * @return string
     */
    private static function upload_error_message( $code ) {
        switch ( $code ) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return __( 'The uploaded replacement image is too large.', 'anchor-schema' );
            case UPLOAD_ERR_PARTIAL:
                return __( 'The replacement image upload was incomplete.', 'anchor-schema' );
            case UPLOAD_ERR_NO_FILE:
                return __( 'Choose a replacement image to upload.', 'anchor-schema' );
            case UPLOAD_ERR_NO_TMP_DIR:
                return __( 'The server is missing a temporary upload directory.', 'anchor-schema' );
            case UPLOAD_ERR_CANT_WRITE:
                return __( 'The server could not write the uploaded replacement image.', 'anchor-schema' );
            case UPLOAD_ERR_EXTENSION:
                return __( 'A server extension stopped the replacement image upload.', 'anchor-schema' );
            default:
                return __( 'The replacement image upload failed.', 'anchor-schema' );
        }
    }

    /**
     * Build a URL map for the original image and generated sizes.
     *
     * @param int          $attachment_id
     * @param string       $file
     * @param array|false  $metadata
     * @return array
     */
    private static function build_attachment_url_map( $attachment_id, $file, $metadata ) {
        $uploads = wp_upload_dir();
        $base_dir = $uploads['basedir'];
        $base_url = $uploads['baseurl'];
        $relative = ltrim( str_replace( $base_dir, '', $file ), '/\\' );
        $dir_rel  = dirname( $relative );
        if ( '.' === $dir_rel ) {
            $dir_rel = '';
        }

        $map = [
            'full' => trailingslashit( $base_url ) . str_replace( '\\', '/', $relative ),
        ];

        if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
            foreach ( $metadata['sizes'] as $size_name => $size_data ) {
                if ( empty( $size_data['file'] ) ) {
                    continue;
                }
                $relative_file = $dir_rel ? trailingslashit( $dir_rel ) . $size_data['file'] : $size_data['file'];
                $map[ $size_name ] = trailingslashit( $base_url ) . str_replace( '\\', '/', $relative_file );
            }
        }

        return $map;
    }

    /**
     * Replace stored URL references from the old attachment files to the new ones.
     *
     * @param int   $attachment_id
     * @param array $old_map
     * @param array $new_map
     * @return array
     */
    private static function search_replace_attachment_urls( $attachment_id, $old_map, $new_map ) {
        $replacements = [];
        foreach ( $old_map as $key => $old_url ) {
            if ( empty( $old_url ) ) {
                continue;
            }
            $replacements[ $old_url ] = $new_map[ $key ] ?? $new_map['full'] ?? '';
        }

        $replacements = array_filter( $replacements, function( $new_url, $old_url ) {
            return ! empty( $new_url ) && $new_url !== $old_url;
        }, ARRAY_FILTER_USE_BOTH );

        if ( empty( $replacements ) ) {
            return [
                'total_replacements' => 0,
                'posts' => 0,
                'postmeta' => 0,
                'options' => 0,
                'termmeta' => 0,
                'usermeta' => 0,
                'commentmeta' => 0,
                'note' => __( 'No stored URL references needed updating.', 'anchor-schema' ),
            ];
        }

        $summary = [
            'total_replacements' => 0,
            'posts' => 0,
            'postmeta' => 0,
            'options' => 0,
            'termmeta' => 0,
            'usermeta' => 0,
            'commentmeta' => 0,
            'note' => __( 'Only database-stored URL usages were updated. Runtime/plugin-generated URLs may still need manual review.', 'anchor-schema' ),
        ];

        self::replace_in_posts_table( $replacements, $summary );
        self::replace_in_meta_table( 'post', $replacements, $summary );
        self::replace_in_options_table( $replacements, $summary );
        self::replace_in_meta_table( 'term', $replacements, $summary );
        self::replace_in_meta_table( 'user', $replacements, $summary );
        self::replace_in_meta_table( 'comment', $replacements, $summary );

        foreach ( $summary as $key => $count ) {
            if ( in_array( $key, [ 'note', 'total_replacements' ], true ) ) {
                continue;
            }
            $summary['total_replacements'] += (int) $count;
        }

        return $summary;
    }

    /**
     * Replace URLs inside posts.
     *
     * @param array $replacements
     * @param array $summary
     * @return void
     */
    private static function replace_in_posts_table( $replacements, &$summary ) {
        global $wpdb;

        $likes = [];
        $params = [];
        foreach ( array_keys( $replacements ) as $old_url ) {
            $likes[] = '(post_content LIKE %s OR post_excerpt LIKE %s)';
            $like = '%' . $wpdb->esc_like( $old_url ) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        if ( empty( $likes ) ) {
            return;
        }

        $sql = "SELECT ID, post_content, post_excerpt FROM {$wpdb->posts} WHERE " . implode( ' OR ', $likes );
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
        if ( empty( $rows ) ) {
            return;
        }

        foreach ( $rows as $row ) {
            $new_content = self::replace_in_value( $row['post_content'], $replacements );
            $new_excerpt = self::replace_in_value( $row['post_excerpt'], $replacements );

            if ( $new_content === $row['post_content'] && $new_excerpt === $row['post_excerpt'] ) {
                continue;
            }

            wp_update_post( [
                'ID'           => (int) $row['ID'],
                'post_content' => $new_content,
                'post_excerpt' => $new_excerpt,
            ] );
            $summary['posts']++;
        }
    }

    /**
     * Replace URLs in a WordPress meta table using metadata APIs.
     *
     * @param string $meta_type
     * @param array  $replacements
     * @param array  $summary
     * @return void
     */
    private static function replace_in_meta_table( $meta_type, $replacements, &$summary ) {
        global $wpdb;

        $map = [
            'post'    => [ 'table' => $wpdb->postmeta, 'id' => 'post_id', 'meta_id' => 'meta_id', 'value' => 'meta_value', 'summary' => 'postmeta' ],
            'term'    => [ 'table' => $wpdb->termmeta, 'id' => 'term_id', 'meta_id' => 'meta_id', 'value' => 'meta_value', 'summary' => 'termmeta' ],
            'user'    => [ 'table' => $wpdb->usermeta, 'id' => 'user_id', 'meta_id' => 'umeta_id', 'value' => 'meta_value', 'summary' => 'usermeta' ],
            'comment' => [ 'table' => $wpdb->commentmeta, 'id' => 'comment_id', 'meta_id' => 'meta_id', 'value' => 'meta_value', 'summary' => 'commentmeta' ],
        ];

        if ( empty( $map[ $meta_type ] ) ) {
            return;
        }

        $config = $map[ $meta_type ];
        $likes = [];
        $params = [];
        foreach ( array_keys( $replacements ) as $old_url ) {
            $likes[] = "{$config['value']} LIKE %s";
            $params[] = '%' . $wpdb->esc_like( $old_url ) . '%';
        }

        $sql = "SELECT {$config['meta_id']} AS meta_id, {$config['id']} AS object_id, meta_key, {$config['value']} AS meta_value FROM {$config['table']} WHERE " . implode( ' OR ', $likes );
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
        if ( empty( $rows ) ) {
            return;
        }

        foreach ( $rows as $row ) {
            $old_value = maybe_unserialize( $row['meta_value'] );
            $new_value = self::replace_in_value( $old_value, $replacements );
            if ( $new_value === $old_value ) {
                continue;
            }

            $wpdb->update(
                $config['table'],
                [ 'meta_value' => maybe_serialize( $new_value ) ],
                [ $config['meta_id'] => (int) $row['meta_id'] ],
                [ '%s' ],
                [ '%d' ]
            );
            $summary[ $config['summary'] ]++;
        }
    }

    /**
     * Replace URLs in options using update_option for serialization safety.
     *
     * @param array $replacements
     * @param array $summary
     * @return void
     */
    private static function replace_in_options_table( $replacements, &$summary ) {
        global $wpdb;

        $likes = [];
        $params = [];
        foreach ( array_keys( $replacements ) as $old_url ) {
            $likes[] = 'option_value LIKE %s';
            $params[] = '%' . $wpdb->esc_like( $old_url ) . '%';
        }

        $sql = "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE " . implode( ' OR ', $likes );
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
        if ( empty( $rows ) ) {
            return;
        }

        foreach ( $rows as $row ) {
            $old_value = maybe_unserialize( $row['option_value'] );
            $new_value = self::replace_in_value( $old_value, $replacements );
            if ( $new_value === $old_value ) {
                continue;
            }

            $autoload = strtolower( (string) $row['autoload'] );
            $autoload_enabled = ! in_array( $autoload, [ 'no', 'off', 'auto-off' ], true );
            update_option( $row['option_name'], $new_value, $autoload_enabled );
            $summary['options']++;
        }
    }

    /**
     * Recursively replace URLs inside strings, arrays, and objects.
     *
     * @param mixed $value
     * @param array $replacements
     * @return mixed
     */
    private static function replace_in_value( $value, $replacements ) {
        if ( is_string( $value ) ) {
            return str_replace( array_keys( $replacements ), array_values( $replacements ), $value );
        }

        if ( is_array( $value ) ) {
            foreach ( $value as $key => $item ) {
                $value[ $key ] = self::replace_in_value( $item, $replacements );
            }
            return $value;
        }

        if ( is_object( $value ) ) {
            foreach ( $value as $key => $item ) {
                $value->$key = self::replace_in_value( $item, $replacements );
            }
            return $value;
        }

        return $value;
    }

    /**
     * Delete the old original file and thumbnails after a replacement.
     *
     * @param string      $old_file
     * @param array|false $old_metadata
     * @param string      $new_file
     * @param array|false $new_metadata
     * @return void
     */
    private static function delete_replaced_files( $old_file, $old_metadata, $new_file, $new_metadata ) {
        $preserve = [];
        if ( $new_file ) {
            $preserve[] = wp_normalize_path( $new_file );
        }

        if ( ! empty( $new_metadata['sizes'] ) && is_array( $new_metadata['sizes'] ) ) {
            $new_dir = dirname( $new_file );
            foreach ( $new_metadata['sizes'] as $size_data ) {
                if ( empty( $size_data['file'] ) ) {
                    continue;
                }
                $preserve[] = wp_normalize_path( trailingslashit( $new_dir ) . $size_data['file'] );
            }
        }

        $old_file_normalized = wp_normalize_path( $old_file );
        if ( $old_file && $old_file_normalized !== wp_normalize_path( $new_file ) && file_exists( $old_file ) ) {
            @unlink( $old_file );
        }

        if ( empty( $old_metadata['sizes'] ) || ! is_array( $old_metadata['sizes'] ) ) {
            return;
        }

        $dir = dirname( $old_file );
        foreach ( $old_metadata['sizes'] as $size_data ) {
            if ( empty( $size_data['file'] ) ) {
                continue;
            }
            $path = trailingslashit( $dir ) . $size_data['file'];
            if ( in_array( wp_normalize_path( $path ), $preserve, true ) ) {
                continue;
            }
            if ( file_exists( $path ) ) {
                @unlink( $path );
            }
        }
    }

    /**
     * Convert a MIME type into a file extension.
     *
     * @param string $mime
     * @return string
     */
    private static function mime_to_extension( $mime ) {
        $map = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
        ];

        return $map[ $mime ] ?? '';
    }
}
