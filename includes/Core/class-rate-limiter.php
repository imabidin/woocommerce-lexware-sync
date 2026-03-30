<?php
/**
 * Rate Limiter - Database-Backed Token Bucket Algorithm
 *
 * Implements Token Bucket rate limiting for Lexware API (2 requests/second).
 * Database-backed design works across load-balanced servers.
 *
 * @package WC_Lexware_MVP
 * @since 1.0.0
 */

namespace WC_Lexware_MVP\Core;

if (!defined('ABSPATH')) {
    exit;
}

class Rate_Limiter {

    /**
     * Rate limit: 2 requests per second (Lexware API limit)
     *
     * @since 1.0.0
     */
    const TOKENS_PER_SECOND = 2;

    /**
     * Bucket capacity: 4 tokens (2 seconds worth)
     *
     * @since 1.0.0
     */
    const BUCKET_CAPACITY = 4;

    /**
     * PERFORMANCE NOTE:
     * Database-backed rate limiting with FOR UPDATE locks works well for moderate traffic.
     * For high-traffic scenarios (>1000 req/min), consider implementing Redis fallback:
     *
     * 1. Check if Redis is available (php-redis extension)
     * 2. Use Redis INCR with EXPIRE for atomic token bucket
     * 3. Fallback to database if Redis unavailable
     *
     * Load testing recommended before production deployment.
     */

    /**
     * Maximum wait time constant
     *
     * @since 1.0.0
     */
    const MAX_WAIT_TIME = 5; // 5 seconds (reduced from 30s to prevent PHP-FPM blocking)

    /**
     * Acquire a token from the bucket (blocking)
     *
     * Blocks up to max_wait_time until token becomes available.
     * Retries every 100ms.
     *
     * @since 1.0.0
     * @param int $max_wait_time Maximum seconds to wait (default: 5s)
     * @return bool True on success, false if timeout
     *
     * @example if (Rate_Limiter::acquire_token()) { // Make API call }
     */
    public static function acquire_token($max_wait_time = self::MAX_WAIT_TIME) {
        $start_time = microtime(true);

        while (microtime(true) - $start_time < $max_wait_time) {
            if (self::try_acquire_token()) {
                return true;
            }

            // Wait 100ms before retry
            usleep(100000);
        }

        // Timeout - could not acquire token
        if (class_exists('\\WC_Lexware_MVP_Logger', false) || class_exists('WC_Lexware_MVP_Logger')) {
            $status = self::get_status();
            \WC_Lexware_MVP_Logger::warning(
                'Rate Limiter: Timeout nach ' . $max_wait_time . ' Sekunden',
                array(
                    'waited_seconds' => round(microtime(true) - $start_time, 2),
                    'max_wait_time' => $max_wait_time,
                    'token_bucket_status' => $status,
                    'tokens_available' => $status['tokens'],
                    'bucket_capacity' => $status['capacity'],
                    'refill_rate' => $status['refill_rate_per_sec'] . ' tokens/sec',
                    'concurrent_requests_est' => ceil(($max_wait_time * self::TOKENS_PER_SECOND) / self::BUCKET_CAPACITY),
                    'recommendation' => 'Consider increasing MAX_WAIT_TIME or implementing Redis-backed rate limiting for high traffic',
                )
            );
        }

        return false;
    }

    /**
     * Try to acquire a token (non-blocking, database-backed)
     *
     * Uses MySQL FOR UPDATE lock for atomic token consumption.
     * Refills bucket based on elapsed time.
     *
     * @since 1.0.0
     * @return bool True if token acquired, false if bucket empty
     */
    private static function try_acquire_token() {
        global $wpdb;
        $table = wc_lexware_mvp_get_rate_limiter_table_name();
        $table_no_backticks = wc_lexware_mvp_get_rate_limiter_table_name(false);

        // Atomic Lock für Token Bucket (nur 1 Row in Tabelle)
        $wpdb->query('START TRANSACTION');

        try {
            // Lock Row with FOR UPDATE (nur 1 Row existiert)
            $bucket = $wpdb->get_row(
                "SELECT * FROM {$table} WHERE id = 1 FOR UPDATE"
            );

            if (!$bucket) {
                // Initialize if not exists (should be created by activator)
                $wpdb->insert($table_no_backticks, array(
                    'id' => 1,
                    'tokens' => self::BUCKET_CAPACITY,
                    'last_refill' => microtime(true)
                ));
                $bucket = $wpdb->get_row("SELECT * FROM {$table} WHERE id = 1");
            }

            // Refill tokens based on time elapsed
            $now = microtime(true);
            $elapsed = $now - $bucket->last_refill;
            $tokens_to_add = $elapsed * self::TOKENS_PER_SECOND;
            $current_tokens = min(self::BUCKET_CAPACITY, $bucket->tokens + $tokens_to_add);

            // Try consume token
            if ($current_tokens >= 1) {
                $wpdb->update($table_no_backticks, array(
                    'tokens' => $current_tokens - 1,
                    'last_refill' => $now
                ), array('id' => 1));

                $wpdb->query('COMMIT');

                if (class_exists('\\WC_Lexware_MVP_Logger', false) || class_exists('WC_Lexware_MVP_Logger')) {
                    \WC_Lexware_MVP_Logger::debug(
                        'Rate Limiter: Token erworben (DB-backed)',
                        array(
                            'tokens_remaining' => round($current_tokens - 1, 2),
                            'bucket_capacity' => self::BUCKET_CAPACITY
                        )
                    );
                }

                return true;
            }

            // No tokens available
            $wpdb->query('COMMIT');
            return false;

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');

            if (class_exists('\\WC_Lexware_MVP_Logger', false) || class_exists('WC_Lexware_MVP_Logger')) {
                \WC_Lexware_MVP_Logger::error('Rate Limiter DB error', array(
                    'error' => $e->getMessage()
                ));
            }

            return false;
        }
    }

    /**
     * Get current rate limit status
     *
     * Returns current token count and availability.
     * Does not consume tokens.
     *
     * @since 1.0.0
     * @return array Status information
     *
     * @example ['available' => true, 'tokens' => 3.5, 'capacity' => 4, ...]
     */
    public static function get_status() {
        global $wpdb;
        $table = wc_lexware_mvp_get_rate_limiter_table_name();

        $bucket = $wpdb->get_row("SELECT * FROM {$table} WHERE id = 1");

        if (!$bucket) {
            return array(
                'available' => true,
                'tokens' => self::BUCKET_CAPACITY,
                'capacity' => self::BUCKET_CAPACITY,
                'backend' => 'database'
            );
        }

        // Refill tokens for status display
        $now = microtime(true);
        $elapsed = $now - $bucket->last_refill;
        $tokens_to_add = $elapsed * self::TOKENS_PER_SECOND;
        $current_tokens = min(
            self::BUCKET_CAPACITY,
            $bucket->tokens + $tokens_to_add
        );

        return array(
            'available' => $current_tokens >= 1,
            'tokens' => round($current_tokens, 2),
            'capacity' => self::BUCKET_CAPACITY,
            'refill_rate_per_sec' => self::TOKENS_PER_SECOND,
            'backend' => 'database'
        );
    }

    /**
     * Reset rate limiter (for testing/debugging)
     *
     * Refills bucket to full capacity.
     *
     * @since 1.0.0
     */
    public static function reset() {
        global $wpdb;
        $table_no_backticks = wc_lexware_mvp_get_rate_limiter_table_name(false);

        $wpdb->update($table_no_backticks, array(
            'tokens' => self::BUCKET_CAPACITY,
            'last_refill' => microtime(true)
        ), array('id' => 1));

        if (class_exists('\\WC_Lexware_MVP_Logger', false) || class_exists('WC_Lexware_MVP_Logger')) {
            \WC_Lexware_MVP_Logger::info('Rate Limiter zurückgesetzt (DB)');
        }
    }

    /**
     * Create rate limit table (called by activator)
     *
     * Creates single-row table to track token bucket state.
     *
     * @since 1.0.0
     */
    public static function create_table() {
        global $wpdb;
        $table = wc_lexware_mvp_get_rate_limiter_table_name(false);
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            id tinyint(1) NOT NULL DEFAULT 1,
            tokens decimal(10,2) NOT NULL,
            last_refill decimal(16,4) NOT NULL,
            PRIMARY KEY (id)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Initialize with full capacity
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO `{$table}` (id, tokens, last_refill) VALUES (1, %f, %f)",
            self::BUCKET_CAPACITY,
            microtime(true)
        ));
    }
}

