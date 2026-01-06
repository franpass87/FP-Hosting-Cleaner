<?php

namespace FP\HostingCleaner;

defined('ABSPATH') || exit;

/**
 * Classe per il logging
 */
class Logger {
    
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
     * Log di un messaggio
     */
    public function log($type, $message, $details = array()) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'fp_hosting_cleaner_logs';
        
        $file_path = isset($details['path']) ? $details['path'] : (isset($details['file_path']) ? $details['file_path'] : null);
        $file_size = isset($details['size']) ? $details['size'] : null;
        
        $wpdb->insert(
            $table_name,
            array(
                'log_type' => $type,
                'message' => $message,
                'file_path' => $file_path,
                'file_size' => $file_size,
                'details' => !empty($details) ? json_encode($details) : null,
            ),
            array('%s', '%s', '%s', '%d', '%s')
        );
    }
    
    /**
     * Ottiene i log recenti
     */
    public function get_recent_logs($limit = 100, $type = null) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'fp_hosting_cleaner_logs';
        
        $where = '';
        if ($type) {
            $where = $wpdb->prepare("WHERE log_type = %s", $type);
        }
        
        $query = "SELECT * FROM {$table_name} {$where} ORDER BY log_date DESC LIMIT %d";
        $query = $wpdb->prepare($query, $limit);
        
        return $wpdb->get_results($query, ARRAY_A);
    }
}
