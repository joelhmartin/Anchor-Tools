<?php
/** @group shared-assets */
class Test_Shared_Assets extends WP_UnitTestCase {
	public function test_lightbox_handles_are_registered() {
		do_action( 'wp_enqueue_scripts' );
		$this->assertArrayHasKey( 'anchor-lightbox', wp_scripts()->registered );
		$this->assertArrayHasKey( 'anchor-lightbox', wp_styles()->registered );
		$this->assertNotEmpty( wp_scripts()->registered['anchor-lightbox']->ver );
	}

	public function test_gallery_script_depends_on_lightbox() {
		do_action( 'wp_enqueue_scripts' );
		$gallery = wp_scripts()->registered['anchor-video-gallery'] ?? null;
		$this->assertNotNull( $gallery, 'gallery module must be enabled in tests/bootstrap.php' );
		$this->assertContains( 'anchor-lightbox', $gallery->deps );
	}

	public function test_carousel_handle_registered_and_gallery_depends_on_it() {
		do_action( 'wp_enqueue_scripts' );
		$this->assertArrayHasKey( 'anchor-carousel', wp_scripts()->registered );
		$this->assertContains( 'anchor-carousel', wp_scripts()->registered['anchor-video-gallery']->deps );
	}

	/**
	 * Owner feedback (drag cursor/text-selection): anchor-carousel.css now
	 * carries rules (.anchor-carousel-drag, .is-dragging) that apply to any
	 * carousel-mode track, gallery included - not just the non-gallery
	 * layout rules it used to be scoped to. The gallery style must depend on
	 * the carousel STYLE handle (previously only the script did), or those
	 * rules never load on a gallery page at all.
	 */
	public function test_gallery_style_depends_on_carousel_style() {
		do_action( 'wp_enqueue_scripts' );
		$gallery_style = wp_styles()->registered['anchor-video-gallery'] ?? null;
		$this->assertNotNull( $gallery_style, 'gallery module must be enabled in tests/bootstrap.php' );
		$this->assertContains( 'anchor-carousel', $gallery_style->deps );
	}

	/**
	 * Regression: enqueue_admin_assets() on the gallery edit screen used to
	 * register 'anchor-video-gallery' with NO deps, so window.AnchorLightbox
	 * and window.AnchorCarousel were undefined in wp-admin even though the
	 * script (unchanged from the front-end file) reads them unconditionally
	 * at the top of its IIFE. `var LB = window.AnchorLightbox; LB.getVideoSrc`
	 * threw on a null LB, aborting the IIFE before window.AnchorVideoGallery
	 * was ever set, which broke the builder preview entirely (admin.js has
	 * nothing to call).
	 */
	public function test_gallery_admin_enqueue_depends_on_lightbox_and_carousel() {
		// Anchor_Shared_Assets registers its handles on admin_enqueue_scripts
		// at priority 5; the gallery module hooks enqueue_admin_assets on the
		// same action with no explicit priority (WordPress default 10). Confirm
		// that ordering holds: it's what guarantees the shared handles exist
		// by the time the gallery's own admin enqueue references them as deps.
		$shared_priority = has_action( 'admin_enqueue_scripts', [ 'Anchor_Shared_Assets', 'register' ] );
		$this->assertSame( 5, $shared_priority, 'Anchor_Shared_Assets::register must stay on admin_enqueue_scripts priority 5.' );

		$module = new Anchor_Gallery_Module();
		$gallery_priority = has_action( 'admin_enqueue_scripts', [ $module, 'enqueue_admin_assets' ] );
		$this->assertNotFalse( $gallery_priority, 'Anchor_Gallery_Module must hook enqueue_admin_assets on admin_enqueue_scripts.' );
		$this->assertGreaterThan( $shared_priority, $gallery_priority, 'Shared assets must register before the gallery admin enqueue runs.' );

		// Simulate the gallery edit screen: a real anchor_gallery post open on
		// post.php, exactly as WordPress sets it up for that admin request.
		$post_id = self::factory()->post->create( [ 'post_type' => Anchor_Gallery_Module::CPT ] );
		global $post;
		$post = get_post( $post_id );
		set_current_screen( Anchor_Gallery_Module::CPT );

		// wp_(register|enqueue)_(script|style)() is a no-op on an already
		// registered handle (WP_Dependencies::add() returns false rather than
		// overwriting), and an earlier test in this run may have already
		// registered 'anchor-video-gallery' via the front-end enqueue_assets()
		// path. Deregister first so THIS call's deps are what actually get
		// asserted, not leftover state from a different code path.
		wp_deregister_script( 'anchor-video-gallery' );
		wp_deregister_style( 'anchor-video-gallery' );

		// Run the two hooks in their real production order (priority 5, then 10).
		Anchor_Shared_Assets::register();
		$module->enqueue_admin_assets( 'post.php' );

		$gallery_script = wp_scripts()->registered['anchor-video-gallery'] ?? null;
		$this->assertNotNull( $gallery_script, 'anchor-video-gallery script must be enqueued on the gallery edit screen.' );
		$this->assertContains( 'anchor-lightbox', $gallery_script->deps );
		$this->assertContains( 'anchor-carousel', $gallery_script->deps );

		$gallery_style = wp_styles()->registered['anchor-video-gallery'] ?? null;
		$this->assertNotNull( $gallery_style, 'anchor-video-gallery style must be enqueued on the gallery edit screen.' );
		$this->assertContains( 'anchor-lightbox', $gallery_style->deps );
		$this->assertContains( 'anchor-carousel', $gallery_style->deps );

		$post = null;
		set_current_screen( 'front' );
	}
}
