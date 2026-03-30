<?php
/**
 * Failed Documents Admin Page - Document Retry Management
 *
 * Provides interface for:
 * - Viewing all failed documents
 * - Bulk retry functionality
 * - Individual retry/delete actions
 * - Log file navigation
 *
 * @package WC_Lexware_MVP
 * @since 1.0.0
 */

namespace WC_Lexware_MVP\Admin;

if (!defined('ABSPATH')) {
    exit;
}

class Failed_Documents_Page {

    /**
     * Initialize hooks
     *
     * Note: The separate menu page has been removed.
     * Failed documents are now shown as a tab in the Buchhaltung page.
     * These handlers are kept for backward compatibility.
     */
    public static function init() {
        // Menu page removed - now integrated into Buchhaltung (Accounting_Page)
        // add_action('admin_menu', array(__CLASS__, 'add_menu_page'), 60);

        // Keep these handlers for backward compatibility
        add_action('admin_post_lexware_bulk_retry', array(__CLASS__, 'handle_bulk_retry'));
        add_action('admin_post_lexware_bulk_delete', array(__CLASS__, 'handle_bulk_delete'));
        add_action('admin_post_lexware_retry_single', array(__CLASS__, 'handle_single_retry'));
    }

    /**
     * Add submenu page under WooCommerce
     */
    public static function add_menu_page() {
        $failed_count = self::get_failed_count();

        $menu_title = 'Lexware Failed';
        if ($failed_count > 0) {
            $menu_title .= ' <span class="awaiting-mod count-' . $failed_count . '"><span class="failed-count">' . $failed_count . '</span></span>';
        }

        add_submenu_page(
            'woocommerce',
            __('Lexware Failed Documents', WC_LEXWARE_MVP_TEXT_DOMAIN),
            $menu_title,
            'manage_woocommerce',
            'lexware-failed-docs',
            array(__CLASS__, 'render_page')
        );
    }

    /**
     * Get count of failed documents
     *
     * @return int
     */
    private static function get_failed_count() {
        global $wpdb;
        $table = wc_lexware_mvp_get_table_name();

        return (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$table}
             WHERE document_status = 'failed'"
        );
    }

    /**
     * Render admin page
     */
    public static function render_page() {
        global $wpdb;
        $table = wc_lexware_mvp_get_table_name();

        // Handle success messages
        if (isset($_GET['retried'])) {
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo sprintf(
                __('%d document(s) have been rescheduled for retry.', WC_LEXWARE_MVP_TEXT_DOMAIN),
                intval($_GET['retried'])
            );
            echo '</p></div>';
        }

        if (isset($_GET['deleted'])) {
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo sprintf(
                __('%d document(s) have been deleted.', WC_LEXWARE_MVP_TEXT_DOMAIN),
                intval($_GET['deleted'])
            );
            echo '</p></div>';
        }

        // Get failed documents with pagination
        $per_page = 50;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;

        $total_failed = self::get_failed_count();
        $total_pages = ceil($total_failed / $per_page);

        $failed_docs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE document_status = 'failed'
             ORDER BY updated_at DESC
             LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ));

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">
                <?php _e('Lexware Failed Documents', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>
            </h1>

            <?php if ($total_failed === 0): ?>
                <div class="notice notice-success lexware-notice-spaced">
                    <p>
                        <strong><?php _e('No failed documents found! 🎉', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></strong><br>
                        <?php _e('All documents have been successfully synced to Lexware.', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>
                    </p>
                </div>
            <?php else: ?>

                <p class="description lexware-description-spaced">
                    <?php echo sprintf(
                        __('Found %d failed document(s) that need attention. You can retry them individually or in bulk.', WC_LEXWARE_MVP_TEXT_DOMAIN),
                        $total_failed
                    ); ?>
                </p>

                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" id="failed-docs-form">
                    <input type="hidden" name="action" value="lexware_bulk_retry">
                    <?php wp_nonce_field('lexware_bulk_action', 'lexware_nonce'); ?>

                    <div class="tablenav top">
                        <div class="alignleft actions bulkactions">
                            <select name="bulk_action" id="bulk-action-selector-top">
                                <option value=""><?php _e('Bulk Actions', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></option>
                                <option value="retry"><?php _e('Retry Selected', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></option>
                                <option value="delete"><?php _e('Delete Selected', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></option>
                            </select>
                            <button type="submit" class="button action" onclick="return handleBulkAction();">
                                <?php _e('Apply', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>
                            </button>
                        </div>

                        <?php if ($total_pages > 1): ?>
                        <div class="tablenav-pages">
                            <span class="displaying-num">
                                <?php echo sprintf(__('%s items', WC_LEXWARE_MVP_TEXT_DOMAIN), number_format_i18n($total_failed)); ?>
                            </span>
                            <?php
                            echo paginate_links(array(
                                'base' => add_query_arg('paged', '%#%'),
                                'format' => '',
                                'prev_text' => '&laquo;',
                                'next_text' => '&raquo;',
                                'total' => $total_pages,
                                'current' => $current_page
                            ));
                            ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th scope="col" class="manage-column column-cb check-column">
                                    <input type="checkbox" id="select-all-top">
                                </th>
                                <th scope="col" class="manage-column column-primary">
                                    <?php _e('Order', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>
                                </th>
                                <th scope="col" class="manage-column">
                                    <?php _e('Document Type', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>
                                </th>
                                <th scope="col" class="manage-column">
                                    <?php _e('Error', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>
                                </th>
                                <th scope="col" class="manage-column">
                                    <?php _e('Failed At', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>
                                </th>
                                <th scope="col" class="manage-column">
                                    <?php _e('Actions', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($failed_docs as $doc): ?>
                            <tr>
                                <th scope="row" class="check-column">
                                    <input type="checkbox" name="doc_ids[]" value="<?php echo esc_attr($doc->id); ?>">
                                </th>
                                <td class="column-primary" data-colname="<?php _e('Order', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>">
                                    <strong>
                                        <a href="<?php echo esc_url(admin_url('post.php?post=' . $doc->order_id . '&action=edit')); ?>">
                                            #<?php echo $doc->order_id; ?>
                                        </a>
                                    </strong>
                                    <?php if ($doc->refund_id): ?>
                                        <br>
                                        <span class="description">
                                            <?php echo sprintf(__('Refund ID: %d', WC_LEXWARE_MVP_TEXT_DOMAIN), $doc->refund_id); ?>
                                        </span>
                                    <?php endif; ?>
                                    <button type="button" class="toggle-row"><span class="screen-reader-text"><?php _e('Show more details', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></span></button>
                                </td>
                                <td data-colname="<?php _e('Document Type', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>">
                                    <span class="dashicons dashicons-<?php echo $doc->document_type === 'invoice' ? 'media-document' : 'editor-removeformatting'; ?>"></span>
                                    <?php echo ucfirst(str_replace('_', ' ', $doc->document_type)); ?>
                                </td>
                                <td data-colname="<?php _e('Error', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>" class="column-error">
                                    <?php
                                    // Extract error from document_meta JSON
                                    $meta = json_decode($doc->document_meta, true);
                                    $error_message = $meta['last_error'] ?? __('Unknown error', WC_LEXWARE_MVP_TEXT_DOMAIN);

                                    // Truncate long errors
                                    $error_short = strlen($error_message) > 100
                                        ? substr($error_message, 0, 100) . '...'
                                        : $error_message;
                                    ?>
                                    <span class="description" title="<?php echo esc_attr($error_message); ?>">
                                        <?php echo esc_html($error_short); ?>
                                    </span>
                                </td>
                                <td data-colname="<?php _e('Failed At', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>">
                                    <?php
                                    echo human_time_diff(strtotime($doc->updated_at), current_time('timestamp')) . ' ago';
                                    ?>
                                    <br>
                                    <span class="description">
                                        <?php echo date_i18n('Y-m-d H:i', strtotime($doc->updated_at)); ?>
                                    </span>
                                </td>
                                <td data-colname="<?php _e('Actions', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>">
                                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=lexware_retry_single&doc_id=' . $doc->id), 'lexware_retry_single')); ?>"
                                       class="button button-small">
                                        <?php _e('Retry', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>
                                    </a>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=wc-status&tab=logs&log_file=' . self::get_log_file_for_order($doc->order_id))); ?>"
                                       class="button button-small"
                                       target="_blank">
                                        <?php _e('View Log', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th scope="col" class="manage-column column-cb check-column">
                                    <input type="checkbox" id="select-all-bottom">
                                </th>
                                <th scope="col" class="manage-column column-primary"><?php _e('Order', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></th>
                                <th scope="col" class="manage-column"><?php _e('Document Type', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></th>
                                <th scope="col" class="manage-column"><?php _e('Error', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></th>
                                <th scope="col" class="manage-column"><?php _e('Failed At', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></th>
                                <th scope="col" class="manage-column"><?php _e('Actions', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></th>
                            </tr>
                        </tfoot>
                    </table>

                    <div class="tablenav bottom">
                        <?php if ($total_pages > 1): ?>
                        <div class="tablenav-pages">
                            <?php
                            echo paginate_links(array(
                                'base' => add_query_arg('paged', '%#%'),
                                'format' => '',
                                'prev_text' => '&laquo;',
                                'next_text' => '&raquo;',
                                'total' => $total_pages,
                                'current' => $current_page
                            ));
                            ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </form>

            <?php endif; ?>
        </div>

        <script type="text/javascript">
            // Select all checkboxes
            document.getElementById('select-all-top')?.addEventListener('change', function(e) {
                const checkboxes = document.querySelectorAll('input[name="doc_ids[]"]');
                checkboxes.forEach(cb => cb.checked = e.target.checked);
                document.getElementById('select-all-bottom').checked = e.target.checked;
            });

            document.getElementById('select-all-bottom')?.addEventListener('change', function(e) {
                const checkboxes = document.querySelectorAll('input[name="doc_ids[]"]');
                checkboxes.forEach(cb => cb.checked = e.target.checked);
                document.getElementById('select-all-top').checked = e.target.checked;
            });

            // Handle bulk action
            function handleBulkAction() {
                const action = document.getElementById('bulk-action-selector-top').value;
                const form = document.getElementById('failed-docs-form');
                const checked = document.querySelectorAll('input[name="doc_ids[]"]:checked');

                if (checked.length === 0) {
                    alert('<?php _e('Please select at least one document.', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>');
                    return false;
                }

                if (action === 'delete') {
                    if (!confirm('<?php _e('Are you sure you want to delete the selected documents? This cannot be undone.', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>')) {
                        return false;
                    }
                    form.action = '<?php echo admin_url('admin-post.php?action=lexware_bulk_delete'); ?>';
                } else if (action === 'retry') {
                    form.action = '<?php echo admin_url('admin-post.php?action=lexware_bulk_retry'); ?>';
                } else {
                    alert('<?php _e('Please select an action.', WC_LEXWARE_MVP_TEXT_DOMAIN); ?>');
                    return false;
                }

                return true;
            }
        </script>
        <?php
    }

    /**
     * Handle bulk retry action
     */
    public static function handle_bulk_retry() {
        check_admin_referer('lexware_bulk_action', 'lexware_nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Insufficient permissions.', WC_LEXWARE_MVP_TEXT_DOMAIN));
        }

        $doc_ids = isset($_POST['doc_ids']) ? array_map('intval', $_POST['doc_ids']) : array();
        $retried_count = 0;

        foreach ($doc_ids as $doc_id) {
            if (self::retry_document($doc_id)) {
                $retried_count++;
            }
        }

        \WC_Lexware_MVP_Logger::info('Bulk retry action executed', array(
            'doc_ids' => $doc_ids,
            'retried_count' => $retried_count,
            'user_id' => get_current_user_id(),
        ));

        wp_redirect(add_query_arg(array(
            'page' => 'lexware-failed-docs',
            'retried' => $retried_count
        ), admin_url('admin.php')));
        exit;
    }

    /**
     * Handle bulk delete action
     */
    public static function handle_bulk_delete() {
        check_admin_referer('lexware_bulk_action', 'lexware_nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Insufficient permissions.', WC_LEXWARE_MVP_TEXT_DOMAIN));
        }

        global $wpdb;
        $table = wc_lexware_mvp_get_table_name(false);
        $doc_ids = isset($_POST['doc_ids']) ? array_map('intval', $_POST['doc_ids']) : array();
        $deleted_count = 0;

        foreach ($doc_ids as $doc_id) {
            $result = $wpdb->delete($table, array('id' => $doc_id), array('%d'));
            if ($result !== false) {
                $deleted_count++;
            }
        }

        \WC_Lexware_MVP_Logger::warning('Bulk delete action executed', array(
            'doc_ids' => $doc_ids,
            'deleted_count' => $deleted_count,
            'user_id' => get_current_user_id(),
        ));

        wp_redirect(add_query_arg(array(
            'page' => 'lexware-failed-docs',
            'deleted' => $deleted_count
        ), admin_url('admin.php')));
        exit;
    }

    /**
     * Handle single retry action
     */
    public static function handle_single_retry() {
        check_admin_referer('lexware_retry_single');

        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Insufficient permissions.', WC_LEXWARE_MVP_TEXT_DOMAIN));
        }

        $doc_id = isset($_GET['doc_id']) ? intval($_GET['doc_id']) : 0;

        if ($doc_id && self::retry_document($doc_id)) {
            \WC_Lexware_MVP_Logger::info('Single retry action executed', array(
                'doc_id' => $doc_id,
                'user_id' => get_current_user_id(),
            ));

            wp_redirect(add_query_arg(array(
                'page' => 'lexware-failed-docs',
                'retried' => 1
            ), admin_url('admin.php')));
        } else {
            wp_redirect(add_query_arg(array(
                'page' => 'lexware-failed-docs',
                'error' => 1
            ), admin_url('admin.php')));
        }
        exit;
    }

    /**
     * Retry a single document
     *
     * @param int $doc_id Document ID
     * @return bool Success
     */
    private static function retry_document($doc_id) {
        global $wpdb;
        $table = wc_lexware_mvp_get_table_name(false);

        $doc = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . wc_lexware_mvp_get_table_name() . " WHERE id = %d",
            $doc_id
        ));

        if (!$doc) {
            return false;
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

        return true;
    }

    /**
     * Find log file containing order_id
     *
     * @param int $order_id Order ID
     * @return string Log filename
     */
    private static function get_log_file_for_order($order_id) {
        if (!defined('WC_LOG_DIR')) {
            return '';
        }

        $log_files = glob(WC_LOG_DIR . 'wc-lexware-mvp-*.log');
        if (empty($log_files)) {
            return '';
        }

        // Sort by modification time (newest first)
        usort($log_files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        // Search for order_id in recent log files (max 5)
        foreach (array_slice($log_files, 0, 5) as $file) {
            $content = file_get_contents($file);
            if (strpos($content, '"order_id":' . $order_id) !== false ||
                strpos($content, "'order_id' => " . $order_id) !== false) {
                return basename($file);
            }
        }

        // Fallback: return most recent log file
        return basename($log_files[0]);
    }
}
