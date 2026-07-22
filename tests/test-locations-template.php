<?php
/**
 * Full-width single template is served for location/service pages when the
 * `fullwidth_template` setting is on and the theme provides no template.
 * (Behavior relocated from the removed SEO class into Module.)
 *
 * @package Anchor\Tests
 */

class LocationsTemplateTest extends WP_UnitTestCase {

    private $module;

    public function set_up() {
        parent::set_up();
        $this->module = new \Anchor\Locations\Module();
    }

    public function tear_down() {
        delete_option( \Anchor\Locations\Module::OPTION );
        parent::tear_down();
    }

    public function test_fullwidth_template_used_when_enabled_on_cpt() {
        update_option( \Anchor\Locations\Module::OPTION, [ 'fullwidth_template' => '1' ], false );
        $loc = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish' ] );
        $this->go_to( get_permalink( $loc ) );
        $this->assertTrue( is_singular( 'anchor_location' ) );

        $out = $this->module->fullwidth_template( '/theme/single.php' );
        $this->assertStringContainsString( 'single-anchor-fullwidth.php', $out );
    }

    public function test_fullwidth_template_untouched_when_disabled() {
        update_option( \Anchor\Locations\Module::OPTION, [ 'fullwidth_template' => '' ], false );
        $loc = self::factory()->post->create( [ 'post_type' => 'anchor_location', 'post_status' => 'publish' ] );
        $this->go_to( get_permalink( $loc ) );
        $this->assertSame( '/theme/single.php', $this->module->fullwidth_template( '/theme/single.php' ) );
    }

    public function test_fullwidth_template_untouched_off_cpt() {
        update_option( \Anchor\Locations\Module::OPTION, [ 'fullwidth_template' => '1' ], false );
        $page = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish' ] );
        $this->go_to( get_permalink( $page ) );
        $this->assertSame( '/theme/single.php', $this->module->fullwidth_template( '/theme/single.php' ) );
    }
}
