<?php
/**
 * API Client - Communication with Lexware Office API
 *
 * Handles all HTTP requests to Lexware API including authentication,
 * rate limiting, circuit breaker pattern, and error handling.
 *
 * @package WC_Lexware_MVP
 * @since 1.0.0
 */

namespace WC_Lexware_MVP\API;

class Client {

    /**
     * API Base URL - Lexware Domain (since May 2025)
     *
     * @since 1.0.0
     */
    const API_URL_LIVE = 'https://api.lexware.io/v1';

    /**
     * API Token
     *
     * @since 1.0.0
     * @var string
     */
    private $token;

    /**
     * Circuit Breaker Instance
     *
     * @since 1.0.0
     * @var \WC_Lexware_MVP_Circuit_Breaker
     */
    private $circuit_breaker;

    /**
     * Constructor
     *
     * Loads and decrypts API token, initializes Circuit Breaker.
     *
     * @since 1.0.0
     */
    public function __construct() {
        // Token laden und entschlüsseln
        $encrypted_token = get_option('wc_lexware_mvp_api_token', '');
        $this->token = !empty($encrypted_token) ? \WC_Lexware_MVP_Encryptor::decrypt($encrypted_token) : '';

        // Initialize Circuit Breaker with configured values
        $failure_threshold = get_option('wc_lexware_mvp_circuit_failure_threshold', 5);
        $success_threshold = get_option('wc_lexware_mvp_circuit_success_threshold', 2);
        $timeout = get_option('wc_lexware_mvp_circuit_timeout', 60);

        $this->circuit_breaker = new \WC_Lexware_MVP_Circuit_Breaker(
            'lexware_api',
            $failure_threshold,
            $success_threshold,
            $timeout
        );
    }

    /**
     * Get cache key for connection test
     *
     * @since 1.0.0
     * @return string Cache key based on token hash, e.g., "lexware_connection_test_abc123"
     */
    private function get_cache_key() {
        return 'lexware_connection_test_' . md5($this->token);
    }

    /**
     * Clear connection test cache
     *
     * Call this when token is changed or API status should be re-validated.
     *
     * @since 1.0.0
     */
    public function clear_connection_cache() {
        $cache_key = $this->get_cache_key();
        delete_transient($cache_key);
        \WC_Lexware_MVP_Logger::debug('Connection test cache cleared', array(
            'cache_key' => $cache_key
        ));
    }

    /**
     * Test API connection with caching
     *
     * Tests connection to Lexware API /profile endpoint.
     * Results are cached for 15 minutes to reduce API calls.
     *
     * @since 1.0.0
     * @param bool $force_refresh Bypass cache and force real API call. Default false.
     * @return array|\WP_Error Success data or error
     */
    public function test_connection($force_refresh = false) {
        if (empty($this->token)) {
            return new \WP_Error('no_token', 'Kein API-Token konfiguriert');
        }

        // Check cache unless force refresh
        $cache_key = $this->get_cache_key();
        if (!$force_refresh) {
            $cached = get_transient($cache_key);
            if ($cached !== false) {
                \WC_Lexware_MVP_Logger::debug('Connection test: Cache HIT', array(
                    'cache_key' => $cache_key,
                    'cached_at' => isset($cached['cached_at']) ? date('Y-m-d H:i:s', $cached['cached_at']) : 'unknown'
                ));
                return $cached;
            }
        }

        // Cache MISS - perform actual API request
        \WC_Lexware_MVP_Logger::debug('Connection test: Cache MISS - calling API', array(
            'cache_key' => $cache_key,
            'force_refresh' => $force_refresh
        ));

        // Profile-Endpoint testen
        $response = $this->request('GET', '/profile');

        if (\is_wp_error($response)) {
            // Cache ERROR for 5 minutes (shorter than success)
            set_transient($cache_key, $response, 5 * MINUTE_IN_SECONDS);
            \WC_Lexware_MVP_Logger::debug('Connection test: Caching ERROR for 5min', array(
                'error_code' => $response->get_error_code(),
                'error_message' => $response->get_error_message()
            ));
            return $response;
        }

        // Cache SUCCESS for 1 hour
        $result = array(
            'success' => true,
            'message' => 'Verbindung erfolgreich',
            'data' => $response,
            'cached_at' => time()
        );
        set_transient($cache_key, $result, HOUR_IN_SECONDS);
        \WC_Lexware_MVP_Logger::debug('Connection test: Caching SUCCESS for 1h', array(
            'cached_at' => date('Y-m-d H:i:s', $result['cached_at'])
        ));

        return $result;
    }

    /**
     * Suche Kontakt nach Email
     *
     * Uses HTML entity encoding + URL encoding for special characters
     * as required by Lexware API.
     *
     * @param string $email Email-Adresse
     * @return array|\WP_Error Kontakt-Daten oder Fehler
     */
    public function search_contact_by_email($email) {
        // Encode email with HTML entities + URL encoding
        $email_encoded = $this->encode_search_string($email);

        \WC_Lexware_MVP_Logger::debug('Contact search by email', array(
            'email_raw' => $email,
            'email_encoded' => $email_encoded
        ));

        $response = $this->request('GET', '/contacts', array(
            'email' => $email_encoded
        ));

        if (\is_wp_error($response)) {
            return $response;
        }

        // Erster Kontakt zurückgeben wenn vorhanden
        if (!empty($response['content']) && is_array($response['content'])) {
            return $response['content'][0];
        }

        return null;
    }

    /**
     * Erstelle neuen Kontakt
     *
     * @param array $contact_data Kontaktdaten
     * @return array|\WP_Error Kontakt-Daten oder Fehler
     */
    public function create_contact($contact_data) {
        return $this->request('POST', '/contacts', array(), $contact_data);
    }

    /**
     * Erstelle Rechnung
     *
     * @param array $invoice_data Rechnungsdaten
     * @param bool $finalize Sofort finalisieren (true = finale Rechnung, false = Entwurf)
     * @param int|null $order_id WooCommerce Order ID (for idempotency)
     * @return array|\WP_Error Rechnungs-Daten oder Fehler
     */
    public function create_invoice($invoice_data, $finalize = false, $order_id = null) {
        $start_time = microtime(true);

        $query_params = array();

        if ($finalize) {
            $query_params['finalize'] = 'true';
        }

        // Add idempotency key if order_id provided
        $custom_headers = array();
        if ($order_id) {
            $idempotency_key = \WC_Lexware_MVP_Idempotency_Manager::get_key($order_id, 'create_invoice');
            $custom_headers['X-Idempotency-Key'] = $idempotency_key;

            \WC_Lexware_MVP_Logger::debug('Invoice request with idempotency key', array(
                'order_id' => $order_id,
                'key' => $idempotency_key
            ));
        }

        $result = $this->request('POST', '/invoices', $query_params, $invoice_data, $custom_headers);

        $duration_ms = round((microtime(true) - $start_time) * 1000, 2);
        \WC_Lexware_MVP_Logger::info('Invoice creation API call completed', array(
            'order_id' => $order_id,
            'finalize' => $finalize,
            'duration_ms' => $duration_ms,
            'success' => !\is_wp_error($result)
        ));

        return $result;
    }

    /**
     * Hole Rechnungsdetails
     *
     * @param string $invoice_id Rechnungs-ID
     * @return array|\WP_Error Rechnungs-Daten oder Fehler
     */
    public function get_invoice($invoice_id) {
        return $this->request('GET', "/invoices/{$invoice_id}");
    }

    /**
     * Update Invoice mit Optimistic Locking Support
     *
     * WICHTIG: version_id MUSS aus vorherigem get_invoice() Aufruf stammen!
     * Wenn version_id nicht übereinstimmt, gibt Lexware 412 Precondition Failed zurück.
     *
     * @since 0.4.0 (Phase 2)
     * @param string $invoice_id Invoice ID
     * @param array $invoice_data Update-Daten (nur geänderte Felder)
     * @param string $version_id version_id aus GET Response (für Optimistic Locking)
     * @return array|\WP_Error Updated invoice oder Error
     */
    public function update_invoice($invoice_id, $invoice_data, $version_id) {
        if (empty($version_id)) {
            return new \WP_Error(
                'missing_version_id',
                __('version_id is required for Optimistic Locking. Call get_invoice() first.', 'woocommerce-lexware-mvp')
            );
        }

        \WC_Lexware_MVP_Logger::debug('Updating invoice with Optimistic Locking', array(
            'invoice_id' => $invoice_id,
            'version_id' => $version_id
        ));

        return $this->request('PUT', "/invoices/{$invoice_id}", array(), $invoice_data, array(), $version_id);
    }

    /**
     * Lade PDF herunter (using modern invoice file endpoint)
     *
     * @param string $invoice_id Invoice ID
     * @return string|\WP_Error PDF-Binärdaten oder Fehler
     */
    public function download_invoice_pdf($invoice_id) {
        $start_time = microtime(true);
        $url = self::API_URL_LIVE . '/invoices/' . $invoice_id . '/file';

        // First, check Content-Length header to validate PDF size before downloading
        $head_response = wp_remote_head($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->token,
            ),
            'timeout' => 10
        ));

        if (!\is_wp_error($head_response)) {
            $content_length = wp_remote_retrieve_header($head_response, 'content-length');
            if ($content_length && $content_length > 50 * 1024 * 1024) { // > 50MB
                return new \WP_Error(
                    'pdf_too_large',
                    sprintf(__('PDF file is too large (%s MB). Maximum allowed: 50 MB', 'woocommerce-lexware-mvp'),
                        round($content_length / 1024 / 1024, 2)
                    ),
                    array('size_bytes' => $content_length)
                );
            }
        }

        // Direct download (PDFs are typically < 1 MB, streaming nicht nötig)
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->token,
                'Accept' => 'application/pdf'
            ),
            'timeout' => 25 // Slightly less than 30s gateway timeout
        ));

        if (\is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $body = wp_remote_retrieve_body($response);
            return new \WP_Error('api_error', 'PDF-Download fehlgeschlagen: ' . $body, array('status' => $code));
        }

        $pdf_data = wp_remote_retrieve_body($response);
        $duration_ms = round((microtime(true) - $start_time) * 1000, 2);

        \WC_Lexware_MVP_Logger::debug('PDF download completed', array(
            'invoice_id' => $invoice_id,
            'size_bytes' => strlen($pdf_data),
            'duration_ms' => $duration_ms
        ));

        return $pdf_data;
    }

    /**
     * Create Credit Note
     *
     * @param array $credit_note_data Credit Note Daten
     * @param bool $finalize Ob Gutschrift finalisiert werden soll
     * @param string $invoice_id Invoice ID für Verknüpfung (precedingSalesVoucherId)
     * @return array|\WP_Error Response oder Fehler
     */
    public function create_credit_note($credit_note_data, $finalize = false, $invoice_id = null) {
        $start_time = microtime(true);

        $query = array();

        // Nur bei true explizit senden (API Best Practice)
        if ($finalize) {
            $query['finalize'] = 'true';
        }

        // precedingSalesVoucherId für Verknüpfung mit Invoice
        if ($invoice_id) {
            $query['precedingSalesVoucherId'] = $invoice_id;
        }

        $result = $this->request('POST', '/credit-notes', $query, $credit_note_data);

        $duration_ms = round((microtime(true) - $start_time) * 1000, 2);
        \WC_Lexware_MVP_Logger::info('Credit note creation API call completed', array(
            'invoice_id' => $invoice_id,
            'finalize' => $finalize,
            'duration_ms' => $duration_ms,
            'success' => !\is_wp_error($result)
        ));

        return $result;
    }

    /**
     * Get Credit Note by ID
     *
     * @param string $cn_id Credit Note ID
     * @return array|\WP_Error Credit Note Daten oder Fehler
     */
    public function get_credit_note($cn_id) {
        return $this->request('GET', '/credit-notes/' . $cn_id);
    }

    /**
     * Update Credit Note mit Optimistic Locking Support
     *
     * WICHTIG: version_id MUSS aus vorherigem get_credit_note() Aufruf stammen!
     *
     * @since 0.4.0 (Phase 2)
     * @param string $cn_id Credit Note ID
     * @param array $credit_note_data Update-Daten
     * @param string $version_id version_id aus GET Response (für Optimistic Locking)
     * @return array|\WP_Error Updated credit note oder Error
     */
    public function update_credit_note($cn_id, $credit_note_data, $version_id) {
        if (empty($version_id)) {
            return new \WP_Error(
                'missing_version_id',
                __('version_id is required for Optimistic Locking. Call get_credit_note() first.', 'woocommerce-lexware-mvp')
            );
        }

        \WC_Lexware_MVP_Logger::debug('Updating credit note with Optimistic Locking', array(
            'credit_note_id' => $cn_id,
            'version_id' => $version_id
        ));

        return $this->request('PUT', "/credit-notes/{$cn_id}", array(), $credit_note_data, array(), $version_id);
    }

    /**
     * Get Payment Conditions from Lexware
     *
     * @return array|\WP_Error List of payment conditions or error
     */
    public function get_payment_conditions() {
        return $this->request('GET', '/payment-conditions');
    }

    /**
     * Download Credit Note PDF
     *
     * @param string $cn_id Credit Note ID
     * @return string|\WP_Error PDF-Binärdaten oder Fehler
     */
    public function download_credit_note_pdf($cn_id) {
        $start_time = microtime(true);
        // Modern endpoint: /credit-notes/{id}/file (deprecated: /document)
        $url = self::API_URL_LIVE . '/credit-notes/' . $cn_id . '/file';

        // First, check Content-Length header to validate PDF size before downloading
        $head_response = wp_remote_head($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->token,
            ),
            'timeout' => 10
        ));

        if (!\is_wp_error($head_response)) {
            $content_length = wp_remote_retrieve_header($head_response, 'content-length');
            if ($content_length && $content_length > 50 * 1024 * 1024) { // > 50MB
                return new \WP_Error(
                    'pdf_too_large',
                    sprintf(__('Credit Note PDF file is too large (%s MB). Maximum allowed: 50 MB', 'woocommerce-lexware-mvp'),
                        round($content_length / 1024 / 1024, 2)
                    ),
                    array('size_bytes' => $content_length)
                );
            }
        }

        // Direct download (PDFs are typically < 1 MB, streaming nicht nötig)
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->token,
                'Accept' => 'application/pdf'
            ),
            'timeout' => 25
        ));

        if (\is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $body = wp_remote_retrieve_body($response);
            return new \WP_Error('api_error', 'Credit Note PDF-Download fehlgeschlagen: ' . $body, array('status' => $code));
        }

        $pdf_data = wp_remote_retrieve_body($response);
        $duration_ms = round((microtime(true) - $start_time) * 1000, 2);

        \WC_Lexware_MVP_Logger::debug('Credit note PDF download completed', array(
            'credit_note_id' => $cn_id,
            'size_bytes' => strlen($pdf_data),
            'duration_ms' => $duration_ms
        ));

        return $pdf_data;
    }

    /**
     * Create Order Confirmation (Auftragsbestätigung)
     *
     * @since 1.3.0
     * @param array $order_confirmation_data Order Confirmation Daten
     * @param bool $finalize Sofort finalisieren (true = final, false = Entwurf)
     * @param int|null $order_id WooCommerce Order ID (for idempotency)
     * @return array|\WP_Error Order Confirmation Daten oder Fehler
     */
    public function create_order_confirmation($order_confirmation_data, $finalize = false, $order_id = null) {
        $start_time = microtime(true);

        $query_params = array();

        if ($finalize) {
            $query_params['finalize'] = 'true';
        }

        // Add idempotency key if order_id provided
        $custom_headers = array();
        if ($order_id) {
            $idempotency_key = \WC_Lexware_MVP_Idempotency_Manager::get_key($order_id, 'create_order_confirmation');
            $custom_headers['X-Idempotency-Key'] = $idempotency_key;

            \WC_Lexware_MVP_Logger::debug('Order confirmation request with idempotency key', array(
                'order_id' => $order_id,
                'key' => $idempotency_key
            ));
        }

        $result = $this->request('POST', '/order-confirmations', $query_params, $order_confirmation_data, $custom_headers);

        $duration_ms = round((microtime(true) - $start_time) * 1000, 2);
        \WC_Lexware_MVP_Logger::info('Order confirmation creation API call completed', array(
            'order_id' => $order_id,
            'finalize' => $finalize,
            'duration_ms' => $duration_ms,
            'success' => !\is_wp_error($result)
        ));

        return $result;
    }

    /**
     * Get Order Confirmation by ID
     *
     * @since 1.3.0
     * @param string $oc_id Order Confirmation ID
     * @return array|\WP_Error Order Confirmation Daten oder Fehler
     */
    public function get_order_confirmation($oc_id) {
        return $this->request('GET', '/order-confirmations/' . $oc_id);
    }

    /**
     * Find invoice by document number (voucherNumber)
     *
     * Searches through all invoices to find one matching the given document number.
     * Uses pagination to iterate through results, stopping early when found.
     *
     * @since 1.3.0
     * @param string $document_number The human-readable document number (e.g., "RE202506-0002")
     * @return array|false Invoice data array if found, false otherwise
     */
    public function find_invoice_by_number($document_number) {
        $page = 0; // Lexware API uses 0-based pagination
        $max_pages = 20; // Safety limit - max 2000 invoices searched
        $size = 100;

        \WC_Lexware_MVP_Logger::info('Searching for invoice by number', array(
            'document_number' => $document_number
        ));

        while ($page < $max_pages) {
            // Use /voucherlist endpoint with invoice filter (not /invoices which returns 404)
            $response = $this->request('GET', '/voucherlist', array(
                'voucherType' => 'invoice',
                'voucherStatus' => 'any', // Required parameter
                'page' => $page,
                'size' => $size,
                'sort' => 'createdDate,desc' // Newest first - more likely to be found early
            ));

            if (\is_wp_error($response)) {
                \WC_Lexware_MVP_Logger::error('Invoice search failed', array(
                    'page' => $page,
                    'error' => $response->get_error_message()
                ));
                return false;
            }

            // Check if we have content
            if (empty($response['content']) || !is_array($response['content'])) {
                break;
            }

            // Search through this page
            foreach ($response['content'] as $invoice) {
                if (isset($invoice['voucherNumber']) && $invoice['voucherNumber'] === $document_number) {
                    \WC_Lexware_MVP_Logger::info('Invoice found', array(
                        'document_number' => $document_number,
                        'invoice_id' => $invoice['id'],
                        'page_found' => $page
                    ));
                    return $invoice;
                }
            }

            // Check if this is the last page
            if (isset($response['last']) && $response['last'] === true) {
                break;
            }

            // Check if we have next page via _links
            if (!isset($response['_links']['next'])) {
                break;
            }

            $page++;
        }

        \WC_Lexware_MVP_Logger::info('Invoice not found after searching', array(
            'document_number' => $document_number,
            'pages_searched' => $page
        ));

        return false;
    }

    /**
     * Find order confirmation by document number (voucherNumber)
     *
     * Searches through all order confirmations to find one matching the given document number.
     * Uses pagination to iterate through results, stopping early when found.
     *
     * @since 1.3.0
     * @param string $document_number The human-readable document number (e.g., "AB202506-0001")
     * @return array|false Order confirmation data array if found, false otherwise
     */
    public function find_order_confirmation_by_number($document_number) {
        $page = 0; // Lexware API uses 0-based pagination
        $max_pages = 20; // Safety limit - max 2000 order confirmations searched
        $size = 100;

        \WC_Lexware_MVP_Logger::info('Searching for order confirmation by number', array(
            'document_number' => $document_number
        ));

        while ($page < $max_pages) {
            // Use /voucherlist endpoint with orderconfirmation filter (not /order-confirmations which returns 404)
            $response = $this->request('GET', '/voucherlist', array(
                'voucherType' => 'orderconfirmation',
                'voucherStatus' => 'any', // Required parameter
                'page' => $page,
                'size' => $size,
                'sort' => 'createdDate,desc' // Newest first
            ));

            if (\is_wp_error($response)) {
                \WC_Lexware_MVP_Logger::error('Order confirmation search failed', array(
                    'page' => $page,
                    'error' => $response->get_error_message()
                ));
                return false;
            }

            // Check if we have content
            if (empty($response['content']) || !is_array($response['content'])) {
                break;
            }

            // Search through this page
            foreach ($response['content'] as $oc) {
                if (isset($oc['voucherNumber']) && $oc['voucherNumber'] === $document_number) {
                    \WC_Lexware_MVP_Logger::info('Order confirmation found', array(
                        'document_number' => $document_number,
                        'oc_id' => $oc['id'],
                        'page_found' => $page
                    ));
                    return $oc;
                }
            }

            // Check if this is the last page
            if (isset($response['last']) && $response['last'] === true) {
                break;
            }

            // Check if we have next page via _links
            if (!isset($response['_links']['next'])) {
                break;
            }

            $page++;
        }

        \WC_Lexware_MVP_Logger::info('Order confirmation not found after searching', array(
            'document_number' => $document_number,
            'pages_searched' => $page
        ));

        return false;
    }

    /**
     * Find credit note by document number (voucherNumber)
     *
     * Searches through all credit notes to find one matching the given document number.
     * Uses pagination to iterate through results, stopping early when found.
     *
     * @since 1.3.2
     * @param string $document_number The human-readable document number (e.g., "GS0001")
     * @return array|false Credit note data array if found, false otherwise
     */
    public function find_credit_note_by_number($document_number) {
        $page = 0;
        $max_pages = 20;
        $size = 100;

        \WC_Lexware_MVP_Logger::info('Searching for credit note by number', array(
            'document_number' => $document_number
        ));

        while ($page < $max_pages) {
            $response = $this->request('GET', '/voucherlist', array(
                'voucherType' => 'creditnote',
                'voucherStatus' => 'any',
                'page' => $page,
                'size' => $size,
                'sort' => 'createdDate,desc'
            ));

            if (\is_wp_error($response)) {
                \WC_Lexware_MVP_Logger::error('Credit note search failed', array(
                    'page' => $page,
                    'error' => $response->get_error_message()
                ));
                return false;
            }

            if (empty($response['content']) || !is_array($response['content'])) {
                break;
            }

            foreach ($response['content'] as $cn) {
                if (isset($cn['voucherNumber']) && $cn['voucherNumber'] === $document_number) {
                    \WC_Lexware_MVP_Logger::info('Credit note found', array(
                        'document_number' => $document_number,
                        'cn_id' => $cn['id'],
                        'page_found' => $page
                    ));
                    return $cn;
                }
            }

            if (isset($response['last']) && $response['last'] === true) {
                break;
            }

            if (!isset($response['_links']['next'])) {
                break;
            }

            $page++;
        }

        \WC_Lexware_MVP_Logger::info('Credit note not found after searching', array(
            'document_number' => $document_number,
            'pages_searched' => $page
        ));

        return false;
    }

    /**
     * Download Order Confirmation PDF
     *
     * Note: Lexware requires calling /document endpoint first to trigger PDF rendering
     * for API-created order confirmations, then the file is available.
     *
     * @since 1.3.0
     * @param string $oc_id Order Confirmation ID
     * @return string|\WP_Error PDF-Binärdaten oder Fehler
     */
    public function download_order_confirmation_pdf($oc_id) {
        $start_time = microtime(true);

        // First trigger PDF rendering via /document endpoint
        $document_url = self::API_URL_LIVE . '/order-confirmations/' . $oc_id . '/document';

        $document_response = wp_remote_get($document_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->token,
                'Accept' => 'application/json'
            ),
            'timeout' => 15
        ));

        if (\is_wp_error($document_response)) {
            return $document_response;
        }

        $document_code = wp_remote_retrieve_response_code($document_response);

        // 406 = Draft mode (no PDF available)
        if ($document_code === 406) {
            return new \WP_Error(
                'draft_mode',
                __('Order Confirmation ist im Entwurfsmodus. PDF nur für finalisierte Dokumente verfügbar.', 'woocommerce-lexware-mvp'),
                array('status' => 406)
            );
        }

        if ($document_code !== 200) {
            $body = wp_remote_retrieve_body($document_response);
            return new \WP_Error('api_error', 'Order Confirmation document endpoint failed: ' . $body, array('status' => $document_code));
        }

        // Parse documentFileId from response
        $document_body = json_decode(wp_remote_retrieve_body($document_response), true);
        $file_id = isset($document_body['documentFileId']) ? $document_body['documentFileId'] : null;

        if (!$file_id) {
            return new \WP_Error('no_file_id', __('Keine documentFileId in der Response gefunden.', 'woocommerce-lexware-mvp'));
        }

        // Download PDF via files endpoint
        $file_url = self::API_URL_LIVE . '/files/' . $file_id;

        // Check Content-Length first
        $head_response = wp_remote_head($file_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->token,
            ),
            'timeout' => 10
        ));

        if (!\is_wp_error($head_response)) {
            $content_length = wp_remote_retrieve_header($head_response, 'content-length');
            if ($content_length && $content_length > 50 * 1024 * 1024) {
                return new \WP_Error(
                    'pdf_too_large',
                    sprintf(__('Order Confirmation PDF file is too large (%s MB). Maximum allowed: 50 MB', 'woocommerce-lexware-mvp'),
                        round($content_length / 1024 / 1024, 2)
                    ),
                    array('size_bytes' => $content_length)
                );
            }
        }

        // Download PDF
        $response = wp_remote_get($file_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->token,
                'Accept' => 'application/pdf'
            ),
            'timeout' => 25
        ));

        if (\is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $body = wp_remote_retrieve_body($response);
            return new \WP_Error('api_error', 'Order Confirmation PDF-Download fehlgeschlagen: ' . $body, array('status' => $code));
        }

        $pdf_data = wp_remote_retrieve_body($response);
        $duration_ms = round((microtime(true) - $start_time) * 1000, 2);

        \WC_Lexware_MVP_Logger::debug('Order confirmation PDF download completed', array(
            'order_confirmation_id' => $oc_id,
            'document_file_id' => $file_id,
            'size_bytes' => strlen($pdf_data),
            'duration_ms' => $duration_ms
        ));

        return $pdf_data;
    }

    /**
     * DEPRECATED: Use download_invoice_pdf() instead
     * Old endpoint /files/ is no longer supported
     */
    /*
    public function download_pdf($document_file_id) {
        // Fallback for backward compatibility
        $url = self::API_URL_LIVE . '/files/' . $document_file_id;

        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->token,
                'Accept' => 'application/pdf'
            ),
            'timeout' => 25
        ));

        if (\is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $body = wp_remote_retrieve_body($response);
            return new \WP_Error('api_error', 'PDF-Download fehlgeschlagen: ' . $body, array('status' => $code));
        }

        return wp_remote_retrieve_body($response);
    }
    */

    /**
     * Encode search string for Lexware API
     *
     * Lexware API requires special encoding for search parameters:
     * - HTML entities encoding (htmlentities)
     * - URL encoding (urlencode)
     *
     * @since 1.0.0
     * @param string $string String to encode
     * @return string Encoded string
     */
    private function encode_search_string($string) {
        // First encode HTML entities, then URL encode
        return urlencode(htmlentities($string, ENT_QUOTES, 'UTF-8'));
    }

    /**
     * HTTP-Request ausführen mit Circuit Breaker Protection
     *
     * Rate Limiting wird proaktiv durch Token Bucket Rate Limiter in execute_request()
     * verhindert, daher ist keine reaktive Retry-Logik für 429 Errors notwendig.
     *
     * @param string $method HTTP-Methode (GET, POST, PUT, DELETE)
     * @param string $endpoint API-Endpoint (z.B. /contacts)
     * @param array $query Query-Parameter
     * @param array $body Request-Body
     * @param array $custom_headers Additional headers (e.g., X-Idempotency-Key)
     * @param string|null $version_id Optional: version_id für Optimistic Locking
     * @return array|\WP_Error Response oder Fehler
     */
    private function request($method, $endpoint, $query = array(), $body = array(), $custom_headers = array(), $version_id = null) {
        if (empty($this->token)) {
            return new \WP_Error('no_token', 'Kein API-Token konfiguriert');
        }

        // ✅ CIRCUIT BREAKER CHECK - Blockiere Request wenn Circuit open
        if ($this->circuit_breaker->is_open()) {
            $circuit_status = $this->circuit_breaker->get_status();

            \WC_Lexware_MVP_Logger::warning('API Request blocked by Circuit Breaker', array(
                'endpoint' => $endpoint,
                'method' => $method,
                'circuit_state' => $circuit_status['state'],
                'retry_in' => isset($circuit_status['seconds_until_retry']) ? $circuit_status['seconds_until_retry'] : 0
            ));

            return new \WP_Error(
                'circuit_open',
                __('Lexware API temporarily unavailable due to repeated failures. Retrying automatically.', 'woocommerce-lexware-mvp'),
                array(
                    'status' => 503,
                    'circuit_status' => $circuit_status
                )
            );
        }

        // Execute request with rate limiting
        $result = $this->execute_request($method, $endpoint, $query, $body, $custom_headers, $version_id);

        // Record result in Circuit Breaker
        if (!\is_wp_error($result)) {
            // ✅ Success: Reset Circuit Breaker failure count
            $this->circuit_breaker->record_success();
        } else {
            // ❌ Failure: Record in Circuit Breaker with error code for filtering
            $error_data = $result->get_error_data();
            $error_code = isset($error_data['status']) ? $error_data['status'] : 0;

            // Circuit Breaker will filter out 4xx client errors and 429 rate limits
            $this->circuit_breaker->record_failure($error_code);
        }

        return $result;
    }

    /**
     * Führe einen einzelnen HTTP-Request aus
     *
     * @param string $method HTTP-Methode
     * @param string $endpoint API-Endpoint
     * @param array $query Query-Parameter (werden automatisch URL-encoded)
     * @param array $body Request-Body
     * @param array $custom_headers Additional headers (e.g., X-Idempotency-Key)
     * @param string|null $version_id Optional: version_id für Optimistic Locking (setzt If-Match Header)
     * @return array|\WP_Error
     */
    private function execute_request($method, $endpoint, $query = array(), $body = array(), $custom_headers = array(), $version_id = null) {
        $start_time = microtime(true);

        // Rate limiting: Wait if necessary to stay under 2 req/s
        $this->rate_limit();

        $url = self::API_URL_LIVE . $endpoint;

        // Query-Parameter hinzufügen (bereits encoded von encode_query_params/encode_search_string)
        // WICHTIG: Nicht nochmal encoden, da bereits in Methoden behandelt
        if (!empty($query)) {
            $url = add_query_arg($query, $url);
        }

        // Merge custom headers with standard headers
        $headers = array_merge(
            array(
                'Authorization' => 'Bearer ' . $this->token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            $custom_headers
        );

        // Optimistic Locking: If-Match Header für PUT/PATCH/DELETE
        if (!empty($version_id) && in_array($method, array('PUT', 'PATCH', 'DELETE'))) {
            $headers['If-Match'] = $version_id;
            \WC_Lexware_MVP_Logger::debug('Optimistic Locking enabled', array(
                'method' => $method,
                'endpoint' => $endpoint,
                'version_id' => $version_id
            ));
        }

        $args = array(
            'method' => $method,
            'headers' => $headers,
            'timeout' => 25 // Slightly less than 30s gateway timeout
        );

        // Body hinzufügen
        if (!empty($body)) {
            $args['body'] = json_encode($body);
        }

        // Request ausführen
        $response = wp_remote_request($url, $args);

        if (\is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        $duration_ms = round((microtime(true) - $start_time) * 1000, 2);

        // Fehlerbehandlung
        if ($code >= 400) {
            \WC_Lexware_MVP_Logger::debug('API request failed', array(
                'method' => $method,
                'endpoint' => $endpoint,
                'status_code' => $code,
                'duration_ms' => $duration_ms
            ));
            return $this->handle_api_error($code, $body);
        }

        \WC_Lexware_MVP_Logger::debug('API request completed', array(
            'method' => $method,
            'endpoint' => $endpoint,
            'status_code' => $code,
            'duration_ms' => $duration_ms
        ));

        // JSON-Response parsen
        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new \WP_Error('json_error', 'JSON-Parsing fehlgeschlagen: ' . json_last_error_msg());
        }

        // Response-Headers für Paging (Link) und Optimistic Locking (ETag, version_id) anhängen
        if (is_array($data)) {
            $link_header = wp_remote_retrieve_header($response, 'link');
            $etag_header = wp_remote_retrieve_header($response, 'etag');

            if (!empty($link_header)) {
                $data['_links'] = $this->parse_link_header($link_header);
            }

            if (!empty($etag_header)) {
                $data['_etag'] = $etag_header;
            }

            // version_id aus Response-Body für Optimistic Locking
            if (isset($data['version_id'])) {
                $data['_version_id'] = $data['version_id'];
            }
        }

        return $data;
    }

    /**
     * Parse RFC 5988 Link Header für Paging
     *
     * Lexware API verwendet Link-Header für Pagination:
     * Link: <https://api.lexware.io/v1/contacts?page=2>; rel="next",
     *       <https://api.lexware.io/v1/contacts?page=5>; rel="last"
     *
     * @since 1.0.0
     * @param string $link_header Link-Header String
     * @return array Assoziatives Array mit rel => URL
     */
    private function parse_link_header($link_header) {
        $links = array();

        // Split by comma to get individual links
        $parts = explode(',', $link_header);

        foreach ($parts as $part) {
            // Extract URL and rel using regex
            if (preg_match('/<([^>]+)>;\s*rel="([^"]+)"/', trim($part), $matches)) {
                $url = $matches[1];
                $rel = $matches[2];
                $links[$rel] = $url;
            }
        }

        return $links;
    }

    /**
     * Folge der "next" Link aus Response für automatisches Paging
     *
     * @since 1.0.0
     * @param array $response Previous API response mit _links array
     * @return array|\WP_Error Next page response oder Error wenn keine next page
     */
    private function follow_next_page($response) {
        if (!isset($response['_links']['next'])) {
            return new \WP_Error('no_next_page', 'Keine weitere Seite verfügbar');
        }

        $next_url = $response['_links']['next'];

        // Parse URL um Endpoint und Query zu extrahieren
        $parsed = parse_url($next_url);
        $endpoint = $parsed['path'];

        // Remove /v1 prefix if present
        $endpoint = str_replace('/v1', '', $endpoint);

        $query = array();
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $query);
        }

        // Use regular request method
        return $this->request('GET', $endpoint, $query);
    }

    /**
     * Hole ALLE Seiten einer paginierten Response automatisch
     *
     * WARNUNG: Verwende diese Methode nur für Endpoints mit überschaubaren Datenmengen!
     * Bei großen Datensätzen (>1000 Einträge) besser manuelles Paging mit follow_next_page().
     *
     * @since 1.0.0
     * @param string $method HTTP Method
     * @param string $endpoint API Endpoint
     * @param array $query Query params
     * @param array $body Request body
     * @param int $max_pages Maximum Seiten (Schutz vor Endlosschleifen), default 100
     * @return array|\WP_Error Alle Ergebnisse kombiniert oder Error
     */
    public function get_all_pages($method, $endpoint, $query = array(), $body = array(), $max_pages = 100) {
        $all_results = array();
        $page_count = 0;

        // Erste Seite holen
        $response = $this->request($method, $endpoint, $query, $body);

        if (\is_wp_error($response)) {
            return $response;
        }

        // Hauptdaten sammeln (meist unter 'content' key bei Lexware)
        if (isset($response['content']) && is_array($response['content'])) {
            $all_results = array_merge($all_results, $response['content']);
        } else {
            // Fallback wenn keine content-Struktur
            $all_results[] = $response;
        }

        $page_count++;

        // Weitere Seiten folgen
        while (isset($response['_links']['next']) && $page_count < $max_pages) {
            $response = $this->follow_next_page($response);

            if (\is_wp_error($response)) {
                // Bei Fehler abbrechen und bisherige Ergebnisse zurückgeben
                \WC_Lexware_MVP_Logger::warning('Paging stopped due to error', array(
                    'page' => $page_count,
                    'error' => $response->get_error_message()
                ));
                break;
            }

            if (isset($response['content']) && is_array($response['content'])) {
                $all_results = array_merge($all_results, $response['content']);
            }

            $page_count++;
        }

        \WC_Lexware_MVP_Logger::debug('Completed paginated request', array(
            'endpoint' => $endpoint,
            'pages_fetched' => $page_count,
            'total_results' => count($all_results)
        ));

        return array(
            'content' => $all_results,
            'total_pages' => $page_count,
            'total_results' => count($all_results)
        );
    }

    /**
     * Rate limiting: Use Token Bucket algorithm via WC_Lexware_MVP_Rate_Limiter
     */
    private function rate_limit() {
        // Use new rate limiter with token bucket
        if (!\WC_Lexware_MVP_Rate_Limiter::acquire_token()) {
            \WC_Lexware_MVP_Logger::warning(
                'API Rate Limit: Konnte keinen Token erhalten',
                array('waited' => '30s')
            );
        }
    }

    /**
     * Handle API errors with specific error codes
     *
     * @param int $code HTTP status code
     * @param string $body Response body
     * @return \WP_Error
     */
    private function handle_api_error($code, $body) {
        $error_data = json_decode($body, true);

        switch ($code) {
            case 402:
                return new \WP_Error('payment_required',
                    'Lexware Vertragsproblem - Bitte kontaktieren Sie den Lexware Support',
                    array('status' => $code)
                );

            case 406:
                $message = 'Validierungsfehler: ';
                if (isset($error_data['IssueList'])) {
                    foreach ($error_data['IssueList'] as $issue) {
                        $source = $issue['source'] ?? 'unbekannt';
                        $i18nKey = $issue['i18nKey'] ?? 'validation_error';
                        $message .= "{$source} ({$i18nKey}); ";
                    }
                } else {
                    $message .= $body;
                }
                return new \WP_Error('validation_error', $message, array('status' => $code));

            case 409:
                return new \WP_Error('conflict',
                    'Konflikt: ' . ($error_data['message'] ?? 'Ressource existiert bereits oder ist veraltet'),
                    array('status' => $code)
                );

            case 429:
                return new \WP_Error('rate_limit',
                    'Rate Limit erreicht - zu viele Anfragen',
                    array('status' => $code, 'retry' => true)
                );

            default:
                $error_message = 'API-Fehler: ' . $code;
                if ($error_data && isset($error_data['message'])) {
                    $error_message .= ' - ' . $error_data['message'];
                } else {
                    $error_message .= ' - ' . $body;
                }
                return new \WP_Error('api_error', $error_message, array('status' => $code));
        }
    }



    /**
     * Get all vouchers from Lexware voucherlist
     *
     * Fetches all vouchers (invoices, credit notes, order confirmations, etc.)
     * from the /voucherlist endpoint with automatic pagination.
     *
     * @since 1.2.6
     * @param array $params Optional filter params:
     *                      - voucherType: 'invoice', 'creditnote', 'orderconfirmation', 'any' (default: 'any')
     *                      - voucherStatus: 'draft', 'open', 'paid', 'voided', 'any' (default: 'any')
     *                      - voucherDateFrom: 'YYYY-MM-DD'
     *                      - voucherDateTo: 'YYYY-MM-DD'
     * @return array|WP_Error Array of vouchers or WP_Error on failure
     */
    public function get_voucherlist($params = array()) {
        $default_params = array(
            'voucherType' => 'any',
            'voucherStatus' => 'any',
            'size' => 100,
            'sort' => 'voucherDate,desc'
        );

        $query = array_merge($default_params, $params);

        \WC_Lexware_MVP_Logger::debug('Fetching voucherlist', array(
            'params' => $query
        ));

        $result = $this->get_all_pages('GET', '/voucherlist', $query, array(), 50);

        if (\is_wp_error($result)) {
            return $result;
        }

        // Return just the content array for easier handling
        return isset($result['content']) ? $result['content'] : array();
    }

    /**
     * Get full voucher details by type and ID
     *
     * Fetches complete voucher data from the type-specific endpoint.
     * Use this to get all fields including line items, addresses, etc.
     *
     * @since 1.2.6
     * @param string $voucher_type Type from voucherlist: 'invoice', 'creditnote', 'orderconfirmation', 'deliverynote', 'quotation'
     * @param string $voucher_id Lexware voucher ID
     * @return array|WP_Error Voucher details or WP_Error on failure
     */
    public function get_voucher_details($voucher_type, $voucher_id) {
        // Map voucherType to API endpoint
        $endpoints = array(
            'invoice'           => '/invoices/',
            'creditnote'        => '/credit-notes/',
            'orderconfirmation' => '/order-confirmations/',
            'deliverynote'      => '/delivery-notes/',
            'quotation'         => '/quotations/',
        );

        $endpoint = isset($endpoints[$voucher_type]) ? $endpoints[$voucher_type] : '/invoices/';

        \WC_Lexware_MVP_Logger::debug('Fetching voucher details', array(
            'type' => $voucher_type,
            'id' => $voucher_id,
            'endpoint' => $endpoint . $voucher_id
        ));

        return $this->request('GET', $endpoint . $voucher_id);
    }

    /**
     * Get Circuit Breaker Instance (für Admin Dashboard)
     *
     * @return WC_Lexware_MVP_Circuit_Breaker
     */
    public function get_circuit_breaker() {
        return $this->circuit_breaker;
    }
}
