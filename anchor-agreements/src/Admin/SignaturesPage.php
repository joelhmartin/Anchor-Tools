<?php
declare(strict_types=1);

namespace Anchor\Agreements\Admin;

use Anchor\Agreements\Content\AgreementPostType;
use Anchor\Agreements\Database\SignatureRepository;
use Anchor\Agreements\Database\VersionRepository;
use Anchor\Agreements\Frontend\SignedCopyPage;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class SignaturesPage {

	public function __construct() {
		\add_action( 'admin_menu', [ $this, 'menu' ] );
		\add_action( 'admin_post_anchor_agreements_export', [ $this, 'export' ] );
	}

	public function menu(): void {
		\add_submenu_page( 'edit.php?post_type=' . AgreementPostType::CPT, \__( 'Signatures', 'anchor-schema' ), \__( 'Signatures', 'anchor-schema' ), 'manage_woocommerce', 'anchor-agreements-signatures', [ $this, 'render' ] );
	}

	private static function cell( string $v ): string {
		return preg_match( '/^[=+\-@\t\r]/', $v ) ? "'" . $v : $v;
	}

	public static function csv_rows( array $args ): array {
		$args     = array_merge( [ 'per_page' => 100000, 'paged' => 1 ], $args );
		$versions = new VersionRepository();
		$out      = [ [ 'Signature ID', 'Document', 'Version date', 'Signer', 'Email', 'Method', 'Font', 'Order', 'Signed (UTC)', 'IP', 'User agent' ] ];
		foreach ( ( new SignatureRepository() )->search( $args )['rows'] as $r ) {
			$v     = $versions->get( (int) $r['version_id'] );
			$out[] = array_map( [ self::class, 'cell' ], [
				strtoupper( substr( $r['token'], 0, 8 ) ), $v['title'] ?? '', $v['created_at'] ?? '', $r['signer_name'], $r['signer_email'],
				$r['method'], (string) $r['font'], (string) $r['order_id'], $r['signed_at'], $r['ip'], $r['user_agent'],
			] );
		}
		return $out;
	}

	public function export(): void {
		if ( ! \current_user_can( 'manage_woocommerce' ) || ! \check_admin_referer( 'anchor_agreements_export' ) ) {
			\wp_die( 'Forbidden', 403 );
		}
		\nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=signatures-' . gmdate( 'Y-m-d' ) . '.csv' );
		$fh = fopen( 'php://output', 'w' );
		foreach ( self::csv_rows( [ 's' => \sanitize_text_field( \wp_unslash( $_GET['s'] ?? '' ) ), 'agreement_id' => \absint( $_GET['agreement_id'] ?? 0 ) ] ) as $row ) {
			fputcsv( $fh, $row );
		}
		fclose( $fh );
		exit;
	}

	public function render(): void {
		$s      = \sanitize_text_field( \wp_unslash( $_GET['s'] ?? '' ) );
		$aid    = \absint( $_GET['agreement_id'] ?? 0 );
		$paged  = max( 1, \absint( $_GET['paged'] ?? 1 ) );
		$result = ( new SignatureRepository() )->search( [ 's' => $s, 'agreement_id' => $aid, 'paged' => $paged, 'per_page' => 50 ] );
		$export = \wp_nonce_url( \admin_url( 'admin-post.php?action=anchor_agreements_export&s=' . rawurlencode( $s ) . '&agreement_id=' . $aid ), 'anchor_agreements_export' );
		$versions = new VersionRepository();
		echo '<div class="wrap"><h1 class="wp-heading-inline">' . \esc_html__( 'Signatures', 'anchor-schema' ) . '</h1> <a class="page-title-action" href="' . \esc_url( $export ) . '">' . \esc_html__( 'Export CSV', 'anchor-schema' ) . '</a>';
		echo '<form method="get"><input type="hidden" name="post_type" value="' . \esc_attr( AgreementPostType::CPT ) . '" /><input type="hidden" name="page" value="anchor-agreements-signatures" />';
		echo '<p class="search-box"><input type="search" name="s" value="' . \esc_attr( $s ) . '" placeholder="' . \esc_attr__( 'Name, email or order #', 'anchor-schema' ) . '" /> ';
		echo '<select name="agreement_id"><option value="0">' . \esc_html__( 'All documents', 'anchor-schema' ) . '</option>';
		foreach ( \get_posts( [ 'post_type' => AgreementPostType::CPT, 'post_status' => 'any', 'numberposts' => -1 ] ) as $p ) {
			echo '<option value="' . (int) $p->ID . '"' . \selected( $aid, $p->ID, false ) . '>' . \esc_html( $p->post_title ) . '</option>';
		}
		echo '</select> <button class="button">' . \esc_html__( 'Filter', 'anchor-schema' ) . '</button></p></form>';
		echo '<table class="widefat striped"><thead><tr><th>' . \esc_html__( 'Signer', 'anchor-schema' ) . '</th><th>' . \esc_html__( 'Document', 'anchor-schema' ) . '</th><th>' . \esc_html__( 'Order', 'anchor-schema' ) . '</th><th>' . \esc_html__( 'Method', 'anchor-schema' ) . '</th><th>' . \esc_html__( 'Signed', 'anchor-schema' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $result['rows'] as $r ) {
			$order_link = $r['order_id'] ? '<a href="' . \esc_url( \wc_get_order( $r['order_id'] ) ? \wc_get_order( $r['order_id'] )->get_edit_order_url() : '#' ) . '">#' . (int) $r['order_id'] . '</a>' : '<em>' . \esc_html__( 'not ordered', 'anchor-schema' ) . '</em>';
			printf( '<tr><td>%s<br><small>%s</small></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				\esc_html( $r['signer_name'] ), \esc_html( $r['signer_email'] ),
				\esc_html( $versions->get( (int) $r['version_id'] )['title'] ?? '' ),
				$order_link, \esc_html( $r['method'] ),
				\esc_html( \wp_date( 'Y-m-d H:i', strtotime( $r['signed_at'] . ' UTC' ) ) ),
				$r['order_id'] ? '<a href="' . \esc_url( SignedCopyPage::url( $r['token'] ) ) . '" target="_blank">' . \esc_html__( 'View', 'anchor-schema' ) . '</a>' : ''
			);
		}
		echo '</tbody></table>';
		echo \paginate_links( [ 'base' => \add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => (int) ceil( $result['total'] / 50 ) ] ) ?: '';
		echo '</div>';
	}
}
