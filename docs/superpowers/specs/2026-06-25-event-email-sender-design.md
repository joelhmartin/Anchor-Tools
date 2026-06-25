# Event Email Sender (From / Reply-To / BCC) — Design Spec

**Scope:** Events module only. Sets the **From**, **Reply-To**, and an optional **BCC** identity on every
email the events module sends, via per-message headers (not global `wp_mail` filters), so it stays
scoped to event/registration emails. Delivery still relies on the site's mail service (Mailgun, WP Mail
SMTP, etc.) — this only sets the headers.

## Settings (Events settings tab → new "Email Sender" section)
Added to `Module::get_settings()` defaults, `register_settings()`, and `sanitize_settings()`:

| Key | Field | Sanitize | Notes |
|---|---|---|---|
| `email_from_name` | From name | `sanitize_text_field` | blank → WP default |
| `email_from_address` | From email | `sanitize_email` | blank → WP default; must be on a domain the mail service is authorized to send for |
| `email_reply_to_name` | Reply-To name | `sanitize_text_field` | optional |
| `email_reply_to_address` | Reply-To email | `sanitize_email` | blank → no Reply-To header |
| `email_bcc` | BCC email | `sanitize_email` | optional; blank → no BCC |

All optional. Section help text states: this only sets headers; actual delivery needs a configured mail
service; the From domain should pass SPF/DKIM for that service; some SMTP/Mailgun plugins force their own
From and will override this (Reply-To is usually respected).

## Behavior
- New helper `Module::email_headers(array $extra = []): array` returns the header lines to apply:
  - `From: "<name>" <email>` only when `email_from_address` is a valid email (name optional).
  - `Reply-To: "<name>" <email>` only when `email_reply_to_address` is valid.
  - `Bcc: <email>` only when `email_bcc` is valid.
  - Merged with `$extra` (e.g. the `Content-Type: text/html` line) without duplicating it.
- `Module::send_html_email()` builds its headers via `email_headers(['Content-Type: text/html; charset=UTF-8'])`
  instead of the bare Content-Type array. (Callers may still pass explicit `$headers`; when they do, those
  are used as-is — but the two internal callers rely on the default path, so they pick up the sender identity.)
- The one remaining plain-text email (the admin notification in `send_registration_emails()`, currently a
  bare `\wp_mail(... )`) is given `email_headers()` too, so From/Reply-To/BCC are consistent across every
  event email. The deferred reminder/cancellation emails inherit it automatically (all route through
  `send_html_email()`).

## Out of scope
No SMTP/Mailgun credentials or sending transport — purely the sender/reply/bcc identity headers.

## Acceptance
- With the fields set, an event confirmation + the admin-notify email carry the configured `From`,
  `Reply-To`, and `Bcc` headers; blank fields omit the corresponding header (WP defaults apply).
- Invalid emails are dropped (not emitted as malformed headers).
- No change when all fields are blank (behaves exactly as today).
