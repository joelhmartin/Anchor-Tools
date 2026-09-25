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

test.describe('mouse drag (shared carousel Pointer Events)', () => {
	/**
	 * Dispatches a synthetic pointer-based drag across an element's centre.
	 * Mirrors the touch-swipe helper in e2e/gallery-lightbox.spec.js (manual
	 * event dispatch rather than OS-level mouse synthesis) so the exact
	 * target of the drag, and of the click that follows it, is deterministic.
	 * pointerId 1 with pointerType 'mouse' is what a real desktop drag
	 * produces; track.setPointerCapture()/releasePointerCapture() in
	 * assets/shared/anchor-carousel.js tolerate this synthetic pointerId
	 * (wrapped in try/catch there for exactly this reason).
	 */
	async function drag(page, selector, distance) {
		await page.evaluate(
			({ selector, distance }) => {
				const el = document.querySelector(selector);
				const box = el.getBoundingClientRect();
				const y = box.top + box.height / 2;
				const startX = box.left + box.width / 2;
				const fire = (type, x) =>
					el.dispatchEvent(
						new PointerEvent(type, {
							bubbles: true,
							cancelable: true,
							pointerId: 1,
							pointerType: 'mouse',
							button: 0,
							clientX: x,
							clientY: y,
						})
					);
				fire('pointerdown', startX);
				fire('pointermove', startX + distance / 2);
				fire('pointermove', startX + distance);
				fire('pointerup', startX + distance);
			},
			{ selector, distance }
		);
	}

	test('a mouse drag past the threshold advances the shared carousel track', async ({ page }) => {
		const track = page.locator('.anchor-testimonials__track').first();
		const before = await track.evaluate((el) => getComputedStyle(el).transform);

		await drag(page, '.anchor-testimonials__track', -120);

		await expect.poll(() => track.evaluate((el) => getComputedStyle(el).transform)).not.toBe(before);
	});

	test('a click that follows a drag past the threshold does not open the lightbox', async ({ page }) => {
		await drag(page, '.anchor-testimonials__track', -120);

		// A real drag-release sequence ends with the browser firing a
		// synthetic click on the element under the pointer; simulate that
		// click directly against the media button the drag started over.
		// The carousel's click-suppression (registered on pointerup, since
		// the drag exceeded the threshold above) must swallow it.
		await page.evaluate(() => {
			document.querySelector('.anchor-testimonial__media').dispatchEvent(
				new MouseEvent('click', { bubbles: true, cancelable: true })
			);
		});

		await expect(page.locator('.avg-modal')).toBeHidden();
	});

	test('a click with no preceding drag still opens the lightbox', async ({ page }) => {
		// Sanity check that the suppression is drag-scoped, not a blanket
		// click blocker: an ordinary click (no pointerdown/move/up sequence
		// first) must still open the lightbox.
		await page.locator('.anchor-testimonial__media').first().click();
		await expect(page.locator('.avg-modal')).toBeVisible();
	});

	test('a drag that ends outside the track does not leave stale click suppression armed', async ({ page }) => {
		// pointerup listens on `document` (not `track`), so a drag whose
		// release happens past the track's right edge never fires a `click`
		// on the track at all - the old once:true suppression listener would
		// stay armed forever in that case and swallow the NEXT, unrelated
		// click on the track instead. Drags past the threshold outside the
		// track, waits past the 300ms suppression window, then makes a fresh,
		// ordinary click; it must still open the lightbox.
		const track = page.locator('.anchor-testimonials__track').first();
		const box = await track.boundingBox();
		const y = box.y + box.height / 2;
		const startX = box.x + box.width - 20;
		const endX = box.x + box.width + 300;

		await page.evaluate(
			({ startX, endX, y }) => {
				const trackEl = document.querySelector('.anchor-testimonials__track');
				const fire = (target, type, x) =>
					target.dispatchEvent(
						new PointerEvent(type, {
							bubbles: true,
							cancelable: true,
							pointerId: 1,
							pointerType: 'mouse',
							button: 0,
							clientX: x,
							clientY: y,
						})
					);
				fire(trackEl, 'pointerdown', startX);
				fire(document, 'pointermove', startX - 80);
				// Release happens on document, past the track's right edge -
				// exactly what a real drag that leaves the track looks like.
				fire(document, 'pointerup', endX);
			},
			{ startX, endX, y }
		);

		await page.waitForTimeout(400);

		// The drag above advanced the carousel by exactly one slide, so the
		// first card in DOM order is now clipped out of the
		// (overflow: hidden) viewport - CSS transforms move it off-screen,
		// which Playwright's :visible pseudo-class does not account for (it
		// only checks computed display/visibility and box size, not
		// clipping by an ancestor), so it would still resolve to the
		// now-offscreen first card. Target the card that slid into view
		// instead (DOM index 1, confirmed via a manual elementFromPoint
		// check while diagnosing this).
		await page.locator('.anchor-testimonial__media').nth(1).click();
		await expect(page.locator('.avg-modal')).toBeVisible();
	});

	test('a drag in progress sets .anchor-carousel-is-dragging on the root and shows the grabbing cursor on the track', async ({ page }) => {
		const track = page.locator('.anchor-testimonials__track').first();

		// Fires pointerdown + pointermove only (no pointerup yet), so the
		// drag is still "in progress" when we check state - mirrors a real
		// click-and-hold. Cleans up with a real pointerup afterward.
		await page.evaluate(() => {
			const el = document.querySelector('.anchor-testimonials__track');
			const box = el.getBoundingClientRect();
			const y = box.top + box.height / 2;
			const startX = box.left + box.width / 2;
			const fire = (type, x) =>
				el.dispatchEvent(
					new PointerEvent(type, { bubbles: true, cancelable: true, pointerId: 1, pointerType: 'mouse', button: 0, clientX: x, clientY: y })
				);
			fire('pointerdown', startX);
			fire('pointermove', startX - 60);
		});

		const root = page.locator('.anchor-testimonials').first();
		await expect(root).toHaveClass(/anchor-carousel-is-dragging/);
		await expect(track).toHaveCSS('cursor', 'grabbing');

		// Release, so the class/cursor don't leak into whatever runs next.
		await page.evaluate(() => {
			document.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, cancelable: true, pointerId: 1, pointerType: 'mouse', button: 0 }));
		});
		await expect(root).not.toHaveClass(/anchor-carousel-is-dragging/);
		await expect(track).toHaveCSS('cursor', 'grab');
	});

	test('losing window focus mid-drag (alt-tab) clears .anchor-carousel-is-dragging', async ({ page }) => {
		// A drag interrupted by switching away entirely (alt-tab, another app
		// stealing focus) never fires pointerup or pointercancel - the button
		// state (down/up) at that point is unknowable to this page at all.
		// Only a window blur signals it, which is what
		// assets/shared/anchor-carousel.js's cancelDrag() listens for.
		const track = page.locator('.anchor-testimonials__track').first();

		await page.evaluate(() => {
			const el = document.querySelector('.anchor-testimonials__track');
			const box = el.getBoundingClientRect();
			const y = box.top + box.height / 2;
			const startX = box.left + box.width / 2;
			const fire = (type, x) =>
				el.dispatchEvent(
					new PointerEvent(type, { bubbles: true, cancelable: true, pointerId: 1, pointerType: 'mouse', button: 0, clientX: x, clientY: y })
				);
			fire('pointerdown', startX);
			fire('pointermove', startX - 60);
		});

		const root = page.locator('.anchor-testimonials').first();
		await expect(root).toHaveClass(/anchor-carousel-is-dragging/);
		await expect(track).toHaveCSS('cursor', 'grabbing');

		await page.evaluate(() => {
			window.dispatchEvent(new Event('blur'));
		});

		await expect(root).not.toHaveClass(/anchor-carousel-is-dragging/);
		await expect(track).toHaveCSS('cursor', 'grab');
	});

	test('a real drag across quote text does not select it', async ({ page }) => {
		// Unlike the synthetic-dispatch drags above (deterministic click
		// targeting), native text selection only responds to trusted input -
		// a synthetic PointerEvent dispatch wouldn't exercise the browser's
		// own selection behavior at all, so this uses real OS-level-equivalent
		// mouse actions (page.mouse), the same reasoning that applied to the
		// native-scroll touch test in shared-carousel.spec.js.
		const quote = page.locator('.anchor-testimonial__quote').first();
		await expect(quote).toBeVisible();
		const box = await quote.boundingBox();
		const y = box.y + box.height / 2;

		await page.mouse.move(box.x + 4, y);
		await page.mouse.down();
		await page.mouse.move(box.x + box.width - 4, y, { steps: 10 });
		await page.mouse.up();

		const selectedText = await page.evaluate(() => window.getSelection().toString());
		expect(selectedText).toBe('');
	});
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
