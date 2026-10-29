# Anchor Checkout Questions

Ask buyers questions at WooCommerce checkout. Answers show on the order screen and in the New order email.

## What it does

A **question set** (WooCommerce > Checkout Questions) holds a list of questions and says where they apply. When a cart matches a set, the classic checkout shows that set's questions after the order notes. Required questions block the order with a normal WooCommerce error naming the question. The answers are saved on the order, shown in the "Checkout questions" box on the order screen, and added to the New order email for questions flagged **Include in admin email**.

Question rows are the shared `Anchor_Questions` shape (`key, label, type, options, required, admin_email`; types `text, textarea, select, multiselect, checkbox`), edited with the shared editor in `includes/questions/`. The same editor and renderer back Anchor Events' attendee questions.

## Where a set applies

A set applies to a cart line that is one of the set's **products** or is in one of its **product categories** (variations match through their parent; child categories count). Rules:

- The same set reached twice (by product and by category, or by two cart lines) is asked once and stored once.
- **Event tickets are excluded from category matching.** A ticket line matches a set only when the ticket product is picked explicitly under Products. This keeps a "Courses" category set from asking course questions of an event ticket in the same cart.
- Two sets may both use a question key such as `phone`; answers are stored and posted per set (`anchor_cq[<set_id>][<key>]`) and never collide.

## Classic checkout only, and the block guard

The questions render on the classic (shortcode) checkout. The block checkout and the Store API never show them. So that a required question can never be silently skipped, `Checkout::guard_block_checkout()` runs on `woocommerce_store_api_checkout_update_order_from_request` (and the older `woocommerce_blocks_checkout_order_processed`) and, when a set with a required question applies to the order, refuses the order with a Store API `RouteException` asking for the classic checkout. If that exception class is unavailable it puts the order on hold with a private note listing the unanswered question labels instead. An admin notice warns when the checkout page uses the checkout block.

## Storage

Order meta `_anchor_cq_answers`: a list of entries `{ set_id, set_title, questions, answers }`. Each entry **snapshots the questions as asked**, so editing or deleting a set later never rewrites an existing order, and a resent New order email still shows what the buyer was shown. Set data lives on the `anchor_question_set` post type (`_anchor_cq_questions`, `_anchor_cq_product_ids`, `_anchor_cq_category_ids`). Answer values are never written to logs.

## The email rule

Only questions flagged **Include in admin email** are added to the admin New order email. Email is plain, unencrypted mail, so the editor shows the help text under that checkbox: "Answers are sent by plain email. Don't tick this for health information." HTML output is escaped; the plain-text email prints answers raw.

## Express-checkout buttons (spec "Calls settled" item 1)

Question: do Stripe and PayPal express buttons reach `WC_Checkout::process_checkout()`, which is what fires `woocommerce_after_checkout_validation` (where this module validates and `woocommerce_checkout_create_order` where it stores)?

Source checked: `woocommerce-gateway-stripe` 11.1.1 and `woocommerce-paypal-payments` 4.2.0 from wordpress.org (read only, never run). Paths below are inside each plugin.

**Result: they do NOT all reach it. Stripe express never does; PayPal does except in its opt-in "Pay Now" mode.**

### Stripe (`woocommerce-gateway-stripe`): bypasses process_checkout, but the block guard catches it

- Express checkout (Apple Pay / Google Pay / Link buttons) creates the order by POSTing to the Store API: `build/express-checkout.js` method `expressCheckoutECECreateOrder` calls `postToStoreApi("/wc/store/v1/checkout", ..., {"X-WCSTRIPE-EXPRESS-CHECKOUT": true})` (minified, line 1).
- The server recognises it in `includes/payment-methods/class-wc-stripe-express-checkout-helper.php:2174-2207` (`is_express_checkout_context()` / `is_request_to_store_api()`, route prefix `/wc/store/v1/checkout`).
- Stripe's own validation hook for it is a stand-in, not the WooCommerce one: `includes/payment-methods/class-wc-stripe-express-checkout-custom-fields.php:53-56` hooks `woocommerce_store_api_checkout_update_order_from_request`, and `:84-96` fires `wc_stripe_express_checkout_after_checkout_validation` with the comment "To be used as a stand-in for the `woocommerce_after_checkout_validation` action".
- No call to `process_checkout()` or `woocommerce_after_checkout_validation` anywhere in the Stripe express path (grep of `includes/` finds neither outside unrelated names).
- Effect here: because Store API checkout fires `woocommerce_store_api_checkout_update_order_from_request`, our `guard_block_checkout()` (hooked there, `src/Frontend/Checkout.php:27`) runs for Stripe express orders. A cart that matches a set with a required question is refused with the "use the classic checkout" message (fail closed). A cart matching only optional-question sets is accepted with no answers stored.

### PayPal (`woocommerce-paypal-payments`): reaches process_checkout on checkout; bypasses it in "Pay Now" mode

Reaches it (or its validator):
- PayPal button on the checkout page: `modules/ppcp-button/src/Endpoint/ValidateCheckoutEndpoint.php:71` calls `CheckoutFormValidator::validate()`, which extends `WC_Checkout` and calls `validate_checkout()` at `modules/ppcp-button/src/Validation/CheckoutFormValidator.php:47`; that fires `woocommerce_after_checkout_validation`. The order is then placed by the normal checkout submit.
- Apple Pay: `modules/ppcp-applepay/src/Assets/ApplePayButton.php:204` calls `WC()->checkout()->process_checkout()`. Its request carries no `anchor_cq` fields, so a required question fails validation and blocks the order (fail closed).
- Product/cart-page PayPal buttons with Pay Now off: the approval only stores the PayPal order and the buyer continues on the checkout page (`ApproveOrderEndpoint.php:172-176` returns plain success when `applies()` is false), so the classic form and `process_checkout()` run.

Bypasses it:
- **Pay Now mode.** `modules/ppcp-order-endpoints/src/Endpoint/ApproveOrderEndpoint.php:172-173` calls `PayNowOrderHandler::pay()` when `PayNowOrderHandler::applies()` is true (`modules/ppcp-order-endpoints/src/Helper/PayNowOrderHandler.php:34-37`: request has `should_create_wc_order`, the `enable_pay_now` setting is on, and the page is not the checkout). `pay()` (`:49-50`) calls `WooCommerceOrderCreator::create_from_paypal_order()`, which builds the order with `wc_create_order()` (`modules/ppcp-order-endpoints/src/Helper/WooCommerceOrderCreator.php:88`) and fires only `woocommerce_paypal_payments_woocommerce_order_created_from_cart` (`:115`), then `process_payment()`. Neither `woocommerce_after_checkout_validation`, `woocommerce_checkout_create_order` nor the Store API hook runs, so **no questions are asked, validated or stored**.
- `enable_pay_now` defaults to false (`modules/ppcp-settings/src/Data/SettingsModel.php:101`); a store owner must switch it on.
- The App Switch cross-browser return (`modules/ppcp-button/src/ButtonModule.php:185-187`) also creates the order with `WooCommerceOrderCreator` and then sends the buyer to the order-pay URL.

### What this means

- Stripe express: safe for required questions (refused, buyer sent to classic checkout). Not safe for optional-only sets (order placed, no answers).
- PayPal with Pay Now ON, or App Switch: an order can be placed with required answers missing. Do not enable PayPal "Pay Now" on a store whose products have required checkout questions until a hook on `woocommerce_paypal_payments_woocommerce_order_created_from_cart` (or an equivalent guard) is added. No code was added for this; it is an owner decision.
