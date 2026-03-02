<?php
/**
 * Periodic Cron
 *
 * WP-Cron job that calls backend for periodic email reminders.
 * Fetches WooCommerce customer emails and sends to backend
 * for periodic rule evaluation.
 *
 * @package WooOneClick
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class OneClick_Periodic_Cron {

    public function __construct() {
        add_action('oneclick_periodic_check', [$this, 'run_periodic_check']);
    }

    /**
     * Run periodic check — called daily by WP-Cron
     *
     * Fetches customer emails from WooCommerce, sends to backend
     * POST /api/periodic/detect for periodic rule matching.
     */
    public function run_periodic_check() {
        // Get customers with orders
        $customers = $this->get_customer_data();

        if (empty($customers['emails'])) {
            error_log('OneClick Periodic: No customers found, skipping');
            return;
        }

        $api = OneClick_API_Client::instance();
        $result = $api->post('/api/periodic/detect', [
            'site_url'       => site_url(),
            'emails'         => $customers['emails'],
            'customer_names' => $customers['names'],
        ]);

        if (is_wp_error($result)) {
            error_log('OneClick Periodic: Detection failed: ' . $result->get_error_message());
            return;
        }

        $sent = $result['sent'] ?? 0;
        $skipped = $result['skipped'] ?? 0;

        error_log(sprintf(
            'OneClick Periodic: Check complete — %d emails sent, %d skipped (of %d customers)',
            $sent,
            $skipped,
            count($customers['emails'])
        ));
    }

    /**
     * Get customer emails and names from WooCommerce orders
     *
     * @return array ['emails' => [...], 'names' => ['email' => 'name', ...]]
     */
    private function get_customer_data() {
        $emails = [];
        $names = [];

        // Query recent customers (last 90 days)
        $args = [
            'limit'      => 500,
            'orderby'    => 'date',
            'order'      => 'DESC',
            'date_after'  => date('Y-m-d', strtotime('-90 days')),
            'status'     => ['wc-completed', 'wc-processing'],
        ];

        $orders = wc_get_orders($args);

        foreach ($orders as $order) {
            $email = $order->get_billing_email();
            if (empty($email) || in_array($email, $emails, true)) {
                continue;
            }

            $emails[] = $email;
            $first_name = $order->get_billing_first_name();
            $last_name = $order->get_billing_last_name();
            $full_name = trim("$first_name $last_name");

            if (!empty($full_name)) {
                $names[$email] = $full_name;
            }
        }

        return [
            'emails' => $emails,
            'names'  => $names,
        ];
    }
}
