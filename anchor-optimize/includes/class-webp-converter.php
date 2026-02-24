<?php
/**
 * Anchor Optimize — WebP / AVIF Converter.
 *
 * Converts images to next-gen formats. Files sit next to originals
 * with appended extension: image.jpg → image.jpg.webp / image.jpg.avif
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Optimize_WebP_Converter {

    /**
     * Convert an image to WebP.
     *
     * @param string $source_path Absolute path to source image.
     * @param int    $quality     WebP quality (1-100). Default 80.
     * @return array { success: bool, path: string, size: int }
     */
    public static function convert( $source_path, $quality = 80 ) {
        $result = [ 'success' => false, 'path' => '', 'size' => 0 ];

        if ( ! file_exists( $source_path ) ) {
            return $result;
        }

        $output = self::get_webp_path( $source_path );

        // Try Imagick first.
        if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
            try {
                $im = new Imagick( $source_path );
                $im->setImageFormat( 'webp' );
                $im->setImageCompressionQuality( $quality );
                $im->writeImage( $output );
                $im->clear();
                $im->destroy();

                if ( file_exists( $output ) && filesize( $output ) > 0 ) {
                    $result['success'] = true;
                    $result['path']    = $output;
                    $result['size']    = filesize( $output );
                    return $result;
                }
            } catch ( \Exception $e ) {
                // Fall through to GD.
            }
        }

        // Try GD.
        if ( function_exists( 'imagewebp' ) ) {
            $im = self::gd_create_from( $source_path );
            if ( $im ) {
                // Preserve transparency for PNG sources.
                imagepalettetotruecolor( $im );
                imagesavealpha( $im, true );

                if ( imagewebp( $im, $output, $quality ) ) {
                    imagedestroy( $im );
                    if ( file_exists( $output ) && filesize( $output ) > 0 ) {
                        $result['success'] = true;
                        $result['path']    = $output;
                        $result['size']    = filesize( $output );
                        return $result;
                    }
                }
                imagedestroy( $im );
            }
        }

        // Try cwebp binary.
        if ( self::has_cwebp() ) {
            $quality  = (int) $quality;
            $src_safe = escapeshellarg( $source_path );
            $out_safe = escapeshellarg( $output );
            @exec( "cwebp -q {$quality} {$src_safe} -o {$out_safe} 2>&1", $out_lines, $code );

            if ( 0 === $code && file_exists( $output ) && filesize( $output ) > 0 ) {
                $result['success'] = true;
                $result['path']    = $output;
                $result['size']    = filesize( $output );
            }
        }

        return $result;
    }

    /**
     * Convert an image to AVIF.
     *
     * @param string $source_path Absolute path to source image.
     * @param int    $quality     AVIF quality (1-100). Default 65.
     * @return array { success: bool, path: string, size: int }
     */
    public static function convert_avif( $source_path, $quality = 65 ) {
        $result = [ 'success' => false, 'path' => '', 'size' => 0 ];

        if ( ! file_exists( $source_path ) ) {
            return $result;
        }

        $output = self::get_avif_path( $source_path );

        // Imagick with AVIF delegate.
        if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
            try {
                $im = new Imagick( $source_path );
                $im->setImageFormat( 'avif' );
                $im->setImageCompressionQuality( $quality );
                $im->writeImage( $output );
                $im->clear();
                $im->destroy();

                if ( file_exists( $output ) && filesize( $output ) > 0 ) {
                    $result['success'] = true;
                    $result['path']    = $output;
                    $result['size']    = filesize( $output );
                    return $result;
                }
            } catch ( \Exception $e ) {
                // Fall through to GD.
            }
        }

        // GD with AVIF support (PHP 8.1+).
        if ( function_exists( 'imageavif' ) ) {
            $im = self::gd_create_from( $source_path );
            if ( $im ) {
                imagepalettetotruecolor( $im );
                imagesavealpha( $im, true );

                if ( imageavif( $im, $output, $quality ) ) {
                    imagedestroy( $im );
                    if ( file_exists( $output ) && filesize( $output ) > 0 ) {
                        $result['success'] = true;
                        $result['path']    = $output;
                        $result['size']    = filesize( $output );
                        return $result;
                    }
                }
                imagedestroy( $im );
            }
        }

        return $result;
    }

    /**
     * Check if WebP conversion is supported.
     */
    public static function supports_webp() {
        if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
            $formats = Imagick::queryFormats( 'WEBP' );
            if ( ! empty( $formats ) ) return true;
        }
        if ( function_exists( 'imagewebp' ) ) return true;
        if ( self::has_cwebp() ) return true;
        return false;
    }

    /**
     * Check if AVIF conversion is supported.
     */
    public static function supports_avif() {
        if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
            $formats = Imagick::queryFormats( 'AVIF' );
            if ( ! empty( $formats ) ) return true;
        }
        if ( function_exists( 'imageavif' ) ) return true;
        return false;
    }

    /**
     * Get WebP output path for a source file.
     * e.g. /path/to/image-480x320.jpg → /path/to/image-480x320.jpg.webp
     */
    public static function get_webp_path( $source_path ) {
        return $source_path . '.webp';
    }

    /**
     * Get AVIF output path for a source file.
     */
    public static function get_avif_path( $source_path ) {
        return $source_path . '.avif';
    }

    /**
     * Create a GD resource from a source image.
     *
     * @param string $source_path
     * @return GdImage|resource|false
     */
    private static function gd_create_from( $source_path ) {
        $check = wp_check_filetype( $source_path );
        $mime  = $check['type'] ?? '';

        switch ( $mime ) {
            case 'image/jpeg':
                return @imagecreatefromjpeg( $source_path );
            case 'image/png':
                return @imagecreatefrompng( $source_path );
            case 'image/gif':
                return @imagecreatefromgif( $source_path );
            case 'image/webp':
                return function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $source_path ) : false;
            default:
                return false;
        }
    }

    /**
     * Check if cwebp binary is available.
     */
    private static function has_cwebp() {
        if ( ! function_exists( 'exec' ) ) return false;
        @exec( 'which cwebp 2>&1', $output, $code );
        return 0 === $code;
    }
}
