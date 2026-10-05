# Anchor Agreements — Design

**Date:** 2026-10-01
**Status:** Part 1 (buyer experience) reviewed and approved in chat. Parts 2 (admin) and 3 (data & security) were written after the conversation ended and have **not** been reviewed. Read them before building.
**First site:** TMJ Therapy Centre International (tmjtherapycentre.com). It replaces WP E-Signature + "WooCommerce Digital Signature" for course purchases.
**Home:** a new Anchor Tools module, `anchor-agreements/`, built so any Anchor site can switch it on.

---

## Why

WP E-Signature gives two choices, and both are bad:

- **`before_checkout`**: the document is pushed at the buyer before they've even finished checkout.
- **`after_checkout`**, which TMJ uses: the card is charged and the buyer is then redirected to a signing page. Nothing enforces the signature. The order is already `processing`, so closing the tab leaves a paid, unsigned seat.

(Measured 2026-10-01: 83 signed docs from 64 people. Every paid course order since July happens to be signed, but by luck, not by design. The plugin also looped once and created 4,656 junk "awaiting" docs for a single buyer.)

**What we want:** a required checkbox directly above **Place Order**. Clicking it opens the document in a pop-up where the buyer signs, either by drawing or with a generated signature. The order can't be placed until every required document is signed, and each signature is logged with an audit trail.

## Decisions taken in chat

| Question | Decision |
|---|---|
| Signature method | **Draw** (canvas pad) **and Generate** (typed name rendered in a script font the buyer chooses). Must work well on mobile. |
| Which products | **Optional, per product.** Each product has a "Require signature" switch, off by default. |
| Which document | **Per product**, falling back to a **site-wide default** document. A library of documents. |
| Buyer's copy | **Link in the customer emails + a "Signed documents" list for the account.** The signed copy has a **Print / Save as PDF** button (browser print). A server-generated PDF attachment is a possible later add-on. |
| Architecture | **Option A**: own module, own signatures table, versioned documents, images stored in the DB (not uploads). |
| Staff notification | A "**Signed:** <doc> – <name>, order #<n>" email, replacing the old "viewed" emails. |

---

## Part 1 — Buyer experience (approved)

**Checkout.** When any cart item requires an agreement, a box renders at `woocommerce_review_order_before_submit` (fired by both classic checkout and FunnelKit's `payment-*.php` templates):

> ☐ **I have read and signed the Cancellation Policy** · *Read & sign*

- The box can't be ticked by hand. Clicking it, or "Read & sign", opens the modal. It ticks itself once every required document is signed.
- With **two or more** documents it reads "I have read and signed 2 required agreements" and the modal steps through them ("1 of 2").
- Placing the order without signing gives a standard WooCommerce error notice ("Please read and sign the Cancellation Policy before placing your order.") and the modal reopens.

**Modal.** Full-screen on phones with a sticky action bar; a centred dialog (max 720px) on desktop. Contents:

1. The document title, its version date and the full text (scrollable).
2. **Full legal name**, pre-filled from the billing first and last name when present.
3. Two tabs:
   - **Draw**: a canvas filling the width, scaled for `devicePixelRatio`, `touch-action:none`, with a Clear button.
   - **Generate**: the name rendered in four bundled script fonts. The buyer taps one, and it re-renders as the name is edited.
4. ☐ *I agree that this electronic signature is the legal equivalent of my handwritten signature.*
5. **Sign & continue.** Disabled until there is a name, a non-empty signature and the consent box is ticked.

**After checkout.**
- Customer order emails (processing / completed / on-hold / invoice) gain a "Your signed agreements" block with a link to each signed copy.
- **Signed copy page:** `/signed-agreement/{token}/`. The token is a UUID v4, the page is `noindex` and never cached, and it has a print stylesheet. It shows the document text exactly as signed, the signature image, the signer's name, the date and time, the order number and a short signature ID, plus a **Print / Save as PDF** button.
- **Account list:** a `signed-documents` My Account endpoint, **plus** a `[anchor_signed_agreements]` shortcode. ⚠️ TMJ's `/my-account/` page has **no `[woocommerce_my_account]` shortcode**, so WooCommerce account endpoints draw nothing there. TMJ must use the shortcode.

**Edge cases.**
- Signed but cart abandoned: the row stays unattached and is purged after **30 days**.
- Billing name changed after signing: the order is still allowed. The signature keeps the name typed in the modal.
- **Payment fails and the buyer retries:** WooCommerce reuses the same order (`order_awaiting_payment`), as order 1119696 did. A signature still counts if it was signed within the last **24 h** and is either unattached or already attached to *that* order.

---

## Part 2 — Admin (not yet reviewed)

- **Documents**: a non-public CPT `anchor_agreement` (title + editor) under its own **Agreements** menu, capability `manage_woocommerce`.
- **Settings** (Agreements → Settings). Every business-facing value is here, never hard-coded:
  - **Documents:** the default document.
  - **Staff notifications:** an on/off switch, **recipients** (comma-separated, falling back to the admin email), and a **subject template** (`Signed: {documents} – {name}, order #{order}`).
  - **Customer:** an on/off switch for signed-copy links in customer order emails, the checkout **checkbox label** (`I have read and signed the {title}`), and the **consent sentence**.
  - **Timing:** the signature **reuse window** after a failed payment (default 24 h, 1–168) and the **abandoned-signature purge** (default 30 days, 1–365).
  - Security limits (rate limit, image size) stay fixed in code on purpose.
- **Product edit → General tab:** a "Require signed agreement" checkbox (`_anchor_agreement_required` = `yes`) and a "Document" select (`_anchor_agreement_id`, where `0` means the site default). Variations inherit from the parent; there are no per-variation fields (YAGNI).
- **Order edit screen:** a "Signed agreements" metabox (HPOS and legacy) listing each signature with document, version date, name, method, time, IP and a View link.
- **Agreements → Signatures:** a `WP_List_Table` with search (name, email, order number), filtering by document and **Export CSV** (the CSV holds metadata only, never the image).
- **Notification email**, sent once per order when signatures are attached: subject `Signed: Cancellation Policy – Pari Ghanbarzadeh, order #1119696`, wrapped in `Anchor_Email_Shell`.

## Part 3 — Data & security (not yet reviewed)

**Tables** (dbDelta, versioned through `anchor_agreements_db_version`, following the anchor-courses `Migrations` pattern):

`{prefix}anchor_agreement_versions`: an immutable snapshot of a document.
`id, agreement_id, content_hash CHAR(64), title, content LONGTEXT, created_at` · UNIQUE(agreement_id, content_hash)

Versions are created **lazily at signing time**: hash `title + "\n" + content`, then insert-or-reuse. Editing a document never touches old signatures; there are no save hooks to get wrong.

`{prefix}anchor_agreement_signatures`
`id, token CHAR(36) UNIQUE, version_id, agreement_id, order_id NULL, product_id, user_id NULL, signer_name, signer_email, method ENUM('draw','generate'), font VARCHAR(40) NULL, image MEDIUMBLOB, ip VARCHAR(45), user_agent VARCHAR(255), session_key VARCHAR(64), signed_at DATETIME (UTC), attached_at DATETIME NULL`

**Security rules.**
- **Images stay in the DB**, never in `uploads/`. On Nginx (Kinsta), `.htaccess` is inert, so uploads are publicly fetchable.
- The image is accepted only as `data:image/png;base64,`. The server decodes it, checks the PNG signature, requires `getimagesizefromstring()` to succeed with dimensions ≤ 2000×1000, and caps it at 200 KB decoded. Anything else is rejected.
- The signing AJAX endpoint is `wc_ajax_anchor_agreement_sign`, protected by a nonce. It is rate-limited to 10 signatures per session per hour.
- The signed copy is reachable **only** by token. Unknown and known tokens return identical headers (`noindex`, `no-store`), following the CertificatePage pattern.
- Block checkout (Store API) is **refused**: an admin notice plus a `woocommerce_store_api_checkout_update_order_from_request` guard throws if required agreements are unsigned. We don't build a block UI (YAGNI; TMJ uses FunnelKit classic).

**Rollout on TMJ.**
1. Ship the module and enable it on TMJ. Create the "Cancellation Policy" agreement by copying WP E-Signature doc 1's text (`wp_esign_documents.document_content`, document_id 1) and set it as the default.
2. On products **1118014** (Toronto 2026) and **1119079** (Pediatric OSA NOLA 2027): set `_esig_woo_meta_product_agreement` to empty, and turn on `_anchor_agreement_required`.
3. Add `[anchor_signed_agreements]` to the account dashboard surface.
4. Place one real order on a test product to verify the full flow and the email.
5. **Keep WP E-Signature installed** until its 83 signed records are exported (`wp_esign_documents*` tables + PDFs). Retiring it is a separate, later decision. Separately, ask the client whether to delete the 4,656 junk James Kamboa docs.
