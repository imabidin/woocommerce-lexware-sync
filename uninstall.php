<?php
/**
 * Uninstall script - DSGVO-compliant full cleanup
 *
 * Removes ALL plugin data including:
 * - Database tables (lexware_mvp_documents, lexware_mvp_rate_limiter)
 * - Stored PDFs
 * - Encrypted credentials
 * - Order meta data
 * - Plugin options
 * - Scheduled events
 */

// Exit if accessed directly
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Load table name helper function
require_once plugin_dir_path(__FILE__) . 'woocommerce-lexware-mvp.php';

// 1. Delete Database Tables
$table_documents = wc_lexware_mvp_get_table_name(false);
$table_rate_limiter = wc_lexware_mvp_get_rate_limiter_table_name(false);

// Note: $wpdb->prepare() does NOT support table name placeholders per WordPress documentation
// Table names must be directly interpolated (they are already escaped by helper functions)
$wpdb->query("DROP TABLE IF EXISTS `{$table_documents}`");
$wpdb->query("DROP TABLE IF EXISTS `{$table_rate_limiter}`");

// 2. Delete stored PDFs
$upload_dir = wp_upload_dir();
$pdf_directory = $upload_dir['basedir'] . '/lexware-invoices';

if (is_dir($pdf_directory)) {
    // Delete all files in directory
    $files = glob($pdf_directory . '/*');
    foreach ($files as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }

    // Delete directory
    @rmdir($pdf_directory);
}

// 3. Delete all plugin options
delete_option('wc_lexware_mvp_api_token');
delete_option('wc_lexware_mvp_invoice_trigger_statuses');
delete_option('wc_lexware_mvp_credit_note_trigger_statuses');
delete_option('wc_lexware_mvp_send_credit_note_email');
delete_option('wc_lexware_mvp_log_level');

// 4. Delete order meta data (HPOS compatible)
// Check if HPOS is enabled
if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil') &&
    method_exists('Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled') &&
    \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {

    // HPOS: Delete from custom order meta table
    $orders_meta_table = $wpdb->prefix . 'wc_orders_meta';
    $wpdb->query("DELETE FROM `{$orders_meta_table}` WHERE meta_key = '_lexware_processed_refund_ids'");
    $wpdb->query("DELETE FROM `{$orders_meta_table}` WHERE meta_key = '_lexware_document_id'");
    $wpdb->query("DELETE FROM `{$orders_meta_table}` WHERE meta_key = '_lexware_document_number'");
} else {
    // Legacy: Delete from postmeta
    $wpdb->query("DELETE FROM `{$wpdb->postmeta}` WHERE meta_key = '_lexware_processed_refund_ids'");
    $wpdb->query("DELETE FROM `{$wpdb->postmeta}` WHERE meta_key = '_lexware_document_id'");
    $wpdb->query("DELETE FROM `{$wpdb->postmeta}` WHERE meta_key = '_lexware_document_number'");
}

// 5. Clear all scheduled events
wp_clear_scheduled_hook('wc_lexware_mvp_retry_sync');
wp_clear_scheduled_hook('wc_lexware_mvp_process_invoice');
wp_clear_scheduled_hook('wc_lexware_mvp_process_credit_note');

// 6. Clear Action Scheduler entries (if Action Scheduler exists)
if (function_exists('as_unschedule_all_actions')) {
    as_unschedule_all_actions('wc_lexware_mvp_process_invoice', array(), 'lexware-mvp');
    as_unschedule_all_actions('wc_lexware_mvp_process_credit_note', array(), 'lexware-mvp');
}
