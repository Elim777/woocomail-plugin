<?php
/**
 * Compatibility Test
 *
 * Production-oriented diagnostics for the current backend-centric OneClick
 * architecture.
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

    public function render_page() {
        $nonce = wp_create_nonce('oneclick_compat_nonce');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Compatibility Test', 'woo-oneclick'); ?></h1>
            <p class="description"><?php esc_html_e('Checks the live WordPress, WooCommerce, backend, license, worker and email-delivery requirements used by the current OneClick architecture.', 'woo-oneclick'); ?></p>

            <div class="card" style="max-width: 900px; margin-top: 20px;">
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

            <div class="card" style="max-width: 900px; margin-top: 20px;">
                <h2><?php esc_html_e('Test Email Delivery', 'woo-oneclick'); ?></h2>
                <p class="description"><?php esc_html_e('Sends a diagnostic email through the licensed backend and tenant SendGrid routing. It does not create a purchase link, offer, session, claim or order.', 'woo-oneclick'); ?></p>
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
            $('#oneclick-run-checks').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true).text('<?php echo esc_js(__('Running...', 'woo-oneclick')); ?>');

                $.post(ajaxurl, {
                    action: 'oneclick_compat_check',
                    nonce: $btn.data('nonce')
                }).done(function(response) {
                    if (response.success) {
                        renderChecks(response.data.checks || []);
                    } else {
                        renderError(response.data && response.data.message ? response.data.message : '<?php echo esc_js(__('Checks failed.', 'woo-oneclick')); ?>');
                    }
                }).fail(function(xhr) {
                    renderError(xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ? xhr.responseJSON.data.message : '<?php echo esc_js(__('Request failed. Check WooCommerce logs source oneclick-core.', 'woo-oneclick')); ?>');
                }).always(function() {
                    $btn.prop('disabled', false).text('<?php echo esc_js(__('Run Checks', 'woo-oneclick')); ?>');
                });
            });

            $('#oneclick-test-email-btn').on('click', function() {
                var $btn = $(this);
                var email = $('#oneclick-test-email-input').val();
                $btn.prop('disabled', true);
                $('#oneclick-test-email-status').text('<?php echo esc_js(__('Sending...', 'woo-oneclick')); ?>');

                $.post(ajaxurl, {
                    action: 'oneclick_test_email',
                    nonce: $btn.data('nonce'),
                    email: email
                }).done(function(response) {
                    var status = response.success ? (response.data.message || '<?php echo esc_js(__('Sent!', 'woo-oneclick')); ?>') : (response.data && response.data.message ? response.data.message : '<?php echo esc_js(__('Failed', 'woo-oneclick')); ?>');
                    var color = response.success ? '#00a32a' : '#d63638';
                    $('#oneclick-test-email-status').html('<span style="color:' + color + ';">' + escapeHtml(status) + '</span>');
                }).fail(function(xhr) {
                    var message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ? xhr.responseJSON.data.message : '<?php echo esc_js(__('Request failed. Check WooCommerce logs source oneclick-core.', 'woo-oneclick')); ?>';
                    $('#oneclick-test-email-status').html('<span style="color:#d63638;">' + escapeHtml(message) + '</span>');
                }).always(function() {
                    $btn.prop('disabled', false);
                });
            });

            function renderChecks(checks) {
                var $tbody = $('#oneclick-compat-table tbody').empty();
                checks.forEach(function(check) {
                    var icon = check.pass ? '<span style="color:#00a32a; font-size:18px;">&#10003;</span>' : '<span style="color:#d63638; font-size:18px;">&#10007;</span>';
                    $tbody.append(
                        '<tr>' +
                        '<td style="text-align:center;">' + icon + '</td>' +
                        '<td><strong>' + escapeHtml(check.name) + '</strong></td>' +
                        '<td>' + escapeHtml(check.detail) + '</td>' +
                        '</tr>'
                    );
                });
            }

            function renderError(message) {
                $('#oneclick-compat-table tbody').html('<tr><td colspan="3"><span style="color:#d63638;">' + escapeHtml(message) + '</span></td></tr>');
            }

            function escapeHtml(value) {
                return $('<div>').text(value || '').html();
            }
        });
        </script>
        <?php
    }

    public function ajax_run_checks() {
        check_ajax_referer('oneclick_compat_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $checks = [];
        if (empty(get_option('oneclick_license_key', '')) && class_exists('OneClick_Settings')) {
            $settings = new OneClick_Settings();
            $settings->refresh_license_from_backend();
        }

        $checks[] = [
            'name'   => 'PHP Version',
            'pass'   => version_compare(PHP_VERSION, '8.1', '>='),
            'detail' => sprintf('Current: PHP %s (requires >= 8.1)', PHP_VERSION),
        ];

        $wc_active = class_exists('WooCommerce');
        $wc_version = $wc_active ? WC()->version : 'Not installed';
        $checks[] = [
            'name'   => 'WooCommerce',
            'pass'   => $wc_active && version_compare($wc_version, '7.5', '>='),
            'detail' => sprintf('Version: %s (requires >= 7.5)', $wc_version),
        ];

        $hpos_enabled = false;
        if (class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class)) {
            $hpos_enabled = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        }
        $checks[] = [
            'name'   => 'HPOS (Custom Order Tables)',
            'pass'   => true,
            'detail' => $hpos_enabled ? 'Enabled (plugin is compatible)' : 'Disabled (plugin works in both modes)',
        ];

        $logger_available = function_exists('wc_get_logger') && wc_get_logger();
        $checks[] = [
            'name'   => 'WooCommerce Logger',
            'pass'   => (bool) $logger_available,
            'detail' => $logger_available ? 'Available — OneClick logs write to WooCommerce → Status → Logs' : 'Unavailable — OneClick will fallback to PHP error_log',
        ];

        $backend_url = get_option('oneclick_backend_url', '');
        $checks[] = [
            'name'   => 'Backend URL',
            'pass'   => !empty($backend_url),
            'detail' => !empty($backend_url) ? $backend_url : 'Not configured',
        ];

        $api = OneClick_API_Client::instance();
        $health = !empty($backend_url) ? $api->get('/health') : new WP_Error('missing_backend_url', 'Backend URL is not configured');
        $checks[] = [
            'name'   => 'Backend Health',
            'pass'   => !is_wp_error($health) && isset($health['status']) && in_array($health['status'], ['ok', 'healthy'], true),
            'detail' => !is_wp_error($health)
                ? sprintf('Status: %s, Version: %s', $health['status'] ?? 'unknown', $health['version'] ?? 'unknown')
                : $health->get_error_message(),
        ];

        $license_key = get_option('oneclick_license_key', '');
        $checks[] = [
            'name'   => 'License Key',
            'pass'   => !empty($license_key),
            'detail' => !empty($license_key) ? 'Stored in WordPress options (masked in UI)' : 'Missing — activate license in OneClick settings',
        ];

        $licensed_check = !empty($license_key) ? $api->post('/api/license/validate', [
            'license_key' => $license_key,
            'site_url' => site_url(),
        ]) : new WP_Error('missing_license', 'License key is missing');
        $checks[] = [
            'name'   => 'Licensed Backend API',
            'pass'   => !is_wp_error($licensed_check) && !empty($licensed_check['valid']),
            'detail' => !is_wp_error($licensed_check)
                ? (!empty($licensed_check['valid']) ? 'License accepted by backend for this tenant' : ($licensed_check['error'] ?? 'License validation failed'))
                : $licensed_check->get_error_message(),
        ];

        $backend_pub_key = get_option('oneclick_backend_public_key');
        $checks[] = [
            'name'   => 'Legacy Backend Public Key',
            'pass'   => true,
            'detail' => !empty($backend_pub_key)
                ? 'Synced; kept only for compatibility diagnostics'
                : 'Not synced; optional for current purchase-session flow',
        ];

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

        $composer_ok = file_exists(ONECLICK_PLUGIN_DIR . 'vendor/autoload.php');
        $checks[] = [
            'name'   => 'Composer Dependencies',
            'pass'   => $composer_ok,
            'detail' => $composer_ok ? 'Installed (vendor/autoload.php found)' : 'Missing — install packaged plugin build with vendor dependencies',
        ];

        $action_scheduler_available = function_exists('as_next_scheduled_action') || class_exists('ActionScheduler');
        $fallback_worker_next = wp_next_scheduled('oneclick_process_due_purchase_sessions');
        $checks[] = [
            'name'   => 'Session Worker',
            'pass'   => $action_scheduler_available || !empty($fallback_worker_next),
            'detail' => $action_scheduler_available
                ? 'Action Scheduler available for due purchase sessions'
                : ($fallback_worker_next ? sprintf('WP-Cron fallback scheduled: %s', wp_date('Y-m-d H:i:s', $fallback_worker_next)) : 'No Action Scheduler and no WP-Cron fallback scheduled'),
        ];

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
                'detail' => $next ? sprintf('Next run: %s', wp_date('Y-m-d H:i:s', $next)) : 'Not scheduled',
            ];
        }

        oneclick_log('OneClick Compatibility: checks completed', 'oneclick-core');
        wp_send_json_success(['checks' => $checks]);
    }

    public function ajax_test_email() {
        check_ajax_referer('oneclick_compat_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $email = sanitize_email($_POST['email'] ?? '');
        if (empty($email)) {
            wp_send_json_error(['message' => 'Invalid email address']);
        }

        if (empty(get_option('oneclick_license_key', '')) && class_exists('OneClick_Settings')) {
            $settings = new OneClick_Settings();
            $license_refresh = $settings->refresh_license_from_backend();
            if (is_wp_error($license_refresh)) {
                wp_send_json_error(['message' => $license_refresh->get_error_message()]);
            }
        }

        $api = OneClick_API_Client::instance();
        $result = $api->post('/api/email/test-delivery', [
            'site_url' => site_url(),
            'to_email' => $email,
        ], [
            'timeout' => 20,
        ]);

        if (is_wp_error($result)) {
            oneclick_log('OneClick Compatibility: test email failed — ' . $result->get_error_message(), 'oneclick-core', 'error');
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        oneclick_log(sprintf('OneClick Compatibility: test email accepted for %s', $email), 'oneclick-core');
        wp_send_json_success(['message' => sprintf(__('Test email sent to %s', 'woo-oneclick'), $email)]);
    }
}
