# WooCommerce One-Click Purchase Plugin

WordPress/WooCommerce plugin enabling one-click email purchases with post-purchase campaign automation. Works as a thin client with a FastAPI backend.

## Overview

When a customer completes a purchase, the plugin evaluates campaign rules and sends targeted email offers. Customers can complete additional purchases with a single click using their saved payment method - no checkout required.

## Quick Start

### Prerequisites
- PHP 8.1+ with Sodium extension
- WordPress 6.4+
- WooCommerce 7.5+
- [Backend API](https://github.com/YOUR_ORG/woo-oneclick-backend) running
- Stripe account (for payment processing)
- Redis (optional, recommended for production)

### Installation

1. **Upload plugin files**
   ```
   wp-content/plugins/woo-oneclick-purchase/
   ```

2. **Install PHP dependencies**
   ```bash
   cd wp-content/plugins/woo-oneclick-purchase
   php composer.phar install
   ```

3. **Activate plugin** in WordPress admin (Plugins page)

4. **Configure settings** (One-Click > Settings):
   - Set Backend URL (your FastAPI backend)
   - Add Stripe API keys
   - (Optional) Configure Redis for token blacklist

5. **Verify setup** (One-Click > Compatibility):
   - Run system checks
   - Send test email

### First Campaign Setup

1. Go to **One-Click > Triggers** - Create a trigger (e.g., "Customer buys Dog Food")
2. Go to **One-Click > Actions** - Create an offer (e.g., "Send email offering Dog Leash with 10% off")
3. Go to **One-Click > Scenarios** - Connect the trigger to the offer
4. A customer completes a purchase matching the trigger -> email is sent automatically

## How It Works

```
1. Customer completes purchase in WooCommerce
2. Plugin detects order → sends to backend for rule evaluation
3. Backend matches rules → returns applicable offers
4. Plugin schedules email (with optional delay)
5. Backend sends branded email with one-click purchase link
6. Customer clicks link → arrives at plugin REST endpoint
7. Plugin verifies JWT → charges saved payment method
8. New order created automatically → success page shown
```

## Features

| Feature | Description | Tier |
|---------|-------------|------|
| **Purchase Triggers** | Auto-trigger on order completion | FREE |
| **Email Offers** | Customizable product offers with discounts | FREE |
| **One-Click Payments** | Stripe MIT (off-session) charges | FREE |
| **COD/BACS Support** | Cash on delivery and bank transfer | FREE |
| **Campaign Rules** | Flexible trigger → action mapping | FREE |
| **Token Security** | EdDSA JWT with replay prevention | FREE |
| **HPOS Compatible** | WooCommerce High-Performance Order Storage | FREE |
| **Email Branding** | Custom logo, colors, sender info | PRO |
| **Custom Domains** | Send from your own domain | PRO |
| **Abandoned Cart** | Auto-detect and recover abandoned carts | PRO |
| **Periodic Reminders** | Time-based re-engagement emails | PRO |
| **AI Setup** | AI-generated campaign suggestions | PRO |

## Admin Menu

```
One-Click Purchase
├── Settings        - Backend URL, Stripe keys, license
├── Triggers        - Campaign trigger definitions
├── Actions         - Email offer definitions
├── Scenarios       - Trigger → Action rules
├── Branding        - Email design + domain setup
├── AI Setup        - AI campaign generation (PRO)
└── Compatibility   - System checks + test purchase
```

## Plugin Structure

```
woo-oneclick-purchase/
├── woo-oneclick-purchase.php  # Main plugin file
├── includes/                  # PHP classes (23 files, ~6,700 lines)
│   ├── class-api-client.php       # Backend HTTP client (Singleton)
│   ├── class-settings.php         # Settings + license management
│   ├── class-jwt-handler.php      # JWT generation + verification
│   ├── class-token-blacklist.php  # Replay attack prevention
│   ├── class-purchase-handler.php # Core purchase REST endpoint
│   ├── class-order-creator.php    # WooCommerce order creation
│   ├── class-stripe.php           # Stripe MIT payments
│   ├── class-campaign-trigger.php # Campaign orchestration
│   ├── class-actions-admin.php    # Triggers admin UI
│   ├── class-reactions-admin.php  # Offers admin UI
│   ├── class-rules-admin.php      # Rules admin UI
│   ├── class-email-branding.php   # Branding + domain management
│   ├── class-cart-tracker.php     # Abandoned cart tracking
│   ├── class-periodic-cron.php    # Periodic email cron
│   ├── class-ai-setup.php         # AI suggestions (PRO)
│   └── ...
├── assets/css/                # Admin stylesheets
├── assets/js/                 # Admin JavaScript
└── vendor/                    # Composer dependencies
```

## Security

- **EdDSA (Ed25519):** Modern JWT signing using native PHP Sodium
- **Token replay prevention:** Redis-backed blacklist with Transients fallback
- **WordPress nonces:** All forms and AJAX calls verified
- **Capability checks:** `manage_woocommerce` required for all admin operations
- **Input sanitization:** `sanitize_text_field()`, `absint()`, `esc_url_raw()` throughout
- **Output escaping:** `esc_html()`, `esc_attr()`, `esc_url()` on all output
- **HPOS compatible:** No direct database queries for orders

## Configuration

### Required Settings (One-Click > Settings)
| Setting | Description |
|---------|-------------|
| Backend URL | FastAPI backend address (must be HTTPS in production) |
| Stripe Secret Key | `sk_test_*` or `sk_live_*` |
| Stripe Publishable Key | `pk_test_*` or `pk_live_*` |

### Optional Settings
| Setting | Default | Description |
|---------|---------|-------------|
| Redis Host | 127.0.0.1 | For token blacklist |
| Redis Port | 6379 | Redis port |

### WP-Cron Jobs
| Schedule | Purpose |
|----------|---------|
| Daily | License check with backend |
| Every 15 min | Abandoned cart detection |
| Daily | Periodic customer reminders |

**Production tip:** Replace WP-Cron with a real system cron:
```
*/15 * * * * curl -s https://your-shop.com/wp-cron.php > /dev/null 2>&1
```

## Payment Methods

| Method | How It Works |
|--------|-------------|
| **Stripe** | Merchant Initiated Transaction - charges saved card off-session |
| **COD** | Creates order with "processing" status (pay on delivery) |
| **BACS** | Creates order with "on-hold" status (bank transfer pending) |

## REST API

The plugin registers one public REST endpoint:

```
GET /wp-json/oneclick/v1/purchase?token=<JWT>
```

This endpoint handles the actual one-click purchase when a customer clicks the email link. Security is provided by JWT verification, not WordPress authentication.

## Requirements

| Component | Version | Required |
|-----------|---------|----------|
| PHP | 8.1+ | Yes |
| Sodium extension | - | Yes |
| WordPress | 6.4+ | Yes |
| WooCommerce | 7.5+ | Yes |
| Backend API | Running | Yes |
| Redis | Any | Recommended |
| Stripe account | - | Yes |

## Development

### Testing
The plugin includes test pages accessible from admin:
- JWT test page (class-jwt-test.php)
- Token blacklist test page (class-blacklist-test.php)
- Compatibility test page with system checks

### Dependencies (Composer)
- `predis/predis ^2.0` - Redis client for token blacklist
- `firebase/php-jwt ^7.0` - Installed but unused (native Sodium used instead)

## License

Proprietary - Seventh Day Labs. All rights reserved.

## Related

- [Backend API Repository](https://github.com/YOUR_ORG/woo-oneclick-backend) - FastAPI backend (business logic)
- [ARCHITECTURE.md](ARCHITECTURE.md) - Detailed architecture documentation
