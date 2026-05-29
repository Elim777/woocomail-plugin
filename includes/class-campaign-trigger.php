<?php
/**
 * Campaign Trigger (Thin Client)
 *
 * Triggers post-purchase actions via backend API.
 * Since v1.1.0: evaluates rules on backend, schedules reactions via WP-Cron.
 *
 * Flow:
 *   Order completed/processing
 *     → POST /api/rules/evaluate (backend decides which rules match)
 *     → Backend returns reactions[] with delay_minutes
 *     → Plugin schedules wp_schedule_single_event for each reaction
 *     → Cron fires → POST /api/send-email (backend sends email)
 *
 * @package WooOneClick
 * @since 1.0.0 — Local campaign system (custom post type)
 * @since 1.1.0 — Thin client: backend rule evaluation + email sending
 */

if (!defined('ABSPATH')) {
    exit;
}

class OneClick_Campaign_Trigger {

    public function __construct() {
        // WooCommerce order completion hooks (HPOS compatible)
        add_action('woocommerce_order_status_completed', [$this, 'trigger_campaigns'], 10, 1);
        add_action('woocommerce_order_status_processing', [$this, 'trigger_campaigns'], 10, 1);
        add_action('woocommerce_order_status_on-hold', [$this, 'trigger_campaigns'], 10, 1);

        // Scheduled email send action (backend API)
        add_action('oneclick_send_campaign_email', [$this, 'send_campaign_email'], 10, 2);
    }

    /**
     * Trigger campaigns for completed order
     *
     * Calls backend POST /api/rules/evaluate to find matching rules.
     * Schedules WP-Cron events for each reaction returned.
     *
     * HPOS Compatible - uses wc_get_order()
     *
     * @param int $order_id WooCommerce order ID
     */
    public function trigger_campaigns($order_id) {
        error_log('');
        error_log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        error_log('🛒 FÁZA 1: NÁKUP NA ESHOPE → VYHODNOTENIE PRAVIDIEL');
        error_log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        error_log('');
        error_log('📌 Step 1/29 | ESHOP → PLUGIN');
        error_log(sprintf('   Zákazník zaplatil objednávku #%d na eshope.', $order_id));
        error_log(sprintf('   WooCommerce zavolal do_action("%s", %d).', current_filter(), $order_id));
        error_log('   Plugin má zaregistrovaný add_action() callback na tento hook');
        error_log('   (class-campaign-trigger.php:29), čím sa spustila táto funkcia trigger_campaigns().');
        error_log('');

        $order = wc_get_order($order_id);
        if (!$order) {
            error_log(sprintf('   ❌ Objednávka #%d neexistuje. Flow končí.', $order_id));
            error_log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            return;
        }

        $user_id = $order->get_user_id();
        $order_currency = $order->get_currency();

        if (!$user_id) {
            error_log(sprintf('   ⚠️ Objednávka #%d nemá user_id (guest checkout). Flow končí.', $order_id));
            error_log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            return;
        }

        if ($order->get_meta('_oneclick_campaigns_handled') === 'yes') {
            error_log(sprintf('   ⏭️ Objednávka #%d už bola spracovaná OneClick campaign triggerom. Preskakujem duplicitu.', $order_id));
            error_log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            return;
        }

        $source_payment_method = $this->normalize_payment_method($order->get_payment_method());
        $purchase_link_mode = get_option('oneclick_purchase_link_mode', 'all_with_cart_fallback');
        $click_behavior = $this->is_card_payment_method($source_payment_method) ? 'mit_purchase' : 'cart_checkout';

        error_log('📦 Step 2/29 | PLUGIN (čítanie dát z objednávky)');
        error_log('   Plugin iteruje cez $order->get_items() a pre každú položku volá');
        error_log('   wc_get_product($product_id) aby získal kategórie cez $product->get_category_ids().');
        error_log(sprintf(
            '   Objednávka #%d: user_id=%d, status=%s, total=%s %s, payment_method=%s',
            $order_id,
            $user_id,
            $order->get_status(),
            $order->get_total(),
            $order_currency,
            $source_payment_method
        ));
        error_log(sprintf(
            '   Purchase link mode=%s → click_behavior=%s',
            $purchase_link_mode,
            $click_behavior
        ));

        if ($purchase_link_mode === 'card_only' && !$this->is_card_payment_method($source_payment_method)) {
            error_log('   ⚠️ Nastavenie Card only: zdrojová objednávka nie je Stripe/card. Email/action sa neposiela.');
            $this->mark_order_handled($order, 'card_only_non_card_skip');
            error_log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            return;
        }

        // Collect product IDs and category IDs from order
        $product_ids = [];
        $category_ids = [];
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $product_ids[] = $product_id;

            $product = wc_get_product($product_id);
            if ($product) {
                $cat_ids = $product->get_category_ids();
                $category_ids = array_merge($category_ids, $cat_ids);
                error_log(sprintf(
                    '   📎 Položka: product_id=%d, "%s", cena=%s, kategórie=[%s]',
                    $product_id,
                    $product->get_name(),
                    $product->get_price(),
                    implode(',', $cat_ids)
                ));
            }
        }

        $product_ids = array_unique($product_ids);
        $category_ids = array_unique(array_map('intval', $category_ids));

        if (empty($product_ids)) {
            error_log(sprintf('   ⚠️ Objednávka #%d nemá položky. Flow končí.', $order_id));
            error_log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            return;
        }

        error_log(sprintf(
            '   Zozbierané: product_ids=[%s], category_ids=[%s]',
            implode(',', $product_ids),
            implode(',', $category_ids)
        ));
        error_log('   Tieto ID sa pošlú na backend, kde sa porovnajú s uloženými pravidlami (rules).');
        error_log('');

        // Call backend to evaluate rules
        $request_data = [
            'site_url'     => site_url(),
            'event_type'   => 'purchase',
            'product_ids'  => array_values($product_ids),
            'category_ids' => array_values($category_ids),
            'user_id'      => $user_id,
            'order_id'     => $order_id,
        ];

        error_log('📤 Step 3/29 | PLUGIN → BACKEND (HTTP request)');
        error_log('   Plugin volá OneClick_API_Client::instance()->post("/api/rules/evaluate", $data).');
        error_log('   class-api-client.php::post() zavolá wp_remote_post() s JSON body.');
        error_log('   Do hlavičiek pridá X-License-Key (z get_option("oneclick_license_key"))');
        error_log('   a X-Site-URL (z site_url()). TLS šifruje celé spojenie.');
        error_log('   Payload: ' . wp_json_encode($request_data));
        error_log('');
        error_log('   ══ Backend teraz vykonáva Steps 4-6 (viď Render logy) ══');
        error_log('');

        $api = OneClick_API_Client::instance();
        $result = $api->post('/api/rules/evaluate', $request_data);

        if (is_wp_error($result)) {
            error_log('📥 Step 7/29 | BACKEND → PLUGIN (odpoveď)');
            error_log('   ❌ Backend rule evaluation zlyhalo: ' . $result->get_error_message());
            error_log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            return;
        }

        $matched_rules = $result['matched_rules'] ?? 0;
        $reactions = $result['reactions'] ?? [];

        error_log('📥 Step 7/29 | BACKEND → PLUGIN (odpoveď)');
        error_log('   wp_remote_post() vrátilo HTTP 200. Plugin dekódoval JSON odpoveď');
        error_log('   cez json_decode() v class-api-client.php::handle_response().');
        error_log(sprintf(
            '   Výsledok: matched_rules=%d, počet reakcií=%d',
            $matched_rules,
            count($reactions)
        ));

        if (empty($reactions)) {
            error_log('   Pre tieto produkty neexistuje žiadne aktívne pravidlo. Flow končí.');
            error_log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            return;
        }

        error_log('   Backend matchol pravidlá a vrátil reakcie. Plugin ich teraz naplánuje do WP-Cron.');

        // Schedule each reaction via WP-Cron
        $scheduled_count = 0;
        $failed_count = 0;
        foreach ($reactions as $index => $reaction) {
            $delay_minutes = $reaction['delay_minutes'] ?? 5;
            $timestamp = time() + ($delay_minutes * 60);

            // Pass reaction data + order context as serialized payload
            $payload = [
                'reaction'              => $reaction,
                'order_id'              => $order_id,
                'user_id'               => $user_id,
                'source_payment_method' => $source_payment_method,
                'click_behavior'        => $click_behavior,
            ];

            $scheduled = wp_schedule_single_event(
                $timestamp,
                'oneclick_send_campaign_email',
                [$order_id, $payload]
            );

            if ($scheduled === false) {
                $failed_count++;
            } else {
                $scheduled_count++;
            }

            error_log(sprintf(
                '   ⏰ Reakcia #%d: "%s" | delay=%d min | wp_schedule_single_event()=%s',
                $index + 1,
                $reaction['reaction_name'] ?? 'unknown',
                $delay_minutes,
                $scheduled === false ? '❌ FAILED' : '✅ OK'
            ));
            error_log(sprintf(
                '      Ponúkané produkty: [%s] | Zľava: %s%%',
                implode(', ', $reaction['offer_products'] ?? []),
                $reaction['discount_percent'] ?? 0
            ));
        }

        if ($scheduled_count > 0 && $failed_count === 0) {
            $this->mark_order_handled($order, 'campaigns_scheduled');
        } elseif ($failed_count > 0) {
            error_log(sprintf('   ⚠️ Niektoré reakcie sa nepodarilo naplánovať (%d failed). Order meta guard sa nenastaví.', $failed_count));
        }

        error_log('');
        error_log('   wp_schedule_single_event() uložilo cron záznam do wp_options("cron").');
        error_log('   WordPress spustí callback pri najbližšom HTTP requeste po uplynutí delay.');
        error_log('   ⚠️ WP-Cron nie je skutočný cron — závisí od návštev stránky.');
        error_log('');
        error_log('   ⏳ Čakám na WP-Cron... (pokračovanie vo Fáze 2)');
        error_log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        error_log('');
    }

    /**
     * Send campaign email via backend API
     *
     * This runs when the scheduled event fires.
     * Generates token via backend, then sends email via backend.
     *
     * @param int   $order_id Original order ID
     * @param array $payload  Reaction data + order context
     */
    public function send_campaign_email($order_id, $payload) {
        error_log('');
        error_log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        error_log('📧 FÁZA 2: WP-CRON FIRES → ODOSLANIE CAMPAIGN EMAILU');
        error_log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        error_log('');
        error_log('⏰ Step 8/29 | WP-CRON → PLUGIN');
        error_log('   WordPress spracoval HTTP request od návštevníka a skontroloval wp_options(\'cron\').');
        error_log('   Naplánovaný čas uplynul → WordPress zavolal do_action(\'oneclick_send_campaign_email\',');
        error_log(sprintf('   $order_id=%d, $payload). Tento callback je zaregistrovaný', $order_id));
        error_log('   v __construct() tejto triedy (class-campaign-trigger.php:32).');
        error_log('');

        $reaction = $payload['reaction'] ?? [];
        $user_id = $payload['user_id'] ?? 0;
        $source_payment_method = $payload['source_payment_method'] ?? 'unknown';
        $click_behavior = $payload['click_behavior'] ?? 'mit_purchase';
        if (!in_array($click_behavior, ['mit_purchase', 'cart_checkout'], true)) {
            $click_behavior = 'mit_purchase';
        }
        $completion_mode = $this->get_purchase_completion_mode($order_id, $payload);

        // Get user data
        $user = get_user_by('id', $user_id);
        if (!$user) {
            error_log(sprintf('   ❌ User #%d neexistuje. Flow končí.', $user_id));
            error_log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            return;
        }

        // Get offer products from reaction
        // Backend returns 'offer_products' (array of product IDs)
        $offer_product_ids = $reaction['offer_products'] ?? [];

        if (empty($offer_product_ids)) {
            error_log('   ❌ Reakcia nemá žiadne offer_products. Flow končí.');
            error_log('   Dostupné reaction keys: ' . implode(', ', array_keys($reaction)));
            error_log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            return;
        }

        $customer_name = $user->display_name ?: $user->first_name ?: $user->user_login;
        $discount      = floatval($reaction['discount_percent'] ?? 0);
        $discount_type = $reaction['discount_type'] ?? 'none';

        error_log('🔧 Step 9/29 | PLUGIN (príprava dát pre backend)');
        error_log('   Plugin zostavuje kontext pre backend: načítava produkty cez wc_get_product(),');
        error_log('   počíta zľavnené ceny, pripravuje pole offer_products[].');
        error_log(sprintf(
            '   Zákazník: user_id=%d, email=%s, display_name=%s',
            $user_id,
            $user->user_email,
            $user->display_name
        ));
        error_log(sprintf(
            '   Zľava: %s%% (%s) | Počet ponúkaných produktov: %d',
            $discount,
            $discount_type,
            count($offer_product_ids)
        ));
        error_log(sprintf(
            '   Zdrojová platba=%s | click_behavior=%s | completion_mode=%s',
            $source_payment_method,
            $click_behavior,
            $completion_mode
        ));

        // Build offer products array with discounted prices for backend
        $offer_products = [];
        foreach ($offer_product_ids as $pid) {
            $product = wc_get_product($pid);
            if (!$product) {
                error_log(sprintf('   ⚠️ Product #%d sa nepodarilo načítať, preskakujem.', $pid));
                continue;
            }

            $original_price  = (float) $product->get_price();
            $discounted_price = $discount > 0
                ? round($original_price * (1 - ($discount / 100)), 2)
                : $original_price;

            $image_id = $product->get_image_id();
            if (!$image_id && $product->is_type('variation')) {
                $parent_product = wc_get_product($product->get_parent_id());
                if ($parent_product) {
                    $image_id = $parent_product->get_image_id();
                }
            }

            $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'medium') : '';

            $offer_product = [
                'product_id'   => $pid,
                'product_name' => $product->get_name(),
                'price'        => $discounted_price,
                'currency'     => get_woocommerce_currency(),
            ];

            if (!empty($image_url)) {
                $offer_product['image_url'] = esc_url_raw($image_url);
            }

            error_log(sprintf(
                '   📎 Product #%d: "%s" | pôvodná=%.2f | po zľave=%.2f %s | obrázok=%s',
                $pid,
                $product->get_name(),
                $original_price,
                $discounted_price,
                get_woocommerce_currency(),
                !empty($image_url) ? 'áno' : 'nie'
            ));

            $offer_products[] = $offer_product;
        }

        if (empty($offer_products)) {
            error_log(sprintf('   ❌ Žiadne validné produkty na odoslanie pre objednávku #%d. Flow končí.', $order_id));
            error_log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            return;
        }

        error_log('');
        error_log('📤 Step 10/29 | PLUGIN → BACKEND (HTTP request)');
        error_log('   Plugin volá OneClick_API_Client::instance()->post("/api/send-campaign-email", $data).');
        error_log('   Toto je JEDEN HTTP request. Backend z neho interně:');
        error_log('   — vygeneruje JWT tokeny (generate_purchase_token() pre každý produkt)');
        error_log('   — vytvorí purchase linky (create_purchase_link() → short_id v DB)');
        error_log('   — odošle email cez SendGrid API');
        error_log(sprintf(
            '   Príjemca: %s | Produktov: %d | Objednávka: #%d | click_behavior=%s | completion_mode=%s',
            $user->user_email,
            count($offer_products),
            $order_id,
            $click_behavior,
            $completion_mode
        ));
        error_log('');
        error_log('   ══ Backend teraz vykonáva Steps 11-14 (viď Render logy) ══');
        error_log('');

        $api    = OneClick_API_Client::instance();
        $result = $api->post('/api/send-campaign-email', [
            'site_url'       => site_url(),
            'to_email'       => $user->user_email,
            'customer_name'  => $customer_name,
            'user_id'        => $user_id,
            'order_id'       => $order_id,
            'offer_products' => $offer_products,
            'click_behavior' => $click_behavior,
            'completion_mode' => $completion_mode,
            'email_subject'  => $reaction['email_subject'] ?? null,
            'email_body'     => $reaction['email_body'] ?? null,
        ]);

        if (is_wp_error($result)) {
            error_log(sprintf('   ❌ Backend email sending zlyhalo pre order #%d: %s', $order_id, $result->get_error_message()));
            error_log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            return;
        }

        $ids = implode(', ', $result['purchase_ids'] ?? []);
        $first_purchase_id = $result['purchase_ids'][0] ?? ($result['purchase_id'] ?? '');
        $backend_url = rtrim(get_option('oneclick_backend_url', 'https://woocomail-api.onrender.com'), '/');
        $first_link = $first_purchase_id ? $backend_url . '/click?id=' . $first_purchase_id : 'n/a';
        error_log('   ✅ Backend potvrdil odoslanie emailu.');
        error_log(sprintf('   purchase_ids: [%s] — tieto krátke ID sú v emailových linkoch', $ids ?: 'n/a'));
        error_log(sprintf('   Link v emaili vyzerá: %s', $first_link));
        error_log('');
        error_log('   📬 Email doručený zákazníkovi. Čakám na klik... (pokračovanie vo Fáze 3)');
        error_log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        error_log('');
    }

    private function normalize_payment_method($method) {
        $method = sanitize_key((string) $method);

        if (in_array($method, ['stripe', 'stripe_cc', 'stripe_sepa', 'stripe_ideal'], true)) {
            return 'stripe';
        }

        return $method ?: 'unknown';
    }

    private function is_card_payment_method($method) {
        return $this->normalize_payment_method($method) === 'stripe';
    }

    private function mark_order_handled($order, $reason) {
        $order->update_meta_data('_oneclick_campaigns_handled', 'yes');
        $order->update_meta_data('_oneclick_campaigns_handled_reason', sanitize_key($reason));
        $order->save();
    }

    private function get_purchase_completion_mode($order_id, $payload) {
        $mode = get_option('oneclick_purchase_completion_mode', 'purchase_session');
        if (!in_array($mode, ['purchase_session', 'immediate_purchase'], true)) {
            $mode = 'purchase_session';
        }

        $mode = apply_filters('oneclick_purchase_completion_mode', $mode, $order_id, $payload);
        if (
            $mode === 'immediate_purchase'
            && class_exists('OneClick_Settings')
            && !OneClick_Settings::immediate_purchase_enabled()
        ) {
            error_log('OneClick Campaign: immediate_purchase requested but disabled; using purchase_session.');
            return 'purchase_session';
        }

        return in_array($mode, ['purchase_session', 'immediate_purchase'], true)
            ? $mode
            : 'purchase_session';
    }
}
