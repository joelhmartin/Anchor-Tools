// @ts-check
const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { acceptConsentBanner, STRICT_POSTURE_TIMEZONE } = require('./helpers/consent');

/**
 * [anchor_testimonials layout="slider"]: shared lightbox (assets/shared/
 * anchor-lightbox.js) and shared carousel (assets/shared/anchor-carousel.js)
 * integration, driven through anchor-testimonials/assets/testimonials.js.
 *
 * FIXTURE: bin/e2e-seed.sh publishes a page with [anchor_testimonials
 * layout="slider"] and four testimonials (2 with YouTube video URLs, 2
 * quote-only), and writes testimonialsSliderUrl into e2e/.seed.json.
 * Run `npm run env:seed` before this spec.
 */

const SEED_PATH = path.join(__dirname, '.seed.json');

/** @type {{ testimonialsSliderUrl: string }} */
let seed;

test.beforeAll(() => {
	if (!fs.existsSync(SEED_PATH)) {
		throw new Error('Missing e2e/.seed.json, run `npm run env:seed` first.');
	}
	seed = JSON.parse(fs.readFileSync(SEED_PATH, 'utf8'));
	if (!seed.testimonialsSliderUrl) {
		throw new Error('Seed has no testimonialsSliderUrl, re-run `npm run env:seed`.');
	}
});

// Clicks media buttons and the next control, so the CMP gate must be settled
// first (see e2e/helpers/consent.js). Pinned to strict posture so a local run
// reproduces CI. Desktop viewport so the carousel's visible count and column
// math match the seeded 3-column default.
test.use({ timezoneId: STRICT_POSTURE_TIMEZONE, viewport: { width: 1280, height: 900 } });

test.beforeEach(async ({ page }) => {
	await page.goto(seed.testimonialsSliderUrl);
	await acceptConsentBanner(page);
});

test('clicking the first video testimonial opens the shared lightbox with a matching iframe', async ({ page }) => {
	const firstMedia = page.locator('.anchor-testimonial__media').first();
	const videoId = await firstMedia.getAttribute('data-video-id');
	expect(videoId).toBeTruthy();

	await firstMedia.click();

	const modal = page.locator('.avg-modal');
	await expect(modal).toBeVisible();
	await expect(modal.locator('.avg-modal-frame iframe')).toHaveAttribute('src', new RegExp(videoId));
});

test('escape hides the lightbox and returns focus to the originating button', async ({ page }) => {
	const firstMedia = page.locator('.anchor-testimonial__media').first();
	await firstMedia.click();

	const modal = page.locator('.avg-modal');
	await expect(modal).toBeVisible();

	await page.keyboard.press('Escape');
	await expect(modal).toBeHidden();

	const returnedFocus = await firstMedia.evaluate((el) => el === document.activeElement);
	expect(returnedFocus).toBe(true);
});

test('a video with a start time passes it into the lightbox iframe src', async ({ page }) => {
	// Fixture: E2E Testimonial 1's video URL is ...&t=42s (bin/e2e-seed.sh),
	// so its media button carries data-start="42".
	const firstMedia = page.locator('.anchor-testimonial__media').first();
	await expect(firstMedia).toHaveAttribute('data-start', '42');

	await firstMedia.click();

	const modal = page.locator('.avg-modal');
	await expect(modal).toBeVisible();
	await expect(modal.locator('.avg-modal-frame iframe')).toHaveAttribute('src', /[?&]start=42\b/);
});

test('the next control advances the shared carousel track', async ({ page }) => {
	const track = page.locator('.anchor-testimonials__track').first();
	const before = await track.evaluate((el) => getComputedStyle(el).transform);

	await page.locator('.anchor-testimonials__next').first().click();

	await expect.poll(() => track.evaluate((el) => getComputedStyle(el).transform)).not.toBe(before);
});

test.describe('mobile viewport (390px)', () => {
	// Final whole-branch review finding 3: --anchor-carousel-cols had no
	// breakpoints, so testimonials.js's AnchorCarousel.init() computed a
	// mobile card count of 1 while the CSS still laid out cards at the
	// desktop column width below 1023px/767px, so card widths and the
	// per-click advance no longer matched.
	test.use({ viewport: { width: 390, height: 844 } });

	test('one card is visible per view and next advances by one card', async ({ page }) => {
		const viewport = page.locator('.anchor-testimonials__viewport').first();
		const track = page.locator('.anchor-testimonials__track').first();
		const firstCard = track.locator(':scope > *').first();

		// The card fills its own viewport container's width (which is
		// narrower than the raw 390px browser viewport, since the active
		// theme adds its own page-content padding) -- i.e. --at-cols
		// resolved to 1 below the 767px breakpoint, matching
		// testimonials.js's mobile: 1 cols.
		const viewportBox = await viewport.boundingBox();
		const cardBox = await firstCard.boundingBox();
		expect(viewportBox).not.toBeNull();
		expect(cardBox).not.toBeNull();
		expect(Math.abs(cardBox.width - viewportBox.width)).toBeLessThan(2);

		const before = await track.evaluate((el) => getComputedStyle(el).transform);
		await page.locator('.anchor-testimonials__next').first().click();
		await expect.poll(() => track.evaluate((el) => getComputedStyle(el).transform)).not.toBe(before);
	});
});
