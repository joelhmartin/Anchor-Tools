<?php
/**
 * Tests for anchor-locations per-page content sections
 * (FAQ / testimonials / projects Monaco fields + shortcodes).
 *
 * @package Anchor\Tests
 */

class LocationsSectionsTest extends WP_UnitTestCase {

    /** @var \Anchor\Locations\Sections */
    private $sections;

    public function set_up() {
        parent::set_up();
        $this->sections = new \Anchor\Locations\Sections();
    }

    public function test_shortcode_renders_current_page_section() {
        $loc = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish' ] );
        update_post_meta( $loc, 'al_faq_html', '<p>How much does paving cost?</p>' );
        $this->go_to( get_permalink( $loc ) );
        $html = do_shortcode( '[anchor_local_faqs]' );
        $this->assertStringContainsString( 'How much does paving cost?', $html );
        $this->assertStringContainsString( 'al-faqs', $html );
    }

    public function test_shortcode_honors_id_attribute() {
        $loc = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish' ] );
        update_post_meta( $loc, 'al_testimonials_html', '<blockquote>Excellent</blockquote>' );
        $html = do_shortcode( '[anchor_local_testimonials id="' . $loc . '"]' );
        $this->assertStringContainsString( 'Excellent', $html );
        $this->assertStringContainsString( 'al-testimonials', $html );
    }

    public function test_blank_section_renders_empty() {
        $loc = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish' ] );
        $html = do_shortcode( '[anchor_local_projects id="' . $loc . '"]' );
        $this->assertSame( '', $html );
    }

    public function test_section_runs_nested_shortcodes() {
        $loc = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish', 'post_title' => 'Greenville' ] );
        update_post_meta( $loc, 'al_projects_html', 'Breadcrumbs: [anchor_breadcrumbs]' );
        $this->go_to( get_permalink( $loc ) );
        $html = do_shortcode( '[anchor_local_projects]' );
        $this->assertStringContainsString( 'al-breadcrumbs', $html );
    }

    public function test_recursion_guard_holds() {
        // A section that embeds its own shortcode must not loop forever.
        $loc = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish' ] );
        update_post_meta( $loc, 'al_faq_html', 'X[anchor_local_faqs id="' . $loc . '"]Y' );
        $this->go_to( get_permalink( $loc ) );
        $html = do_shortcode( '[anchor_local_faqs]' );
        $this->assertStringContainsString( 'X', $html );
        $this->assertStringContainsString( 'Y', $html );
    }

    public function test_save_stores_raw_html_and_requires_nonce() {
        $user = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $user );
        $loc = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish' ] );

        // No nonce -> no write.
        $_POST = [ 'al_faq_html' => '<p>nope</p>' ];
        $this->sections->save_meta( $loc );
        $this->assertSame( '', get_post_meta( $loc, 'al_faq_html', true ) );

        // With nonce -> raw HTML preserved (not sanitized).
        $_POST = [
            \Anchor\Locations\Sections::NONCE => wp_create_nonce( \Anchor\Locations\Sections::NONCE ),
            'al_faq_html'          => '<div onclick="x">Q</div>',
            'al_testimonials_html' => '<blockquote>T</blockquote>',
            'al_projects_html'     => '<p>P</p>',
        ];
        $this->sections->save_meta( $loc );
        $this->assertSame( '<div onclick="x">Q</div>', get_post_meta( $loc, 'al_faq_html', true ) );
        $this->assertSame( '<blockquote>T</blockquote>', get_post_meta( $loc, 'al_testimonials_html', true ) );
        $this->assertSame( '<p>P</p>', get_post_meta( $loc, 'al_projects_html', true ) );
        $_POST = [];
    }
}
