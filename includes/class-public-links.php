<?php
/**
 * Public One-Click Links
 *
 * Admin UI for ad/chat/post links and public shop URL passthrough.
 *
 * @package WooOneClick
 */

if (!defined('ABSPATH')) {
    exit;
}

class OneClick_Public_Links {

    const MENU_SLUG = 'oneclick-public-links';
    const VISITOR_COOKIE = 'oneclick_public_visitor';
    const REWRITE_VERSION = 'public-email-claim-v2';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_submenu']);
        add_action('admin_init', [$this, 'handle_form_actions']);
        add_action('init', [$this, 'add_rewrite_rule']);
        add_filter('query_vars', [$this, 'add_query_vars']);
        add_action('template_redirect', [$this, 'handle_passthrough']);
        add_action('init', [$this, 'maybe_flush_rewrite_rules'], 20);
    }

    public static function get_or_create_visitor_key() {
        if (!empty($_COOKIE[self::VISITOR_COOKIE])) {
            $key = sanitize_text_field(wp_unslash($_COOKIE[self::VISITOR_COOKIE]));
            self::set_visitor_cookie($key);
            return $key;
        }

        $key = wp_generate_password(32, false, false);
        self::set_visitor_cookie($key);
        $_COOKIE[self::VISITOR_COOKIE] = $key;
        return $key;
    }

    private static function set_visitor_cookie($key) {
        $args = [
            'expires'  => time() + DAY_IN_SECONDS * 30,
            'path'     => COOKIEPATH ?: '/',
            'secure'   => false,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        if (!empty(COOKIE_DOMAIN)) {
            $args['domain'] = COOKIE_DOMAIN;
        }
        setcookie(self::VISITOR_COOKIE, $key, $args);
    }

    public function add_submenu() {
        add_submenu_page(
            'oneclick-settings',
            __('One-Click Links', 'woo-oneclick'),
            __('One-Click Links', 'woo-oneclick'),
            'manage_woocommerce',
            self::MENU_SLUG,
            [$this, 'render_page']
        );
    }

    public function add_rewrite_rule() {
        add_rewrite_rule('^oneclick/email-claim/([A-Za-z0-9_-]{10,50})/?$', 'index.php?oneclick_email_claim_code=$matches[1]', 'top');
        add_rewrite_rule('^oneclick/claim/([A-Za-z0-9_-]{10,50})/?$', 'index.php?oneclick_public_claim_code=$matches[1]', 'top');
        add_rewrite_rule('^oneclick/([A-Za-z0-9]{8,16})/?$', 'index.php?oneclick_public_short_id=$matches[1]', 'top');
    }

    public function add_query_vars($vars) {
        $vars[] = 'oneclick_public_short_id';
        $vars[] = 'oneclick_public_claim_code';
        $vars[] = 'oneclick_email_claim_code';
        return $vars;
    }

    public function maybe_flush_rewrite_rules() {
        $rewrite_version = ONECLICK_VERSION . '-' . self::REWRITE_VERSION;
        if (get_option('oneclick_public_links_rewrite_version') === $rewrite_version) {
            return;
        }
        $this->add_rewrite_rule();
        flush_rewrite_rules(false);
        update_option('oneclick_public_links_rewrite_version', $rewrite_version, false);
    }

    public function handle_passthrough() {
        $email_claim_code = get_query_var('oneclick_email_claim_code');
        if (!empty($email_claim_code)) {
            $this->handle_email_claim_landing($email_claim_code);
            return;
        }

        $claim_code = get_query_var('oneclick_public_claim_code');
        if (!empty($claim_code)) {
            $this->handle_claim_landing($claim_code);
            return;
        }

        $short_id = get_query_var('oneclick_public_short_id');
        if (empty($short_id)) {
            return;
        }

        $short_id = preg_replace('/[^A-Za-z0-9]/', '', (string) $short_id);
        if (strlen($short_id) < 8 || strlen($short_id) > 16) {
            status_header(404);
            exit;
        }

        error_log('');
        error_log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        error_log('🔗 PUBLIC LINK FÁZA B: SHOP URL → BACKEND PASSTHROUGH');
        error_log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        error_log(sprintf('   Browser otvoril /oneclick/%s na WordPress.', $short_id));
        error_log('   Plugin je v tejto fáze iba passthrough redirect.');
        error_log('   Plugin NEVOLÁ exchange, NEPOSIELA license key, NEVYTVÁRA session, NEČÍTA produkty/ceny.');
        $visitor_key = self::get_or_create_visitor_key();
        error_log(sprintf(
            '   Plugin nastavuje/obnovuje anonymnú visitor cookie | visitor_hash=%s... | cookie=%s | HttpOnly + SameSite=Lax.',
            substr(hash('sha256', $visitor_key), 0, 12),
            self::VISITOR_COOKIE
        ));

        nocache_headers();
        $backend = rtrim(OneClick_API_Client::instance()->get_base_url(), '/');
        $target = $backend . '/public-click?id=' . rawurlencode($short_id);
        error_log(sprintf('   302 redirect na backend: %s', $target));
        error_log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        error_log('');
        // This route is intentionally an external passthrough to the trusted backend.
        wp_redirect($target, 302);
        exit;
    }

    private function handle_claim_landing($claim_code) {
        $claim_code = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $claim_code);
        if (strlen($claim_code) < 10 || strlen($claim_code) > 50) {
            status_header(404);
            exit;
        }

        nocache_headers();

        error_log('');
        error_log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        error_log('🔗 PUBLIC LINK FÁZA D: WORDPRESS CLAIM LANDING → LICENSED EXCHANGE');
        error_log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        error_log(sprintf('   Browser prišiel na non-REST route /oneclick/claim/%s...', substr($claim_code, 0, 8)));
        error_log(sprintf(
            '   WordPress context: site_url=%s | home_url=%s | is_ssl=%s | is_user_logged_in=%s | current_user_id=%s',
            site_url(),
            home_url(),
            is_ssl() ? 'yes' : 'no',
            is_user_logged_in() ? 'yes' : 'no',
            get_current_user_id() ?: 'anonymous'
        ));

        if (is_user_logged_in() && current_user_can('manage_woocommerce')) {
            error_log('   WordPress user má manage_woocommerce capability. Public shopper identity sa nepoužije; pokračuje anonymous routing.');
            $this->exchange_public_claim($claim_code, false, true);
        }

        if (is_user_logged_in()) {
            error_log('   Logged-in user rozpoznaný na non-REST route. Plugin bez extra confirmation pripraví server-side user/payment context.');
            $this->exchange_public_claim($claim_code, true, false);
        }

        error_log('   Anonymous visitor. Plugin pokračuje do checkout-only exchange kontextu a potom použije anonymous routing policy.');
        $this->exchange_public_claim($claim_code, false, true);
    }

    private function handle_email_claim_landing($claim_code) {
        $claim_code = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $claim_code);
        if (strlen($claim_code) < 10 || strlen($claim_code) > 50) {
            status_header(404);
            exit;
        }

        nocache_headers();

        error_log('');
        error_log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        error_log('🔗 EMAIL LINK FÁZA 4: WORDPRESS EMAIL CLAIM LANDING → IDENTITY GATE');
        error_log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        error_log(sprintf('   Browser prišiel na non-REST route /oneclick/email-claim/%s...', substr($claim_code, 0, 8)));
        error_log(sprintf(
            '   WordPress context: site_url=%s | home_url=%s | is_ssl=%s | is_user_logged_in=%s | current_user_id=%s',
            site_url(),
            home_url(),
            is_ssl() ? 'yes' : 'no',
            is_user_logged_in() ? 'yes' : 'no',
            get_current_user_id() ?: 'anonymous'
        ));
        error_log('   Plugin pošle cookie-derived user context iba server-side v licencovanom exchange requeste.');

        if (!class_exists('OneClick_Purchase_Handler')) {
            $this->render_claim_error(
                __('Purchase Handler Unavailable', 'woo-oneclick'),
                __('The purchase flow could not be initialized. Please contact support.', 'woo-oneclick')
            );
        }

        $handler = new OneClick_Purchase_Handler(false);
        $include_user_context = is_user_logged_in();
        $context = $handler->get_exchange_context($include_user_context);
        $result = $handler->exchange_code_with_backend($claim_code, $context);

        if (is_wp_error($result)) {
            error_log('OneClick Email Link: exchange-code failed — ' . $result->get_error_message());
            return $handler->render_purchase_error_page(
                __('Invalid Purchase Link', 'woo-oneclick'),
                __('This purchase link is invalid or has expired. Please contact support if you need assistance.', 'woo-oneclick')
            );
        }

        if (empty($result['success']) || empty($result['payload'])) {
            error_log('OneClick Email Link: exchange-code returned empty or unsuccessful response');
            return $handler->render_purchase_error_page(
                __('Invalid Purchase Link', 'woo-oneclick'),
                __('Could not verify this purchase window. Please contact support.', 'woo-oneclick')
            );
        }

        $payload = $result['payload'];
        if (($payload['flow'] ?? '') !== 'purchase_session') {
            error_log('OneClick Email Link: email claim did not return purchase_session flow');
            return $handler->render_purchase_error_page(
                __('Invalid Purchase Link', 'woo-oneclick'),
                __('This email purchase link could not open a purchase window.', 'woo-oneclick')
            );
        }

        error_log(sprintf(
            '   Email identity gate výsledok | identity_verified=%s | finalization_mode=%s',
            !empty($payload['identity_verified']) ? 'yes' : 'no',
            $payload['finalization_mode'] ?? 'unknown'
        ));
        error_log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        error_log('');

        return $handler->redirect_to_purchase_session($payload);
    }

    private function exchange_public_claim($claim_code, $include_user_context, $route_anonymous = false) {
        if (!class_exists('OneClick_Purchase_Handler')) {
            $this->render_claim_error(
                __('Purchase Handler Unavailable', 'woo-oneclick'),
                __('The purchase flow could not be initialized. Please contact support.', 'woo-oneclick')
            );
        }

        $handler = new OneClick_Purchase_Handler(false);
        $context = $handler->get_exchange_context($include_user_context);
        $result = $handler->exchange_code_with_backend($claim_code, $context);

        if (is_wp_error($result)) {
            error_log('OneClick Public Link: exchange-code failed — ' . $result->get_error_message());
            return $handler->render_purchase_error_page(
                __('Invalid Purchase Link', 'woo-oneclick'),
                __('This purchase link is invalid or has expired. Please contact support if you need assistance.', 'woo-oneclick')
            );
        }

        if (empty($result['success']) || empty($result['payload'])) {
            error_log('OneClick Public Link: exchange-code returned empty or unsuccessful response');
            return $handler->render_purchase_error_page(
                __('Invalid Purchase Link', 'woo-oneclick'),
                __('Could not verify this purchase window. Please contact support.', 'woo-oneclick')
            );
        }

        $payload = $result['payload'];
        if (($payload['flow'] ?? '') !== 'purchase_session') {
            error_log('OneClick Public Link: public claim did not return purchase_session flow');
            return $handler->render_purchase_error_page(
                __('Invalid Purchase Link', 'woo-oneclick'),
                __('This public purchase link could not open a purchase window.', 'woo-oneclick')
            );
        }

        if ($route_anonymous) {
            $anonymous_destination = sanitize_key($payload['anonymous_destination'] ?? 'checkout');
            if ($anonymous_destination === 'product') {
                return $this->redirect_to_primary_product($payload);
            }

            if (($payload['finalization_mode'] ?? '') === 'checkout' && class_exists('OneClick_Purchase_Session')) {
                error_log('   Anonymous destination=checkout. Plugin naplní Woo cart a presmeruje priamo na checkout bez purchase window.');
                $session_handler = new OneClick_Purchase_Session(false);
                return $session_handler->redirect_to_checkout_for_session_payload($payload);
            }
        }

        return $handler->redirect_to_purchase_session($payload);
    }

    private function redirect_to_primary_product($payload) {
        $items = $payload['items'] ?? [];
        if (empty($items)) {
            $items = $payload['offer_products'] ?? [];
        }

        $primary = null;
        foreach ($items as $item) {
            if (!empty($item['is_primary']) || !empty($item['selected'])) {
                $primary = $item;
                break;
            }
        }
        if (!$primary && !empty($items[0])) {
            $primary = $items[0];
        }

        $product_id = absint($primary['product_id'] ?? 0);
        $product = $product_id ? wc_get_product($product_id) : null;
        if (!$product) {
            $this->render_claim_error(
                __('Product Not Available', 'woo-oneclick'),
                __('The product for this one-click link is no longer available.', 'woo-oneclick')
            );
        }

        $url = get_permalink($product_id);
        error_log(sprintf('   Anonymous destination=product. Plugin presmeruje na primary product #%d: %s', $product_id, $url));
        wp_safe_redirect($url, 302);
        exit;
    }

    private function render_claim_error($title, $message) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html>
<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>' . esc_html($title) . '</title>
    <style>
        body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;background:#f6f7f7;margin:0;padding:24px;min-height:100vh;display:flex;align-items:center;justify-content:center;color:#1d2327}
        .oneclick-error{background:#fff;border:1px solid #dcdcde;border-radius:8px;box-shadow:0 8px 28px rgba(0,0,0,.08);max-width:460px;width:100%;padding:28px;text-align:center}
        h1{font-size:22px;line-height:1.25;margin:0 0 12px}
        p{font-size:15px;line-height:1.5;color:#50575e;margin:0 0 22px}
        a{display:inline-block;background:#2271b1;border-radius:4px;color:#fff;font-size:15px;font-weight:600;padding:12px 20px;text-decoration:none}
    </style>
</head>
<body>
    <main class="oneclick-error">
        <h1>' . esc_html($title) . '</h1>
        <p>' . esc_html($message) . '</p>
        <a href="' . esc_url(home_url('/')) . '">' . esc_html__('Go to Homepage', 'woo-oneclick') . '</a>
    </main>
</body>
</html>';
        exit;
    }

    public function handle_form_actions() {
        if (!isset($_POST['oneclick_public_link_nonce'])) {
            return;
        }
        if (!wp_verify_nonce($_POST['oneclick_public_link_nonce'], 'oneclick_public_link_save')) {
            wp_die(__('Security check failed.', 'woo-oneclick'));
        }
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $submit = sanitize_text_field($_POST['oneclick_public_link_submit'] ?? '');
        $id = absint($_POST['public_link_id'] ?? 0);

        if ($submit === 'delete' && $id) {
            error_log('');
            error_log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            error_log('🔗 PUBLIC LINK FÁZA A: PLUGIN ADMIN → BACKEND (disable/delete)');
            error_log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            error_log(sprintf('   Admin vypína public one-click link id=%d.', $id));
            error_log('   Plugin volá DELETE /api/public-links/{id}; backend iba nastaví status=disabled.');
            $result = OneClick_API_Client::instance()->delete('/api/public-links/' . $id);
            if (is_wp_error($result)) {
                error_log('   ❌ Backend disable zlyhal: ' . $result->get_error_message());
                set_transient('oneclick_public_link_error', $result->get_error_message(), 30);
                wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&error=1'));
                exit;
            }
            error_log('   ✅ Link je disabled. Existujúca shop URL už nevytvorí nový claim/session.');
            error_log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            error_log('');
            wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&deleted=1'));
            exit;
        }

        $data = $this->build_payload_from_post();
        if (is_wp_error($data)) {
            set_transient('oneclick_public_link_error', $data->get_error_message(), 30);
            wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&error=1'));
            exit;
        }

        $api = OneClick_API_Client::instance();
        $action_label = $id ? 'update' : 'create';
        $primary_product = (int) ($_POST['primary_product'] ?? 0);
        error_log('');
        error_log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        error_log('🔗 PUBLIC LINK FÁZA A: ADMIN GENERUJE PUBLIC ONE-CLICK LINK');
        error_log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        error_log(sprintf(
            '   Plugin admin pripravuje %s request | link_id=%d | name="%s" | primary_product=%d | products=%d | status=%s',
            $action_label,
            $id,
            $data['name'] ?? '',
            $primary_product,
            count($data['products'] ?? []),
            $data['status'] ?? 'active'
        ));
        error_log(sprintf(
            '   Discount: %s %s | Produkty obsahujú locked price snapshot + currency + image_url.',
            $data['discount_percent'] ?? 'none',
            $data['discount_type'] ?? ''
        ));
        error_log(sprintf(
            '   Plugin volá backend %s /api/public-links%s s X-License-Key server-side.',
            $id ? 'PUT' : 'POST',
            $id ? '/' . $id : ''
        ));
        $result = $id ? $api->put('/api/public-links/' . $id, $data) : $api->post('/api/public-links', $data);
        if (is_wp_error($result)) {
            error_log('   ❌ Backend public link save zlyhal: ' . $result->get_error_message());
            set_transient('oneclick_public_link_error', $result->get_error_message(), 30);
            wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&error=1'));
            exit;
        }
        $short_id = sanitize_text_field($result['short_id'] ?? '');
        $shop_url = $short_id ? home_url('/oneclick/' . $short_id) : '(missing short_id)';
        error_log(sprintf('   ✅ Backend vrátil short_id=%s. Plugin zobrazí shop URL: %s', $short_id, $shop_url));
        error_log('   short_id nie je JWT ani payload; je to iba backendom evidovaný opaque identifikátor.');
        error_log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        error_log('');

        wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&saved=1'));
        exit;
    }

    private function build_payload_from_post() {
        $primary_product = absint($_POST['primary_product'] ?? 0);
        $additional = array_map('absint', (array) ($_POST['additional_products'] ?? []));
        $product_ids = array_values(array_unique(array_filter(array_merge([$primary_product], $additional))));
        if (empty($primary_product) || empty($product_ids)) {
            return new WP_Error('missing_products', __('Select a primary product.', 'woo-oneclick'));
        }

        $discount_percent = (float) ($_POST['discount_percent'] ?? 0);
        $discount_type = sanitize_text_field($_POST['discount_type'] ?? '%');
        $products = [];
        foreach ($product_ids as $index => $product_id) {
            $product = wc_get_product($product_id);
            if (!$product) {
                continue;
            }
            $price = (float) $product->get_price();
            if ($discount_percent > 0) {
                if ($discount_type === 'fixed') {
                    $price = max(0, $price - $discount_percent);
                } else {
                    $price = max(0, $price * (1 - ($discount_percent / 100)));
                }
            }
            $image_url = '';
            $image_id = $product->get_image_id();
            if ($image_id) {
                $image_url = wp_get_attachment_image_url($image_id, 'woocommerce_thumbnail') ?: '';
            }
            $products[] = [
                'product_id' => $product_id,
                'product_name' => $product->get_name(),
                'price' => round($price, 2),
                'currency' => get_woocommerce_currency(),
                'image_url' => $image_url,
                'is_primary' => $product_id === $primary_product,
            ];
        }

        $anonymous_destination = sanitize_key(wp_unslash($_POST['anonymous_destination'] ?? 'checkout'));
        if (!in_array($anonymous_destination, ['checkout', 'product'], true)) {
            $anonymous_destination = 'checkout';
        }

        return [
            'site_url' => site_url(),
            'name' => sanitize_text_field($_POST['link_name'] ?? ''),
            'products' => $products,
            'discount_percent' => $discount_percent > 0 ? $discount_percent : null,
            'discount_type' => $discount_percent > 0 ? $discount_type : null,
            'anonymous_destination' => $anonymous_destination,
            'status' => sanitize_text_field($_POST['link_status'] ?? 'active'),
            'expires_at' => null,
        ];
    }

    public function render_page() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $view = sanitize_key($_GET['view'] ?? 'list');
        $id = absint($_GET['id'] ?? 0);
        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">' . esc_html__('One-Click Links', 'woo-oneclick') . '</h1>';
        $this->show_notices();
        if ($view === 'new' || $view === 'edit') {
            $this->render_form($id);
        } else {
            $this->render_list();
        }
        echo '</div>';
    }

    private function render_list() {
        $links = OneClick_API_Client::instance()->get('/api/public-links', ['site_url' => site_url()]);
        if (is_wp_error($links)) {
            echo '<div class="notice notice-error"><p>' . esc_html($links->get_error_message()) . '</p></div>';
            $links = [];
        }
        echo '<a href="' . esc_url(admin_url('admin.php?page=' . self::MENU_SLUG . '&view=new')) . '" class="page-title-action">' . esc_html__('Add New', 'woo-oneclick') . '</a>';
        echo '<hr class="wp-header-end">';
        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
        echo '<th>' . esc_html__('Name', 'woo-oneclick') . '</th><th>' . esc_html__('URL', 'woo-oneclick') . '</th><th>' . esc_html__('Products', 'woo-oneclick') . '</th><th>' . esc_html__('Status', 'woo-oneclick') . '</th><th>' . esc_html__('Actions', 'woo-oneclick') . '</th>';
        echo '</tr></thead><tbody>';
        if (empty($links)) {
            echo '<tr><td colspan="5">' . esc_html__('No public one-click links yet.', 'woo-oneclick') . '</td></tr>';
        }
        foreach ($links as $link) {
            $url = home_url('/oneclick/' . ($link['short_id'] ?? ''));
            $edit = admin_url('admin.php?page=' . self::MENU_SLUG . '&view=edit&id=' . absint($link['id'] ?? 0));
            echo '<tr>';
            echo '<td><strong>' . esc_html($link['name'] ?? '') . '</strong></td>';
            echo '<td><input type="text" readonly class="regular-text" value="' . esc_attr($url) . '" onclick="this.select();"></td>';
            echo '<td>' . esc_html(count($link['products'] ?? [])) . '</td>';
            echo '<td>' . esc_html($link['status'] ?? '') . '</td>';
            echo '<td><a class="button button-small" href="' . esc_url($edit) . '">' . esc_html__('Edit', 'woo-oneclick') . '</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private function render_form($id = 0) {
        $link = [];
        if ($id) {
            $result = OneClick_API_Client::instance()->get('/api/public-links/' . $id);
            if (is_wp_error($result)) {
                echo '<div class="notice notice-error"><p>' . esc_html($result->get_error_message()) . '</p></div>';
                return;
            }
            $link = $result;
        }
        $products = $link['products'] ?? [];
        $primary = 0;
        $additional = [];
        foreach ($products as $product) {
            if (!empty($product['is_primary']) && !$primary) {
                $primary = (int) $product['product_id'];
            } else {
                $additional[] = (int) $product['product_id'];
            }
        }
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)); ?>">
            <?php wp_nonce_field('oneclick_public_link_save', 'oneclick_public_link_nonce'); ?>
            <input type="hidden" name="public_link_id" value="<?php echo esc_attr($id); ?>">
            <input type="hidden" name="oneclick_public_link_submit" value="save">
            <table class="form-table">
                <tr>
                    <th><label for="link_name"><?php esc_html_e('Name', 'woo-oneclick'); ?></label></th>
                    <td><input type="text" id="link_name" name="link_name" value="<?php echo esc_attr($link['name'] ?? ''); ?>" class="regular-text" required></td>
                </tr>
                <tr>
                    <th><label for="primary_product"><?php esc_html_e('Primary Product', 'woo-oneclick'); ?></label></th>
                    <td>
                        <select id="primary_product" name="primary_product" class="oneclick-product-select" style="width:400px;" data-placeholder="<?php esc_attr_e('Search product...', 'woo-oneclick'); ?>">
                            <?php if ($primary && ($p = wc_get_product($primary))): ?>
                                <option value="<?php echo esc_attr($primary); ?>" selected><?php echo esc_html($p->get_name()); ?> (#<?php echo esc_html($primary); ?>)</option>
                            <?php endif; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="additional_products"><?php esc_html_e('Additional Products', 'woo-oneclick'); ?></label></th>
                    <td>
                        <select id="additional_products" name="additional_products[]" class="oneclick-product-select" multiple="multiple" style="width:400px;" data-placeholder="<?php esc_attr_e('Search products...', 'woo-oneclick'); ?>">
                            <?php foreach ($additional as $pid): $p = wc_get_product($pid); if ($p): ?>
                                <option value="<?php echo esc_attr($pid); ?>" selected><?php echo esc_html($p->get_name()); ?> (#<?php echo esc_html($pid); ?>)</option>
                            <?php endif; endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="discount_percent"><?php esc_html_e('Discount', 'woo-oneclick'); ?></label></th>
                    <td>
                        <input type="number" id="discount_percent" name="discount_percent" value="<?php echo esc_attr($link['discount_percent'] ?? 0); ?>" min="0" max="100" step="0.01" class="small-text">
                        <select name="discount_type">
                            <option value="%" <?php selected($link['discount_type'] ?? '%', '%'); ?>>%</option>
                            <option value="fixed" <?php selected($link['discount_type'] ?? '', 'fixed'); ?>><?php esc_html_e('Fixed', 'woo-oneclick'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="link_status"><?php esc_html_e('Status', 'woo-oneclick'); ?></label></th>
                    <td>
                        <select id="link_status" name="link_status">
                            <option value="active" <?php selected($link['status'] ?? 'active', 'active'); ?>><?php esc_html_e('Active', 'woo-oneclick'); ?></option>
                            <option value="disabled" <?php selected($link['status'] ?? '', 'disabled'); ?>><?php esc_html_e('Disabled', 'woo-oneclick'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="anonymous_destination"><?php esc_html_e('Anonymous Visitors', 'woo-oneclick'); ?></label></th>
                    <td>
                        <select id="anonymous_destination" name="anonymous_destination">
                            <option value="checkout" <?php selected($link['anonymous_destination'] ?? 'checkout', 'checkout'); ?>><?php esc_html_e('Direct to Checkout', 'woo-oneclick'); ?></option>
                            <option value="product" <?php selected($link['anonymous_destination'] ?? '', 'product'); ?>><?php esc_html_e('Primary Product Page', 'woo-oneclick'); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e('Logged-in customers use the purchase session flow. Anonymous visitors either go straight to checkout or to the primary product page.', 'woo-oneclick'); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button($id ? __('Update Link', 'woo-oneclick') : __('Create Link', 'woo-oneclick')); ?>
        </form>
        <?php if ($id): ?>
            <hr>
            <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)); ?>" onsubmit="return confirm('<?php esc_attr_e('Disable this link?', 'woo-oneclick'); ?>');">
                <?php wp_nonce_field('oneclick_public_link_save', 'oneclick_public_link_nonce'); ?>
                <input type="hidden" name="public_link_id" value="<?php echo esc_attr($id); ?>">
                <input type="hidden" name="oneclick_public_link_submit" value="delete">
                <?php submit_button(__('Disable Link', 'woo-oneclick'), 'delete', 'submit', false); ?>
            </form>
        <?php endif;
    }

    private function show_notices() {
        if (isset($_GET['saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('One-click link saved.', 'woo-oneclick') . '</p></div>';
        }
        if (isset($_GET['deleted'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('One-click link disabled.', 'woo-oneclick') . '</p></div>';
        }
        if (isset($_GET['error'])) {
            $error = get_transient('oneclick_public_link_error');
            if ($error) {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($error) . '</p></div>';
                delete_transient('oneclick_public_link_error');
            }
        }
    }
}
