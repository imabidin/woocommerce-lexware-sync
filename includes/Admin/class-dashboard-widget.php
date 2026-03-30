<?php
/**
 * Dashboard Widget - Lexware MVP Status Overview
 *
 * Provides real-time monitoring of:
 * - Queue status (pending/failed documents)
 * - Circuit Breaker state
 * - Rate Limiter status
 * - Action Scheduler health
 *
 * @package WC_Lexware_MVP
 * @since 1.0.0
 */

namespace WC_Lexware_MVP\Admin;

if (!defined('ABSPATH')) {
    exit;
}

class Dashboard_Widget {

    /**
     * Initialize hooks
     *
     * Registers WordPress dashboard widget.
     *
     * @since 1.0.0
     */
    public static function init() {
        add_action('wp_dashboard_setup', array(__CLASS__, 'add_dashboard_widget'));
    }

    /**
     * Register dashboard widget
     *
     * Only shown to users with manage_woocommerce capability.
     *
     * @since 1.0.0
     */
    public static function add_dashboard_widget() {
        // Only show to users who can manage WooCommerce
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        wp_add_dashboard_widget(
            'wc_lexware_mvp_status',
            '📊 Lexware MVP Status',
            array(__CLASS__, 'render_widget'),
            null,
            null,
            'normal',
            'high'
        );
    }

    /**
     * Render dashboard widget
     *
     * Displays document statistics, circuit breaker status, rate limiter info,
     * and Action Scheduler queue health.
     *
     * @since 1.0.0
     */
    public static function render_widget() {
        global $wpdb;

        // Get database table name
        $table = wc_lexware_mvp_get_table_name();

        // 1. Document Statistics
        $pending_invoices = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table}
             WHERE document_status = 'pending'
             AND document_type = 'invoice'"
        );

        $failed_invoices = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table}
             WHERE document_status = 'failed'
             AND document_type = 'invoice'
             AND updated_at > NOW() - INTERVAL 1 DAY"
        );

        $synced_today = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table}
             WHERE document_status = 'synced'
             AND synced_at > NOW() - INTERVAL 1 DAY"
        );

        $pending_credit_notes = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table}
             WHERE document_status = 'pending'
             AND document_type = 'credit_note'"
        );

        // 2. Circuit Breaker Status
        $circuit_breaker = new \WC_Lexware_MVP\Core\Circuit_Breaker('lexware_api');
        $cb_status = $circuit_breaker->get_status();

        // 3. Rate Limiter Status
        $rl_status = \WC_Lexware_MVP\Core\Rate_Limiter::get_status();

        // 4. Action Scheduler Queue (if available)
        $as_pending = 0;
        $as_failed = 0;
        if (function_exists('as_get_scheduled_actions')) {
            $as_pending = (int) $wpdb->get_var(
                "SELECT COUNT(*)
                 FROM {$wpdb->prefix}actionscheduler_actions
                 WHERE hook LIKE 'wc_lexware_mvp_%'
                 AND status = 'pending'"
            );

            $as_failed = (int) $wpdb->get_var(
                "SELECT COUNT(*)
                 FROM {$wpdb->prefix}actionscheduler_actions
                 WHERE hook LIKE 'wc_lexware_mvp_%'
                 AND status = 'failed'
                 AND scheduled_date_gmt > NOW() - INTERVAL 1 HOUR"
            );
        }

        // 5. Last API Call Timestamp
        $last_api_call = get_transient('wc_lexware_mvp_last_api_call');
        $last_api_time = $last_api_call ? human_time_diff($last_api_call, time()) : 'never';

        // 6. PDF Storage Health
        $pdf_handler = \WC_Lexware_MVP\Core\PDF_Handler::get_instance();
        $pdf_health = \WC_Lexware_MVP\Core\PDF_Handler::check_directory_health();

        // Render Widget HTML
        ?>
        <div class="lexware-mvp-dashboard" style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">

            <!-- Queue Status -->
            <div style="margin-bottom: 20px;">
                <h4 style="margin: 0 0 10px 0; font-size: 14px; font-weight: 600;">🔄 Queue Status</h4>
                <table style="width: 100%; font-size: 13px;">
                    <tr>
                        <td style="padding: 5px 0;">Pending Invoices:</td>
                        <td style="text-align: right; font-weight: 600;">
                            <span style="color: <?php echo $pending_invoices > 10 ? '#d63638' : '#2271b1'; ?>">
                                <?php echo $pending_invoices; ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 5px 0;">Pending Credit Notes:</td>
                        <td style="text-align: right; font-weight: 600;">
                            <span style="color: <?php echo $pending_credit_notes > 5 ? '#d63638' : '#2271b1'; ?>">
                                <?php echo $pending_credit_notes; ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 5px 0;">Failed (24h):</td>
                        <td style="text-align: right; font-weight: 600;">
                            <span style="color: <?php echo $failed_invoices > 0 ? '#d63638' : '#00a32a'; ?>">
                                <?php echo $failed_invoices; ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 5px 0; border-top: 1px solid #dcdcde; padding-top: 8px;">Synced Today:</td>
                        <td style="text-align: right; font-weight: 600; border-top: 1px solid #dcdcde; padding-top: 8px;">
                            <span style="color: #00a32a;">
                                <?php echo $synced_today; ?>
                            </span>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- System Health -->
            <div style="margin-bottom: 20px;">
                <h4 style="margin: 0 0 10px 0; font-size: 14px; font-weight: 600;">⚡ System Health</h4>

                <!-- Circuit Breaker -->
                <div style="padding: 8px 12px; background: <?php echo $cb_status['state'] === 'open' ? '#fcf0f1' : '#f0f6fc'; ?>; border-left: 3px solid <?php echo $cb_status['state'] === 'open' ? '#d63638' : '#2271b1'; ?>; margin-bottom: 8px; border-radius: 3px;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="font-size: 12px; font-weight: 500;">Circuit Breaker:</span>
                        <span style="font-size: 12px; font-weight: 600; color: <?php echo $cb_status['state'] === 'open' ? '#d63638' : '#00a32a'; ?>; text-transform: uppercase;">
                            <?php echo $cb_status['state']; ?>
                            <?php if ($cb_status['state'] === 'open'): ?>
                                <span style="font-weight: normal; text-transform: none; font-size: 11px;">
                                    (<?php echo $cb_status['failures']; ?> failures)
                                </span>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>

                <!-- Rate Limiter -->
                <div style="padding: 8px 12px; background: #f0f6fc; border-left: 3px solid #2271b1; margin-bottom: 8px; border-radius: 3px;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="font-size: 12px; font-weight: 500;">Rate Limiter:</span>
                        <span style="font-size: 12px; font-weight: 600;">
                            <?php echo $rl_status['tokens']; ?> / <?php echo $rl_status['capacity']; ?> tokens
                        </span>
                    </div>
                    <div style="margin-top: 5px; height: 6px; background: #dcdcde; border-radius: 3px; overflow: hidden;">
                        <div style="height: 100%; background: #2271b1; width: <?php echo ($rl_status['tokens'] / $rl_status['capacity']) * 100; ?>%;"></div>
                    </div>
                </div>

                <!-- PDF Storage -->
                <div style="padding: 8px 12px; background: <?php echo $pdf_health['status'] === 'critical' ? '#fcf0f1' : '#f0f6fc'; ?>; border-left: 3px solid <?php echo $pdf_health['status'] === 'critical' ? '#d63638' : '#00a32a'; ?>; border-radius: 3px;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="font-size: 12px; font-weight: 500;">PDF Storage:</span>
                        <span style="font-size: 12px; font-weight: 600; color: <?php echo $pdf_health['writable'] ? '#00a32a' : '#d63638'; ?>; text-transform: uppercase;">
                            <?php echo $pdf_health['writable'] ? 'OK' : 'ERROR'; ?>
                        </span>
                    </div>
                    <?php if (!empty($pdf_health['issues'])): ?>
                        <div style="margin-top: 5px; font-size: 11px; color: #d63638;">
                            <?php foreach ($pdf_health['issues'] as $issue): ?>
                                • <?php echo esc_html($issue); ?><br>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Action Scheduler -->
            <?php if (function_exists('as_get_scheduled_actions')): ?>
            <div style="margin-bottom: 20px;">
                <h4 style="margin: 0 0 10px 0; font-size: 14px; font-weight: 600;">📅 Action Scheduler</h4>
                <table style="width: 100%; font-size: 13px;">
                    <tr>
                        <td style="padding: 5px 0;">Pending Jobs:</td>
                        <td style="text-align: right; font-weight: 600;">
                            <span style="color: <?php echo $as_pending > 50 ? '#d63638' : '#2271b1'; ?>">
                                <?php echo $as_pending; ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 5px 0;">Failed (1h):</td>
                        <td style="text-align: right; font-weight: 600;">
                            <span style="color: <?php echo $as_failed > 0 ? '#d63638' : '#00a32a'; ?>">
                                <?php echo $as_failed; ?>
                            </span>
                        </td>
                    </tr>
                </table>
            </div>
            <?php endif; ?>

            <!-- Last Activity -->
            <div style="margin-bottom: 15px; padding-top: 15px; border-top: 1px solid #dcdcde;">
                <div style="font-size: 12px; color: #646970;">
                    📝 Last API Call: <strong><?php echo $last_api_time; ?> ago</strong>
                </div>
            </div>

            <!-- Action Buttons -->
            <div style="text-align: center; padding-top: 10px; border-top: 1px solid #dcdcde;">
                <a href="<?php echo admin_url('admin.php?page=wc-settings&tab=lexware_mvp'); ?>"
                   class="button button-primary"
                   style="margin-right: 5px;">
                    Settings
                </a>

                <?php if ($failed_invoices > 0): ?>
                <a href="<?php echo admin_url('admin.php?page=lexware-failed-docs'); ?>"
                   class="button"
                   style="margin-right: 5px;">
                    View Failed (<?php echo $failed_invoices; ?>)
                </a>
                <?php endif; ?>

                <a href="<?php echo admin_url('admin.php?page=wc-status&tab=logs'); ?>"
                   class="button">
                    View Logs
                </a>
            </div>

            <!-- Debug Info (collapsed) -->
            <details style="margin-top: 15px; font-size: 11px; color: #646970;">
                <summary style="cursor: pointer; padding: 5px; background: #f6f7f7; border-radius: 3px;">Debug Info</summary>
                <pre style="margin: 10px 0 0 0; padding: 10px; background: #f6f7f7; border-radius: 3px; overflow-x: auto; font-size: 10px;">Plugin Version: <?php echo defined('WC_LEXWARE_MVP_VERSION') ? WC_LEXWARE_MVP_VERSION : 'unknown'; ?>

PHP Version: <?php echo PHP_VERSION; ?>

Memory Limit: <?php echo ini_get('memory_limit'); ?>

Memory Usage: <?php echo round(memory_get_usage(true) / 1024 / 1024, 2); ?> MB

WC Version: <?php echo defined('WC_VERSION') ? WC_VERSION : 'n/a'; ?>

Action Scheduler: <?php echo function_exists('as_get_scheduled_actions') ? 'Available' : 'Not Available'; ?>

Table: <?php echo $table; ?>
</pre>
            </details>
        </div>

        <style>
            .lexware-mvp-dashboard details[open] summary {
                margin-bottom: 10px;
            }
        </style>
        <?php
    }
}
