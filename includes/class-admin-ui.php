<?php
/**
 * React administration shell.
 *
 * This class is presentation-only. Existing page renderers, form handlers,
 * AJAX actions, capabilities, and backend API calls remain authoritative.
 *
 * @package WooOneClick
 */

if (!defined('ABSPATH')) {
    exit;
}

class OneClick_Admin_UI {

    private const PAGE_TITLES = [
        'oneclick-settings'     => 'Settings',
        'oneclick-dashboard'    => 'Dashboard',
        'oneclick-actions'      => 'Triggers',
        'oneclick-reactions'    => 'Actions',
        'oneclick-rules'        => 'Scenarios',
        'oneclick-public-links' => 'One-Click Links',
        'oneclick-branding'     => 'Branding',
        'oneclick-ai-setup'     => 'AI Setup',
        'oneclick-compat-test'  => 'Compatibility Test',
        'oneclick-jwt-test'     => 'Backend Token Diagnostics',
    ];

    public function __construct() {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets'], 1);
    }

    public function enqueue_assets() {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (!isset(self::PAGE_TITLES[$page])) {
            return;
        }

        wp_enqueue_style(
            'oneclick-admin-ui',
            ONECLICK_PLUGIN_URL . 'assets/build/admin-app.css',
            [],
            ONECLICK_VERSION
        );

        wp_enqueue_script(
            'oneclick-admin-ui',
            ONECLICK_PLUGIN_URL . 'assets/build/admin-app.js',
            ['wp-element', 'jquery'],
            ONECLICK_VERSION,
            true
        );

        wp_localize_script('oneclick-admin-ui', 'oneclickAdminUI', [
            'page'     => $page,
            'title'    => self::PAGE_TITLES[$page],
            'adminUrl' => admin_url('admin.php'),
            'ajaxUrl'  => admin_url('admin-ajax.php'),
            'nav'      => [
                ['slug' => 'oneclick-dashboard', 'label' => __('Dashboard', 'woo-oneclick'), 'icon' => 'chart'],
                ['slug' => 'oneclick-actions', 'label' => __('Triggers', 'woo-oneclick'), 'icon' => 'zap'],
                ['slug' => 'oneclick-reactions', 'label' => __('Actions', 'woo-oneclick'), 'icon' => 'send'],
                ['slug' => 'oneclick-rules', 'label' => __('Scenarios', 'woo-oneclick'), 'icon' => 'workflow'],
                ['slug' => 'oneclick-public-links', 'label' => __('Links', 'woo-oneclick'), 'icon' => 'link'],
                ['slug' => 'oneclick-branding', 'label' => __('Branding', 'woo-oneclick'), 'icon' => 'palette'],
                ['slug' => 'oneclick-ai-setup', 'label' => __('AI Setup', 'woo-oneclick'), 'icon' => 'sparkles'],
                ['slug' => 'oneclick-settings', 'label' => __('Settings', 'woo-oneclick'), 'icon' => 'settings'],
                ['slug' => 'oneclick-compat-test', 'label' => __('System', 'woo-oneclick'), 'icon' => 'activity'],
            ],
            'i18n'     => [
                'workspace' => __('Commerce orchestration workspace', 'woo-oneclick'),
                'loading'   => __('Loading interface...', 'woo-oneclick'),
                'copy'      => __('Copy', 'woo-oneclick'),
                'copied'    => __('Copied', 'woo-oneclick'),
                'runChecks' => __('Run Checks', 'woo-oneclick'),
                'running'   => __('Running...', 'woo-oneclick'),
                'sendTest'  => __('Send Test Email', 'woo-oneclick'),
                'sending'   => __('Sending...', 'woo-oneclick'),
            ],
        ]);
    }

    /**
     * Mount existing trusted admin markup inside the React presentation shell.
     */
    public static function render($page, $html) {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        echo '<div id="oneclick-admin-root" class="ocliby-admin" data-page="' . esc_attr($page) . '">';
        echo '<div class="ocliby-admin__boot">' . esc_html__('Loading interface...', 'woo-oneclick') . '</div>';
        echo '</div>';
        echo '<template id="oneclick-admin-content">';
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted output from existing page renderers.
        echo '</template>';
    }
}
