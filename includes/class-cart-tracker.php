<?php
/**
 * Cart Tracker (Abandoned Cart Detection)
 *
 * Hooks into WooCommerce cart/checkout events and sends activity
 * data to the backend for abandoned cart detection.
 *
 * Hooks:
 *   woocommerce_add_to_cart          → track_activity('add_to_cart')
 *   woocommerce_cart_item_removed    → track_activity('remove_item')
 *   woocommerce_after_cart_item_quantity_update → track_activity('update_cart')
 *   woocommerce_checkout_order_processed       → track_activity('order_processed')
 *
 * WP-Cron:
 *   oneclick_detect_abandoned (every 15 min) → POST /api/carts/detect-abandoned
 *
 * @package WooOneClick
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class OneClick_Cart_Tracker {

    public function __construct() {
        // Cart activity hooks
        add_action('woocommerce_add_to_cart', [$this, 'on_add_to_cart'], 10, 6);
        add_action('woocommerce_cart_item_removed', [$this, 'on_remove_item'], 10, 2);
        add_action('woocommerce_after_cart_item_quantity_update', [$this, 'on_update_cart'], 10, 4);
        add_action('woocommerce_checkout_order_processed', [$this, 'on_order_processed'], 10, 3);

        // Cron: detect abandoned carts
        add_action('oneclick_detect_abandoned', [$this, 'detect_abandoned']);
    }

    /**
     * Track add to cart event
     */
    public function on_add_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
        $this->track_activity('add_to_cart');
    }

    /**
     * Track remove item event
     */
    public function on_remove_item($cart_item_key, $cart) {
        $this->track_activity('remove_item');
    }

    /**
     * Track cart update event
     */
    public function on_update_cart($cart_item_key, $quantity, $old_quantity, $cart) {
        $this->track_activity('update_cart');
    }

    /**
     * Track order processed event
     */
    public function on_order_processed($order_id, $posted_data, $order) {
        $this->track_activity('order_processed');
    }

    /**
     * Send cart activity to backend
     *
     * @param string $event_type Event type (add_to_cart, remove_item, update_cart, order_processed)
     */
    private function track_activity($event_type) {
        // Don't track if WooCommerce cart is not available
        if (!function_exists('WC') || !WC()->cart) {
            return;
        }

        // Get session key
        $session_key = '';
        if (WC()->session) {
            $session_key = WC()->session->get_customer_id();
        }

        // Get user info
        $user_id = get_current_user_id();
        $email = '';

        if ($user_id) {
            $user = get_user_by('id', $user_id);
            if ($user) {
                $email = $user->user_email;
            }
        }

        // Get cart contents
        $cart_items = [];
        foreach (WC()->cart->get_cart() as $item) {
            $product = $item['data'] ?? null;
            $cart_items[] = [
                'product_id'   => $item['product_id'],
                'variation_id' => $item['variation_id'] ?? 0,
                'quantity'     => $item['quantity'],
                'price'        => $product ? (float) $product->get_price() : 0,
                'name'         => $product ? $product->get_name() : '',
            ];
        }

        $cart_total = (float) WC()->cart->get_cart_contents_total();

        // Send to backend (non-blocking)
        $api = OneClick_API_Client::instance();
        $result = $api->post('/api/carts/activity', [
            'site_url'    => site_url(),
            'session_key' => $session_key,
            'user_id'     => $user_id ?: null,
            'email'       => $email ?: null,
            'event_type'  => $event_type,
            'cart_items'   => $cart_items,
            'cart_total'  => $cart_total,
            'currency'    => get_woocommerce_currency(),
        ]);

        if (is_wp_error($result)) {
            error_log(sprintf(
                'OneClick Cart: Failed to track %s: %s',
                $event_type,
                $result->get_error_message()
            ));
        }
    }

    /**
     * Detect abandoned carts via WP-Cron
     *
     * Called every 15 minutes by oneclick_detect_abandoned cron event.
     */
    public function detect_abandoned() {
        $api = OneClick_API_Client::instance();
        $result = $api->post('/api/carts/detect-abandoned', [
            'site_url' => site_url(),
        ]);

        if (is_wp_error($result)) {
            error_log('OneClick Cart: Abandoned cart detection failed: ' . $result->get_error_message());
            return;
        }

        $detected = $result['detected'] ?? 0;
        $emails_queued = $result['emails_queued'] ?? 0;

        if ($detected > 0 || $emails_queued > 0) {
            error_log(sprintf(
                'OneClick Cart: Detected %d abandoned carts, %d emails queued',
                $detected,
                $emails_queued
            ));
        }
    }
}
