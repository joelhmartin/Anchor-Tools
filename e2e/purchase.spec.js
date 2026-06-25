// @ts-check
const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');

/**
 * Critical purchase happy path:
 *   event page → pick a paid GA ticket → AJAX add-to-cart → classic checkout →
 *   fill per-seat attendee fields → pay with Cash on Delivery → order received →
 *   attendee appears on the event roster.
 *
 * The fixture (event id + permalink) comes from e2e/.seed.json, produced by
 * bin/e2e-seed.sh. Run the seed before this spec (npm run env:seed).
 */

const SEED_PATH = path.join(__dirname, '.seed.json');

/** @type {{ event_id: number, event_url: string, product_id: number }} */
let seed;

// A unique attendee we can later find on the roster.
const ATTENDEE = {
  name: 'Ada Attendee E2E',
  email: 'ada.attendee.e2e@example.com',
  phone: '555-0100',
};

test.beforeAll(() => {
  if (!fs.existsSync(SEED_PATH)) {
    throw new Error(
      `Missing ${SEED_PATH}. Run the seed first: npm run env:seed`
    );
  }
  seed = JSON.parse(fs.readFileSync(SEED_PATH, 'utf8'));
  expect(seed.event_url, 'seed event_url').toBeTruthy();
  expect(seed.event_id, 'seed event_id').toBeTruthy();
});

/** Log into wp-admin using the wp-env default credentials (admin / password). */
async function wpAdminLogin(page) {
  await page.goto('/wp-login.php');
  await page.fill('#user_login', 'admin');
  await page.fill('#user_pass', 'password');
  await Promise.all([
    page.waitForURL(/wp-admin/),
    page.click('#wp-submit'),
  ]);
}

test('buy a paid ticket and land on the roster', async ({ page }) => {
  // ----------------------------------------------------------------------
  // 1) Event-page storefront → choose GA qty 1 → AJAX add-to-cart.
  // ----------------------------------------------------------------------
  await page.goto(seed.event_url);

  const tickets = page.locator('.anchor-event-tickets');
  await expect(tickets, 'ticket block renders on the event page').toBeVisible();

  // GA tier qty input (one per active paid tier; matched by data-tier).
  const gaQty = tickets.locator(
    '.anchor-event-ticket-row[data-tier="ga"] input.anchor-event-ticket-qty'
  );
  await expect(gaQty, 'GA quantity input present (tier on sale, seats left)').toBeVisible();
  await gaQty.fill('1');

  await tickets.locator('[data-add-to-cart]').click();

  // On AJAX success the storefront renders a "Checkout" link into the live
  // status region (.anchor-event-cart-msg .anchor-event-checkout).
  const checkoutLink = tickets.locator('.anchor-event-cart-msg .anchor-event-checkout');
  await expect(checkoutLink, 'add-to-cart AJAX succeeded').toBeVisible();

  // ----------------------------------------------------------------------
  // 2) Classic WooCommerce checkout → billing + per-seat attendee fields.
  // ----------------------------------------------------------------------
  // Visit the cart first: asserts the AJAX add actually persisted to the
  // session the browser carries, and surfaces what's there for diagnostics.
  await page.goto('/cart/');
  const cartText = (await page.locator('body').innerText()).slice(0, 800);
  console.log('=== CART BODY ===\n' + cartText + '\n=== /CART BODY ===');

  await page.goto('/checkout/');
  const checkoutText = (await page.locator('body').innerText()).slice(0, 1200);
  console.log('=== CHECKOUT BODY ===\n' + checkoutText + '\n=== /CHECKOUT BODY ===');

  // Wait for the classic checkout form to hydrate.
  await expect(page.locator('#billing_first_name')).toBeVisible();

  await page.fill('#billing_first_name', 'Buyer');
  await page.fill('#billing_last_name', 'Person');

  // Country / state are <select> elements (enhanced by select2); set the
  // underlying value, then let WC's update_order_review settle.
  await page.selectOption('#billing_country', 'US').catch(() => {});
  await page.fill('#billing_address_1', '500 Commerce St');
  await page.fill('#billing_city', 'Dallas');
  await page.selectOption('#billing_state', 'TX').catch(async () => {
    // Some locales render state as a free-text input.
    await page.fill('#billing_state', 'TX').catch(() => {});
  });
  await page.fill('#billing_postcode', '75201');
  await page.fill('#billing_phone', '555-0123');
  await page.fill('#billing_email', 'buyer.person.e2e@example.com');

  // Plugin per-seat attendee fields, injected after the customer details.
  // Field names follow anchor_attendees[<cart_item_key>][<seat>][name|email|phone].
  const attendees = page.locator('#anchor-event-attendees');
  await expect(attendees, 'attendee block rendered on classic checkout').toBeVisible();

  await attendees.locator('input[name$="[name]"]').first().fill(ATTENDEE.name);
  await attendees.locator('input[name$="[email]"]').first().fill(ATTENDEE.email);
  await attendees.locator('input[name$="[phone]"]').first().fill(ATTENDEE.phone);

  // ----------------------------------------------------------------------
  // 3) Select Cash on Delivery and place the order.
  // ----------------------------------------------------------------------
  const cod = page.locator('#payment_method_cod');
  await expect(cod, 'Cash on Delivery available').toBeVisible();
  await cod.check();

  // WC submits the checkout via AJAX then redirects to order-received.
  await page.locator('#place_order').click();

  await expect(
    page.locator('.woocommerce-order-received, .woocommerce-thankyou-order-received')
      .or(page.getByText(/order received|thank you\. your order/i))
      .first(),
    'order received / thank-you page reached'
  ).toBeVisible({ timeout: 60000 });

  // ----------------------------------------------------------------------
  // 4) Attendee shows up on the event roster (wp-admin).
  // ----------------------------------------------------------------------
  await wpAdminLogin(page);
  await page.goto(
    `/wp-admin/edit.php?post_type=event&page=anchor-event-roster&event_id=${seed.event_id}`
  );

  await expect(
    page.getByText(ATTENDEE.name, { exact: false }),
    'attendee name appears on the roster'
  ).toBeVisible();
  await expect(
    page.getByText(ATTENDEE.email, { exact: false }),
    'attendee email appears on the roster'
  ).toBeVisible();
});
