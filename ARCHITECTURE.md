# Plugin Architecture Documentation
## WooCommerce One-Click Purchase Plugin

**Version:** 2.0.0
**Author:** Seventh Day Labs
**Last Updated:** 2026-06-01

---

## Table of Contents

1. [System Overview](#1-system-overview)
2. [Trust Boundaries](#2-trust-boundaries)
3. [Plugin Lifecycle](#3-plugin-lifecycle)
4. [Core Classes](#4-core-classes)
5. [Purchase Flows](#5-purchase-flows)
6. [Backend Communication](#6-backend-communication)
7. [WordPress Hooks And Routes](#7-wordpress-hooks-and-routes)
8. [Admin Pages](#8-admin-pages)
9. [Session Worker And Order Execution](#9-session-worker-and-order-execution)
10. [Security Model](#10-security-model)
11. [Data Stored In WordPress](#11-data-stored-in-wordpress)
12. [Development And Release Notes](#12-development-and-release-notes)

---

## 1. System Overview

The plugin is the WordPress/WooCommerce execution layer for the One-Click Purchase system.

It is intentionally not the source of truth for campaign/public-link/session state. The FastAPI backend owns rules, offers, public link state, claim state, session timing, tenant validation, email delivery, and licensing.

The plugin owns:

- WordPress admin UI,
- WooCommerce product/order/user context,
- WordPress login cookies and nonces,
- public shop URL passthrough,
- purchase window rendering,
- WooCommerce checkout fallback,
- Stripe MIT charge execution,
- WooCommerce order creation,
- scheduled finalization worker.

```text
Browser
  |
  | clicks, redirects, WP cookies, session cookies
  v
WordPress + WooCommerce plugin
  |
  | outbound HTTPS JSON with X-License-Key + X-Site-URL
  v
FastAPI backend
```

The plugin never receives raw JWT in the browser URL in the current primary flows. It receives opaque codes and redeems them through a licensed backend exchange.

---

## 2. Trust Boundaries

### Browser

Browser may carry:

- public `short_id`,
- opaque `claim_code`,
- WordPress login cookies,
- `session_id`,
- HttpOnly session access cookie.

Browser must not see:

- backend license key,
- raw JWT purchase payload,
- Stripe secrets,
- backend session payload outside rendered/proxied state.

### Plugin

The plugin may execute trusted operations only after licensed backend exchange:

- Stripe MIT charge,
- WooCommerce order creation,
- locked-price cart checkout fallback,
- finalization reports.

### Backend

Backend remains source of truth for:

- rule matches,
- campaign/public offers,
- short ID validity,
- claim validity,
- session state,
- timer,
- finalization mode.

---

## 3. Plugin Lifecycle

### Activation

```text
oneclick_activation()
  -> requirements check
  -> default backend URL: https://woocomail-api.onrender.com
  -> default purchase completion mode: purchase_session
  -> schedule license check
  -> schedule abandoned cart detection
  -> schedule periodic customer check
  -> schedule fallback purchase session worker if Action Scheduler unavailable
  -> flush rewrite rules
```

No local crypto keypair is required for the current primary purchase flow.

### Load

```text
plugins_loaded
  -> load Composer autoloader
  -> load class files
  -> instantiate admin, automation, public link, session, purchase, Stripe, order classes
```

Loaded classes include:

- `OneClick_Logger`
- `OneClick_API_Client`
- `OneClick_Settings`
- `OneClick_Actions_Admin`
- `OneClick_Reactions_Admin`
- `OneClick_Rules_Admin`
- `OneClick_Product_Picker`
- `OneClick_Public_Links`
- `OneClick_Email_Branding`
- `OneClick_Observability_Dashboard`
- `OneClick_Cart_Tracker`
- `OneClick_Periodic_Cron`
- `OneClick_Campaign_Trigger`
- `OneClick_Stripe_Reconciliation`
- `OneClick_AI_Setup`
- `OneClick_Compatibility_Test`
- `OneClick_Purchase_Session`
- `OneClick_Purchase_Handler`
- `OneClick_Product_Button`

### Deactivation

Unschedules:

- `oneclick_send_campaign_email`
- `oneclick_daily_license_check`
- `oneclick_detect_abandoned`
- `oneclick_periodic_check`
- `oneclick_process_due_purchase_sessions`

Then flushes rewrite rules.

---

## 4. Core Classes

### `OneClick_API_Client`

Central backend HTTP client.

Responsibilities:

- stores backend base URL,
- adds JSON headers,
- adds `X-Site-URL`,
- adds `X-License-Key` when configured,
- wraps `wp_remote_get/post/request`,
- returns decoded JSON or `WP_Error`.

All protected backend calls go through this class.

### `OneClick_Logger`

Central production logger for plugin-side diagnostics.

Responsibilities:

- routes OneClick logs through WooCommerce `wc_get_logger()`,
- writes to sources such as `oneclick-session`, `oneclick-email`, `oneclick-stripe`, `oneclick-backend`, `oneclick-public`, `oneclick-cart`, `oneclick-order`, `oneclick-ai` and `oneclick-core`,
- preserves readable flow log text, including `FÁZA`, `Step x/29`, emoji markers and separators,
- falls back to PHP `error_log()` only when WooCommerce logging is unavailable.

Production operators should read plugin logs in `WooCommerce -> Status -> Logs`, filtered by the OneClick source.

### `OneClick_Campaign_Trigger`

Owns email campaign orchestration:

1. reacts to WooCommerce order status/payment hooks,
2. collects product IDs and category IDs,
3. calls `/api/rules/evaluate`,
4. schedules campaign email send,
5. prepares product snapshots,
6. sends `/api/send-campaign-email`,
7. includes `completion_mode=purchase_session` by default.

It preserves detailed `FÁZA` / `Step x/29` audit logging.

### `OneClick_Public_Links`

Owns public marketing links.

Admin responsibilities:

- list/create/edit/disable public one-click links,
- collect WooCommerce product snapshots,
- call `/api/public-links`,
- show copyable shop URL `/oneclick/{short_id}`.

Public route responsibilities:

- register rewrite route `/oneclick/{short_id}`,
- register rewrite route `/oneclick/claim/{claim_code}`,
- set/refresh anonymous visitor cookie `oneclick_public_visitor`,
- perform passthrough redirect to backend `/public-click`,
- handle silent logged-in non-admin claim exchange from WordPress cookie context,
- ignore WooCommerce admin users as shopper identity in public flow,
- route anonymous public visitors to checkout or primary product page according to link policy,
- perform public claim exchange through `OneClick_Purchase_Handler`.

### `OneClick_Purchase_Handler`

Owns opaque code exchange and immediate/session branch handling.

Legacy compatibility REST route:

```text
GET /wp-json/oneclick/v1/purchase?code={claim_code}
```

Responsibilities:

- receive opaque `code` for legacy/compatibility callbacks,
- prepare exchange context,
- call `/api/purchase/exchange-code`,
- branch on backend response:
  - `flow=purchase_session` -> set session cookie and redirect to session page,
  - `flow=immediate_purchase` -> explicit dev/test compatibility execution path when enabled.

It exposes shared helpers used by public and email claim landing:

- `get_exchange_context()`,
- `exchange_code_with_backend()`,
- `redirect_to_purchase_session()`.

### `OneClick_Purchase_Session`

Owns timed purchase window UI and session actions.

Routes:

- `GET /wp-json/oneclick/v1/session`
- `POST /wp-json/oneclick/v1/session/status`
- `POST /wp-json/oneclick/v1/session/add-item`
- `POST /wp-json/oneclick/v1/session/update-quantity`
- `POST /wp-json/oneclick/v1/session/remove-item`
- `POST /wp-json/oneclick/v1/session/extend`
- `POST /wp-json/oneclick/v1/session/cancel`
- `POST /wp-json/oneclick/v1/session/checkout`

Responsibilities:

- store session access token in HttpOnly cookie,
- proxy browser session actions to backend with license key server-side,
- render branded purchase window,
- auto-redirect checkout-mode sessions when timer reaches zero,
- report checkout redirect handoff to backend,
- process due sessions through Action Scheduler / WP-Cron,
- execute finalization and report result.

### `OneClick_Observability_Dashboard`

Owns the read-only OneClick Dashboard admin page.

Responsibilities:

- register the `Dashboard` submenu,
- call licensed backend `GET /api/observability/overview`,
- call licensed backend `GET /api/observability/sessions`,
- render merchant-facing funnel metrics for emails, links, sessions, checkout visits, orders and outcomes,
- render Technical Health as a secondary support section,
- display only masked/whitelisted backend data.

The dashboard is tenant-local. It shows only the licensed WordPress site's data. A separate Ocliby server-side admin dashboard is still needed for internal multi-tenant operations across all tenants/licenses.

### `OneClick_Order_Creator`

Owns WooCommerce order creation:

- immediate compatibility order creation,
- multi-item session order creation,
- locked session prices,
- billing/shipping copy from source/last order,
- payment metadata,
- HPOS-compatible `wc_create_order()`.

### `OneClick_Stripe`

Owns customer Stripe MIT execution:

- finds customer/payment method,
- creates off-session payment intent,
- uses backend-provided idempotency key,
- handles failures/authentication-required states.

### `OneClick_Email_Branding`

Owns admin UI for email and purchase experience branding:

- logo,
- company/sender data,
- colors,
- button style,
- font family,
- preview,
- domain provisioning/verification.

Purchase window loads branding server-side through backend API.

### Admin/Automation Support Classes

- `OneClick_Actions_Admin`
- `OneClick_Reactions_Admin`
- `OneClick_Rules_Admin`
- `OneClick_Product_Picker`
- `OneClick_Cart_Tracker`
- `OneClick_Periodic_Cron`
- `OneClick_AI_Setup`
- `OneClick_Compatibility_Test`
- `OneClick_JWT_Handler`
- `OneClick_JWT_Test`
- `OneClick_Stripe_Reconciliation`
- `OneClick_Product_Button`

JWT classes remain for compatibility/testing; they are not the primary browser purchase security model.
The immediate/JWT path is disabled by default and should be enabled only for explicit dev/test compatibility.

`OneClick_Cart_Tracker` also reports checkout completion for OneClick checkout sessions from the Woo order hook. It reads `oneclick_session_id` cart item metadata, stores safe order meta, and calls `POST /api/purchase-sessions/report-checkout-completion`.

---

## 5. Purchase Flows

### 5.1 Email Campaign Session Flow

```text
WooCommerce order
  -> OneClick_Campaign_Trigger::trigger_campaigns()
  -> POST /api/rules/evaluate
  -> schedule oneclick_send_campaign_email
  -> OneClick_Campaign_Trigger::send_campaign_email()
  -> POST /api/send-campaign-email completion_mode=purchase_session
  -> backend sends /click?id={short_id}
  -> backend 302 to /oneclick/email-claim/{claim_code}
  -> non-REST email claim landing reads WordPress cookies
  -> POST /api/purchase/exchange-code
  -> backend enforces expected user match for MIT/non-card and returns flow=purchase_session
  -> session cookie + redirect to /wp-json/oneclick/v1/session
  -> OneClick_Purchase_Session renders window
```

Email flow keeps the browser bridge:

- browser hits backend `/click`,
- backend redirects browser to WordPress non-REST email claim route with opaque code,
- WordPress provides cookie identity context,
- plugin redeems code outbound.

### 5.2 Public One-Click Link Flow

```text
Admin
  -> OneClick_Public_Links admin form
  -> POST /api/public-links
  -> backend returns public short_id
  -> plugin displays /oneclick/{short_id}

Browser click
  -> GET /oneclick/{short_id}
  -> plugin sets oneclick_public_visitor
  -> 302 /public-click?id={short_id}
  -> backend creates claim_code
  -> 302 /oneclick/claim/{claim_code}
  -> plugin non-REST claim landing
  -> logged-in non-admin silent cookie context OR admin/anonymous routing
  -> POST /api/purchase/exchange-code
  -> session cookie + purchase window
```

`/oneclick/{short_id}` is intentionally passthrough-only. It must not call exchange, send license key, create a session, decide product/price, or execute payment/order logic.

### 5.3 Session Window Flow

```text
GET /wp-json/oneclick/v1/session?session_id=...
  -> plugin reads HttpOnly session cookie
  -> POST /api/purchase-sessions/status
  -> render purchase window

Browser actions
  -> WordPress REST session endpoints
  -> plugin proxies to backend
  -> backend returns updated session state
```

### 5.4 Checkout Fallback

For `finalization_mode=checkout`:

- user can click **Continue to Checkout**,
- timer expiration auto-starts checkout redirect,
- plugin validates session state,
- plugin adds session items to WooCommerce cart with locked prices,
- plugin reports checkout redirect handoff to backend,
- Woo order hook reports checkout completion to backend when checkout creates an order,
- browser redirects to Woo checkout,
- no automatic order/payment happens before checkout.

### 5.5 Auto Finalization

For backend-claimed due sessions:

```text
OneClick_Purchase_Session::process_due_sessions()
  -> POST /api/purchase-sessions/claim-due
  -> for each claimed session:
       mit_purchase    -> Stripe MIT + Woo order
       non_card_order  -> Woo order without Stripe charge
  -> POST /api/purchase-sessions/report-finalization
```

Checkout sessions are not claimed by backend worker.

---

## 6. Backend Communication

All protected backend calls include:

```http
Content-Type: application/json
Accept: application/json
X-Site-URL: https://shop.example.com
X-License-Key: <license from wp_options>
```

Important backend endpoints:

| Plugin area | Endpoint |
|-------------|----------|
| Rules evaluation | `POST /api/rules/evaluate` |
| Compatibility email delivery test | `POST /api/email/test-delivery` |
| Campaign email | `POST /api/send-campaign-email` |
| Opaque code exchange | `POST /api/purchase/exchange-code` |
| Public link CRUD | `GET/POST/PUT/DELETE /api/public-links` |
| Session status/actions | `POST /api/purchase-sessions/*`, including checkout redirect reporting |
| Observability dashboard | `GET /api/observability/overview`, `GET /api/observability/sessions` |
| Checkout completion report | `POST /api/purchase-sessions/report-checkout-completion` |
| Branding | `GET/POST /api/branding`, preview endpoints |
| Domains | `POST /api/domains/provision`, `POST /api/domains/{id}/verify` |
| Licensing | `POST /api/license/check`, `POST /api/license/activate-from-session` |
| Carts | `POST /api/carts/activity`, `POST /api/carts/detect-abandoned` |
| Periodic | `POST /api/periodic/detect` |
| AI | `GET /api/ai/disclosure`, `POST /api/ai/setup`, `/api/ai/apply`, `/api/ai/generate-email` |

Public browser-only backend endpoints:

- `/click?id=...` for email links,
- `/public-click?id=...` for public marketing links.

The plugin never adds license headers to browser redirects.

---

## 7. WordPress Hooks And Routes

### WordPress Actions

| Hook | Purpose |
|------|---------|
| `before_woocommerce_init` | Declare HPOS compatibility |
| `plugins_loaded` | Load and instantiate plugin classes |
| `admin_menu` | Register admin pages |
| `admin_init` | Settings/form handlers |
| `admin_enqueue_scripts` | Admin CSS/JS |
| `rest_api_init` | Register purchase/session REST routes |
| `init` | Register public rewrite routes |
| `template_redirect` | Handle `/oneclick/*` routes |
| `query_vars` | Register public route query vars |
| `woocommerce_order_status_completed` | Trigger campaigns |
| `woocommerce_order_status_processing` | Trigger campaigns |
| `woocommerce_add_to_cart` | Track cart |
| `woocommerce_cart_item_removed` | Track cart |
| `woocommerce_after_cart_item_quantity_update` | Track cart |
| `woocommerce_checkout_order_processed` | Mark cart checkout and report OneClick checkout completion |
| `woocommerce_payment_complete` | Stripe reconciliation |
| `oneclick_send_campaign_email` | Send scheduled campaign email |
| `oneclick_daily_license_check` | License refresh |
| `oneclick_detect_abandoned` | Abandoned cart detection |
| `oneclick_periodic_check` | Periodic reminders |
| `oneclick_process_due_purchase_sessions` | Session finalization worker |

### Public Routes

```text
/oneclick/{short_id}
/oneclick/claim/{claim_code}
/oneclick/email-claim/{claim_code}
```

### REST Routes

```text
/wp-json/oneclick/v1/purchase
/wp-json/oneclick/v1/session
/wp-json/oneclick/v1/session/status
/wp-json/oneclick/v1/session/add-item
/wp-json/oneclick/v1/session/update-quantity
/wp-json/oneclick/v1/session/remove-item
/wp-json/oneclick/v1/session/extend
/wp-json/oneclick/v1/session/cancel
/wp-json/oneclick/v1/session/checkout
```

---

## 8. Admin Pages

```text
One-Click Purchase
├── Dashboard
├── Settings
├── Triggers
├── Actions
├── Scenarios
├── One-Click Links
├── Branding
├── AI Setup
├── Compatibility
└── Backend Token Diagnostics
```

### Settings

Stores backend URL, license, Stripe keys, purchase link mode, and completion mode.

### Dashboard

Read-only merchant view backed by licensed server-side backend calls:

- Overview: `Emails Sent`, `Links Clicked`, `Sessions Opened`, `Orders Created`.
- Email Campaigns: sent emails, sent product links, delivered/opened/clicked events, sessions and orders.
- Public Links: public link clicks, sessions, product page visits, checkout visits and orders.
- Purchase Outcomes: open now, went to checkout, auto purchases, orders created, cancelled and abandoned.
- Recent Sessions: safe references with client-friendly labels such as `Public link`, `Email campaign`, `Saved card`, `Checkout`, `Ordered`, `Open now`.
- Technical Health: due/retry, stale finalizing, failed, finalization mode split, source split and public endpoint guard.

`checkout` in Technical Health is a finalization mode, meaning the session must use WooCommerce checkout instead of automatic purchase. `Went to Checkout` / `Checkout Visits` are counted only when OneClick actually redirects the shopper to WooCommerce checkout and reports that handoff.

`Public Endpoint Guard` is a technical count of public endpoint calls such as `/public-click`. It helps support and abuse monitoring; it is not a purchase count.

Roadmap: Ocliby needs a separate server-side internal admin dashboard for all tenants. It should show tenant/license lists, activation state, tenant keys, per-tenant funnels, failed/stale sessions, rate-limit and abuse signals, and safe drilldown without exposing access tokens, license keys, Stripe secrets or raw payloads.

### Triggers / Actions / Scenarios

Thin admin UI for backend-managed actions, reactions, and rules.

### One-Click Links

Public marketing link CRUD:

- primary product,
- additional products,
- discount,
- anonymous destination: direct checkout or primary product page,
- status,
- generated `/oneclick/{short_id}` URL.

### Branding

Shared email and purchase experience branding.

### Compatibility / JWT Test

Diagnostics and legacy compatibility checks. JWT is not the primary runtime purchase security surface.

---

## 9. Session Worker And Order Execution

### Worker

`OneClick_Purchase_Session::schedule_worker()` uses:

- Action Scheduler when available,
- WP-Cron fallback every minute.

### MIT Purchase

For `finalization_mode=mit_purchase`:

1. backend claims due session,
2. plugin charges saved Stripe payment method,
3. plugin creates one multi-item WooCommerce order,
4. plugin reports finalization result.

### Non-Card Order

For `finalization_mode=non_card_order`:

1. backend claims due session,
2. plugin creates one WooCommerce order without Stripe charge,
3. plugin reports result.

### Checkout

For `finalization_mode=checkout`:

- backend worker does not claim session,
- browser checkout button/timer redirects to Woo checkout,
- checkout remains user-confirmed WooCommerce flow,
- checkout redirect handoff is reported without changing session status,
- checkout completion is reported after WooCommerce creates the order.

---

## 10. Security Model

### Public Link Security

- `/oneclick/{short_id}` contains no payload.
- Route is passthrough-only.
- `oneclick_public_visitor` contains only random anonymous identity and is `HttpOnly`, `SameSite=Lax`, `Secure=true` on production HTTPS.
- Backend `/public-click` creates opaque claim code.
- Logged-in non-admin user context is read silently from current WordPress cookies.
- WooCommerce admin users are not used as shopper identity.
- Anonymous user routes to direct checkout or primary product page and is never auto-charged.

### Email Link Security

- Email link goes to backend `/click`.
- Backend creates opaque claim code.
- Browser returns to plugin non-REST `/oneclick/email-claim/{claim_code}`.
- Plugin exchanges code through licensed backend request with cookie-derived identity context.
- Backend permits MIT/non-card only if current WordPress user matches the original email offer user; anonymous/mismatch is checkout-only.

### Session Security

- Session access token is stored in HttpOnly cookie.
- Browser actions go to WordPress REST only.
- WordPress proxies to backend with license key server-side.
- Backend validates session ID, access token, and tenant.
- Dashboard observability responses are read-only and masked; they do not expose access tokens, license keys, visitor keys, idempotency keys, Stripe secrets or raw payloads.

### Admin Security

- Admin pages require WooCommerce management capability.
- Forms use WordPress nonces.
- Inputs are sanitized.
- Outputs are escaped.
- License keys are masked in admin display.
- AI Setup includes one-time admin acknowledgement for provider-bound catalog/instruction data.
- AI Generate Email uses the same acknowledgement state and is disabled until the disclosure is acknowledged.
- AI disclosure states that product names, prices, categories, short descriptions, shop name, locale, currency and admin instruction text may be sent to the OneClick backend and configured AI provider. Customer emails, payment data, license keys, Stripe secrets, session access tokens and raw purchase payloads are not sent in AI requests.

### Removed Old Runtime Model

The old token-in-URL plugin purchase model is not the primary flow.

Removed from current runtime:

- local token blacklist class,
- blacklist test page,
- deprecated campaign manager.

Legacy JWT handler/test remain only for compatibility/diagnostics.

---

## 11. Data Stored In WordPress

### Options

Important options:

- `oneclick_backend_url`
- `oneclick_license_key`
- `oneclick_license_status`
- `oneclick_license_tier`
- `oneclick_purchase_completion_mode`
- `oneclick_purchase_link_mode`
- `oneclick_stripe_secret_key`
- `oneclick_stripe_publishable_key`
- `oneclick_backend_public_key`
- public route rewrite version

### Cookies

| Cookie | Purpose |
|--------|---------|
| `oneclick_public_visitor` | Anonymous public session rejoin identity |
| `oneclick_session_{hash}` | HttpOnly session access token for purchase window |

### WooCommerce Data

The plugin uses WooCommerce APIs for:

- reading products,
- reading users/orders,
- copying billing/shipping,
- adding cart items,
- creating orders.
- reporting OneClick checkout completion after Woo checkout order creation.

It does not use direct SQL for WooCommerce orders and is HPOS compatible.

---

## 12. Development And Release Notes

### Lint

```bash
php -l woo-oneclick-purchase.php
find includes -name '*.php' -print0 | xargs -0 -n1 php -l
```

### Current GitHub Remote

```text
origin https://github.com/Elim777/woocomail-plugin.git
```

### Release Caution

This plugin repo currently contains a much newer runtime than the old public commits:

- timed purchase sessions,
- public one-click links,
- non-REST claim landing,
- session checkout fallback,
- shared branding for email and purchase window,
- backend opaque code exchange.

Before packaging a ZIP, verify that the ZIP is created from this current folder and does not contain removed legacy files.
