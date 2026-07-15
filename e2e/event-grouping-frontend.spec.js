// @ts-check
const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');

/**
 * Front-end "choose your date" presentation (Phase 2, Task 2.4): a group
 * PARENT single-event page renders a "choose a date" picker over its live
 * children INSTEAD OF its own registration form; a group CHILD page keeps
 * its own booking UI and gets an "other dates" sibling nav pointing at its
 * siblings + back to the parent. Public, unauthenticated pages — no login
 * required.
 *
 * FIXTURES: bin/e2e-seed.sh (Task 2.3) creates (or reuses) an OFFERING parent
 * reconciled into >=2 live CHILD event posts — `offering_event_id`/
 * `offering_event_url` in e2e/.seed.json. Run the seed first:
 * `npm run env:seed`.
 *
 * NOTE: the live child COUNT is read dynamically from the rendered picker
 * rather than hardcoded — event-grouping-authoring.spec.js (Task 2.3) edits
 * this SAME shared fixture (2 dates -> 3) as part of testing the offering
 * repeater, and file order in a full suite run puts that spec ahead of this
 * one alphabetically, so this fixture's live count is not guaranteed to stay
 * at the seed's original 2 across a full run.
 *
 * NOTE: this event's post_content can carry an auto-appended
 * `[event_registration]` shortcode (Module::maybe_append_registration_shortcode(),
 * a pre-existing Task 1.x behavior unrelated to grouping) if the
 * event-grouping-authoring spec edited/saved it through the classic admin
 * metabox earlier in the same run — that shortcode re-renders
 * render_registration_form() a second time inside the content. This spec
 * therefore asserts presence/absence of booking UI with `.first()` /
 * `count() >= 1` rather than an exact form count, so it stays robust to that
 * unrelated content-authoring side effect either way.
 */

const SEED_PATH = path.join(__dirname, '.seed.json');

/** @type {{ offering_event_id: number, offering_event_url: string }} */
let seed;

test.beforeAll(() => {
  if (!fs.existsSync(SEED_PATH)) {
    throw new Error(`Missing ${SEED_PATH}. Run the seed first: npm run env:seed`);
  }
  seed = JSON.parse(fs.readFileSync(SEED_PATH, 'utf8'));
  expect(seed.offering_event_id, 'seed offering_event_id').toBeTruthy();
  expect(seed.offering_event_url, 'seed offering_event_url').toBeTruthy();
});

test('group parent page: shows the choose-a-date list with child dates + register links, no direct booking form', async ({ page }) => {
  const resp = await page.goto(new URL(seed.offering_event_url).pathname);
  expect(resp && resp.status() !== 404, 'Expected the seeded offering event to exist.').toBeTruthy();

  const picker = page.locator('.anchor-event-choose-date');
  await expect(picker).toBeVisible();
  await expect(picker.locator('.anchor-event-choose-date-title')).toHaveText('Choose a date');

  const rows = picker.locator('.anchor-event-choose-date-row');
  const rowCount = await rows.count();
  expect(rowCount, 'the offering parent must have at least its 2 seeded live dates').toBeGreaterThanOrEqual(2);

  // Every row links to its own (distinct) child event, never the parent, and
  // has a visible register/details CTA.
  const parentPath = new URL(seed.offering_event_url).pathname;
  const hrefs = await rows.locator('.anchor-event-choose-date-link').evaluateAll(
    (els) => els.map((el) => new URL(el.getAttribute('href'), window.location.href).pathname)
  );
  expect(hrefs).toHaveLength(rowCount);
  for (const href of hrefs) {
    expect(href).toBeTruthy();
    expect(href).not.toBe(parentPath);
  }
  expect(new Set(hrefs).size, 'every row must link to a distinct child').toBe(rowCount);

  for (let i = 0; i < rowCount; i++) {
    await expect(rows.nth(i).locator('.anchor-event-choose-date-cta')).toBeVisible();
  }

  // The parent itself is a container — it must never render its own direct
  // registration form outside the picker.
  await expect(page.locator('.anchor-event-content > form.anchor-event-registration')).toHaveCount(0);
});

test('group child page: keeps its own booking UI and lists its live sibling(s) + a link back to all dates', async ({ page }) => {
  await page.goto(new URL(seed.offering_event_url).pathname);
  const parentRows = page.locator('.anchor-event-choose-date .anchor-event-choose-date-row');
  const liveChildCount = await parentRows.count();
  expect(liveChildCount).toBeGreaterThanOrEqual(2);

  const childHref = await parentRows.first().locator('.anchor-event-choose-date-link').getAttribute('href');
  expect(childHref).toBeTruthy();

  const resp = await page.goto(childHref);
  expect(resp && resp.status() !== 404, 'Expected the child event page to exist.').toBeTruthy();

  // Own booking UI still renders (at least one registration form on the page).
  await expect(page.locator('form.anchor-event-registration').first()).toBeVisible();

  // "Other dates" sibling nav: every OTHER live child is listed, the child's
  // OWN link is never listed, and it links back to the parent.
  const otherDates = page.locator('.anchor-event-other-dates');
  await expect(otherDates).toBeVisible();
  await expect(otherDates.locator('.anchor-event-other-dates-title')).toHaveText('Other dates');

  const siblingRows = otherDates.locator('.anchor-event-choose-date-row');
  await expect(siblingRows).toHaveCount(liveChildCount - 1);

  const childPath = new URL(childHref, page.url()).pathname;
  const siblingHrefs = await siblingRows.locator('.anchor-event-choose-date-link').evaluateAll(
    (els) => els.map((el) => new URL(el.getAttribute('href'), window.location.href).pathname)
  );
  expect(siblingHrefs).not.toContain(childPath);

  const allDatesLink = otherDates.locator('.anchor-event-other-dates-link');
  await expect(allDatesLink).toBeVisible();
  await expect(allDatesLink).toHaveAttribute('href', new RegExp(new URL(seed.offering_event_url).pathname + '/?$'));
});

test('series archive for the group: collapses to one parent row (not one row per child)', async ({ page }) => {
  const resp = await page.goto(new URL(seed.offering_event_url).pathname);
  expect(resp && resp.status() !== 404).toBeTruthy();

  // Discover the series archive link the way a visitor would — the page
  // itself doesn't surface one directly, so read the term slug via the
  // event's own choose-date children count and hit /series/group-<parent_id>/
  // (Occurrences::assign_series()'s stable slug convention).
  const seriesUrl = `/series/group-${seed.offering_event_id}/`;
  const archiveResp = await page.goto(seriesUrl);
  expect(archiveResp && archiveResp.status() !== 404, 'Expected the group series archive to exist.').toBeTruthy();

  const groupRow = page.locator('.anchor-event-series__item--group');
  await expect(groupRow).toHaveCount(1);
  await expect(groupRow.locator('.anchor-event-series__link')).toHaveAttribute(
    'href',
    new RegExp(new URL(seed.offering_event_url).pathname + '/?$')
  );
  await expect(groupRow.locator('.anchor-event-series__availability')).toContainText('dates available');

  // No standalone row for either child — the group renders as ONE row.
  const allItems = page.locator('.anchor-event-series__item');
  await expect(allItems).toHaveCount(1);
});
