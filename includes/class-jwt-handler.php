<?php
/**
 * JWT Handler — Thin Client
 *
 * All JWT operations delegated to backend API.
 * No local crypto — no Sodium, no Firebase JWT.
 *
 * @package WooOneClick
 * @since 1.2.0 — Fully backend-delegated (removed local Sodium signing/verification)
 */

if (!defined('ABSPATH')) {
    exit;
}

class OneClick_JWT_Handler {

    /**
     * Generate JWT token via backend API
     *
     * Backend signs the token and creates a short purchase link.
     *
     * @param array $payload Token payload data (user_id, user_email, product_id, product_name, price)
     * @return string|false JWT token string or false on failure
     */
    public function generate_via_backend($payload) {
        try {
            $api = OneClick_API_Client::instance();
            $request_data = [
                'site_url'     => site_url(),
                'user_id'      => $payload['user_id'] ?? 0,
                'user_email'   => $payload['user_email'] ?? '',
                'product_id'   => $payload['product_id'] ?? 0,
                'product_name' => $payload['product_name'] ?? '',
                'price'        => $payload['price'] ?? 0.0,
                'currency'     => $payload['currency'] ?? get_woocommerce_currency(),
                'order_id'     => $payload['original_order_id'] ?? null,
            ];

            if (!empty($payload['test'])) {
                $request_data['test'] = true;
            }

            $result = $api->post('/api/tokens/generate', $request_data);

            if (is_wp_error($result)) {
                error_log('OneClick JWT: Backend token generation failed: ' . $result->get_error_message());
                return false;
            }

            if (!empty($result['token'])) {
                error_log(sprintf(
                    'OneClick JWT: Token generated via backend for %s (purchase_url: %s)',
                    $payload['user_email'] ?? 'unknown',
                    $result['purchase_url'] ?? 'n/a'
                ));
                return $result['token'];
            }

            error_log('OneClick JWT: Backend returned no token');
            return false;

        } catch (Exception $e) {
            error_log('OneClick JWT: Backend generation error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Verify JWT token + claim JTI via backend API
     *
     * Calls POST /api/tokens/verify-and-claim which atomically:
     * - Verifies EdDSA signature
     * - Checks expiration
     * - Checks JTI not already used
     * - Marks JTI as used
     *
     * @param string $token JWT token string
     * @return array ['valid' => true, 'payload' => [...]] or ['valid' => false, 'error' => '...']
     */
    public function verify_via_backend($token) {
        try {
            $api = OneClick_API_Client::instance();
            $result = $api->post('/api/tokens/verify-and-claim', ['token' => $token]);

            if (is_wp_error($result)) {
                error_log('OneClick JWT: Backend verify-and-claim failed: ' . $result->get_error_message());
                return [
                    'valid' => false,
                    'error' => 'Backend verification failed: ' . $result->get_error_message(),
                ];
            }

            return $result;

        } catch (Exception $e) {
            error_log('OneClick JWT: Backend verify error: ' . $e->getMessage());
            return [
                'valid' => false,
                'error' => 'Verification error: ' . $e->getMessage(),
            ];
        }
    }
}
