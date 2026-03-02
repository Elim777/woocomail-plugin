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
        error_log("========== OneClick Trigger START ==========");
        error_log("OneClick Trigger: Processing order #$order_id");

        $order = wc_get_order($order_id);
        if (!$order) {
            error_log("OneClick Trigger: ERROR — Order #$order_id not found");
            error_log("========== OneClick Trigger END ==========");
            return;
        }

        $user_id = $order->get_user_id();
        error_log("OneClick Trigger: Order #$order_id — user_id=$user_id, status=" . $order->get_status() . ", total=" . $order->get_total());

        if (!$user_id) {
            error_log("OneClick Trigger: SKIP — Order #$order_id has no user (guest checkout)");
            error_log("========== OneClick Trigger END ==========");
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
                    'OneClick Trigger: Order item — product_id=%d, name="%s", price=%s, categories=[%s]',
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
            error_log("OneClick Trigger: SKIP — Order #$order_id has no items");
            error_log("========== OneClick Trigger END ==========");
            return;
        }

        error_log(sprintf(
            'OneClick Trigger: Collected — product_ids=[%s], category_ids=[%s]',
            implode(',', $product_ids),
            implode(',', $category_ids)
        ));

        // Call backend to evaluate rules
        $request_data = [
            'site_url'     => site_url(),
            'event_type'   => 'purchase',
            'product_ids'  => array_values($product_ids),
            'category_ids' => array_values($category_ids),
            'user_id'      => $user_id,
            'order_id'     => $order_id,
        ];

        error_log('OneClick Trigger: Calling POST /api/rules/evaluate with: ' . wp_json_encode($request_data));

        $api = OneClick_API_Client::instance();
        $result = $api->post('/api/rules/evaluate', $request_data);

        if (is_wp_error($result)) {
            error_log(sprintf(
                'OneClick Trigger: ERROR — Backend rule evaluation failed for order #%d: %s',
                $order_id,
                $result->get_error_message()
            ));
            error_log("========== OneClick Trigger END ==========");
            return;
        }

        error_log('OneClick Trigger: Backend response: ' . wp_json_encode($result));

        $matched_rules = $result['matched_rules'] ?? 0;
        $reactions = $result['reactions'] ?? [];

        error_log(sprintf(
            'OneClick Trigger: Order #%d — %d rules matched, %d reactions to fire',
            $order_id,
            $matched_rules,
            count($reactions)
        ));

        if (empty($reactions)) {
            error_log("OneClick Trigger: No reactions to schedule — done");
            error_log("========== OneClick Trigger END ==========");
            return;
        }

        // Schedule each reaction via WP-Cron
        foreach ($reactions as $index => $reaction) {
            error_log(sprintf(
                'OneClick Trigger: Reaction #%d full data: %s',
                $index,
                wp_json_encode($reaction)
            ));

            $delay_minutes = $reaction['delay_minutes'] ?? 5;
            $timestamp = time() + ($delay_minutes * 60);

            // Pass reaction data + order context as serialized payload
            $payload = [
                'reaction'  => $reaction,
                'order_id'  => $order_id,
                'user_id'   => $user_id,
            ];

            $scheduled = wp_schedule_single_event(
                $timestamp,
                'oneclick_send_campaign_email',
                [$order_id, $payload]
            );

            error_log(sprintf(
                'OneClick Trigger: Scheduled reaction "%s" for order #%d (delay: %d min, timestamp: %d, scheduled_result: %s)',
                $reaction['reaction_name'] ?? 'unknown',
                $order_id,
                $delay_minutes,
                $timestamp,
                $scheduled === false ? 'FAILED' : 'OK'
            ));
        }

        error_log("========== OneClick Trigger END ==========");
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
        error_log("========== OneClick Send Email START ==========");
        error_log('OneClick Send: Full payload received: ' . wp_json_encode($payload));

        $reaction = $payload['reaction'] ?? [];
        $user_id = $payload['user_id'] ?? 0;

        error_log('OneClick Send: Reaction data: ' . wp_json_encode($reaction));
        error_log("OneClick Send: order_id=$order_id, user_id=$user_id");

        // Get user data
        $user = get_user_by('id', $user_id);
        if (!$user) {
            error_log("OneClick Send: ERROR — User #$user_id not found");
            error_log("========== OneClick Send Email END ==========");
            return;
        }

        error_log(sprintf(
            'OneClick Send: User found — id=%d, email=%s, display_name=%s',
            $user->ID,
            $user->user_email,
            $user->display_name
        ));

        // Get offer products from reaction
        // Backend returns 'offer_products' (array of product IDs)
        $offer_product_ids = $reaction['offer_products'] ?? [];

        error_log(sprintf(
            'OneClick Send: offer_products from reaction: %s (type: %s)',
            wp_json_encode($offer_product_ids),
            gettype($offer_product_ids)
        ));

        if (empty($offer_product_ids)) {
            error_log("OneClick Send: ERROR — Reaction has no offer products!");
            error_log("OneClick Send: Available reaction keys: " . implode(', ', array_keys($reaction)));
            error_log("========== OneClick Send Email END ==========");
            return;
        }

        $customer_name = $user->display_name ?: $user->first_name ?: $user->user_login;
        $discount = $reaction['discount_percent'] ?? 0;
        $discount_type = $reaction['discount_type'] ?? 'none';
        $jwt_handler = new OneClick_JWT_Handler();

        error_log("OneClick Send: customer_name=$customer_name, discount=$discount% ($discount_type)");
        error_log(sprintf('OneClick Send: Processing %d offer products', count($offer_product_ids)));

        // Generate token for each offer product
        $products_data = [];
        $primary_token = null;
        $primary_product_name = '';
        $primary_price = 0;

        foreach ($offer_product_ids as $idx => $pid) {
            $product = wc_get_product($pid);
            if (!$product) {
                error_log("OneClick Send: WARNING — Product #$pid not found, skipping");
                continue;
            }

            $original_price = (float) $product->get_price();
            $discounted_price = $discount > 0 ? $original_price * (1 - ($discount / 100)) : $original_price;

            error_log(sprintf(
                'OneClick Send: Product #%d — name="%s", original=%.2f, final=%.2f',
                $pid, $product->get_name(), $original_price, $discounted_price
            ));

            // Generate JWT token for this product
            $token = $jwt_handler->generate_via_backend([
                'user_id'          => $user_id,
                'user_email'       => $user->user_email,
                'product_id'       => $pid,
                'product_name'     => $product->get_name(),
                'price'            => $discounted_price,
                'original_order_id' => $order_id,
            ]);

            if (!$token) {
                // Fallback to local generation
                error_log("OneClick Send: WARNING — Backend token failed for product #$pid, trying local");
                $token = $jwt_handler->generate([
                    'product_id'       => $pid,
                    'product_name'     => $product->get_name(),
                    'price'            => $discounted_price,
                    'original_price'   => $original_price,
                    'discount'         => $discount,
                    'user_id'          => $user_id,
                    'user_email'       => $user->user_email,
                    'customer_name'    => $customer_name,
                    'original_order_id' => $order_id,
                ]);
            }

            if (!$token) {
                error_log("OneClick Send: ERROR — Failed to generate token for product #$pid, skipping");
                continue;
            }

            error_log(sprintf('OneClick Send: Token OK for product #%d (length=%d)', $pid, strlen($token)));

            $products_data[] = [
                'token'        => $token,
                'product_name' => $product->get_name(),
                'price'        => $discounted_price,
            ];

            // First successful product becomes the primary
            if ($primary_token === null) {
                $primary_token = $token;
                $primary_product_name = $product->get_name();
                $primary_price = $discounted_price;
            }
        }

        if (empty($products_data)) {
            error_log("OneClick Send: ERROR — No valid products to send for order #$order_id");
            error_log("========== OneClick Send Email END ==========");
            return;
        }

        // Check email subject/body
        $email_subject = $reaction['email_subject'] ?? '';
        $email_body = $reaction['email_body'] ?? '';

        // Build email request
        $email_request = [
            'token'         => $primary_token,
            'to_email'      => $user->user_email,
            'customer_name' => $customer_name,
            'product_name'  => $primary_product_name,
            'price'         => $primary_price,
        ];

        // Multi-product: include all products
        if (count($products_data) > 1) {
            $email_request['products'] = $products_data;
            error_log(sprintf('OneClick Send: Multi-product email with %d products', count($products_data)));
        }

        if (!empty($email_subject)) {
            $email_request['email_subject'] = $email_subject;
        }
        if (!empty($email_body)) {
            $email_request['email_body'] = $email_body;
        }

        error_log('OneClick Send: Calling POST /api/send-email with ' . count($products_data) . ' product(s)');

        $api = OneClick_API_Client::instance();
        $result = $api->post('/api/send-email', $email_request);

        if (is_wp_error($result)) {
            error_log(sprintf(
                'OneClick Send: ERROR — Backend email sending failed for order #%d: %s',
                $order_id,
                $result->get_error_message()
            ));
            error_log("========== OneClick Send Email END ==========");
            return;
        }

        error_log('OneClick Send: Backend response: ' . wp_json_encode($result));

        $success = $result['success'] ?? false;
        if ($success) {
            error_log(sprintf(
                'OneClick Send: SUCCESS — Email sent for order #%d to %s (reaction: %s, purchase_id: %s)',
                $order_id,
                $user->user_email,
                $reaction['reaction_name'] ?? 'unknown',
                $result['purchase_id'] ?? 'n/a'
            ));
        } else {
            error_log(sprintf(
                'OneClick Send: FAILED — Backend returned failure for order #%d: %s',
                $order_id,
                $result['message'] ?? wp_json_encode($result)
            ));
        }

        error_log("========== OneClick Send Email END ==========");
    }
}
