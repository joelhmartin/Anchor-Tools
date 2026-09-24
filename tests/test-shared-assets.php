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
}
