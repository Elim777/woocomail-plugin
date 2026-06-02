<?php
/**
 * OneClick Logger
 *
 * Routes OneClick logs to WooCommerce logs in production while preserving a
 * safe error_log fallback for environments where WooCommerce logging is not
 * available yet.
 *
 * @package WooOneClick
 */

if (!defined('ABSPATH')) {
    exit;
}

class OneClick_Logger {

    const DEFAULT_SOURCE = 'oneclick-core';

    private static $source_map = [
        'class-api-client.php' => 'oneclick-backend',
        'class-settings.php' => 'oneclick-core',
        'class-compatibility-test.php' => 'oneclick-core',
        'class-jwt-handler.php' => 'oneclick-backend',
        'class-jwt-test.php' => 'oneclick-backend',
        'class-campaign-trigger.php' => 'oneclick-email',
        'class-periodic-cron.php' => 'oneclick-email',
        'class-email-branding.php' => 'oneclick-email',
        'class-public-links.php' => 'oneclick-public',
        'class-purchase-handler.php' => 'oneclick-session',
        'class-purchase-session.php' => 'oneclick-session',
        'class-stripe.php' => 'oneclick-stripe',
        'class-stripe-reconciliation.php' => 'oneclick-stripe',
        'class-order-creator.php' => 'oneclick-order',
        'class-cart-tracker.php' => 'oneclick-cart',
        'class-ai-setup.php' => 'oneclick-ai',
        'class-reactions-admin.php' => 'oneclick-ai',
    ];

    public static function log($message, $source = null, $level = 'info', $context = []) {
        $message = self::sanitize_message((string) $message);
        $source = self::sanitize_source($source ?: self::detect_source());
        $level = self::sanitize_level($level);
        $context = is_array($context) ? $context : [];
        $context['source'] = $source;

        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            if ($logger && is_callable([$logger, $level])) {
                $logger->{$level}($message, $context);

                if (defined('ONECLICK_DUAL_WRITE_ERROR_LOG') && ONECLICK_DUAL_WRITE_ERROR_LOG) {
                    error_log($message);
                }
                return;
            }
        }

        error_log($message);
    }

    private static function detect_source() {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6);
        foreach ($trace as $frame) {
            if (empty($frame['file'])) {
                continue;
            }
            $basename = basename($frame['file']);
            if ($basename === 'class-logger.php') {
                continue;
            }
            if (isset(self::$source_map[$basename])) {
                return self::$source_map[$basename];
            }
        }

        return self::DEFAULT_SOURCE;
    }

    private static function sanitize_source($source) {
        $source = sanitize_key((string) $source);
        return $source ?: self::DEFAULT_SOURCE;
    }

    private static function sanitize_level($level) {
        $level = strtolower((string) $level);
        $allowed = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];
        return in_array($level, $allowed, true) ? $level : 'info';
    }

    private static function sanitize_message($message) {
        $patterns = [
            '/(X-License-Key:\s*)[A-Za-z0-9._~+\-\/=]+/i' => '$1***',
            '/(Authorization:\s*Bearer\s+)[A-Za-z0-9._~+\-\/=]+/i' => '$1***',
            '/(license[_ -]?key[=:]\s*)[A-Za-z0-9._~+\-\/=]+/i' => '$1***',
            '/(access[_ -]?token[=:]\s*)[A-Za-z0-9._~+\-\/=]+/i' => '$1***',
            '/(secret[_ -]?key[=:]\s*)[A-Za-z0-9._~+\-\/=]+/i' => '$1***',
            '/(sk_(?:live|test)_[A-Za-z0-9]+)/' => 'sk_***',
        ];

        return preg_replace(array_keys($patterns), array_values($patterns), $message);
    }
}

function oneclick_log($message, $source = null, $level = 'info', $context = []) {
    OneClick_Logger::log($message, $source, $level, $context);
}
