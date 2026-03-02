<?php
/**
 * Product Button
 *
 * Adds "Buy Now (One Click)" button to product pages
 * LOWEST PRIORITY FEATURE
 *
 * @package WooOneClick
 */

if (!defined('ABSPATH')) {
    exit;
}

class OneClick_Product_Button {

    public function __construct() {
        // TODO: Implement in Phase 6
        // - Add button to product page
        // - Enqueue JavaScript
        // - AJAX handler
        // Stub — logging removed to avoid spam in debug.log
    }

    // TODO: Implement in Phase 6
    // - add_action('woocommerce_after_add_to_cart_button', [$this, 'add_buy_now_button']);
    // - add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
    // - add_action('wp_ajax_oneclick_request_purchase', [$this, 'handle_ajax_request']);
}
