<?php
/**
 * Admin Meta Box - Lexware Document Status
 *
 * Displays Lexware invoice and credit note status on WooCommerce order edit screen.
 * Provides actions for PDF download, email resend, and manual retry.
 *
 * @package WC_Lexware_MVP
 * @since 1.0.0
 */

namespace WC_Lexware_MVP\Admin;

if (!defined('ABSPATH')) {
    exit;
}

class Meta_Box {

    /**
     * Initialize meta box
     *
     * Registers meta box and AJAX handlers for document actions.
     *
     * @since 1.0.0
     */
    public static function init() {
        add_action('add_meta_boxes', array(__CLASS__, 'add_meta_box'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_scripts'));
        add_action('wp_ajax_lexware_mvp_download_pdf', array(__CLASS__, 'ajax_download_pdf'));
        add_action('wp_ajax_lexware_mvp_resend_email', array(__CLASS__, 'ajax_resend_email'));
        add_action('wp_ajax_lexware_mvp_manual_retry', array(__CLASS__, 'ajax_manual_retry'));
        add_action('wp_ajax_lexware_mvp_retry_pdf_download', array(__CLASS__, 'ajax_retry_pdf_download'));
        add_action('wp_ajax_lexware_mvp_create_invoice_manually', array(__CLASS__, 'ajax_create_invoice_manually'));
        add_action('wp_ajax_lexware_mvp_create_order_confirmation_manually', array(__CLASS__, 'ajax_create_order_confirmation_manually'));
        add_action('wp_ajax_lexware_mvp_delete_external_document', array(__CLASS__, 'ajax_delete_external_document'));
    }

    /**
     * Enqueue admin scripts and styles
     *
     * Loads JavaScript for AJAX actions on order edit screen.
     *
     * @since 1.0.1
     * @param string $hook Current admin page hook
     */
    public static function enqueue_scripts($hook) {
        // Only load on order edit screen
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, array('shop_order', 'woocommerce_page_wc-orders'))) {
            return;
        }

        // Enqueue admin meta box JavaScript
        wp_enqueue_script(
            'lexware-mvp-admin-meta-box',
            WC_LEXWARE_MVP_URL . 'assets/js/admin-meta-box.js',
            array('jquery'),
            WC_LEXWARE_MVP_VERSION,
            true
        );

        // Localize script with AJAX URL and nonce
        wp_localize_script(
            'lexware-mvp-admin-meta-box',
            'lexware_mvp_meta_box',
            array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('lexware_mvp_meta_box')
            )
        );
    }

    /**
     * Register meta box
     *
     * Adds Lexware Documents meta box to order edit screen.
     * Compatible with both HPOS and legacy post-based orders.
     *
     * @since 1.0.0
     */
    public static function add_meta_box() {
        $screen = wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()
            ? wc_get_page_screen_id('shop-order')
            : 'shop_order';

        add_meta_box(
            'lexware_mvp_invoice_status',
            'Lexware Dokumente',
            array(__CLASS__, 'render_meta_box'),
            $screen,
            'side',
            'default'
        );
    }

    /**
     * Render meta box content
     *
     * Displays Lexware document status (order confirmations, invoices, credit notes),
     * PDF links, and action buttons.
     *
     * @since 1.0.0
     * @since 1.3.0 Added order confirmation support
     * @param \WP_Post|\WC_Order $post_or_order Post object or Order object (HPOS)
     * @param array $metabox Meta box configuration array
     */
    public static function render_meta_box($post_or_order, $metabox = array()) {
        $order_id = $post_or_order instanceof \WP_Post ? $post_or_order->ID : $post_or_order->get_id();
        $documents = self::get_documents_data($order_id);

        wp_nonce_field('lexware_mvp_meta_box', 'lexware_mvp_meta_box_nonce');

        if (empty($documents)) {
            self::render_not_synced($order_id);
        } else {
            // Group documents by type
            $order_confirmations = array_filter($documents, function($doc) {
                return $doc->document_type === 'order_confirmation';
            });
            $invoices = array_filter($documents, function($doc) {
                return $doc->document_type === 'invoice';
            });
            $credit_notes = array_filter($documents, function($doc) {
                return $doc->document_type === 'credit_note';
            });

            // Render order confirmations section (first, as they come before invoices)
            if (!empty($order_confirmations)) {
                echo '<div class="lexware-section">';
                echo '<h4 class="lexware-section-title">Auftragsbestätigung</h4>';
                foreach ($order_confirmations as $oc) {
                    self::render_document($order_id, $oc);
                }
                echo '</div>';
            }

            // Render invoices section
            if (!empty($invoices)) {
                echo '<div class="lexware-section">';
                echo '<h4 class="lexware-section-title">Rechnung</h4>';
                foreach ($invoices as $invoice) {
                    self::render_document($order_id, $invoice);
                }
                echo '</div>';
            }

            // Render credit notes section
            if (!empty($credit_notes)) {
                echo '<div class="lexware-section">';
                $cn_label = count($credit_notes) > 1 ? 'Gutschriften' : 'Gutschrift';
                echo '<h4 class="lexware-section-title">' . esc_html($cn_label) . '</h4>';
                foreach ($credit_notes as $credit_note) {
                    self::render_document($order_id, $credit_note);
                }
                echo '</div>';
            }

            // Show "not synced" if no documents exist
            if (empty($order_confirmations) && empty($invoices) && empty($credit_notes)) {
                self::render_not_synced($order_id);
            }

            // Show manual creation buttons for missing document types
            self::render_missing_document_buttons($order_id, $order_confirmations, $invoices);
        }
    }

    /**
     * Render a single document (order confirmation, invoice or credit note)
     *
     * @since 1.0.0
     * @since 1.3.0 Added order confirmation support
     */
    private static function render_document($order_id, $document) {
        // Determine document type and label
        $doc_labels = array(
            'order_confirmation' => 'Auftragsbestätigung',
            'invoice' => 'Rechnung',
            'credit_note' => 'Gutschrift'
        );
        $doc_label = isset($doc_labels[$document->document_type]) ? $doc_labels[$document->document_type] : 'Dokument';
        $is_credit_note = ($document->document_type === 'credit_note');

        switch ($document->document_status) {
            case 'synced':
                echo '<div class="lexware-meta-box-status lexware-status-completed">✓ ' . esc_html($doc_label) . ' erstellt</div>';

                echo '<div class="lexware-info-row">';
                echo '<span class="lexware-info-label">' . esc_html($doc_label) . '-Nr:</span> ';
                echo '<span class="lexware-info-value">' . esc_html($document->document_nr) . '</span>';
                echo '</div>';

                if ($is_credit_note && $document->refund_amount) {
                    echo '<div class="lexware-info-row">';
                    echo '<span class="lexware-info-label">Betrag:</span> ';
                    echo '<span class="lexware-info-value">-' . wc_price(abs($document->refund_amount)) . '</span>';
                    echo '</div>';
                }

                if ($document->synced_at) {
                    echo '<div class="lexware-info-row">';
                    echo '<span class="lexware-info-label">Erstellt:</span> ';
                    echo '<span class="lexware-info-value">' . esc_html(date_i18n('d.m.Y H:i', strtotime($document->synced_at))) . '</span>';
                    echo '</div>';
                }

                if ($document->email_sent_at) {
                    echo '<div class="lexware-info-row">';
                    echo '<span class="lexware-info-label">E-Mail versendet:</span> ';
                    echo '<span class="lexware-info-value">' . esc_html(date_i18n('d.m.Y H:i', strtotime($document->email_sent_at))) . '</span>';
                    echo '</div>';
                }

                echo '<div class="lexware-actions">';

                // Check if PDF exists
                if ($document->document_filename && \WC_Lexware_MVP_PDF_Handler::pdf_exists($document->document_filename)) {
                    echo '<button class="lexware-action-button lexware-download-pdf" data-order-id="' . esc_attr($order_id) . '" data-document-id="' . esc_attr($document->id) . '" data-document-type="' . esc_attr($document->document_type) . '">PDF herunterladen</button>';
                    echo '<span class="spinner lexware-spinner"></span>';
                    echo '<br>';
                    echo '<button class="lexware-action-button secondary lexware-resend-email" data-order-id="' . esc_attr($order_id) . '" data-document-id="' . esc_attr($document->id) . '" data-document-type="' . esc_attr($document->document_type) . '">E-Mail erneut senden</button>';
                } else {
                    // PDF fehlt - prüfe ob Dokument finalisiert ist
                    if ($document->document_finalized) {
                        // Finalisiert aber kein PDF → kann nachgeladen werden
                        echo '<button class="lexware-action-button button-warning lexware-retry-pdf" data-order-id="' . esc_attr($order_id) . '" data-document-id="' . esc_attr($document->id) . '" data-document-type="' . esc_attr($document->document_type) . '">📥 PDF nachladen</button>';
                        echo '<span class="spinner lexware-spinner"></span>';
                        echo '<div class="lexware-missing-warning">⚠️ PDF fehlt - bitte zuerst nachladen</div>';
                    } else {
                        // Nicht finalisiert → kein PDF verfügbar
                        echo '<div class="lexware-missing-warning">';
                        echo 'ℹ️ Dokument ist ein <strong>Entwurf</strong> (nicht finalisiert).<br>';
                        echo 'PDFs sind nur für finalisierte Dokumente verfügbar.<br>';
                        echo '<a href="https://app.lexware.de" target="_blank" class="lexware-link">→ In Lexware finalisieren</a>';
                        echo '</div>';
                    }
                }

                echo '</div>';
                break;

            case 'failed':
            case 'failed_auth':
            case 'failed_validation':
                echo '<div class="lexware-meta-box-status lexware-status-failed">✗ ' . esc_html($doc_label) . ' Fehler</div>';

                // Get error from Logger
                $error_message = \WC_Lexware_MVP_Logger::get_latest_error($order_id, $document->document_type);
                if ($error_message) {
                    echo '<div class="lexware-error-message">';
                    echo esc_html($error_message);
                    echo '</div>';
                } else {
                    echo '<div class="lexware-error-message">Fehler aufgetreten. Details siehe <a href="' . esc_url(admin_url('admin.php?page=wc-status&tab=logs')) . '" target="_blank">WooCommerce Logs</a>.</div>';
                }

                if ($document->updated_at) {
                    echo '<div class="lexware-info-row">';
                    echo '<span class="lexware-info-label">Letzter Versuch:</span> ';
                    echo '<span class="lexware-info-value">' . esc_html(date_i18n('d.m.Y H:i', strtotime($document->updated_at))) . '</span>';
                    echo '</div>';
                }

                // Manual Retry Button
                echo '<div class="lexware-actions">';
                echo '<button class="lexware-action-button lexware-manual-retry" data-order-id="' . esc_attr($order_id) . '" data-document-id="' . esc_attr($document->id) . '" data-document-type="' . esc_attr($document->document_type) . '">🔄 Erneut versuchen</button>';
                echo '<span class="spinner lexware-spinner"></span>';
                echo '</div>';
                break;

            case 'processing':
            case 'pending':
                echo '<div class="lexware-meta-box-status lexware-status-processing">⏳ ' . esc_html($doc_label) . ' wird erstellt...</div>';

                echo '<div class="lexware-info-row">';
                echo '<span class="lexware-info-value">Das Dokument wird gerade erstellt.</span>';
                echo '</div>';

                if ($document->created_at) {
                    echo '<div class="lexware-info-row">';
                    echo '<span class="lexware-info-label">Gestartet:</span> ';
                    echo '<span class="lexware-info-value">' . esc_html(date_i18n('d.m.Y H:i', strtotime($document->created_at))) . '</span>';
                    echo '</div>';
                }
                break;

            case 'external':
                echo '<div class="lexware-meta-box-status lexware-status-external">📝 ' . esc_html($doc_label) . ' (extern)</div>';

                echo '<div class="lexware-info-row">';
                echo '<span class="lexware-info-value">Dieses Dokument wurde manuell in Lexware erstellt.</span>';
                echo '</div>';

                if (!empty($document->document_nr)) {
                    echo '<div class="lexware-info-row">';
                    echo '<span class="lexware-info-label">' . esc_html($doc_label) . '-Nr:</span> ';
                    echo '<span class="lexware-info-value">' . esc_html($document->document_nr) . '</span>';
                    echo '</div>';
                }

                // Show metadata if available
                $meta = json_decode($document->document_meta, true);
                if (!empty($meta['note'])) {
                    echo '<div class="lexware-info-row">';
                    echo '<span class="lexware-info-label">Notiz:</span> ';
                    echo '<span class="lexware-info-value">' . esc_html($meta['note']) . '</span>';
                    echo '</div>';
                }

                if ($document->created_at) {
                    echo '<div class="lexware-info-row">';
                    echo '<span class="lexware-info-label">Vermerkt am:</span> ';
                    echo '<span class="lexware-info-value">' . esc_html(date_i18n('d.m.Y H:i', strtotime($document->created_at))) . '</span>';
                    echo '</div>';
                }

                // Delete button for external documents
                echo '<div class="lexware-actions">';
                echo '<button class="lexware-action-button secondary lexware-delete-external" data-order-id="' . esc_attr($order_id) . '" data-document-id="' . esc_attr($document->id) . '" data-document-type="' . esc_attr($document->document_type) . '">🗑️ Markierung entfernen</button>';
                echo '<span class="spinner lexware-spinner"></span>';
                echo '</div>';
                break;

            default:
                echo '<div class="lexware-meta-box-status lexware-status-not-synced">○ Status unbekannt</div>';
        }
    }

    /**
     * Render not synced status
     *
     * Shows current status and configured trigger statuses for all document types.
     *
     * @since 1.0.0
     * @since 1.3.0 Added order confirmation trigger info
     */
    private static function render_not_synced($order_id) {
        echo '<div class="lexware-meta-box-status lexware-status-not-synced">○ Noch nicht synchronisiert</div>';

        echo '<div class="lexware-info-row">';
        echo '<span class="lexware-info-value">Keine Lexware-Dokumente für diese Bestellung.</span>';
        echo '</div>';

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $current_status = 'wc-' . $order->get_status();

        // Get trigger statuses for both document types
        $oc_trigger_statuses = get_option('wc_lexware_mvp_order_confirmation_trigger_statuses', array());
        $invoice_trigger_statuses = get_option('wc_lexware_mvp_invoice_trigger_statuses', array('wc-completed'));

        // Ensure arrays
        if (!is_array($oc_trigger_statuses)) {
            $oc_trigger_statuses = !empty($oc_trigger_statuses) ? array($oc_trigger_statuses) : array();
        }
        if (!is_array($invoice_trigger_statuses)) {
            $invoice_trigger_statuses = array($invoice_trigger_statuses);
        }

        // Check if current status matches any trigger
        $can_create_oc = in_array($current_status, $oc_trigger_statuses);
        $can_create_invoice = in_array($current_status, $invoice_trigger_statuses);

        // Show manual creation buttons if status matches
        if ($can_create_oc || $can_create_invoice) {
            echo '<div class="lexware-actions">';
            echo '<div class="lexware-meta-box-status lexware-status-info">';
            echo 'ℹ️ Status: <strong>' . esc_html(wc_get_order_status_name($order->get_status())) . '</strong><br>';
            echo 'Dokumente können manuell erstellt werden.';
            echo '</div>';

            if ($can_create_oc) {
                echo '<button class="lexware-action-button lexware-create-order-confirmation-manually" data-order-id="' . esc_attr($order_id) . '">📋 Auftragsbestätigung erstellen</button>';
                echo '<span class="spinner lexware-spinner lexware-spinner-oc"></span>';
            }

            if ($can_create_invoice) {
                echo '<button class="lexware-action-button lexware-create-invoice-manually" data-order-id="' . esc_attr($order_id) . '">📝 Rechnung erstellen</button>';
                echo '<span class="spinner lexware-spinner lexware-spinner-invoice"></span>';
            }
            echo '</div>';
        } else {
            // Show info about configured triggers
            echo '<div class="lexware-notice notice-info">';
            echo 'ℹ️ Aktueller Status: <strong>' . esc_html(wc_get_order_status_name($order->get_status())) . '</strong><br><br>';

            // Order Confirmation triggers
            if (!empty($oc_trigger_statuses)) {
                $oc_labels = array();
                foreach ($oc_trigger_statuses as $status) {
                    $clean_status = str_replace('wc-', '', $status);
                    $oc_labels[] = wc_get_order_status_name($clean_status);
                }
                echo '✅ <strong>Auftragsbestätigung</strong> bei: ' . esc_html(implode(', ', $oc_labels)) . '<br>';
            } else {
                echo '✅ <strong>Auftragsbestätigung</strong>: <em>deaktiviert</em><br>';
            }

            // Invoice triggers
            if (!empty($invoice_trigger_statuses)) {
                $invoice_labels = array();
                foreach ($invoice_trigger_statuses as $status) {
                    $clean_status = str_replace('wc-', '', $status);
                    $invoice_labels[] = wc_get_order_status_name($clean_status);
                }
                echo '🧾 <strong>Rechnung</strong> bei: ' . esc_html(implode(', ', $invoice_labels));
            } else {
                echo '🧾 <strong>Rechnung</strong>: <em>deaktiviert</em>';
            }

            echo '</div>';
        }
    }

    /**
     * Render buttons for missing document types
     *
     * Shows manual creation buttons when documents exist but some types are missing.
     * E.g., Order has Order Confirmation but no Invoice yet.
     *
     * @since 1.3.0
     * @param int $order_id Order ID
     * @param array $order_confirmations Existing order confirmations
     * @param array $invoices Existing invoices
     */
    private static function render_missing_document_buttons($order_id, $order_confirmations, $invoices) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $current_status = 'wc-' . $order->get_status();

        // Get trigger statuses
        $oc_trigger_statuses = get_option('wc_lexware_mvp_order_confirmation_trigger_statuses', array());
        $invoice_trigger_statuses = get_option('wc_lexware_mvp_invoice_trigger_statuses', array('wc-completed'));

        // Ensure arrays
        if (!is_array($oc_trigger_statuses)) {
            $oc_trigger_statuses = !empty($oc_trigger_statuses) ? array($oc_trigger_statuses) : array();
        }
        if (!is_array($invoice_trigger_statuses)) {
            $invoice_trigger_statuses = array($invoice_trigger_statuses);
        }

        // Check what's missing and can be created
        $can_create_oc = empty($order_confirmations) && in_array($current_status, $oc_trigger_statuses);
        $can_create_invoice = empty($invoices) && in_array($current_status, $invoice_trigger_statuses);

        // Only show if something can be created
        if (!$can_create_oc && !$can_create_invoice) {
            return;
        }

        echo '<div class="lexware-section">';
        echo '<div class="lexware-missing-warning">';
        echo '⚠️ <strong>Fehlende Dokumente</strong><br>';

        if ($can_create_oc) {
            echo '• Auftragsbestätigung fehlt<br>';
        }
        if ($can_create_invoice) {
            echo '• Rechnung fehlt<br>';
        }

        echo '</div>';

        echo '<div class="lexware-actions">';
        if ($can_create_oc) {
            echo '<button class="lexware-action-button lexware-create-order-confirmation-manually" data-order-id="' . esc_attr($order_id) . '">📋 Auftragsbestätigung erstellen</button>';
            echo '<span class="spinner lexware-spinner lexware-spinner-oc"></span>';
        }

        if ($can_create_invoice) {
            echo '<button class="lexware-action-button lexware-create-invoice-manually" data-order-id="' . esc_attr($order_id) . '">📝 Rechnung erstellen</button>';
            echo '<span class="spinner lexware-spinner lexware-spinner-invoice"></span>';
        }
        echo '</div>';

        echo '</div>';
    }

    /**
     * Get documents data from database (order confirmations, invoices and credit notes)
     *
     * @since 1.0.0
     * @since 1.3.0 Added order confirmation support
     */
    private static function get_documents_data($order_id) {
        global $wpdb;
        $table_name = wc_lexware_mvp_get_table_name();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_name}
             WHERE order_id = %d
             ORDER BY
                CASE
                    WHEN document_type = 'order_confirmation' THEN 1
                    WHEN document_type = 'invoice' THEN 2
                    WHEN document_type = 'credit_note' THEN 3
                    ELSE 4
                END,
                created_at ASC",
            $order_id
        ));
    }

    /**
     * AJAX handler for PDF download
     */
    public static function ajax_download_pdf() {
        \WC_Lexware_MVP_Logger::debug('PDF Download requested via meta box', array(
            'order_id' => isset($_POST['order_id']) ? $_POST['order_id'] : null,
            'document_id' => isset($_POST['document_id']) ? $_POST['document_id'] : null,
            'document_type' => isset($_POST['document_type']) ? $_POST['document_type'] : null,
            'user_id' => get_current_user_id()
        ));

        try {
            check_ajax_referer('lexware_mvp_meta_box', 'nonce');

            if (!current_user_can('edit_shop_orders')) {
                \WC_Lexware_MVP_Logger::warning('PDF Download: Insufficient permissions', array(
                    'user_id' => get_current_user_id()
                ));
                wp_die('Keine Berechtigung');
            }

            $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
            $document_id = isset($_POST['document_id']) ? intval($_POST['document_id']) : 0;

            if (!$order_id) {
                \WC_Lexware_MVP_Logger::error('PDF Download: Invalid order ID');
                wp_die('Ungültige Bestellung');
            }

            // Get specific document by ID if provided, otherwise get latest document
            $document_data = $document_id ? self::get_document_by_id($document_id) : self::get_document_by_id($document_id); // Note: get_invoice_data() is deprecated

            if (!$document_data || !$document_data->document_filename) {
                \WC_Lexware_MVP_Logger::error('PDF Download: No document data found', array(
                    'order_id' => $order_id,
                    'document_id' => $document_id
                ));
                wp_die('Keine PDF-Datei gefunden');
            }

            // Use PDF Handler for consistent path management
            $pdf_path = \WC_Lexware_MVP_PDF_Handler::get_pdf_path($document_data->document_filename);
            // Old: $upload_dir = wp_upload_dir(); $pdf_dir = $upload_dir['basedir'] . '/lexware-invoices/'; $pdf_path = $pdf_dir . $document_data->document_filename;

            if (!file_exists($pdf_path)) {
                \WC_Lexware_MVP_Logger::error('PDF Download: File does not exist', array(
                    'order_id' => $order_id,
                    'pdf_path' => $pdf_path,
                    'pdf_title' => $document_data->document_filename
                ));
                wp_die('PDF-Datei nicht gefunden');
            }

            \WC_Lexware_MVP_Logger::info('PDF Download successful', array(
                'order_id' => $order_id,
                'document_type' => $document_data->document_type,
                'pdf_file' => $document_data->document_filename,
                'file_size' => filesize($pdf_path)
            ));

            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $document_data->document_filename . '"');
            header('Content-Length: ' . filesize($pdf_path));
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');

            // Use file lock to prevent reading while file is being written
            $fp = fopen($pdf_path, 'rb');
            if ($fp) {
                if (flock($fp, LOCK_SH)) { // Shared lock for reading
                    fpassthru($fp);
                    flock($fp, LOCK_UN); // Release lock
                } else {
                    fclose($fp);
                    wp_die('PDF-Datei ist gerade in Bearbeitung. Bitte versuchen Sie es in einigen Sekunden erneut.');
                }
                fclose($fp);
            } else {
                wp_die('Fehler beim Öffnen der PDF-Datei.');
            }
            exit;

        } catch (Exception $e) {
            \WC_Lexware_MVP_Logger::critical('PDF Download: Exception', array(
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ));
            wp_die('Fehler beim PDF-Download: ' . $e->getMessage());
        }
    }

    /**
     * Get document by ID
     */
    private static function get_document_by_id($document_id) {
        global $wpdb;
        $table_name = wc_lexware_mvp_get_table_name();

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $document_id
        ));
    }

    /**
     * AJAX handler for email resend
     */
    public static function ajax_resend_email() {
        \WC_Lexware_MVP_Logger::debug('Email Resend requested via meta box', array(
            'order_id' => isset($_POST['order_id']) ? $_POST['order_id'] : null,
            'document_id' => isset($_POST['document_id']) ? $_POST['document_id'] : null,
            'document_type' => isset($_POST['document_type']) ? $_POST['document_type'] : null,
            'user_id' => get_current_user_id()
        ));

        try {
            check_ajax_referer('lexware_mvp_meta_box', 'nonce');

            if (!current_user_can('edit_shop_orders')) {
                \WC_Lexware_MVP_Logger::warning('Email Resend: Insufficient permissions', array(
                    'user_id' => get_current_user_id()
                ));
                wp_send_json_error('Keine Berechtigung');
            }

            $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
            $document_id = isset($_POST['document_id']) ? intval($_POST['document_id']) : 0;
            $document_type = isset($_POST['document_type']) ? sanitize_text_field($_POST['document_type']) : 'invoice';

            if (!$order_id) {
                \WC_Lexware_MVP_Logger::error('Email Resend: Invalid order ID');
                wp_send_json_error('Ungültige Bestellung');
            }

            // Get specific document by ID if provided, otherwise get latest document
            $document_data = $document_id ? self::get_document_by_id($document_id) : self::get_document_by_id($document_id); // Note: get_invoice_data() is deprecated

            if (!$document_data) {
                \WC_Lexware_MVP_Logger::error('Email Resend: No document data found', array(
                    'order_id' => $order_id,
                    'document_id' => $document_id
                ));
                wp_send_json_error('Kein Dokument für diese Bestellung gefunden');
            }

            if ($document_data->document_status !== 'synced') {
                \WC_Lexware_MVP_Logger::warning('Email Resend: Document not completed', array(
                    'order_id' => $order_id,
                    'document_type' => $document_data->document_type,
                    'document_status' => $document_data->document_status
                ));
                wp_send_json_error('Dokument ist noch nicht abgeschlossen (Status: ' . $document_data->document_status . ')');
            }

            $order = wc_get_order($order_id);

            if (!$order) {
                \WC_Lexware_MVP_Logger::error('Email Resend: WC Order not found', array(
                    'order_id' => $order_id
                ));
                wp_send_json_error('Bestellung nicht gefunden');
            }

            // Ensure WooCommerce emails are loaded
            if (!class_exists('WC_Email')) {
                WC()->mailer();
            }

            // Determine which email class to use based on document type
            switch ($document_data->document_type) {
                case 'order_confirmation':
                    if (!class_exists('WC_Lexware_MVP\Email\Order_Confirmation')) {
                        require_once WC_LEXWARE_MVP_PATH . 'includes/Email/class-order-confirmation.php';
                    }
                    $email_handler = new \WC_Lexware_MVP\Email\Order_Confirmation();
                    $doc_label = 'Auftragsbestätigung';
                    break;

                case 'credit_note':
                    if (!class_exists('WC_Lexware_MVP\Email\Credit_Note')) {
                        require_once WC_LEXWARE_MVP_PATH . 'includes/Email/class-credit-note.php';
                    }
                    $email_handler = new \WC_Lexware_MVP\Email\Credit_Note();
                    $doc_label = 'Gutschrift';
                    break;

                case 'invoice':
                default:
                    if (!class_exists('WC_Lexware_MVP\Email\Invoice')) {
                        require_once WC_LEXWARE_MVP_PATH . 'includes/Email/class-invoice.php';
                    }
                    $email_handler = new \WC_Lexware_MVP\Email\Invoice();
                    $doc_label = 'Rechnung';
                    break;
            }

            \WC_Lexware_MVP_Logger::info('Email Resend: Starting email delivery', array(
                'order_id' => $order_id,
                'document_type' => $document_data->document_type,
                'document_nr' => $document_data->document_nr,
                'pdf_title' => $document_data->document_filename,
                'recipient' => $order->get_billing_email()
            ));

            // Trigger the email
            $email_handler->trigger($order_id, $document_data->id, $document_data->document_filename);

            \WC_Lexware_MVP_Logger::info('Email Resend successful', array(
                'order_id' => $order_id,
                'document_type' => $document_data->document_type,
                'document_nr' => $document_data->document_nr
            ));

            wp_send_json_success($doc_label . ' erfolgreich versendet');

        } catch (Exception $e) {
            \WC_Lexware_MVP_Logger::critical('Email Resend: Exception', array(
                'order_id' => isset($order_id) ? $order_id : 'unknown',
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ));
            wp_send_json_error('Kritischer Fehler: ' . $e->getMessage());
        }
    }

    /**
     * AJAX handler for manual retry of failed documents
     */
    public static function ajax_manual_retry() {
        \WC_Lexware_MVP_Logger::debug('Manual Retry requested via meta box', array(
            'order_id' => isset($_POST['order_id']) ? $_POST['order_id'] : null,
            'document_id' => isset($_POST['document_id']) ? $_POST['document_id'] : null,
            'document_type' => isset($_POST['document_type']) ? $_POST['document_type'] : null,
            'user_id' => get_current_user_id()
        ));

        try {
            check_ajax_referer('lexware_mvp_meta_box', 'nonce');

            if (!current_user_can('edit_shop_orders')) {
                \WC_Lexware_MVP_Logger::warning('Manual Retry: Insufficient permissions', array(
                    'user_id' => get_current_user_id()
                ));
                wp_send_json_error('Keine Berechtigung');
            }

            $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
            $document_id = isset($_POST['document_id']) ? intval($_POST['document_id']) : 0;
            $document_type = isset($_POST['document_type']) ? sanitize_text_field($_POST['document_type']) : 'invoice';

            if (!$order_id || !$document_id) {
                \WC_Lexware_MVP_Logger::error('Manual Retry: Invalid parameters', array(
                    'order_id' => $order_id,
                    'document_id' => $document_id
                ));
                wp_send_json_error('Ungültige Parameter');
            }

            global $wpdb;
            $table = wc_lexware_mvp_get_table_name();

            // Get document record
            $document = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d AND order_id = %d",
                $document_id,
                $order_id
            ));

            if (!$document) {
                \WC_Lexware_MVP_Logger::error('Manual Retry: Document not found', array(
                    'order_id' => $order_id,
                    'document_id' => $document_id
                ));
                wp_send_json_error('Dokument nicht gefunden');
            }

            // Check if document is in failed state
            if (!in_array($document->document_status, array('failed', 'failed_auth', 'failed_validation'))) {
                \WC_Lexware_MVP_Logger::warning('Manual Retry: Document not in failed state', array(
                    'order_id' => $order_id,
                    'document_id' => $document_id,
                    'current_status' => $document->document_status
                ));
                wp_send_json_error('Dokument ist nicht im Fehlerstatus (aktuell: ' . $document->document_status . ')');
            }

            // Reset document status to pending
            $table_name = wc_lexware_mvp_get_table_name(false);
            $update_result = $wpdb->update(
                $table_name,
                array('document_status' => 'pending'),
                array('id' => $document_id),
                array('%s'),
                array('%d')
            );

            if ($update_result === false) {
                \WC_Lexware_MVP_Logger::error('Manual Retry: Database update failed', array(
                    'order_id' => $order_id,
                    'document_id' => $document_id,
                    'error' => $wpdb->last_error
                ));
                wp_send_json_error('Datenbankfehler beim Status-Update');
            }

            // Schedule retry via Action Scheduler based on document type
            $action_hook = '';
            $action_args = array($order_id);

            switch ($document_type) {
                case 'order_confirmation':
                    $action_hook = 'wc_lexware_mvp_process_order_confirmation';
                    $doc_label = 'Auftragsbestätigung';
                    break;

                case 'credit_note':
                    $action_hook = 'wc_lexware_mvp_process_credit_note';
                    $action_args = array($order_id, $document->refund_id);
                    $doc_label = 'Gutschrift';
                    break;

                case 'invoice':
                default:
                    $action_hook = 'wc_lexware_mvp_process_invoice';
                    $doc_label = 'Rechnung';
                    break;
            }

            if (function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action($action_hook, $action_args, 'lexware-mvp');
            } elseif (function_exists('as_schedule_single_action')) {
                as_schedule_single_action(time(), $action_hook, $action_args, 'lexware-mvp');
            }

            $order = wc_get_order($order_id);
            if ($order) {
                $order->add_order_note(
                    sprintf(__('%s wird manuell erneut erstellt (Admin-Retry)...', WC_LEXWARE_MVP_TEXT_DOMAIN), $doc_label)
                );
            }

            \WC_Lexware_MVP_Logger::info('Manual Retry scheduled', array(
                'order_id' => $order_id,
                'document_id' => $document_id,
                'document_type' => $document_type
            ));

            wp_send_json_success($doc_label . ' wird erneut erstellt. Seite wird neu geladen...');

        } catch (Exception $e) {
            \WC_Lexware_MVP_Logger::critical('Manual Retry: Exception', array(
                'order_id' => isset($order_id) ? $order_id : 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ));
            wp_send_json_error('Kritischer Fehler: ' . $e->getMessage());
        }
    }

    /**
     * AJAX: Retry PDF Download
     * For cases where PDF failed to download due to memory constraints
     */
    public static function ajax_retry_pdf_download() {
        try {
            check_ajax_referer('lexware_mvp_meta_box', 'nonce');

            if (!current_user_can('edit_shop_orders')) {
                wp_send_json_error('Keine Berechtigung');
            }

            $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
            $document_id = isset($_POST['document_id']) ? intval($_POST['document_id']) : 0;

            if (!$order_id || !$document_id) {
                wp_send_json_error('Ungültige Parameter');
            }

            // Get document data
            $document = self::get_document_by_id($document_id);
            if (!$document || !$document->document_id) {
                wp_send_json_error('Dokument nicht gefunden');
            }

            // Check if document is finalized - PDFs only exist for finalized documents
            if (!$document->document_finalized) {
                \WC_Lexware_MVP_Logger::warning('PDF Download attempted for non-finalized document', array(
                    'order_id' => $order_id,
                    'document_id' => $document_id,
                    'document_finalized' => $document->document_finalized
                ));
                wp_send_json_error('PDF kann nicht geladen werden: Dokument ist noch nicht finalisiert (Entwurf). Bitte finalisieren Sie das Dokument zuerst in Lexware.');
            }

            \WC_Lexware_MVP_Logger::info('Retry PDF Download initiated', array(
                'order_id' => $order_id,
                'document_id' => $document_id,
                'invoice_id' => $document->document_id
            ));

            // Initialize API and PDF Handler
            $api = new \WC_Lexware_MVP_API_Client();
            $pdf_handler = new \WC_Lexware_MVP_PDF_Handler();

            // Download PDF from Lexware based on document type
            switch ($document->document_type) {
                case 'order_confirmation':
                    $pdf_data = $api->download_order_confirmation_pdf($document->document_id);
                    break;

                case 'credit_note':
                    $pdf_data = $api->download_credit_note_pdf($document->document_id);
                    break;

                case 'invoice':
                default:
                    $pdf_data = $api->download_invoice_pdf($document->document_id);
                    break;
            }

            if (\is_wp_error($pdf_data)) {
                \WC_Lexware_MVP_Logger::error('PDF Download from Lexware failed', array(
                    'order_id' => $order_id,
                    'document_type' => $document->document_type,
                    'error' => $pdf_data->get_error_message()
                ));
                wp_send_json_error('PDF konnte nicht von Lexware geladen werden: ' . $pdf_data->get_error_message());
            }

            // Save PDF with document number
            $pdf_filename = $pdf_handler->save_pdf(
                $pdf_data,
                $order_id,
                $document->document_type,
                $document->document_nr
            );

            if (!$pdf_filename) {
                \WC_Lexware_MVP_Logger::error('PDF save failed on retry', array(
                    'order_id' => $order_id
                ));
                wp_send_json_error('PDF konnte nicht gespeichert werden');
            }

            // Update database with PDF filename
            global $wpdb;
            $table_name = wc_lexware_mvp_get_table_name(false); // Without backticks for wpdb->update()
            $update_result = $wpdb->update(
                $table_name,
                array(
                    'document_filename' => $pdf_filename,
                    'updated_at' => current_time('mysql')
                ),
                array('id' => $document_id),
                array('%s', '%s'),
                array('%d')
            );

            \WC_Lexware_MVP_Logger::info('Database update for PDF retry', array(
                'order_id' => $order_id,
                'document_id' => $document_id,
                'pdf_filename' => $pdf_filename,
                'update_result' => $update_result,
                'wpdb_last_query' => $wpdb->last_query,
                'wpdb_last_error' => $wpdb->last_error
            ));

            \WC_Lexware_MVP_Logger::info('PDF Download retry successful', array(
                'order_id' => $order_id,
                'document_id' => $document_id,
                'pdf_filename' => $pdf_filename
            ));

            // Add order note
            $order = wc_get_order($order_id);
            if ($order) {
                $doc_labels = array(
                    'order_confirmation' => 'Auftragsbestätigung',
                    'invoice' => 'Rechnung',
                    'credit_note' => 'Gutschrift'
                );
                $doc_label = isset($doc_labels[$document->document_type]) ? $doc_labels[$document->document_type] : 'Dokument';
                $order->add_order_note(
                    sprintf(__('%s PDF erfolgreich nachgeladen: %s', WC_LEXWARE_MVP_TEXT_DOMAIN), $doc_label, $pdf_filename)
                );
            }

            wp_send_json_success('PDF erfolgreich geladen. Seite wird neu geladen...');

        } catch (Exception $e) {
            \WC_Lexware_MVP_Logger::critical('Retry PDF Download: Exception', array(
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ));
            wp_send_json_error('Kritischer Fehler: ' . $e->getMessage());
        }
    }

    /**
     * AJAX: Create Invoice Manually
     * For orders that are in the right status but don't have an invoice yet
     */
    public static function ajax_create_invoice_manually() {
        try {
            check_ajax_referer('lexware_mvp_meta_box', 'nonce');

            if (!current_user_can('edit_shop_orders')) {
                \WC_Lexware_MVP_Logger::warning('Create Invoice Manually: Insufficient permissions', array(
                    'user_id' => get_current_user_id()
                ));
                wp_send_json_error('Keine Berechtigung');
            }

            $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;

            if (!$order_id) {
                \WC_Lexware_MVP_Logger::error('Create Invoice Manually: Invalid order ID');
                wp_send_json_error('Ungültige Bestellung');
            }

            // Get order
            $order = wc_get_order($order_id);
            if (!$order) {
                \WC_Lexware_MVP_Logger::error('Create Invoice Manually: Order not found', array(
                    'order_id' => $order_id
                ));
                wp_send_json_error('Bestellung nicht gefunden');
            }

            // Check if order is in the right status
            $current_status = 'wc-' . $order->get_status();
            $invoice_trigger_statuses = get_option('wc_lexware_mvp_invoice_trigger_statuses', array('wc-completed'));

            if (!is_array($invoice_trigger_statuses)) {
                $invoice_trigger_statuses = array($invoice_trigger_statuses);
            }

            if (!in_array($current_status, $invoice_trigger_statuses)) {
                \WC_Lexware_MVP_Logger::warning('Create Invoice Manually: Order not in invoice trigger status', array(
                    'order_id' => $order_id,
                    'current_status' => $current_status,
                    'required_statuses' => $invoice_trigger_statuses
                ));
                wp_send_json_error('Bestellung hat nicht den richtigen Status für die Rechnungserstellung. Aktueller Status: ' . wc_get_order_status_name($order->get_status()));
            }

            // Check if invoice already exists
            global $wpdb;
            $table_name = wc_lexware_mvp_get_table_name();
            $existing_invoice = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_name}
                WHERE order_id = %d
                AND document_type = 'invoice'
                LIMIT 1",
                $order_id
            ));

            if ($existing_invoice) {
                \WC_Lexware_MVP_Logger::warning('Create Invoice Manually: Invoice already exists', array(
                    'order_id' => $order_id,
                    'invoice_id' => $existing_invoice->id,
                    'invoice_status' => $existing_invoice->document_status
                ));
                wp_send_json_error('Für diese Bestellung existiert bereits eine Rechnung (Status: ' . $existing_invoice->document_status . ')');
            }

            \WC_Lexware_MVP_Logger::info('Manual Invoice Creation initiated', array(
                'order_id' => $order_id,
                'order_status' => $current_status,
                'user_id' => get_current_user_id()
            ));

            // Create database record BEFORE scheduling (CRITICAL FIX!)
            global $wpdb;
            $table = wc_lexware_mvp_get_table_name(false);

            // Start transaction
            $wpdb->query('START TRANSACTION');

            try {
                // Check if record already exists
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM `{$table}` WHERE order_id = %d AND document_type = 'invoice'",
                    $order_id
                ));

                if (!$exists) {
                    // Insert pending record
                    $insert_result = $wpdb->insert($table, array(
                        'document_type' => 'invoice',
                        'order_id' => $order_id,
                        'user_id' => $order->get_customer_id(),
                        'document_status' => 'pending',
                        'created_at' => current_time('mysql')
                    ));

                    if ($insert_result === false) {
                        throw new \Exception('Database insert failed: ' . $wpdb->last_error);
                    }

                    \WC_Lexware_MVP_Logger::info('Database record created for manual invoice', array(
                        'order_id' => $order_id,
                        'insert_id' => $wpdb->insert_id
                    ));
                }

                $wpdb->query('COMMIT');
            } catch (\Exception $e) {
                $wpdb->query('ROLLBACK');
                \WC_Lexware_MVP_Logger::error('Failed to create database record', array(
                    'order_id' => $order_id,
                    'error' => $e->getMessage()
                ));
                wp_send_json_error('Datenbankfehler: ' . $e->getMessage());
                return;
            }

            // Schedule invoice creation via Action Scheduler
            $scheduled = false;
            $scheduler_method = '';

            if (function_exists('as_enqueue_async_action')) {
                $action_id = as_enqueue_async_action('wc_lexware_mvp_process_invoice', array($order_id), 'lexware-mvp');
                $scheduled = true;
                $scheduler_method = 'as_enqueue_async_action';

                \WC_Lexware_MVP_Logger::info('Action Scheduler: Task enqueued', array(
                    'order_id' => $order_id,
                    'action_id' => $action_id,
                    'method' => $scheduler_method
                ));
            } elseif (function_exists('as_schedule_single_action')) {
                $action_id = as_schedule_single_action(time(), 'wc_lexware_mvp_process_invoice', array($order_id), 'lexware-mvp');
                $scheduled = true;
                $scheduler_method = 'as_schedule_single_action';

                \WC_Lexware_MVP_Logger::info('Action Scheduler: Task scheduled', array(
                    'order_id' => $order_id,
                    'action_id' => $action_id,
                    'method' => $scheduler_method,
                    'scheduled_time' => date('Y-m-d H:i:s')
                ));
            } else {
                \WC_Lexware_MVP_Logger::error('Create Invoice Manually: Action Scheduler not available');
                wp_send_json_error('Action Scheduler nicht verfügbar. Bitte WooCommerce Action Scheduler Plugin installieren.');
            }

            // Add order note with details
            $order->add_order_note(
                sprintf(
                    __('🚀 Rechnung wird manuell erstellt (Admin)...\nAction Scheduler: %s\nBenutzer: %s', WC_LEXWARE_MVP_TEXT_DOMAIN),
                    $scheduler_method,
                    wp_get_current_user()->user_login
                )
            );

            \WC_Lexware_MVP_Logger::info('Manual Invoice Creation scheduled successfully', array(
                'order_id' => $order_id,
                'scheduler_method' => $scheduler_method,
                'action_id' => isset($action_id) ? $action_id : 'unknown',
                'user_id' => get_current_user_id(),
                'timestamp' => time()
            ));

            wp_send_json_success("✅ Rechnung wurde in Warteschlange eingereiht!\n\n📊 Action Scheduler ID: " . (isset($action_id) ? $action_id : 'N/A') . "\n⏰ Verarbeitung: 5-15 Sekunden\n🔄 Seite wird automatisch neu geladen...");

        } catch (Exception $e) {
            \WC_Lexware_MVP_Logger::critical('Create Invoice Manually: Exception', array(
                'order_id' => isset($order_id) ? $order_id : 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ));
            wp_send_json_error('Kritischer Fehler: ' . $e->getMessage());
        }
    }

    /**
     * AJAX: Create Order Confirmation Manually
     * For orders that are in the right status but don't have an order confirmation yet
     *
     * @since 1.3.0
     */
    public static function ajax_create_order_confirmation_manually() {
        try {
            check_ajax_referer('lexware_mvp_meta_box', 'nonce');

            if (!current_user_can('edit_shop_orders')) {
                \WC_Lexware_MVP_Logger::warning('Create Order Confirmation Manually: Insufficient permissions', array(
                    'user_id' => get_current_user_id()
                ));
                wp_send_json_error('Keine Berechtigung');
            }

            $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;

            if (!$order_id) {
                \WC_Lexware_MVP_Logger::error('Create Order Confirmation Manually: Invalid order ID');
                wp_send_json_error('Ungültige Bestellung');
            }

            // Get order
            $order = wc_get_order($order_id);
            if (!$order) {
                \WC_Lexware_MVP_Logger::error('Create Order Confirmation Manually: Order not found', array(
                    'order_id' => $order_id
                ));
                wp_send_json_error('Bestellung nicht gefunden');
            }

            // Check if order is in the right status
            $current_status = 'wc-' . $order->get_status();
            $oc_trigger_statuses = get_option('wc_lexware_mvp_order_confirmation_trigger_statuses', array());

            if (!is_array($oc_trigger_statuses)) {
                $oc_trigger_statuses = !empty($oc_trigger_statuses) ? array($oc_trigger_statuses) : array();
            }

            if (!in_array($current_status, $oc_trigger_statuses)) {
                \WC_Lexware_MVP_Logger::warning('Create Order Confirmation Manually: Order not in OC trigger status', array(
                    'order_id' => $order_id,
                    'current_status' => $current_status,
                    'required_statuses' => $oc_trigger_statuses
                ));
                wp_send_json_error('Bestellung hat nicht den richtigen Status für Auftragsbestätigung. Aktueller Status: ' . wc_get_order_status_name($order->get_status()));
            }

            // Check if order confirmation already exists
            global $wpdb;
            $table_name = wc_lexware_mvp_get_table_name();
            $existing_oc = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_name}
                WHERE order_id = %d
                AND document_type = 'order_confirmation'
                LIMIT 1",
                $order_id
            ));

            if ($existing_oc) {
                \WC_Lexware_MVP_Logger::warning('Create Order Confirmation Manually: Order confirmation already exists', array(
                    'order_id' => $order_id,
                    'oc_id' => $existing_oc->id,
                    'oc_status' => $existing_oc->document_status
                ));
                wp_send_json_error('Für diese Bestellung existiert bereits eine Auftragsbestätigung (Status: ' . $existing_oc->document_status . ')');
            }

            \WC_Lexware_MVP_Logger::info('Manual Order Confirmation Creation initiated', array(
                'order_id' => $order_id,
                'order_status' => $current_status,
                'user_id' => get_current_user_id()
            ));

            // Create database record BEFORE scheduling (CRITICAL!)
            global $wpdb;
            $table = wc_lexware_mvp_get_table_name(false);

            // Start transaction
            $wpdb->query('START TRANSACTION');

            try {
                // Check if record already exists
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM `{$table}` WHERE order_id = %d AND document_type = 'order_confirmation'",
                    $order_id
                ));

                if (!$exists) {
                    // Insert pending record
                    $insert_result = $wpdb->insert($table, array(
                        'document_type' => 'order_confirmation',
                        'order_id' => $order_id,
                        'user_id' => $order->get_customer_id(),
                        'document_status' => 'pending',
                        'created_at' => current_time('mysql')
                    ));

                    if ($insert_result === false) {
                        throw new \Exception('Database insert failed: ' . $wpdb->last_error);
                    }

                    \WC_Lexware_MVP_Logger::info('Database record created for manual order confirmation', array(
                        'order_id' => $order_id,
                        'insert_id' => $wpdb->insert_id
                    ));
                }

                $wpdb->query('COMMIT');
            } catch (\Exception $e) {
                $wpdb->query('ROLLBACK');
                \WC_Lexware_MVP_Logger::error('Failed to create database record for order confirmation', array(
                    'order_id' => $order_id,
                    'error' => $e->getMessage()
                ));
                wp_send_json_error('Datenbankfehler: ' . $e->getMessage());
                return;
            }

            // Schedule order confirmation creation via Action Scheduler
            $scheduled = false;
            $scheduler_method = '';

            if (function_exists('as_enqueue_async_action')) {
                $action_id = as_enqueue_async_action('wc_lexware_mvp_process_order_confirmation', array($order_id), 'lexware-mvp');
                $scheduled = true;
                $scheduler_method = 'as_enqueue_async_action';

                \WC_Lexware_MVP_Logger::info('Action Scheduler: Order Confirmation Task enqueued', array(
                    'order_id' => $order_id,
                    'action_id' => $action_id,
                    'method' => $scheduler_method
                ));
            } elseif (function_exists('as_schedule_single_action')) {
                $action_id = as_schedule_single_action(time(), 'wc_lexware_mvp_process_order_confirmation', array($order_id), 'lexware-mvp');
                $scheduled = true;
                $scheduler_method = 'as_schedule_single_action';

                \WC_Lexware_MVP_Logger::info('Action Scheduler: Order Confirmation Task scheduled', array(
                    'order_id' => $order_id,
                    'action_id' => $action_id,
                    'method' => $scheduler_method,
                    'scheduled_time' => date('Y-m-d H:i:s')
                ));
            } else {
                \WC_Lexware_MVP_Logger::error('Create Order Confirmation Manually: Action Scheduler not available');
                wp_send_json_error('Action Scheduler nicht verfügbar. Bitte WooCommerce Action Scheduler Plugin installieren.');
            }

            // Add order note with details
            $order->add_order_note(
                sprintf(
                    __('🚀 Auftragsbestätigung wird manuell erstellt (Admin)...\nAction Scheduler: %s\nBenutzer: %s', WC_LEXWARE_MVP_TEXT_DOMAIN),
                    $scheduler_method,
                    wp_get_current_user()->user_login
                )
            );

            \WC_Lexware_MVP_Logger::info('Manual Order Confirmation Creation scheduled successfully', array(
                'order_id' => $order_id,
                'scheduler_method' => $scheduler_method,
                'action_id' => isset($action_id) ? $action_id : 'unknown',
                'user_id' => get_current_user_id(),
                'timestamp' => time()
            ));

            wp_send_json_success("✅ Auftragsbestätigung wurde in Warteschlange eingereiht!\n\n📊 Action Scheduler ID: " . (isset($action_id) ? $action_id : 'N/A') . "\n⏰ Verarbeitung: 5-15 Sekunden\n🔄 Seite wird automatisch neu geladen...");

        } catch (Exception $e) {
            \WC_Lexware_MVP_Logger::critical('Create Order Confirmation Manually: Exception', array(
                'order_id' => isset($order_id) ? $order_id : 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ));
            wp_send_json_error('Kritischer Fehler: ' . $e->getMessage());
        }
    }

    /**
     * AJAX handler to delete external document marking
     *
     * Removes the external document entry, making the order appear
     * as if no document exists again (for re-creation if needed).
     *
     * @since 1.3.0
     */
    public static function ajax_delete_external_document() {
        // Verify nonce
        check_ajax_referer('lexware_mvp_meta_box', 'nonce');

        // Check permissions
        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error('Keine Berechtigung.');
        }

        // Get parameters
        $document_id = isset($_POST['document_id']) ? intval($_POST['document_id']) : 0;
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;

        if (!$document_id || !$order_id) {
            wp_send_json_error('Ungültige Parameter.');
        }

        global $wpdb;
        $table_name = \WC_Lexware_MVP\Core\Database_Helper::get_documents_table_name(false);

        // Only allow deletion of external documents
        $document = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `{$table_name}` WHERE id = %d AND order_id = %d AND document_status = 'external'",
            $document_id,
            $order_id
        ));

        if (!$document) {
            wp_send_json_error('Dokument nicht gefunden oder ist kein externes Dokument.');
        }

        // Delete the external document entry
        $result = $wpdb->delete(
            $table_name,
            array('id' => $document_id),
            array('%d')
        );

        if ($result === false) {
            \WC_Lexware_MVP_Logger::error('Delete External Document failed', array(
                'document_id' => $document_id,
                'order_id' => $order_id,
                'error' => $wpdb->last_error
            ));
            wp_send_json_error('Datenbankfehler beim Löschen.');
        }

        // Log the action
        \WC_Lexware_MVP_Logger::info('External document marking deleted', array(
            'document_id' => $document_id,
            'order_id' => $order_id,
            'document_type' => $document->document_type,
            'deleted_by' => get_current_user_id()
        ));

        // Add order note
        $order = wc_get_order($order_id);
        if ($order) {
            $doc_labels = array(
                'order_confirmation' => 'Auftragsbestätigung',
                'invoice' => 'Rechnung',
                'credit_note' => 'Gutschrift'
            );
            $doc_label = isset($doc_labels[$document->document_type]) ? $doc_labels[$document->document_type] : 'Dokument';

            $order->add_order_note(
                sprintf(
                    __('🗑️ Externe %s-Markierung entfernt von %s', WC_LEXWARE_MVP_TEXT_DOMAIN),
                    $doc_label,
                    wp_get_current_user()->user_login
                )
            );
        }

        wp_send_json_success('Externe Markierung wurde entfernt.');
    }
}

