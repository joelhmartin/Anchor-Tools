=== Anchor Schema ===
Contributors: anchorcorps
Tags: schema, json-ld, openai, faq, localbusiness
Requires at least: 5.8
Tested up to: 6.7
Stable tag: 1.0.3
License: GPLv2 or later

Generate, upload, validate, edit, and serve JSON-LD schema with AI or your own files. Includes debug logging to Kinsta error log.

== Anchor Events Manager ==
Anchor Tools now includes an Events Manager module authored by Anchor Corps. Enable it in Anchor Tools Settings > Modules.

Features:
- Event custom post type with date/time, location, and registration controls
- Event Categories, Tags, and Types taxonomies
- Shortcodes: [events_list], [event_calendar], [featured_events]
- Internal registration with CSV export and email notifications

Usage:
1. Enable the module in Anchor Tools Settings > Modules.
2. Add events under the new Events menu.
3. Use [events_list] or [event_calendar] in pages or posts.

== Changelog ==

= 3.26.0 (unreleased) =

Events Manager — access change on WooCommerce sites:

* Every surface that shows or acts on attendee and customer data now resolves
  one capability in one place. On a site with WooCommerce active that capability
  is `manage_woocommerce` (Shop Manager and Administrator hold it); on a site
  without WooCommerce it stays `edit_others_posts`. This already governed the
  Roster screen; it now also governs the `[event_registrants_list]` and
  `[event_manager]` shortcodes and the console's save handler, every "Export CSV"
  link and the export itself, the order actions (Resync order / Mark reviewed /
  Resend confirmation) and their needs-review notice, and the event error log.
* **On a WooCommerce site, a stock Editor loses those screens.** Previously the
  front-end console showed an Editor the same attendee names and emails the
  Roster screen refused them. If your site runs events from a role that does not
  hold `manage_woocommerce`, map the module to your own capability in one line:

      add_filter( 'anchor_events_capability', fn() => 'manage_event_roster' );

  The filter also receives whether WooCommerce is active as its second argument.
  Sites without WooCommerce are unaffected.
* The CSV export now refuses any id that is not a live `event` post — a trashed
  or deleted event no longer exports its attendee list — and the manual seat
  actions (edit / cancel, and the edit panels that show a seat) accept only a
  seat belonging to the event whose link opened them.

Events Manager — breaking change for custom code:

* `Anchor\Events\Registrations::update_status()` and the email senders
  (`send_reminder_email()`, `send_roster_email()`, `send_cancellation_email()`,
  and the WooCommerce buyer/organizer senders) now return an
  `Anchor\Events\Outcome` object instead of a bool. An Outcome is always truthy,
  so `if ( $reg->update_status( ... ) )` will now always take the true branch —
  custom code must use `->is_sent()` / `->is_skipped()` / `->is_failed()`.
  `sent` = it happened, `skipped` = deliberately not done (an email type
  switched off, nothing to send, a status a seat already holds), `failed` = it
  was attempted and rejected. Only `failed` flags an order for review or queues
  a retry.
* Event meta saved before 3.26.0 could lose backslashes (the values were passed
  to `update_post_meta()` already unslashed). Backslashes eaten by those earlier
  saves are not recoverable; values round-trip correctly from the next save.

