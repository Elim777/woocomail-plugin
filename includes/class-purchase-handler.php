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

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Register REST API routes
     */
    public function register_routes() {
        register_rest_route('oneclick/v1', '/purchase', [
            'methods' => 'GET',
            'callback' => [$this, 'handle_purchase'],
            'permission_callback' => '__return_true', // Public endpoint (token verification handles security)
            'args' => [
                'token' => [
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
     * Flow:
     * 1. Verify JWT token
     * 2. Check if token already used (blacklist)
     * 3. Charge customer via Stripe MIT
     * 4. Create WooCommerce order
     * 5. Mark token as used (prevent replay)
     * 6. Return success/error HTML page
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_purchase($request) {
        $token = $request->get_param('token');

        // Step 1: Verify JWT token
        $jwt_handler = new OneClick_JWT_Handler();
        $verification = $jwt_handler->verify($token);

        if (!$verification['valid']) {
            error_log('OneClick Purchase: Invalid token - ' . $verification['error']);
            return $this->render_error_page(
                __('Invalid Purchase Link', 'woo-oneclick'),
                __('This purchase link is invalid or has expired. Please contact support if you need assistance.', 'woo-oneclick')
            );
        }

        $payload = $verification['payload'];
        $jti = $payload['jti'] ?? '';

        if (empty($jti)) {
            error_log('OneClick Purchase: Missing JTI in token');
            return $this->render_error_page(
                __('Invalid Token', 'woo-oneclick'),
                __('The purchase link is malformed. Please try again or contact support.', 'woo-oneclick')
            );
        }

        // Step 2: Check if token already used (replay attack prevention)
        $blacklist = new OneClick_Token_Blacklist();
        if ($blacklist->is_token_used($jti)) {
            error_log(sprintf('OneClick Purchase: Token already used (JTI: %s)', substr($jti, 0, 8)));
            return $this->render_error_page(
                __('Already Purchased', 'woo-oneclick'),
                __('This purchase link has already been used. Please check your orders or contact support.', 'woo-oneclick')
            );
        }

        // Extract payload data
        $product_id = $payload['product_id'] ?? 0;
        $user_id = $payload['user_id'] ?? 0;
        $price = $payload['price'] ?? 0;
        $user_email = $payload['user_email'] ?? '';
        $campaign_id = $payload['campaign_id'] ?? null;
        $original_order_id = $payload['original_order_id'] ?? null;
        $discount = $payload['discount'] ?? 0;

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

        // Step 3: Detect payment method from last order
        $payment_method = $is_test ? 'test' : $this->detect_payment_method($user_id);

        $payment_intent_id = '';

        if ($payment_method === 'stripe') {
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
                ]
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
        }
        // COD, BACS, and test: no payment needed at this point

        // Step 4: Create WooCommerce order
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

            $error_msg = __('Order creation failed. Please contact support.', 'woo-oneclick');
            if (!empty($payment_intent_id)) {
                $error_msg = sprintf(
                    __('Payment was processed but order creation failed. Please contact support with Payment Intent ID: %s', 'woo-oneclick'),
                    $payment_intent_id
                );
            }

            return $this->render_error_page(
                __('Order Creation Failed', 'woo-oneclick'),
                $error_msg
            );
        }

        // Step 5: Mark token as used (prevent replay attacks)
        $blacklist->mark_token_used($jti);

        error_log(sprintf(
            'OneClick Purchase: ✅ SUCCESS - Order #%d created, user #%d, product #%d, amount: %s (JTI: %s)',
            $order_id,
            $user_id,
            $product_id,
            wc_price($price),
            substr($jti, 0, 8)
        ));

        // Step 6: Return success page (different per payment method)
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
    private function detect_payment_method($user_id) {
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
        $method = $last_order->get_payment_method();

        // Normalize Stripe gateway variants
        if (in_array($method, ['stripe', 'stripe_cc', 'stripe_sepa', 'stripe_ideal'], true)) {
            $method = 'stripe';
        }

        // Only support known methods, fallback to stripe
        if (!in_array($method, ['stripe', 'cod', 'bacs'], true)) {
            error_log(sprintf(
                'OneClick Purchase: Unknown payment method "%s" for user #%d, defaulting to stripe',
                $method,
                $user_id
            ));
            return 'stripe';
        }

        error_log(sprintf(
            'OneClick Purchase: Detected payment method "%s" from order #%d for user #%d',
            $method,
            $last_order->get_id(),
            $user_id
        ));

        return $method;
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
