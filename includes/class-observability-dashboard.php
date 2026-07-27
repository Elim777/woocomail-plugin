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

        ob_start();
        $hours = isset($_GET['hours']) ? absint($_GET['hours']) : 24;
        if (!in_array($hours, [24, 168, 720], true)) {
            $hours = 24;
        }

        $api = OneClick_API_Client::instance();
        $overview = $api->get('/api/observability/overview', ['hours' => $hours]);
        $sessions = $api->get('/api/observability/sessions', ['limit' => 40]);

        echo '<div class="wrap oneclick-observability">';
        echo '<h1>' . esc_html__('OneClick Dashboard', 'woo-oneclick') . '</h1>';
        echo '<p class="description">' . esc_html__('Read-only client view of emails sent, links clicked, sessions opened, checkout visits and orders created.', 'woo-oneclick') . '</p>';

        $this->render_window_tabs($hours);

        if (is_wp_error($overview)) {
            echo '<div class="notice notice-error"><p>' . esc_html($overview->get_error_message()) . '</p></div>';
            echo '</div>';
            OneClick_Admin_UI::render(self::MENU_SLUG, ob_get_clean());
            return;
        }
        if (is_wp_error($sessions)) {
            echo '<div class="notice notice-error"><p>' . esc_html($sessions->get_error_message()) . '</p></div>';
            $sessions = ['sessions' => []];
        }

        $this->render_styles();
        $this->render_overview($overview);
        $this->render_email_campaigns($overview);
        $this->render_public_links($overview);
        $this->render_purchase_outcomes($overview);
        $this->render_recent_sessions($sessions['sessions'] ?? []);
        $this->render_technical_health($overview);

        echo '</div>';
        OneClick_Admin_UI::render(self::MENU_SLUG, ob_get_clean());
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

    private function render_overview($overview) {
        $overview_data = $this->get($overview, ['client_funnel', 'overview'], []);
        echo '<h2>' . esc_html__('Overview', 'woo-oneclick') . '</h2>';
        echo '<div class="oneclick-grid oneclick-grid-4">';
        $this->card(__('Emails Sent', 'woo-oneclick'), $overview_data['emails_sent'] ?? 0, __('Campaign, recovery and reminder emails', 'woo-oneclick'));
        $this->card(__('Links Clicked', 'woo-oneclick'), $overview_data['links_clicked'] ?? 0, __('Email and public links opened by shoppers', 'woo-oneclick'));
        $this->card(__('Sessions Opened', 'woo-oneclick'), $overview_data['sessions_opened'] ?? 0, __('Purchase windows or checkout sessions started', 'woo-oneclick'));
        $this->card(__('Orders Created', 'woo-oneclick'), $overview_data['orders_created'] ?? 0, __('WooCommerce orders attributed to OneClick', 'woo-oneclick'));
        echo '</div>';
    }

    private function render_email_campaigns($overview) {
        $email = $this->get($overview, ['client_funnel', 'email_campaigns'], []);
        echo '<h2>' . esc_html__('Email Campaigns', 'woo-oneclick') . '</h2>';
        echo '<div class="oneclick-grid oneclick-grid-4">';
        $this->card(__('Sent', 'woo-oneclick'), $email['emails_sent'] ?? 0, __('Emails created by OneClick flows', 'woo-oneclick'));
        $this->card(__('Links Sent', 'woo-oneclick'), $email['email_links_sent'] ?? 0, __('Product links included in campaign emails', 'woo-oneclick'));
        $this->card(__('Delivered', 'woo-oneclick'), $email['emails_delivered'] ?? 0, __('SendGrid delivered events', 'woo-oneclick'));
        $this->card(__('Opened', 'woo-oneclick'), $email['emails_opened'] ?? 0, __('Depends on SendGrid open tracking', 'woo-oneclick'));
        echo '</div>';

        echo '<div class="oneclick-grid oneclick-grid-4">';
        $this->card(__('Clicked', 'woo-oneclick'), $email['email_clicks'] ?? 0, __('Email purchase links clicked', 'woo-oneclick'));
        $this->card(__('Sessions', 'woo-oneclick'), $email['email_sessions_opened'] ?? 0, __('Purchase sessions opened from email', 'woo-oneclick'));
        $this->card(__('Orders', 'woo-oneclick'), $email['email_orders'] ?? 0, __('Orders attributed to email sessions', 'woo-oneclick'));
        $this->card(__('Tracking Note', 'woo-oneclick'), __('Info', 'woo-oneclick'), __('Opens and clicks depend on SendGrid event webhooks being enabled.', 'woo-oneclick'));
        echo '</div>';
    }

    private function render_public_links($overview) {
        $public = $this->get($overview, ['client_funnel', 'public_links'], []);
        echo '<h2>' . esc_html__('Public Links', 'woo-oneclick') . '</h2>';
        echo '<div class="oneclick-grid oneclick-grid-5">';
        $this->card(__('Link Clicks', 'woo-oneclick'), $public['public_link_clicks'] ?? 0, __('Public one-click links opened', 'woo-oneclick'));
        $this->card(__('Sessions', 'woo-oneclick'), $public['public_sessions_opened'] ?? 0, __('Purchase sessions opened from public links', 'woo-oneclick'));
        $this->card(__('Product Page Visits', 'woo-oneclick'), $public['public_product_exits'] ?? 0, __('Anonymous shoppers sent to product page', 'woo-oneclick'));
        $this->card(__('Checkout Visits', 'woo-oneclick'), $public['public_checkout_redirects'] ?? 0, __('Anonymous shoppers sent to checkout', 'woo-oneclick'));
        $this->card(__('Orders', 'woo-oneclick'), $public['public_orders'] ?? 0, __('Orders attributed to public links', 'woo-oneclick'));
        echo '</div>';
    }

    private function render_purchase_outcomes($overview) {
        $outcomes = $this->get($overview, ['client_funnel', 'purchase_outcomes'], []);
        echo '<h2>' . esc_html__('Purchase Outcomes', 'woo-oneclick') . '</h2>';
        echo '<div class="oneclick-grid oneclick-grid-6">';
        $this->card(__('Open Now', 'woo-oneclick'), $outcomes['open_now'] ?? 0, __('Sessions still inside their active timer', 'woo-oneclick'));
        $this->card(__('Went to Checkout', 'woo-oneclick'), $outcomes['went_to_checkout'] ?? 0, __('Checkout handoffs from OneClick', 'woo-oneclick'));
        $this->card(__('Auto Purchases', 'woo-oneclick'), $outcomes['auto_purchase_attempts'] ?? 0, __('Saved-card or non-card order attempts', 'woo-oneclick'));
        $this->card(__('Orders Created', 'woo-oneclick'), $outcomes['orders_created'] ?? 0, __('Finalized sessions with Woo order', 'woo-oneclick'));
        $this->card(__('Cancelled', 'woo-oneclick'), $outcomes['cancelled'] ?? 0, __('Sessions cancelled by shopper', 'woo-oneclick'));
        $this->card(__('Abandoned', 'woo-oneclick'), $outcomes['abandoned'] ?? 0, sprintf(__('No checkout/order after %d minutes or expired timer', 'woo-oneclick'), (int) ($outcomes['abandoned_after_minutes'] ?? 30)));
        echo '</div>';
    }

    private function render_technical_health($overview) {
        $status = $this->get($overview, ['sessions', 'by_status'], []);
        $sessions = $this->get($overview, ['sessions'], []);
        $finalization = $this->get($overview, ['sessions', 'by_finalization_mode'], []);
        $source = $this->get($overview, ['sessions', 'by_source_type'], []);
        $rate_limits = $this->get($overview, ['rate_limits', 'hits'], []);

        echo '<h2>' . esc_html__('Technical Health', 'woo-oneclick') . '</h2>';
        echo '<div class="oneclick-grid oneclick-grid-3">';
        $this->card(__('Due / Retry', 'woo-oneclick'), $sessions['due'] ?? 0, __('Worker can claim these sessions', 'woo-oneclick'));
        $this->card(__('Stale Finalizing', 'woo-oneclick'), $sessions['stale_finalizing'] ?? 0, __('Worker retry watch', 'woo-oneclick'));
        $this->card(__('Failed', 'woo-oneclick'), $status['failed'] ?? 0, __('Needs review if non-zero', 'woo-oneclick'));
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
        foreach ([__('Reference', 'woo-oneclick'), __('Outcome', 'woo-oneclick'), __('From', 'woo-oneclick'), __('Path', 'woo-oneclick'), __('Customer', 'woo-oneclick'), __('Order', 'woo-oneclick'), __('Updated', 'woo-oneclick')] as $heading) {
            echo '<th>' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';

        if (empty($sessions)) {
            echo '<tr><td colspan="7">' . esc_html__('No sessions found.', 'woo-oneclick') . '</td></tr>';
        }

        foreach ($sessions as $session) {
            echo '<tr>';
            echo '<td><code>' . esc_html($session['session_ref'] ?? '') . '</code></td>';
            echo '<td>' . esc_html($this->label_outcome($session)) . '</td>';
            echo '<td>' . esc_html($this->label_source($session['source_type'] ?? '')) . '</td>';
            echo '<td>' . esc_html($this->label_path($session['finalization_mode'] ?? '')) . '</td>';
            echo '<td>' . esc_html($this->label_customer($session)) . '</td>';
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

    private function label_source($source) {
        $labels = [
            'public_link' => __('Public link', 'woo-oneclick'),
            'campaign_email' => __('Email campaign', 'woo-oneclick'),
        ];
        return $labels[$source] ?? ($source !== '' ? $source : __('Unknown', 'woo-oneclick'));
    }

    private function label_path($mode) {
        $labels = [
            'mit_purchase' => __('Saved card', 'woo-oneclick'),
            'non_card_order' => __('Saved non-card order', 'woo-oneclick'),
            'checkout' => __('Checkout', 'woo-oneclick'),
        ];
        return $labels[$mode] ?? ($mode !== '' ? $mode : __('Unknown', 'woo-oneclick'));
    }

    private function label_outcome($session) {
        $status = $session['status'] ?? '';
        $order_id = $session['order_id'] ?? null;

        if ($status === 'finalized' && !empty($order_id)) {
            return __('Ordered', 'woo-oneclick');
        }
        if ($status === 'cancelled') {
            return __('Cancelled', 'woo-oneclick');
        }
        if ($status === 'failed') {
            return __('Failed', 'woo-oneclick');
        }
        if ($status === 'finalizing') {
            return __('Finalizing', 'woo-oneclick');
        }
        if ($status === 'active') {
            $finalize_after = !empty($session['finalize_after']) ? strtotime($session['finalize_after']) : false;
            if ($finalize_after && $finalize_after <= time() && empty($session['checkout_redirected_at']) && empty($order_id)) {
                return __('Abandoned / no checkout', 'woo-oneclick');
            }
            return __('Open now', 'woo-oneclick');
        }

        return $status !== '' ? $status : __('Unknown', 'woo-oneclick');
    }

    private function label_customer($session) {
        if (!empty($session['user_email'])) {
            return $session['user_email'];
        }
        if (!empty($session['user_id']) && (int) $session['user_id'] > 0) {
            return sprintf(__('User #%d', 'woo-oneclick'), (int) $session['user_id']);
        }
        return __('Anonymous', 'woo-oneclick');
    }

    private function render_styles() {
        ?>
        <style>
            .oneclick-observability .description{max-width:780px}
            .oneclick-window-tabs{margin:18px 0 22px}
            .oneclick-grid{display:grid;gap:14px;margin:14px 0 24px}
            .oneclick-grid-4{grid-template-columns:repeat(4,minmax(0,1fr))}
            .oneclick-grid-3{grid-template-columns:repeat(3,minmax(0,1fr))}
            .oneclick-grid-5{grid-template-columns:repeat(5,minmax(0,1fr))}
            .oneclick-grid-6{grid-template-columns:repeat(6,minmax(0,1fr))}
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
            @media (max-width: 1280px){.oneclick-grid-6,.oneclick-grid-5{grid-template-columns:repeat(3,minmax(0,1fr))}}
            @media (max-width: 1100px){.oneclick-grid-6,.oneclick-grid-5,.oneclick-grid-4,.oneclick-grid-3,.oneclick-panels{grid-template-columns:repeat(2,minmax(0,1fr))}}
            @media (max-width: 720px){.oneclick-grid-6,.oneclick-grid-5,.oneclick-grid-4,.oneclick-grid-3,.oneclick-panels{grid-template-columns:1fr}}
        </style>
        <?php
    }
}
