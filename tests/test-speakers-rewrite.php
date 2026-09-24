<?php
/** @group speakers */
class Test_Speakers_Rewrite extends WP_UnitTestCase {
	public function set_up() {
		parent::set_up();
		update_option( 'anchor_speakers_options', [ 'base' => 'about-us', 'archive' => false ], false );
		$this->set_permalink_structure( '/%postname%/' );
		( new Anchor_Speakers_Module() )->register();
		flush_rewrite_rules();
	}
	public function test_speaker_resolves_under_custom_base() {
		$s = self::factory()->post->create( [ 'post_type' => 'anchor_speaker', 'post_name' => 'dr-jane-smith', 'post_title' => 'Dr. Jane Smith' ] );
		$this->assertSame( home_url( '/about-us/dr-jane-smith/' ), get_permalink( $s ) );
		$this->go_to( '/about-us/dr-jane-smith/' );
		$this->assertTrue( is_singular( 'anchor_speaker' ) );
		$this->assertSame( $s, get_queried_object_id() );
	}
	public function test_parent_page_and_non_speaker_child_page_still_resolve() {
		$about = self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'about-us' ] );
		$child = self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'our-story', 'post_parent' => $about ] );
		$this->go_to( '/about-us/' );
		$this->assertSame( $about, get_queried_object_id() );
		$this->go_to( '/about-us/our-story/' );
		$this->assertTrue( is_page() );
		$this->assertSame( $child, get_queried_object_id() );
	}
	public function test_default_base_is_speakers() {
		delete_option( 'anchor_speakers_options' );
		$this->assertSame( 'speakers', Anchor_Speakers_Module::base() );
	}

	/**
	 * WordPress' own CPT rewrite rules include an auto-generated
	 * "attachment under this post type" rule that matches two path segments
	 * below the base (base/parent-slug/child-slug), exposing only the last
	 * segment as the `attachment` query var. Without the fallback reading
	 * the raw matched path off $wp->request, a nested non-speaker child page
	 * two levels below the base would 404 instead of resolving.
	 */
	public function test_nested_child_page_two_levels_under_base_still_resolves() {
		$about = self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'about-us' ] );
		$story = self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'our-story', 'post_parent' => $about ] );
		$team  = self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'team', 'post_parent' => $story ] );
		$this->go_to( '/about-us/our-story/team/' );
		$this->assertTrue( is_page() );
		$this->assertSame( $team, get_queried_object_id() );
	}

	public function test_nested_nonexistent_path_still_404s() {
		self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'about-us' ] );
		$this->go_to( '/about-us/our-story/nonexistent/' );
		$this->assertTrue( is_404() );
	}
}
