<?php
/**
 * Anchor Store Locator — front-end manager: query building, columns, caps.
 *
 * Covers the read side of [anchor_store_manager]: capability resolution, the
 * column whitelist, argument normalisation, and the WP_Query the list is built
 * from — including the meta-search extension that lets the manager find a store
 * by owner, address or phone rather than title alone.
 *
 * @package Anchor\StoreLocator\Tests
 */

class Test_Store_Manager extends WP_UnitTestCase {

	/** @var \Anchor\StoreLocator\Module */
	private $module;

	/**
	 * Build the module fresh for every test.
	 *
	 * WP_UnitTestCase snapshots $wp_filter once for the whole run and restores
	 * that snapshot after each test, so hooks registered in wpSetUpBeforeClass
	 * (the manager's posts_search filter among them) are stripped after the
	 * class's first test. Constructing in set_up — after parent::set_up() has
	 * taken/restored the snapshot — gives each test exactly one live instance.
	 */
	public function set_up() {
		parent::set_up();

		require_once dirname( __DIR__ ) . '/anchor-store-locator/anchor-store-locator.php';

		$this->module = new \Anchor\StoreLocator\Module();
		// init has already fired by the time the module is constructed here.
		$this->module->register_cpt();
	}

	private function manager() {
		return $this->module->manager();
	}

	/**
	 * Create a store with meta in one call.
	 */
	private function make_store( array $args = [], array $meta = [] ) {
		$post_id = self::factory()->post->create(
			array_merge(
				[
					'post_type'   => \Anchor\StoreLocator\Module::CPT,
					'post_status' => 'publish',
					'post_title'  => 'Test Store',
				],
				$args
			)
		);

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, '_anchor_store_' . $key, $value );
		}

		return $post_id;
	}

	/* ─── Capability ─── */

	public function test_capability_defaults_to_edit_posts() {
		$this->assertSame( 'edit_posts', $this->manager()->capability() );
	}

	public function test_capability_is_filterable() {
		$filter = function () {
			return 'manage_options';
		};
		add_filter( 'anchor_store_manager_capability', $filter );

		$this->assertSame( 'manage_options', $this->manager()->capability() );

		remove_filter( 'anchor_store_manager_capability', $filter );
	}

	public function test_subscriber_cannot_manage_but_editor_can() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertFalse( $this->manager()->can_manage() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertTrue( $this->manager()->can_manage() );
	}

	public function test_shortcode_refuses_users_without_the_capability() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$output = $this->manager()->render_shortcode( [] );

		$this->assertStringContainsString( 'asm-denied', $output );
		$this->assertStringNotContainsString( '<table', $output );
	}

	/* ─── Statuses ─── */

	public function test_pending_is_an_editable_status() {
		// Regression: the list showed pending stores but the edit form offered
		// only publish/draft, so editing a pending store silently demoted it.
		$this->assertArrayHasKey( 'pending', $this->manager()->editable_statuses() );
	}

	public function test_filter_statuses_include_any_and_trash() {
		$statuses = $this->manager()->filter_statuses();

		$this->assertArrayHasKey( 'any', $statuses );
		$this->assertArrayHasKey( 'trash', $statuses );
		$this->assertArrayHasKey( 'pending', $statuses );
	}

	/* ─── Columns ─── */

	public function test_resolve_columns_accepts_a_valid_list() {
		$this->assertSame(
			[ 'image', 'name', 'owner' ],
			$this->manager()->resolve_columns( 'image,name,owner' )
		);
	}

	public function test_resolve_columns_drops_unknown_and_duplicate_keys() {
		$this->assertSame(
			[ 'name', 'owner' ],
			$this->manager()->resolve_columns( 'name,bogus,owner,name' )
		);
	}

	public function test_resolve_columns_always_includes_name() {
		$this->assertContains( 'name', $this->manager()->resolve_columns( 'address,phone' ) );
	}

	public function test_resolve_columns_falls_back_to_defaults_when_empty() {
		$columns = $this->manager()->resolve_columns( '' );

		$this->assertContains( 'name', $columns );
		$this->assertContains( 'owner', $columns );
	}

	public function test_owner_is_an_available_column() {
		$this->assertArrayHasKey( 'owner', $this->manager()->available_columns() );
	}

	/* ─── Argument normalisation ─── */

	public function test_normalize_args_rejects_unknown_status_and_orderby() {
		$args = $this->manager()->normalize_args(
			[
				'status'  => 'bogus',
				'orderby' => 'post_password',
				'order'   => 'sideways',
			]
		);

		$this->assertSame( 'any', $args['status'] );
		$this->assertSame( 'title', $args['orderby'] );
		$this->assertSame( 'ASC', $args['order'] );
	}

	public function test_normalize_args_accepts_valid_input() {
		$args = $this->manager()->normalize_args(
			[
				'status'  => 'draft',
				'orderby' => 'owner',
				'order'   => 'desc',
				'paged'   => '3',
				's'       => '  Dana  ',
			]
		);

		$this->assertSame( 'draft', $args['status'] );
		$this->assertSame( 'owner', $args['orderby'] );
		$this->assertSame( 'DESC', $args['order'] );
		$this->assertSame( 3, $args['paged'] );
		$this->assertSame( 'Dana', $args['search'] );
	}

	/* ─── Per page ─── */

	public function test_default_per_page_is_ten() {
		$args = $this->manager()->normalize_args( [] );

		$this->assertSame( 10, $args['per_page'] );
		$this->assertSame( 10, \Anchor\StoreLocator\Store_Manager::DEFAULT_PER_PAGE );
	}

	public function test_per_page_choices_are_offered_in_order() {
		$this->assertSame( [ 10, 20, 50, 100 ], $this->manager()->per_page_choices( 10 ) );
	}

	public function test_per_page_choices_include_a_custom_shortcode_value() {
		// [anchor_store_manager per_page="25"] must still be selectable, or the
		// control would silently resize the page the moment it renders.
		$this->assertSame( [ 10, 20, 25, 50, 100 ], $this->manager()->per_page_choices( 25 ) );
	}

	public function test_bulk_bar_renders_the_per_page_control_with_the_current_value() {
		$args = $this->manager()->normalize_args( [ 'per_page' => 50 ] );
		$html = $this->manager()->render_bulk_bar( 'asm-1', $args );

		$this->assertStringContainsString( 'data-asm-per-page', $html );
		$this->assertMatchesRegularExpression( '/value="50"\s+selected/', $html );
		$this->assertMatchesRegularExpression( '/value="10"\s*>/', $html );
	}

	public function test_normalize_args_clamps_per_page() {
		$high = $this->manager()->normalize_args( [ 'per_page' => 9999 ] );
		$this->assertSame( \Anchor\StoreLocator\Store_Manager::MAX_PER_PAGE, $high['per_page'] );

		$low = $this->manager()->normalize_args( [ 'per_page' => 0 ] );
		$this->assertSame( \Anchor\StoreLocator\Store_Manager::DEFAULT_PER_PAGE, $low['per_page'] );

		$negative = $this->manager()->normalize_args( [ 'paged' => -5 ] );
		$this->assertSame( 1, $negative['paged'] );
	}

	/* ─── Query: paging, status, sorting ─── */

	public function test_pagination_limits_the_result_set() {
		for ( $i = 0; $i < 7; $i++ ) {
			$this->make_store( [ 'post_title' => 'Store ' . $i ] );
		}

		$args  = $this->manager()->normalize_args( [ 'per_page' => 3, 'paged' => 1 ] );
		$query = $this->manager()->build_query( $args );

		$this->assertCount( 3, $query->posts );
		$this->assertSame( 7, (int) $query->found_posts );
		$this->assertSame( 3, (int) $query->max_num_pages );
	}

	public function test_second_page_returns_different_posts() {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->make_store( [ 'post_title' => 'Store ' . $i ] );
		}

		$page1 = $this->manager()->build_query( $this->manager()->normalize_args( [ 'per_page' => 2, 'paged' => 1 ] ) );
		$page2 = $this->manager()->build_query( $this->manager()->normalize_args( [ 'per_page' => 2, 'paged' => 2 ] ) );

		$ids1 = wp_list_pluck( $page1->posts, 'ID' );
		$ids2 = wp_list_pluck( $page2->posts, 'ID' );

		$this->assertCount( 2, $ids2 );
		$this->assertEmpty( array_intersect( $ids1, $ids2 ) );
	}

	public function test_status_filter_narrows_results() {
		$this->make_store( [ 'post_title' => 'Published One', 'post_status' => 'publish' ] );
		$this->make_store( [ 'post_title' => 'Drafted One', 'post_status' => 'draft' ] );

		$query = $this->manager()->build_query( $this->manager()->normalize_args( [ 'status' => 'draft' ] ) );

		$this->assertCount( 1, $query->posts );
		$this->assertSame( 'Drafted One', $query->posts[0]->post_title );
	}

	public function test_any_status_excludes_trashed_stores() {
		$this->make_store( [ 'post_title' => 'Live Store' ] );
		$trashed = $this->make_store( [ 'post_title' => 'Gone Store' ] );
		wp_trash_post( $trashed );

		$query = $this->manager()->build_query( $this->manager()->normalize_args( [ 'status' => 'any' ] ) );
		$ids   = wp_list_pluck( $query->posts, 'ID' );

		$this->assertNotContains( $trashed, $ids );
	}

	public function test_trash_status_returns_only_trashed_stores() {
		$this->make_store( [ 'post_title' => 'Live Store' ] );
		$trashed = $this->make_store( [ 'post_title' => 'Gone Store' ] );
		wp_trash_post( $trashed );

		$query = $this->manager()->build_query( $this->manager()->normalize_args( [ 'status' => 'trash' ] ) );
		$ids   = wp_list_pluck( $query->posts, 'ID' );

		$this->assertSame( [ $trashed ], $ids );
	}

	public function test_sorting_by_title_respects_direction() {
		$this->make_store( [ 'post_title' => 'Alpha' ] );
		$this->make_store( [ 'post_title' => 'Zulu' ] );

		$asc = $this->manager()->build_query( $this->manager()->normalize_args( [ 'orderby' => 'title', 'order' => 'ASC' ] ) );
		$this->assertSame( 'Alpha', $asc->posts[0]->post_title );

		$desc = $this->manager()->build_query( $this->manager()->normalize_args( [ 'orderby' => 'title', 'order' => 'DESC' ] ) );
		$this->assertSame( 'Zulu', $desc->posts[0]->post_title );
	}

	public function test_sorting_by_owner_uses_the_owner_meta() {
		$this->make_store( [ 'post_title' => 'Store B' ], [ 'owner' => 'Adams' ] );
		$this->make_store( [ 'post_title' => 'Store A' ], [ 'owner' => 'Zimmerman' ] );

		$query = $this->manager()->build_query( $this->manager()->normalize_args( [ 'orderby' => 'owner', 'order' => 'ASC' ] ) );

		$this->assertSame( 'Store B', $query->posts[0]->post_title );
	}

	/* ─── Query: search ─── */

	public function test_search_matches_the_title() {
		$this->make_store( [ 'post_title' => 'Riverside Clinic' ] );
		$this->make_store( [ 'post_title' => 'Hilltop Clinic' ] );

		$query = $this->manager()->build_query( $this->manager()->normalize_args( [ 's' => 'Riverside' ] ) );

		$this->assertCount( 1, $query->posts );
		$this->assertSame( 'Riverside Clinic', $query->posts[0]->post_title );
	}

	public function test_search_matches_the_owner_meta() {
		$match = $this->make_store( [ 'post_title' => 'Store One' ], [ 'owner' => 'Dana Reyes' ] );
		$this->make_store( [ 'post_title' => 'Store Two' ], [ 'owner' => 'Sam Fields' ] );

		$query = $this->manager()->build_query( $this->manager()->normalize_args( [ 's' => 'Dana' ] ) );
		$ids   = wp_list_pluck( $query->posts, 'ID' );

		$this->assertSame( [ $match ], $ids );
	}

	public function test_search_matches_the_address_meta() {
		$match = $this->make_store( [ 'post_title' => 'Store One' ], [ 'address' => '400 Mulberry Lane, Austin' ] );
		$this->make_store( [ 'post_title' => 'Store Two' ], [ 'address' => '12 Oak Street, Dallas' ] );

		$query = $this->manager()->build_query( $this->manager()->normalize_args( [ 's' => 'Mulberry' ] ) );
		$ids   = wp_list_pluck( $query->posts, 'ID' );

		$this->assertSame( [ $match ], $ids );
	}

	public function test_search_matches_the_phone_meta() {
		$match = $this->make_store( [ 'post_title' => 'Store One' ], [ 'phone' => '(512) 555-0134' ] );
		$this->make_store( [ 'post_title' => 'Store Two' ], [ 'phone' => '(214) 555-9987' ] );

		$query = $this->manager()->build_query( $this->manager()->normalize_args( [ 's' => '0134' ] ) );
		$ids   = wp_list_pluck( $query->posts, 'ID' );

		$this->assertSame( [ $match ], $ids );
	}

	public function test_search_returns_nothing_when_no_field_matches() {
		$this->make_store( [ 'post_title' => 'Store One' ], [ 'owner' => 'Dana Reyes' ] );

		$query = $this->manager()->build_query( $this->manager()->normalize_args( [ 's' => 'Nonexistent' ] ) );

		$this->assertCount( 0, $query->posts );
	}

	public function test_meta_search_does_not_leak_into_other_queries() {
		// The posts_search filter is global; it must only ever fire for the
		// manager's own query, or every search box on the site changes meaning.
		$this->make_store( [ 'post_title' => 'Store One' ], [ 'owner' => 'Dana Reyes' ] );

		$plain = new WP_Query(
			[
				'post_type'   => \Anchor\StoreLocator\Module::CPT,
				'post_status' => 'publish',
				's'           => 'Dana',
			]
		);

		$this->assertCount( 0, $plain->posts );
	}

	/* ─── Counts ─── */

	public function test_status_counts_are_reported_per_tab() {
		$this->make_store( [ 'post_title' => 'Pub A', 'post_status' => 'publish' ] );
		$this->make_store( [ 'post_title' => 'Pub B', 'post_status' => 'publish' ] );
		$this->make_store( [ 'post_title' => 'Draft A', 'post_status' => 'draft' ] );
		$trashed = $this->make_store( [ 'post_title' => 'Trash A' ] );
		wp_trash_post( $trashed );

		$counts = $this->manager()->status_counts( $this->manager()->normalize_args( [] ) );

		$this->assertSame( 2, $counts['publish'] );
		$this->assertSame( 1, $counts['draft'] );
		$this->assertSame( 1, $counts['trash'] );
		$this->assertSame( 3, $counts['any'] );
	}

	public function test_status_counts_respect_the_active_search() {
		$this->make_store( [ 'post_title' => 'Riverside Clinic', 'post_status' => 'publish' ] );
		$this->make_store( [ 'post_title' => 'Hilltop Clinic', 'post_status' => 'publish' ] );

		$counts = $this->manager()->status_counts( $this->manager()->normalize_args( [ 's' => 'Riverside' ] ) );

		$this->assertSame( 1, $counts['publish'] );
	}

	/* ─── Row rendering ─── */

	public function test_rows_render_the_owner_value() {
		$this->make_store( [ 'post_title' => 'Store One' ], [ 'owner' => 'Dana Reyes' ] );

		$args = $this->manager()->normalize_args( [] );
		$html = $this->manager()->render_rows(
			$this->manager()->build_query( $args ),
			[ 'name', 'owner' ],
			$args
		);

		$this->assertStringContainsString( 'Dana Reyes', $html );
	}

	public function test_rows_escape_store_titles() {
		// kses already strips <script> from post_title on insert, so use a tag
		// that survives storage — otherwise the assertion passes without the
		// renderer having escaped anything.
		$this->make_store( [ 'post_title' => 'Bold <b>Store</b> & Co' ] );

		$args = $this->manager()->normalize_args( [] );
		$html = $this->manager()->render_rows(
			$this->manager()->build_query( $args ),
			[ 'name' ],
			$args
		);

		$this->assertStringContainsString( '&lt;b&gt;', $html );
		$this->assertStringNotContainsString( '<b>Store</b>', $html );
	}

	public function test_rows_escape_owner_values() {
		$this->make_store(
			[ 'post_title' => 'Store One' ],
			[ 'owner' => 'Dana <img src=x onerror=alert(1)> Reyes' ]
		);

		$args = $this->manager()->normalize_args( [] );
		$html = $this->manager()->render_rows(
			$this->manager()->build_query( $args ),
			[ 'name', 'owner' ],
			$args
		);

		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringContainsString( '&lt;img', $html );
	}

	public function test_empty_state_differs_between_no_stores_and_no_matches() {
		$args  = $this->manager()->normalize_args( [] );
		$empty = $this->manager()->render_rows( $this->manager()->build_query( $args ), [ 'name' ], $args );
		$this->assertStringContainsString( 'No stores yet', $empty );

		$this->make_store( [ 'post_title' => 'Riverside Clinic' ] );

		$search_args = $this->manager()->normalize_args( [ 's' => 'Nonexistent' ] );
		$no_match    = $this->manager()->render_rows(
			$this->manager()->build_query( $search_args ),
			[ 'name' ],
			$search_args
		);
		$this->assertStringContainsString( 'No stores match', $no_match );
		$this->assertStringContainsString( 'clear-search', $no_match );
	}

	public function test_trash_rows_offer_restore_instead_of_edit() {
		$trashed = $this->make_store( [ 'post_title' => 'Gone Store' ] );
		wp_trash_post( $trashed );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$args = $this->manager()->normalize_args( [ 'status' => 'trash' ] );
		$html = $this->manager()->render_rows(
			$this->manager()->build_query( $args ),
			[ 'name' ],
			$args
		);

		$this->assertStringContainsString( 'data-asm-action="restore"', $html );
		$this->assertStringContainsString( 'data-asm-action="delete-permanently"', $html );
		$this->assertStringNotContainsString( 'data-asm-action="edit"', $html );
	}

	public function test_rows_hide_actions_the_user_cannot_perform() {
		$this->make_store( [ 'post_title' => 'Store One', 'post_status' => 'publish' ] );

		// A contributor cannot edit or delete a published post.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'contributor' ] ) );

		$args = $this->manager()->normalize_args( [] );
		$html = $this->manager()->render_rows(
			$this->manager()->build_query( $args ),
			[ 'name' ],
			$args
		);

		$this->assertStringNotContainsString( 'data-asm-action="edit"', $html );
		$this->assertStringNotContainsString( 'data-asm-action="delete"', $html );
	}

	/* ─── Header ─── */

	public function test_header_marks_the_active_sort_column() {
		$args = $this->manager()->normalize_args( [ 'orderby' => 'title', 'order' => 'DESC' ] );
		$html = $this->manager()->render_head( [ 'name', 'owner' ], $args );

		$this->assertStringContainsString( 'aria-sort="descending"', $html );
		$this->assertStringContainsString( 'data-asm-sort="owner"', $html );
	}
}
