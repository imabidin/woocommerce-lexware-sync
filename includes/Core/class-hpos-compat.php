<?php
/**
 * HPOS Compatibility Handler - Order Storage Abstraction
 *
 * Ensures compatibility with both:
 * - Legacy post-based order storage (WooCommerce < 8.0)
 * - High-Performance Order Storage (HPOS) (WooCommerce 8.0+)
 *
 * @package WC_Lexware_MVP
 * @since 1.0.0
 */

namespace WC_Lexware_MVP\Core;

if (!defined('ABSPATH')) {
    exit;
}

class HPOS_Compat {

    /**
     * Check if HPOS is enabled
     *
     * @since 1.0.0
     * @return bool True if HPOS is active, false if legacy post-based storage
     */
    public static function is_hpos_enabled() {
        if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')) {
            return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        }

        return false;
    }

    /**
     * Get order using HPOS-compatible method
     *
     * @since 1.0.0
     * @param int $order_id Order ID
     * @return \WC_Order|false Order object or false on failure
     */
    public static function get_order($order_id) {
        return wc_get_order($order_id);
    }

    /**
     * Get orders using HPOS-compatible method
     *
     * @since 1.0.0
     * @param array $args Query arguments
     * @return \WC_Order[] Array of order objects
     */
    public static function get_orders($args = array()) {
        return wc_get_orders($args);
    }

    /**
     * Get system info for debugging
     *
     * @since 1.0.0
     * @return array System information with wc_version, hpos_enabled, storage_type
     */
    public static function get_system_info() {
        $wc_version = defined('WC_VERSION') ? WC_VERSION : 'Unknown';
        $hpos_enabled = self::is_hpos_enabled();

        return array(
            'wc_version' => $wc_version,
            'hpos_enabled' => $hpos_enabled,
            'hpos_available' => class_exists('\Automattic\WooCommerce\Utilities\OrderUtil'),
            'storage_type' => $hpos_enabled ? 'HPOS' : 'Legacy',
        );
    }

    /**
     * Log HPOS status for debugging
     *
     * @since 1.0.0
     */
    public static function log_status() {
        $info = self::get_system_info();

        \WC_Lexware_MVP_Logger::info('HPOS Kompatibilität', array(
            'wc_version' => $info['wc_version'],
            'storage_type' => $info['storage_type'],
            'hpos_enabled' => $info['hpos_enabled'],
            'hpos_available' => $info['hpos_available']
        ));
    }
}
