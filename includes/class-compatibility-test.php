<?php
/**
 * Compatibility Test
 *
 * Admin page that checks system requirements, backend connectivity,
 * Stripe configuration, and allows sending test emails / mock purchases.
 *
 * @package WooOneClick
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class OneClick_Compatibility_Test {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('wp_ajax_oneclick_compat_check', [$this, 'ajax_run_checks']);
        add_action('wp_ajax_oneclick_test_email', [$this, 'ajax_test_email']);
    }

    /**
     * Add admin menu page
     */
    public function add_menu_page() {
        add_submenu_page(
            'oneclick-settings',
            __('Compatibility Test', 'woo-oneclick'),
            __('Compatibility Test', 'woo-oneclick'),
            'manage_woocommerce',
            'oneclick-compat-test',
            [$this, 'render_page']
        );
    }

    /**
     * Render compatibility test page
     */
    public function render_page() {
        $nonce = wp_create_nonce('oneclick_compat_nonce');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Compatibility Test', 'woo-oneclick'); ?></h1>
            <p class="description"><?php esc_html_e('Check that your environment meets all requirements for OneClick Purchase.', 'woo-oneclick'); ?></p>

            <!-- System Checks -->
            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2><?php esc_html_e('System Requirements', 'woo-oneclick'); ?></h2>
                <table class="widefat striped" id="oneclick-compat-table">
                    <thead>
                        <tr>
                            <th style="width: 40px;"><?php esc_html_e('Status', 'woo-oneclick'); ?></th>
                            <th><?php esc_html_e('Check', 'woo-oneclick'); ?></th>
                            <th><?php esc_html_e('Details', 'woo-oneclick'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td colspan="3"><em><?php esc_html_e('Click "Run Checks" to start.', 'woo-oneclick'); ?></em></td></tr>
                    </tbody>
                </table>

                <p style="margin-top: 15px;">
                    <button type="button" class="button button-primary" id="oneclick-run-checks" data-nonce="<?php echo esc_attr($nonce); ?>">
                        <?php esc_html_e('Run Checks', 'woo-oneclick'); ?>
                    </button>
                </p>
            </div>

            <!-- Test Email -->
            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2><?php esc_html_e('Test Email', 'woo-oneclick'); ?></h2>
                <p class="description"><?php esc_html_e('Send a test email through the backend to verify email delivery.', 'woo-oneclick'); ?></p>
                <p>
                    <label>
                        <?php esc_html_e('Email:', 'woo-oneclick'); ?>
                        <input type="email" id="oneclick-test-email-input" value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>" class="regular-text">
                    </label>
                </p>
                <p>
                    <button type="button" class="button" id="oneclick-test-email-btn" data-nonce="<?php echo esc_attr($nonce); ?>">
                        <?php esc_html_e('Send Test Email', 'woo-oneclick'); ?>
                    </button>
                    <span id="oneclick-test-email-status" style="margin-left: 10px;"></span>
                </p>
            </div>

        </div>

        <script>
        jQuery(function($) {
            // Run checks
            $('#oneclick-run-checks').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true).text('<?php esc_html_e('Running...', 'woo-oneclick'); ?>');

                $.post(ajaxurl, {
                    action: 'oneclick_compat_check',
                    nonce: $btn.data('nonce')
                }, function(response) {
                    $btn.prop('disabled', false).text('<?php esc_html_e('Run Checks', 'woo-oneclick'); ?>');
                    if (response.success) {
                        renderChecks(response.data.checks);
                    }
                });
            });

            // Test email
            $('#oneclick-test-email-btn').on('click', function() {
                var $btn = $(this);
                var email = $('#oneclick-test-email-input').val();
                $btn.prop('disabled', true);
                $('#oneclick-test-email-status').text('<?php esc_html_e('Sending...', 'woo-oneclick'); ?>');

                $.post(ajaxurl, {
                    action: 'oneclick_test_email',
                    nonce: $btn.data('nonce'),
                    email: email
                }, function(response) {
                    $btn.prop('disabled', false);
                    var status = response.success ? '<?php esc_html_e('Sent!', 'woo-oneclick'); ?>' : (response.data.message || '<?php esc_html_e('Failed', 'woo-oneclick'); ?>');
                    var color = response.success ? '#00a32a' : '#d63638';
                    $('#oneclick-test-email-status').html('<span style="color:' + color + ';">' + status + '</span>');
                });
            });

            function renderChecks(checks) {
                var $tbody = $('#oneclick-compat-table tbody').empty();
                checks.forEach(function(check) {
                    var icon = check.pass ? '<span style="color:#00a32a; font-size:18px;">&#10003;</span>' : '<span style="color:#d63638; font-size:18px;">&#10007;</span>';
                    $tbody.append(
                        '<tr>' +
                        '<td style="text-align:center;">' + icon + '</td>' +
                        '<td><strong>' + check.name + '</strong></td>' +
                        '<td>' + check.detail + '</td>' +
                        '</tr>'
                    );
                });
            }
        });
        </script>
        <?php
    }

    /**
     * AJAX: Run compatibility checks
     */
    public function ajax_run_checks() {
        check_ajax_referer('oneclick_compat_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $checks = [];

        // PHP Version
        $checks[] = [
            'name'   => 'PHP Version',
            'pass'   => version_compare(PHP_VERSION, '8.1', '>='),
            'detail' => sprintf('Current: PHP %s (requires >= 8.1)', PHP_VERSION),
        ];

        // WooCommerce
        $wc_active = class_exists('WooCommerce');
        $wc_version = $wc_active ? WC()->version : 'Not installed';
        $checks[] = [
            'name'   => 'WooCommerce',
            'pass'   => $wc_active && version_compare($wc_version, '7.5', '>='),
            'detail' => sprintf('Version: %s (requires >= 7.5)', $wc_version),
        ];

        // HPOS (Custom Order Tables)
        $hpos_enabled = false;
        if (class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class)) {
            $hpos_enabled = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        }
        $checks[] = [
            'name'   => 'HPOS (Custom Order Tables)',
            'pass'   => true, // Works with both HPOS and legacy
            'detail' => $hpos_enabled ? 'Enabled (plugin is compatible)' : 'Disabled (plugin works in both modes)',
        ];

        // EdDSA Keys
        $private_key = get_option('oneclick_private_key');
        $public_key = get_option('oneclick_public_key');
        $checks[] = [
            'name'   => 'EdDSA Keypair',
            'pass'   => !empty($private_key) && !empty($public_key),
            'detail' => (!empty($private_key) && !empty($public_key))
                ? 'Generated and stored'
                : 'Missing — deactivate and reactivate plugin to generate',
        ];

        // Backend Public Key (synced from backend for JWT verification)
        $backend_pub_key = get_option('oneclick_backend_public_key');
        $checks[] = [
            'name'   => 'Backend Public Key',
            'pass'   => !empty($backend_pub_key),
            'detail' => !empty($backend_pub_key)
                ? 'Synced (used for JWT verification)'
                : 'Missing — go to OneClick Settings → License tab → click Refresh',
        ];

        // Stripe Gateway
        $stripe_settings = get_option('woocommerce_stripe_settings', []);
        $stripe_allowed = (int) get_option('oneclick_use_woocommerce_stripe_keys', 0) === 1;
        $stripe_enabled = !empty($stripe_settings['enabled']) && $stripe_settings['enabled'] === 'yes';
        $stripe_test_mode = !empty($stripe_settings['testmode']) && $stripe_settings['testmode'] === 'yes';
        $stripe_secret_key = $stripe_test_mode
            ? ($stripe_settings['test_secret_key'] ?? '')
            : ($stripe_settings['secret_key'] ?? '');
        $stripe_publishable_key = $stripe_test_mode
            ? ($stripe_settings['test_publishable_key'] ?? '')
            : ($stripe_settings['publishable_key'] ?? '');
        $stripe_key = !empty($stripe_secret_key) && !empty($stripe_publishable_key);
        $checks[] = [
            'name'   => 'Stripe Gateway',
            'pass'   => $stripe_allowed && $stripe_enabled && $stripe_key,
            'detail' => !$stripe_allowed
                ? 'Not allowed in OneClick settings'
                : ($stripe_enabled
                    ? ($stripe_key ? 'Allowed; WooCommerce Stripe Gateway is enabled with API keys' : 'Allowed; WooCommerce Stripe Gateway is enabled but API keys are incomplete')
                    : 'Allowed; WooCommerce Stripe Gateway is not enabled'),
        ];

        // Backend URL
        $backend_url = get_option('oneclick_backend_url', '');
        $checks[] = [
            'name'   => 'Backend URL',
            'pass'   => !empty($backend_url),
            'detail' => !empty($backend_url) ? $backend_url : 'Not configured',
        ];

        // Backend Health Check
        $backend_health = false;
        $backend_detail = 'Not tested';
        if (!empty($backend_url)) {
            $api = OneClick_API_Client::instance();
            $health = $api->get('/health');
            if (!is_wp_error($health) && isset($health['status'])) {
                $backend_health = ($health['status'] === 'ok' || $health['status'] === 'healthy');
                $backend_detail = sprintf('Status: %s, Version: %s', $health['status'] ?? 'unknown', $health['version'] ?? 'unknown');
            } else {
                $backend_detail = is_wp_error($health) ? $health->get_error_message() : 'Unexpected response';
            }
        }
        $checks[] = [
            'name'   => 'Backend Health',
            'pass'   => $backend_health,
            'detail' => $backend_detail,
        ];

        // Composer Dependencies
        $composer_ok = file_exists(ONECLICK_PLUGIN_DIR . 'vendor/autoload.php');
        $checks[] = [
            'name'   => 'Composer Dependencies',
            'pass'   => $composer_ok,
            'detail' => $composer_ok ? 'Installed (vendor/autoload.php found)' : 'Missing — run "composer install"',
        ];

        // WP Cron
        $cron_events = [
            'oneclick_daily_license_check' => 'Daily License Check',
            'oneclick_detect_abandoned'    => 'Abandoned Cart Detection',
            'oneclick_periodic_check'      => 'Periodic Email Check',
        ];
        foreach ($cron_events as $hook => $label) {
            $next = wp_next_scheduled($hook);
            $checks[] = [
                'name'   => 'Cron: ' . $label,
                'pass'   => !empty($next),
                'detail' => $next
                    ? sprintf('Next run: %s', wp_date('Y-m-d H:i:s', $next))
                    : 'Not scheduled',
            ];
        }

        wp_send_json_success(['checks' => $checks]);
    }

    /**
     * AJAX: Send test email via backend
     *
     * Generates a real JWT token and calls /api/send-email with correct fields.
     * This tests the full email path: JWT → backend → SendGrid → inbox.
     */
    public function ajax_test_email() {
        check_ajax_referer('oneclick_compat_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $email = sanitize_email($_POST['email'] ?? '');
        if (empty($email)) {
            wp_send_json_error(['message' => 'Invalid email address']);
        }

        // Find first real product from the store
        $products = wc_get_products(['status' => 'publish', 'limit' => 1]);
        if (empty($products)) {
            wp_send_json_error(['message' => 'No products found. Import test products first.']);
        }
        $product = $products[0];

        // Generate a test JWT token (backend signs it, with test flag)
        $jwt = new OneClick_JWT_Handler();
        $token_data = [
            'user_id'      => get_current_user_id(),
            'user_email'   => $email,
            'product_id'   => $product->get_id(),
            'product_name' => $product->get_name(),
            'price'        => (float) $product->get_price(),
            'test'         => true,
        ];

        $token = $jwt->generate_via_backend($token_data);

        if (!$token) {
            // Fallback to local generation
            $token = $jwt->generate($token_data);
        }

        if (!$token) {
            wp_send_json_error(['message' => 'Failed to generate JWT token for test email']);
        }

        // Call /api/send-email with the required fields
        $api = OneClick_API_Client::instance();
        $result = $api->post('/api/send-email', [
            'token'         => $token,
            'to_email'      => $email,
            'customer_name' => wp_get_current_user()->display_name ?: 'Test User',
            'product_name'  => $product->get_name(),
            'price'         => (float) $product->get_price(),
        ]);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['message' => sprintf(__('Test email sent to %s', 'woo-oneclick'), $email)]);
    }

}
