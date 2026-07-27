<?php
/**
 * Backend Token Diagnostics
 *
 * Compatibility-only diagnostics for backend-delegated token operations.
 *
 * @package WooOneClick
 */

if (!defined('ABSPATH')) {
    exit;
}

class OneClick_JWT_Test {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_test_page'], 100);
    }

    public function add_test_page() {
        add_submenu_page(
            'oneclick-settings',
            __('Backend Token Diagnostics', 'woo-oneclick'),
            __('Backend Token Diagnostics', 'woo-oneclick'),
            'manage_woocommerce',
            'oneclick-jwt-test',
            [$this, 'render_test_page']
        );
    }

    public function render_test_page() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        ob_start();
        $results = $this->run_tests();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Backend Token Diagnostics', 'woo-oneclick'); ?></h1>
            <p><?php esc_html_e('OneClick token signing and verification live on the backend. This page checks backend compatibility only; it does not require or generate local EdDSA keys in WordPress.', 'woo-oneclick'); ?></p>

            <div style="background: white; padding: 20px; border: 1px solid #ccc; margin-top: 20px;">
                <?php foreach ($results as $test): ?>
                    <div style="margin-bottom: 30px; padding: 15px; border-left: 4px solid <?php echo $test['pass'] ? '#46b450' : '#dc3232'; ?>; background: <?php echo $test['pass'] ? '#f0fff4' : '#fff0f0'; ?>;">
                        <h3 style="margin-top: 0;">
                            <?php echo $test['pass'] ? '✅' : '❌'; ?>
                            <?php echo esc_html($test['name']); ?>
                        </h3>
                        <p><?php echo esc_html($test['message']); ?></p>
                        <?php if (!empty($test['details'])): ?>
                            <details>
                                <summary style="cursor: pointer; color: #0073aa;"><?php esc_html_e('Show details', 'woo-oneclick'); ?></summary>
                                <pre style="background: #f5f5f5; padding: 10px; overflow-x: auto; font-size: 11px;"><?php echo esc_html($test['details']); ?></pre>
                            </details>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div style="margin-top: 20px;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=oneclick-jwt-test')); ?>" class="button button-primary">
                    <?php esc_html_e('Run Diagnostics Again', 'woo-oneclick'); ?>
                </a>
            </div>
        </div>
        <?php
        OneClick_Admin_UI::render('oneclick-jwt-test', ob_get_clean());
    }

    private function run_tests() {
        $results = [];
        $jwt_handler = new OneClick_JWT_Handler();

        $license_key = get_option('oneclick_license_key', '');
        $results[] = [
            'name' => 'License Key',
            'pass' => !empty($license_key),
            'message' => !empty($license_key)
                ? 'License key is stored and will be sent server-side to the backend.'
                : 'License key is missing. Activate the license before running token diagnostics.',
            'details' => '',
        ];

        $api = OneClick_API_Client::instance();
        $public_key = $api->get('/api/public-key');
        $results[] = [
            'name' => 'Backend Public Key',
            'pass' => !is_wp_error($public_key) && !empty($public_key['public_key_base64']),
            'message' => !is_wp_error($public_key) && !empty($public_key['public_key_base64'])
                ? 'Backend public key endpoint is reachable.'
                : 'Backend public key endpoint failed.',
            'details' => is_wp_error($public_key) ? $public_key->get_error_message() : ('Algorithm: ' . ($public_key['algorithm'] ?? 'unknown')),
        ];

        $test_payload = [
            'product_id' => 123,
            'product_name' => 'Diagnostic Product',
            'price' => 1.00,
            'user_id' => get_current_user_id(),
            'user_email' => wp_get_current_user()->user_email,
            'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'EUR',
            'test' => true,
        ];

        $token = !empty($license_key) ? $jwt_handler->generate_via_backend($test_payload) : false;
        $results[] = [
            'name' => 'Backend Token Generation',
            'pass' => $token !== false,
            'message' => $token !== false
                ? 'Backend generated a compatibility token successfully.'
                : 'Backend token generation failed.',
            'details' => $token !== false ? 'Token prefix: ' . substr($token, 0, 24) . '...' : '',
        ];

        $invalid_token = 'eyJhbGciOiJFZERTQSIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.invalid';
        $invalid_verification = !empty($license_key)
            ? $jwt_handler->verify_via_backend($invalid_token)
            : ['valid' => false, 'error' => 'Skipped because license key is missing'];

        $results[] = [
            'name' => 'Invalid Token Rejection',
            'pass' => $invalid_verification['valid'] === false,
            'message' => $invalid_verification['valid'] === false
                ? 'Backend correctly rejected an invalid token.'
                : 'Backend unexpectedly accepted an invalid token.',
            'details' => 'Error: ' . ($invalid_verification['error'] ?? 'none'),
        ];

        oneclick_log('OneClick Token Diagnostics: checks completed', 'oneclick-backend');
        return $results;
    }
}
