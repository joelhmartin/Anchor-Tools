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

/**
 * Fix round 1 finding: mode === 'slider' (the gallery module's default
 * layout - a native-scroll, scroll-snap track, unlike mode === 'carousel'
 * above which is a JS-driven transform) must keep native horizontal touch
 * scrolling untouched. assets/shared/anchor-carousel.js used to set
 * touch-action: pan-y on every track regardless of mode, which blocked the
 * browser's own horizontal panning on a slider-layout gallery; the Pointer
 * Events drag handling and that touch-action override are now gated to
 * mode === 'carousel' only.
 *
 * FIXTURE: bin/e2e-seed.sh publishes one gallery (layout=slider, 6 video
 * items, 3 desktop columns) on a page, and writes gallerySliderUrl into
 * e2e/.seed.json. Run `npm run env:seed` before this spec.
 */
test.describe('native slider mode (touch)', () => {
  test.use({ hasTouch: true, viewport: { width: 1280, height: 900 } });

  test('the slider track keeps the browser default touch-action (no pan-y override)', async ({ page }) => {
    await page.goto(seed.gallerySliderUrl);
    await acceptConsentBanner(page);

    const track = page.locator('.avg-track').first();
    await expect(track).toBeVisible();
    const touchAction = await track.evaluate((el) => getComputedStyle(el).touchAction);
    expect(touchAction).not.toBe('pan-y');
  });

  test('a horizontal touch swipe scrolls the native slider track', async ({ page, context }) => {
    await page.goto(seed.gallerySliderUrl);
    await acceptConsentBanner(page);

    const track = page.locator('.avg-track').first();
    await expect(track).toBeVisible();
    const box = await track.boundingBox();
    const y = box.y + box.height / 2;
    // Swipe within the interior tile content, well clear of the
    // .avg-nav-prev/.avg-nav-next overlay buttons that sit right at the
    // track's left/right edges - a touch that starts on one of those
    // buttons doesn't produce a scroll gesture (confirmed while writing
    // this test: elementFromPoint at a near-edge x resolved to
    // .avg-nav-next, not track content, and scrollLeft never moved).
    const startX = box.x + box.width * 0.75;
    const endX = box.x + box.width * 0.25;

    const before = await track.evaluate((el) => el.scrollLeft);

    // Real, OS-level-equivalent touch input (not a synthetic DOM TouchEvent
    // dispatch, which native overflow-x scrolling ignores - only trusted
    // input drives the browser's own scroll physics) via the CDP protocol,
    // which hasTouch: true makes Chromium treat as genuine touch.
    const cdp = await context.newCDPSession(page);
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: startX, y }] });
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: (startX + endX) / 2, y }] });
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: endX, y }] });
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });

    await expect.poll(() => track.evaluate((el) => el.scrollLeft)).not.toBe(before);
  });
});
