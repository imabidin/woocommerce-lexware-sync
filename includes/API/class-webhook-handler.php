<?php
/**
 * Webhook Handler - Empfang und Verarbeitung von Lexware Webhooks
 *
 * Registriert WooCommerce API Endpoint: /wc-api/lexware-webhook
 *
 * Unterstützte Events:
 * - invoice.created
 * - invoice.changed
 * - invoice.status.changed
 * - credit-note.created
 * - credit-note.status.changed
 * - payment.changed
 *
 * @package WooCommerce_Lexware_MVP
 * @since 0.4.0
 */

namespace WC_Lexware_MVP\API;

if (!defined('ABSPATH')) {
    exit;
}

class Webhook_Handler {

    /**
     * Initialize webhook handler
     */
    public static function init() {
        // Register WooCommerce API endpoint
        add_action('woocommerce_api_lexware-webhook', array(__CLASS__, 'handle_webhook'));

        \WC_Lexware_MVP_Logger::debug('Webhook handler initialized', array(
            'endpoint' => home_url('/wc-api/lexware-webhook')
        ));
    }

    /**
     * Handle incoming webhook from Lexware
     */
    public static function handle_webhook() {
        // Get raw payload
        $payload = file_get_contents('php://input');

        // Get signature from header
        $signature = isset($_SERVER['HTTP_X_LXO_SIGNATURE']) ? $_SERVER['HTTP_X_LXO_SIGNATURE'] : '';

        \WC_Lexware_MVP_Logger::info('Webhook received', array(
            'payload_length' => strlen($payload),
            'has_signature' => !empty($signature),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ));

        // Verify signature (SECURITY CRITICAL!)
        $client = new Client();
        if (!$client->verify_webhook_signature($payload, $signature)) {
            \WC_Lexware_MVP_Logger::warning('Webhook rejected: Invalid signature', array(
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'payload_preview' => substr($payload, 0, 100)
            ));

            http_response_code(401);
            wp_send_json_error(array(
                'message' => 'Invalid signature'
            ), 401);
            exit;
        }

        // Parse webhook data
        $data = json_decode($payload, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            \WC_Lexware_MVP_Logger::error('Webhook JSON parsing failed', array(
                'error' => json_last_error_msg()
            ));

            http_response_code(400);
            wp_send_json_error(array(
                'message' => 'Invalid JSON'
            ), 400);
            exit;
        }

        // Validate required fields
        if (empty($data['eventType']) || empty($data['resourceId'])) {
            \WC_Lexware_MVP_Logger::error('Webhook missing required fields', array(
                'data' => $data
            ));

            http_response_code(400);
            wp_send_json_error(array(
                'message' => 'Missing eventType or resourceId'
            ), 400);
            exit;
        }

        // Log successful webhook
        \WC_Lexware_MVP_Logger::info('Webhook signature verified successfully', array(
            'event_type' => $data['eventType'],
            'resource_id' => $data['resourceId'],
            'organization_id' => $data['organizationId'] ?? 'unknown'
        ));

        // Process webhook based on event type
        $result = self::process_webhook_event($data);

        if (is_wp_error($result)) {
            \WC_Lexware_MVP_Logger::error('Webhook processing failed', array(
                'event_type' => $data['eventType'],
                'error' => $result->get_error_message()
            ));

            http_response_code(500);
            wp_send_json_error(array(
                'message' => $result->get_error_message()
            ), 500);
            exit;
        }

        // Return 200 OK
        \WC_Lexware_MVP_Logger::debug('Webhook processed successfully', array(
            'event_type' => $data['eventType']
        ));

        wp_send_json_success(array(
            'message' => 'Webhook processed',
            'event_type' => $data['eventType']
        ), 200);
    }

    /**
     * Process webhook event based on type
     *
     * @param array $data Webhook data
     * @return true|\WP_Error True on success, WP_Error on failure
     */
    private static function process_webhook_event($data) {
        $event_type = $data['eventType'];
        $resource_id = $data['resourceId'];

        switch ($event_type) {
            case 'invoice.created':
                return self::handle_invoice_created($resource_id, $data);

            case 'invoice.changed':
                return self::handle_invoice_changed($resource_id, $data);

            case 'invoice.status.changed':
                return self::handle_invoice_status_changed($resource_id, $data);

            case 'credit-note.created':
                return self::handle_credit_note_created($resource_id, $data);

            case 'credit-note.status.changed':
                return self::handle_credit_note_status_changed($resource_id, $data);

            case 'payment.changed':
                return self::handle_payment_changed($resource_id, $data);

            default:
                \WC_Lexware_MVP_Logger::info('Webhook event type not handled', array(
                    'event_type' => $event_type
                ));
                return true; // Not an error, just not implemented yet
        }
    }

    /**
     * Handle invoice.created event
     *
     * @param string $invoice_id Lexware Invoice ID
     * @param array $data Webhook data
     * @return true|\WP_Error
     */
    private static function handle_invoice_created($invoice_id, $data) {
        \WC_Lexware_MVP_Logger::info('Processing invoice.created webhook', array(
            'invoice_id' => $invoice_id
        ));

        // Find WooCommerce order by Lexware invoice ID
        $order = self::find_order_by_lexware_id($invoice_id, 'invoice');

        if (!$order) {
            // Invoice wurde manuell in Lexware erstellt (nicht via Plugin)
            \WC_Lexware_MVP_Logger::debug('No WooCommerce order found for invoice', array(
                'invoice_id' => $invoice_id
            ));
            return true;
        }

        // Update order note
        $order->add_order_note(
            sprintf(__('Lexware Invoice created: %s (Webhook)', 'woocommerce-lexware-mvp'), $invoice_id)
        );

        return true;
    }

    /**
     * Handle invoice.changed event
     *
     * @param string $invoice_id Lexware Invoice ID
     * @param array $data Webhook data
     * @return true|\WP_Error
     */
    private static function handle_invoice_changed($invoice_id, $data) {
        \WC_Lexware_MVP_Logger::info('Processing invoice.changed webhook', array(
            'invoice_id' => $invoice_id
        ));

        // Find order and update
        $order = self::find_order_by_lexware_id($invoice_id, 'invoice');

        if ($order) {
            $order->add_order_note(
                sprintf(__('Lexware Invoice updated: %s (Webhook)', 'woocommerce-lexware-mvp'), $invoice_id)
            );
        }

        return true;
    }

    /**
     * Handle invoice.status.changed event
     *
     * @param string $invoice_id Lexware Invoice ID
     * @param array $data Webhook data
     * @return true|\WP_Error
     */
    private static function handle_invoice_status_changed($invoice_id, $data) {
        \WC_Lexware_MVP_Logger::info('Processing invoice.status.changed webhook', array(
            'invoice_id' => $invoice_id
        ));

        // Find order
        $order = self::find_order_by_lexware_id($invoice_id, 'invoice');

        if (!$order) {
            return true;
        }

        // Get current invoice status from API
        $client = new Client();
        $invoice = $client->get_invoice($invoice_id);

        if (is_wp_error($invoice)) {
            return $invoice;
        }

        $status = $invoice['voucherStatus'] ?? 'unknown';

        // Update order note
        $order->add_order_note(
            sprintf(__('Lexware Invoice status changed to: %s (Webhook)', 'woocommerce-lexware-mvp'), $status)
        );

        // If invoice is paid, mark order as completed (optional)
        if ($status === 'paid' && $order->get_status() !== 'completed') {
            $auto_complete = get_option('wc_lexware_mvp_auto_complete_on_paid', 'no');

            if ($auto_complete === 'yes') {
                $order->update_status('completed', __('Order auto-completed: Lexware invoice paid (Webhook)', 'woocommerce-lexware-mvp'));

                \WC_Lexware_MVP_Logger::info('Order auto-completed via webhook', array(
                    'order_id' => $order->get_id(),
                    'invoice_id' => $invoice_id
                ));
            }
        }

        return true;
    }

    /**
     * Handle credit-note.created event
     *
     * @param string $cn_id Lexware Credit Note ID
     * @param array $data Webhook data
     * @return true|\WP_Error
     */
    private static function handle_credit_note_created($cn_id, $data) {
        \WC_Lexware_MVP_Logger::info('Processing credit-note.created webhook', array(
            'credit_note_id' => $cn_id
        ));

        $order = self::find_order_by_lexware_id($cn_id, 'credit_note');

        if ($order) {
            $order->add_order_note(
                sprintf(__('Lexware Credit Note created: %s (Webhook)', 'woocommerce-lexware-mvp'), $cn_id)
            );
        }

        return true;
    }

    /**
     * Handle credit-note.status.changed event
     *
     * @param string $cn_id Lexware Credit Note ID
     * @param array $data Webhook data
     * @return true|\WP_Error
     */
    private static function handle_credit_note_status_changed($cn_id, $data) {
        \WC_Lexware_MVP_Logger::info('Processing credit-note.status.changed webhook', array(
            'credit_note_id' => $cn_id
        ));

        $order = self::find_order_by_lexware_id($cn_id, 'credit_note');

        if ($order) {
            // Get current credit note status from API
            $client = new Client();
            $cn = $client->get_credit_note($cn_id);

            if (!is_wp_error($cn)) {
                $status = $cn['voucherStatus'] ?? 'unknown';
                $order->add_order_note(
                    sprintf(__('Lexware Credit Note status: %s (Webhook)', 'woocommerce-lexware-mvp'), $status)
                );
            }
        }

        return true;
    }

    /**
     * Handle payment.changed event
     *
     * @param string $resource_id Invoice or Credit Note ID
     * @param array $data Webhook data
     * @return true|\WP_Error
     */
    private static function handle_payment_changed($resource_id, $data) {
        \WC_Lexware_MVP_Logger::info('Processing payment.changed webhook', array(
            'resource_id' => $resource_id
        ));

        // Try to find order (could be invoice or credit note)
        $order = self::find_order_by_lexware_id($resource_id, 'invoice');

        if (!$order) {
            $order = self::find_order_by_lexware_id($resource_id, 'credit_note');
        }

        if ($order) {
            $order->add_order_note(
                sprintf(__('Lexware Payment changed for: %s (Webhook)', 'woocommerce-lexware-mvp'), $resource_id)
            );
        }

        return true;
    }

    /**
     * Find WooCommerce order by Lexware document ID
     *
     * @param string $lexware_id Lexware Invoice or Credit Note ID
     * @param string $type 'invoice' or 'credit_note'
     * @return \WC_Order|null Order object or null
     */
    private static function find_order_by_lexware_id($lexware_id, $type = 'invoice') {
        if (empty($lexware_id)) {
            return null;
        }

        $meta_key = $type === 'invoice' ? '_lexware_invoice_id' : '_lexware_credit_note_id';

        // Query orders with Lexware ID
        $orders = wc_get_orders(array(
            'limit' => 1,
            'meta_key' => $meta_key,
            'meta_value' => $lexware_id,
            'return' => 'objects'
        ));

        if (!empty($orders)) {
            return $orders[0];
        }

        return null;
    }
}
