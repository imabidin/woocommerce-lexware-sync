<?php
/**
 * Lexware Invoice Email (HTML)
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/lexware-invoice.php
 *
 * @see https://woocommerce.com/document/template-structure/
 * @package WooCommerce_Lexware_MVP
 * @version 1.0.0
 *
 * @var WC_Order $order Order object
 * @var string $email_heading Email heading string
 * @var string $additional_content Additional content
 * @var string $invoice_number Invoice number from Lexware
 * @var string $invoice_id Lexware invoice UUID
 * @var string $text_align Text alignment (left/right for RTL)
 * @var WC_Lexware_MVP_Email_Invoice $email Email object
 * @var bool $sent_to_admin Sent to admin flag
 * @var bool $plain_text Plain text flag
 */

defined('ABSPATH') || exit;

/*
 * Email Header
 */
do_action('woocommerce_email_header', $email_heading, $email);
?>

<?php /* translators: %s: Customer first name */ ?>
<p><?php printf(esc_html__('Hallo %s,', WC_LEXWARE_MVP_TEXT_DOMAIN), esc_html($order->get_billing_first_name())); ?></p>

<p><?php esc_html_e('vielen Dank für Ihre Bestellung. Ihre Rechnung wurde erstellt.', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></p>

<h2><?php esc_html_e('Rechnungsdetails', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></h2>

<table class="td" cellspacing="0" cellpadding="6" style="width: 100%; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; margin-bottom: 20px;" border="1">
	<tr>
		<th class="td" scope="row" style="text-align:<?php echo esc_attr($text_align); ?>; padding: 12px;"><?php esc_html_e('Rechnungsnummer:', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></th>
		<td class="td" style="text-align:<?php echo esc_attr($text_align); ?>; padding: 12px;"><strong><?php echo esc_html($invoice_number); ?></strong></td>
	</tr>
	<tr>
		<th class="td" scope="row" style="text-align:<?php echo esc_attr($text_align); ?>; padding: 12px;"><?php esc_html_e('Bestellnummer:', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></th>
		<td class="td" style="text-align:<?php echo esc_attr($text_align); ?>; padding: 12px;"><?php echo esc_html($order->get_order_number()); ?></td>
	</tr>
	<tr>
		<th class="td" scope="row" style="text-align:<?php echo esc_attr($text_align); ?>; padding: 12px;"><?php esc_html_e('Bestelldatum:', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></th>
		<td class="td" style="text-align:<?php echo esc_attr($text_align); ?>; padding: 12px;"><?php echo esc_html(wc_format_datetime($order->get_date_created())); ?></td>
	</tr>
	<tr>
		<th class="td" scope="row" style="text-align:<?php echo esc_attr($text_align); ?>; padding: 12px;"><?php esc_html_e('Gesamtbetrag:', WC_LEXWARE_MVP_TEXT_DOMAIN); ?></th>
		<td class="td" style="text-align:<?php echo esc_attr($text_align); ?>; padding: 12px;"><strong><?php echo wp_kses_post($order->get_formatted_order_total()); ?></strong></td>
	</tr>
</table>

<?php
/*
 * Hook: woocommerce_email_before_order_table
 *
 * @hooked WC_Emails::order_downloads_table() - 10
 */
do_action('woocommerce_email_before_order_table', $order, $sent_to_admin, $plain_text, $email);

/*
 * Show order details table
 */
do_action('woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email);

/*
 * Hook: woocommerce_email_after_order_table
 */
do_action('woocommerce_email_after_order_table', $order, $sent_to_admin, $plain_text, $email);

/*
 * Show order meta (custom fields)
 */
do_action('woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email);

/*
 * Show customer details (billing and shipping addresses)
 */
do_action('woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email);
?>

<?php
/*
 * Show additional content
 */
if ($additional_content) {
    echo wp_kses_post(wpautop(wptexturize($additional_content)));
}

/*
 * Email Footer
 */
do_action('woocommerce_email_footer', $email);
