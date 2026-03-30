<?php
/**
 * Main Plugin Controller Class
 *
 * Orchestrates plugin initialization, dependency loading, and hook registration
 *
 * @package WC_Lexware_MVP
 * @since 1.0.0
 */

namespace WC_Lexware_MVP\Core;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Plugin
 *
 * Central controller for the WooCommerce Lexware MVP plugin.
 * Handles initialization, class loading, and hook registration.
 */
class Plugin {

    /**
     * Plugin instance (singleton)
     *
     * @var Plugin
     */
    private static $instance = null;

    /**
     * Plugin version
     *
     * @var string
     */
    private $version = '1.0.0';

    /**
     * Get plugin instance
     *
     * @since 1.0.0
     * @return Plugin Singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor - Private to enforce singleton
     *
     * @since 1.0.0
     */
    private function __construct() {
        // Constructor intentionally empty - use init() for setup
    }

    /**
     * Initialize plugin
     *
     * Called on 'plugins_loaded' hook with priority 20.
     * Loads textdomain, dependencies, and initializes all components.
     *
     * @since 1.0.0
     */
    public static function init() {
        $instance = self::get_instance();

        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            return;
        }

        // Staging detection: disable document generation on non-production environments
        if (self::is_staging()) {
            if (is_admin()) {
                add_action('admin_notices', array(__CLASS__, 'staging_admin_notice'));
            }
            if (class_exists('\\WC_Lexware_MVP_Logger', false) || class_exists('WC_Lexware_MVP_Logger')) {
                \WC_Lexware_MVP_Logger::warning('Staging-Umgebung erkannt – Dokumentenerstellung deaktiviert', array(
                    'site_url' => get_site_url(),
                ));
            }
            return; // Skip all plugin initialization (no Order Sync, no Webhooks, no Email hooks)
        }

        // Check for database upgrades (runs migrations if needed)
        $instance->maybe_upgrade_database();

        // Load plugin textdomain
        load_plugin_textdomain('wc-lexware-mvp', false, dirname(plugin_basename(WC_LEXWARE_MVP_FILE)) . '/languages');

        // Load core dependencies
        $instance->load_dependencies();

        // Initialize admin components
        if (is_admin()) {
            $instance->init_admin();
        }

        // Initialize frontend components
        if (!is_admin()) {
            $instance->init_frontend();
        }

        // Initialize core components (always needed)
        $instance->init_core();

        // Log initialization
        if (class_exists('\\WC_Lexware_MVP_Logger', false) || class_exists('WC_Lexware_MVP_Logger')) {
            \WC_Lexware_MVP_Logger::info('🔥 Plugin initialized successfully', array(
                'version' => $instance->version,
                'is_admin' => is_admin() ? 'YES' : 'NO',
                'timestamp' => current_time('mysql')
            ));
        }
    }

    /**
     * Load all plugin dependencies
     *
     * With PSR-4 autoloader, most classes load automatically.
     * Email classes require special handling to register Action Scheduler hooks early.
     *
     * @since 1.0.0
     */
    private function load_dependencies() {
        // Email classes need special handling - load early to register hooks for Action Scheduler
        // Use woocommerce_email_classes filter which runs AFTER WC_Email is loaded
        add_filter('woocommerce_email_classes', array($this, 'register_email_classes'), 10);

        // Register custom email group for better organization in WooCommerce settings
        add_filter('woocommerce_email_groups', array($this, 'register_email_group'), 10);

        // Also instantiate early on woocommerce_loaded (after woocommerce_init)
        // This ensures hooks are registered before Action Scheduler runs
        add_action('woocommerce_loaded', function() {
            // WC_Email should definitely be loaded by now
            if (!class_exists('WC_Email')) {
                \WC_Lexware_MVP_Logger::error('WC_Email class still not found on woocommerce_loaded!');
                return;
            }

            // Instantiate email classes to register their action hooks
            // These instances will be replaced by the ones in woocommerce_email_classes
            // but the hooks (like wc_lexware_mvp_invoice_ready) will be registered
            if (class_exists('WC_Lexware_MVP\\Email\\Invoice')) {
                new \WC_Lexware_MVP\Email\Invoice();
                \WC_Lexware_MVP_Logger::debug('Invoice email class instantiated and hooks registered');
            }

            if (class_exists('WC_Lexware_MVP\\Email\\Credit_Note')) {
                new \WC_Lexware_MVP\Email\Credit_Note();
                \WC_Lexware_MVP_Logger::debug('Credit Note email class instantiated and hooks registered');
            }

            if (class_exists('WC_Lexware_MVP\\Email\\Order_Confirmation')) {
                new \WC_Lexware_MVP\Email\Order_Confirmation();
                \WC_Lexware_MVP_Logger::debug('Order Confirmation email class instantiated and hooks registered');
            }

            \WC_Lexware_MVP_Logger::info('✉️ Email classes loaded and action hooks registered');
        }, 10);
    }

    /**
     * Initialize admin components
     *
     * Loads settings page, meta boxes, notices, dashboard widget, and failed documents page.
     * Only called when is_admin() is true.
     *
     * @since 1.0.0
     */
    private function init_admin() {
        // Enqueue central admin styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));

        if (class_exists('WC_Lexware_MVP_Admin') || class_exists('\\WC_Lexware_MVP_Admin', false)) {
            \WC_Lexware_MVP_Admin::init();
        }

        if (class_exists('WC_Lexware_MVP_Admin_Meta_Box') || class_exists('\\WC_Lexware_MVP_Admin_Meta_Box', false)) {
            \WC_Lexware_MVP_Admin_Meta_Box::init();
        }

        if (class_exists('WC_Lexware_MVP_Admin_Notices') || class_exists('\\WC_Lexware_MVP_Admin_Notices', false)) {
            \WC_Lexware_MVP_Admin_Notices::init();
        }

        // Initialize Dashboard Widget
        if (class_exists('\\WC_Lexware_MVP\\Admin\\Dashboard_Widget')) {
            \WC_Lexware_MVP\Admin\Dashboard_Widget::init();
        }

        // Initialize Failed Documents Page
        if (class_exists('\\WC_Lexware_MVP\\Admin\\Failed_Documents_Page')) {
            \WC_Lexware_MVP\Admin\Failed_Documents_Page::init();
        }

        // Initialize Accounting Page (Buchhaltung)
        if (class_exists('\\WC_Lexware_MVP\\Admin\\Accounting_Page')) {
            \WC_Lexware_MVP\Admin\Accounting_Page::init();
        }

        // Initialize Status Monitoring Page
        if (class_exists('\\WC_Lexware_MVP\\Admin\\Status_Page')) {
            new \WC_Lexware_MVP\Admin\Status_Page();
        }

        // Check PDF directory health
        add_action('admin_init', array($this, 'check_pdf_directory_health'));
    }

    /**
     * Initialize frontend components
     *
     * Loads My Account page integration for customer document downloads.
     * Only called when is_admin() is false.
     *
     * @since 1.0.0
     */
    private function init_frontend() {
        if (class_exists('\\WC_Lexware_MVP_My_Account', false) || class_exists('WC_Lexware_MVP_My_Account')) {
            new \WC_Lexware_MVP_My_Account();
        }
    }

    /**
     * Initialize core components
     *
     * Loads Order Sync and security features that are needed in both admin and frontend.
     * Always called regardless of context.
     *
     * @since 1.0.0
     */
    private function init_core() {
        // Initialize Order Sync AFTER WooCommerce is fully loaded
        add_action('woocommerce_init', array($this, 'init_order_sync'));

        // Initialize Webhook Handler (Phase 1: Signature Verification)
        add_action('woocommerce_init', array($this, 'init_webhook_handler'));

        // Register REST API endpoints
        add_action('rest_api_init', array($this, 'init_rest_api'));

        // Reduce nonce lifetime for enhanced security
        add_filter('nonce_life', array($this, 'reduce_nonce_lifetime'));

        // Cleanup documents when order is permanently deleted
        add_action('woocommerce_delete_order', array($this, 'cleanup_deleted_order_documents'));
        add_action('before_delete_post', array($this, 'cleanup_deleted_order_documents_legacy'));
    }

    /**
     * Detect staging/non-production environments.
     *
     * Checks WP_ENVIRONMENT_TYPE, Kinsta staging URL pattern, and common staging indicators.
     * Returns true if the site should NOT create Lexware documents.
     *
     * @since 1.4.0
     * @return bool True if staging environment detected
     */
    public static function is_staging() {
        // 1. WordPress 5.5+ environment type (most reliable)
        if (function_exists('wp_get_environment_type')) {
            $env = wp_get_environment_type();
            if (in_array($env, array('staging', 'development', 'local'), true)) {
                return true;
            }
        }

        // 2. Kinsta staging URL pattern
        $site_url = get_site_url();
        if (preg_match('/stg-.*\.kinsta\.cloud/', $site_url)) {
            return true;
        }

        // 3. Common staging/local domains
        if (preg_match('/\.(local|test|localhost|staging|dev)($|\/)/', $site_url)) {
            return true;
        }

        return false;
    }

    /**
     * Admin notice for staging environments.
     *
     * @since 1.4.0
     */
    public static function staging_admin_notice() {
        echo '<div class="notice notice-warning" style="border-left-color: #ff6b00;">';
        echo '<p><strong>⚠️ Lexware MVP – Staging-Modus:</strong> ';
        echo 'Dokumentenerstellung (Rechnungen, Gutschriften, Auftragsbestätigungen) ist <strong>deaktiviert</strong>, ';
        echo 'da dies eine Staging-Umgebung ist. Lexware API wird nicht angesprochen.</p>';
        echo '</div>';
    }

    /**
     * Check and run database migrations if needed
     *
     * Called on every page load to ensure database schema is up to date.
     * Safe to call multiple times - only runs migrations when version changes.
     *
     * @since 1.2.6
     */
    private function maybe_upgrade_database() {
        // Trigger autoload
        if (!class_exists('\\WC_Lexware_MVP_Documents_Table', false)) {
            class_exists('\\WC_Lexware_MVP\\Core\\Documents_Table');
        }

        // Run migrations if needed
        \WC_Lexware_MVP_Documents_Table::maybe_upgrade();
    }

    /**
     * Cleanup documents when an order is permanently deleted (HPOS)
     *
     * Removes associated document records and PDF files when an order
     * is permanently deleted. Prevents orphaned records.
     *
     * @since 1.2.6
     * @param int $order_id WooCommerce Order ID
     */
    public function cleanup_deleted_order_documents($order_id) {
        if (!class_exists('\\WC_Lexware_MVP_Documents_Table', false)) {
            class_exists('\\WC_Lexware_MVP\\Core\\Documents_Table');
        }

        \WC_Lexware_MVP_Documents_Table::delete_by_order_id($order_id);
    }

    /**
     * Cleanup documents when an order is permanently deleted (Legacy)
     *
     * Handles cleanup for shops not using HPOS.
     *
     * @since 1.2.6
     * @param int $post_id Post ID being deleted
     */
    public function cleanup_deleted_order_documents_legacy($post_id) {
        // Only handle shop_order post type
        if (get_post_type($post_id) !== 'shop_order') {
            return;
        }

        $this->cleanup_deleted_order_documents($post_id);
    }

    /**
     * Initialize REST API endpoints
     *
     * Registers custom REST routes for external access to plugin data.
     * Called on 'rest_api_init' hook.
     *
     * @since 1.3.1
     */
    public function init_rest_api() {
        if (class_exists('\\WC_Lexware_MVP\\API\\Rest_Documents')) {
            $controller = new \WC_Lexware_MVP\API\Rest_Documents();
            $controller->register_routes();
        }
    }

    /**
     * Initialize Order Sync component
     *
     * Called on 'woocommerce_init' hook to ensure all WooCommerce hooks are available.
     * This is the core component that handles invoice and credit note synchronization.
     *
     * @since 1.0.0
     */
    public function init_order_sync() {
        if (class_exists('\\WC_Lexware_MVP_Order_Sync', false) || class_exists('WC_Lexware_MVP_Order_Sync')) {
            \WC_Lexware_MVP_Order_Sync::init();

            if (class_exists('\\WC_Lexware_MVP_Logger', false) || class_exists('WC_Lexware_MVP_Logger')) {
                \WC_Lexware_MVP_Logger::info('🔥 Order Sync initialized (on woocommerce_init)');
            }
        }
    }

    /**
     * Initialize Webhook Handler component
     *
     * Called on 'woocommerce_init' to register WooCommerce API endpoint.
     * Handles incoming webhooks from Lexware with RSA-SHA512 signature verification.
     *
     * @since 0.4.0 (Phase 1)
     */
    public function init_webhook_handler() {
        if (class_exists('\\WC_Lexware_MVP\\API\\Webhook_Handler')) {
            \WC_Lexware_MVP\API\Webhook_Handler::init();

            if (class_exists('\\WC_Lexware_MVP_Logger', false) || class_exists('WC_Lexware_MVP_Logger')) {
                \WC_Lexware_MVP_Logger::info('🔔 Webhook Handler initialized', array(
                    'endpoint' => home_url('/wc-api/lexware-webhook')
                ));
            }
        }
    }

    /**
     * Register email classes with WooCommerce
     *
     * Adds Invoice and Credit Note email types to WooCommerce email system.
     * Called via 'woocommerce_email_classes' filter.
     *
     * @since 1.0.0
     * @param array $emails Existing WooCommerce email classes
     * @return array Modified email classes array with plugin emails added
     */
    public function register_email_classes($emails) {
        // Use full namespaced class names
        if (class_exists('WC_Lexware_MVP\\Email\\Invoice')) {
            $emails['WC_Lexware_MVP_Email_Invoice'] = new \WC_Lexware_MVP\Email\Invoice();
        }

        if (class_exists('WC_Lexware_MVP\\Email\\Credit_Note')) {
            $emails['WC_Lexware_MVP_Email_Credit_Note'] = new \WC_Lexware_MVP\Email\Credit_Note();
        }

        if (class_exists('WC_Lexware_MVP\\Email\\Order_Confirmation')) {
            $emails['WC_Lexware_MVP_Email_Order_Confirmation'] = new \WC_Lexware_MVP\Email\Order_Confirmation();
        }

        return $emails;
    }

    /**
     * Register custom email group for Lexware emails
     *
     * Creates a dedicated "Lexware" section in WooCommerce > Settings > Emails
     * for better organization of plugin-specific emails.
     *
     * @since 1.0.1
     * @param array $groups Existing email groups
     * @return array Modified email groups array
     */
    public function register_email_group($groups) {
        $groups['lexware'] = array(
            'name' => __('Lexware', WC_LEXWARE_MVP_TEXT_DOMAIN),
            'description' => __('E-Mails für Lexware-Dokumente (Rechnungen und Gutschriften)', WC_LEXWARE_MVP_TEXT_DOMAIN)
        );

        return $groups;
    }

    /**
     * Check PDF directory health
     *
     * Verifies that the PDF upload directory is writable and shows admin notice if not.
     * Called on 'admin_init' hook.
     *
     * @since 1.0.0
     */
    public function check_pdf_directory_health() {
        if (class_exists('\\WC_Lexware_MVP_PDF_Handler', false) || class_exists('WC_Lexware_MVP_PDF_Handler')) {
            \WC_Lexware_MVP_PDF_Handler::maybe_show_admin_notice();
        }
    }

    /**
     * Enqueue admin styles on Lexware-related pages
     *
     * Loads the central admin stylesheet on all pages where Lexware components are displayed.
     * Called on 'admin_enqueue_scripts' hook.
     *
     * @since 1.0.2
     * @param string $hook The current admin page hook
     */
    public function enqueue_admin_styles($hook) {
        // List of pages where Lexware styles should load
        $lexware_pages = array(
            'shop_order',                           // Legacy order edit
            'woocommerce_page_wc-orders',           // HPOS order edit
            'woocommerce_page_lexware-accounting',  // Buchhaltung page
            'woocommerce_page_lexware-failed-documents', // Failed documents
            'woocommerce_page_wc-settings',         // Settings page (for Lexware tab)
            'tools_page_lexware-status',            // Status page
            'index.php',                            // Dashboard (for widget)
        );

        $screen = get_current_screen();
        $load_styles = false;

        // Check if current screen matches Lexware pages
        if ($screen) {
            if (in_array($screen->id, $lexware_pages)) {
                $load_styles = true;
            }
            // Also load on settings page when on Lexware tab
            if ($screen->id === 'woocommerce_page_wc-settings' && isset($_GET['tab']) && $_GET['tab'] === 'lexware_mvp') {
                $load_styles = true;
            }
        }

        if (!$load_styles) {
            return;
        }

        // Enqueue central admin stylesheet
        wp_enqueue_style(
            'lexware-mvp-admin',
            WC_LEXWARE_MVP_URL . 'assets/css/admin.css',
            array(),
            WC_LEXWARE_MVP_VERSION
        );
    }

    /**
     * Reduce nonce lifetime for enhanced security
     *
     * Changes WordPress default from 24 hours to 1 hour.
     * Applied via 'nonce_life' filter.
     *
     * @since 1.0.0
     * @return int Nonce lifetime in seconds, e.g., 3600
     */
    public function reduce_nonce_lifetime() {
        return HOUR_IN_SECONDS;
    }

    /**
     * Plugin activation
     *
     * Checks system requirements and creates database tables.
     * Called via register_activation_hook() in bootstrap file.
     *
     * @since 1.0.0
     * @see WC_Lexware_MVP\Core\Activator::activate()
     */
    public static function activate() {
        // Use legacy class names during activation (autoloader aliases not yet registered)
        // This ensures activation works even before WordPress hooks are fully loaded
        if (!class_exists('\\WC_Lexware_MVP_Requirements_Checker', false) && !class_exists('WC_Lexware_MVP_Requirements_Checker')) {
            // Try to load via autoloader
            class_exists('WC_Lexware_MVP\\Core\\Requirements_Checker');
        }

        // Check requirements using legacy class name
        if (class_exists('\\WC_Lexware_MVP_Requirements_Checker', false) || class_exists('WC_Lexware_MVP_Requirements_Checker')) {
            if (!\WC_Lexware_MVP_Requirements_Checker::meets_requirements()) {
                wp_die(
                    \WC_Lexware_MVP_Requirements_Checker::get_wp_die_message(),
                    'Plugin Activation Error',
                    array('back_link' => true)
                );
            }
        }

        // Trigger activator using legacy class name
        if (class_exists('\\WC_Lexware_MVP_Activator', false) || class_exists('WC_Lexware_MVP_Activator')) {
            \WC_Lexware_MVP_Activator::activate();
        }
    }

    /**
     * Plugin deactivation
     *
     * Clears scheduled events but preserves database data.
     * For complete data removal, user must uninstall the plugin.
     *
     * @since 1.0.0
     */
    public static function deactivate() {
        // Clear scheduled hooks
        wp_clear_scheduled_hook('wc_lexware_mvp_retry_sync');

        // Log deactivation
        if (class_exists('\\WC_Lexware_MVP_Logger', false) || class_exists('WC_Lexware_MVP_Logger')) {
            \WC_Lexware_MVP_Logger::info('Plugin deactivated');
        }
    }

    /**
     * Declare HPOS compatibility
     *
     * Informs WooCommerce that this plugin is compatible with
     * High-Performance Order Storage (Custom Order Tables).
     *
     * @since 1.0.0
     * @see https://github.com/woocommerce/woocommerce/wiki/High-Performance-Order-Storage-Upgrade-Recipe-Book
     */
    public static function declare_hpos_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                WC_LEXWARE_MVP_FILE,
                true
            );
        }
    }
}
