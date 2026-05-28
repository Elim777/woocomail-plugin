# WooCommerce One-Click Purchase Plugin

WordPress/WooCommerce plugin for post-purchase email campaigns, public one-click marketing links, timed purchase windows, Stripe MIT execution, checkout fallback, abandoned cart recovery, periodic reminders, branding, and AI-assisted campaign setup.

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
9. Browser returns to plugin REST purchase route:

   ```text
   /wp-json/oneclick/v1/purchase?code={claim_code}
   ```

10. Plugin calls backend `POST /api/purchase/exchange-code` with license headers.
11. Backend returns `flow=purchase_session`.
12. Plugin sets HttpOnly session access cookie and redirects to purchase window.

Compatibility value `immediate_purchase` still exists for legacy/test fallback. It uses the same opaque exchange pattern but may return immediate payload instead of a timed session.

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
6. Logged-in users must confirm via WordPress nonce before user/payment context is used.
7. Anonymous users continue as checkout-only.
8. Plugin exchanges claim code via licensed `POST /api/purchase/exchange-code`.
9. Backend opens or joins a `PurchaseSession`.

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
- browser redirects to Woo checkout,
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
├── Settings          Backend URL, license, Stripe, completion mode
├── Triggers          Action definitions
├── Actions           Offer/reaction definitions
├── Scenarios         Trigger -> action rules
├── One-Click Links   Public marketing links
├── Branding          Email and purchase experience branding
├── AI Setup          AI campaign suggestions
├── Compatibility     System checks and test email
└── JWT Test          Legacy/compatibility JWT diagnostics
```

## Plugin Structure

```text
woo-oneclick-purchase/
├── woo-oneclick-purchase.php
├── composer.json
├── includes/
│   ├── class-api-client.php
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
| `oneclick_purchase_completion_mode` | `purchase_session` | `purchase_session` or `immediate_purchase` |
| `oneclick_purchase_link_mode` | `all_with_cart_fallback` | Compatibility payment behavior |
| `oneclick_license_key` | empty | Backend license key |

## Security

- Public shop URL contains only backend-generated opaque `short_id`.
- `/oneclick/{short_id}` is passthrough-only.
- Public logged-in user context requires WordPress nonce confirmation.
- Browser never sees backend license key.
- Browser never sees raw JWT purchase payload.
- Plugin sends license key only in server-side backend requests.
- Session access token is stored in HttpOnly cookie.
- WooCommerce orders are created only after backend session state allows execution.
- Anonymous public sessions are checkout-only and never auto-charged.
- Admin forms use WordPress nonces and `manage_woocommerce` capability.
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
