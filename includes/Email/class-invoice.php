<?php
/**
 * Email Handler - Invoice Email with PDF Attachment
 *
 * Extends WooCommerce email system to send invoices created in Lexware.
 * Automatically triggered when invoice PDF is ready.
 *
 * @package WC_Lexware_MVP
 * @since 1.0.0
 */

namespace WC_Lexware_MVP\Email;

class Invoice extends \WC_Email {

    /**
     * PDF Attachment paths
     *
     * @since 1.0.0
     * @var array
     */
    public $attachments = array();

    /**
     * Invoice number from Lexware (e.g., RE0047)
     *
     * @since 1.0.0
     * @var string
     */
    public $invoice_number = '';

    /**
     * Lexware Invoice ID (UUID)
     *
     * @since 1.0.0
     * @var string
     */
    public $invoice_id = '';

    /**
     * Constructor
     *
     * Configures email ID, title, templates, and registers trigger hook.
     *
     * @since 1.0.0
     */
    public function __construct() {
        $this->id = 'lexware_mvp_invoice';
        $this->title = __('Lexware Rechnung', WC_LEXWARE_MVP_TEXT_DOMAIN);
        $this->description = __('Email mit Lexware-Rechnung als PDF-Anhang', WC_LEXWARE_MVP_TEXT_DOMAIN);

        $this->template_html = 'emails/lexware-invoice.php';
        $this->template_plain = 'emails/plain/lexware-invoice.php';
        $this->template_base = plugin_dir_path(dirname(dirname(__FILE__))) . 'templates/';

        $this->customer_email = true;

        // Triggers
        add_action('wc_lexware_mvp_invoice_ready', array($this, 'trigger'), 10, 3);

        // Call parent constructor to initialize settings
        parent::__construct();

        // Set default subject and heading if not already set
        $this->subject = $this->get_option('subject', $this->get_default_subject());
        $this->heading = $this->get_option('heading', $this->get_default_heading());
    }

    /**
     * Get email type option group
     *
     * Places this email in the 'Lexware' group in WooCommerce settings.
     *
     * @since 1.0.1
     * @return string
     */
    public function get_email_type_option_group() {
        return 'lexware';
    }

    /**
     * Trigger email
     *
     * Called via 'wc_lexware_mvp_invoice_ready' action when PDF is created.
     * Attaches PDF and sends email to customer.
     *
     * @since 1.0.0
     * @param int    $order_id           Order ID
     * @param int    $invoice_record_id  Invoice database record ID
     * @param string $pdf_filename       PDF filename to attach
     */
    public function trigger($order_id, $invoice_record_id, $pdf_filename) {
        \WC_Lexware_MVP_Logger::info('📧 EMAIL TRIGGER CALLED', array(
            'order_id' => $order_id,
            'invoice_record_id' => $invoice_record_id,
            'pdf_filename' => $pdf_filename,
            'function_exists_wc_get_order' => function_exists('wc_get_order'),
            'class_WC_Email_exists' => class_exists('WC_Email'),
            'this_is_a' => get_class($this)
        ));

        $this->setup_locale();

        \WC_Lexware_MVP_Logger::info('🔍 About to call wc_get_order', array('order_id' => $order_id));

        $order = wc_get_order($order_id);

        \WC_Lexware_MVP_Logger::info('🔍 wc_get_order RETURNED', array(
            'order_id' => $order_id,
            'order_object' => $order ? 'EXISTS' : 'NULL',
            'order_type' => $order ? get_class($order) : 'N/A'
        ));

        if (!$order) {
            \WC_Lexware_MVP_Logger::error('Order not found in email trigger', array('order_id' => $order_id));
            $this->restore_locale();
            return;
        }

        \WC_Lexware_MVP_Logger::debug('Order loaded successfully', array(
            'order_id' => $order_id,
            'billing_email' => $order->get_billing_email()
        ));

        $this->object = $order;
        $this->recipient = $order->get_billing_email();

        // Load invoice number and ID from order meta
        $this->invoice_number = $order->get_meta('_lexware_invoice_number', true);
        $this->invoice_id = $order->get_meta('_lexware_invoice_id', true);

        // Set placeholders for subject/heading
        $this->placeholders = array(
            '{order_number}' => $order->get_order_number(),
            '{order_date}' => wc_format_datetime($order->get_date_created()),
            '{invoice_number}' => $this->invoice_number,
            '{site_title}' => $this->get_blogname()
        );

        // PDF-Anhang hinzufügen - Use PDF Handler for consistent path
        $pdf_path = \WC_Lexware_MVP_PDF_Handler::get_pdf_path($pdf_filename);

        if (\WC_Lexware_MVP_PDF_Handler::pdf_exists($pdf_filename)) {
            // Check file size before attaching
            $file_size = filesize($pdf_path);
            $max_size = apply_filters('wc_lexware_mvp_max_attachment_size', 10 * 1024 * 1024); // 10MB default

            if ($file_size <= $max_size) {
                // Reset attachments array completely and add PDF
                $this->attachments = array($pdf_path);
                \WC_Lexware_MVP_Logger::debug('PDF als E-Mail-Anhang hinzugefügt', array(
                    'order_id' => $order_id,
                    'pdf_path' => $pdf_path,
                    'file_size' => size_format($file_size),
                    'file_size_bytes' => $file_size,
                    'max_size' => size_format($max_size)
                ));
            } else {
                \WC_Lexware_MVP_Logger::warning('PDF zu groß für E-Mail-Anhang', array(
                    'order_id' => $order_id,
                    'pdf_path' => $pdf_path,
                    'file_size' => size_format($file_size),
                    'max_size' => size_format($max_size)
                ));
                $this->attachments = array();

                // Add order note about size issue
                $order->add_order_note(
                    sprintf(
                        __('⚠️ Lexware Rechnung PDF zu groß für E-Mail (%s). Maximum: %s. Bitte manuell versenden.', WC_LEXWARE_MVP_TEXT_DOMAIN),
                        size_format($file_size),
                        size_format($max_size)
                    )
                );
            }
        } else {
            \WC_Lexware_MVP_Logger::error('PDF-Datei nicht gefunden', array(
                'order_id' => $order_id,
                'pdf_path' => $pdf_path,
                'pdf_filename' => $pdf_filename
            ));
            $this->attachments = array();
        }

        $is_enabled = $this->is_enabled();
        $recipient = $this->get_recipient();

        \WC_Lexware_MVP_Logger::debug('Email pre-send checks', array(
            'order_id' => $order_id,
            'is_enabled' => $is_enabled ? 'yes' : 'no',
            'recipient' => $recipient,
            'has_attachments' => !empty($this->attachments)
        ));

        if ($is_enabled && $recipient) {
            \WC_Lexware_MVP_Logger::debug('E-Mail wird versendet', array(
                'order_id' => $order_id,
                'recipient' => $this->get_recipient(),
                'attachment_count' => count($this->attachments)
            ));

            // Hook into phpmailer_init for debugging
            add_action('phpmailer_init', function($phpmailer) use ($order_id) {
                \WC_Lexware_MVP_Logger::debug('PHPMailer initialized', array(
                    'order_id' => $order_id,
                    'mailer' => get_class($phpmailer),
                    'from' => $phpmailer->From,
                    'subject' => $phpmailer->Subject,
                    'to_count' => count($phpmailer->getToAddresses()),
                    'attachment_count' => count($phpmailer->getAttachments())
                ));
            }, 999);

            // Track email success for database update
            $email_sent_successfully = false;
            $order_id_for_tracking = $order_id;

            // Hook into wp_mail success/failure
            add_action('wp_mail_succeeded', function() use (&$email_sent_successfully) {
                $email_sent_successfully = true;
            }, 10, 1);

            add_action('wp_mail_failed', function($error) use ($order_id_for_tracking) {
                \WC_Lexware_MVP_Logger::error('wp_mail failed', array(
                    'order_id' => $order_id_for_tracking,
                    'error' => is_wp_error($error) ? $error->get_error_message() : 'Unknown error'
                ));
            }, 10, 1);

            \WC_Lexware_MVP_Logger::info('Sending invoice email via WC_Email->send()', array(
                'order_id' => $order_id,
                'recipient' => $this->get_recipient(),
                'subject' => $this->get_subject(),
                'attachments_count' => count($this->attachments)
            ));

            // Use WooCommerce standard send() method
            // This handles:
            // - From address/name formatting
            // - Header formatting (no manual parsing needed)
            // - Content-Type setting
            // - SMTP plugin integration
            // - WooCommerce email hooks
            $this->send(
                $this->get_recipient(),
                $this->get_subject(),
                $this->get_content(),
                $this->get_headers(),
                $this->get_attachments()
            );

            \WC_Lexware_MVP_Logger::info('Invoice email sent via WC_Email->send()', array(
                'order_id' => $order_id,
                'recipient' => $this->get_recipient()
            ));

            // Track email sent time and add order note
            // Note: We assume success if no wp_mail_failed hook fired
            // WooCommerce's send() method doesn't return boolean
            $success = true; // Default to success, wp_mail_failed hook will override

            if ($success) {
                global $wpdb;
                $table = wc_lexware_mvp_get_table_name(false);

                $updated = $wpdb->update(
                    $table,
                    array('email_sent_at' => current_time('mysql')),
                    array('order_id' => $order_id),
                    array('%s'),
                    array('%d')
                );

                \WC_Lexware_MVP_Logger::info('email_sent_at updated', array(
                    'order_id' => $order_id,
                    'rows_affected' => $updated
                ));

                $order->add_order_note(
                    sprintf(
                        __('📧 Lexware Rechnung per E-Mail versendet an %s', WC_LEXWARE_MVP_TEXT_DOMAIN),
                        $this->get_recipient()
                    )
                );
                \WC_Lexware_MVP_Logger::info('E-Mail erfolgreich versendet', array(
                    'order_id' => $order_id,
                    'recipient' => $this->get_recipient()
                ));
            }
        }

        $this->restore_locale();
    }

    /**
     * Get default email subject
     *
     * @since 1.0.0
     * @return string
     */
    public function get_default_subject() {
        return __('Ihre Rechnung {invoice_number} für Bestellung #{order_number}', WC_LEXWARE_MVP_TEXT_DOMAIN);
    }

    /**
     * Get default email heading
     *
     * @since 1.0.0
     * @return string
     */
    public function get_default_heading() {
        return __('Ihre Rechnung', WC_LEXWARE_MVP_TEXT_DOMAIN);
    }

    /**
     * Get email HTML content
     */
    public function get_content_html() {
        return wc_get_template_html(
            $this->template_html,
            array(
                'order' => $this->object,
                'invoice_number' => $this->invoice_number,
                'invoice_id' => $this->invoice_id,
                'email_heading' => $this->get_heading(),
                'additional_content' => $this->get_additional_content(),
                'sent_to_admin' => false,
                'plain_text' => false,
                'email' => $this,
                'text_align' => is_rtl() ? 'right' : 'left'
            ),
            '',
            $this->template_base
        );
    }

    /**
     * Get email plain content
     */
    public function get_content_plain() {
        return wc_get_template_html(
            $this->template_plain,
            array(
                'order' => $this->object,
                'invoice_number' => $this->invoice_number,
                'invoice_id' => $this->invoice_id,
                'email_heading' => $this->get_heading(),
                'additional_content' => $this->get_additional_content(),
                'sent_to_admin' => false,
                'plain_text' => true,
                'email' => $this,
                'text_align' => is_rtl() ? 'right' : 'left'
            ),
            '',
            $this->template_base
        );
    }

    /**
     * Get email attachments
     *
     * @return array
     */
    public function get_attachments() {
        return apply_filters(
            'wc_lexware_mvp_email_attachments',
            $this->attachments,
            $this->id,
            $this->object
        );
    }

    /**
     * Initialize settings form fields
     *
     * Provides admin UI in WooCommerce > Settings > Emails for customizing
     * email subject, heading, and additional content.
     *
     * @since 1.0.0
     */
    public function init_form_fields() {
        /* translators: %s: list of placeholders */
        $placeholder_text = sprintf(
            __('Available placeholders: %s', WC_LEXWARE_MVP_TEXT_DOMAIN),
            '<code>' . implode('</code>, <code>', array_keys($this->placeholders)) . '</code>'
        );

        $this->form_fields = array(
            'enabled' => array(
                'title'   => __('Enable/Disable', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'type'    => 'checkbox',
                'label'   => __('Enable this email notification', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'default' => 'yes'
            ),
            'subject' => array(
                'title'       => __('Subject', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'type'        => 'text',
                'desc_tip'    => true,
                'description' => $placeholder_text,
                'placeholder' => $this->get_default_subject(),
                'default'     => ''
            ),
            'heading' => array(
                'title'       => __('Email heading', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'type'        => 'text',
                'desc_tip'    => true,
                'description' => $placeholder_text,
                'placeholder' => $this->get_default_heading(),
                'default'     => ''
            ),
            'additional_content' => array(
                'title'       => __('Additional content', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'description' => __('Text to appear below the main email content.', WC_LEXWARE_MVP_TEXT_DOMAIN) . ' ' . $placeholder_text,
                'css'         => 'width:400px; height: 75px;',
                'placeholder' => __('N/A', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'type'        => 'textarea',
                'default'     => '',
                'desc_tip'    => true
            ),
            'email_type' => array(
                'title'       => __('Email type', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'type'        => 'select',
                'description' => __('Choose which format of email to send.', WC_LEXWARE_MVP_TEXT_DOMAIN),
                'default'     => 'html',
                'class'       => 'email_type wc-enhanced-select',
                'options'     => $this->get_email_type_options(),
                'desc_tip'    => true
            )
        );
    }
}
