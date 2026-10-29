// @ts-check
const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { acceptConsentBanner, STRICT_POSTURE_TIMEZONE } = require('./helpers/consent');

/**
 * Anchor Checkout Questions, end to end:
 *   1. classic checkout asks the seeded "Course intake" set (category Courses),
 *      refuses a blank required answer, takes a filled one, and the order screen
 *      shows it in the "Checkout questions" box;
 *   2. the real set edit screen saves a question (Required + Include in admin
 *      email) and a category, and a reload shows them.
 *
 * Needs the seed (`npm run env:seed`) for cq_product_id / cq_category_id in
 * e2e/.seed.json.
 */

const SEED_PATH = path.join(__dirname, '.seed.json');

/** @type {{ cq_product_id: number, cq_set_id: number, cq_category_id: number }} */
let seed;

test.beforeAll(() => {
  if (!fs.existsSync(SEED_PATH)) {
    throw new Error(`Missing ${SEED_PATH}. Run the seed first: npm run env:seed`);
  }
  seed = JSON.parse(fs.readFileSync(SEED_PATH, 'utf8'));
  expect(seed.cq_product_id, 'seed cq_product_id').toBeTruthy();
  expect(seed.cq_category_id, 'seed cq_category_id').toBeTruthy();
});

async function wpAdminLogin(page) {
  await page.goto('/wp-login.php');
  if (!(await page.locator('#user_login').isVisible().catch(() => false))) {
    return;
  }
  await page.fill('#user_login', 'admin');
  await page.fill('#user_pass', 'password');
  await Promise.all([page.waitForURL(/wp-admin/), page.click('#wp-submit')]);
}

test.use({ timezoneId: STRICT_POSTURE_TIMEZONE });

test('checkout asks the Courses set, enforces the required answer, and the order shows it', async ({ page }) => {
  // Guest buyer: add the course product, then open classic checkout.
  await page.goto('/?add-to-cart=' + seed.cq_product_id);
  await page.goto('/checkout/');
  await acceptConsentBanner(page);
  await expect(page.locator('#billing_first_name')).toBeVisible();

  const block = page.locator('.anchor-cq-set', { hasText: 'Course intake' });
  await expect(block, 'question set renders at checkout').toBeVisible();
  const licence = block.locator('input[type="text"]');
  await expect(block.getByText('Licence number')).toBeVisible();

  await page.fill('#billing_first_name', 'Buyer');
  await page.fill('#billing_last_name', 'Person');
  await page.selectOption('#billing_country', 'US').catch(() => {});
  await page.fill('#billing_address_1', '500 Commerce St');
  await page.fill('#billing_city', 'Dallas');
  await page.selectOption('#billing_state', 'TX').catch(async () => {
    await page.fill('#billing_state', 'TX').catch(() => {});
  });
  await page.fill('#billing_postcode', '75201');
  await page.fill('#billing_phone', '555-0123');
  await page.fill('#billing_email', 'cq.buyer.e2e@example.com');
  await page.waitForLoadState('networkidle').catch(() => {});

  const codLabel = page.locator('label[for="payment_method_cod"]');
  await expect(codLabel).toBeVisible();
  await codLabel.click();
  await expect(page.locator('#payment_method_cod')).toBeChecked();

  // Blank required answer: WooCommerce refuses and names the question.
  await page.locator('#place_order').click();
  await expect(page.locator('.woocommerce-error')).toContainText('Licence number');
  await expect(page).toHaveURL(/\/checkout\//);

  // Filled: the order goes through.
  const answer = `LIC-${Date.now()}`;
  await licence.fill(answer);
  await page.locator('#place_order').click();
  await expect(
    page.locator('.woocommerce-order-received, .woocommerce-thankyou-order-received')
      .or(page.getByText(/order received|thank you\. your order/i))
      .first()
  ).toBeVisible({ timeout: 60000 });
  const orderId = new URL(page.url()).pathname.match(/order-received\/(\d+)/)?.[1];
  expect(orderId, 'order id in the order-received URL').toBeTruthy();

  // Admin order screen (HPOS or legacy), "Checkout questions" box.
  await wpAdminLogin(page);
  await page.goto(`/wp-admin/admin.php?page=wc-orders&action=edit&id=${orderId}`);
  if (!(await page.locator('#anchor-cq-answers').count())) {
    await page.goto(`/wp-admin/post.php?post=${orderId}&action=edit`);
  }
  const box = page.locator('#anchor-cq-answers.postbox');
  await expect(box).toBeVisible();
  await expect(box).toContainText('Course intake');
  await expect(box).toContainText('Licence number');
  await expect(box).toContainText(answer);
});

test('the set edit screen saves a question, its flags and a category', async ({ page }) => {
  await wpAdminLogin(page);
  await page.goto('/wp-admin/post-new.php?post_type=anchor_question_set');

  const title = 'E2E UI set';
  await page.fill('#title', title);

  const editor = page.locator('.anchor-questions-editor');
  await expect(editor).toBeVisible();
  await editor.locator('.anchor-question-add').click();
  const row = editor.locator('.anchor-question-row').first();
  await row.locator('input[name$="[label]"]').fill('Favourite colour');
  await row.locator('.anchor-question-type').selectOption('select');
  await row.locator('textarea[name$="[options]"]').fill('Red\nBlue');
  await row.locator('input[name$="[required]"]').check();
  await row.locator('input[name$="[admin_email]"]').check();

  await page.locator('.anchor-cq-categories label', { hasText: 'Courses' }).locator('input').check();

  await Promise.all([
    page.waitForURL(/post\.php\?post=\d+&action=edit/, { timeout: 30000 }),
    page.locator('#publish').click(),
  ]);
  const setId = new URL(page.url()).searchParams.get('post');

  try {
    await page.reload();
    const saved = page.locator('.anchor-questions-editor .anchor-question-row');
    await expect(saved).toHaveCount(1);
    const r = saved.first();
    await expect(r.locator('input[name$="[label]"]')).toHaveValue('Favourite colour');
    await expect(r.locator('.anchor-question-type')).toHaveValue('select');
    await expect(r.locator('textarea[name$="[options]"]')).toHaveValue('Red\nBlue');
    await expect(r.locator('input[name$="[required]"]')).toBeChecked();
    await expect(r.locator('input[name$="[admin_email]"]')).toBeChecked();
    await expect(
      page.locator('.anchor-cq-categories label', { hasText: 'Courses' }).locator('input')
    ).toBeChecked();
  } finally {
    // Never leave a second required set asking Courses buyers extra questions:
    // trash it (a trashed set is not published, so it never matches a cart).
    await page.goto(`/wp-admin/post.php?post=${setId}&action=edit`);
    const trash = await page.locator('#delete-action a.submitdelete').getAttribute('href');
    await page.goto(trash);
    await page.waitForURL(/edit\.php\?post_type=anchor_question_set/);
  }
});
