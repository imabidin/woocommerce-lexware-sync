<?php
/**
 * Admin Alerts - Email Notifications for Critical Events
 *
 * Sends email notifications to administrators when critical errors occur,
 * such as Circuit Breaker opening, persistent sync failures, or database errors.
 * Implements rate limiting to prevent notification spam.
 *
 * @package WC_Lexware_MVP
 * @since 1.2.0
 */

namespace WC_Lexware_MVP\Core;

if (!defined('ABSPATH')) {
    exit;
}

class Admin_Alerts {

    /**
     * Alert types with rate limiting windows (in seconds)
     */
    const ALERT_CIRCUIT_BREAKER = 'circuit_breaker_open';
    const ALERT_SYNC_FAILURE = 'sync_failure_permanent';
    const ALERT_DB_ERROR = 'database_error';
    const ALERT_CREDIT_NOTE_FAILURE = 'credit_note_failure';

    /**
     * Rate limit window: 1 hour (3600 seconds)
     * Only one alert of each type per hour
     */
    const RATE_LIMIT_WINDOW = 3600;

    /**
     * Get admin alert email address
     *
     * Returns configured alert email or falls back to WordPress admin email.
     *
     * @since 1.2.0
     * @return string Email address for alerts
     */
    private static function get_alert_email() {
        $alert_email = get_option('wc_lexware_mvp_alert_email', '');

        if (empty($alert_email) || !is_email($alert_email)) {
            return get_option('admin_email');
        }

        return $alert_email;
    }

    /**
     * Check if alert should be sent (rate limiting)
     *
     * Uses transients to track last sent time for each alert type.
     * Prevents sending more than one alert per hour for the same type.
     *
     * @since 1.2.0
     * @param string $alert_type Alert type constant
     * @return bool True if alert should be sent, false if rate limited
     */
    private static function should_send_alert($alert_type) {
        $transient_key = 'lexware_alert_' . $alert_type;
        $last_sent = get_transient($transient_key);

        if (false !== $last_sent) {
            // Alert was recently sent, don't send again
            return false;
        }

        // Set transient to prevent duplicate alerts
        set_transient($transient_key, time(), self::RATE_LIMIT_WINDOW);
        return true;
    }

    /**
     * Send alert email
     *
     * Sends HTML email with WordPress branding and detailed error information.
     *
     * @since 1.2.0
     * @param string $subject Email subject line
     * @param string $message Email body (HTML)
     * @return bool True if email sent successfully
     */
    private static function send_email($subject, $message) {
        $to = self::get_alert_email();

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
        );

        // Wrap message in HTML template
        $html_message = self::get_email_template($subject, $message);

        $sent = wp_mail($to, $subject, $html_message, $headers);

        if ($sent) {
            Logger::info('Admin alert email sent', array(
                'subject' => $subject,
                'recipient' => $to,
            ));
        } else {
            Logger::error('Failed to send admin alert email', array(
                'subject' => $subject,
                'recipient' => $to,
            ));
        }

        return $sent;
    }

    /**
     * Get HTML email template
     *
     * @since 1.2.0
     * @param string $subject Email subject
     * @param string $body Email body content (HTML)
     * @return string Formatted HTML email
     */
    private static function get_email_template($subject, $body) {
        $site_name = get_bloginfo('name');
        $site_url = home_url();
        $admin_url = admin_url('admin.php?page=wc-settings&tab=lexware_mvp');

        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Arial, sans-serif; background-color: #f7f7f7;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f7f7f7; padding: 40px 20px;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <!-- Header -->
                    <tr>
                        <td style="padding: 30px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 8px 8px 0 0;">
                            <h1 style="margin: 0; color: #ffffff; font-size: 24px; font-weight: 600;">⚠️ Lexware MVP Alert</h1>
                            <p style="margin: 8px 0 0 0; color: #ffffff; opacity: 0.9; font-size: 14px;">' . esc_html($site_name) . '</p>
                        </td>
                    </tr>

                    <!-- Subject -->
                    <tr>
                        <td style="padding: 30px 30px 20px 30px;">
                            <h2 style="margin: 0 0 10px 0; color: #23282d; font-size: 20px; font-weight: 600;">' . esc_html($subject) . '</h2>
                        </td>
                    </tr>

                    <!-- Body -->
                    <tr>
                        <td style="padding: 0 30px 30px 30px; color: #50575e; font-size: 14px; line-height: 1.6;">
                            ' . $body . '
                        </td>
                    </tr>

                    <!-- Action Button -->
                    <tr>
                        <td style="padding: 0 30px 30px 30px;" align="center">
                            <a href="' . esc_url($admin_url) . '" style="display: inline-block; padding: 12px 30px; background-color: #667eea; color: #ffffff; text-decoration: none; border-radius: 5px; font-weight: 600; font-size: 14px;">Zu den Einstellungen</a>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="padding: 20px 30px; background-color: #f7f7f7; border-radius: 0 0 8px 8px; border-top: 1px solid #e5e5e5;">
                            <p style="margin: 0; color: #7e8993; font-size: 12px; text-align: center;">
                                Diese E-Mail wurde automatisch von <a href="' . esc_url($site_url) . '" style="color: #667eea; text-decoration: none;">' . esc_html($site_name) . '</a> generiert.<br>
                                Plugin: WooCommerce Lexware MVP v' . WC_LEXWARE_MVP_VERSION . '
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
    }

    /**
     * Send Circuit Breaker alert
     *
     * Notifies admin when Circuit Breaker opens due to repeated API failures.
     *
     * @since 1.2.0
     * @param int $failure_count Number of consecutive failures
     * @param string $last_error Last error message
     * @return bool True if alert sent
     */
    public static function send_circuit_breaker_alert($failure_count, $last_error = '') {
        if (!self::should_send_alert(self::ALERT_CIRCUIT_BREAKER)) {
            return false;
        }

        $subject = '[KRITISCH] Lexware API Circuit Breaker aktiviert';

        $message = '<div style="padding: 15px; background-color: #fff3cd; border-left: 4px solid #ffc107; margin-bottom: 20px;">
            <strong>⚠️ Der Circuit Breaker wurde aktiviert</strong>
        </div>

        <p>Die Verbindung zur Lexware API wurde automatisch unterbrochen, da <strong>' . (int) $failure_count . ' aufeinanderfolgende Fehler</strong> aufgetreten sind.</p>

        <h3 style="margin: 25px 0 10px 0; color: #23282d; font-size: 16px;">Was bedeutet das?</h3>
        <ul style="margin: 10px 0; padding-left: 20px;">
            <li>Keine neuen Rechnungen werden zu Lexware synchronisiert</li>
            <li>Die API-Verbindung wird automatisch in 60 Sekunden erneut getestet</li>
            <li>Bei erfolgreicher Verbindung wird die Synchronisation fortgesetzt</li>
        </ul>';

        if (!empty($last_error)) {
            $message .= '
        <h3 style="margin: 25px 0 10px 0; color: #23282d; font-size: 16px;">Letzter Fehler:</h3>
        <div style="padding: 12px; background-color: #f8f9fa; border-radius: 4px; font-family: monospace; font-size: 13px; color: #d63301;">
            ' . esc_html($last_error) . '
        </div>';
        }

        $message .= '
        <h3 style="margin: 25px 0 10px 0; color: #23282d; font-size: 16px;">Empfohlene Maßnahmen:</h3>
        <ol style="margin: 10px 0; padding-left: 20px;">
            <li>Prüfen Sie die Lexware API-Verbindung in den Einstellungen</li>
            <li>Überprüfen Sie Ihren API-Token auf Gültigkeit</li>
            <li>Kontaktieren Sie den Lexware Support bei anhaltenden Problemen</li>
        </ol>

        <p style="margin-top: 25px; padding-top: 20px; border-top: 1px solid #e5e5e5; color: #7e8993; font-size: 13px;">
            Diese Benachrichtigung wird maximal 1x pro Stunde gesendet.
        </p>';

        return self::send_email($subject, $message);
    }

    /**
     * Send persistent sync failure alert
     *
     * Notifies admin when invoice/order sync fails permanently after max retries.
     *
     * @since 1.2.0
     * @param int $order_id WooCommerce order ID
     * @param string $error_message Error message
     * @param int $retry_count Number of retry attempts
     * @return bool True if alert sent
     */
    public static function send_sync_failure_alert($order_id, $error_message, $retry_count = 3) {
        if (!self::should_send_alert(self::ALERT_SYNC_FAILURE)) {
            return false;
        }

        $order = wc_get_order($order_id);
        $order_number = $order ? $order->get_order_number() : $order_id;
        $order_edit_url = admin_url('post.php?post=' . $order_id . '&action=edit');

        $subject = '[FEHLER] Lexware Rechnungserstellung fehlgeschlagen';

        $message = '<div style="padding: 15px; background-color: #f8d7da; border-left: 4px solid #dc3545; margin-bottom: 20px;">
            <strong>❌ Rechnungserstellung dauerhaft fehlgeschlagen</strong>
        </div>

        <p>Die Rechnung für <strong>Bestellung #' . esc_html($order_number) . '</strong> konnte nach <strong>' . (int) $retry_count . ' Versuchen</strong> nicht erstellt werden.</p>

        <h3 style="margin: 25px 0 10px 0; color: #23282d; font-size: 16px;">Fehlerdetails:</h3>
        <div style="padding: 12px; background-color: #f8f9fa; border-radius: 4px; font-family: monospace; font-size: 13px; color: #d63301;">
            ' . esc_html($error_message) . '
        </div>

        <h3 style="margin: 25px 0 10px 0; color: #23282d; font-size: 16px;">Bestellinformationen:</h3>
        <ul style="margin: 10px 0; padding-left: 20px;">
            <li><strong>Bestellung:</strong> #' . esc_html($order_number) . '</li>
            <li><strong>Bestellungs-Link:</strong> <a href="' . esc_url($order_edit_url) . '" style="color: #667eea;">' . esc_url($order_edit_url) . '</a></li>
            <li><strong>Fehlgeschlagene Versuche:</strong> ' . (int) $retry_count . '</li>
        </ul>

        <h3 style="margin: 25px 0 10px 0; color: #23282d; font-size: 16px;">Nächste Schritte:</h3>
        <ol style="margin: 10px 0; padding-left: 20px;">
            <li>Öffnen Sie die Bestellung in WooCommerce</li>
            <li>Prüfen Sie die Details im Lexware MVP Meta-Box</li>
            <li>Versuchen Sie die Rechnung manuell zu erstellen</li>
            <li>Bei Authentifizierungsfehlern: API-Token überprüfen</li>
        </ol>

        <div style="margin-top: 25px; padding: 15px; background-color: #e7f3ff; border-left: 4px solid #0073aa; border-radius: 4px;">
            <strong>💡 Tipp:</strong> Sie können die Rechnung manuell über den "Rechnung jetzt erstellen" Button in der Bestellung neu versuchen.
        </div>';

        return self::send_email($subject, $message);
    }

    /**
     * Send database error alert
     *
     * Notifies admin when critical database operations fail.
     *
     * @since 1.2.0
     * @param string $operation Database operation that failed
     * @param string $error_message Database error message
     * @param array $context Additional context data
     * @return bool True if alert sent
     */
    public static function send_database_error_alert($operation, $error_message, $context = array()) {
        if (!self::should_send_alert(self::ALERT_DB_ERROR)) {
            return false;
        }

        $subject = '[KRITISCH] Lexware Datenbankfehler';

        $message = '<div style="padding: 15px; background-color: #f8d7da; border-left: 4px solid #dc3545; margin-bottom: 20px;">
            <strong>❌ Kritischer Datenbankfehler aufgetreten</strong>
        </div>

        <p>Bei der Datenbankoperation "<strong>' . esc_html($operation) . '</strong>" ist ein Fehler aufgetreten.</p>

        <h3 style="margin: 25px 0 10px 0; color: #23282d; font-size: 16px;">Fehlerdetails:</h3>
        <div style="padding: 12px; background-color: #f8f9fa; border-radius: 4px; font-family: monospace; font-size: 13px; color: #d63301;">
            ' . esc_html($error_message) . '
        </div>';

        if (!empty($context)) {
            $message .= '
        <h3 style="margin: 25px 0 10px 0; color: #23282d; font-size: 16px;">Kontext:</h3>
        <div style="padding: 12px; background-color: #f8f9fa; border-radius: 4px; font-family: monospace; font-size: 12px;">
            <pre style="margin: 0; white-space: pre-wrap; word-wrap: break-word;">' . esc_html(print_r($context, true)) . '</pre>
        </div>';
        }

        $message .= '
        <h3 style="margin: 25px 0 10px 0; color: #23282d; font-size: 16px;">Mögliche Ursachen:</h3>
        <ul style="margin: 10px 0; padding-left: 20px;">
            <li>Datenbank-Verbindungsprobleme</li>
            <li>Fehlende Tabellen oder Spalten</li>
            <li>Datenbankserver ist überlastet</li>
            <li>Speicherplatz auf dem Server voll</li>
        </ul>

        <h3 style="margin: 25px 0 10px 0; color: #23282d; font-size: 16px;">Empfohlene Maßnahmen:</h3>
        <ol style="margin: 10px 0; padding-left: 20px;">
            <li>Prüfen Sie die Datenbankverbindung in wp-config.php</li>
            <li>Überprüfen Sie die WordPress Debug-Logs</li>
            <li>Stellen Sie sicher, dass ausreichend Speicherplatz verfügbar ist</li>
            <li>Kontaktieren Sie Ihren Hosting-Provider bei anhaltenden Problemen</li>
        </ol>

        <div style="margin-top: 25px; padding: 15px; background-color: #fff3cd; border-left: 4px solid #ffc107; border-radius: 4px;">
            <strong>⚠️ Wichtig:</strong> Datenbankfehler können die Funktionalität des Plugins beeinträchtigen. Bitte beheben Sie das Problem zeitnah.
        </div>';

        return self::send_email($subject, $message);
    }

    /**
     * Send credit note failure alert
     *
     * Notifies admin when credit note creation fails permanently.
     *
     * @since 1.2.0
     * @param int $order_id WooCommerce order ID
     * @param string $error_message Error message
     * @param int $retry_count Number of retry attempts
     * @return bool True if alert sent
     */
    public static function send_credit_note_failure_alert($order_id, $error_message, $retry_count = 3) {
        if (!self::should_send_alert(self::ALERT_CREDIT_NOTE_FAILURE)) {
            return false;
        }

        $order = wc_get_order($order_id);
        $order_number = $order ? $order->get_order_number() : $order_id;
        $order_edit_url = admin_url('post.php?post=' . $order_id . '&action=edit');

        $subject = '[FEHLER] Lexware Gutschrift-Erstellung fehlgeschlagen';

        $message = '<div style="padding: 15px; background-color: #f8d7da; border-left: 4px solid #dc3545; margin-bottom: 20px;">
            <strong>❌ Gutschrift-Erstellung dauerhaft fehlgeschlagen</strong>
        </div>

        <p>Die Gutschrift für <strong>Bestellung #' . esc_html($order_number) . '</strong> konnte nach <strong>' . (int) $retry_count . ' Versuchen</strong> nicht erstellt werden.</p>

        <h3 style="margin: 25px 0 10px 0; color: #23282d; font-size: 16px;">Fehlerdetails:</h3>
        <div style="padding: 12px; background-color: #f8f9fa; border-radius: 4px; font-family: monospace; font-size: 13px; color: #d63301;">
            ' . esc_html($error_message) . '
        </div>

        <h3 style="margin: 25px 0 10px 0; color: #23282d; font-size: 16px;">Bestellinformationen:</h3>
        <ul style="margin: 10px 0; padding-left: 20px;">
            <li><strong>Bestellung:</strong> #' . esc_html($order_number) . '</li>
            <li><strong>Bestellungs-Link:</strong> <a href="' . esc_url($order_edit_url) . '" style="color: #667eea;">' . esc_url($order_edit_url) . '</a></li>
            <li><strong>Fehlgeschlagene Versuche:</strong> ' . (int) $retry_count . '</li>
        </ul>

        <h3 style="margin: 25px 0 10px 0; color: #23282d; font-size: 16px;">Mögliche Ursachen:</h3>
        <ul style="margin: 10px 0; padding-left: 20px;">
            <li>Keine Rechnung für diese Bestellung vorhanden</li>
            <li>Gutschrift bereits in Lexware erstellt</li>
            <li>API-Verbindungsprobleme</li>
            <li>Fehlerhafte Rückerstattungsdaten</li>
        </ul>

        <h3 style="margin: 25px 0 10px 0; color: #23282d; font-size: 16px;">Nächste Schritte:</h3>
        <ol style="margin: 10px 0; padding-left: 20px;">
            <li>Öffnen Sie die Bestellung in WooCommerce</li>
            <li>Überprüfen Sie, ob eine Rechnung vorhanden ist</li>
            <li>Prüfen Sie die Lexware MVP Meta-Box für Details</li>
            <li>Versuchen Sie die Gutschrift manuell zu erstellen</li>
        </ol>';

        return self::send_email($subject, $message);
    }

    /**
     * Test alert email (for settings page)
     *
     * Sends a test email to verify alert configuration.
     *
     * @since 1.2.0
     * @return bool True if test email sent successfully
     */
    public static function send_test_alert() {
        $subject = '[TEST] Lexware MVP Alert-System';

        $message = '<div style="padding: 15px; background-color: #d4edda; border-left: 4px solid #28a745; margin-bottom: 20px;">
            <strong>✅ Test-Benachrichtigung erfolgreich!</strong>
        </div>

        <p>Dies ist eine Test-E-Mail um zu bestätigen, dass das Lexware MVP Alert-System korrekt konfiguriert ist.</p>

        <h3 style="margin: 25px 0 10px 0; color: #23282d; font-size: 16px;">System-Informationen:</h3>
        <ul style="margin: 10px 0; padding-left: 20px;">
            <li><strong>WordPress Version:</strong> ' . get_bloginfo('version') . '</li>
            <li><strong>WooCommerce Version:</strong> ' . (defined('WC_VERSION') ? WC_VERSION : 'N/A') . '</li>
            <li><strong>Plugin Version:</strong> ' . WC_LEXWARE_MVP_VERSION . '</li>
            <li><strong>PHP Version:</strong> ' . PHP_VERSION . '</li>
            <li><strong>Aktuelles Datum/Zeit:</strong> ' . current_time('Y-m-d H:i:s') . '</li>
        </ul>

        <p style="margin-top: 25px;">Wenn Sie diese E-Mail erhalten haben, funktioniert das Alert-System einwandfrei. Bei kritischen Fehlern werden Sie automatisch benachrichtigt.</p>

        <div style="margin-top: 25px; padding: 15px; background-color: #e7f3ff; border-left: 4px solid #0073aa; border-radius: 4px;">
            <strong>ℹ️ Hinweis:</strong> Alert-E-Mails werden maximal 1x pro Stunde pro Fehlertyp versendet, um Spam zu vermeiden.
        </div>';

        return self::send_email($subject, $message);
    }
}
