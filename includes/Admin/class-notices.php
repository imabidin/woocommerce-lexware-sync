<?php
/**
 * Admin Notices Class
 *
 * Manages all admin notifications including errors, warnings, and success messages
 *
 * @package WC_Lexware_MVP
 * @since 1.0.0
 */

namespace WC_Lexware_MVP\Admin;

use WC_Lexware_MVP\Core\Requirements_Checker;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Notices
 *
 * Centralized handler for all admin notices displayed in WordPress dashboard.
 *
 * @since 1.0.0
 */
class Notices {

    /**
     * Initialize admin notices
     *
     * @since 1.0.0
     */
    public static function init() {
        add_action('admin_notices', array(__CLASS__, 'display_notices'));
    }

    /**
     * Display all pending admin notices
     *
     * @since 1.0.0
     */
    public static function display_notices() {
        // Check for requirement errors
        self::display_requirement_errors();

        // Check for activation status
        self::display_activation_status();

        // Check for PDF directory issues
        self::display_pdf_directory_warnings();
    }

    /**
     * Display requirement errors if plugin dependencies not met
     *
     * @since 1.0.0
     */
    private static function display_requirement_errors() {
        if (!class_exists(Requirements_Checker::class)) {
            return;
        }

        $errors = Requirements_Checker::check();

        if (!empty($errors)) {
            ?>
            <div class="notice notice-error">
                <p><strong><?php _e('WooCommerce Lexware MVP', 'wc-lexware-mvp'); ?></strong></p>
                <ul style="list-style: disc; padding-left: 20px;">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo esc_html($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php
        }
    }

    /**
     * Display activation status notice (success or warnings)
     */
    private static function display_activation_status() {
        $status = get_option('wc_lexware_mvp_activation_status');

        if ($status === false) {
            return;
        }

        if ($status['success']) {
            self::display_success_notice($status);
        } else {
            self::display_warning_notice($status);
        }

        // Delete option after displaying once
        delete_option('wc_lexware_mvp_activation_status');
    }

    /**
     * Display successful activation notice
     *
     * @param array $status Activation status details
     */
    private static function display_success_notice($status) {
        ?>
        <div class="notice notice-success is-dismissible">
            <p><strong>✅ <?php _e('WooCommerce Lexware MVP erfolgreich aktiviert!', 'wc-lexware-mvp'); ?></strong></p>
            <ul style="margin: 10px 0 10px 20px; list-style: none;">
                <li>✓ <?php printf(__('PDF-Verzeichnis erstellt: <code>%s</code>', 'wc-lexware-mvp'), esc_html($status['directory_path'])); ?></li>
                <li>✓ <?php _e('.htaccess Schutz aktiviert (Direkter Zugriff blockiert)', 'wc-lexware-mvp'); ?></li>
                <li>✓ <?php _e('index.php Sicherheitsdatei erstellt', 'wc-lexware-mvp'); ?></li>
                <li>✓ <?php _e('Datenbank-Tabellen erstellt', 'wc-lexware-mvp'); ?></li>
            </ul>
            <p style="margin: 10px 0;">
                <em><?php _e('PDFs sind nur über authentifizierte WooCommerce-Downloads zugänglich.', 'wc-lexware-mvp'); ?></em><br>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=lexware_mvp')); ?>">
                    <?php _e('→ Jetzt API-Konfiguration vornehmen', 'wc-lexware-mvp'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Display activation warning notice (partial success)
     *
     * @param array $status Activation status details
     */
    private static function display_warning_notice($status) {
        ?>
        <div class="notice notice-error is-dismissible">
            <p><strong>⚠️ <?php _e('WooCommerce Lexware MVP Aktivierung mit Warnungen:', 'wc-lexware-mvp'); ?></strong></p>
            <ul style="margin: 10px 0 10px 20px; list-style: none;">
                <?php if (!empty($status['directory_created'])): ?>
                    <li>✓ <?php printf(__('PDF-Verzeichnis: <code>%s</code>', 'wc-lexware-mvp'), esc_html($status['directory_path'])); ?></li>
                <?php endif; ?>
                <?php if (!empty($status['htaccess_created'])): ?>
                    <li>✓ <?php _e('.htaccess Schutz aktiviert', 'wc-lexware-mvp'); ?></li>
                <?php endif; ?>
                <?php if (!empty($status['index_created'])): ?>
                    <li>✓ <?php _e('index.php erstellt', 'wc-lexware-mvp'); ?></li>
                <?php endif; ?>
                <?php if (!empty($status['errors'])): ?>
                    <?php foreach ($status['errors'] as $error): ?>
                        <li style="color: #dc3232;">✗ <?php echo esc_html($error); ?></li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
            <p style="margin: 10px 0;">
                <strong><?php _e('Bitte prüfen Sie die Schreibrechte für das Upload-Verzeichnis.', 'wc-lexware-mvp'); ?></strong><br>
                <?php printf(__('Empfohlen: <code>chmod 755 %s</code>', 'wc-lexware-mvp'), esc_html(dirname($status['directory_path']))); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Display PDF directory warnings
     */
    private static function display_pdf_directory_warnings() {
        // This will be called by PDF_Handler if needed
        // Keeping this as a placeholder for future directory health checks
    }

    /**
     * Add a custom notice (can be called from other classes)
     *
     * @since 1.0.0
     * @param string $message Notice message
     * @param string $type Notice type (success|error|warning|info)
     * @param bool $dismissible Whether notice is dismissible
     */
    public static function add_notice($message, $type = 'info', $dismissible = true) {
        $dismissible_class = $dismissible ? 'is-dismissible' : '';

        add_action('admin_notices', function() use ($message, $type, $dismissible_class) {
            ?>
            <div class="notice notice-<?php echo esc_attr($type); ?> <?php echo esc_attr($dismissible_class); ?>">
                <p><?php echo wp_kses_post($message); ?></p>
            </div>
            <?php
        });
    }
}
