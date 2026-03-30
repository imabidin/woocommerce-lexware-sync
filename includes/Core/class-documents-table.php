<?php
/**
 * Documents Table Manager - Database Schema Management
 *
 * Manages the lexware_mvp_documents table schema and creation.
 * Handles document tracking for invoices, credit notes, and future document types.
 * Includes migration system for safe schema updates.
 *
 * @package WC_Lexware_MVP
 * @since 1.0.0
 */

namespace WC_Lexware_MVP\Core;

if (!defined('ABSPATH')) {
    exit;
}

class Documents_Table {

    /**
     * Option key for storing installed DB version
     *
     * @since 1.2.6
     */
    const DB_VERSION_OPTION = 'wc_lexware_mvp_db_version';

    /**
     * Check and run database migrations if needed
     *
     * Compares installed DB version with current DB version and runs
     * any necessary migrations. Safe to call multiple times.
     *
     * @since 1.2.6
     * @return bool True if migrations were run, false if already up to date
     */
    public static function maybe_upgrade() {
        $installed_version = get_option(self::DB_VERSION_OPTION, '0.0.0');
        $current_version = defined('WC_LEXWARE_MVP_DB_VERSION') ? WC_LEXWARE_MVP_DB_VERSION : '1.0.0';

        // Already up to date
        if (version_compare($installed_version, $current_version, '>=')) {
            return false;
        }

        // Run migrations
        self::run_migrations($installed_version, $current_version);

        // Update stored version
        update_option(self::DB_VERSION_OPTION, $current_version);

        // Log migration
        if (class_exists('\\WC_Lexware_MVP_Logger', false) || class_exists('WC_Lexware_MVP_Logger')) {
            \WC_Lexware_MVP_Logger::info('Database migrated', array(
                'from_version' => $installed_version,
                'to_version' => $current_version
            ));
        }

        return true;
    }

    /**
     * Run database migrations between versions
     *
     * Each migration method handles a specific version upgrade.
     * Migrations are cumulative - upgrading from 0.0.0 to 1.1.0 will run
     * all intermediate migrations in order.
     *
     * @since 1.2.6
     * @param string $from_version Version upgrading from
     * @param string $to_version Version upgrading to
     */
    private static function run_migrations($from_version, $to_version) {
        // Always ensure table exists first
        self::create_table();

        // Define migrations in order (version => method)
        $migrations = array(
            // '1.1.0' => 'migrate_to_1_1_0',
            // '1.2.0' => 'migrate_to_1_2_0',
            // Future migrations will be added here
        );

        foreach ($migrations as $version => $method) {
            // Run migration if:
            // - from_version is less than migration version
            // - to_version is greater than or equal to migration version
            if (version_compare($from_version, $version, '<') && version_compare($to_version, $version, '>=')) {
                if (method_exists(__CLASS__, $method)) {
                    self::$method();

                    if (class_exists('\\WC_Lexware_MVP_Logger', false) || class_exists('WC_Lexware_MVP_Logger')) {
                        \WC_Lexware_MVP_Logger::info('Migration executed', array(
                            'version' => $version,
                            'method' => $method
                        ));
                    }
                }
            }
        }
    }

    /**
     * Example migration method for future use
     *
     * @since 1.2.6
     */
    // private static function migrate_to_1_1_0() {
    //     global $wpdb;
    //     $table_name = wc_lexware_mvp_get_table_name(false);
    //
    //     // Example: Add a new column
    //     $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN new_column VARCHAR(255) DEFAULT NULL");
    // }

    /**
     * Create documents table
     *
     * Creates database table with document chain linking, refund tracking,
     * and optimized indexes for frequent queries.
     *
     * @since 1.0.0
     */
    public static function create_table() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $table_name = wc_lexware_mvp_get_table_name();

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,

            -- Core References
            user_id bigint(20) UNSIGNED DEFAULT NULL COMMENT 'WooCommerce Customer User ID',
            order_id bigint(20) UNSIGNED NOT NULL COMMENT 'WooCommerce Order ID',

            -- Lexware Reference
            contact_id varchar(36) DEFAULT NULL COMMENT 'Lexware Contact UUID',
            document_id varchar(36) DEFAULT NULL COMMENT 'Lexware Document UUID',
            document_nr varchar(50) DEFAULT NULL COMMENT 'Lexware Document Number',
            document_type varchar(20) DEFAULT 'invoice' COMMENT 'Lexware Document Type',
            document_filename varchar(255) DEFAULT NULL COMMENT 'Lexware Document Filename',
            document_status varchar(20) DEFAULT 'pending' COMMENT 'pending, processing, synced, failed',
            document_meta json DEFAULT NULL COMMENT 'Dokumenttyp-spezifische Metadaten (quote_expiration_date, quote_status, delivery_terms)',
            document_finalized tinyint(1) DEFAULT 0 COMMENT 'Lexware Finalized Status',

            -- Document Chain Linking (Quotation→Order Confirmation→Delivery Note→Invoice→Credit Note)
            preceding_document_id varchar(36) DEFAULT NULL COMMENT 'Parent Lexware Document UUID',
            preceding_document_nr varchar(50) DEFAULT NULL COMMENT 'Parent Document Number',

            -- Refund Reference for Credit Notes
            refund_id bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Woocommerce Refund ID (for Credit Notes)',
            refund_reason text DEFAULT NULL COMMENT 'Woocommerce Refund Reason (from Admin)',
            refund_amount decimal(10,2) DEFAULT NULL COMMENT 'Woocommerce Refund Amount',
            refund_full tinyint(1) DEFAULT 0 COMMENT 'Woocommerce Refund Flag (full or partial)',

            -- Timestamps
            created_at datetime DEFAULT CURRENT_TIMESTAMP COMMENT 'DB Record created',
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'DB Record updated',
            synced_at datetime DEFAULT NULL COMMENT 'Synced with Lexware',
            email_sent_at datetime DEFAULT NULL COMMENT 'Email sent to customer',

            PRIMARY KEY (id),

            -- Indexes (optimiert für häufige Queries)
            KEY order_id (order_id),
            KEY order_type_document_status (order_id, document_type, document_status),
            KEY document_type_status_order (document_type, document_status, order_id),
            KEY user_id (user_id),
            KEY document_type (document_type),
            KEY document_status (document_status),
            KEY preceding_document_id (preceding_document_id),
            KEY refund_id (refund_id),
            KEY created_at (created_at)
        ) {$charset_collate} COMMENT='Lexware Dokumente (Rechnungen, Gutschriften, etc)';";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Delete all documents for a given order ID
     *
     * Called when an order is permanently deleted to clean up
     * associated document records. Also deletes associated PDF files.
     *
     * @since 1.2.6
     * @param int $order_id WooCommerce Order ID
     * @return int Number of deleted records
     */
    public static function delete_by_order_id($order_id) {
        global $wpdb;

        $order_id = absint($order_id);
        if ($order_id <= 0) {
            return 0;
        }

        $table_name = wc_lexware_mvp_get_table_name(false);

        // First, get all document filenames to delete PDFs
        $documents = $wpdb->get_results($wpdb->prepare(
            "SELECT id, document_filename, document_type FROM {$table_name} WHERE order_id = %d",
            $order_id
        ));

        if (empty($documents)) {
            return 0;
        }

        // Delete associated PDF files
        if (class_exists('\\WC_Lexware_MVP_PDF_Handler', false) || class_exists('WC_Lexware_MVP_PDF_Handler')) {
            $pdf_handler = \WC_Lexware_MVP_PDF_Handler::get_instance();

            foreach ($documents as $doc) {
                if (!empty($doc->document_filename)) {
                    $pdf_handler->delete_pdf($doc->document_filename);
                }
            }
        }

        // Delete database records
        $deleted = $wpdb->delete(
            $table_name,
            array('order_id' => $order_id),
            array('%d')
        );

        // Log cleanup
        if (class_exists('\\WC_Lexware_MVP_Logger', false) || class_exists('WC_Lexware_MVP_Logger')) {
            \WC_Lexware_MVP_Logger::info('Documents cleaned up for deleted order', array(
                'order_id' => $order_id,
                'documents_deleted' => $deleted,
                'pdf_files_deleted' => count($documents)
            ));
        }

        return $deleted;
    }

    /**
     * Check if table exists
     *
     * @since 1.2.6
     * @return bool True if table exists
     */
    public static function table_exists() {
        global $wpdb;

        $table_name = wc_lexware_mvp_get_table_name(false);
        $result = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $table_name
        ));

        return ($result === $table_name);
    }
}
