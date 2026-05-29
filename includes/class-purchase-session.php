<?php
/**
 * Timed Purchase Session
 *
 * Renders the window-shopping session page, proxies browser actions to backend,
 * and finalizes due sessions from Action Scheduler.
 *
 * @package WooOneClick
 */

if (!defined('ABSPATH')) {
    exit;
}

class OneClick_Purchase_Session {

    const WORKER_HOOK = 'oneclick_process_due_purchase_sessions';

    public function __construct($register_hooks = true) {
        if (!$register_hooks) {
            return;
        }

        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('init', [$this, 'schedule_worker']);
        add_action(self::WORKER_HOOK, [$this, 'process_due_sessions']);
    }

    public static function cookie_name($session_id) {
        return 'oneclick_session_' . md5((string) $session_id);
    }

    public static function set_session_cookie($session_id, $access_token) {
        $args = [
            'expires'  => time() + DAY_IN_SECONDS * 2,
            'path'     => COOKIEPATH ?: '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        if (!empty(COOKIE_DOMAIN)) {
            $args['domain'] = COOKIE_DOMAIN;
        }

        setcookie(self::cookie_name($session_id), $access_token, $args);
        $_COOKIE[self::cookie_name($session_id)] = $access_token;
    }

    public function register_routes() {
        register_rest_route('oneclick/v1', '/session', [
            'methods' => 'GET',
            'callback' => [$this, 'render_session_page'],
            'permission_callback' => '__return_true',
            'args' => [
                'session_id' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        foreach (['status', 'add-item', 'update-quantity', 'remove-item', 'extend', 'cancel', 'checkout'] as $action) {
            register_rest_route('oneclick/v1', '/session/' . $action, [
                'methods' => 'POST',
                'callback' => [$this, 'handle_session_action'],
                'permission_callback' => '__return_true',
            ]);
        }
    }

    public function schedule_worker() {
        if (function_exists('as_next_scheduled_action') && function_exists('as_schedule_recurring_action')) {
            if (!as_next_scheduled_action(self::WORKER_HOOK, [], 'oneclick')) {
                as_schedule_recurring_action(time() + 60, 60, self::WORKER_HOOK, [], 'oneclick');
            }
            return;
        }

        if (!wp_next_scheduled(self::WORKER_HOOK)) {
            wp_schedule_event(time() + 60, 'oneclick_minutely', self::WORKER_HOOK);
        }
    }

    public function render_session_page($request) {
        $session_id = $request->get_param('session_id');
        $access_token = $this->get_access_token_for_session($session_id);
        if (empty($access_token)) {
            return $this->render_error_page(__('Session Unavailable', 'woo-oneclick'), __('This purchase session cannot be opened on this device. Please click the email link again.', 'woo-oneclick'));
        }

        $state = $this->backend_session_post('/api/purchase-sessions/status', [
            'site_url' => site_url(),
            'session_id' => $session_id,
            'access_token' => $access_token,
        ]);

        if (is_wp_error($state) || empty($state['session'])) {
            return $this->render_error_page(__('Session Expired', 'woo-oneclick'), __('This purchase session is no longer available.', 'woo-oneclick'));
        }

        $public_state = $this->public_session_state($state['session']);
        $json_state = wp_json_encode($public_state);
        $rest_base = esc_url_raw(rest_url('oneclick/v1/session/'));
        $branding = $this->get_session_branding();
        $brand_name = !empty($branding['company_name']) ? $branding['company_name'] : get_bloginfo('name');
        $logo_url = !empty($branding['logo_url']) ? esc_url($branding['logo_url']) : '';

        header('Content-Type: text/html; charset=utf-8');
        ?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo esc_html__('Complete Your Purchase', 'woo-oneclick'); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        :root {
            <?php echo $this->build_session_brand_css($branding); ?>
        }
        * { box-sizing: border-box; }
        html { min-height: 100%; background: var(--oc-bg); }
        body {
            min-height: 100%;
            margin: 0;
            font-family: var(--oc-font);
            background: var(--oc-bg);
            color: var(--oc-text);
            -webkit-font-smoothing: antialiased;
            text-rendering: optimizeLegibility;
        }
        button, input { font: inherit; }
        button { cursor: pointer; }
        button:disabled { cursor: not-allowed; opacity: .55; }
        .oc-topbar {
            background: var(--oc-topbar);
            border-bottom: 1px solid var(--oc-outline-soft);
        }
        .oc-shell {
            width: min(100%, 1280px);
            margin: 0 auto;
            padding: 24px;
        }
        .oc-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
        }
        .oc-brand {
            display: flex;
            align-items: center;
            gap: 16px;
            min-width: 0;
        }
        .oc-brand-mark {
            width: 48px;
            height: 48px;
            flex: 0 0 48px;
            border-radius: 16px;
            border: 1px solid var(--oc-outline-soft);
            background: var(--oc-surface-soft);
            color: var(--oc-primary);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        .oc-brand-mark img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            padding: 6px;
            background: var(--oc-surface);
        }
        .oc-brand h1 {
            margin: 0;
            color: var(--oc-heading);
            font-size: 24px;
            line-height: 1.25;
            font-weight: 750;
            letter-spacing: 0;
        }
        .oc-brand p {
            margin: 4px 0 0;
            color: var(--oc-muted);
            font-size: 14px;
            line-height: 1.45;
            font-weight: 500;
        }
        .oc-status-chip {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 10px 16px;
            border-radius: 999px;
            background: var(--oc-chip);
            color: var(--oc-primary);
            font-size: 13px;
            line-height: 1;
            font-weight: 700;
            white-space: nowrap;
        }
        .oc-pulse {
            width: 10px;
            height: 10px;
            border-radius: 999px;
            background: currentColor;
            box-shadow: 0 0 0 5px color-mix(in srgb, var(--oc-primary) 12%, transparent);
        }
        .oc-main {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 380px;
            gap: 24px;
            align-items: start;
        }
        .oc-panel {
            background: var(--oc-surface);
            border: 1px solid var(--oc-outline-soft);
            border-radius: 24px;
            box-shadow: var(--oc-shadow);
        }
        .oc-products-panel { padding: 28px 24px; }
        .oc-panel-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 24px;
        }
        .oc-panel-head h2,
        .oc-order h2 {
            margin: 0;
            color: var(--oc-heading);
            font-size: 18px;
            line-height: 1.3;
            font-weight: 750;
            letter-spacing: 0;
        }
        .oc-panel-head p {
            margin: 6px 0 0;
            color: var(--oc-muted);
            font-size: 14px;
            line-height: 1.45;
            font-weight: 500;
        }
        .oc-count-pill {
            flex: 0 0 auto;
            min-width: 46px;
            min-height: 36px;
            padding: 9px 13px;
            border-radius: 999px;
            background: var(--oc-surface-soft);
            color: var(--oc-primary);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 800;
        }
        .oc-products {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            gap: 20px;
        }
        .oc-product {
            position: relative;
            overflow: hidden;
            min-height: 100%;
            border: 1px solid var(--oc-outline-soft);
            border-radius: 18px;
            background: var(--oc-surface);
            display: flex;
            flex-direction: column;
            transition: border-color .16s ease, transform .16s ease, box-shadow .16s ease;
        }
        .oc-product.is-selected {
            border: 1.5px solid var(--oc-primary);
        }
        .oc-product:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 26px rgba(20, 27, 43, .06);
        }
        .oc-product-media {
            position: relative;
            width: 100%;
            aspect-ratio: 1.45 / 1;
            background: var(--oc-surface-soft);
            overflow: hidden;
        }
        .oc-product-media img {
            width: 100%;
            height: 100%;
            display: block;
            object-fit: cover;
        }
        .oc-product-placeholder,
        .oc-thumb-placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--oc-primary);
            background: var(--oc-surface-soft);
        }
        .oc-added-badge {
            position: absolute;
            top: 14px;
            left: 14px;
            z-index: 2;
            display: none;
            align-items: center;
            gap: 6px;
            padding: 8px 12px;
            border-radius: 999px;
            background: var(--oc-inverse);
            color: var(--oc-inverse-text);
            font-size: 12px;
            font-weight: 700;
        }
        .oc-product.is-selected .oc-added-badge { display: inline-flex; }
        .oc-product-body {
            padding: 16px;
            display: flex;
            flex: 1 1 auto;
            flex-direction: column;
            gap: 14px;
        }
        .oc-product-title {
            margin: 0;
            min-height: 42px;
            color: var(--oc-heading);
            font-size: 15px;
            line-height: 1.4;
            font-weight: 700;
        }
        .oc-product-foot {
            margin-top: auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .oc-price {
            color: var(--oc-primary);
            font-size: 16px;
            line-height: 1.2;
            font-weight: 800;
            white-space: nowrap;
        }
        .oc-add {
            border: 1px solid var(--oc-outline-soft);
            background: var(--oc-surface);
            color: var(--oc-heading);
            border-radius: 999px;
            padding: 8px 12px;
            min-height: 36px;
            font-size: 13px;
            font-weight: 750;
        }
        .oc-add:not(:disabled):hover {
            border-color: var(--oc-primary);
            color: var(--oc-primary);
        }
        .oc-sidebar {
            position: sticky;
            top: 24px;
            display: flex;
            flex-direction: column;
            gap: 18px;
        }
        .oc-order {
            padding: 24px;
        }
        .oc-order-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 22px;
        }
        .oc-order-icon {
            width: 38px;
            height: 38px;
            border-radius: 999px;
            background: var(--oc-chip);
            color: var(--oc-primary);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 800;
        }
        .oc-items {
            display: flex;
            flex-direction: column;
            gap: 16px;
            max-height: 330px;
            overflow: auto;
            padding-right: 3px;
            margin-bottom: 20px;
        }
        .oc-line {
            display: grid;
            grid-template-columns: 58px minmax(0, 1fr);
            gap: 14px;
            align-items: start;
        }
        .oc-thumb {
            width: 58px;
            height: 58px;
            border-radius: 10px;
            border: 1px solid var(--oc-outline-soft);
            background: var(--oc-surface-soft);
            overflow: hidden;
        }
        .oc-thumb img {
            width: 100%;
            height: 100%;
            display: block;
            object-fit: cover;
        }
        .oc-line-title {
            margin: 0;
            color: var(--oc-heading);
            font-size: 14px;
            line-height: 1.35;
            font-weight: 750;
        }
        .oc-line-meta {
            margin-top: 4px;
            color: var(--oc-muted);
            font-size: 12px;
            line-height: 1.35;
        }
        .oc-line-controls {
            margin-top: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .oc-qty {
            display: inline-flex;
            align-items: center;
            height: 34px;
            overflow: hidden;
            border-radius: 999px;
            border: 1px solid var(--oc-outline-soft);
            background: var(--oc-surface-soft);
        }
        .oc-qty button {
            width: 34px;
            height: 34px;
            border: 0;
            background: transparent;
            color: var(--oc-heading);
            font-size: 18px;
            line-height: 1;
        }
        .oc-qty strong {
            min-width: 28px;
            text-align: center;
            font-size: 13px;
        }
        .oc-remove {
            width: 34px;
            height: 34px;
            border-radius: 999px;
            border: 1px solid var(--oc-outline-soft);
            background: var(--oc-surface);
            color: var(--oc-danger);
            font-size: 16px;
            line-height: 1;
        }
        .oc-total-box {
            border-radius: 16px;
            background: var(--oc-surface-soft);
            padding: 18px 20px;
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 14px;
            margin: 14px 0 18px;
        }
        .oc-total-label {
            color: var(--oc-heading);
            font-size: 14px;
            font-weight: 700;
        }
        .oc-total {
            margin-top: 6px;
            color: var(--oc-heading);
            font-size: 28px;
            line-height: 1;
            font-weight: 800;
            white-space: nowrap;
        }
        .oc-total-note {
            color: var(--oc-muted);
            font-size: 12px;
            text-align: right;
        }
        .oc-actions {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .oc-action-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            border: 1px solid var(--oc-outline-soft);
            border-radius: 16px;
            padding: 15px;
            background: var(--oc-surface);
        }
        .oc-action-row span {
            display: block;
            color: var(--oc-heading);
            font-size: 14px;
            font-weight: 750;
        }
        .oc-action-row small {
            display: block;
            margin-top: 3px;
            color: var(--oc-muted);
            font-size: 12px;
        }
        .oc-mini-button {
            border: 1px solid var(--oc-outline-soft);
            background: var(--oc-surface-soft);
            color: var(--oc-primary);
            border-radius: 999px;
            min-width: 42px;
            min-height: 34px;
            padding: 7px 12px;
            font-size: 13px;
            font-weight: 800;
        }
        .oc-primary {
            width: 100%;
            min-height: 56px;
            border: 0;
            border-radius: var(--oc-button-radius);
            background: var(--oc-button);
            color: var(--oc-button-text);
            font-size: 15px;
            font-weight: 800;
            box-shadow: 0 12px 26px color-mix(in srgb, var(--oc-button) 25%, transparent);
        }
        .oc-secondary-danger {
            width: 100%;
            min-height: 52px;
            border-radius: var(--oc-button-radius);
            border: 1px solid var(--oc-outline-soft);
            background: var(--oc-surface);
            color: var(--oc-danger);
            font-size: 14px;
            font-weight: 800;
        }
        .oc-trust {
            padding: 18px 20px;
        }
        .oc-trust-line {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            color: var(--oc-muted);
            font-size: 12px;
            line-height: 1.35;
        }
        .oc-trust-line + .oc-trust-line { margin-top: 12px; }
        .oc-trust-dot {
            width: 18px;
            height: 18px;
            border-radius: 999px;
            border: 2px solid var(--oc-primary);
            flex: 0 0 18px;
        }
        .oc-empty {
            border: 1px dashed var(--oc-outline);
            border-radius: 18px;
            min-height: 220px;
            display: none;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: var(--oc-muted);
            background: var(--oc-surface-soft);
            padding: 24px;
        }
        .oc-footer {
            border-top: 1px solid var(--oc-outline-soft);
            color: var(--oc-muted);
            font-size: 12px;
            line-height: 1.4;
            padding: 22px 0 10px;
            margin-top: 16px;
        }
        @media (max-width: 960px) {
            .oc-main { grid-template-columns: 1fr; }
            .oc-sidebar { position: static; }
        }
        @media (max-width: 640px) {
            .oc-shell { padding: 18px 16px; }
            .oc-header,
            .oc-panel-head {
                align-items: stretch;
                flex-direction: column;
            }
            .oc-status-chip { justify-content: center; width: 100%; }
            .oc-products-panel,
            .oc-order { padding: 20px 16px; }
            .oc-products { grid-template-columns: 1fr; }
            .oc-total-box { align-items: flex-start; flex-direction: column; }
            .oc-total-note { text-align: left; }
        }
    </style>
</head>
<body>
    <header class="oc-topbar">
        <div class="oc-shell oc-header">
            <div class="oc-brand">
                <div class="oc-brand-mark" aria-hidden="true">
                    <?php if ($logo_url): ?>
                        <img src="<?php echo $logo_url; ?>" alt="">
                    <?php else: ?>
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M7 8h10l-.8 11H7.8L7 8Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                            <path d="M9 8a3 3 0 0 1 6 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                        </svg>
                    <?php endif; ?>
                </div>
                <div>
                    <h1><?php echo esc_html__('Purchase Window', 'woo-oneclick'); ?></h1>
                    <p><?php echo esc_html__('Add products, then continue to checkout.', 'woo-oneclick'); ?></p>
                </div>
            </div>
            <div class="oc-status-chip" aria-live="polite">
                <span class="oc-pulse" aria-hidden="true"></span>
                <span id="oc-timer">--:--</span>
            </div>
        </div>
    </header>

    <main class="oc-shell">
        <div class="oc-main">
            <section class="oc-panel oc-products-panel">
                <div class="oc-panel-head">
                    <div>
                        <h2><?php echo esc_html__('Products from your offer', 'woo-oneclick'); ?></h2>
                        <p id="oc-status"></p>
                    </div>
                    <div class="oc-count-pill" id="oc-product-count">0</div>
                </div>
                <div class="oc-products" id="oc-products"></div>
                <div class="oc-empty" id="oc-products-empty">
                    <div><?php echo esc_html__('No additional products are available in this window.', 'woo-oneclick'); ?></div>
                </div>
                <div class="oc-footer">
                    <?php echo esc_html__('Secure checkout. Your session is protected and controlled by the store backend.', 'woo-oneclick'); ?>
                </div>
            </section>

            <aside class="oc-sidebar">
                <section class="oc-panel oc-order">
                    <div class="oc-order-head">
                        <h2><?php echo esc_html__('Your order', 'woo-oneclick'); ?></h2>
                        <div class="oc-order-icon" id="oc-item-count">0</div>
                    </div>
                    <div class="oc-items" id="oc-items"></div>
                    <div class="oc-total-box">
                        <div>
                            <div class="oc-total-label"><?php echo esc_html__('Total', 'woo-oneclick'); ?></div>
                            <div class="oc-total" id="oc-total">--</div>
                        </div>
                        <div class="oc-total-note"><?php echo esc_html__('Includes VAT if applicable.', 'woo-oneclick'); ?></div>
                    </div>
                    <div class="oc-actions">
                        <div class="oc-action-row">
                            <div>
                                <span><?php echo esc_html__('Add 5 minutes', 'woo-oneclick'); ?></span>
                                <small><?php echo esc_html__('Extend this purchase window', 'woo-oneclick'); ?></small>
                            </div>
                            <button type="button" class="oc-mini-button" id="oc-extend">+5</button>
                        </div>
                        <button type="button" class="oc-primary" id="oc-checkout"><?php echo esc_html__('Continue to Checkout', 'woo-oneclick'); ?></button>
                        <button type="button" class="oc-secondary-danger" id="oc-cancel"><?php echo esc_html__('Cancel order', 'woo-oneclick'); ?></button>
                    </div>
                </section>

                <section class="oc-panel oc-trust">
                    <div class="oc-trust-line"><span class="oc-trust-dot" aria-hidden="true"></span><div><strong><?php echo esc_html__('Protected session', 'woo-oneclick'); ?></strong><br><?php echo esc_html__('The browser never receives license keys or raw backend payloads.', 'woo-oneclick'); ?></div></div>
                    <div class="oc-trust-line"><span class="oc-trust-dot" aria-hidden="true"></span><div><strong><?php echo esc_html($brand_name); ?></strong><br><?php echo esc_html__('Order details stay synchronized with WooCommerce.', 'woo-oneclick'); ?></div></div>
                </section>
            </aside>
        </div>
    </main>

    <script>
    window.oneclickSession = { state: <?php echo $json_state; ?>, restBase: "<?php echo esc_js($rest_base); ?>" };
    </script>
    <script>
    (function() {
        var state = window.oneclickSession.state;
        var restBase = window.oneclickSession.restBase;
        var countdownSyncedAt = Date.now();
        var countdownBaseSeconds = Number(state.remaining_seconds || 0);
        var timer = document.getElementById("oc-timer");
        var status = document.getElementById("oc-status");
        var products = document.getElementById("oc-products");
        var productsEmpty = document.getElementById("oc-products-empty");
        var items = document.getElementById("oc-items");
        var total = document.getElementById("oc-total");
        var productCount = document.getElementById("oc-product-count");
        var itemCount = document.getElementById("oc-item-count");
        var extend = document.getElementById("oc-extend");
        var checkout = document.getElementById("oc-checkout");
        var cancel = document.getElementById("oc-cancel");
        var checkoutRedirectStarted = false;
        var checkoutAutoAttempted = false;
        var checkoutRedirectFailed = false;

        function money(value, currency) {
            return new Intl.NumberFormat(undefined, { style: "currency", currency: currency || "EUR" }).format(Number(value || 0));
        }
        function currentRemaining() {
            var elapsed = Math.floor((Date.now() - countdownSyncedAt) / 1000);
            return Math.max(0, countdownBaseSeconds - elapsed);
        }
        function syncCountdown(nextState) {
            state = nextState;
            countdownSyncedAt = Date.now();
            countdownBaseSeconds = Math.max(0, Number(state.remaining_seconds || 0));
        }
        function post(action, body) {
            body = body || {};
            body.session_id = state.session_id;
            return fetch(restBase + action, {
                method: "POST",
                credentials: "same-origin",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(body)
            }).then(function(response) { return response.json(); }).then(function(data) {
                if (data && data.session) {
                    syncCountdown(data.session);
                    render();
                }
                return data;
            });
        }
        function iconSvg(name) {
            var paths = {
                check: "<path d=\"M5 12.5l4 4L19 6\"/>",
                image: "<path d=\"M4 6h16v12H4z\"/><path d=\"M7 15l3-3 2 2 3-4 2 5\"/><path d=\"M8 9h.01\"/>"
            };
            return "<svg width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" aria-hidden=\"true\"><g stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\">" + (paths[name] || paths.check) + "</g></svg>";
        }
        function setImage(container, src, alt) {
            container.innerHTML = "";
            if (!src) {
                var placeholder = document.createElement("div");
                placeholder.className = container.className.indexOf("oc-thumb") !== -1 ? "oc-thumb-placeholder" : "oc-product-placeholder";
                placeholder.innerHTML = iconSvg("image");
                container.appendChild(placeholder);
                return;
            }
            var img = document.createElement("img");
            img.alt = alt || "";
            img.src = src;
            img.onerror = function() {
                setImage(container, "", alt);
            };
            container.appendChild(img);
        }
        function findSelectedItem(product) {
            var selected = null;
            (state.items || []).forEach(function(item) {
                if (String(item.product_id) === String(product.product_id)) {
                    selected = item;
                }
            });
            return selected;
        }
        function redirectToCheckout(isAuto) {
            if (checkoutRedirectStarted || state.status !== "active" || !(state.items || []).length) {
                return;
            }
            checkoutRedirectStarted = true;
            checkoutRedirectFailed = false;
            checkout.disabled = true;
            checkout.textContent = "Redirecting...";
            status.textContent = isAuto ? "Redirecting to checkout..." : status.textContent;
            post("checkout").then(function(data) {
                if (data && data.checkout_url) {
                    window.location.href = data.checkout_url;
                    return;
                }
                checkoutRedirectStarted = false;
                checkoutRedirectFailed = true;
                checkout.disabled = false;
                checkout.textContent = "Continue to Checkout";
                status.textContent = "Could not redirect automatically. Please click Continue to Checkout.";
            }).catch(function() {
                checkoutRedirectStarted = false;
                checkoutRedirectFailed = true;
                checkout.disabled = false;
                checkout.textContent = "Continue to Checkout";
                status.textContent = "Could not redirect automatically. Please click Continue to Checkout.";
            });
        }
        function tick() {
            if (state.status !== "active") {
                timer.textContent = state.status.toUpperCase();
                return;
            }
            var remaining = currentRemaining();
            if (remaining === 0) {
                if (state.finalization_mode === "checkout") {
                    timer.textContent = "Checkout";
                    if (!checkoutAutoAttempted) {
                        checkoutAutoAttempted = true;
                        redirectToCheckout(true);
                    }
                    return;
                }
                timer.textContent = "Finalizing...";
                return;
            }
            var m = String(Math.floor(remaining / 60)).padStart(2, "0");
            var s = String(remaining % 60).padStart(2, "0");
            timer.textContent = m + ":" + s;
        }
        function render() {
            tick();
            products.innerHTML = "";
            items.innerHTML = "";
            if (state.status === "finalized") {
                status.textContent = "Order created. Order #" + (state.order_id || "");
            } else if (state.status === "cancelled") {
                status.textContent = "This purchase window was cancelled.";
            } else if (state.status === "failed") {
                status.textContent = "Finalization failed: " + (state.error_message || "unknown error");
            } else if (state.status === "finalizing") {
                status.textContent = "Your order is being finalized.";
            } else if (checkoutRedirectStarted) {
                status.textContent = "Redirecting to checkout...";
            } else if (checkoutRedirectFailed) {
                status.textContent = "Could not redirect automatically. Please click Continue to Checkout.";
            } else if (state.finalization_mode === "checkout") {
                status.textContent = "Add products, then continue to checkout.";
            } else {
                status.textContent = "You can add products until the timer expires.";
            }

            (state.offer_products || []).forEach(function(product) {
                var card = document.createElement("div");
                var selectedItem = findSelectedItem(product);
                card.className = "oc-product" + (selectedItem ? " is-selected" : "");

                var badge = document.createElement("div");
                badge.className = "oc-added-badge";
                badge.innerHTML = iconSvg("check") + " Added";
                card.appendChild(badge);

                var media = document.createElement("div");
                media.className = "oc-product-media";
                setImage(media, product.image_url, product.product_name);
                card.appendChild(media);

                var body = document.createElement("div");
                body.className = "oc-product-body";

                var title = document.createElement("h3");
                title.className = "oc-product-title";
                title.textContent = product.product_name;
                body.appendChild(title);

                var foot = document.createElement("div");
                foot.className = "oc-product-foot";

                var price = document.createElement("div");
                price.className = "oc-price";
                price.textContent = money(product.price, product.currency);
                foot.appendChild(price);

                var button = document.createElement("button");
                button.type = "button";
                button.className = "oc-add";
                button.textContent = selectedItem ? "Added" : "Add";
                button.disabled = !!selectedItem || product.selected || state.status !== "active";
                button.addEventListener("click", function() { post("add-item", { offer_item_id: product.offer_item_id }); });
                foot.appendChild(button);

                body.appendChild(foot);
                card.appendChild(body);
                products.appendChild(card);
            });
            productsEmpty.style.display = (state.offer_products || []).length ? "none" : "flex";
            productCount.textContent = String((state.offer_products || []).length);

            (state.items || []).forEach(function(item) {
                var line = document.createElement("div");
                line.className = "oc-line";

                var thumb = document.createElement("div");
                thumb.className = "oc-thumb";
                setImage(thumb, item.image_url, item.product_name);
                line.appendChild(thumb);

                var detail = document.createElement("div");
                var title = document.createElement("h4");
                title.className = "oc-line-title";
                title.textContent = item.product_name;
                detail.appendChild(title);

                var meta = document.createElement("div");
                meta.className = "oc-line-meta";
                meta.textContent = money(item.price, item.currency) + " each";
                detail.appendChild(meta);

                var controlsWrap = document.createElement("div");
                controlsWrap.className = "oc-line-controls";
                var controls = "<span class=\"oc-qty\"><button type=\"button\" data-dec>-</button><strong>" + item.quantity + "</strong><button type=\"button\" data-inc>+</button></span>";
                if (item.can_remove) {
                    controls += " <button type=\"button\" class=\"oc-remove\" title=\"Remove\" data-remove>&times;</button>";
                }
                controlsWrap.innerHTML = controls;
                detail.appendChild(controlsWrap);
                line.appendChild(detail);

                var dec = line.querySelector("[data-dec]");
                var inc = line.querySelector("[data-inc]");
                dec.disabled = item.quantity <= 1 || state.status !== "active";
                inc.disabled = state.status !== "active";
                dec.addEventListener("click", function() { post("update-quantity", { product_id: item.product_id, quantity: item.quantity - 1 }); });
                inc.addEventListener("click", function() { post("update-quantity", { product_id: item.product_id, quantity: item.quantity + 1 }); });
                var remove = line.querySelector("[data-remove]");
                if (remove) {
                    remove.disabled = state.status !== "active";
                    remove.addEventListener("click", function() { post("remove-item", { product_id: item.product_id }); });
                }
                items.appendChild(line);
            });

            total.textContent = money(state.total, state.currency);
            itemCount.textContent = String((state.items || []).length);
            extend.disabled = state.status !== "active";
            cancel.disabled = state.status !== "active";
            checkout.style.display = state.finalization_mode === "checkout" && state.status === "active" ? "" : "none";
            checkout.disabled = checkoutRedirectStarted || state.status !== "active" || !(state.items || []).length;
        }
        extend.addEventListener("click", function() { post("extend"); });
        checkout.addEventListener("click", function() { redirectToCheckout(false); });
        cancel.addEventListener("click", function() { if (confirm("Cancel this purchase window?")) { post("cancel"); } });
        setInterval(tick, 1000);
        setInterval(function() { post("status"); }, 5000);
        render();
    })();
    </script>
</body>
</html>
        <?php
        exit;
    }

    public function handle_session_action($request) {
        $route = $request->get_route();
        $action = basename($route);
        $session_id = sanitize_text_field($request->get_param('session_id'));
        $access_token = $this->get_access_token_for_session($session_id);
        if (empty($session_id) || empty($access_token)) {
            return new WP_Error('oneclick_session_denied', 'Session access denied', ['status' => 403]);
        }

        $body = [
            'site_url' => site_url(),
            'session_id' => $session_id,
            'access_token' => $access_token,
        ];
        if ($action === 'checkout') {
            return $this->handle_checkout_redirect($body);
        }
        $endpoint = '/api/purchase-sessions/status';

        if ($action === 'add-item') {
            $endpoint = '/api/purchase-sessions/add-item';
            $body['offer_item_id'] = absint($request->get_param('offer_item_id'));
        } elseif ($action === 'update-quantity') {
            $endpoint = '/api/purchase-sessions/update-quantity';
            $body['product_id'] = absint($request->get_param('product_id'));
            $body['quantity'] = max(1, min(99, absint($request->get_param('quantity'))));
        } elseif ($action === 'remove-item') {
            $endpoint = '/api/purchase-sessions/remove-item';
            $body['product_id'] = absint($request->get_param('product_id'));
        } elseif ($action === 'extend') {
            $endpoint = '/api/purchase-sessions/extend';
        } elseif ($action === 'cancel') {
            $endpoint = '/api/purchase-sessions/cancel';
        }

        $result = $this->backend_session_post($endpoint, $body);
        if (is_wp_error($result)) {
            return $result;
        }

        if (!empty($result['session'])) {
            $result['session'] = $this->public_session_state($result['session']);
        }
        return rest_ensure_response($result);
    }

    public function process_due_sessions() {
        error_log('');
        error_log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        error_log('⏲️ SESSION FLOW: ACTION SCHEDULER → FINALIZÁCIA PURCHASE SESSIONS');
        error_log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $result = $this->backend_session_post('/api/purchase-sessions/claim-due', [
            'site_url' => site_url(),
            'limit' => 5,
        ], ['timeout' => 60]);

        if (is_wp_error($result)) {
            error_log('   ❌ Claim due sessions zlyhal: ' . $result->get_error_message());
            return;
        }

        $sessions = $result['sessions'] ?? [];
        error_log(sprintf('   Backend vrátil due sessions: %d', count($sessions)));
        foreach ($sessions as $session) {
            $this->finalize_session($session);
        }
        error_log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        error_log('');
    }

    private function handle_checkout_redirect($body) {
        $result = $this->backend_session_post('/api/purchase-sessions/status', $body);
        if (is_wp_error($result)) {
            return $result;
        }
        $session = $result['session'] ?? [];
        if (($session['finalization_mode'] ?? '') !== 'checkout') {
            return new WP_Error('oneclick_session_not_checkout', 'This session is not a checkout fallback session', ['status' => 400]);
        }

        $cart_ready = $this->ensure_cart_available();
        if (is_wp_error($cart_ready)) {
            return $cart_ready;
        }

        $session_id = sanitize_text_field($session['session_id'] ?? '');
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            if (!empty($cart_item['oneclick_session_id']) && $cart_item['oneclick_session_id'] === $session_id) {
                WC()->cart->remove_cart_item($cart_item_key);
            }
        }

        foreach (($session['items'] ?? []) as $item) {
            $product_id = (int) ($item['product_id'] ?? 0);
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $price = (float) ($item['price'] ?? 0);
            $product = wc_get_product($product_id);
            if (!$product || !$product->is_purchasable()) {
                return new WP_Error('oneclick_invalid_checkout_product', 'Product is not purchasable', ['status' => 400]);
            }

            $add_product_id = $product_id;
            $variation_id = 0;
            $variation = [];
            if ($product->is_type('variation')) {
                $variation_id = $product_id;
                $add_product_id = $product->get_parent_id();
                $variation = $product->get_variation_attributes();
            }

            WC()->cart->add_to_cart($add_product_id, $quantity, $variation_id, $variation, [
                'oneclick_purchase' => 'yes',
                'oneclick_price' => $price,
                'oneclick_session_id' => $session_id,
                'oneclick_public_link' => ($session['source_type'] ?? '') === 'public_link' ? 'yes' : 'no',
                'oneclick_unique_key' => wp_hash($product_id . '|' . $price . '|' . microtime(true)),
            ]);
        }

        WC()->cart->calculate_totals();
        error_log(sprintf(
            '🛒 PUBLIC LINK CHECKOUT FALLBACK | Session %s pridaná do košíka, items=%d',
            $session['session_id'] ?? '',
            count($session['items'] ?? [])
        ));

        return rest_ensure_response([
            'success' => true,
            'checkout_url' => wc_get_checkout_url(),
        ]);
    }

    public function redirect_to_checkout_for_session_payload($payload) {
        $session_id = sanitize_text_field($payload['session_id'] ?? '');
        $access_token = sanitize_text_field($payload['access_token'] ?? '');

        if (empty($session_id) || empty($access_token)) {
            return $this->render_error_page(
                __('Invalid Session', 'woo-oneclick'),
                __('Could not prepare checkout for this one-click link.', 'woo-oneclick')
            );
        }

        if (($payload['finalization_mode'] ?? '') !== 'checkout') {
            return $this->render_error_page(
                __('Checkout Unavailable', 'woo-oneclick'),
                __('This one-click link is not a checkout fallback session.', 'woo-oneclick')
            );
        }

        self::set_session_cookie($session_id, $access_token);
        $result = $this->handle_checkout_redirect([
            'site_url' => site_url(),
            'session_id' => $session_id,
            'access_token' => $access_token,
        ]);

        if (is_wp_error($result)) {
            return $this->render_error_page(
                __('Checkout Unavailable', 'woo-oneclick'),
                $result->get_error_message()
            );
        }

        $data = $result instanceof WP_REST_Response ? $result->get_data() : $result;
        $checkout_url = esc_url_raw($data['checkout_url'] ?? '');
        if (empty($checkout_url)) {
            return $this->render_error_page(
                __('Checkout Unavailable', 'woo-oneclick'),
                __('We could not prepare the checkout redirect. Please contact support.', 'woo-oneclick')
            );
        }

        error_log(sprintf('   ✅ Direct checkout pripravený pre session %s. Redirect: %s', $session_id, $checkout_url));
        wp_safe_redirect($checkout_url, 302);
        exit;
    }

    private function ensure_cart_available() {
        if (!function_exists('WC')) {
            return new WP_Error('woocommerce_unavailable', 'WooCommerce is unavailable');
        }

        if ((null === WC()->session || null === WC()->cart) && function_exists('wc_load_cart')) {
            wc_load_cart();
        }

        if (null === WC()->cart) {
            return new WP_Error('cart_unavailable', 'WooCommerce cart is unavailable');
        }

        return true;
    }

    private function finalize_session($session) {
        $session_id = $session['session_id'] ?? '';
        $user_id = (int) ($session['user_id'] ?? 0);
        $items = $session['items'] ?? [];
        $currency = $session['currency'] ?? get_woocommerce_currency();
        $total = (float) ($session['total'] ?? 0);
        $payment_method = $this->resolve_payment_method($session);
        $payment_intent_id = '';

        error_log(sprintf('   ▶️ Finalizujem session %s | items=%d | total=%s %s | method=%s', $session_id, count($items), $total, $currency, $payment_method));

        foreach ($items as $item) {
            $product = wc_get_product((int) $item['product_id']);
            if (!$product || !$product->is_purchasable() || !$product->is_in_stock()) {
                $this->report_session_result($session, false, 0, '', 'Product unavailable: ' . ($item['product_id'] ?? 'unknown'));
                return;
            }
        }

        if ($payment_method === 'stripe') {
            $stripe = new OneClick_Stripe();
            $charge = $stripe->charge_saved_payment_method(
                $user_id,
                $total,
                $currency,
                [
                    'oneclick_session_id' => $session_id,
                    'oneclick_offer_id' => $session['offer_id'] ?? '',
                    'original_order_id' => $session['source_order_id'] ?? '',
                ],
                $session['idempotency_key'] ?? null
            );

            if (empty($charge['success'])) {
                $error = $charge['error'] ?? 'Stripe charge failed';
                error_log(sprintf('   ❌ Stripe combined MIT zlyhal pre session %s: %s', $session_id, $error));
                $this->report_session_result($session, false, 0, $charge['payment_intent_id'] ?? '', $error);
                return;
            }

            $payment_intent_id = $charge['payment_intent_id'];
            error_log(sprintf('   ✅ Stripe combined MIT úspešný. PaymentIntent=%s', $payment_intent_id));
        }

        $creator = new OneClick_Order_Creator();
        $order_id = $creator->create_session_order([
            'session_id' => $session_id,
            'offer_id' => $session['offer_id'] ?? '',
            'user_id' => $user_id,
            'items' => $items,
            'currency' => $currency,
            'payment_method' => $payment_method,
            'payment_intent_id' => $payment_intent_id,
            'original_order_id' => $session['source_order_id'] ?? null,
        ]);

        if (is_wp_error($order_id)) {
            if (!empty($payment_intent_id)) {
                $stripe = new OneClick_Stripe();
                $stripe->refund_payment($payment_intent_id);
            }
            $this->report_session_result($session, false, 0, $payment_intent_id, $order_id->get_error_message());
            return;
        }

        error_log(sprintf('   ✅ Session %s dokončená jednou objednávkou #%d.', $session_id, $order_id));
        $this->report_session_result($session, true, $order_id, $payment_intent_id, '');
    }

    private function report_session_result($session, $success, $order_id, $payment_intent_id, $error) {
        $result = $this->backend_session_post('/api/purchase-sessions/report-finalization', [
            'site_url' => site_url(),
            'session_id' => $session['session_id'] ?? '',
            'access_token' => $session['access_token'] ?? '',
            'success' => (bool) $success,
            'order_id' => $order_id ?: null,
            'payment_intent_id' => $payment_intent_id ?: null,
            'error_message' => $error ?: null,
        ], ['timeout' => 60]);

        if (is_wp_error($result)) {
            error_log('   ❌ Report finalization zlyhal: ' . $result->get_error_message());
        }
    }

    private function resolve_payment_method($session) {
        if (($session['click_behavior'] ?? 'mit_purchase') === 'mit_purchase') {
            return 'stripe';
        }

        $source_order_id = (int) ($session['source_order_id'] ?? 0);
        if ($source_order_id) {
            $source_order = wc_get_order($source_order_id);
            if ($source_order) {
                $method = sanitize_key($source_order->get_payment_method());
                if (in_array($method, ['cod', 'bacs'], true)) {
                    return $method;
                }
            }
        }

        return 'cod';
    }

    private function get_session_branding($load_remote = true) {
        $defaults = [
            'logo_url'             => '',
            'primary_color'        => '#5b3cdd',
            'secondary_color'      => '#e9edff',
            'background_color'     => '#f9f9ff',
            'text_color'           => '#141b2b',
            'accent_color'         => '#5b3cdd',
            'button_color'         => '#5b3cdd',
            'button_text_color'    => '#ffffff',
            'button_border_radius' => 14,
            'company_name'         => '',
            'font_family'          => 'Manrope, Arial, sans-serif',
        ];

        $branding = $defaults;
        if ($load_remote) {
            $result = OneClick_API_Client::instance()->get('/api/branding', ['site_url' => site_url()]);
            if (!is_wp_error($result) && is_array($result)) {
                $branding = wp_parse_args($result, $defaults);
            }
        }

        $primary = $this->session_brand_color($branding['primary_color'] ?? '', $defaults['primary_color']);
        $accent = $this->session_brand_color($branding['accent_color'] ?? '', $primary);
        $button = $this->session_brand_color($branding['button_color'] ?? '', $accent);

        return [
            'logo_url'             => esc_url_raw($branding['logo_url'] ?? ''),
            'primary_color'        => $primary,
            'secondary_color'      => $this->session_brand_color($branding['secondary_color'] ?? '', $defaults['secondary_color']),
            'background_color'     => $this->session_brand_color($branding['background_color'] ?? '', $defaults['background_color']),
            'text_color'           => $this->session_brand_color($branding['text_color'] ?? '', $defaults['text_color']),
            'accent_color'         => $accent,
            'button_color'         => $button,
            'button_text_color'    => $this->session_brand_color($branding['button_text_color'] ?? '', $defaults['button_text_color']),
            'button_border_radius' => max(0, min(50, absint($branding['button_border_radius'] ?? $defaults['button_border_radius']))),
            'company_name'         => sanitize_text_field($branding['company_name'] ?? ''),
            'font_family'          => $this->sanitize_session_font($branding['font_family'] ?? $defaults['font_family']),
        ];
    }

    private function session_brand_color($value, $fallback) {
        $color = sanitize_hex_color($value);
        return $color ?: $fallback;
    }

    private function sanitize_session_font($font) {
        $font = sanitize_text_field((string) $font);
        $font = preg_replace('/[^A-Za-z0-9 ,._-]/', '', $font);
        return $font ?: 'Manrope, Arial, sans-serif';
    }

    private function build_session_brand_css($branding) {
        $vars = [
            '--oc-primary'       => $branding['primary_color'],
            '--oc-button'        => $branding['button_color'],
            '--oc-button-text'   => $branding['button_text_color'],
            '--oc-bg'            => $branding['background_color'],
            '--oc-topbar'        => '#ffffff',
            '--oc-surface'       => '#ffffff',
            '--oc-surface-soft'  => $branding['secondary_color'],
            '--oc-chip'          => $branding['secondary_color'],
            '--oc-text'          => $branding['text_color'],
            '--oc-heading'       => $branding['text_color'],
            '--oc-muted'         => '#5d5d6b',
            '--oc-outline'       => '#797587',
            '--oc-outline-soft'  => '#c9c4d8',
            '--oc-inverse'       => '#293040',
            '--oc-inverse-text'  => '#edf0ff',
            '--oc-danger'        => '#ba1a1a',
            '--oc-shadow'        => '0 4px 20px rgba(20, 27, 43, .05)',
            '--oc-button-radius' => $branding['button_border_radius'] . 'px',
            '--oc-font'          => $branding['font_family'],
        ];

        $css = '';
        foreach ($vars as $name => $value) {
            $css .= esc_html($name) . ': ' . esc_html($value) . ';' . "\n";
        }
        return $css;
    }

    private function backend_session_post($endpoint, $body, $args = []) {
        return OneClick_API_Client::instance()->post($endpoint, $body, $args);
    }

    private function get_access_token_for_session($session_id) {
        $cookie = self::cookie_name($session_id);
        return isset($_COOKIE[$cookie]) ? sanitize_text_field(wp_unslash($_COOKIE[$cookie])) : '';
    }

    private function public_session_state($session) {
        $allowed = [
            'flow',
            'created',
            'session_id',
            'offer_id',
            'source_type',
            'source_ref_id',
            'finalization_mode',
            'status',
            'server_time',
            'finalize_after',
            'remaining_seconds',
            'extend_seconds',
            'max_extend_until',
            'total',
            'currency',
            'order_id',
            'error_message',
            'items',
            'offer_products',
        ];

        $public = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $session)) {
                $public[$key] = $session[$key];
            }
        }

        return $public;
    }

    private function render_error_page($title, $message) {
        $branding = $this->get_session_branding(false);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>' . esc_html($title) . '</title><style>:root{' . $this->build_session_brand_css($branding) . '}*{box-sizing:border-box}body{font-family:var(--oc-font);background:var(--oc-bg);color:var(--oc-text);margin:0;padding:40px 18px}main{max-width:560px;margin:0 auto;background:var(--oc-surface);border:1px solid var(--oc-outline-soft);border-radius:24px;padding:30px;box-shadow:var(--oc-shadow)}h1{margin:0 0 12px;color:var(--oc-heading);font-size:24px;line-height:1.25}p{margin:0;color:var(--oc-muted);font-size:15px;line-height:1.55}</style></head><body><main><h1>' . esc_html($title) . '</h1><p>' . esc_html($message) . '</p></main></body></html>';
        exit;
    }
}
