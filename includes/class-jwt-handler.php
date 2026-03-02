<?php
/**
 * JWT Handler
 *
 * Handles JWT token generation and verification using EdDSA (Ed25519)
 * MIGRATED FROM: backend/auth.py
 *
 * Uses native PHP sodium extension (NOT Firebase JWT)
 *
 * @package WooOneClick
 */

if (!defined('ABSPATH')) {
    exit;
}

class OneClick_JWT_Handler {

    /**
     * JWT algorithm (EdDSA = Ed25519)
     */
    const ALGORITHM = 'EdDSA';

    /**
     * Token expiration time (48 hours)
     */
    const EXPIRATION_HOURS = 48;

    /**
     * Generate JWT token via backend API
     *
     * Since v1.1.0, token generation is delegated to the backend.
     * The backend signs tokens and creates short purchase links.
     *
     * @param array $payload Token payload data (must include: user_id, user_email, product_id, product_name, price)
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
     * Generate JWT token locally (legacy, kept for backward compatibility)
     *
     * @deprecated Since 1.1.0 — Use generate_via_backend() instead.
     *             Kept for fallback if backend is unreachable.
     *
     * @param array $payload Token payload data
     * @return string|false JWT token string or false on failure
     */
    public function generate($payload) {
        try {
            // Get private key from WordPress options (generated on activation)
            $private_key_b64 = get_option('oneclick_private_key');

            if (empty($private_key_b64)) {
                error_log('OneClick JWT Error: Private key not found in options');
                return false;
            }

            // Decode base64 private key (64 bytes from sodium)
            $private_key_raw = base64_decode($private_key_b64);

            if ($private_key_raw === false || strlen($private_key_raw) !== 64) {
                error_log('OneClick JWT Error: Invalid private key');
                return false;
            }

            // Add standard JWT claims
            $payload['iat'] = time(); // Issued at
            $payload['exp'] = time() + (self::EXPIRATION_HOURS * 3600); // Expiration
            $payload['jti'] = wp_generate_uuid4(); // JWT ID (unique identifier)
            $payload['wordpress_url'] = home_url(); // WordPress site URL

            // Build JWT manually using sodium
            $header = ['alg' => self::ALGORITHM, 'typ' => 'JWT'];
            $header_b64 = $this->base64url_encode(json_encode($header));
            $payload_b64 = $this->base64url_encode(json_encode($payload));
            $message = $header_b64 . '.' . $payload_b64;

            // Sign with Ed25519 (sodium)
            $signature = sodium_crypto_sign_detached($message, $private_key_raw);
            $signature_b64 = $this->base64url_encode($signature);

            // Combine into JWT
            $token = $message . '.' . $signature_b64;

            error_log(sprintf(
                'OneClick JWT: Token generated for user %s (JTI: %s)',
                $payload['user_email'] ?? 'unknown',
                substr($payload['jti'], 0, 8)
            ));

            return $token;

        } catch (Exception $e) {
            error_log('OneClick JWT Generation Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Verify JWT token
     *
     * Migrated from: backend/auth.py::verify_token()
     *
     * @param string $token JWT token string
     * @return array Verification result
     *               ['valid' => true, 'payload' => array] on success
     *               ['valid' => false, 'error' => string] on failure
     */
    public function verify($token) {
        try {
            // Collect all available public keys to try
            $keys_to_try = [];

            $backend_key = get_option('oneclick_backend_public_key');
            if (!empty($backend_key)) {
                $keys_to_try['backend'] = $backend_key;
            }

            $local_key = get_option('oneclick_public_key');
            if (!empty($local_key)) {
                $keys_to_try['local'] = $local_key;
            }

            if (empty($keys_to_try)) {
                return [
                    'valid' => false,
                    'error' => 'Public key not found — go to OneClick Settings and click Refresh on License tab'
                ];
            }

            // Split JWT into parts
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                return [
                    'valid' => false,
                    'error' => 'Invalid token format'
                ];
            }

            list($header_b64, $payload_b64, $signature_b64) = $parts;
            $message = $header_b64 . '.' . $payload_b64;

            // Decode signature
            $signature = $this->base64url_decode($signature_b64);
            if ($signature === false) {
                return [
                    'valid' => false,
                    'error' => 'Invalid signature encoding'
                ];
            }

            // Try each key — backend first, then local
            $valid = false;
            foreach ($keys_to_try as $key_source => $key_b64) {
                $public_key_raw = base64_decode($key_b64);
                if ($public_key_raw === false || strlen($public_key_raw) !== 32) {
                    continue;
                }
                try {
                    if (sodium_crypto_sign_verify_detached($signature, $message, $public_key_raw)) {
                        $valid = true;
                        break;
                    }
                } catch (Exception $e) {
                    continue;
                }
            }

            if (!$valid) {
                return [
                    'valid' => false,
                    'error' => 'Invalid signature'
                ];
            }

            // Decode payload
            $payload_json = $this->base64url_decode($payload_b64);
            $payload = json_decode($payload_json, true);

            if ($payload === null) {
                return [
                    'valid' => false,
                    'error' => 'Invalid payload'
                ];
            }

            // Check expiration
            if (isset($payload['exp']) && $payload['exp'] < time()) {
                return [
                    'valid' => false,
                    'error' => 'Token expired'
                ];
            }

            return [
                'valid' => true,
                'payload' => $payload
            ];

        } catch (Exception $e) {
            return [
                'valid' => false,
                'error' => 'Verification failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Base64URL encode
     *
     * @param string $data Data to encode
     * @return string Base64URL encoded string
     */
    private function base64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64URL decode
     *
     * @param string $data Base64URL encoded string
     * @return string|false Decoded data or false on failure
     */
    private function base64url_decode($data) {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }

    /**
     * Get token expiration time in hours
     *
     * @return int Hours until token expires
     */
    public function get_expiration_hours() {
        return self::EXPIRATION_HOURS;
    }

    /**
     * Decode token without verification (for debugging)
     *
     * WARNING: Only use for debugging! Does NOT verify signature!
     *
     * @param string $token JWT token
     * @return array|false Decoded payload or false
     */
    public function decode_unverified($token) {
        try {
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                return false;
            }

            $payload = json_decode($this->base64url_decode($parts[1]), true);
            return $payload;

        } catch (Exception $e) {
            return false;
        }
    }
}
