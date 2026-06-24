<?php
/**
 * Shared non-public "Group" taxonomy for Anchor Tools CPTs.
 *
 * Registers a hierarchical, category-style taxonomy with no public archive
 * pages or sitemap exposure, plus a list-table filter dropdown and a bulk
 * "Add to group" action. Quick Edit + admin column come from core via the
 * registration flags.
 *
 * @package AnchorTools
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Anchor_Groups {

	/** @var array<string,string> taxonomy => cpt, for hooked callbacks. */
	private static $map = array();

	/** @var bool Whether the shared admin hooks have been attached. */
	private static $hooked = false;

	/**
	 * Register a Group taxonomy for a CPT and wire its admin UX.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @param string $cpt      Post type slug.
	 * @param array  $labels   Optional label overrides.
	 */
	public static function register( $taxonomy, $cpt, $labels = array() ) {
		$labels = wp_parse_args( $labels, array(
			'name'          => __( 'Groups', 'anchor-schema' ),
			'singular_name' => __( 'Group', 'anchor-schema' ),
			'menu_name'     => __( 'Groups', 'anchor-schema' ),
			'all_items'     => __( 'All Groups', 'anchor-schema' ),
			'edit_item'     => __( 'Edit Group', 'anchor-schema' ),
			'add_new_item'  => __( 'Add New Group', 'anchor-schema' ),
			'new_item_name' => __( 'New Group Name', 'anchor-schema' ),
			'search_items'  => __( 'Search Groups', 'anchor-schema' ),
		) );

		register_taxonomy( $taxonomy, $cpt, array(
			'labels'             => $labels,
			'hierarchical'       => true,
			'public'             => false,
			'publicly_queryable' => false,
			'rewrite'            => false,
			'query_var'          => false,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'show_admin_column'  => true,
			'show_in_quick_edit' => true,
			'show_in_rest'       => false,
		) );

		if ( isset( self::$map[ $taxonomy ] ) ) { return; }
		self::$map[ $taxonomy ] = $cpt;

		add_filter( "bulk_actions-edit-{$cpt}",        array( __CLASS__, 'bulk_actions' ) );
		add_filter( "handle_bulk_actions-edit-{$cpt}", array( __CLASS__, 'handle_bulk' ), 10, 3 );

		if ( ! self::$hooked ) {
			self::$hooked = true;
			add_action( 'restrict_manage_posts', array( __CLASS__, 'render_filter' ) );
			add_filter( 'parse_query',           array( __CLASS__, 'apply_filter' ) );
		}
	}

	/** Group <select> at the top of the list table. */
	public static function render_filter( $post_type ) {
		$taxonomy = array_search( $post_type, self::$map, true );
		if ( ! $taxonomy ) { return; }
		$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
		if ( is_wp_error( $terms ) || empty( $terms ) ) { return; }
		$current = isset( $_GET[ $taxonomy ] ) ? sanitize_text_field( wp_unslash( $_GET[ $taxonomy ] ) ) : '';
		echo '<select name="' . esc_attr( $taxonomy ) . '">';
		echo '<option value="">' . esc_html__( 'All Groups', 'anchor-schema' ) . '</option>';
		foreach ( $terms as $t ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $t->slug ),
				selected( $current, $t->slug, false ),
				esc_html( $t->name )
			);
		}
		echo '</select>';
	}

	/** Translate the selected term slug into a taxonomy query on the list screen. */
	public static function apply_filter( $query ) {
		global $pagenow;
		if ( 'edit.php' !== $pagenow || ! is_admin() || ! $query->is_main_query() ) { return; }
		$post_type = isset( $query->query_vars['post_type'] ) ? $query->query_vars['post_type'] : '';
		$taxonomy  = array_search( $post_type, self::$map, true );
		if ( ! $taxonomy ) { return; }
		if ( ! empty( $_GET[ $taxonomy ] ) ) {
			$query->query_vars['tax_query'] = array( array(
				'taxonomy' => $taxonomy,
				'field'    => 'slug',
				'terms'    => sanitize_text_field( wp_unslash( $_GET[ $taxonomy ] ) ),
			) );
		}
	}

	/** Add one "Add to group: <name>" bulk action per existing term. */
	public static function bulk_actions( $actions ) {
		$screen = get_current_screen();
		if ( ! $screen ) { return $actions; }
		$taxonomy = array_search( $screen->post_type, self::$map, true );
		if ( ! $taxonomy ) { return $actions; }
		$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
		if ( is_wp_error( $terms ) ) { return $actions; }
		foreach ( $terms as $t ) {
			$actions[ 'anchor_group_add_' . $t->term_id ] = sprintf(
				/* translators: %s group name */
				__( 'Add to group: %s', 'anchor-schema' ),
				$t->name
			);
		}
		return $actions;
	}

	/** Assign the selected posts to the chosen group term. */
	public static function handle_bulk( $redirect, $action, $post_ids ) {
		if ( 0 !== strpos( $action, 'anchor_group_add_' ) ) { return $redirect; }
		$term_id = (int) substr( $action, strlen( 'anchor_group_add_' ) );
		$term    = get_term( $term_id );
		if ( ! $term || is_wp_error( $term ) ) { return $redirect; }
		$taxonomy = $term->taxonomy;
		if ( ! in_array( $taxonomy, array_keys( self::$map ), true ) ) { return $redirect; }
		foreach ( (array) $post_ids as $pid ) {
			if ( current_user_can( 'edit_post', $pid ) ) {
				wp_set_object_terms( (int) $pid, $term_id, $taxonomy, true );
			}
		}
		return add_query_arg( 'anchor_grouped', count( (array) $post_ids ), $redirect );
	}
}
