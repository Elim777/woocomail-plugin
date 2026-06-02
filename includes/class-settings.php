<?php
/**
 * Settings Page
 *
 * Admin UI for plugin configuration.
 * Includes License section with tier info, upgrade button, and auto-check.
 *
 * @package WooOneClick
 * @since 1.0.0 — basic settings
 * @since 1.1.0 — License UI, tabs, auto-check after Stripe checkout
 */

if (!defined('ABSPATH')) {
    exit;
}

class OneClick_Settings {

    /** @var string Current admin tab */
    private $current_tab = 'general';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'handle_license_check_after_checkout']);
        add_action('admin_init', [$this, 'delete_legacy_stripe_key_options']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_styles']);
    }

    /**
     * Add admin menu
     */
    public function add_menu() {
        add_menu_page(
            __('One-Click Purchase', 'woo-oneclick'),
            __('One-Click', 'woo-oneclick'),
            'manage_woocommerce',
            'oneclick-settings',
            [$this, 'render_settings_page'],
            'dashicons-email-alt',
            56
        );

        // Submenu pages for Actions/Reactions/Rules are registered
        // by their own classes (class-actions-admin, etc.)
    }

    /**
     * Register settings
     */
    public function register_settings() {
        // Backend URL
        register_setting('oneclick_settings', 'oneclick_backend_url', [
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => 'https://woocomail-api.onrender.com'
        ]);

        // Stripe integration consent. Keys stay in WooCommerce Stripe Gateway settings.
        register_setting('oneclick_settings', 'oneclick_use_woocommerce_stripe_keys', [
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 0,
        ]);

        register_setting('oneclick_settings', 'oneclick_purchase_link_mode', [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitize_purchase_link_mode'],
            'default' => 'all_with_cart_fallback',
        ]);

        register_setting('oneclick_settings', 'oneclick_purchase_completion_mode', [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitize_purchase_completion_mode'],
            'default' => 'purchase_session',
        ]);

        // --- General tab sections ---
        add_settings_section(
            'oneclick_stripe_section',
            __('Stripe Configuration', 'woo-oneclick'),
            [$this, 'render_stripe_section'],
            'oneclick-settings-general'
        );

        // Fields: Stripe
        add_settings_field(
            'oneclick_use_woocommerce_stripe_keys',
            __('Use WooCommerce Stripe', 'woo-oneclick'),
            [$this, 'render_checkbox_field'],
            'oneclick-settings-general',
            'oneclick_stripe_section',
            [
                'label_for' => 'oneclick_use_woocommerce_stripe_keys',
                'label' => __('Allow OneClick to use the Stripe keys already configured in WooCommerce Stripe Gateway.', 'woo-oneclick'),
            ]
        );

        add_settings_field(
            'oneclick_purchase_link_mode',
            __('Purchase Link Behavior', 'woo-oneclick'),
            [$this, 'render_purchase_link_mode_field'],
            'oneclick-settings-general',
            'oneclick_stripe_section'
        );

    }

    public function sanitize_purchase_link_mode($value) {
        $allowed = ['all_with_cart_fallback', 'card_only'];
        return in_array($value, $allowed, true) ? $value : 'all_with_cart_fallback';
    }

    public function sanitize_purchase_completion_mode($value) {
        if (!self::immediate_purchase_enabled()) {
            return 'purchase_session';
        }

        $allowed = ['purchase_session', 'immediate_purchase'];
        return in_array($value, $allowed, true) ? $value : 'purchase_session';
    }

    public static function immediate_purchase_enabled() {
        $enabled = defined('ONECLICK_ALLOW_IMMEDIATE_PURCHASE') && ONECLICK_ALLOW_IMMEDIATE_PURCHASE;
        return (bool) apply_filters('oneclick_allow_immediate_purchase', $enabled);
    }

    private function mask_license_key($license_key) {
        $license_key = (string) $license_key;
        if (strlen($license_key) <= 10) {
            return $license_key ? '****' : '';
        }

        return substr($license_key, 0, 7) . '******' . substr($license_key, -4);
    }

    /**
     * Handle license check after returning from Stripe checkout
     *
     * When user returns from Stripe with ?page=oneclick-settings&tab=license&upgraded=1,
     * we call backend to refresh license status.
     */
    public function handle_license_check_after_checkout() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'oneclick-settings') {
            return;
        }
        if (!isset($_GET['upgraded']) || $_GET['upgraded'] !== '1') {
            return;
        }
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $session_id = isset($_GET['session_id']) ? sanitize_text_field($_GET['session_id']) : '';

        // Primary activation: verify Stripe session directly via backend
        // Backend retrieves session from Stripe API → creates license in DB
        if (!empty($session_id)) {
            $api = OneClick_API_Client::instance();
            $activation = $api->post('/api/license/activate-from-session', [
                'session_id' => $session_id,
                'site_url'   => site_url(),
            ]);

            if (is_wp_error($activation)) {
                oneclick_log('OneClick License: Session activation failed: ' . $activation->get_error_message());
            } else {
                oneclick_log('OneClick License: Session activation result: ' . wp_json_encode($activation));
            }
        }

        // Refresh license info from backend DB
        $this->refresh_license_from_backend();

        // Remove the query params to prevent re-triggering on refresh
        wp_safe_redirect(admin_url('admin.php?page=oneclick-settings&tab=license&license_refreshed=1'));
        exit;
    }

    /**
     * Refresh license info from backend
     *
     * Calls POST /api/license/check with site_url.
     * Stores result in wp_options.
     *
     * @return array|WP_Error Backend response or error
     */
    public function refresh_license_from_backend() {
        $api = OneClick_API_Client::instance();
        $result = $api->post('/api/license/check', [
            'site_url' => site_url(),
        ]);

        if (is_wp_error($result)) {
            oneclick_log('OneClick License: Failed to check license: ' . $result->get_error_message());
            return $result;
        }

        // Store license data in wp_options
        if (!empty($result['has_license']) && $result['has_license'] === true) {
            update_option('oneclick_license_key', $result['license_key'] ?? '', true);
            update_option('oneclick_license_tier', $result['tier'] ?? 'free', true);
            update_option('oneclick_license_status', $result['status'] ?? 'free', true);
            update_option('oneclick_license_quota', $result['email_quota'] ?? 0, true);
            update_option('oneclick_license_max_activations', $result['max_activations'] ?? 1, true);
            update_option('oneclick_license_expires', $result['expires_at'] ?? '', true);
        } else {
            update_option('oneclick_license_tier', $result['tier'] ?? 'free', true);
            update_option('oneclick_license_status', $result['status'] ?? 'free', true);
            update_option('oneclick_license_quota', $result['email_quota'] ?? 50, true);
            // Clear key-related options for free tier
            delete_option('oneclick_license_key');
            delete_option('oneclick_license_max_activations');
            delete_option('oneclick_license_expires');
        }

        update_option('oneclick_license_last_check', current_time('mysql'), true);

        // Sync backend public key (needed for JWT verification)
        $this->sync_backend_public_key();

        oneclick_log(sprintf(
            'OneClick License: Refreshed — tier=%s, status=%s, quota=%d',
            $result['tier'] ?? 'free',
            $result['status'] ?? 'free',
            $result['email_quota'] ?? 0
        ));

        return $result;
    }

    /**
     * Fetch and store backend's Ed25519 public key
     *
     * Plugin needs the backend public key to verify JWT tokens
     * that were signed by the backend's private key.
     */
    private function sync_backend_public_key() {
        $api = OneClick_API_Client::instance();
        $result = $api->get('/api/public-key');

        if (is_wp_error($result)) {
            oneclick_log('OneClick: Failed to sync backend public key: ' . $result->get_error_message());
            return;
        }

        $pub_key_b64 = $result['public_key_base64'] ?? '';
        if (empty($pub_key_b64)) {
            oneclick_log('OneClick: Backend returned empty public key');
            return;
        }

        update_option('oneclick_backend_public_key', $pub_key_b64, true);
        oneclick_log('OneClick: Backend public key synced successfully');
    }

    /**
     * Remove legacy OneClick-owned Stripe key options.
     *
     * Merchant Stripe API keys must remain owned by WooCommerce Stripe Gateway.
     * OneClick only stores consent to use those gateway settings.
     */
    public function delete_legacy_stripe_key_options() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        delete_option('oneclick_stripe_secret_key');
        delete_option('oneclick_stripe_publishable_key');
    }

    /**
     * Enqueue admin styles for settings page
     */
    public function enqueue_admin_styles($hook) {
        if ($hook !== 'toplevel_page_oneclick-settings') {
            return;
        }

        wp_add_inline_style('wp-admin', '
            .oneclick-tabs { margin: 20px 0 10px; }
            .oneclick-tabs .nav-tab { cursor: pointer; }
            .oneclick-license-box {
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
                padding: 20px;
                margin: 15px 0;
            }
            .oneclick-license-box h3 { margin-top: 0; }
            .oneclick-tier-badge {
                display: inline-block;
                padding: 4px 12px;
                border-radius: 3px;
                font-weight: 600;
                font-size: 13px;
                text-transform: uppercase;
            }
            .oneclick-tier-free { background: #f0f0f1; color: #50575e; }
            .oneclick-tier-pro { background: #dff0d8; color: #3c763d; }
            .oneclick-tier-enterprise { background: #d9edf7; color: #31708f; }
            .oneclick-license-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 15px;
                margin: 15px 0;
            }
            .oneclick-license-grid dt {
                font-weight: 600;
                color: #1d2327;
            }
            .oneclick-license-grid dd {
                margin: 0;
                color: #50575e;
            }
            .oneclick-upgrade-btn {
                display: inline-block;
                padding: 10px 24px;
                background: #0073aa;
                color: #fff !important;
                text-decoration: none !important;
                border-radius: 4px;
                font-size: 14px;
                font-weight: 600;
                margin-top: 10px;
            }
            .oneclick-upgrade-btn:hover {
                background: #005a87;
                color: #fff !important;
            }
        ');
    }

    /**
     * Render settings page with tabs
     */
    public function render_settings_page() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $this->current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';

        // Show notice if license was just refreshed
        if (isset($_GET['license_refreshed'])) {
            $tier = get_option('oneclick_license_tier', 'free');
            echo '<div class="notice notice-success is-dismissible"><p>';
            printf(
                __('License status refreshed. Current tier: <strong>%s</strong>', 'woo-oneclick'),
                esc_html(strtoupper($tier))
            );
            echo '</p></div>';
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <nav class="nav-tab-wrapper oneclick-tabs">
                <a href="<?php echo esc_url(admin_url('admin.php?page=oneclick-settings&tab=general')); ?>"
                   class="nav-tab <?php echo $this->current_tab === 'general' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('General', 'woo-oneclick'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=oneclick-settings&tab=license')); ?>"
                   class="nav-tab <?php echo $this->current_tab === 'license' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('License', 'woo-oneclick'); ?>
                </a>
            </nav>

            <?php
            if ($this->current_tab === 'license') {
                $this->render_license_tab();
            } else {
                $this->render_general_tab();
            }
            ?>
        </div>
        <?php
    }

    /**
     * Render General settings tab
     */
    private function render_general_tab() {
        ?>
        <form action="options.php" method="post">
            <?php
            settings_fields('oneclick_settings');
            ?>
            <input type="hidden"
                   name="oneclick_backend_url"
                   value="<?php echo esc_attr(get_option('oneclick_backend_url', 'https://woocomail-api.onrender.com')); ?>">
            <?php
            do_settings_sections('oneclick-settings-general');
            submit_button(__('Save Settings', 'woo-oneclick'));
            ?>
        </form>
        <?php
    }

    /**
     * Render License tab
     */
    private function render_license_tab() {
        $tier = get_option('oneclick_license_tier', 'free');
        $status = get_option('oneclick_license_status', 'free');
        $quota = get_option('oneclick_license_quota', 50);
        $license_key = get_option('oneclick_license_key', '');
        $max_activations = get_option('oneclick_license_max_activations', '');
        $expires_at = get_option('oneclick_license_expires', '');
        $last_check = get_option('oneclick_license_last_check', '');

        $backend_url = rtrim(get_option('oneclick_backend_url', 'https://woocomail-api.onrender.com'), '/');
        $checkout_url = $backend_url . '/checkout?' . http_build_query([
            'site_url' => site_url(),
            'tier' => 'pro',
        ]);

        $tier_class = 'oneclick-tier-' . esc_attr($tier);
        ?>
        <div class="oneclick-license-box">
            <h3><?php _e('License Status', 'woo-oneclick'); ?></h3>

            <p>
                <?php _e('Current Tier:', 'woo-oneclick'); ?>
                <span class="oneclick-tier-badge <?php echo $tier_class; ?>">
                    <?php echo esc_html(strtoupper($tier)); ?>
                </span>
                <?php if ($status === 'expired'): ?>
                    <span style="color: #d63638; margin-left: 10px; font-weight: 600;">
                        <?php _e('EXPIRED', 'woo-oneclick'); ?>
                    </span>
                <?php endif; ?>
            </p>

            <dl class="oneclick-license-grid">
                <dt><?php _e('Email Quota', 'woo-oneclick'); ?></dt>
                <dd><?php echo esc_html(number_format($quota)); ?> <?php _e('emails/month', 'woo-oneclick'); ?></dd>

                <?php if (!empty($license_key)): ?>
                    <dt><?php _e('License Key', 'woo-oneclick'); ?></dt>
                    <dd><code><?php echo esc_html($this->mask_license_key($license_key)); ?></code></dd>
                <?php endif; ?>

                <?php if (!empty($max_activations)): ?>
                    <dt><?php _e('Max Activations', 'woo-oneclick'); ?></dt>
                    <dd><?php echo esc_html($max_activations); ?></dd>
                <?php endif; ?>

                <?php if (!empty($expires_at)): ?>
                    <dt><?php _e('Expires', 'woo-oneclick'); ?></dt>
                    <dd>
                        <?php
                        $date = strtotime($expires_at);
                        echo $date ? esc_html(date_i18n(get_option('date_format'), $date)) : esc_html($expires_at);
                        ?>
                    </dd>
                <?php endif; ?>

                <dt><?php _e('Last Checked', 'woo-oneclick'); ?></dt>
                <dd><?php echo !empty($last_check) ? esc_html($last_check) : __('Never', 'woo-oneclick'); ?></dd>
            </dl>

            <?php if ($tier === 'free'): ?>
                <hr>
                <h4><?php _e('Upgrade to PRO', 'woo-oneclick'); ?></h4>
                <p><?php _e('Get 5,000 emails/month, AI Setup, and priority support.', 'woo-oneclick'); ?></p>
                <a href="<?php echo esc_url($checkout_url); ?>" class="oneclick-upgrade-btn">
                    <?php _e('Upgrade to PRO', 'woo-oneclick'); ?>
                </a>
            <?php endif; ?>

            <hr>
            <p>
                <?php
                $refresh_url = wp_nonce_url(
                    admin_url('admin.php?page=oneclick-settings&tab=license&action=refresh_license'),
                    'oneclick_refresh_license'
                );
                ?>
                <a href="<?php echo esc_url($refresh_url); ?>" class="button">
                    <?php _e('Refresh License Status', 'woo-oneclick'); ?>
                </a>
                <span class="description" style="margin-left: 10px;">
                    <?php _e('License is also checked automatically once per day.', 'woo-oneclick'); ?>
                </span>
            </p>
        </div>

        <?php
        // Handle manual refresh
        if (isset($_GET['action']) && $_GET['action'] === 'refresh_license') {
            if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'oneclick_refresh_license')) {
                wp_die(__('Security check failed.', 'woo-oneclick'));
            }
            $this->refresh_license_from_backend();
            wp_safe_redirect(admin_url('admin.php?page=oneclick-settings&tab=license&license_refreshed=1'));
            exit;
        }
    }

    /**
     * Section descriptions
     */
    public function render_stripe_section() {
        $stripe_settings = get_option('woocommerce_stripe_settings', []);
        $enabled = !empty($stripe_settings['enabled']) && $stripe_settings['enabled'] === 'yes';
        $test_mode = !empty($stripe_settings['testmode']) && $stripe_settings['testmode'] === 'yes';
        $secret_key = $test_mode
            ? ($stripe_settings['test_secret_key'] ?? '')
            : ($stripe_settings['secret_key'] ?? '');
        $publishable_key = $test_mode
            ? ($stripe_settings['test_publishable_key'] ?? '')
            : ($stripe_settings['publishable_key'] ?? '');
        $has_keys = !empty($secret_key) && !empty($publishable_key);

        echo '<p>' . __('OneClick does not store separate Stripe API keys. It can use the keys already configured in WooCommerce Stripe Gateway after you allow it below.', 'woo-oneclick') . '</p>';

        if ($enabled && $has_keys) {
            echo '<p style="color: green;">' . __('WooCommerce Stripe Gateway is enabled and has API keys configured.', 'woo-oneclick') . '</p>';
        } elseif ($enabled) {
            echo '<p style="color: orange;">' . __('WooCommerce Stripe Gateway is enabled, but its API keys are incomplete.', 'woo-oneclick') . '</p>';
        } else {
            echo '<p style="color: orange;">' . __('WooCommerce Stripe Gateway is not enabled. One-click Stripe payments will not run until it is configured there.', 'woo-oneclick') . '</p>';
        }
    }

    /**
     * Render text field
     */
    public function render_text_field($args) {
        $option = get_option($args['label_for']);
        $type = $args['type'] ?? 'text';
        $placeholder = $args['placeholder'] ?? '';
        ?>
        <input type="<?php echo esc_attr($type); ?>"
               id="<?php echo esc_attr($args['label_for']); ?>"
               name="<?php echo esc_attr($args['label_for']); ?>"
               value="<?php echo esc_attr($option); ?>"
               placeholder="<?php echo esc_attr($placeholder); ?>"
               class="regular-text"
               <?php echo $type === 'number' ? 'min="0"' : ''; ?>>
        <?php
    }

    public function render_checkbox_field($args) {
        $option = (int) get_option($args['label_for'], 0);
        ?>
        <label for="<?php echo esc_attr($args['label_for']); ?>">
            <input type="checkbox"
                   id="<?php echo esc_attr($args['label_for']); ?>"
                   name="<?php echo esc_attr($args['label_for']); ?>"
                   value="1"
                   <?php checked(1, $option); ?>>
            <?php echo esc_html($args['label'] ?? ''); ?>
        </label>
        <?php
    }

    public function render_purchase_link_mode_field() {
        $value = get_option('oneclick_purchase_link_mode', 'all_with_cart_fallback');
        ?>
        <fieldset>
            <label>
                <input type="radio"
                       name="oneclick_purchase_link_mode"
                       value="all_with_cart_fallback"
                       <?php checked($value, 'all_with_cart_fallback'); ?>>
                <?php esc_html_e('Send to all purchases. Card purchases use one-click payment; non-card purchases continue to checkout.', 'woo-oneclick'); ?>
            </label>
            <br>
            <label>
                <input type="radio"
                       name="oneclick_purchase_link_mode"
                       value="card_only"
                       <?php checked($value, 'card_only'); ?>>
                <?php esc_html_e('Card purchases only. Non-card purchases are skipped.', 'woo-oneclick'); ?>
            </label>
        </fieldset>
        <?php
    }
}
