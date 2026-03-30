<?php
/**
 * Document Types Registry - Central Document Type Management
 *
 * Provides type safety via constants, central labels & translations,
 * hierarchy levels for sorting, and workflow validation.
 * Future-proof for quotation, delivery note, etc.
 *
 * @package WC_Lexware_MVP
 * @since 1.0.0
 */

namespace WC_Lexware_MVP\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Document Types Registry Class
 *
 * @since 1.0.0
 */
class Document_Types {

    /**
     * Document Type Constants
     *
     * @since 1.0.0
     */
    const QUOTATION = 'quotation';                    // Offer (before order)
    const ORDER_CONFIRMATION = 'order_confirmation';  // Order confirmation (after order placed)
    const INVOICE = 'invoice';                        // Invoice (after order)
    const CREDIT_NOTE = 'credit_note';                // Credit note (after invoice)
    const DELIVERY_NOTE = 'delivery_note';            // Delivery note (future)
    const PROFORMA = 'proforma';                      // Proforma invoice (future)
    const REMINDER = 'reminder';                      // Payment reminder (future)

    /**
     * Get all registered document types
     *
     * @since 1.0.0
     * @return array List of valid document types
     */
    public static function get_all_types() {
        return array(
            self::QUOTATION,
            self::ORDER_CONFIRMATION,
            self::INVOICE,
            self::CREDIT_NOTE,
            self::DELIVERY_NOTE,
            self::PROFORMA,
            self::REMINDER
        );
    }

    /**
     * Get currently supported document types (MVP)
     *
     * Only these types are actively used, rest is preparation.
     *
     * @since 1.0.0
     * @return array Supported document types (invoice, credit_note)
     */
    public static function get_supported_types() {
        return array(
            self::ORDER_CONFIRMATION,
            self::INVOICE,
            self::CREDIT_NOTE
        );
    }

    /**
     * Check if document type is valid
     *
     * @since 1.0.0
     * @param string $type Document type to check
     * @return bool True if valid
     */
    public static function is_valid($type) {
        return in_array($type, self::get_all_types(), true);
    }

    /**
     * Check if document type is currently supported
     *
     * @since 1.0.0
     * @param string $type Document type to check
     * @return bool True if supported
     */
    public static function is_supported($type) {
        return in_array($type, self::get_supported_types(), true);
    }

    /**
     * Validate document type and throw exception if invalid
     *
     * @since 1.0.0
     * @param string $type Document type to validate
     * @throws \InvalidArgumentException If type is invalid
     */
    public static function validate($type) {
        if (!self::is_valid($type)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Invalid document type: %s. Valid types: %s',
                    $type,
                    implode(', ', self::get_all_types())
                )
            );
        }
    }

    /**
     * Get document type label (singular or plural)
     *
     * @since 1.0.0
     * @param string $type Document type
     * @param bool $plural Return plural form
     * @return string Translated label
     *
     * @example get_label('invoice', false) returns 'Rechnung'
     */
    public static function get_label($type, $plural = false) {
        $labels = array(
            self::QUOTATION => array(
                'singular' => __('Angebot', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'plural' => __('Angebote', WC_LEXWARE_MVP_TEXT_DOMAIN)
            ),
            self::ORDER_CONFIRMATION => array(
                'singular' => __('Auftragsbestätigung', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'plural' => __('Auftragsbestätigungen', WC_LEXWARE_MVP_TEXT_DOMAIN)
            ),
            self::INVOICE => array(
                'singular' => __('Rechnung', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'plural' => __('Rechnungen', WC_LEXWARE_MVP_TEXT_DOMAIN)
            ),
            self::CREDIT_NOTE => array(
                'singular' => __('Gutschrift', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'plural' => __('Gutschriften', WC_LEXWARE_MVP_TEXT_DOMAIN)
            ),
            self::DELIVERY_NOTE => array(
                'singular' => __('Lieferschein', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'plural' => __('Lieferscheine', WC_LEXWARE_MVP_TEXT_DOMAIN)
            ),
            self::PROFORMA => array(
                'singular' => __('Proforma-Rechnung', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'plural' => __('Proforma-Rechnungen', WC_LEXWARE_MVP_TEXT_DOMAIN)
            ),
            self::REMINDER => array(
                'singular' => __('Mahnung', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'plural' => __('Mahnungen', WC_LEXWARE_MVP_TEXT_DOMAIN)
            )
        );

        if (!isset($labels[$type])) {
            return $type; // Fallback to type string
        }

        return $labels[$type][$plural ? 'plural' : 'singular'];
    }

    /**
     * Get hierarchy level for sorting
     *
     * Lower numbers = displayed first
     * Useful for ordering documents in chronological workflow order
     *
     * @param string $type Document type
     * @return int Hierarchy level (0-99)
     */
    public static function get_hierarchy_level($type) {
        $levels = array(
            self::QUOTATION => 0,           // Angebot kommt zuerst
            self::ORDER_CONFIRMATION => 5,  // Auftragsbestätigung nach Bestellung
            self::INVOICE => 10,            // Dann Rechnung
            self::DELIVERY_NOTE => 15,      // Optional: Lieferschein
            self::PROFORMA => 20,           // Proforma vor Credit Note
            self::CREDIT_NOTE => 30,        // Gutschrift nach Invoice
            self::REMINDER => 40            // Mahnung am Ende
        );

        return isset($levels[$type]) ? $levels[$type] : 99;
    }

    /**
     * Get SQL ORDER BY clause for hierarchy sorting
     *
     * @param string $column_name Column name for document_type (default: 'document_type')
     * @return string SQL CASE statement
     */
    public static function get_hierarchy_sql($column_name = 'document_type') {
        $cases = array();
        foreach (self::get_all_types() as $type) {
            $level = self::get_hierarchy_level($type);
            $cases[] = sprintf("WHEN %s = '%s' THEN %d", $column_name, esc_sql($type), $level);
        }

        return sprintf(
            "CASE %s ELSE 99 END",
            implode(' ', $cases)
        );
    }

    /**
     * Get icon/emoji for document type (for UI display)
     *
     * @param string $type Document type
     * @return string Icon or emoji
     */
    public static function get_icon($type) {
        $icons = array(
            self::QUOTATION => '📋',
            self::ORDER_CONFIRMATION => '✅',
            self::INVOICE => '🧾',
            self::CREDIT_NOTE => '💳',
            self::DELIVERY_NOTE => '📦',
            self::PROFORMA => '📄',
            self::REMINDER => '⚠️'
        );

        return isset($icons[$type]) ? $icons[$type] : '📄';
    }

    /**
     * Get CSS class for document type
     *
     * @param string $type Document type
     * @return string CSS class
     */
    public static function get_css_class($type) {
        return 'lexware-doc-' . str_replace('_', '-', $type);
    }

    /**
     * Check if document type requires a preceding document
     *
     * @param string $type Document type to check
     * @return string|false Required document type or false
     */
    public static function get_required_predecessor($type) {
        $requirements = array(
            self::CREDIT_NOTE => self::INVOICE,      // Credit Note braucht Invoice
            self::REMINDER => self::INVOICE,          // Mahnung braucht Invoice
            self::DELIVERY_NOTE => self::INVOICE      // Lieferschein optional nach Invoice
        );

        return isset($requirements[$type]) ? $requirements[$type] : false;
    }

    /**
     * Validate if document can be created based on workflow rules
     *
     * Checks:
     * - Type validity
     * - Support status
     * - Predecessor requirements (e.g., credit note needs invoice)
     * - Duplicate prevention
     *
     * @since 1.0.0
     * @param string $type Document type to create
     * @param int $order_id WooCommerce Order ID
     * @return true|\WP_Error True if valid, WP_Error if invalid
     */
    public static function can_create($type, $order_id) {
        global $wpdb;

        // Type validation
        if (!self::is_valid($type)) {
            return new \WP_Error(
                'invalid_type',
                sprintf(__('Ungültiger Dokumenttyp: %s', WC_LEXWARE_MVP_TEXT_DOMAIN), $type)
            );
        }

        // Support check
        if (!self::is_supported($type)) {
            return new \WP_Error(
                'unsupported_type',
                sprintf(__('Dokumenttyp noch nicht unterstützt: %s', WC_LEXWARE_MVP_TEXT_DOMAIN), self::get_label($type))
            );
        }

        // Check predecessor requirement
        $required_predecessor = self::get_required_predecessor($type);
        if ($required_predecessor) {
            $table = wc_lexware_mvp_get_table_name(false);

            $predecessor_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}`
                 WHERE order_id = %d
                 AND document_type = %s
                 AND document_status IN ('synced', 'completed')",
                $order_id,
                $required_predecessor
            ));

            if (!$predecessor_exists) {
                return new \WP_Error(
                    'missing_predecessor',
                    sprintf(
                        __('%s benötigt eine abgeschlossene %s', WC_LEXWARE_MVP_TEXT_DOMAIN),
                        self::get_label($type),
                        self::get_label($required_predecessor)
                    )
                );
            }
        }

        // Check for duplicates (nur 1 Dokument pro Type und Order erlaubt, außer Credit Notes)
        if ($type !== self::CREDIT_NOTE) {
            $table = wc_lexware_mvp_get_table_name(false);

            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}`
                 WHERE order_id = %d
                 AND document_type = %s
                 AND document_status NOT IN ('failed', 'cancelled')",
                $order_id,
                $type
            ));

            if ($exists) {
                return new \WP_Error(
                    'duplicate_document',
                    sprintf(
                        __('%s existiert bereits für diese Bestellung', WC_LEXWARE_MVP_TEXT_DOMAIN),
                        self::get_label($type)
                    )
                );
            }
        }

        return true;
    }

    /**
     * Get allowed status transitions for document type
     *
     * @param string $type Document type
     * @return array Allowed status transitions [from => [to1, to2, ...]]
     */
    public static function get_allowed_status_transitions($type) {
        // Common transitions for all types
        $common = array(
            'pending' => array('processing', 'failed', 'cancelled'),
            'processing' => array('synced', 'failed'),
            'synced' => array('completed', 'failed'),
            'completed' => array(), // Final state
            'failed' => array('pending_retry', 'cancelled'),
            'pending_retry' => array('processing', 'failed', 'cancelled'),
            'cancelled' => array() // Final state
        );

        // Type-specific overrides (falls nötig)
        $type_specific = array(
            self::CREDIT_NOTE => $common,
            self::INVOICE => $common,
            // Weitere Types können eigene Transitions haben
        );

        return isset($type_specific[$type]) ? $type_specific[$type] : $common;
    }

    /**
     * Get Lexware API endpoint for document type
     *
     * @param string $type Document type
     * @return string API endpoint (e.g., '/invoices', '/credit-notes')
     */
    public static function get_api_endpoint($type) {
        $endpoints = array(
            self::QUOTATION => '/quotations',
            self::ORDER_CONFIRMATION => '/order-confirmations',
            self::INVOICE => '/invoices',
            self::CREDIT_NOTE => '/credit-notes',
            self::DELIVERY_NOTE => '/delivery-notes',
            self::PROFORMA => '/proforma-invoices',
            self::REMINDER => '/reminders'
        );

        return isset($endpoints[$type]) ? $endpoints[$type] : '';
    }

    /**
     * Get document type description for admin UI
     *
     * @param string $type Document type
     * @return string Description
     */
    public static function get_description($type) {
        $descriptions = array(
            self::QUOTATION => __('Verbindliches Angebot vor Bestellabschluss', WC_LEXWARE_MVP_TEXT_DOMAIN),
            self::ORDER_CONFIRMATION => __('Auftragsbestätigung nach Bestelleingang', WC_LEXWARE_MVP_TEXT_DOMAIN),
            self::INVOICE => __('Finale Rechnung nach Bestellbestätigung', WC_LEXWARE_MVP_TEXT_DOMAIN),
            self::CREDIT_NOTE => __('Gutschrift bei Retoure oder Erstattung', WC_LEXWARE_MVP_TEXT_DOMAIN),
            self::DELIVERY_NOTE => __('Lieferschein für Warenversand', WC_LEXWARE_MVP_TEXT_DOMAIN),
            self::PROFORMA => __('Proforma-Rechnung für Vorauszahlung', WC_LEXWARE_MVP_TEXT_DOMAIN),
            self::REMINDER => __('Zahlungserinnerung bei überfälliger Rechnung', WC_LEXWARE_MVP_TEXT_DOMAIN)
        );

        return isset($descriptions[$type]) ? $descriptions[$type] : '';
    }

    /**
     * Check if document type supports email sending
     *
     * @param string $type Document type
     * @return bool True if email supported
     */
    public static function supports_email($type) {
        $email_types = array(
            self::QUOTATION,
            self::ORDER_CONFIRMATION,
            self::INVOICE,
            self::CREDIT_NOTE,
            self::REMINDER
        );

        return in_array($type, $email_types, true);
    }

    /**
     * Check if document type supports PDF generation
     *
     * @param string $type Document type
     * @return bool True if PDF supported
     */
    public static function supports_pdf($type) {
        // Alle Types außer interne Notes
        return self::is_valid($type);
    }

    /**
     * Get default document filename pattern
     *
     * @param string $type Document type
     * @param int $order_id Order ID
     * @param string $document_number Document number from Lexware
     * @return string Filename pattern (without extension)
     */
    public static function get_filename_pattern($type, $order_id, $document_number = '') {
        $prefix = array(
            self::QUOTATION => 'angebot',
            self::ORDER_CONFIRMATION => 'auftragsbestaetigung',
            self::INVOICE => 'rechnung',
            self::CREDIT_NOTE => 'gutschrift',
            self::DELIVERY_NOTE => 'lieferschein',
            self::PROFORMA => 'proforma',
            self::REMINDER => 'mahnung'
        );

        $type_prefix = isset($prefix[$type]) ? $prefix[$type] : 'dokument';

        if ($document_number) {
            return sprintf('%s-%s-order-%d', $type_prefix, $document_number, $order_id);
        }

        return sprintf('%s-order-%d', $type_prefix, $order_id);
    }
}
