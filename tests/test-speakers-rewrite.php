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

	/**
	 * A speaker and a child page of the base can legitimately share a slug
	 * (e.g. a "dr-x" page left over from before the speaker was migrated in).
	 * The speaker must win: page_fallback()'s single-segment branch only
	 * hands the request back to the page when no speaker exists at that
	 * path, so with both existing the request resolves to the speaker.
	 */
	public function test_speaker_wins_when_slug_collides_with_sibling_page() {
		$about   = self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'about-us' ] );
		$speaker = self::factory()->post->create( [ 'post_type' => 'anchor_speaker', 'post_name' => 'dr-x', 'post_title' => 'Dr. X' ] );
		self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'dr-x', 'post_parent' => $about ] );

		$this->go_to( '/about-us/dr-x/' );
		$this->assertTrue( is_singular( 'anchor_speaker' ) );
		$this->assertSame( $speaker, get_queried_object_id() );
	}

	/**
	 * Final whole-branch review finding (found importing real data):
	 * get_page_by_path()'s $post_type parameter, passed as a plain string,
	 * makes WP core also match 'attachment' posts of the same slug (it
	 * queries post_type IN ($post_type, 'attachment') internally, see
	 * get_page_by_path() in wp-includes/post.php). Both get_page_by_path()
	 * calls in page_fallback() must pass an array so an unrelated media
	 * attachment sharing a path segment's slug is never mistaken for "a
	 * real speaker/page exists here", which would otherwise 404 a real
	 * child page instead of letting it resolve.
	 *
	 * wp_insert_post() itself would normally de-duplicate the attachment's
	 * slug away from an existing page's ('our-story' -> 'our-story-2'), so
	 * the collision is forced directly at the DB row the way an import that
	 * bypasses that de-dup (a raw SQL import, an XML-RPC/REST media import
	 * with its own slug handling, a migration) can leave behind, which is
	 * exactly the scenario this finding was found from.
	 */
	public function test_attachment_with_same_slug_does_not_shadow_a_real_child_page() {
		global $wpdb;

		$about = self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'about-us' ] );
		$child = self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'our-story', 'post_parent' => $about ] );
		// Unrelated media attachment forced to the same slug as the child
		// page's last path segment (see docblock for why this is forced
		// directly at the DB row instead of via the post factory).
		$attachment = self::factory()->post->create( [ 'post_type' => 'attachment', 'post_name' => 'our-story-tmp', 'post_status' => 'inherit' ] );
		$wpdb->update( $wpdb->posts, [ 'post_name' => 'our-story' ], [ 'ID' => $attachment ] );
		clean_post_cache( $attachment );

		$this->go_to( '/about-us/our-story/' );
		$this->assertTrue( is_page() );
		$this->assertSame( $child, get_queried_object_id() );
	}

	/**
	 * maybe_flush() flushes once (no stored signature yet, i.e. first load),
	 * is a no-op when nothing about the rules has changed, and flushes again
	 * when the base changes outside the settings page (a direct
	 * update_option() call, as this test and any programmatic change does).
	 *
	 * The signature option alone can't distinguish a true no-op from a
	 * redundant re-flush that happens to produce the same signature and the
	 * same rewrite_rules content, so this counts actual flushes via the
	 * 'generate_rewrite_rules' action, which WP_Rewrite::rewrite_rules()
	 * fires only when rules are actually (re)built, not on every call.
	 */
	public function test_maybe_flush_signature_covers_first_load_and_base_change_outside_settings() {
		delete_option( Anchor_Speakers_Module::SIGNATURE_OPTION );
		$module = new Anchor_Speakers_Module();

		$flushes = 0;
		$counter = function () use ( &$flushes ) { $flushes++; };
		add_action( 'generate_rewrite_rules', $counter );

		$module->maybe_flush();
		$first_signature = get_option( Anchor_Speakers_Module::SIGNATURE_OPTION );
		$this->assertNotFalse( $first_signature );
		$this->assertGreaterThan( 0, $flushes, 'First load (no stored signature) must flush.' );

		$flushes = 0;
		$module->maybe_flush();
		$this->assertSame( $first_signature, get_option( Anchor_Speakers_Module::SIGNATURE_OPTION ) );
		$this->assertSame( 0, $flushes, 'An unchanged signature must not flush again.' );

		update_option( 'anchor_speakers_options', [ 'base' => 'faculty', 'archive' => false ], false );
		$module->register();
		$flushes = 0;
		$module->maybe_flush();
		$this->assertNotSame( $first_signature, get_option( Anchor_Speakers_Module::SIGNATURE_OPTION ) );
		$this->assertGreaterThan( 0, $flushes, 'A base change made outside the settings page must flush again.' );

		remove_action( 'generate_rewrite_rules', $counter );
	}
}
