<?php
/**
 * Idempotency Manager - Duplicate Operation Prevention for API Retries
 *
 * Prevents duplicate invoices/credit notes when network timeouts occur after
 * successful API operations. Uses WordPress transients to store idempotency keys
 * that are reused across retries.
 *
 * @package WooCommerce_Lexware_MVP
 * @since 0.5.1
 */

namespace WC_Lexware_MVP\Core;

if (!defined('ABSPATH')) {
    exit;
}

class Idempotency_Manager {

    /**
     * Transient prefix for idempotency keys
     */
    const TRANSIENT_PREFIX = 'lexware_mvp_idempotency_';

    /**
     * TTL for idempotency keys (24 hours)
     * Matches Lexware API idempotency window
     */
    const TTL = 86400;

    /**
     * Generate idempotency key for an operation
     *
     * Format: mvp_{order_id}_{operation}_{hour_timestamp}
     *
     * The hourly timestamp ensures the same key is used for retries
     * within the same hour, but new operations in different hours
     * get new keys.
     *
     * @param int $order_id WooCommerce Order ID
     * @param string $operation Operation type (e.g., 'create_invoice', 'create_credit_note_123')
     * @return string Idempotency key
     */
    public static function generate_key($order_id, $operation) {
        // Hourly timestamp: floor(time() / 3600)
        // Example: 14:00-14:59 = same timestamp
        $hour_timestamp = floor(time() / 3600);

        return sprintf('mvp_%d_%s_%d', $order_id, $operation, $hour_timestamp);
    }

    /**
     * Get or create idempotency key for an operation
     *
     * If a key already exists in transients (from a previous attempt),
     * return that key to ensure retries use the same key.
     * Otherwise, generate and store a new key.
     *
     * @param int $order_id WooCommerce Order ID
     * @param string $operation Operation type
     * @return string Idempotency key (existing or newly generated)
     */
    public static function get_key($order_id, $operation) {
        $transient_key = self::get_transient_key($order_id, $operation);
        $existing_key = get_transient($transient_key);

        if ($existing_key !== false) {
            \WC_Lexware_MVP_Logger::debug('Reusing existing idempotency key', array(
                'order_id' => $order_id,
                'operation' => $operation,
                'key' => $existing_key
            ));
            return $existing_key;
        }

        // Generate new key
        $new_key = self::generate_key($order_id, $operation);
        set_transient($transient_key, $new_key, self::TTL);

        \WC_Lexware_MVP_Logger::debug('Generated new idempotency key', array(
            'order_id' => $order_id,
            'operation' => $operation,
            'key' => $new_key,
            'ttl' => self::TTL
        ));

        return $new_key;
    }

    /**
     * Check if an operation has already been completed successfully
     *
     * This prevents re-execution of operations that have already succeeded.
     * Useful for manual retries via admin meta box or duplicate webhook triggers.
     *
     * @param int $order_id WooCommerce Order ID
     * @param string $operation Operation type
     * @return bool True if operation already completed
     */
    public static function is_completed($order_id, $operation) {
        $completion_key = self::get_completion_key($order_id, $operation);
        $is_completed = get_transient($completion_key) === 'completed';

        if ($is_completed) {
            \WC_Lexware_MVP_Logger::debug('Operation already completed (idempotency check)', array(
                'order_id' => $order_id,
                'operation' => $operation
            ));
        }

        return $is_completed;
    }

    /**
     * Mark an operation as completed successfully
     *
     * This sets a completion flag that prevents the operation from being
     * executed again, even if retries are triggered.
     *
     * @param int $order_id WooCommerce Order ID
     * @param string $operation Operation type
     * @return void
     */
    public static function mark_completed($order_id, $operation) {
        $completion_key = self::get_completion_key($order_id, $operation);
        set_transient($completion_key, 'completed', self::TTL);

        \WC_Lexware_MVP_Logger::info('Operation marked as completed', array(
            'order_id' => $order_id,
            'operation' => $operation,
            'completion_key' => $completion_key
        ));
    }

    /**
     * Reset idempotency state for an operation
     *
     * Use this to force re-execution of an operation, for example
     * when an admin manually wants to retry a failed operation.
     *
     * @param int $order_id WooCommerce Order ID
     * @param string $operation Operation type
     * @return void
     */
    public static function reset($order_id, $operation) {
        $transient_key = self::get_transient_key($order_id, $operation);
        $completion_key = self::get_completion_key($order_id, $operation);

        delete_transient($transient_key);
        delete_transient($completion_key);

        \WC_Lexware_MVP_Logger::info('Idempotency state reset', array(
            'order_id' => $order_id,
            'operation' => $operation
        ));
    }

    /**
     * Get transient key name for idempotency key storage
     *
     * @param int $order_id WooCommerce Order ID
     * @param string $operation Operation type
     * @return string Transient key name
     */
    private static function get_transient_key($order_id, $operation) {
        return self::TRANSIENT_PREFIX . $order_id . '_' . $operation;
    }

    /**
     * Get transient key name for completion tracking
     *
     * @param int $order_id WooCommerce Order ID
     * @param string $operation Operation type
     * @return string Completion transient key name
     */
    private static function get_completion_key($order_id, $operation) {
        return self::TRANSIENT_PREFIX . $order_id . '_' . $operation . '_completed';
    }

    /**
     * Cleanup expired idempotency keys (optional maintenance function)
     *
     * WordPress automatically cleans up expired transients, but this
     * method provides manual cleanup if needed for high-traffic sites.
     *
     * @return int Number of keys cleaned up
     */
    public static function cleanup_expired_keys() {
        global $wpdb;

        $prefix = '_transient_' . self::TRANSIENT_PREFIX;
        $timeout_prefix = '_transient_timeout_' . self::TRANSIENT_PREFIX;
        $now = time();

        // Find expired transients
        $expired = $wpdb->get_col($wpdb->prepare(
            "SELECT REPLACE(option_name, '_transient_timeout_', '')
             FROM {$wpdb->options}
             WHERE option_name LIKE %s
             AND option_value < %d",
            $wpdb->esc_like($timeout_prefix) . '%',
            $now
        ));

        $count = 0;
        foreach ($expired as $key) {
            delete_transient($key);
            $count++;
        }

        if ($count > 0) {
            \WC_Lexware_MVP_Logger::info('Cleaned up expired idempotency keys', array(
                'count' => $count
            ));
        }

        return $count;
    }

    /**
     * Get statistics about current idempotency keys
     *
     * Useful for debugging and monitoring
     *
     * @return array Statistics
     */
    public static function get_stats() {
        global $wpdb;

        $prefix = '_transient_' . self::TRANSIENT_PREFIX;

        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options}
             WHERE option_name LIKE %s
             AND option_name NOT LIKE %s",
            $wpdb->esc_like($prefix) . '%',
            $wpdb->esc_like('_transient_timeout_') . '%'
        ));

        $completed = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options}
             WHERE option_name LIKE %s",
            $wpdb->esc_like($prefix) . '%_completed'
        ));

        return array(
            'total_keys' => (int) $total,
            'completed_operations' => (int) $completed,
            'active_keys' => (int) $total - (int) $completed,
            'ttl_seconds' => self::TTL,
            'ttl_hours' => self::TTL / 3600
        );
    }
}
