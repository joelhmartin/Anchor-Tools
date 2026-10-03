# Anchor Agreements

A required, signed agreement at WooCommerce checkout. The buyer ticks a box, signs in a mobile-first
modal (draw, or type a name rendered in a script font), and the signature is stored against the exact
version of the document they signed. Editing a document later never changes what anyone signed.

## Setup

1. Enable the module: Settings > Anchor Tools > Agreements.
2. Agreements > Add New: write the document (title and body). Publish it.
3. Agreements > Settings: pick the **default agreement**, set staff notification recipients and review
   the other defaults (subject template, checkbox label, consent sentence, reuse window, purge days).

## Product fields

Product > General: **Require signed agreement** and **Agreement** (a specific document, or the site
default). A product that is required but has no usable document (no default, or the document is
trashed or unpublished) is not silently sold unsigned: the product save shows an error and the
Products list flags it.

## Where signed copies show up

- Customer order emails: a "Your signed agreements" block (can be switched off in Settings).
- `/signed-agreement/{token}/`: private, unguessable, noindex, no-store; print or save as PDF.
- My Account > Signed documents.
- `[anchor_signed_agreements]`: the same list as a shortcode. Use this on sites whose My Account page
  has no `[woocommerce_my_account]` shortcode (e.g. TMJ).
- Order screen: a "Signed agreements" metabox (works with HPOS on or off).
- Agreements > Signatures: searchable list and CSV export (no signature images).

## Notes

- **Requires WooCommerce.** Without it the module only shows an admin notice.
- **Block checkout is refused** for carts that need a signature; use the classic checkout. Store
  managers see an admin notice while the checkout page uses the Checkout block.
- **No order is sold unsigned.** If a required signature cannot be attached when the order is created,
  checkout stops with an error (order note + WooCommerce log, source `anchor-agreements`). A signature on
  an abandoned unpaid order (pending, failed, cancelled) moves to the order that replaces it; one on a
  paid or on-hold order never moves.
- Signing as a guest and then logging in, or creating an account at checkout, keeps the signature.
- My Account > Signed documents lists signatures by account only, never by matching email.
- **WP Rocket / RUCSS:** add `/aagr-/` to `remove_unused_css_safelist` (every class is `aagr-` prefixed).
- Signature images live only in the database, never in `uploads/`.
- A signature not attached to an order within the purge window (default 30 days) is deleted daily
  (`anchor_agreements_cleanup`). Signatures on orders are kept.

## Hooks

- `do_action( 'anchor_agreements_attached', int $order_id, int[] $signature_ids )`: fires once signatures
  are attached to an order.
