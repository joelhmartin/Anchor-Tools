<?php
/**
 * Anchor Optimize — Compression Engine.
 *
 * Handles image compression using Imagick or GD, with optional resize.
 * Never makes files larger — only keeps compressed version if smaller.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Optimize_Optimizer {

    /** @var string Detected engine: 'imagick', 'gd', or 'none'. */
    private $engine;

    public function __construct() {
        $this->engine = self::detect_engine();
    }

    /**
     * Detect the best available image processing engine.
     *
     * @return string 'imagick' | 'gd' | 'none'
     */
    public static function detect_engine() {
        if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
            return 'imagick';
        }
        if ( function_exists( 'imagecreatefromjpeg' ) ) {
            return 'gd';
        }
        return 'none';
    }

    /**
     * Get the current engine.
     *
     * @return string
     */
    public function get_engine() {
        return $this->engine;
    }

    /**
     * Compress a file in-place.
     *
     * @param string $file_path Absolute path to the image file.
     * @param array  $args {
     *     @type int    $quality        JPEG/WebP quality (1-100). Default 82.
     *     @type int    $png_quality    PNG compression level (0-9). Default 9.
     *     @type string $mode           'smart' | 'lossless' | 'custom'. Default 'smart'.
     *     @type bool   $strip_metadata Strip EXIF/IPTC data. Default true.
     *     @type int    $max_width      Downscale if wider than this (0 = no resize). Default 0.
     * }
     * @return array { success: bool, original_size: int, new_size: int, savings: float }
     */
    public function compress( $file_path, $args = [] ) {
        $defaults = [
            'quality'        => 82,
            'png_quality'    => 9,
            'mode'           => 'smart',
            'strip_metadata' => true,
            'max_width'      => 0,
        ];
        $args = wp_parse_args( $args, $defaults );

        $result = [
            'success'       => false,
            'original_size' => 0,
            'new_size'      => 0,
            'savings'       => 0.0,
        ];

        if ( ! file_exists( $file_path ) || 'none' === $this->engine ) {
            if ( 'none' === $this->engine ) {
                self::log( 'No image engine available (neither Imagick nor GD)' );
            }
            return $result;
        }

        $original_size = filesize( $file_path );
        $result['original_size'] = $original_size;
        $temp_backup = wp_tempnam( wp_basename( $file_path ) );

        if ( ! $temp_backup || ! @copy( $file_path, $temp_backup ) ) {
            $temp_backup = '';
        }

        $mime = $this->get_mime_type( $file_path );
        if ( ! $mime ) {
            if ( $temp_backup ) {
                @unlink( $temp_backup );
            }
            return $result;
        }

        // GIF: skip compression (preserve animation).
        if ( 'image/gif' === $mime ) {
            $result['success']  = true;
            $result['new_size'] = $original_size;
            if ( $temp_backup ) {
                @unlink( $temp_backup );
            }
            return $result;
        }

        // Smart mode quality targets.
        if ( 'smart' === $args['mode'] ) {
            $args['quality']     = 82;
            $args['png_quality'] = 9;
        }

        if ( 'imagick' === $this->engine ) {
            $compressed = $this->compress_with_imagick( $file_path, $mime, $args );
        } else {
            $compressed = $this->compress_with_gd( $file_path, $mime, $args );
        }

        if ( ! $compressed ) {
            if ( $temp_backup ) {
                @unlink( $temp_backup );
            }
            return $result;
        }

        // Clear stat cache so filesize() returns the new value.
        clearstatcache( true, $file_path );
        $new_size = filesize( $file_path );

        // Never make files larger.
        if ( $new_size >= $original_size ) {
            if ( $temp_backup && file_exists( $temp_backup ) ) {
                @copy( $temp_backup, $file_path );
                clearstatcache( true, $file_path );
                $new_size = filesize( $file_path );
            }
            $result['success']  = true;
            $result['new_size'] = $new_size;
            $result['savings']  = 0.0;
            if ( $temp_backup ) {
                @unlink( $temp_backup );
            }
            return $result;
        }

        $result['success']  = true;
        $result['new_size'] = $new_size;
        $result['savings']  = round( ( 1 - $new_size / $original_size ) * 100, 1 );

        if ( $temp_backup ) {
            @unlink( $temp_backup );
        }

        return $result;
    }

    /**
     * Compress using Imagick.
     *
     * @return bool True on success.
     */
    private function compress_with_imagick( $file_path, $mime, $args ) {
        try {
            $im = new Imagick( $file_path );

            // Resize if needed.
            if ( $args['max_width'] > 0 ) {
                $geo = $im->getImageGeometry();
                if ( $geo['width'] > $args['max_width'] ) {
                    $new_height = (int) round( $geo['height'] * ( $args['max_width'] / $geo['width'] ) );
                    $im->resizeImage( $args['max_width'], $new_height, Imagick::FILTER_LANCZOS, 1 );
                }
            }

            if ( $args['strip_metadata'] ) {
                $im->stripImage();
            }

            switch ( $mime ) {
                case 'image/jpeg':
                    $im->setImageCompression( Imagick::COMPRESSION_JPEG );
                    $im->setImageCompressionQuality( $args['quality'] );
                    $im->setInterlaceScheme( Imagick::INTERLACE_PLANE ); // Progressive JPEG.
                    break;

                case 'image/png':
                    // PNG: lossless recompression — quality = compression level.
                    // Imagick PNG quality is a composite: tens digit = zlib level, ones = filter.
                    $im->setImageCompressionQuality( $args['png_quality'] * 10 + 5 );
                    break;

                case 'image/webp':
                    $im->setImageCompressionQuality( $args['quality'] );
                    break;
            }

            $im->writeImage( $file_path );
            $im->clear();
            $im->destroy();

            return true;
        } catch ( \Exception $e ) {
            self::log( "Imagick compress failed: {$e->getMessage()}" );
            return false;
        }
    }

    /**
     * Compress using GD.
     *
     * @return bool True on success.
     */
    private function compress_with_gd( $file_path, $mime, $args ) {
        $im = null;

        switch ( $mime ) {
            case 'image/jpeg':
                $im = @imagecreatefromjpeg( $file_path );
                break;
            case 'image/png':
                $im = @imagecreatefrompng( $file_path );
                break;
            case 'image/webp':
                if ( function_exists( 'imagecreatefromwebp' ) ) {
                    $im = @imagecreatefromwebp( $file_path );
                }
                break;
        }

        if ( ! $im ) {
            return false;
        }

        // Resize if needed.
        if ( $args['max_width'] > 0 ) {
            $width  = imagesx( $im );
            $height = imagesy( $im );
            if ( $width > $args['max_width'] ) {
                $new_height = (int) round( $height * ( $args['max_width'] / $width ) );
                $resized = imagescale( $im, $args['max_width'], $new_height );
                if ( $resized ) {
                    imagedestroy( $im );
                    $im = $resized;
                }
            }
        }

        $success = false;

        switch ( $mime ) {
            case 'image/jpeg':
                // Preserve alpha isn't needed for JPEG, but interlace = progressive.
                imageinterlace( $im, true );
                $success = imagejpeg( $im, $file_path, $args['quality'] );
                break;

            case 'image/png':
                // Preserve transparency.
                imagesavealpha( $im, true );
                $success = imagepng( $im, $file_path, $args['png_quality'] );
                break;

            case 'image/webp':
                if ( function_exists( 'imagewebp' ) ) {
                    $success = imagewebp( $im, $file_path, $args['quality'] );
                }
                break;
        }

        imagedestroy( $im );
        return $success;
    }

    /**
     * Get MIME type for an image file.
     *
     * @param string $file_path
     * @return string|false
     */
    private function get_mime_type( $file_path ) {
        $check = wp_check_filetype( $file_path );
        $mime  = $check['type'] ?? '';

        $supported = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ];
        return in_array( $mime, $supported, true ) ? $mime : false;
    }

    /**
     * Log a debug message.
     *
     * @param string $message
     */
    private static function log( $message ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[Anchor Optimize] ' . $message );
        }
    }
}
