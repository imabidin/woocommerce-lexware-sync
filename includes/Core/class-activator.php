<?php
/**
 * Plugin Activator - Installation & Setup
 *
 * Handles plugin activation tasks:
 * - Database table creation
 * - Default option setup
 * - Upload directory initialization
 *
 * @package WC_Lexware_MVP
 * @since 1.0.0
 */

namespace WC_Lexware_MVP\Core;

class Activator {

    /**
     * Run on plugin activation
     *
     * @since 1.0.0
     */
    public static function activate() {
        // Create documents tables
        self::create_documents_tables();

        // Create rate limiter table
        self::create_rate_limiter_table();

        // Set default options
        self::set_default_options();

        // Create upload directory for documents
        $directory_status = self::create_upload_directory();

        // Store activation status for admin notice
        update_option('wc_lexware_mvp_activation_status', $directory_status);
    }

    /**
     * Create or upgrade custom database tables
     *
     * Uses the migration system to ensure tables are created
     * and upgraded to the latest schema version.
     *
     * @since 1.0.0
     * @since 1.2.6 Now uses migration system
     */
    private static function create_documents_tables() {
        // Trigger autoload before using
        if (!class_exists('\\WC_Lexware_MVP_Documents_Table', false)) {
            class_exists('\\WC_Lexware_MVP\\Core\\Documents_Table');
        }

        // Use migration system (creates table if not exists, runs migrations if needed)
        \WC_Lexware_MVP_Documents_Table::maybe_upgrade();
    }

    /**
     * Create rate limiter table for multi-server environments
     *
     * @since 1.0.0
     */
    private static function create_rate_limiter_table() {
        // Trigger autoload before using
        if (!class_exists('\\WC_Lexware_MVP_Rate_Limiter', false)) {
            class_exists('\\WC_Lexware_MVP\\Core\\Rate_Limiter');
        }
        \WC_Lexware_MVP_Rate_Limiter::create_table();
    }

    /**
     * Create protected upload directory
     *
     * @since 1.0.0
     * @return array Status with details about directory, .htaccess and index.php
     */
    private static function create_upload_directory() {
        $status = array(
            'success' => false,
            'directory_created' => false,
            'htaccess_created' => false,
            'index_created' => false,
            'directory_path' => '',
            'errors' => array()
        );

        // Trigger autoload of PDF_Handler before using it
        if (!class_exists('\\WC_Lexware_MVP_PDF_Handler', false)) {
            class_exists('\\WC_Lexware_MVP\\Core\\PDF_Handler');
        }

        $lexware_dir = \WC_Lexware_MVP_PDF_Handler::get_pdf_directory();
        $status['directory_path'] = $lexware_dir;

        // Create directory if it doesn't exist
        if (!file_exists($lexware_dir)) {
            if (!wp_mkdir_p($lexware_dir)) {
                $status['errors'][] = sprintf('Konnte Verzeichnis nicht erstellen: %s (Berechtigung prüfen)', $lexware_dir);
                return $status;
            }
            $status['directory_created'] = true;
        } else {
            $status['directory_created'] = true;

            // Check write permissions for existing directory
            if (!is_writable($lexware_dir)) {
                $status['errors'][] = sprintf('Verzeichnis ist nicht beschreibbar: %s (chmod 755 empfohlen)', $lexware_dir);
                return $status;
            }
        }

        // Create .htaccess for protection
        $htaccess_file = $lexware_dir . '/.htaccess';
        if (!file_exists($htaccess_file)) {
            $htaccess_content = "deny from all\n";
            if (@file_put_contents($htaccess_file, $htaccess_content) === false) {
                $status['errors'][] = 'Konnte .htaccess nicht erstellen (Schreibrechte prüfen)';
            } else {
                $status['htaccess_created'] = true;
            }
        } else {
            $status['htaccess_created'] = true;
        }

        // Create index.php
        $index_file = $lexware_dir . '/index.php';
        if (!file_exists($index_file)) {
            $index_content = '<?php // Silence is golden';
            if (@file_put_contents($index_file, $index_content) === false) {
                $status['errors'][] = 'Konnte index.php nicht erstellen (Schreibrechte prüfen)';
            } else {
                $status['index_created'] = true;
            }
        } else {
            $status['index_created'] = true;
        }

		// Success if all required files and directory were created
        $status['success'] = $status['directory_created'] && $status['htaccess_created'] && $status['index_created'];

        return $status;
    }

    /**
     * Set default options
     *
     * @since 1.0.0
     */
    private static function set_default_options() {
        if (false === get_option('wc_lexware_mvp_invoice_trigger_statuses')) {
            add_option('wc_lexware_mvp_invoice_trigger_statuses', array('wc-completed'));
        }

        if (false === get_option('wc_lexware_mvp_credit_note_trigger_statuses')) {
            add_option('wc_lexware_mvp_credit_note_trigger_statuses', array('wc-refunded'));
        }

        if (false === get_option('wc_lexware_mvp_auto_finalize_invoice')) {
            add_option('wc_lexware_mvp_auto_finalize_invoice', 'yes');
        }

        if (false === get_option('wc_lexware_mvp_auto_finalize_credit_note')) {
            add_option('wc_lexware_mvp_auto_finalize_credit_note', 'yes');
        }

        if (false === get_option('wc_lexware_mvp_send_credit_note_email')) {
            add_option('wc_lexware_mvp_send_credit_note_email', 'yes');
        }

        // Order Confirmation defaults (disabled by default)
        if (false === get_option('wc_lexware_mvp_order_confirmation_trigger_statuses')) {
            add_option('wc_lexware_mvp_order_confirmation_trigger_statuses', array()); // Default: disabled
        }

        if (false === get_option('wc_lexware_mvp_auto_finalize_order_confirmation')) {
            add_option('wc_lexware_mvp_auto_finalize_order_confirmation', 'yes');
        }

        if (false === get_option('wc_lexware_mvp_send_order_confirmation_email')) {
            add_option('wc_lexware_mvp_send_order_confirmation_email', 'yes');
        }
    }
}
