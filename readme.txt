=== WooCommerce One-Click Purchase ===
Contributors: seventhdaylabs
Tags: woocommerce, one-click, email, purchase, stripe
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

One-click email purchases with Stripe MIT + Post-Purchase Email Campaigns for WooCommerce.

== Description ==

**WooCommerce One-Click Purchase** enables customers to complete purchases directly from email with a single click, using saved payment methods via Stripe MIT (Merchant Initiated Transactions).

= Key Features =

* **Post-Purchase Email Campaigns** - Automatically send upsell emails after order completion
* **One-Click Purchases** - Customers buy directly from email without logging in
* **Stripe MIT Integration** - Charge saved payment methods off-session
* **Campaign Manager** - Configure trigger products, offer products, discounts, and email content
* **Bot Detection** - Protect against email scanner false triggers
* **HPOS Compatible** - Full support for WooCommerce High-Performance Order Storage

= How It Works =

1. **Customer completes a purchase** (e.g., Dog Food)
2. **Plugin triggers campaign** (configured by admin)
3. **Email sent with one-click offer** (e.g., Dog Leash with 10% discount)
4. **Customer clicks → Purchase completed** (using saved payment method)
5. **Order created in WooCommerce** (just like normal checkout)

= Requirements =

* PHP 8.1 or higher
* WooCommerce 7.5 or higher
* Sodium PHP extension (for cryptographic operations)
* Stripe account
* Backend API for email sending (FastAPI)

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/woo-oneclick-purchase/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Run `composer install` in the plugin directory to install dependencies
4. Go to One-Click → Settings to configure
5. Add your Stripe API keys
6. Configure your backend API URL
7. Create email campaigns in One-Click → Campaigns

== Frequently Asked Questions ==

= Does this work with HPOS? =

Yes! The plugin is fully compatible with WooCommerce High-Performance Order Storage (HPOS).

= What payment methods are supported? =

Currently, only Stripe payment methods are supported via Merchant Initiated Transactions (MIT).

= How are emails sent? =

Emails are sent via a separate backend API using SendGrid for high deliverability.

= Is it secure? =

Yes. The plugin uses EdDSA (Ed25519) cryptographic signatures, token blacklisting, and bot detection to prevent unauthorized purchases.

== Changelog ==

= 1.0.0 =
* Initial release
* Post-purchase email campaigns
* One-click purchases from email
* Stripe MIT integration
* Campaign manager UI
* HPOS compatibility
