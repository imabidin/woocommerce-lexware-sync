<?php
/**
 * Circuit Breaker - Cascading Failure Prevention
 *
 * Prevents WordPress outage during Lexware API failures by
 * automatically blocking requests after repeated errors.
 *
 * Three-State Machine: CLOSED → OPEN → HALF_OPEN → CLOSED
 *
 * - CLOSED: Normal operation, all requests allowed
 * - OPEN: API failures detected, all requests blocked (fail fast)
 * - HALF_OPEN: Testing recovery, limited requests allowed
 *
 * @package WC_Lexware_MVP
 * @since 1.0.0
 */

namespace WC_Lexware_MVP\Core;

if (!defined('ABSPATH')) {
    exit;
}

class Circuit_Breaker {

    /**
     * State: Normal operation
     *
     * @since 1.0.0
     */
    const STATE_CLOSED = 'closed';      // Normal: All requests allowed

    /**
     * State: API failures detected
     *
     * @since 1.0.0
     */
    const STATE_OPEN = 'open';          // Error: All requests blocked

    /**
     * State: Testing recovery
     *
     * @since 1.0.0
     */
    const STATE_HALF_OPEN = 'half_open'; // Test: Single test requests allowed

    /**
     * Circuit name (for transient keys)
     *
     * @since 1.0.0
     * @var string
     */
    private $name;

    /**
     * Failure threshold - Number of failures until circuit opens
     *
     * Default: 5
     *
     * @since 1.0.0
     * @var int
     */
    private $failure_threshold;

    /**
     * Success threshold - Number of successes in HALF_OPEN until circuit closes
     *
     * Default: 2
     *
     * @since 1.0.0
     * @var int
     */
    private $success_threshold;

    /**
     * Timeout - Seconds until OPEN → HALF_OPEN transition
     *
     * Default: 60 seconds
     *
     * @since 1.0.0
     * @var int
     */
    private $timeout;

    /**
     * Constructor
     *
     * @since 1.0.0
     * @param string $name Circuit name (e.g., 'lexware_api')
     * @param int $failure_threshold Failures until circuit opens
     * @param int $success_threshold Successes until circuit closes
     * @param int $timeout Seconds until retry test
     */
    public function __construct($name = 'lexware_api', $failure_threshold = 5, $success_threshold = 2, $timeout = 60) {
        $this->name = sanitize_key($name);

        // Allow runtime override via WordPress filters
        $this->failure_threshold = apply_filters(
            'wc_lexware_circuit_failure_threshold',
            max(1, intval($failure_threshold)),
            $this->name
        );

        $this->success_threshold = apply_filters(
            'wc_lexware_circuit_success_threshold',
            max(1, intval($success_threshold)),
            $this->name
        );

        $this->timeout = apply_filters(
            'wc_lexware_circuit_timeout',
            max(10, intval($timeout)),
            $this->name
        );
    }

    /**
     * Check if circuit is open (requests should be blocked)
     *
     * Checks current state and handles automatic transition from OPEN to HALF_OPEN
     * after timeout expires (with exponential backoff).
     *
     * @since 1.0.0
     * @return bool True if circuit OPEN (requests blocked)
     */
    public function is_open() {
        $state = $this->get_state();

        if ($state === self::STATE_OPEN) {
            // Prüfe ob Timeout abgelaufen → Transition zu HALF_OPEN
            $state_data = $this->get_state_data();
            $opened_at = isset($state_data['opened_at']) ? $state_data['opened_at'] : 0;

            // Use dynamic timeout with exponential backoff
            $dynamic_timeout = $this->get_dynamic_timeout();

            if (time() - $opened_at >= $dynamic_timeout) {
                // Timeout abgelaufen → Transition zu HALF_OPEN
                $this->transition_to_half_open();

                \WC_Lexware_MVP_Logger::info('Circuit Breaker: Transitioned from OPEN to HALF_OPEN', array(
                    'circuit' => $this->name,
                    'elapsed_seconds' => time() - $opened_at,
                    'base_timeout' => $this->timeout,
                    'dynamic_timeout' => $dynamic_timeout,
                    'consecutive_opens' => $this->get_consecutive_opens()
                ));

                // HALF_OPEN erlaubt Requests (für Testing)
                return false;
            }

            // Still OPEN - blockiere Request
            return true;
        }

        // CLOSED oder HALF_OPEN erlauben Requests
        return false;
    }

    /**
     * Record successful request
     *
     * In HALF_OPEN state: Increments success count, closes circuit if threshold reached.
     * In CLOSED state: Resets failure count.
     *
     * @since 1.0.0
     */
    public function record_success() {
        $state = $this->get_state();

        if ($state === self::STATE_HALF_OPEN) {
            // Increment Success Count
            $state_data = $this->get_state_data();
            $success_count = isset($state_data['success_count']) ? intval($state_data['success_count']) : 0;
            $success_count++;

            if ($success_count >= $this->success_threshold) {
                // Genug Erfolge → Close Circuit
                $this->close_circuit();

                \WC_Lexware_MVP_Logger::info('Circuit Breaker: CLOSED after successful recovery', array(
                    'circuit' => $this->name,
                    'successes' => $success_count,
                    'threshold' => $this->success_threshold
                ));
            } else {
                // Update Success Count
                $state_data['success_count'] = $success_count;
                $this->save_state_data($state_data);

                \WC_Lexware_MVP_Logger::debug('Circuit Breaker: Success in HALF_OPEN', array(
                    'circuit' => $this->name,
                    'success_count' => $success_count,
                    'threshold' => $this->success_threshold
                ));
            }
        } elseif ($state === self::STATE_CLOSED) {
            // Reset Failure Count bei Erfolg
            $state_data = $this->get_state_data();
            if (isset($state_data['failure_count']) && $state_data['failure_count'] > 0) {
                $state_data['failure_count'] = 0;
                $this->save_state_data($state_data);

                \WC_Lexware_MVP_Logger::debug('Circuit Breaker: Failure count reset after success', array(
                    'circuit' => $this->name
                ));
            }
        }
    }

    /**
     * Check if error should be recorded as Circuit Breaker failure
     *
     * Only records transient server-side errors:
     * - 5xx Server Errors (500, 502, 503, 504)
     * - Timeouts
     * - Network errors
     *
     * Does NOT record:
     * - 4xx Client Errors (400, 401, 403, 404, 406) - these are application logic issues
     * - 429 Rate Limit - handled separately by Rate Limiter
     *
     * @since 1.0.0
     * @param int|string $error_code HTTP status code or error type
     * @return bool True if error should trigger circuit breaker
     */
    public function should_record_failure($error_code) {
        // Normalize to integer
        $code = intval($error_code);

        // Rate Limits - handled by Rate Limiter
        if ($code === 429) {
            return false;
        }

        // Client Errors (4xx) - application logic issues, not circuit breaker worthy
        if ($code >= 400 && $code < 500) {
            \WC_Lexware_MVP_Logger::debug('Circuit Breaker: Ignoring client error', array(
                'error_code' => $code,
                'reason' => 'Client errors are not transient failures'
            ));
            return false;
        }

        // Server Errors (5xx) - should trigger circuit breaker
        if ($code >= 500 && $code < 600) {
            return true;
        }

        // Timeouts and network errors (no status code)
        if ($code === 0) {
            return true;
        }

        // Default: Record as failure
        return true;
    }

    /**
     * Zeichne fehlgeschlagene Request auf
     *
     * In HALF_OPEN state: Re-opens circuit immediately
     * In CLOSED state: Increments failure count, opens circuit if threshold reached
     *
     * @since 1.0.0
     * @param int|string|null $error_code Optional HTTP status code for filtering
     */
    public function record_failure($error_code = null) {
        // Check if this error should trigger circuit breaker
        if ($error_code !== null && !$this->should_record_failure($error_code)) {
            return;
        }

        $state = $this->get_state();

        if ($state === self::STATE_HALF_OPEN) {
            // Fehler in HALF_OPEN → Sofort wieder OPEN
            $this->open_circuit();

            \WC_Lexware_MVP_Logger::warning('Circuit Breaker: Re-opened after failure in HALF_OPEN', array(
                'circuit' => $this->name
            ));
        } elseif ($state === self::STATE_CLOSED) {
            // Increment Failure Count
            $state_data = $this->get_state_data();
            $failure_count = isset($state_data['failure_count']) ? intval($state_data['failure_count']) : 0;
            $failure_count++;

            if ($failure_count >= $this->failure_threshold) {
                // Threshold erreicht → Open Circuit
                $this->open_circuit();

                \WC_Lexware_MVP_Logger::warning('Circuit Breaker: OPENED after threshold reached', array(
                    'circuit' => $this->name,
                    'failures' => $failure_count,
                    'threshold' => $this->failure_threshold,
                    'timeout_seconds' => $this->timeout
                ));
            } else {
                // Update Failure Count
                $state_data['failure_count'] = $failure_count;
                $state_data['last_failure'] = time();
                $this->save_state_data($state_data);

                \WC_Lexware_MVP_Logger::debug('Circuit Breaker: Failure recorded', array(
                    'circuit' => $this->name,
                    'failure_count' => $failure_count,
                    'threshold' => $this->failure_threshold
                ));
            }
        }
    }

    /**
     * Öffne Circuit (blockiere alle Requests)
     *
     * @since 1.0.0
     */
    private function open_circuit() {
        // Increment consecutive opens counter for exponential backoff
        $consecutive_opens = $this->get_consecutive_opens();
        $consecutive_opens++;

        $this->set_state(self::STATE_OPEN);
        $state_data = array(
            'opened_at' => time(),
            'failure_count' => 0,
            'success_count' => 0,
            'consecutive_opens' => $consecutive_opens
        );
        $this->save_state_data($state_data);

        // Send admin email notification (throttled to once per hour)
        $this->maybe_send_circuit_open_email($consecutive_opens);
    }

    /**
     * Send email notification when circuit opens (throttled)
     *
     * Sends an email to the WordPress admin when the circuit breaker opens.
     * Throttled to prevent email spam - max one email per hour.
     *
     * @since 1.2.6
     * @param int $consecutive_opens Number of consecutive circuit opens
     */
    private function maybe_send_circuit_open_email($consecutive_opens) {
        $throttle_key = 'lexware_mvp_circuit_email_' . $this->name;

        // Check if we already sent an email recently (throttle: 1 hour)
        if (\get_transient($throttle_key)) {
            \WC_Lexware_MVP_Logger::debug('Circuit Breaker: Email notification throttled', array(
                'circuit' => $this->name
            ));
            return;
        }

        // Set throttle transient (1 hour)
        \set_transient($throttle_key, time(), HOUR_IN_SECONDS);

        // Get admin email
        $admin_email = \get_option('admin_email');
        if (empty($admin_email)) {
            return;
        }

        // Build email content
        $site_name = \get_bloginfo('name');
        $site_url = \get_site_url();

        $subject = sprintf('[%s] Lexware API - Circuit Breaker offen', $site_name);

        $dynamic_timeout = $this->get_dynamic_timeout();
        $retry_minutes = round($dynamic_timeout / 60, 1);

        $message = sprintf(
            "Die Lexware API ist momentan nicht erreichbar.\n\n" .
            "Details:\n" .
            "- Shop: %s (%s)\n" .
            "- Circuit: %s\n" .
            "- Aufeinanderfolgende Öffnungen: %d\n" .
            "- Nächster Retry in: %s Minuten\n" .
            "- Zeitpunkt: %s\n\n" .
            "Was bedeutet das?\n" .
            "- Neue Rechnungen und Auftragsbestätigungen werden nicht erstellt\n" .
            "- Bestehende Dokumente sind weiterhin verfügbar\n" .
            "- Das System versucht automatisch, die Verbindung wiederherzustellen\n\n" .
            "Was tun?\n" .
            "- Prüfen Sie den Lexware Office Status\n" .
            "- Prüfen Sie den API-Token in den Plugin-Einstellungen\n" .
            "- Warten Sie auf automatische Wiederherstellung\n\n" .
            "Settings: %s/wp-admin/admin.php?page=wc-settings&tab=lexware_mvp\n\n" .
            "---\n" .
            "Diese E-Mail wurde automatisch vom WooCommerce Lexware MVP Plugin gesendet.",
            $site_name,
            $site_url,
            $this->name,
            $consecutive_opens,
            $retry_minutes,
            \current_time('mysql'),
            $site_url
        );

        // Send email
        $sent = \wp_mail($admin_email, $subject, $message);

        if ($sent) {
            \WC_Lexware_MVP_Logger::info('Circuit Breaker: Admin notification email sent', array(
                'circuit' => $this->name,
                'recipient' => $admin_email,
                'consecutive_opens' => $consecutive_opens
            ));
        } else {
            \WC_Lexware_MVP_Logger::warning('Circuit Breaker: Failed to send admin notification email', array(
                'circuit' => $this->name,
                'recipient' => $admin_email
            ));
        }
    }

    /**
     * Schließe Circuit (normale Operation)
     *
     * @since 1.0.0
     */
    private function close_circuit() {
        // Get previous consecutive_opens before resetting for email
        $previous_opens = $this->get_consecutive_opens();

        $this->set_state(self::STATE_CLOSED);
        $state_data = array(
            'failure_count' => 0,
            'success_count' => 0,
            'consecutive_opens' => 0  // Reset exponential backoff on successful recovery
        );
        $this->save_state_data($state_data);

        // Send recovery notification if circuit was previously open
        if ($previous_opens > 0) {
            $this->send_circuit_recovery_email($previous_opens);
        }
    }

    /**
     * Send email notification when circuit recovers
     *
     * @since 1.2.6
     * @param int $previous_opens Number of consecutive opens before recovery
     */
    private function send_circuit_recovery_email($previous_opens) {
        // Clear the throttle transient since we recovered
        \delete_transient('lexware_mvp_circuit_email_' . $this->name);

        $admin_email = \get_option('admin_email');
        if (empty($admin_email)) {
            return;
        }

        $site_name = \get_bloginfo('name');
        $site_url = \get_site_url();

        $subject = sprintf('[%s] Lexware API - Verbindung wiederhergestellt', $site_name);

        $message = sprintf(
            "Die Lexware API ist wieder erreichbar.\n\n" .
            "Details:\n" .
            "- Shop: %s (%s)\n" .
            "- Circuit: %s\n" .
            "- Wiederhergestellt nach: %d Ausfällen\n" .
            "- Zeitpunkt: %s\n\n" .
            "Der normale Betrieb wurde wiederaufgenommen.\n" .
            "Rechnungen und Auftragsbestätigungen werden wieder automatisch erstellt.\n\n" .
            "---\n" .
            "Diese E-Mail wurde automatisch vom WooCommerce Lexware MVP Plugin gesendet.",
            $site_name,
            $site_url,
            $this->name,
            $previous_opens,
            \current_time('mysql')
        );

        $sent = \wp_mail($admin_email, $subject, $message);

        if ($sent) {
            \WC_Lexware_MVP_Logger::info('Circuit Breaker: Recovery notification email sent', array(
                'circuit' => $this->name,
                'previous_opens' => $previous_opens
            ));
        }
    }

    /**
     * Transition zu HALF_OPEN (Test-Modus)
     */
    private function transition_to_half_open() {
        $this->set_state(self::STATE_HALF_OPEN);
        $state_data = array(
            'success_count' => 0,
            'failure_count' => 0
        );
        $this->save_state_data($state_data);
    }

    /**
     * Hole aktuellen Circuit State
     *
     * @return string 'closed', 'open', oder 'half_open'
     */
    public function get_state() {
        $state = \get_transient($this->get_state_key());
        return $state ? $state : self::STATE_CLOSED;
    }

    /**
     * Setze Circuit State
     */
    private function set_state($state) {
        \set_transient($this->get_state_key(), $state, HOUR_IN_SECONDS);
    }

    /**
     * Hole State Data (Counts, Timestamps)
     */
    private function get_state_data() {
        $data = \get_transient($this->get_data_key());
        return is_array($data) ? $data : array();
    }

    /**
     * Speichere State Data
     */
    private function save_state_data($data) {
        \set_transient($this->get_data_key(), $data, HOUR_IN_SECONDS);
    }

    /**
     * Transient Key für State
     */
    private function get_state_key() {
        return 'lexware_mvp_circuit_' . $this->name . '_state';
    }

    /**
     * Transient Key für Data
     */
    private function get_data_key() {
        return 'lexware_mvp_circuit_' . $this->name . '_data';
    }

    /**
     * Get consecutive opens count for exponential backoff
     *
     * @since 1.0.0
     * @return int Number of consecutive circuit opens
     */
    private function get_consecutive_opens() {
        $state_data = $this->get_state_data();
        return isset($state_data['consecutive_opens']) ? intval($state_data['consecutive_opens']) : 0;
    }

    /**
     * Calculate dynamic timeout with exponential backoff and jitter
     *
     * Formula: min(base_timeout * 2^consecutive_opens, max_timeout) + jitter
     *
     * Timeline example (base_timeout = 60s):
     * - 1st open: 60s + jitter
     * - 2nd open: 120s + jitter
     * - 3rd open: 240s + jitter
     * - 4th open: 480s + jitter
     * - 5th open: 960s + jitter
     * - 6th+ open: 3600s (capped) + jitter
     *
     * Jitter (0-500ms) prevents thundering herd problem when multiple
     * instances retry simultaneously.
     *
     * @since 1.0.0
     * @return int Dynamic timeout in seconds
     */
    private function get_dynamic_timeout() {
        $consecutive_opens = $this->get_consecutive_opens();

        // Exponential backoff: base * 2^consecutive_opens
        $exponential_timeout = $this->timeout * pow(2, $consecutive_opens);

        // Cap at 1 hour (3600 seconds)
        $max_timeout = 3600;
        $timeout = min($exponential_timeout, $max_timeout);

        // Add jitter (0-500ms = 0-0.5 seconds) to prevent thundering herd
        $jitter = (mt_rand(0, 500) / 1000);

        return intval($timeout + $jitter);
    }

    /**
     * Reset Circuit (Admin Tool)
     *
     * Manually resets the circuit breaker to CLOSED state.
     * Useful for testing or when admin wants to force immediate retry.
     */
    public function reset() {
        \delete_transient($this->get_state_key());
        \delete_transient($this->get_data_key());

        \WC_Lexware_MVP_Logger::info('Circuit Breaker manually reset', array(
            'circuit' => $this->name
        ));
    }

    /**
     * Hole Circuit Status (für Admin Dashboard)
     *
     * @return array Status Info with state, counts, and configuration
     */
    public function get_status() {
        $state = $this->get_state();
        $state_data = $this->get_state_data();

        $status = array(
            'state' => $state,
            'failure_count' => isset($state_data['failure_count']) ? $state_data['failure_count'] : 0,
            'success_count' => isset($state_data['success_count']) ? $state_data['success_count'] : 0,
            'failure_threshold' => $this->failure_threshold,
            'success_threshold' => $this->success_threshold,
            'timeout' => $this->timeout,
            'consecutive_opens' => isset($state_data['consecutive_opens']) ? $state_data['consecutive_opens'] : 0
        );

        if ($state === self::STATE_OPEN && isset($state_data['opened_at'])) {
            $status['opened_at'] = $state_data['opened_at'];
            $elapsed = time() - $state_data['opened_at'];

            // Use dynamic timeout with exponential backoff
            $dynamic_timeout = $this->get_dynamic_timeout();
            $status['dynamic_timeout'] = $dynamic_timeout;
            $status['seconds_until_retry'] = max(0, $dynamic_timeout - $elapsed);
            $status['opened_at_formatted'] = date('Y-m-d H:i:s', $state_data['opened_at']);
        }

        if (isset($state_data['last_failure'])) {
            $status['last_failure'] = $state_data['last_failure'];
            $status['last_failure_formatted'] = date('Y-m-d H:i:s', $state_data['last_failure']);
        }

        return $status;
    }

    /**
     * Get human-readable state label
     *
     * @return string State label with emoji
     */
    public function get_state_label() {
        $state = $this->get_state();

        $labels = array(
            self::STATE_CLOSED => '🟢 Operational',
            self::STATE_OPEN => '🔴 Circuit Open',
            self::STATE_HALF_OPEN => '🟡 Testing Recovery'
        );

        return isset($labels[$state]) ? $labels[$state] : '⚪ Unknown';
    }
}
