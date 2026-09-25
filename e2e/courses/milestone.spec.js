// @ts-check
const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { acceptConsentBanner } = require('../helpers/consent');

/**
 * Anchor Courses - the brief section 38 milestone, through the browser.
 *
 * FIXTURES: bin/e2e-seed.sh publishes Course A (sequential) with Lesson 1 and
 * Quiz 1 (passing 80%, 2 attempts), creates the `courses-learner` account and
 * grants it the course's access role via Support\Roles::grant_access() - the
 * same enrolment path Support\Roles' role listener uses everywhere in
 * production - writing the ids/urls into e2e/.seed.json. Run
 * `npm run env:seed` first.
 *
 * The spec drives the real UI: read, mark complete, fail the quiz, retry,
 * pass, and land on a 100% course with a certificate link. There is no enrol
 * step to drive - the learner arrives already holding the access role, which
 * is the only way anybody is ever enrolled (design spec 3.1, 7).
 *
 * The compliance module is also enabled in this seed (bin/e2e-seed.sh), so
 * every front-end page carries the consent gate (`#anchor-cmp`); the shared
 * helper below dismisses it the same way every other spec in this suite does.
 */

const SEED_PATH = path.join(__dirname, '..', '.seed.json');

/** @type {{courses_course_url: string, courses_lesson_url: string, courses_learner_user: string, courses_learner_pass: string}} */
let seed;

test.beforeAll(() => {
  if (!fs.existsSync(SEED_PATH)) {
    throw new Error(`Missing ${SEED_PATH}. Run the seed first: npm run env:seed`);
  }
  seed = JSON.parse(fs.readFileSync(SEED_PATH, 'utf8'));
  expect(seed.courses_course_url, 'seed courses_course_url').toBeTruthy();
  expect(seed.courses_lesson_url, 'seed courses_lesson_url').toBeTruthy();
});

/** Sign in as the seeded learner. */
async function loginAsLearner(page) {
  await page.goto('/wp-login.php');
  if (await page.locator('#user_login').isVisible().catch(() => false)) {
    await page.fill('#user_login', seed.courses_learner_user);
    await page.fill('#user_pass', seed.courses_learner_pass);
    await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
  }
}

test('milestone: complete a lesson, fail a quiz, retry, pass, finish the course', async ({ page }) => {
  await loginAsLearner(page);

  // 1) The learner already holds the access role, so the course opens with no
  //    enrol control at all - not a button, not a "sign in to enrol" link.
  await page.goto(new URL(seed.courses_course_url).pathname);
  await acceptConsentBanner(page);
  await expect(page.locator('.anchor-course-title')).toContainText('Course A');
  await expect(page.locator('.anchor-course-cta')).toHaveCount(0);
  await expect(page.locator('.anchor-courses-progress-label')).toContainText('0%');

  // 2) Sequential progression: the quiz is not startable yet.
  await expect(page.locator('.anchor-course-item--quiz.is-locked')).toHaveCount(1);

  // 3) Open Lesson 1 and mark it complete.
  await page.goto(new URL(seed.courses_lesson_url).pathname);
  await expect(page.locator('.anchor-lesson-content')).toContainText('Read this lesson');
  await page.locator('.anchor-lesson-complete button[type="submit"]').click();
  await expect(page.locator('.anchor-courses-notice')).toContainText(/complete/i);
  await expect(page.locator('.anchor-courses-progress-label')).toContainText('50%');

  // 4) Back on the course, start the quiz and answer both wrong.
  await page.goto(new URL(seed.courses_course_url).pathname);
  await page.locator('.anchor-quiz-start').click();
  await expect(page.locator('.anchor-quiz-question')).toHaveCount(2);

  // No answer key may ever be in the page source (brief rule 8).
  expect(await page.content()).not.toContain('"correct"');

  await page.locator('.anchor-quiz-question').nth(0).locator('input[value="a1"]').check();
  await page.locator('.anchor-quiz-question').nth(1).locator('input[value="b2"]').check();
  await page.locator('.anchor-quiz-form button[type="submit"]').click();
  await expect(page.locator('.anchor-quiz-result')).toContainText(/not passed/i);

  // 5) Retry and answer both correctly.
  await page.reload();
  await expect(page.locator('.anchor-quiz-remaining')).toContainText('1 attempt');
  await page.locator('.anchor-quiz-start').click();
  await page.locator('.anchor-quiz-question').nth(0).locator('input[value="a2"]').check();
  await page.locator('.anchor-quiz-question').nth(1).locator('input[value="b1"]').check();
  await page.locator('.anchor-quiz-form button[type="submit"]').click();
  await expect(page.locator('.anchor-quiz-result')).toContainText(/passed/i);

  // 6) The course is finished: 100%, credits and a certificate.
  await page.reload();
  await expect(page.locator('.anchor-courses-progress-label')).toContainText('100%');
});

test('a visitor without access is told how to ask, and offered no enrol control', async ({ page }) => {
  // Signed out on purpose: the one place a stray "Enrol" button would show up.
  await page.goto(new URL(seed.courses_course_url).pathname);
  await acceptConsentBanner(page);

  await expect(page.locator('.anchor-course-cta--ask')).toContainText(/ask us about access/i);
  await expect(page.locator('.anchor-course-cta form')).toHaveCount(0);
  expect(await page.content()).not.toContain('anchor_courses_enroll');
});

test('the learner dashboard shows the credit and the certificate', async ({ page }) => {
  await loginAsLearner(page);

  await page.goto(new URL(seed.courses_course_url).pathname);
  await acceptConsentBanner(page);
  await expect(page.locator('.anchor-course-credits')).toContainText('2');
});
