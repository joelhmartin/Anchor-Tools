<?php
declare(strict_types=1);

namespace Anchor\Announcements\Admin;

use Anchor\Announcements\Content\AnnouncementPostType as PT;
use Anchor\Announcements\Database\Migrations;
use Anchor\Announcements\Module;
use Anchor\Announcements\Sending\Queue;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Totals, per-recipient and per-link reporting for one announcement. */
final class Reports {

	public const PER_PAGE = 50;

	public function __construct() {
		\add_action( 'add_meta_boxes_' . PT::CPT, [ $this, 'box' ] );
		\add_action( 'admin_post_anchor_announcements_csv', [ $this, 'csv' ] );
	}

	private static function sends(): string { return Migrations::table( 'sends' ); }
	private static function events(): string { return Migrations::table( 'events' ); }

	public static function stats( int $id ): array {
		global $wpdb;
		$s   = self::sends();
		$e   = self::events();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) total,
					SUM(status IN ('queued','sending')) queued,
					SUM(status = 'sent') sent,
					SUM(status = 'failed') failed,
					SUM(status = 'skipped') skipped,
					SUM(status = 'sent' AND open_count > 0) opened,
					SUM(status = 'sent' AND click_count > 0) clicked
				FROM {$s} WHERE announcement_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$id
			),
			ARRAY_A
		);
		$unsub = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT ev.send_id) FROM {$e} ev JOIN {$s} se ON se.id = ev.send_id WHERE se.announcement_id = %d AND ev.type = 'unsubscribe'", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = \array_map( 'intval', (array) $row );
		$out  += [ 'total' => 0, 'queued' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'opened' => 0, 'clicked' => 0 ];
		$out['unsubscribed'] = $unsub;
		$out['open_rate']    = $out['sent'] ? $out['opened'] / $out['sent'] : 0.0;
		$out['click_rate']   = $out['sent'] ? $out['clicked'] / $out['sent'] : 0.0;
		return $out;
	}

	public static function recipients( int $id, string $filter = 'all', string $search = '', int $page = 1, int $per_page = self::PER_PAGE ): array {
		global $wpdb;
		$s     = self::sends();
		$e     = self::events();
		$where = [ $wpdb->prepare( 'announcement_id = %d', $id ) ];
		$map   = [
			'opened'       => "status = 'sent' AND open_count > 0",
			'not_opened'   => "status = 'sent' AND open_count = 0",
			'clicked'      => "status = 'sent' AND click_count > 0",
			'failed'       => "status = 'failed'",
			'skipped'      => "status = 'skipped'",
			'unsubscribed' => "id IN (SELECT send_id FROM {$e} WHERE type = 'unsubscribe')",
		];
		if ( isset( $map[ $filter ] ) ) {
			$where[] = $map[ $filter ];
		}
		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = $wpdb->prepare( '(email LIKE %s OR name LIKE %s)', $like, $like );
		}
		$w     = \implode( ' AND ', $where );
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$s} WHERE {$w}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$s} WHERE {$w} ORDER BY email LIMIT %d OFFSET %d", $per_page, \max( 0, $page - 1 ) * $per_page ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return [ 'rows' => $rows, 'total' => $total ];
	}

	public static function links( int $id ): array {
		global $wpdb;
		$s      = self::sends();
		$e      = self::events();
		$counts = $wpdb->get_results( $wpdb->prepare( "SELECT ev.link_index, COUNT(*) clicks, COUNT(DISTINCT ev.send_id) uniq FROM {$e} ev JOIN {$s} se ON se.id = ev.send_id WHERE se.announcement_id = %d AND ev.type = 'click' AND ev.scanner = 0 GROUP BY ev.link_index", $id ), OBJECT_K ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out    = [];
		foreach ( (array) \get_post_meta( $id, PT::META_LINKS, true ) as $i => $url ) {
			$out[] = [ 'url' => (string) $url, 'clicks' => (int) ( $counts[ $i ]->clicks ?? 0 ), 'unique' => (int) ( $counts[ $i ]->uniq ?? 0 ) ];
		}
		return $out;
	}

	public static function csv_rows( int $id ): array {
		global $wpdb;
		$s     = self::sends();
		$e     = self::events();
		$rows  = [ [ 'name', 'email', 'status', 'skip_reason', 'error', 'sent_at', 'first_opened_at', 'open_count', 'first_clicked_at', 'click_count', 'unsubscribed' ] ];
		$unsub = \array_flip( \array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ev.send_id FROM {$e} ev JOIN {$s} se ON se.id = ev.send_id WHERE se.announcement_id = %d AND ev.type = 'unsubscribe'", $id ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$cell  = static fn( $v ) => \preg_match( '/^[=+\-@\t\r]/', (string) $v ) ? "'" . $v : (string) $v;
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$s} WHERE announcement_id = %d ORDER BY email", $id ) ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows[] = \array_map( $cell, [ $r->name, $r->email, $r->status, $r->skip_reason, (string) $r->error, (string) $r->sent_at, (string) $r->first_opened_at, (string) $r->open_count, (string) $r->first_clicked_at, (string) $r->click_count, isset( $unsub[ (int) $r->id ] ) ? 'yes' : '' ] );
		}
		return $rows;
	}

	public function csv(): void {
		$id = \absint( $_GET['post'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification -- checked next line.
		if ( ! \current_user_can( Module::CAP ) || ! \wp_verify_nonce( \sanitize_key( \wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'aa_csv_' . $id ) ) {
			\wp_die( \esc_html__( 'You are not allowed to do that.', 'anchor-schema' ), 403 );
		}
		\nocache_headers();
		\header( 'Content-Type: text/csv; charset=utf-8' );
		\header( 'Content-Disposition: attachment; filename="announcement-' . $id . '-recipients.csv"' );
		$out = \fopen( 'php://output', 'w' );
		foreach ( self::csv_rows( $id ) as $row ) {
			\fputcsv( $out, $row, ',', '"', '\\' );
		}
		\fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	public function box( \WP_Post $post ): void {
		if ( \in_array( PT::state( (int) $post->ID ), [ PT::STATE_DRAFT, PT::STATE_SCHEDULED ], true ) ) {
			return;
		}
		\add_meta_box( 'aa-report', \__( 'Report', 'anchor-schema' ), [ $this, 'render' ], PT::CPT, 'normal', 'high' );
	}

	public function render( \WP_Post $post ): void {
		// phpcs:disable WordPress.Security.NonceVerification -- read-only filters.
		$id     = (int) $post->ID;
		$s      = self::stats( $id );
		$filter = \sanitize_key( \wp_unslash( $_GET['aa_filter'] ?? 'all' ) );
		$search = \sanitize_text_field( \wp_unslash( $_GET['aa_s'] ?? '' ) );
		$page   = \max( 1, \absint( $_GET['aa_page'] ?? 1 ) );
		// phpcs:enable
		$list   = self::recipients( $id, $filter, $search, $page, self::PER_PAGE );
		$pct    = static fn( float $r ) => \number_format_i18n( $r * 100, 1 ) . '%';
		$last   = (int) \get_option( Queue::LAST_RUN_OPTION );
		$edit   = (string) \get_edit_post_link( $id, 'raw' );
		$with_s = '' !== $search ? \add_query_arg( 'aa_s', \rawurlencode( $search ), $edit ) : $edit; // Filter links keep the search.
		$base   = 'all' !== $filter ? \add_query_arg( 'aa_filter', $filter, $with_s ) : $with_s; // Pagination keeps filter and search.
		?>
		<div class="aa-report">
			<ul class="aa-stats">
				<li><strong><?php echo \esc_html( \number_format_i18n( $s['sent'] ) ); ?></strong> <?php \esc_html_e( 'sent', 'anchor-schema' ); ?></li>
				<li><strong><?php echo \esc_html( \number_format_i18n( $s['queued'] ) ); ?></strong> <?php \esc_html_e( 'waiting', 'anchor-schema' ); ?></li>
				<li><strong><?php echo \esc_html( $pct( $s['open_rate'] ) ); ?></strong> <?php echo \esc_html( \sprintf( \__( 'opened (%s, estimated)', 'anchor-schema' ), \number_format_i18n( $s['opened'] ) ) ); ?></li>
				<li><strong><?php echo \esc_html( $pct( $s['click_rate'] ) ); ?></strong> <?php echo \esc_html( \sprintf( \__( 'clicked (%s)', 'anchor-schema' ), \number_format_i18n( $s['clicked'] ) ) ); ?></li>
				<li><strong><?php echo \esc_html( \number_format_i18n( $s['unsubscribed'] ) ); ?></strong> <?php \esc_html_e( 'unsubscribed', 'anchor-schema' ); ?></li>
				<li><strong><?php echo \esc_html( \number_format_i18n( $s['failed'] ) ); ?></strong> <?php \esc_html_e( 'failed', 'anchor-schema' ); ?></li>
				<li><strong><?php echo \esc_html( \number_format_i18n( $s['skipped'] ) ); ?></strong> <?php \esc_html_e( 'skipped', 'anchor-schema' ); ?></li>
			</ul>
			<p class="description"><?php \esc_html_e( 'Opens are estimated: Apple Mail and some company mail filters load images for the reader, so the true open rate is lower. Clicks are the reliable signal; clicks by link scanners seconds after sending are left out.', 'anchor-schema' ); ?>
				<?php echo \esc_html( $last ? \sprintf( \__( 'Queue last ran %s ago.', 'anchor-schema' ), \human_time_diff( $last ) ) : \__( 'The send queue has not run yet: check that WP-Cron runs on this site.', 'anchor-schema' ) ); ?></p>

			<h3><?php \esc_html_e( 'Recipients', 'anchor-schema' ); ?></h3>
			<p class="aa-filters">
				<?php foreach ( [ 'all' => \__( 'All', 'anchor-schema' ), 'opened' => \__( 'Opened', 'anchor-schema' ), 'not_opened' => \__( 'Not opened', 'anchor-schema' ), 'clicked' => \__( 'Clicked', 'anchor-schema' ), 'unsubscribed' => \__( 'Unsubscribed', 'anchor-schema' ), 'failed' => \__( 'Failed', 'anchor-schema' ), 'skipped' => \__( 'Skipped', 'anchor-schema' ) ] as $key => $label ) : ?>
					<a class="<?php echo $key === $filter ? 'current' : ''; ?>" href="<?php echo \esc_url( \add_query_arg( [ 'aa_filter' => $key, 'aa_page' => 1 ], $with_s ) . '#aa-report' ); ?>"><?php echo \esc_html( $label ); ?></a>
				<?php endforeach; ?>
				<a class="button" href="<?php echo \esc_url( \wp_nonce_url( \admin_url( 'admin-post.php?action=anchor_announcements_csv&post=' . $id ), 'aa_csv_' . $id ) ); ?>"><?php \esc_html_e( 'Export CSV', 'anchor-schema' ); ?></a>
			</p>
			<?php // The report box sits inside the post edit form: no nested <form>; admin.js navigates. ?>
			<p class="aa-report-search">
				<input type="search" id="aa-report-search" value="<?php echo \esc_attr( $search ); ?>" placeholder="<?php \esc_attr_e( 'Search name or email', 'anchor-schema' ); ?>" />
				<button type="button" class="button" id="aa-report-search-go" data-base="<?php echo \esc_url( \add_query_arg( [ 'aa_filter' => $filter, 'aa_page' => 1 ], $edit ) ); ?>"><?php \esc_html_e( 'Search', 'anchor-schema' ); ?></button>
			</p>
			<table class="widefat striped">
				<thead><tr><th><?php \esc_html_e( 'Name', 'anchor-schema' ); ?></th><th><?php \esc_html_e( 'Email', 'anchor-schema' ); ?></th><th><?php \esc_html_e( 'Status', 'anchor-schema' ); ?></th><th><?php \esc_html_e( 'First opened', 'anchor-schema' ); ?></th><th><?php \esc_html_e( 'Opens', 'anchor-schema' ); ?></th><th><?php \esc_html_e( 'First clicked', 'anchor-schema' ); ?></th><th><?php \esc_html_e( 'Clicks', 'anchor-schema' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $list['rows'] as $r ) : ?>
					<tr>
						<td><?php echo \esc_html( $r['name'] ); ?></td>
						<td><?php echo \esc_html( $r['email'] ); ?></td>
						<td><?php echo \esc_html( $r['status'] . ( '' !== $r['skip_reason'] ? ' (' . $r['skip_reason'] . ')' : '' ) ); ?><?php echo '' !== (string) $r['error'] ? '<br /><small>' . \esc_html( $r['error'] ) . '</small>' : ''; ?></td>
						<td><?php echo \esc_html( $r['first_opened_at'] ? \get_date_from_gmt( $r['first_opened_at'], 'M j, g:i a' ) : '' ); ?></td>
						<td><?php echo \esc_html( (string) $r['open_count'] ); ?></td>
						<td><?php echo \esc_html( $r['first_clicked_at'] ? \get_date_from_gmt( $r['first_clicked_at'], 'M j, g:i a' ) : '' ); ?></td>
						<td><?php echo \esc_html( (string) $r['click_count'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $list['total'] > self::PER_PAGE ) : ?>
				<p><?php echo \wp_kses_post( \paginate_links( [ 'base' => $base . ( false === \strpos( $base, '?' ) ? '?' : '&' ) . 'aa_page=%#%#aa-report', 'format' => '', 'current' => $page, 'total' => (int) \ceil( $list['total'] / self::PER_PAGE ) ] ) ); ?></p>
			<?php endif; ?>

			<h3><?php \esc_html_e( 'Links', 'anchor-schema' ); ?></h3>
			<table class="widefat striped">
				<thead><tr><th><?php \esc_html_e( 'Link', 'anchor-schema' ); ?></th><th><?php \esc_html_e( 'Clicks', 'anchor-schema' ); ?></th><th><?php \esc_html_e( 'People', 'anchor-schema' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( self::links( $id ) as $l ) : ?>
					<tr><td><?php echo \esc_html( $l['url'] ); ?></td><td><?php echo \esc_html( (string) $l['clicks'] ); ?></td><td><?php echo \esc_html( (string) $l['unique'] ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
