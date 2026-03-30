<?php
/**
 * Payment Mapping Helper - Payment Conditions Management
 *
 * Manages mapping between WooCommerce payment methods and Lexware payment conditions.
 * Includes caching for performance optimization.
 *
 * @package WC_Lexware_MVP
 * @since 1.0.0
 */

namespace WC_Lexware_MVP\Core;

use WC_Lexware_MVP\API\Client as API_Client;

class Payment_Mapping {

    /**
     * Transient key for cached payment conditions
     *
     * Cache does not expire automatically - only manually via refresh button or after save.
     *
     * @since 1.0.0
     */
    const CACHE_KEY = 'wc_lexware_mvp_payment_conditions';

    /**
     * Option key for mapping storage
     *
     * @since 1.0.0
     */
    const OPTION_KEY = 'wc_lexware_mvp_payment_mapping';

    /**
     * Fetch payment conditions from Lexware API
     *
     * Uses transient cache unless force refresh is requested.
     *
     * @since 1.0.0
     * @param bool $force Force refresh (bypass cache)
     * @return array|\WP_Error Payment conditions or error
     */
    public static function fetch_payment_conditions($force = false) {
        // Check cache first (unless force refresh)
        if (!$force) {
            $cached = get_transient(self::CACHE_KEY);
            if ($cached !== false) {
                \WC_Lexware_MVP_Logger::debug('Payment Conditions loaded from cache', array(
                    'count' => count($cached)
                ));
                return $cached;
            }
        }

        // Fetch from API
        $api = new API_Client();
        $response = $api->get_payment_conditions();

        if (\is_wp_error($response)) {
            \WC_Lexware_MVP_Logger::error('Failed to fetch payment conditions', array(
                'error' => $response->get_error_message()
            ));
            return $response;
        }

        // Payment conditions endpoint returns direct array (not wrapped in 'content')
        // Handle both formats for backwards compatibility
        if (isset($response['content']) && is_array($response['content'])) {
            $conditions = $response['content'];
        } elseif (is_array($response)) {
            $conditions = $response;
        } else {
            $conditions = array();
        }

        // Cache for 24 hours (can be manually refreshed via Refresh button)
        set_transient(self::CACHE_KEY, $conditions, DAY_IN_SECONDS);

        \WC_Lexware_MVP_Logger::info('Payment Conditions fetched from API', array(
            'count' => count($conditions)
        ));

        return $conditions;
    }

    /**
     * Get saved payment mapping
     *
     * @since 1.0.0
     * @return array Mapping array [payment_method => condition_id]
     */
    public static function get_mapping() {
        return get_option(self::OPTION_KEY, array());
    }

    /**
     * Save payment mapping
     *
     * Clears cache to ensure fresh data after save.
     *
     * @since 1.0.0
     * @param array $mapping Mapping array
     * @return bool Success
     */
    public static function save_mapping($mapping) {
        // Clear cache to ensure fresh data after save
        self::clear_cache();
        return update_option(self::OPTION_KEY, $mapping);
    }

    /**
     * Get payment condition ID for specific payment method
     *
     * @since 1.0.0
     * @param string $payment_method WooCommerce payment method ID
     * @return string|null Lexware payment condition ID or null
     */
    public static function get_condition_for_method($payment_method) {
        $mapping = self::get_mapping();
        return isset($mapping[$payment_method]) ? $mapping[$payment_method] : null;
    }

    /**
     * Get WooCommerce payment methods (gateways)
     *
     * @return array Payment gateways [id => title]
     */
    public static function get_wc_payment_methods() {
        $methods = array();

        // Ensure WooCommerce is loaded
        if (!function_exists('WC')) {
            return $methods;
        }

        // Get all payment gateways
        $gateways = WC()->payment_gateways()->payment_gateways();

        foreach ($gateways as $gateway) {
            if ($gateway->enabled === 'yes') {
                $methods[$gateway->id] = $gateway->get_title();
            }
        }

        return $methods;
    }

    /**
     * Clear cached payment conditions
     */
    public static function clear_cache() {
        delete_transient(self::CACHE_KEY);
        \WC_Lexware_MVP_Logger::info('Payment Conditions cache cleared');
    }

    /**
     * Validate if payment condition exists in Lexware
     *
     * @param string $condition_id Payment condition ID
     * @return bool True if exists
     */
    public static function validate_condition_exists($condition_id) {
        $conditions = self::fetch_payment_conditions();

        if (\is_wp_error($conditions)) {
            return false;
        }

        foreach ($conditions as $condition) {
            if ($condition['id'] === $condition_id) {
                return true;
            }
        }

        return false;
    }

    /**
     * Format payment condition for display
     *
     * @param array $condition Payment condition from API
     * @return string Formatted label
     */
    public static function format_condition_label($condition) {
        // API returns 'paymentTermLabelTemplate'
        $label = isset($condition['paymentTermLabelTemplate']) ? $condition['paymentTermLabelTemplate'] :
                 (isset($condition['name']) ? $condition['name'] : 'Unbekannt');

        // Add duration if available
        if (isset($condition['paymentTermDuration']) && $condition['paymentTermDuration'] > 0) {
            $label .= sprintf(' (%d Tage)', $condition['paymentTermDuration']);
        } else {
            // Show (0 Tage) for immediate payment
            $label .= ' (0 Tage)';
        }

        // Add Skonto info if available
        if (isset($condition['paymentDiscountConditions']) &&
            !empty($condition['paymentDiscountConditions'])) {
            $discount = $condition['paymentDiscountConditions'];
            if (isset($discount['discountPercentage']) && $discount['discountPercentage'] > 0) {
                $label .= sprintf(
                    ' - %d%% Skonto in %d Tagen',
                    $discount['discountPercentage'],
                    $discount['discountRange']
                );
            }
        }

        return $label;
    }
}
