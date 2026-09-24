// @ts-check
const { test, expect } = require('@playwright/test');
const seed = require('./.seed.json');
const { acceptConsentBanner, STRICT_POSTURE_TIMEZONE } = require('./helpers/consent');

/**
 * Shared carousel (assets/shared/anchor-carousel.js), driven through the
 * gallery module's carousel layout.
 *
 * FIXTURE: bin/e2e-seed.sh publishes one gallery (layout=carousel, 5 video
 * items, 3 desktop columns, dots on) on a page, and writes galleryCarouselUrl
 * into e2e/.seed.json. Run `npm run env:seed` before this spec.
 *
 * Pinned to strict consent posture (same as gallery-lightbox.spec.js) so the
 * compliance gate is dismissed before any click, reproducing CI locally.
 */
test.use({ timezoneId: STRICT_POSTURE_TIMEZONE });

test('gallery carousel advances with next and dots via shared carousel', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await page.goto(seed.galleryCarouselUrl);
  await acceptConsentBanner(page);

  const track = page.locator('.avg-track').first();
  const before = await track.evaluate(el => getComputedStyle(el).transform);
  await page.locator('.avg-nav-next').first().click();
  await expect.poll(() => track.evaluate(el => getComputedStyle(el).transform)).not.toBe(before);
  await expect(page.locator('.avg-dot')).toHaveCount(5);
  await page.locator('.avg-dot').nth(0).click();
  await expect(page.locator('.avg-dot').nth(0)).toHaveClass(/active/);
  expect(await page.evaluate(() => typeof window.AnchorCarousel.init)).toBe('function');
});
