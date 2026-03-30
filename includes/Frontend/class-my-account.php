<?php
/**
 * My Account Integration - Invoice/Credit Note Display
 *
 * Adds "Invoices" tab to WooCommerce My Account area.
 * Displays invoice and credit note download buttons.
 *
 * @package WC_Lexware_MVP
 * @since 1.0.0
 */

namespace WC_Lexware_MVP\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class My_Account {

	/**
	 * Constructor
	 */
	public function __construct() {
		// Füge Rechnung-Button zu Order Actions hinzu
		add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'add_invoice_action' ), 10, 2 );

		// Zeige Rechnung auf Order Details Seite (vor Germanized Sendungen bei Priority 10)
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'show_invoice_on_order_details' ), 5, 1 );

		// Handle PDF Download
		add_action( 'template_redirect', array( $this, 'handle_pdf_download' ) );
	}

	/**
	 * Füge Rechnung-Button zu My Account Order Actions hinzu
	 *
	 * @param array $actions Existing actions
	 * @param WC_Order $order Order object
	 * @return array Modified actions
	 */
	public function add_invoice_action( $actions, $order ) {
		global $wpdb;

		$table_name = wc_lexware_mvp_get_table_name();

		// Prüfe ob Rechnung existiert
		$invoice = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table_name}
			WHERE order_id = %d
			AND document_type = 'invoice'
			AND document_status = 'synced'
			LIMIT 1",
			$order->get_id()
		) );

		if ( $invoice ) {
			// Prüfe ob PDF existiert
			$pdf_filename = $invoice->document_nr . '_' . $invoice->order_id . '.pdf';
			if ( \WC_Lexware_MVP_PDF_Handler::pdf_exists( $pdf_filename ) ) {
				// Füge Rechnung-Button hinzu
				$actions['lexware_invoice'] = array(
					'url'  => $this->get_pdf_download_url( $invoice->id, $order->get_id() ),
					'name' => __( 'Rechnung', WC_LEXWARE_MVP_TEXT_DOMAIN ),
				);
			}
		}

		// Prüfe ob Gutschrift existiert
		$credit_note = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table_name}
			WHERE order_id = %d
			AND document_type = 'credit_note'
			AND document_status = 'synced'
			ORDER BY created_at DESC
			LIMIT 1",
			$order->get_id()
		) );

		if ( $credit_note ) {
			// Prüfe ob PDF existiert
			$pdf_filename = $credit_note->document_nr . '_' . $credit_note->order_id . '.pdf';
			if ( \WC_Lexware_MVP_PDF_Handler::pdf_exists( $pdf_filename ) ) {
				// Füge Gutschrift-Button hinzu
				$actions['lexware_credit_note'] = array(
					'url'  => $this->get_pdf_download_url( $credit_note->id, $order->get_id() ),
					'name' => __( 'Gutschrift', WC_LEXWARE_MVP_TEXT_DOMAIN ),
				);
			}
		}

		return $actions;
	}

	/**
	 * Zeige Rechnung auf Order Details Seite
	 *
	 * @param WC_Order $order Order object
	 */
	public function show_invoice_on_order_details( $order ) {
		// Prüfe ob User der Besitzer ist
		if ( ! is_user_logged_in() || $order->get_customer_id() !== get_current_user_id() ) {
		return;
	}

	global $wpdb;
	$table_name = wc_lexware_mvp_get_table_name();		// Hole alle Dokumente (Invoice und Credit Notes)
		$documents = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table_name}
			WHERE order_id = %d
			AND document_status = 'synced'
			ORDER BY
				CASE
					WHEN document_type = 'invoice' THEN 1
					WHEN document_type = 'credit_note' THEN 2
					ELSE 3
				END,
				created_at ASC",
			$order->get_id()
		) );

		if ( empty( $documents ) ) {
			return;
		}

		// Zeige Dokumente
		foreach ( $documents as $document ) {
			$is_credit_note = ( $document->document_type === 'credit_note' );
			$doc_label = $is_credit_note ? __( 'Gutschrift', WC_LEXWARE_MVP_TEXT_DOMAIN ) : __( 'Rechnung', WC_LEXWARE_MVP_TEXT_DOMAIN );
			$doc_label_nr = $is_credit_note ? __( 'Gutschrift-Nr:', WC_LEXWARE_MVP_TEXT_DOMAIN ) : __( 'Rechnungsnummer:', WC_LEXWARE_MVP_TEXT_DOMAIN );
			$doc_label_date = $is_credit_note ? __( 'Gutschriftdatum:', WC_LEXWARE_MVP_TEXT_DOMAIN ) : __( 'Rechnungsdatum:', WC_LEXWARE_MVP_TEXT_DOMAIN );
			$doc_label_download = $is_credit_note ? __( 'Gutschrift herunterladen', WC_LEXWARE_MVP_TEXT_DOMAIN ) : __( 'Rechnung herunterladen', WC_LEXWARE_MVP_TEXT_DOMAIN );

			// Prüfe ob PDF existiert
			$pdf_filename = $document->document_nr . '_' . $document->order_id . '.pdf';
			if ( ! \WC_Lexware_MVP_PDF_Handler::pdf_exists( $pdf_filename ) ) {
				continue;
			}

			?>
			<section class="woocommerce-order-invoice-details" style="margin-top: 20px;">
				<h2 class="woocommerce-order-invoice-details__title"><?php echo esc_html( $doc_label ); ?></h2>
				<table class="woocommerce-table woocommerce-table--invoice-details shop_table invoice_details">
					<tbody>
						<tr>
							<th><?php echo esc_html( $doc_label_nr ); ?></th>
							<td><?php echo esc_html( $document->document_nr ); ?></td>
						</tr>
						<tr>
							<th><?php echo esc_html( $doc_label_date ); ?></th>
							<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $document->created_at ) ) ); ?></td>
						</tr>
						<?php if ( $is_credit_note && $document->refund_amount ) : ?>
						<tr>
							<th><?php _e( 'Erstattungsbetrag:', WC_LEXWARE_MVP_TEXT_DOMAIN ); ?></th>
							<td><?php echo wc_price( abs( $document->refund_amount ) ); ?></td>
						</tr>
						<?php endif; ?>
						<?php if ( $document->email_sent_at ) : ?>
						<tr>
							<th><?php _e( 'E-Mail versendet:', WC_LEXWARE_MVP_TEXT_DOMAIN ); ?></th>
							<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $document->email_sent_at ) ) ); ?></td>
						</tr>
						<?php endif; ?>
						<tr>
							<th><?php _e( 'Download:', WC_LEXWARE_MVP_TEXT_DOMAIN ); ?></th>
							<td>
								<a href="<?php echo esc_url( $this->get_pdf_download_url( $document->id, $order->get_id() ) ); ?>" class="woocommerce-button button">
									<span class="dashicons dashicons-pdf" style="margin-top: 3px;"></span>
									<?php echo esc_html( $doc_label_download ); ?>
								</a>
							</td>
						</tr>
					</tbody>
				</table>
			</section>
			<?php
		}
	}

	/**
	 * Generiere PDF Download URL
	 *
	 * @param int $invoice_id Invoice ID
	 * @param int $order_id Order ID
	 * @return string Download URL
	 */
	private function get_pdf_download_url( $invoice_id, $order_id ) {
		return add_query_arg( array(
			'lexware_download_pdf' => $invoice_id,
			'order_id'             => $order_id,
			'_wpnonce'             => wp_create_nonce( 'lexware_download_pdf_' . $invoice_id ),
		), wc_get_page_permalink( 'myaccount' ) );
	}

	/**
	 * Handle PDF Download
	 */
	public function handle_pdf_download() {
		if ( ! isset( $_GET['lexware_download_pdf'] ) || ! isset( $_GET['_wpnonce'] ) ) {
			return;
		}

		$invoice_id = intval( $_GET['lexware_download_pdf'] );

		// Verify nonce
		if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'lexware_download_pdf_' . $invoice_id ) ) {
			wp_die( __( 'Sicherheitsprüfung fehlgeschlagen.', WC_LEXWARE_MVP_TEXT_DOMAIN ) );
		}

		// Prüfe ob User eingeloggt ist
		if ( ! is_user_logged_in() ) {
		wp_die( __( 'Sie müssen angemeldet sein, um Rechnungen herunterzuladen.', WC_LEXWARE_MVP_TEXT_DOMAIN ) );
	}

	global $wpdb;
	$table_name = wc_lexware_mvp_get_table_name();		$invoice = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE id = %d",
			$invoice_id
		) );

		if ( ! $invoice ) {
			wp_die( __( 'Rechnung nicht gefunden.', WC_LEXWARE_MVP_TEXT_DOMAIN ) );
		}

		// Prüfe ob User der Besitzer der Bestellung ist
		$order = wc_get_order( $invoice->order_id );
		if ( ! $order || $order->get_customer_id() !== get_current_user_id() ) {
			wp_die( __( 'Sie haben keine Berechtigung, diese Rechnung herunterzuladen.', WC_LEXWARE_MVP_TEXT_DOMAIN ) );
		}

		// Prüfe ob PDF existiert
		$pdf_filename = $invoice->document_nr . '_' . $invoice->order_id . '.pdf';
		$pdf_path = \WC_Lexware_MVP_PDF_Handler::get_pdf_path($pdf_filename);
		if ( ! \WC_Lexware_MVP_PDF_Handler::pdf_exists($pdf_filename) ) {
			wp_die( __( 'PDF-Datei nicht gefunden.', WC_LEXWARE_MVP_TEXT_DOMAIN ) );
		}

		// Sende PDF zum Download
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . basename( $pdf_filename ) . '"' );
		header( 'Content-Length: ' . filesize( $pdf_path ) );
		header( 'Cache-Control: private, max-age=0, must-revalidate' );
		header( 'Pragma: public' );

		// Use file lock to prevent reading while file is being written
		$fp = fopen( $pdf_path, 'rb' );
		if ( $fp ) {
			if ( flock( $fp, LOCK_SH ) ) { // Shared lock for reading
				fpassthru( $fp );
				flock( $fp, LOCK_UN ); // Release lock
			} else {
				fclose( $fp );
				wp_die( __( 'PDF-Datei ist gerade in Bearbeitung. Bitte versuchen Sie es in einigen Sekunden erneut.', WC_LEXWARE_MVP_TEXT_DOMAIN ) );
			}
			fclose( $fp );
		} else {
			wp_die( __( 'Fehler beim Öffnen der PDF-Datei.', WC_LEXWARE_MVP_TEXT_DOMAIN ) );
		}
		exit;
	}
}
