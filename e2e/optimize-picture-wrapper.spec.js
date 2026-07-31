// @ts-check
const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');

/**
 * Anchor Optimize's <picture> wrapper must not participate in layout.
 *
 * The rewriter turns every front-end <img> into <picture class="ao-picture">…
 * <img></picture>, which changes the box tree: under a flex or grid parent the
 * wrapper becomes the item instead of the image, and its content-sized height
 * strips the basis out from under percentage heights on the image. It skips
 * admin and AJAX, so none of that is visible in any module's editor preview.
 *
 * Both the marker class and the reset rule are read out of the PHP source, so
 * this fails if either is renamed or dropped rather than silently passing
 * against a rule that no longer ships.
 */

const REWRITER = fs.readFileSync(
  path.join(__dirname, '..', 'anchor-optimize', 'includes', 'class-frontend-rewriter.php'),
  'utf8'
);

/** The exact CSS the module prints into wp_head. */
const resetMatch = REWRITER.match(/<style id="anchor-optimize-picture-reset">([^<]+)<\/style>/);
/** The exact wrapper markup the module emits. */
const wrapperMatch = REWRITER.match(/return '(<picture[^']*)'/);

test('module still ships a wrapper reset and a marked wrapper', () => {
  expect(resetMatch, 'wrapper reset <style> not found in rewriter').not.toBeNull();
  expect(wrapperMatch, 'wrapper markup not found in rewriter').not.toBeNull();
  expect(wrapperMatch[1]).toContain('class="ao-picture"');
  expect(resetMatch[1]).toContain('display:contents');
});

const RESET_CSS = resetMatch ? resetMatch[1] : '';
const IMG =
  'data:image/svg+xml;base64,' +
  Buffer.from(
    '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="100"><rect width="200" height="100" fill="red"/></svg>'
  ).toString('base64');

/**
 * The three shapes that actually break, measured rather than assumed — plain
 * CSS, no module styles, so this covers every module rather than the ones we
 * happened to audit. Notably a plain block parent, and a flex parent left on
 * the default align-items: stretch, are NOT affected; the damage needs the
 * wrapper to become an item whose own size the image then depends on.
 */
const HARNESS = `
  <style>
    ${RESET_CSS}
    /* 1. non-stretch flex parent: wrapper is content-sized, so the image's
          percentage cap loses its basis. This is the logo-reel bug. */
    .flex-parent { display: flex; align-items: center; height: 60px; width: 300px; }
    .flex-parent img { max-height: 100%; width: auto; }
    /* 2. grid placement on the image: the wrapper becomes the grid item. */
    .grid-parent { display: grid; grid-template-columns: 1fr 1fr; width: 300px; }
    .grid-parent img { grid-column: 1 / -1; width: 100%; }
    /* 3. flex sizing on the image: likewise, the wrapper becomes the item. */
    .flex-grow { display: flex; width: 300px; height: 60px; }
    .flex-grow img { flex: 1; min-width: 0; }
  </style>
  <div class="flex-parent">__FLEX__</div>
  <div class="grid-parent"><div></div>__GRID__</div>
  <div class="flex-grow">__GROW__</div>`;

/** @param {boolean} wrapped */
function markup(wrapped) {
  const img = `<img src="${IMG}" alt="">`;
  const wrap = (i) =>
    wrapped ? `<picture class="ao-picture"><source srcset="${IMG}">${i}</picture>` : i;
  return HARNESS.replace('__FLEX__', wrap(img))
    .replace('__GRID__', wrap(img))
    .replace('__GROW__', wrap(img));
}

/** @param {import('@playwright/test').Page} page */
async function measure(page, wrapped) {
  await page.setContent(markup(wrapped));
  return page.evaluate(() => {
    const box = (sel) => {
      const r = document.querySelector(sel).getBoundingClientRect();
      return { w: Math.round(r.width), h: Math.round(r.height) };
    };
    return {
      flexImg: box('.flex-parent img'),
      gridImg: box('.grid-parent img'),
      growImg: box('.flex-grow img'),
    };
  });
}

test('wrapping an image does not change how it lays out', async ({ page }) => {
  const plain = await measure(page, false);
  const wrapped = await measure(page, true);
  expect(wrapped).toEqual(plain);
  // Guard the specific failure that shipped: the percentage cap must survive.
  expect(wrapped.flexImg.h).toBeLessThanOrEqual(60);
});
