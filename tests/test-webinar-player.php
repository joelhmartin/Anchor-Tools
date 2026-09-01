<?php
// tests/test-webinar-player.php
//
// Regression guard for silent watch-tracking loss. The module used to have two
// renderers for the same job: the single template emitted the tracked container
// while [anchor_webinar] emitted a raw Vimeo iframe. Any site whose single
// webinar page is driven by a builder layout (Divi Theme Builder) runs the
// shortcode, so player.js found no container and logged nothing — for months,
// while the page source still looked correctly wired.
//
// Every render path must go through Module::render_player().
class WebinarPlayerTest extends WP_UnitTestCase {

    private function module() {
        return \Anchor\Webinars\Module::instance();
    }

    private function make_webinar( $vimeo_id = '958563353' ) {
        $id = self::factory()->post->create( [
            'post_type'   => 'anchor_webinar',
            'post_status' => 'publish',
        ] );
        if ( $vimeo_id ) {
            update_post_meta( $id, '_anchor_webinar_vimeo_id', $vimeo_id );
        }
        return $id;
    }

    public function set_up() {
        parent::set_up();
        // Fresh registries so the once-per-request guard in
        // enqueue_player_assets() cannot leak between tests.
        $GLOBALS['wp_scripts'] = null;
        $GLOBALS['wp_styles']  = null;
    }

    public function test_shortcode_emits_tracked_container_not_a_raw_iframe() {
        $id  = $this->make_webinar( '958563353' );
        $out = do_shortcode( '[anchor_webinar id="' . $id . '"]' );

        $this->assertStringContainsString( 'data-anchor-webinar-player', $out );
        $this->assertStringContainsString( 'data-webinar-id="' . $id . '"', $out );
        $this->assertStringContainsString( 'data-vimeo-id="958563353"', $out );

        // The untracked embed must not come back.
        $this->assertStringNotContainsString( '<iframe', $out );
        $this->assertStringNotContainsString( 'player.vimeo.com/video/', $out );
    }

    public function test_shortcode_enqueues_the_player_itself() {
        // frontend_assets() only fires on is_singular( anchor_webinar ); a
        // shortcode dropped on an ordinary page must still load the tracker.
        $id = $this->make_webinar();
        $this->assertFalse( wp_script_is( 'anchor-webinar-player', 'enqueued' ) );

        do_shortcode( '[anchor_webinar id="' . $id . '"]' );

        $this->assertTrue( wp_script_is( 'anchor-webinar-player', 'enqueued' ) );
        $this->assertTrue( wp_script_is( 'vimeo-player', 'enqueued' ) );

        $data = wp_scripts()->get_data( 'anchor-webinar-player', 'data' );
        $this->assertStringContainsString( 'ajaxUrl', (string) $data );
        $this->assertStringContainsString( 'nonce', (string) $data );
    }

    public function test_two_webinars_on_one_page_get_their_own_containers() {
        $a = $this->make_webinar( '111111' );
        $b = $this->make_webinar( '222222' );

        $out = do_shortcode( '[anchor_webinar id="' . $a . '"][anchor_webinar id="' . $b . '"]' );

        $this->assertStringContainsString( 'data-webinar-id="' . $a . '"', $out );
        $this->assertStringContainsString( 'data-vimeo-id="111111"', $out );
        $this->assertStringContainsString( 'data-webinar-id="' . $b . '"', $out );
        $this->assertStringContainsString( 'data-vimeo-id="222222"', $out );
        $this->assertSame( 2, substr_count( $out, 'data-anchor-webinar-player' ) );
    }

    public function test_render_player_is_empty_without_a_vimeo_id() {
        $id = $this->make_webinar( '' );
        $this->assertSame( '', $this->module()->render_player( $id ) );
        $this->assertFalse( wp_script_is( 'anchor-webinar-player', 'enqueued' ) );
    }

    public function test_gated_webinar_leaks_no_player_through_the_shortcode() {
        $id = $this->make_webinar( '958563353' );
        update_post_meta( $id, '_anchor_webinar_access', 'login' );
        wp_set_current_user( 0 );

        $out = do_shortcode( '[anchor_webinar id="' . $id . '"]' );

        $this->assertStringNotContainsString( '958563353', $out );
        $this->assertStringNotContainsString( 'data-anchor-webinar-player', $out );
        $this->assertFalse( wp_script_is( 'anchor-webinar-player', 'enqueued' ) );
    }
}
