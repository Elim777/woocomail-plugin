<?php
/**
 * Token Blacklist Test Page
 *
 * Admin page for testing blacklist functionality
 *
 * @package WooOneClick
 */

if (!defined('ABSPATH')) {
    exit;
}

class OneClick_Blacklist_Test {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_test_page'], 100);
    }

    /**
     * Add test page to admin menu
     */
    public function add_test_page() {
        add_submenu_page(
            'oneclick-settings',
            __('Blacklist Test', 'woo-oneclick'),
            __('🛡️ Blacklist Test', 'woo-oneclick'),
            'manage_woocommerce',
            'oneclick-blacklist-test',
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
            <h1>🛡️ Token Blacklist Test</h1>
            <p>Testing Redis/Transients storage for replay attack prevention</p>

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
                <a href="<?php echo admin_url('admin.php?page=oneclick-blacklist-test'); ?>" class="button button-primary">
                    🔄 Run Tests Again
                </a>
            </div>
        </div>
        <?php
    }

    /**
     * Run blacklist tests
     *
     * @return array Test results
     */
    private function run_tests() {
        $results = [];
        $blacklist = new OneClick_Token_Blacklist();

        // Test 1: Check storage backend
        $connection_test = $blacklist->test_connection();

        $results[] = [
            'name' => 'Test 1: Storage Backend',
            'pass' => $connection_test['success'],
            'message' => $connection_test['message'] ?? $connection_test['error'] ?? 'Unknown',
            'details' => 'Storage: ' . $connection_test['storage']
        ];

        // Test 2: Mark token as used
        $test_jti = 'test-jti-' . wp_generate_uuid4();
        $mark_result = $blacklist->mark_token_used($test_jti);

        $results[] = [
            'name' => 'Test 2: Mark Token as Used',
            'pass' => $mark_result === true,
            'message' => $mark_result ? 'Successfully marked token as used' : 'Failed to mark token as used',
            'details' => 'Test JTI: ' . substr($test_jti, 0, 20) . '...'
        ];

        // Test 3: Check if token is blacklisted
        $is_used = $blacklist->is_token_used($test_jti);

        $results[] = [
            'name' => 'Test 3: Verify Token is Blacklisted',
            'pass' => $is_used === true,
            'message' => $is_used ? 'Token correctly identified as used' : 'Token NOT found in blacklist!',
            'details' => 'Expected: true, Got: ' . ($is_used ? 'true' : 'false')
        ];

        // Test 4: Check non-existent token
        $fake_jti = 'fake-jti-never-existed';
        $is_used_fake = $blacklist->is_token_used($fake_jti);

        $results[] = [
            'name' => 'Test 4: Non-existent Token Check',
            'pass' => $is_used_fake === false,
            'message' => !$is_used_fake ? 'Correctly returned false for non-existent token' : 'WARNING: False positive!',
            'details' => 'Expected: false, Got: ' . ($is_used_fake ? 'true' : 'false')
        ];

        // Test 5: Blacklist statistics
        $stats = $blacklist->get_stats();

        $results[] = [
            'name' => 'Test 5: Blacklist Statistics',
            'pass' => !empty($stats['storage']),
            'message' => 'Statistics retrieved successfully',
            'details' => json_encode($stats, JSON_PRETTY_PRINT)
        ];

        // Test 6: Empty JTI handling
        $empty_result = $blacklist->is_token_used('');

        $results[] = [
            'name' => 'Test 6: Empty JTI Handling',
            'pass' => $empty_result === false,
            'message' => $empty_result === false ? 'Empty JTI correctly rejected' : 'WARNING: Empty JTI accepted!',
            'details' => 'Expected: false, Got: ' . ($empty_result ? 'true' : 'false')
        ];

        return $results;
    }
}
