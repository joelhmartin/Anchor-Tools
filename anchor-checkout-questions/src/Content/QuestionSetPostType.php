<?php
declare(strict_types=1);

namespace Anchor\CheckoutQuestions\Content;

use Anchor\CheckoutQuestions\Support\QuestionSets;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class QuestionSetPostType {

	public static function register(): void {
		\register_post_type( QuestionSets::CPT, [
			'labels'          => [
				'name'          => \__( 'Checkout Questions', 'anchor-schema' ),
				'singular_name' => \__( 'Question set', 'anchor-schema' ),
				'add_new'       => \__( 'Add question set', 'anchor-schema' ),
				'add_new_item'  => \__( 'Add New Question Set', 'anchor-schema' ),
				'edit_item'     => \__( 'Edit Question Set', 'anchor-schema' ),
				'menu_name'     => \__( 'Checkout Questions', 'anchor-schema' ),
			],
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => \apply_filters( 'anchor_checkout_questions_parent_menu', 'woocommerce' ),
			'supports'        => [ 'title', 'page-attributes' ],
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
}
