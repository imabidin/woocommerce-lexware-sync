<?php
/**
 * Status Monitoring Page
 *
 * Dashboard for monitoring Lexware API integration health:
 * - Circuit Breaker status and metrics
 * - Rate Limiter statistics
 * - Recent API calls log
 * - Error rates and patterns
 * - Webhook delivery status
 *
 * @package WC_Lexware_MVP
 * @since 1.0.0
 */

namespace WC_Lexware_MVP\Admin;

class Status_Page {

    /**
     * Constructor
     *
     * @since 1.0.0
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_menu_item'), 99);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('wp_ajax_lexware_reset_circuit_breaker', array($this, 'ajax_reset_circuit_breaker'));
    }

    /**
     * Add menu item under Tools
     *
     * @since 1.0.0
     */
    public function add_menu_item() {
        add_management_page(
            __('Lexware Status', 'woocommerce-lexware-mvp'),
            __('Lexware Status', 'woocommerce-lexware-mvp'),
            'manage_woocommerce',
            'lexware-status',
            array($this, 'render_page')
        );
    }

    /**
     * Enqueue CSS for status page
     *
     * @since 1.0.0
     */
    public function enqueue_assets($hook) {
        if ($hook !== 'tools_page_lexware-status') {
            return;
        }

        // Inline CSS for status page
        wp_add_inline_style('wp-admin', '
            .lexware-status-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
                gap: 20px;
                margin-top: 20px;
            }
            .lexware-status-card {
                background: #fff;
                border: 1px solid #c3c4c7;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
                padding: 20px;
            }
            .lexware-status-card h3 {
                margin-top: 0;
                padding-bottom: 10px;
                border-bottom: 1px solid #dcdcde;
            }
            .status-indicator {
                display: inline-block;
                width: 12px;
                height: 12px;
                border-radius: 50%;
                margin-right: 8px;
            }
            .status-indicator.green { background: #00a32a; }
            .status-indicator.yellow { background: #dba617; }
            .status-indicator.red { background: #d63638; }
            .status-metric {
                display: flex;
                justify-content: space-between;
                padding: 8px 0;
                border-bottom: 1px solid #f0f0f1;
            }
            .status-metric:last-child {
                border-bottom: none;
            }
            .status-metric-label {
                color: #50575e;
            }
            .status-metric-value {
                font-weight: 600;
            }
            .log-table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 15px;
            }
            .log-table th {
                text-align: left;
                padding: 8px;
                background: #f6f7f7;
                border-bottom: 2px solid #c3c4c7;
            }
            .log-table td {
                padding: 8px;
                border-bottom: 1px solid #dcdcde;
            }
            .log-table tr:last-child td {
                border-bottom: none;
            }
            .status-success { color: #00a32a; }
            .status-error { color: #d63638; }
            .status-warning { color: #dba617; }
        ');

        // Inline JavaScript for Circuit Breaker reset
        wp_add_inline_script('jquery', '
            jQuery(document).ready(function($) {
                $("#lexware-circuit-reset-btn").on("click", function() {
                    var $btn = $(this);
                    var $result = $("#circuit-reset-result");

                    $btn.prop("disabled", true).text("' . esc_js(__('Zurücksetzen...', 'woocommerce-lexware-mvp')) . '");
                    $result.html("");

                    $.ajax({
                        url: ajaxurl,
                        type: "POST",
                        data: {
                            action: "lexware_reset_circuit_breaker",
                            nonce: "' . wp_create_nonce('lexware_reset_circuit') . '"
                        },
                        success: function(response) {
                            if (response.success) {
                                $result.html("<div class=\"notice notice-success inline\"><p>" + response.data.message + "</p></div>");
                                setTimeout(function() {
                                    location.reload();
                                }, 1500);
                            } else {
                                $result.html("<div class=\"notice notice-error inline\"><p>" + response.data.message + "</p></div>");
                                $btn.prop("disabled", false).html("<span class=\"dashicons dashicons-update-alt\" style=\"margin-top: 3px;\"></span> ' . esc_js(__('Circuit manuell zurücksetzen', 'woocommerce-lexware-mvp')) . '");
                            }
                        },
                        error: function() {
                            $result.html("<div class=\"notice notice-error inline\"><p>' . esc_js(__('AJAX-Fehler beim Zurücksetzen', 'woocommerce-lexware-mvp')) . '</p></div>");
                            $btn.prop("disabled", false).html("<span class=\"dashicons dashicons-update-alt\" style=\"margin-top: 3px;\"></span> ' . esc_js(__('Circuit manuell zurücksetzen', 'woocommerce-lexware-mvp')) . '");
                        }
                    });
                });
            });
        ');
    }

    /**
     * Render status page
     *
     * @since 1.0.0
     */
    public function render_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Lexware API Status Monitor', 'woocommerce-lexware-mvp'); ?></h1>

            <div class="lexware-status-grid">
                <!-- Circuit Breaker Status -->
                <div class="lexware-status-card">
                    <?php $this->render_circuit_breaker_card(); ?>
                </div>

                <!-- Rate Limiter Stats -->
                <div class="lexware-status-card">
                    <?php $this->render_rate_limiter_card(); ?>
                </div>

                <!-- API Connection Status -->
                <div class="lexware-status-card">
                    <?php $this->render_connection_status_card(); ?>
                </div>

                <!-- Webhook Status -->
                <div class="lexware-status-card">
                    <?php $this->render_webhook_status_card(); ?>
                </div>
            </div>

            <!-- Recent API Calls Log -->
            <div class="lexware-status-card" style="margin-top: 20px;">
                <?php $this->render_recent_api_calls(); ?>
            </div>

            <!-- Error Summary -->
            <div class="lexware-status-card" style="margin-top: 20px;">
                <?php $this->render_error_summary(); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render Circuit Breaker status card
     *
     * @since 1.0.0
     */
    private function render_circuit_breaker_card() {
        $circuit_breaker = new \WC_Lexware_MVP_Circuit_Breaker('lexware_api', 5, 2, 60);
        $status = $circuit_breaker->get_status();

        $state_colors = array(
            'closed' => 'green',
            'half_open' => 'yellow',
            'open' => 'red'
        );

        $state_labels = array(
            'closed' => __('Closed (Healthy)', 'woocommerce-lexware-mvp'),
            'half_open' => __('Half-Open (Testing)', 'woocommerce-lexware-mvp'),
            'open' => __('Open (Blocking)', 'woocommerce-lexware-mvp')
        );

        $color = $state_colors[$status['state']] ?? 'red';
        $label = $state_labels[$status['state']] ?? $status['state'];

        ?>
        <h3>
            <span class="dashicons dashicons-shield-alt"></span>
            <?php _e('Circuit Breaker', 'woocommerce-lexware-mvp'); ?>
        </h3>

        <div class="status-metric">
            <span class="status-metric-label"><?php _e('Status', 'woocommerce-lexware-mvp'); ?></span>
            <span class="status-metric-value">
                <span class="status-indicator <?php echo esc_attr($color); ?>"></span>
                <?php echo esc_html($label); ?>
            </span>
        </div>

        <div class="status-metric">
            <span class="status-metric-label"><?php _e('Failure Count', 'woocommerce-lexware-mvp'); ?></span>
            <span class="status-metric-value"><?php echo esc_html($status['failure_count']); ?></span>
        </div>

        <div class="status-metric">
            <span class="status-metric-label"><?php _e('Failure Threshold', 'woocommerce-lexware-mvp'); ?></span>
            <span class="status-metric-value"><?php echo esc_html($status['failure_threshold']); ?></span>
        </div>

        <?php if ($status['state'] === 'open' && isset($status['seconds_until_retry'])): ?>
        <div class="status-metric">
            <span class="status-metric-label"><?php _e('Retry In', 'woocommerce-lexware-mvp'); ?></span>
            <span class="status-metric-value"><?php echo esc_html($status['seconds_until_retry']); ?>s</span>
        </div>
        <?php endif; ?>

        <?php if ($status['state'] === 'half_open'): ?>
        <div class="status-metric">
            <span class="status-metric-label"><?php _e('Success Count', 'woocommerce-lexware-mvp'); ?></span>
            <span class="status-metric-value"><?php echo esc_html($status['success_count']); ?> / <?php echo esc_html($status['success_threshold']); ?></span>
        </div>
        <?php endif; ?>

        <!-- Manual Reset Button -->
        <div class="status-metric" style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;">
            <button type="button" class="button" id="lexware-circuit-reset-btn"
                    <?php echo ($status['state'] === 'closed' ? 'disabled' : ''); ?>>
                <span class="dashicons dashicons-update-alt" style="margin-top: 3px;"></span>
                <?php _e('Circuit manuell zurücksetzen', 'woocommerce-lexware-mvp'); ?>
            </button>
            <p class="description" style="margin-top: 8px;">
                <?php _e('Setzt den Circuit Breaker sofort auf CLOSED zurück. Nur verwenden wenn die API-Probleme behoben sind.', 'woocommerce-lexware-mvp'); ?>
            </p>
            <div id="circuit-reset-result" style="margin-top: 10px;"></div>
        </div>
        <?php
    }

    /**
     * Render Rate Limiter statistics card
     *
     * @since 1.0.0
     */
    private function render_rate_limiter_card() {
        global $wpdb;
        $table = $wpdb->prefix . 'lexware_rate_limiter';

        // Get current token count
        $rate_limiter = \WC_Lexware_MVP_Rate_Limiter::get_instance();
        $tokens = get_transient('lexware_rate_limiter_tokens');

        if ($tokens === false) {
            $tokens = 2.0; // Default capacity
        }

        // Get stats from last 24 hours
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely constructed
        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*) as total_requests,
                SUM(CASE WHEN wait_time_ms > 0 THEN 1 ELSE 0 END) as throttled_requests,
                AVG(wait_time_ms) as avg_wait_ms,
                MAX(wait_time_ms) as max_wait_ms
            FROM {$table}
            WHERE created_at > DATE_SUB(NOW(), INTERVAL %d HOUR)",
            24
        ));

        ?>
        <h3>
            <span class="dashicons dashicons-clock"></span>
            <?php _e('Rate Limiter (24h)', 'woocommerce-lexware-mvp'); ?>
        </h3>

        <div class="status-metric">
            <span class="status-metric-label"><?php _e('Available Tokens', 'woocommerce-lexware-mvp'); ?></span>
            <span class="status-metric-value"><?php echo number_format($tokens, 2); ?> / 2.0</span>
        </div>

        <div class="status-metric">
            <span class="status-metric-label"><?php _e('Total Requests', 'woocommerce-lexware-mvp'); ?></span>
            <span class="status-metric-value"><?php echo esc_html($stats->total_requests ?? 0); ?></span>
        </div>

        <div class="status-metric">
            <span class="status-metric-label"><?php _e('Throttled Requests', 'woocommerce-lexware-mvp'); ?></span>
            <span class="status-metric-value"><?php echo esc_html($stats->throttled_requests ?? 0); ?></span>
        </div>

        <div class="status-metric">
            <span class="status-metric-label"><?php _e('Avg Wait Time', 'woocommerce-lexware-mvp'); ?></span>
            <span class="status-metric-value"><?php echo number_format($stats->avg_wait_ms ?? 0, 2); ?>ms</span>
        </div>

        <div class="status-metric">
            <span class="status-metric-label"><?php _e('Max Wait Time', 'woocommerce-lexware-mvp'); ?></span>
            <span class="status-metric-value"><?php echo number_format($stats->max_wait_ms ?? 0, 2); ?>ms</span>
        </div>
        <?php
    }

    /**
     * Render API connection status card
     *
     * @since 1.0.0
     */
    private function render_connection_status_card() {
        $client = new \WC_Lexware_MVP\API\Client();
        $test_result = $client->test_connection();

        $is_connected = !is_wp_error($test_result);
        $color = $is_connected ? 'green' : 'red';
        $label = $is_connected ? __('Connected', 'woocommerce-lexware-mvp') : __('Disconnected', 'woocommerce-lexware-mvp');

        ?>
        <h3>
            <span class="dashicons dashicons-cloud"></span>
            <?php _e('API Connection', 'woocommerce-lexware-mvp'); ?>
        </h3>

        <div class="status-metric">
            <span class="status-metric-label"><?php _e('Status', 'woocommerce-lexware-mvp'); ?></span>
            <span class="status-metric-value">
                <span class="status-indicator <?php echo esc_attr($color); ?>"></span>
                <?php echo esc_html($label); ?>
            </span>
        </div>

        <?php if ($is_connected): ?>
            <div class="status-metric">
                <span class="status-metric-label"><?php _e('Profile ID', 'woocommerce-lexware-mvp'); ?></span>
                <span class="status-metric-value"><?php echo esc_html($test_result['id'] ?? 'N/A'); ?></span>
            </div>

            <div class="status-metric">
                <span class="status-metric-label"><?php _e('Company Name', 'woocommerce-lexware-mvp'); ?></span>
                <span class="status-metric-value"><?php echo esc_html($test_result['company_name'] ?? 'N/A'); ?></span>
            </div>
        <?php else: ?>
            <div class="status-metric">
                <span class="status-metric-label"><?php _e('Error', 'woocommerce-lexware-mvp'); ?></span>
                <span class="status-metric-value status-error">
                    <?php echo esc_html($test_result->get_error_message()); ?>
                </span>
            </div>
        <?php endif; ?>

        <div class="status-metric">
            <span class="status-metric-label"><?php _e('Endpoint', 'woocommerce-lexware-mvp'); ?></span>
            <span class="status-metric-value">
                <code><?php echo esc_html(\WC_Lexware_MVP\API\Client::API_URL_LIVE); ?></code>
            </span>
        </div>
        <?php
    }

    /**
     * Render webhook status card
     *
     * @since 1.0.0
     */
    private function render_webhook_status_card() {
        global $wpdb;

        // Count webhooks in last 24 hours from logs
        $webhook_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
            FROM {$wpdb->prefix}lexware_logs
            WHERE message LIKE %s
            AND level IN ('info', 'debug')
            AND created_at > DATE_SUB(NOW(), INTERVAL %d HOUR)",
            '%webhook%',
            24
        ));

        // Count webhook errors
        $webhook_errors = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
            FROM {$wpdb->prefix}lexware_logs
            WHERE message LIKE %s
            AND level = %s
            AND created_at > DATE_SUB(NOW(), INTERVAL %d HOUR)",
            '%webhook%',
            'error',
            24
        ));

        $webhook_endpoint = home_url('/wc-api/lexware-webhook');

        ?>
        <h3>
            <span class="dashicons dashicons-admin-plugins"></span>
            <?php _e('Webhooks (24h)', 'woocommerce-lexware-mvp'); ?>
        </h3>

        <div class="status-metric">
            <span class="status-metric-label"><?php _e('Received', 'woocommerce-lexware-mvp'); ?></span>
            <span class="status-metric-value"><?php echo esc_html($webhook_count ?? 0); ?></span>
        </div>

        <div class="status-metric">
            <span class="status-metric-label"><?php _e('Errors', 'woocommerce-lexware-mvp'); ?></span>
            <span class="status-metric-value <?php echo ($webhook_errors > 0) ? 'status-error' : 'status-success'; ?>">
                <?php echo esc_html($webhook_errors ?? 0); ?>
            </span>
        </div>

        <div class="status-metric">
            <span class="status-metric-label"><?php _e('Endpoint', 'woocommerce-lexware-mvp'); ?></span>
            <span class="status-metric-value">
                <code style="font-size: 11px;"><?php echo esc_html($webhook_endpoint); ?></code>
            </span>
        </div>

        <div class="status-metric">
            <span class="status-metric-label"><?php _e('Signature Verification', 'woocommerce-lexware-mvp'); ?></span>
            <span class="status-metric-value">
                <span class="status-indicator green"></span>
                <?php _e('RSA-SHA512', 'woocommerce-lexware-mvp'); ?>
            </span>
        </div>
        <?php
    }

    /**
     * Render recent API calls log
     *
     * @since 1.0.0
     */
    private function render_recent_api_calls() {
        global $wpdb;

        $logs = $wpdb->get_results($wpdb->prepare(
            "SELECT *
            FROM {$wpdb->prefix}lexware_logs
            WHERE message LIKE %s
            ORDER BY created_at DESC
            LIMIT %d",
            '%API request%',
            20
        ));

        ?>
        <h3>
            <span class="dashicons dashicons-list-view"></span>
            <?php _e('Recent API Calls', 'woocommerce-lexware-mvp'); ?>
        </h3>

        <?php if (empty($logs)): ?>
            <p><?php _e('No API calls logged yet.', 'woocommerce-lexware-mvp'); ?></p>
        <?php else: ?>
            <table class="log-table">
                <thead>
                    <tr>
                        <th><?php _e('Time', 'woocommerce-lexware-mvp'); ?></th>
                        <th><?php _e('Level', 'woocommerce-lexware-mvp'); ?></th>
                        <th><?php _e('Message', 'woocommerce-lexware-mvp'); ?></th>
                        <th><?php _e('Details', 'woocommerce-lexware-mvp'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <?php
                        $level_class = '';
                        if ($log->level === 'error') $level_class = 'status-error';
                        elseif ($log->level === 'warning') $level_class = 'status-warning';
                        else $level_class = 'status-success';

                        $context = json_decode($log->context, true);
                        ?>
                        <tr>
                            <td><?php echo esc_html(mysql2date('Y-m-d H:i:s', $log->created_at)); ?></td>
                            <td class="<?php echo esc_attr($level_class); ?>">
                                <?php echo esc_html(strtoupper($log->level)); ?>
                            </td>
                            <td><?php echo esc_html($log->message); ?></td>
                            <td>
                                <?php if (!empty($context)): ?>
                                    <details>
                                        <summary style="cursor: pointer;"><?php _e('View Details', 'woocommerce-lexware-mvp'); ?></summary>
                                        <pre style="font-size: 11px; margin-top: 5px;"><?php echo esc_html(json_encode($context, JSON_PRETTY_PRINT)); ?></pre>
                                    </details>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
    }

    /**
     * Render error summary
     *
     * @since 1.0.0
     */
    private function render_error_summary() {
        global $wpdb;

        // Get error statistics from last 24 hours
        $error_stats = $wpdb->get_results($wpdb->prepare(
            "SELECT
                JSON_EXTRACT(context, '$.status_code') as status_code,
                COUNT(*) as count
            FROM {$wpdb->prefix}lexware_logs
            WHERE level = %s
            AND created_at > DATE_SUB(NOW(), INTERVAL %d HOUR)
            AND context LIKE %s
            GROUP BY status_code
            ORDER BY count DESC
            LIMIT %d",
            'error',
            24,
            '%status_code%',
            10
        ));

        $total_errors = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
            FROM {$wpdb->prefix}lexware_logs
            WHERE level = %s
            AND created_at > DATE_SUB(NOW(), INTERVAL %d HOUR)",
            'error',
            24
        ));

        ?>
        <h3>
            <span class="dashicons dashicons-warning"></span>
            <?php _e('Error Summary (24h)', 'woocommerce-lexware-mvp'); ?>
        </h3>

        <div class="status-metric">
            <span class="status-metric-label"><?php _e('Total Errors', 'woocommerce-lexware-mvp'); ?></span>
            <span class="status-metric-value <?php echo ($total_errors > 0) ? 'status-error' : 'status-success'; ?>">
                <?php echo esc_html($total_errors); ?>
            </span>
        </div>

        <?php if (!empty($error_stats)): ?>
            <table class="log-table">
                <thead>
                    <tr>
                        <th><?php _e('Status Code', 'woocommerce-lexware-mvp'); ?></th>
                        <th><?php _e('Count', 'woocommerce-lexware-mvp'); ?></th>
                        <th><?php _e('Description', 'woocommerce-lexware-mvp'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($error_stats as $stat): ?>
                        <?php
                        $status_code = trim($stat->status_code, '"');
                        $descriptions = array(
                            '400' => 'Bad Request',
                            '401' => 'Unauthorized',
                            '403' => 'Forbidden',
                            '404' => 'Not Found',
                            '409' => 'Conflict',
                            '412' => 'Precondition Failed (Optimistic Locking)',
                            '422' => 'Validation Error',
                            '429' => 'Rate Limit Exceeded',
                            '500' => 'Internal Server Error',
                            '502' => 'Bad Gateway',
                            '503' => 'Service Unavailable'
                        );
                        $description = $descriptions[$status_code] ?? 'Unknown Error';
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html($status_code); ?></strong></td>
                            <td><?php echo esc_html($stat->count); ?></td>
                            <td><?php echo esc_html($description); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="color: #00a32a;">
                <span class="dashicons dashicons-yes-alt"></span>
                <?php _e('No errors in the last 24 hours!', 'woocommerce-lexware-mvp'); ?>
            </p>
        <?php endif; ?>
        <?php
    }

    /**
     * AJAX handler for Circuit Breaker reset
     *
     * @since 1.0.0
     */
    public function ajax_reset_circuit_breaker() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'lexware_reset_circuit')) {
            wp_send_json_error(array(
                'message' => __('Sicherheitsprüfung fehlgeschlagen', 'woocommerce-lexware-mvp')
            ));
        }

        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array(
                'message' => __('Keine Berechtigung', 'woocommerce-lexware-mvp')
            ));
        }

        // Reset circuit breaker
        $circuit_breaker = new \WC_Lexware_MVP_Circuit_Breaker('lexware_api');
        $circuit_breaker->reset();

        wp_send_json_success(array(
            'message' => __('✅ Circuit Breaker wurde erfolgreich zurückgesetzt', 'woocommerce-lexware-mvp')
        ));
    }
}
