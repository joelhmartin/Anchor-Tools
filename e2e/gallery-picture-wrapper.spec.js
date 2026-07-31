// @ts-check
const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');

/**
 * Gallery sizing survives <picture> wrapping.
 *
 * Anchor Optimize's WebP delivery ("picture_tags" mode) rewrites front-end
 * <img> tags into <picture><source><img> via an output buffer. It skips admin
 * and AJAX, so the gallery's editor preview never sees the wrapper and a
 * regression here looks correct right up until the page is viewed. That is how
 * the logo reel shipped ignoring its Item Max Height: the wrapper is
 * content-sized, so `max-height: 100%` on the img had no definite basis and
 * resolved to none, letting tall logos render at full size.
 *
 * No WordPress needed — this pins the CSS contract directly: gallery layout
 * must be byte-identical with and without the wrapper.
 */

const CSS = fs.readFileSync(
  path.join(__dirname, '..', 'anchor-gallery', 'assets', 'anchor-video-slider.css'),
  'utf8'
);

const ITEM_HEIGHT = 60;

// 200x100 stand-in — deliberately wider than tall and taller than ITEM_HEIGHT,
// so an unconstrained image is obvious in the measurements.
const IMG =
  'data:image/svg+xml;base64,' +
  Buffer.from(
    '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="100"><rect width="200" height="100" fill="red"/></svg>'
  ).toString('base64');

/** @param {boolean} wrapped */
function markup(wrapped) {
  const img = (cls) => `<img class="${cls}" src="${IMG}" alt="">`;
  const wrap = (inner) => (wrapped ? `<picture><source srcset="${IMG}">${inner}</picture>` : inner);

  return `<style>${CSS}</style>
    <div class="anchor-video-gallery avg-layout-logo-carousel" data-layout="logo_carousel"
         style="--avg-marquee-item-height: ${ITEM_HEIGHT}px; --avg-marquee-item-width: 150px">
      <div class="avg-marquee-row"><div class="avg-marquee"><div class="avg-marquee-group">
        <div class="avg-marquee-item" id="marquee">${wrap(img(''))}</div>
      </div></div></div>
    </div>

    <div class="anchor-video-gallery avg-layout-grid" data-layout="grid" style="width:400px">
      <div class="avg-tile"><div class="avg-tile-inner">
        <div class="avg-thumb" id="grid-thumb">${wrap(img('avg-thumb-img'))}</div>
      </div></div>
    </div>

    <div class="anchor-video-gallery avg-layout-grid avg-aspect-auto" data-layout="grid" style="width:400px">
      <div class="avg-tile"><div class="avg-tile-inner">
        <div class="avg-thumb" id="auto-thumb">${wrap(img('avg-thumb-img'))}</div>
      </div></div>
    </div>`;
}

/** @param {import('@playwright/test').Page} page */
async function measure(page, wrapped) {
  await page.setContent(markup(wrapped));
  return page.evaluate(() => {
    const box = (sel) => {
      const el = document.querySelector(sel);
      if (!el) throw new Error(`missing ${sel}`);
      const r = el.getBoundingClientRect();
      return { w: Math.round(r.width), h: Math.round(r.height) };
    };
    return {
      marqueeItem: box('#marquee'),
      marqueeImg: box('#marquee img'),
      gridThumb: box('#grid-thumb'),
      gridImg: box('#grid-thumb img'),
      autoThumb: box('#auto-thumb'),
      autoImg: box('#auto-thumb img'),
    };
  });
}

test('picture wrapping does not change gallery layout', async ({ page }) => {
  const plain = await measure(page, false);
  const wrapped = await measure(page, true);
  expect(wrapped).toEqual(plain);
});

test('logo reel honours Item Max Height when images are wrapped', async ({ page }) => {
  const wrapped = await measure(page, true);
  expect(wrapped.marqueeImg.h).toBeLessThanOrEqual(ITEM_HEIGHT);
  // Capped by height, not squashed: the 2:1 source keeps its ratio.
  expect(wrapped.marqueeImg.w).toBe(wrapped.marqueeImg.h * 2);
});

/**
 * The renderer writes desktop values into the style attribute, so the mobile
 * media query has to outrank an inline declaration to take effect at all.
 */
test('mobile item height overrides the inline desktop value', async ({ page }) => {
  await page.setViewportSize({ width: 400, height: 800 });
  await page.setContent(`<style>${CSS}</style>
    <div class="anchor-video-gallery avg-layout-logo-carousel" id="g"
         style="--avg-marquee-item-height: ${ITEM_HEIGHT}px; --avg-marquee-item-height-mobile: 30px">
      <div class="avg-marquee-row"><div class="avg-marquee"><div class="avg-marquee-group">
        <div class="avg-marquee-item" id="i"></div>
      </div></div></div>
    </div>`);

  const height = await page.evaluate(() =>
    Math.round(document.getElementById('i').getBoundingClientRect().height)
  );
  expect(height).toBe(30);
});
