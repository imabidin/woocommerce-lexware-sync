<?php
/**
 * Plugin Name: WooCommerce Lexware MVP
 * Plugin URI: https://github.com/imabidin/woocommerce-lexware-sync
 * Description: Einfache Lexware Office Integration - Automatische Rechnungs- & Gutschrifterstellung inkl. Email-Versand
 * Version: 1.4.0
 * Author: Abidin Alkilinc
 * Author URI: https://github.com/imabidin
 * License: GPL-2.0+
 * Text Domain: wc-lexware-mvp
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 9.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WC_LEXWARE_MVP_VERSION', '1.4.0');
define('WC_LEXWARE_MVP_DB_VERSION', '1.0.0');
define('WC_LEXWARE_MVP_FILE', __FILE__);
define('WC_LEXWARE_MVP_PATH', plugin_dir_path(__FILE__));
define('WC_LEXWARE_MVP_URL', plugin_dir_url(__FILE__));
define('WC_LEXWARE_MVP_TEXT_DOMAIN', 'wc-lexware-mvp');

// Load PSR-4 autoloader (enables lazy loading of all classes)
require_once WC_LEXWARE_MVP_PATH . 'includes/autoloader.php';

/**
 * Database table name helper functions
 *
 * Provides convenient global access to database table names with proper escaping.
 * These functions delegate to Database_Helper class for centralized table management.
 *
 * @since 1.0.0
 */

if (!function_exists('wc_lexware_mvp_get_table_name')) {
    /**
     * Get documents table name with WordPress prefix
     *
     * @since 1.0.0
     * @param bool $with_backticks Whether to wrap table name in backticks for raw SQL queries (default: true)
     * @return string Database table name with prefix, e.g., "wp_lexware_mvp_documents" or "`wp_lexware_mvp_documents`"
     */
    function wc_lexware_mvp_get_table_name($with_backticks = true) {
        return \WC_Lexware_MVP\Core\Database_Helper::get_documents_table_name($with_backticks);
    }
}

if (!function_exists('wc_lexware_mvp_get_rate_limiter_table_name')) {
    /**
     * Get rate limiter table name with WordPress prefix
     *
     * @since 1.0.0
     * @param bool $with_backticks Whether to wrap table name in backticks for raw SQL queries (default: true)
     * @return string Rate limiter table name with prefix, e.g., "wp_lexware_mvp_rate_limiter" or "`wp_lexware_mvp_rate_limiter`"
     */
    function wc_lexware_mvp_get_rate_limiter_table_name($with_backticks = true) {
        return \WC_Lexware_MVP\Core\Database_Helper::get_rate_limiter_table_name($with_backticks);
    }
}

/**
 * Plugin activation hook wrapper
 *
 * Ensures autoloader is loaded before activation and delegates
 * to the main Plugin class for database table creation and setup.
 *
 * @since 1.0.0
 * @see WC_Lexware_MVP\Core\Plugin::activate()
 */
function wc_lexware_mvp_activate_wrapper() {
    // Autoloader already loaded above
    \WC_Lexware_MVP\Core\Plugin::activate();
}

/**
 * Plugin activation hook
 *
 * Registers the activation callback that sets up database tables
 * and initial plugin configuration.
 *
 * @since 1.0.0
 */
register_activation_hook(__FILE__, 'wc_lexware_mvp_activate_wrapper');

/**
 * Plugin deactivation hook
 *
 * Cleans up scheduled events but preserves database data.
 * For complete data removal, user must uninstall the plugin.
 *
 * @since 1.0.0
 * @see WC_Lexware_MVP\Core\Plugin::deactivate()
 */
register_deactivation_hook(__FILE__, array('WC_Lexware_MVP\\Core\\Plugin', 'deactivate'));

/**
 * Initialize plugin on plugins_loaded hook
 *
 * Loads after WordPress core and WooCommerce are available.
 * Priority 20 ensures WooCommerce is fully loaded first.
 *
 * @since 1.0.0
 * @see WC_Lexware_MVP\Core\Plugin::init()
 */
add_action('plugins_loaded', array('WC_Lexware_MVP\\Core\\Plugin', 'init'), 20);

/**
 * Declare HPOS (High-Performance Order Storage) compatibility
 *
 * Informs WooCommerce that this plugin is compatible with
 * the new Custom Order Tables feature introduced in WooCommerce 8.0+
 *
 * @since 1.0.0
 * @see https://github.com/woocommerce/woocommerce/wiki/High-Performance-Order-Storage-Upgrade-Recipe-Book
 */
add_action('before_woocommerce_init', array('WC_Lexware_MVP\\Core\\Plugin', 'declare_hpos_compatibility'));
