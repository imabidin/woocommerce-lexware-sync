<?php
/**
 * Lexware Invoice Email (Plain Text)
 *
 * @var WC_Order $order
 * @var string $email_heading
 * @var string $additional_content
 * @var string $invoice_number Invoice number from Lexware
 * @var string $invoice_id Lexware invoice UUID
 * @var WC_Lexware_MVP_Email_Invoice $email
 */

defined('ABSPATH') || exit;

echo "= " . esc_html($email_heading) . " =\n\n";

/* translators: %s: Customer first name */
echo sprintf(esc_html__('Hallo %s,', WC_LEXWARE_MVP_TEXT_DOMAIN), esc_html($order->get_billing_first_name())) . "\n\n";

echo esc_html__('vielen Dank für Ihre Bestellung. Ihre Rechnung wurde erstellt.', WC_LEXWARE_MVP_TEXT_DOMAIN) . "\n\n";

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

echo esc_html__('RECHNUNGSDETAILS', WC_LEXWARE_MVP_TEXT_DOMAIN) . "\n\n";

echo esc_html__('Rechnungsnummer:', WC_LEXWARE_MVP_TEXT_DOMAIN) . ' ' . esc_html($invoice_number) . "\n";
echo esc_html__('Bestellnummer:', WC_LEXWARE_MVP_TEXT_DOMAIN) . ' ' . esc_html($order->get_order_number()) . "\n";
echo esc_html__('Bestelldatum:', WC_LEXWARE_MVP_TEXT_DOMAIN) . ' ' . esc_html(wc_format_datetime($order->get_date_created())) . "\n";
echo esc_html__('Gesamtbetrag:', WC_LEXWARE_MVP_TEXT_DOMAIN) . ' ' . wp_strip_all_tags($order->get_formatted_order_total()) . "\n\n";

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

do_action('woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email);

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

do_action('woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email);
do_action('woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email);

echo "\n\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

if ($additional_content) {
    echo esc_html(wp_strip_all_tags(wptexturize($additional_content)));
    echo "\n\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
}

// Use WooCommerce standard footer filter for consistency
echo wp_kses_post(apply_filters('woocommerce_email_footer_text', get_option('woocommerce_email_footer_text')));
