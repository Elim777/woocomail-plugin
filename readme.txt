=== WooCommerce One-Click Purchase ===
Contributors: seventhdaylabs
Tags: woocommerce, one-click, email, purchase, stripe, campaigns
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Post-purchase email campaigns, public one-click marketing links, timed purchase windows, Stripe MIT execution, and WooCommerce checkout fallback.

== Description ==

WooCommerce One-Click Purchase connects WooCommerce to a FastAPI backend for campaign rules, branded emails, public one-click links, opaque claim exchange, and timed purchase sessions.

The WordPress plugin remains the WooCommerce execution layer:

* renders WordPress admin UI,
* collects WooCommerce product/order context,
* handles public shop URL passthrough,
* renders purchase windows,
* executes Stripe MIT charges when backend session state allows it,
* creates WooCommerce orders,
* redirects anonymous checkout sessions to WooCommerce checkout.

= Key Features =

* Post-purchase campaign emails
* Timed purchase sessions / purchase window
* Public One-Click Links for ads, chatbots, posts, and messages
* Backend short ID claim and opaque code exchange
* Stripe MIT for known saved-card users
* COD/BACS order creation for known non-card users
* Anonymous checkout fallback
* Session reopen/join for active public links
* Branding for email and purchase window experience
* Abandoned cart recovery
* Periodic reminders
* AI setup assistant
* HPOS compatible WooCommerce order creation

= Current Security Model =

Browser URLs never contain raw JWT purchase payloads or backend license keys.

Email links go first to the backend `/click?id=...`, which creates a short-lived opaque claim code. The plugin then redeems that code server-side through `/api/purchase/exchange-code` with its license key.

Public links use `/oneclick/{short_id}` as a shop URL passthrough. The plugin does not create a session or send a license key in this step. It redirects to backend `/public-click?id=...`; the backend creates an opaque claim code and returns the browser to `/oneclick/claim/{claim_code}`.

Logged-in public users must confirm with a WordPress nonce before user/payment context is used. Anonymous users remain checkout-only.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/woo-oneclick-purchase/`.
2. Activate the plugin in WordPress admin.
3. Ensure Composer dependencies are present if building from source.
4. Go to One-Click Purchase -> Settings.
5. Configure Backend URL and License Key.
6. Configure Stripe keys for MIT purchases.
7. Create Triggers, Actions, Scenarios, or One-Click Links.

== Frequently Asked Questions ==

= Does this work with HPOS? =

Yes. Order creation uses WooCommerce APIs and declares HPOS compatibility.

= Does the browser receive the license key? =

No. The license key is stored server-side in WordPress options and is sent only from the plugin to the backend.

= Are public links automatic charges? =

No. Anonymous public visitors are checkout-only. Logged-in public users require WordPress nonce confirmation before the plugin sends user/payment context to the backend.

= Can an active public session be reopened? =

Yes. Logged-in public sessions use `user:{id}` and anonymous sessions use the `oneclick_public_visitor` cookie to rejoin an active timed session.

== Changelog ==

= 1.1.0 =
* Added timed purchase sessions and purchase window.
* Added public One-Click Links with backend `/public-click` claim.
* Added non-REST `/oneclick/claim/{claim_code}` landing for public links.
* Added anonymous visitor cookie session rejoin.
* Added checkout auto-redirect for checkout-mode sessions.
* Unified email and purchase window branding.
* Kept legacy immediate purchase mode as compatibility fallback.

= 1.0.0 =
* Initial post-purchase campaign and one-click purchase flow.
