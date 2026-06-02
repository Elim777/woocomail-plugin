<?php
/**
 * Order Creator
 *
 * Creates WooCommerce orders programmatically with HPOS compatibility
 * Uses wc_create_order() for HPOS support
 *
 * @package WooOneClick
 */

if (!defined('ABSPATH')) {
    exit;
}

class OneClick_Order_Creator {

    /**
     * Create WooCommerce order for one-click purchase
     *
     * HPOS Compatible - uses wc_create_order()
     * Supports multiple payment methods: stripe, cod, bacs
     *
     * @param array $data Order data with keys:
     *                    - product_id (int) Required
     *                    - user_id (int) Required
     *                    - price (float) Required
     *                    - payment_method (string) Required: 'stripe', 'cod', 'bacs'
     *                    - payment_intent_id (string) Required for stripe, empty for cod/bacs
     *                    - campaign_id (int) Optional
     *                    - original_order_id (int) Optional
     *                    - discount (float) Optional
     * @return int|WP_Error Order ID or error
     */
    public function create_order($data) {
        try {
            $payment_method = $data['payment_method'] ?? 'stripe';

            // Validate required fields (payment_intent_id only required for stripe)
            if (empty($data['product_id']) || empty($data['user_id']) || empty($data['price'])) {
                return new WP_Error('invalid_data', 'Missing required order data');
            }

            if ($payment_method === 'stripe' && empty($data['payment_intent_id'])) {
                return new WP_Error('invalid_data', 'Missing payment_intent_id for Stripe order');
            }

            // Get product
            $product = wc_get_product($data['product_id']);
            if (!$product) {
                return new WP_Error('invalid_product', 'Product not found');
            }

            // Get user
            $user = get_user_by('id', $data['user_id']);
            if (!$user) {
                return new WP_Error('invalid_user', 'User not found');
            }

            // Create order (HPOS compatible)
            $order = wc_create_order([
                'customer_id' => $data['user_id'],
                'status' => 'pending',
                'created_via' => 'oneclick_purchase'
            ]);

            if (is_wp_error($order)) {
                oneclick_log('OneClick Order Creator: Failed to create order - ' . $order->get_error_message());
                return $order;
            }

            // Add product to order
            $order->add_product($product, 1, [
                'subtotal' => $data['price'],
                'total' => $data['price']
            ]);

            // Copy billing/shipping address from last order (more reliable than user meta)
            $this->copy_addresses_from_last_order($order, $data['user_id']);

            // Copy shipping method from last order (critical for COD/BACS — carriers, pickup points)
            if (in_array($payment_method, ['cod', 'bacs'], true)) {
                $this->copy_shipping_from_last_order($order, $data['user_id']);
            }

            // Calculate totals
            $order->calculate_totals();

            // Add order meta
            $order->update_meta_data('_oneclick_purchase', 'yes');
            $order->update_meta_data('_oneclick_payment_method', $payment_method);

            if (!empty($data['payment_intent_id'])) {
                $order->update_meta_data('_stripe_payment_intent_id', $data['payment_intent_id']);
            }

            if (!empty($data['campaign_id'])) {
                $order->update_meta_data('_oneclick_campaign_id', $data['campaign_id']);
            }

            if (!empty($data['original_order_id'])) {
                $order->update_meta_data('_oneclick_original_order_id', $data['original_order_id']);
            }

            if (!empty($data['discount'])) {
                $order->update_meta_data('_oneclick_discount', $data['discount']);
            }

            // Set payment method and title per type
            $this->set_payment_method_on_order($order, $payment_method);

            // Add order note
            $note = __('One-click purchase completed via email campaign.', 'woo-oneclick');
            if (!empty($data['campaign_id'])) {
                $note .= ' ' . sprintf(__('Campaign ID: %d', 'woo-oneclick'), $data['campaign_id']);
            }
            if (!empty($data['payment_intent_id'])) {
                $note .= ' ' . sprintf(__('Payment Intent: %s', 'woo-oneclick'), $data['payment_intent_id']);
            }
            $note .= ' ' . sprintf(__('Payment Method: %s', 'woo-oneclick'), $payment_method);

            $order->add_order_note($note);

            // Save order before status change
            $order->save();

            // Set order status based on payment method
            // IMPORTANT: Use update_status() (NOT set_status()) to trigger hooks
            // External plugins (Packeta, DPD, etc.) listen on woocommerce_order_status_changed
            switch ($payment_method) {
                case 'stripe':
                    // Stripe: payment already collected → mark as paid
                    $order->payment_complete($data['payment_intent_id']);
                    break;

                case 'cod':
                    // COD: payment on delivery → processing (ready for fulfillment)
                    $order->update_status(
                        'processing',
                        __('One-click COD order — payment on delivery.', 'woo-oneclick')
                    );
                    break;

                case 'bacs':
                    // BACS: awaiting bank transfer → on-hold
                    $order->update_status(
                        'on-hold',
                        __('One-click BACS order — awaiting bank transfer.', 'woo-oneclick')
                    );
                    break;

                case 'test':
                    // Test/mock: mark as completed immediately
                    $order->update_status(
                        'completed',
                        __('One-click TEST order — mock purchase (no real payment).', 'woo-oneclick')
                    );
                    break;
            }

            oneclick_log(sprintf(
                'OneClick Order Creator: Order #%d created [%s] for user #%d, product #%d (%s)',
                $order->get_id(),
                $payment_method,
                $data['user_id'],
                $data['product_id'],
                $product->get_name()
            ));

            return $order->get_id();

        } catch (Exception $e) {
            oneclick_log('OneClick Order Creator Exception: ' . $e->getMessage());
            return new WP_Error('creation_failed', $e->getMessage());
        }
    }

    /**
     * Create one WooCommerce order from a timed purchase session.
     *
     * @param array $data Session order data.
     * @return int|WP_Error
     */
    public function create_session_order($data) {
        try {
            $session_id = sanitize_text_field($data['session_id'] ?? '');
            $user_id = (int) ($data['user_id'] ?? 0);
            $items = $data['items'] ?? [];
            $payment_method = sanitize_key($data['payment_method'] ?? 'stripe');
            $payment_intent_id = sanitize_text_field($data['payment_intent_id'] ?? '');
            $original_order_id = !empty($data['original_order_id']) ? (int) $data['original_order_id'] : null;

            if (empty($session_id) || empty($user_id) || empty($items)) {
                return new WP_Error('invalid_session_order_data', 'Missing required session order data');
            }

            if ($payment_method === 'stripe' && empty($payment_intent_id)) {
                return new WP_Error('invalid_session_order_data', 'Missing payment_intent_id for Stripe session order');
            }

            $existing = wc_get_orders([
                'limit' => 1,
                'meta_key' => '_oneclick_session_id',
                'meta_value' => $session_id,
                'return' => 'ids',
            ]);
            if (!empty($existing)) {
                oneclick_log(sprintf('OneClick Order Creator: Session %s already has order #%d', $session_id, $existing[0]));
                return (int) $existing[0];
            }

            $order = wc_create_order([
                'customer_id' => $user_id,
                'status' => 'pending',
                'created_via' => 'oneclick_purchase_session',
            ]);

            if (is_wp_error($order)) {
                return $order;
            }

            foreach ($items as $item) {
                $product_id = (int) ($item['product_id'] ?? 0);
                $quantity = max(1, (int) ($item['quantity'] ?? 1));
                $price = (float) ($item['price'] ?? 0);
                $product = wc_get_product($product_id);

                if (!$product || !$product->is_purchasable()) {
                    return new WP_Error('invalid_session_product', sprintf('Product %d is not purchasable', $product_id));
                }

                $order->add_product($product, $quantity, [
                    'subtotal' => $price * $quantity,
                    'total' => $price * $quantity,
                ]);
            }

            $source_order = $this->get_source_order($original_order_id, $user_id);
            if ($source_order) {
                $this->copy_addresses_from_order($order, $source_order);
                $this->copy_shipping_from_order($order, $source_order);
            } else {
                $this->copy_addresses_from_last_order($order, $user_id);
                if (in_array($payment_method, ['cod', 'bacs'], true)) {
                    $this->copy_shipping_from_last_order($order, $user_id);
                }
            }

            $order->calculate_totals();
            $order->update_meta_data('_oneclick_purchase', 'yes');
            $order->update_meta_data('_oneclick_purchase_session', 'yes');
            $order->update_meta_data('_oneclick_session_id', $session_id);
            $order->update_meta_data('_oneclick_offer_id', sanitize_text_field($data['offer_id'] ?? ''));
            $order->update_meta_data('_oneclick_payment_method', $payment_method);

            if (!empty($payment_intent_id)) {
                $order->update_meta_data('_stripe_payment_intent_id', $payment_intent_id);
            }
            if (!empty($original_order_id)) {
                $order->update_meta_data('_oneclick_original_order_id', $original_order_id);
            }

            $this->set_payment_method_on_order($order, $payment_method);
            $order->add_order_note(sprintf(
                __('OneClick timed purchase session completed. Session: %s. Payment Method: %s', 'woo-oneclick'),
                $session_id,
                $payment_method
            ));
            $order->save();

            switch ($payment_method) {
                case 'stripe':
                    $order->payment_complete($payment_intent_id);
                    break;
                case 'bacs':
                    $order->update_status('on-hold', __('OneClick session BACS order — awaiting bank transfer.', 'woo-oneclick'));
                    break;
                case 'cod':
                default:
                    $order->update_status('processing', __('OneClick session order — ready for fulfillment.', 'woo-oneclick'));
                    break;
            }

            oneclick_log(sprintf(
                'OneClick Order Creator: Session order #%d created [%s] for user #%d, session %s, items=%d',
                $order->get_id(),
                $payment_method,
                $user_id,
                $session_id,
                count($items)
            ));

            return $order->get_id();
        } catch (Exception $e) {
            oneclick_log('OneClick Session Order Creator Exception: ' . $e->getMessage());
            return new WP_Error('session_order_creation_failed', $e->getMessage());
        }
    }

    /**
     * Copy billing and shipping addresses from user's last order
     *
     * More reliable than user meta — last order always has the most recent address.
     * Falls back to user meta if no previous orders exist.
     *
     * @param WC_Order $order New order
     * @param int $user_id User ID
     */
    private function copy_addresses_from_last_order($order, $user_id) {
        $last_order = $this->get_last_order($user_id);

        if ($last_order) {
            $this->copy_addresses_from_order($order, $last_order);
            return;
        }

        // Fallback: use user meta
        $this->set_billing_address_from_meta($order, $user_id);
        $this->set_shipping_address_from_meta($order, $user_id);
    }

    private function copy_addresses_from_order($order, $source_order) {
        $order->set_address([
            'first_name' => $source_order->get_billing_first_name(),
            'last_name'  => $source_order->get_billing_last_name(),
            'company'    => $source_order->get_billing_company(),
            'address_1'  => $source_order->get_billing_address_1(),
            'address_2'  => $source_order->get_billing_address_2(),
            'city'       => $source_order->get_billing_city(),
            'state'      => $source_order->get_billing_state(),
            'postcode'   => $source_order->get_billing_postcode(),
            'country'    => $source_order->get_billing_country(),
            'email'      => $source_order->get_billing_email(),
            'phone'      => $source_order->get_billing_phone(),
        ], 'billing');

        $order->set_address([
            'first_name' => $source_order->get_shipping_first_name(),
            'last_name'  => $source_order->get_shipping_last_name(),
            'company'    => $source_order->get_shipping_company(),
            'address_1'  => $source_order->get_shipping_address_1(),
            'address_2'  => $source_order->get_shipping_address_2(),
            'city'       => $source_order->get_shipping_city(),
            'state'      => $source_order->get_shipping_state(),
            'postcode'   => $source_order->get_shipping_postcode(),
            'country'    => $source_order->get_shipping_country(),
        ], 'shipping');
    }

    /**
     * Copy shipping method and items from user's last order
     *
     * Critical for COD/BACS — copies the complete shipping configuration:
     * - Shipping method (flat_rate, local_pickup, etc.)
     * - Method title, instance_id, total, taxes
     * - All shipping item meta (pickup point, carrier code, tracking, etc.)
     *
     * External plugins (Packeta, DPD, Zasielkovna) store their data in shipping item meta.
     *
     * @param WC_Order $order New order
     * @param int $user_id User ID
     */
    private function copy_shipping_from_last_order($order, $user_id) {
        $last_order = $this->get_last_order($user_id);

        if (!$last_order) {
            oneclick_log(sprintf('OneClick Order Creator: No last order for shipping copy, user #%d', $user_id));
            return;
        }

        $this->copy_shipping_from_order($order, $last_order);
    }

    private function copy_shipping_from_order($order, $source_order) {
        $shipping_items = $source_order->get_shipping_methods();

        if (empty($shipping_items)) {
            oneclick_log(sprintf('OneClick Order Creator: Source order #%d has no shipping methods', $source_order->get_id()));
            return;
        }

        foreach ($shipping_items as $shipping_item) {
            /** @var WC_Order_Item_Shipping $shipping_item */
            $new_shipping = new WC_Order_Item_Shipping();

            // Copy core shipping fields
            $new_shipping->set_method_id($shipping_item->get_method_id());
            $new_shipping->set_instance_id($shipping_item->get_instance_id());
            $new_shipping->set_method_title($shipping_item->get_method_title());
            $new_shipping->set_total($shipping_item->get_total());

            // Copy taxes if applicable
            $taxes = $shipping_item->get_taxes();
            if (!empty($taxes)) {
                $new_shipping->set_taxes($taxes);
            }

            // Copy ALL meta data (pickup point, carrier code, etc.)
            // This ensures compatibility with Packeta, DPD, Zasielkovna, etc.
            $meta_data = $shipping_item->get_meta_data();
            foreach ($meta_data as $meta) {
                $new_shipping->add_meta_data($meta->key, $meta->value, true);
            }

            $order->add_item($new_shipping);
        }

        oneclick_log(sprintf(
            'OneClick Order Creator: Copied %d shipping method(s) from order #%d',
            count($shipping_items),
            $source_order->get_id()
        ));
    }

    /**
     * Set payment method and title on order
     *
     * @param WC_Order $order Order object
     * @param string $payment_method Payment method slug
     */
    private function set_payment_method_on_order($order, $payment_method) {
        switch ($payment_method) {
            case 'cod':
                $order->set_payment_method('cod');
                $order->set_payment_method_title(__('Cash on Delivery (One-Click)', 'woo-oneclick'));
                break;

            case 'bacs':
                $order->set_payment_method('bacs');
                $order->set_payment_method_title(__('Bank Transfer (One-Click)', 'woo-oneclick'));
                break;

            case 'test':
                $order->set_payment_method('cod');
                $order->set_payment_method_title(__('Test / Mock (One-Click)', 'woo-oneclick'));
                break;

            case 'stripe':
            default:
                $order->set_payment_method('stripe');
                $order->set_payment_method_title(__('Stripe (One-Click Purchase)', 'woo-oneclick'));
                break;
        }
    }

    /**
     * Get user's last completed/processing order
     *
     * @param int $user_id User ID
     * @return WC_Order|null
     */
    private function get_last_order($user_id) {
        $orders = wc_get_orders([
            'customer_id' => $user_id,
            'limit'       => 1,
            'orderby'     => 'date',
            'order'       => 'DESC',
            'status'      => ['wc-completed', 'wc-processing', 'wc-on-hold'],
        ]);

        return !empty($orders) ? $orders[0] : null;
    }

    private function get_source_order($order_id, $user_id) {
        if (empty($order_id)) {
            return null;
        }

        $order = wc_get_order($order_id);
        if (!$order || (int) $order->get_user_id() !== (int) $user_id) {
            return null;
        }

        return $order;
    }

    /**
     * Set billing address from user meta (fallback)
     *
     * @param WC_Order $order Order object
     * @param int $user_id User ID
     */
    private function set_billing_address_from_meta($order, $user_id) {
        $billing_data = [
            'first_name' => get_user_meta($user_id, 'billing_first_name', true) ?: get_user_meta($user_id, 'first_name', true),
            'last_name'  => get_user_meta($user_id, 'billing_last_name', true) ?: get_user_meta($user_id, 'last_name', true),
            'company'    => get_user_meta($user_id, 'billing_company', true),
            'address_1'  => get_user_meta($user_id, 'billing_address_1', true),
            'address_2'  => get_user_meta($user_id, 'billing_address_2', true),
            'city'       => get_user_meta($user_id, 'billing_city', true),
            'state'      => get_user_meta($user_id, 'billing_state', true),
            'postcode'   => get_user_meta($user_id, 'billing_postcode', true),
            'country'    => get_user_meta($user_id, 'billing_country', true),
            'email'      => get_user_by('id', $user_id)->user_email,
            'phone'      => get_user_meta($user_id, 'billing_phone', true),
        ];

        $billing_data = array_filter($billing_data);

        if (!empty($billing_data)) {
            $order->set_address($billing_data, 'billing');
        }
    }

    /**
     * Set shipping address from user meta (fallback)
     *
     * @param WC_Order $order Order object
     * @param int $user_id User ID
     */
    private function set_shipping_address_from_meta($order, $user_id) {
        $shipping_data = [
            'first_name' => get_user_meta($user_id, 'shipping_first_name', true) ?: get_user_meta($user_id, 'first_name', true),
            'last_name'  => get_user_meta($user_id, 'shipping_last_name', true) ?: get_user_meta($user_id, 'last_name', true),
            'company'    => get_user_meta($user_id, 'shipping_company', true),
            'address_1'  => get_user_meta($user_id, 'shipping_address_1', true),
            'address_2'  => get_user_meta($user_id, 'shipping_address_2', true),
            'city'       => get_user_meta($user_id, 'shipping_city', true),
            'state'      => get_user_meta($user_id, 'shipping_state', true),
            'postcode'   => get_user_meta($user_id, 'shipping_postcode', true),
            'country'    => get_user_meta($user_id, 'shipping_country', true),
        ];

        $shipping_data = array_filter($shipping_data);

        if (!empty($shipping_data)) {
            $order->set_address($shipping_data, 'shipping');
        }
    }

    /**
     * Get order by payment intent ID
     *
     * @param string $payment_intent_id Stripe Payment Intent ID
     * @return WC_Order|false Order object or false
     */
    public function get_order_by_payment_intent($payment_intent_id) {
        $args = [
            'limit' => 1,
            'meta_key' => '_stripe_payment_intent_id',
            'meta_value' => $payment_intent_id,
            'return' => 'ids'
        ];

        $orders = wc_get_orders($args);

        if (!empty($orders)) {
            return wc_get_order($orders[0]);
        }

        return false;
    }
}
