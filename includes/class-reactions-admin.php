<?php
/**
 * Reactions Admin Page
 *
 * Admin UI for managing Reactions (email offers, discounts).
 * Communicates with backend via OneClick_API_Client.
 *
 * @package WooOneClick
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class OneClick_Reactions_Admin {

    const MENU_SLUG = 'oneclick-reactions';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_submenu']);
        add_action('admin_init', [$this, 'handle_form_actions']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_ai_email_assets']);
        add_action('wp_ajax_oneclick_ai_generate_email', [$this, 'ajax_generate_email']);
    }

    public function add_submenu() {
        add_submenu_page(
            'oneclick-settings',
            __('Actions', 'woo-oneclick'),
            __('Actions', 'woo-oneclick'),
            'manage_woocommerce',
            self::MENU_SLUG,
            [$this, 'render_page']
        );
    }

    public function handle_form_actions() {
        if (!isset($_POST['oneclick_reaction_nonce'])) {
            return;
        }
        if (!wp_verify_nonce($_POST['oneclick_reaction_nonce'], 'oneclick_reaction_save')) {
            wp_die(__('Security check failed.', 'woo-oneclick'));
        }
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $submit = sanitize_text_field($_POST['oneclick_reaction_submit'] ?? '');
        $reaction_id = absint($_POST['reaction_id'] ?? 0);

        // DELETE ALL
        if ($submit === 'delete_all') {
            $result = OneClick_API_Client::instance()->delete("/api/reactions?site_url=" . urlencode(site_url()));
            if (!is_wp_error($result)) {
                $count = $result['count'] ?? 0;
                set_transient('oneclick_reaction_deleted_count', $count, 30);
                wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&deleted_all=1'));
                exit;
            }
            set_transient('oneclick_reaction_error', $result->get_error_message(), 30);
            wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&error=1'));
            exit;
        }



        // DELETE
        if ($submit === 'delete' && $reaction_id) {
            $result = OneClick_API_Client::instance()->delete("/api/reactions/$reaction_id");
            if (!is_wp_error($result)) {
                wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&deleted=1'));
                exit;
            }
            set_transient('oneclick_reaction_error', $result->get_error_message(), 30);
            wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&error=1'));
            exit;
        }

        // CREATE or UPDATE
        $data = [
            'site_url'         => site_url(),
            'name'             => sanitize_text_field($_POST['reaction_name'] ?? ''),
            'type'             => sanitize_text_field($_POST['reaction_type'] ?? 'send_email'),
            'offer_products'   => $this->parse_int_list($_POST['offer_products'] ?? ''),
            'offer_categories' => $this->parse_int_list($_POST['offer_categories'] ?? ''),
            'discount_percent' => floatval($_POST['discount_percent'] ?? 0),
            'discount_type'    => sanitize_text_field($_POST['discount_type'] ?? '%'),
            'delay_minutes'    => absint($_POST['delay_minutes'] ?? 0),
            'email_subject'    => sanitize_text_field($_POST['email_subject'] ?? ''),
            'email_body'       => wp_kses_post($_POST['email_body'] ?? ''),
            'status'           => sanitize_text_field($_POST['reaction_status'] ?? 'active'),
        ];

        if ($reaction_id > 0) {
            unset($data['site_url']);
            $result = OneClick_API_Client::instance()->put("/api/reactions/$reaction_id", $data);
        } else {
            $api = OneClick_API_Client::instance();
            $result = $api->post('/api/reactions', $data);
        }

        if (is_wp_error($result)) {
            set_transient('oneclick_reaction_error', $result->get_error_message(), 30);
            wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&error=1'));
            exit;
        }

        wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&saved=1'));
        exit;
    }

    public function render_page() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        ob_start();
        $view = sanitize_key($_GET['view'] ?? 'list');
        $reaction_id = absint($_GET['id'] ?? 0);

        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">' . __('Actions', 'woo-oneclick') . '</h1>';
        $this->show_notices();

        if ($view === 'edit' || $view === 'new') {
            $this->render_form($reaction_id);
        } else {
            $this->render_list();
        }

        echo '</div>';
        OneClick_Admin_UI::render(self::MENU_SLUG, ob_get_clean());
    }

    private function render_list() {
        $api = OneClick_API_Client::instance();
        $reactions = $api->get('/api/reactions', ['site_url' => site_url()]);

        if (is_wp_error($reactions)) {
            echo '<div class="notice notice-error"><p>' . esc_html($reactions->get_error_message()) . '</p></div>';
            $reactions = [];
        }

        $new_url = admin_url('admin.php?page=' . self::MENU_SLUG . '&view=new');
        echo '<a href="' . esc_url($new_url) . '" class="page-title-action">' . __('Add New', 'woo-oneclick') . '</a>';

        if (!empty($reactions)) {
            // Delete All
            echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)) . '" style="display:inline;">';
            wp_nonce_field('oneclick_reaction_save', 'oneclick_reaction_nonce');
            echo '<input type="hidden" name="oneclick_reaction_submit" value="delete_all">';
            echo '<button type="submit" class="page-title-action" style="color:#b32d2e;" onclick="return confirm(\'' . esc_js(__('Are you sure you want to delete ALL actions? This cannot be undone.', 'woo-oneclick')) . '\')">';
            echo __('Delete All', 'woo-oneclick');
            echo '</button>';
            echo '</form>';
        }

        echo '<hr class="wp-header-end">';

        // Fetch rules to count scenario usage per reaction
        $api = OneClick_API_Client::instance();
        $rules = $api->get('/api/rules', ['site_url' => site_url()]);
        if (is_wp_error($rules)) $rules = [];
        $reaction_scenario_count = [];
        foreach ($rules as $rule) {
            $rid = $rule['reaction_id'] ?? 0;
            $reaction_scenario_count[$rid] = ($reaction_scenario_count[$rid] ?? 0) + 1;
        }

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . __('ID', 'woo-oneclick') . '</th>';
        echo '<th>' . __('Name', 'woo-oneclick') . '</th>';
        echo '<th>' . __('Type', 'woo-oneclick') . '</th>';
        echo '<th>' . __('Discount', 'woo-oneclick') . '</th>';
        echo '<th>' . __('Delay', 'woo-oneclick') . '</th>';
        echo '<th>' . __('Scenarios', 'woo-oneclick') . '</th>';
        echo '<th>' . __('Actions', 'woo-oneclick') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        if (empty($reactions)) {
            echo '<tr><td colspan="7">' . __('No actions found.', 'woo-oneclick') . '</td></tr>';
        }

        foreach ($reactions as $r) {
            $edit_url = admin_url('admin.php?page=' . self::MENU_SLUG . '&view=edit&id=' . $r['id']);
            $discount = ($r['discount_percent'] ?? 0) > 0 ? $r['discount_percent'] . '%' : '-';
            $delay = ($r['delay_minutes'] ?? 0) > 0 ? $r['delay_minutes'] . ' min' : __('Immediate', 'woo-oneclick');
            $scenario_count = $reaction_scenario_count[$r['id']] ?? 0;
            $scenario_style = $scenario_count > 0 ? 'color:green' : 'color:#999';

            echo '<tr>';
            echo '<td>' . esc_html($r['id']) . '</td>';
            echo '<td><a href="' . esc_url($edit_url) . '"><strong>' . esc_html($r['name']) . '</strong></a></td>';
            echo '<td>' . esc_html($r['type'] ?? 'send_email') . '</td>';
            echo '<td>' . esc_html($discount) . '</td>';
            echo '<td>' . esc_html($delay) . '</td>';
            if ($scenario_count > 0) {
                $scenarios_url = admin_url('admin.php?page=oneclick-rules&highlight_reaction_id=' . $r['id']);
                echo '<td style="' . $scenario_style . '"><a href="' . esc_url($scenarios_url) . '" style="text-decoration:none;color:inherit;">' . sprintf(_n('%d scenario', '%d scenarios', $scenario_count, 'woo-oneclick'), $scenario_count) . '</a></td>';
            } else {
                echo '<td style="' . $scenario_style . '">' . sprintf(_n('%d scenario', '%d scenarios', $scenario_count, 'woo-oneclick'), $scenario_count) . '</td>';
            }
            echo '<td><a href="' . esc_url($edit_url) . '" class="button button-small">' . __('Edit', 'woo-oneclick') . '</a></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private function render_form($reaction_id = 0) {
        $reaction = [];
        if ($reaction_id > 0) {
            $api = OneClick_API_Client::instance();
            $reaction = $api->get("/api/reactions/$reaction_id");
            if (is_wp_error($reaction)) {
                echo '<div class="notice notice-error"><p>' . esc_html($reaction->get_error_message()) . '</p></div>';
                return;
            }
        }

        $name = $reaction['name'] ?? '';
        $type = $reaction['type'] ?? 'send_email';
        $offer_products = $reaction['offer_products'] ?? [];
        $offer_categories = $reaction['offer_categories'] ?? [];
        $discount_percent = $reaction['discount_percent'] ?? 0;
        $discount_type = $reaction['discount_type'] ?? '%';
        $delay_minutes = $reaction['delay_minutes'] ?? 0;
        $email_subject = $reaction['email_subject'] ?? '';
        $email_body = $reaction['email_body'] ?? '';
        $status = $reaction['status'] ?? 'active';

        $back_url = admin_url('admin.php?page=' . self::MENU_SLUG);
        echo '<a href="' . esc_url($back_url) . '">&larr; ' . __('Back to Actions', 'woo-oneclick') . '</a>';
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)); ?>">
            <?php wp_nonce_field('oneclick_reaction_save', 'oneclick_reaction_nonce'); ?>
            <input type="hidden" name="reaction_id" value="<?php echo esc_attr($reaction_id); ?>">
            <input type="hidden" name="oneclick_reaction_submit" value="save">

            <table class="form-table">
                <tr>
                    <th><label for="reaction_name"><?php _e('Name', 'woo-oneclick'); ?></label></th>
                    <td><input type="text" id="reaction_name" name="reaction_name" value="<?php echo esc_attr($name); ?>" class="regular-text" required></td>
                </tr>
                <tr>
                    <th><label for="reaction_type"><?php _e('Type', 'woo-oneclick'); ?></label></th>
                    <td>
                        <input type="hidden" id="reaction_type" name="reaction_type" value="send_email">
                        <span><?php _e('Send Email', 'woo-oneclick'); ?></span>
                    </td>
                </tr>
                <tr>
                    <th><label for="offer_products"><?php _e('Offer Products', 'woo-oneclick'); ?></label></th>
                    <td>
                        <select id="offer_products" name="offer_products[]" class="oneclick-product-select" multiple="multiple" style="width:400px;"
                                data-placeholder="<?php esc_attr_e('Search products...', 'woo-oneclick'); ?>">
                            <?php foreach ($offer_products as $pid): ?>
                                <?php $p = wc_get_product($pid); if ($p): ?>
                                    <option value="<?php echo esc_attr($pid); ?>" selected><?php echo esc_html($p->get_name()); ?> (#<?php echo esc_html($pid); ?>)</option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="offer_categories"><?php _e('Offer Categories', 'woo-oneclick'); ?></label></th>
                    <td>
                        <select id="offer_categories" name="offer_categories[]" class="oneclick-category-select" multiple="multiple" style="width:400px;"
                                data-placeholder="<?php esc_attr_e('Search categories...', 'woo-oneclick'); ?>">
                            <?php foreach ($offer_categories as $cid):
                                $term = get_term($cid, 'product_cat');
                                if ($term && !is_wp_error($term)): ?>
                                    <option value="<?php echo esc_attr($cid); ?>" selected><?php echo esc_html($term->name); ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="discount_percent"><?php _e('Discount', 'woo-oneclick'); ?></label></th>
                    <td>
                        <input type="number" id="discount_percent" name="discount_percent" value="<?php echo esc_attr($discount_percent); ?>" min="0" max="100" step="0.01" class="small-text">
                        <select name="discount_type" style="vertical-align:baseline;">
                            <option value="%" <?php selected($discount_type, '%'); ?>>%</option>
                            <option value="fixed" <?php selected($discount_type, 'fixed'); ?>><?php _e('Fixed', 'woo-oneclick'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="delay_minutes"><?php _e('Delay (minutes)', 'woo-oneclick'); ?></label></th>
                    <td>
                        <input type="number" id="delay_minutes" name="delay_minutes" value="<?php echo esc_attr($delay_minutes); ?>" min="0" class="small-text">
                        <p class="description"><?php _e('0 = immediate, 30 = 30 min after trigger, 1440 = 24 hours', 'woo-oneclick'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php _e('AI Generate', 'woo-oneclick'); ?></th>
                    <td>
                        <?php $ai_disclosure_acknowledged = class_exists('OneClick_AI_Setup') ? OneClick_AI_Setup::is_disclosure_acknowledged() : false; ?>
                        <textarea id="oneclick-ai-instruction" rows="2" class="large-text" placeholder="<?php esc_attr_e('Optional: instructions for AI (e.g. "write in Slovak", "make it funny", "focus on pet care")...', 'woo-oneclick'); ?>"></textarea>
                        <p style="margin-top:8px;">
                            <button type="button" id="oneclick-ai-generate-btn" class="button button-secondary" <?php disabled(!$ai_disclosure_acknowledged); ?>>
                                <span class="dashicons dashicons-admin-generic" style="vertical-align:middle;margin-top:-2px;"></span>
                                <?php _e('AI Generate Email', 'woo-oneclick'); ?>
                            </button>
                            <span id="oneclick-ai-spinner" class="spinner" style="float:none;margin-top:0;"></span>
                        </p>
                        <?php if (!$ai_disclosure_acknowledged): ?>
                            <p class="description">
                                <?php
                                printf(
                                    wp_kses_post(__('AI email generation is disabled until the AI privacy disclosure is acknowledged in <a href="%s">AI Setup</a>.', 'woo-oneclick')),
                                    esc_url(admin_url('admin.php?page=oneclick-ai-setup'))
                                );
                                ?>
                            </p>
                        <?php else: ?>
                            <p class="description"><?php _e('Generates email subject and body based on the action context (products, categories, discount). Fill those fields first.', 'woo-oneclick'); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><label for="email_subject"><?php _e('Email Subject', 'woo-oneclick'); ?></label></th>
                    <td><input type="text" id="email_subject" name="email_subject" value="<?php echo esc_attr($email_subject); ?>" class="large-text"></td>
                </tr>
                <tr>
                    <th><label for="email_body"><?php _e('Email Body', 'woo-oneclick'); ?></label></th>
                    <td>
                        <?php
                        wp_editor($email_body, 'email_body', [
                            'textarea_name' => 'email_body',
                            'textarea_rows' => 10,
                            'media_buttons' => false,
                        ]);
                        ?>
                        <p class="description"><?php _e('Available placeholders: {customer_name}, {product_name}, {price}, {discount}, {purchase_url}', 'woo-oneclick'); ?></p>
                    </td>
                </tr>
            </table>

            <?php submit_button($reaction_id ? __('Update Action', 'woo-oneclick') : __('Create Action', 'woo-oneclick')); ?>
        </form>

        <?php if ($reaction_id > 0): ?>
            <hr>
            <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)); ?>"
                  onsubmit="return confirm('<?php esc_attr_e('Are you sure you want to delete this action?', 'woo-oneclick'); ?>');">
                <?php wp_nonce_field('oneclick_reaction_save', 'oneclick_reaction_nonce'); ?>
                <input type="hidden" name="reaction_id" value="<?php echo esc_attr($reaction_id); ?>">
                <input type="hidden" name="oneclick_reaction_submit" value="delete">
                <?php submit_button(__('Delete Action', 'woo-oneclick'), 'delete', 'submit', false); ?>
            </form>
        <?php endif;
    }

    public function enqueue_ai_email_assets($hook) {
        // Only load on reaction edit/new page
        if (strpos($hook, 'page_' . self::MENU_SLUG) === false) {
            return;
        }
        $view = sanitize_key($_GET['view'] ?? '');
        if ($view !== 'edit' && $view !== 'new') {
            return;
        }

        wp_enqueue_script(
            'oneclick-ai-email-generate',
            plugin_dir_url(dirname(__FILE__)) . 'assets/js/ai-email-generate.js',
            ['jquery', 'oneclick-admin-ui'],
            '1.1.0',
            true
        );

        wp_localize_script('oneclick-ai-email-generate', 'oneclickAiEmail', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('oneclick_ai_generate_email'),
            'disclosureAcknowledged' => class_exists('OneClick_AI_Setup') ? OneClick_AI_Setup::is_disclosure_acknowledged() : false,
            'i18n'    => [
                'generating' => __('Generating...', 'woo-oneclick'),
                'generate'   => __('AI Generate Email', 'woo-oneclick'),
                'error'      => __('AI generation failed. Please try again.', 'woo-oneclick'),
                'noProducts' => __('Please select at least one offer product or category first.', 'woo-oneclick'),
                'disclosureRequired' => class_exists('OneClick_AI_Setup') ? OneClick_AI_Setup::disclosure_required_message() : __('AI features require acknowledgement of the AI privacy disclosure before use.', 'woo-oneclick'),
            ],
        ]);
    }

    public function ajax_generate_email() {
        check_ajax_referer('oneclick_ai_generate_email', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }
        if (!class_exists('OneClick_AI_Setup') || !OneClick_AI_Setup::is_disclosure_acknowledged()) {
            $message = class_exists('OneClick_AI_Setup')
                ? OneClick_AI_Setup::disclosure_required_message()
                : __('AI features require acknowledgement of the AI privacy disclosure before use.', 'woo-oneclick');
            wp_send_json_error(['message' => $message], 403);
        }

        $reaction_name    = sanitize_text_field($_POST['reaction_name'] ?? '');
        $product_ids      = array_map('absint', (array)($_POST['product_ids'] ?? []));
        $category_ids     = array_map('absint', (array)($_POST['category_ids'] ?? []));
        $discount_percent = floatval($_POST['discount_percent'] ?? 0);
        $discount_type    = sanitize_text_field($_POST['discount_type'] ?? '%');
        $user_instruction = sanitize_textarea_field($_POST['user_instruction'] ?? '');

        // Resolve product IDs → names + prices
        $product_names  = [];
        $product_prices = [];
        foreach ($product_ids as $pid) {
            $product = wc_get_product($pid);
            if ($product) {
                $product_names[]  = $product->get_name();
                $product_prices[] = (float) $product->get_price();
            }
        }

        // Resolve category IDs → names
        $category_names = [];
        foreach ($category_ids as $cid) {
            $term = get_term($cid, 'product_cat');
            if ($term && !is_wp_error($term)) {
                $category_names[] = $term->name;
            }
        }

        // Get shop name and locale
        $shop_name = get_bloginfo('name');
        $locale    = get_locale();
        $currency  = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'EUR';

        // Call backend
        $api = OneClick_API_Client::instance();
        $result = $api->post('/api/ai/generate-email', [
            'site_url'         => site_url(),
            'shop_name'        => $shop_name,
            'locale'           => $locale,
            'currency'         => $currency,
            'reaction_name'    => $reaction_name,
            'product_names'    => $product_names,
            'product_prices'   => $product_prices,
            'category_names'   => $category_names,
            'discount_percent' => $discount_percent > 0 ? $discount_percent : null,
            'discount_type'    => $discount_percent > 0 ? $discount_type : null,
            'user_instruction' => !empty($user_instruction) ? $user_instruction : null,
        ]);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'email_subject' => $result['email_subject'] ?? '',
            'email_body'    => $result['email_body'] ?? '',
            'model_used'    => $result['model_used'] ?? '',
            'tokens_used'   => $result['tokens_used'] ?? 0,
        ]);
    }

    private function show_notices() {
        if (isset($_GET['saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . __('Action saved.', 'woo-oneclick') . '</p></div>';
        }
        if (isset($_GET['deleted'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . __('Action deleted.', 'woo-oneclick') . '</p></div>';
        }
        if (isset($_GET['deleted_all'])) {
            $count = get_transient('oneclick_reaction_deleted_count');
            delete_transient('oneclick_reaction_deleted_count');
            echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(__('All actions deleted (%d).', 'woo-oneclick'), (int)$count) . '</p></div>';
        }
        if (isset($_GET['error'])) {
            $error = get_transient('oneclick_reaction_error');
            if ($error) {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($error) . '</p></div>';
                delete_transient('oneclick_reaction_error');
            }
        }
    }

    private function parse_int_list($input) {
        if (is_array($input)) {
            return array_map('absint', array_filter($input));
        }
        if (is_string($input) && !empty($input)) {
            return array_map('absint', array_filter(explode(',', $input)));
        }
        return [];
    }

}
