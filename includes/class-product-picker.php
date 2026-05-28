<?php
/**
 * Product/Category Picker
 *
 * Enqueues Select2 and configures AJAX product/category search
 * using WooCommerce built-in admin AJAX handlers.
 *
 * @package WooOneClick
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class OneClick_Product_Picker {

    /** @var array Admin pages where Select2 is needed */
    private $admin_pages = [
        'oneclick-actions',
        'oneclick-reactions',
        'oneclick-public-links',
    ];

    public function __construct() {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_ajax_oneclick_search_categories', [$this, 'ajax_search_categories']);
    }

    /**
     * Enqueue Select2 and admin scripts on relevant pages
     */
    public function enqueue_scripts($hook) {
        // Only on our admin pages
        $page = $_GET['page'] ?? '';
        if (!in_array($page, $this->admin_pages, true)) {
            return;
        }

        // WooCommerce admin scripts include Select2
        wp_enqueue_script('wc-enhanced-select');
        wp_enqueue_style('woocommerce_admin_styles');

        // Our custom admin JS
        wp_enqueue_script(
            'oneclick-admin',
            ONECLICK_PLUGIN_URL . 'assets/js/admin-actions.js',
            ['jquery', 'wc-enhanced-select'],
            ONECLICK_VERSION,
            true
        );

        // Our custom admin CSS
        wp_enqueue_style(
            'oneclick-admin',
            ONECLICK_PLUGIN_URL . 'assets/css/admin.css',
            ['woocommerce_admin_styles'],
            ONECLICK_VERSION
        );

        // Localize script with AJAX URL and nonces
        wp_localize_script('oneclick-admin', 'oneclick_admin', [
            'ajax_url'       => admin_url('admin-ajax.php'),
            'nonce'          => wp_create_nonce('search-products'),
            'category_nonce' => wp_create_nonce('oneclick-search-categories'),
        ]);
    }

    /**
     * AJAX handler for category search
     *
     * WooCommerce doesn't expose a public AJAX category search endpoint,
     * so we implement our own.
     */
    public function ajax_search_categories() {
        check_ajax_referer('oneclick-search-categories', 'security');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json([]);
        }

        $term = sanitize_text_field(wp_unslash($_GET['term'] ?? ''));
        if (empty($term)) {
            wp_send_json([]);
        }

        $categories = get_terms([
            'taxonomy'   => 'product_cat',
            'name__like' => $term,
            'hide_empty' => false,
            'number'     => 20,
        ]);

        if (is_wp_error($categories)) {
            wp_send_json([]);
        }

        $results = [];
        foreach ($categories as $cat) {
            // Build hierarchical name: Parent > Child (count)
            $display_name = $cat->name;
            if ($cat->parent) {
                $parent = get_term($cat->parent, 'product_cat');
                if ($parent && !is_wp_error($parent)) {
                    $display_name = $parent->name . ' > ' . $display_name;
                }
            }
            $results[$cat->term_id] = $display_name . ' (' . $cat->count . ')';
        }

        wp_send_json($results);
    }
}
