<?php
/**
 * Input Validator
 *
 * Validates and sanitizes all user inputs for security
 *
 * @package WooCommerce_Lexware_MVP
 * @since 0.3.3
 */

namespace WC_Lexware_MVP\Core;

if (!defined('ABSPATH')) {
    exit;
}

class Validator {

    /**
     * Validate API token format
     *
     * @param string $token API token
     * @return true|\WP_Error
     */
    public static function validate_api_token($token) {
        if (empty($token)) {
            return new \WP_Error('empty_token', __('API-Token darf nicht leer sein.', 'wc-lexware-mvp'));
        }

        $length = strlen($token);
        if ($length < 32 || $length > 128) {
            return new \WP_Error('invalid_length', __('API-Token muss zwischen 32 und 128 Zeichen lang sein.', 'wc-lexware-mvp'));
        }

        // Erlaube: Buchstaben, Zahlen, Unterstrich, Bindestrich, Punkt, Plus, Gleichheitszeichen, Schrägstrich
        if (!preg_match('/^[a-zA-Z0-9_\.\-+=\/]+$/', $token)) {
            return new \WP_Error('invalid_format', __('API-Token enthält ungültige Zeichen.', 'wc-lexware-mvp'));
        }

        return true;
    }

    /**
     * Validate order status
     *
     * @param string $status Order status
     * @return true|\WP_Error
     */
    public static function validate_order_status($status) {
        $valid_statuses = array_keys(wc_get_order_statuses());
        $status_with_prefix = strpos($status, 'wc-') === 0 ? $status : 'wc-' . $status;

        if (!in_array($status_with_prefix, $valid_statuses, true)) {
            return new \WP_Error('invalid_status', __('Ungültiger Bestellstatus.', 'wc-lexware-mvp'));
        }

        return true;
    }
}
