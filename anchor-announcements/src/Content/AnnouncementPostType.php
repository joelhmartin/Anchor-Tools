<?php
declare(strict_types=1);

namespace Anchor\Announcements\Content;

use Anchor\Announcements\Module;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** The announcement post: the draft while composing, the record once sent. */
final class AnnouncementPostType {

	public const CPT = 'anchor_announcement';

	public const META_SUBJECT   = '_aa_subject';
	public const META_PREHEADER = '_aa_preheader';
	public const META_BODY      = '_aa_body';
	public const META_AUDIENCE  = '_aa_audience';
	public const META_STATE     = '_aa_state';
	public const META_SCHEDULED = '_aa_scheduled_at';
	public const META_LINKS     = '_aa_links';
	public const META_SENT_AT   = '_aa_sent_at';

	public const STATE_DRAFT     = 'draft';
	public const STATE_SCHEDULED = 'scheduled';
	public const STATE_SENDING   = 'sending';
	public const STATE_PAUSED    = 'paused';
	public const STATE_SENT      = 'sent';
	public const STATE_CANCELLED = 'cancelled';

	public static function register(): void {
		$cap = Module::CAP;
		\register_post_type(
			self::CPT,
			[
				'labels'          => [
					'name'          => \__( 'Announcements', 'anchor-schema' ),
					'singular_name' => \__( 'Announcement', 'anchor-schema' ),
					'add_new_item'  => \__( 'New announcement', 'anchor-schema' ),
					'edit_item'     => \__( 'Edit announcement', 'anchor-schema' ),
					'menu_name'     => \__( 'Announcements', 'anchor-schema' ),
				],
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'menu_icon'       => 'dashicons-megaphone',
				'menu_position'   => 26,
				'supports'        => [ 'title' ],
				'map_meta_cap'    => false,
				'capabilities'    => [
					'edit_post'          => $cap,
					'read_post'          => $cap,
					'delete_post'        => $cap,
					'edit_posts'         => $cap,
					'edit_others_posts'  => $cap,
					'publish_posts'      => $cap,
					'read_private_posts' => $cap,
					'delete_posts'       => $cap,
					'create_posts'       => $cap,
				],
			]
		);
	}

	public static function state( int $id ): string {
		$state = (string) \get_post_meta( $id, self::META_STATE, true );
		return '' !== $state ? $state : self::STATE_DRAFT;
	}
}
