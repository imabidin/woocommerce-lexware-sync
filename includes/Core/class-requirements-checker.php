<?php
/**
 * Requirements Checker Class
 *
 * Validates system requirements before plugin activation
 *
 * @package WC_Lexware_MVP
 * @since 1.0.0
 */

namespace WC_Lexware_MVP\Core;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Requirements_Checker
 *
 * Checks all system requirements including PHP version, WooCommerce availability,
 * and required PHP extensions.
 */
class Requirements_Checker {

    /**
     * Minimum required PHP version
     */
    const MIN_PHP_VERSION = '7.4';

    /**
     * Minimum required WooCommerce version
     */
    const MIN_WC_VERSION = '8.0';

    /**
     * Check all system requirements
     *
     * Validates:
     * - PHP version >= 7.4
     * - WooCommerce >= 8.0
     * - OpenSSL extension
     * - cURL extension
     * - Upload directory writable
     *
     * @since 1.0.0
     * @return array Array of error messages (empty if all requirements met)
     */
    public static function check() {
        $errors = array();

        // Check PHP version
        if (version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '<')) {
            $errors[] = sprintf(
                __('WooCommerce Lexware MVP benötigt PHP %s oder höher. Aktuelle Version: %s', 'wc-lexware-mvp'),
                self::MIN_PHP_VERSION,
                PHP_VERSION
            );
        }

        // Check if WooCommerce is active
        if (!class_exists('\\WooCommerce', false) && !class_exists('WooCommerce')) {
            $errors[] = sprintf(
                __('WooCommerce Lexware MVP benötigt WooCommerce %s oder höher.', 'wc-lexware-mvp'),
                self::MIN_WC_VERSION
            );
        } elseif (defined('WC_VERSION')) {
            // Check WooCommerce version if available
            if (version_compare(WC_VERSION, self::MIN_WC_VERSION, '<')) {
                $errors[] = sprintf(
                    __('WooCommerce Lexware MVP benötigt WooCommerce %s oder höher. Aktuelle Version: %s', 'wc-lexware-mvp'),
                    self::MIN_WC_VERSION,
                    WC_VERSION
                );
            }
        }

        // Check OpenSSL extension
        if (!extension_loaded('openssl')) {
            $errors[] = __('WooCommerce Lexware MVP benötigt die PHP OpenSSL-Erweiterung.', 'wc-lexware-mvp');
        }

        // Check cURL extension (recommended for API calls)
        if (!extension_loaded('curl')) {
            $errors[] = __('WooCommerce Lexware MVP benötigt die PHP cURL-Erweiterung für API-Kommunikation.', 'wc-lexware-mvp');
        }

        // Check if uploads directory is writable
        $upload_dir = wp_upload_dir();
        if (!wp_is_writable($upload_dir['basedir'])) {
            $errors[] = sprintf(
                __('Das Upload-Verzeichnis ist nicht beschreibbar: %s', 'wc-lexware-mvp'),
                $upload_dir['basedir']
            );
        }

        return $errors;
    }

    /**
     * Check if all requirements are met
     *
     * @since 1.0.0
     * @return bool True if all requirements met, false otherwise
     */
    public static function meets_requirements() {
        $errors = self::check();
        return empty($errors);
    }

    /**
     * Get formatted error message for wp_die()
     *
     * @since 1.0.0
     * @return string HTML formatted error message
     */
    public static function get_wp_die_message() {
        $errors = self::check();

        if (empty($errors)) {
            return '';
        }

        $message = '<h2>' . __('WooCommerce Lexware MVP - Systemanforderungen nicht erfüllt', 'wc-lexware-mvp') . '</h2>';
        $message .= '<ul style="list-style: disc; padding-left: 20px;">';

        foreach ($errors as $error) {
            $message .= '<li>' . esc_html($error) . '</li>';
        }

        $message .= '</ul>';
        $message .= '<p><a href="' . esc_url(admin_url('plugins.php')) . '">' . __('← Zurück zu Plugins', 'wc-lexware-mvp') . '</a></p>';

        return $message;
    }
}
