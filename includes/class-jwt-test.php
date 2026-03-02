<?php
/**
 * JWT Handler Test Page
 *
 * Admin page for testing JWT generation and verification
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

    /**
     * Add test page to admin menu
     */
    public function add_test_page() {
        add_submenu_page(
            'oneclick-settings',
            __('JWT Test', 'woo-oneclick'),
            __('🧪 JWT Test', 'woo-oneclick'),
            'manage_woocommerce',
            'oneclick-jwt-test',
            [$this, 'render_test_page']
        );
    }

    /**
     * Render test page
     */
    public function render_test_page() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        // Run tests
        $results = $this->run_tests();

        ?>
        <div class="wrap">
            <h1>🧪 JWT Handler Test</h1>
            <p>Testing EdDSA (Ed25519) token generation and verification</p>

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
                                <summary style="cursor: pointer; color: #0073aa;">Show details</summary>
                                <pre style="background: #f5f5f5; padding: 10px; overflow-x: auto; font-size: 11px;"><?php echo esc_html($test['details']); ?></pre>
                            </details>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div style="margin-top: 20px;">
                <a href="<?php echo admin_url('admin.php?page=oneclick-jwt-test'); ?>" class="button button-primary">
                    🔄 Run Tests Again
                </a>
            </div>
        </div>
        <?php
    }

    /**
     * Run JWT tests
     *
     * @return array Test results
     */
    private function run_tests() {
        $results = [];
        $jwt_handler = new OneClick_JWT_Handler();

        // Test 1: Check if keys exist
        $private_key = get_option('oneclick_private_key');
        $public_key = get_option('oneclick_public_key');

        $results[] = [
            'name' => 'Test 1: EdDSA Keys Exist',
            'pass' => !empty($private_key) && !empty($public_key),
            'message' => !empty($private_key) && !empty($public_key)
                ? 'Private and public keys found in WordPress options'
                : 'Keys not found! Try deactivating and reactivating the plugin.',
            'details' => !empty($public_key) ? 'Public Key: ' . substr($public_key, 0, 50) . '...' : ''
        ];

        // Test 2: Generate JWT token
        $test_payload = [
            'product_id' => 123,
            'product_name' => 'Test Product',
            'price' => 99.99,
            'user_id' => 1,
            'user_email' => 'test@example.com',
            'customer_name' => 'Test Customer'
        ];

        $token = $jwt_handler->generate($test_payload);

        $results[] = [
            'name' => 'Test 2: Generate JWT Token',
            'pass' => $token !== false,
            'message' => $token !== false
                ? 'JWT token generated successfully using EdDSA algorithm'
                : 'Failed to generate JWT token',
            'details' => $token !== false ? 'Token: ' . substr($token, 0, 100) . '...' : ''
        ];

        if ($token === false) {
            $results[] = [
                'name' => 'Test 3: Verify JWT Token',
                'pass' => false,
                'message' => 'Skipped (token generation failed)',
                'details' => ''
            ];
            return $results;
        }

        // Test 3: Verify JWT token
        $verification = $jwt_handler->verify($token);

        $results[] = [
            'name' => 'Test 3: Verify JWT Token',
            'pass' => $verification['valid'] === true,
            'message' => $verification['valid']
                ? 'Token verified successfully! Signature and expiration valid.'
                : 'Token verification failed: ' . ($verification['error'] ?? 'Unknown error'),
            'details' => $verification['valid']
                ? json_encode($verification['payload'], JSON_PRETTY_PRINT)
                : ''
        ];

        // Test 4: Check payload integrity
        if ($verification['valid']) {
            $payload = $verification['payload'];
            $payload_match = (
                $payload['product_id'] == $test_payload['product_id'] &&
                $payload['user_email'] == $test_payload['user_email'] &&
                $payload['price'] == $test_payload['price']
            );

            $results[] = [
                'name' => 'Test 4: Payload Integrity',
                'pass' => $payload_match,
                'message' => $payload_match
                    ? 'Payload data matches original input'
                    : 'Payload data does not match!',
                'details' => 'Original product_id: ' . $test_payload['product_id'] . "\n" .
                            'Decoded product_id: ' . ($payload['product_id'] ?? 'missing')
            ];

            // Test 5: Check JWT claims
            $has_claims = isset($payload['jti']) && isset($payload['iat']) && isset($payload['exp']) && isset($payload['wordpress_url']);

            $results[] = [
                'name' => 'Test 5: JWT Claims',
                'pass' => $has_claims,
                'message' => $has_claims
                    ? 'All required JWT claims present (jti, iat, exp, wordpress_url)'
                    : 'Missing required JWT claims',
                'details' => $has_claims
                    ? "JTI: " . $payload['jti'] . "\n" .
                      "Issued: " . date('Y-m-d H:i:s', $payload['iat']) . "\n" .
                      "Expires: " . date('Y-m-d H:i:s', $payload['exp']) . "\n" .
                      "WordPress URL: " . $payload['wordpress_url']
                    : ''
            ];
        }

        // Test 6: Test expired token (simulate)
        $expired_payload = $test_payload;
        $expired_payload['exp'] = time() - 3600; // 1 hour ago

        // We can't easily test this without modifying the generate function,
        // but we can test invalid token
        $invalid_token = 'eyJhbGciOiJFZERTQSIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.invalid';
        $invalid_verification = $jwt_handler->verify($invalid_token);

        $results[] = [
            'name' => 'Test 6: Invalid Token Detection',
            'pass' => $invalid_verification['valid'] === false,
            'message' => $invalid_verification['valid'] === false
                ? 'Invalid tokens are properly rejected'
                : 'WARNING: Invalid token was accepted!',
            'details' => 'Error: ' . ($invalid_verification['error'] ?? 'none')
        ];

        return $results;
    }
}
