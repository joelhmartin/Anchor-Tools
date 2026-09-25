// @ts-check
const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { acceptConsentBanner, STRICT_POSTURE_TIMEZONE } = require('./helpers/consent');

/**
 * Gallery layout ("Featured + Thumbs" / feature_gallery preset) with
 * popup_style="inline".
 *
 * Regression coverage: this layout has no `.avg-track` (only the slider
 * layout renders one), so the generic inline player in
 * anchor-video-slider.js used to call `.avg-track`.insertBefore() on null
 * and throw "Cannot read properties of null (reading 'insertBefore')" the
 * moment the featured tile was clicked. The fix plays the embed in place of
 * `.avg-gallery-featured` instead.
 *
 * FIXTURE: bin/e2e-seed.sh publishes one gallery (popup_style=inline,
 * layout=gallery) with three YouTube video items on a page, and writes
 * galleryInlineUrl into e2e/.seed.json. Run `npm run env:seed` before this
 * spec.
 */

const SEED_PATH = path.join(__dirname, '.seed.json');

/** @type {{ galleryInlineUrl: string }} */
let seed;

test.beforeAll(() => {
  if (!fs.existsSync(SEED_PATH)) {
    throw new Error('Missing e2e/.seed.json — run `npm run env:seed` first.');
  }
  seed = JSON.parse(fs.readFileSync(SEED_PATH, 'utf8'));
  if (!seed.galleryInlineUrl) {
    throw new Error('Seed has no galleryInlineUrl — re-run `npm run env:seed`.');
  }
});

// Clicks tiles, so the CMP gate must be settled first — see
// e2e/helpers/consent.js. Pinned to strict posture so a local run reproduces
// CI rather than getting the non-blocking relaxed notice.
test.use({ timezoneId: STRICT_POSTURE_TIMEZONE });

test.beforeEach(async ({ page }) => {
  await page.goto(seed.galleryInlineUrl);
  await acceptConsentBanner(page);
});

test('clicking the featured tile plays its video inline, in place, with no console errors', async ({ page }) => {
  const consoleErrors = [];
  page.on('console', (msg) => {
    if (msg.type() === 'error') consoleErrors.push(msg.text());
  });
  page.on('pageerror', (err) => consoleErrors.push(String(err)));

  const gallery = page.locator('.anchor-video-gallery');
  await expect(gallery).toHaveAttribute('data-layout', 'gallery');
  await expect(gallery).toHaveAttribute('data-popup', 'inline');

  const featured = gallery.locator('.avg-gallery-featured');
  await featured.click();

  const frame = featured.locator('.avg-thumb iframe');
  await expect(frame).toHaveCount(1);
  await expect(frame).toHaveAttribute('src', /youtube\.com\/embed\/dQw4w9WgXcQ/);

  expect(consoleErrors).toEqual([]);
});

test('clicking a thumb swaps the featured tile to that video and plays it there', async ({ page }) => {
  const gallery = page.locator('.anchor-video-gallery');
  const featured = gallery.locator('.avg-gallery-featured');
  const thumbs = gallery.locator('.avg-gallery-thumb');

  // Third thumb -> third seeded video.
  await thumbs.nth(2).click();

  await expect(featured).toHaveAttribute('data-video-id', '9bZkp7q19f0');
  const frame = featured.locator('.avg-thumb iframe');
  await expect(frame).toHaveCount(1);
  await expect(frame).toHaveAttribute('src', /youtube\.com\/embed\/9bZkp7q19f0/);

  // Switching again tears down the previous iframe rather than stacking one.
  await thumbs.nth(0).click();
  await expect(featured).toHaveAttribute('data-video-id', 'dQw4w9WgXcQ');
  await expect(featured.locator('.avg-thumb iframe')).toHaveCount(1);
  await expect(featured.locator('.avg-thumb iframe')).toHaveAttribute('src', /youtube\.com\/embed\/dQw4w9WgXcQ/);
});

test('the close button tears down the iframe and restores the static featured thumb', async ({ page }) => {
  const gallery = page.locator('.anchor-video-gallery');
  const featured = gallery.locator('.avg-gallery-featured');

  await featured.click();
  await expect(featured.locator('.avg-thumb iframe')).toHaveCount(1);

  await featured.locator('.avg-gallery-featured-close').click();
  await expect(featured.locator('.avg-thumb iframe')).toHaveCount(0);
  await expect(featured.locator('.avg-thumb-img')).toHaveCount(1);
});

test('the featured tile is keyboard accessible', async ({ page }) => {
  const gallery = page.locator('.anchor-video-gallery');
  const featured = gallery.locator('.avg-gallery-featured');

  await expect(featured).toHaveAttribute('role', 'button');
  await expect(featured).toHaveAttribute('tabindex', '0');

  await featured.focus();
  await page.keyboard.press('Enter');
  await expect(featured.locator('.avg-thumb iframe')).toHaveCount(1);
});
