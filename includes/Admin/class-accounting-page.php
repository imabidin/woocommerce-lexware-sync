<?php
/**
 * Accounting Admin Page - Document Overview for Bookkeeping
 *
 * Provides interface for:
 * - Viewing all Lexware documents (invoices, order confirmations, credit notes)
 * - Filtering by document type and status
 * - Search by order ID or document number
 * - CSV export for accounting software
 *
 * @package WC_Lexware_MVP
 * @since 1.3.1
 */

namespace WC_Lexware_MVP\Admin;

if (!defined('ABSPATH')) {
    exit;
}

class Accounting_Page {

    /**
     * Initialize hooks
     */
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_menu_page'), 55);
        add_action('admin_post_lexware_export_csv', array(__CLASS__, 'handle_csv_export'));
        add_action('wp_ajax_lexware_create_missing_document', array(__CLASS__, 'ajax_create_missing_document'));
        add_action('wp_ajax_lexware_mark_external_document', array(__CLASS__, 'ajax_mark_external_document'));
        add_action('wp_ajax_lexware_delete_external_document', array(__CLASS__, 'ajax_delete_external_document'));
        add_action('wp_ajax_lexware_import_external_document', array(__CLASS__, 'ajax_import_external_document'));

        // Failed documents handlers
        add_action('wp_ajax_lexware_retry_failed_document', array(__CLASS__, 'ajax_retry_failed_document'));
        add_action('wp_ajax_lexware_delete_failed_document', array(__CLASS__, 'ajax_delete_failed_document'));
        add_action('admin_post_lexware_bulk_retry_accounting', array(__CLASS__, 'handle_bulk_retry_accounting'));
        add_action('admin_post_lexware_bulk_delete_accounting', array(__CLASS__, 'handle_bulk_delete_accounting'));

        // Lexware API Export handlers
        add_action('wp_ajax_lexware_quick_export', array(__CLASS__, 'ajax_quick_export'));
        add_action('wp_ajax_lexware_start_detail_export', array(__CLASS__, 'ajax_start_detail_export'));
        add_action('wp_ajax_lexware_export_progress', array(__CLASS__, 'ajax_export_progress'));
        add_action('wp_ajax_lexware_download_export', array(__CLASS__, 'ajax_download_export'));

        // Background job for detail export
        add_action('lexware_process_detail_export', array(__CLASS__, 'process_detail_export'));
    }

    /**
     * Add submenu page under WooCommerce
     */
    public static function add_menu_page() {
        add_submenu_page(
            'woocommerce',
            __('Buchhaltung', WC_LEXWARE_MVP_TEXT_DOMAIN),
            __('Buchhaltung', WC_LEXWARE_MVP_TEXT_DOMAIN),
            'manage_woocommerce',
            'lexware-accounting',
            array(__CLASS__, 'render_page')
        );
    }

    /**
     * Get document type labels
     *
     * @return array
     */
    private static function get_document_type_labels() {
        return array(
            'invoice' => __('Rechnung', WC_LEXWARE_MVP_TEXT_DOMAIN),
            'order_confirmation' => __('Auftragsbestätigung', WC_LEXWARE_MVP_TEXT_DOMAIN),
            'credit_note' => __('Gutschrift', WC_LEXWARE_MVP_TEXT_DOMAIN),
        );
    }

    /**
     * Get status labels
     *
     * @return array
     */
    private static function get_status_labels() {
        return array(
            'pending' => __('Wartend', WC_LEXWARE_MVP_TEXT_DOMAIN),
            'pending_retry' => __('Retry geplant', WC_LEXWARE_MVP_TEXT_DOMAIN),
            'processing' => __('In Bearbeitung', WC_LEXWARE_MVP_TEXT_DOMAIN),
            'synced' => __('Synchronisiert', WC_LEXWARE_MVP_TEXT_DOMAIN),
            'failed' => __('Fehlgeschlagen', WC_LEXWARE_MVP_TEXT_DOMAIN),
            'failed_auth' => __('Auth-Fehler', WC_LEXWARE_MVP_TEXT_DOMAIN),
            'failed_validation' => __('Validierungsfehler', WC_LEXWARE_MVP_TEXT_DOMAIN),
            'external' => __('Extern/Manuell', WC_LEXWARE_MVP_TEXT_DOMAIN),
        );
    }

    /**
     * Render admin page
     */
    public static function render_page() {
        global $wpdb;
        $table = wc_lexware_mvp_get_table_name();

        // Get filter values
        $filter_type = isset($_GET['document_type']) ? sanitize_text_field($_GET['document_type']) : '';
        $filter_status = isset($_GET['document_status']) ? sanitize_text_field($_GET['document_status']) : '';
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
        $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';

        // Build query
        $where_clauses = array('1=1');
        $query_params = array();

        if (!empty($filter_type)) {
            $where_clauses[] = 'document_type = %s';
            $query_params[] = $filter_type;
        }

        if (!empty($filter_status)) {
            $where_clauses[] = 'document_status = %s';
            $query_params[] = $filter_status;
        }

        if (!empty($search)) {
            $where_clauses[] = '(order_id LIKE %s OR document_nr LIKE %s OR document_id LIKE %s)';
            $search_like = '%' . $wpdb->esc_like($search) . '%';
            $query_params[] = $search_like;
            $query_params[] = $search_like;
            $query_params[] = $search_like;
        }

        if (!empty($date_from)) {
            $where_clauses[] = 'DATE(created_at) >= %s';
            $query_params[] = $date_from;
        }

        if (!empty($date_to)) {
            $where_clauses[] = 'DATE(created_at) <= %s';
            $query_params[] = $date_to;
        }

        $where_sql = implode(' AND ', $where_clauses);

        // Pagination
        $per_page = 50;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;

        // Count total
        $count_query = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        if (!empty($query_params)) {
            $count_query = $wpdb->prepare($count_query, $query_params);
        }
        $total_items = (int) $wpdb->get_var($count_query);
        $total_pages = ceil($total_items / $per_page);

        // Get documents
        $query = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $query_params[] = $per_page;
        $query_params[] = $offset;
        $documents = $wpdb->get_results($wpdb->prepare($query, $query_params));

        // Get statistics
        $stats = self::get_statistics();

        $type_labels = self::get_document_type_labels();
        $status_labels = self::get_status_labels();

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">
                <?php _e('Buchhaltung - Lexware Dokumente', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>
            </h1>

            <?php
            $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'documents';
            ?>

            <?php
            // Count failed documents for conditional tab display
            $failed_count = self::get_failed_documents_count();
            $missing_count = self::count_orders_with_missing_documents();
            ?>

            <!-- Tab Navigation -->
            <nav class="nav-tab-wrapper wp-clearfix">
                <a href="<?php echo admin_url('admin.php?page=lexware-accounting&tab=documents'); ?>"
                   class="nav-tab <?php echo $current_tab === 'documents' ? 'nav-tab-active' : ''; ?>">
                    📄 <?php _e('Dokumente', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>
                </a>
                <a href="<?php echo admin_url('admin.php?page=lexware-accounting&tab=missing'); ?>"
                   class="nav-tab <?php echo $current_tab === 'missing' ? 'nav-tab-active' : ''; ?>">
                    ⚠️ <?php _e('Fehlende Dokumente', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>
                    <?php if ($missing_count > 0): ?>
                        <span class="lexware-tab-badge"><?php echo $missing_count; ?></span>
                    <?php endif; ?>
                </a>
                <?php if ($failed_count > 0): ?>
                <a href="<?php echo admin_url('admin.php?page=lexware-accounting&tab=failed'); ?>"
                   class="nav-tab <?php echo $current_tab === 'failed' ? 'nav-tab-active' : ''; ?>">
                    ❌ <?php _e('Fehlgeschlagen', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>
                    <span class="lexware-tab-badge lexware-tab-badge-error"><?php echo $failed_count; ?></span>
                </a>
                <?php endif; ?>
            </nav>

            <?php if ($current_tab === 'missing'): ?>
                <?php self::render_missing_documents_tab(); ?>
            <?php elseif ($current_tab === 'failed'): ?>
                <?php self::render_failed_documents_tab(); ?>
            <?php else: ?>

            <!-- Statistics -->
            <div class="lexware-stats-container">
                <div class="lexware-stat-box">
                    <span class="lexware-stat-number lexware-stat-total"><?php echo number_format_i18n($stats['total']); ?></span>
                    <span class="lexware-stat-label"><?php _e('Gesamt', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></span>
                </div>
                <div class="lexware-stat-box">
                    <span class="lexware-stat-number lexware-stat-success"><?php echo number_format_i18n($stats['synced']); ?></span>
                    <span class="lexware-stat-label"><?php _e('Synchronisiert', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></span>
                </div>
                <div class="lexware-stat-box">
                    <span class="lexware-stat-number lexware-stat-warning"><?php echo number_format_i18n($stats['pending']); ?></span>
                    <span class="lexware-stat-label"><?php _e('Wartend', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></span>
                </div>
                <div class="lexware-stat-box">
                    <span class="lexware-stat-number lexware-stat-danger"><?php echo number_format_i18n($stats['failed']); ?></span>
                    <span class="lexware-stat-label"><?php _e('Fehlgeschlagen', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></span>
                </div>
            </div>

            <!-- Filters -->
            <form method="get" action="" class="lexware-filter-form">
                <input type="hidden" name="page" value="lexware-accounting">

                <div class="lexware-filter-row">
                    <!-- Document Type Filter -->
                    <div class="lexware-filter-group">
                        <label for="document_type" class="lexware-filter-label">
                            <?php _e('Dokumenttyp', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>
                        </label>
                        <select name="document_type" id="document_type">
                            <option value=""><?php _e('Alle Typen', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></option>
                            <?php foreach ($type_labels as $value => $label): ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($filter_type, $value); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Status Filter -->
                    <div class="lexware-filter-group">
                        <label for="document_status" class="lexware-filter-label">
                            <?php _e('Status', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>
                        </label>
                        <select name="document_status" id="document_status">
                            <option value=""><?php _e('Alle Status', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></option>
                            <?php foreach ($status_labels as $value => $label): ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($filter_status, $value); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Date Range -->
                    <div class="lexware-filter-group">
                        <label for="date_from" class="lexware-filter-label">
                            <?php _e('Von', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>
                        </label>
                        <input type="date" name="date_from" id="date_from" value="<?php echo esc_attr($date_from); ?>">
                    </div>

                    <div class="lexware-filter-group">
                        <label for="date_to" class="lexware-filter-label">
                            <?php _e('Bis', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>
                        </label>
                        <input type="date" name="date_to" id="date_to" value="<?php echo esc_attr($date_to); ?>">
                    </div>

                    <!-- Search -->
                    <div class="lexware-filter-group">
                        <label for="s" class="lexware-filter-label">
                            <?php _e('Suche', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>
                        </label>
                        <input type="text" name="s" id="s" value="<?php echo esc_attr($search); ?>"
                               placeholder="<?php esc_attr_e('Order ID oder Dokument-Nr.', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>">
                    </div>

                    <div class="lexware-filter-group lexware-filter-actions">
                        <button type="submit" class="button"><?php _e('Filtern', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></button>
                        <a href="<?php echo admin_url('admin.php?page=lexware-accounting'); ?>" class="button">
                            <?php _e('Zurücksetzen', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>
                        </a>
                    </div>
                </div>
            </form>

            <!-- CSV Export (lokale Daten) -->
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" class="lexware-export-form">
                <input type="hidden" name="action" value="lexware_export_csv">
                <input type="hidden" name="document_type" value="<?php echo esc_attr($filter_type); ?>">
                <input type="hidden" name="document_status" value="<?php echo esc_attr($filter_status); ?>">
                <input type="hidden" name="date_from" value="<?php echo esc_attr($date_from); ?>">
                <input type="hidden" name="date_to" value="<?php echo esc_attr($date_to); ?>">
                <input type="hidden" name="s" value="<?php echo esc_attr($search); ?>">
                <?php wp_nonce_field('lexware_export_csv', 'lexware_nonce'); ?>
                <button type="submit" class="button">
                    <?php _e('📊 Lokaler Export', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>
                </button>
                <span class="description lexware-export-hint">
                    <?php echo sprintf(__('Exportiert %d Dokumente mit aktuellen Filtern', WC_LEXWARE_MVP_TEXT_DOMAIN), $total_items); ?>
                </span>
            </form>

            <!-- Lexware API Export Buttons -->
            <div class="lexware-api-export-section" style="margin-top: 15px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
                <h4 style="margin-top: 0; margin-bottom: 10px;"><?php _e('Lexware Beleg-Export', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></h4>
                <p class="description" style="margin-bottom: 10px;">
                    <?php _e('Exportiert alle Belege direkt aus deinem Lexware-Konto.', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>
                </p>
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <button type="button" class="button" id="lexware-quick-export">
                        <?php _e('⚡ Schnell-Export', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>
                    </button>
                    <button type="button" class="button button-primary" id="lexware-detail-export">
                        <?php _e('📥 Detail-Export', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>
                    </button>
                    <span class="description">
                        <?php _e('Schnell = Basis-Daten | Detail = Alle Felder (dauert länger)', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>
                    </span>
                </div>

                <!-- Progress Anzeige für Detail-Export -->
                <div id="lexware-export-progress" style="display:none; margin-top:15px; padding:15px; background:#fff; border:1px solid #ddd; border-radius:4px;">
                    <div style="display:flex; align-items:center; gap:10px;">
                        <span class="spinner is-active" style="float:none;"></span>
                        <span class="progress-text"><?php _e('Starte Export...', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></span>
                    </div>
                    <div class="progress-bar" style="margin-top:10px; height:24px; background:#ddd; border-radius:4px; overflow:hidden;">
                        <div class="progress-fill" style="width:0%; height:100%; background:#2271b1; transition:width 0.3s;"></div>
                    </div>
                    <div class="progress-details" style="margin-top:8px; font-size:12px; color:#666;">
                        <span class="current">0</span> / <span class="total">0</span> <?php _e('Belege', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>
                    </div>
                </div>
            </div>

            <!-- Lexware API Export JavaScript -->
            <script>
            jQuery(document).ready(function($) {
                var exportPollInterval = null;
                var exportNonce = '<?php echo wp_create_nonce('lexware_export'); ?>';

                // ========== SCHNELL-EXPORT (synchron) ==========
                $('#lexware-quick-export').on('click', function() {
                    var $btn = $(this);
                    var originalText = $btn.text();
                    $btn.prop('disabled', true).text('<?php _e('⏳ Exportiere...', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        timeout: 120000, // 2 Minuten
                        data: {
                            action: 'lexware_quick_export',
                            nonce: exportNonce
                        },
                        success: function(response) {
                            if (response.success) {
                                downloadCSV(response.data.csv, response.data.filename);
                                showExportNotice('success', response.data.count + ' <?php _e('Belege exportiert!', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>');
                            } else {
                                showExportNotice('error', response.data.message);
                            }
                        },
                        error: function(xhr, status, error) {
                            var msg = status === 'timeout' ? '<?php _e('Timeout - zu viele Belege?', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>' : '<?php _e('Verbindungsfehler', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>';
                            showExportNotice('error', msg);
                        },
                        complete: function() {
                            $btn.prop('disabled', false).text(originalText);
                        }
                    });
                });

                // ========== DETAIL-EXPORT (Background Job) ==========
                $('#lexware-detail-export').on('click', function() {
                    var $btn = $(this);
                    var $progress = $('#lexware-export-progress');

                    $btn.prop('disabled', true);
                    $progress.show();
                    updateProgress(0, 0, '<?php _e('Starte Export...', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'lexware_start_detail_export',
                            nonce: exportNonce
                        },
                        success: function(response) {
                            if (response.success) {
                                updateProgress(0, response.data.total, '<?php _e('Lade Beleg-Details...', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>');
                                pollExportProgress(response.data.export_id);
                            } else {
                                showExportNotice('error', response.data.message);
                                resetExportUI();
                            }
                        },
                        error: function() {
                            showExportNotice('error', '<?php _e('Verbindungsfehler', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>');
                            resetExportUI();
                        }
                    });
                });

                function pollExportProgress(exportId) {
                    exportPollInterval = setInterval(function() {
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'lexware_export_progress',
                                nonce: exportNonce,
                                export_id: exportId
                            },
                            success: function(response) {
                                if (response.success) {
                                    var data = response.data;
                                    updateProgress(data.processed, data.total);

                                    if (data.status === 'completed') {
                                        clearInterval(exportPollInterval);
                                        downloadDetailExport(exportId);
                                    } else if (data.error) {
                                        clearInterval(exportPollInterval);
                                        showExportNotice('error', data.error);
                                        resetExportUI();
                                    }
                                }
                            }
                        });
                    }, 1000);
                }

                function downloadDetailExport(exportId) {
                    updateProgress(100, 100, '<?php _e('Erstelle CSV...', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'lexware_download_export',
                            nonce: exportNonce,
                            export_id: exportId
                        },
                        success: function(response) {
                            if (response.success) {
                                downloadCSV(response.data.csv, response.data.filename);
                                showExportNotice('success', response.data.count + ' <?php _e('Belege mit Details exportiert!', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>');
                            } else {
                                showExportNotice('error', response.data.message);
                            }
                            resetExportUI();
                        }
                    });
                }

                function updateProgress(current, total, text) {
                    var $progress = $('#lexware-export-progress');
                    var percent = total > 0 ? Math.round((current / total) * 100) : 0;

                    $progress.find('.progress-fill').css('width', percent + '%');
                    $progress.find('.current').text(current);
                    $progress.find('.total').text(total);

                    if (text) {
                        $progress.find('.progress-text').text(text);
                    } else {
                        $progress.find('.progress-text').text('<?php _e('Lade Beleg', WC_LEXWARE_MVP_TEXT_DOMAIN); ?> ' + current + ' <?php _e('von', WC_LEXWARE_MVP_TEXT_DOMAIN); ?> ' + total + '...');
                    }
                }

                function resetExportUI() {
                    $('#lexware-detail-export').prop('disabled', false);
                    setTimeout(function() {
                        $('#lexware-export-progress').fadeOut();
                    }, 3000);
                }

                function downloadCSV(csv, filename) {
                    var blob = new Blob([csv], {type: 'text/csv;charset=utf-8;'});
                    var link = document.createElement('a');
                    link.href = URL.createObjectURL(blob);
                    link.download = filename;
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                }

                function showExportNotice(type, message) {
                    var icon = type === 'success' ? '✅' : '❌';
                    var $progress = $('#lexware-export-progress');
                    $progress.show().find('.progress-text').text(icon + ' ' + message);
                    $progress.find('.spinner').removeClass('is-active');
                }
            });
            </script>

            <!-- Results Table -->
            <?php if (empty($documents)): ?>
                <div class="notice notice-info">
                    <p><?php _e('Keine Dokumente gefunden.', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></p>
                </div>
            <?php else: ?>

                <table class="wp-list-table widefat fixed striped lexware-documents-table">
                    <thead>
                        <tr>
                            <th class="column-order"><?php _e('Order', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></th>
                            <th class="column-type"><?php _e('Dokumenttyp', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></th>
                            <th class="column-related"><?php _e('Verwandt', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></th>
                            <th class="column-number"><?php _e('Dokument-Nr.', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></th>
                            <th class="column-status"><?php _e('Status', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></th>
                            <th class="column-amount"><?php _e('Betrag', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></th>
                            <th class="column-created"><?php _e('Erstellt', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></th>
                            <th class="column-synced"><?php _e('Synchronisiert', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></th>
                            <th class="column-actions"><?php _e('Aktionen', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($documents as $doc): ?>
                            <?php
                            $order = wc_get_order($doc->order_id);
                            $order_total = $order ? $order->get_total() : 0;

                            // For credit notes, use refund amount
                            if ($doc->document_type === 'credit_note' && !empty($doc->refund_amount)) {
                                $amount = $doc->refund_amount;
                            } else {
                                $amount = $order_total;
                            }

                            $status_class = 'status-' . str_replace('_', '-', $doc->document_status);

                            // Get related documents for this order
                            $related_docs = self::get_related_documents($doc->order_id, $doc->id);
                            ?>
                            <tr>
                                <td>
                                    <?php if ($order): ?>
                                        <a href="<?php echo $order->get_edit_order_url(); ?>">#<?php echo $doc->order_id; ?></a>
                                    <?php else: ?>
                                        <span class="lexware-muted">#<?php echo $doc->order_id; ?></span>
                                        <br><small class="lexware-deleted-hint"><?php _e('(gelöscht)', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $type_icon = '';
                                    switch ($doc->document_type) {
                                        case 'invoice': $type_icon = '📄'; break;
                                        case 'order_confirmation': $type_icon = '📋'; break;
                                        case 'credit_note': $type_icon = '💳'; break;
                                    }
                                    echo $type_icon . ' ' . esc_html($type_labels[$doc->document_type] ?? $doc->document_type);
                                    ?>
                                </td>
                                <td class="column-related">
                                    <?php if (!empty($related_docs)): ?>
                                        <?php foreach ($related_docs as $rel): ?>
                                            <?php
                                            $rel_icon = '';
                                            $rel_label = '';
                                            switch ($rel->document_type) {
                                                case 'invoice':
                                                    $rel_icon = '📄';
                                                    $rel_label = 'RE';
                                                    break;
                                                case 'order_confirmation':
                                                    $rel_icon = '📋';
                                                    $rel_label = 'AB';
                                                    break;
                                                case 'credit_note':
                                                    $rel_icon = '💳';
                                                    $rel_label = 'GS';
                                                    break;
                                            }
                                            $rel_status_class = 'status-' . str_replace('_', '-', $rel->document_status);
                                            ?>
                                            <span class="lexware-related-badge <?php echo esc_attr($rel_status_class); ?>" title="<?php echo esc_attr($type_labels[$rel->document_type] ?? $rel->document_type); ?>: <?php echo esc_attr($rel->document_nr ?: 'In Bearbeitung'); ?>">
                                                <?php echo $rel_icon; ?> <?php echo esc_html($rel_label); ?>
                                            </span>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span class="lexware-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($doc->document_nr)): ?>
                                        <code><?php echo esc_html($doc->document_nr); ?></code>
                                    <?php else: ?>
                                        <span class="lexware-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="lexware-status-badge <?php echo esc_attr($status_class); ?>">
                                        <?php echo esc_html($status_labels[$doc->document_status] ?? $doc->document_status); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo wc_price($amount); ?>
                                </td>
                                <td>
                                    <?php echo date_i18n('d.m.Y H:i', strtotime($doc->created_at)); ?>
                                </td>
                                <td>
                                    <?php if (!empty($doc->synced_at)): ?>
                                        <?php echo date_i18n('d.m.Y H:i', strtotime($doc->synced_at)); ?>
                                    <?php else: ?>
                                        <span class="lexware-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="column-actions-buttons">
                                    <?php if (!empty($doc->document_filename)): ?>
                                        <a href="<?php echo esc_url(self::get_pdf_url($doc)); ?>" target="_blank" class="button button-small">
                                            📥 PDF
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($order): ?>
                                        <a href="<?php echo $order->get_edit_order_url(); ?>" class="button button-small">
                                            👁 Order
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($doc->document_status === 'external'): ?>
                                        <button type="button"
                                                class="button button-small lexware-delete-external-btn"
                                                data-doc-id="<?php echo esc_attr($doc->id); ?>"
                                                data-order-id="<?php echo esc_attr($doc->order_id); ?>"
                                                title="<?php esc_attr_e('Externe Markierung entfernen', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>">
                                            🗑️
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="tablenav bottom">
                        <div class="tablenav-pages">
                            <span class="displaying-num">
                                <?php echo sprintf(__('%d Dokumente', WC_LEXWARE_MVP_TEXT_DOMAIN), $total_items); ?>
                            </span>
                            <?php
                            $page_links = paginate_links(array(
                                'base' => add_query_arg('paged', '%#%'),
                                'format' => '',
                                'prev_text' => '&laquo;',
                                'next_text' => '&raquo;',
                                'total' => $total_pages,
                                'current' => $current_page
                            ));
                            echo $page_links;
                            ?>
                        </div>
                    </div>
                <?php endif; ?>

            <!-- Delete External Document Script -->
            <script>
            jQuery(document).ready(function($) {
                // Delete external document
                $('.lexware-delete-external-btn').on('click', function() {
                    if (!confirm('<?php _e('Externe Markierung wirklich entfernen? Die Bestellung erscheint dann wieder bei den fehlenden Dokumenten.', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>')) {
                        return;
                    }

                    var $btn = $(this);
                    var $row = $btn.closest('tr');
                    var docId = $btn.data('doc-id');

                    $btn.prop('disabled', true).text('⏳');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'lexware_delete_external_document',
                            doc_id: docId,
                            nonce: '<?php echo wp_create_nonce('lexware_delete_external'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                $row.fadeOut(400, function() {
                                    $(this).remove();
                                });
                            } else {
                                alert('Fehler: ' + (response.data || 'Unbekannter Fehler'));
                                $btn.prop('disabled', false).html('🗑️');
                            }
                        },
                        error: function() {
                            alert('AJAX Fehler');
                            $btn.prop('disabled', false).html('🗑️');
                        }
                    });
                });
            });
            </script>

            <?php endif; // end tab=missing check ?>
            <?php endif; // end tab conditional ?>

        </div>
        <?php
    }

    /**
     * Get document statistics
     *
     * @return array
     */
    private static function get_statistics() {
        global $wpdb;
        $table = wc_lexware_mvp_get_table_name();

        $stats = $wpdb->get_results(
            "SELECT document_status, COUNT(*) as count
             FROM {$table}
             GROUP BY document_status",
            OBJECT_K
        );

        return array(
            'total' => array_sum(array_column($stats, 'count')),
            'synced' => isset($stats['synced']) ? $stats['synced']->count : 0,
            'pending' => (isset($stats['pending']) ? $stats['pending']->count : 0) +
                        (isset($stats['pending_retry']) ? $stats['pending_retry']->count : 0) +
                        (isset($stats['processing']) ? $stats['processing']->count : 0),
            'failed' => (isset($stats['failed']) ? $stats['failed']->count : 0) +
                       (isset($stats['failed_auth']) ? $stats['failed_auth']->count : 0) +
                       (isset($stats['failed_validation']) ? $stats['failed_validation']->count : 0),
        );
    }

    /**
     * Get PDF download URL
     *
     * @param object $doc Document record
     * @return string URL
     */
    private static function get_pdf_url($doc) {
        $upload_dir = wp_upload_dir();
        $pdf_dir = $upload_dir['baseurl'] . '/lexware-mvp-pdfs/';
        return $pdf_dir . $doc->document_filename;
    }

    /**
     * Get related documents for an order (excluding current document)
     *
     * @param int $order_id Order ID
     * @param int $exclude_id Document ID to exclude (current document)
     * @return array Related documents
     */
    private static function get_related_documents($order_id, $exclude_id) {
        global $wpdb;
        $table = wc_lexware_mvp_get_table_name();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, document_type, document_nr, document_status
             FROM {$table}
             WHERE order_id = %d AND id != %d
             ORDER BY FIELD(document_type, 'order_confirmation', 'invoice', 'credit_note')",
            $order_id,
            $exclude_id
        ));
    }

    /**
     * Handle CSV export
     */
    public static function handle_csv_export() {
        // Verify nonce
        if (!isset($_POST['lexware_nonce']) || !wp_verify_nonce($_POST['lexware_nonce'], 'lexware_export_csv')) {
            wp_die(__('Security check failed', WC_LEXWARE_MVP_TEXT_DOMAIN));
        }

        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to export data', WC_LEXWARE_MVP_TEXT_DOMAIN));
        }

        global $wpdb;
        $table = wc_lexware_mvp_get_table_name();

        // Build query with filters
        $filter_type = isset($_POST['document_type']) ? sanitize_text_field($_POST['document_type']) : '';
        $filter_status = isset($_POST['document_status']) ? sanitize_text_field($_POST['document_status']) : '';
        $search = isset($_POST['s']) ? sanitize_text_field($_POST['s']) : '';
        $date_from = isset($_POST['date_from']) ? sanitize_text_field($_POST['date_from']) : '';
        $date_to = isset($_POST['date_to']) ? sanitize_text_field($_POST['date_to']) : '';

        $where_clauses = array('1=1');
        $query_params = array();

        if (!empty($filter_type)) {
            $where_clauses[] = 'document_type = %s';
            $query_params[] = $filter_type;
        }

        if (!empty($filter_status)) {
            $where_clauses[] = 'document_status = %s';
            $query_params[] = $filter_status;
        }

        if (!empty($search)) {
            $where_clauses[] = '(order_id LIKE %s OR document_nr LIKE %s)';
            $search_like = '%' . $wpdb->esc_like($search) . '%';
            $query_params[] = $search_like;
            $query_params[] = $search_like;
        }

        if (!empty($date_from)) {
            $where_clauses[] = 'DATE(created_at) >= %s';
            $query_params[] = $date_from;
        }

        if (!empty($date_to)) {
            $where_clauses[] = 'DATE(created_at) <= %s';
            $query_params[] = $date_to;
        }

        $where_sql = implode(' AND ', $where_clauses);

        $query = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC";
        if (!empty($query_params)) {
            $query = $wpdb->prepare($query, $query_params);
        }

        $documents = $wpdb->get_results($query);

        // Generate CSV
        $filename = 'lexware-documents-' . date('Y-m-d-His') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $output = fopen('php://output', 'w');

        // BOM for Excel UTF-8
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // Header row
        fputcsv($output, array(
            'Order ID',
            'Dokumenttyp',
            'Dokument-Nr.',
            'Lexware ID',
            'Status',
            'Betrag',
            'Erstellt',
            'Synchronisiert',
            'Finalisiert',
            'Kunde',
            'E-Mail'
        ), ';');

        $type_labels = self::get_document_type_labels();
        $status_labels = self::get_status_labels();

        foreach ($documents as $doc) {
            $order = wc_get_order($doc->order_id);

            if ($doc->document_type === 'credit_note' && !empty($doc->refund_amount)) {
                $amount = $doc->refund_amount;
            } else {
                $amount = $order ? $order->get_total() : 0;
            }

            fputcsv($output, array(
                $doc->order_id,
                $type_labels[$doc->document_type] ?? $doc->document_type,
                $doc->document_nr ?? '',
                $doc->document_id ?? '',
                $status_labels[$doc->document_status] ?? $doc->document_status,
                number_format($amount, 2, ',', '.'),
                $doc->created_at,
                $doc->synced_at ?? '',
                $doc->document_finalized ? 'Ja' : 'Nein',
                $order ? $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() : '(gelöscht)',
                $order ? $order->get_billing_email() : ''
            ), ';');
        }

        fclose($output);
        exit;
    }

    /**
     * Get status to document type mapping
     *
     * @return array Map of order status => expected document type
     */
    private static function get_status_document_mapping() {
        // Get settings
        $invoice_statuses = get_option('wc_lexware_mvp_invoice_statuses', array('completed'));
        $order_confirmation_statuses = get_option('wc_lexware_mvp_order_confirmation_statuses', array('processing'));

        $mapping = array();

        // Invoice statuses
        if (is_array($invoice_statuses)) {
            foreach ($invoice_statuses as $status) {
                $mapping[$status] = 'invoice';
            }
        }

        // Order confirmation statuses (if not already set for invoice)
        if (is_array($order_confirmation_statuses)) {
            foreach ($order_confirmation_statuses as $status) {
                if (!isset($mapping[$status])) {
                    $mapping[$status] = 'order_confirmation';
                }
            }
        }

        return $mapping;
    }

    /**
     * Count orders with missing documents
     *
     * @return int Number of orders missing documents
     */
    private static function count_orders_with_missing_documents() {
        $orders = self::get_orders_with_missing_documents(1, 0);
        return $orders['total'];
    }

    /**
     * Get orders that should have documents but don't
     *
     * @param int $per_page Items per page
     * @param int $offset Offset for pagination
     * @return array Array with 'orders' and 'total'
     */
    private static function get_orders_with_missing_documents($per_page = 50, $offset = 0) {
        global $wpdb;
        $table = wc_lexware_mvp_get_table_name();
        $status_mapping = self::get_status_document_mapping();

        if (empty($status_mapping)) {
            return array('orders' => array(), 'total' => 0);
        }

        // Build status list for query
        $statuses = array_keys($status_mapping);
        $wc_statuses = array_map(function($s) {
            return 'wc-' . $s;
        }, $statuses);

        $missing_orders = array();
        $total_count = 0;

        // Get orders with these statuses (exclude refunds - they are handled separately for credit notes)
        $orders_query = wc_get_orders(array(
            'status' => $statuses,
            'type' => 'shop_order',
            'limit' => -1,
            'return' => 'ids',
            'orderby' => 'date',
            'order' => 'DESC',
        ));

        foreach ($orders_query as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order) continue;

            $order_status = $order->get_status();
            $expected_doc_type = $status_mapping[$order_status] ?? null;

            if (!$expected_doc_type) continue;

            // Check if document exists
            $doc_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE order_id = %d AND document_type = %s",
                $order_id,
                $expected_doc_type
            ));

            if (!$doc_exists) {
                $total_count++;

                // Only add to results if within pagination range
                if ($total_count > $offset && count($missing_orders) < $per_page) {
                    $missing_orders[] = array(
                        'order' => $order,
                        'expected_type' => $expected_doc_type,
                    );
                }
            }
        }

        // Also check for missing credit notes (refunds with parent invoice but no credit note)
        $refunds_without_credit_notes = self::get_refunds_without_credit_notes();
        foreach ($refunds_without_credit_notes as $refund_data) {
            $total_count++;

            // Only add to results if within pagination range
            if ($total_count > $offset && count($missing_orders) < $per_page) {
                $missing_orders[] = array(
                    'order' => $refund_data['parent_order'],
                    'expected_type' => 'credit_note',
                    'refund' => $refund_data['refund'],
                    'refund_id' => $refund_data['refund_id'],
                );
            }
        }

        return array(
            'orders' => $missing_orders,
            'total' => $total_count,
        );
    }

    /**
     * Get refunds that should have credit notes but don't
     * A credit note is required when: parent order has an invoice AND refund has no credit_note yet
     *
     * @return array Array of refund data with parent order info
     */
    private static function get_refunds_without_credit_notes() {
        global $wpdb;
        $table = wc_lexware_mvp_get_table_name();

        // Get all refunds where:
        // 1. Parent order has a synced invoice
        // 2. No credit_note exists for this refund
        $results = $wpdb->get_results("
            SELECT
                r.id as refund_id,
                r.parent_order_id
            FROM {$wpdb->prefix}wc_orders r
            WHERE r.type = 'shop_order_refund'
            AND EXISTS (
                SELECT 1 FROM {$table} d
                WHERE d.order_id = r.parent_order_id
                AND d.document_type = 'invoice'
                AND d.document_status = 'synced'
            )
            AND NOT EXISTS (
                SELECT 1 FROM {$table} d
                WHERE d.refund_id = r.id
                AND d.document_type = 'credit_note'
            )
            ORDER BY r.date_created_gmt DESC
        ");

        $refunds_data = array();
        foreach ($results as $row) {
            $refund = wc_get_order($row->refund_id);
            $parent_order = wc_get_order($row->parent_order_id);

            if ($refund && $parent_order) {
                $refunds_data[] = array(
                    'refund_id' => $row->refund_id,
                    'refund' => $refund,
                    'parent_order' => $parent_order,
                );
            }
        }

        return $refunds_data;
    }

    /**
     * Render the missing documents tab
     */
    private static function render_missing_documents_tab() {
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 50;
        $offset = ($current_page - 1) * $per_page;

        $result = self::get_orders_with_missing_documents($per_page, $offset);
        $missing_orders = $result['orders'];
        $total_items = $result['total'];
        $total_pages = ceil($total_items / $per_page);

        $type_labels = self::get_document_type_labels();
        $status_mapping = self::get_status_document_mapping();
        ?>

        <div class="lexware-missing-docs-info">
            <p>
                <?php _e('Diese Bestellungen haben basierend auf ihrem Status ein Dokument erwartet, aber keins wurde gefunden.', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>
            </p>
            <p class="lexware-status-mapping">
                <strong><?php _e('Aktuelles Status-Mapping:', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></strong><br>
                <?php foreach ($status_mapping as $status => $doc_type): ?>
                    <span class="lexware-mapping-item">
                        <code><?php echo esc_html($status); ?></code> →
                        <?php echo $doc_type === 'invoice' ? '📄' : '📋'; ?>
                        <?php echo esc_html($type_labels[$doc_type] ?? $doc_type); ?>
                    </span>
                <?php endforeach; ?>
            </p>
        </div>

        <?php if (empty($missing_orders)): ?>
            <div class="lexware-no-documents">
                <span class="dashicons dashicons-yes-alt" style="color: #46b450; font-size: 48px;"></span>
                <p><?php _e('Alle Bestellungen haben die erwarteten Dokumente! 🎉', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></p>
            </div>
        <?php else: ?>

            <div class="lexware-bulk-actions">
                <button type="button" class="button button-primary" id="lexware-create-all-missing">
                    🔄 <?php printf(__('Alle %d fehlenden Dokumente erstellen', WC_LEXWARE_MVP_TEXT_DOMAIN), $total_items); ?>
                </button>
            </div>

            <table class="wp-list-table widefat fixed striped lexware-documents-table">
                <thead>
                    <tr>
                        <th class="column-order"><?php _e('Order', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></th>
                        <th class="column-date"><?php _e('Datum', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></th>
                        <th class="column-status"><?php _e('Status', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></th>
                        <th class="column-customer"><?php _e('Kunde', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></th>
                        <th class="column-amount"><?php _e('Betrag', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></th>
                        <th class="column-missing"><?php _e('Fehlendes Dokument', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></th>
                        <th class="column-actions"><?php _e('Aktionen', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($missing_orders as $item):
                        try {
                            $order = $item['order'];
                            if (!$order || !is_a($order, 'WC_Order')) continue;

                            $expected_type = $item['expected_type'];
                            $refund_id = $item['refund_id'] ?? null;
                            $refund = $item['refund'] ?? null;

                            // Set icon based on document type
                            if ($expected_type === 'invoice') {
                                $type_icon = '📄';
                            } elseif ($expected_type === 'credit_note') {
                                $type_icon = '💳';
                            } else {
                                $type_icon = '📋';
                            }

                            $order_id = $order->get_id();
                            $date_created = $refund ? $refund->get_date_created() : $order->get_date_created();
                            $order_status = $order->get_status();
                            $billing_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
                            $billing_email = $order->get_billing_email();

                            // For credit notes, show refund amount (as positive value)
                            if ($expected_type === 'credit_note' && $refund) {
                                $order_total = abs($refund->get_amount());
                            } else {
                                $order_total = $order->get_total();
                            }
                    ?>
                        <tr data-order-id="<?php echo esc_attr($order_id); ?>" data-doc-type="<?php echo esc_attr($expected_type); ?>" <?php if ($refund_id): ?>data-refund-id="<?php echo esc_attr($refund_id); ?>"<?php endif; ?>>
                            <td>
                                <a href="<?php echo esc_url($order->get_edit_order_url()); ?>">
                                    #<?php echo esc_html($order->get_order_number()); ?>
                                </a>
                                <?php if ($refund_id): ?>
                                    <br><small class="refund-info">↳ Refund #<?php echo esc_html($refund_id); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo $date_created ? esc_html($date_created->date_i18n('d.m.Y H:i')) : '—'; ?>
                            </td>
                            <td>
                                <mark class="order-status status-<?php echo esc_attr($order_status); ?>">
                                    <span><?php echo esc_html(wc_get_order_status_name($order_status)); ?></span>
                                </mark>
                            </td>
                            <td>
                                <?php echo esc_html($billing_name ?: '—'); ?>
                                <br>
                                <small><?php echo esc_html($billing_email ?: '—'); ?></small>
                            </td>
                            <td>
                                <?php echo wc_price($order_total); ?>
                            </td>
                            <td>
                                <span class="lexware-missing-badge">
                                    <?php echo $type_icon; ?> <?php echo esc_html($type_labels[$expected_type] ?? $expected_type); ?>
                                </span>
                            </td>
                            <td class="column-actions-buttons">
                                <button type="button"
                                        class="button button-primary button-small lexware-create-doc-btn"
                                        data-order-id="<?php echo esc_attr($order_id); ?>"
                                        data-doc-type="<?php echo esc_attr($expected_type); ?>"
                                        <?php if ($refund_id): ?>data-refund-id="<?php echo esc_attr($refund_id); ?>"<?php endif; ?>>
                                    ➕ <?php _e('Erstellen', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>
                                </button>
                                <button type="button"
                                        class="button button-small lexware-import-doc-btn"
                                        data-order-id="<?php echo esc_attr($order_id); ?>"
                                        data-order-nr="<?php echo esc_attr($order->get_order_number()); ?>"
                                        data-doc-type="<?php echo esc_attr($expected_type); ?>"
                                        data-doc-type-label="<?php echo esc_attr($type_labels[$expected_type] ?? $expected_type); ?>"
                                        <?php if ($refund_id): ?>data-refund-id="<?php echo esc_attr($refund_id); ?>"<?php endif; ?>>
                                    📥 <?php _e('Importieren', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>
                                </button>
                                <button type="button"
                                        class="button button-small lexware-external-doc-btn"
                                        data-order-id="<?php echo esc_attr($order_id); ?>"
                                        data-order-nr="<?php echo esc_attr($order->get_order_number()); ?>"
                                        data-doc-type="<?php echo esc_attr($expected_type); ?>"
                                        data-doc-type-label="<?php echo esc_attr($type_labels[$expected_type] ?? $expected_type); ?>"
                                        <?php if ($refund_id): ?>data-refund-id="<?php echo esc_attr($refund_id); ?>"<?php endif; ?>>
                                    📝 <?php _e('Nur markieren', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>
                                </button>
                            </td>
                        </tr>
                    <?php
                        } catch (Exception $e) {
                            // Skip orders that cause errors
                            continue;
                        }
                    endforeach; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <span class="displaying-num">
                            <?php echo sprintf(__('%d Bestellungen', WC_LEXWARE_MVP_TEXT_DOMAIN), $total_items); ?>
                        </span>
                        <?php
                        $page_links = paginate_links(array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total' => $total_pages,
                            'current' => $current_page
                        ));
                        echo $page_links;
                        ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- External Document Modal -->
            <div id="lexware-external-modal" class="lexware-modal" style="display:none;">
                <div class="lexware-modal-content">
                    <div class="lexware-modal-header">
                        <h2><?php _e('Externes Dokument vermerken', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></h2>
                        <button type="button" class="lexware-modal-close">&times;</button>
                    </div>
                    <div class="lexware-modal-body">
                        <p class="lexware-modal-subtitle">
                            <?php _e('Bestellung', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>: <strong id="external-modal-order-nr"></strong>
                            &mdash;
                            <span id="external-modal-doc-type-icon"></span> <span id="external-modal-doc-type-label"></span>
                        </p>

                        <div class="lexware-form-group">
                            <label>
                                <input type="checkbox" id="external-just-mark" checked>
                                <?php _e('Nur als "existiert" markieren (ohne Dokumentnummer)', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>
                            </label>
                        </div>

                        <div id="external-doc-fields" class="lexware-form-fields" style="display:none;">
                            <div class="lexware-form-group">
                                <label for="external-doc-nr"><?php _e('Lexware Dokument-Nr.', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></label>
                                <input type="text" id="external-doc-nr" placeholder="<?php esc_attr_e('z.B. RE2024-00123', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>">
                            </div>

                            <div class="lexware-form-group">
                                <label for="external-doc-note"><?php _e('Notiz (optional)', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></label>
                                <textarea id="external-doc-note" rows="2" placeholder="<?php esc_attr_e('z.B. Manuell in Lexware erstellt am...', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>"></textarea>
                            </div>
                        </div>

                        <input type="hidden" id="external-order-id" value="">
                        <input type="hidden" id="external-doc-type" value="">
                    </div>
                    <div class="lexware-modal-footer">
                        <button type="button" class="button lexware-modal-cancel">
                            <?php _e('Abbrechen', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>
                        </button>
                        <button type="button" class="button button-primary" id="lexware-save-external">
                            ✓ <?php _e('Speichern', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Import Document Modal -->
            <div id="lexware-import-modal" class="lexware-modal" style="display:none;">
                <div class="lexware-modal-content">
                    <div class="lexware-modal-header">
                        <h2><?php _e('Dokument aus Lexware importieren', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></h2>
                        <button type="button" class="lexware-modal-close">&times;</button>
                    </div>
                    <div class="lexware-modal-body">
                        <p class="lexware-modal-subtitle">
                            <?php _e('Bestellung', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>: <strong id="import-modal-order-nr"></strong>
                            &mdash;
                            <span id="import-modal-doc-type-icon"></span> <span id="import-modal-doc-type-label"></span>
                        </p>

                        <div class="lexware-notice lexware-notice-info" style="margin-bottom: 15px;">
                            <p>
                                📥 <?php _e('Das Dokument wird in Lexware gesucht und inkl. PDF vollständig importiert.', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>
                            </p>
                        </div>

                        <div class="lexware-form-group">
                            <label for="import-doc-nr"><?php _e('Lexware Dokument-Nr.', WC_LEXWARE_MVP_TEXT_DOMAIN); ?> <span class="required">*</span></label>
                            <input type="text" id="import-doc-nr" placeholder="<?php esc_attr_e('z.B. RE2025-00123, AB2025-00045 oder GS0001', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>" required>
                            <p class="description"><?php _e('Exakte Dokumentnummer wie in Lexware angezeigt.', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></p>
                        </div>

                        <input type="hidden" id="import-order-id" value="">
                        <input type="hidden" id="import-doc-type" value="">

                        <div id="import-result-message" style="display:none;"></div>
                    </div>
                    <div class="lexware-modal-footer">
                        <button type="button" class="button lexware-modal-cancel">
                            <?php _e('Abbrechen', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>
                        </button>
                        <button type="button" class="button button-primary" id="lexware-start-import">
                            🔍 <?php _e('Suchen & Importieren', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>
                        </button>
                    </div>
                </div>
            </div>

            <script>
            jQuery(document).ready(function($) {
                // Single document creation
                $('.lexware-create-doc-btn').on('click', function() {
                    var $btn = $(this);
                    var $row = $btn.closest('tr');
                    var orderId = $btn.data('order-id');
                    var docType = $btn.data('doc-type');
                    var refundId = $btn.data('refund-id') || 0;

                    $btn.prop('disabled', true).text('⏳ ...');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'lexware_create_missing_document',
                            order_id: orderId,
                            doc_type: docType,
                            refund_id: refundId,
                            nonce: '<?php echo wp_create_nonce('lexware_create_missing_doc'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                $row.fadeOut(400, function() {
                                    $(this).remove();
                                    updateBadgeCount();
                                });
                            } else {
                                alert('Fehler: ' + (response.data || 'Unbekannter Fehler'));
                                $btn.prop('disabled', false).html('➕ <?php _e('Erstellen', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>');
                            }
                        },
                        error: function() {
                            alert('AJAX Fehler');
                            $btn.prop('disabled', false).html('➕ <?php _e('Erstellen', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>');
                        }
                    });
                });

                // External document button - open modal
                $('.lexware-external-doc-btn').on('click', function() {
                    var $btn = $(this);
                    var orderId = $btn.data('order-id');
                    var orderNr = $btn.data('order-nr');
                    var docType = $btn.data('doc-type');
                    var docTypeLabel = $btn.data('doc-type-label');

                    // Set modal data
                    $('#external-order-id').val(orderId);
                    $('#external-doc-type').val(docType);
                    $('#external-modal-order-nr').text('#' + orderNr);
                    $('#external-modal-doc-type-label').text(docTypeLabel);
                    $('#external-modal-doc-type-icon').text(docType === 'invoice' ? '📄' : '📋');

                    // Reset form
                    $('#external-just-mark').prop('checked', true);
                    $('#external-doc-fields').hide();
                    $('#external-doc-nr').val('');
                    $('#external-doc-note').val('');

                    // Show modal
                    $('#lexware-external-modal').fadeIn(200);
                });

                // Toggle document number fields
                $('#external-just-mark').on('change', function() {
                    if ($(this).is(':checked')) {
                        $('#external-doc-fields').slideUp(200);
                    } else {
                        $('#external-doc-fields').slideDown(200);
                    }
                });

                // Import document button - open modal
                $('.lexware-import-doc-btn').on('click', function() {
                    var $btn = $(this);
                    var orderId = $btn.data('order-id');
                    var orderNr = $btn.data('order-nr');
                    var docType = $btn.data('doc-type');
                    var docTypeLabel = $btn.data('doc-type-label');

                    // Set modal data
                    $('#import-order-id').val(orderId);
                    $('#import-doc-type').val(docType);
                    $('#import-modal-order-nr').text('#' + orderNr);
                    $('#import-modal-doc-type-label').text(docTypeLabel);
                    $('#import-modal-doc-type-icon').text(docType === 'invoice' ? '📄' : '📋');

                    // Reset form
                    $('#import-doc-nr').val('');
                    $('#import-result-message').hide().empty();

                    // Show modal
                    $('#lexware-import-modal').fadeIn(200);
                });

                // Close modal
                $('.lexware-modal-close, .lexware-modal-cancel').on('click', function() {
                    $('#lexware-external-modal').fadeOut(200);
                    $('#lexware-import-modal').fadeOut(200);
                });

                // Close modal on overlay click
                $('.lexware-modal').on('click', function(e) {
                    if (e.target === this) {
                        $(this).fadeOut(200);
                    }
                });

                // Start Import
                $('#lexware-start-import').on('click', function() {
                    var $btn = $(this);
                    var $msg = $('#import-result-message');
                    var orderId = $('#import-order-id').val();
                    var docType = $('#import-doc-type').val();
                    var docNr = $('#import-doc-nr').val().trim();

                    if (!docNr) {
                        $msg.removeClass('lexware-notice-success lexware-notice-error')
                            .addClass('lexware-notice lexware-notice-warning')
                            .html('<p>⚠️ <?php _e('Bitte Dokumentnummer eingeben.', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></p>')
                            .show();
                        return;
                    }

                    $btn.prop('disabled', true).text('⏳ <?php _e('Suche läuft...', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>');
                    $msg.removeClass('lexware-notice-success lexware-notice-error lexware-notice-warning')
                        .addClass('lexware-notice lexware-notice-info')
                        .html('<p>🔍 <?php _e('Suche Dokument in Lexware... Dies kann bis zu 30 Sekunden dauern.', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></p>')
                        .show();

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        timeout: 60000, // 60 second timeout for search
                        data: {
                            action: 'lexware_import_external_document',
                            order_id: orderId,
                            doc_type: docType,
                            doc_nr: docNr,
                            nonce: '<?php echo wp_create_nonce('lexware_import_external'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                // Show success message
                                $msg.removeClass('lexware-notice-info lexware-notice-warning lexware-notice-error')
                                    .addClass('lexware-notice lexware-notice-success')
                                    .html('<p>✅ <?php _e('Dokument erfolgreich importiert!', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></p>' +
                                        '<p><?php _e('PDF wurde heruntergeladen und gespeichert.', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></p>' +
                                        '<p><strong><?php _e('Das Dokument ist jetzt vollständig integriert:', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></strong></p>' +
                                        '<ul style="margin-left: 20px;">' +
                                        '<li>✓ <?php _e('Admin kann PDF herunterladen', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></li>' +
                                        '<li>✓ <?php _e('Kunde sieht es im Konto', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></li>' +
                                        '<li>✓ <?php _e('E-Mail-Versand funktioniert', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></li>' +
                                        '</ul>')
                                    .show();

                                $btn.prop('disabled', false).html('✅ <?php _e('Importiert!', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>');

                                // Reload after 2 seconds
                                setTimeout(function() {
                                    location.reload();
                                }, 2000);
                            } else {
                                // Check if document not found
                                var errorData = response.data;
                                if (errorData && errorData.not_found) {
                                    $msg.removeClass('lexware-notice-info lexware-notice-success')
                                        .addClass('lexware-notice lexware-notice-warning')
                                        .html('<p>⚠️ <?php _e('Dokument nicht in Lexware gefunden:', WC_LEXWARE_MVP_TEXT_DOMAIN); ?> <strong>' + docNr + '</strong></p>' +
                                            '<p><?php _e('Mögliche Gründe:', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></p>' +
                                            '<ul style="margin-left: 20px;">' +
                                            '<li><?php _e('Tippfehler in der Dokumentnummer', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></li>' +
                                            '<li><?php _e('Dokument wurde in Lexware gelöscht', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></li>' +
                                            '<li><?php _e('Dokument ist älter als 2 Jahre', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></li>' +
                                            '</ul>' +
                                            '<p><?php _e('Verwenden Sie "Nur markieren" um das Dokument ohne PDF zu vermerken.', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></p>')
                                        .show();
                                } else {
                                    var errMsg = (typeof errorData === 'object' && errorData.message) ? errorData.message : (errorData || 'Unbekannter Fehler');
                                    $msg.removeClass('lexware-notice-info lexware-notice-success lexware-notice-warning')
                                        .addClass('lexware-notice lexware-notice-error')
                                        .html('<p>❌ <?php _e('Fehler:', WC_LEXWARE_MVP_TEXT_DOMAIN); ?> ' + errMsg + '</p>')
                                        .show();
                                }
                                $btn.prop('disabled', false).html('🔍 <?php _e('Suchen & Importieren', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>');
                            }
                        },
                        error: function(xhr, status, error) {
                            var errMsg = '<?php _e('AJAX Fehler', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>';
                            if (status === 'timeout') {
                                errMsg = '<?php _e('Zeitüberschreitung - Lexware API antwortet nicht', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>';
                            }
                            $msg.removeClass('lexware-notice-info lexware-notice-success lexware-notice-warning')
                                .addClass('lexware-notice lexware-notice-error')
                                .html('<p>❌ ' + errMsg + '</p>')
                                .show();
                            $btn.prop('disabled', false).html('🔍 <?php _e('Suchen & Importieren', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>');
                        }
                    });
                });

                // Save external document
                $('#lexware-save-external').on('click', function() {
                    var $btn = $(this);
                    var orderId = $('#external-order-id').val();
                    var docType = $('#external-doc-type').val();
                    var justMark = $('#external-just-mark').is(':checked');
                    var docNr = justMark ? '' : $('#external-doc-nr').val();
                    var note = justMark ? '' : $('#external-doc-note').val();

                    $btn.prop('disabled', true).text('⏳ ...');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'lexware_mark_external_document',
                            order_id: orderId,
                            doc_type: docType,
                            doc_nr: docNr,
                            note: note,
                            nonce: '<?php echo wp_create_nonce('lexware_mark_external'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                // Close modal
                                $('#lexware-external-modal').fadeOut(200);

                                // Remove row from table
                                var $row = $('tr[data-order-id="' + orderId + '"]');
                                $row.fadeOut(400, function() {
                                    $(this).remove();
                                    updateBadgeCount();
                                });

                                $btn.prop('disabled', false).html('✓ <?php _e('Speichern', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>');
                            } else {
                                alert('Fehler: ' + (response.data || 'Unbekannter Fehler'));
                                $btn.prop('disabled', false).html('✓ <?php _e('Speichern', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>');
                            }
                        },
                        error: function() {
                            alert('AJAX Fehler');
                            $btn.prop('disabled', false).html('✓ <?php _e('Speichern', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>');
                        }
                    });
                });

                // Helper function to update badge count
                function updateBadgeCount() {
                    var $badge = $('.lexware-tab-badge');
                    var count = parseInt($badge.text()) - 1;
                    if (count > 0) {
                        $badge.text(count);
                    } else {
                        $badge.remove();
                    }

                    // Check if table is empty
                    if ($('table.lexware-documents-table tbody tr').length === 0) {
                        location.reload();
                    }
                }

                // Bulk creation
                $('#lexware-create-all-missing').on('click', function() {
                    if (!confirm('<?php _e('Möchten Sie wirklich alle fehlenden Dokumente erstellen?', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>')) {
                        return;
                    }

                    var $btn = $(this);
                    $btn.prop('disabled', true).text('⏳ Wird verarbeitet...');

                    var $rows = $('table.lexware-documents-table tbody tr');
                    var total = $rows.length;
                    var processed = 0;

                    $rows.each(function(index) {
                        var $row = $(this);
                        var $createBtn = $row.find('.lexware-create-doc-btn');

                        setTimeout(function() {
                            $createBtn.click();
                            processed++;

                            if (processed >= total) {
                                setTimeout(function() {
                                    location.reload();
                                }, 1000);
                            }
                        }, index * 500); // 500ms delay between each
                    });
                });
            });
            </script>

        <?php endif;
    }

    /**
     * AJAX handler to create missing document
     */
    public static function ajax_create_missing_document() {
        check_ajax_referer('lexware_create_missing_doc', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Keine Berechtigung');
        }

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $doc_type = isset($_POST['doc_type']) ? sanitize_text_field($_POST['doc_type']) : '';
        $refund_id = isset($_POST['refund_id']) ? intval($_POST['refund_id']) : 0;

        if (!$order_id || !$doc_type) {
            wp_send_json_error('Ungültige Parameter');
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error('Bestellung nicht gefunden');
        }

        try {
            if ($doc_type === 'invoice') {
                // Schedule invoice creation
                if (function_exists('as_enqueue_async_action')) {
                    $action_id = as_enqueue_async_action('wc_lexware_mvp_create_invoice', array('order_id' => $order_id));
                } else {
                    // Fallback: direct creation
                    do_action('wc_lexware_mvp_create_invoice', $order_id);
                    $action_id = 'direct';
                }

                \WC_Lexware_MVP_Logger::info('Missing Invoice Creation Scheduled', array(
                    'order_id' => $order_id,
                    'action_id' => $action_id,
                    'source' => 'accounting_page'
                ));

            } elseif ($doc_type === 'order_confirmation') {
                // Schedule order confirmation creation
                if (function_exists('as_enqueue_async_action')) {
                    $action_id = as_enqueue_async_action('wc_lexware_mvp_create_order_confirmation', array('order_id' => $order_id));
                } else {
                    do_action('wc_lexware_mvp_create_order_confirmation', $order_id);
                    $action_id = 'direct';
                }

                \WC_Lexware_MVP_Logger::info('Missing Order Confirmation Creation Scheduled', array(
                    'order_id' => $order_id,
                    'action_id' => $action_id,
                    'source' => 'accounting_page'
                ));

            } elseif ($doc_type === 'credit_note') {
                // Credit note requires refund_id
                if (!$refund_id) {
                    wp_send_json_error('Refund ID fehlt für Gutschrift');
                }

                $refund = wc_get_order($refund_id);
                if (!$refund) {
                    wp_send_json_error('Refund nicht gefunden');
                }

                // Check if invoice exists (required for credit note)
                global $wpdb;
                $table = wc_lexware_mvp_get_table_name(false);

                $invoice_record = $wpdb->get_row($wpdb->prepare(
                    "SELECT document_id, document_nr FROM `{$table}`
                     WHERE order_id = %d AND document_type = 'invoice' AND document_status = 'synced'
                     LIMIT 1",
                    $order_id
                ));

                if (!$invoice_record || empty($invoice_record->document_id)) {
                    wp_send_json_error('Keine synchronisierte Rechnung gefunden. Gutschrift benötigt eine Rechnung.');
                }

                // Check if credit note already exists for this refund
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM `{$table}`
                     WHERE order_id = %d AND refund_id = %d AND document_type = 'credit_note'",
                    $order_id,
                    $refund_id
                ));

                if ($existing) {
                    // Reset existing failed record to pending for retry
                    $wpdb->update($table, array(
                        'document_status' => 'pending',
                        'preceding_document_id' => $invoice_record->document_id,
                        'preceding_document_nr' => $invoice_record->document_nr,
                    ), array('id' => $existing));

                    \WC_Lexware_MVP_Logger::info('Reset existing credit note record to pending', array(
                        'order_id' => $order_id,
                        'refund_id' => $refund_id,
                        'record_id' => $existing,
                        'source' => 'accounting_page'
                    ));
                } else {
                    // Create pending DB record BEFORE scheduling (required by create_credit_note)
                    $is_full_refund = abs(floatval($refund->get_amount()) - floatval($order->get_total())) < 0.01;

                    $insert_result = $wpdb->insert($table, array(
                        'document_type' => 'credit_note',
                        'order_id' => $order_id,
                        'user_id' => $order->get_customer_id(),
                        'refund_id' => $refund_id,
                        'refund_amount' => $refund->get_amount(),
                        'refund_full' => $is_full_refund ? 1 : 0,
                        'refund_reason' => $refund->get_reason(),
                        'preceding_document_id' => $invoice_record->document_id,
                        'preceding_document_nr' => $invoice_record->document_nr,
                        'document_status' => 'pending',
                        'created_at' => current_time('mysql')
                    ));

                    if ($insert_result === false) {
                        wp_send_json_error('Datenbankfehler: ' . $wpdb->last_error);
                    }

                    \WC_Lexware_MVP_Logger::info('Created pending credit note record', array(
                        'order_id' => $order_id,
                        'refund_id' => $refund_id,
                        'insert_id' => $wpdb->insert_id,
                        'source' => 'accounting_page'
                    ));
                }

                // Schedule credit note creation (indexed array for correct arg passing)
                if (function_exists('as_enqueue_async_action')) {
                    $action_id = as_enqueue_async_action('wc_lexware_mvp_process_credit_note', array($order_id, $refund_id), 'lexware-mvp');
                } else {
                    do_action('wc_lexware_mvp_process_credit_note', $order_id, $refund_id);
                    $action_id = 'direct';
                }

                \WC_Lexware_MVP_Logger::info('Missing Credit Note Creation Scheduled', array(
                    'order_id' => $order_id,
                    'refund_id' => $refund_id,
                    'action_id' => $action_id,
                    'source' => 'accounting_page'
                ));

            } else {
                wp_send_json_error('Unbekannter Dokumenttyp: ' . $doc_type);
            }

            wp_send_json_success(array(
                'message' => 'Dokument wird erstellt',
                'action_id' => $action_id
            ));

        } catch (Exception $e) {
            \WC_Lexware_MVP_Logger::error('Missing Document Creation Failed', array(
                'order_id' => $order_id,
                'doc_type' => $doc_type,
                'error' => $e->getMessage()
            ));
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * AJAX handler to mark document as external/manual
     */
    public static function ajax_mark_external_document() {
        check_ajax_referer('lexware_mark_external', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Keine Berechtigung');
        }

        global $wpdb;
        $table = wc_lexware_mvp_get_table_name(false);

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $doc_type = isset($_POST['doc_type']) ? sanitize_text_field($_POST['doc_type']) : '';
        $doc_nr = isset($_POST['doc_nr']) ? sanitize_text_field($_POST['doc_nr']) : '';
        $note = isset($_POST['note']) ? sanitize_textarea_field($_POST['note']) : '';

        // Validate
        if (!$order_id || !in_array($doc_type, array('invoice', 'order_confirmation', 'credit_note'))) {
            wp_send_json_error('Ungültige Parameter');
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error('Bestellung nicht gefunden');
        }

        // Check if document already exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE order_id = %d AND document_type = %s",
            $order_id,
            $doc_type
        ));

        if ($exists) {
            wp_send_json_error('Dokument existiert bereits für diese Bestellung');
        }

        // Insert external document record
        $result = $wpdb->insert($table, array(
            'order_id' => $order_id,
            'user_id' => $order->get_customer_id(),
            'document_type' => $doc_type,
            'document_nr' => !empty($doc_nr) ? $doc_nr : null,
            'document_status' => 'external',
            'document_meta' => json_encode(array(
                'source' => 'manual_entry',
                'marked_by' => get_current_user_id(),
                'marked_at' => current_time('mysql'),
                'note' => $note
            )),
            'created_at' => current_time('mysql')
        ));

        if ($result === false) {
            wp_send_json_error('Datenbankfehler: ' . $wpdb->last_error);
        }

        \WC_Lexware_MVP_Logger::info('External Document Marked', array(
            'order_id' => $order_id,
            'doc_type' => $doc_type,
            'doc_nr' => $doc_nr,
            'marked_by' => get_current_user_id()
        ));

        wp_send_json_success(array(
            'message' => 'Externes Dokument vermerkt',
            'doc_id' => $wpdb->insert_id
        ));
    }

    /**
     * AJAX handler to delete external document marking
     */
    public static function ajax_delete_external_document() {
        check_ajax_referer('lexware_delete_external', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Keine Berechtigung');
        }

        global $wpdb;
        $table = wc_lexware_mvp_get_table_name(false);

        $doc_id = isset($_POST['doc_id']) ? intval($_POST['doc_id']) : 0;

        if (!$doc_id) {
            wp_send_json_error('Ungültige Dokument-ID');
        }

        // Only allow deletion of external documents
        $doc = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND document_status = 'external'",
            $doc_id
        ));

        if (!$doc) {
            wp_send_json_error('Dokument nicht gefunden oder nicht extern');
        }

        $result = $wpdb->delete($table, array('id' => $doc_id));

        if ($result === false) {
            wp_send_json_error('Löschfehler: ' . $wpdb->last_error);
        }

        \WC_Lexware_MVP_Logger::info('External Document Deleted', array(
            'doc_id' => $doc_id,
            'order_id' => $doc->order_id,
            'doc_type' => $doc->document_type,
            'deleted_by' => get_current_user_id()
        ));

        wp_send_json_success('Externe Markierung entfernt');
    }

    /**
     * AJAX handler to import external document from Lexware
     *
     * Searches for document by number in Lexware, downloads PDF,
     * and creates a full database entry with synced status.
     *
     * @since 1.3.0
     */
    public static function ajax_import_external_document() {
        check_ajax_referer('lexware_import_external', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Keine Berechtigung');
        }

        global $wpdb;
        $table = wc_lexware_mvp_get_table_name(false);

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $doc_type = isset($_POST['doc_type']) ? sanitize_text_field($_POST['doc_type']) : '';
        $doc_nr = isset($_POST['doc_nr']) ? sanitize_text_field($_POST['doc_nr']) : '';

        // Validate parameters
        if (!$order_id || !in_array($doc_type, array('invoice', 'order_confirmation', 'credit_note'))) {
            wp_send_json_error('Ungültige Parameter');
        }

        if (empty($doc_nr)) {
            wp_send_json_error('Bitte Dokumentnummer eingeben');
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error('Bestellung nicht gefunden');
        }

        // Check if document already exists
        // For credit_note: check by refund_id or document_nr (multiple credit notes per order possible)
        // For invoice/order_confirmation: check by order_id + type
        $existing_record = null;
        if ($doc_type === 'credit_note') {
            // First try to find by document_nr
            $existing_record = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE order_id = %d AND document_type = %s AND document_nr = %s",
                $order_id,
                $doc_type,
                $doc_nr
            ));
            // If not found, check for failed credit notes without document_nr (can be updated)
            if (!$existing_record) {
                $existing_record = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$table} WHERE order_id = %d AND document_type = %s AND document_status LIKE 'failed%%' AND (document_nr IS NULL OR document_nr = '')",
                    $order_id,
                    $doc_type
                ));
            }
        } else {
            $existing_record = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE order_id = %d AND document_type = %s",
                $order_id,
                $doc_type
            ));
        }

        // If exists and already synced, reject
        if ($existing_record && $existing_record->document_status === 'synced') {
            wp_send_json_error('Dokument existiert bereits für diese Bestellung');
        }

        // Flag for update vs insert
        $update_existing = ($existing_record && strpos($existing_record->document_status, 'failed') !== false);

        // Initialize API client
        $client = new \WC_Lexware_MVP\API\Client();

        // Search for document in Lexware
        \WC_Lexware_MVP_Logger::info('Starting external document import', array(
            'order_id' => $order_id,
            'doc_type' => $doc_type,
            'doc_nr' => $doc_nr
        ));

        if ($doc_type === 'invoice') {
            $lexware_doc = $client->find_invoice_by_number($doc_nr);
        } elseif ($doc_type === 'credit_note') {
            $lexware_doc = $client->find_credit_note_by_number($doc_nr);
        } else {
            $lexware_doc = $client->find_order_confirmation_by_number($doc_nr);
        }

        if (!$lexware_doc) {
            wp_send_json_error(array(
                'message' => 'Dokument nicht in Lexware gefunden: ' . $doc_nr,
                'not_found' => true
            ));
        }

        // For credit_note: fetch full details (voucherlist doesn't include totalPrice)
        if ($doc_type === 'credit_note') {
            $full_details = $client->get_credit_note($lexware_doc['id']);
            if (!is_wp_error($full_details)) {
                $lexware_doc = array_merge($lexware_doc, $full_details);
            }
        }

        // Download PDF
        if ($doc_type === 'invoice') {
            $pdf_data = $client->download_invoice_pdf($lexware_doc['id']);
        } elseif ($doc_type === 'credit_note') {
            $pdf_data = $client->download_credit_note_pdf($lexware_doc['id']);
        } else {
            $pdf_data = $client->download_order_confirmation_pdf($lexware_doc['id']);
        }

        if (is_wp_error($pdf_data)) {
            wp_send_json_error('PDF konnte nicht heruntergeladen werden: ' . $pdf_data->get_error_message());
        }

        if (empty($pdf_data)) {
            wp_send_json_error('PDF-Download fehlgeschlagen - leere Daten');
        }

        // Save PDF locally
        $pdf_handler = \WC_Lexware_MVP_PDF_Handler::get_instance();
        $filename = $pdf_handler->save_pdf(
            $pdf_data,
            $order_id,
            $doc_type,
            $doc_nr
        );

        if (!$filename) {
            wp_send_json_error('PDF konnte nicht gespeichert werden');
        }

        // Build database entry data
        $db_data = array(
            'order_id' => $order_id,
            'user_id' => $order->get_customer_id(),
            'document_type' => $doc_type,
            'document_id' => $lexware_doc['id'],
            'document_nr' => $doc_nr,
            'document_status' => 'synced',
            'document_filename' => $filename,
            'document_finalized' => 1,
            'synced_at' => current_time('mysql'),
            'created_at' => current_time('mysql'),
            'document_meta' => json_encode(array(
                'source' => 'manual_import',
                'imported_by' => get_current_user_id(),
                'imported_at' => current_time('mysql'),
                'lexware_voucher_status' => $lexware_doc['voucherStatus'] ?? null,
                'lexware_created_at' => $lexware_doc['createdDate'] ?? null
            ))
        );

        // Add credit note specific fields
        if ($doc_type === 'credit_note') {
            // Get preceding invoice info from Lexware document
            $preceding_invoice_id = $lexware_doc['precedingSalesVoucherId'] ?? null;
            $db_data['preceding_document_id'] = $preceding_invoice_id;

            // Try to find preceding invoice number in our DB
            if ($preceding_invoice_id) {
                $preceding_nr = $wpdb->get_var($wpdb->prepare(
                    "SELECT document_nr FROM {$table} WHERE document_id = %s AND document_type = 'invoice' LIMIT 1",
                    $preceding_invoice_id
                ));
                $db_data['preceding_document_nr'] = $preceding_nr;
            }

            // Get refund amount from Lexware
            $db_data['refund_amount'] = abs($lexware_doc['totalPrice']['totalGrossAmount'] ?? 0);
        }

        // Create or update database entry
        if ($update_existing) {
            // Update existing failed record
            unset($db_data['created_at']); // Don't overwrite created_at
            $db_data['updated_at'] = current_time('mysql');
            $result = $wpdb->update($table, $db_data, array('id' => $existing_record->id));
            $inserted_id = $existing_record->id;
        } else {
            // Create new record
            $result = $wpdb->insert($table, $db_data);
            $inserted_id = $wpdb->insert_id;
        }

        if ($result === false) {
            // Delete the saved PDF since DB operation failed
            $pdf_handler->delete_pdf($filename);
            wp_send_json_error('Datenbankfehler: ' . $wpdb->last_error);
        }

        // Add order note
        $doc_labels = array(
            'invoice' => 'Rechnung',
            'order_confirmation' => 'Auftragsbestätigung',
            'credit_note' => 'Gutschrift'
        );
        $doc_label = $doc_labels[$doc_type] ?? 'Dokument';

        $order->add_order_note(sprintf(
            __('📥 Externes %s importiert: %s (von %s)', WC_LEXWARE_MVP_TEXT_DOMAIN),
            $doc_label,
            $doc_nr,
            wp_get_current_user()->user_login
        ));

        \WC_Lexware_MVP_Logger::info('External Document Imported Successfully', array(
            'order_id' => $order_id,
            'doc_type' => $doc_type,
            'doc_nr' => $doc_nr,
            'lexware_id' => $lexware_doc['id'],
            'filename' => $filename,
            'imported_by' => get_current_user_id()
        ));

        wp_send_json_success(array(
            'message' => $update_existing ? 'Fehlgeschlagenes Dokument erfolgreich importiert!' : 'Dokument erfolgreich importiert!',
            'doc_id' => $inserted_id,
            'document_nr' => $doc_nr,
            'filename' => $filename
        ));
    }

    /**
     * Get count of failed documents
     *
     * @return int
     */
    private static function get_failed_documents_count() {
        global $wpdb;
        $table = wc_lexware_mvp_get_table_name();

        return (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$table}
             WHERE document_status IN ('failed', 'failed_validation', 'failed_auth')"
        );
    }

    /**
     * Render the failed documents tab
     */
    private static function render_failed_documents_tab() {
        global $wpdb;
        $table = wc_lexware_mvp_get_table_name();

        // Handle success messages
        if (isset($_GET['retried'])) {
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo sprintf(
                __('%d Dokument(e) wurden zum erneuten Versuch eingeplant.', WC_LEXWARE_MVP_TEXT_DOMAIN),
                intval($_GET['retried'])
            );
            echo '</p></div>';
        }

        if (isset($_GET['deleted'])) {
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo sprintf(
                __('%d Dokument(e) wurden gelöscht.', WC_LEXWARE_MVP_TEXT_DOMAIN),
                intval($_GET['deleted'])
            );
            echo '</p></div>';
        }

        // Get failed documents with pagination
        $per_page = 50;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;

        $total_failed = self::get_failed_documents_count();
        $total_pages = ceil($total_failed / $per_page);

        $failed_docs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE document_status IN ('failed', 'failed_validation', 'failed_auth')
             ORDER BY updated_at DESC
             LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ));

        $type_labels = self::get_document_type_labels();

        ?>

        <div class="lexware-missing-docs-info">
            <p>
                <?php echo sprintf(
                    __('%d fehlgeschlagene Dokument(e) benötigen Aufmerksamkeit. Sie können diese einzeln oder in Bulk erneut versuchen.', WC_LEXWARE_MVP_TEXT_DOMAIN),
                    $total_failed
                ); ?>
            </p>
        </div>

        <?php if (empty($failed_docs)): ?>
            <div class="lexware-no-documents">
                <span class="dashicons dashicons-yes-alt" style="color: #46b450; font-size: 48px;"></span>
                <p><?php _e('Keine fehlgeschlagenen Dokumente! Alle Dokumente wurden erfolgreich synchronisiert.', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></p>
            </div>
        <?php else: ?>

            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" id="failed-docs-form">
                <input type="hidden" name="action" value="lexware_bulk_retry_accounting">
                <input type="hidden" name="redirect_tab" value="failed">
                <?php wp_nonce_field('lexware_bulk_action', 'lexware_nonce'); ?>

                <div class="lexware-bulk-actions">
                    <select name="bulk_action" id="bulk-action-selector">
                        <option value=""><?php _e('Bulk-Aktionen', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></option>
                        <option value="retry"><?php _e('Ausgewählte erneut versuchen', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></option>
                        <option value="delete"><?php _e('Ausgewählte löschen', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></option>
                    </select>
                    <button type="submit" class="button" onclick="return handleFailedBulkAction();">
                        <?php _e('Anwenden', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>
                    </button>
                </div>

                <table class="wp-list-table widefat fixed striped lexware-documents-table">
                    <thead>
                        <tr>
                            <th class="check-column">
                                <input type="checkbox" id="select-all-failed">
                            </th>
                            <th class="column-order"><?php _e('Order', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></th>
                            <th class="column-type"><?php _e('Dokumenttyp', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></th>
                            <th class="column-error"><?php _e('Fehler', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></th>
                            <th class="column-date"><?php _e('Fehlgeschlagen', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></th>
                            <th class="column-actions"><?php _e('Aktionen', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($failed_docs as $doc): ?>
                            <?php
                            $order = wc_get_order($doc->order_id);
                            $meta = json_decode($doc->document_meta, true);
                            $error_message = $meta['last_error'] ?? __('Unbekannter Fehler', WC_LEXWARE_MVP_TEXT_DOMAIN);
                            $error_short = strlen($error_message) > 80
                                ? substr($error_message, 0, 80) . '...'
                                : $error_message;

                            $type_icon = '';
                            switch ($doc->document_type) {
                                case 'invoice': $type_icon = '📄'; break;
                                case 'order_confirmation': $type_icon = '📋'; break;
                                case 'credit_note': $type_icon = '💳'; break;
                            }
                            ?>
                            <tr>
                                <th class="check-column">
                                    <input type="checkbox" name="doc_ids[]" value="<?php echo esc_attr($doc->id); ?>">
                                </th>
                                <td>
                                    <?php if ($order): ?>
                                        <a href="<?php echo $order->get_edit_order_url(); ?>">#<?php echo $doc->order_id; ?></a>
                                    <?php else: ?>
                                        <span class="lexware-muted">#<?php echo $doc->order_id; ?></span>
                                        <br><small class="lexware-deleted-hint"><?php _e('(gelöscht)', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></small>
                                    <?php endif; ?>
                                    <?php if ($doc->refund_id): ?>
                                        <br>
                                        <small><?php echo sprintf(__('Refund: %d', WC_LEXWARE_MVP_TEXT_DOMAIN), $doc->refund_id); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo $type_icon . ' ' . esc_html($type_labels[$doc->document_type] ?? $doc->document_type); ?>
                                </td>
                                <td>
                                    <?php
                                    $status_badge_label = '';
                                    switch ($doc->document_status) {
                                        case 'failed_validation':
                                            $status_badge_label = __('Validierung', WC_LEXWARE_MVP_TEXT_DOMAIN);
                                            break;
                                        case 'failed_auth':
                                            $status_badge_label = __('Auth', WC_LEXWARE_MVP_TEXT_DOMAIN);
                                            break;
                                        default:
                                            $status_badge_label = __('API', WC_LEXWARE_MVP_TEXT_DOMAIN);
                                    }
                                    $status_css = str_replace('_', '-', $doc->document_status);
                                    ?>
                                    <span class="lexware-status-badge status-<?php echo esc_attr($status_css); ?>"><?php echo esc_html($status_badge_label); ?></span>
                                    <span class="lexware-error-text" title="<?php echo esc_attr($error_message); ?>">
                                        <?php echo esc_html($error_short); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo human_time_diff(strtotime($doc->updated_at), current_time('timestamp')); ?> <?php _e('her', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>
                                    <br>
                                    <small class="lexware-muted"><?php echo date_i18n('d.m.Y H:i', strtotime($doc->updated_at)); ?></small>
                                </td>
                                <td class="column-actions-buttons">
                                    <button type="button"
                                            class="button button-primary button-small lexware-retry-doc-btn"
                                            data-doc-id="<?php echo esc_attr($doc->id); ?>">
                                        🔄 <?php _e('Retry', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>
                                    </button>
                                    <button type="button"
                                            class="button button-small lexware-delete-failed-btn"
                                            data-doc-id="<?php echo esc_attr($doc->id); ?>">
                                        🗑️
                                    </button>
                                    <?php if ($order): ?>
                                        <a href="<?php echo $order->get_edit_order_url(); ?>" class="button button-small">
                                            👁 Order
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="tablenav bottom">
                        <div class="tablenav-pages">
                            <span class="displaying-num">
                                <?php echo sprintf(__('%d Dokumente', WC_LEXWARE_MVP_TEXT_DOMAIN), $total_failed); ?>
                            </span>
                            <?php
                            $page_links = paginate_links(array(
                                'base' => add_query_arg('paged', '%#%'),
                                'format' => '',
                                'prev_text' => '&laquo;',
                                'next_text' => '&raquo;',
                                'total' => $total_pages,
                                'current' => $current_page
                            ));
                            echo $page_links;
                            ?>
                        </div>
                    </div>
                <?php endif; ?>

            </form>

            <script>
            jQuery(document).ready(function($) {
                // Select all checkboxes
                $('#select-all-failed').on('change', function() {
                    $('input[name="doc_ids[]"]').prop('checked', $(this).is(':checked'));
                });

                // Single retry
                $('.lexware-retry-doc-btn').on('click', function() {
                    var $btn = $(this);
                    var $row = $btn.closest('tr');
                    var docId = $btn.data('doc-id');

                    $btn.prop('disabled', true).text('⏳...');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'lexware_retry_failed_document',
                            doc_id: docId,
                            nonce: '<?php echo wp_create_nonce('lexware_retry_failed'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                $row.fadeOut(400, function() {
                                    $(this).remove();
                                    updateFailedBadgeCount();
                                });
                            } else {
                                alert('Fehler: ' + (response.data || 'Unbekannter Fehler'));
                                $btn.prop('disabled', false).html('🔄 <?php _e('Retry', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>');
                            }
                        },
                        error: function() {
                            alert('AJAX Fehler');
                            $btn.prop('disabled', false).html('🔄 <?php _e('Retry', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>');
                        }
                    });
                });

                // Single delete
                $('.lexware-delete-failed-btn').on('click', function() {
                    if (!confirm('<?php _e('Dokument wirklich löschen? Dies kann nicht rückgängig gemacht werden.', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>')) {
                        return;
                    }

                    var $btn = $(this);
                    var $row = $btn.closest('tr');
                    var docId = $btn.data('doc-id');

                    $btn.prop('disabled', true).text('⏳');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'lexware_delete_failed_document',
                            doc_id: docId,
                            nonce: '<?php echo wp_create_nonce('lexware_delete_failed'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                $row.fadeOut(400, function() {
                                    $(this).remove();
                                    updateFailedBadgeCount();
                                });
                            } else {
                                alert('Fehler: ' + (response.data || 'Unbekannter Fehler'));
                                $btn.prop('disabled', false).html('🗑️');
                            }
                        },
                        error: function() {
                            alert('AJAX Fehler');
                            $btn.prop('disabled', false).html('🗑️');
                        }
                    });
                });

                // Helper function to update badge count
                function updateFailedBadgeCount() {
                    var $badge = $('.lexware-tab-badge-error');
                    var count = parseInt($badge.text()) - 1;
                    if (count > 0) {
                        $badge.text(count);
                    } else {
                        // Tab should disappear - reload to update
                        location.href = '<?php echo admin_url('admin.php?page=lexware-accounting&tab=documents'); ?>';
                    }

                    // Check if table is empty
                    if ($('table.lexware-documents-table tbody tr').length === 0) {
                        location.href = '<?php echo admin_url('admin.php?page=lexware-accounting&tab=documents'); ?>';
                    }
                }
            });

            // Handle bulk action
            function handleFailedBulkAction() {
                var action = document.getElementById('bulk-action-selector').value;
                var form = document.getElementById('failed-docs-form');
                var checked = document.querySelectorAll('input[name="doc_ids[]"]:checked');

                if (checked.length === 0) {
                    alert('<?php _e('Bitte wählen Sie mindestens ein Dokument.', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>');
                    return false;
                }

                if (action === 'delete') {
                    if (!confirm('<?php _e('Ausgewählte Dokumente wirklich löschen? Dies kann nicht rückgängig gemacht werden.', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>')) {
                        return false;
                    }
                    form.querySelector('input[name="action"]').value = 'lexware_bulk_delete_accounting';
                } else if (action === 'retry') {
                    form.querySelector('input[name="action"]').value = 'lexware_bulk_retry_accounting';
                } else {
                    alert('<?php _e('Bitte wählen Sie eine Aktion.', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>');
                    return false;
                }

                return true;
            }
            </script>

        <?php endif;
    }

    /**
     * AJAX handler to retry failed document
     */
    public static function ajax_retry_failed_document() {
        check_ajax_referer('lexware_retry_failed', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Keine Berechtigung');
        }

        $doc_id = isset($_POST['doc_id']) ? intval($_POST['doc_id']) : 0;

        if (!$doc_id) {
            wp_send_json_error('Ungültige Dokument-ID');
        }

        global $wpdb;
        $table = wc_lexware_mvp_get_table_name(false);

        $failed_statuses = array('failed', 'failed_validation', 'failed_auth');
        $doc = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND document_status IN ('" . implode("','", $failed_statuses) . "')",
            $doc_id
        ));

        if (!$doc) {
            wp_send_json_error('Dokument nicht gefunden oder nicht fehlgeschlagen');
        }

        // Reset status to pending
        $wpdb->update(
            $table,
            array('document_status' => 'pending'),
            array('id' => $doc_id),
            array('%s'),
            array('%d')
        );

        // Reschedule via Action Scheduler
        if ($doc->document_type === 'invoice') {
            if (function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action(
                    'wc_lexware_mvp_process_invoice',
                    array($doc->order_id),
                    'lexware-mvp'
                );
            }
        } elseif ($doc->document_type === 'credit_note') {
            if (function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action(
                    'wc_lexware_mvp_process_credit_note',
                    array($doc->order_id, $doc->refund_id),
                    'lexware-mvp'
                );
            }
        } elseif ($doc->document_type === 'order_confirmation') {
            if (function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action(
                    'wc_lexware_mvp_create_order_confirmation',
                    array($doc->order_id),
                    'lexware-mvp'
                );
            }
        }

        \WC_Lexware_MVP_Logger::info('Failed Document Retry Scheduled', array(
            'doc_id' => $doc_id,
            'order_id' => $doc->order_id,
            'doc_type' => $doc->document_type,
            'user_id' => get_current_user_id()
        ));

        wp_send_json_success('Dokument zum erneuten Versuch eingeplant');
    }

    /**
     * AJAX handler to delete failed document
     */
    public static function ajax_delete_failed_document() {
        check_ajax_referer('lexware_delete_failed', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Keine Berechtigung');
        }

        $doc_id = isset($_POST['doc_id']) ? intval($_POST['doc_id']) : 0;

        if (!$doc_id) {
            wp_send_json_error('Ungültige Dokument-ID');
        }

        global $wpdb;
        $table = wc_lexware_mvp_get_table_name(false);

        // Only allow deletion of failed documents
        $doc = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND document_status IN ('failed', 'failed_validation', 'failed_auth')",
            $doc_id
        ));

        if (!$doc) {
            wp_send_json_error('Dokument nicht gefunden oder nicht fehlgeschlagen');
        }

        $result = $wpdb->delete($table, array('id' => $doc_id));

        if ($result === false) {
            wp_send_json_error('Löschfehler: ' . $wpdb->last_error);
        }

        \WC_Lexware_MVP_Logger::warning('Failed Document Deleted', array(
            'doc_id' => $doc_id,
            'order_id' => $doc->order_id,
            'doc_type' => $doc->document_type,
            'deleted_by' => get_current_user_id()
        ));

        wp_send_json_success('Dokument gelöscht');
    }

    /**
     * Handle bulk retry from accounting page
     */
    public static function handle_bulk_retry_accounting() {
        check_admin_referer('lexware_bulk_action', 'lexware_nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Insufficient permissions.', WC_LEXWARE_MVP_TEXT_DOMAIN));
        }

        global $wpdb;
        $table = wc_lexware_mvp_get_table_name(false);

        $doc_ids = isset($_POST['doc_ids']) ? array_map('intval', $_POST['doc_ids']) : array();
        $retried_count = 0;

        foreach ($doc_ids as $doc_id) {
            $doc = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d AND document_status IN ('failed', 'failed_validation', 'failed_auth')",
                $doc_id
            ));

            if (!$doc) continue;

            // Reset status to pending
            $wpdb->update(
                $table,
                array('document_status' => 'pending'),
                array('id' => $doc_id),
                array('%s'),
                array('%d')
            );

            // Reschedule with indexed arrays
            if ($doc->document_type === 'invoice' && function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action('wc_lexware_mvp_process_invoice', array($doc->order_id), 'lexware-mvp');
            } elseif ($doc->document_type === 'credit_note' && function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action('wc_lexware_mvp_process_credit_note', array($doc->order_id, $doc->refund_id), 'lexware-mvp');
            } elseif ($doc->document_type === 'order_confirmation' && function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action('wc_lexware_mvp_create_order_confirmation', array($doc->order_id), 'lexware-mvp');
            }

            $retried_count++;
        }

        \WC_Lexware_MVP_Logger::info('Bulk retry from accounting page', array(
            'doc_ids' => $doc_ids,
            'retried_count' => $retried_count,
            'user_id' => get_current_user_id(),
        ));

        wp_redirect(add_query_arg(array(
            'page' => 'lexware-accounting',
            'tab' => 'failed',
            'retried' => $retried_count
        ), admin_url('admin.php')));
        exit;
    }

    /**
     * Handle bulk delete from accounting page
     */
    public static function handle_bulk_delete_accounting() {
        check_admin_referer('lexware_bulk_action', 'lexware_nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Insufficient permissions.', WC_LEXWARE_MVP_TEXT_DOMAIN));
        }

        global $wpdb;
        $table = wc_lexware_mvp_get_table_name(false);

        $doc_ids = isset($_POST['doc_ids']) ? array_map('intval', $_POST['doc_ids']) : array();
        $deleted_count = 0;

        foreach ($doc_ids as $doc_id) {
            // Only delete failed documents
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE id = %d AND document_status IN ('failed', 'failed_validation', 'failed_auth')",
                $doc_id
            ));

            if (!$exists) continue;

            $result = $wpdb->delete($table, array('id' => $doc_id), array('%d'));
            if ($result !== false) {
                $deleted_count++;
            }
        }

        \WC_Lexware_MVP_Logger::warning('Bulk delete from accounting page', array(
            'doc_ids' => $doc_ids,
            'deleted_count' => $deleted_count,
            'user_id' => get_current_user_id(),
        ));

        wp_redirect(add_query_arg(array(
            'page' => 'lexware-accounting',
            'tab' => 'failed',
            'deleted' => $deleted_count
        ), admin_url('admin.php')));
        exit;
    }

    // =========================================================================
    // LEXWARE API EXPORT METHODS
    // =========================================================================

    /**
     * AJAX: Quick Export - Synchronous export of voucherlist data
     *
     * @since 1.2.6
     */
    public static function ajax_quick_export() {
        check_ajax_referer('lexware_export', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Keine Berechtigung', WC_LEXWARE_MVP_TEXT_DOMAIN)));
        }

        $client = new \WC_Lexware_MVP\API\Client();
        $vouchers = $client->get_voucherlist();

        if (is_wp_error($vouchers)) {
            \WC_Lexware_MVP_Logger::error('Quick export failed', array(
                'error' => $vouchers->get_error_message()
            ));
            wp_send_json_error(array('message' => $vouchers->get_error_message()));
        }

        if (empty($vouchers)) {
            wp_send_json_error(array('message' => __('Keine Belege in Lexware gefunden', WC_LEXWARE_MVP_TEXT_DOMAIN)));
        }

        $csv = self::generate_voucher_csv($vouchers, false);

        \WC_Lexware_MVP_Logger::info('Quick export completed', array(
            'count' => count($vouchers)
        ));

        wp_send_json_success(array(
            'csv' => $csv,
            'filename' => 'lexware-belege-schnell-' . date('Y-m-d') . '.csv',
            'count' => count($vouchers)
        ));
    }

    /**
     * AJAX: Start Detail Export - Initiates background job
     *
     * @since 1.2.6
     */
    public static function ajax_start_detail_export() {
        check_ajax_referer('lexware_export', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Keine Berechtigung', WC_LEXWARE_MVP_TEXT_DOMAIN)));
        }

        $client = new \WC_Lexware_MVP\API\Client();
        $vouchers = $client->get_voucherlist();

        if (is_wp_error($vouchers)) {
            \WC_Lexware_MVP_Logger::error('Detail export start failed', array(
                'error' => $vouchers->get_error_message()
            ));
            wp_send_json_error(array('message' => $vouchers->get_error_message()));
        }

        if (empty($vouchers)) {
            wp_send_json_error(array('message' => __('Keine Belege in Lexware gefunden', WC_LEXWARE_MVP_TEXT_DOMAIN)));
        }

        // Create export job
        $export_id = 'lexware_export_' . get_current_user_id() . '_' . time();
        $export_data = array(
            'status' => 'running',
            'total' => count($vouchers),
            'processed' => 0,
            'vouchers' => $vouchers,
            'detailed' => array(),
            'started_at' => time(),
            'error' => null,
        );

        set_transient($export_id, $export_data, HOUR_IN_SECONDS);

        // Schedule background processing
        wp_schedule_single_event(time(), 'lexware_process_detail_export', array($export_id));
        spawn_cron();

        \WC_Lexware_MVP_Logger::info('Detail export started', array(
            'export_id' => $export_id,
            'total' => count($vouchers)
        ));

        wp_send_json_success(array(
            'export_id' => $export_id,
            'total' => count($vouchers)
        ));
    }

    /**
     * AJAX: Get Export Progress
     *
     * @since 1.2.6
     */
    public static function ajax_export_progress() {
        check_ajax_referer('lexware_export', 'nonce');

        $export_id = isset($_POST['export_id']) ? sanitize_text_field($_POST['export_id']) : '';
        $export_data = get_transient($export_id);

        if (!$export_data) {
            wp_send_json_error(array('message' => __('Export nicht gefunden', WC_LEXWARE_MVP_TEXT_DOMAIN)));
        }

        wp_send_json_success(array(
            'status' => $export_data['status'],
            'total' => $export_data['total'],
            'processed' => $export_data['processed'],
            'error' => $export_data['error'],
        ));
    }

    /**
     * AJAX: Download completed export
     *
     * @since 1.2.6
     */
    public static function ajax_download_export() {
        check_ajax_referer('lexware_export', 'nonce');

        $export_id = isset($_POST['export_id']) ? sanitize_text_field($_POST['export_id']) : '';
        $export_data = get_transient($export_id);

        if (!$export_data || $export_data['status'] !== 'completed') {
            wp_send_json_error(array('message' => __('Export nicht bereit', WC_LEXWARE_MVP_TEXT_DOMAIN)));
        }

        $csv = self::generate_voucher_csv($export_data['detailed'], true);

        // Delete transient after download
        delete_transient($export_id);

        \WC_Lexware_MVP_Logger::info('Detail export downloaded', array(
            'export_id' => $export_id,
            'count' => count($export_data['detailed'])
        ));

        wp_send_json_success(array(
            'csv' => $csv,
            'filename' => 'lexware-belege-detail-' . date('Y-m-d') . '.csv',
            'count' => count($export_data['detailed'])
        ));
    }

    /**
     * Background Job: Process Detail Export
     *
     * Called via WP Cron to fetch voucher details in background.
     *
     * @since 1.2.6
     * @param string $export_id Export job ID
     */
    public static function process_detail_export($export_id) {
        $export_data = get_transient($export_id);

        if (!$export_data || $export_data['status'] !== 'running') {
            return;
        }

        \WC_Lexware_MVP_Logger::info('Processing detail export', array(
            'export_id' => $export_id,
            'total' => $export_data['total']
        ));

        $client = new \WC_Lexware_MVP\API\Client();
        $detailed = array();

        foreach ($export_data['vouchers'] as $index => $voucher) {
            $details = $client->get_voucher_details(
                $voucher['voucherType'],
                $voucher['id']
            );

            if (is_wp_error($details)) {
                \WC_Lexware_MVP_Logger::warning('Detail export: voucher fetch failed', array(
                    'voucher_id' => $voucher['id'],
                    'type' => $voucher['voucherType'],
                    'error' => $details->get_error_message()
                ));
                // Fallback to basic data
                $detailed[] = $voucher;
            } else {
                $detailed[] = $details;
            }

            // Update progress every 5 vouchers or at the end
            if ($index % 5 === 0 || $index === count($export_data['vouchers']) - 1) {
                $export_data['processed'] = $index + 1;
                $export_data['detailed'] = $detailed;
                set_transient($export_id, $export_data, HOUR_IN_SECONDS);
            }
        }

        // Mark as completed
        $export_data['status'] = 'completed';
        $export_data['processed'] = count($export_data['vouchers']);
        $export_data['detailed'] = $detailed;
        set_transient($export_id, $export_data, HOUR_IN_SECONDS);

        \WC_Lexware_MVP_Logger::info('Detail export completed', array(
            'export_id' => $export_id,
            'total' => count($detailed)
        ));
    }

    /**
     * Generate CSV from voucher data
     *
     * @since 1.2.6
     * @param array $vouchers Array of voucher data
     * @param bool $detailed Whether to include detailed fields
     * @return string CSV content with BOM for Excel
     */
    private static function generate_voucher_csv($vouchers, $detailed = false) {
        $output = fopen('php://temp', 'r+');

        // UTF-8 BOM for Excel
        fwrite($output, "\xEF\xBB\xBF");

        // Headers based on mode
        if ($detailed) {
            $headers = array(
                'ID', 'Typ', 'Status', 'Belegnummer', 'Belegdatum', 'Fällig',
                'Netto', 'Steuer', 'Brutto', 'Währung',
                'Kontakt-ID', 'Kontaktname', 'Straße', 'PLZ', 'Ort', 'Land',
                'Positionen', 'Bemerkung', 'Erstellt', 'Geändert'
            );
        } else {
            $headers = array(
                'ID', 'Typ', 'Status', 'Belegnummer', 'Belegdatum',
                'Netto', 'Steuer', 'Brutto', 'Kontaktname'
            );
        }
        fputcsv($output, $headers, ';');

        // Data rows
        foreach ($vouchers as $v) {
            if ($detailed) {
                // Count line items
                $positions = isset($v['lineItems']) ? count($v['lineItems']) . ' Pos.' : '';

                fputcsv($output, array(
                    isset($v['id']) ? $v['id'] : '',
                    isset($v['voucherType']) ? $v['voucherType'] : '',
                    isset($v['voucherStatus']) ? $v['voucherStatus'] : '',
                    isset($v['voucherNumber']) ? $v['voucherNumber'] : '',
                    isset($v['voucherDate']) ? $v['voucherDate'] : '',
                    isset($v['dueDate']) ? $v['dueDate'] : '',
                    isset($v['totalNetAmount']) ? $v['totalNetAmount'] : '',
                    isset($v['totalTaxAmount']) ? $v['totalTaxAmount'] : '',
                    isset($v['totalGrossAmount']) ? $v['totalGrossAmount'] : '',
                    isset($v['currency']) ? $v['currency'] : 'EUR',
                    isset($v['address']['contactId']) ? $v['address']['contactId'] : '',
                    isset($v['address']['name']) ? $v['address']['name'] : '',
                    isset($v['address']['street']) ? $v['address']['street'] : '',
                    isset($v['address']['zip']) ? $v['address']['zip'] : '',
                    isset($v['address']['city']) ? $v['address']['city'] : '',
                    isset($v['address']['countryCode']) ? $v['address']['countryCode'] : '',
                    $positions,
                    isset($v['remark']) ? $v['remark'] : '',
                    isset($v['createdDate']) ? $v['createdDate'] : '',
                    isset($v['updatedDate']) ? $v['updatedDate'] : ''
                ), ';');
            } else {
                fputcsv($output, array(
                    isset($v['id']) ? $v['id'] : '',
                    isset($v['voucherType']) ? $v['voucherType'] : '',
                    isset($v['voucherStatus']) ? $v['voucherStatus'] : '',
                    isset($v['voucherNumber']) ? $v['voucherNumber'] : '',
                    isset($v['voucherDate']) ? $v['voucherDate'] : '',
                    isset($v['totalNetAmount']) ? $v['totalNetAmount'] : '',
                    isset($v['totalTaxAmount']) ? $v['totalTaxAmount'] : '',
                    isset($v['totalGrossAmount']) ? $v['totalGrossAmount'] : '',
                    isset($v['contactName']) ? $v['contactName'] : ''
                ), ';');
            }
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }
}
