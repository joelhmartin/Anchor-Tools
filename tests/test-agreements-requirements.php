<?php
// tests/test-agreements-requirements.php
use Anchor\Agreements\Admin\ProductFields;
use Anchor\Agreements\Content\AgreementPostType;
use Anchor\Agreements\Services\Requirements;
use Anchor\Agreements\Support\Settings;

if ( ! class_exists( 'WC_Admin_Meta_Boxes' ) && defined( 'WC_ABSPATH' ) ) {
	require_once WC_ABSPATH . 'includes/admin/class-wc-admin-meta-boxes.php';
}

class Test_Agreements_Requirements extends WP_UnitTestCase {
	private function agreement(): int {
		return self::factory()->post->create( [ 'post_type' => AgreementPostType::CPT, 'post_status' => 'publish', 'post_title' => 'Policy' ] );
	}

	private function product( string $required = 'yes', int $agreement = 0 ): WC_Product_Simple {
		$p = new WC_Product_Simple();
		$p->set_name( 'Course' );
		$p->set_regular_price( '10' );
		if ( $required ) {
			$p->update_meta_data( '_anchor_agreement_required', $required );
		}
		$p->update_meta_data( '_anchor_agreement_id', $agreement );
		$p->save();
		return $p;
	}

	public function test_not_required_returns_zero() {
		$this->assertSame( 0, Requirements::for_product( $this->product( '' ) ) );
	}

	public function test_explicit_agreement_wins_over_default() {
		$a = $this->agreement();
		$b = $this->agreement();
		update_option( Settings::OPTION, [ 'default_agreement_id' => $b ] );
		$this->assertSame( $a, Requirements::for_product( $this->product( 'yes', $a ) ) );
	}

	public function test_falls_back_to_default() {
		$b = $this->agreement();
		update_option( Settings::OPTION, [ 'default_agreement_id' => $b ] );
		$this->assertSame( $b, Requirements::for_product( $this->product( 'yes', 0 ) ) );
	}

	public function test_variation_uses_parent() {
		$a      = $this->agreement();
		$parent = new WC_Product_Variable();
		$parent->set_name( 'Var' );
		$parent->update_meta_data( '_anchor_agreement_required', 'yes' );
		$parent->update_meta_data( '_anchor_agreement_id', $a );
		$parent->save();
		$v = new WC_Product_Variation();
		$v->set_parent_id( $parent->get_id() );
		$v->set_regular_price( '5' );
		$v->save();
		$this->assertSame( $a, Requirements::for_product( wc_get_product( $v->get_id() ) ) );
	}

	public function test_save_with_required_but_no_document_adds_error() {
		delete_option( Settings::OPTION );
		$p     = $this->product( '' );
		$_POST = [ '_anchor_agreement_required' => 'yes', '_anchor_agreement_id' => '0' ];
		WC_Admin_Meta_Boxes::$meta_box_errors = [];
		( new ProductFields() )->save( $p );
		$this->assertTrue( Requirements::is_misconfigured( $p ) );
		$this->assertNotEmpty( WC_Admin_Meta_Boxes::$meta_box_errors );
		$_POST = [];
	}
}
