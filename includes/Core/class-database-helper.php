<?php
/**
 * Database Helper Class
 *
 * Provides secure database table name handling with proper escaping
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
 * Class Database_Helper
 *
 * Handles all database-related utility functions including table name generation
 * with proper prefix handling and SQL injection protection.
 *
 * @since 1.0.0
 */
class Database_Helper {

    /**
     * Main documents table name (without prefix)
     *
     * @since 1.0.0
     */
    const TABLE_DOCUMENTS = 'lexware_mvp_documents';

    /**
     * Rate limiter table name (without prefix)
     *
     * @since 1.0.0
     */
    const TABLE_RATE_LIMITER = 'lexware_mvp_rate_limiter';

    /**
     * Get main documents table name with prefix
     *
     * @since 1.0.0
     * @param bool $with_backticks Whether to include backticks for raw SQL queries (default: true)
     * @return string Table name with WordPress prefix, e.g., "wp_lexware_mvp_documents" or "`wp_lexware_mvp_documents`"
     */
    public static function get_documents_table_name($with_backticks = true) {
        global $wpdb;

        // Validate table name (alphanumeric + underscore only)
        $validated_table = preg_replace('/[^a-zA-Z0-9_]/', '', self::TABLE_DOCUMENTS);
        $table_name = $wpdb->prefix . $validated_table;

        return $with_backticks ? '`' . $table_name . '`' : $table_name;
    }

    /**
     * Get rate limiter table name with prefix
     *
     * @since 1.0.0
     * @param bool $with_backticks Whether to include backticks for raw SQL queries (default: true)
     * @return string Rate limiter table name with WordPress prefix, e.g., "wp_lexware_mvp_rate_limiter" or "`wp_lexware_mvp_rate_limiter`"
     */
    public static function get_rate_limiter_table_name($with_backticks = true) {
        global $wpdb;

        // Validate table name (alphanumeric + underscore only)
        $validated_table = preg_replace('/[^a-zA-Z0-9_]/', '', self::TABLE_RATE_LIMITER);
        $table_name = $wpdb->prefix . $validated_table;

        return $with_backticks ? '`' . $table_name . '`' : $table_name;
    }

    /**
     * Check if documents table exists
     *
     * @since 1.0.0
     * @return bool True if table exists, false otherwise
     */
    public static function documents_table_exists() {
        global $wpdb;

        $table_name = self::get_documents_table_name(false);
        $query = $wpdb->prepare('SHOW TABLES LIKE %s', $table_name);

        return $wpdb->get_var($query) === $table_name;
    }

    /**
     * Check if rate limiter table exists
     *
     * @since 1.0.0
     * @return bool True if table exists, false otherwise
     */
    public static function rate_limiter_table_exists() {
        global $wpdb;

        $table_name = self::get_rate_limiter_table_name(false);
        $query = $wpdb->prepare('SHOW TABLES LIKE %s', $table_name);

        return $wpdb->get_var($query) === $table_name;
    }

    /**
     * Get database charset and collation
     *
     * @since 1.0.0
     * @return string Charset collate string for CREATE TABLE statements, e.g., "DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
     */
    public static function get_charset_collate() {
        global $wpdb;
        return $wpdb->get_charset_collate();
    }
}
