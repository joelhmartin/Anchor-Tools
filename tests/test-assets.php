<?php
/**
 * Every enqueue in this module must version through Module::asset_version()
 * — filemtime of whichever file (source or `.min`) Anchor_Asset_Loader
 * actually resolves — instead of a hand-typed literal or a filemtime() of
 * the SOURCE path taken while the `.min` sibling is what's served
 * (RENDER-D16, WOO-D46, RENDER-D17).
 *
 * A hand-typed version string (or one pinned to the wrong file's mtime)
 * never changes when the served asset is rebuilt, so a fixed/rebuilt file
 * never reaches a returning browser even though the edit is on disk.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Module;

/**
 * @group assets
 */
class Test_Assets extends Anchor_Events_TestCase {

	/**
	 * admin.css / admin.js used to be pinned at the literal '1.0.5'
	 * (RENDER-D16) no matter how many times admin.min.{css,js} was rebuilt.
	 */
	public function test_admin_assets_version_css_and_js_via_asset_version() {
		set_current_screen( Module::CPT );
		$module = $this->module();
		$module->admin_assets( 'post.php' );

		$this->assertArrayHasKey( 'anchor-events-admin', wp_styles()->registered );
		$this->assertArrayHasKey( 'anchor-events-admin', wp_scripts()->registered );

		$css_ver = (string) wp_styles()->registered['anchor-events-admin']->ver;
		$js_ver  = (string) wp_scripts()->registered['anchor-events-admin']->ver;

		$this->assertNotSame( '1.0.5', $css_ver, 'admin.css is still pinned to the old hand-typed literal.' );
		$this->assertNotSame( '1.0.5', $js_ver, 'admin.js is still pinned to the old hand-typed literal.' );

		$this->assertSame( $module->asset_version( 'anchor-events-manager/assets/admin.css' ), $css_ver );
		$this->assertSame( $module->asset_version( 'anchor-events-manager/assets/admin.js' ), $js_ver );
	}

	/**
	 * The email-builder assets used to be versioned with
	 * filemtime( $edir . 'email-builder.css' ) — the SOURCE path — even
	 * though Anchor_Asset_Loader::url() serves email-builder.min.css
	 * (RENDER-D17). A rebuild of the .min file alone never bumped the
	 * version string.
	 */
	public function test_admin_assets_version_email_builder_by_served_file() {
		set_current_screen( Module::CPT );
		$module = $this->module();
		$module->admin_assets( 'post.php' );

		$this->assertArrayHasKey( 'anchor-events-email-builder', wp_styles()->registered );
		$this->assertArrayHasKey( 'anchor-events-email-builder', wp_scripts()->registered );

		$css_ver = (string) wp_styles()->registered['anchor-events-email-builder']->ver;
		$js_ver  = (string) wp_scripts()->registered['anchor-events-email-builder']->ver;

		$this->assertSame( $module->asset_version( 'anchor-events-manager/assets/email-builder.css' ), $css_ver );
		$this->assertSame( $module->asset_version( 'anchor-events-manager/assets/email-builder.js' ), $js_ver );

		// The bug used the SOURCE file's mtime unconditionally. Assert we are
		// no longer doing that when it would actually differ from the file
		// the loader resolves to.
		$edir              = ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-events-manager/assets/';
		$source_css_mtime  = (string) @\filemtime( $edir . 'email-builder.css' );
		$resolved_css_path = \Anchor_Asset_Loader::path( 'anchor-events-manager/assets/email-builder.css' );
		if ( $resolved_css_path !== $edir . 'email-builder.css' ) {
			$this->assertNotSame( $source_css_mtime, $css_ver, 'email-builder.css is still versioned by the SOURCE mtime, not the file actually served.' );
		}
	}

	/**
	 * event-storefront.js used to be pinned at the literal '1.0.0'
	 * (WOO-D46) regardless of the WooCommerce class holding a
	 * private-only Module::asset_version().
	 */
	public function test_storefront_asset_uses_asset_version_not_literal() {
		$this->require_wc();
		$woocommerce = $this->woocommerce();
		$this->assertNotNull( $woocommerce, 'WooCommerce class did not instantiate.' );

		$method = new ReflectionMethod( get_class( $woocommerce ), 'enqueue_storefront_assets' );
		$method->setAccessible( true );
		$method->invoke( $woocommerce );

		$this->assertArrayHasKey(
			'anchor-event-storefront',
			wp_scripts()->registered,
			'event-storefront.js was never enqueued — check enqueue_storefront_assets().'
		);

		$ver = (string) wp_scripts()->registered['anchor-event-storefront']->ver;
		$this->assertNotSame( '1.0.0', $ver );
		$this->assertSame(
			$this->module()->asset_version( 'anchor-events-manager/assets/event-storefront.js' ),
			$ver
		);
	}

	/**
	 * checkout-attendees.js used to be pinned at the literal '1.0.0'
	 * (WOO-D46) as well.
	 *
	 * WOO-D45 gated the enqueue on the cart actually holding an event line
	 * (a laser-accessory-only order used to load this script too, as a
	 * harmless but unconditional extra request on the checkout page), so
	 * this test now needs a real event line in the cart to exercise it.
	 */
	public function test_checkout_attendees_asset_uses_asset_version_not_literal() {
		$this->require_wc();
		$woocommerce = $this->woocommerce();
		$this->assertNotNull( $woocommerce, 'WooCommerce class did not instantiate.' );

		// CodeRabbit finding-3 (PR #20, 2nd round): require_wc() only checks
		// that the WooCommerce CLASS is active — WC()->cart is null until a
		// cart is actually loaded, which WC only does on a front-end request.
		// Load it explicitly the way WC's own AJAX endpoints (and
		// test-add-to-cart-ajax.php) do, or WC()->cart->empty_cart() below
		// dereferences null.
		if ( \function_exists( 'wc_load_cart' ) ) {
			\wc_load_cart();
		}
		$this->assertNotNull( WC()->cart, 'The cart must be loaded before this test can use it.' );

		$event_id = $this->make_event(
			[ 'title' => 'Asset Version Event', 'timezone' => 'UTC' ],
			[ [ 'label' => 'General', 'price' => '10', 'active' => 1 ] ]
		);
		$this->product_sync()->sync_event( $event_id );
		$tiers        = $this->ticket_types()->get( $event_id );
		$variation_id = (int) $this->product_sync()->variation_for_tier( $event_id, $tiers[0]['id'] );
		$this->assertGreaterThan( 0, $variation_id );
		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( (int) wc_get_product( $variation_id )->get_parent_id(), 1, $variation_id );

		add_filter( 'woocommerce_is_checkout', '__return_true' );
		try {
			$woocommerce->enqueue_checkout_assets();
		} finally {
			remove_filter( 'woocommerce_is_checkout', '__return_true' );
		}

		$this->assertArrayHasKey(
			'anchor-event-checkout-attendees',
			wp_scripts()->registered,
			'checkout-attendees.js was never enqueued — check enqueue_checkout_assets().'
		);

		$ver = (string) wp_scripts()->registered['anchor-event-checkout-attendees']->ver;
		$this->assertNotSame( '1.0.0', $ver );
		$this->assertSame(
			$this->module()->asset_version( 'anchor-events-manager/assets/checkout-attendees.js' ),
			$ver
		);
	}

	/**
	 * WOO-D45: a cart with NO event line must not load
	 * checkout-attendees.js at all — an unconditional extra request on the
	 * highest-value page on the site for every non-event order.
	 */
	public function test_checkout_attendees_asset_is_not_enqueued_without_an_event_cart_line() {
		$this->require_wc();
		wp_dequeue_script( 'anchor-event-checkout-attendees' );
		wp_deregister_script( 'anchor-event-checkout-attendees' );

		// CodeRabbit finding-3 (PR #20, 2nd round): see the sibling test above
		// — require_wc() does not guarantee WC()->cart exists.
		if ( \function_exists( 'wc_load_cart' ) ) {
			\wc_load_cart();
		}
		$this->assertNotNull( WC()->cart, 'The cart must be loaded before this test can use it.' );
		WC()->cart->empty_cart();

		add_filter( 'woocommerce_is_checkout', '__return_true' );
		try {
			$this->woocommerce()->enqueue_checkout_assets();
		} finally {
			remove_filter( 'woocommerce_is_checkout', '__return_true' );
		}

		$this->assertArrayNotHasKey( 'anchor-event-checkout-attendees', wp_scripts()->registered );
	}
}
