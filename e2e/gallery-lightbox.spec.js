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

test('tiles are keyboard focusable and expose a button role', async ({ page }) => {
  await page.goto(seed.gallery_page_url);
  const first = page.locator('.anchor-video-gallery .avg-tile').first();
  await expect(first).toHaveAttribute('role', 'button');
  await expect(first).toHaveAttribute('tabindex', '0');

  // HTML tiles host arbitrary shortcode markup — they must NOT be buttons.
  const htmlTile = page.locator('.anchor-video-gallery .avg-tile-html');
  await expect(htmlTile).not.toHaveAttribute('role', 'button');
});

test('collectSequence returns all six visible items with the clicked start index', async ({ page }) => {
  await page.goto(seed.gallery_page_url);
  const result = await page.evaluate(() => {
    const gallery = document.querySelector('.anchor-video-gallery');
    const tiles = gallery.querySelectorAll('.avg-tile');
    const seq = window.AnchorVideoGallery.collectSequence(gallery, tiles[3]);
    return {
      types: seq.items.map((i) => i.type),
      startIndex: seq.startIndex,
      videoProvider: seq.items[2].provider,
      videoId: seq.items[2].videoId,
      imageHasUrl: !!seq.items[0].fullUrl,
      imageAlt: seq.items[0].alt,
      htmlContent: seq.items[4].html,
      caption: seq.items[0].caption,
    };
  });

  expect(result.types).toEqual(['image', 'image', 'video', 'image', 'html', 'image']);
  expect(result.startIndex).toBe(3);
  expect(result.videoProvider).toBe('youtube');
  expect(result.videoId).toBe('dQw4w9WgXcQ');
  expect(result.imageHasUrl).toBe(true);
  expect(result.imageAlt).toBe('Red image');
  expect(result.htmlContent).toContain('Hello HTML');
  expect(result.caption).toBe('Caption red');
});

test('clicking a tile opens the lightbox at that item with a counter', async ({ page }) => {
  await page.goto(seed.gallery_page_url);
  await page.locator('.anchor-video-gallery .avg-tile').nth(1).click();

  const modal = page.locator('.avg-modal');
  await expect(modal).toBeVisible();
  await expect(modal.locator('.avg-modal-counter')).toHaveText('2 / 6');
  await expect(modal.locator('.avg-modal-frame img')).toHaveAttribute('alt', 'Green image');
});

test('next advances, prev goes back, and the video iframe is destroyed on navigate', async ({ page }) => {
  await page.goto(seed.gallery_page_url);
  // Open on the video (index 2).
  await page.locator('.anchor-video-gallery .avg-tile').nth(2).click();

  const modal = page.locator('.avg-modal');
  const frame = modal.locator('.avg-modal-frame');
  await expect(frame.locator('iframe')).toHaveCount(1);
  await expect(frame.locator('iframe')).toHaveAttribute('src', /youtube\.com\/embed\/dQw4w9WgXcQ/);

  // Advance — the iframe must be GONE. This is the video-stop guarantee.
  await modal.locator('[data-next]').click();
  await expect(frame.locator('iframe')).toHaveCount(0);
  await expect(modal.locator('.avg-modal-counter')).toHaveText('4 / 6');
  await expect(frame.locator('img')).toHaveAttribute('alt', 'Blue image');

  // Back to the video.
  await modal.locator('[data-prev]').click();
  await expect(modal.locator('.avg-modal-counter')).toHaveText('3 / 6');
  await expect(frame.locator('iframe')).toHaveCount(1);
});

test('closing the lightbox destroys the iframe', async ({ page }) => {
  await page.goto(seed.gallery_page_url);
  await page.locator('.anchor-video-gallery .avg-tile').nth(2).click();
  const modal = page.locator('.avg-modal');
  await expect(modal.locator('iframe')).toHaveCount(1);
  await modal.locator('.avg-modal-close').click();
  await expect(modal).toBeHidden();
  await expect(modal.locator('iframe')).toHaveCount(0);
});
