const { test, expect, devices } = require( '@playwright/test' );
const fs = require( 'fs' );
const path = require( 'path' );
const { acceptConsentBanner, STRICT_POSTURE_TIMEZONE } = require( './helpers/consent' );

// AAGR_PRODUCT_ID wins; otherwise read what bin/e2e-seed.sh wrote to e2e/.seed.json.
// The seed creates the "Cancellation Policy" agreement (site default) and product
// slug "agreement-test" with _anchor_agreement_required=yes.
function seed() {
	return JSON.parse( fs.readFileSync( path.join( __dirname, '.seed.json' ), 'utf8' ) );
}
function productId() {
	return process.env.AAGR_PRODUCT_ID || seed().agreements_product_id;
}

async function drawAndConsent( page, modal ) {
	await modal.locator( 'input[name="aagr-name"]' ).fill( 'Test Buyer' );
	await modal.locator( 'canvas.aagr-pad' ).scrollIntoViewIfNeeded(); // short landscape viewports scroll the dialog body
	const b = await modal.locator( 'canvas.aagr-pad' ).boundingBox();
	await page.mouse.move( b.x + 20, b.y + 40 );
	await page.mouse.down();
	await page.mouse.move( b.x + 120, b.y + 60, { steps: 8 } );
	await page.mouse.up();
	await modal.locator( 'input[name="aagr-consent"]' ).check();
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

// A wide dialog at DPR 3 would make a >2000px canvas (server cap is 2000x1000) without the DPR clamp.
test.describe( 'landscape phone at DPR 3', () => {
	test.use( { viewport: { width: 900, height: 420 }, deviceScaleFactor: 3, hasTouch: false, timezoneId: STRICT_POSTURE_TIMEZONE } );

	test( 'a drawn signature within the server size cap signs successfully', async ( { page } ) => {
		await openCheckout( page );
		await page.locator( '.aagr-open' ).click();
		const modal = page.locator( '.aagr-modal' );
		await drawAndConsent( page, modal );
		const dims = await modal.locator( 'canvas.aagr-pad' ).evaluate( ( c ) => [ c.width, c.height ] );
		expect( dims[ 0 ] ).toBeLessThanOrEqual( 2000 );
		expect( dims[ 1 ] ).toBeLessThanOrEqual( 1000 );
		await modal.locator( '.aagr-modal__sign' ).click();
		await expect( modal ).toBeHidden();
		await expect( page.locator( '.aagr-checkout[data-complete="1"] #aagr-confirm' ) ).toBeChecked();
	} );
} );

test.describe( 'multiple agreements', () => {
	test.use( { timezoneId: STRICT_POSTURE_TIMEZONE } );

	test( 'sign both in turn, then View reviews every document read-only', async ( { page } ) => {
		const s = seed();
		await page.goto( '/?add-to-cart=' + s.agreements_product_id );
		await page.goto( '/?add-to-cart=' + s.agreements_product2_id );
		await page.goto( '/checkout/' );
		await acceptConsentBanner( page );
		await page.locator( '.aagr-open' ).click();
		const modal = page.locator( '.aagr-modal' );
		for ( let i = 1; i <= 2; i++ ) {
			await expect( modal.locator( '.aagr-modal__step' ) ).toHaveText( i + ' of 2' );
			await drawAndConsent( page, modal );
			await modal.locator( '.aagr-modal__sign' ).click();
		}
		await expect( modal ).toBeHidden();
		await expect( page.locator( '.aagr-checkout[data-complete="1"] #aagr-confirm' ) ).toBeChecked();

		await page.locator( '.aagr-open' ).click();
		await expect( modal ).toBeVisible();
		await expect( modal.locator( '.aagr-modal__sign' ) ).toBeHidden();
		await expect( modal.locator( '.aagr-modal__step' ) ).toHaveText( '1 of 2' );
		const first = await modal.locator( '#aagr-title' ).textContent();
		await modal.locator( '.aagr-modal__next' ).click();
		await expect( modal.locator( '.aagr-modal__step' ) ).toHaveText( '2 of 2' );
		expect( await modal.locator( '#aagr-title' ).textContent() ).not.toBe( first );
		await modal.locator( '.aagr-modal__next' ).click(); // last document: closes
		await expect( modal ).toBeHidden();
	} );
} );
