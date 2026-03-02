# Plugin Architecture Documentation
## WooCommerce One-Click Purchase Plugin

**Version:** 1.1.0
**Author:** Seventh Day Labs
**Last Updated:** 2026-03-02

---

## Table of Contents
1. [System Overview](#1-system-overview)
2. [Directory Structure](#2-directory-structure)
3. [Requirements](#3-requirements)
4. [Plugin Lifecycle](#4-plugin-lifecycle)
5. [Class Reference](#5-class-reference)
6. [Purchase Flow](#6-purchase-flow)
7. [Backend Communication](#7-backend-communication)
8. [WordPress Hooks](#8-wordpress-hooks)
9. [Admin Pages](#9-admin-pages)
10. [Payment Methods](#10-payment-methods)
11. [Token Security](#11-token-security)
12. [Data Storage](#12-data-storage)
13. [WP-Cron Jobs](#13-wp-cron-jobs)
14. [JavaScript & CSS](#14-javascript--css)

---

## 1. System Overview

The WooCommerce One-Click Purchase plugin is a **thin client** that communicates with a FastAPI backend for all business logic. This architecture protects intellectual property by keeping campaign evaluation, email sending, and AI features on the backend.

### What the Plugin Does
- Registers WooCommerce hooks for order events and cart tracking
- Delegates rule evaluation to backend API
- Generates JWT tokens via backend
- Handles the actual purchase (Stripe MIT, COD, BACS) via WooCommerce
- Creates WooCommerce orders programmatically
- Provides WordPress admin UI for Actions/Reactions/Rules management
- Manages EdDSA keypair for JWT verification
- Maintains token blacklist (Redis or Transients)

### What the Plugin Delegates to Backend
- Campaign rule evaluation (`POST /api/rules/evaluate`)
- JWT token generation (`POST /api/tokens/generate`)
- Email sending (`POST /api/send-email`)
- License management (`POST /api/license/*`)
- AI suggestions (`POST /api/ai/*`)
- Domain management (`POST /api/domains/*`)
- Branding management (`GET/POST /api/branding`)

---

## 2. Directory Structure

```
woo-oneclick-purchase/
│
├── woo-oneclick-purchase.php    # Main plugin file (activation, hooks, class loading)
├── readme.txt                   # WordPress.org plugin readme
├── composer.json                # PHP dependencies (Predis, Firebase JWT)
├── composer.lock
├── composer.phar
│
├── includes/                    # PHP classes (~6,700 lines total)
│   │
│   │  # Core Infrastructure
│   ├── class-api-client.php         # Singleton HTTP client for backend API
│   ├── class-settings.php           # Admin settings page + license management
│   │
│   │  # Purchase Flow (core)
│   ├── class-jwt-handler.php        # JWT generation (via backend) + verification
│   ├── class-token-blacklist.php    # Replay prevention (Redis + Transients)
│   ├── class-purchase-handler.php   # REST endpoint: /oneclick/v1/purchase
│   ├── class-order-creator.php      # WooCommerce order creation (HPOS)
│   ├── class-stripe.php             # Stripe MIT payment processing
│   ├── class-stripe-reconciliation.php  # Payment method data fix
│   │
│   │  # Campaign System
│   ├── class-campaign-trigger.php   # Order hook -> rule evaluation -> email scheduling
│   ├── class-actions-admin.php      # Triggers admin UI (CRUD)
│   ├── class-reactions-admin.php    # Offers admin UI (CRUD)
│   ├── class-rules-admin.php        # Rules admin UI (CRUD)
│   │
│   │  # Email & Branding
│   ├── class-email-branding.php     # Branding editor + domain management
│   │
│   │  # Automation
│   ├── class-cart-tracker.php       # Abandoned cart tracking via WC hooks
│   ├── class-periodic-cron.php      # Periodic email cron job
│   │
│   │  # PRO Features
│   ├── class-ai-setup.php           # AI campaign suggestions (PRO only)
│   │
│   │  # Utilities
│   ├── class-compatibility-test.php # System checks + test purchase
│   ├── class-product-picker.php     # Select2 product/category AJAX
│   ├── class-product-button.php     # TODO: One-click buy button (Phase 6)
│   │
│   │  # Test & Deprecated
│   ├── class-jwt-test.php           # JWT test page
│   ├── class-blacklist-test.php     # Token blacklist test page
│   └── class-campaign-manager.php.deprecated  # Old campaign system (removed)
│
├── assets/
│   ├── css/
│   │   ├── admin.css            # Admin table styles
│   │   ├── branding.css         # Branding editor styles
│   │   └── ai-setup.css         # AI setup page styles
│   └── js/
│       ├── admin-actions.js     # Admin page interactions
│       ├── branding.js          # Color picker + media upload
│       ├── domain-setup.js      # Domain provisioning AJAX
│       ├── ai-setup.js          # AI suggestions AJAX
│       └── ai-email-generate.js # AI email generation AJAX
│
├── vendor/                      # Composer dependencies
│   ├── predis/predis/           # Redis client
│   └── firebase/php-jwt/        # JWT library (installed but unused - native sodium used)
│
└── scripts/
    └── import-test-products.php # Test product import utility
```

---

## 3. Requirements

| Requirement | Minimum | Notes |
|-------------|---------|-------|
| PHP | 8.1+ | Required for Sodium extension |
| WordPress | 6.4+ | |
| WooCommerce | 7.5+ | Tested up to 9.9 |
| Sodium extension | Required | For EdDSA (Ed25519) cryptography |
| Backend API | Running | FastAPI backend must be accessible |
| Stripe account | Required | For payment processing |
| Redis | Optional | Recommended for token blacklist in production |

---

## 4. Plugin Lifecycle

### Activation
```
register_activation_hook()
├── Generate Ed25519 keypair (sodium_crypto_sign_keypair)
├── Store keys in wp_options (base64 encoded)
├── Set default backend URL (http://localhost:8001)
├── Schedule WP-Cron jobs:
│   ├── oneclick_daily_license_check (daily)
│   ├── oneclick_detect_abandoned (every 15 minutes)
│   └── oneclick_periodic_check (daily)
└── Flush rewrite rules
```

### Loading (every page load)
```
plugins_loaded hook
├── Check WooCommerce active
├── Load all class files (19 classes)
├── Initialize OneClick_API_Client (Singleton)
├── Instantiate all components:
│   ├── OneClick_Settings
│   ├── OneClick_JWT_Handler
│   ├── OneClick_Token_Blacklist
│   ├── OneClick_Cart_Tracker
│   ├── OneClick_Email_Branding
│   ├── OneClick_Campaign_Trigger
│   ├── OneClick_Purchase_Handler
│   ├── OneClick_Order_Creator
│   ├── OneClick_Stripe
│   ├── OneClick_Stripe_Reconciliation
│   ├── OneClick_Actions_Admin
│   ├── OneClick_Reactions_Admin
│   ├── OneClick_Rules_Admin
│   ├── OneClick_AI_Setup
│   ├── OneClick_Compatibility_Test
│   ├── OneClick_Product_Picker
│   ├── OneClick_Periodic_Cron
│   └── OneClick_Product_Button (if user logged in)
└── Register plugin action links
```

### Deactivation
```
register_deactivation_hook()
├── wp_clear_scheduled_hook('oneclick_daily_license_check')
├── wp_clear_scheduled_hook('oneclick_detect_abandoned')
├── wp_clear_scheduled_hook('oneclick_periodic_check')
└── wp_clear_scheduled_hook('oneclick_send_campaign_email')
```

---

## 5. Class Reference

### Core Classes

#### `OneClick_API_Client` (class-api-client.php)
**Pattern:** Singleton
**Purpose:** Centralized HTTP communication with backend API

| Method | Description |
|--------|-------------|
| `get_instance()` | Returns singleton instance |
| `post($endpoint, $data)` | POST request to backend |
| `get($endpoint, $params)` | GET request to backend |
| `get_headers()` | Returns headers with X-License-Key + X-Site-URL |
| `health_check()` | Tests backend connectivity |

All requests include:
- `Content-Type: application/json`
- `X-Site-URL: {site_url}`
- `X-License-Key: {license_key}` (if available)

#### `OneClick_Settings` (class-settings.php)
**Purpose:** Admin settings page, license management

| Method | Description |
|--------|-------------|
| `add_menu()` | Registers main menu + Settings submenu |
| `register_settings()` | Registers wp_options fields |
| `refresh_license_from_backend()` | Calls `/api/license/check` |
| `sync_backend_public_key()` | Fetches backend's EdDSA public key |
| `handle_license_activation()` | Activates license from Stripe session |

Settings stored in `wp_options`:
- `oneclick_backend_url` - Backend API URL
- `oneclick_stripe_secret_key` - Stripe secret key
- `oneclick_stripe_publishable_key` - Stripe publishable key
- `oneclick_redis_host` / `oneclick_redis_port` - Redis config
- `oneclick_private_key` / `oneclick_public_key` - EdDSA keypair
- `oneclick_backend_public_key` - Backend's public key
- `oneclick_license_key` / `_tier` / `_status` / `_quota` / etc.

#### `OneClick_JWT_Handler` (class-jwt-handler.php)
**Purpose:** JWT token generation and verification

| Method | Description |
|--------|-------------|
| `generate_via_backend($payload)` | Generates JWT via backend API |
| `generate($payload)` | Local fallback generation (if backend unreachable) |
| `verify($token)` | Verifies JWT signature (backend or local key) |
| `base64url_encode/decode()` | URL-safe base64 encoding |

- **Algorithm:** EdDSA (Ed25519) using native PHP `sodium_crypto_sign_*`
- **Verification:** Accepts tokens signed by either backend or local key
- **Fallback:** If backend unreachable, generates locally

#### `OneClick_Token_Blacklist` (class-token-blacklist.php)
**Purpose:** Prevent replay attacks (same purchase link used twice)

| Method | Description |
|--------|-------------|
| `is_token_used($jti)` | Check if token already consumed |
| `mark_token_used($jti)` | Add token to blacklist |
| `init_redis()` | Initialize Redis connection |

- **Primary storage:** Redis (Predis client) with 48h TTL
- **Fallback:** WordPress Transients if Redis unavailable
- **Key format:** `oneclick:used_token:{jti}`

### Purchase Flow Classes

#### `OneClick_Purchase_Handler` (class-purchase-handler.php)
**Purpose:** Core REST endpoint that processes one-click purchases

**REST Endpoint:** `GET /wp-json/oneclick/v1/purchase?token=<JWT>`

| Method | Description |
|--------|-------------|
| `handle_purchase(WP_REST_Request)` | Main purchase processor |
| `detect_payment_method($user_id)` | Finds saved payment method |
| `render_success_page($order)` | HTML success page |
| `render_error_page($message)` | HTML error page |

**Purchase Flow:**
1. Extract and verify JWT token
2. Check token not in blacklist
3. Validate product exists and is purchasable
4. Detect payment method (Stripe/COD/BACS)
5. Process payment
6. Create WooCommerce order
7. Blacklist token (prevent reuse)
8. Return HTML success/error page

#### `OneClick_Order_Creator` (class-order-creator.php)
**Purpose:** Creates WooCommerce orders programmatically

| Method | Description |
|--------|-------------|
| `create_order($params)` | Creates order with product, addresses, payment |
| `copy_addresses_from_last_order($order, $user_id)` | Copies billing/shipping |
| `copy_shipping_from_last_order($order, $user_id)` | Copies shipping method + meta |

- **HPOS compatible:** Uses `wc_create_order()`
- **Shipping:** Copies full shipping method including meta (Packeta, DPD, etc.)
- **Status by payment:** Stripe=paid, COD=processing, BACS=on-hold, Test=completed
- **Meta added:** `_oneclick_purchase`, `_oneclick_campaign_id`, etc.

#### `OneClick_Stripe` (class-stripe.php)
**Purpose:** Stripe Merchant Initiated Transaction (MIT) processing

| Method | Description |
|--------|-------------|
| `charge_saved_payment_method($params)` | Creates off-session payment |
| `get_customer_payment_method($customer_id)` | Finds saved PM |
| `handle_authentication_required()` | 3DS handling |

**Payment method detection chain:**
1. User meta `_stripe_default_payment_method`
2. WC Payment Tokens API
3. Stripe API fallback (queries customer's payment methods)

### Campaign System Classes

#### `OneClick_Campaign_Trigger` (class-campaign-trigger.php)
**Purpose:** Orchestrates the entire campaign flow

**Flow:**
```
Order completed/processing
    → Collect product IDs + category IDs
    → POST /api/rules/evaluate (backend)
    → For each matched reaction:
        → Schedule WP-Cron event with delay_minutes
    → On cron fire:
        → Generate JWT per product via backend
        → POST /api/send-email via backend
```

#### `OneClick_Actions_Admin` (class-actions-admin.php)
- CRUD admin page for trigger definitions
- Types: purchase, abandoned_cart, periodic
- Product/category picker via Select2 AJAX

#### `OneClick_Reactions_Admin` (class-reactions-admin.php)
- CRUD admin page for offer definitions
- Discount type/percent, delay minutes
- Email subject/body with AI generation option

#### `OneClick_Rules_Admin` (class-rules-admin.php)
- CRUD admin page for Action -> Reaction mappings
- Status toggle (active/inactive)
- Displays action + reaction details

### Email & Domain Classes

#### `OneClick_Email_Branding` (class-email-branding.php)
- Branding editor (logo, colors, button text, sender info)
- WordPress media uploader for logo
- wp-color-picker for color fields
- Domain management: subdomain + custom domain provisioning
- AJAX handlers for domain operations

### Automation Classes

#### `OneClick_Cart_Tracker` (class-cart-tracker.php)
- Hooks: `wc_add_to_cart`, `wc_cart_item_removed`, `wc_after_cart_item_quantity_update`
- Sends cart activity to backend `POST /api/carts/activity`
- 15-minute cron detects abandoned carts via `POST /api/carts/detect-abandoned`

#### `OneClick_Periodic_Cron` (class-periodic-cron.php)
- Daily cron sends customer data to backend `POST /api/periodic/detect`
- Backend evaluates periodic rules and sends emails

---

## 6. Purchase Flow (Detailed)

```
Customer clicks link in email
    ↓
GET /wp-json/oneclick/v1/purchase?token=<JWT>
    ↓
┌─ OneClick_Purchase_Handler::handle_purchase()
│
├─ 1. JWT Verification
│   └─ OneClick_JWT_Handler::verify($token)
│       ├─ Decode base64url header.payload.signature
│       ├─ Verify EdDSA signature (backend key or local key)
│       └─ Check expiration (exp claim)
│
├─ 2. Replay Prevention
│   └─ OneClick_Token_Blacklist::is_token_used($jti)
│       ├─ Check Redis (primary)
│       └─ Check Transients (fallback)
│
├─ 3. Validate Purchase Data
│   ├─ Product exists (wc_get_product)
│   ├─ Product is purchasable
│   ├─ User exists (get_user_by)
│   └─ Detect payment method
│
├─ 4. Process Payment
│   ├─ Stripe MIT: charge_saved_payment_method()
│   │   └─ off_session=true, confirm=true
│   ├─ COD: No charge (pay on delivery)
│   └─ BACS: No charge (bank transfer pending)
│
├─ 5. Create Order
│   └─ OneClick_Order_Creator::create_order()
│       ├─ wc_create_order() (HPOS)
│       ├─ Add product line item
│       ├─ Apply discount (if any)
│       ├─ Copy billing/shipping from last order
│       ├─ Copy shipping method + meta
│       ├─ Set appropriate status
│       └─ Add order meta + notes
│
├─ 6. Blacklist Token
│   └─ OneClick_Token_Blacklist::mark_token_used($jti)
│
└─ 7. Return Response
    ├─ Success: HTML page with order details + animations
    └─ Error: HTML page with error message
```

---

## 7. Backend Communication

### API Client Pattern
All backend calls go through `OneClick_API_Client` (Singleton):

```php
$client = OneClick_API_Client::get_instance();
$response = $client->post('/api/rules/evaluate', [
    'site_url' => get_site_url(),
    'event_type' => 'purchase',
    'product_ids' => [123, 456],
    'category_ids' => [10, 20],
]);
```

### Headers Sent
```
Content-Type: application/json
X-Site-URL: https://your-shop.com
X-License-Key: your-license-key (if available)
```

### Backend Endpoints Used by Plugin

| Plugin Class | Backend Endpoint | When Called |
|--------------|-----------------|------------|
| JWT Handler | `POST /api/tokens/generate` | Generating purchase tokens |
| JWT Handler | `GET /api/public-key` | Syncing backend public key |
| Settings | `POST /api/license/check` | Daily license check |
| Settings | `POST /api/license/activate-from-session` | After Stripe checkout |
| Campaign Trigger | `POST /api/rules/evaluate` | On order completion |
| Campaign Trigger | `POST /api/send-email` | Sending campaign emails |
| Actions Admin | `POST/GET/PUT/DELETE /api/actions` | CRUD operations |
| Reactions Admin | `POST/GET/PUT/DELETE /api/reactions` | CRUD operations |
| Rules Admin | `POST/GET/PUT/DELETE /api/rules` | CRUD operations |
| Email Branding | `GET/POST/PUT /api/branding` | Branding management |
| Email Branding | `POST /api/domains/provision` | Domain setup |
| Email Branding | `POST /api/domains/{id}/verify` | Domain verification |
| Cart Tracker | `POST /api/carts/activity` | Cart events |
| Cart Tracker | `POST /api/carts/detect-abandoned` | Abandoned detection |
| Periodic Cron | `POST /api/periodic/detect` | Periodic check |
| AI Setup | `POST /api/ai/setup` | Generate suggestions |
| AI Setup | `POST /api/ai/apply` | Apply suggestions |
| Reactions Admin | `POST /api/ai/generate-email` | AI email copy |

---

## 8. WordPress Hooks

### Actions Registered
| Hook | Class | Purpose |
|------|-------|---------|
| `plugins_loaded` | Main | Initialize all classes |
| `before_woocommerce_init` | Main | HPOS compatibility |
| `admin_menu` | Settings, Actions, Reactions, Rules, Branding, AI, Compat | Admin menu |
| `admin_init` | Settings | Register settings |
| `admin_enqueue_scripts` | Multiple | CSS/JS assets |
| `rest_api_init` | Purchase Handler | Register REST endpoint |
| `woocommerce_order_status_completed` | Campaign Trigger | Trigger campaigns |
| `woocommerce_order_status_processing` | Campaign Trigger | Trigger campaigns |
| `woocommerce_payment_complete` | Stripe Reconciliation | Fix PM data |
| `woocommerce_add_to_cart` | Cart Tracker | Track cart |
| `woocommerce_cart_item_removed` | Cart Tracker | Track cart |
| `woocommerce_after_cart_item_quantity_update` | Cart Tracker | Track cart |
| `woocommerce_checkout_order_processed` | Cart Tracker | Mark checkout |
| `oneclick_send_campaign_email` | Campaign Trigger | Scheduled email send |
| `oneclick_daily_license_check` | Main | License check |
| `oneclick_detect_abandoned` | Cart Tracker | Abandoned detection |
| `oneclick_periodic_check` | Periodic Cron | Periodic emails |

### Filters Registered
| Hook | Class | Purpose |
|------|-------|---------|
| `plugin_action_links_{plugin}` | Main | Settings link |
| `cron_schedules` | Main | 15-minute schedule |

### AJAX Handlers (wp_ajax_*)
| Action | Class | Purpose |
|--------|-------|---------|
| `oneclick_domain_provision` | Email Branding | Provision domain |
| `oneclick_domain_verify` | Email Branding | Verify domain |
| `oneclick_domain_status` | Email Branding | Check domain status |
| `oneclick_ai_generate` | AI Setup | Generate suggestions |
| `oneclick_ai_apply` | AI Setup | Apply suggestions |
| `oneclick_ai_generate_email` | Reactions Admin | Generate email copy |
| `oneclick_compat_check` | Compatibility | Run system checks |
| `oneclick_test_email` | Compatibility | Send test email |

---

## 9. Admin Pages

### Menu Structure
```
One-Click Purchase (dashicons-email-alt, position 56)
├── Settings          → class-settings.php
│   ├── Tab: General  (Backend URL, Stripe keys, Redis)
│   └── Tab: License  (Key, tier, quota, upgrade button)
├── Triggers          → class-actions-admin.php
├── Actions           → class-reactions-admin.php
├── Scenarios         → class-rules-admin.php
├── Branding          → class-email-branding.php
│   ├── Email Branding (logo, colors, button)
│   └── Domain Setup  (subdomain/custom)
├── AI Setup (PRO)    → class-ai-setup.php
└── Compatibility     → class-compatibility-test.php
```

All admin pages require `manage_woocommerce` capability.

---

## 10. Payment Methods

| Method | Status | Description |
|--------|--------|-------------|
| **Stripe MIT** | Full support | Off-session payment with saved card |
| **COD** | Full support | Cash on Delivery - order set to "processing" |
| **BACS** | Full support | Bank Transfer - order set to "on-hold" |
| **Test** | For testing | Compatibility test mode |

### Stripe Payment Method Detection
1. Check user meta `_stripe_default_payment_method`
2. Check WC Payment Tokens API
3. Fallback: Query Stripe API for customer's payment methods

---

## 11. Token Security

### EdDSA (Ed25519) Implementation
- Uses native PHP `sodium_crypto_sign_*` functions
- Not using Firebase JWT library (installed but unused)
- Keypair generated on plugin activation
- Backend generates tokens, plugin can verify them
- Plugin can also generate locally as fallback

### Token Lifecycle
```
1. Generated (backend /api/tokens/generate)
2. Embedded in short URL (backend creates purchase link)
3. Sent in email to customer
4. Customer clicks → token extracted from URL
5. Verified (signature + expiration)
6. Checked against blacklist
7. Used for purchase
8. Added to blacklist (never reusable)
```

### Replay Prevention
- **Redis (primary):** TTL-based expiry (48 hours)
- **Transients (fallback):** WordPress transient API
- Token JTI (unique ID) is the blacklist key

---

## 12. Data Storage

### wp_options (Plugin Settings)
| Key | Description |
|-----|-------------|
| `oneclick_backend_url` | Backend API URL |
| `oneclick_stripe_secret_key` | Stripe secret key |
| `oneclick_stripe_publishable_key` | Stripe publishable key |
| `oneclick_redis_host` | Redis host (default: 127.0.0.1) |
| `oneclick_redis_port` | Redis port (default: 6379) |
| `oneclick_private_key` | EdDSA private key (base64) |
| `oneclick_public_key` | EdDSA public key (base64) |
| `oneclick_backend_public_key` | Backend's EdDSA public key |
| `oneclick_license_key` | License key string |
| `oneclick_license_tier` | free / pro / enterprise |
| `oneclick_license_status` | active / expired |
| `oneclick_license_quota` | Monthly email quota |
| `oneclick_license_expires` | Expiration date |

### wp_usermeta (Per-User Data)
| Key | Description |
|-----|-------------|
| `_stripe_customer_id` | Stripe customer ID |
| `_stripe_default_payment_method` | Default saved payment method |

### Order Meta (Per-Order Data)
| Key | Description |
|-----|-------------|
| `_oneclick_purchase` | Flag: order created via one-click |
| `_oneclick_campaign_id` | Source campaign/rule ID |
| `_stripe_payment_intent_id` | Stripe payment intent |

### Redis / Transients (Temporary)
| Key Pattern | TTL | Description |
|-------------|-----|-------------|
| `oneclick:used_token:{jti}` | 48h | Token blacklist entry |

---

## 13. WP-Cron Jobs

| Schedule | Hook | Handler | Purpose |
|----------|------|---------|---------|
| Daily | `oneclick_daily_license_check` | Main plugin | Check license status with backend |
| Every 15 min | `oneclick_detect_abandoned` | Cart Tracker | Detect and process abandoned carts |
| Daily | `oneclick_periodic_check` | Periodic Cron | Send periodic customer reminders |
| Single event | `oneclick_send_campaign_email` | Campaign Trigger | Send individual campaign email (with delay) |

**Production Note:** WP-Cron depends on site traffic. For reliable scheduling, configure a real system cron:
```
*/15 * * * * curl -s https://your-shop.com/wp-cron.php > /dev/null 2>&1
```

---

## 14. JavaScript & CSS

### JavaScript Files
| File | Purpose | Dependencies |
|------|---------|-------------|
| `admin-actions.js` | Admin page interactions | jQuery |
| `branding.js` | Color picker + media upload | jQuery, wp-color-picker, media |
| `domain-setup.js` | Domain provisioning AJAX | jQuery |
| `ai-setup.js` | AI suggestions AJAX | jQuery |
| `ai-email-generate.js` | AI email generation | jQuery |

All AJAX calls are nonce-protected via `check_ajax_referer()`.

### CSS Files
| File | Purpose |
|------|---------|
| `admin.css` | Admin table column widths, status badges |
| `branding.css` | Branding editor layout |
| `ai-setup.css` | AI suggestion cards, loading spinner |
