<?php
/**
 * Order Sync - Automatic Invoice and Credit Note Creation
 *
 * Handles synchronization between WooCommerce orders and Lexware Office.
 * Creates invoices on configured order statuses and credit notes on refunds.
 *
 * @package WC_Lexware_MVP
 * @since 1.0.0
 */

namespace WC_Lexware_MVP\API;

class Order_Sync {

    /**
     * Initialize hooks
     *
     * Registers WooCommerce hooks for invoice and credit note creation.
     * Configures Action Scheduler for async processing.
     *
     * @since 1.0.0
     */
    public static function init() {
        \WC_Lexware_MVP_Logger::debug('Order Sync initialization started', array(
            'php_version' => PHP_VERSION,
            'wc_version' => defined('WC_VERSION') ? WC_VERSION : 'undefined',
            'action_scheduler_available' => function_exists('as_enqueue_async_action')
        ));

        // Check Action Scheduler availability
        if (!self::is_action_scheduler_available()) {
            add_action('admin_notices', array(__CLASS__, 'action_scheduler_missing_notice'));
            \WC_Lexware_MVP_Logger::warning('Action Scheduler not available - using WP-Cron fallback');
        }

        // Register Invoice Hooks (Multi-Status Support)
        $invoice_statuses = get_option('wc_lexware_mvp_invoice_trigger_statuses', array('wc-completed'));

        // Ensure array
        if (!is_array($invoice_statuses)) {
            $invoice_statuses = array($invoice_statuses);
        }

        \WC_Lexware_MVP_Logger::debug('Invoice trigger statuses configured', array(
            'statuses' => $invoice_statuses,
            'count' => count($invoice_statuses)
        ));

        // Get valid WooCommerce order statuses
        $valid_statuses = array_keys(wc_get_order_statuses());

        \WC_Lexware_MVP_Logger::debug('WooCommerce order statuses loaded', array(
            'status_count' => count($valid_statuses)
        ));

        // Register hook for each configured invoice status
        foreach ($invoice_statuses as $status) {
            // Validate against WooCommerce Order-Status
            if (!in_array($status, $valid_statuses) && !in_array('wc-' . $status, $valid_statuses)) {
                \WC_Lexware_MVP_Logger::warning('Invalid invoice trigger status detected, skipping', array('status' => $status));
                continue; // Ungültiger Status - überspringen
            }

            $clean_status = str_replace('wc-', '', $status);
            $hook_name = "woocommerce_order_status_{$clean_status}";

            \WC_Lexware_MVP_Logger::debug('Invoice hook registered', array(
                'status' => $status,
                'hook_name' => $hook_name
            ));

            add_action($hook_name, array(__CLASS__, 'on_order_status_invoice'), 20, 2);
        }

        // Register Refund Hook (ALLE Refunds - partial & full)
        add_action('woocommerce_order_refunded', array(__CLASS__, 'on_order_refunded'), 20, 2);

        \WC_Lexware_MVP_Logger::debug('Refund hook registered', array(
            'hook' => 'woocommerce_order_refunded',
            'priority' => 20
        ));

        // Register Credit Note Hooks (wenn Status konfiguriert)
        $credit_note_statuses = get_option('wc_lexware_mvp_credit_note_trigger_statuses', array('wc-refunded'));

        if (!is_array($credit_note_statuses)) {
            $credit_note_statuses = array($credit_note_statuses);
        }

        \WC_Lexware_MVP_Logger::debug('Credit note trigger statuses configured', array(
            'statuses' => $credit_note_statuses,
            'count' => count($credit_note_statuses),
            'enabled' => !empty($credit_note_statuses)
        ));

        if (!empty($credit_note_statuses)) {

            // Validate credit note statuses
            foreach ($credit_note_statuses as $status) {
                // Validate against WooCommerce Order-Status
                if (!in_array($status, $valid_statuses) && !in_array('wc-' . $status, $valid_statuses)) {
                    \WC_Lexware_MVP_Logger::warning('Invalid credit note trigger status detected, skipping', array('status' => $status));
                    continue; // Ungültiger Status - überspringen
                }

                $clean_status = str_replace('wc-', '', $status);
                $hook_name = "woocommerce_order_status_{$clean_status}";

                \WC_Lexware_MVP_Logger::debug('Credit note hook registered', array(
                    'status' => $status,
                    'hook_name' => $hook_name
                ));

                add_action($hook_name, array(__CLASS__, 'on_order_status_credit_note'), 20, 2);
            }

            // Action Scheduler Hook für Credit Notes
            add_action('wc_lexware_mvp_process_credit_note', array(__CLASS__, 'process_credit_note_async'), 10, 2);

            // Action Scheduler Hook für Voll-Gutschriften ohne WC-Refund
            add_action('wc_lexware_mvp_process_full_credit_note', array(__CLASS__, 'process_full_credit_note_async'), 10, 2);
        }

        // Register Order Confirmation Hooks (wenn Status konfiguriert)
        $order_confirmation_statuses = get_option('wc_lexware_mvp_order_confirmation_trigger_statuses', array());

        if (!is_array($order_confirmation_statuses)) {
            $order_confirmation_statuses = array($order_confirmation_statuses);
        }

        \WC_Lexware_MVP_Logger::debug('Order confirmation trigger statuses configured', array(
            'statuses' => $order_confirmation_statuses,
            'count' => count($order_confirmation_statuses),
            'enabled' => !empty($order_confirmation_statuses)
        ));

        if (!empty($order_confirmation_statuses)) {
            foreach ($order_confirmation_statuses as $status) {
                if (!in_array($status, $valid_statuses) && !in_array('wc-' . $status, $valid_statuses)) {
                    \WC_Lexware_MVP_Logger::warning('Invalid order confirmation trigger status detected, skipping', array('status' => $status));
                    continue;
                }

                $clean_status = str_replace('wc-', '', $status);
                $hook_name = "woocommerce_order_status_{$clean_status}";

                \WC_Lexware_MVP_Logger::debug('Order confirmation hook registered', array(
                    'status' => $status,
                    'hook_name' => $hook_name
                ));

                add_action($hook_name, array(__CLASS__, 'on_order_status_order_confirmation'), 15, 2);
            }

            // Action Scheduler Hook für Order Confirmations
            add_action('wc_lexware_mvp_process_order_confirmation', array(__CLASS__, 'process_order_confirmation_async'), 10, 1);
        }

        // Action Scheduler Hook für Invoices
        add_action('wc_lexware_mvp_process_invoice', array(__CLASS__, 'process_invoice_async'), 10, 1);

        \WC_Lexware_MVP_Logger::info('Order Sync initialization complete', array(
            'invoice_hooks' => count($invoice_statuses),
            'credit_note_hooks' => count($credit_note_statuses),
            'order_confirmation_hooks' => count($order_confirmation_statuses)
        ));

        // Check WP-Cron status (Production Safety)
        add_action('admin_init', array(__CLASS__, 'check_cron_status'));

        // Migration Bridge: Block Germanized/StoreaBill for pre-cutoff orders
        $cutoff = get_option('wc_lexware_mvp_cutoff_date', '');
        if (!empty($cutoff)) {
            add_filter('storeabill_woo_auto_sync_order_invoices', array(__CLASS__, 'bridge_block_germanized'), 10, 3);

            \WC_Lexware_MVP_Logger::debug('Migration bridge active', array(
                'cutoff' => $cutoff,
                'filter' => 'storeabill_woo_auto_sync_order_invoices'
            ));
        }
    }

    /**
     * Check if Action Scheduler is available
     *
     * @since 1.0.0
     * @return bool True if Action Scheduler functions exist, false otherwise
     */
    private static function is_action_scheduler_available() {
        return function_exists('as_enqueue_async_action') || function_exists('as_schedule_single_action');
    }

    /**
     * Admin notice when Action Scheduler is missing
     */
    public static function action_scheduler_missing_notice() {
        ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php _e('Lexware MVP Warning:', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></strong>
                <?php _e('Action Scheduler not found. Using WP-Cron as fallback. For best performance, please use WooCommerce 3.5+ with Action Scheduler.', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Check if WP-Cron is disabled and show admin warning
     */
    public static function check_cron_status() {
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-warning"><p>';
                echo '<strong>' . esc_html__('Lexware MVP Warnung:', WC_LEXWARE_MVP_TEXT_DOMAIN) . '</strong> ';
                echo esc_html__('WP-Cron ist deaktiviert (DISABLE_WP_CRON). Bitte stelle sicher, dass ein System-Cron läuft für automatische Rechnungserstellung.', WC_LEXWARE_MVP_TEXT_DOMAIN);
                echo '</p></div>';
            });
        }
    }

    /**
     * Handle order status change for invoice creation
     */
    public static function on_order_status_invoice($order_id, $order) {
        \WC_Lexware_MVP_Logger::debug('Invoice hook triggered', array(
            'order_id' => $order_id,
            'order_status' => $order->get_status(),
            'order_total' => $order->get_total(),
            'currency' => $order->get_currency(),
            'hook' => current_action(),
            'customer_id' => $order->get_customer_id()
        ));

        // Migration: Post-Cutoff-Bestellungen überspringen (Germanized zuständig)
        if (self::is_past_cutoff($order_id, $order, 'invoice')) {
            return;
        }

        // Prüfe ob bereits eine Rechnung existiert (wichtig bei Multi-Status!)
        if (self::invoice_exists($order_id)) {
            \WC_Lexware_MVP_Logger::debug('Rechnung existiert bereits, überspringe', array(
                'order_id' => $order_id,
                'trigger_status' => $order->get_status()
            ));
            return;
        }

        \WC_Lexware_MVP_Logger::debug('Scheduling invoice creation', array(
            'order_id' => $order_id
        ));

        // Schedule asynchrone Verarbeitung via Action Scheduler
        self::schedule_invoice_creation($order_id);
    }

    /**
     * Triggered when a refund is created (partial or full)
     * Entry Point für alle Refund-Events
     *
     * @param int $order_id  Parent order ID
     * @param int $refund_id Refund post ID
     */
    public static function on_order_refunded($order_id, $refund_id) {
        $order = wc_get_order($order_id);
        $refund = wc_get_order($refund_id);

        \WC_Lexware_MVP_Logger::info('Refund hook triggered', array(
            'order_id' => $order_id,
            'refund_id' => $refund_id,
            'refund_amount' => $refund ? abs($refund->get_amount()) : null,
            'order_total' => $order ? $order->get_total() : null,
            'hook' => 'woocommerce_order_refunded'
        ));

        if (!$order || !$refund) {
            \WC_Lexware_MVP_Logger::warning('Order oder Refund nicht gefunden', array(
                'order_id' => $order_id,
                'refund_id' => $refund_id
            ));
            return;
        }

        // Migration: Post-Cutoff-Bestellungen überspringen (Germanized zuständig)
        if (self::is_past_cutoff($order_id, $order, 'credit_note')) {
            return;
        }

        // Hole Invoice aus DB
        global $wpdb;
        $table = wc_lexware_mvp_get_table_name(false); // ohne Backticks
        $invoice = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE order_id = %d AND document_type = 'invoice' AND document_status IN ('synced', 'completed') ORDER BY created_at DESC LIMIT 1",
            $order_id
        ));

        if (!$invoice) {
            $order->add_order_note(__('⚠️ Lexware Gutschrift nicht erstellt – Keine Rechnung vorhanden', WC_LEXWARE_MVP_TEXT_DOMAIN));
            \WC_Lexware_MVP_Logger::warning('Keine Invoice für Refund gefunden', array(
                'order_id' => $order_id,
                'refund_id' => $refund_id
            ));
            return;
        }

        \WC_Lexware_MVP_Logger::info('Invoice gefunden, schedule Credit Note', array(
            'order_id' => $order_id,
            'refund_id' => $refund_id,
            'invoice_id' => $invoice->document_id,
            'invoice_number' => $invoice->document_nr
        ));

        // Schedule Credit Note (mit Duplicate Prevention)
        self::schedule_credit_note_creation($order_id, $refund_id, $invoice->document_id);
    }

    /**
     * Handle order status change for credit note creation
     */
    public static function on_order_status_credit_note($order_id, $order) {
        \WC_Lexware_MVP_Logger::info('Credit Note Trigger gefeuert', array(
            'order_id' => $order_id,
            'status' => $order->get_status()
        ));

        // Migration: Post-Cutoff-Bestellungen überspringen (Germanized zuständig)
        if (self::is_past_cutoff($order_id, $order, 'credit_note_status')) {
            return;
        }

        // KRITISCH: Prüfe ob Rechnung existiert
        $invoice_record = self::get_invoice_record($order_id);

        if (!$invoice_record || empty($invoice_record->invoice_id)) {
            \WC_Lexware_MVP_Logger::warning('Keine Rechnung vorhanden, Credit Note nicht möglich', array(
                'order_id' => $order_id
            ));

            $order->add_order_note(
                __('⚠️ Lexware Gutschrift nicht erstellt - Keine Rechnung vorhanden', WC_LEXWARE_MVP_TEXT_DOMAIN)
            );
            return;
        }

        // Hole alle Refunds für diese Order
        $refunds = $order->get_refunds();

        if (empty($refunds)) {
            // Edge Case: Status "refunded" ohne WC-Refund → Voll-Gutschrift erstellen
            if ($order->get_status() === 'refunded') {
                global $wpdb;
                $table = wc_lexware_mvp_get_table_name(false);

                // Prüfe ob bereits eine Voll-Gutschrift existiert
                $full_credit_exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM `{$table}`
                     WHERE order_id = %d
                     AND document_type = 'credit_note'
                     AND refund_full = 1
                     AND document_status NOT IN ('failed', 'cancelled')",
                    $order_id
                ));

                if (!$full_credit_exists) {
                    \WC_Lexware_MVP_Logger::info('Status "refunded" ohne WC-Refund - erstelle Voll-Gutschrift', array(
                        'order_id' => $order_id,
                        'order_total' => $order->get_total()
                    ));
                    self::schedule_full_credit_note_creation($order_id, $invoice_record->document_id);
                    return;
                }

                \WC_Lexware_MVP_Logger::debug('Voll-Gutschrift existiert bereits', array('order_id' => $order_id));
                return;
            }

            \WC_Lexware_MVP_Logger::warning('Keine Refunds vorhanden', array('order_id' => $order_id));
            return;
        }

        // Hole bereits verarbeitete Refund-IDs aus Order Meta
        $processed_refund_ids = $order->get_meta('_lexware_processed_refund_ids', true);
        if (empty($processed_refund_ids) || !is_array($processed_refund_ids)) {
            $processed_refund_ids = array();
        }

        // OPTIMIERUNG: Batch-Check gegen DB für zusätzliche Sicherheit (verhindert N+1 Query)
        global $wpdb;
        $table = wc_lexware_mvp_get_table_name(false);
        $refund_ids = array_map(function($r) { return $r->get_id(); }, $refunds);

        if (!empty($refund_ids)) {
            $placeholders = implode(',', array_fill(0, count($refund_ids), '%d'));
            $query_params = array_merge(array($order_id), $refund_ids);

            $already_processed_in_db = $wpdb->get_col($wpdb->prepare(
                "SELECT refund_id FROM {$table}
                 WHERE order_id = %d
                 AND refund_id IN ({$placeholders})
                 AND document_type = 'credit_note'",
                $query_params
            ));

            // Merge beide Listen (Meta + DB) für vollständige Abdeckung
            $all_processed = array_unique(array_merge($processed_refund_ids, $already_processed_in_db));
        } else {
            $all_processed = $processed_refund_ids;
        }

        // Verarbeite jeden unverarbeiteten Refund
        foreach ($refunds as $refund) {
            $refund_id = $refund->get_id();

            // Skip wenn bereits verarbeitet (optimierter Check gegen combined list)
            if (in_array($refund_id, $all_processed)) {
                \WC_Lexware_MVP_Logger::debug('Refund bereits verarbeitet', array(
                    'order_id' => $order_id,
                    'refund_id' => $refund_id
                ));
                continue;
            }

            // Schedule Credit Note für diesen Refund
            self::schedule_credit_note_creation($order_id, $refund_id, $invoice_record->invoice_id);
        }
    }

    /**
     * Handle order status change for order confirmation creation
     *
     * @since 1.3.0
     * @param int $order_id Order ID
     * @param WC_Order $order Order object
     */
    public static function on_order_status_order_confirmation($order_id, $order) {
        \WC_Lexware_MVP_Logger::debug('Order confirmation hook triggered', array(
            'order_id' => $order_id,
            'order_status' => $order->get_status(),
            'hook' => current_action()
        ));

        // Migration: Post-Cutoff-Bestellungen überspringen (Germanized zuständig)
        if (self::is_past_cutoff($order_id, $order, 'order_confirmation')) {
            return;
        }

        // Prüfe ob bereits eine Auftragsbestätigung existiert
        if (self::order_confirmation_exists($order_id)) {
            \WC_Lexware_MVP_Logger::debug('Auftragsbestätigung existiert bereits, überspringe', array(
                'order_id' => $order_id
            ));
            return;
        }

        // Schedule asynchrone Verarbeitung
        self::schedule_order_confirmation_creation($order_id);
    }

    /**
     * Check if order confirmation already exists for order
     *
     * @since 1.3.0
     * @param int $order_id Order ID
     * @return bool True if exists
     */
    private static function order_confirmation_exists($order_id) {
        global $wpdb;
        $table = wc_lexware_mvp_get_table_name(false);

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM `{$table}` WHERE order_id = %d AND document_type = 'order_confirmation' AND document_status NOT IN ('failed', 'cancelled')",
            $order_id
        ));

        return !empty($exists);
    }

    /**
     * Schedule order confirmation creation via Action Scheduler
     *
     * @since 1.3.0
     * @param int $order_id Order ID
     */
    private static function schedule_order_confirmation_creation($order_id) {
        global $wpdb;
        $table = wc_lexware_mvp_get_table_name(false);
        $table_with_backticks = wc_lexware_mvp_get_table_name(true);

        $lock_key = "lexware_oc_lock_{$order_id}";

        // Application-Level Lock (60 second timeout)
        if (false === set_transient($lock_key, time(), 60)) {
            \WC_Lexware_MVP_Logger::debug('Order confirmation lock already exists, skipping', array(
                'order_id' => $order_id
            ));
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            delete_transient($lock_key);
            return;
        }

        // Database Transaction
        $wpdb->query('START TRANSACTION');

        try {
            // Check with FOR UPDATE
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table_with_backticks}
                 WHERE order_id = %d AND document_type = 'order_confirmation'
                 FOR UPDATE",
                $order_id
            ));

            if ($exists) {
                $wpdb->query('ROLLBACK');
                delete_transient($lock_key);
                \WC_Lexware_MVP_Logger::debug('Order confirmation already exists in DB', array('order_id' => $order_id));
                return;
            }

            // Insert Record
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

            $wpdb->query('COMMIT');
            delete_transient($lock_key);

            $order->add_order_note(__('Lexware Auftragsbestätigung wird erstellt...', WC_LEXWARE_MVP_TEXT_DOMAIN));

            \WC_Lexware_MVP_Logger::info('Order confirmation scheduled', array(
                'order_id' => $order_id
            ));

            // Schedule via Action Scheduler
            if (function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action('wc_lexware_mvp_process_order_confirmation', array($order_id), 'lexware-mvp');
            } elseif (function_exists('as_schedule_single_action')) {
                as_schedule_single_action(time(), 'wc_lexware_mvp_process_order_confirmation', array($order_id), 'lexware-mvp');
            } else {
                wp_schedule_single_event(time(), 'wc_lexware_mvp_process_order_confirmation', array($order_id));
            }

        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            delete_transient($lock_key);
            \WC_Lexware_MVP_Logger::error('Schedule order confirmation failed', array(
                'order_id' => $order_id,
                'error' => $e->getMessage()
            ));
        }
    }

    /**
     * Process order confirmation asynchronously
     *
     * @since 1.3.0
     * @param int $order_id Order ID
     */
    public static function process_order_confirmation_async($order_id) {
        global $wpdb;
        $table = wc_lexware_mvp_get_table_name(false);

        \WC_Lexware_MVP_Logger::info('Processing order confirmation async', array('order_id' => $order_id));

        try {
            self::create_order_confirmation($order_id);

        } catch (\Exception $e) {
            $error_message = $e->getMessage();
            $should_retry = true;
            $retry_delay = 0;
            $final_status = 'failed';

            // Error classification (same as Invoice)
            if (strpos($error_message, '401') !== false || strpos($error_message, '403') !== false) {
                $should_retry = false;
                $final_status = 'failed_auth';
            } elseif (strpos($error_message, '406') !== false || strpos($error_message, '400') !== false) {
                $should_retry = false;
                $final_status = 'failed_validation';
            } elseif (strpos($error_message, '429') !== false) {
                $should_retry = true;
                $retry_delay = 60;
                $final_status = 'pending_retry';
            } elseif (strpos($error_message, '503') !== false || strpos($error_message, '502') !== false) {
                $should_retry = true;
                $retry_delay = 0;
                $final_status = 'pending_retry';
            } else {
                $should_retry = true;
                $retry_delay = 5;
                $final_status = 'pending_retry';
            }

            // Retry Count
            $retry_count = 0;
            if (function_exists('as_get_scheduled_actions')) {
                $failed_actions = as_get_scheduled_actions(array(
                    'hook' => 'wc_lexware_mvp_process_order_confirmation',
                    'args' => array($order_id),
                    'status' => \ActionScheduler_Store::STATUS_FAILED,
                    'per_page' => 10
                ));
                $retry_count = count($failed_actions);
            }

            \WC_Lexware_MVP_Logger::error('Order confirmation processing failed', array(
                'order_id' => $order_id,
                'retry_count' => $retry_count,
                'should_retry' => $should_retry,
                'error_type' => $final_status,
                'error' => $error_message
            ));

            if ($retry_count >= 2 || !$should_retry) {
                $order = wc_get_order($order_id);
                if ($order) {
                    $order->add_order_note(sprintf(
                        __('⚠️ Lexware Auftragsbestätigung fehlgeschlagen: %s', WC_LEXWARE_MVP_TEXT_DOMAIN),
                        $error_message
                    ));
                }

                $wpdb->update($table,
                    array('document_status' => $final_status),
                    array('order_id' => $order_id, 'document_type' => 'order_confirmation')
                );

                throw $e;
            }

            if ($should_retry && $retry_count < 3) {
                $wpdb->update($table,
                    array('document_status' => $final_status),
                    array('order_id' => $order_id, 'document_type' => 'order_confirmation')
                );

                if (function_exists('as_schedule_single_action')) {
                    as_schedule_single_action(time() + $retry_delay, 'wc_lexware_mvp_process_order_confirmation', array($order_id), 'lexware-mvp');
                }

                return;
            }
        }
    }

    /**
     * Schedule credit note creation via Action Scheduler
     * Uses Database Transactions to prevent race conditions
     */
    private static function schedule_credit_note_creation($order_id, $refund_id, $invoice_id) {
        global $wpdb;
        $table = wc_lexware_mvp_get_table_name(false);

        // Application-Level Lock (30 second timeout) - Atomic Check-And-Set
        $lock_key = "lexware_queue_lock_{$order_id}_{$refund_id}";
        if (false === set_transient($lock_key, time(), 30)) {
            // Lock bereits vorhanden - andere Instanz arbeitet
            \WC_Lexware_MVP_Logger::debug('Credit note lock already exists, skipping', array(
                'order_id' => $order_id,
                'refund_id' => $refund_id,
                'lock_key' => $lock_key
            ));
            return;
        }

        \WC_Lexware_MVP_Logger::debug('Credit note lock acquired', array(
            'order_id' => $order_id,
            'refund_id' => $refund_id
        ));

        $order = wc_get_order($order_id);
        $refund = wc_get_order($refund_id);

        if (!$order || !$refund) {
            delete_transient($lock_key);
            return;
        }

        // Hole Refund Amount und prüfe ob Full Refund
        $refund_amount = abs($refund->get_amount()); // Exakter Betrag dieser Credit Note
        $order_total = $order->get_total();
        $is_full_refund = ($order_total == $order->get_total_refunded()) ? 1 : 0;

        // Validate refund amount
        if ($refund_amount > $order_total) {
            \WC_Lexware_MVP_Logger::warning('Refund amount exceeds order total', array(
                'order_id' => $order_id,
                'refund_id' => $refund_id,
                'order_total' => $order_total,
                'refund_amount' => $refund_amount
            ));

            $wpdb->insert($table, array(
                'document_type' => 'credit_note',
                'order_id' => $order_id,
                'refund_id' => $refund_id,
                'document_status' => 'failed',
                'created_at' => current_time('mysql')
            ));

            $order->add_order_note(
                sprintf(
                    __('⚠️ Gutschrift nicht erstellt: Erstattungsbetrag (%s) übersteigt Bestellsumme (%s)', WC_LEXWARE_MVP_TEXT_DOMAIN),
                    wc_price($refund_amount),
                    wc_price($order_total)
                )
            );

            delete_transient($lock_key);
            return;
        }

        // Database Transaction with Row-Level Lock
        $wpdb->query('START TRANSACTION');

        try {
            // Check with FOR UPDATE (Row-Level Lock)
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM `{$table}`
                 WHERE order_id = %d AND refund_id = %d AND document_type = 'credit_note'
                 FOR UPDATE",
                $order_id,
                $refund_id
            ));

            if ($exists) {
                $wpdb->query('ROLLBACK');
                delete_transient($lock_key);
                \WC_Lexware_MVP_Logger::debug('Credit note already exists, rollback', array(
                    'order_id' => $order_id,
                    'refund_id' => $refund_id,
                    'existing_id' => $exists
                ));
                return;
            }

            // Insert Record
            $insert_result = $wpdb->insert($table, array(
                'document_type' => 'credit_note',
                'order_id' => $order_id,
                'user_id' => $order->get_customer_id(),
                'refund_id' => $refund_id,
                'refund_reason' => $refund->get_reason(),
                'refund_amount' => $refund_amount,
                'refund_full' => $is_full_refund,
                'preceding_document_id' => $invoice_id,
                'document_status' => 'pending',
                'created_at' => current_time('mysql')
            ));

            if ($insert_result === false) {
                throw new \Exception('Database insert failed: ' . $wpdb->last_error);
            }

            // Commit Transaction
            $wpdb->query('COMMIT');
            delete_transient($lock_key);

            $order->add_order_note(
                sprintf(
                    __('Lexware Gutschrift wird erstellt (Refund #%d, Betrag: %s)...', WC_LEXWARE_MVP_TEXT_DOMAIN),
                    $refund_id,
                    wc_price($refund->get_amount())
                )
            );

            // Schedule Action Scheduler Task or WP-Cron Fallback
            if (function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action('wc_lexware_mvp_process_credit_note', array($order_id, $refund_id), 'lexware-mvp');
            } elseif (function_exists('as_schedule_single_action')) {
                as_schedule_single_action(time(), 'wc_lexware_mvp_process_credit_note', array($order_id, $refund_id), 'lexware-mvp');
            } else {
                // WP-Cron Fallback
                wp_schedule_single_event(time(), 'wc_lexware_mvp_process_credit_note', array($order_id, $refund_id));
                \WC_Lexware_MVP_Logger::debug('Scheduled credit note via WP-Cron fallback', array(
                    'order_id' => $order_id,
                    'refund_id' => $refund_id
                ));
            }

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            delete_transient($lock_key);
            \WC_Lexware_MVP_Logger::error('Schedule credit note creation failed', array(
                'order_id' => $order_id,
                'refund_id' => $refund_id,
                'error' => $e->getMessage()
            ));
        }
    }

    /**
     * Schedule full credit note creation without WC-Refund
     *
     * Used when order status is set to "refunded" manually without creating a WC refund.
     * Creates a full credit note for the entire order amount.
     *
     * @since 1.3.1
     * @param int $order_id WooCommerce Order ID
     * @param string $invoice_id Lexware Invoice Document ID
     */
    private static function schedule_full_credit_note_creation($order_id, $invoice_id) {
        global $wpdb;
        $table = wc_lexware_mvp_get_table_name(false);

        // Application-Level Lock (60 second timeout)
        $lock_key = "lexware_full_credit_lock_{$order_id}";
        if (false === set_transient($lock_key, time(), 60)) {
            \WC_Lexware_MVP_Logger::debug('Full credit note lock already exists, skipping', array(
                'order_id' => $order_id,
                'lock_key' => $lock_key
            ));
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            delete_transient($lock_key);
            return;
        }

        // Database Transaction with Row-Level Lock
        $wpdb->query('START TRANSACTION');

        try {
            // Check if full credit note already exists (refund_id IS NULL AND refund_full = 1)
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM `{$table}`
                 WHERE order_id = %d
                 AND document_type = 'credit_note'
                 AND refund_id IS NULL
                 AND refund_full = 1
                 FOR UPDATE",
                $order_id
            ));

            if ($exists) {
                $wpdb->query('ROLLBACK');
                delete_transient($lock_key);
                \WC_Lexware_MVP_Logger::debug('Full credit note already exists, rollback', array(
                    'order_id' => $order_id,
                    'existing_id' => $exists
                ));
                return;
            }

            // Insert Record
            $insert_result = $wpdb->insert($table, array(
                'document_type' => 'credit_note',
                'order_id' => $order_id,
                'user_id' => $order->get_customer_id(),
                'refund_id' => null, // Kein WC-Refund
                'refund_reason' => __('Vollständige Erstattung (Status: Refunded)', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'refund_amount' => $order->get_total(),
                'refund_full' => 1,
                'preceding_document_id' => $invoice_id,
                'document_status' => 'pending',
                'created_at' => current_time('mysql')
            ));

            if ($insert_result === false) {
                throw new \Exception('Database insert failed: ' . $wpdb->last_error);
            }

            // Commit Transaction
            $wpdb->query('COMMIT');
            delete_transient($lock_key);

            $order->add_order_note(
                sprintf(
                    __('Lexware Voll-Gutschrift wird erstellt (ohne WC-Refund, Betrag: %s)...', WC_LEXWARE_MVP_TEXT_DOMAIN),
                    wc_price($order->get_total())
                )
            );

            // Schedule Action Scheduler Task
            if (function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action('wc_lexware_mvp_process_full_credit_note', array($order_id, $invoice_id), 'lexware-mvp');
            } elseif (function_exists('as_schedule_single_action')) {
                as_schedule_single_action(time(), 'wc_lexware_mvp_process_full_credit_note', array($order_id, $invoice_id), 'lexware-mvp');
            } else {
                wp_schedule_single_event(time(), 'wc_lexware_mvp_process_full_credit_note', array($order_id, $invoice_id));
            }

        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            delete_transient($lock_key);
            \WC_Lexware_MVP_Logger::error('Schedule full credit note creation failed', array(
                'order_id' => $order_id,
                'error' => $e->getMessage()
            ));
        }
    }

    /**
     * Process credit note asynchronously (called by Action Scheduler)
     * Implements intelligent error classification for retry vs. permanent failures
     */
    public static function process_credit_note_async($order_id, $refund_id) {
        global $wpdb;
        $table = wc_lexware_mvp_get_table_name(false);

        try {
            self::create_credit_note($order_id, $refund_id);

        } catch (\Exception $e) {
            $error_message = $e->getMessage();
            $should_retry = true;
            $retry_delay = 0;
            $final_status = 'failed';

            // Klassifiziere Fehler nach Typ
            if (strpos($error_message, '401') !== false || stripos($error_message, 'unauthorized') !== false) {
                $should_retry = false;
                $final_status = 'failed_auth';

            } elseif (strpos($error_message, '406') !== false || stripos($error_message, 'not acceptable') !== false) {
                $should_retry = false;
                $final_status = 'failed_validation';

            } elseif (strpos($error_message, '400') !== false || stripos($error_message, 'bad request') !== false) {
                $should_retry = false;
                $final_status = 'failed_validation';

            } elseif (strpos($error_message, '429') !== false || stripos($error_message, 'rate limit') !== false) {
                $should_retry = true;
                $retry_delay = 60;
                $final_status = 'pending_retry';

            } elseif (strpos($error_message, '503') !== false || strpos($error_message, '502') !== false) {
                $should_retry = true;
                $retry_delay = 0;
                $final_status = 'pending_retry';

            } elseif (stripos($error_message, 'timeout') !== false || stripos($error_message, 'timed out') !== false) {
                $should_retry = true;
                $retry_delay = 0;
                $final_status = 'pending_retry';

            } else {
                $should_retry = true;
                $retry_delay = 5;
                $final_status = 'pending_retry';
            }

            // Retry Count prüfen
            $retry_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions
                 WHERE hook = 'wc_lexware_mvp_process_credit_note'
                 AND args = %s AND status = 'failed'",
                serialize(array($order_id, $refund_id))
            ));

            \WC_Lexware_MVP_Logger::error('Credit Note processing failed', array(
                'order_id' => $order_id,
                'refund_id' => $refund_id,
                'retry_count' => $retry_count,
                'should_retry' => $should_retry,
                'retry_delay' => $retry_delay,
                'error_type' => $final_status,
                'error' => $error_message
            ));

            // Admin Notification nach 3 Fehlversuchen oder bei permanenten Fehlern
            if ($retry_count >= 2 || !$should_retry) {
                $order = wc_get_order($order_id);
                if ($order) {
                    $order->add_order_note(sprintf(
                        __('⚠️ Lexware Gutschrift fehlgeschlagen: %s (Fehlertyp: %s, Versuche: %d)', WC_LEXWARE_MVP_TEXT_DOMAIN),
                        $error_message,
                        $final_status,
                        $retry_count + 1
                    ));
                }

                // Update Status zu final failed
                $wpdb->update($table,
                    array('document_status' => $final_status),
                    array('order_id' => $order_id, 'refund_id' => $refund_id, 'document_type' => 'credit_note')
                );

                // Kein Retry mehr möglich - Exception werfen
                throw $e;
            }

            // Expliziter Retry mit Action Scheduler
            if ($should_retry && $retry_count < 3) {
                \WC_Lexware_MVP_Logger::info('Scheduling retry for credit note', array(
                    'order_id' => $order_id,
                    'refund_id' => $refund_id,
                    'retry_count' => $retry_count + 1,
                    'retry_delay' => $retry_delay,
                    'reason' => $final_status
                ));

                // Update Status in Database
                $wpdb->update($table,
                    array('document_status' => $final_status),
                    array('order_id' => $order_id, 'refund_id' => $refund_id, 'document_type' => 'credit_note')
                );

                // Schedule Retry via Action Scheduler
                if (function_exists('as_schedule_single_action')) {
                    $retry_time = time() + $retry_delay;
                    as_schedule_single_action($retry_time, 'wc_lexware_mvp_process_credit_note', array($order_id, $refund_id), 'lexware-mvp');

                    \WC_Lexware_MVP_Logger::info('Retry scheduled successfully', array(
                        'order_id' => $order_id,
                        'refund_id' => $refund_id,
                        'scheduled_at' => date('Y-m-d H:i:s', $retry_time),
                        'delay_seconds' => $retry_delay
                    ));
                } else {
                    // WP-Cron Fallback
                    wp_schedule_single_event(time() + $retry_delay, 'wc_lexware_mvp_process_credit_note', array($order_id, $refund_id));
                }

                // DO NOT throw - let Action Scheduler mark this as complete and use our scheduled retry
                return;
            }
        }
    }

    /**
     * Process full credit note asynchronously (called by Action Scheduler)
     *
     * For credit notes created without WC-Refund (manual status change to "refunded").
     *
     * @since 1.3.1
     * @param int $order_id WooCommerce Order ID
     * @param string $invoice_id Lexware Invoice Document ID
     */
    public static function process_full_credit_note_async($order_id, $invoice_id) {
        global $wpdb;
        $table = wc_lexware_mvp_get_table_name(false);

        try {
            self::create_full_credit_note($order_id, $invoice_id);

        } catch (\Exception $e) {
            $error_message = $e->getMessage();
            $should_retry = true;
            $retry_delay = 0;
            $final_status = 'failed';

            // Klassifiziere Fehler nach Typ
            if (strpos($error_message, '401') !== false || stripos($error_message, 'unauthorized') !== false) {
                $should_retry = false;
                $final_status = 'failed_auth';
            } elseif (strpos($error_message, '406') !== false || strpos($error_message, '400') !== false) {
                $should_retry = false;
                $final_status = 'failed_validation';
            } elseif (strpos($error_message, '429') !== false) {
                $should_retry = true;
                $retry_delay = 60;
                $final_status = 'pending_retry';
            } elseif (strpos($error_message, '503') !== false || strpos($error_message, '502') !== false) {
                $should_retry = true;
                $retry_delay = 0;
                $final_status = 'pending_retry';
            } elseif (stripos($error_message, 'timeout') !== false) {
                $should_retry = true;
                $retry_delay = 0;
                $final_status = 'pending_retry';
            } else {
                $should_retry = true;
                $retry_delay = 5;
                $final_status = 'pending_retry';
            }

            // Retry Count prüfen
            $retry_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions
                 WHERE hook = 'wc_lexware_mvp_process_full_credit_note'
                 AND args = %s AND status = 'failed'",
                serialize(array($order_id, $invoice_id))
            ));

            \WC_Lexware_MVP_Logger::error('Full Credit Note processing failed', array(
                'order_id' => $order_id,
                'invoice_id' => $invoice_id,
                'retry_count' => $retry_count,
                'should_retry' => $should_retry,
                'error_type' => $final_status,
                'error' => $error_message
            ));

            if ($retry_count >= 2 || !$should_retry) {
                $order = wc_get_order($order_id);
                if ($order) {
                    $order->add_order_note(sprintf(
                        __('⚠️ Lexware Voll-Gutschrift fehlgeschlagen: %s (Fehlertyp: %s)', WC_LEXWARE_MVP_TEXT_DOMAIN),
                        $error_message,
                        $final_status
                    ));
                }

                $wpdb->update($table,
                    array('document_status' => $final_status),
                    array('order_id' => $order_id, 'refund_id' => null, 'refund_full' => 1, 'document_type' => 'credit_note')
                );

                throw $e;
            }

            if ($should_retry && $retry_count < 3) {
                $wpdb->update($table,
                    array('document_status' => $final_status),
                    array('order_id' => $order_id, 'refund_id' => null, 'refund_full' => 1, 'document_type' => 'credit_note')
                );

                if (function_exists('as_schedule_single_action')) {
                    $retry_time = time() + $retry_delay;
                    as_schedule_single_action($retry_time, 'wc_lexware_mvp_process_full_credit_note', array($order_id, $invoice_id), 'lexware-mvp');
                } else {
                    wp_schedule_single_event(time() + $retry_delay, 'wc_lexware_mvp_process_full_credit_note', array($order_id, $invoice_id));
                }

                return;
            }
        }
    }

    /**
     * Schedule invoice creation via Action Scheduler
     * Uses Application-Level Locks + Database Transactions to prevent race conditions
     */
    private static function schedule_invoice_creation($order_id) {
        global $wpdb;

        \WC_Lexware_MVP_Logger::debug('Schedule invoice creation started', array(
            'order_id' => $order_id
        ));

        // CRITICAL FIX: Get table name WITHOUT backticks for wpdb methods
        $table = wc_lexware_mvp_get_table_name(false); // false = no backticks
        $table_with_backticks = wc_lexware_mvp_get_table_name(true); // for raw SQL queries

        $lock_key = "lexware_sync_lock_{$order_id}";

        // Application-Level Lock (60 second timeout) - Atomic Check-And-Set
        if (false === set_transient($lock_key, time(), 60)) {
            // Lock bereits vorhanden - andere Instanz arbeitet
            \WC_Lexware_MVP_Logger::debug('Lock already exists, skipping', array(
                'order_id' => $order_id,
                'lock_key' => $lock_key
            ));
            return;
        }

        \WC_Lexware_MVP_Logger::debug('Lock acquired', array(
            'order_id' => $order_id
        ));

        // Erstelle Datenbank-Eintrag mit Status "pending"
        $order = wc_get_order($order_id);
        if (!$order) {
            delete_transient($lock_key);
            \WC_Lexware_MVP_Logger::error('Order not found', array(
                'order_id' => $order_id
            ));
            return;
        }

        // Database Transaction with Row-Level Lock
        \WC_Lexware_MVP_Logger::debug('Starting DB transaction', array(
            'order_id' => $order_id,
            'order_status' => $order->get_status(),
            'order_total' => $order->get_total(),
            'customer_id' => $order->get_customer_id()
        ));
        $wpdb->query('START TRANSACTION');

        try {
            // Check with FOR UPDATE (Row-Level Lock) - use backticks for raw SQL
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table_with_backticks}
                 WHERE order_id = %d AND document_type = 'invoice'
                 FOR UPDATE",
                $order_id
            ));

            if ($exists) {
                $wpdb->query('ROLLBACK');
                delete_transient($lock_key);
                \WC_Lexware_MVP_Logger::debug('Invoice already exists, rollback', array(
                    'order_id' => $order_id,
                    'existing_id' => $exists
                ));
                return;
            }

            // Insert Record
            $insert_result = $wpdb->insert($table, array(
                'document_type' => 'invoice',
                'order_id' => $order_id,
                'user_id' => $order->get_customer_id(),
                'document_status' => 'pending',
                'created_at' => current_time('mysql')
            ));

            if ($insert_result === false) {
                \WC_Lexware_MVP_Logger::error('Database insert failed', array(
                    'order_id' => $order_id,
                    'error' => $wpdb->last_error,
                    'query' => $wpdb->last_query
                ));
                throw new \Exception('Database insert failed: ' . $wpdb->last_error);
            }

            \WC_Lexware_MVP_Logger::debug('Invoice record inserted', array(
                'order_id' => $order_id,
                'insert_id' => $wpdb->insert_id
            ));

            // Commit Transaction
            $wpdb->query('COMMIT');
            delete_transient($lock_key);

            // Füge initiale Bestellnotiz hinzu
            $order->add_order_note(__('Lexware Rechnung wird erstellt...', WC_LEXWARE_MVP_TEXT_DOMAIN));

            // Schedule Action Scheduler Task or WP-Cron Fallback
            if (function_exists('as_enqueue_async_action')) {
                $action_id = as_enqueue_async_action('wc_lexware_mvp_process_invoice', array($order_id), 'lexware-mvp');

                \WC_Lexware_MVP_Logger::info('Invoice scheduled via Action Scheduler', array(
                    'order_id' => $order_id,
                    'action_id' => $action_id,
                    'queue_group' => 'lexware-mvp',
                    'estimated_execution' => 'within 1 minute',
                    'queue_status' => self::get_action_scheduler_queue_health(),
                ));
            } elseif (function_exists('as_schedule_single_action')) {
                $action_id = as_schedule_single_action(time(), 'wc_lexware_mvp_process_invoice', array($order_id), 'lexware-mvp');

                \WC_Lexware_MVP_Logger::info('Invoice scheduled via Action Scheduler (single)', array(
                    'order_id' => $order_id,
                    'action_id' => $action_id,
                    'queue_status' => self::get_action_scheduler_queue_health(),
                ));
            } else {
                // WP-Cron Fallback
                wp_schedule_single_event(time(), 'wc_lexware_mvp_process_invoice', array($order_id));
                \WC_Lexware_MVP_Logger::warning('Invoice scheduled via WP-Cron fallback (Action Scheduler not available)', array(
                    'order_id' => $order_id,
                    'recommendation' => 'Install Action Scheduler plugin for better reliability'
                ));
            }

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            delete_transient($lock_key);
            \WC_Lexware_MVP_Logger::error('Schedule invoice creation failed', array(
                'order_id' => $order_id,
                'order_status' => $order->get_status(),
                'order_total' => $order->get_total(),
                'currency' => $order->get_currency(),
                'error' => $e->getMessage(),
                'error_code' => $e->getCode()
            ));
        }
    }

    /**
     * Process invoice asynchronously (called by Action Scheduler)
     * Implements intelligent error classification for retry vs. permanent failures
     */
    public static function process_invoice_async($order_id) {
        global $wpdb;
        $table = wc_lexware_mvp_get_table_name(false);

        try {
            self::create_invoice($order_id);

        } catch (\Exception $e) {
            $error_message = $e->getMessage();
            $should_retry = true;
            $retry_delay = 0;
            $final_status = 'failed';

            // Klassifiziere Fehler nach Typ
            if (strpos($error_message, '401') !== false || stripos($error_message, 'unauthorized') !== false) {
                // Unauthorized - kein Retry (permanenter Fehler)
                $should_retry = false;
                $final_status = 'failed_auth';

            } elseif (strpos($error_message, '406') !== false || stripos($error_message, 'not acceptable') !== false) {
                // Validation Error - kein Retry (Daten fehlerhaft)
                $should_retry = false;
                $final_status = 'failed_validation';

            } elseif (strpos($error_message, '400') !== false || stripos($error_message, 'bad request') !== false) {
                // Bad Request - kein Retry (Payload fehlerhaft)
                $should_retry = false;
                $final_status = 'failed_validation';

            } elseif (strpos($error_message, '429') !== false || stripos($error_message, 'rate limit') !== false) {
                // Rate Limit - Retry mit Delay
                $should_retry = true;
                $retry_delay = 60; // 60 Sekunden warten
                $final_status = 'pending_retry';

            } elseif (strpos($error_message, '503') !== false || strpos($error_message, '502') !== false) {
                // Server Error - Retry ohne Delay
                $should_retry = true;
                $retry_delay = 0;
                $final_status = 'pending_retry';

            } elseif (stripos($error_message, 'timeout') !== false || stripos($error_message, 'timed out') !== false) {
                // Timeout - Retry ohne Delay
                $should_retry = true;
                $retry_delay = 0;
                $final_status = 'pending_retry';

            } else {
                // Unbekannter Fehler - 1 Retry mit kurzem Delay
                $should_retry = true;
                $retry_delay = 5;
                $final_status = 'pending_retry';
            }

            // Retry Count prüfen
            $retry_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions
                 WHERE hook = 'wc_lexware_mvp_process_invoice'
                 AND args = %s AND status = 'failed'",
                serialize(array($order_id))
            ));

            \WC_Lexware_MVP_Logger::error('Invoice processing failed', array(
                'order_id' => $order_id,
                'retry_count' => $retry_count,
                'should_retry' => $should_retry,
                'retry_delay' => $retry_delay,
                'error_type' => $final_status,
                'error' => $error_message
            ));

            // Admin Notification nach 3 Fehlversuchen oder bei permanenten Fehlern
            if ($retry_count >= 2 || !$should_retry) {
                $order = wc_get_order($order_id);
                if ($order) {
                    $order->add_order_note(sprintf(
                        __('⚠️ Lexware Rechnung fehlgeschlagen: %s (Fehlertyp: %s, Versuche: %d)', WC_LEXWARE_MVP_TEXT_DOMAIN),
                        $error_message,
                        $final_status,
                        $retry_count + 1
                    ));
                }

                // Update Status zu final failed
                $wpdb->update($table,
                    array('document_status' => $final_status),
                    array('order_id' => $order_id, 'document_type' => 'invoice')
                );

                // Kein Retry mehr möglich - Exception werfen
                throw $e;
            }

            // Expliziter Retry mit Action Scheduler
            if ($should_retry && $retry_count < 3) {
                \WC_Lexware_MVP_Logger::info('Scheduling retry for invoice', array(
                    'order_id' => $order_id,
                    'retry_count' => $retry_count + 1,
                    'retry_delay' => $retry_delay,
                    'reason' => $final_status
                ));

                // Update Status in Database
                $wpdb->update($table,
                    array('document_status' => $final_status),
                    array('order_id' => $order_id, 'document_type' => 'invoice')
                );

                // Schedule Retry via Action Scheduler
                if (function_exists('as_schedule_single_action')) {
                    $retry_time = time() + $retry_delay;
                    as_schedule_single_action($retry_time, 'wc_lexware_mvp_process_invoice', array($order_id), 'lexware-mvp');

                    \WC_Lexware_MVP_Logger::info('Retry scheduled successfully', array(
                        'order_id' => $order_id,
                        'scheduled_at' => date('Y-m-d H:i:s', $retry_time),
                        'delay_seconds' => $retry_delay
                    ));
                } else {
                    // WP-Cron Fallback
                    wp_schedule_single_event(time() + $retry_delay, 'wc_lexware_mvp_process_invoice', array($order_id));
                }

                // DO NOT throw - let Action Scheduler mark this as complete and use our scheduled retry
                return;
            }
        }
    }

    /**
     * Check if order was created after the migration cutoff date.
     *
     * When a cutoff date is set, orders created AFTER that date are handled
     * by Germanized Pro instead of Lexware. Returns true if this order
     * should be skipped by Lexware.
     *
     * @since 1.4.0
     * @param int $order_id WooCommerce Order ID
     * @param \WC_Order $order WooCommerce Order object
     * @param string $context Logging context (e.g. 'invoice', 'credit_note', 'order_confirmation')
     * @return bool True if order is past cutoff and should be skipped
     */
    private static function is_past_cutoff($order_id, $order, $context = 'document') {
        $cutoff = get_option('wc_lexware_mvp_cutoff_date', '');
        if (empty($cutoff)) {
            return false;
        }

        $order_date = $order->get_date_created();
        if (!$order_date) {
            return false;
        }

        $order_date_str = $order_date->format('Y-m-d');
        if ($order_date_str > $cutoff) {
            \WC_Lexware_MVP_Logger::info("Bestellung nach Cutoff-Datum – Germanized zuständig ({$context})", array(
                'order_id'   => $order_id,
                'order_date' => $order_date_str,
                'cutoff'     => $cutoff,
            ));
            return true;
        }

        return false;
    }

    /**
     * Migration Bridge: Prevent Germanized/StoreaBill from creating invoices
     * for orders that belong to Lexware (pre-cutoff) or already have a Lexware document.
     *
     * Hooked into 'storeabill_woo_auto_sync_order_invoices' filter.
     * Only registered when cutoff date is set (see init()).
     *
     * @since 1.4.0
     * @param bool $sync Whether to sync (create invoice)
     * @param int $order_id WooCommerce Order ID
     * @param \WC_Order $order WooCommerce Order object
     * @return bool False to block Germanized, original value otherwise
     */
    public static function bridge_block_germanized($sync, $order_id, $order) {
        if (!$sync) {
            return $sync;
        }

        $cutoff = get_option('wc_lexware_mvp_cutoff_date', '');
        if (empty($cutoff)) {
            return $sync;
        }

        if (!$order instanceof \WC_Order) {
            $order = wc_get_order($order_id);
        }
        if (!$order) {
            return $sync;
        }

        $order_date = $order->get_date_created();
        if (!$order_date) {
            return $sync;
        }

        // Pre-cutoff: Lexware MVP is responsible
        if ($order_date->format('Y-m-d') <= $cutoff) {
            \WC_Lexware_MVP_Logger::debug('Bridge: Germanized blocked for pre-cutoff order', array(
                'order_id'   => $order_id,
                'order_date' => $order_date->format('Y-m-d'),
                'cutoff'     => $cutoff,
            ));
            return false;
        }

        // Post-cutoff but Lexware already has a document (race condition guard)
        if (self::invoice_exists($order_id)) {
            \WC_Lexware_MVP_Logger::debug('Bridge: Germanized blocked – Lexware invoice already exists', array(
                'order_id' => $order_id,
            ));
            return false;
        }

        return $sync;
    }

    /**
     * Prüfe ob Rechnung bereits existiert
     *
     * @param int $order_id WooCommerce Order ID
     * @return bool True if invoice exists (not failed/cancelled)
     */
    private static function invoice_exists($order_id) {
        global $wpdb;
        $table = wc_lexware_mvp_get_table_name(false);

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM `{$table}`
             WHERE order_id = %d
             AND document_type = 'invoice'
             AND document_status NOT IN ('failed', 'cancelled')",
            $order_id
        ));

        return !empty($exists);
    }

    /**
     * Get invoice record for order (used for credit notes)
     * @return object|null Database row or null if not found
     */
    private static function get_invoice_record($order_id) {
        global $wpdb;
        $table = wc_lexware_mvp_get_table_name(false);

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE order_id = %d
             AND document_type = 'invoice'
             AND document_status = 'completed'
             ORDER BY id DESC LIMIT 1",
            $order_id
        ));
    }

    /**
     * Create Order Confirmation in Lexware
     *
     * @since 1.3.0
     * @param int $order_id Order ID
     */
    public static function create_order_confirmation($order_id) {
        global $wpdb;
        $table = wc_lexware_mvp_get_table_name(false);

        $order = wc_get_order($order_id);
        if (!$order) {
            \WC_Lexware_MVP_Logger::error('Order Confirmation: Order nicht gefunden', array('order_id' => $order_id));
            return;
        }

        // Idempotency Check
        if (\WC_Lexware_MVP_Idempotency_Manager::is_completed($order_id, 'create_order_confirmation')) {
            \WC_Lexware_MVP_Logger::info('Order confirmation already completed (idempotency)', array('order_id' => $order_id));
            return;
        }

        // Currency Validation
        if ($order->get_currency() !== 'EUR') {
            $order->add_order_note(sprintf(
                __('⚠️ Auftragsbestätigung nicht erstellt: Währung %s wird nicht unterstützt (nur EUR)', WC_LEXWARE_MVP_TEXT_DOMAIN),
                $order->get_currency()
            ));
            throw new \Exception(sprintf('Currency %s not supported, only EUR', $order->get_currency()));
        }

        // DB Transaction
        $wpdb->query('START TRANSACTION');

        try {
            $record = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM `{$table}`
                 WHERE order_id = %d AND document_type = 'order_confirmation'
                 FOR UPDATE",
                $order_id
            ));

            if (!$record || !in_array($record->document_status, array('pending', 'pending_retry'))) {
                throw new \Exception('Invalid record state: ' . ($record ? $record->document_status : 'not found'));
            }

            // Get or create contact
            $api = new \WC_Lexware_MVP_API_Client();
            $contact_id = self::get_or_create_contact($api, $order);

            if (!$contact_id) {
                throw new \Exception('Contact creation failed');
            }

            usleep(500000); // Rate limit protection (500ms)

            // Build line items (reuse Invoice logic)
            $line_items = self::build_invoice_line_items($order);
            $has_any_tax = self::order_has_any_tax($order);
            $tax_type = $has_any_tax ? 'gross' : 'net';

            // Build address
            $company = $order->get_billing_company();
            $first_name = $order->get_billing_first_name();
            $last_name = $order->get_billing_last_name();
            $address_name = !empty($company) ? $company : trim("{$first_name} {$last_name}");

            // Order metadata
            $order_prefix = get_option('wc_lexware_mvp_order_prefix', '');
            $order_number = !empty($order_prefix) ? $order_prefix . $order_id : (string)$order_id;
            $order_date = $order->get_date_created()->date_i18n('d.m.Y');
            $payment_method = $order->get_payment_method_title() ?: $order->get_payment_method();

            // Build Order Confirmation Payload
            $oc_data = array(
                'archived' => false,
                'voucherDate' => $order->get_date_created()->date('Y-m-d\TH:i:s.vP'),
                'title' => __('Auftragsbestätigung', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'address' => array(
                    'contactId' => $contact_id,
                    'name' => $address_name,
                    'street' => $order->get_billing_address_1(),
                    'zip' => $order->get_billing_postcode(),
                    'city' => $order->get_billing_city(),
                    'countryCode' => $order->get_billing_country(),
                ),
                'lineItems' => $line_items,
                'totalPrice' => array(
                    'currency' => 'EUR'
                ),
                'taxConditions' => array(
                    'taxType' => $tax_type
                ),
                'introduction' => __('Vielen Dank für Ihre Bestellung. Hiermit bestätigen wir Ihren Auftrag.', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'shippingConditions' => self::build_shipping_conditions($order),
                'remark' => sprintf(
                    "Bestellnummer: %s\nBestelldatum: %s\nZahlungsart: %s",
                    $order_number,
                    $order_date,
                    $payment_method
                )
            );

            // Add address supplement if exists
            $address_2 = $order->get_billing_address_2();
            if (!empty($address_2)) {
                $oc_data['address']['supplement'] = $address_2;
            }

            // Payment Conditions (if mapped)
            $payment_condition_data = self::get_payment_condition_for_order($order);
            if ($payment_condition_data && is_array($payment_condition_data)) {
                $oc_data['paymentConditions'] = $payment_condition_data;
            }

            if (empty($oc_data['lineItems'])) {
                throw new \Exception('No valid line items found');
            }

            \WC_Lexware_MVP_Logger::debug('Order confirmation payload built', array(
                'order_id' => $order_id,
                'line_items_count' => count($oc_data['lineItems']),
                'tax_type' => $tax_type
            ));

            // Create Order Confirmation
            $finalize = get_option('wc_lexware_mvp_auto_finalize_order_confirmation', 'yes') === 'yes';
            $result = $api->create_order_confirmation($oc_data, $finalize, $order_id);

            if (\is_wp_error($result)) {
                throw new \Exception($result->get_error_message());
            }

            // Mark completed in idempotency manager
            \WC_Lexware_MVP_Idempotency_Manager::mark_completed($order_id, 'create_order_confirmation');

            $oc_id = $result['id'];
            $oc_number = '';

            // Get voucherNumber if finalized
            if ($finalize) {
                usleep(300000); // Small delay for API
                $oc_details = $api->get_order_confirmation($oc_id);
                if (!\is_wp_error($oc_details) && isset($oc_details['voucherNumber'])) {
                    $oc_number = $oc_details['voucherNumber'];
                }
            }

            // Update DB
            $wpdb->update($table, array(
                'document_id' => $oc_id,
                'document_nr' => $oc_number,
                'document_status' => 'synced',
                'document_finalized' => $finalize ? 1 : 0,
                'synced_at' => current_time('mysql')
            ), array('id' => $record->id));

            $wpdb->query('COMMIT');

            // Order Note
            $order->add_order_note(sprintf(
                __('✅ Lexware Auftragsbestätigung erstellt: %s (ID: %s)', WC_LEXWARE_MVP_TEXT_DOMAIN),
                $oc_number ?: 'Entwurf',
                $oc_id
            ));

            // Order Meta
            $order->update_meta_data('_lexware_order_confirmation_id', $oc_id);
            $order->update_meta_data('_lexware_order_confirmation_number', $oc_number);
            $order->save();

            \WC_Lexware_MVP_Logger::info('Order confirmation created successfully', array(
                'order_id' => $order_id,
                'oc_id' => $oc_id,
                'oc_number' => $oc_number,
                'finalized' => $finalize
            ));

            // PDF Download
            $pdf_filename = null;
            if ($finalize) {
                $max_retries = 3;
                $retry_delays = array(2, 3, 5);

                for ($attempt = 0; $attempt < $max_retries; $attempt++) {
                    if ($attempt > 0) {
                        sleep($retry_delays[$attempt - 1]);
                    }

                    $pdf_result = $api->download_order_confirmation_pdf($oc_id);

                    if (!\is_wp_error($pdf_result)) {
                        break;
                    }

                    \WC_Lexware_MVP_Logger::debug('Order confirmation PDF download attempt', array(
                        'order_id' => $order_id,
                        'attempt' => $attempt + 1,
                        'error' => $pdf_result->get_error_message()
                    ));

                    // Don't retry on permanent errors
                    if ($pdf_result->get_error_code() !== 'conflict') {
                        break;
                    }
                }

                if (!\is_wp_error($pdf_result)) {
                    $pdf_handler = \WC_Lexware_MVP_PDF_Handler::get_instance();
                    $pdf_filename = $pdf_handler->save_pdf($pdf_result, $order_id, 'order_confirmation', $oc_number);

                    if ($pdf_filename) {
                        $wpdb->update($table, array('document_filename' => $pdf_filename), array('id' => $record->id));

                        \WC_Lexware_MVP_Logger::info('Order confirmation PDF saved', array(
                            'order_id' => $order_id,
                            'filename' => $pdf_filename
                        ));
                    }
                } else {
                    \WC_Lexware_MVP_Logger::warning('Order confirmation PDF download failed after retries', array(
                        'order_id' => $order_id,
                        'error' => $pdf_result->get_error_message()
                    ));
                }
            }

            // Email senden
            $email_option = get_option('wc_lexware_mvp_send_order_confirmation_email', 'yes');

            if ($email_option === 'yes' && $pdf_filename) {
                \WC_Lexware_MVP_Logger::debug('Triggering order confirmation email', array(
                    'order_id' => $order_id,
                    'record_id' => $record->id
                ));

                do_action('wc_lexware_mvp_order_confirmation_ready', $order_id, $record->id, $pdf_filename);
            }

            // Update status to synced (consistent with Invoice/Credit Note)
            $wpdb->update($table, array('document_status' => 'synced'), array('id' => $record->id));

        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');

            \WC_Lexware_MVP_Logger::error('Order confirmation creation failed', array(
                'order_id' => $order_id,
                'error' => $e->getMessage()
            ));

            $order->add_order_note(sprintf(
                __('⚠️ Fehler beim Erstellen der Auftragsbestätigung: %s', WC_LEXWARE_MVP_TEXT_DOMAIN),
                $e->getMessage()
            ));

            throw $e;
        }
    }

    /**
     * Erstelle Rechnung in Lexware
     * Uses Database Transactions to ensure ACID guarantees
     */
    public static function create_invoice($order_id) {
        global $wpdb;
        $table = wc_lexware_mvp_get_table_name(false); // NO backticks - query template adds them

        $order = wc_get_order($order_id);

        if (!$order) {
            \WC_Lexware_MVP_Logger::error('Rechnung: Order nicht gefunden', array(
                'order_id' => $order_id
            ));
            return;
        }

        // ✅ IDEMPOTENCY CHECK - Prevents duplicate invoices on retries
        if (\WC_Lexware_MVP_Idempotency_Manager::is_completed($order_id, 'create_invoice')) {
            \WC_Lexware_MVP_Logger::info('Invoice creation already completed, skipping', array(
                'order_id' => $order_id
            ));
            $order->add_order_note(__('⚠️ Rechnungserstellung bereits abgeschlossen (Idempotency Check)', WC_LEXWARE_MVP_TEXT_DOMAIN));
            return;
        }

        // Validate currency - Lexware only supports EUR
        if ($order->get_currency() !== 'EUR') {
            $error_msg = sprintf(
                __('Order currency %s is not supported. Only EUR is accepted by Lexware.', WC_LEXWARE_MVP_TEXT_DOMAIN),
                $order->get_currency()
            );
            \WC_Lexware_MVP_Logger::error('Currency validation failed', array(
                'order_id' => $order_id,
                'currency' => $order->get_currency()
            ));
            $order->add_order_note('❌ ' . $error_msg);
            throw new \Exception($error_msg);
        }

        // Start Database Transaction
        $wpdb->query('START TRANSACTION');

        try {
            // Lock Record with FOR UPDATE (Row-Level Lock)
            $record = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM `{$table}`
                 WHERE order_id = %d AND document_type = 'invoice'
                 FOR UPDATE",
                $order_id
            ));

            // Allow 'pending' or 'pending_retry' (for retry attempts)
            if (!$record || !in_array($record->document_status, array('pending', 'pending_retry'))) {
                throw new \Exception('Invalid record state: ' . ($record ? $record->document_status : 'not found'));
            }

            // Get or create contact first (needed for contactId reference)
            $api = new \WC_Lexware_MVP_API_Client();
            $contact_id = self::get_or_create_contact($api, $order);

            if (!$contact_id) {
                throw new \Exception(__('Contact creation or retrieval failed', WC_LEXWARE_MVP_TEXT_DOMAIN));
            }

            // Additional delay before invoice creation to prevent rate limit
            usleep(500000); // 500ms

            // Build line items first to determine document-level taxType
            $line_items = self::build_invoice_line_items($order);

            // INTELLIGENTE REGEL FÜR DOCUMENT-LEVEL taxType:
            // Wenn IRGENDEIN Item MwSt hat → taxType='gross'
            // Nur wenn ALLE Items keine MwSt haben → taxType='net'
            $has_any_tax = self::order_has_any_tax($order);
            $tax_type = $has_any_tax ? 'gross' : 'net';

            \WC_Lexware_MVP_Logger::debug('Invoice tax type determined', array(
                'order_id' => $order_id,
                'has_any_tax' => $has_any_tax,
                'tax_type' => $tax_type,
                'reason' => $has_any_tax ? 'Order has tax → using gross approach' : 'No tax → using net approach'
            ));

            // Build address name: Prioritize company name for B2B orders
            $company = $order->get_billing_company();
            $address_name = !empty($company)
                ? $company  // B2B: Use company name
                : trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()); // B2C: Use person name

            // Build order metadata for remark field
            $order_prefix = get_option('wc_lexware_mvp_order_prefix', '');
            $order_number = !empty($order_prefix) ? $order_prefix . $order_id : (string)$order_id;
            $order_date = $order->get_date_created()->date_i18n('d.m.Y');
            $payment_method = $order->get_payment_method_title();
            if (empty($payment_method)) {
                $payment_method = $order->get_payment_method();
            }

            // Baue Invoice Data Array für Lexware API
            $invoice_data = array(
                'archived' => false,  // Dokument ist aktiv (nicht archiviert)
                'contactId' => $contact_id,  // Top-Level contact reference
                'voucherDate' => $order->get_date_created()->date('Y-m-d\TH:i:s.v\Z'),
                'title' => __('Rechnung', WC_LEXWARE_MVP_TEXT_DOMAIN),  // Dokumenttitel (max 25 Zeichen)
                'address' => array(
                    'contactId' => $contact_id,  // Contact reference in address
                    'name' => $address_name,  // Firma oder Name (B2B-kompatibel)
                    'street' => $order->get_billing_address_1(),
                    'zip' => $order->get_billing_postcode(),
                    'city' => $order->get_billing_city(),
                    'countryCode' => $order->get_billing_country(),
                ),
                'lineItems' => $line_items,
                'totalPrice' => array(
                    'currency' => 'EUR'
                ),
                'taxConditions' => array(
                    'taxType' => $tax_type // DYNAMISCH: gross wenn Tax vorhanden, sonst net
                ),
                'introduction' => '',  // Leer bei Invoices (Einleitungstext)
                'shippingConditions' => self::build_shipping_conditions($order),
                'remark' => sprintf(
                    "Bestellnummer: %s\nBestelldatum: %s\nZahlungsart: %s",
                    $order_number,
                    $order_date,
                    $payment_method
                )
            );

            // Add Payment Conditions if mapped
            $payment_condition_data = self::get_payment_condition_for_order($order);
            if ($payment_condition_data && is_array($payment_condition_data)) {
                $invoice_data['paymentConditions'] = $payment_condition_data;

                \WC_Lexware_MVP_Logger::debug('Payment condition added to invoice', array(
                    'order_id' => $order_id,
                    'payment_method' => $order->get_payment_method(),
                    'condition_data' => $payment_condition_data
                ));
            }

            // Validate line items are not empty
            if (empty($invoice_data['lineItems'])) {
                throw new \Exception(__('No valid line items found for invoice. Cannot create invoice without products.', WC_LEXWARE_MVP_TEXT_DOMAIN));
            }

            // Erstelle Rechnung (finalize parameter via Settings)
            $finalize = get_option('wc_lexware_mvp_auto_finalize_invoice', 'yes') === 'yes';
            $start_time = microtime(true);
            $result = $api->create_invoice($invoice_data, $finalize, $order_id); // ← order_id für Idempotency

            if (\is_wp_error($result)) {
                \WC_Lexware_MVP_Logger::error('Invoice creation failed - API returned error', array(
                    'order_id' => $order_id,
                    'invoice_payload' => $invoice_data, // Wird GDPR-compliant redacted
                    'api_error_code' => $result->get_error_code(),
                    'api_error_message' => $result->get_error_message(),
                    'api_error_data' => $result->get_error_data(),
                    'payment_conditions' => array(
                        'method' => $order->get_payment_method(),
                        'due_days' => isset($invoice_data['paymentConditions']) ? 'mapped' : 'not_mapped',
                        'condition_id' => $payment_condition_id ?? null,
                    ),
                    'line_items_count' => count($invoice_data['lineItems']),
                    'total_amount' => $order->get_total(),
                    'finalize_requested' => $finalize,
                    'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                    'time_elapsed_ms' => round((microtime(true) - $start_time) * 1000, 2),
                ));
                throw new \Exception($result->get_error_message());
            }

            // ✅ MARK AS COMPLETED - Prevents re-execution on retries
            \WC_Lexware_MVP_Idempotency_Manager::mark_completed($order_id, 'create_invoice');

            // Erfolg: Update DB mit Invoice ID
            $invoice_id = $result['id'];

            // Lexware API gibt voucherNumber nicht in CREATE response zurück
            // Wir müssen die Invoice nochmal GETen um die Nummer zu bekommen
            $invoice_number = '';
            if ($finalize) {
                $invoice_details = $api->get_invoice($invoice_id);
                if (!\is_wp_error($invoice_details) && isset($invoice_details['voucherNumber'])) {
                    $invoice_number = $invoice_details['voucherNumber'];
                    \WC_Lexware_MVP_Logger::info('Retrieved invoice number from Lexware', array(
                        'order_id' => $order_id,
                        'invoice_id' => $invoice_id,
                        'invoice_number' => $invoice_number
                    ));
                } else {
                    \WC_Lexware_MVP_Logger::warning('Could not retrieve invoice number', array(
                        'order_id' => $order_id,
                        'invoice_id' => $invoice_id,
                        'error' => \is_wp_error($invoice_details) ? $invoice_details->get_error_message() : 'voucherNumber not in response'
                    ));
                }
            }

            $update_result = $wpdb->update($table, array(
                'document_id' => $invoice_id,
                'document_nr' => $invoice_number,
                'document_status' => 'synced',
                'document_finalized' => $finalize ? 1 : 0,
                'synced_at' => current_time('mysql')
            ), array('id' => $record->id));

            if ($update_result === false) {
                \WC_Lexware_MVP_Logger::error('Database update failed during invoice sync', array(
                    'order_id' => $order_id,
                    'document_id' => $invoice_id,
                    'document_nr' => $invoice_number,
                    'update_data' => array(
                        'document_status' => 'synced',
                        'document_finalized' => $finalize,
                        'synced_at' => current_time('mysql'),
                    ),
                    'wpdb_last_error' => $wpdb->last_error,
                    'wpdb_last_query' => $wpdb->last_query,
                    'table' => $table,
                    'record_id' => $record->id,
                    'transaction_active' => $wpdb->get_var("SELECT @@in_transaction"),
                    'innodb_lock_wait_timeout' => $wpdb->get_var("SELECT @@innodb_lock_wait_timeout"),
                ));
                throw new \Exception('Database update failed: ' . $wpdb->last_error);
            }

            // Commit Transaction
            $wpdb->query('COMMIT');

            // Post-Success Actions (außerhalb Transaction)
            $order->add_order_note(
                sprintf(
                    __('Lexware Rechnung erstellt: %s (ID: %s)', WC_LEXWARE_MVP_TEXT_DOMAIN),
                    $invoice_number,
                    $invoice_id
                )
            );

            // PDF Download (always when finalized)
            $pdf_filename = null;
            if ($finalize) {
                // Retry PDF download with exponential backoff (PDF generation is async in Lexware)
                $max_retries = 3;
                $retry_delays = array(2, 3, 5); // seconds between retries
                $pdf_result = null;

                for ($attempt = 0; $attempt < $max_retries; $attempt++) {
                    if ($attempt > 0) {
                        \WC_Lexware_MVP_Logger::info('PDF Download Retry', array('order_id' => $order_id, 'attempt' => $attempt + 1, 'delay' => $retry_delays[$attempt - 1]));
                        sleep($retry_delays[$attempt - 1]);
                    }

                    $pdf_result = $api->download_invoice_pdf($invoice_id);

                    // Success - break out of retry loop
                    if (!\is_wp_error($pdf_result)) {
                        break;
                    }

                    // If 409 Conflict, PDF is still being generated - retry
                    if ($pdf_result->get_error_code() === 'conflict') {
                        \WC_Lexware_MVP_Logger::info('PDF noch nicht verfügbar (409 Conflict)', array('order_id' => $order_id, 'attempt' => $attempt + 1));
                        continue;
                    }

                    // Other error - don't retry
                    break;
                }

                if (!\is_wp_error($pdf_result)) {
                    $pdf_handler = \WC_Lexware_MVP_PDF_Handler::get_instance();
                    $pdf_filename = $pdf_handler->save_pdf($pdf_result, $order_id, 'invoice', $invoice_number);

                    if ($pdf_filename) {
                        $wpdb->update($table, array('document_filename' => $pdf_filename), array('document_id' => $invoice_id, 'document_type' => 'invoice'));
                        \WC_Lexware_MVP_Logger::info('Invoice PDF gespeichert', array('order_id' => $order_id, 'filename' => $pdf_filename));
                    } else {
                        \WC_Lexware_MVP_Logger::warning('PDF konnte nicht gespeichert werden', array('order_id' => $order_id));
                        $order->add_order_note(__('⚠️ Rechnung erstellt, aber PDF-Download fehlgeschlagen', WC_LEXWARE_MVP_TEXT_DOMAIN));
                    }
                } else {
                    \WC_Lexware_MVP_Logger::error('PDF Download fehlgeschlagen', array('order_id' => $order_id, 'error' => $pdf_result->get_error_message(), 'attempts' => $attempt + 1));
                    $order->add_order_note(
                        sprintf(
                            __('⚠️ Rechnung erstellt, aber PDF-Download fehlgeschlagen: %s', WC_LEXWARE_MVP_TEXT_DOMAIN),
                            $pdf_result->get_error_message()
                        )
                    );
                }
            }

            // Get invoice record ID for email
            $invoice_record = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$table} WHERE document_id = %s AND document_type = 'invoice'",
                $invoice_id
            ));

            // DEBUG: Check all variables for email sending
            $email_option = get_option('wc_lexware_mvp_send_invoice_email', 'yes');
            \WC_Lexware_MVP_Logger::info('📧 Email sending check', array(
                'order_id' => $order_id,
                'email_option' => $email_option,
                'pdf_filename' => $pdf_filename,
                'invoice_record' => $invoice_record ? 'EXISTS (id=' . $invoice_record->id . ')' : 'NULL',
                'invoice_id' => $invoice_id
            ));

            // Send email if enabled and PDF available
            if ($email_option === 'yes' && $pdf_filename && $invoice_record) {
                \WC_Lexware_MVP_Logger::info('✉️ Email IF condition PASSED - attempting to load email class', array(
                    'order_id' => $order_id,
                    'woocommerce_loaded' => function_exists('WC'),
                    'wc_get_order_exists' => function_exists('wc_get_order'),
                    'WC_Email_class_exists' => class_exists('WC_Email'),
                    'php_version' => PHP_VERSION
                ));

                // CRITICAL FIX: Load email classes in Action Scheduler context
                // The woocommerce_email_classes filter doesn't run in async workers

                // STEP 1: Check if WC_Email is available (required for our email class)
                $wc_email_available = class_exists('WC_Email');

                if (!$wc_email_available) {
                    // Try to load WC_Email
                    $wc_email_path = null;

                    if (function_exists('WC') && WC()) {
                        $wc_email_path = WC()->plugin_path() . '/includes/emails/class-wc-email.php';
                    } else if (defined('WC_PLUGIN_FILE')) {
                        $wc_email_path = dirname(WC_PLUGIN_FILE) . '/includes/emails/class-wc-email.php';
                    }

                    if ($wc_email_path && file_exists($wc_email_path)) {
                        \WC_Lexware_MVP_Logger::info('✉️ Loading WC_Email parent class', array(
                            'order_id' => $order_id,
                            'wc_emails_path' => $wc_email_path
                        ));
                        require_once $wc_email_path;
                        $wc_email_available = class_exists('WC_Email');
                    } else {
                        \WC_Lexware_MVP_Logger::error('Cannot find WC_Email class file', array(
                            'order_id' => $order_id,
                            'wc_function_exists' => function_exists('WC'),
                            'wc_plugin_file_defined' => defined('WC_PLUGIN_FILE'),
                            'wc_email_path' => $wc_email_path
                        ));
                    }
                }

                // STEP 2: Decide which email method to use
                if (!$wc_email_available) {
                        \WC_Lexware_MVP_Logger::warning('⚠️ WC_Email not available - using wp_mail() fallback', array(
                            'order_id' => $order_id,
                            'wc_function_exists' => function_exists('WC'),
                            'wc_available' => function_exists('WC') && WC() !== null,
                            'wc_plugin_file_defined' => defined('WC_PLUGIN_FILE')
                        ));

                        // FALLBACK: Send email directly with wp_mail()
                        $order = wc_get_order($order_id);
                        if ($order) {
                            $to = $order->get_billing_email();
                            $subject = sprintf('Ihre Rechnung für Bestellung #%s', $order->get_order_number());
                            $pdf_path = WP_CONTENT_DIR . '/uploads/lexware-invoices/' . $pdf_filename;

                            $message = sprintf(
                                "Hallo %s,\n\nIm Anhang finden Sie Ihre Rechnung für Bestellung #%s.\n\nVielen Dank für Ihren Einkauf!\n\nMit freundlichen Grüßen\nIhr Team",
                                $order->get_billing_first_name(),
                                $order->get_order_number()
                            );

                            $headers = array(
                                'Content-Type: text/plain; charset=UTF-8',
                                'From: ' . get_option('woocommerce_email_from_name') . ' <' . get_option('woocommerce_email_from_address') . '>'
                            );

                            $sent = wp_mail($to, $subject, $message, $headers, array($pdf_path));

                            if ($sent) {
                                \WC_Lexware_MVP_Logger::info('✉️ Fallback email sent via wp_mail()', array(
                                    'order_id' => $order_id,
                                    'recipient' => $to,
                                    'pdf_attached' => file_exists($pdf_path)
                                ));

                                // Update email_sent_at
                                $wpdb->update(
                                    $table,
                                    array('email_sent_at' => current_time('mysql')),
                                    array('id' => $invoice_record->id),
                                    array('%s'),
                                    array('%d')
                                );
                            } else {
                                \WC_Lexware_MVP_Logger::error('❌ Fallback email failed', array(
                                    'order_id' => $order_id,
                                    'recipient' => $to
                                ));
                            }
                        }

                } else {
                    // WC_Email is available - use WC_Email system
                    // CRITICAL: Check if hook is registered, NOT just if class exists
                    // Class might be autoloaded but never instantiated (no hooks registered)

                    $hook_registered = has_action('wc_lexware_mvp_invoice_ready');

                    if (!$hook_registered) {
                        \WC_Lexware_MVP_Logger::info('✉️ Hook not registered - loading and instantiating email class', array(
                            'order_id' => $order_id,
                            'class_exists' => class_exists('WC_Lexware_MVP\\Email\\Invoice') ? 'YES' : 'NO'
                        ));

                        if (!class_exists('WC_Lexware_MVP\\Email\\Invoice')) {
                            require_once dirname(plugin_dir_path(__FILE__)) . '/Email/class-invoice.php';
                        }

                        // ALWAYS instantiate to register hooks (even if class was already loaded)
                        new \WC_Lexware_MVP\Email\Invoice();
                        \WC_Lexware_MVP_Logger::info('✉️ Email class instantiated - hooks registered');
                    } else {
                        \WC_Lexware_MVP_Logger::info('✉️ Hook already registered', array(
                            'order_id' => $order_id,
                            'priority' => $hook_registered
                        ));
                    }

                    // Trigger the email via WC_Email system
                    \WC_Lexware_MVP_Logger::info('🚀 BEFORE do_action - Triggering invoice email via WC_Email', array(
                        'order_id' => $order_id,
                        'invoice_record_id' => $invoice_record->id,
                        'pdf_filename' => $pdf_filename,
                        'has_filter' => has_filter('wc_lexware_mvp_invoice_ready') ? 'YES' : 'NO',
                        'hook_callbacks' => did_action('wc_lexware_mvp_invoice_ready')
                    ));

                    do_action('wc_lexware_mvp_invoice_ready', $order_id, $invoice_record->id, $pdf_filename);

                    \WC_Lexware_MVP_Logger::info('✅ AFTER do_action - Invoice email hook fired', array(
                        'order_id' => $order_id,
                        'did_action_count' => did_action('wc_lexware_mvp_invoice_ready')
                    ));

                    // NOTE: email_sent_at will be updated by Email\Invoice::trigger() after successful send
                    // Do NOT update here - we need to wait for confirmation that email was actually sent
                }
            } elseif (!$pdf_filename) {
                \WC_Lexware_MVP_Logger::warning('Email not sent - PDF not available', array('order_id' => $order_id));
            } else {
                \WC_Lexware_MVP_Logger::warning('Email not sent - conditions not met', array(
                    'order_id' => $order_id,
                    'email_option' => $email_option,
                    'email_option_strict_check' => $email_option === 'yes' ? 'YES' : 'NO',
                    'pdf_filename_check' => $pdf_filename ? 'YES' : 'NO',
                    'invoice_record_check' => $invoice_record ? 'YES' : 'NO'
                ));
            }

        } catch (Exception $e) {
            // Rollback bei Fehler
            $wpdb->query('ROLLBACK');

            \WC_Lexware_MVP_Logger::error('Invoice creation failed', array(
                'order_id' => $order_id,
                'order_status' => $order->get_status(),
                'order_total' => $order->get_total(),
                'currency' => $order->get_currency(),
                'customer_id' => $order->get_customer_id(),
                'error' => $e->getMessage(),
                'error_code' => $e->getCode()
            ));

            // Mark als failed (AFTER rollback in separate operation to prevent rollback of status)
            $wpdb->update($table, array(
                'document_status' => 'failed'
            ), array('order_id' => $order_id, 'document_type' => 'invoice'));

            $order->add_order_note(
                sprintf(
                    __('Fehler beim Erstellen der Rechnung: %s', WC_LEXWARE_MVP_TEXT_DOMAIN),
                    $e->getMessage()
                )
            );

            // Re-throw für Action Scheduler Retry
            throw $e;
        }
    }

    /**
     * Create credit note in Lexware
     * Uses Database Transactions to ensure ACID guarantees
     */
    private static function create_credit_note($order_id, $refund_id) {
        global $wpdb;
        $table = wc_lexware_mvp_get_table_name(false);

        $order = wc_get_order($order_id);
        $refund = wc_get_order($refund_id);

        if (!$order || !$refund) {
            \WC_Lexware_MVP_Logger::error('Credit Note: Order oder Refund nicht gefunden', array(
                'order_id' => $order_id,
                'refund_id' => $refund_id
            ));
            return;
        }

        // ✅ IDEMPOTENCY CHECK - Prevents duplicate credit notes on retries (unique per refund)
        $operation = 'create_credit_note_' . $refund_id;
        if (\WC_Lexware_MVP_Idempotency_Manager::is_completed($order_id, $operation)) {
            \WC_Lexware_MVP_Logger::info('Credit note creation already completed, skipping', array(
                'order_id' => $order_id,
                'refund_id' => $refund_id
            ));
            $order->add_order_note(__('⚠️ Gutschrift-Erstellung bereits abgeschlossen (Idempotency Check)', WC_LEXWARE_MVP_TEXT_DOMAIN));
            return;
        }

        // Validate currency - Lexware only supports EUR
        if ($order->get_currency() !== 'EUR') {
            $error_msg = sprintf(
                __('Order currency %s is not supported. Only EUR is accepted by Lexware.', WC_LEXWARE_MVP_TEXT_DOMAIN),
                $order->get_currency()
            );
            \WC_Lexware_MVP_Logger::error('Credit Note: Currency validation failed', array(
                'order_id' => $order_id,
                'currency' => $order->get_currency()
            ));
            $order->add_order_note('❌ Gutschrift: ' . $error_msg);
            throw new \Exception($error_msg);
        }

        // Start Database Transaction
        $wpdb->query('START TRANSACTION');

        try {
            // Lock Record with FOR UPDATE (Row-Level Lock)
            $record = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM `{$table}`
                 WHERE order_id = %d AND refund_id = %d AND document_type = 'credit_note'
                 FOR UPDATE",
                $order_id,
                $refund_id
            ));

            // Allow 'pending' or 'pending_retry' (for retry attempts)
            if (!$record || !in_array($record->document_status, array('pending', 'pending_retry'))) {
                throw new \Exception('Invalid record state: ' . ($record ? $record->document_status : 'not found'));
            }

            // Prüfe ob preceding_document_id gesetzt ist (Invoice ID)
            if (empty($record->preceding_document_id)) {
                // Fallback: Suche Invoice Record
                $invoice_record = self::get_invoice_record($order_id);
                if (!$invoice_record || empty($invoice_record->document_id)) {
                    throw new \Exception('No invoice found for credit note');
                }
                $invoice_id = $invoice_record->document_id;
                $invoice_number = $invoice_record->document_nr;
            } else {
                // Verwende gespeicherte Invoice ID
                $invoice_id = $record->preceding_document_id;
                // Hole Invoice Number falls vorhanden
                $invoice_number = $wpdb->get_var($wpdb->prepare(
                    "SELECT document_nr FROM `{$table}` WHERE document_id = %s AND document_type = 'invoice' LIMIT 1",
                    $invoice_id
                ));
            }

            // Get or create contact first (needed for contactId reference)
            $cn_api = new \WC_Lexware_MVP_API_Client();
            $contact_id = self::get_or_create_contact($cn_api, $order);

            if (!$contact_id) {
                throw new \Exception(__('Contact creation or retrieval failed for credit note', WC_LEXWARE_MVP_TEXT_DOMAIN));
            }

            // Baue Credit Note Line Items
            $line_items_result = self::build_credit_note_line_items($refund, $order);
            $line_items = $line_items_result['items'];
            $is_amount_only_refund = $line_items_result['is_amount_only'];

            if (empty($line_items)) {
                throw new \Exception('No line items generated for credit note');
            }

            \WC_Lexware_MVP_Logger::debug('Credit note line items built', array(
                'order_id' => $order_id,
                'refund_id' => $refund_id,
                'line_item_count' => count($line_items),
                'is_amount_only' => $is_amount_only_refund
            ));

            // Determine document-level taxType for credit note
            // INTELLIGENTE REGEL: Wenn IRGENDEIN Item MwSt hat → taxType='gross'
            $cn_has_any_tax = self::order_has_any_tax($order); // Prüfe Original Order
            $cn_tax_type = $cn_has_any_tax ? 'gross' : 'net';

            \WC_Lexware_MVP_Logger::debug('Credit note tax type determined', array(
                'order_id' => $order_id,
                'refund_id' => $refund_id,
                'has_any_tax' => $cn_has_any_tax,
                'tax_type' => $cn_tax_type
            ));

            // Build address name: Prioritize company name for B2B orders
            $cn_company = $order->get_billing_company();
            $cn_address_name = !empty($cn_company)
                ? $cn_company
                : trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());

            // Build order metadata for remark field
            $cn_order_prefix = get_option('wc_lexware_mvp_order_prefix', '');
            $cn_order_number = !empty($cn_order_prefix) ? $cn_order_prefix . $order_id : (string)$order_id;
            $cn_order_date = $order->get_date_created()->date_i18n('d.m.Y');
            $cn_payment_method = $order->get_payment_method_title();
            if (empty($cn_payment_method)) {
                $cn_payment_method = $order->get_payment_method();
            }

            // Build comprehensive remark with invoice reference and order metadata
            $remark_parts = array(
                sprintf('Rechnungsreferenz: %s', $invoice_number),
                sprintf('Bestellnummer: %s', $cn_order_number),
                sprintf('Bestelldatum: %s', $cn_order_date),
                sprintf('Zahlungsart: %s', $cn_payment_method)
            );

            // Optional: Append refund reason if provided
            if ($refund->get_reason()) {
                $remark_parts[] = '';
                $remark_parts[] = 'Erstattungsgrund: ' . $refund->get_reason();
            }

            // Baue Credit Note Payload
            $credit_note_data = array(
                'archived' => false,  // Dokument ist aktiv (nicht archiviert)
                'contactId' => $contact_id,  // Top-Level contact reference
                'voucherDate' => $refund->get_date_created()->date('Y-m-d\TH:i:s.v\Z'),
                'title' => __('Gutschrift', WC_LEXWARE_MVP_TEXT_DOMAIN),  // Dokumenttitel (max 25 Zeichen)
                'address' => array(
                    'contactId' => $contact_id,  // Contact reference in address
                    'name' => $cn_address_name,  // Firma oder Name (B2B-kompatibel)
                    'street' => $order->get_billing_address_1(),
                    'zip' => $order->get_billing_postcode(),
                    'city' => $order->get_billing_city(),
                    'countryCode' => $order->get_billing_country(),
                ),
                'lineItems' => $line_items,
                'totalPrice' => array(
                    'currency' => 'EUR'
                ),
                'taxConditions' => array(
                    'taxType' => $cn_tax_type // DYNAMISCH: gross wenn Tax vorhanden, sonst net
                ),
                'introduction' => '',  // Leer (Invoice-Referenz ist im remark)
                'remark' => implode("\n", $remark_parts)
            );

            // Log das komplette Payload für Debugging
            \WC_Lexware_MVP_Logger::info('Creating credit note with payload', array(
                'order_id' => $order->get_id(),
                'refund_id' => $refund->get_id(),
                'invoice_id' => $send_standalone ? 'NONE (standalone)' : $invoice_id,
                'payload' => $credit_note_data,
                'is_amount_only' => $is_amount_only_refund,
                'is_full_refund' => $is_full_refund,
                'send_standalone' => $send_standalone,
                'finalize' => get_option('wc_lexware_mvp_auto_finalize_credit_note', 'yes') === 'yes'
            ));

            // Erstelle Credit Note in Lexware
            // Standalone (OHNE precedingSalesVoucherId) wenn:
            // - Amount-only Refund, ODER
            // - Voll-Erstattung (Refund-Betrag == Order-Total) — Lexware 406 bei Full Refund mit Reference
            $finalize = get_option('wc_lexware_mvp_auto_finalize_credit_note', 'yes') === 'yes';
            $is_full_refund = abs($refund->get_amount()) >= $order->get_total();
            $send_standalone = $is_amount_only_refund || $is_full_refund;
            $preceding_invoice_id = $send_standalone ? null : $invoice_id;
            $start_time_cn = microtime(true);
            $result = $cn_api->create_credit_note($credit_note_data, $finalize, $preceding_invoice_id, $order_id, $refund_id); // ← order_id + refund_id für Idempotency

            if (\is_wp_error($result)) {
                \WC_Lexware_MVP_Logger::error('Credit note creation failed - API returned error', array(
                    'order_id' => $order_id,
                    'refund_id' => $refund_id,
                    'credit_note_payload' => $credit_note_data, // Wird GDPR-compliant redacted
                    'api_error_code' => $result->get_error_code(),
                    'api_error_message' => $result->get_error_message(),
                    'api_error_data' => $result->get_error_data(),
                    'preceding_invoice_id' => $preceding_invoice_id,
                    'preceding_invoice_nr' => $invoice_number ?? null,
                    'is_amount_only_refund' => $is_amount_only_refund,
                    'line_items_count' => count($credit_note_data['lineItems']),
                    'refund_amount' => $refund->get_amount(),
                    'finalize_requested' => $finalize,
                    'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                    'time_elapsed_ms' => round((microtime(true) - $start_time_cn) * 1000, 2),
                ));
                throw new \Exception($result->get_error_message());
            }

            // ✅ MARK AS COMPLETED - Prevents re-execution on retries
            $operation = 'create_credit_note_' . $refund_id;
            \WC_Lexware_MVP_Idempotency_Manager::mark_completed($order_id, $operation);

            // Update DB mit Credit Note ID und preceding_document_id
            $credit_note_id = $result['id'];
            $credit_note_number = isset($result['voucherNumber']) ? $result['voucherNumber'] : '';

            $update_result = $wpdb->update($table, array(
                'document_id' => $credit_note_id,
                'document_nr' => $credit_note_number,
                'preceding_document_id' => $invoice_id,
                'preceding_document_nr' => $invoice_number,
                'document_status' => 'synced',
                'document_finalized' => $finalize ? 1 : 0,
                'synced_at' => current_time('mysql')
            ), array('id' => $record->id));

            if ($update_result === false) {
                \WC_Lexware_MVP_Logger::error('Database update failed during credit note sync', array(
                    'order_id' => $order_id,
                    'refund_id' => $refund_id,
                    'document_id' => $credit_note_id,
                    'document_nr' => $credit_note_number,
                    'preceding_document_id' => $invoice_id,
                    'update_data' => array(
                        'document_status' => 'synced',
                        'document_finalized' => $finalize,
                        'synced_at' => current_time('mysql'),
                    ),
                    'wpdb_last_error' => $wpdb->last_error,
                    'wpdb_last_query' => $wpdb->last_query,
                    'table' => $table,
                    'record_id' => $record->id,
                    'transaction_active' => $wpdb->get_var("SELECT @@in_transaction"),
                ));
                throw new \Exception('Database update failed: ' . $wpdb->last_error);
            }

            // Commit Transaction
            $wpdb->query('COMMIT');

            // Post-Success Actions (außerhalb Transaction)
            $order->add_order_note(
                sprintf(
                    __('Lexware Gutschrift erstellt: %s (ID: %s)', WC_LEXWARE_MVP_TEXT_DOMAIN),
                    $credit_note_number,
                    $credit_note_id
                )
            );

            // Markiere Refund als verarbeitet (atomic update to prevent race conditions)
            $max_retries = 3;
            $retry_count = 0;
            $update_success = false;

            while (!$update_success && $retry_count < $max_retries) {
                $processed_refunds = $order->get_meta('_lexware_processed_refund_ids', true);
                if (!is_array($processed_refunds)) {
                    $processed_refunds = array();
                }

                // Check if already processed (another process might have updated it)
                if (in_array($refund_id, $processed_refunds)) {
                    $update_success = true;
                    break;
                }

                $old_value = $processed_refunds;
                $processed_refunds[] = $refund_id;

                // Atomic update with old value check
                global $wpdb;
                $result = $wpdb->update(
                    $wpdb->prefix . 'wc_orders_meta',
                    array('meta_value' => maybe_serialize($processed_refunds)),
                    array(
                        'order_id' => $order->get_id(),
                        'meta_key' => '_lexware_processed_refund_ids',
                        'meta_value' => maybe_serialize($old_value)
                    ),
                    array('%s'),
                    array('%d', '%s', '%s')
                );

                if ($result !== false && $result > 0) {
                    $update_success = true;
                } else {
                    $retry_count++;
                    usleep(100000); // Wait 100ms before retry
                }
            }

            if (!$update_success) {
                \WC_Lexware_MVP_Logger::warning('Failed to update processed refund IDs atomically', array(
                    'order_id' => $order->get_id(),
                    'refund_id' => $refund_id,
                    'retries' => $retry_count
                ));
            }

            // PDF Download (always when finalized)
            if ($finalize) {
                // Retry PDF download with exponential backoff (PDF generation is async in Lexware)
                $max_retries = 3;
                $retry_delays = array(2, 3, 5); // seconds between retries
                $pdf_result = null;

                for ($attempt = 0; $attempt < $max_retries; $attempt++) {
                    if ($attempt > 0) {
                        \WC_Lexware_MVP_Logger::info('Credit Note PDF Download Retry', array('order_id' => $order_id, 'attempt' => $attempt + 1, 'delay' => $retry_delays[$attempt - 1]));
                        sleep($retry_delays[$attempt - 1]);
                    }

                    $pdf_result = $cn_api->download_credit_note_pdf($credit_note_id);

                    // Success - break out of retry loop
                    if (!\is_wp_error($pdf_result)) {
                        break;
                    }

                    // If 409 Conflict, PDF is still being generated - retry
                    if ($pdf_result->get_error_code() === 'conflict') {
                        \WC_Lexware_MVP_Logger::info('Credit Note PDF noch nicht verfügbar (409 Conflict)', array('order_id' => $order_id, 'attempt' => $attempt + 1));
                        continue;
                    }

                    // Other error - don't retry
                    break;
                }

                if (!\is_wp_error($pdf_result)) {
                    $pdf_handler = \WC_Lexware_MVP_PDF_Handler::get_instance();
                    $pdf_filename = $pdf_handler->save_pdf($pdf_result, $order_id, 'credit_note', $credit_note_number);

                    if ($pdf_filename) {
                        $wpdb->update($table,
                            array('document_filename' => $pdf_filename),
                            array('document_id' => $credit_note_id, 'document_type' => 'credit_note')
                        );

                        \WC_Lexware_MVP_Logger::info('Credit Note PDF gespeichert', array(
                            'order_id' => $order_id,
                            'filename' => $pdf_filename
                        ));

                        // E-Mail senden wenn aktiviert
                        if (get_option('wc_lexware_mvp_send_credit_note_email', 'no') === 'yes') {
                            $cn_record = $wpdb->get_row($wpdb->prepare(
                                "SELECT id FROM {$table} WHERE document_id = %s AND document_type = 'credit_note'",
                                $credit_note_id
                            ));

                            if ($cn_record) {
                                do_action('wc_lexware_mvp_send_credit_note_email', $order_id, $cn_record->id, $pdf_filename);

                                // Update email_sent_at timestamp
                                $wpdb->update(
                                    $table,
                                    array('email_sent_at' => current_time('mysql')),
                                    array('id' => $cn_record->id),
                                    array('%s'),
                                    array('%d')
                                );
                            }
                        }
                    } else {
                        \WC_Lexware_MVP_Logger::warning('Credit Note PDF konnte nicht gespeichert werden', array('order_id' => $order_id));
                    }
                } else {
                    \WC_Lexware_MVP_Logger::error('Credit Note PDF Download fehlgeschlagen', array('order_id' => $order_id, 'error' => $pdf_result->get_error_message(), 'attempts' => $attempt + 1));
                }
            }

        } catch (Exception $e) {
            // Rollback bei Fehler
            $wpdb->query('ROLLBACK');

            \WC_Lexware_MVP_Logger::error('Credit Note creation failed', array(
                'order_id' => $order_id,
                'refund_id' => $refund_id,
                'order_status' => $order->get_status(),
                'refund_amount' => $refund->get_amount(),
                'currency' => $order->get_currency(),
                'customer_id' => $order->get_customer_id(),
                'error' => $e->getMessage(),
                'error_code' => $e->getCode()
            ));

            // Mark als failed (AFTER rollback in separate operation to prevent rollback of status)
            $wpdb->update($table, array(
                'document_status' => 'failed'
            ), array('order_id' => $order_id, 'refund_id' => $refund_id, 'document_type' => 'credit_note'));

            $order->add_order_note(
                sprintf(
                    __('Fehler beim Erstellen der Gutschrift: %s', WC_LEXWARE_MVP_TEXT_DOMAIN),
                    $e->getMessage()
                )
            );

            // Re-throw für Action Scheduler Retry
            throw $e;
        }
    }

    /**
     * Create full credit note in Lexware (without WC-Refund)
     *
     * Used when order status is manually set to "refunded" without creating a WC refund.
     * Creates a full credit note for the entire order amount using all line items.
     *
     * @since 1.3.1
     * @param int $order_id WooCommerce Order ID
     * @param string $invoice_id Lexware Invoice Document ID
     */
    private static function create_full_credit_note($order_id, $invoice_id) {
        global $wpdb;
        $table = wc_lexware_mvp_get_table_name(false);

        $order = wc_get_order($order_id);
        if (!$order) {
            \WC_Lexware_MVP_Logger::error('Full Credit Note: Order nicht gefunden', array('order_id' => $order_id));
            return;
        }

        // Idempotency Check
        $operation = 'create_full_credit_note_' . $order_id;
        if (\WC_Lexware_MVP_Idempotency_Manager::is_completed($order_id, $operation)) {
            \WC_Lexware_MVP_Logger::info('Full credit note creation already completed, skipping', array('order_id' => $order_id));
            return;
        }

        // Validate currency
        if ($order->get_currency() !== 'EUR') {
            throw new \Exception(__('Only EUR currency is supported by Lexware', WC_LEXWARE_MVP_TEXT_DOMAIN));
        }

        $wpdb->query('START TRANSACTION');

        try {
            // Lock Record
            $record = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM `{$table}`
                 WHERE order_id = %d
                 AND document_type = 'credit_note'
                 AND refund_id IS NULL
                 AND refund_full = 1
                 FOR UPDATE",
                $order_id
            ));

            if (!$record || !in_array($record->document_status, array('pending', 'pending_retry'))) {
                throw new \Exception('Invalid record state: ' . ($record ? $record->document_status : 'not found'));
            }

            // Get invoice number
            $invoice_number = $wpdb->get_var($wpdb->prepare(
                "SELECT document_nr FROM `{$table}` WHERE document_id = %s AND document_type = 'invoice' LIMIT 1",
                $invoice_id
            ));

            // Get or create contact
            $api = new \WC_Lexware_MVP_API_Client();
            $contact_id = self::get_or_create_contact($api, $order);

            if (!$contact_id) {
                throw new \Exception(__('Contact creation or retrieval failed', WC_LEXWARE_MVP_TEXT_DOMAIN));
            }

            // Build line items from order (all items as credit)
            $line_items = self::build_full_credit_note_line_items($order);

            if (empty($line_items)) {
                throw new \Exception('No line items generated for full credit note');
            }

            // Determine tax type
            $has_any_tax = self::order_has_any_tax($order);
            $tax_type = $has_any_tax ? 'gross' : 'net';

            // Build address name
            $company = $order->get_billing_company();
            $address_name = !empty($company)
                ? $company
                : trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());

            // Build remark
            $order_prefix = get_option('wc_lexware_mvp_order_prefix', '');
            $order_number = !empty($order_prefix) ? $order_prefix . $order_id : (string)$order_id;
            $remark_parts = array(
                sprintf('Rechnungsreferenz: %s', $invoice_number),
                sprintf('Bestellnummer: %s', $order_number),
                sprintf('Bestelldatum: %s', $order->get_date_created()->date_i18n('d.m.Y')),
                '',
                __('Vollständige Erstattung (Status: Refunded)', WC_LEXWARE_MVP_TEXT_DOMAIN)
            );

            // Build payload
            $credit_note_data = array(
                'archived' => false,
                'contactId' => $contact_id,
                'voucherDate' => current_time('Y-m-d\TH:i:s.v\Z'), // Aktuelles Datum für Voll-Gutschrift
                'title' => __('Gutschrift', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'address' => array(
                    'contactId' => $contact_id,
                    'name' => $address_name,
                    'street' => $order->get_billing_address_1(),
                    'zip' => $order->get_billing_postcode(),
                    'city' => $order->get_billing_city(),
                    'countryCode' => $order->get_billing_country(),
                ),
                'lineItems' => $line_items,
                'totalPrice' => array('currency' => 'EUR'),
                'taxConditions' => array('taxType' => $tax_type),
                'introduction' => '',
                'remark' => implode("\n", $remark_parts)
            );

            \WC_Lexware_MVP_Logger::info('Creating full credit note', array(
                'order_id' => $order_id,
                'invoice_id' => $invoice_id,
                'order_total' => $order->get_total()
            ));

            // Create in Lexware
            $finalize = get_option('wc_lexware_mvp_auto_finalize_credit_note', 'yes') === 'yes';
            $result = $api->create_credit_note($credit_note_data, $finalize, $invoice_id);

            if (\is_wp_error($result)) {
                \WC_Lexware_MVP_Logger::error('Full credit note creation failed', array(
                    'order_id' => $order_id,
                    'error' => $result->get_error_message()
                ));
                throw new \Exception($result->get_error_message());
            }

            // Mark as completed
            \WC_Lexware_MVP_Idempotency_Manager::mark_completed($order_id, $operation);

            // Update DB
            $credit_note_id = $result['id'];
            $credit_note_number = isset($result['voucherNumber']) ? $result['voucherNumber'] : '';

            $wpdb->update($table, array(
                'document_id' => $credit_note_id,
                'document_nr' => $credit_note_number,
                'preceding_document_id' => $invoice_id,
                'preceding_document_nr' => $invoice_number,
                'document_status' => 'synced',
                'document_finalized' => $finalize ? 1 : 0,
                'synced_at' => current_time('mysql')
            ), array('id' => $record->id));

            $wpdb->query('COMMIT');

            $order->add_order_note(
                sprintf(
                    __('Lexware Voll-Gutschrift erstellt: %s (ID: %s)', WC_LEXWARE_MVP_TEXT_DOMAIN),
                    $credit_note_number,
                    $credit_note_id
                )
            );

            // PDF Download
            if ($finalize) {
                $max_retries = 3;
                $retry_delays = array(2, 3, 5);
                $pdf_result = null;

                for ($attempt = 0; $attempt < $max_retries; $attempt++) {
                    if ($attempt > 0) {
                        sleep($retry_delays[$attempt - 1]);
                    }
                    $pdf_result = $api->download_credit_note_pdf($credit_note_id);
                    if (!\is_wp_error($pdf_result)) {
                        break;
                    }
                    if ($pdf_result->get_error_code() !== 'conflict') {
                        break;
                    }
                }

                if (!\is_wp_error($pdf_result)) {
                    $pdf_handler = \WC_Lexware_MVP_PDF_Handler::get_instance();
                    $pdf_filename = $pdf_handler->save_pdf($pdf_result, $order_id, 'credit_note', $credit_note_number);

                    if ($pdf_filename) {
                        $wpdb->update($table,
                            array('document_filename' => $pdf_filename),
                            array('document_id' => $credit_note_id, 'document_type' => 'credit_note')
                        );

                        // Send email if enabled
                        if (get_option('wc_lexware_mvp_send_credit_note_email', 'no') === 'yes') {
                            $cn_record = $wpdb->get_row($wpdb->prepare(
                                "SELECT id FROM {$table} WHERE document_id = %s AND document_type = 'credit_note'",
                                $credit_note_id
                            ));
                            if ($cn_record) {
                                do_action('wc_lexware_mvp_send_credit_note_email', $order_id, $cn_record->id, $pdf_filename);
                                $wpdb->update($table,
                                    array('email_sent_at' => current_time('mysql')),
                                    array('id' => $cn_record->id)
                                );
                            }
                        }
                    }
                }
            }

        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');

            \WC_Lexware_MVP_Logger::error('Full Credit Note creation failed', array(
                'order_id' => $order_id,
                'error' => $e->getMessage()
            ));

            $wpdb->update($table, array(
                'document_status' => 'failed'
            ), array('order_id' => $order_id, 'refund_id' => null, 'refund_full' => 1, 'document_type' => 'credit_note'));

            $order->add_order_note(
                sprintf(__('Fehler bei Voll-Gutschrift: %s', WC_LEXWARE_MVP_TEXT_DOMAIN), $e->getMessage())
            );

            throw $e;
        }
    }

    /**
     * Build line items for full credit note (all order items)
     *
     * @since 1.3.1
     * @param WC_Order $order
     * @return array Line items for Lexware API
     */
    private static function build_full_credit_note_line_items($order) {
        $line_items = array();

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $quantity = $item->get_quantity();

            if ($quantity <= 0) {
                continue;
            }

            // WooCommerce-Werte direkt verwenden (keine Neuberechnung!)
            $line_total = (float) $item->get_total();
            $line_tax = (float) $item->get_total_tax();
            $line_gross = $line_total + $line_tax;

            // Tax Rate als INTEGER direkt aus WC-Werten
            $tax_rate = $line_total > 0 ? round(($line_tax / $line_total) * 100, 0) : 0;
            $has_tax = $tax_rate > 0;

            if ($has_tax) {
                $amount = round($line_gross / $quantity, 2);
                $price_type = 'grossAmount';
            } else {
                $amount = round($line_total / $quantity, 2);
                $price_type = 'netAmount';
            }

            $description = '';
            if ($product) {
                $description = self::extract_item_meta_description($item, $product);
            }

            $line_items[] = array(
                'type' => 'custom',
                'name' => $item->get_name(),
                'description' => $description,
                'quantity' => $quantity,
                'unitName' => 'Stück',
                'unitPrice' => array(
                    'currency' => 'EUR',
                    $price_type => $amount,
                    'taxRatePercentage' => $tax_rate
                )
            );
        }

        // Shipping
        $shipping_total = (float) $order->get_shipping_total();
        $shipping_tax = (float) $order->get_shipping_tax();

        if ($shipping_total > 0) {
            $shipping_gross = $shipping_total + $shipping_tax;
            $shipping_tax_rate = round(($shipping_tax / $shipping_total) * 100, 0);
            $has_tax = $shipping_tax_rate > 0;

            $line_items[] = array(
                'type' => 'custom',
                'name' => __('Versandkosten', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'quantity' => 1,
                'unitName' => 'Pauschale',
                'unitPrice' => array(
                    'currency' => 'EUR',
                    ($has_tax ? 'grossAmount' : 'netAmount') => $has_tax ? round($shipping_gross, 2) : round($shipping_total, 2),
                    'taxRatePercentage' => $shipping_tax_rate
                )
            );
        }

        return $line_items;
    }

    /**
     * Build invoice line items from order
     * With memory-efficient chunk processing for large orders
     */
    private static function build_invoice_line_items($order) {
        $line_items = array();

        // Memory check before processing
        $available_memory = self::get_available_memory();
        $order_item_count = count($order->get_items());

        \WC_Lexware_MVP_Logger::debug('Building invoice line items', array(
            'order_id' => $order->get_id(),
            'item_count' => $order_item_count,
            'available_memory_mb' => round($available_memory / 1024 / 1024, 2)
        ));

        // Hard limit: Lexware API can't handle more than 300 items
        if ($order_item_count > 300) {
            throw new \Exception(
                sprintf(
                    __('Order exceeds maximum limit of 300 items (current: %d). Please split the order or contact support.', WC_LEXWARE_MVP_TEXT_DOMAIN),
                    $order_item_count
                )
            );
        }

        // For very large orders (>500 items), warn about potential memory issues
        if ($order_item_count > 500) {
            \WC_Lexware_MVP_Logger::warning('Large order detected - potential memory concern', array(
                'order_id' => $order->get_id(),
                'item_count' => $order_item_count,
                'memory_limit' => ini_get('memory_limit')
            ));
        }

        // Produkte
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();

            if (!$product) {
                \WC_Lexware_MVP_Logger::error('Line item product not found - item will be skipped', array(
                    'order_id' => $order->get_id(),
                    'item_id' => $item->get_id(),
                    'item_name' => $item->get_name(),
                    'product_id' => $item->get_product_id(),
                    'variation_id' => $item->get_variation_id()
                ));
                continue;
            }

            $quantity = $item->get_quantity();

            // Guard against division by zero
            if ($quantity <= 0) {
                \WC_Lexware_MVP_Logger::warning('Skipping item with zero or negative quantity', array(
                    'order_id' => $order->get_id(),
                    'item_name' => $item->get_name(),
                    'quantity' => $quantity
                ));
                continue;
            }

            // Direkte WooCommerce-Werte
            $line_total = (float) $item->get_total();          // Netto
            $line_tax = (float) $item->get_total_tax();        // MwSt
            $line_gross = $line_total + $line_tax;             // Brutto

            // Unit-Preise: Division durch Menge
            $unit_price_net = $line_total / $quantity;
            $unit_tax = $line_tax / $quantity;
            $unit_gross = $line_gross / $quantity;

            // Steuersatz aus Tax / Netto - als INTEGER (Lexware erwartet 0, 7, 19)
            $tax_rate = $unit_price_net > 0 ? (($unit_tax / $unit_price_net) * 100) : 0;
            $tax_rate = round($tax_rate, 0); // Integer!

            // INTELLIGENTE REGEL:
            // - MIT MwSt (tax_rate > 0): Brutto-Wert übergeben → präziser!
            // - OHNE MwSt (tax_rate = 0): Netto-Wert übergeben
            $has_tax = $tax_rate > 0;

            if ($has_tax) {
                // MIT MwSt: Brutto direkt durchgeben (2 Dezimalstellen)
                $unit_price = round($unit_gross, 2);
                $price_type = 'grossAmount';

                \WC_Lexware_MVP_Logger::debug('📦 Invoice Line Item (Brutto-Ansatz)', array(
                    'item_name' => $item->get_name(),
                    'quantity' => $quantity,
                    'line_gross' => $line_gross,
                    'unit_gross' => $unit_price,
                    'tax_rate' => $tax_rate,
                    'reason' => 'MwSt vorhanden → Brutto durchgeben'
                ));
            } else {
                // OHNE MwSt: Netto durchgeben
                $unit_price = round($unit_price_net, 2);
                $price_type = 'netAmount';

                \WC_Lexware_MVP_Logger::debug('📦 Invoice Line Item (Netto-Ansatz)', array(
                    'item_name' => $item->get_name(),
                    'quantity' => $quantity,
                    'line_total' => $line_total,
                    'unit_net' => $unit_price,
                    'tax_rate' => $tax_rate,
                    'reason' => 'Keine MwSt → Netto durchgeben'
                ));
            }

            // Extract product meta data for description
            $description = self::extract_item_meta_description($item, $product);

            $line_items[] = array(
                'type' => 'custom',
                'name' => $item->get_name(),
                'description' => $description,
                'quantity' => $quantity,
                'unitName' => 'Stück',
                'unitPrice' => array(
                    'currency' => 'EUR',
                    $price_type => $unit_price, // ENTWEDER grossAmount ODER netAmount
                    'taxRatePercentage' => $tax_rate
                )
            );

            // Garbage collection hint every 100 items
            if (count($line_items) % 100 === 0) {
                gc_collect_cycles();
            }
        }

        // Versandkosten - EXAKT GLEICHE LOGIK WIE PRODUKTE
        if ($order->get_shipping_total() > 0) {
            $shipping_total = (float) $order->get_shipping_total();  // Netto
            $shipping_tax = (float) $order->get_shipping_tax();      // MwSt
            $shipping_gross = $shipping_total + $shipping_tax;       // Brutto

            // Steuersatz aus Tax / Netto - als INTEGER (Lexware erwartet 0, 7, 19)
            $tax_rate = $shipping_total > 0 ? (($shipping_tax / $shipping_total) * 100) : 0;
            $tax_rate = round($tax_rate, 0); // Integer!

            // INTELLIGENTE REGEL (IDENTISCH ZU PRODUKTEN):
            // - MIT MwSt (tax_rate > 0): Brutto-Wert übergeben → präziser!
            // - OHNE MwSt (tax_rate = 0): Netto-Wert übergeben
            $has_tax = $tax_rate > 0;

            if ($has_tax) {
                // MIT MwSt: Brutto direkt durchgeben (2 Dezimalstellen)
                $shipping_amount = round($shipping_gross, 2);
                $price_type = 'grossAmount';
            } else {
                // OHNE MwSt: Netto durchgeben
                $shipping_amount = round($shipping_total, 2);
                $price_type = 'netAmount';
            }

            // Get shipping method name for description
            $shipping_method = $order->get_shipping_method();
            $shipping_description = !empty($shipping_method) ? sanitize_text_field($shipping_method) : '';

            $line_items[] = array(
                'type' => 'custom',
                'name' => __('Versandkosten', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'description' => $shipping_description,
                'quantity' => 1,
                'unitName' => 'Pauschal',
                'unitPrice' => array(
                    'currency' => $order->get_currency(),
                    $price_type => $shipping_amount, // ENTWEDER grossAmount ODER netAmount
                    'taxRatePercentage' => $tax_rate
                )
            );
        }

        return $line_items;
    }

    /**
     * Get available memory for operations
     *
     * @return int Available memory in bytes
     */
    private static function get_available_memory() {
        $memory_limit = ini_get('memory_limit');

        // Unlimited memory
        if ($memory_limit === '-1') {
            return PHP_INT_MAX;
        }

        $limit_bytes = self::parse_memory_limit($memory_limit);
        $used_memory = memory_get_usage(true);

        // Reserve 20% buffer for safety
        $buffer = $limit_bytes * 0.2;
        return max(0, $limit_bytes - $used_memory - $buffer);
    }

    /**
     * Parse memory limit string to bytes
     *
     * @param string $limit Memory limit (e.g., "256M", "1G", "512K")
     * @return int Memory in bytes
     */
    private static function parse_memory_limit($limit) {
        $limit = trim($limit);
        $last_char = strtolower(substr($limit, -1));
        $value = (int) $limit;

        switch ($last_char) {
            case 'g':
                $value *= 1024 * 1024 * 1024;
                break;
            case 'm':
                $value *= 1024 * 1024;
                break;
            case 'k':
                $value *= 1024;
                break;
        }

        return $value;
    }

    /**
     * Extract product meta data for description field
     *
     * Extracts all visible (non-hidden) order item meta data and formats it
     * for display in Lexware document description field.
     *
     * Supports:
     * - WooCommerce Product Add-Ons (_pao_ids + visible keys)
     * - Custom Configurators (custom_configurator)
     * - Any other visible meta fields
     *
     * @param WC_Order_Item_Product $item Order item
     * @param WC_Product $product Product object
     * @return string Formatted description (max 2000 chars for Lexware API)
     */
    private static function extract_item_meta_description($item, $product) {
        $max_length = 2000; // Lexware API limit
        $detail_parts = array();
        $config_code = ''; // Store configuration code for end of description

        // 1. SKU - Highest priority (always included if available)
        if ($product && $product->get_sku()) {
            $detail_parts[] = 'Artikelnummer: ' . $product->get_sku();
        }

        // 2. Product Configuration - Multi-source fallback system
        // Supports multiple configurator formats:
        // - Priority 1: custom_configurator (custom theme format)
        // - Priority 2: _wapf_field_groups (WooCommerce Advanced Product Fields)
        // - Priority 3: _product_addons (WooCommerce Product Add-Ons)
        // - Priority 4: Direct meta fields (legacy/custom implementations)

        $config_data = self::extract_product_configuration($item);

        if (!empty($config_data['details'])) {
            $detail_parts = array_merge($detail_parts, $config_data['details']);
            $config_code = $config_data['code'] ?? '';
        }

        // 3. Combine all parts with " // " separator
        if (empty($detail_parts)) {
            // Fallback: Use short description if no meta data
            return $product ? substr($product->get_short_description(), 0, $max_length) : '';
        }

        // 4. Smart truncation: Always keep Code at the end
        $separator = ' // ';
        $ellipsis = '...';

        // Build code part if it exists (it's always at the end)
        $code_part = '';
        if (!empty($config_code)) {
            $code_part = $separator . 'Code: ' . $config_code;
        }

        $code_length = strlen($code_part);
        $available_space = $max_length - $code_length;

        // Build description from detail_parts
        $description = implode($separator, $detail_parts);

        // Check if we need to truncate
        if (strlen($description) > $available_space) {
            // SKU has highest priority - always keep it (first part)
            $reserved_space = strlen($ellipsis);

            if (!empty($detail_parts)) {
                // Start with SKU (first part)
                $result_parts = array($detail_parts[0]);
                $current_length = strlen($detail_parts[0]);

                // Add as many additional parts as possible
                for ($i = 1; $i < count($detail_parts); $i++) {
                    $part = $detail_parts[$i];
                    $new_length = $current_length + strlen($separator) + strlen($part);

                    // Check if we can fit this part + ellipsis + code
                    if ($new_length + $reserved_space + $code_length <= $max_length) {
                        $result_parts[] = $part;
                        $current_length = $new_length;
                    } else {
                        // Can't fit more parts
                        break;
                    }
                }

                // Build description with ellipsis if truncated
                $description = implode($separator, $result_parts);
                if (count($result_parts) < count($detail_parts)) {
                    $description .= $ellipsis;
                }
            } else {
                // Fallback: Simple truncation
                $description = substr($description, 0, $available_space - 3) . '...';
            }
        }

        // Always append code at the very end (guaranteed to be visible)
        $description .= $code_part;

        \WC_Lexware_MVP_Logger::debug('Extracted item meta description', array(
            'item_name' => $item->get_name(),
            'meta_count' => count($detail_parts),
            'description_length' => strlen($description),
            'truncated' => strlen($description) >= $max_length,
            'has_config_code' => !empty($config_code)
        ));

        return $description;
    }

    /**
     * Extract product configuration from order item meta
     *
     * Supports multiple configuration formats with intelligent fallbacks:
     * 1. custom_configurator - Custom theme configurator format
     * 2. _wapf_field_groups - WooCommerce Advanced Product Fields
     * 3. _product_addons - WooCommerce Product Add-Ons
     * 4. Direct meta fields - Legacy/custom implementations
     *
     * @since 1.0.0
     * @param WC_Order_Item_Product $item Order item
     * @return array Array with 'details' (array of strings) and 'code' (string)
     */
    private static function extract_product_configuration($item) {
        $details = array();
        $code = '';

        // ═════════════════════════════════════════════════════════════════════
        // STRATEGY 1: custom_configurator (Custom Theme Format)
        // ═════════════════════════════════════════════════════════════════════
        $custom_configurator = $item->get_meta('custom_configurator', true);

        if (!empty($custom_configurator) && is_array($custom_configurator)) {
            $skip_keys = array('original_price', 'additional_price', 'auto_generated_code', 'config_url');
            $skip_types = array('price', 'pricematrix');

            // Extract config code
            if (isset($custom_configurator['auto_generated_code'])) {
                $code = $custom_configurator['auto_generated_code'];
            }

            // Process each configurator option
            foreach ($custom_configurator as $option_key => $option_data) {
                if (in_array($option_key, $skip_keys, true) || !is_array($option_data)) {
                    continue;
                }

                if (!empty($option_data['type']) && in_array($option_data['type'], $skip_types, true)) {
                    continue;
                }

                $display_label = $option_data['display_label'] ?? $option_data['label'] ?? '';
                $display_value = $option_data['display_value'] ?? $option_data['value'] ?? '';

                if (!empty($display_label) && !empty($display_value)) {
                    $details[] = sprintf('%s: %s', $display_label, $display_value);
                }
            }

            if (!empty($details)) {
                return array('details' => $details, 'code' => $code);
            }
        }

        // ═════════════════════════════════════════════════════════════════════
        // STRATEGY 2: WAPF (WooCommerce Advanced Product Fields)
        // ═════════════════════════════════════════════════════════════════════
        $wapf_data = $item->get_meta('_wapf_item_meta', true);

        if (!empty($wapf_data) && is_array($wapf_data)) {
            foreach ($wapf_data as $field_data) {
                if (!is_array($field_data)) {
                    continue;
                }

                $label = $field_data['label'] ?? $field_data['name'] ?? '';
                $value = $field_data['value'] ?? $field_data['values'] ?? '';

                // Handle array values
                if (is_array($value)) {
                    $value = implode(', ', array_filter($value));
                }

                if (!empty($label) && !empty($value)) {
                    $details[] = sprintf('%s: %s', $label, $value);
                }
            }

            if (!empty($details)) {
                return array('details' => $details, 'code' => $code);
            }
        }

        // ═════════════════════════════════════════════════════════════════════
        // STRATEGY 3: Product Add-Ons (Official WooCommerce)
        // ═════════════════════════════════════════════════════════════════════
        $addon_data = $item->get_meta('_product_addons', true);

        if (!empty($addon_data) && is_array($addon_data)) {
            foreach ($addon_data as $addon) {
                if (!is_array($addon)) {
                    continue;
                }

                $name = $addon['name'] ?? $addon['field_name'] ?? '';
                $value = $addon['value'] ?? '';

                if (!empty($name) && !empty($value)) {
                    $details[] = sprintf('%s: %s', $name, $value);
                }
            }

            if (!empty($details)) {
                return array('details' => $details, 'code' => $code);
            }
        }

        // ═════════════════════════════════════════════════════════════════════
        // STRATEGY 4: Direct Meta Fields (Fallback)
        // ═════════════════════════════════════════════════════════════════════
        // Scan all meta for common patterns
        $all_meta = $item->get_meta_data();
        $skip_meta_keys = array(
            '_qty', '_tax_class', '_product_id', '_variation_id', '_line_subtotal',
            '_line_total', '_line_tax', '_line_subtotal_tax', '_reduced_stock',
            'custom_configurator', '_wapf_item_meta', '_product_addons',
            // Additional WooCommerce internal keys
            '_pao_ids', '_pao_total',
            '_defect_description', '_item_desc', '_min_age', '_unit', '_unit_base', '_unit_product',
            '_delivery_time',
            // Shipping meta
            'method_id', 'method_title', 'cost'
        );

        foreach ($all_meta as $meta) {
            $key = $meta->key;
            $value = $meta->value;

            // Skip internal WooCommerce fields
            if (in_array($key, $skip_meta_keys, true) || strpos($key, '_') === 0) {
                continue;
            }

            // Skip empty values
            if (empty($value) || (is_array($value) && empty(array_filter($value)))) {
                continue;
            }

            // Handle array values
            if (is_array($value)) {
                $value = implode(', ', array_filter($value));
            }

            // Format key to human-readable label
            $label = str_replace(array('_', '-'), ' ', $key);
            $label = ucwords($label);

            $details[] = sprintf('%s: %s', $label, $value);
        }

        return array('details' => $details, 'code' => $code);
    }

    /**
     * Build credit note line items from refund
     *
     * NOTE: Multi-tax rate items not yet supported - each line item currently
     * supports single tax rate only. For items with multiple tax rates (e.g.,
     * compound taxes), only the effective combined rate is calculated.
     * Future enhancement: Split line items by individual tax rates.
     */
    private static function build_credit_note_line_items($refund, $order) {
        $line_items = array();
        $has_amount_only = false; // Flag für amount-only detection

        $refund_items = $refund->get_items();

        // FULL REFUND FALLBACK: If refund has no items, use original order items
        // This happens when WooCommerce creates an "amount-only" full refund
        if (empty($refund_items)) {
            \WC_Lexware_MVP_Logger::info('Full refund with no line items - using original order items', array(
                'order_id' => $order->get_id(),
                'refund_id' => $refund->get_id(),
                'refund_amount' => $refund->get_amount()
            ));
            $refund_items = $order->get_items();
            $is_full_refund_fallback = true;
        } else {
            $is_full_refund_fallback = false;
        }

        // Hole Refund Items (negative Mengen)
        foreach ($refund_items as $item_id => $item) {
            $product = $item->get_product();

            if (!$product) {
                continue;
            }

            // Quantity calculation depends on whether we're using fallback or not
            if ($is_full_refund_fallback) {
                // Using original order items - quantities are positive
                $quantity = $item->get_quantity();
                $item_total = abs($item->get_total());
            } else {
                // Using refund items - quantities are negative
                $quantity = abs($item->get_quantity()); // Wir brauchen positive Zahl für Credit Note
                $item_total = abs($item->get_total());
            }

            // Edge Case: WooCommerce "Amount Only" Refund (Menge 0, aber Betrag vorhanden)
            // Workaround: Setze quantity=1 und verwende Gesamtbetrag als Einzelpreis
            // WICHTIG: Amount-only Refunds werden als STANDALONE Credit Notes erstellt (ohne precedingSalesVoucherId)
            if ($quantity <= 0 && $item_total > 0) {
                \WC_Lexware_MVP_Logger::info('Amount-only refund - applying workaround', array(
                    'order_id' => $order->get_id(),
                    'refund_id' => $refund->get_id(),
                    'item_name' => $item->get_name(),
                    'original_quantity' => $quantity,
                    'total_amount' => $item_total,
                    'workaround' => 'Setting quantity=1, standalone credit note (no invoice reference)'
                ));
                $has_amount_only = true;
                $quantity = 1; // Workaround: Setze auf 1
                $unit_price = $item_total; // Gesamtbetrag = Einzelpreis
            } elseif ($quantity > 0) {
                // Check for partial refund: refund unit price differs from original
                // This happens when customer gets partial money back for each item
                $refund_unit_price = $item_total / $quantity;

                // Find original order item to compare prices
                $original_order_item = null;
                foreach ($order->get_items() as $order_item_id => $potential_order_item) {
                    if ($potential_order_item->get_product_id() == $item->get_product_id()) {
                        $original_order_item = $potential_order_item;
                        break;
                    }
                }

                if ($original_order_item) {
                    $original_qty = abs($original_order_item->get_quantity());
                    $original_unit_price = $original_qty > 0 ? abs($original_order_item->get_total()) / $original_qty : 0;

                    // Compare unit prices with 1% tolerance (rounding differences)
                    $price_diff_percent = $original_unit_price > 0
                        ? abs($refund_unit_price - $original_unit_price) / $original_unit_price * 100
                        : 100;

                    if ($price_diff_percent > 1) {
                        // Partial refund detected - unit prices don't match
                        // Create as standalone credit note (without precedingSalesVoucherId)
                        \WC_Lexware_MVP_Logger::info('Partial refund detected - unit price mismatch, creating standalone credit note', array(
                            'order_id' => $order->get_id(),
                            'refund_id' => $refund->get_id(),
                            'item_name' => $item->get_name(),
                            'refund_unit_price' => $refund_unit_price,
                            'original_unit_price' => $original_unit_price,
                            'price_diff_percent' => round($price_diff_percent, 2),
                            'workaround' => 'Standalone credit note (no invoice reference)'
                        ));
                        $has_amount_only = true;
                    }
                }

                // Normale Berechnung
                $unit_price = $refund_unit_price;
            } else {
                // Keine Menge UND kein Betrag - überspringe
                \WC_Lexware_MVP_Logger::warning('Skipping refund item with zero quantity and zero amount', array(
                    'order_id' => $order->get_id(),
                    'refund_id' => $refund->get_id(),
                    'item_name' => $item->get_name()
                ));
                continue;
            }

            // Tax aus WooCommerce direkt holen (nicht neu berechnen!)
            $item_tax = abs($item->get_total_tax());
            $item_gross = $item_total + $item_tax;

            // Finde das entsprechende Original Order Item (für Tax Rate bei amount-only)
            $order_item = null;
            foreach ($order->get_items() as $order_item_id => $potential_order_item) {
                if ($potential_order_item->get_product_id() == $item->get_product_id()) {
                    $order_item = $potential_order_item;
                    break;
                }
            }

            // Tax Rate als INTEGER (Lexware erwartet 0, 7, 19)
            $tax_rate = 0;
            if ($order_item) {
                $order_item_total = abs($order_item->get_total());
                $order_item_tax = abs($order_item->get_total_tax());
                if ($order_item_total > 0) {
                    $tax_rate = round(($order_item_tax / $order_item_total) * 100, 0);
                }
            } elseif ($item_total > 0) {
                $tax_rate = round(($item_tax / $item_total) * 100, 0);
            }

            // Unit-Preise: WooCommerce-Werte direkt durch Menge teilen (analog zur Invoice-Logik)
            $has_tax = $tax_rate > 0;

            if ($has_tax) {
                $amount = round($item_gross / $quantity, 2); // Brutto pro Stück direkt aus WC
                $price_type = 'grossAmount';
            } else {
                $amount = round($item_total / $quantity, 2); // Netto pro Stück direkt aus WC
                $price_type = 'netAmount';
            }

            // Extract product meta data for description (from original order item if available)
            $description = '';
            if ($order_item) {
                $description = self::extract_item_meta_description($order_item, $product);
            } else {
                $description = self::extract_item_meta_description($item, $product);
            }

            $line_items[] = array(
                'type' => 'custom',
                'name' => $item->get_name(),
                'description' => $description,
                'quantity' => $quantity,
                'unitName' => 'Stück',
                'unitPrice' => array(
                    'currency' => 'EUR',
                    $price_type => $amount, // ENTWEDER grossAmount ODER netAmount (POSITIV für Credit Note)
                    'taxRatePercentage' => $tax_rate
                )
            );
        }

        // Shipping Refund (falls vorhanden)
        if ($refund->get_shipping_total() != 0) {
            $shipping_total = abs($refund->get_shipping_total());
            $shipping_tax = abs($refund->get_shipping_tax());
            $shipping_gross = $shipping_total + $shipping_tax;

            // Calculate tax rate - Lexware expects INTEGER values (0, 7, 19)
            $shipping_tax_rate = $shipping_total > 0 ? round(($shipping_tax / $shipping_total) * 100, 0) : 0; // Integer!

            // INTELLIGENTE REGEL: MIT MwSt → Brutto, OHNE MwSt → Netto
            $has_tax = $shipping_tax_rate > 0;

            if ($has_tax) {
                $shipping_amount = round($shipping_gross, 2);
                $price_type = 'grossAmount';
            } else {
                $shipping_amount = round($shipping_total, 2);
                $price_type = 'netAmount';
            }

            // Get shipping method name for description
            $shipping_method = $order->get_shipping_method();

            $line_items[] = array(
                'type' => 'custom',
                'name' => __('Versandkosten Erstattung', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'description' => $shipping_method,
                'quantity' => 1,
                'unitName' => 'Pauschale',
                'unitPrice' => array(
                    'currency' => 'EUR',
                    $price_type => $shipping_amount, // ENTWEDER grossAmount ODER netAmount (POSITIV)
                    'taxRatePercentage' => $shipping_tax_rate
                )
            );
        }

        return array(
            'items' => $line_items,
            'is_amount_only' => $has_amount_only
        );
    }

    /**
     * Kontakt in Lexware suchen oder anlegen
     */
    private static function get_or_create_contact($api, $order) {
        $email = $order->get_billing_email();
        $order_id = $order->get_id();

        // Suche bestehenden Kontakt
        $search_result = $api->search_contact_by_email($email);

        // Delay to prevent rate limit (2 req/sec = 500ms between calls)
        usleep(500000); // 500ms

        if (!\is_wp_error($search_result) && !empty($search_result['content'])) {
            $existing_contact = $search_result['content'][0];
            $existing_contact_id = $existing_contact['id'];

            // Enhanced Logging: Address Comparison für Duplicate Detection
            $order_street = $order->get_billing_address_1();
            $order_zip = $order->get_billing_postcode();
            $order_city = $order->get_billing_city();

            // Extract existing address from contact (might be in different structures)
            $existing_street = $existing_contact['addresses']['billing'][0]['street'] ?? null;
            $existing_zip = $existing_contact['addresses']['billing'][0]['zip'] ?? null;
            $existing_city = $existing_contact['addresses']['billing'][0]['city'] ?? null;

            \WC_Lexware_MVP_Logger::info('Contact found by email in Lexware', array(
                'order_id' => $order_id,
                'contact_id' => $existing_contact_id,
                'email' => $email, // Wird redacted in Logger
                'address_comparison' => array(
                    'same_street' => ($existing_street === $order_street),
                    'same_zip' => ($existing_zip === $order_zip),
                    'same_city' => ($existing_city === $order_city),
                    'order_street' => $order_street,
                    'existing_street' => $existing_street,
                ),
                'will_update_address' => false, // TODO: Implement address update logic
            ));

            return $existing_contact_id;
        }

        // Kontakt existiert nicht - erstelle neuen
        $contact_data = array(
            'version' => 0,
            'roles' => array(
                'customer' => (object)array()
            ),
            'emailAddresses' => array(
                'business' => array($email)
            )
        );

        // Firma falls vorhanden, sonst Privatperson
        if ($order->get_billing_company()) {
            // Nur company, kein person-Feld
            $contact_data['company'] = array(
                'name' => $order->get_billing_company()
            );
        } else {
            // Nur person, kein company-Feld
            $contact_data['person'] = array(
                'firstName' => $order->get_billing_first_name(),
                'lastName' => $order->get_billing_last_name()
            );
        }

        // Adresse hinzufügen
        $addresses = array();
        $billing_address = array(
            'street' => $order->get_billing_address_1(),
            'zip' => $order->get_billing_postcode(),
            'city' => $order->get_billing_city(),
            'countryCode' => $order->get_billing_country()
        );

        if ($order->get_billing_address_2()) {
            $billing_address['supplement'] = $order->get_billing_address_2();
        }

        $addresses['billing'] = array($billing_address);
        $contact_data['addresses'] = $addresses;

        // Debug: Log contact data structure
        \WC_Lexware_MVP_Logger::info('Creating new contact in Lexware', array(
            'order_id' => $order->get_id(),
            'email' => $email, // Wird redacted in Logger
            'company_name' => $contact_data['company']['name'] ?? null,
            'person_name' => isset($contact_data['person']) ? ($contact_data['person']['firstName'] . ' ' . $contact_data['person']['lastName']) : null,
            'contact_type' => 'customer',
            'has_company' => !empty($contact_data['company']['name']),
            'has_person' => !empty($contact_data['person']),
            'address_count' => count($addresses),
            'billing_country' => $billing_address['countryCode'],
        ));

        $create_result = $api->create_contact($contact_data);

        // Delay to prevent rate limit
        usleep(500000); // 500ms

        if (\is_wp_error($create_result)) {
            \WC_Lexware_MVP_Logger::error('Contact creation failed - API returned error', array(
                'order_id' => $order->get_id(),
                'contact_payload' => $contact_data, // Wird GDPR-compliant redacted
                'api_error_code' => $create_result->get_error_code(),
                'api_error_message' => $create_result->get_error_message(),
                'api_error_data' => $create_result->get_error_data(),
            ));
            throw new \Exception(__('Kontakt-Erstellung fehlgeschlagen: ', WC_LEXWARE_MVP_TEXT_DOMAIN) . $create_result->get_error_message());
        }

        // Contact created successfully - return ID
        return $create_result['id'];
    }

    /**
     * Check if order has any tax (products, shipping, or fees).
     *
     * Helper method to determine document-level taxType.
     * Used for both invoices and credit notes.
     *
     * @param WC_Order $order Order object
     * @return bool True if any item has tax, false otherwise
     */
    private static function order_has_any_tax($order) {
        // Check product items
        foreach ($order->get_items() as $item) {
            if ((float) $item->get_total_tax() > 0) {
                return true;
            }
        }

        // Check shipping
        if ((float) $order->get_shipping_tax() > 0) {
            return true;
        }

        // Check fees
        foreach ($order->get_fees() as $fee) {
            if ((float) $fee->get_total_tax() > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get payment condition data for order based on payment method mapping
     *
     * @param WC_Order $order Order object
     * @return array|null Payment condition data or null for Lexware default
     */
    private static function get_payment_condition_for_order($order) {
        $payment_method = $order->get_payment_method();

        if (empty($payment_method)) {
            return null;
        }

        $condition_id = \WC_Lexware_MVP_Payment_Mapping::get_condition_for_method($payment_method);

        if ($condition_id) {
            // Fetch full condition data from Lexware
            $conditions = \WC_Lexware_MVP_Payment_Mapping::fetch_payment_conditions();

            if (!\is_wp_error($conditions)) {
                foreach ($conditions as $condition) {
                    if ($condition['id'] === $condition_id) {
                        // Return full payment condition data as API expects
                        $payment_data = array(
                            'paymentConditionId' => $condition['id'],
                            'paymentTermLabel' => $condition['paymentTermLabelTemplate'] ?? '',
                            'paymentTermDuration' => $condition['paymentTermDuration'] ?? 0
                        );

                        \WC_Lexware_MVP_Logger::debug('Payment condition mapped', array(
                            'order_id' => $order->get_id(),
                            'payment_method' => $payment_method,
                            'condition_data' => $payment_data
                        ));

                        return $payment_data;
                    }
                }
            }

            // Condition not found - it was deleted from Lexware
            \WC_Lexware_MVP_Logger::warning('Payment condition no longer exists in Lexware, using default', array(
                'order_id' => $order->get_id(),
                'payment_method' => $payment_method,
                'invalid_condition_id' => $condition_id
            ));
        } else {
            \WC_Lexware_MVP_Logger::debug('No payment condition mapped for method', array(
                'order_id' => $order->get_id(),
                'payment_method' => $payment_method
            ));
        }

        return null; // Use Lexware default
    }

    /**
     * Build shipping conditions array for Lexware API
     *
     * Creates the shippingConditions object with deliveryTerms from WooCommerce
     * shipping method. Uses 'none' as shippingType since exact delivery date
     * is typically not known at order confirmation time.
     *
     * @since 1.3.0
     * @param WC_Order $order Order object
     * @return array Shipping conditions for Lexware API
     */
    private static function build_shipping_conditions($order) {
        // Get delivery terms from shipping method
        $delivery_terms = self::get_delivery_terms_from_shipping($order);

        // Always use 'none' as shippingType - exact delivery date is not known
        // at order confirmation time. The delivery date can be specified later
        // on the invoice or delivery note.
        $shipping_conditions = array(
            'shippingType' => 'none'
        );

        if (!empty($delivery_terms)) {
            $shipping_conditions['deliveryTerms'] = $delivery_terms;
        }

        return $shipping_conditions;
    }

    /**
     * Get delivery terms from WooCommerce shipping method
     *
     * Extracts the shipping method name from the order to use as deliveryTerms
     * in Lexware documents. Returns the shipping method title or null if not available.
     *
     * @since 1.3.0
     * @param WC_Order $order Order object
     * @return string|null Delivery terms string or null for Lexware default
     */
    private static function get_delivery_terms_from_shipping($order) {
        $shipping_methods = $order->get_shipping_methods();

        if (empty($shipping_methods)) {
            \WC_Lexware_MVP_Logger::debug('No shipping methods found for order', array(
                'order_id' => $order->get_id()
            ));
            return null;
        }

        // Get the first shipping method (most orders have only one)
        $shipping_method = reset($shipping_methods);

        if (!$shipping_method) {
            return null;
        }

        // Get the shipping method name/title
        $delivery_terms = $shipping_method->get_name();

        // Allow filtering for customization
        $delivery_terms = apply_filters(
            'wc_lexware_mvp_delivery_terms',
            $delivery_terms,
            $order,
            $shipping_method
        );

        if (!empty($delivery_terms)) {
            \WC_Lexware_MVP_Logger::debug('Delivery terms extracted from shipping method', array(
                'order_id' => $order->get_id(),
                'shipping_method_id' => $shipping_method->get_method_id(),
                'delivery_terms' => $delivery_terms
            ));
        }

        return $delivery_terms ?: null;
    }

    /**
     * Get Action Scheduler Queue Health Status
     * Helper method for monitoring queue depth and failed jobs
     *
     * @return array Queue health metrics
     */
    private static function get_action_scheduler_queue_health() {
        global $wpdb;

        $metrics = array(
            'pending_invoices' => 0,
            'pending_credit_notes' => 0,
            'failed_last_hour' => 0,
            'running_jobs' => 0,
            'oldest_pending_age_minutes' => 0,
        );

        // Only check if Action Scheduler is available
        if (!self::is_action_scheduler_available()) {
            return $metrics;
        }

        // Pending invoices
        $metrics['pending_invoices'] = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$wpdb->prefix}actionscheduler_actions
             WHERE hook = 'wc_lexware_mvp_process_invoice'
             AND status = 'pending'"
        );

        // Pending credit notes
        $metrics['pending_credit_notes'] = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$wpdb->prefix}actionscheduler_actions
             WHERE hook = 'wc_lexware_mvp_process_credit_note'
             AND status = 'pending'"
        );

        // Failed jobs in last hour
        $metrics['failed_last_hour'] = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$wpdb->prefix}actionscheduler_actions
             WHERE hook LIKE 'wc_lexware_mvp_%'
             AND status = 'failed'
             AND scheduled_date_gmt > NOW() - INTERVAL 1 HOUR"
        );

        // Running jobs
        $metrics['running_jobs'] = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$wpdb->prefix}actionscheduler_actions
             WHERE hook LIKE 'wc_lexware_mvp_%'
             AND status = 'in-progress'"
        );

        // Oldest pending job age
        $oldest_pending = $wpdb->get_var(
            "SELECT MIN(scheduled_date_gmt)
             FROM {$wpdb->prefix}actionscheduler_actions
             WHERE hook LIKE 'wc_lexware_mvp_%'
             AND status = 'pending'"
        );

        if ($oldest_pending) {
            $age_seconds = strtotime('now') - strtotime($oldest_pending);
            $metrics['oldest_pending_age_minutes'] = round($age_seconds / 60, 1);
        }

        return $metrics;
    }
}
