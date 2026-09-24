<?php
/** @group testimonials */
class Test_Testimonials_Registration extends WP_UnitTestCase {
	public function test_cpt_and_tax_registered_private() {
		$this->assertTrue( post_type_exists( 'anchor_testimonial' ) );
		$pt = get_post_type_object( 'anchor_testimonial' );
		$this->assertFalse( $pt->public );
		$this->assertFalse( $pt->publicly_queryable );
		$this->assertTrue( $pt->show_ui );
		$this->assertTrue( taxonomy_exists( 'anchor_testimonial_audience' ) );
	}
	public function test_default_audience_terms_seeded() {
		delete_option( 'anchor_testimonials_seeded' );
		( new Anchor_Testimonials_Module() )->seed_terms();
		$this->assertNotFalse( term_exists( 'patient', 'anchor_testimonial_audience' ) );
		$this->assertNotFalse( term_exists( 'doctor', 'anchor_testimonial_audience' ) );
	}
	public function test_module_in_registry() {
		$mods = anchor_tools_get_available_modules();
		$this->assertArrayHasKey( 'testimonials', $mods );
		$this->assertSame( 'Anchor_Testimonials_Module', $mods['testimonials']['class'] );
	}
}
