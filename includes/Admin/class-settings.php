<?php
/**
 * Admin Settings - WooCommerce Settings Integration
 *
 * Provides settings page within WooCommerce Settings for configuring
 * API connection, invoice triggers, email settings, and advanced options.
 *
 * @package WC_Lexware_MVP
 * @since 1.0.0
 */

namespace WC_Lexware_MVP\Admin;

use WC_Lexware_MVP\Core\Payment_Mapping;
use WC_Lexware_MVP\API\Client as API_Client;

class Settings {

    /**
     * Initialize hooks
     *
     * Registers WooCommerce settings tab, AJAX handlers, and admin scripts.
     *
     * @since 1.0.0
     */
    public static function init() {
        // Add settings tab to WooCommerce
        add_filter('woocommerce_settings_tabs_array', array(__CLASS__, 'add_settings_tab'), 50);

        // Render settings page
        add_action('woocommerce_settings_tabs_lexware_mvp', array(__CLASS__, 'render_settings'));

        // Save settings
        add_action('woocommerce_update_options_lexware_mvp', array(__CLASS__, 'save_settings'));

        // AJAX handler for connection test
        add_action('wp_ajax_lexware_test_connection', array(__CLASS__, 'ajax_test_connection'));

        // AJAX handler for payment conditions refresh
        add_action('wp_ajax_lexware_refresh_payment_conditions', array(__CLASS__, 'ajax_refresh_payment_conditions'));

        // Enqueue admin scripts
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_admin_scripts'));
    }

    /**
     * Add Lexware MVP tab to WooCommerce settings
     *
     * @since 1.0.0
     * @param array $tabs Existing WooCommerce settings tabs
     * @return array Modified tabs array with Lexware MVP tab added
     */
    public static function add_settings_tab($tabs) {
        $tabs['lexware_mvp'] = __('Lexware MVP', WC_LEXWARE_MVP_TEXT_DOMAIN);
        return $tabs;
    }

    /**
     * Get settings fields
     *
     * Defines all plugin settings fields for WooCommerce Settings API.
     * Includes API config, invoice/credit note triggers, and email settings.
     *
     * @since 1.0.0
     * @return array Settings fields configuration
     */
    public static function get_settings() {
        $order_statuses = wc_get_order_statuses();

        // Check if token exists (don't decrypt, just check)
        $token_exists = !empty(get_option('wc_lexware_mvp_api_token', ''));

        return array(
            // Section: API Configuration
            array(
                'title' => __('🔐 API-Konfiguration', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'type'  => 'title',
                'desc'  => __('Verbinde deinen Shop mit Lexware Office.', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'id'    => 'wc_lexware_mvp_api_section'
            ),

            array(
                'title'       => __('API-Token', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'desc'        => '<div id="lexware-connection-status" style="margin-top: 8px;">' .
                    ($token_exists ? '<em>Klicke auf "Verbindung testen" um die Konfiguration zu überprüfen.</em>' : '') .
                    '</div>',
                'id'          => 'wc_lexware_mvp_api_token',
                'type'        => 'password',
                'css'         => 'min-width: 400px;',
                'placeholder' => $token_exists ? __('Token ist gespeichert. Leer lassen um beizubehalten.', WC_LEXWARE_MVP_TEXT_DOMAIN) : __('Lexware API-Token eingeben', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'desc_tip'    => false,
                'default'     => '',
                'value'       => '' // Immer leer anzeigen
            ),

            array(
                'type' => 'sectionend',
                'id'   => 'wc_lexware_mvp_api_section'
            ),

            // Section: Invoice Settings
            array(
                'title' => __('🧾 Rechnungs-Einstellungen', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'type'  => 'title',
                'desc'  => __('Wähle wann Rechnungen automatisch erstellt werden sollen.', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'id'    => 'wc_lexware_mvp_invoice_section'
            ),

            array(
                'title'    => __('Auslöse-Status für Rechnung', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'desc'     => __('Rechnung wird erstellt wenn Bestellung einen dieser Status erreicht (Mehrfachauswahl möglich)', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'id'       => 'wc_lexware_mvp_invoice_trigger_statuses',
                'type'     => 'multiselect',
                'options'  => $order_statuses,
                'default'  => array('wc-completed'),
                'class'    => 'wc-enhanced-select',
                'desc_tip' => __('Strg/Cmd + Klick für Mehrfachauswahl', WC_LEXWARE_MVP_TEXT_DOMAIN)
            ),

            array(
                'title'   => __('Auto-Finalisierung Rechnung', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'desc'    => __('Rechnung automatisch finalisieren (unveränderbar machen)', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'id'      => 'wc_lexware_mvp_auto_finalize_invoice',
                'type'    => 'checkbox',
                'default' => 'yes'
            ),

            array(
                'title'       => __('Bestellnummer-Präfix', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'desc'        => __('Optionales Präfix für Bestellnummern in Lexware-Dokumenten (z.B. "BSD-12345"). Leer lassen für nur die Bestellnummer.', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'id'          => 'wc_lexware_mvp_order_prefix',
                'type'        => 'text',
                'default'     => '',
                'css'         => 'min-width:300px;',
                'placeholder' => __('z.B. SHOP oder MeinShop.de', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'desc_tip'    => true
            ),

            array(
                'title'   => __('Email-Versand', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'desc'    => __('Rechnung per Email an Kunden senden (separate Email mit PDF-Anhang)', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'id'      => 'wc_lexware_mvp_send_invoice_email',
                'type'    => 'checkbox',
                'default' => 'yes'
            ),

            array(
                'type' => 'sectionend',
                'id'   => 'wc_lexware_mvp_invoice_section'
            ),

            // Section: Order Confirmation Settings
            array(
                'title' => __('✅ Auftragsbestätigung-Einstellungen', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'type'  => 'title',
                'desc'  => __('Automatische Auftragsbestätigungen bei Bestelleingang. Leer lassen um Auftragsbestätigungen zu deaktivieren.', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'id'    => 'wc_lexware_mvp_order_confirmation_section'
            ),

            array(
                'title'    => __('Auslöse-Status für Auftragsbestätigung', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'desc'     => __('Auftragsbestätigung wird erstellt wenn Bestellung einen dieser Status erreicht (Mehrfachauswahl möglich). Leer lassen um Auftragsbestätigungen zu deaktivieren.', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'id'       => 'wc_lexware_mvp_order_confirmation_trigger_statuses',
                'type'     => 'multiselect',
                'options'  => $order_statuses,
                'default'  => array(),
                'class'    => 'wc-enhanced-select',
                'desc_tip' => __('Typischerweise "In Bearbeitung" (processing) oder "Wartend" (on-hold). Leer = deaktiviert.', WC_LEXWARE_MVP_TEXT_DOMAIN)
            ),

            array(
                'title'   => __('Auto-Finalisierung Auftragsbestätigung', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'desc'    => __('Auftragsbestätigung automatisch finalisieren (unveränderbar machen)', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'id'      => 'wc_lexware_mvp_auto_finalize_order_confirmation',
                'type'    => 'checkbox',
                'default' => 'yes'
            ),

            array(
                'title'   => __('Auftragsbestätigung Email-Versand', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'desc'    => __('Auftragsbestätigung per Email an Kunden senden (mit PDF-Anhang)', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'id'      => 'wc_lexware_mvp_send_order_confirmation_email',
                'type'    => 'checkbox',
                'default' => 'yes'
            ),

            array(
                'type' => 'sectionend',
                'id'   => 'wc_lexware_mvp_order_confirmation_section'
            ),

            // Section: Credit Note Settings
            array(
                'title' => __('💸 Gutschrift-Einstellungen', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'type'  => 'title',
                'desc'  => __('Automatische Gutschriften bei Rückerstattungen. Gutschriften werden automatisch erstellt wenn Status-Liste nicht leer ist.', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'id'    => 'wc_lexware_mvp_credit_note_section'
            ),

            array(
                'title'    => __('Auslöse-Status für Gutschrift', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'desc'     => __('Gutschrift wird erstellt wenn Bestellung einen dieser Status erreicht (Mehrfachauswahl möglich). Leer lassen um Gutschriften zu deaktivieren.', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'id'       => 'wc_lexware_mvp_credit_note_trigger_statuses',
                'type'     => 'multiselect',
                'options'  => $order_statuses,
                'default'  => array('wc-refunded'),
                'class'    => 'wc-enhanced-select',
                'desc_tip' => __('Typischerweise "Erstattet" (refunded). Leer lassen um Gutschriften zu deaktivieren.', WC_LEXWARE_MVP_TEXT_DOMAIN)
            ),

            array(
                'title'   => __('Auto-Finalisierung Gutschrift', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'desc'    => __('Gutschrift automatisch finalisieren (unveränderbar machen)', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'id'      => 'wc_lexware_mvp_auto_finalize_credit_note',
                'type'    => 'checkbox',
                'default' => 'yes'
            ),

            array(
                'title'   => __('Gutschrift Email-Versand', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'desc'    => __('Gutschrift per Email an Kunden senden (mit PDF-Anhang)', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'id'      => 'wc_lexware_mvp_send_credit_note_email',
                'type'    => 'checkbox',
                'default' => 'yes'
            ),

            array(
                'type' => 'sectionend',
                'id'   => 'wc_lexware_mvp_credit_note_section'
            ),

            // Section: Advanced Settings
            array(
                'title' => __('⚙️ Erweiterte Einstellungen', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'type'  => 'title',
                'desc'  => __('Erweiterte Konfiguration für Entwickler und Debugging.', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'id'    => 'wc_lexware_mvp_advanced_section'
            ),

            array(
                'title'    => __('Log-Level', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'desc'     => __('Mindest-Log-Level für Lexware MVP Logs (Debug = alle, Error = nur Fehler)', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'id'       => 'wc_lexware_mvp_log_level',
                'type'     => 'select',
                'options'  => array(
                    'auto'    => __('Auto (Debug wenn WP_DEBUG aktiv, sonst Info)', WC_LEXWARE_MVP_TEXT_DOMAIN),
                    'debug'   => __('Debug (alle Meldungen)', WC_LEXWARE_MVP_TEXT_DOMAIN),
                    'info'    => __('Info (normale Meldungen)', WC_LEXWARE_MVP_TEXT_DOMAIN),
                    'warning' => __('Warning (nur Warnungen und Fehler)', WC_LEXWARE_MVP_TEXT_DOMAIN),
                    'error'   => __('Error (nur Fehler)', WC_LEXWARE_MVP_TEXT_DOMAIN)
                ),
                'default'  => 'auto',
                'desc_tip' => __('Kann auch via WC_LEXWARE_MVP_LOG_LEVEL Konstante gesetzt werden', WC_LEXWARE_MVP_TEXT_DOMAIN)
            ),

            array(
                'title'   => __('🔒 GDPR: Sensible Daten schwärzen', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'desc'    => __('Emails, Adressen, Tokens in Logs automatisch anonymisieren (Empfohlen für DSGVO-Konformität)', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'id'      => 'wc_lexware_mvp_redact_sensitive_logs',
                'type'    => 'checkbox',
                'default' => 'yes',
                'desc_tip' => __('Schwärzt sensible Daten wie E-Mail-Adressen (m***@example.com), API-Tokens (***REDACTED***), und Adressen in allen Log-Dateien. Kann via WC_LEXWARE_MVP_DISABLE_LOG_REDACTION deaktiviert werden.', WC_LEXWARE_MVP_TEXT_DOMAIN)
            ),

            array(
                'type' => 'sectionend',
                'id'   => 'wc_lexware_mvp_advanced_section'
            ),

            // Section: Circuit Breaker Configuration
            array(
                'title' => __('🔌 Circuit Breaker (Fehlertoleranz)', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'type'  => 'title',
                'desc'  => __('Schützt deinen Shop vor wiederholten fehlgeschlagenen API-Anfragen. Der Circuit Breaker blockiert automatisch Requests wenn die Lexware API nicht erreichbar ist und testet periodisch die Wiederherstellung.', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'id'    => 'wc_lexware_mvp_circuit_breaker_section'
            ),

            array(
                'title'    => __('Fehler-Schwelle', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'desc'     => __('Anzahl der fehlgeschlagenen Requests bis der Circuit Breaker öffnet', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'id'       => 'wc_lexware_mvp_circuit_failure_threshold',
                'type'     => 'number',
                'default'  => 5,
                'css'      => 'width: 80px;',
                'custom_attributes' => array(
                    'min'  => 1,
                    'max'  => 20,
                    'step' => 1
                ),
                'desc_tip' => __('Standard: 5. Zu niedrig = Falsch-Positive bei temporären Netzwerkproblemen. Zu hoch = Langsame Erkennung von Ausfällen.', WC_LEXWARE_MVP_TEXT_DOMAIN)
            ),

            array(
                'title'    => __('Erfolgs-Schwelle', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'desc'     => __('Anzahl erfolgreicher Test-Requests bis der Circuit Breaker schließt', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'id'       => 'wc_lexware_mvp_circuit_success_threshold',
                'type'     => 'number',
                'default'  => 2,
                'css'      => 'width: 80px;',
                'custom_attributes' => array(
                    'min'  => 1,
                    'max'  => 10,
                    'step' => 1
                ),
                'desc_tip' => __('Standard: 2. Anzahl erfolgreicher Requests im Test-Modus (HALF_OPEN) bevor normale Operation wieder aufgenommen wird.', WC_LEXWARE_MVP_TEXT_DOMAIN)
            ),

            array(
                'title'    => __('Basis-Timeout', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'desc'     => __('Sekunden', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'id'       => 'wc_lexware_mvp_circuit_timeout',
                'type'     => 'number',
                'default'  => 60,
                'css'      => 'width: 80px;',
                'custom_attributes' => array(
                    'min'  => 10,
                    'max'  => 600,
                    'step' => 10
                ),
                'desc_tip' => __('Standard: 60 Sekunden. Wartezeit bis der erste Test-Request versucht wird. Verwendet exponentielles Backoff bei wiederholten Fehlern (60s → 120s → 240s → ... max 3600s).', WC_LEXWARE_MVP_TEXT_DOMAIN)
            ),

            array(
                'type' => 'sectionend',
                'id'   => 'wc_lexware_mvp_circuit_breaker_section'
            ),

            // Section: Migration
            array(
                'title' => __('🔄 Migration (Germanized)', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'type'  => 'title',
                'desc'  => __('Cutoff für die Umstellung von Lexware auf Germanized Pro. Bestellungen BIS einschließlich dieses Datums werden weiterhin über Lexware erstellt. Bestellungen DANACH werden übersprungen (Germanized zuständig).', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'id'    => 'wc_lexware_mvp_migration_section'
            ),

            array(
                'title'    => __('Cutoff-Datum (Bestelldatum)', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'desc'     => __('Format: YYYY-MM-DD. Leer = kein Cutoff (alle Bestellungen werden verarbeitet).', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'id'       => 'wc_lexware_mvp_cutoff_date',
                'type'     => 'text',
                'default'  => '',
                'css'      => 'max-width: 180px;',
                'placeholder' => 'z.B. 2026-03-31',
                'desc_tip' => __('Entscheidend ist das Bestelldatum (date_created), nicht wann die Bestellung abgeschlossen wird. Alle Dokument-Typen (Rechnungen, Gutschriften, Auftragsbestätigungen) sind betroffen.', WC_LEXWARE_MVP_TEXT_DOMAIN)
            ),

            array(
                'type' => 'sectionend',
                'id'   => 'wc_lexware_mvp_migration_section'
            )
        );
    }

    /**
     * Render settings page
     */
    public static function render_settings() {
        woocommerce_admin_fields(self::get_settings());

        // Custom Payment Mapping Section (nicht via WooCommerce Settings API)
        self::render_payment_mapping_section();
    }

    /**
     * Render Payment Mapping Settings Section
     */
    private static function render_payment_mapping_section() {
        echo '<h2>💳 ' . __('Payment Mapping (Zahlungsbedingungen)', WC_LEXWARE_MVP_TEXT_DOMAIN) . '</h2>';
        echo '<table class="form-table">';
        echo '<tbody>';
        echo '<tr valign="top">';
        echo '<th scope="row" class="titledesc">&nbsp;</th>';
        echo '<td class="forminp">';

        echo '<p class="description" style="margin-bottom: 15px;">';
        echo __('Verknüpfe WooCommerce Zahlungsmethoden mit Lexware Zahlungsbedingungen. Dies bestimmt die Zahlungsfristen und Skonto-Bedingungen auf Rechnungen.', WC_LEXWARE_MVP_TEXT_DOMAIN);
        echo '</p>';

        // Fetch payment conditions (refresh is now done via AJAX POST)
        $conditions = Payment_Mapping::fetch_payment_conditions();
        $is_error = \is_wp_error($conditions);

        if ($is_error) {
            echo '<div class="notice notice-error inline" style="margin: 10px 0;">';
            echo '<p><strong>' . __('Fehler beim Laden der Zahlungsbedingungen:', WC_LEXWARE_MVP_TEXT_DOMAIN) . '</strong> ';
            echo esc_html($conditions->get_error_message());
            echo '</p>';
            echo '</div>';

            // Show refresh button
            echo '<p>';
            echo '<button type="button" class="button" onclick="refreshPaymentConditions()">';
            echo '🔄 ' . __('Erneut versuchen', WC_LEXWARE_MVP_TEXT_DOMAIN);
            echo '</button>';
            echo '</p>';

            echo '</td></tr></tbody></table>';
            return;
        }

        if (empty($conditions)) {
            echo '<div class="notice notice-warning inline" style="margin: 10px 0;">';
            echo '<p><strong>' . __('Keine Zahlungsbedingungen gefunden.', WC_LEXWARE_MVP_TEXT_DOMAIN) . '</strong> ';
            echo __('Bitte konfigurieren Sie Zahlungsbedingungen in Ihrem Lexware-Konto.', WC_LEXWARE_MVP_TEXT_DOMAIN);
            echo '</p>';
            echo '</div>';

            echo '</td></tr></tbody></table>';
            return;
        }

        // Get WooCommerce payment methods
        $payment_methods = Payment_Mapping::get_wc_payment_methods();

        if (empty($payment_methods)) {
            echo '<div class="notice notice-info inline" style="margin: 10px 0;">';
            echo '<p>' . __('Keine aktiven Zahlungsmethoden gefunden.', WC_LEXWARE_MVP_TEXT_DOMAIN) . ' ';
            echo __('Aktivieren Sie Zahlungsmethoden in WooCommerce → Einstellungen → Zahlungen.', WC_LEXWARE_MVP_TEXT_DOMAIN);
            echo '</p>';
            echo '</div>';

            echo '</td></tr></tbody></table>';
            return;
        }

        // Get current mapping
        $mapping = Payment_Mapping::get_mapping();

        // Render mapping table
        echo '<table class="widefat" style="max-width: 800px; margin-top: 10px;">';
        echo '<thead>';
        echo '<tr>';
        echo '<th style="width: 40%;">' . __('WooCommerce Zahlungsmethode', WC_LEXWARE_MVP_TEXT_DOMAIN) . '</th>';
        echo '<th style="width: 60%;">' . __('Lexware Zahlungsbedingung', WC_LEXWARE_MVP_TEXT_DOMAIN) . '</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        foreach ($payment_methods as $method_id => $method_title) {
            $selected_condition = isset($mapping[$method_id]) ? $mapping[$method_id] : '';

            echo '<tr>';
            echo '<td>';
            echo '<strong>' . esc_html($method_title) . '</strong>';
            echo '<br><small style="color: #666;">' . esc_html($method_id) . '</small>';
            echo '</td>';
            echo '<td>';

            // Dropdown select
            $field_name = 'wc_lexware_mvp_payment_mapping[' . esc_attr($method_id) . ']';
            echo '<select name="' . $field_name . '" style="width: 100%; max-width: 400px;">';

            // Default option
            echo '<option value="">' . __('-- Standard (Lexware Default) --', WC_LEXWARE_MVP_TEXT_DOMAIN) . '</option>';

            // Payment conditions options
            foreach ($conditions as $condition) {
                $condition_id = $condition['id'];
                $label = Payment_Mapping::format_condition_label($condition);
                $selected = ($condition_id === $selected_condition) ? ' selected' : '';

                echo '<option value="' . esc_attr($condition_id) . '"' . $selected . '>';
                echo esc_html($label);
                echo '</option>';
            }

            echo '</select>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';

        // Refresh button
        echo '<p style="margin-top: 15px;">';
        echo '<button type="button" class="button" id="lexware-refresh-payment-conditions">';
        echo '🔄 ' . __('Zahlungsbedingungen aktualisieren', WC_LEXWARE_MVP_TEXT_DOMAIN);
        echo '</button>';
        echo ' <span class="description">' . __('Lädt die neuesten Zahlungsbedingungen aus Lexware.', WC_LEXWARE_MVP_TEXT_DOMAIN) . '</span>';
        echo ' <span id="payment-conditions-status" style="margin-left: 10px;"></span>';
        echo '</p>';

        // JavaScript for refresh button (AJAX POST with nonce)
        echo '<script>
        jQuery(document).ready(function($) {
            $("#lexware-refresh-payment-conditions").on("click", function(e) {
                e.preventDefault();

                if (!confirm("' . esc_js(__('Zahlungsbedingungen von Lexware neu laden?', WC_LEXWARE_MVP_TEXT_DOMAIN)) . '")) {
                    return;
                }

                var $btn = $(this);
                var $status = $("#payment-conditions-status");
                var originalText = $btn.text();

                $btn.prop("disabled", true).text("⏳ ' . esc_js(__('Lade...', WC_LEXWARE_MVP_TEXT_DOMAIN)) . '");
                $status.html("");

                $.ajax({
                    url: ajaxurl,
                    type: "POST",
                    data: {
                        action: "lexware_refresh_payment_conditions",
                        nonce: "' . wp_create_nonce('lexware-refresh-payment-conditions') . '"
                    },
                    success: function(response) {
                        if (response.success) {
                            $status.html("<span style=\"color: #0f5132;\">✅ " + response.data.message + "</span>");
                            // Reload page after 1 second to show updated data
                            setTimeout(function() {
                                location.reload();
                            }, 1000);
                        } else {
                            $status.html("<span style=\"color: #842029;\">❌ " + response.data.message + "</span>");
                            $btn.prop("disabled", false).text(originalText);
                        }
                    },
                    error: function() {
                        $status.html("<span style=\"color: #842029;\">❌ ' . esc_js(__('Verbindungsfehler', WC_LEXWARE_MVP_TEXT_DOMAIN)) . '</span>");
                        $btn.prop("disabled", false).text(originalText);
                    }
                });
            });
        });
        </script>';

        echo '</td>';
        echo '</tr>';
        echo '</tbody>';
        echo '</table>';
    }

    /**
     * AJAX handler for refreshing payment conditions
     *
     * Clears cached payment conditions and reloads from Lexware API.
     * Uses POST method with nonce verification for CSRF protection.
     *
     * @since 1.2.6
     */
    public static function ajax_refresh_payment_conditions() {
        // Verify nonce
        check_ajax_referer('lexware-refresh-payment-conditions', 'nonce');

        // Check user permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array(
                'message' => __('Keine Berechtigung', WC_LEXWARE_MVP_TEXT_DOMAIN)
            ));
        }

        // Clear cache
        Payment_Mapping::clear_cache();

        // Fetch fresh data
        $conditions = Payment_Mapping::fetch_payment_conditions();

        if (\is_wp_error($conditions)) {
            wp_send_json_error(array(
                'message' => $conditions->get_error_message()
            ));
        }

        wp_send_json_success(array(
            'message' => __('Zahlungsbedingungen erfolgreich aktualisiert!', WC_LEXWARE_MVP_TEXT_DOMAIN),
            'count' => count($conditions)
        ));
    }

    /**
     * AJAX handler for connection test
     */
    public static function ajax_test_connection() {
        // Verify nonce
        check_ajax_referer('lexware-test-connection', 'nonce');

        // Check user permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array(
                'message' => __('Keine Berechtigung', WC_LEXWARE_MVP_TEXT_DOMAIN)
            ));
        }

        // Test the connection
        $api = new API_Client();
        $result = $api->test_connection();

        if (\is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message(),
                'code' => $result->get_error_code()
            ));
        }

        // Extract profile information if available
        $profile_info = '';
        if (!empty($result['data']['organisationName'])) {
            $profile_info = sprintf(
                __('Organisation: %s', WC_LEXWARE_MVP_TEXT_DOMAIN),
                esc_html($result['data']['organisationName'])
            );
        }

        wp_send_json_success(array(
            'message' => __('Verbindung erfolgreich!', WC_LEXWARE_MVP_TEXT_DOMAIN),
            'profile' => $profile_info
        ));
    }

    /**
     * Enqueue admin scripts
     */
    public static function enqueue_admin_scripts($hook) {
        // Only load on WooCommerce settings page
        if ('woocommerce_page_wc-settings' !== $hook) {
            return;
        }

        // Only load on our settings tab
        if (!isset($_GET['tab']) || $_GET['tab'] !== 'lexware_mvp') {
            return;
        }

        // Inline script for connection test and button injection
        wp_add_inline_script('jquery', "
            jQuery(document).ready(function($) {
                // Inject button next to API token input
                var tokenInput = $('#wc_lexware_mvp_api_token');
                if (tokenInput.length) {
                    tokenInput.css({
                        'display': 'inline-block',
                        'vertical-align': 'middle',
                        'margin-right': '10px'
                    });

                    var button = $('<button type=\"button\" id=\"lexware-test-connection\" class=\"button button-secondary\" style=\"vertical-align: middle;\">' +
                        '<span class=\"dashicons dashicons-update\" style=\"vertical-align: middle; margin-top: 3px;\"></span> ' +
                        'Verbindung testen</button>');

                    tokenInput.after(button);
                }

                // Connection test handler
                $(document).on('click', '#lexware-test-connection', function(e) {
                    e.preventDefault();

                    var button = $(this);
                    var statusDiv = $('#lexware-connection-status');

                    // Disable button and show loading
                    button.prop('disabled', true);
                    button.find('.dashicons').addClass('dashicons-update-spinning');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'lexware_test_connection',
                            nonce: '" . wp_create_nonce('lexware-test-connection') . "'
                        },
                        success: function(response) {
                            if (response.success) {
                                statusDiv.html('<div style=\"padding: 8px 12px; background: #d1e7dd; border-left: 4px solid #0f5132; border-radius: 4px;\">' +
                                    '<strong>✅ ' + response.data.message + '</strong>' +
                                    (response.data.profile ? '<div style=\"margin-top: 4px; font-size: 0.9em;\">' + response.data.profile + '</div>' : '') +
                                    '<div style=\"margin-top: 4px; font-size: 0.85em; opacity: 0.8;\">API ist erreichbar und Token ist gültig.</div>' +
                                    '</div>');
                            } else {
                                statusDiv.html('<div style=\"padding: 8px 12px; background: #f8d7da; border-left: 4px solid #842029; border-radius: 4px;\">' +
                                    '<strong>❌ Verbindung fehlgeschlagen</strong>' +
                                    '<div style=\"margin-top: 4px;\">' + response.data.message + '</div>' +
                                    '</div>');
                            }
                        },
                        error: function() {
                            statusDiv.html('<div style=\"padding: 8px 12px; background: #f8d7da; border-left: 4px solid #842029; border-radius: 4px;\">' +
                                '<strong>❌ Fehler beim Test</strong>' +
                                '<div style=\"margin-top: 4px;\">Unerwarteter Fehler beim Verbindungstest.</div>' +
                                '</div>');
                        },
                        complete: function() {
                            button.prop('disabled', false);
                            button.find('.dashicons').removeClass('dashicons-update-spinning');
                        }
                    });
                });
            });
        ");
    }

    /**
     * Save settings
     */
    public static function save_settings() {
        // Verify nonce
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'woocommerce-settings')) {
            wp_die(__('Sicherheitsprüfung fehlgeschlagen.', WC_LEXWARE_MVP_TEXT_DOMAIN));
        }

        // Handle API token separately with validation
        if (isset($_POST['wc_lexware_mvp_api_token'])) {
            $new_token = sanitize_text_field(wp_unslash($_POST['wc_lexware_mvp_api_token']));

            // Only validate and update if a new token was provided
            if (!empty($new_token)) {
                $validation = \WC_Lexware_MVP_Validator::validate_api_token($new_token);
                if (\is_wp_error($validation)) {
                    \WC_Admin_Settings::add_error($validation->get_error_message());
                    unset($_POST['wc_lexware_mvp_api_token']);
                    woocommerce_update_options(self::get_settings());
                    return;
                }

                $encrypted = \WC_Lexware_MVP_Encryptor::encrypt($new_token);
                if (empty($encrypted)) {
                    \WC_Admin_Settings::add_error(__('Verschlüsselung fehlgeschlagen.', WC_LEXWARE_MVP_TEXT_DOMAIN));
                    unset($_POST['wc_lexware_mvp_api_token']);
                    woocommerce_update_options(self::get_settings());
                    return;
                }

                // Test API connection with encrypted token BEFORE saving
                update_option('wc_lexware_mvp_api_token', $encrypted);
                $api = new API_Client();

                // Clear any existing connection cache before testing new token
                $api->clear_connection_cache();

                // Force fresh API test (bypass cache)
                $test_result = $api->test_connection(true);

                if (\is_wp_error($test_result)) {
                    // Revert token if connection test fails
                    delete_option('wc_lexware_mvp_api_token');
                    \WC_Admin_Settings::add_error(
                        sprintf(
                            __('API-Token ungültig: %s', WC_LEXWARE_MVP_TEXT_DOMAIN),
                            $test_result->get_error_message()
                        )
                    );
                    unset($_POST['wc_lexware_mvp_api_token']);
                    woocommerce_update_options(self::get_settings());
                    return;
                }

                \WC_Admin_Settings::add_message(__('API-Token erfolgreich gespeichert und getestet.', WC_LEXWARE_MVP_TEXT_DOMAIN));
            }

            // Remove from POST to prevent WooCommerce from saving it
            unset($_POST['wc_lexware_mvp_api_token']);
        }

        // Validate invoice trigger statuses
        if (isset($_POST['wc_lexware_mvp_invoice_trigger_statuses'])) {
            $statuses = array_map('sanitize_text_field', wp_unslash($_POST['wc_lexware_mvp_invoice_trigger_statuses']));
            foreach ($statuses as $status) {
                $validation = \WC_Lexware_MVP_Validator::validate_order_status($status);
                if (\is_wp_error($validation)) {
                    \WC_Admin_Settings::add_error($validation->get_error_message());
                    unset($_POST['wc_lexware_mvp_invoice_trigger_statuses']);
                    break;
                }
            }
        }

        // Validate credit note trigger statuses
        if (isset($_POST['wc_lexware_mvp_credit_note_trigger_statuses'])) {
            $statuses = array_map('sanitize_text_field', wp_unslash($_POST['wc_lexware_mvp_credit_note_trigger_statuses']));
            foreach ($statuses as $status) {
                $validation = \WC_Lexware_MVP_Validator::validate_order_status($status);
                if (\is_wp_error($validation)) {
                    \WC_Admin_Settings::add_error($validation->get_error_message());
                    unset($_POST['wc_lexware_mvp_credit_note_trigger_statuses']);
                    break;
                }
            }
        }

        // Validate order confirmation trigger statuses
        if (isset($_POST['wc_lexware_mvp_order_confirmation_trigger_statuses'])) {
            $statuses = array_map('sanitize_text_field', wp_unslash($_POST['wc_lexware_mvp_order_confirmation_trigger_statuses']));
            foreach ($statuses as $status) {
                $validation = \WC_Lexware_MVP_Validator::validate_order_status($status);
                if (\is_wp_error($validation)) {
                    \WC_Admin_Settings::add_error($validation->get_error_message());
                    unset($_POST['wc_lexware_mvp_order_confirmation_trigger_statuses']);
                    break;
                }
            }
        }

        // Sanitize boolean fields
        $_POST['wc_lexware_mvp_auto_finalize_invoice'] = isset($_POST['wc_lexware_mvp_auto_finalize_invoice']) ? 'yes' : 'no';
        $_POST['wc_lexware_mvp_auto_finalize_credit_note'] = isset($_POST['wc_lexware_mvp_auto_finalize_credit_note']) ? 'yes' : 'no';
        $_POST['wc_lexware_mvp_auto_finalize_order_confirmation'] = isset($_POST['wc_lexware_mvp_auto_finalize_order_confirmation']) ? 'yes' : 'no';
        $_POST['wc_lexware_mvp_send_invoice_email'] = isset($_POST['wc_lexware_mvp_send_invoice_email']) ? 'yes' : 'no';
        $_POST['wc_lexware_mvp_send_credit_note_email'] = isset($_POST['wc_lexware_mvp_send_credit_note_email']) ? 'yes' : 'no';
        $_POST['wc_lexware_mvp_send_order_confirmation_email'] = isset($_POST['wc_lexware_mvp_send_order_confirmation_email']) ? 'yes' : 'no';

        // Save Payment Mapping
        if (isset($_POST['wc_lexware_mvp_payment_mapping'])) {
            $mapping = array();

            foreach ($_POST['wc_lexware_mvp_payment_mapping'] as $method_id => $condition_id) {
                // Only save non-empty mappings
                if (!empty($condition_id)) {
                    $mapping[sanitize_text_field($method_id)] = sanitize_text_field($condition_id);
                }
            }

            Payment_Mapping::save_mapping($mapping);

            \WC_Lexware_MVP_Logger::info('Payment mapping saved', array(
                'mapping_count' => count($mapping)
            ));
        }

        // Save other settings normally
        woocommerce_update_options(self::get_settings());
    }
}
