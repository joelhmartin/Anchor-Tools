const { test, expect, devices } = require( '@playwright/test' );
const fs = require( 'fs' );
const path = require( 'path' );
const { acceptConsentBanner, STRICT_POSTURE_TIMEZONE } = require( './helpers/consent' );

// AAGR_PRODUCT_ID wins; otherwise read what bin/e2e-seed.sh wrote to e2e/.seed.json.
// The seed creates the "Cancellation Policy" agreement (site default) and product
// slug "agreement-test" with _anchor_agreement_required=yes.
function productId() {
	if ( process.env.AAGR_PRODUCT_ID ) return process.env.AAGR_PRODUCT_ID;
	return JSON.parse( fs.readFileSync( path.join( __dirname, '.seed.json' ), 'utf8' ) ).agreements_product_id;
}

async function openCheckout( page ) {
	await page.goto( '/?add-to-cart=' + productId() );
	await page.goto( '/checkout/' );
	await acceptConsentBanner( page );
}

for ( const device of [ 'Desktop Chrome', 'iPhone 13' ] ) {
	test.describe( device, () => {
		// Strip defaultBrowserType: it can't be set inside a describe, and iPhone 13 would
		// otherwise demand WebKit. Viewport, touch, DPR and UA still emulate the phone on Chromium.
		const { defaultBrowserType, ...emulation } = devices[ device ]; // eslint-disable-line no-unused-vars
		test.use( { ...emulation, timezoneId: STRICT_POSTURE_TIMEZONE } );

		test( 'cannot order unsigned; draw signature unlocks; generate works', async ( { page } ) => {
			await openCheckout( page );
			const box = page.locator( '#aagr-confirm' );
			await expect( box ).not.toBeChecked();

			await page.locator( '.aagr-open' ).click();
			const modal = page.locator( '.aagr-modal' );
			await expect( modal ).toBeVisible();
			await expect( modal.locator( '#aagr-title' ) ).toHaveText( 'Cancellation Policy' );
			await expect( modal.locator( '.aagr-modal__doc' ) ).toContainText( 'forfeit the deposit' );

			const sign = modal.locator( '.aagr-modal__sign' );
			await expect( sign ).toBeDisabled();
			await modal.locator( 'input[name="aagr-name"]' ).fill( 'Test Buyer' );

			const pad = modal.locator( 'canvas.aagr-pad' );
			const b   = await pad.boundingBox();
			await page.mouse.move( b.x + 20, b.y + 40 );
			await page.mouse.down();
			await page.mouse.move( b.x + 120, b.y + 60, { steps: 8 } );
			await page.mouse.up();
			await modal.locator( 'input[name="aagr-consent"]' ).check();
			await expect( sign ).toBeEnabled();
			await sign.click();

			await expect( modal ).toBeHidden();
			await expect( page.locator( '.aagr-checkout[data-complete="1"] #aagr-confirm' ) ).toBeChecked();
		} );

		test( 'generate tab renders four font choices', async ( { page } ) => {
			await openCheckout( page );
			await page.locator( '.aagr-open' ).click();
			await page.locator( '.aagr-tab[data-tab="generate"]' ).click();
			await page.locator( 'input[name="aagr-name"]' ).fill( 'Test Buyer' );
			await expect( page.locator( '.aagr-font-choice' ) ).toHaveCount( 4 );
		} );

		test( 'clicking the box label opens the modal and never ticks the box by hand', async ( { page } ) => {
			await openCheckout( page );
			await page.locator( '.aagr-checkout__label span' ).click();
			await expect( page.locator( '.aagr-modal' ) ).toBeVisible();
			await expect( page.locator( '#aagr-confirm' ) ).not.toBeChecked();
			await page.keyboard.press( 'Escape' );
			await expect( page.locator( '.aagr-modal' ) ).toBeHidden();
			await expect( page.locator( '#aagr-confirm' ) ).not.toBeChecked();
		} );

		test( 'a generated signature can be signed', async ( { page } ) => {
			await openCheckout( page );
			await page.locator( '.aagr-open' ).click();
			const modal = page.locator( '.aagr-modal' );
			await modal.locator( 'input[name="aagr-name"]' ).fill( 'Test Buyer' );
			await modal.locator( '.aagr-tab[data-tab="generate"]' ).click();
			await modal.locator( '.aagr-font-choice' ).nth( 1 ).click();
			await modal.locator( 'input[name="aagr-consent"]' ).check();
			await modal.locator( '.aagr-modal__sign' ).click();
			await expect( modal ).toBeHidden();
			await expect( page.locator( '.aagr-checkout[data-complete="1"] #aagr-confirm' ) ).toBeChecked();
		} );
	} );
}
