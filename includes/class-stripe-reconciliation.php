<?php
/**
 * Stripe Payment Method Reconciliation
 *
 * Ensures payment methods are reliably saved after first purchase.
 * Hooks into WooCommerce payment completion to verify and fix PM storage.
 *
 * Problem: WooCommerce Stripe Gateway doesn't always save payment methods
 * in user meta (especially with HPOS, 3DS, or guest→registered flows).
 * This class detects and fixes missing PM data so one-click purchases work.
 *
 * @package WooOneClick
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class OneClick_Stripe_Reconciliation {

    public function __construct() {
        // Run after payment completes (priority 20 = after WC Stripe gateway)
        add_action('woocommerce_payment_complete', [$this, 'reconcile_payment_method'], 20, 1);
    }

    /**
     * Reconcile payment method after payment completion
     *
     * Checks if user has both _stripe_customer_id and _stripe_default_payment_method.
     * If either is missing, queries Stripe API to fill the gap.
     *
     * @param int $order_id WooCommerce order ID
     */
    public function reconcile_payment_method($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Only reconcile Stripe orders
        $payment_method = $order->get_payment_method();
        if (!in_array($payment_method, ['stripe', 'stripe_cc', 'stripe_sepa', 'stripe_ideal'], true)) {
            return;
        }

        $user_id = $order->get_customer_id();
        if (empty($user_id)) {
            return; // Guest order, nothing to reconcile
        }

        // Check current state
        $customer_id = $this->get_stripe_customer_id($user_id);
        $pm_id = $this->get_stripe_pm_id($user_id);

        if (!empty($customer_id) && !empty($pm_id)) {
            // Both present, nothing to do
            return;
        }

        error_log(sprintf(
            'OneClick Reconciliation: Missing PM data for user #%d (customer_id: %s, pm_id: %s) — reconciling',
            $user_id,
            $customer_id ?: 'MISSING',
            $pm_id ?: 'MISSING'
        ));

        // Try to get payment intent ID from order meta
        $pi_id = $order->get_meta('_stripe_payment_intent_id');
        if (empty($pi_id)) {
            // Try alternate meta key
            $pi_id = $order->get_meta('_stripe_intent_id');
        }

        if (empty($pi_id)) {
            error_log(sprintf('OneClick Reconciliation: No payment intent ID on order #%d, cannot reconcile', $order_id));
            return;
        }

        // Query Stripe API for payment intent details
        $pi_data = $this->get_payment_intent($pi_id);
        if (!$pi_data) {
            return;
        }

        // Extract customer and payment method
        $stripe_customer_id = $pi_data['customer'] ?? '';
        $stripe_pm_id = $pi_data['payment_method'] ?? '';

        if (empty($stripe_customer_id) || empty($stripe_pm_id)) {
            error_log(sprintf(
                'OneClick Reconciliation: Stripe PI %s missing customer/pm (customer: %s, pm: %s)',
                $pi_id,
                $stripe_customer_id ?: 'none',
                $stripe_pm_id ?: 'none'
            ));
            return;
        }

        // Save missing data
        if (empty($customer_id)) {
            update_user_meta($user_id, '_stripe_customer_id', $stripe_customer_id);
            error_log(sprintf('OneClick Reconciliation: Saved _stripe_customer_id %s for user #%d', $stripe_customer_id, $user_id));
        }

        if (empty($pm_id)) {
            update_user_meta($user_id, '_stripe_default_payment_method', $stripe_pm_id);
            error_log(sprintf('OneClick Reconciliation: Saved _stripe_default_payment_method %s for user #%d', $stripe_pm_id, $user_id));
        }

        // Also save WC Payment Token if missing
        $this->ensure_wc_payment_token($user_id, $stripe_customer_id, $stripe_pm_id, $pi_data);

        error_log(sprintf(
            'OneClick Reconciliation: Reconciliation complete for user #%d, order #%d',
            $user_id,
            $order_id
        ));
    }

    /**
     * Get Stripe Payment Intent details via API
     *
     * @param string $pi_id Payment Intent ID
     * @return array|null Payment Intent data or null
     */
    private function get_payment_intent($pi_id) {
        $secret_key = $this->get_stripe_secret_key();
        if (!$secret_key) {
            return null;
        }

        $response = wp_remote_get('https://api.stripe.com/v1/payment_intents/' . $pi_id, [
            'headers' => [
                'Authorization' => 'Bearer ' . $secret_key,
            ],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            error_log('OneClick Reconciliation: Stripe API error: ' . $response->get_error_message());
            return null;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code !== 200 || empty($body['id'])) {
            error_log(sprintf('OneClick Reconciliation: Stripe API returned %d for PI %s', $status_code, $pi_id));
            return null;
        }

        return $body;
    }

    /**
     * Query Stripe API for customer's payment methods
     *
     * Used as fallback when user meta is missing PM ID.
     *
     * @param string $customer_id Stripe customer ID
     * @return string|false First payment method ID or false
     */
    public function get_customer_payment_methods($customer_id) {
        $secret_key = $this->get_stripe_secret_key();
        if (!$secret_key) {
            return false;
        }

        $response = wp_remote_get(
            'https://api.stripe.com/v1/payment_methods?' . http_build_query([
                'customer' => $customer_id,
                'type'     => 'card',
                'limit'    => 1,
            ]),
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $secret_key,
                ],
                'timeout' => 15,
            ]
        );

        if (is_wp_error($response)) {
            error_log('OneClick Reconciliation: PM list API error: ' . $response->get_error_message());
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $methods = $body['data'] ?? [];

        if (empty($methods)) {
            error_log(sprintf('OneClick Reconciliation: No payment methods found for customer %s', $customer_id));
            return false;
        }

        return $methods[0]['id'];
    }

    /**
     * Ensure WC Payment Token exists for user
     *
     * WooCommerce Payment Tokens are the canonical way to store payment methods.
     * Some Stripe features (like the My Account → Payment Methods page) require this.
     *
     * @param int $user_id User ID
     * @param string $customer_id Stripe customer ID
     * @param string $pm_id Stripe payment method ID
     * @param array $pi_data Payment Intent data (contains PM details)
     */
    private function ensure_wc_payment_token($user_id, $customer_id, $pm_id, $pi_data) {
        // Check if token already exists
        $tokens = WC_Payment_Tokens::get_customer_tokens($user_id, 'stripe');
        foreach ($tokens as $token) {
            if ($token->get_token() === $pm_id) {
                return; // Token already exists
            }
        }

        // Get PM details from the payment intent
        $pm_details = $pi_data['payment_method_details'] ?? [];
        $card = $pm_details['card'] ?? [];

        if (empty($card)) {
            // Try to get from charges
            $charges = $pi_data['charges']['data'] ?? $pi_data['latest_charge'] ?? [];
            if (is_array($charges) && !empty($charges)) {
                $charge = is_array($charges[0] ?? null) ? $charges[0] : $charges;
                $card = ($charge['payment_method_details'] ?? [])['card'] ?? [];
            }
        }

        if (empty($card['last4'])) {
            error_log(sprintf('OneClick Reconciliation: Cannot create WC token — no card details for PM %s', $pm_id));
            return;
        }

        // Create WC Payment Token
        $token = new WC_Payment_Token_CC();
        $token->set_token($pm_id);
        $token->set_gateway_id('stripe');
        $token->set_card_type($card['brand'] ?? 'card');
        $token->set_last4($card['last4']);
        $token->set_expiry_month($card['exp_month'] ?? '');
        $token->set_expiry_year($card['exp_year'] ?? '');
        $token->set_user_id($user_id);
        $token->set_default(true);
        $token->save();

        error_log(sprintf(
            'OneClick Reconciliation: Created WC Payment Token for user #%d (%s ending %s)',
            $user_id,
            $card['brand'] ?? 'card',
            $card['last4']
        ));
    }

    /**
     * Get Stripe customer ID from user meta
     *
     * @param int $user_id User ID
     * @return string|false
     */
    private function get_stripe_customer_id($user_id) {
        $id = get_user_meta($user_id, '_stripe_customer_id', true);
        if (empty($id)) {
            $id = get_user_meta($user_id, 'stripe_customer_id', true);
        }
        return $id ?: false;
    }

    /**
     * Get Stripe payment method ID from user meta
     *
     * @param int $user_id User ID
     * @return string|false
     */
    private function get_stripe_pm_id($user_id) {
        $id = get_user_meta($user_id, '_stripe_default_payment_method', true);
        if (empty($id)) {
            $id = get_user_meta($user_id, 'stripe_default_payment_method', true);
        }
        return $id ?: false;
    }

    /**
     * Get Stripe secret key (same logic as OneClick_Stripe)
     *
     * @return string|false
     */
    private function get_stripe_secret_key() {
        $stripe_settings = get_option('woocommerce_stripe_settings');
        if (!empty($stripe_settings['secret_key'])) {
            return $stripe_settings['secret_key'];
        }

        $key = get_option('oneclick_stripe_secret_key');
        return !empty($key) ? $key : false;
    }
}
