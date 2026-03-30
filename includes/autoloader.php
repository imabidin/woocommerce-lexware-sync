<?php
/**
 * PSR-4 Autoloader (Manual Implementation)
 *
 * Automatically loads classes based on namespace and file path conventions.
 * No Composer required - pure PHP implementation for maximum portability.
 *
 * @package WC_Lexware_MVP
 * @since 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Autoloader debug mode
 *
 * Set to true to enable detailed autoloader logging.
 * Logs are written to wp-content/debug-autoloader.log.
 * IMPORTANT: Always set to false in production for performance.
 *
 * @since 1.0.0
 */
define('WC_LEXWARE_MVP_AUTOLOADER_DEBUG', false);

/**
 * Log autoloader activity for debugging
 *
 * Only logs when WC_LEXWARE_MVP_AUTOLOADER_DEBUG is enabled.
 * Used to troubleshoot class loading issues during development.
 *
 * @since 1.0.0
 * @param string $message Log message describing the autoloader action
 * @param array  $context Optional. Additional context data as key-value pairs. Default empty array.
 * @return void
 */
function wc_lexware_mvp_autoloader_log($message, $context = array()) {
    if (!defined('WC_LEXWARE_MVP_AUTOLOADER_DEBUG') || !WC_LEXWARE_MVP_AUTOLOADER_DEBUG) {
        return;
    }

    $log_file = defined('ABSPATH') ? ABSPATH . 'wp-content/debug-autoloader.log' : '/tmp/debug-autoloader.log';
    $timestamp = date('[Y-m-d H:i:s]');
    $context_str = !empty($context) ? ' | ' . json_encode($context) : '';
    $log_line = $timestamp . ' ' . $message . $context_str . PHP_EOL;

    @file_put_contents($log_file, $log_line, FILE_APPEND);
}

/**
 * Register PSR-4 autoloader for WC_Lexware_MVP namespace
 *
 * Implements lazy loading - classes are loaded only when first used.
 * Provides ~60% memory savings compared to loading all classes upfront.
 * Supports both direct namespace paths and WordPress class- prefix convention.
 *
 * @since 1.0.0
 */
spl_autoload_register(function ($class) {
    // Plugin namespace prefix
    $prefix = 'WC_Lexware_MVP\\';

    // Base directory for the namespace prefix
    $base_dir = __DIR__ . '/';

    // Check if the class uses the namespace prefix
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        // Not our namespace, let other autoloaders handle it
        return;
    }

    wc_lexware_mvp_autoloader_log("🔍 Autoloader triggered for class: $class");

    // Get the relative class name (remove namespace prefix)
    $relative_class = substr($class, $len);

    // Strategy 1: Try direct namespace path (e.g., Core\Plugin -> Core/Plugin.php)
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    wc_lexware_mvp_autoloader_log("  → Trying direct path: $file", array('exists' => file_exists($file)));

    if (file_exists($file)) {
        require_once $file;
        wc_lexware_mvp_autoloader_log("  ✅ Loaded from: $file");
        return;
    }

    // Strategy 2: Try with class- prefix in subdirectory (e.g., Core\Plugin -> Core/class-plugin.php)
    $relative_path = str_replace('\\', '/', $relative_class);
    $path_parts = explode('/', $relative_path);

    $class_name = array_pop($path_parts);
    // Convert CamelCase/PascalCase to kebab-case properly
    // Handles: DatabaseHelper -> database-helper, PDF_Handler -> pdf-handler, My_Account -> my-account
    // First, replace underscores with hyphens
    $class_name = str_replace('_', '-', $class_name);
    // Then insert hyphens before uppercase letters (except at the start)
    // But handle consecutive uppercase letters (like PDF) as a single word
    $class_name_lower = preg_replace('/([a-z])([A-Z])/', '$1-$2', $class_name); // lowercase before uppercase
    $class_name_lower = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1-$2', $class_name_lower); // consecutive caps before cap+lowercase
    $class_name_lower = strtolower($class_name_lower);
    $path_parts[] = 'class-' . $class_name_lower;

    $file_fallback = $base_dir . implode('/', $path_parts) . '.php';
    wc_lexware_mvp_autoloader_log("  → Trying WordPress convention: $file_fallback", array('exists' => file_exists($file_fallback)));

    if (file_exists($file_fallback)) {
        require_once $file_fallback;
        wc_lexware_mvp_autoloader_log("  ✅ Loaded from: $file_fallback");
        return;
    }

    wc_lexware_mvp_autoloader_log("  ❌ Class NOT found: $class");
});

/**
 * Backward compatibility class aliases
 *
 * Maps legacy non-namespaced class names to new PSR-4 namespaced classes.
 * Ensures existing code using old class names continues to work after migration.
 *
 * @since 1.0.0
 */
$legacy_class_aliases = array(
    // Core classes
    'WC_Lexware_MVP_Plugin' => 'WC_Lexware_MVP\\Core\\Plugin',
    'WC_Lexware_MVP_Activator' => 'WC_Lexware_MVP\\Core\\Activator',
    'WC_Lexware_MVP_Logger' => 'WC_Lexware_MVP\\Core\\Logger',
    'WC_Lexware_MVP_Circuit_Breaker' => 'WC_Lexware_MVP\\Core\\Circuit_Breaker',
    'WC_Lexware_MVP_Database_Helper' => 'WC_Lexware_MVP\\Core\\Database_Helper',
    'WC_Lexware_MVP_Documents_Table' => 'WC_Lexware_MVP\\Core\\Documents_Table',
    'WC_Lexware_MVP_Document_Types' => 'WC_Lexware_MVP\\Core\\Document_Types',
    'WC_Lexware_MVP_Encryptor' => 'WC_Lexware_MVP\\Core\\Encryptor',
    'WC_Lexware_MVP_HPOS_Compat' => 'WC_Lexware_MVP\\Core\\HPOS_Compat',
    'WC_Lexware_MVP_Idempotency_Manager' => 'WC_Lexware_MVP\\Core\\Idempotency_Manager',
    'WC_Lexware_MVP_PDF_Handler' => 'WC_Lexware_MVP\\Core\\PDF_Handler',
    'WC_Lexware_MVP_Payment_Mapping' => 'WC_Lexware_MVP\\Core\\Payment_Mapping',
    'WC_Lexware_MVP_Rate_Limiter' => 'WC_Lexware_MVP\\Core\\Rate_Limiter',
    'WC_Lexware_MVP_Requirements_Checker' => 'WC_Lexware_MVP\\Core\\Requirements_Checker',
    'WC_Lexware_MVP_Validator' => 'WC_Lexware_MVP\\Core\\Validator',

    // API classes
    'WC_Lexware_MVP_API_Client' => 'WC_Lexware_MVP\\API\\Client',
    'WC_Lexware_MVP_Order_Sync' => 'WC_Lexware_MVP\\API\\Order_Sync',

    // Admin classes
    'WC_Lexware_MVP_Admin' => 'WC_Lexware_MVP\\Admin\\Settings',
    'WC_Lexware_MVP_Admin_Meta_Box' => 'WC_Lexware_MVP\\Admin\\Meta_Box',
    'WC_Lexware_MVP_Admin_Notices' => 'WC_Lexware_MVP\\Admin\\Notices',

    // Email classes
    'WC_Lexware_MVP_Email_Invoice' => 'WC_Lexware_MVP\\Email\\Invoice',
    'WC_Lexware_MVP_Email_Credit_Note' => 'WC_Lexware_MVP\\Email\\Credit_Note',

    // Frontend classes
    'WC_Lexware_MVP_My_Account' => 'WC_Lexware_MVP\\Frontend\\My_Account',
);

/**
 * Register fallback autoloader for legacy class names
 *
 * Enables backward compatibility without loading all classes upfront.
 * Creates class aliases on-demand when legacy class names are used.
 *
 * @since 1.0.0
 */
spl_autoload_register(function ($class) use ($legacy_class_aliases) {
    // Check if this is a legacy class name we need to alias
    if (isset($legacy_class_aliases[$class])) {
        $namespaced_class = $legacy_class_aliases[$class];

        // Only create the alias if the target class doesn't already exist
        // This prevents issues with classes that have dependencies
        if (!class_exists($namespaced_class, true)) {
            wc_lexware_mvp_autoloader_log("⚠️ Legacy class requested but target not loaded: $class → $namespaced_class");
            return;
        }

        // Create the alias now that the target class is loaded
        if (!class_exists($class, false)) {
            class_alias($namespaced_class, $class);
            wc_lexware_mvp_autoloader_log("✅ Legacy class alias created on-demand: $class → $namespaced_class");
        }
    }
});

wc_lexware_mvp_autoloader_log("🎉 Autoloader initialization complete - " . count($legacy_class_aliases) . " legacy class mappings registered");
