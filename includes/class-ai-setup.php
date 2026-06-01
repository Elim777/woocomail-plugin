<?php
/**
 * AI Setup
 *
 * Admin UI for AI-generated actions/reactions/rules suggestions.
 * PRO feature — fetches products + categories from WooCommerce,
 * sends to backend AI endpoint, displays suggestions for review.
 *
 * @package WooOneClick
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class OneClick_AI_Setup {

    const DISCLOSURE_OPTION = 'oneclick_ai_disclosure_acknowledged';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_init', [$this, 'handle_disclosure_acknowledgement']);
        add_action('wp_ajax_oneclick_ai_generate', [$this, 'ajax_generate']);
        add_action('wp_ajax_oneclick_ai_apply', [$this, 'ajax_apply']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public static function is_disclosure_acknowledged() {
        return get_option(self::DISCLOSURE_OPTION, '') === 'yes';
    }

    public static function disclosure_required_message() {
        return __('AI features require acknowledgement of the AI privacy disclosure in OneClick > AI Setup before use.', 'woo-oneclick');
    }

    public function handle_disclosure_acknowledgement() {
        if (!isset($_POST['oneclick_ai_disclosure_submit'])) {
            return;
        }
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to enable AI features.', 'woo-oneclick'));
        }
        check_admin_referer('oneclick_ai_disclosure_ack', 'oneclick_ai_disclosure_nonce');

        $confirmed = isset($_POST['oneclick_ai_disclosure_confirm']) && $_POST['oneclick_ai_disclosure_confirm'] === '1';
        if (!$confirmed) {
            set_transient('oneclick_ai_disclosure_error', __('Please confirm the AI privacy disclosure before enabling AI features.', 'woo-oneclick'), 30);
            wp_safe_redirect(admin_url('admin.php?page=oneclick-ai-setup'));
            exit;
        }

        update_option(self::DISCLOSURE_OPTION, 'yes', false);
        set_transient('oneclick_ai_disclosure_enabled', __('AI features are enabled for this site.', 'woo-oneclick'), 30);
        wp_safe_redirect(admin_url('admin.php?page=oneclick-ai-setup'));
        exit;
    }

    /**
     * Add admin menu page
     */
    public function add_menu_page() {
        add_submenu_page(
            'oneclick-settings',
            __('AI Setup', 'woo-oneclick'),
            __('AI Setup', 'woo-oneclick'),
            'manage_woocommerce',
            'oneclick-ai-setup',
            [$this, 'render_page']
        );
    }

    /**
     * Enqueue assets only on AI Setup page
     */
    public function enqueue_assets($hook) {
        if ($hook !== 'one-click_page_oneclick-ai-setup') {
            return;
        }

        wp_enqueue_style(
            'oneclick-ai-setup',
            ONECLICK_PLUGIN_URL . 'assets/css/ai-setup.css',
            [],
            ONECLICK_VERSION
        );

        wp_enqueue_script(
            'oneclick-ai-setup',
            ONECLICK_PLUGIN_URL . 'assets/js/ai-setup.js',
            ['jquery'],
            ONECLICK_VERSION,
            true
        );

        wp_localize_script('oneclick-ai-setup', 'oneclickAI', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('oneclick_ai_nonce'),
            'disclosureAcknowledged' => self::is_disclosure_acknowledged(),
            'i18n'    => [
                'generating'    => __('Generating suggestions...', 'woo-oneclick'),
                'applying'      => __('Applying suggestions...', 'woo-oneclick'),
                'error'         => __('An error occurred. Please try again.', 'woo-oneclick'),
                'noSuggestions' => __('No suggestions were generated. Try adding more products to your store.', 'woo-oneclick'),
                'applied'       => __('Suggestions applied successfully! Check Triggers, Actions, and Scenarios pages.', 'woo-oneclick'),
                'confirmApply'  => __('Apply selected suggestions? They will be created as inactive.', 'woo-oneclick'),
                'disclosureRequired' => self::disclosure_required_message(),
            ],
        ]);
    }

    /**
     * Render AI Setup page
     */
    public function render_page() {
        $tier = get_option('oneclick_license_tier', 'free');
        $is_pro = ($tier === 'pro');
        $disclosure_acknowledged = self::is_disclosure_acknowledged();
        $disclosure_metadata = $this->get_disclosure_metadata();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('AI Setup', 'woo-oneclick'); ?></h1>

            <?php if ($message = get_transient('oneclick_ai_disclosure_enabled')): delete_transient('oneclick_ai_disclosure_enabled'); ?>
                <div class="notice notice-success"><p><?php echo esc_html($message); ?></p></div>
            <?php endif; ?>
            <?php if ($message = get_transient('oneclick_ai_disclosure_error')): delete_transient('oneclick_ai_disclosure_error'); ?>
                <div class="notice notice-error"><p><?php echo esc_html($message); ?></p></div>
            <?php endif; ?>

            <?php if (!$is_pro): ?>
                <div class="notice notice-warning">
                    <p>
                        <?php esc_html_e('AI Setup is a PRO feature. Upgrade to PRO to use AI-generated campaign suggestions.', 'woo-oneclick'); ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=oneclick-settings&tab=license')); ?>" class="button button-primary" style="margin-left: 10px;">
                            <?php esc_html_e('Upgrade to PRO', 'woo-oneclick'); ?>
                        </a>
                    </p>
                </div>
            <?php endif; ?>

            <div class="oneclick-ai-container">
                <?php $this->render_disclosure_box($disclosure_metadata, $disclosure_acknowledged); ?>

                <!-- Generate Section -->
                <div class="oneclick-ai-generate-section" <?php echo (!$is_pro || !$disclosure_acknowledged) ? 'style="opacity: 0.5; pointer-events: none;"' : ''; ?>>
                    <h2><?php esc_html_e('Generate Campaign Suggestions', 'woo-oneclick'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('AI will analyze your products and categories to suggest optimal triggers, actions, and scenarios for your email campaigns.', 'woo-oneclick'); ?>
                    </p>

                    <button type="button" id="oneclick-ai-generate-btn" class="button button-primary button-hero">
                        <?php esc_html_e('Generate Suggestions', 'woo-oneclick'); ?>
                    </button>

                    <div id="oneclick-ai-loading" class="oneclick-ai-loading" style="display: none;">
                        <span class="spinner is-active"></span>
                        <span class="oneclick-ai-loading-text"><?php esc_html_e('Analyzing your products...', 'woo-oneclick'); ?></span>
                    </div>
                </div>

                <!-- Results Section -->
                <div id="oneclick-ai-results" class="oneclick-ai-results" style="display: none;">
                    <!-- Actions -->
                    <div class="oneclick-ai-section">
                        <h3><?php esc_html_e('Suggested Triggers', 'woo-oneclick'); ?></h3>
                        <table class="widefat striped" id="oneclick-ai-actions-table">
                            <thead>
                                <tr>
                                    <th class="check-column"><input type="checkbox" class="oneclick-ai-select-all" data-target="actions" checked></th>
                                    <th><?php esc_html_e('Name', 'woo-oneclick'); ?></th>
                                    <th><?php esc_html_e('Type', 'woo-oneclick'); ?></th>
                                    <th><?php esc_html_e('Trigger Products', 'woo-oneclick'); ?></th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>

                    <!-- Reactions -->
                    <div class="oneclick-ai-section">
                        <h3><?php esc_html_e('Suggested Actions', 'woo-oneclick'); ?></h3>
                        <table class="widefat striped" id="oneclick-ai-reactions-table">
                            <thead>
                                <tr>
                                    <th class="check-column"><input type="checkbox" class="oneclick-ai-select-all" data-target="reactions" checked></th>
                                    <th><?php esc_html_e('Name', 'woo-oneclick'); ?></th>
                                    <th><?php esc_html_e('Discount', 'woo-oneclick'); ?></th>
                                    <th><?php esc_html_e('Delay', 'woo-oneclick'); ?></th>
                                    <th><?php esc_html_e('Email Subject', 'woo-oneclick'); ?></th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>

                    <!-- Rules -->
                    <div class="oneclick-ai-section">
                        <h3><?php esc_html_e('Suggested Scenarios', 'woo-oneclick'); ?></h3>
                        <table class="widefat striped" id="oneclick-ai-rules-table">
                            <thead>
                                <tr>
                                    <th class="check-column"><input type="checkbox" class="oneclick-ai-select-all" data-target="rules" checked></th>
                                    <th><?php esc_html_e('Trigger', 'woo-oneclick'); ?></th>
                                    <th><?php esc_html_e('Action', 'woo-oneclick'); ?></th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>

                    <!-- Apply Button -->
                    <div class="oneclick-ai-apply-section">
                        <button type="button" id="oneclick-ai-apply-btn" class="button button-primary button-hero">
                            <?php esc_html_e('Apply Selected Suggestions', 'woo-oneclick'); ?>
                        </button>
                        <p class="description">
                            <?php esc_html_e('Applied suggestions will be created as inactive. You can review and activate them in Triggers, Actions, and Scenarios pages.', 'woo-oneclick'); ?>
                        </p>
                    </div>
                </div>

                <!-- Status Messages -->
                <div id="oneclick-ai-status" class="oneclick-ai-status" style="display: none;"></div>
            </div>
        </div>
        <?php
    }

    private function get_disclosure_metadata() {
        $fallback = [
            'provider' => __('configured AI provider', 'woo-oneclick'),
            'model' => '',
        ];

        if (empty(get_option('oneclick_license_key', ''))) {
            return $fallback;
        }

        $api = OneClick_API_Client::instance();
        $result = $api->get('/api/ai/disclosure');
        if (is_wp_error($result)) {
            return $fallback;
        }

        return array_merge($fallback, is_array($result) ? $result : []);
    }

    private function render_disclosure_box($metadata, $acknowledged) {
        $provider = !empty($metadata['provider']) ? $metadata['provider'] : __('configured AI provider', 'woo-oneclick');
        $model = !empty($metadata['model']) ? $metadata['model'] : '';
        ?>
        <div class="oneclick-ai-disclosure <?php echo $acknowledged ? 'is-acknowledged' : 'needs-acknowledgement'; ?>">
            <h2><?php esc_html_e('AI Privacy Disclosure', 'woo-oneclick'); ?></h2>
            <p>
                <?php
                printf(
                    esc_html__('AI features send selected store data through the OneClick backend to the configured AI provider (%1$s%2$s).', 'woo-oneclick'),
                    esc_html($provider),
                    $model ? esc_html(' / ' . $model) : ''
                );
                ?>
            </p>
            <div class="oneclick-ai-disclosure-grid">
                <div>
                    <h3><?php esc_html_e('Data that may be sent', 'woo-oneclick'); ?></h3>
                    <ul>
                        <li><?php esc_html_e('Product names, prices, categories and short descriptions', 'woo-oneclick'); ?></li>
                        <li><?php esc_html_e('Shop name, locale and currency', 'woo-oneclick'); ?></li>
                        <li><?php esc_html_e('Admin instruction text entered for AI email generation', 'woo-oneclick'); ?></li>
                    </ul>
                </div>
                <div>
                    <h3><?php esc_html_e('Data not sent in AI requests', 'woo-oneclick'); ?></h3>
                    <ul>
                        <li><?php esc_html_e('Customer emails or customer payment data', 'woo-oneclick'); ?></li>
                        <li><?php esc_html_e('License keys, Stripe secrets or backend API secrets', 'woo-oneclick'); ?></li>
                        <li><?php esc_html_e('Session access tokens or raw purchase payloads', 'woo-oneclick'); ?></li>
                    </ul>
                </div>
            </div>
            <p class="description">
                <?php esc_html_e('OneClick logs AI usage metadata such as provider, model, product count, token count and success/failure. Full prompts, catalog payloads, admin instructions and AI response bodies should not be logged.', 'woo-oneclick'); ?>
            </p>

            <?php if ($acknowledged): ?>
                <p class="oneclick-ai-disclosure-status"><?php esc_html_e('AI disclosure acknowledged. AI features are enabled for this site.', 'woo-oneclick'); ?></p>
            <?php else: ?>
                <form method="post" action="">
                    <?php wp_nonce_field('oneclick_ai_disclosure_ack', 'oneclick_ai_disclosure_nonce'); ?>
                    <label>
                        <input type="checkbox" name="oneclick_ai_disclosure_confirm" value="1" required>
                        <?php esc_html_e('I understand and want to enable AI features for this site.', 'woo-oneclick'); ?>
                    </label>
                    <p>
                        <button type="submit" name="oneclick_ai_disclosure_submit" value="1" class="button button-primary">
                            <?php esc_html_e('I understand and enable AI features', 'woo-oneclick'); ?>
                        </button>
                    </p>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * AJAX: Generate AI suggestions
     *
     * Fetches products + categories from WooCommerce, sends to backend.
     */
    public function ajax_generate() {
        check_ajax_referer('oneclick_ai_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'woo-oneclick')]);
        }

        // Check PRO tier
        $tier = get_option('oneclick_license_tier', 'free');
        if ($tier !== 'pro') {
            wp_send_json_error(['message' => __('AI Setup requires PRO license.', 'woo-oneclick')]);
        }
        if (!self::is_disclosure_acknowledged()) {
            wp_send_json_error(['message' => self::disclosure_required_message()], 403);
        }

        // Fetch products from WooCommerce
        $products = $this->get_products_for_ai();
        $categories = $this->get_categories_for_ai();

        if (empty($products)) {
            wp_send_json_error(['message' => __('No products found in your store.', 'woo-oneclick')]);
        }

        // Send to backend (AI generation can take 60-90s, use longer timeout)
        $api = OneClick_API_Client::instance();
        $result = $api->post('/api/ai/setup', [
            'site_url'   => site_url(),
            'shop_name'  => get_bloginfo('name') ?: 'My Shop',
            'locale'     => substr(get_locale(), 0, 2),
            'currency'   => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'EUR',
            'products'   => $products,
            'categories' => $categories,
        ], ['timeout' => 120]);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'actions'   => $result['actions'] ?? [],
            'reactions' => $result['reactions'] ?? [],
            'rules'     => $result['rules'] ?? [],
        ]);
    }

    /**
     * AJAX: Apply AI suggestions
     *
     * Sends selected suggestions to backend for creation.
     */
    public function ajax_apply() {
        check_ajax_referer('oneclick_ai_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'woo-oneclick')]);
        }

        $suggestions = json_decode(stripslashes($_POST['suggestions'] ?? '{}'), true);

        if (empty($suggestions)) {
            wp_send_json_error(['message' => __('No suggestions to apply.', 'woo-oneclick')]);
        }
        if (!self::is_disclosure_acknowledged()) {
            wp_send_json_error(['message' => self::disclosure_required_message()], 403);
        }

        // Backend expects actions/reactions/rules as top-level fields, not nested under "suggestions"
        $api = OneClick_API_Client::instance();
        $result = $api->post('/api/ai/apply', [
            'site_url'  => site_url(),
            'actions'   => $suggestions['actions'] ?? [],
            'reactions' => $suggestions['reactions'] ?? [],
            'rules'     => $suggestions['rules'] ?? [],
        ]);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'created_actions'   => $result['created_actions'] ?? 0,
            'created_reactions' => $result['created_reactions'] ?? 0,
            'created_rules'     => $result['created_rules'] ?? 0,
        ]);
    }

    /**
     * Get products for AI analysis
     *
     * Returns minimal product data needed for AI suggestions.
     *
     * @return array
     */
    private function get_products_for_ai() {
        $products = [];

        $args = [
            'status'  => 'publish',
            'limit'   => 100,
            'orderby' => 'date',
            'order'   => 'DESC',
        ];

        $wc_products = wc_get_products($args);

        $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'EUR';

        foreach ($wc_products as $product) {
            $category_ids = $product->get_category_ids();
            $category_slugs = [];
            foreach ($category_ids as $cat_id) {
                $term = get_term($cat_id, 'product_cat');
                if ($term && !is_wp_error($term)) {
                    $category_slugs[] = $term->slug;
                }
            }

            $products[] = [
                'id'                => $product->get_id(),
                'name'              => $product->get_name(),
                'price'             => (float) $product->get_price(),
                'currency'          => $currency,
                'category_slugs'    => $category_slugs,
                'in_stock'          => $product->get_stock_status() === 'instock',
                'short_description' => wp_strip_all_tags($product->get_short_description()),
            ];
        }

        return $products;
    }

    /**
     * Get categories for AI analysis
     *
     * @return array
     */
    private function get_categories_for_ai() {
        $categories = [];

        $terms = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => true,
            'orderby'    => 'count',
            'order'      => 'DESC',
        ]);

        if (is_wp_error($terms)) {
            return $categories;
        }

        foreach ($terms as $term) {
            // Get product IDs in this category
            $product_ids = get_posts([
                'post_type'   => 'product',
                'post_status' => 'publish',
                'numberposts' => 100,
                'fields'      => 'ids',
                'tax_query'   => [[
                    'taxonomy' => 'product_cat',
                    'field'    => 'term_id',
                    'terms'    => $term->term_id,
                ]],
            ]);

            $categories[] = [
                'slug'        => $term->slug,
                'name'        => $term->name,
                'product_ids' => array_map('intval', $product_ids),
            ];
        }

        return $categories;
    }
}
