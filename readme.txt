=== Anchor Schema ===
Contributors: anchorcorps
Tags: schema, json-ld, openai, faq, localbusiness
Requires at least: 5.8
Tested up to: 6.7
Stable tag: 3.27.0
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

= 3.27.0 =

Events Manager — new event statuses:

* Two new manual-only statuses, alongside Cancelled: **Postponed** and
  **Moved online**. A postponed event sells nothing — same as cancelled —
  because the original date is off and no new one is known yet; every
  seat-selling path (`bookability()`, the manual roster add, and the free
  registration form) now refuses it the same way, and a direct/stale POST is
  refused too. A moved-online event stays fully bookable: it still happens,
  on the same date, just virtually.
* The JSON-LD for a postponed or moved-online event emits `EventPostponed` /
  `EventMovedOnline` for `eventStatus`. A postponed (or re-postponed) event
  also gets a `previousStartDate`, captured once — from the front-end
  console, the wp-admin metabox, and now a direct REST `PATCH` to the event's
  status meta too, which previously bypassed the capture entirely.

Events Manager — schema & SEO:

* New filter `anchor_events_schema_node( $node, $event_id )` — runs on every
  JSON-LD node this module assembles (single events, group children, and
  both group-parent/multisession header nodes) just before it is returned.
  Return the (possibly decorated) node array.
* New filter `anchor_events_emit_canonical( $emit, $seo_plugin_active )` —
  this module's own fallback `<link rel="canonical">` on a
  `?anchor_events_month=` calendar URL now stays silent by default whenever
  Yoast, Rank Math, All in One SEO or SEOPress is detected active, since each
  already emits/filters its own canonical for the same URL. A site running
  none of those is unaffected. See EVENTS.md.

Events Manager — shortcodes:

* `[events_list]`'s taxonomy-filter attribute is renamed `event_type` (it was
  named `type`, which read as the *_anchor_event_type* single/offering/
  recurring enum but silently ran a tax_query against the unrelated Event
  Types taxonomy instead). `type=` is kept as a deprecated alias — an
  existing shortcode keeps working unchanged — and now triggers a
  `_doing_it_wrong()` notice (visible only under `WP_DEBUG`) pointing at
  `event_type=`.

Events Manager — admin & console:

* **Closed occurrences** panel on the Events settings tab. A soft-closed
  group-child date whose seats have since all been cancelled/refunded used
  to have no terminal state at all — excluded from every listing forever,
  with no way to remove it. The new panel lists every one and lets you
  delete it; delete is refused while the occurrence still holds an active
  (confirmed/pending/waitlist) seat.
* Recurrence rows can now carry their own `end_date` (via a new `span_days`
  rule key), `tier_id`, and `label`, the same fields a hand-authored
  offering row has. There is no admin UI for these yet — set them via REST,
  a filter, or direct meta — but a value set that way now survives a
  front-end console re-save of a locked recurring event, and a generated
  occurrence is no longer forced to sell every one of the parent's ticket
  tiers regardless of its own date.
* The `event_series` term a group parent auto-mints (`group-{parent_id}`)
  is now noindexed; the taxonomy and its archive stay public and functional
  for a hand-curated series.
* The registrants metabox's attendee table (name, email, guest count) is
  now gated behind the same module capability as the Roster screen and the
  Export button, instead of the looser `edit_post` WordPress applies to the
  metabox itself.
* The front-end console's event title and content, and a manual roster
  add's / the free registration form's attendee name, phone and answers, no
  longer lose a literal backslash on save — they were being unslashed and
  then handed to a WordPress write function (`wp_insert_post()`,
  `update_post_meta()`) that unslashes its input again internally. A value
  saved before this fix is not recoverable; it round-trips correctly from
  the next save.
* The ticket-tiers repeater now ships a hidden `anchor_event_tiers_present`
  marker (mirroring the existing attendee-questions marker), so a save can
  tell "every tier row was deliberately removed" from "this form never
  posted the tiers table at all" — the latter is now a no-op instead of
  silently clearing an event's tiers.

Events Manager — registration:

* A manual roster add now refuses a **cancelled or postponed** event the
  same way it already refused a closed registration window — same "Allow
  over capacity" checkbox to add anyway, logged as `capacity_overfill` with
  reason `status_cancelled` / `status_postponed`.
* New filter `anchor_events_auto_append_registration( true, $post_id )` —
  return `false` to suppress the automatic `[event_registration]` append on
  save. A theme can also opt out wholesale with
  `add_theme_support( 'anchor-events-registration' )`, declaring that it
  renders its own registration UI; either one stops the shortcode being
  appended, and the shortcode itself now renders at most once per event per
  request as a backstop against a double copy of the tag.

Events Manager — WooCommerce / checkout:

* Add-to-cart capacity checks are now cart-wide, not per-line: adding more
  seats for an event already in the cart is compared against what actually
  remains, on both the plugin's own "Register / Add to cart" AJAX endpoint
  and WooCommerce's own add-to-cart validation. A line that is over capacity
  (a stale cart, or a capacity reduction after the add) is now clamped to
  what remains, or removed, instead of left permanently invalid with only a
  notice. The AJAX endpoint's quantity field also enforces its published
  cap (20) server-side; it was previously only an HTML `max` attribute.
  When one event has several lines in the cart (two ticket tiers, say),
  the remaining seats are allocated across those lines in cart order rather
  than each line being compared to the full remainder.
* A ticket tier's sale-window ("Sales open"/"Sales closed") messaging and
  the checks behind it now compare dates in the site's own timezone instead
  of mixing a site-time value with a UTC-parsed one — every boundary used to
  be off by the site's UTC offset.

Events Manager — emails & reminders:

* An order covering more than one event now sends **one confirmation email
  per enabled event**, each gated and built against that event's own
  Confirmation switch and template, rather than resolving a single "primary"
  event for the whole order. A mixed order (confirmation on for event A, off
  for event B) used to silence B's buyer entirely because A was chosen as
  primary. The manual "Resend confirmation" order action follows the same
  per-event rule and now logs one skipped/sent line per event.
* The retry queue's drain now reads the `Outcome` its sender returns instead
  of inferring one from the attempt counter, so a deliberate skip (email
  type switched off, no address, the seat no longer eligible) is retired
  quietly instead of logging an `email_retry_undeliverable` error.
* An abandoned reminder (3 failed attempts) is now distinguishable from a
  reminder simply superseded by a nearer offset; the Upcoming Sends panel
  reports the former as "Failed after 3 attempts" instead of "Past — not
  sent".
* The per-event reminder-override scan is now capped at 500 rows
  (filterable via `anchor_events_reminder_override_scan_limit`), with a
  `reminder_scan_truncated` log entry when the cap is hit, instead of
  running an unbounded query every hour.
  The largest override is found by its own one-row query, so an event
  outside the capped page can no longer be missed.
* A **postponed** event no longer sends or retries reminders for its seats.
  The hourly sweep's "date is off" guard and the queued-retry eligibility
  guard both checked `cancelled` only, so a postponed event with confirmed
  seats kept mailing "…is coming up" for a date that was no longer happening;
  both now call the same `status_is_closed()` check `bookability()` and the
  registration guards already use. Reminders resume automatically once the
  event is no longer postponed. **Moved online** is unaffected — it keeps
  sending, since the event still happens on the same date.

Events Manager — front-end assets:

* Baseline CSS for classes that previously had zero rules in `frontend.css`:
  the single/archive event template containers, the event-series list
  parts, and the WooCommerce ticket/checkout wrapper and state classes
  (sold out, waitlist, closed, upcoming, and the cart error/success
  messages) — layout and spacing only, no colour beyond the existing
  neutral custom properties, no font-family.
* The plugin's minified JS source maps now carry the real source filename
  and full `sourcesContent`, instead of a source named "0" with nothing
  behind it — the maps were unusable in devtools before this.

Events Manager — breaking change for custom code:

* The `anchor_events_seat_status_changed` action no longer fires when a
  status "change" is actually a no-op (e.g. clicking Cancel again on an
  already-cancelled seat). Code that relied on it firing unconditionally on
  every Cancel click, note included, must now check the seat's prior status
  itself if it needs that signal.
* WooCommerce's `send_customer_confirmation()` gained a third parameter,
  `$event_id` — it now builds and sends one confirmation per event rather
  than one combined email for the whole order. A subclass or copy of this
  method must be updated to the new per-event signature.

= 3.26.0 =

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
* `Anchor\Events\WooCommerce::reconcile_order()` has lost its `$clear_review`
  parameter — "Resync order" no longer wipes an order's needs-review flags, it
  re-evaluates them like every other pass and drops only what it re-checked and
  found satisfied, so a refund discrepancy nobody has read survives a resync.
  ("Mark reviewed" still clears everything.) Custom code passing `$seed_flags`
  positionally must drop the now-absent fourth argument.
* A seat move refused only by a ticket-tier quota now reports the reason
  `tier_full` (the event-level shortage keeps `capacity_full`); custom code
  branching on `->reason() === 'capacity_full'` should also handle `tier_full`.
* The `anchor_events_registration_fields` filter is gone. The free registration
  form now asks the event's own Attendee questions and keys each answer by its
  question key, so extra fields are added in that UI rather than in code. Custom
  code hooking the filter no longer runs and must be re-created as questions.

Events Manager — emails:

* The organizer notices (new registration, seats released) now obey the event's
  own Confirmation and Cancellation switches, exactly as the buyer's
  confirmation already did. Turning Confirmation off on an event previously
  silenced the attendee but still mailed the organizer.
* New settings: **Confirmation subject** (`confirmation_subject`), **Refund
  subject** (`refund_subject`) and **Refund email intro** (`refund_intro`). The
  unused `notify_attendee` setting has been retired.
* The shipped default email template now uses the `{brand_bg}`,
  `{brand_surface}`, `{brand_heading}`, `{brand_text}`, `{brand_button}`,
  `{brand_button_text}` and `{logo}` tokens, so the Email Appearance colours and
  logo reach installs that never edited a template. A template you have already
  customised is untouched — the builder shows a notice telling you which tokens
  to add if you want to opt back in.
* The `{join_button}` token is retired: it drew a second button no field
  controlled. It now expands to nothing, so an old saved template that still
  contains it renders cleanly instead of printing the literal token.
* Moving a seat off the waitlist now emails the attendee their confirmation —
  it previously told nobody. The organizer's "New registration" notice is not
  repeated: that seat registered once, when it was created.
* New filter `anchor_events_default_email_template( $html, $type )` — override
  the shipped default body for one email type (`confirmation`, `reminder`,
  `cancellation`, `roster`) without touching the other three.

Events Manager — seats and reminders:

* The seat status transitions `cancelled → waitlist` and `failed → waitlist` are
  now legal. Reviving a seat on an event that is full and has a waitlist puts it
  on the waitlist instead of refusing the transition outright.
* The seat statuses `attended` and `no_show` are read-only rather than gone. A
  site that ran an earlier version may hold seats stored as either; they are
  counted in the roster summary under their own labels and can be moved on to
  Confirmed or Cancelled. Nothing writes them — they are not offered in the
  seat status select and no new seat can be created in either state — and no
  migration rewrites the stored values.
* Reminder offsets are capped at 366 days. A stored offset larger than that is
  clamped when the settings are saved, so a scan can no longer be asked to look
  further ahead than the reminder horizon it actually walks.

