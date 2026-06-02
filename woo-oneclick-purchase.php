<?php
/**
 * Plugin Name: WooCommerce One-Click Purchase
 * Plugin URI: https://seventhdaylabs.com/woo-oneclick
 * Description: One-click email purchases with Stripe MIT + Post-Purchase Email Campaigns
 * Version: 1.1.0
 * Author: Seventh Day Labs
 * Author URI: https://seventhdaylabs.com
 * Text Domain: woo-oneclick
 * Domain Path: /languages
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * WC requires at least: 7.5
 * WC tested up to: 9.9
 * License: GPL v3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Plugin constants
define('ONECLICK_VERSION', '1.1.0');
define('ONECLICK_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ONECLICK_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ONECLICK_PLUGIN_FILE', __FILE__);

/**
 * CRITICAL: HPOS (High-Performance Order Storage) Compatibility Declaration
 *
 * WooCommerce 8.0+ uses HPOS (Custom Order Tables) by default.
 * We MUST declare compatibility to ensure the plugin works correctly.
 *
 * @link https://github.com/woocommerce/woocommerce/wiki/High-Performance-Order-Storage-Upgrade-Recipe-Book
 */
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
});

/**
 * Check plugin requirements
 */
function oneclick_check_requirements() {
    $errors = [];

    // Check PHP version
    if (version_compare(PHP_VERSION, '8.1', '<')) {
        $errors[] = sprintf(
            __('WooCommerce One-Click Purchase requires PHP 8.1 or higher. You are running PHP %s.', 'woo-oneclick'),
            PHP_VERSION
        );
    }

    // Check if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        $errors[] = __('WooCommerce One-Click Purchase requires WooCommerce to be installed and active.', 'woo-oneclick');
    }


    if (!empty($errors)) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            '<h1>' . __('Plugin Activation Failed', 'woo-oneclick') . '</h1>' .
            '<p>' . implode('</p><p>', $errors) . '</p>' .
            '<p><a href="' . admin_url('plugins.php') . '">' . __('Return to Plugins', 'woo-oneclick') . '</a></p>'
        );
    }
}
register_activation_hook(__FILE__, 'oneclick_check_requirements');

/**
 * Plugin activation hook
 *
 * All crypto is on the backend — no local keypair needed.
 */
function oneclick_activation() {
    // Check requirements first
    oneclick_check_requirements();


    // Set default options
    if (!get_option('oneclick_backend_url')) {
        update_option('oneclick_backend_url', 'https://woocomail-api.onrender.com', true);
    }

    if (!get_option('oneclick_purchase_link_mode')) {
        update_option('oneclick_purchase_link_mode', 'all_with_cart_fallback', true);
    }

    if (!get_option('oneclick_purchase_completion_mode')) {
        update_option('oneclick_purchase_completion_mode', 'purchase_session', true);
    }

    // Schedule daily license check
    if (!wp_next_scheduled('oneclick_daily_license_check')) {
        wp_schedule_event(time(), 'daily', 'oneclick_daily_license_check');
    }

    // Schedule abandoned cart detection (every 15 min)
    if (!wp_next_scheduled('oneclick_detect_abandoned')) {
        wp_schedule_event(time(), 'oneclick_15min', 'oneclick_detect_abandoned');
    }

    // Schedule periodic customer email check (daily)
    if (!wp_next_scheduled('oneclick_periodic_check')) {
        wp_schedule_event(time(), 'daily', 'oneclick_periodic_check');
    }

    // Fallback worker schedule when Action Scheduler is unavailable.
    if (!function_exists('as_next_scheduled_action') && !wp_next_scheduled('oneclick_process_due_purchase_sessions')) {
        wp_schedule_event(time() + 60, 'oneclick_minutely', 'oneclick_process_due_purchase_sessions');
    }

    // Register OneClick non-REST routes before flushing permalink rules.
    $public_links_file = ONECLICK_PLUGIN_DIR . 'includes/class-public-links.php';
    if (!class_exists('OneClick_Public_Links') && file_exists($public_links_file)) {
        require_once $public_links_file;
    }
    if (class_exists('OneClick_Public_Links')) {
        OneClick_Public_Links::register_rewrite_rules();
        update_option('oneclick_public_links_rewrite_version', OneClick_Public_Links::rewrite_version(), false);
    }

    // Flush rewrite rules for OneClick shop/email/public claim routes.
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'oneclick_activation');

/**
 * Plugin deactivation hook
 */
function oneclick_deactivation() {
    // Clear scheduled campaigns
    $timestamp = wp_next_scheduled('oneclick_send_campaign_email');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'oneclick_send_campaign_email');
    }

    // Clear daily license check
    $timestamp = wp_next_scheduled('oneclick_daily_license_check');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'oneclick_daily_license_check');
    }

    // Clear abandoned cart detection
    $timestamp = wp_next_scheduled('oneclick_detect_abandoned');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'oneclick_detect_abandoned');
    }

    // Clear periodic check
    $timestamp = wp_next_scheduled('oneclick_periodic_check');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'oneclick_periodic_check');
    }

    $timestamp = wp_next_scheduled('oneclick_process_due_purchase_sessions');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'oneclick_process_due_purchase_sessions');
    }
    if (function_exists('as_unschedule_all_actions')) {
        as_unschedule_all_actions('oneclick_process_due_purchase_sessions', [], 'oneclick');
    }

    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'oneclick_deactivation');

/**
 * Load Composer autoloader
 */
function oneclick_load_composer() {
    $autoload_file = ONECLICK_PLUGIN_DIR . 'vendor/autoload.php';

    if (file_exists($autoload_file)) {
        require_once $autoload_file;
        return true;
    } else {
        // Show admin notice if composer dependencies are missing
        add_action('admin_notices', function() {
            ?>
            <div class="notice notice-error">
                <p><?php _e('WooCommerce One-Click Purchase: Composer dependencies not installed. Run "composer install" in the plugin directory.', 'woo-oneclick'); ?></p>
            </div>
            <?php
        });
        return false;
    }
}

/**
 * Load plugin classes
 */
function oneclick_load_classes() {
    // Load composer autoloader first (for Firebase JWT, Predis)
    if (!oneclick_load_composer()) {
        return;
    }

    // Logger must load early so all plugin components can write to WooCommerce logs.
    require_once ONECLICK_PLUGIN_DIR . 'includes/class-logger.php';

    // API client (must be loaded before settings — settings uses it)
    require_once ONECLICK_PLUGIN_DIR . 'includes/class-api-client.php';

    // Core classes
    require_once ONECLICK_PLUGIN_DIR . 'includes/class-settings.php';
    require_once ONECLICK_PLUGIN_DIR . 'includes/class-jwt-handler.php';
    require_once ONECLICK_PLUGIN_DIR . 'includes/class-jwt-test.php';

    // Actions/Reactions/Rules admin UI (replaces old campaign system)
    require_once ONECLICK_PLUGIN_DIR . 'includes/class-actions-admin.php';
    require_once ONECLICK_PLUGIN_DIR . 'includes/class-reactions-admin.php';
    require_once ONECLICK_PLUGIN_DIR . 'includes/class-rules-admin.php';
    require_once ONECLICK_PLUGIN_DIR . 'includes/class-product-picker.php';
    require_once ONECLICK_PLUGIN_DIR . 'includes/class-public-links.php';
    require_once ONECLICK_PLUGIN_DIR . 'includes/class-observability-dashboard.php';

    // Email branding admin
    require_once ONECLICK_PLUGIN_DIR . 'includes/class-email-branding.php';

    // Cart tracking (abandoned cart detection)
    require_once ONECLICK_PLUGIN_DIR . 'includes/class-cart-tracker.php';

    // Periodic cron (daily customer email reminders)
    require_once ONECLICK_PLUGIN_DIR . 'includes/class-periodic-cron.php';

    // Campaign trigger (thin client — uses backend API)
    require_once ONECLICK_PLUGIN_DIR . 'includes/class-campaign-trigger.php';

    // AI Setup (PRO feature)
    require_once ONECLICK_PLUGIN_DIR . 'includes/class-ai-setup.php';

    // Compatibility test
    require_once ONECLICK_PLUGIN_DIR . 'includes/class-compatibility-test.php';

    // Purchase processing
    require_once ONECLICK_PLUGIN_DIR . 'includes/class-stripe.php';
    require_once ONECLICK_PLUGIN_DIR . 'includes/class-stripe-reconciliation.php';
    require_once ONECLICK_PLUGIN_DIR . 'includes/class-order-creator.php';
    require_once ONECLICK_PLUGIN_DIR . 'includes/class-purchase-session.php';
    require_once ONECLICK_PLUGIN_DIR . 'includes/class-purchase-handler.php';

    // Product page button (LOWEST PRIORITY)
    require_once ONECLICK_PLUGIN_DIR . 'includes/class-product-button.php';
}

/**
 * Initialize plugin
 */
function oneclick_init() {
    // Load text domain for translations
    load_plugin_textdomain('woo-oneclick', false, dirname(plugin_basename(__FILE__)) . '/languages');

    // Load classes
    oneclick_load_classes();

    // Initialize components
    new OneClick_Settings();
    new OneClick_JWT_Test();
    new OneClick_Actions_Admin();
    new OneClick_Reactions_Admin();
    new OneClick_Rules_Admin();
    new OneClick_Product_Picker();
    new OneClick_Public_Links();
    new OneClick_Observability_Dashboard();
    new OneClick_Email_Branding();
    new OneClick_Cart_Tracker();
    new OneClick_Periodic_Cron();
    new OneClick_Campaign_Trigger();
    new OneClick_Stripe_Reconciliation();
    new OneClick_AI_Setup();
    new OneClick_Compatibility_Test();
    new OneClick_Purchase_Session();
    new OneClick_Purchase_Handler();

    // Product button (only if user has payment method)
    if (is_user_logged_in()) {
        new OneClick_Product_Button();
    }
}
add_action('plugins_loaded', 'oneclick_init');

/**
 * Register custom cron schedule (every 15 minutes)
 */
function oneclick_cron_schedules($schedules) {
    $schedules['oneclick_15min'] = [
        'interval' => 900,
        'display'  => __('Every 15 Minutes', 'woo-oneclick'),
    ];
    $schedules['oneclick_minutely'] = [
        'interval' => 60,
        'display'  => __('Every Minute', 'woo-oneclick'),
    ];
    return $schedules;
}
add_filter('cron_schedules', 'oneclick_cron_schedules');

/**
 * Daily license check via WP-Cron
 *
 * Calls backend POST /api/license/check to refresh tier, quota, and status.
 */
function oneclick_cron_license_check() {
    if (!class_exists('OneClick_API_Client')) {
        return;
    }

    $settings = new OneClick_Settings();
    $settings->refresh_license_from_backend();
}
add_action('oneclick_daily_license_check', 'oneclick_cron_license_check');

/**
 * Add settings link on plugin page
 */
function oneclick_plugin_action_links($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=oneclick-settings') . '">' . __('Settings', 'woo-oneclick') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'oneclick_plugin_action_links');

/**
 * Admin notice if WooCommerce is not active
 */
function oneclick_woocommerce_missing_notice() {
    if (!class_exists('WooCommerce')) {
        ?>
        <div class="notice notice-error">
            <p><?php _e('WooCommerce One-Click Purchase requires WooCommerce to be installed and active.', 'woo-oneclick'); ?></p>
        </div>
        <?php
    }
}
add_action('admin_notices', 'oneclick_woocommerce_missing_notice');
