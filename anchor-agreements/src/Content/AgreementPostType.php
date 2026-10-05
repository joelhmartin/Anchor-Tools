<?php
declare(strict_types=1);

namespace Anchor\Agreements\Content;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class AgreementPostType {

	public const CPT = 'anchor_agreement';

	public static function register(): void {
		\register_post_type( self::CPT, [
			'labels'          => [
				'name'          => \__( 'Agreements', 'anchor-schema' ),
				'singular_name' => \__( 'Agreement', 'anchor-schema' ),
				'add_new_item'  => \__( 'Add New Agreement', 'anchor-schema' ),
				'edit_item'     => \__( 'Edit Agreement', 'anchor-schema' ),
			],
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => true,
			'menu_icon'       => 'dashicons-edit-page',
			'supports'        => [ 'title', 'editor', 'revisions' ],
			'capability_type' => 'post',
			'capabilities'    => [
				'edit_post' => 'manage_woocommerce', 'read_post' => 'manage_woocommerce', 'delete_post' => 'manage_woocommerce',
				'edit_posts' => 'manage_woocommerce', 'edit_others_posts' => 'manage_woocommerce', 'publish_posts' => 'manage_woocommerce',
				'read_private_posts' => 'manage_woocommerce', 'delete_posts' => 'manage_woocommerce', 'create_posts' => 'manage_woocommerce',
			],
			'map_meta_cap'    => false,
			'show_in_rest'    => false,
		] );
	}

	public static function is_usable( int $id ): bool {
		$post = $id ? \get_post( $id ) : null;
		return $post && self::CPT === $post->post_type && 'publish' === $post->post_status;
	}
}
