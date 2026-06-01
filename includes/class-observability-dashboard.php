<?php
/**
 * OneClick Observability Dashboard
 *
 * Read-only admin view backed by licensed server-side API calls.
 *
 * @package WooOneClick
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class OneClick_Observability_Dashboard {

    const MENU_SLUG = 'oneclick-dashboard';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_submenu']);
    }

    public function add_submenu() {
        add_submenu_page(
            'oneclick-settings',
            __('Dashboard', 'woo-oneclick'),
            __('Dashboard', 'woo-oneclick'),
            'manage_woocommerce',
            self::MENU_SLUG,
            [$this, 'render_page']
        );
    }

    public function render_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to access this page.', 'woo-oneclick'));
        }

        $hours = isset($_GET['hours']) ? absint($_GET['hours']) : 24;
        if (!in_array($hours, [24, 168, 720], true)) {
            $hours = 24;
        }

        $api = OneClick_API_Client::instance();
        $overview = $api->get('/api/observability/overview', ['hours' => $hours]);
        $sessions = $api->get('/api/observability/sessions', ['limit' => 40]);

        echo '<div class="wrap oneclick-observability">';
        echo '<h1>' . esc_html__('OneClick Dashboard', 'woo-oneclick') . '</h1>';
        echo '<p class="description">' . esc_html__('Read-only operational view of purchase sessions, checkout handoff, email engagement and public endpoint guardrails.', 'woo-oneclick') . '</p>';

        $this->render_window_tabs($hours);

        if (is_wp_error($overview)) {
            echo '<div class="notice notice-error"><p>' . esc_html($overview->get_error_message()) . '</p></div>';
            echo '</div>';
            return;
        }
        if (is_wp_error($sessions)) {
            echo '<div class="notice notice-error"><p>' . esc_html($sessions->get_error_message()) . '</p></div>';
            $sessions = ['sessions' => []];
        }

        $this->render_styles();
        $this->render_cards($overview);
        $this->render_funnel($overview);
        $this->render_email($overview);
        $this->render_health($overview);
        $this->render_recent_sessions($sessions['sessions'] ?? []);

        echo '</div>';
    }

    private function render_window_tabs($hours) {
        $windows = [
            24  => __('24 hours', 'woo-oneclick'),
            168 => __('7 days', 'woo-oneclick'),
            720 => __('30 days', 'woo-oneclick'),
        ];

        echo '<div class="oneclick-window-tabs">';
        foreach ($windows as $value => $label) {
            $url = add_query_arg(['page' => self::MENU_SLUG, 'hours' => $value], admin_url('admin.php'));
            $class = $hours === $value ? 'button button-primary' : 'button';
            echo '<a class="' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a> ';
        }
        echo '</div>';
    }

    private function render_cards($overview) {
        $status = $this->get($overview, ['sessions', 'by_status'], []);
        $sessions = $this->get($overview, ['sessions'], []);
        $checkout = $this->get($overview, ['checkout_funnel'], []);
        $claims = $this->get($overview, ['claims'], []);

        echo '<div class="oneclick-grid oneclick-grid-4">';
        $this->card(__('Active Sessions', 'woo-oneclick'), $status['active'] ?? 0, __('Currently open windows', 'woo-oneclick'));
        $this->card(__('Due / Retry', 'woo-oneclick'), $sessions['due'] ?? 0, __('Ready for worker claim', 'woo-oneclick'));
        $this->card(__('Checkout Completed', 'woo-oneclick'), $checkout['completed'] ?? 0, __('Checkout sessions with Woo order', 'woo-oneclick'));
        $this->card(__('Claims Redeemed', 'woo-oneclick'), ($claims['email_redeemed'] ?? 0) + ($claims['public_redeemed'] ?? 0), __('Email + public claims', 'woo-oneclick'));
        echo '</div>';
    }

    private function render_funnel($overview) {
        $checkout = $this->get($overview, ['checkout_funnel'], []);
        echo '<h2>' . esc_html__('Checkout Funnel', 'woo-oneclick') . '</h2>';
        echo '<div class="oneclick-grid oneclick-grid-4">';
        $this->card(__('Redirected', 'woo-oneclick'), $checkout['redirected'] ?? 0, __('Handed to Woo checkout', 'woo-oneclick'));
        $this->card(__('Completed', 'woo-oneclick'), $checkout['completed'] ?? 0, __('Woo order reported', 'woo-oneclick'));
        $this->card(__('Abandoned', 'woo-oneclick'), $checkout['abandoned'] ?? 0, sprintf(__('No order after %d minutes', 'woo-oneclick'), (int) ($checkout['abandoned_after_minutes'] ?? 30)));
        $this->card(__('Product Exits', 'woo-oneclick'), $checkout['public_product_redirects'] ?? 0, __('Public anonymous product redirects', 'woo-oneclick'));
        echo '</div>';
    }

    private function render_email($overview) {
        $email = $this->get($overview, ['email'], []);
        $events = $email['events'] ?? [];
        echo '<h2>' . esc_html__('Email Engagement', 'woo-oneclick') . '</h2>';
        echo '<div class="oneclick-grid oneclick-grid-4">';
        $this->card(__('Tracked Sends', 'woo-oneclick'), $email['sent_tracked'] ?? 0, __('Campaign/recovery/periodic records', 'woo-oneclick'));
        $this->card(__('Delivered', 'woo-oneclick'), $events['delivered'] ?? 0, __('SendGrid delivered events', 'woo-oneclick'));
        $this->card(__('Opened', 'woo-oneclick'), $events['open'] ?? 0, sprintf(__('Open rate %s%%', 'woo-oneclick'), esc_html($this->percent($email['open_rate'] ?? 0))));
        $this->card(__('Clicked', 'woo-oneclick'), $events['click'] ?? 0, sprintf(__('Click rate %s%%', 'woo-oneclick'), esc_html($this->percent($email['click_rate'] ?? 0))));
        echo '</div>';

        echo '<div class="oneclick-grid oneclick-grid-3">';
        $this->card(__('Bounces', 'woo-oneclick'), $events['bounce'] ?? 0, __('Suppression-sensitive', 'woo-oneclick'));
        $this->card(__('Complaints', 'woo-oneclick'), $events['complaint'] ?? 0, __('Suppression-sensitive', 'woo-oneclick'));
        $this->card(__('Unsubscribes', 'woo-oneclick'), $events['unsubscribe'] ?? 0, __('Tenant suppression events', 'woo-oneclick'));
        echo '</div>';
    }

    private function render_health($overview) {
        $status = $this->get($overview, ['sessions', 'by_status'], []);
        $finalization = $this->get($overview, ['sessions', 'by_finalization_mode'], []);
        $source = $this->get($overview, ['sessions', 'by_source_type'], []);
        $rate_limits = $this->get($overview, ['rate_limits', 'hits'], []);

        echo '<h2>' . esc_html__('Session Health', 'woo-oneclick') . '</h2>';
        echo '<div class="oneclick-grid oneclick-grid-3">';
        $this->card(__('Finalized', 'woo-oneclick'), $status['finalized'] ?? 0, __('All-time tenant count', 'woo-oneclick'));
        $this->card(__('Failed', 'woo-oneclick'), $status['failed'] ?? 0, __('Needs review if non-zero', 'woo-oneclick'));
        $this->card(__('Stale Finalizing', 'woo-oneclick'), $this->get($overview, ['sessions', 'stale_finalizing'], 0), __('Worker retry watch', 'woo-oneclick'));
        echo '</div>';

        echo '<div class="oneclick-panels">';
        $this->list_panel(__('By Finalization Mode', 'woo-oneclick'), $finalization);
        $this->list_panel(__('By Source', 'woo-oneclick'), $source);
        $this->rate_limit_panel($rate_limits);
        echo '</div>';
    }

    private function render_recent_sessions($sessions) {
        echo '<h2>' . esc_html__('Recent Sessions', 'woo-oneclick') . '</h2>';
        echo '<table class="widefat striped oneclick-sessions"><thead><tr>';
        foreach ([__('Session', 'woo-oneclick'), __('Status', 'woo-oneclick'), __('Source', 'woo-oneclick'), __('Mode', 'woo-oneclick'), __('User', 'woo-oneclick'), __('Order', 'woo-oneclick'), __('Updated', 'woo-oneclick')] as $heading) {
            echo '<th>' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';

        if (empty($sessions)) {
            echo '<tr><td colspan="7">' . esc_html__('No sessions found.', 'woo-oneclick') . '</td></tr>';
        }

        foreach ($sessions as $session) {
            echo '<tr>';
            echo '<td><code>' . esc_html($session['session_ref'] ?? '') . '</code></td>';
            echo '<td>' . esc_html($session['status'] ?? '') . '</td>';
            echo '<td>' . esc_html($session['source_type'] ?? '') . '</td>';
            echo '<td>' . esc_html($session['finalization_mode'] ?? '') . '</td>';
            echo '<td>' . esc_html(($session['user_email'] ?? '') ?: ($session['user_id'] ?? 'anonymous')) . '</td>';
            echo '<td>' . esc_html($session['order_id'] ?? '') . '</td>';
            echo '<td>' . esc_html($session['updated_at'] ?? '') . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private function card($label, $value, $hint = '') {
        echo '<div class="oneclick-card">';
        echo '<div class="oneclick-card-label">' . esc_html($label) . '</div>';
        echo '<div class="oneclick-card-value">' . esc_html((string) $value) . '</div>';
        if ($hint !== '') {
            echo '<div class="oneclick-card-hint">' . esc_html($hint) . '</div>';
        }
        echo '</div>';
    }

    private function list_panel($title, $rows) {
        echo '<div class="oneclick-panel"><h3>' . esc_html($title) . '</h3>';
        if (empty($rows)) {
            echo '<p class="description">' . esc_html__('No data.', 'woo-oneclick') . '</p></div>';
            return;
        }
        echo '<ul>';
        foreach ($rows as $key => $value) {
            echo '<li><span>' . esc_html($key) . '</span><strong>' . esc_html((string) $value) . '</strong></li>';
        }
        echo '</ul></div>';
    }

    private function rate_limit_panel($hits) {
        echo '<div class="oneclick-panel"><h3>' . esc_html__('Public Endpoint Guard', 'woo-oneclick') . '</h3>';
        if (empty($hits)) {
            echo '<p class="description">' . esc_html__('No recent public endpoint hits.', 'woo-oneclick') . '</p></div>';
            return;
        }
        echo '<ul>';
        foreach ($hits as $hit) {
            $label = trim(($hit['scope'] ?? '') . ' ' . ($hit['path'] ?? ''));
            echo '<li><span>' . esc_html($label) . '</span><strong>' . esc_html((string) ($hit['count'] ?? 0)) . '</strong></li>';
        }
        echo '</ul></div>';
    }

    private function get($array, $path, $default = null) {
        $value = $array;
        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return $default;
            }
            $value = $value[$key];
        }
        return $value;
    }

    private function percent($ratio) {
        return number_format(((float) $ratio) * 100, 1);
    }

    private function render_styles() {
        ?>
        <style>
            .oneclick-observability .description{max-width:780px}
            .oneclick-window-tabs{margin:18px 0 22px}
            .oneclick-grid{display:grid;gap:14px;margin:14px 0 24px}
            .oneclick-grid-4{grid-template-columns:repeat(4,minmax(0,1fr))}
            .oneclick-grid-3{grid-template-columns:repeat(3,minmax(0,1fr))}
            .oneclick-card,.oneclick-panel{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;box-shadow:0 1px 2px rgba(0,0,0,.03)}
            .oneclick-card-label{font-size:12px;font-weight:700;text-transform:uppercase;color:#646970;letter-spacing:.03em}
            .oneclick-card-value{font-size:30px;line-height:1.2;font-weight:700;color:#1d2327;margin-top:8px}
            .oneclick-card-hint{font-size:13px;color:#646970;margin-top:6px}
            .oneclick-panels{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin:14px 0 24px}
            .oneclick-panel h3{margin:0 0 10px;font-size:14px}
            .oneclick-panel ul{margin:0}
            .oneclick-panel li{display:flex;justify-content:space-between;gap:12px;margin:0;padding:8px 0;border-bottom:1px solid #f0f0f1}
            .oneclick-panel li:last-child{border-bottom:0}
            .oneclick-sessions code{font-size:12px}
            @media (max-width: 1100px){.oneclick-grid-4,.oneclick-grid-3,.oneclick-panels{grid-template-columns:repeat(2,minmax(0,1fr))}}
            @media (max-width: 720px){.oneclick-grid-4,.oneclick-grid-3,.oneclick-panels{grid-template-columns:1fr}}
        </style>
        <?php
    }
}
