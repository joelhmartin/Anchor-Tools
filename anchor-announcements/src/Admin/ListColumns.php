<?php
declare(strict_types=1);

namespace Anchor\Announcements\Admin;

use Anchor\Announcements\Content\AnnouncementPostType as PT;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class ListColumns {

	public function __construct() {
		\add_filter( 'manage_' . PT::CPT . '_posts_columns', [ $this, 'columns' ] );
		\add_action( 'manage_' . PT::CPT . '_posts_custom_column', [ $this, 'cell' ], 10, 2 );
	}

	public function columns( array $cols ): array {
		unset( $cols['date'] );
		return $cols + [ 'aa_state' => \__( 'Status', 'anchor-schema' ), 'aa_audience' => \__( 'Audience', 'anchor-schema' ), 'aa_sent' => \__( 'Sent', 'anchor-schema' ), 'aa_open' => \__( 'Opened', 'anchor-schema' ), 'aa_click' => \__( 'Clicked', 'anchor-schema' ), 'aa_when' => \__( 'Date', 'anchor-schema' ) ];
	}

	public function cell( string $col, int $id ): void {
		$state = PT::state( $id );
		$s     = \in_array( $col, [ 'aa_audience', 'aa_sent', 'aa_open', 'aa_click' ], true ) ? Reports::stats( $id ) : [];
		switch ( $col ) {
			case 'aa_state':
				echo \esc_html( \ucfirst( $state ) );
				break;
			case 'aa_audience':
				echo \esc_html( $s['total'] ? \number_format_i18n( $s['total'] - $s['skipped'] ) : '' );
				break;
			case 'aa_sent':
				echo \esc_html( \number_format_i18n( $s['sent'] ) );
				break;
			case 'aa_open':
				echo \esc_html( $s['sent'] ? \number_format_i18n( $s['open_rate'] * 100, 1 ) . '%' : '' );
				break;
			case 'aa_click':
				echo \esc_html( $s['sent'] ? \number_format_i18n( $s['click_rate'] * 100, 1 ) . '%' : '' );
				break;
			case 'aa_when':
				$ts = PT::STATE_SCHEDULED === $state ? (int) \get_post_meta( $id, PT::META_SCHEDULED, true ) : (int) \get_post_meta( $id, PT::META_SENT_AT, true );
				echo \esc_html( $ts ? \wp_date( \get_option( 'date_format' ) . ' ' . \get_option( 'time_format' ), $ts ) : \get_the_modified_date( '', $id ) );
				break;
		}
	}
}
