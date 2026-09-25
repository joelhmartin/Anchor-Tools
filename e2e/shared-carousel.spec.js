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

/**
 * PR #28 review finding B: with fewer items than the responsive visible
 * column count (e.g. 2 items, 3 desktop columns), maxIndex went negative;
 * pressing "previous" before any forward navigation in loop mode set
 * currentIndex to that negative maxIndex, producing an invalid transform
 * string (a literal double minus sign) that the browser silently refuses to
 * apply, leaving the track's inline transform never set. Builds a minimal
 * carousel entirely in-page (no fixture needed) via the already-loaded
 * window.AnchorCarousel global, so this runs against any page in the suite
 * that loads the shared carousel script.
 */
test('carousel with fewer items than visible columns clamps maxIndex to zero', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await page.goto(seed.galleryCarouselUrl);
  await acceptConsentBanner(page);

  const transform = await page.evaluate(() => {
    const root = document.createElement('div');
    root.style.width = '900px';
    root.style.setProperty('--avg-gap', '16px');
    const track = document.createElement('div');
    root.appendChild(track);
    document.body.appendChild(root);

    const items = [0, 1].map(() => {
      const item = document.createElement('div');
      item.style.width = '300px';
      track.appendChild(item);
      return item;
    });

    const api = window.AnchorCarousel.init(root, {
      track,
      items,
      mode: 'carousel',
      loop: true,
      cols: { desktop: 3, tablet: 2, mobile: 1 },
    });

    api.prev();
    const result = track.style.transform;
    root.remove();
    return result;
  });

  expect(transform).toBe('translateX(-0px)');
});
