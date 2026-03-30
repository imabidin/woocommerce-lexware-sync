<?php
/**
 * Logger Wrapper - Structured Logging with GDPR Compliance
 *
 * Wraps WooCommerce Logger (WC_Logger) with automatic sensitive data redaction,
 * structured context, and consistent formatting across the plugin.
 *
 * @package WC_Lexware_MVP
 * @since 1.0.0
 */

namespace WC_Lexware_MVP\Core;

if (!defined('ABSPATH')) {
    exit;
}

class Logger {

    /**
     * WooCommerce Logger instance
     *
     * @since 1.0.0
     * @var \WC_Logger
     */
    private static $logger;

    /**
     * Log source identifier
     *
     * @since 1.0.0
     */
    const SOURCE = 'wc-lexware-mvp';

    /**
     * Sensitive data patterns for GDPR-compliant logging
     * Keys that should be redacted in log output
     */
    private static $sensitive_keys = array(
        // Authentication & Security
        'token', 'api_token', 'api_key', 'apiKey', 'authorization', 'bearer',
        'password', 'passwd', 'secret', 'private_key', 'privateKey',

        // Personal Identifiable Information (PII)
        'email', 'emailAddress', 'billing_email', 'customer_email',
        'phone', 'telephone', 'mobile', 'billing_phone',

        // Financial Data
        'iban', 'bic', 'accountNumber', 'account_number', 'card_number', 'cvv', 'cvc',

        // Address Data (partial redaction)
        'street', 'address', 'address_1', 'address_2', 'billing_address_1', 'billing_address_2',
        'shipping_address_1', 'shipping_address_2',
    );

    /**
     * Keys that should keep last 4 characters visible (e.g., IBANs)
     */
    private static $partial_redact_keys = array(
        'iban', 'accountNumber', 'account_number'
    );

    /**
     * Get logger instance
     *
     * Lazy loads WooCommerce logger.
     *
     * @since 1.0.0
     * @return \WC_Logger WooCommerce logger instance
     */
    private static function get_logger() {
        if (null === self::$logger) {
            self::$logger = wc_get_logger();
        }
        return self::$logger;
    }

    /**
     * Format context with standard fields and redact sensitive data
     *
     * Adds timestamp, version info, and user context.
     * Automatically redacts PII and sensitive data for GDPR compliance.
     *
     * @since 1.0.0
     * @param array $context Additional context data to log
     * @return array Formatted and sanitized context
     */
    private static function format_context($context = array()) {
        $base_context = array(
            'source' => self::SOURCE,
            'timestamp' => current_time('mysql'),
            'php_version' => PHP_VERSION,
            'wc_version' => defined('WC_VERSION') ? WC_VERSION : 'unknown',
        );

        // Add user context if available
        if (is_user_logged_in()) {
            $base_context['user_id'] = get_current_user_id();
        }

        $merged_context = array_merge($base_context, $context);

        // GDPR Compliance: Redact sensitive data if enabled
        if (self::is_redaction_enabled()) {
            $merged_context = self::redact_sensitive_data($merged_context);
        }

        return $merged_context;
    }

    /**
     * Check if sensitive data redaction is enabled
     *
     * Default: enabled in production (unless explicitly disabled).
     * Can be overridden via WC_LEXWARE_MVP_DISABLE_LOG_REDACTION constant.
     *
     * @since 1.0.0
     * @return bool True if redaction is enabled
     */
    private static function is_redaction_enabled() {
        // Default: enabled in production (unless explicitly disabled)
        $setting = get_option('wc_lexware_mvp_redact_sensitive_logs', 'yes');

        // Allow override via constant for development
        if (defined('WC_LEXWARE_MVP_DISABLE_LOG_REDACTION') && WC_LEXWARE_MVP_DISABLE_LOG_REDACTION) {
            return false;
        }

        return $setting === 'yes';
    }

    /**
     * Redact sensitive data from context array (recursive)
     *
     * Applies different redaction strategies:
     * - Partial redaction for IBAN (show last 4 characters)
     * - Email redaction (show first char + domain)
     * - Full redaction for passwords, tokens, API keys
     *
     * @since 1.0.0
     * @param array $data Data to redact
     * @return array Redacted data with sensitive values replaced
     */
    private static function redact_sensitive_data($data) {
        if (!is_array($data)) {
            return $data;
        }

        foreach ($data as $key => $value) {
            // Recursive redaction for nested arrays
            if (is_array($value)) {
                $data[$key] = self::redact_sensitive_data($value);
                continue;
            }

            // Skip non-string values
            if (!is_string($value) && !is_numeric($value)) {
                continue;
            }

            $value = (string) $value;

            // Check if key is sensitive (case-insensitive)
            $key_lower = strtolower($key);
            $is_sensitive = false;

            foreach (self::$sensitive_keys as $sensitive_key) {
                if (stripos($key_lower, strtolower($sensitive_key)) !== false) {
                    $is_sensitive = true;
                    break;
                }
            }

            if ($is_sensitive) {
                // Partial redaction for specific keys (e.g., IBAN - show last 4)
                if (in_array($key, self::$partial_redact_keys) && strlen($value) > 4) {
                    $data[$key] = str_repeat('*', strlen($value) - 4) . substr($value, -4);
                }
                // Email redaction (show first char + domain)
                elseif (filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $parts = explode('@', $value);
                    if (count($parts) === 2) {
                        $data[$key] = substr($parts[0], 0, 1) . '***@' . $parts[1];
                    } else {
                        $data[$key] = '***REDACTED***';
                    }
                }
                // Full redaction for tokens, passwords, etc.
                else {
                    $data[$key] = '***REDACTED***';
                }
            }
        }

        return $data;
    }

    /**
     * Get configured log level
     *
     * Priority:
     * 1. Environment Variable (WC_LEXWARE_MVP_LOG_LEVEL constant)
     * 2. Admin Setting (wc_lexware_mvp_log_level option)
     * 3. WP_DEBUG fallback (debug if WP_DEBUG=true, info otherwise)
     *
     * @since 1.0.0
     * @return string Log level (debug|info|warning|error)
     */
    private static function get_log_level() {
        // 1. Check environment variable
        if (defined('WC_LEXWARE_MVP_LOG_LEVEL')) {
            return strtolower(WC_LEXWARE_MVP_LOG_LEVEL);
        }

        // 2. Check admin setting
        $setting = get_option('wc_lexware_mvp_log_level', 'auto');
        if ($setting !== 'auto') {
            return $setting;
        }

        // 3. Fallback to WP_DEBUG behavior
        return (defined('WP_DEBUG') && WP_DEBUG) ? 'debug' : 'info';
    }

    /**
     * Check if message should be logged based on configured level
     *
     * Compares message priority with configured minimum log level.
     *
     * @since 1.0.0
     * @param string $level Message level (debug|info|notice|warning|error|critical)
     * @return bool True if message should be logged
     */
    private static function should_log($level) {
        $levels = array('debug' => 0, 'info' => 1, 'notice' => 2, 'warning' => 3, 'error' => 4, 'critical' => 5);
        $configured_level = self::get_log_level();

        $message_priority = isset($levels[$level]) ? $levels[$level] : 0;
        $configured_priority = isset($levels[$configured_level]) ? $levels[$configured_level] : 0;

        return $message_priority >= $configured_priority;
    }

    /**
     * Log debug message
     *
     * Only logged when log level is 'debug'.
     * Use for detailed diagnostic information.
     *
     * @since 1.0.0
     * @param string $message Log message
     * @param array $context Additional context data
     *
     * @example Logger::debug('Processing order', ['order_id' => 123]);
     */
    public static function debug($message, $context = array()) {
        if (!self::should_log('debug')) {
            return;
        }

        self::get_logger()->debug($message, self::format_context($context));
    }

    /**
     * Log info message
     *
     * Logged when log level is 'info' or lower.
     * Use for informational messages.
     *
     * @since 1.0.0
     * @param string $message Log message
     * @param array $context Additional context data
     *
     * @example Logger::info('Invoice sent successfully', ['order_id' => 123]);
     */
    public static function info($message, $context = array()) {
        if (!self::should_log('info')) {
            return;
        }

        self::get_logger()->info($message, self::format_context($context));
    }

    /**
     * Log notice message
     *
     * Always logged. Use for normal but significant conditions.
     *
     * @since 1.0.0
     * @param string $message Log message
     * @param array $context Additional context data
     *
     * @example Logger::notice('Rate limit approaching', ['remaining' => 5]);
     */
    public static function notice($message, $context = array()) {
        self::get_logger()->notice($message, self::format_context($context));
    }

    /**
     * Log warning message
     *
     * Always logged. Use for exceptional occurrences that are not errors.
     *
     * @since 1.0.0
     * @param string $message Log message
     * @param array $context Additional context data
     *
     * @example Logger::warning('API response slow', ['duration' => 5.2]);
     */
    public static function warning($message, $context = array()) {
        self::get_logger()->warning($message, self::format_context($context));
    }

    /**
     * Log error message
     *
     * Always logged. Use for runtime errors that should be investigated.
     *
     * @since 1.0.0
     * @param string $message Log message
     * @param array $context Additional context data
     *
     * @example Logger::error('API request failed', ['order_id' => 123, 'error' => 'timeout']);
     */
    public static function error($message, $context = array()) {
        self::get_logger()->error($message, self::format_context($context));
    }

    /**
     * Log critical message
     *
     * Always logged. Use for critical conditions requiring immediate action.
     *
     * @since 1.0.0
     * @param string $message Log message
     * @param array $context Additional context data
     *
     * @example Logger::critical('Database connection lost', ['error' => $e->getMessage()]);
     */
    public static function critical($message, $context = array()) {
        self::get_logger()->critical($message, self::format_context($context));
    }

    /**
     * Get latest error for order/document from logs
     *
     * Searches recent log files for the most recent error message
     * related to a specific order and document type.
     *
     * @since 1.0.0
     * @param int $order_id Order ID to search for
     * @param string $document_type Document type (invoice|credit_note)
     * @return string|null Error message or null if none found
     *
     * @example $error = Logger::get_latest_error(123, 'invoice');
     */
    public static function get_latest_error($order_id, $document_type = 'invoice') {
        // WooCommerce stores logs in wp-content/uploads/wc-logs/
        $log_files = self::get_log_files();

        if (empty($log_files)) {
            return null;
        }

        // Search latest log file for order_id errors
        $search_pattern = $document_type === 'credit_note' ? 'Credit Note API Error' : 'Invoice API Error';

        foreach ($log_files as $log_file) {
            $content = file_get_contents($log_file);
            if (!$content) continue;

            // Parse log entries for this order
            $lines = explode("\n", $content);
            $latest_error = null;

            foreach (array_reverse($lines) as $line) {
                if (strpos($line, $search_pattern) !== false && strpos($line, "'order_id' => {$order_id}") !== false) {
                    // Extract error message from log line
                    if (preg_match("/'error' => '([^']+)'/", $line, $matches)) {
                        return $matches[1];
                    }
                    break;
                }
            }
        }

        return null;
    }

    /**
     * Get WooCommerce log files for this plugin
     *
     * Returns the 3 most recent log files sorted by modification time.
     *
     * @since 1.0.0
     * @return array Array of log file paths (absolute paths)
     *
     * @example ['wp-content/uploads/wc-logs/wc-lexware-mvp-2024-01-15.log', ...]
     */
    private static function get_log_files() {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/wc-logs/';

        if (!is_dir($log_dir)) {
            return array();
        }

        $files = glob($log_dir . self::SOURCE . '-*.log');

        if (!$files) {
            return array();
        }

        // Sort by modification time (newest first)
        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        // Return only the 3 most recent log files
        return array_slice($files, 0, 3);
    }
}
