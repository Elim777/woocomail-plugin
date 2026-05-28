<?php
/**
 * Purchase Handler
 *
 * REST API endpoint for processing one-click purchases from email links
 * This is the core endpoint that completes the purchase flow
 *
 * @package WooOneClick
 */

if (!defined('ABSPATH')) {
    exit;
}

class OneClick_Purchase_Handler {

    public function __construct($register_hooks = true) {
        if (!$register_hooks) {
            return;
        }

        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('woocommerce_before_calculate_totals', [$this, 'apply_oneclick_cart_prices'], 20, 1);
    }

    /**
     * Register REST API routes
     */
    public function register_routes() {
        register_rest_route('oneclick/v1', '/purchase', [
            'methods' => 'GET',
            'callback' => [$this, 'handle_purchase'],
            'permission_callback' => '__return_true', // Public endpoint (exchange-code handles security)
            'args' => [
                'code' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    /**
     * Handle one-click purchase request
     *
     * Opaque Authorization Code Exchange Flow:
     * 1. Receive opaque claim code from URL (?code=abc123)
     * 2. Exchange code for verified payload via backend (POST /api/purchase/exchange-code)
     * 3. Charge customer via Stripe MIT (with idempotency_key)
     * 4. Create WooCommerce order
     * 5. Return success/error HTML page
     *
     * JWT never touches the plugin — backend is source of truth.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_purchase($request) {
        $code = $request->get_param('code');

        error_log('');
        error_log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        error_log('💳 FÁZA 4: EXCHANGE CODE + STRIPE MIT / CHECKOUT FALLBACK');
        error_log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        error_log('');
        error_log('🖱️ Step 23/29 | BROWSER → PLUGIN');
        error_log('   Browser nasledoval 302 redirect z backendu a odoslal');
        error_log(sprintf('   GET /wp-json/oneclick/v1/purchase?code=%s... na WordPress.', substr($code, 0, 8)));
        error_log('   WordPress REST API matchol route (registrovaná v __construct() tejto triedy)');
        error_log('   a zavolal callback handle_purchase($request).');
        error_log('   V URL je len opaque code — plugin nevie čo znamená, musí ho vymeniť na backende.');
        error_log('');
        error_log('📤 Step 24/29 | PLUGIN → BACKEND (outbound HTTP)');
        error_log(sprintf('   Plugin volá OneClick_API_Client::post("/api/purchase/exchange-code", ["code" => "%s..."]).', substr($code, 0, 8)));
        error_log('   Toto je outbound request — plugin si VYBERÁ komu zavolá (známa URL backendu).');
        error_log('   V hlavičkách ide X-License-Key (z wp_options) — tým plugin preukazuje identitu.');
        error_log('   Backend atomicky vymení code za payload (jednorazové — druhé zavolanie vráti 404).');
        error_log('');
        error_log(sprintf(
            '   WordPress request context: site_url=%s | home_url=%s | is_ssl=%s | is_user_logged_in=%s | current_user_id=%s',
            site_url(),
            home_url(),
            is_ssl() ? 'yes' : 'no',
            is_user_logged_in() ? 'yes' : 'no',
            get_current_user_id() ?: 'anonymous'
        ));
        error_log('');
        error_log('   ══ Backend vykonáva Steps 25-26 (viď Render logy) ══');
        error_log('');

        // Step 1: Exchange opaque code for verified payload via backend.
        $result = $this->exchange_code_with_backend($code);

        if (is_wp_error($result)) {
            $error = $result->get_error_message();
            error_log('OneClick Purchase: exchange-code failed — ' . $error);

            if (stripos($error, 'already redeemed') !== false) {
                return $this->render_error_page(
                    __('Already Purchased', 'woo-oneclick'),
                    __('This purchase link has already been used. Please check your orders or contact support.', 'woo-oneclick')
                );
            }

            return $this->render_error_page(
                __('Invalid Purchase Link', 'woo-oneclick'),
                __('This purchase link is invalid or has expired. Please contact support if you need assistance.', 'woo-oneclick')
            );
        }

        if (empty($result['success'])) {
            error_log('OneClick Purchase: exchange-code returned success=false');
            return $this->render_error_page(
                __('Invalid Purchase Link', 'woo-oneclick'),
                __('Could not verify purchase. Please contact support.', 'woo-oneclick')
            );
        }

        $payload = $result['payload'];
        $idempotency_key = $result['idempotency_key'] ?? null;

        if (($payload['flow'] ?? '') === 'purchase_session') {
            return $this->redirect_to_purchase_session($payload);
        }

        // Extract payload data
        $product_id = $payload['product_id'] ?? 0;
        $user_id = $payload['user_id'] ?? 0;
        $price = $payload['price'] ?? 0;
        $user_email = $payload['user_email'] ?? '';
        $campaign_id = $payload['campaign_id'] ?? null;
        $original_order_id = $payload['original_order_id'] ?? ($payload['order_id'] ?? null);
        $discount = $payload['discount'] ?? 0;
        $click_behavior = $payload['click_behavior'] ?? 'mit_purchase';
        if (!in_array($click_behavior, ['mit_purchase', 'cart_checkout'], true)) {
            $click_behavior = 'mit_purchase';
        }

        error_log('📥 Step 26/29 | BACKEND → PLUGIN (odpoveď prijatá)');
        error_log('   Backend vrátil HTTP 200 s JSON payloadom. Plugin dekódoval cez json_decode().');
        error_log(sprintf(
            '   product_id=%d | user_id=%d | price=%s | currency=%s | click_behavior=%s',
            $product_id,
            $user_id,
            $price,
            $payload['currency'] ?? get_woocommerce_currency(),
            $click_behavior
        ));
        if (!empty($idempotency_key)) {
            error_log(sprintf('   idempotency_key=%s... (vygenerovaný backendom, pôjde do Stripe hlavičky pri MIT vetve)', substr($idempotency_key, 0, 8)));
        }
        error_log('');

        // Validate required data
        if (empty($product_id) || empty($user_id) || empty($price)) {
            error_log('OneClick Purchase: Missing required data in payload');
            return $this->render_error_page(
                __('Invalid Data', 'woo-oneclick'),
                __('The purchase link is missing required information. Please contact support.', 'woo-oneclick')
            );
        }

        // Get product
        $product = wc_get_product($product_id);
        if (!$product) {
            error_log(sprintf('OneClick Purchase: Product #%d not found', $product_id));
            return $this->render_error_page(
                __('Product Not Available', 'woo-oneclick'),
                __('The product you\'re trying to purchase is no longer available.', 'woo-oneclick')
            );
        }

        // Get user
        $user = get_user_by('id', $user_id);
        if (!$user) {
            error_log(sprintf('OneClick Purchase: User #%d not found', $user_id));
            return $this->render_error_page(
                __('User Not Found', 'woo-oneclick'),
                __('Your user account could not be found. Please contact support.', 'woo-oneclick')
            );
        }

        // Check for test mode (compatibility test — skip payment)
        $is_test = !empty($payload['test']);

        // Step 3: Detect payment method from source order
        $payment_method = $is_test ? 'test' : $this->detect_payment_method($user_id, $original_order_id);

        if (!$is_test && $click_behavior === 'cart_checkout') {
            error_log('🛒 Step 27/29 | PLUGIN → WOOCOMMERCE CART (checkout fallback)');
            error_log('   click_behavior=cart_checkout, takže plugin nespúšťa Stripe MIT a nevytvára objednávku.');
            error_log('   Produkt pridá do WooCommerce košíka so zľavnenou cenou z payloadu a presmeruje na checkout.');

            return $this->redirect_to_checkout_with_cart_item(
                $product,
                $product_id,
                $price,
                $user_id,
                $original_order_id,
                $campaign_id,
                $discount
            );
        }

        $payment_intent_id = '';

        if ($payment_method === 'stripe') {
            error_log('💰 Step 27/29 | PLUGIN → STRIPE (MIT platba)');
            error_log(sprintf('   detect_payment_method(%d, %s) vrátil "stripe".', $user_id, $original_order_id ?: 'null'));
            error_log('   Plugin volá OneClick_Stripe::charge_saved_payment_method().');
            error_log(sprintf(
                '   amount=%d (v centoch) | currency=%s | off_session=true | confirm=true',
                (int) round($price * 100),
                get_woocommerce_currency()
            ));
            if (!empty($idempotency_key)) {
                error_log(sprintf('   Idempotency-Key: %s... (z backendu — ochrana proti double-charge)', substr($idempotency_key, 0, 8)));
            }

            // Stripe MIT (off-session) charge
            $stripe = new OneClick_Stripe();
            $charge_result = $stripe->charge_saved_payment_method(
                $user_id,
                $price,
                get_woocommerce_currency(),
                [
                    'campaign_id' => $campaign_id,
                    'product_id' => $product_id,
                    'original_order_id' => $original_order_id
                ],
                $idempotency_key
            );

            if (!$charge_result['success']) {
                error_log(sprintf(
                    'OneClick Purchase: Stripe charge failed for user #%d - %s',
                    $user_id,
                    $charge_result['error']
                ));

                if (!empty($charge_result['requires_action'])) {
                    $stripe->handle_authentication_required(
                        $charge_result['payment_intent_id'],
                        $charge_result['client_secret'] ?? '',
                        $user_id,
                        $user_email
                    );

                    return $this->render_error_page(
                        __('Authentication Required', 'woo-oneclick'),
                        __('Your bank requires additional verification. We\'ve sent you an email with instructions to complete your purchase.', 'woo-oneclick')
                    );
                }

                return $this->render_error_page(
                    __('Payment Failed', 'woo-oneclick'),
                    sprintf(__('We couldn\'t process your payment: %s. Please try again or contact support.', 'woo-oneclick'), $charge_result['error'])
                );
            }

            $payment_intent_id = $charge_result['payment_intent_id'];
            error_log(sprintf('   ✅ Stripe platba úspešná. PaymentIntent: %s', $payment_intent_id));
        }
        // COD, BACS, and test: no payment needed at this point

        // Step 4: Create WooCommerce order
        error_log('');
        error_log('📋 Step 28/29 | PLUGIN (vytvorenie WooCommerce objednávky)');
        error_log('   Plugin volá OneClick_Order_Creator::create_order().');
        error_log('   class-order-creator.php vytvorí objednávku cez wc_create_order(),');
        error_log('   skopíruje adresy z poslednej objednávky, nastaví platbu a meta dáta.');

        $order_creator = new OneClick_Order_Creator();
        $order_id = $order_creator->create_order([
            'product_id' => $product_id,
            'user_id' => $user_id,
            'price' => $price,
            'payment_method' => $payment_method,
            'payment_intent_id' => $payment_intent_id,
            'campaign_id' => $campaign_id,
            'original_order_id' => $original_order_id,
            'discount' => $discount
        ]);

        if (is_wp_error($order_id)) {
            error_log(sprintf(
                'OneClick Purchase: Order creation failed for user #%d - %s',
                $user_id,
                $order_id->get_error_message()
            ));

            // Auto-refund if Stripe charge was already processed
            if (!empty($payment_intent_id)) {
                error_log(sprintf(
                    'OneClick Purchase: Auto-refunding PI %s due to order creation failure',
                    $payment_intent_id
                ));
                $stripe = new OneClick_Stripe();
                $refund_result = $stripe->refund_payment($payment_intent_id);

                if ($refund_result['success']) {
                    return $this->render_error_page(
                        __('Order Creation Failed', 'woo-oneclick'),
                        __('We could not create your order. Your payment has been automatically refunded. Please try again or contact support.', 'woo-oneclick')
                    );
                } else {
                    return $this->render_error_page(
                        __('Order Creation Failed', 'woo-oneclick'),
                        sprintf(
                            __('Order creation failed and automatic refund could not be processed. Please contact support with Payment Intent ID: %s', 'woo-oneclick'),
                            $payment_intent_id
                        )
                    );
                }
            }

            return $this->render_error_page(
                __('Order Creation Failed', 'woo-oneclick'),
                __('Order creation failed. Please contact support.', 'woo-oneclick')
            );
        }

        // Step 5: Token already marked as used in Step 2 (atomic claim_token)

        error_log(sprintf(
            'OneClick Purchase: ✅ SUCCESS - Order #%d created, user #%d, product #%d, amount: %s (code: %s)',
            $order_id,
            $user_id,
            $product_id,
            wc_price($price),
            substr($code, 0, 8)
        ));

        // Step 6: Return success page (different per payment method)
        error_log('');
        error_log('🎉 Step 29/29 | PLUGIN → BROWSER (záverečná stránka)');
        error_log(sprintf('   Plugin vracia HTML thank-you stránku pre objednávku #%d.', $order_id));
        error_log(sprintf('   Zákazník vidí potvrdenie: produkt "%s", cena %s, platba %s.', $product->get_name(), wc_price($price), $payment_method));
        error_log('   Browser zobrazuje stránku — celý flow je dokončený.');
        error_log('');
        error_log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        error_log('✅ FLOW DOKONČENÝ: Nákup → Pravidlá → Email → Klik → Platba/Checkout → Objednávka');
        error_log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        error_log('');

        return $this->render_success_page($order_id, $product, $price, $payment_method);
    }

    /**
     * Detect payment method from user's last completed order
     *
     * Queries WooCommerce for the user's most recent completed/processing order
     * and returns the payment method used. Falls back to 'stripe' if no orders found.
     *
     * @param int $user_id WordPress user ID
     * @return string Payment method slug: 'stripe', 'cod', 'bacs'
     */
    private function detect_payment_method($user_id, $original_order_id = null) {
        if (!empty($original_order_id)) {
            $source_order = wc_get_order($original_order_id);
            if ($source_order && (int) $source_order->get_user_id() === (int) $user_id) {
                $method = $this->normalize_payment_method($source_order->get_payment_method());
                error_log(sprintf(
                    'OneClick Purchase: Detected payment method "%s" from source order #%d for user #%d',
                    $method,
                    $source_order->get_id(),
                    $user_id
                ));
                return $method;
            }
        }

        $orders = wc_get_orders([
            'customer_id' => $user_id,
            'limit'       => 1,
            'orderby'     => 'date',
            'order'       => 'DESC',
            'status'      => ['wc-completed', 'wc-processing', 'wc-on-hold'],
        ]);

        if (empty($orders)) {
            error_log(sprintf('OneClick Purchase: No previous orders for user #%d, defaulting to stripe', $user_id));
            return 'stripe';
        }

        $last_order = $orders[0];
        $method = $this->normalize_payment_method($last_order->get_payment_method());

        error_log(sprintf(
            'OneClick Purchase: Detected payment method "%s" from order #%d for user #%d',
            $method,
            $last_order->get_id(),
            $user_id
        ));

        return $method;
    }

    public function exchange_code_with_backend($code, $context = null) {
        $api = OneClick_API_Client::instance();
        $exchange_data = array_merge(
            ['code' => $code],
            is_array($context) ? $context : $this->get_exchange_context()
        );

        error_log(sprintf(
            '   Exchange context: visitor_key=%s... | current_user_id=%s | last_order_id=%s | last_payment_method=%s | has_saved_stripe=%s',
            substr($exchange_data['visitor_key'] ?? 'none', 0, 8),
            $exchange_data['current_user_id'] ?? 'anonymous',
            $exchange_data['last_order_id'] ?? 'none',
            $exchange_data['last_payment_method'] ?? 'none',
            !empty($exchange_data['has_saved_stripe']) ? 'yes' : 'no'
        ));
        error_log('   Tento kontext ide až v licencovanom outbound exchange requeste, nie v public shop URL.');

        return $api->post('/api/purchase/exchange-code', $exchange_data);
    }

    public function redirect_to_purchase_session($payload) {
        $session_id = $payload['session_id'] ?? '';
        $access_token = $payload['access_token'] ?? '';

        if (empty($session_id) || empty($access_token)) {
            error_log('OneClick Purchase Session: Missing session_id or access_token in exchange-code response');
            return $this->render_error_page(
                __('Invalid Session', 'woo-oneclick'),
                __('Could not open your purchase session. Please click the email link again.', 'woo-oneclick')
            );
        }

        error_log('🪟 SESSION FLOW | PLUGIN (otvorenie nákupného okna)');
        error_log(sprintf(
            '   Backend vrátil flow=purchase_session | session_id=%s | remaining=%ss | items=%d',
            $session_id,
            $payload['remaining_seconds'] ?? 'n/a',
            count($payload['items'] ?? [])
        ));
        error_log('   Plugin nespúšťa Stripe MIT ani nevytvára objednávku. Nastaví session cookie a presmeruje na session page.');

        OneClick_Purchase_Session::set_session_cookie($session_id, $access_token);
        wp_safe_redirect(add_query_arg('session_id', rawurlencode($session_id), rest_url('oneclick/v1/session')));
        exit;
    }

    public function render_purchase_error_page($title, $message) {
        return $this->render_error_page($title, $message);
    }

    public function get_exchange_context($include_user_context = true) {
        $context = [];
        if (class_exists('OneClick_Public_Links')) {
            $context['visitor_key'] = OneClick_Public_Links::get_or_create_visitor_key();
        }

        if (!$include_user_context || !is_user_logged_in()) {
            return $context;
        }

        $user_id = get_current_user_id();
        $user = get_user_by('id', $user_id);
        $context['current_user_id'] = $user_id;
        if ($user) {
            $context['current_user_email'] = $user->user_email;
        }

        $orders = wc_get_orders([
            'customer_id' => $user_id,
            'limit' => 1,
            'orderby' => 'date',
            'order' => 'DESC',
            'status' => ['processing', 'completed', 'on-hold'],
        ]);
        if (!empty($orders)) {
            $last_order = $orders[0];
            $context['last_order_id'] = $last_order->get_id();
            $context['last_payment_method'] = $this->normalize_payment_method($last_order->get_payment_method());
        }

        if (class_exists('OneClick_Stripe')) {
            $stripe = new OneClick_Stripe();
            $context['has_saved_stripe'] = (bool) $stripe->has_saved_payment_method($user_id);
        }

        return $context;
    }

    private function normalize_payment_method($method) {
        $method = sanitize_key((string) $method);

        if (in_array($method, ['stripe', 'stripe_cc', 'stripe_sepa', 'stripe_ideal'], true)) {
            return 'stripe';
        }

        if (in_array($method, ['stripe', 'cod', 'bacs', 'test'], true)) {
            return $method;
        }

        if (!empty($method)) {
            error_log(sprintf('OneClick Purchase: Unknown payment method "%s", defaulting to stripe for legacy MIT compatibility', $method));
        }
        return 'stripe';
    }

    private function redirect_to_checkout_with_cart_item($product, $product_id, $price, $user_id, $original_order_id, $campaign_id, $discount) {
        if (!$product->is_purchasable() || !$product->is_in_stock()) {
            return $this->render_error_page(
                __('Product Not Available', 'woo-oneclick'),
                __('This product cannot currently be added to cart.', 'woo-oneclick')
            );
        }

        $cart_ready = $this->ensure_cart_available();
        if (is_wp_error($cart_ready)) {
            return $this->render_error_page(
                __('Cart Unavailable', 'woo-oneclick'),
                __('We could not prepare your checkout cart. Please try again or contact support.', 'woo-oneclick')
            );
        }

        $add_product_id = $product_id;
        $variation_id = 0;
        $variation = [];

        if ($product->is_type('variation')) {
            $variation_id = $product_id;
            $add_product_id = $product->get_parent_id();
            $variation = $product->get_variation_attributes();
        }

        $cart_item_data = [
            'oneclick_purchase' => 'yes',
            'oneclick_price' => (float) $price,
            'oneclick_user_id' => (int) $user_id,
            'oneclick_original_order_id' => (int) $original_order_id,
            'oneclick_campaign_id' => (int) $campaign_id,
            'oneclick_discount' => (float) $discount,
            'oneclick_unique_key' => wp_hash($product_id . '|' . $price . '|' . microtime(true)),
        ];

        $cart_item_key = WC()->cart->add_to_cart($add_product_id, 1, $variation_id, $variation, $cart_item_data);
        if (!$cart_item_key) {
            return $this->render_error_page(
                __('Could Not Add To Cart', 'woo-oneclick'),
                __('We could not add this product to your cart. Please try again or contact support.', 'woo-oneclick')
            );
        }

        WC()->cart->calculate_totals();

        error_log(sprintf(
            '   ✅ Product #%d pridaný do košíka ako cart_item=%s so zľavnenou cenou %s.',
            $product_id,
            $cart_item_key,
            wc_price($price)
        ));
        error_log(sprintf('   Presmerovanie na checkout: %s', wc_get_checkout_url()));
        error_log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        error_log('✅ FLOW POKRAČUJE VO WOOCOMMERCE CHECKOUTE: Nákup → Pravidlá → Email → Klik → Košík → Checkout');
        error_log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        error_log('');

        wp_safe_redirect(wc_get_checkout_url());
        exit;
    }

    private function ensure_cart_available() {
        if (!function_exists('WC')) {
            return new WP_Error('woocommerce_unavailable', 'WooCommerce is unavailable');
        }

        if ((null === WC()->session || null === WC()->cart) && function_exists('wc_load_cart')) {
            wc_load_cart();
        }

        if (null === WC()->cart) {
            return new WP_Error('cart_unavailable', 'WooCommerce cart is unavailable');
        }

        return true;
    }

    public function apply_oneclick_cart_prices($cart) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        if (!$cart || !method_exists($cart, 'get_cart')) {
            return;
        }

        foreach ($cart->get_cart() as $cart_item) {
            if (empty($cart_item['oneclick_purchase']) || !isset($cart_item['oneclick_price'])) {
                continue;
            }

            if (!empty($cart_item['data']) && is_object($cart_item['data']) && method_exists($cart_item['data'], 'set_price')) {
                $cart_item['data']->set_price((float) $cart_item['oneclick_price']);
            }
        }
    }

    /**
     * Render success HTML page
     *
     * Shows different messages based on payment method:
     * - stripe: "Payment processed successfully!"
     * - cod: "Order created! Pay on delivery."
     * - bacs: "Order created! Payment details sent via email."
     *
     * @param int $order_id Order ID
     * @param WC_Product $product Product object
     * @param float $price Price paid
     * @param string $payment_method Payment method slug
     * @return void (exits directly)
     */
    private function render_success_page($order_id, $product, $price, $payment_method = 'stripe') {
        $order_url = wc_get_endpoint_url('view-order', $order_id, wc_get_page_permalink('myaccount'));

        $html = '<!DOCTYPE html>
<html>
<head>
    <title>' . esc_html__('Purchase Successful', 'woo-oneclick') . '</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            margin: 0;
            padding: 20px;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            padding: 40px;
            max-width: 500px;
            width: 100%;
            text-align: center;
        }
        .checkmark {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: block;
            stroke-width: 3;
            stroke: #4caf50;
            stroke-miterlimit: 10;
            margin: 10% auto;
            box-shadow: inset 0px 0px 0px #4caf50;
            animation: fill .4s ease-in-out .4s forwards, scale .3s ease-in-out .9s both;
        }
        .checkmark__circle {
            stroke-dasharray: 166;
            stroke-dashoffset: 166;
            stroke-width: 2;
            stroke-miterlimit: 10;
            stroke: #4caf50;
            fill: none;
            animation: stroke .6s cubic-bezier(0.65, 0, 0.45, 1) forwards;
        }
        .checkmark__check {
            transform-origin: 50% 50%;
            stroke-dasharray: 48;
            stroke-dashoffset: 48;
            animation: stroke .3s cubic-bezier(0.65, 0, 0.45, 1) .8s forwards;
        }
        @keyframes stroke {
            100% { stroke-dashoffset: 0; }
        }
        @keyframes scale {
            0%, 100% { transform: none; }
            50% { transform: scale3d(1.1, 1.1, 1); }
        }
        @keyframes fill {
            100% { box-shadow: inset 0px 0px 0px 30px #4caf50; }
        }
        h1 {
            color: #333;
            margin: 20px 0;
            font-size: 28px;
        }
        .product-name {
            color: #667eea;
            font-size: 20px;
            font-weight: bold;
            margin: 10px 0;
        }
        .price {
            color: #4caf50;
            font-size: 24px;
            font-weight: bold;
            margin: 15px 0;
        }
        .button {
            display: inline-block;
            padding: 15px 40px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 50px;
            font-weight: bold;
            margin-top: 20px;
            transition: all 0.3s;
        }
        .button:hover {
            background: #764ba2;
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
        }
        .order-number {
            color: #999;
            font-size: 14px;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <svg class="checkmark" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 52 52">
            <circle class="checkmark__circle" cx="26" cy="26" r="25" fill="none"/>
            <path class="checkmark__check" fill="none" d="M14.1 27.2l7.1 7.2 16.7-16.8"/>
        </svg>

        <h1>' . esc_html__('Purchase Successful!', 'woo-oneclick') . '</h1>

        <div class="product-name">' . esc_html($product->get_name()) . '</div>
        <div class="price">' . wc_price($price) . '</div>

        <p>' . esc_html($this->get_success_message($payment_method)) . '</p>

        <a href="' . esc_url($order_url) . '" class="button">' . esc_html__('View Order', 'woo-oneclick') . '</a>

        <div class="order-number">' . sprintf(esc_html__('Order #%d', 'woo-oneclick'), $order_id) . '</div>
    </div>
</body>
</html>';

        // Output HTML directly (REST API always returns JSON, so we bypass it)
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }

    /**
     * Get success message based on payment method
     *
     * @param string $payment_method Payment method slug
     * @return string Translated success message
     */
    private function get_success_message($payment_method) {
        switch ($payment_method) {
            case 'test':
                return __('TEST MODE: Mock purchase completed successfully! No real payment was charged. This confirms your OneClick setup is working correctly.', 'woo-oneclick');
            case 'cod':
                return __('Your order has been created successfully! You will pay upon delivery. A confirmation email has been sent to your inbox.', 'woo-oneclick');
            case 'bacs':
                return __('Your order has been created successfully! Payment details have been sent to your email. Please complete the bank transfer to process your order.', 'woo-oneclick');
            case 'stripe':
            default:
                return __('Your one-click purchase has been completed successfully. A confirmation email has been sent to your inbox.', 'woo-oneclick');
        }
    }

    /**
     * Render error HTML page
     *
     * @param string $title Error title
     * @param string $message Error message
     * @return void (exits directly)
     */
    private function render_error_page($title, $message) {
        $html = '<!DOCTYPE html>
<html>
<head>
    <title>' . esc_html($title) . '</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            margin: 0;
            padding: 20px;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            padding: 40px;
            max-width: 500px;
            width: 100%;
            text-align: center;
        }
        .error-icon {
            font-size: 80px;
            color: #f5576c;
            margin-bottom: 20px;
        }
        h1 {
            color: #333;
            margin: 20px 0;
            font-size: 28px;
        }
        p {
            color: #666;
            line-height: 1.6;
            margin: 20px 0;
        }
        .button {
            display: inline-block;
            padding: 15px 40px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 50px;
            font-weight: bold;
            margin-top: 20px;
            transition: all 0.3s;
        }
        .button:hover {
            background: #764ba2;
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="error-icon">⚠️</div>
        <h1>' . esc_html($title) . '</h1>
        <p>' . esc_html($message) . '</p>
        <a href="' . esc_url(home_url()) . '" class="button">' . esc_html__('Go to Homepage', 'woo-oneclick') . '</a>
    </div>
</body>
</html>';

        // Output HTML directly (REST API always returns JSON, so we bypass it)
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }
}
