<?php
/**
 * Token Blacklist Handler
 *
 * Prevents replay attacks by tracking used JWT tokens (by JTI)
 *
 * Storage: Redis (primary) with WordPress Transients fallback
 *
 * NOTE (v1.1.0): This class is still used by the purchase handler for
 * local token replay prevention. In future phases, this may migrate
 * to a backend check via POST /api/tokens/check.
 *
 * @package WooOneClick
 * @since 1.0.0 — Initial implementation
 * @since 1.1.0 — Kept for backward compatibility (purchase handler)
 */

if (!defined('ABSPATH')) {
    exit;
}

class OneClick_Token_Blacklist {

    /**
     * Redis client instance
     * @var Predis\Client|null
     */
    private $redis;

    /**
     * TTL for blacklisted tokens (48 hours, matches JWT expiration)
     */
    const TTL_HOURS = 48;

    /**
     * Constructor - Initialize Redis connection
     */
    public function __construct() {
        $this->init_redis();
    }

    /**
     * Initialize Redis connection
     *
     * Tries to connect to Redis, falls back to WordPress Transients if fails
     */
    private function init_redis() {
        // Check if Predis is available
        if (!class_exists('Predis\Client')) {
            error_log('OneClick Blacklist: Predis not found, using WordPress Transients');
            $this->redis = null;
            return;
        }

        try {
            $redis_host = get_option('oneclick_redis_host', '127.0.0.1');
            $redis_port = get_option('oneclick_redis_port', 6379);

            $this->redis = new Predis\Client([
                'scheme' => 'tcp',
                'host'   => $redis_host,
                'port'   => $redis_port,
            ]);

            // Test connection
            $this->redis->ping();
            error_log('OneClick Blacklist: Redis connected successfully');

        } catch (Exception $e) {
            error_log('OneClick Blacklist: Redis connection failed: ' . $e->getMessage());
            error_log('OneClick Blacklist: Falling back to WordPress Transients');
            $this->redis = null;
        }
    }

    /**
     * Check if a token has been used (replay attack prevention)
     *
     * Migrated from: backend/token_blacklist.py::is_token_used()
     *
     * @param string $jti JWT ID (unique identifier from token)
     * @return bool True if token has been used, false otherwise
     */
    public function is_token_used($jti) {
        if (empty($jti)) {
            return false;
        }

        if ($this->redis) {
            // Redis implementation
            try {
                $key = $this->get_redis_key($jti);
                $exists = $this->redis->exists($key);
                return $exists > 0;

            } catch (Exception $e) {
                error_log('OneClick Blacklist Error (Redis): ' . $e->getMessage());
                // Fallback to transients on error
                return $this->is_token_used_transient($jti);
            }

        } else {
            // WordPress Transients fallback
            return $this->is_token_used_transient($jti);
        }
    }

    /**
     * Mark a token as used (add to blacklist)
     *
     * Migrated from: backend/token_blacklist.py::mark_token_used()
     *
     * @param string $jti JWT ID (unique identifier from token)
     * @return bool True on success, false on failure
     */
    public function mark_token_used($jti) {
        if (empty($jti)) {
            error_log('OneClick Blacklist Error: Empty JTI provided');
            return false;
        }

        if ($this->redis) {
            // Redis implementation with TTL
            try {
                $key = $this->get_redis_key($jti);
                $ttl_seconds = self::TTL_HOURS * 3600;
                $timestamp = current_time('mysql');

                $this->redis->setex($key, $ttl_seconds, $timestamp);

                error_log(sprintf(
                    'OneClick Blacklist: Token %s marked as used (Redis, TTL: %dh)',
                    substr($jti, 0, 8),
                    self::TTL_HOURS
                ));

                return true;

            } catch (Exception $e) {
                error_log('OneClick Blacklist Error (Redis): ' . $e->getMessage());
                // Fallback to transients on error
                return $this->mark_token_used_transient($jti);
            }

        } else {
            // WordPress Transients fallback
            return $this->mark_token_used_transient($jti);
        }
    }

    /**
     * Get Redis key for a JTI
     *
     * @param string $jti JWT ID
     * @return string Redis key
     */
    private function get_redis_key($jti) {
        return 'oneclick:used_token:' . $jti;
    }

    /**
     * Check if token is used (WordPress Transients fallback)
     *
     * @param string $jti JWT ID
     * @return bool True if used, false otherwise
     */
    private function is_token_used_transient($jti) {
        $transient_name = $this->get_transient_name($jti);
        $value = get_transient($transient_name);
        return $value !== false;
    }

    /**
     * Mark token as used (WordPress Transients fallback)
     *
     * @param string $jti JWT ID
     * @return bool True on success
     */
    private function mark_token_used_transient($jti) {
        $transient_name = $this->get_transient_name($jti);
        $ttl_seconds = self::TTL_HOURS * 3600;
        $timestamp = current_time('mysql');

        $result = set_transient($transient_name, $timestamp, $ttl_seconds);

        if ($result) {
            error_log(sprintf(
                'OneClick Blacklist: Token %s marked as used (Transients, TTL: %dh)',
                substr($jti, 0, 8),
                self::TTL_HOURS
            ));
        }

        return $result;
    }

    /**
     * Get WordPress transient name for a JTI
     *
     * @param string $jti JWT ID
     * @return string Transient name
     */
    private function get_transient_name($jti) {
        // WordPress transient names must be <= 172 characters
        // Use md5 hash to ensure consistent length
        return 'oneclick_used_' . md5($jti);
    }

    /**
     * Get blacklist statistics (for debugging/monitoring)
     *
     * @return array Statistics about blacklist
     */
    public function get_stats() {
        if ($this->redis) {
            try {
                $pattern = 'oneclick:used_token:*';
                $keys = $this->redis->keys($pattern);
                $count = count($keys);

                return [
                    'storage' => 'Redis',
                    'total_blacklisted' => $count,
                    'ttl_hours' => self::TTL_HOURS,
                    'redis_host' => get_option('oneclick_redis_host', '127.0.0.1'),
                    'redis_port' => get_option('oneclick_redis_port', 6379)
                ];

            } catch (Exception $e) {
                return [
                    'storage' => 'Redis (error)',
                    'error' => $e->getMessage()
                ];
            }

        } else {
            // For transients, we can't easily count them
            return [
                'storage' => 'WordPress Transients',
                'ttl_hours' => self::TTL_HOURS,
                'note' => 'Cannot count transients efficiently'
            ];
        }
    }

    /**
     * Clear the entire blacklist (use with caution!)
     * For testing/debugging purposes only
     *
     * @return bool True on success
     */
    public function clear_blacklist() {
        if ($this->redis) {
            try {
                $pattern = 'oneclick:used_token:*';
                $keys = $this->redis->keys($pattern);

                if (!empty($keys)) {
                    $this->redis->del($keys);
                }

                error_log('OneClick Blacklist: Cleared ' . count($keys) . ' tokens from Redis');
                return true;

            } catch (Exception $e) {
                error_log('OneClick Blacklist Error: ' . $e->getMessage());
                return false;
            }

        } else {
            // For transients, we can't easily clear all at once
            error_log('OneClick Blacklist: Cannot clear all transients (no pattern matching)');
            return false;
        }
    }

    /**
     * Test Redis connection
     *
     * @return array Connection test result
     */
    public function test_connection() {
        if ($this->redis) {
            try {
                $this->redis->ping();
                return [
                    'success' => true,
                    'storage' => 'Redis',
                    'message' => 'Redis connection successful'
                ];

            } catch (Exception $e) {
                return [
                    'success' => false,
                    'storage' => 'Redis (failed)',
                    'error' => $e->getMessage()
                ];
            }

        } else {
            return [
                'success' => true,
                'storage' => 'WordPress Transients',
                'message' => 'Using WordPress Transients (Redis not available)'
            ];
        }
    }
}
