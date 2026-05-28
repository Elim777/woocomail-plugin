<?php
/**
 * Stripe Integration
 *
 * Handles Stripe MIT (Merchant Initiated Transactions) for off-session payments
 * Uses WooCommerce Stripe Gateway's saved payment methods
 *
 * @package WooOneClick
 */

if (!defined('ABSPATH')) {
    exit;
}

class OneClick_Stripe {

    /**
     * Get Stripe API key
     *
     * Uses WooCommerce Stripe Gateway settings only after explicit consent.
     * OneClick does not store separate merchant Stripe API keys.
     *
     * @return string|false Stripe secret key or false
     */
    private function get_stripe_secret_key() {
        if ((int) get_option('oneclick_use_woocommerce_stripe_keys', 0) !== 1) {
            error_log('OneClick Stripe: WooCommerce Stripe key usage not allowed in OneClick settings');
            return false;
        }

        $stripe_settings = get_option('woocommerce_stripe_settings', []);
        if (empty($stripe_settings['enabled']) || $stripe_settings['enabled'] !== 'yes') {
            error_log('OneClick Stripe: WooCommerce Stripe Gateway is not enabled');
            return false;
        }

        $test_mode = !empty($stripe_settings['testmode']) && $stripe_settings['testmode'] === 'yes';
        $secret_key = $test_mode
            ? ($stripe_settings['test_secret_key'] ?? '')
            : ($stripe_settings['secret_key'] ?? '');

        if (empty($secret_key)) {
            error_log('OneClick Stripe: WooCommerce Stripe secret key is not configured');
            return false;
        }

        return $secret_key;
    }

    /**
     * Get customer's default payment method
     *
     * Tries user meta first, falls back to WC Payment Tokens,
     * then queries Stripe API as last resort.
     *
     * @param int $user_id WordPress user ID
     * @return string|false Payment method ID or false
     */
    private function get_customer_payment_method($user_id) {
        // Get Stripe customer ID from user meta
        $customer_id = $this->get_customer_id($user_id);

        if (empty($customer_id)) {
            error_log("OneClick Stripe: User #$user_id has no Stripe customer ID");
            return false;
        }

        // 1. Try user meta (fastest)
        $payment_method_id = get_user_meta($user_id, '_stripe_default_payment_method', true);
        if (empty($payment_method_id)) {
            $payment_method_id = get_user_meta($user_id, 'stripe_default_payment_method', true);
        }

        if (!empty($payment_method_id)) {
            return $payment_method_id;
        }

        // 2. Try WC Payment Tokens (canonical WC API)
        $tokens = WC_Payment_Tokens::get_customer_tokens($user_id, 'stripe');
        if (!empty($tokens)) {
            foreach ($tokens as $token) {
                $pm = $token->get_token();
                if (!empty($pm)) {
                    error_log(sprintf('OneClick Stripe: Found PM %s via WC Payment Token for user #%d', $pm, $user_id));
                    // Save to user meta for faster lookup next time
                    update_user_meta($user_id, '_stripe_default_payment_method', $pm);
                    return $pm;
                }
            }
        }

        // 3. Fallback: Query Stripe API directly
        error_log(sprintf('OneClick Stripe: No PM in user meta or WC tokens for user #%d, querying Stripe API', $user_id));
        $reconciliation = new OneClick_Stripe_Reconciliation();
        $pm_from_stripe = $reconciliation->get_customer_payment_methods($customer_id);

        if ($pm_from_stripe) {
            // Save for future use
            update_user_meta($user_id, '_stripe_default_payment_method', $pm_from_stripe);
            error_log(sprintf('OneClick Stripe: Found PM %s via Stripe API for user #%d, saved to meta', $pm_from_stripe, $user_id));
            return $pm_from_stripe;
        }

        error_log("OneClick Stripe: User #$user_id has no payment method anywhere (meta, WC tokens, Stripe API)");
        return false;
    }

    /**
     * Charge customer using saved payment method (MIT - off-session)
     *
     * @param int $user_id WordPress user ID
     * @param float $amount Amount to charge (in store currency)
     * @param string $currency Currency code (default: USD)
     * @param array $metadata Additional metadata for the payment
     * @return array ['success' => bool, 'payment_intent_id' => string, 'error' => string]
     */
    public function charge_saved_payment_method($user_id, $amount, $currency = 'USD', $metadata = [], $idempotency_key = null) {
        try {
            // Get Stripe secret key
            $secret_key = $this->get_stripe_secret_key();
            if (!$secret_key) {
                return [
                    'success' => false,
                    'error' => 'Stripe not configured'
                ];
            }

            // Get customer's payment method
            $payment_method_id = $this->get_customer_payment_method($user_id);
            if (!$payment_method_id) {
                return [
                    'success' => false,
                    'error' => 'No saved payment method'
                ];
            }

            // Get customer ID (with fallback)
            $customer_id = $this->get_customer_id($user_id);

            // Convert amount to cents (Stripe uses smallest currency unit)
            $amount_cents = (int) round($amount * 100);

            if ($amount_cents <= 0) {
                return [
                    'success' => false,
                    'error' => 'Invalid amount'
                ];
            }

            // Create Payment Intent with off_session flag (MIT)
            $response = wp_remote_post('https://api.stripe.com/v1/payment_intents', [
                'headers' => array_filter([
                    'Authorization'  => 'Bearer ' . $secret_key,
                    'Content-Type'   => 'application/x-www-form-urlencoded',
                    'Idempotency-Key' => $idempotency_key,
                ]),
                'body' => http_build_query([
                    'amount' => $amount_cents,
                    'currency' => strtolower($currency),
                    'customer' => $customer_id,
                    'payment_method' => $payment_method_id,
                    'off_session' => 'true', // MIT flag
                    'confirm' => 'true', // Confirm immediately
                    'metadata' => array_merge([
                        'wordpress_user_id' => $user_id,
                        'plugin' => 'woo-oneclick-purchase'
                    ], $metadata)
                ]),
                'timeout' => 30
            ]);

            if (is_wp_error($response)) {
                error_log('OneClick Stripe: API error: ' . $response->get_error_message());
                return [
                    'success' => false,
                    'error' => 'Stripe API connection failed'
                ];
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            // Handle success
            if ($status_code === 200 && isset($data['id'])) {
                $status = $data['status'] ?? '';

                if ($status === 'succeeded') {
                    error_log(sprintf(
                        'OneClick Stripe: ✅ Payment succeeded for user #%d, amount: %s %s (PI: %s)',
                        $user_id,
                        $amount,
                        $currency,
                        $data['id']
                    ));

                    return [
                        'success' => true,
                        'payment_intent_id' => $data['id'],
                        'status' => $status
                    ];
                }

                // Handle authentication required (3DS)
                if ($status === 'requires_action' || $status === 'requires_source_action') {
                    error_log(sprintf(
                        'OneClick Stripe: ⚠️ Authentication required for user #%d (PI: %s)',
                        $user_id,
                        $data['id']
                    ));

                    return [
                        'success' => false,
                        'error' => 'Authentication required',
                        'requires_action' => true,
                        'payment_intent_id' => $data['id'],
                        'client_secret' => $data['client_secret'] ?? null
                    ];
                }

                // Other status
                error_log(sprintf(
                    'OneClick Stripe: Payment status "%s" for user #%d (PI: %s)',
                    $status,
                    $user_id,
                    $data['id']
                ));

                return [
                    'success' => false,
                    'error' => 'Payment ' . $status,
                    'payment_intent_id' => $data['id'],
                    'status' => $status
                ];
            }

            // Handle Stripe errors
            $error_message = 'Unknown error';
            if (isset($data['error']['message'])) {
                $error_message = $data['error']['message'];
            } elseif (isset($data['message'])) {
                $error_message = $data['message'];
            }

            error_log(sprintf(
                'OneClick Stripe: ❌ Payment failed for user #%d: %s',
                $user_id,
                $error_message
            ));

            return [
                'success' => false,
                'error' => $error_message,
                'stripe_error' => $data['error'] ?? null
            ];

        } catch (Exception $e) {
            error_log('OneClick Stripe Exception: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Internal error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Handle authentication required (3DS)
     *
     * For one-click purchases, we can't prompt user for 3DS during email click.
     * We send them an email with a link to complete authentication.
     *
     * @param string $payment_intent_id Stripe Payment Intent ID
     * @param string $client_secret Client secret for authentication
     * @param int $user_id User ID
     * @param string $user_email Customer email
     * @return bool True if notification sent successfully
     */
    public function handle_authentication_required($payment_intent_id, $client_secret, $user_id, $user_email) {
        // Create authentication page URL
        // This would be a custom page that uses Stripe.js to complete 3DS
        $auth_url = home_url('/one-click-auth/?pi=' . $payment_intent_id . '&cs=' . $client_secret);

        // Send email to customer
        $subject = __('Action Required: Complete Your Purchase', 'woo-oneclick');

        $message = sprintf(
            __('We need to verify your payment method to complete your one-click purchase.

Please click the link below to complete verification:

%s

This is required by your bank for security.

If you did not attempt this purchase, please ignore this email.', 'woo-oneclick'),
            $auth_url
        );

        $sent = wp_mail($user_email, $subject, $message);

        if ($sent) {
            error_log(sprintf(
                'OneClick Stripe: 3DS authentication email sent to %s (PI: %s)',
                $user_email,
                $payment_intent_id
            ));
        } else {
            error_log(sprintf(
                'OneClick Stripe: Failed to send 3DS email to %s (PI: %s)',
                $user_email,
                $payment_intent_id
            ));
        }

        return $sent;
    }

    /**
     * Check if user has saved payment method
     *
     * @param int $user_id WordPress user ID
     * @return bool True if user has saved payment method
     */
    public function has_saved_payment_method($user_id) {
        // Try both underscore and non-underscore versions
        $customer_id = get_user_meta($user_id, '_stripe_customer_id', true);
        if (empty($customer_id)) {
            $customer_id = get_user_meta($user_id, 'stripe_customer_id', true);
        }

        $payment_method_id = get_user_meta($user_id, '_stripe_default_payment_method', true);
        if (empty($payment_method_id)) {
            $payment_method_id = get_user_meta($user_id, 'stripe_default_payment_method', true);
        }

        return !empty($customer_id) && !empty($payment_method_id);
    }

    /**
     * Get customer's Stripe customer ID
     *
     * @param int $user_id WordPress user ID
     * @return string|false Stripe customer ID or false
     */
    public function get_customer_id($user_id) {
        // Try both underscore and non-underscore versions
        $customer_id = get_user_meta($user_id, '_stripe_customer_id', true);
        if (empty($customer_id)) {
            $customer_id = get_user_meta($user_id, 'stripe_customer_id', true);
        }
        return $customer_id ?: false;
    }

    /**
     * Refund a payment intent
     *
     * Used when Stripe charge succeeded but order creation failed,
     * to prevent orphaned charges.
     *
     * @param string $payment_intent_id Stripe Payment Intent ID
     * @return array Result with 'success' and 'refund_id' or 'error'
     */
    public function refund_payment($payment_intent_id) {
        $secret_key = $this->get_stripe_secret_key();
        if (!$secret_key) {
            return ['success' => false, 'error' => 'Stripe not configured'];
        }

        try {
            $response = wp_remote_post('https://api.stripe.com/v1/refunds', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $secret_key,
                    'Content-Type' => 'application/x-www-form-urlencoded'
                ],
                'body' => http_build_query([
                    'payment_intent' => $payment_intent_id,
                ]),
                'timeout' => 30,
            ]);

            if (is_wp_error($response)) {
                error_log('OneClick Stripe Refund: API error: ' . $response->get_error_message());
                return ['success' => false, 'error' => $response->get_error_message()];
            }

            $data = json_decode(wp_remote_retrieve_body($response), true);
            $status_code = wp_remote_retrieve_response_code($response);

            if ($status_code === 200 && isset($data['id'])) {
                error_log(sprintf(
                    'OneClick Stripe: Refund created for PI %s (Refund: %s)',
                    $payment_intent_id,
                    $data['id']
                ));
                return ['success' => true, 'refund_id' => $data['id']];
            }

            $error = $data['error']['message'] ?? 'Unknown refund error';
            error_log(sprintf('OneClick Stripe Refund failed for PI %s: %s', $payment_intent_id, $error));
            return ['success' => false, 'error' => $error];

        } catch (Exception $e) {
            error_log('OneClick Stripe Refund Exception: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
