// @ts-check
const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');

/**
 * Gallery lightbox navigation.
 *
 * FIXTURE: bin/e2e-seed.sh publishes one gallery (popup_style=lightbox,
 * layout=grid, pagination off) on a page, with six items in a known order:
 *   0 image Red | 1 image Green | 2 video YouTube | 3 image Blue
 *   4 html      | 5 image Amber
 * and writes gallery_page_url / gallery_id into e2e/.seed.json.
 * Run `npm run env:seed` before this spec.
 *
 * We never assert on video PLAYBACK — that would need the network and be
 * flaky. The video-stop guarantee is asserted structurally: the iframe
 * element is gone from the DOM after navigating away.
 */

const SEED_PATH = path.join(__dirname, '.seed.json');

/** @type {{ gallery_page_url: string, gallery_id: number }} */
let seed;

test.beforeAll(() => {
  if (!fs.existsSync(SEED_PATH)) {
    throw new Error('Missing e2e/.seed.json — run `npm run env:seed` first.');
  }
  seed = JSON.parse(fs.readFileSync(SEED_PATH, 'utf8'));
  if (!seed.gallery_page_url) {
    throw new Error('Seed has no gallery_page_url — re-run `npm run env:seed`.');
  }
});

test('gallery renders six tiles with the expected types', async ({ page }) => {
  await page.goto(seed.gallery_page_url);
  const gallery = page.locator('.anchor-video-gallery');
  await expect(gallery).toHaveAttribute('data-popup', 'lightbox');

  const tiles = gallery.locator('.avg-tile');
  await expect(tiles).toHaveCount(6);
  await expect(tiles.nth(0)).toHaveAttribute('data-type', 'image');
  await expect(tiles.nth(2)).toHaveAttribute('data-type', 'video');
  await expect(tiles.nth(2)).toHaveAttribute('data-provider', 'youtube');
  await expect(tiles.nth(4)).toHaveAttribute('data-type', 'html');
});
