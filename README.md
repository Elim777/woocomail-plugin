# WooCommerce One-Click Purchase Plugin

WordPress/WooCommerce plugin for post-purchase email campaigns, public one-click marketing links, timed purchase windows, Stripe MIT execution, checkout fallback, checkout completion reporting, abandoned cart recovery, periodic reminders, branding, AI-assisted campaign setup, and read-only OneClick observability.

The plugin is the WordPress-side execution layer. The FastAPI backend is the source of truth for rules, public links, offers, opaque claims, purchase sessions, timing, branding data, email delivery, and licensing.

## Current Architecture

```text
Browser
  |
  | clicks, redirects, WordPress cookies, session cookies
  v
WordPress + WooCommerce plugin
  |
  | outbound HTTPS JSON
  | X-License-Key + X-Site-URL on protected calls
  v
FastAPI backend
```

The plugin owns:

- WooCommerce hooks and admin UI,
- WordPress login/nonce/cookie context,
- public shop URL passthrough routes,
- purchase window rendering,
- checkout fallback,
- checkout redirect/completion reporting,
- read-only operational dashboard,
- Stripe MIT charge execution,
- WooCommerce order creation,
- Action Scheduler / WP-Cron session finalization worker.

The backend owns:

- rule evaluation,
- email sending,
- public link and offer state,
- short ID claim,
- opaque code exchange,
- purchase session state and timer,
- observability metrics and safe dashboard data,
- license and tenant validation.

Security invariant:

- Browser never receives backend license key.
- Browser never receives raw JWT purchase payload.
- Public URLs contain only opaque IDs.
- Plugin calls backend outbound before executing payment/order logic.
- Backend never calls inbound WordPress callbacks.

## Main Purchase Flows

### 1. Email Campaign Purchase Sessions

Default completion mode is:

```text
oneclick_purchase_completion_mode = purchase_session
```

Flow:

1. WooCommerce order event triggers `OneClick_Campaign_Trigger`.
2. Plugin sends event context to backend `POST /api/rules/evaluate`.
3. Backend returns matched reactions.
4. Plugin schedules campaign email via WP-Cron.
5. Plugin sends `POST /api/send-campaign-email` with `completion_mode=purchase_session`.
6. Backend creates `PurchaseOffer` and `PurchaseOfferItem.short_id`.
7. Email link points to backend `/click?id={short_id}`.
8. Backend turns short ID into an opaque `claim_code`.
9. Browser returns to plugin non-REST email identity gate:

   ```text
   /oneclick/email-claim/{claim_code}
   ```

10. WordPress reads normal login cookies and the plugin prepares cookie-derived identity context.
11. Plugin calls backend `POST /api/purchase/exchange-code` with license headers.
12. Backend returns `flow=purchase_session`; MIT/non-card is allowed only when the current WordPress user matches the original email offer user.
13. Anonymous or mismatched email clicks fall back to checkout-only behavior.
14. Plugin sets HttpOnly session access cookie and redirects to purchase window or checkout fallback.

Compatibility value `immediate_purchase` still exists for explicit legacy/test fallback, but is hidden/default-disabled unless a dev/test flag enables it.

### 2. Public One-Click Marketing Links

Public links are for ads, AI chatbots, posts, messages, and manual communication.

Admin flow:

1. Admin opens **One-Click Links**.
2. Plugin collects WooCommerce product snapshots.
3. Plugin calls `POST /api/public-links`.
4. Backend creates `PublicOneClickLink.short_id`.
5. Plugin displays shop URL:

   ```text
   https://shop.example.com/oneclick/{short_id}
   ```

Click flow:

1. Browser opens `/oneclick/{short_id}`.
2. Plugin route is passthrough only:
   - sanitizes `short_id`,
   - sets/refreshes anonymous `oneclick_public_visitor`,
   - sends no license key,
   - creates no session,
   - performs no exchange,
   - redirects to backend `/public-click?id={short_id}`.
3. Backend creates `PublicOneClickClaim.claim_id`.
4. Backend redirects browser to:

   ```text
   /oneclick/claim/{claim_code}
   ```

5. Plugin non-REST claim landing reads normal WordPress login cookies.
6. Logged-in non-admin users are handled silently from WordPress cookie context without an extra confirmation screen.
7. Users with WooCommerce management/admin capability are not used as shopper identity.
8. Anonymous users follow public link policy: direct Woo checkout or primary product page.
9. Plugin exchanges claim code via licensed `POST /api/purchase/exchange-code` when a session/checkout transport is needed.
10. Backend opens or joins a `PurchaseSession`.

Session rejoin:

- logged-in public user uses `user:{id}`,
- anonymous public visitor uses stable `oneclick_public_visitor`,
- closing and reopening the same public link during the active timer should join the same session.

### 3. Purchase Window

`OneClick_Purchase_Session` renders a branded timed purchase window.

It provides REST routes:

```text
GET  /wp-json/oneclick/v1/session?session_id=...
POST /wp-json/oneclick/v1/session/status
POST /wp-json/oneclick/v1/session/add-item
POST /wp-json/oneclick/v1/session/update-quantity
POST /wp-json/oneclick/v1/session/remove-item
POST /wp-json/oneclick/v1/session/extend
POST /wp-json/oneclick/v1/session/cancel
POST /wp-json/oneclick/v1/session/checkout
```

The browser talks only to WordPress. WordPress proxies session actions to the backend with the license key server-side.

Checkout mode behavior:

- before timer expires, user can click **Continue to Checkout**,
- when checkout-mode timer reaches zero, UI auto-calls the checkout endpoint,
- plugin adds locked-price session items to WooCommerce cart,
- plugin reports checkout redirect handoff to the backend for observability,
- browser redirects to Woo checkout,
- Woo checkout order hook reports checkout completion to the backend when an order is created,
- no payment or order is created before checkout.

### 4. Auto Finalization

`OneClick_Purchase_Session` schedules a worker through Action Scheduler when available, otherwise WP-Cron.

Worker flow:

1. Plugin calls `POST /api/purchase-sessions/claim-due`.
2. Backend returns sessions that may auto-finalize.
3. Plugin executes based on `finalization_mode`:
   - `mit_purchase`: Stripe MIT charge + one multi-item WooCommerce order,
   - `non_card_order`: one WooCommerce order without Stripe charge,
   - `checkout`: not claimed by backend worker.
4. Plugin reports result via `POST /api/purchase-sessions/report-finalization`.

## Admin Menu

```text
One-Click Purchase
├── Dashboard         Read-only merchant funnel: emails, clicks, sessions, checkout visits, orders and technical health
├── Settings          Backend URL, license, Stripe, completion mode
├── Triggers          Action definitions
├── Actions           Offer/reaction definitions
├── Scenarios         Trigger -> action rules
├── One-Click Links   Public marketing links
├── Branding          Email and purchase experience branding
├── AI Setup          AI campaign suggestions
├── Compatibility     Current-architecture system checks and diagnostic email delivery
└── Backend Token Diagnostics
```

## Plugin Structure

```text
woo-oneclick-purchase/
├── woo-oneclick-purchase.php
├── composer.json
├── includes/
│   ├── class-api-client.php
│   ├── class-logger.php
│   ├── class-settings.php
│   ├── class-campaign-trigger.php
│   ├── class-purchase-handler.php
│   ├── class-purchase-session.php
│   ├── class-public-links.php
│   ├── class-order-creator.php
│   ├── class-stripe.php
│   ├── class-stripe-reconciliation.php
│   ├── class-actions-admin.php
│   ├── class-reactions-admin.php
│   ├── class-rules-admin.php
│   ├── class-product-picker.php
│   ├── class-email-branding.php
│   ├── class-observability-dashboard.php
│   ├── class-cart-tracker.php
│   ├── class-periodic-cron.php
│   ├── class-ai-setup.php
│   ├── class-compatibility-test.php
│   ├── class-jwt-handler.php
│   ├── class-jwt-test.php
│   └── class-product-button.php
├── assets/
│   ├── css/
│   └── js/
├── vendor/
└── scripts/
```

## Production Logging

OneClick plugin logs are routed to WooCommerce logs via `wc_get_logger()`, so production debugging can happen in `WooCommerce -> Status -> Logs` without relying on `wp-content/debug.log`.

Primary log sources:

- `oneclick-core`
- `oneclick-backend`
- `oneclick-email`
- `oneclick-public`
- `oneclick-session`
- `oneclick-stripe`
- `oneclick-order`
- `oneclick-cart`
- `oneclick-ai`

The logger preserves the existing readable flow format, including `FÁZA`, `Step x/29`, emoji markers and separators. If WooCommerce logging is unavailable, it falls back to PHP `error_log()`.

Removed legacy runtime pieces:

- `class-token-blacklist.php`
- `class-blacklist-test.php`
- `class-campaign-manager.php.deprecated`
- WordPress-side JWT-in-URL purchase flow

## Configuration

Required settings:

| Setting | Purpose |
|---------|---------|
| Backend URL | FastAPI backend base URL |
| License Key | Sent only server-side in `X-License-Key` |
| Stripe Secret Key | Used by plugin for customer MIT charges |
| Stripe Publishable Key | Stripe frontend/admin support |

Important options:

| Option | Default | Purpose |
|--------|---------|---------|
| `oneclick_backend_url` | `https://woocomail-api.onrender.com` | Backend API base URL |
| `oneclick_purchase_completion_mode` | `purchase_session` | `purchase_session`; `immediate_purchase` is dev/test-only when explicitly enabled |
| `oneclick_purchase_link_mode` | `all_with_cart_fallback` | Compatibility payment behavior |
| `oneclick_license_key` | empty | Backend license key |

## Security

- Public shop URL contains only backend-generated opaque `short_id`.
- `/oneclick/{short_id}` is passthrough-only.
- Public logged-in shopper context is read silently from current WordPress cookies on non-REST claim landing.
- Admin users are not used as public shopper identity.
- `oneclick_public_visitor` is `HttpOnly`, `SameSite=Lax`, and `Secure=true` on production HTTPS while preserving Local compatibility.
- Browser never sees backend license key.
- Browser never sees raw JWT purchase payload.
- Plugin sends license key only in server-side backend requests.
- Session access token is stored in HttpOnly cookie.
- WooCommerce orders are created only after backend session state allows execution.
- Anonymous public sessions are checkout-only and never auto-charged.
- Anonymous public links can route directly to Woo checkout or primary product page according to admin policy.
- Checkout redirects are reported to the backend without changing session status.
- Checkout completion is reported from the Woo order hook for checkout sessions; the backend records completion but does not create the order or charge the payment method.
- OneClick Dashboard data comes from licensed server-side backend requests and is read-only.
- Dashboard/session rows are masked and do not expose access tokens, license keys, visitor keys, Stripe secrets or raw payloads.
- Dashboard is tenant-local: the merchant sees only this WordPress site's data.
- Client metrics mean:
  - `Emails Sent`: campaign, cart recovery and periodic emails created by OneClick.
  - `Links Clicked`: email link clicks plus public link clicks.
  - `Sessions Opened`: OneClick purchase sessions started in the selected time window.
  - `Orders Created`: WooCommerce orders attributed to OneClick.
  - `Product Page Visits`: public anonymous shoppers sent to a product page; this is not checkout.
  - `Checkout Visits` / `Went to Checkout`: shoppers actually sent from OneClick to WooCommerce checkout.
  - `Auto Purchases`: saved-card or saved non-card purchase attempts.
  - `Cancelled`: sessions cancelled by the shopper.
  - `Abandoned`: checkout sessions that expired or reached checkout without an order.
- Technical Health is a support section, not the main merchant KPI surface:
  - `Due / Retry`: sessions the worker can claim.
  - `Stale Finalizing`: sessions stuck during finalization.
  - `Failed`: sessions requiring review.
  - `By Finalization Mode`: technical path split; `checkout` means checkout-mode session, not necessarily an actual checkout visit.
  - `By Source`: public link vs email campaign source split.
  - `Public Endpoint Guard`: public endpoint calls such as `/public-click`, not purchases.
- License keys shown in admin UI are masked unless explicitly revealed/copied.
- AI Setup requires a one-time admin acknowledgement before AI features can be used.
- AI Generate Email uses the same acknowledgement state and is disabled until disclosure is acknowledged.
- AI disclosure explains that product names, prices, categories, short descriptions, shop name, locale, currency and admin instruction text may be sent to the OneClick backend and configured AI provider. Customer emails, payment data, license keys, Stripe secrets, session access tokens and raw purchase payloads are not sent in AI requests.
- Admin forms use WordPress nonces and `manage_woocommerce` capability.
- Ocliby still needs a separate server-side internal admin dashboard outside the plugin. That future dashboard should show all tenants/licenses, tenant keys, activation state, per-tenant funnel counts, failed/stale sessions and public endpoint abuse signals without exposing tokens, license keys, Stripe secrets or raw payloads.
- Order creation uses WooCommerce APIs and is HPOS compatible.

## Requirements

| Component | Version | Required |
|-----------|---------|----------|
| PHP | 8.1+ | Yes |
| WordPress | 6.4+ | Yes |
| WooCommerce | 7.5+ | Yes |
| Backend API | Running | Yes |
| Stripe account | Required for MIT purchases | Yes |
| Action Scheduler | WooCommerce bundled | Preferred for session worker |

## Development Checks

```bash
php -l woo-oneclick-purchase.php
find includes -name '*.php' -print0 | xargs -0 -n1 php -l
```

## Related

- Backend repository: `https://github.com/Elim777/woocomail-server-deploy`
- Plugin repository: `https://github.com/Elim777/woocomail-plugin`
- [ARCHITECTURE.md](ARCHITECTURE.md)
