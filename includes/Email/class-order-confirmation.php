<?php
/**
 * Email Handler - Order Confirmation Email with PDF Attachment
 *
 * Extends WooCommerce email system to send order confirmations created in Lexware.
 * Automatically triggered when order confirmation PDF is ready.
 *
 * @package WC_Lexware_MVP
 * @since 1.3.0
 */

namespace WC_Lexware_MVP\Email;

class Order_Confirmation extends \WC_Email {

    /**
     * PDF Attachment paths
     *
     * @since 1.3.0
     * @var array
     */
    public $attachments = array();

    /**
     * Order Confirmation number from Lexware (e.g., AB0047)
     *
     * @since 1.3.0
     * @var string
     */
    public $order_confirmation_number = '';

    /**
     * Lexware Order Confirmation ID (UUID)
     *
     * @since 1.3.0
     * @var string
     */
    public $order_confirmation_id = '';

    /**
     * Constructor
     *
     * Configures email ID, title, templates, and registers trigger hook.
     *
     * @since 1.3.0
     */
    public function __construct() {
        $this->id = 'lexware_mvp_order_confirmation';
        $this->title = __('Lexware Auftragsbestätigung', WC_LEXWARE_MVP_TEXT_DOMAIN);
        $this->description = __('Email mit Lexware-Auftragsbestätigung als PDF-Anhang', WC_LEXWARE_MVP_TEXT_DOMAIN);

        $this->template_html = 'emails/lexware-order-confirmation.php';
        $this->template_plain = 'emails/plain/lexware-order-confirmation.php';
        $this->template_base = plugin_dir_path(dirname(dirname(__FILE__))) . 'templates/';

        $this->customer_email = true;

        // Triggers
        add_action('wc_lexware_mvp_order_confirmation_ready', array($this, 'trigger'), 10, 3);

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
     * @since 1.3.0
     * @return string
     */
    public function get_email_type_option_group() {
        return 'lexware';
    }

    /**
     * Trigger email
     *
     * Called via 'wc_lexware_mvp_order_confirmation_ready' action when PDF is created.
     * Attaches PDF and sends email to customer.
     *
     * @since 1.3.0
     * @param int    $order_id        Order ID
     * @param int    $oc_record_id    Order Confirmation database record ID
     * @param string $pdf_filename    PDF filename to attach
     */
    public function trigger($order_id, $oc_record_id, $pdf_filename) {
        \WC_Lexware_MVP_Logger::info('Order Confirmation EMAIL TRIGGER CALLED', array(
            'order_id' => $order_id,
            'oc_record_id' => $oc_record_id,
            'pdf_filename' => $pdf_filename
        ));

        $this->setup_locale();

        $order = wc_get_order($order_id);

        if (!$order) {
            \WC_Lexware_MVP_Logger::error('Order not found in order confirmation email trigger', array('order_id' => $order_id));
            $this->restore_locale();
            return;
        }

        $this->object = $order;
        $this->recipient = $order->get_billing_email();

        // Load order confirmation number and ID from order meta
        $this->order_confirmation_number = $order->get_meta('_lexware_order_confirmation_number', true);
        $this->order_confirmation_id = $order->get_meta('_lexware_order_confirmation_id', true);

        // Set placeholders for subject/heading
        $this->placeholders = array(
            '{order_number}' => $order->get_order_number(),
            '{order_date}' => wc_format_datetime($order->get_date_created()),
            '{order_confirmation_number}' => $this->order_confirmation_number,
            '{site_title}' => $this->get_blogname()
        );

        // PDF-Anhang hinzufügen - Use PDF Handler for consistent path
        $pdf_path = \WC_Lexware_MVP_PDF_Handler::get_pdf_path($pdf_filename);

        if (\WC_Lexware_MVP_PDF_Handler::pdf_exists($pdf_filename)) {
            // Check file size before attaching
            $file_size = filesize($pdf_path);
            $max_size = apply_filters('wc_lexware_mvp_max_attachment_size', 10 * 1024 * 1024); // 10MB default

            if ($file_size <= $max_size) {
                $this->attachments = array($pdf_path);
                \WC_Lexware_MVP_Logger::debug('Order Confirmation PDF als E-Mail-Anhang hinzugefügt', array(
                    'order_id' => $order_id,
                    'pdf_path' => $pdf_path,
                    'file_size' => size_format($file_size)
                ));
            } else {
                \WC_Lexware_MVP_Logger::warning('Order Confirmation PDF zu groß für E-Mail-Anhang', array(
                    'order_id' => $order_id,
                    'file_size' => size_format($file_size),
                    'max_size' => size_format($max_size)
                ));
                $this->attachments = array();

                $order->add_order_note(
                    sprintf(
                        __('Lexware Auftragsbestätigung PDF zu groß für E-Mail (%s). Maximum: %s. Bitte manuell versenden.', WC_LEXWARE_MVP_TEXT_DOMAIN),
                        size_format($file_size),
                        size_format($max_size)
                    )
                );
            }
        } else {
            \WC_Lexware_MVP_Logger::error('Order Confirmation PDF-Datei nicht gefunden', array(
                'order_id' => $order_id,
                'pdf_path' => $pdf_path
            ));
            $this->attachments = array();
        }

        if ($this->is_enabled() && $this->get_recipient()) {
            \WC_Lexware_MVP_Logger::debug('Order Confirmation E-Mail wird versendet', array(
                'order_id' => $order_id,
                'recipient' => $this->get_recipient(),
                'attachment_count' => count($this->attachments)
            ));

            $this->send(
                $this->get_recipient(),
                $this->get_subject(),
                $this->get_content(),
                $this->get_headers(),
                $this->get_attachments()
            );

            // Update email_sent_at in database
            global $wpdb;
            $table = wc_lexware_mvp_get_table_name(false);

            $wpdb->update(
                $table,
                array('email_sent_at' => current_time('mysql')),
                array('id' => $oc_record_id),
                array('%s'),
                array('%d')
            );

            $order->add_order_note(
                sprintf(
                    __('Lexware Auftragsbestätigung per E-Mail versendet an %s', WC_LEXWARE_MVP_TEXT_DOMAIN),
                    $this->get_recipient()
                )
            );

            \WC_Lexware_MVP_Logger::info('Order Confirmation E-Mail erfolgreich versendet', array(
                'order_id' => $order_id,
                'recipient' => $this->get_recipient()
            ));
        }

        $this->restore_locale();
    }

    /**
     * Get default email subject
     *
     * @since 1.3.0
     * @return string
     */
    public function get_default_subject() {
        return __('Ihre Auftragsbestätigung {order_confirmation_number} für Bestellung #{order_number}', WC_LEXWARE_MVP_TEXT_DOMAIN);
    }

    /**
     * Get default email heading
     *
     * @since 1.3.0
     * @return string
     */
    public function get_default_heading() {
        return __('Ihre Auftragsbestätigung', WC_LEXWARE_MVP_TEXT_DOMAIN);
    }

    /**
     * Get email HTML content
     */
    public function get_content_html() {
        return wc_get_template_html(
            $this->template_html,
            array(
                'order' => $this->object,
                'order_confirmation_number' => $this->order_confirmation_number,
                'order_confirmation_id' => $this->order_confirmation_id,
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
                'order_confirmation_number' => $this->order_confirmation_number,
                'order_confirmation_id' => $this->order_confirmation_id,
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
     * @since 1.3.0
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
