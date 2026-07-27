<?php
/**
 * Rules Admin Page
 *
 * Admin UI for managing Rules (action → reaction mappings).
 * Communicates with backend via OneClick_API_Client.
 *
 * @package WooOneClick
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class OneClick_Rules_Admin {

    const MENU_SLUG = 'oneclick-rules';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_submenu']);
        add_action('admin_init', [$this, 'handle_form_actions']);
    }

    public function add_submenu() {
        add_submenu_page(
            'oneclick-settings',
            __('Scenarios', 'woo-oneclick'),
            __('Scenarios', 'woo-oneclick'),
            'manage_woocommerce',
            self::MENU_SLUG,
            [$this, 'render_page']
        );
    }

    public function handle_form_actions() {
        if (!isset($_POST['oneclick_rule_nonce'])) {
            return;
        }
        if (!wp_verify_nonce($_POST['oneclick_rule_nonce'], 'oneclick_rule_save')) {
            wp_die(__('Security check failed.', 'woo-oneclick'));
        }
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $submit = sanitize_text_field($_POST['oneclick_rule_submit'] ?? '');
        $rule_id = absint($_POST['rule_id'] ?? 0);

        // DELETE ALL
        if ($submit === 'delete_all') {
            $result = OneClick_API_Client::instance()->delete("/api/rules?site_url=" . urlencode(site_url()));
            if (!is_wp_error($result)) {
                $count = $result['count'] ?? 0;
                set_transient('oneclick_rule_deleted_count', $count, 30);
                wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&deleted_all=1'));
                exit;
            }
            set_transient('oneclick_rule_error', $result->get_error_message(), 30);
            wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&error=1'));
            exit;
        }

        // ACTIVATE ALL
        if ($submit === 'activate_all') {
            $result = OneClick_API_Client::instance()->patch("/api/rules/status?site_url=" . urlencode(site_url()) . "&status=active");
            if (!is_wp_error($result)) {
                $count = $result['count'] ?? 0;
                set_transient('oneclick_rule_status_count', $count, 30);
                wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&activated_all=1'));
                exit;
            }
            set_transient('oneclick_rule_error', $result->get_error_message(), 30);
            wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&error=1'));
            exit;
        }

        // DEACTIVATE ALL
        if ($submit === 'deactivate_all') {
            $result = OneClick_API_Client::instance()->patch("/api/rules/status?site_url=" . urlencode(site_url()) . "&status=inactive");
            if (!is_wp_error($result)) {
                $count = $result['count'] ?? 0;
                set_transient('oneclick_rule_status_count', $count, 30);
                wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&deactivated_all=1'));
                exit;
            }
            set_transient('oneclick_rule_error', $result->get_error_message(), 30);
            wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&error=1'));
            exit;
        }

        // QUICK TOGGLE (single item)
        if ($submit === 'toggle_status') {
            $rule_id = absint($_POST['rule_id'] ?? 0);
            $new_status = sanitize_text_field($_POST['new_status'] ?? 'active');
            if ($rule_id) {
                $result = OneClick_API_Client::instance()->put("/api/rules/$rule_id", ['status' => $new_status]);
                if (!is_wp_error($result)) {
                    wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&toggled=1'));
                    exit;
                }
                set_transient('oneclick_rule_error', $result->get_error_message(), 30);
                wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&error=1'));
                exit;
            }
        }

        // DELETE
        if ($submit === 'delete' && $rule_id) {
            $result = OneClick_API_Client::instance()->delete("/api/rules/$rule_id");
            if (!is_wp_error($result)) {
                wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&deleted=1'));
                exit;
            }
            set_transient('oneclick_rule_error', $result->get_error_message(), 30);
            wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&error=1'));
            exit;
        }

        // CREATE or UPDATE
        $api = OneClick_API_Client::instance();

        if ($rule_id > 0) {
            $data = [
                'action_id'   => absint($_POST['action_id'] ?? 0),
                'reaction_id' => absint($_POST['reaction_id'] ?? 0),
                'status'      => sanitize_text_field($_POST['rule_status'] ?? 'active'),
            ];
            $result = OneClick_API_Client::instance()->put("/api/rules/$rule_id", $data);
        } else {
            $data = [
                'site_url'    => site_url(),
                'action_id'   => absint($_POST['action_id'] ?? 0),
                'reaction_id' => absint($_POST['reaction_id'] ?? 0),
            ];
            $result = $api->post('/api/rules', $data);
        }

        if (is_wp_error($result)) {
            set_transient('oneclick_rule_error', $result->get_error_message(), 30);
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
        $rule_id = absint($_GET['id'] ?? 0);

        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">' . __('Scenarios', 'woo-oneclick') . '</h1>';
        $this->show_notices();

        if ($view === 'edit' || $view === 'new') {
            $this->render_form($rule_id);
        } else {
            $this->render_list();
        }

        echo '</div>';
        OneClick_Admin_UI::render(self::MENU_SLUG, ob_get_clean());
    }

    private function render_list() {
        $api = OneClick_API_Client::instance();
        $rules = $api->get('/api/rules', ['site_url' => site_url()]);

        if (is_wp_error($rules)) {
            echo '<div class="notice notice-error"><p>' . esc_html($rules->get_error_message()) . '</p></div>';
            $rules = [];
        }

        $new_url = admin_url('admin.php?page=' . self::MENU_SLUG . '&view=new');
        echo '<a href="' . esc_url($new_url) . '" class="page-title-action">' . __('Add New', 'woo-oneclick') . '</a>';

        if (!empty($rules)) {
            // Activate All
            echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)) . '" style="display:inline;">';
            wp_nonce_field('oneclick_rule_save', 'oneclick_rule_nonce');
            echo '<input type="hidden" name="oneclick_rule_submit" value="activate_all">';
            echo '<button type="submit" class="page-title-action" style="color:#00a32a;">' . __('Activate All', 'woo-oneclick') . '</button>';
            echo '</form>';

            // Deactivate All
            echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)) . '" style="display:inline;">';
            wp_nonce_field('oneclick_rule_save', 'oneclick_rule_nonce');
            echo '<input type="hidden" name="oneclick_rule_submit" value="deactivate_all">';
            echo '<button type="submit" class="page-title-action" style="color:#d63638;">' . __('Deactivate All', 'woo-oneclick') . '</button>';
            echo '</form>';

            // Delete All
            echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)) . '" style="display:inline;">';
            wp_nonce_field('oneclick_rule_save', 'oneclick_rule_nonce');
            echo '<input type="hidden" name="oneclick_rule_submit" value="delete_all">';
            echo '<button type="submit" class="page-title-action" style="color:#b32d2e;" onclick="return confirm(\'' . esc_js(__('Are you sure you want to delete ALL scenarios? This cannot be undone.', 'woo-oneclick')) . '\')">';
            echo __('Delete All', 'woo-oneclick');
            echo '</button>';
            echo '</form>';
        }

        echo '<hr class="wp-header-end">';

        // Check for highlight params from Triggers/Actions pages
        $highlight_action_id = absint($_GET['highlight_action_id'] ?? 0);
        $highlight_reaction_id = absint($_GET['highlight_reaction_id'] ?? 0);

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . __('ID', 'woo-oneclick') . '</th>';
        echo '<th>' . __('Trigger', 'woo-oneclick') . '</th>';
        echo '<th>' . __('Action', 'woo-oneclick') . '</th>';
        echo '<th>' . __('Status', 'woo-oneclick') . '</th>';
        echo '<th>' . __('Created', 'woo-oneclick') . '</th>';
        echo '<th>' . __('Quick Actions', 'woo-oneclick') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        if (empty($rules)) {
            echo '<tr><td colspan="6">' . __('No scenarios found. Create triggers and actions first, then link them with scenarios.', 'woo-oneclick') . '</td></tr>';
        }

        foreach ($rules as $rule) {
            $edit_url = admin_url('admin.php?page=' . self::MENU_SLUG . '&view=edit&id=' . $rule['id']);
            $action_name = $rule['action']['name'] ?? __('(unknown)', 'woo-oneclick');
            $reaction_name = $rule['reaction']['name'] ?? __('(unknown)', 'woo-oneclick');
            $trigger_edit_url = admin_url('admin.php?page=oneclick-actions&view=edit&id=' . ($rule['action_id'] ?? 0));
            $action_edit_url = admin_url('admin.php?page=oneclick-reactions&view=edit&id=' . ($rule['reaction_id'] ?? 0));
            $is_active = ($rule['status'] ?? '') === 'active';
            $status_class = $is_active ? 'color:green' : 'color:#999';
            $created = isset($rule['created_at']) ? date_i18n(get_option('date_format'), strtotime($rule['created_at'])) : '';
            $toggle_status = $is_active ? 'inactive' : 'active';
            $toggle_label = $is_active ? __('Deactivate', 'woo-oneclick') : __('Activate', 'woo-oneclick');
            $toggle_color = $is_active ? '#d63638' : '#00a32a';

            // Highlight row if it matches the trigger/action filter
            $row_highlight = '';
            if ($highlight_action_id && ($rule['action_id'] ?? 0) == $highlight_action_id) {
                $row_highlight = ' style="background:#fff8e1;"';
            } elseif ($highlight_reaction_id && ($rule['reaction_id'] ?? 0) == $highlight_reaction_id) {
                $row_highlight = ' style="background:#fff8e1;"';
            }

            echo '<tr' . $row_highlight . '>';
            echo '<td>' . esc_html($rule['id']) . '</td>';
            echo '<td><a href="' . esc_url($trigger_edit_url) . '"><strong>' . esc_html($action_name) . '</strong></a></td>';
            echo '<td><a href="' . esc_url($action_edit_url) . '"><strong>' . esc_html($reaction_name) . '</strong></a></td>';
            echo '<td style="' . $status_class . '">' . esc_html(strtoupper($rule['status'] ?? '')) . '</td>';
            echo '<td>' . esc_html($created) . '</td>';
            echo '<td>';
            echo '<a href="' . esc_url($edit_url) . '" class="button button-small">' . __('Edit', 'woo-oneclick') . '</a> ';
            echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)) . '" style="display:inline;">';
            wp_nonce_field('oneclick_rule_save', 'oneclick_rule_nonce');
            echo '<input type="hidden" name="oneclick_rule_submit" value="toggle_status">';
            echo '<input type="hidden" name="rule_id" value="' . esc_attr($rule['id']) . '">';
            echo '<input type="hidden" name="new_status" value="' . esc_attr($toggle_status) . '">';
            echo '<button type="submit" class="button button-small" style="color:' . $toggle_color . ';">' . esc_html($toggle_label) . '</button>';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private function render_form($rule_id = 0) {
        $api = OneClick_API_Client::instance();
        $rule = [];

        if ($rule_id > 0) {
            $rule = $api->get("/api/rules/$rule_id");
            if (is_wp_error($rule)) {
                echo '<div class="notice notice-error"><p>' . esc_html($rule->get_error_message()) . '</p></div>';
                return;
            }
        }

        // Fetch actions and reactions for dropdowns
        $actions = $api->get('/api/actions', ['site_url' => site_url()]);
        $reactions = $api->get('/api/reactions', ['site_url' => site_url()]);

        if (is_wp_error($actions)) $actions = [];
        if (is_wp_error($reactions)) $reactions = [];

        $selected_action = $rule['action_id'] ?? 0;
        $selected_reaction = $rule['reaction_id'] ?? 0;
        $status = $rule['status'] ?? 'active';

        $back_url = admin_url('admin.php?page=' . self::MENU_SLUG);
        echo '<a href="' . esc_url($back_url) . '">&larr; ' . __('Back to Scenarios', 'woo-oneclick') . '</a>';

        if (empty($actions) || empty($reactions)) {
            echo '<div class="notice notice-warning"><p>';
            _e('You need at least one Trigger and one Action before creating a Scenario.', 'woo-oneclick');
            echo '</p></div>';
        }
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)); ?>">
            <?php wp_nonce_field('oneclick_rule_save', 'oneclick_rule_nonce'); ?>
            <input type="hidden" name="rule_id" value="<?php echo esc_attr($rule_id); ?>">
            <input type="hidden" name="oneclick_rule_submit" value="save">

            <table class="form-table">
                <tr>
                    <th><label for="action_id"><?php _e('Trigger (When)', 'woo-oneclick'); ?></label></th>
                    <td>
                        <select id="action_id" name="action_id" required>
                            <option value=""><?php _e('Select a trigger...', 'woo-oneclick'); ?></option>
                            <?php foreach ($actions as $a): ?>
                                <option value="<?php echo esc_attr($a['id']); ?>" <?php selected($selected_action, $a['id']); ?>>
                                    <?php echo esc_html($a['name'] . ' (' . $a['type'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php _e('When this trigger fires...', 'woo-oneclick'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="reaction_id"><?php _e('Action (Then)', 'woo-oneclick'); ?></label></th>
                    <td>
                        <select id="reaction_id" name="reaction_id" required>
                            <option value=""><?php _e('Select an action...', 'woo-oneclick'); ?></option>
                            <?php foreach ($reactions as $r): ?>
                                <option value="<?php echo esc_attr($r['id']); ?>" <?php selected($selected_reaction, $r['id']); ?>>
                                    <?php echo esc_html($r['name'] . ' (' . ($r['discount_percent'] ?? 0) . '% off, ' . ($r['delay_minutes'] ?? 0) . 'min delay)'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php _e('...then execute this action.', 'woo-oneclick'); ?></p>
                    </td>
                </tr>
                <?php if ($rule_id > 0): ?>
                <tr>
                    <th><label for="rule_status"><?php _e('Status', 'woo-oneclick'); ?></label></th>
                    <td>
                        <select id="rule_status" name="rule_status">
                            <option value="active" <?php selected($status, 'active'); ?>><?php _e('Active', 'woo-oneclick'); ?></option>
                            <option value="inactive" <?php selected($status, 'inactive'); ?>><?php _e('Inactive', 'woo-oneclick'); ?></option>
                        </select>
                    </td>
                </tr>
                <?php endif; ?>
            </table>

            <?php submit_button($rule_id ? __('Update Scenario', 'woo-oneclick') : __('Create Scenario', 'woo-oneclick')); ?>
        </form>

        <?php if ($rule_id > 0): ?>
            <hr>
            <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)); ?>"
                  onsubmit="return confirm('<?php esc_attr_e('Are you sure?', 'woo-oneclick'); ?>');">
                <?php wp_nonce_field('oneclick_rule_save', 'oneclick_rule_nonce'); ?>
                <input type="hidden" name="rule_id" value="<?php echo esc_attr($rule_id); ?>">
                <input type="hidden" name="oneclick_rule_submit" value="delete">
                <?php submit_button(__('Delete Scenario', 'woo-oneclick'), 'delete', 'submit', false); ?>
            </form>
        <?php endif;
    }

    private function show_notices() {
        if (isset($_GET['saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . __('Scenario saved.', 'woo-oneclick') . '</p></div>';
        }
        if (isset($_GET['deleted'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . __('Scenario deleted.', 'woo-oneclick') . '</p></div>';
        }
        if (isset($_GET['deleted_all'])) {
            $count = get_transient('oneclick_rule_deleted_count');
            delete_transient('oneclick_rule_deleted_count');
            echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(__('All scenarios deleted (%d).', 'woo-oneclick'), (int)$count) . '</p></div>';
        }
        if (isset($_GET['activated_all'])) {
            $count = get_transient('oneclick_rule_status_count');
            delete_transient('oneclick_rule_status_count');
            echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(__('All scenarios activated (%d).', 'woo-oneclick'), (int)$count) . '</p></div>';
        }
        if (isset($_GET['deactivated_all'])) {
            $count = get_transient('oneclick_rule_status_count');
            delete_transient('oneclick_rule_status_count');
            echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(__('All scenarios deactivated (%d).', 'woo-oneclick'), (int)$count) . '</p></div>';
        }
        if (isset($_GET['toggled'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . __('Scenario status updated.', 'woo-oneclick') . '</p></div>';
        }
        if (isset($_GET['error'])) {
            $error = get_transient('oneclick_rule_error');
            if ($error) {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($error) . '</p></div>';
                delete_transient('oneclick_rule_error');
            }
        }
    }
}
