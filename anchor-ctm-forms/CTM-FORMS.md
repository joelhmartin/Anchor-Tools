# CTM Forms Module

## Overview

The CTM Forms module lets you build custom forms in WordPress that submit leads directly to your CallTrackingMetrics account. Each form is a `ctm_form_variant` custom post type with two modes: **Reactor mode** (pick an existing FormReactor from CTM) and **Builder mode** (design a form visually and auto-create reactors on publish).

Shortcode: `[ctm_form_variant id="123"]`

## Dynamic Source Attribution

When a form is published in Builder mode, the module automatically creates a **separate FormReactor for each tracking number** in your CTM account. This enables dynamic lead routing based on how the visitor arrived at your site.

### How It Works

**On Publish:**

1. The module calls the CTM API to fetch all tracking numbers in your account (e.g. Google Ads, Facebook Ads, Google Organic, Website).
2. For each number, it creates a FormReactor with the same form fields but linked to that specific number.
3. The full mapping is stored in the `_ctm_reactor_map` post meta as an array:
   ```
   [
     { "number_id": "...", "reactor_id": "FRT...", "number_name": "Google Ads (555-123-4567)" },
     { "number_id": "...", "reactor_id": "FRT...", "number_name": "Facebook Ads (555-234-5678)" },
     { "number_id": "...", "reactor_id": "FRT...", "number_name": "Google Organic (555-345-6789)" },
     { "number_id": "...", "reactor_id": "FRT...", "number_name": "Website (555-456-7890)" }
   ]
   ```

**On Form Submission:**

1. The frontend JavaScript captures visitor attribution data: referrer URL, UTM parameters (`utm_source`, `utm_medium`, etc.), and click IDs (`gclid`, `fbclid`, `msclkid`).
2. The form submits via AJAX to WordPress, which passes the attribution data to `resolve_reactor_for_attribution()`.
3. That method matches the visitor to the correct tracking number using this priority:

| Signal | Matched Source |
|--------|---------------|
| `gclid` present, or `utm_source=google` + `utm_medium=cpc/ppc/paid` | Google Ads number |
| `fbclid` present, or `utm_source=facebook/fb/instagram` + paid medium | Facebook Ads number |
| `msclkid` present, or `utm_source=bing/microsoft` + paid medium | Bing/Microsoft Ads number |
| Google referrer without `gclid`, no paid UTMs | Google Organic number |
| No match | Website/default number (fallback) |

4. The submission is sent to the matched reactor's CTM endpoint, attributing the lead to the correct source.

### Matching Logic

The resolver matches attribution signals against tracking number **names** from your CTM account. It looks for keywords like:

- **Google Ads**: name contains "google" + "ad" or "paid" or "ppc" or "cpc"
- **Facebook Ads**: name contains "facebook" or "fb" or "meta" + "ad" or "paid"
- **Google Organic**: name contains "google" + "organic" or "seo" or "search"
- **Website/Default**: name contains "website" or "web" or "default" or "direct"

Name your CTM tracking numbers clearly (e.g. "Google Ads", "Facebook Ads", "Google Organic", "Website") for reliable matching.

## Architecture

### Two Form Modes

**Reactor Mode** — Select an existing FormReactor from a dropdown, optionally generate starter HTML from its field config. Manual HTML editing. Single reactor, no dynamic routing.

**Builder Mode** — Drag-and-drop field palette (text, email, tel, select, checkbox, radio, etc.) with a live preview. On publish, auto-creates reactors for all tracking numbers. Supports multi-step forms, title pages, floating labels, scoring, and conditional logic.

### Data Model

| Post Meta Key | Description |
|--------------|-------------|
| `_ctm_form_mode` | `reactor` or `builder` |
| `_ctm_reactor_id` | Single reactor ID (reactor mode, or default for builder) |
| `_ctm_reactor_map` | Array of `{number_id, reactor_id, number_name}` entries (builder mode) |
| `_ctm_builder_reactor_id` | Default reactor ID from the map (builder mode) |
| `_ctm_form_config` | JSON config from the builder (field definitions + settings) |
| `_ctm_form_html` | Rendered HTML (generated from config or manual) |
| `_ctm_multi_step` | `1` if multi-step enabled |
| `_ctm_title_page` | `1` if title page enabled |
| `_ctm_analytics_override` | `1` to use per-form analytics instead of global defaults |

### Submission Flow

```
Browser                    WordPress                        CTM API
  │                            │                               │
  │  POST /wp-admin/admin-ajax.php                             │
  │  action=anchor_ctm_submit  │                               │
  │  core_json, custom_json,   │                               │
  │  attribution_json          │                               │
  │ ─────────────────────────► │                               │
  │                            │  resolve_reactor_for_          │
  │                            │  attribution()                 │
  │                            │  ┌──────────────────┐         │
  │                            │  │ gclid? → Google   │         │
  │                            │  │ fbclid? → Facebook│         │
  │                            │  │ organic? → SEO    │         │
  │                            │  │ else → Website    │         │
  │                            │  └──────┬───────────┘         │
  │                            │         │                      │
  │                            │  POST /api/v1/formreactor/{id} │
  │                            │ ──────────────────────────────►│
  │                            │                                │
  │                            │◄──────────── 200 OK ──────────│
  │◄─── json_success ─────────│                                │
```

### Settings (Settings > CTM Forms)

- **Access Key / Secret Key** — CTM API credentials (Basic auth)
- **Account ID** — Your CTM account ID
- **Analytics** — Global defaults for GA4, Google Ads, Facebook Pixel, TikTok, Bing UET conversion events (can be overridden per form)

### API Endpoints Used

| Purpose | Method | Endpoint |
|---------|--------|----------|
| List tracking numbers | GET | `/api/v1/accounts/{id}/numbers.json` |
| List form reactors | GET | `/api/v1/accounts/{id}/form_reactors` |
| Get reactor detail | GET | `/api/v1/accounts/{id}/form_reactors/{reactor_id}` |
| Create form reactor | POST | `/api/v1/accounts/{id}/form_reactors` |
| Submit to reactor | POST | `/api/v1/formreactor/{reactor_id}` |

Note: The submit endpoint uses singular `formreactor` (no underscore) and no account ID — different from the management endpoints.
