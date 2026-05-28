<?php
/**
 * API Client - Centralized HTTP Client for Backend Communication
 *
 * Singleton pattern. All backend API calls go through this class.
 * Automatically adds X-License-Key and X-Site-URL headers.
 *
 * @package WooOneClick
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class OneClick_API_Client {

    /** @var OneClick_API_Client|null Singleton instance */
    private static $instance = null;

    /** @var string Backend base URL */
    private $base_url;

    /** @var int Request timeout in seconds */
    private $timeout = 30;

    /**
     * Get singleton instance
     *
     * @return OneClick_API_Client
     */
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor (singleton)
     */
    private function __construct() {
        $this->base_url = rtrim(get_option('oneclick_backend_url', 'https://woocomail-api.onrender.com'), '/');
    }

    /**
     * Send POST request to backend
     *
     * @param string $endpoint API endpoint (e.g. '/api/license/check')
     * @param array  $data     Request body (will be JSON-encoded)
     * @return array|WP_Error  Parsed response body as array, or WP_Error
     */
    public function post($endpoint, $data = [], $args = []) {
        $url = $this->base_url . $endpoint;
        $timeout = isset($args['timeout']) ? (int) $args['timeout'] : $this->timeout;

        $response = wp_remote_post($url, [
            'headers' => $this->get_headers(),
            'body'    => wp_json_encode($data),
            'timeout' => $timeout,
        ]);

        return $this->handle_response($response, $endpoint);
    }

    /**
     * Send GET request to backend
     *
     * @param string $endpoint   API endpoint (e.g. '/health')
     * @param array  $params     Query parameters
     * @return array|WP_Error    Parsed response body as array, or WP_Error
     */
    public function get($endpoint, $params = []) {
        $url = $this->base_url . $endpoint;

        if (!empty($params)) {
            $url = add_query_arg($params, $url);
        }

        $response = wp_remote_get($url, [
            'headers' => $this->get_headers(),
            'timeout' => $this->timeout,
        ]);

        return $this->handle_response($response, $endpoint);
    }

    /**
     * Build request headers
     *
     * @return array
     */
    private function get_headers() {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
            'X-Site-URL'   => site_url(),
        ];

        $license_key = get_option('oneclick_license_key', '');
        if (!empty($license_key)) {
            $headers['X-License-Key'] = $license_key;
        }

        return $headers;
    }

    /**
     * Handle HTTP response
     *
     * @param array|WP_Error $response  wp_remote_* response
     * @param string         $endpoint  For error logging
     * @return array|WP_Error           Parsed body or WP_Error
     */
    private function handle_response($response, $endpoint) {
        if (is_wp_error($response)) {
            error_log(sprintf(
                'OneClick API: Request to %s failed: %s',
                $endpoint,
                $response->get_error_message()
            ));
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if ($status_code >= 200 && $status_code < 300) {
            return $decoded ?? [];
        }

        $error_message = '';
        if (is_array($decoded) && isset($decoded['detail'])) {
            $error_message = is_string($decoded['detail'])
                ? $decoded['detail']
                : wp_json_encode($decoded['detail']);
        } else {
            $error_message = $body;
        }

        error_log(sprintf(
            'OneClick API: %s returned HTTP %d: %s',
            $endpoint,
            $status_code,
            $error_message
        ));

        return new WP_Error(
            'oneclick_api_error',
            $error_message,
            ['status' => $status_code, 'body' => $decoded]
        );
    }

    /**
     * Send PUT request to backend
     *
     * @param string $endpoint API endpoint
     * @param array  $data     Request body (will be JSON-encoded)
     * @return array|WP_Error
     */
    public function put($endpoint, $data = []) {
        $url = $this->base_url . $endpoint;

        $response = wp_remote_request($url, [
            'method'  => 'PUT',
            'headers' => $this->get_headers(),
            'body'    => wp_json_encode($data),
            'timeout' => $this->timeout,
        ]);

        return $this->handle_response($response, $endpoint);
    }

    /**
     * Send PATCH request to backend
     *
     * @param string $endpoint API endpoint
     * @param array  $data     Optional request body
     * @return array|WP_Error
     */
    public function patch($endpoint, $data = []) {
        $url = $this->base_url . $endpoint;

        $response = wp_remote_request($url, [
            'method'  => 'PATCH',
            'headers' => $this->get_headers(),
            'body'    => !empty($data) ? wp_json_encode($data) : null,
            'timeout' => $this->timeout,
        ]);

        return $this->handle_response($response, $endpoint);
    }

    /**
     * Send DELETE request to backend
     *
     * @param string $endpoint API endpoint
     * @return array|WP_Error
     */
    public function delete($endpoint) {
        $url = $this->base_url . $endpoint;

        $response = wp_remote_request($url, [
            'method'  => 'DELETE',
            'headers' => $this->get_headers(),
            'timeout' => $this->timeout,
        ]);

        return $this->handle_response($response, $endpoint);
    }

    /**
     * Get backend base URL
     *
     * @return string
     */
    public function get_base_url() {
        return $this->base_url;
    }

    /**
     * Check if backend is reachable
     *
     * @return array|WP_Error Health check response or error
     */
    public function health_check() {
        return $this->get('/health');
    }

    /**
     * Reset singleton (useful after settings change)
     */
    public static function reset() {
        self::$instance = null;
    }
}
