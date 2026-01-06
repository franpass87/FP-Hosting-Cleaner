<?php

namespace FP\HostingCleaner;

defined('ABSPATH') || exit;

/**
 * Classe principale del plugin
 */
class Plugin {
    
    private static $instance = null;
    
    /**
     * Singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        // Hooks essenziali
        register_activation_hook(FP_HOSTING_CLEANER_PLUGIN_FILE, array($this, 'activate'));
        register_deactivation_hook(FP_HOSTING_CLEANER_PLUGIN_FILE, array($this, 'deactivate'));
        
        // Carica l'admin se siamo nell'admin
        if (is_admin()) {
            $this->load_admin();
        }
        
        // Carica lo scanner
        Scanner::get_instance();
    }
    
    /**
     * Carica l'admin (PSR-4 autoload via Composer)
     */
    private function load_admin() {
        if (class_exists('\FP\HostingCleaner\Admin')) {
            Admin::get_instance();
        }
    }
    
    /**
     * Attivazione plugin
     */
    public function activate() {
        // Crea opzioni di default
        $default_settings = array(
            'scan_directories' => array(
                'wp-content/uploads',
                'wp-content/cache',
                'wp-content/backups',
                'wp-content/upgrade',
                'wp-content/backup-db',
                'wp-content/ai1wm-backups',
                'wp-content/backups-dup-pro',
            ),
            'exclude_patterns' => array(
                '.htaccess',
                'index.php',
                '.git',
                'node_modules',
            ),
            'min_file_age_days' => 30,
            'max_duplicate_size_mb' => 10,
            'auto_cleanup_enabled' => false,
            'auto_cleanup_schedule' => 'weekly',
        );
        
        $existing_settings = get_option('fp_hosting_cleaner_settings', array());
        $settings = wp_parse_args($existing_settings, $default_settings);
        update_option('fp_hosting_cleaner_settings', $settings);
        
        // Crea tabella per i log
        $this->create_log_table();
    }
    
    /**
     * Disattivazione plugin
     */
    public function deactivate() {
        // Pulisci i cron job
        $timestamp = wp_next_scheduled('fp_hosting_cleaner_auto_cleanup');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'fp_hosting_cleaner_auto_cleanup');
        }
        
        flush_rewrite_rules();
    }
    
    /**
     * Crea la tabella per i log
     */
    private function create_log_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'fp_hosting_cleaner_logs';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            log_date datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            log_type varchar(50) NOT NULL,
            message text NOT NULL,
            file_path varchar(500),
            file_size bigint(20),
            details longtext,
            PRIMARY KEY  (id),
            KEY log_date (log_date),
            KEY log_type (log_type)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}
