/**
 * Lexware MVP Admin Meta Box JavaScript
 *
 * Handles AJAX interactions for Lexware document actions on order edit screen.
 *
 * @package WC_Lexware_MVP
 * @since 1.0.1
 */

(function ($) {
    'use strict';

    /**
     * Initialize meta box actions
     */
    $(document).ready(function () {

        /**
         * Handle PDF Download
         */
        $(document).on('click', '.lexware-download-pdf', function (e) {
            e.preventDefault();

            var $button = $(this);
            var $spinner = $button.siblings('.lexware-spinner');
            var orderId = $button.data('order-id');
            var documentId = $button.data('document-id');
            var documentType = $button.data('document-type');

            $button.prop('disabled', true);
            $spinner.show();

            $.ajax({
                url: lexware_mvp_meta_box.ajax_url,
                type: 'POST',
                data: {
                    action: 'lexware_mvp_download_pdf',
                    order_id: orderId,
                    document_id: documentId,
                    document_type: documentType,
                    nonce: lexware_mvp_meta_box.nonce
                },
                xhrFields: {
                    responseType: 'blob'
                },
                success: function (data, textStatus, xhr) {
                    var contentType = xhr.getResponseHeader('Content-Type');

                    // Check if response is JSON (error message)
                    if (contentType && contentType.indexOf('application/json') !== -1) {
                        // Convert blob to JSON
                        var reader = new FileReader();
                        reader.onload = function () {
                            try {
                                var json = JSON.parse(reader.result);
                                if (json.success === false) {
                                    alert('Fehler: ' + json.data);
                                }
                            } catch (e) {
                                alert('Ein unerwarteter Fehler ist aufgetreten');
                            }
                        };
                        reader.readAsText(data);
                    } else {
                        // Success - trigger file download
                        var filename = xhr.getResponseHeader('Content-Disposition');
                        if (filename) {
                            var filenameMatch = filename.match(/filename="(.+)"/);
                            if (filenameMatch) {
                                filename = filenameMatch[1];
                            }
                        } else {
                            filename = 'lexware-document-' + orderId + '.pdf';
                        }

                        // Create download link
                        var blob = new Blob([data], { type: 'application/pdf' });
                        var link = document.createElement('a');
                        link.href = window.URL.createObjectURL(blob);
                        link.download = filename;
                        document.body.appendChild(link);
                        link.click();
                        document.body.removeChild(link);
                        window.URL.revokeObjectURL(link.href);
                    }
                },
                error: function (xhr) {
                    var errorMessage = 'PDF-Download fehlgeschlagen';

                    if (xhr.responseJSON && xhr.responseJSON.data) {
                        errorMessage = xhr.responseJSON.data;
                    }

                    alert('Fehler: ' + errorMessage);
                },
                complete: function () {
                    $button.prop('disabled', false);
                    $spinner.hide();
                }
            });
        });

        /**
         * Handle Email Resend
         */
        $(document).on('click', '.lexware-resend-email', function (e) {
            e.preventDefault();

            var $button = $(this);
            var orderId = $button.data('order-id');
            var documentId = $button.data('document-id');
            var documentType = $button.data('document-type');

            console.log('📧 Lexware Email Resend Button geklickt', {
                order_id: orderId,
                document_id: documentId,
                document_type: documentType,
                timestamp: new Date().toISOString()
            });

            if (!confirm('Möchten Sie die E-Mail wirklich erneut versenden?')) {
                console.log('⚠️ Email-Versand vom Admin abgebrochen');
                return;
            }

            console.log('✓ Bestätigung erhalten, starte AJAX-Request...');

            $button.prop('disabled', true);
            $button.text('Wird gesendet...');

            var startTime = Date.now();

            $.ajax({
                url: lexware_mvp_meta_box.ajax_url,
                type: 'POST',
                data: {
                    action: 'lexware_mvp_resend_email',
                    order_id: orderId,
                    document_id: documentId,
                    document_type: documentType,
                    nonce: lexware_mvp_meta_box.nonce
                },
                beforeSend: function() {
                    console.log('🚀 AJAX-Request wird gesendet...', {
                        endpoint: lexware_mvp_meta_box.ajax_url,
                        action: 'lexware_mvp_resend_email',
                        order_id: orderId,
                        document_id: documentId,
                        document_type: documentType
                    });
                },
                success: function (response) {
                    var duration = Date.now() - startTime;

                    console.log('📨 Server-Antwort erhalten (Dauer: ' + duration + 'ms)', response);

                    if (response.success) {
                        console.log('✅ Email erfolgreich versendet!', {
                            message: response.data,
                            order_id: orderId,
                            document_type: documentType,
                            duration_ms: duration
                        });

                        alert('✅ ' + response.data);
                        $button.text('E-Mail versendet!');

                        setTimeout(function() {
                            $button.text('E-Mail erneut senden');
                            $button.prop('disabled', false);
                            console.log('↻ Button zurückgesetzt, bereit für nächsten Versand');
                        }, 3000);
                    } else {
                        console.error('❌ Email-Versand fehlgeschlagen', {
                            error_message: response.data,
                            order_id: orderId,
                            document_id: documentId,
                            full_response: response
                        });

                        alert('❌ Fehler: ' + response.data);
                        $button.text('E-Mail erneut senden');
                        $button.prop('disabled', false);
                    }
                },
                error: function (xhr, status, error) {
                    var duration = Date.now() - startTime;
                    var errorMessage = 'E-Mail konnte nicht versendet werden';

                    if (xhr.responseJSON && xhr.responseJSON.data) {
                        errorMessage = xhr.responseJSON.data;
                    }

                    console.error('❌ AJAX-Request fehlgeschlagen', {
                        status: status,
                        error: error,
                        status_code: xhr.status,
                        error_message: errorMessage,
                        response: xhr.responseJSON || xhr.responseText,
                        duration_ms: duration
                    });

                    alert('❌ Fehler: ' + errorMessage);
                    $button.text('E-Mail erneut senden');
                    $button.prop('disabled', false);
                },
                complete: function() {
                    var duration = Date.now() - startTime;
                    console.log('🏁 Email-Resend-Vorgang abgeschlossen (Gesamt-Dauer: ' + duration + 'ms)');
                }
            });
        });

        /**
         * Handle Manual Retry
         */
        $(document).on('click', '.lexware-manual-retry', function (e) {
            e.preventDefault();

            var $button = $(this);
            var $spinner = $button.siblings('.lexware-spinner');
            var orderId = $button.data('order-id');
            var documentId = $button.data('document-id');
            var documentType = $button.data('document-type');

            if (!confirm('Möchten Sie das Dokument wirklich erneut versuchen?')) {
                return;
            }

            $button.prop('disabled', true);
            $spinner.show();

            $.ajax({
                url: lexware_mvp_meta_box.ajax_url,
                type: 'POST',
                data: {
                    action: 'lexware_mvp_manual_retry',
                    order_id: orderId,
                    document_id: documentId,
                    document_type: documentType,
                    nonce: lexware_mvp_meta_box.nonce
                },
                success: function (response) {
                    if (response.success) {
                        alert('✅ ' + response.data);
                        // Reload page to show updated status
                        location.reload();
                    } else {
                        alert('❌ Fehler: ' + response.data);
                        $button.prop('disabled', false);
                        $spinner.hide();
                    }
                },
                error: function (xhr) {
                    var errorMessage = 'Retry fehlgeschlagen';

                    if (xhr.responseJSON && xhr.responseJSON.data) {
                        errorMessage = xhr.responseJSON.data;
                    }

                    alert('❌ Fehler: ' + errorMessage);
                    $button.prop('disabled', false);
                    $spinner.hide();
                }
            });
        });

        /**
         * Handle PDF Retry/Download
         */
        $(document).on('click', '.lexware-retry-pdf', function (e) {
            e.preventDefault();

            var $button = $(this);
            var $spinner = $button.siblings('.lexware-spinner');
            var orderId = $button.data('order-id');
            var documentId = $button.data('document-id');
            var documentType = $button.data('document-type');

            $button.prop('disabled', true);
            $spinner.show();
            $button.text('📥 Lade PDF...');

            $.ajax({
                url: lexware_mvp_meta_box.ajax_url,
                type: 'POST',
                data: {
                    action: 'lexware_mvp_retry_pdf_download',
                    order_id: orderId,
                    document_id: documentId,
                    document_type: documentType,
                    nonce: lexware_mvp_meta_box.nonce
                },
                success: function (response) {
                    if (response.success) {
                        alert('✅ ' + response.data);
                        // Reload page to show updated status
                        location.reload();
                    } else {
                        alert('❌ Fehler: ' + response.data);
                        $button.text('📥 PDF nachladen');
                        $button.prop('disabled', false);
                        $spinner.hide();
                    }
                },
                error: function (xhr) {
                    var errorMessage = 'PDF-Download fehlgeschlagen';

                    if (xhr.responseJSON && xhr.responseJSON.data) {
                        errorMessage = xhr.responseJSON.data;
                    }

                    alert('❌ Fehler: ' + errorMessage);
                    $button.text('📥 PDF nachladen');
                    $button.prop('disabled', false);
                    $spinner.hide();
                }
            });
        });

        /**
         * Handle Manual Invoice Creation
         */
        $(document).on('click', '.lexware-create-invoice-manually', function (e) {
            e.preventDefault();

            console.log('📝 Manual Invoice Creation clicked');

            var $button = $(this);
            var $spinner = $button.siblings('.lexware-spinner');
            var orderId = $button.data('order-id');

            if (!confirm('Möchten Sie jetzt eine Rechnung für diese Bestellung erstellen?')) {
                console.log('📝 Manual Invoice Creation cancelled by user');
                return;
            }

            console.log('📝 Manual Invoice Creation params:', { orderId });

            $button.prop('disabled', true);
            $spinner.show();

            console.log('🚀 Sending Manual Invoice Creation AJAX request...');

            $.ajax({
                url: lexware_mvp_meta_box.ajax_url,
                type: 'POST',
                data: {
                    action: 'lexware_mvp_create_invoice_manually',
                    order_id: orderId,
                    nonce: lexware_mvp_meta_box.nonce
                },
                success: function (response) {
                    console.log('✅ Manual Invoice Creation AJAX success:', response);

                    if (response.success) {
                        console.log('✅ SUCCESS - Rechnung wird erstellt!');
                        console.log('📊 Action Scheduler Status: Rechnung in Warteschlange');
                        console.log('⏰ Verarbeitung dauert normalerweise 5-15 Sekunden');
                        console.log('🔄 Seite in 5 Sekunden neu laden oder manuell F5 drücken');

                        alert(response.data + '\n\nSeite wird automatisch in 5 Sekunden neu geladen...');

                        // Auto-reload after 5 seconds to show progress
                        setTimeout(function () {
                            console.log('🔄 Auto-Reload nach 5 Sekunden...');
                            location.reload();
                        }, 5000);
                    } else {
                        console.error('❌ Manual Invoice Creation failed:', response.data);
                        alert('❌ Fehler: ' + response.data);
                        $button.prop('disabled', false);
                        $spinner.hide();
                    }
                },
                error: function (xhr, status, error) {
                    console.error('❌ Manual Invoice Creation AJAX error:', { xhr, status, error });

                    var errorMessage = 'Fehler beim Erstellen der Rechnung';
                    if (xhr.responseJSON && xhr.responseJSON.data) {
                        errorMessage = xhr.responseJSON.data;
                    }

                    alert('❌ ' + errorMessage);
                    $button.prop('disabled', false);
                    $spinner.hide();
                }
            });
        });

        /**
         * Handle Manual Order Confirmation Creation
         */
        $(document).on('click', '.lexware-create-order-confirmation-manually', function (e) {
            e.preventDefault();

            console.log('📋 Manual Order Confirmation Creation clicked');

            var $button = $(this);
            var $spinner = $button.siblings('.lexware-spinner-oc');
            var orderId = $button.data('order-id');

            if (!confirm('Möchten Sie jetzt eine Auftragsbestätigung für diese Bestellung erstellen?')) {
                console.log('📋 Manual Order Confirmation Creation cancelled by user');
                return;
            }

            console.log('📋 Manual Order Confirmation Creation params:', { orderId });

            $button.prop('disabled', true);
            $spinner.show();

            console.log('🚀 Sending Manual Order Confirmation Creation AJAX request...');

            $.ajax({
                url: lexware_mvp_meta_box.ajax_url,
                type: 'POST',
                data: {
                    action: 'lexware_mvp_create_order_confirmation_manually',
                    order_id: orderId,
                    nonce: lexware_mvp_meta_box.nonce
                },
                success: function (response) {
                    console.log('✅ Manual Order Confirmation Creation AJAX success:', response);

                    if (response.success) {
                        console.log('✅ SUCCESS - Auftragsbestätigung wird erstellt!');
                        console.log('📊 Action Scheduler Status: Auftragsbestätigung in Warteschlange');
                        console.log('⏰ Verarbeitung dauert normalerweise 5-15 Sekunden');
                        console.log('🔄 Seite in 5 Sekunden neu laden oder manuell F5 drücken');

                        alert(response.data + '\n\nSeite wird automatisch in 5 Sekunden neu geladen...');

                        // Auto-reload after 5 seconds to show progress
                        setTimeout(function () {
                            console.log('🔄 Auto-Reload nach 5 Sekunden...');
                            location.reload();
                        }, 5000);
                    } else {
                        console.error('❌ Manual Order Confirmation Creation failed:', response.data);
                        alert('❌ Fehler: ' + response.data);
                        $button.prop('disabled', false);
                        $spinner.hide();
                    }
                },
                error: function (xhr, status, error) {
                    console.error('❌ Manual Order Confirmation Creation AJAX error:', { xhr, status, error });

                    var errorMessage = 'Fehler beim Erstellen der Auftragsbestätigung';
                    if (xhr.responseJSON && xhr.responseJSON.data) {
                        errorMessage = xhr.responseJSON.data;
                    }

                    alert('❌ ' + errorMessage);
                    $button.prop('disabled', false);
                    $spinner.hide();
                }
            });
        });

        /**
         * Handle Delete External Document Marking
         */
        $(document).on('click', '.lexware-delete-external', function (e) {
            e.preventDefault();

            var $button = $(this);
            var $spinner = $button.siblings('.lexware-spinner');
            var orderId = $button.data('order-id');
            var documentId = $button.data('document-id');
            var documentType = $button.data('document-type');

            if (!confirm('Möchten Sie die externe Markierung wirklich entfernen?\n\nDie Bestellung erscheint dann wieder als "fehlend" und Sie können ein neues Dokument erstellen.')) {
                return;
            }

            $button.prop('disabled', true);
            $spinner.show();

            $.ajax({
                url: lexware_mvp_meta_box.ajax_url,
                type: 'POST',
                data: {
                    action: 'lexware_mvp_delete_external_document',
                    order_id: orderId,
                    document_id: documentId,
                    document_type: documentType,
                    nonce: lexware_mvp_meta_box.nonce
                },
                success: function (response) {
                    if (response.success) {
                        alert('✅ ' + response.data);
                        location.reload();
                    } else {
                        alert('❌ Fehler: ' + response.data);
                        $button.prop('disabled', false);
                        $spinner.hide();
                    }
                },
                error: function (xhr) {
                    var errorMessage = 'Entfernen fehlgeschlagen';

                    if (xhr.responseJSON && xhr.responseJSON.data) {
                        errorMessage = xhr.responseJSON.data;
                    }

                    alert('❌ Fehler: ' + errorMessage);
                    $button.prop('disabled', false);
                    $spinner.hide();
                }
            });
        });

    });

})(jQuery);
