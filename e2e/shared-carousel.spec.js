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
 * string (a literal double minus sign, e.g. "translateX(--316px)"). The
 * browser's CSSOM silently refuses to apply an invalid value and keeps
 * whatever was there before, which happens to look the same
 * ("translateX(0px)", left over from init()'s own currentIndex=0 render) as
 * the fixed, clamped result would - so reading the applied style back can't
 * tell the two apart. This instead intercepts every raw string assigned to
 * track.style.transform and asserts none of them is the invalid,
 * double-minus form, which does distinguish them. Builds a minimal carousel
 * entirely in-page (no fixture needed) via the already-loaded
 * window.AnchorCarousel global, so this runs against any page in the suite
 * that loads the shared carousel script.
 */
test('carousel with fewer items than visible columns clamps maxIndex to zero', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await page.goto(seed.galleryCarouselUrl);
  await acceptConsentBanner(page);

  const assignedTransforms = await page.evaluate(() => {
    const root = document.createElement('div');
    // > 1023px so getVisibleCount() picks the desktop bucket (3 columns);
    // 2 items < 3 columns is what makes items.length - visibleCount negative.
    root.style.width = '1100px';
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

    const seen = [];
    let raw = '';
    Object.defineProperty(track.style, 'transform', {
      configurable: true,
      get() { return raw; },
      set(v) { seen.push(v); raw = v; },
    });

    const api = window.AnchorCarousel.init(root, {
      track,
      items,
      mode: 'carousel',
      loop: true,
      cols: { desktop: 3, tablet: 2, mobile: 1 },
    });

    api.prev();
    root.remove();
    return seen;
  });

  expect(assignedTransforms.length).toBeGreaterThan(0);
  for (const value of assignedTransforms) {
    expect(value).not.toMatch(/--/);
  }
});
