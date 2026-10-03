<?php
declare(strict_types=1);

namespace Anchor\Shipping\Services;

use Anchor\Shipping\Domain\PackageLabel;
use Anchor\Shipping\Support\ImagePdf;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Label files under uploads/anchor-shipping. Apache is denied by .htaccess; nginx
 * (Kinsta) ignores it, so names carry a 32-char random token and the only
 * supported way to fetch a label is the admin download handler.
 */
final class LabelStore {

	public function __construct( private ?string $base_dir = null ) {}

	public function dir(): string {
		$dir = $this->base_dir ?? \wp_upload_dir()['basedir'] . '/anchor-shipping';
		if ( ! is_dir( $dir ) ) {
			\wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" );
		}
		return $dir;
	}

	/** @return array{path:string, format:string} */
	public function save( int $order_id, PackageLabel $label ): array {
		$is_zpl = 'ZPL' === strtoupper( $label->format );
		$bytes  = $is_zpl ? $label->bytes : $this->gif_to_pdf( $label->bytes );
		$name   = sprintf( '%d-%s-%s.%s', $order_id, preg_replace( '/[^A-Za-z0-9]/', '', $label->tracking_number ), \wp_generate_password( 32, false ), $is_zpl ? 'zpl' : 'pdf' );
		file_put_contents( $this->dir() . '/' . $name, $bytes );
		return [ 'path' => $name, 'format' => $is_zpl ? 'ZPL' : 'PDF' ];
	}

	public function absolute( string $relative ): ?string {
		if ( '' === $relative || basename( $relative ) !== $relative ) {
			return null;
		}
		$path = $this->dir() . '/' . $relative;
		return is_file( $path ) ? $path : null;
	}

	public function delete( string $relative ): void {
		$path = $this->absolute( $relative );
		if ( $path ) {
			unlink( $path );
		}
	}

	public function content_type( string $format ): string {
		return 'ZPL' === $format ? 'text/plain; charset=utf-8' : 'application/pdf';
	}

	/**
	 * UPS returns a landscape GIF with the label in the left part. Rotate it to
	 * portrait, crop to 2:3 from the top, and place it on one 4x6in page.
	 */
	public function gif_to_pdf( string $gif ): string {
		$im = \imagecreatefromstring( $gif );
		if ( false === $im ) {
			throw new \RuntimeException( 'The carrier returned a label image that could not be read.' );
		}
		if ( \imagesx( $im ) > \imagesy( $im ) ) {
			$im = \imagerotate( $im, 270, 0 ); // 270° counter-clockwise = 90° clockwise
			if ( false === $im ) {
				throw new \RuntimeException( 'The carrier returned a label image that could not be read.' );
			}
		}
		$w      = \imagesx( $im );
		$target = (int) round( $w * 1.5 );
		if ( \imagesy( $im ) > $target ) {
			$im = \imagecrop( $im, [ 'x' => 0, 'y' => 0, 'width' => $w, 'height' => $target ] );
			if ( false === $im ) {
				throw new \RuntimeException( 'The carrier returned a label image that could not be read.' );
			}
		}
		return ImagePdf::from_images( [ $im ], 4, 6 );
	}
}
