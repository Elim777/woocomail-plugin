<?php
/**
 * Actions Admin Page
 *
 * Admin UI for managing Actions (purchase triggers, abandoned cart, periodic).
 * Communicates with backend via OneClick_API_Client.
 *
 * @package WooOneClick
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class OneClick_Actions_Admin {

    /** @var string Menu slug */
    const MENU_SLUG = 'oneclick-actions';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_submenu']);
        add_action('admin_init', [$this, 'handle_form_actions']);
    }

    /**
     * Add submenu under One-Click
     */
    public function add_submenu() {
        add_submenu_page(
            'oneclick-settings',
            __('Triggers', 'woo-oneclick'),
            __('Triggers', 'woo-oneclick'),
            'manage_woocommerce',
            self::MENU_SLUG,
            [$this, 'render_page']
        );
    }

    /**
     * Handle create/update/delete form submissions
     */
    public function handle_form_actions() {
        if (!isset($_POST['oneclick_action_nonce'])) {
            return;
        }
        if (!wp_verify_nonce($_POST['oneclick_action_nonce'], 'oneclick_action_save')) {
            wp_die(__('Security check failed.', 'woo-oneclick'));
        }
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $api = OneClick_API_Client::instance();
        $action_submit = sanitize_text_field($_POST['oneclick_action_submit'] ?? '');

        // DELETE ALL
        if ($action_submit === 'delete_all') {
            $result = OneClick_API_Client::instance()->delete("/api/actions?site_url=" . urlencode(site_url()));
            if (!is_wp_error($result)) {
                $count = $result['count'] ?? 0;
                set_transient('oneclick_action_deleted_count', $count, 30);
                wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&deleted_all=1'));
                exit;
            }
            set_transient('oneclick_action_error', $result->get_error_message(), 30);
            wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&error=1'));
            exit;
        }

        // DELETE
        if ($action_submit === 'delete') {
            $action_id = absint($_POST['action_id'] ?? 0);
            if ($action_id) {
                $result = OneClick_API_Client::instance()->delete("/api/actions/$action_id");
                if (!is_wp_error($result)) {
                    wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&deleted=1'));
                    exit;
                }
                set_transient('oneclick_action_error', $result->get_error_message(), 30);
                wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&error=1'));
                exit;
            }
        }

        // CREATE or UPDATE
        $data = [
            'site_url'              => site_url(),
            'name'                  => sanitize_text_field($_POST['action_name'] ?? ''),
            'type'                  => sanitize_text_field($_POST['action_type'] ?? 'purchase'),
            'trigger_products'      => $this->parse_int_list($_POST['trigger_products'] ?? ''),
            'trigger_categories'    => $this->parse_int_list($_POST['trigger_categories'] ?? ''),
            'periodic_interval_days' => absint($_POST['periodic_interval_days'] ?? 0) ?: null,
            'status'                => sanitize_text_field($_POST['action_status'] ?? 'active'),
        ];

        $action_id = absint($_POST['action_id'] ?? 0);

        if ($action_id > 0) {
            // UPDATE
            unset($data['site_url']);
            $result = OneClick_API_Client::instance()->put("/api/actions/$action_id", $data);
        } else {
            // CREATE
            $result = $api->post('/api/actions', $data);
        }

        if (is_wp_error($result)) {
            set_transient('oneclick_action_error', $result->get_error_message(), 30);
            wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&error=1'));
            exit;
        }

        wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&saved=1'));
        exit;
    }

    /**
     * Render the main page (list or form)
     */
    public function render_page() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        ob_start();
        $view = sanitize_key($_GET['view'] ?? 'list');
        $action_id = absint($_GET['id'] ?? 0);

        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">' . __('Triggers', 'woo-oneclick') . '</h1>';

        // Show notices
        $this->show_notices();

        if ($view === 'edit' || $view === 'new') {
            $this->render_form($action_id);
        } else {
            $this->render_list();
        }

        echo '</div>';
        OneClick_Admin_UI::render(self::MENU_SLUG, ob_get_clean());
    }

    /**
     * Render actions list
     */
    private function render_list() {
        $api = OneClick_API_Client::instance();
        $actions = $api->get('/api/actions', ['site_url' => site_url()]);

        if (is_wp_error($actions)) {
            echo '<div class="notice notice-error"><p>' . esc_html($actions->get_error_message()) . '</p></div>';
            $actions = [];
        }

        $new_url = admin_url('admin.php?page=' . self::MENU_SLUG . '&view=new');
        echo '<a href="' . esc_url($new_url) . '" class="page-title-action">' . __('Add New', 'woo-oneclick') . '</a>';

        if (!empty($actions)) {
            // Delete All
            echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)) . '" style="display:inline;">';
            wp_nonce_field('oneclick_action_save', 'oneclick_action_nonce');
            echo '<input type="hidden" name="oneclick_action_submit" value="delete_all">';
            echo '<button type="submit" class="page-title-action" style="color:#b32d2e;" onclick="return confirm(\'' . esc_js(__('Are you sure you want to delete ALL triggers? This cannot be undone.', 'woo-oneclick')) . '\')">';
            echo __('Delete All', 'woo-oneclick');
            echo '</button>';
            echo '</form>';
        }

        echo '<hr class="wp-header-end">';

        // Fetch rules to count scenario usage per trigger
        $rules = $api->get('/api/rules', ['site_url' => site_url()]);
        if (is_wp_error($rules)) $rules = [];
        $action_scenario_count = [];
        foreach ($rules as $rule) {
            $aid = $rule['action_id'] ?? 0;
            $action_scenario_count[$aid] = ($action_scenario_count[$aid] ?? 0) + 1;
        }

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . __('ID', 'woo-oneclick') . '</th>';
        echo '<th>' . __('Name', 'woo-oneclick') . '</th>';
        echo '<th>' . __('Type', 'woo-oneclick') . '</th>';
        echo '<th>' . __('Trigger Products', 'woo-oneclick') . '</th>';
        echo '<th>' . __('Scenarios', 'woo-oneclick') . '</th>';
        echo '<th>' . __('Created', 'woo-oneclick') . '</th>';
        echo '<th>' . __('Actions', 'woo-oneclick') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        if (empty($actions)) {
            echo '<tr><td colspan="7">' . __('No triggers found. Create your first trigger.', 'woo-oneclick') . '</td></tr>';
        }

        foreach ($actions as $action) {
            $edit_url = admin_url('admin.php?page=' . self::MENU_SLUG . '&view=edit&id=' . $action['id']);
            $products = $action['trigger_products'] ?? [];
            $product_count = is_array($products) ? count($products) : 0;
            $created = isset($action['created_at']) ? date_i18n(get_option('date_format'), strtotime($action['created_at'])) : '';
            $scenario_count = $action_scenario_count[$action['id']] ?? 0;
            $scenario_style = $scenario_count > 0 ? 'color:green' : 'color:#999';

            echo '<tr>';
            echo '<td>' . esc_html($action['id']) . '</td>';
            echo '<td><a href="' . esc_url($edit_url) . '"><strong>' . esc_html($action['name']) . '</strong></a></td>';
            echo '<td>' . esc_html($action['type'] ?? '') . '</td>';
            echo '<td>' . esc_html($product_count) . ' ' . __('products', 'woo-oneclick') . '</td>';
            if ($scenario_count > 0) {
                $scenarios_url = admin_url('admin.php?page=oneclick-rules&highlight_action_id=' . $action['id']);
                echo '<td style="' . $scenario_style . '"><a href="' . esc_url($scenarios_url) . '" style="text-decoration:none;color:inherit;">' . sprintf(_n('%d scenario', '%d scenarios', $scenario_count, 'woo-oneclick'), $scenario_count) . '</a></td>';
            } else {
                echo '<td style="' . $scenario_style . '">' . sprintf(_n('%d scenario', '%d scenarios', $scenario_count, 'woo-oneclick'), $scenario_count) . '</td>';
            }
            echo '<td>' . esc_html($created) . '</td>';
            echo '<td><a href="' . esc_url($edit_url) . '" class="button button-small">' . __('Edit', 'woo-oneclick') . '</a></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Render create/edit form
     */
    private function render_form($action_id = 0) {
        $action = [];
        if ($action_id > 0) {
            $api = OneClick_API_Client::instance();
            $action = $api->get("/api/actions/$action_id");
            if (is_wp_error($action)) {
                echo '<div class="notice notice-error"><p>' . esc_html($action->get_error_message()) . '</p></div>';
                return;
            }
        }

        $name = $action['name'] ?? '';
        $type = $action['type'] ?? 'purchase';
        $trigger_products = $action['trigger_products'] ?? [];
        $trigger_categories = $action['trigger_categories'] ?? [];
        $periodic_interval_days = $action['periodic_interval_days'] ?? '';
        $status = $action['status'] ?? 'active';

        $back_url = admin_url('admin.php?page=' . self::MENU_SLUG);
        echo '<a href="' . esc_url($back_url) . '">&larr; ' . __('Back to Triggers', 'woo-oneclick') . '</a>';
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)); ?>">
            <?php wp_nonce_field('oneclick_action_save', 'oneclick_action_nonce'); ?>
            <input type="hidden" name="action_id" value="<?php echo esc_attr($action_id); ?>">
            <input type="hidden" name="oneclick_action_submit" value="save">

            <table class="form-table">
                <tr>
                    <th><label for="action_name"><?php _e('Name', 'woo-oneclick'); ?></label></th>
                    <td><input type="text" id="action_name" name="action_name" value="<?php echo esc_attr($name); ?>" class="regular-text" required></td>
                </tr>
                <tr>
                    <th><label for="action_type"><?php _e('Type', 'woo-oneclick'); ?></label></th>
                    <td>
                        <select id="action_type" name="action_type">
                            <option value="purchase" <?php selected($type, 'purchase'); ?>><?php _e('Purchase', 'woo-oneclick'); ?></option>
                            <option value="abandoned_cart" <?php selected($type, 'abandoned_cart'); ?>><?php _e('Abandoned Cart', 'woo-oneclick'); ?></option>
                            <option value="periodic" <?php selected($type, 'periodic'); ?>><?php _e('Periodic', 'woo-oneclick'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="trigger_products"><?php _e('Trigger Products', 'woo-oneclick'); ?></label></th>
                    <td>
                        <select id="trigger_products" name="trigger_products[]" class="oneclick-product-select" multiple="multiple" style="width:400px;"
                                data-placeholder="<?php esc_attr_e('Search products...', 'woo-oneclick'); ?>">
                            <?php foreach ($trigger_products as $pid): ?>
                                <?php $p = wc_get_product($pid); if ($p): ?>
                                    <option value="<?php echo esc_attr($pid); ?>" selected><?php echo esc_html($p->get_name()); ?> (#<?php echo esc_html($pid); ?>)</option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php _e('Products that activate this trigger. Leave empty for all products.', 'woo-oneclick'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="trigger_categories"><?php _e('Trigger Categories', 'woo-oneclick'); ?></label></th>
                    <td>
                        <select id="trigger_categories" name="trigger_categories[]" class="oneclick-category-select" multiple="multiple" style="width:400px;"
                                data-placeholder="<?php esc_attr_e('Search categories...', 'woo-oneclick'); ?>">
                            <?php foreach ($trigger_categories as $cid):
                                $term = get_term($cid, 'product_cat');
                                if ($term && !is_wp_error($term)): ?>
                                    <option value="<?php echo esc_attr($cid); ?>" selected><?php echo esc_html($term->name); ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php _e('Categories that activate this trigger. Leave empty for all categories.', 'woo-oneclick'); ?></p>
                    </td>
                </tr>
                <tr class="periodic-field" style="<?php echo $type !== 'periodic' ? 'display:none' : ''; ?>">
                    <th><label for="periodic_interval_days"><?php _e('Interval (days)', 'woo-oneclick'); ?></label></th>
                    <td><input type="number" id="periodic_interval_days" name="periodic_interval_days" value="<?php echo esc_attr($periodic_interval_days); ?>" min="1" class="small-text"></td>
                </tr>
            </table>

            <?php submit_button($action_id ? __('Update Trigger', 'woo-oneclick') : __('Create Trigger', 'woo-oneclick')); ?>
        </form>

        <?php if ($action_id > 0): ?>
            <hr>
            <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)); ?>"
                  onsubmit="return confirm('<?php esc_attr_e('Are you sure you want to delete this trigger?', 'woo-oneclick'); ?>');">
                <?php wp_nonce_field('oneclick_action_save', 'oneclick_action_nonce'); ?>
                <input type="hidden" name="action_id" value="<?php echo esc_attr($action_id); ?>">
                <input type="hidden" name="oneclick_action_submit" value="delete">
                <?php submit_button(__('Delete Trigger', 'woo-oneclick'), 'delete', 'submit', false); ?>
            </form>
        <?php endif;
    }

    /**
     * Show admin notices
     */
    private function show_notices() {
        if (isset($_GET['saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . __('Trigger saved.', 'woo-oneclick') . '</p></div>';
        }
        if (isset($_GET['deleted'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . __('Trigger deleted.', 'woo-oneclick') . '</p></div>';
        }
        if (isset($_GET['deleted_all'])) {
            $count = get_transient('oneclick_action_deleted_count');
            delete_transient('oneclick_action_deleted_count');
            echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(__('All triggers deleted (%d).', 'woo-oneclick'), (int)$count) . '</p></div>';
        }
        if (isset($_GET['error'])) {
            $error = get_transient('oneclick_action_error');
            if ($error) {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($error) . '</p></div>';
                delete_transient('oneclick_action_error');
            }
        }
    }

    /**
     * Parse comma-separated or array input into int list
     */
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
