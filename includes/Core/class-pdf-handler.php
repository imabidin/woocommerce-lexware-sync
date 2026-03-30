<?php
/**
 * PDF Handler - Centralized PDF Storage Management
 *
 * Ensures PDFs are always stored and retrieved from correct location,
 * independent of server configuration or WordPress UPLOADS constant.
 * Handles memory-efficient saving of large PDF files.
 *
 * @package WC_Lexware_MVP
 * @since 1.0.0
 */

namespace WC_Lexware_MVP\Core;

class PDF_Handler {

    /**
     * Get correct path to PDF directory
     *
     * Uses wp_upload_dir() for maximum compatibility with:
     * - Standard WordPress installations
     * - Multisite installations
     * - Custom UPLOADS constants
     * - CDN/Cloud Storage plugins
     *
     * @since 1.0.0
     * @return string Absolute path to lexware-invoices directory
     */
    public static function get_pdf_directory() {
        $upload_dir = wp_upload_dir();
        return $upload_dir['basedir'] . '/lexware-invoices';
    }

    /**
     * Get full path to a PDF file
     *
     * @since 1.0.0
     * @param string $filename PDF filename (e.g., "RE0140_6963.pdf")
     * @return string Absolute path to PDF file
     */
    public static function get_pdf_path($filename) {
        return self::get_pdf_directory() . '/' . sanitize_file_name($filename);
    }

    /**
     * Get URL to PDF directory (for future features)
     *
     * @since 1.0.0
     * @return string URL to lexware-invoices directory
     */
    public static function get_pdf_directory_url() {
        $upload_dir = wp_upload_dir();
        return $upload_dir['baseurl'] . '/lexware-invoices';
    }

    /**
     * Check if PDF exists
     *
     * @since 1.0.0
     * @param string $filename PDF filename
     * @return bool True if PDF exists and is readable
     */
    public static function pdf_exists($filename) {
        $pdf_path = self::get_pdf_path($filename);
        return file_exists($pdf_path) && is_readable($pdf_path);
    }

    /**
     * Get PDF file size in bytes
     *
     * @since 1.0.0
     * @param string $filename PDF filename
     * @return int|false File size in bytes or false if not found
     */
    public static function get_pdf_size($filename) {
        if (!self::pdf_exists($filename)) {
            return false;
        }
        return filesize(self::get_pdf_path($filename));
    }

    /**
     * Create PDF directory if not exists
     *
     * Called during plugin activation, but also used as fallback
     * during PDF save operations.
     *
     * @since 1.0.0
     * @return bool True on success
     */
    public static function ensure_pdf_directory_exists() {
        $lexware_dir = self::get_pdf_directory();

        // Verzeichnis erstellen
        if (!file_exists($lexware_dir)) {
            wp_mkdir_p($lexware_dir);
        }

        // .htaccess für Schutz erstellen
        $htaccess_file = $lexware_dir . '/.htaccess';
        if (!file_exists($htaccess_file)) {
            file_put_contents($htaccess_file, "deny from all\n");
        }

        // index.php erstellen
        $index_file = $lexware_dir . '/index.php';
        if (!file_exists($index_file)) {
            file_put_contents($index_file, '<?php // Silence is golden');
        }

        return file_exists($lexware_dir) && is_writable($lexware_dir);
    }

    /**
     * Health-check for PDF directory write permissions
     *
     * Should be called regularly (e.g., in admin dashboard).
     *
     * @since 1.0.0
     * @return array Health-check status with 'status', 'directory', 'writable', 'protected', 'issues'
     */
    public static function check_directory_health() {
        $lexware_dir = self::get_pdf_directory();
        $issues = array();
        $status = 'healthy';

        // Check 1: Verzeichnis existiert
        if (!file_exists($lexware_dir)) {
            $issues[] = 'PDF-Verzeichnis existiert nicht';
            $status = 'critical';
        }

        // Check 2: Schreibrechte
        if (file_exists($lexware_dir) && !is_writable($lexware_dir)) {
            $issues[] = 'Keine Schreibrechte für PDF-Verzeichnis';
            $status = 'critical';
        }

        // Check 3: .htaccess Schutz
        $htaccess_file = $lexware_dir . '/.htaccess';
        if (file_exists($lexware_dir) && !file_exists($htaccess_file)) {
            $issues[] = '.htaccess Schutz fehlt - PDFs öffentlich zugänglich';
            $status = ($status === 'critical') ? 'critical' : 'warning';
        }

        // Check 4: Verfügbarer Speicherplatz
        if (file_exists($lexware_dir)) {
            $free_space = @disk_free_space($lexware_dir);
            if ($free_space !== false && $free_space < 100 * 1024 * 1024) { // < 100 MB
                $issues[] = sprintf('Wenig Speicherplatz verfügbar (%s MB)', round($free_space / 1024 / 1024, 2));
                $status = ($status === 'critical') ? 'critical' : 'warning';
            }
        }

        return array(
            'status' => $status,
            'directory' => $lexware_dir,
            'writable' => file_exists($lexware_dir) && is_writable($lexware_dir),
            'protected' => file_exists($htaccess_file),
            'issues' => $issues
        );
    }

    /**
     * Display admin notice if directory has issues
     *
     * @since 1.0.0
     */
    public static function maybe_show_admin_notice() {
        $health = self::check_directory_health();

        if ($health['status'] === 'critical') {
            add_action('admin_notices', function() use ($health) {
                echo '<div class="notice notice-error"><p>';
                echo '<strong>' . esc_html__('Lexware MVP: Kritischer Fehler', WC_LEXWARE_MVP_TEXT_DOMAIN) . '</strong><br>';
                foreach ($health['issues'] as $issue) {
                    echo '• ' . esc_html($issue) . '<br>';
                }
                echo '</p></div>';
            });
        } elseif ($health['status'] === 'warning') {
            add_action('admin_notices', function() use ($health) {
                echo '<div class="notice notice-warning"><p>';
                echo '<strong>' . esc_html__('Lexware MVP: Warnung', WC_LEXWARE_MVP_TEXT_DOMAIN) . '</strong><br>';
                foreach ($health['issues'] as $issue) {
                    echo '• ' . esc_html($issue) . '<br>';
                }
                echo '</p></div>';
            });
        }
    }

    /**
     * Save PDF file with memory management for large files
     *
     * Uses streaming write for files > 10 MB.
     * Verifies file size after save.
     *
     * @since 1.0.0
     * @param string $pdf_data PDF binary data
     * @param int $order_id Order ID
     * @param string $document_type Document type (invoice|credit_note)
     * @param string $document_number Document number (e.g., RE0140, GS0023)
     * @return string|false Filename on success, false on failure
     */
    public function save_pdf($pdf_data, $order_id, $document_type = 'invoice', $document_number = '') {
        $start_time = microtime(true);
        $memory_before = memory_get_usage(true);

        // 1. Memory Check (with fallback for restricted contexts)
        $pdf_size = strlen($pdf_data);
        $available_memory = $this->get_available_memory();

        // Enhanced Logging: PDF Download Started
        \WC_Lexware_MVP_Logger::debug('PDF save started', array(
            'order_id' => $order_id,
            'document_type' => $document_type,
            'document_number' => $document_number,
            'pdf_size_mb' => round($pdf_size / 1024 / 1024, 2),
            'memory_before_mb' => round($memory_before / 1024 / 1024, 2),
            'memory_limit' => ini_get('memory_limit'),
            'available_memory_mb' => round($available_memory / 1024 / 1024, 2),
            'will_use_streaming' => $pdf_size > 10 * 1024 * 1024,
        ));

        // In WP-Cron context, available_memory might be 0 or very low
        // But we still want to try saving small PDFs (< 5MB)
        if ($available_memory > 0 && $pdf_size > $available_memory) {
            \WC_Lexware_MVP_Logger::error('Insufficient memory for PDF', array(
                'order_id' => $order_id,
                'pdf_size_mb' => round($pdf_size / 1024 / 1024, 2),
                'available_mb' => round($available_memory / 1024 / 1024, 2),
                'memory_limit' => ini_get('memory_limit'),
                'memory_used_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                'recommendation' => 'Increase PHP memory_limit to at least 512M',
            ));
            return false;
        }

        // If available_memory is 0 (restricted context), allow small PDFs (< 5MB)
        if ($available_memory == 0 && $pdf_size > 5 * 1024 * 1024) {
            \WC_Lexware_MVP_Logger::warning('Low memory context - skipping large PDF', array(
                'order_id' => $order_id,
                'pdf_size_mb' => round($pdf_size / 1024 / 1024, 2),
                'note' => 'Will retry in admin context'
            ));
            return false;
        }

        // Ensure directory exists
        self::ensure_pdf_directory_exists();

        // Generate filename based on document type
        $prefix = ($document_type === 'credit_note') ? 'CN' : 'RE';
        $filename = sanitize_file_name($document_number . '_' . $order_id . '.pdf');

        // Fallback wenn keine document_number
        if (empty($document_number)) {
            $filename = sanitize_file_name($prefix . '_' . $order_id . '_' . time() . '.pdf');
        }

        $file_path = self::get_pdf_path($filename);

        // 2. Streaming Write für große Dateien (> 10 MB)
        if ($pdf_size > 10 * 1024 * 1024) {
            $handle = fopen($file_path, 'wb');
            if (!$handle) {
                \WC_Lexware_MVP_Logger::error('Cannot open file for writing', array(
                    'order_id' => $order_id,
                    'file_path' => $file_path
                ));
                return false;
            }

            // Chunk-weise schreiben (1 MB Chunks)
            $chunk_size = 1024 * 1024; // 1 MB
            $offset = 0;
            $success = true;

            while ($offset < $pdf_size) {
                $chunk = substr($pdf_data, $offset, $chunk_size);
                if (fwrite($handle, $chunk) === false) {
                    $success = false;
                    break;
                }
                $offset += $chunk_size;

                // Garbage Collection hint every 5 MB
                if ($offset % (5 * 1024 * 1024) === 0) {
                    gc_collect_cycles();
                }
            }

            fclose($handle);
            unset($pdf_data); // Free memory

            if (!$success) {
                @unlink($file_path); // Cleanup on failure
                \WC_Lexware_MVP_Logger::error('PDF streaming write failed', array(
                    'order_id' => $order_id,
                    'filename' => $filename
                ));
                return false;
            }

        } else {
            // Small files: Direct write
            $result = file_put_contents($file_path, $pdf_data);
            if ($result === false) {
                \WC_Lexware_MVP_Logger::error('PDF speichern fehlgeschlagen', array(
                    'order_id' => $order_id,
                    'filename' => $filename,
                    'document_type' => $document_type
                ));
                return false;
            }
        }

        // 3. Verify file
        if (!file_exists($file_path)) {
            \WC_Lexware_MVP_Logger::error('PDF verification failed - file not found', array(
                'order_id' => $order_id,
                'expected_size' => $pdf_size,
                'file_path' => $file_path,
            ));
            return false;
        }

        $actual_size = filesize($file_path);
        if ($actual_size !== $pdf_size) {
            \WC_Lexware_MVP_Logger::error('PDF verification failed - size mismatch', array(
                'order_id' => $order_id,
                'expected_size' => $pdf_size,
                'actual_size' => $actual_size,
                'size_diff_bytes' => abs($actual_size - $pdf_size),
            ));
            @unlink($file_path); // Cleanup
            return false;
        }

        $memory_after = memory_get_usage(true);
        $memory_peak = memory_get_peak_usage(true);
        $time_elapsed = microtime(true) - $start_time;

        // Enhanced Logging: PDF Save Completed
        \WC_Lexware_MVP_Logger::info('PDF erfolgreich gespeichert', array(
            'order_id' => $order_id,
            'filename' => $filename,
            'file_path' => $file_path,
            'size_bytes' => $actual_size,
            'size_mb' => round($actual_size / 1024 / 1024, 2),
            'document_type' => $document_type,
            'document_number' => $document_number,
            'streaming_used' => $pdf_size > 10 * 1024 * 1024,
            'memory_before_mb' => round($memory_before / 1024 / 1024, 2),
            'memory_after_mb' => round($memory_after / 1024 / 1024, 2),
            'memory_peak_mb' => round($memory_peak / 1024 / 1024, 2),
            'memory_delta_mb' => round(($memory_after - $memory_before) / 1024 / 1024, 2),
            'time_elapsed_ms' => round($time_elapsed * 1000, 2),
            'write_speed_mbps' => round(($actual_size / 1024 / 1024) / $time_elapsed, 2),
        ));

        return $filename;
    }

    /**
     * Get available memory for operations
     *
     * Reserves 20% buffer for safety.
     *
     * @since 1.0.0
     * @return int Available memory in bytes
     */
    private function get_available_memory() {
        $memory_limit = ini_get('memory_limit');

        // Unlimited memory
        if ($memory_limit === '-1') {
            return PHP_INT_MAX;
        }

        $limit_bytes = $this->parse_memory_limit($memory_limit);
        $used_memory = memory_get_usage(true);

        // Reserve 20% buffer for safety
        $buffer = $limit_bytes * 0.2;
        return max(0, $limit_bytes - $used_memory - $buffer);
    }

    /**
     * Parse memory limit string to bytes
     *
     * @since 1.0.0
     * @param string $limit Memory limit (e.g., "256M", "1G", "512K")
     * @return int Memory in bytes
     */
    private function parse_memory_limit($limit) {
        $limit = trim($limit);
        $last_char = strtolower(substr($limit, -1));
        $value = (int) $limit;

        switch ($last_char) {
            case 'g':
                $value *= 1024 * 1024 * 1024;
                break;
            case 'm':
                $value *= 1024 * 1024;
                break;
            case 'k':
                $value *= 1024;
                break;
        }

        return $value;
    }

    /**
     * Get singleton instance (for non-static usage)
     *
     * @since 1.0.0
     * @return self Singleton instance
     */
    public static function get_instance() {
        static $instance = null;
        if ($instance === null) {
            $instance = new self();
        }
        return $instance;
    }
}
