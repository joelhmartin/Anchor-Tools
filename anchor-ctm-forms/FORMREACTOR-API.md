# CTM FormReactor API — Complete Reference

This document covers everything needed to work with the CallTrackingMetrics (CTM) FormReactor API as implemented in the Anchor CTM Forms module. It is written so that any agent can understand the full system — API endpoints, authentication, field types, custom fields, visitor attribution, and submission flow — without reading the source code.

---

## Table of Contents

1. [Authentication](#authentication)
2. [API Endpoints](#api-endpoints)
3. [Core Concepts](#core-concepts)
4. [FormReactor Creation](#formreactor-creation)
5. [Field Types & Type Mapping](#field-types--type-mapping)
6. [Custom Fields](#custom-fields)
7. [Visitor Attribution & visitor_sid](#visitor-attribution--visitor_sid)
8. [Form Submission](#form-submission)
9. [Submission Body Reference](#submission-body-reference)
10. [Builder Config Schema](#builder-config-schema)
11. [Analytics & Conversion Tracking](#analytics--conversion-tracking)
12. [WordPress Integration](#wordpress-integration)

---

## Authentication

All CTM API calls use **HTTP Basic Auth**:

```
Authorization: Basic base64(access_key:secret_key)
```

Credentials are stored in the WordPress option `anchor_ctm_forms_options`:

| Key | Description |
|-----|-------------|
| `access_key` | CTM API access key |
| `secret_key` | CTM API secret key |
| `account_id` | Your CTM account ID (numeric) |

Find these in your CTM dashboard under **Settings > API**.

Every request also sends:
```
Accept: application/json
Content-Type: application/json
User-Agent: Anchor-CTM-Forms/1.0 (+WordPress)
```

---

## API Endpoints

Base URL: `https://api.calltrackingmetrics.com`

### Management Endpoints (require account_id)

| Purpose | Method | Path |
|---------|--------|------|
| List tracking numbers | GET | `/api/v1/accounts/{account_id}/numbers.json` |
| List form reactors | GET | `/api/v1/accounts/{account_id}/form_reactors` |
| Get reactor detail | GET | `/api/v1/accounts/{account_id}/form_reactors/{reactor_id}` |
| Create form reactor | POST | `/api/v1/accounts/{account_id}/form_reactors` |
| List account custom fields | GET | `/api/v1/accounts/{account_id}/custom_fields.json?all=true` |
| Create account custom field | POST | `/api/v1/accounts/{account_id}/custom_fields.json` |

### Submission Endpoint (NO account_id)

| Purpose | Method | Path |
|---------|--------|------|
| **Submit form data** | **POST** | **`/api/v1/formreactor/{reactor_id}`** |

**Critical distinction**: The submission endpoint uses singular `formreactor` (no underscore, no `account_id` in the path). This is different from all management endpoints which use `form_reactors` (with underscore) under the account path.

---

## Core Concepts

### What Is a FormReactor?

A FormReactor is a CTM endpoint that receives form submissions and creates activity records (leads). Each reactor:

- Is tied to a specific **tracking number** (`virtual_phone_number_id`)
- Defines which **core fields** to accept (name, email)
- Defines which **custom fields** to accept
- Has a unique ID (e.g. `FRT...`) used for the submission URL
- Requires `phone_number` on every submission (CTM's universal requirement)

### Core Fields

CTM natively recognizes exactly five field names:

| Field Name | Purpose | Required? |
|------------|---------|-----------|
| `caller_name` | Contact name | Optional (controlled by `include_name` on reactor) |
| `email` | Email address | Optional (controlled by `include_email` on reactor) |
| `phone_number` | Phone number | **Always required** |
| `phone` | Alternative phone (alias for phone_number) | Optional |
| `country_code` | Country dialing code (defaults to `1` / US) | Optional |

Every other field is a **custom field**.

---

## FormReactor Creation

When a Builder-mode form is published, the module creates a reactor via:

```
POST /api/v1/accounts/{account_id}/form_reactors
```

### Request Body

```json
{
  "name": "Contact Form",
  "virtual_phone_number_id": "TPN123456",
  "include_name": true,
  "name_required": false,
  "include_email": true,
  "email_required": false,
  "custom_fields": [
    {
      "name": "Message",
      "type": "textarea",
      "required": false,
      "log_visible": true
    },
    {
      "name": "Service Type",
      "type": "list",
      "required": true,
      "items": "Consulting\nImplementation\nSupport",
      "log_visible": true
    }
  ]
}
```

### Response

```json
{
  "form_reactor": {
    "id": "FRT789abc"
  }
}
```

The reactor ID may appear at `response.form_reactor.id` or `response.id` depending on the API version.

### Tracking Number Selection

On publish, the module fetches all tracking numbers and picks the **website/default** number by searching for keywords in the number's name:
- `"website"`, `"default"`, `"direct"` → selected
- Fallback: last number in the list

This single-reactor approach works because CTM's `visitor_sid` (see below) handles source attribution automatically.

### Deduplication

The module hashes the reactor field config and stores it in `_ctm_reactor_fields_hash` post meta. A new reactor is only created when the hash changes (i.e., the fields were modified). Re-publishing without field changes skips reactor creation.

---

## Field Types & Type Mapping

### Builder → CTM Type Map

The visual builder uses standard HTML field types. These are mapped to CTM's type system when creating reactors:

| Builder Type | CTM Reactor Type | Notes |
|-------------|-----------------|-------|
| `text` | `text` | |
| `email` | `text` | CTM doesn't distinguish email from text |
| `tel` | `text` | |
| `number` | `text` | |
| `url` | `text` | |
| `hidden` | `text` | |
| `textarea` | `textarea` | |
| `select` | `list` | Single-select from options |
| `radio` | `list` | Single-select from options |
| `checkbox` | `checklist` | Multi-select from options |

### Options Format

For `list` and `checklist` types, CTM expects options as a **newline-separated string** in the `items` field — not a JSON array:

```json
{
  "name": "Service Type",
  "type": "list",
  "items": "Consulting\nImplementation\nSupport\nTraining"
}
```

The builder stores options as a JSON array internally:
```json
"options": [
  { "label": "Consulting", "value": "consulting", "score": 10 },
  { "label": "Implementation", "value": "implementation", "score": 5 }
]
```

On reactor creation, the module converts: `implode("\n", option_labels)`.

### Account-Level Custom Field Types

When registering fields at the account level (not reactor level), CTM only accepts these types:
`text`, `textarea`, `number`, `email`, `date`, `float`

The module maps reactor types → account types:
- `list` → `text`
- `checklist` → `text`
- `select` → `text`
- `checkbox` → `text`
- `radio` → `text`

---

## Custom Fields

### The Two Layers

CTM has two layers of custom fields:

1. **Reactor-level custom fields** — defined when creating a FormReactor. These specify what custom data the reactor will accept. Any data submitted with a `custom_` prefix is recorded on the activity, even if not pre-defined on the reactor.

2. **Account-level custom fields** — registered in the CTM account's global field library. These make custom data searchable, filterable, and visible as columns in reporting.

### Sending Custom Fields with Submissions

On the submission endpoint, custom fields are sent as **top-level keys with a `custom_` prefix**:

```json
{
  "caller_name": "Jane Doe",
  "phone_number": "5551234567",
  "country_code": "1",
  "custom_message": "I need help with my account",
  "custom_service_type": "consulting",
  "custom_company_size": "enterprise",
  "custom_sms_consent": "Yes",
  "visitor_sid": "abc123..."
}
```

**Critical**: Custom fields go at the top level with the `custom_` prefix. They are NOT nested under a `"custom"` or `"custom_fields"` key.

### How the Frontend Separates Core vs Custom

Form inputs rendered by the builder include a CSS class `ctm-custom` on all non-core fields:

```html
<!-- Core field (no special class) -->
<input type="text" name="caller_name" />

<!-- Custom field (has ctm-custom class) -->
<input type="text" name="message" class="ctm-custom" />
<select name="service_type" class="ctm-custom">...</select>
```

The frontend JS checks this class to route values:
```js
var isCustom = el.classList.contains('ctm-custom');
var target = isCustom ? custom : core;
```

The two objects are JSON-serialized and sent as `core_json` and `custom_json` in the AJAX POST.

### Backend Prefixing

The PHP backend adds the `custom_` prefix before sending to CTM:

```php
foreach ( $custom as $key => $value ) {
    $prefixed_key = strpos( $key, 'custom_' ) === 0 ? $key : 'custom_' . $key;
    $body[ $prefixed_key ] = is_array( $value ) ? implode( ', ', $value ) : $value;
}
```

Checkbox arrays are flattened to comma-separated strings: `"option1, option2, option3"`.

### Field Name Sanitization

All custom field names are sanitized to safe machine identifiers:
- Lowercase
- Underscores only (no spaces, hyphens, special characters)
- Example: `"Are You New?"` → `"are_you_new"`

The `label` property holds the human-readable text; the `name` property holds the machine identifier.

### Registering Account-Level Custom Fields

There are two ways to promote custom fields to account-level:

#### Method 1: Click-to-Register in CTM UI

1. Submit a form that includes custom field data
2. Open the resulting activity in the CTM dashboard
3. Click on the custom field value
4. CTM offers to create it as an account-level custom field
5. Confirm — the field is now searchable/filterable/visible in reports

This only needs to be done once per field name. All past and future activities with that key are linked automatically.

#### Method 2: Auto-Registration on Publish (API)

In the builder, each custom field has a **"Register as account field"** toggle (`registerField: true` in the config). When enabled, on publish:

1. `GET /api/v1/accounts/{id}/custom_fields.json?all=true` — fetch existing fields
2. For each new field not already registered:
   ```
   POST /api/v1/accounts/{id}/custom_fields.json
   ```
   ```json
   {
     "custom_field": {
       "name": "Service Type",
       "api_name": "service_type",
       "field_type": "text",
       "object_type": "Call",
       "panel": "contact",
       "required": false,
       "log_visible": true,
       "should_redact": true,
       "multipicker": false
     }
   }
   ```

3. Sync result stored in `_ctm_custom_fields_sync_result` post meta

Existing fields are never modified or removed — the sync is additive only.

### logVisible

Set `log_visible: true` on custom fields so their values appear in CTM's activity log. Without this, submitted data is stored but not visible in the call/form log UI. Default to `true` for all fields unless there's a specific reason to hide them.

---

## Visitor Attribution & visitor_sid

### How CTM Tracks Visitors

CTM provides a JavaScript snippet that runs on the website. This script:
- Tracks which ad/source brought the visitor
- Dynamically swaps phone numbers on the page based on source
- Generates a **visitor session ID** (`visitor_sid`)

### visitor_sid

The `visitor_sid` is the key to CTM's source attribution. When included in a form submission, CTM can automatically attribute the lead to the correct traffic source (Google Ads, Facebook, organic, etc.) without needing multiple reactors.

**How to capture it**:
```js
// CTM's JS sets window.__ctm when ready
// Capture at SUBMIT TIME (not page load) because the async script may not be ready yet
try {
  if (window.__ctm && __ctm.config && __ctm.config.sid) {
    attribution.visitor_sid = __ctm.config.sid;
  }
} catch(ex) {}
```

**Important**: The `visitor_sid` must be captured at submit time, not page load, because CTM's JavaScript loads asynchronously.

### Attribution Fields Sent with Submissions

These are sent as **top-level keys** (NOT prefixed with `custom_`):

| Field | Source | Purpose |
|-------|--------|---------|
| `visitor_sid` | `window.__ctm.config.sid` | CTM session ID for source attribution |
| `referring_url` | `document.referrer` | Where the visitor came from |
| `page_url` | `window.location.href` | Which page the form is on |
| `visitor_ip` | Server-side (`X-Forwarded-For` or `REMOTE_ADDR`) | Visitor IP address |
| `user_agent` | Server-side (`HTTP_USER_AGENT`) | Browser user agent |

UTM parameters and click IDs are also collected from the URL at page load but are used for fallback attribution logic, not sent directly to the submission endpoint:

| Parameter | Purpose |
|-----------|---------|
| `utm_source` | Traffic source |
| `utm_medium` | Traffic medium |
| `utm_campaign` | Campaign name |
| `utm_term` | Paid keyword |
| `utm_content` | Ad content/variant |
| `gclid` | Google Ads click ID |
| `fbclid` | Facebook click ID |
| `msclkid` | Microsoft/Bing click ID |

### Single Reactor + visitor_sid (Current Architecture)

The module creates a **single reactor** tied to the website/default tracking number. CTM's `visitor_sid` handles source attribution automatically — no need for one reactor per traffic source. This is simpler and more reliable than the multi-reactor approach.

---

## Form Submission

### End-to-End Flow

```
Browser                        WordPress                          CTM API
  |                                |                                  |
  |  1. User fills form, clicks    |                                  |
  |     Submit                     |                                  |
  |                                |                                  |
  |  2. JS collects:               |                                  |
  |     - core fields (name/email/phone)                              |
  |     - custom fields (class=ctm-custom)                            |
  |     - attribution (referrer, UTMs, visitor_sid)                    |
  |                                |                                  |
  |  3. POST /wp-admin/admin-ajax.php                                 |
  |     action=anchor_ctm_submit   |                                  |
  |     core_json={...}            |                                  |
  |     custom_json={...}          |                                  |
  |     attribution_json={...}     |                                  |
  |  ----------------------------> |                                  |
  |                                |                                  |
  |                                |  4. Sanitize + normalize         |
  |                                |     phone → phone_number         |
  |                                |     name → caller_name           |
  |                                |     default country_code=1       |
  |                                |                                  |
  |                                |  5. Prefix custom fields:        |
  |                                |     message → custom_message     |
  |                                |     Flatten arrays → CSV         |
  |                                |                                  |
  |                                |  6. Add server-side attribution: |
  |                                |     visitor_ip, user_agent       |
  |                                |                                  |
  |                                |  7. POST /api/v1/formreactor/{id}|
  |                                |     {merged body as JSON}        |
  |                                |  -------------------------------->|
  |                                |                                  |
  |                                |  8. CTM returns 200 OK           |
  |                                |<-------------------------------- |
  |                                |                                  |
  |  9. Success response           |                                  |
  |     + fire analytics events    |                                  |
  |<-----------------------------  |                                  |
```

### Consent Fields

Consent checkboxes (class `ctm-consent-checkbox`) have special behavior:
- **Checked**: submits `"Yes"`
- **Unchecked**: submits `"No"`

Normal checkboxes are skipped when unchecked. Consent fields always submit a value.

### Validation

- `phone_number` is **required** — submission fails without it
- Phone numbers are stripped to digits only: `preg_replace('/\D+/', '', $phone)`
- `country_code` defaults to `"1"` (US) if not provided
- `name` is aliased to `caller_name`; `phone` is aliased to `phone_number`

---

## Submission Body Reference

### Complete Example

What actually gets POSTed to `POST /api/v1/formreactor/{reactor_id}`:

```json
{
  "caller_name": "Jane Doe",
  "email": "jane@example.com",
  "phone_number": "5551234567",
  "country_code": "1",
  "custom_message": "I need help with my project",
  "custom_service_type": "consulting",
  "custom_budget_range": "50k-100k",
  "custom_how_did_you_hear": "Google Search, Friend Referral",
  "custom_sms_consent": "Yes",
  "custom_newsletter": "No",
  "visitor_sid": "v_abc123def456",
  "referring_url": "https://www.google.com/",
  "page_url": "https://example.com/contact",
  "visitor_ip": "203.0.113.42",
  "user_agent": "Mozilla/5.0..."
}
```

### Field Categories at a Glance

| Category | Prefix | Examples |
|----------|--------|---------|
| Core fields | none | `caller_name`, `email`, `phone_number`, `country_code` |
| Custom fields | `custom_` | `custom_message`, `custom_service_type` |
| Attribution | none | `visitor_sid`, `referring_url`, `page_url`, `visitor_ip`, `user_agent` |

All three categories are mixed together at the top level of the JSON body. There is no nesting.

---

## Builder Config Schema

The visual builder stores form configuration as JSON in the `_ctm_form_config` post meta.

### Top-Level Structure

```json
{
  "settings": {
    "labelStyle": "above|floating|hidden",
    "submitText": "Submit",
    "successMessage": "Thanks! We'll be in touch shortly.",
    "multiStep": false,
    "progressBar": true,
    "autoAdvance": false,
    "colorScheme": "light|dark",
    "colors": {
      "bg": "#ffffff",
      "text": "#1e1e1e",
      "label": "#374151",
      "inputBg": "#ffffff",
      "inputBorder": "#d1d5db",
      "inputText": "#1e1e1e",
      "focus": "#2563eb",
      "btnBg": "#2563eb",
      "btnText": "#ffffff"
    },
    "titlePage": {
      "enabled": false,
      "heading": "",
      "description": "",
      "buttonText": "Get Started"
    },
    "scoring": {
      "enabled": false,
      "showTotal": false,
      "totalLabel": "Your Score",
      "sendAs": "custom_total_score"
    }
  },
  "fields": [ ... ]
}
```

### Field Object

```json
{
  "id": "f_a3b9c2d1",
  "type": "text|email|tel|number|url|textarea|select|checkbox|radio|hidden|consent|heading|paragraph|divider|score_display",
  "label": "Human-Readable Label",
  "name": "machine_safe_name",
  "placeholder": "",
  "helpText": "",
  "defaultValue": "",
  "required": false,
  "isCustom": true,
  "width": "full|half|third|quarter",
  "labelStyle": "inherit|above|floating|hidden",
  "cssClass": "",
  "step": 0,
  "logVisible": true,
  "registerField": false,
  "conditions": [
    {
      "field": "f_other_field_id",
      "operator": "equals|not_equals|contains|is_empty|is_not_empty|greater_than|less_than",
      "value": "some_value"
    }
  ],
  "conditionLogic": "all|any",
  "options": [
    { "label": "Option A", "value": "option_a", "score": 10 }
  ],
  "consentText": "I agree to receive SMS messages",
  "min": null,
  "max": null,
  "numStep": null
}
```

### Field ID Format

IDs are `f_` followed by 8 random alphanumeric characters (e.g. `f_a3b9c2d1`). Existing IDs must never be regenerated.

### isCustom Rule

- `isCustom: false` — ONLY for the five core fields: `caller_name`, `email`, `phone_number`, `phone`, `country_code`
- `isCustom: true` — everything else, including `message`, service dropdowns, consent checkboxes, etc.

### Layout-Only Fields

These field types produce HTML elements but do NOT submit data:
- `heading` — renders `<h3>`
- `paragraph` — renders `<p>`
- `divider` — renders `<hr>`
- `score_display` — renders a score total with a hidden input

### Multi-Step Forms

Set `settings.multiStep = true`. Assign a `step` property (0-indexed integer) to each field. Fields with the same step number appear on the same page. The builder wraps each step in `<div class="ctm-multi-step-item">`. Progress bar, Back/Continue buttons are added automatically by `multi-step.js`.

### Scoring

Set `settings.scoring.enabled = true`. Add `score` values to option objects. The `score_display` field type renders the total. `form-logic.js` handles accumulation and stores the result in a hidden `ctm-score-input` field sent as a custom field.

### Conditional Logic

Fields can show/hide based on other field values. Add conditions to the `conditions` array. Supported operators: `equals`, `not_equals`, `contains`, `is_empty`, `is_not_empty`, `greater_than`, `less_than`. Set `conditionLogic` to `"all"` (AND) or `"any"` (OR). Hidden fields are disabled so they don't submit.

---

## Analytics & Conversion Tracking

On successful submission, the frontend fires conversion events for configured platforms:

| Platform | JS Function | Example Event |
|----------|-------------|---------------|
| GA4 | `gtag('event', eventName, params)` | `form_submit` |
| Google Ads | `gtag('event', 'conversion', { send_to: id })` | `AW-123456789/AbCdEf` |
| Facebook Pixel | `fbq('track', eventName, params)` | `Lead` |
| TikTok Pixel | `ttq.track(eventName, params)` | `SubmitForm` |
| Bing UET | `uetq.push('event', eventName, params)` | `submit_lead_form` |

Analytics settings are global defaults (Settings > CTM Forms) but can be overridden per-form via the `_ctm_analytics_override` and `_ctm_analytics` post meta.

---

## WordPress Integration

### Data Model

| Post Meta Key | Description |
|--------------|-------------|
| `_ctm_form_mode` | `"reactor"` or `"builder"` |
| `_ctm_reactor_id` | Active reactor ID used for submissions |
| `_ctm_builder_reactor_id` | Reactor ID created by builder mode |
| `_ctm_reactor_fields_hash` | MD5 hash of reactor field config (for change detection) |
| `_ctm_form_config` | JSON string — builder config (fields + settings) |
| `_ctm_form_html` | Rendered form HTML |
| `_ctm_multi_step` | `1` if multi-step enabled |
| `_ctm_title_page` | `1` if title page enabled |
| `_ctm_title_heading` | Title page heading text |
| `_ctm_title_desc` | Title page description |
| `_ctm_start_text` | Title page start button text |
| `_ctm_auto_advance` | `1` to auto-advance steps on selection |
| `_ctm_success_message` | Custom success message |
| `_ctm_analytics_override` | `1` to use per-form analytics |
| `_ctm_analytics` | Array of per-form analytics settings |
| `_ctm_custom_fields_sync_result` | `"success"` or error message from last sync |

### Shortcode

```
[ctm_form_variant id="123"]
```

### AJAX Actions

| Action | Auth | Purpose |
|--------|------|---------|
| `anchor_ctm_submit` | Public (nonce) | Submit form to CTM |
| `anchor_ctm_generate` | Admin | Generate starter HTML from existing reactor |
| `ctm_builder_preview` | Admin | Live preview of builder config |
| `anchor_ctm_ai_assist` | Admin | AI form assistant (OpenAI) |
| `anchor_ctm_account_fields` | Admin | Fetch account-level custom fields |

### CPT

Post type: `ctm_form_variant`
- `public: false`, `show_ui: true`
- Supports: `title` only
- Menu icon: `dashicons-feedback`

### Row Actions

Each form in the list table has:
- **Duplicate** — creates a draft copy (portable meta only, no reactor IDs)
- **Export JSON** — downloads config as `.json` file
- **Import JSON** — upload panel on list page, creates draft from file

Portable meta (copied on duplicate/export): form mode, config, HTML, multi-step settings, analytics. Reactor IDs and field hashes are NOT copied — new reactors are created on publish.
