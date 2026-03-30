<?php
/**
 * REST API Documents Controller
 *
 * Exposes wp_lexware_mvp_documents table via WP REST API.
 * Authenticated via WooCommerce consumer key/secret (Basic Auth).
 *
 * Endpoints:
 *   GET /wp-json/wc-lexware-mvp/v1/documents
 *   GET /wp-json/wc-lexware-mvp/v1/documents?order_id=8085
 *   GET /wp-json/wc-lexware-mvp/v1/documents?document_type=invoice
 *
 * @package WC_Lexware_MVP
 * @since 1.3.1
 */

namespace WC_Lexware_MVP\API;

if (!defined('ABSPATH')) {
    exit;
}

class Rest_Documents extends \WP_REST_Controller {

    /**
     * Route namespace
     *
     * @var string
     */
    protected $namespace = 'wc-lexware-mvp/v1';

    /**
     * Route base
     *
     * @var string
     */
    protected $rest_base = 'documents';

    /**
     * Register REST routes
     *
     * @since 1.3.1
     */
    public function register_routes() {
        register_rest_route($this->namespace, '/' . $this->rest_base, array(
            array(
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => array($this, 'get_items'),
                'permission_callback' => array($this, 'get_items_permissions_check'),
                'args'                => $this->get_collection_params(),
            ),
        ));
    }

    /**
     * Permission check: requires manage_woocommerce capability
     *
     * Works with WooCommerce consumer key/secret via Basic Auth,
     * because WC maps API keys to a WP user with appropriate capabilities.
     *
     * @since 1.3.1
     * @param \WP_REST_Request $request
     * @return bool|\WP_Error
     */
    public function get_items_permissions_check($request) {
        // Let WooCommerce authenticate the request via API keys
        if (function_exists('wc_rest_check_manager_permissions')) {
            return wc_rest_check_manager_permissions('settings', 'read');
        }

        // Fallback: check capability directly
        if (!current_user_can('manage_woocommerce')) {
            return new \WP_Error(
                'rest_forbidden',
                __('You do not have permission to access this endpoint.', 'wc-lexware-mvp'),
                array('status' => 403)
            );
        }

        return true;
    }

    /**
     * Get collection of documents
     *
     * @since 1.3.1
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_items($request) {
        global $wpdb;

        $table_name = wc_lexware_mvp_get_table_name(false);

        // Build WHERE clauses
        $where = array();
        $values = array();

        if ($request->get_param('order_id')) {
            $where[] = 'order_id = %d';
            $values[] = absint($request->get_param('order_id'));
        }

        if ($request->get_param('document_type')) {
            $where[] = 'document_type = %s';
            $values[] = sanitize_text_field($request->get_param('document_type'));
        }

        if ($request->get_param('document_status')) {
            $where[] = 'document_status = %s';
            $values[] = sanitize_text_field($request->get_param('document_status'));
        }

        if ($request->get_param('user_id')) {
            $where[] = 'user_id = %d';
            $values[] = absint($request->get_param('user_id'));
        }

        if ($request->get_param('refund_id')) {
            $where[] = 'refund_id = %d';
            $values[] = absint($request->get_param('refund_id'));
        }

        // Date range filters
        if ($request->get_param('created_after')) {
            $where[] = 'created_at >= %s';
            $values[] = sanitize_text_field($request->get_param('created_after'));
        }

        if ($request->get_param('created_before')) {
            $where[] = 'created_at <= %s';
            $values[] = sanitize_text_field($request->get_param('created_before'));
        }

        $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Pagination
        $per_page = absint($request->get_param('per_page')) ?: 100;
        $per_page = min($per_page, 500);
        $page = absint($request->get_param('page')) ?: 1;
        $offset = ($page - 1) * $per_page;

        // Count total
        $count_sql = "SELECT COUNT(*) FROM {$table_name} {$where_sql}";
        if (!empty($values)) {
            $count_sql = $wpdb->prepare($count_sql, ...$values);
        }
        $total = (int) $wpdb->get_var($count_sql);

        // Fetch rows
        $sql = "SELECT * FROM {$table_name} {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
        $all_values = array_merge($values, array($per_page, $offset));
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$all_values), ARRAY_A);

        if ($rows === null) {
            return new \WP_Error(
                'rest_db_error',
                __('Database query failed.', 'wc-lexware-mvp'),
                array('status' => 500)
            );
        }

        // Cast numeric fields
        $items = array_map(array($this, 'prepare_item'), $rows);

        $response = rest_ensure_response($items);

        // Pagination headers
        $response->header('X-WP-Total', $total);
        $response->header('X-WP-TotalPages', ceil($total / $per_page));

        return $response;
    }

    /**
     * Prepare a single document item for response
     *
     * @since 1.3.1
     * @param array $row Database row
     * @return array Cleaned item
     */
    private function prepare_item($row) {
        return array(
            'id'                    => (int) $row['id'],
            'user_id'               => $row['user_id'] ? (int) $row['user_id'] : null,
            'order_id'              => (int) $row['order_id'],
            'contact_id'            => $row['contact_id'] ?: null,
            'document_id'           => $row['document_id'] ?: null,
            'document_nr'           => $row['document_nr'] ?: null,
            'document_type'         => $row['document_type'],
            'document_filename'     => $row['document_filename'] ?: null,
            'document_status'       => $row['document_status'],
            'document_meta'         => $row['document_meta'] ? json_decode($row['document_meta'], true) : null,
            'document_finalized'    => (bool) $row['document_finalized'],
            'preceding_document_id' => $row['preceding_document_id'] ?: null,
            'preceding_document_nr' => $row['preceding_document_nr'] ?: null,
            'refund_id'             => $row['refund_id'] ? (int) $row['refund_id'] : null,
            'refund_reason'         => $row['refund_reason'] ?: null,
            'refund_amount'         => $row['refund_amount'] ? (float) $row['refund_amount'] : null,
            'refund_full'           => (bool) $row['refund_full'],
            'created_at'            => $row['created_at'],
            'updated_at'            => $row['updated_at'],
            'synced_at'             => $row['synced_at'] ?: null,
            'email_sent_at'         => $row['email_sent_at'] ?: null,
        );
    }

    /**
     * Get query parameters for collection
     *
     * @since 1.3.1
     * @return array
     */
    public function get_collection_params() {
        return array(
            'page' => array(
                'description' => 'Current page of the collection.',
                'type'        => 'integer',
                'default'     => 1,
                'minimum'     => 1,
            ),
            'per_page' => array(
                'description' => 'Maximum number of items per page.',
                'type'        => 'integer',
                'default'     => 100,
                'minimum'     => 1,
                'maximum'     => 500,
            ),
            'order_id' => array(
                'description' => 'Filter by WooCommerce Order ID.',
                'type'        => 'integer',
            ),
            'document_type' => array(
                'description' => 'Filter by document type (invoice, credit_note, order_confirmation).',
                'type'        => 'string',
                'enum'        => array('invoice', 'credit_note', 'order_confirmation'),
            ),
            'document_status' => array(
                'description' => 'Filter by document status.',
                'type'        => 'string',
                'enum'        => array('pending', 'processing', 'synced', 'failed'),
            ),
            'user_id' => array(
                'description' => 'Filter by WooCommerce Customer User ID.',
                'type'        => 'integer',
            ),
            'refund_id' => array(
                'description' => 'Filter by WooCommerce Refund ID.',
                'type'        => 'integer',
            ),
            'created_after' => array(
                'description' => 'Filter documents created after this date (YYYY-MM-DD).',
                'type'        => 'string',
                'format'      => 'date',
            ),
            'created_before' => array(
                'description' => 'Filter documents created before this date (YYYY-MM-DD).',
                'type'        => 'string',
                'format'      => 'date',
            ),
        );
    }
}
