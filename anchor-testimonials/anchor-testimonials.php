<?php
/**
 * Anchor Tools module: Anchor Testimonials.
 * Authored testimonials (quote and/or video) with an audience taxonomy and a
 * "related to" list of post IDs used to scope them to courses, pages or speakers.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Testimonials_Module {
	const CPT         = 'anchor_testimonial';
	const TAX         = 'anchor_testimonial_audience';
	const META_PREFIX = '_at_';

	public function __construct() {
		add_action( 'init', [ $this, 'register' ] );
		add_action( 'init', [ $this, 'seed_terms' ], 20 );
	}

	public function register() {
		register_post_type( self::CPT, [
			'labels'             => [
				'name'          => __( 'Testimonials', 'anchor-schema' ),
				'singular_name' => __( 'Testimonial', 'anchor-schema' ),
				'add_new_item'  => __( 'Add New Testimonial', 'anchor-schema' ),
				'edit_item'     => __( 'Edit Testimonial', 'anchor-schema' ),
			],
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => apply_filters( 'anchor_testimonials_parent_menu', true ),
			'menu_icon'          => 'dashicons-format-quote',
			'supports'           => [ 'title', 'editor', 'thumbnail', 'page-attributes' ],
			'show_in_rest'       => false,
		] );
		register_taxonomy( self::TAX, self::CPT, [
			'labels'            => [ 'name' => __( 'Audiences', 'anchor-schema' ), 'singular_name' => __( 'Audience', 'anchor-schema' ) ],
			'public'            => false,
			'show_ui'           => true,
			'show_admin_column' => true,
			'hierarchical'      => false,
			'rewrite'           => false,
		] );
	}

	public function seed_terms() {
		if ( get_option( 'anchor_testimonials_seeded' ) ) return;
		foreach ( [ 'patient' => __( 'Patient', 'anchor-schema' ), 'doctor' => __( 'Doctor', 'anchor-schema' ) ] as $slug => $name ) {
			if ( ! term_exists( $slug, self::TAX ) ) wp_insert_term( $name, self::TAX, [ 'slug' => $slug ] );
		}
		update_option( 'anchor_testimonials_seeded', 1, false );
	}
}
