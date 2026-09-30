<?php
declare(strict_types=1);

namespace Anchor\Announcements\Admin;

use Anchor\Announcements\Content\AnnouncementPostType as PT;
use Anchor\Announcements\Module;
use Anchor\Announcements\Suppression\Suppressions;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Announcements > Unsubscribed: list, search, add, remove. */
final class SuppressionsPage {

	public const SLUG = 'anchor-announcements-suppressions';

	public function __construct() {
		\add_action( 'admin_menu', [ $this, 'menu' ] );
		\add_action( 'admin_post_anchor_announcements_suppression', [ $this, 'act' ] );
	}

	public function menu(): void {
		\add_submenu_page( 'edit.php?post_type=' . PT::CPT, \__( 'Unsubscribed', 'anchor-schema' ), \__( 'Unsubscribed', 'anchor-schema' ), Module::CAP, self::SLUG, [ $this, 'render' ] );
	}

	public function act(): void {
		if ( ! \current_user_can( Module::CAP ) ) {
			\wp_die( \esc_html__( 'You are not allowed to do that.', 'anchor-schema' ), 403 );
		}
		\check_admin_referer( 'aa_suppression' );
		$email = \sanitize_email( \wp_unslash( $_REQUEST['email'] ?? '' ) );
		'remove' === ( $_REQUEST['do'] ?? '' ) ? Suppressions::remove( $email ) : Suppressions::add( $email, 'manual' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		\wp_safe_redirect( \add_query_arg( [ 'post_type' => PT::CPT, 'page' => self::SLUG ], \admin_url( 'edit.php' ) ) );
		exit;
	}

	public function render(): void {
		$search = \sanitize_text_field( \wp_unslash( $_GET['s'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$page   = \max( 1, \absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$list   = Suppressions::list( $search, $page );
		?>
		<div class="wrap">
			<h1><?php \esc_html_e( 'Unsubscribed and blocked addresses', 'anchor-schema' ); ?></h1>
			<p><?php \esc_html_e( 'Announcements are never sent to these addresses.', 'anchor-schema' ); ?></p>
			<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="anchor_announcements_suppression" />
				<?php \wp_nonce_field( 'aa_suppression' ); ?>
				<input type="email" name="email" required placeholder="<?php \esc_attr_e( 'email@example.com', 'anchor-schema' ); ?>" />
				<?php \submit_button( \__( 'Block address', 'anchor-schema' ), 'secondary', 'submit', false ); ?>
			</form>
			<form method="get">
				<input type="hidden" name="post_type" value="<?php echo \esc_attr( PT::CPT ); ?>" />
				<input type="hidden" name="page" value="<?php echo \esc_attr( self::SLUG ); ?>" />
				<p class="search-box"><input type="search" name="s" value="<?php echo \esc_attr( $search ); ?>" /> <?php \submit_button( \__( 'Search', 'anchor-schema' ), '', '', false ); ?></p>
			</form>
			<table class="widefat striped">
				<thead><tr><th><?php \esc_html_e( 'Email', 'anchor-schema' ); ?></th><th><?php \esc_html_e( 'Reason', 'anchor-schema' ); ?></th><th><?php \esc_html_e( 'Since', 'anchor-schema' ); ?></th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $list['rows'] as $r ) : ?>
					<tr>
						<td><?php echo \esc_html( $r['email'] ); ?></td>
						<td><?php echo \esc_html( $r['reason'] ); ?></td>
						<td><?php echo \esc_html( \get_date_from_gmt( $r['created_at'], (string) \get_option( 'date_format' ) ) ); ?></td>
						<td><a href="<?php echo \esc_url( \wp_nonce_url( \admin_url( 'admin-post.php?action=anchor_announcements_suppression&do=remove&email=' . \rawurlencode( $r['email'] ) ), 'aa_suppression' ) ); ?>"><?php \esc_html_e( 'Remove', 'anchor-schema' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php echo \wp_kses_post( (string) \paginate_links( [ 'total' => (int) \ceil( $list['total'] / 50 ), 'current' => $page ] ) ); ?>
		</div>
		<?php
	}
}
