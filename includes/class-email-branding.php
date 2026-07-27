<?php
/**
 * Email and purchase experience branding admin page
 *
 * Admin UI for customizing customer email and purchase window branding.
 * Includes Domain Setup section for subdomain/custom domain configuration.
 * Communicates with backend via OneClick_API_Client.
 *
 * @package WooOneClick
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class OneClick_Email_Branding {

    const MENU_SLUG = 'oneclick-branding';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_submenu']);
        add_action('admin_init', [$this, 'handle_save']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);

        // Domain AJAX handlers
        add_action('wp_ajax_oneclick_domain_provision', [$this, 'ajax_domain_provision']);
        add_action('wp_ajax_oneclick_domain_verify', [$this, 'ajax_domain_verify']);
        add_action('wp_ajax_oneclick_domain_status', [$this, 'ajax_domain_status']);
    }

    public function add_submenu() {
        add_submenu_page(
            'oneclick-settings',
            __('Email & Purchase Branding', 'woo-oneclick'),
            __('Branding', 'woo-oneclick'),
            'manage_woocommerce',
            self::MENU_SLUG,
            [$this, 'render_page']
        );
    }

    public function enqueue_scripts($hook) {
        $page = $_GET['page'] ?? '';
        if ($page !== self::MENU_SLUG) {
            return;
        }

        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        wp_enqueue_media();

        wp_enqueue_script(
            'oneclick-branding',
            ONECLICK_PLUGIN_URL . 'assets/js/branding.js',
            ['jquery', 'wp-color-picker', 'oneclick-admin-ui'],
            ONECLICK_VERSION,
            true
        );

        wp_enqueue_script(
            'oneclick-domain-setup',
            ONECLICK_PLUGIN_URL . 'assets/js/domain-setup.js',
            ['jquery', 'oneclick-admin-ui'],
            ONECLICK_VERSION,
            true
        );

        wp_localize_script('oneclick-domain-setup', 'oneclickDomain', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('oneclick_domain_nonce'),
            'siteUrl' => site_url(),
        ]);

        wp_enqueue_style(
            'oneclick-branding',
            ONECLICK_PLUGIN_URL . 'assets/css/branding.css',
            [],
            ONECLICK_VERSION
        );
    }

    // ========================================================================
    // DOMAIN AJAX HANDLERS
    // ========================================================================

    public function ajax_domain_provision() {
        check_ajax_referer('oneclick_domain_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized', 403);
        }

        $custom_domain = sanitize_text_field($_POST['custom_domain'] ?? '');
        $data = ['site_url' => site_url()];
        if ($custom_domain) {
            $data['custom_domain'] = $custom_domain;
        }

        $api = OneClick_API_Client::instance();
        $result = $api->post('/api/domains/provision', $data);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success($result);
    }

    public function ajax_domain_verify() {
        check_ajax_referer('oneclick_domain_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized', 403);
        }

        $domain_id = absint($_POST['domain_id'] ?? 0);
        if (!$domain_id) {
            wp_send_json_error('Missing domain_id');
        }

        $api = OneClick_API_Client::instance();
        $result = $api->post("/api/domains/{$domain_id}/verify");

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success($result);
    }

    public function ajax_domain_status() {
        check_ajax_referer('oneclick_domain_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized', 403);
        }

        $api = OneClick_API_Client::instance();
        $result = $api->get('/api/domains', ['site_url' => site_url()]);

        if (is_wp_error($result)) {
            // 404 = no domain yet, that's OK
            wp_send_json_success(['status' => 'none']);
        }

        wp_send_json_success($result);
    }

    // ========================================================================
    // BRANDING SAVE
    // ========================================================================

    public function handle_save() {
        if (!isset($_POST['oneclick_branding_nonce'])) {
            return;
        }
        if (!wp_verify_nonce($_POST['oneclick_branding_nonce'], 'oneclick_branding_save')) {
            wp_die(__('Security check failed.', 'woo-oneclick'));
        }
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $data = [
            'site_url'             => site_url(),
            'logo_url'             => esc_url_raw($_POST['logo_url'] ?? ''),
            'primary_color'        => sanitize_hex_color($_POST['primary_color'] ?? '#5b3cdd'),
            'secondary_color'      => sanitize_hex_color($_POST['secondary_color'] ?? '#e9edff'),
            'background_color'     => sanitize_hex_color($_POST['background_color'] ?? '#f9f9ff'),
            'text_color'           => sanitize_hex_color($_POST['text_color'] ?? '#141b2b'),
            'accent_color'         => sanitize_hex_color($_POST['accent_color'] ?? ''),
            'button_color'         => sanitize_hex_color($_POST['button_color'] ?? ''),
            'button_text_color'    => sanitize_hex_color($_POST['button_text_color'] ?? '#ffffff'),
            'button_text'          => sanitize_text_field($_POST['button_text'] ?? 'Buy Now with One Click'),
            'button_border_radius' => absint($_POST['button_border_radius'] ?? 14),
            'company_name'         => sanitize_text_field($_POST['company_name'] ?? ''),
            'sender_name'          => sanitize_text_field($_POST['sender_name'] ?? ''),
            'sender_email'         => sanitize_email($_POST['sender_email'] ?? ''),
            'header_text'          => sanitize_text_field($_POST['header_text'] ?? ''),
            'body_text'            => wp_kses_post($_POST['body_text'] ?? ''),
            'footer_text'          => sanitize_text_field($_POST['footer_text'] ?? ''),
            'font_family'          => sanitize_text_field($_POST['font_family'] ?? 'Manrope, Arial, sans-serif'),
        ];

        $api = OneClick_API_Client::instance();
        $result = $api->post('/api/branding', $data);

        if (is_wp_error($result)) {
            set_transient('oneclick_branding_error', $result->get_error_message(), 30);
            wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&error=1'));
            exit;
        }

        wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&saved=1'));
        exit;
    }

    // ========================================================================
    // RENDER PAGE
    // ========================================================================

    public function render_page() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        ob_start();
        // Fetch current branding from backend
        $api = OneClick_API_Client::instance();
        $branding = $api->get('/api/branding', ['site_url' => site_url()]);

        if (is_wp_error($branding)) {
            $branding = [];
        }

        // Fetch current domain status
        $domain = $api->get('/api/domains', ['site_url' => site_url()]);
        if (is_wp_error($domain)) {
            $domain = null;
        }

        // Defaults
        $b = wp_parse_args($branding, [
            'logo_url'             => '',
            'primary_color'        => '#5b3cdd',
            'secondary_color'      => '#e9edff',
            'background_color'     => '#f9f9ff',
            'text_color'           => '#141b2b',
            'accent_color'         => '',
            'button_color'         => '',
            'button_text_color'    => '#ffffff',
            'button_text'          => 'Buy Now with One Click',
            'button_border_radius' => 14,
            'company_name'         => '',
            'sender_name'          => '',
            'sender_email'         => '',
            'header_text'          => '',
            'body_text'            => '',
            'footer_text'          => '',
            'font_family'          => 'Manrope, Arial, sans-serif',
        ]);

        // Preview URL — fetch a short-lived signed token from backend
        // (iframe can't send custom headers, so we use a preview_token query param)
        $backend_url = rtrim(get_option('oneclick_backend_url', 'https://woocomail-api.onrender.com'), '/');
        $api = OneClick_API_Client::instance();
        $token_result = $api->post('/api/branding/preview-token', ['site_url' => site_url()]);
        $preview_token = '';
        if (!is_wp_error($token_result) && !empty($token_result['preview_token'])) {
            $preview_token = $token_result['preview_token'];
        } else {
            oneclick_log('OneClick Branding: Failed to fetch preview token: ' . (is_wp_error($token_result) ? $token_result->get_error_message() : 'empty response'));
        }
        $preview_url = $backend_url . '/api/branding/preview?' . http_build_query([
            'site_url' => site_url(),
            'preview_token' => $preview_token,
        ]);

        // Notices
        if (isset($_GET['saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . __('Branding saved.', 'woo-oneclick') . '</p></div>';
        }
        if (isset($_GET['error'])) {
            $error = get_transient('oneclick_branding_error');
            if ($error) {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($error) . '</p></div>';
                delete_transient('oneclick_branding_error');
            }
        }
        ?>
        <div class="wrap">
            <h1><?php _e('Email & Purchase Branding', 'woo-oneclick'); ?></h1>
            <p class="description"><?php _e('These settings style customer emails and the merchant-themed purchase window. The Ocliby session theme keeps its system colors and typography while using the merchant logo and company name. Email copy fields apply to email content only.', 'woo-oneclick'); ?></p>

            <!-- ============================================================ -->
            <!-- DOMAIN SETUP SECTION                                         -->
            <!-- ============================================================ -->
            <div class="oneclick-domain-setup" id="oneclick-domain-setup">
                <h2><?php _e('Sending Domain', 'woo-oneclick'); ?></h2>
                <p class="description"><?php _e('Configure which domain your emails are sent from. Subdomain is automatic, custom domain requires DNS setup.', 'woo-oneclick'); ?></p>

                <div class="oneclick-domain-cards">
                    <!-- Subdomain Card -->
                    <div class="oneclick-domain-card" id="domain-card-subdomain">
                        <h3><?php _e('Subdomain (automatic)', 'woo-oneclick'); ?></h3>
                        <p class="description"><?php _e('We automatically create and verify a subdomain for your shop. No DNS configuration needed.', 'woo-oneclick'); ?></p>
                        <div class="oneclick-domain-card-body">
                            <code id="subdomain-preview"><?php
                                if ($domain && ($domain['domain_type'] ?? '') === 'subdomain') {
                                    echo esc_html($domain['domain_name']);
                                } else {
                                    echo esc_html('{brand}.woocomail.com');
                                }
                            ?></code>
                            <div id="subdomain-status">
                                <?php $this->render_domain_badge($domain, 'subdomain'); ?>
                            </div>
                        </div>
                        <?php if (!$domain || ($domain['domain_type'] ?? '') !== 'subdomain'): ?>
                            <button type="button" class="button button-primary" id="btn-setup-subdomain">
                                <?php _e('Setup Subdomain', 'woo-oneclick'); ?>
                            </button>
                        <?php elseif (!($domain['verified'] ?? false)): ?>
                            <button type="button" class="button" id="btn-reverify" data-domain-id="<?php echo esc_attr($domain['id']); ?>">
                                <?php _e('Re-verify', 'woo-oneclick'); ?>
                            </button>
                        <?php endif; ?>
                        <span class="spinner" id="subdomain-spinner"></span>
                    </div>

                    <!-- Custom Domain Card -->
                    <div class="oneclick-domain-card" id="domain-card-custom">
                        <h3><?php _e('Custom Domain (DNS required)', 'woo-oneclick'); ?></h3>
                        <p class="description"><?php _e('Use your own domain for sending. You will need to add DNS records at your DNS provider.', 'woo-oneclick'); ?></p>
                        <div class="oneclick-domain-card-body">
                            <?php if ($domain && ($domain['domain_type'] ?? '') === 'custom'): ?>
                                <code><?php echo esc_html($domain['domain_name']); ?></code>
                                <div id="custom-status">
                                    <?php $this->render_domain_badge($domain, 'custom'); ?>
                                </div>
                            <?php else: ?>
                                <input type="text" id="custom-domain-input" placeholder="mail.yourdomain.com" class="regular-text">
                            <?php endif; ?>
                        </div>
                        <?php if ($domain && ($domain['domain_type'] ?? '') === 'custom'): ?>
                            <?php if (!($domain['verified'] ?? false)): ?>
                                <button type="button" class="button" id="btn-reverify-custom" data-domain-id="<?php echo esc_attr($domain['id']); ?>">
                                    <?php _e('Re-verify', 'woo-oneclick'); ?>
                                </button>
                            <?php endif; ?>
                        <?php else: ?>
                            <button type="button" class="button button-primary" id="btn-setup-custom">
                                <?php _e('Setup Domain', 'woo-oneclick'); ?>
                            </button>
                        <?php endif; ?>
                        <span class="spinner" id="custom-spinner"></span>
                    </div>
                </div>

                <!-- DNS Records Table (shown for custom domain or pending subdomain) -->
                <?php if ($domain && !empty($domain['dns_records']) && ($domain['domain_type'] ?? '') === 'custom'): ?>
                    <div class="oneclick-dns-records" id="dns-records-section">
                        <h3><?php _e('DNS Records', 'woo-oneclick'); ?></h3>
                        <p class="description"><?php _e('Add these records at your DNS provider, then click Re-verify.', 'woo-oneclick'); ?></p>
                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th><?php _e('Type', 'woo-oneclick'); ?></th>
                                    <th><?php _e('Host', 'woo-oneclick'); ?></th>
                                    <th><?php _e('Value', 'woo-oneclick'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($domain['dns_records'] as $key => $record): ?>
                                    <tr>
                                        <td><code><?php echo esc_html(strtoupper($record['type'] ?? 'CNAME')); ?></code></td>
                                        <td><code><?php echo esc_html($record['host'] ?? ''); ?></code></td>
                                        <td><code><?php echo esc_html($record['data'] ?? ''); ?></code></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <!-- DNS records placeholder for JS-rendered records -->
                <div class="oneclick-dns-records" id="dns-records-dynamic" style="display:none;">
                    <h3><?php _e('DNS Records', 'woo-oneclick'); ?></h3>
                    <p class="description"><?php _e('Add these records at your DNS provider, then click Re-verify.', 'woo-oneclick'); ?></p>
                    <table class="widefat striped" id="dns-records-table">
                        <thead>
                            <tr>
                                <th><?php _e('Type', 'woo-oneclick'); ?></th>
                                <th><?php _e('Host', 'woo-oneclick'); ?></th>
                                <th><?php _e('Value', 'woo-oneclick'); ?></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>

            <hr style="margin: 30px 0;">

            <!-- ============================================================ -->
            <!-- BRANDING FORM                                                -->
            <!-- ============================================================ -->
            <div class="oneclick-branding-layout">
                <div class="oneclick-branding-form">
                    <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)); ?>">
                        <?php wp_nonce_field('oneclick_branding_save', 'oneclick_branding_nonce'); ?>

                        <!-- Logo -->
                        <h2><?php _e('Logo', 'woo-oneclick'); ?></h2>
                        <table class="form-table">
                            <tr>
                                <th><label for="logo_url"><?php _e('Logo URL', 'woo-oneclick'); ?></label></th>
                                <td>
                                    <input type="url" id="logo_url" name="logo_url" value="<?php echo esc_attr($b['logo_url']); ?>" class="regular-text">
                                    <button type="button" class="button oneclick-upload-logo"><?php _e('Upload', 'woo-oneclick'); ?></button>
                                    <?php if (!empty($b['logo_url'])): ?>
                                        <div style="margin-top:10px;"><img src="<?php echo esc_url($b['logo_url']); ?>" style="max-height:60px;"></div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>

                        <!-- Colors -->
                        <h2><?php _e('Colors', 'woo-oneclick'); ?></h2>
                        <table class="form-table">
                            <?php
                            $color_fields = [
                                'primary_color'     => __('Primary Color', 'woo-oneclick'),
                                'secondary_color'   => __('Secondary Color', 'woo-oneclick'),
                                'background_color'  => __('Background Color', 'woo-oneclick'),
                                'text_color'        => __('Text Color', 'woo-oneclick'),
                                'accent_color'      => __('Accent Color', 'woo-oneclick'),
                                'button_color'      => __('Button Color', 'woo-oneclick'),
                                'button_text_color' => __('Button Text Color', 'woo-oneclick'),
                            ];
                            foreach ($color_fields as $key => $label): ?>
                                <tr>
                                    <th><label for="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
                                    <td><input type="text" id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($b[$key]); ?>" class="oneclick-color-picker"></td>
                                </tr>
                            <?php endforeach; ?>
                        </table>

                        <!-- Button -->
                        <h2><?php _e('Button', 'woo-oneclick'); ?></h2>
                        <table class="form-table">
                            <tr>
                                <th><label for="button_text"><?php _e('Button Text', 'woo-oneclick'); ?></label></th>
                                <td><input type="text" id="button_text" name="button_text" value="<?php echo esc_attr($b['button_text']); ?>" class="regular-text"></td>
                            </tr>
                            <tr>
                                <th><label for="button_border_radius"><?php _e('Border Radius (px)', 'woo-oneclick'); ?></label></th>
                                <td><input type="number" id="button_border_radius" name="button_border_radius" value="<?php echo esc_attr($b['button_border_radius']); ?>" min="0" max="50" class="small-text"></td>
                            </tr>
                        </table>

                        <!-- Company Info -->
                        <h2><?php _e('Company Info', 'woo-oneclick'); ?></h2>
                        <table class="form-table">
                            <tr>
                                <th><label for="company_name"><?php _e('Company Name', 'woo-oneclick'); ?></label></th>
                                <td><input type="text" id="company_name" name="company_name" value="<?php echo esc_attr($b['company_name']); ?>" class="regular-text"></td>
                            </tr>
                            <tr>
                                <th><label for="sender_name"><?php _e('Sender Name', 'woo-oneclick'); ?></label></th>
                                <td><input type="text" id="sender_name" name="sender_name" value="<?php echo esc_attr($b['sender_name']); ?>" class="regular-text"></td>
                            </tr>
                            <tr>
                                <th><label for="sender_email"><?php _e('Sender Email', 'woo-oneclick'); ?></label></th>
                                <td><input type="email" id="sender_email" name="sender_email" value="<?php echo esc_attr($b['sender_email']); ?>" class="regular-text"></td>
                            </tr>
                        </table>

                        <!-- Texts -->
                        <h2><?php _e('Texts', 'woo-oneclick'); ?></h2>
                        <table class="form-table">
                            <tr>
                                <th><label for="header_text"><?php _e('Header Text', 'woo-oneclick'); ?></label></th>
                                <td><input type="text" id="header_text" name="header_text" value="<?php echo esc_attr($b['header_text']); ?>" class="large-text"></td>
                            </tr>
                            <tr>
                                <th><label for="body_text"><?php _e('Body Text', 'woo-oneclick'); ?></label></th>
                                <td><textarea id="body_text" name="body_text" rows="4" class="large-text"><?php echo esc_textarea($b['body_text']); ?></textarea></td>
                            </tr>
                            <tr>
                                <th><label for="footer_text"><?php _e('Footer Text', 'woo-oneclick'); ?></label></th>
                                <td><input type="text" id="footer_text" name="footer_text" value="<?php echo esc_attr($b['footer_text']); ?>" class="large-text"></td>
                            </tr>
                            <tr>
                                <th><label for="font_family"><?php _e('Font Family', 'woo-oneclick'); ?></label></th>
                                <td>
                                    <select id="font_family" name="font_family">
                                        <?php
                                        $fonts = ['Manrope, Arial, sans-serif', 'Arial, sans-serif', 'Helvetica, sans-serif', 'Georgia, serif', 'Verdana, sans-serif', 'Tahoma, sans-serif'];
                                        foreach ($fonts as $font): ?>
                                            <option value="<?php echo esc_attr($font); ?>" <?php selected($b['font_family'], $font); ?>><?php echo esc_html($font); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                        </table>

                        <?php submit_button(__('Save Branding', 'woo-oneclick')); ?>
                    </form>
                </div>

                <!-- Preview Panel -->
                <div class="oneclick-branding-preview">
                    <h2><?php _e('Customer Email Preview', 'woo-oneclick'); ?></h2>
                    <p class="description"><?php _e('Save branding first, then refresh preview. This preview represents the customer email. The merchant session theme also uses these colors and typography; the Ocliby session theme uses the saved logo and company name.', 'woo-oneclick'); ?></p>
                    <button type="button" class="button oneclick-refresh-preview"><?php _e('Refresh Preview', 'woo-oneclick'); ?></button>
                    <div style="margin-top:10px;">
                        <iframe id="oneclick-preview-frame" src="<?php echo esc_url($preview_url); ?>" style="width:100%; height:600px; border:1px solid #ccd0d4; border-radius:4px;"></iframe>
                    </div>
                </div>
            </div>
        </div>
        <?php
        OneClick_Admin_UI::render(self::MENU_SLUG, ob_get_clean());
    }

    // ========================================================================
    // HELPERS
    // ========================================================================

    private function render_domain_badge($domain, $type) {
        if (!$domain || ($domain['domain_type'] ?? '') !== $type) {
            echo '<span class="oneclick-badge badge-none">' . __('Not configured', 'woo-oneclick') . '</span>';
            return;
        }
        if ($domain['verified'] ?? false) {
            echo '<span class="oneclick-badge badge-verified">' . __('Verified', 'woo-oneclick') . '</span>';
        } else {
            echo '<span class="oneclick-badge badge-pending">' . __('Pending verification...', 'woo-oneclick') . '</span>';
        }
    }
}
