<?php

namespace FP\HostingCleaner;

defined('ABSPATH') || exit;

/**
 * Classe per scansionare e identificare file ridondanti
 */
class Scanner {
    
    private static $instance = null;
    private $settings = null;
    private $protection = null;
    
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
        $this->settings = get_option('fp_hosting_cleaner_settings', array());
        $this->protection = ProtectionManager::get_instance();
    }
    
    /**
     * Scansiona le directory per trovare file ridondanti
     */
    public function scan($directory = null) {
        $results = array(
            'duplicates' => array(),
            'old_files' => array(),
            'temp_files' => array(),
            'cache_files' => array(),
            'backup_files' => array(),
            'empty_dirs' => array(),
            'total_files' => 0,
            'total_size' => 0,
            'scanned_dirs' => array(),
        );
        
        $scan_dirs = $directory ? array($directory) : $this->get_scan_directories();
        
        foreach ($scan_dirs as $dir) {
            $full_path = $this->get_full_path($dir);
            if (!is_dir($full_path)) {
                continue;
            }
            
            $results['scanned_dirs'][] = $dir;
            $this->scan_directory($full_path, $results);
        }
        
        // Trova duplicati
        $results['duplicates'] = $this->find_duplicates($results);
        
        return $results;
    }
    
    /**
     * Scansiona una directory ricorsivamente
     */
    private function scan_directory($path, &$results) {
        if (!is_readable($path)) {
            return;
        }
        
        $items = @scandir($path);
        if ($items === false) {
            return;
        }
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            
            $full_path = $path . '/' . $item;
            
            // Controlla se è escluso o protetto
            if ($this->is_excluded($full_path) || $this->protection->is_protected($full_path)) {
                continue;
            }
            
            if (is_dir($full_path)) {
                $this->scan_directory($full_path, $results);
                
                // Controlla se la directory è vuota (ma non protetta)
                if ($this->is_empty_directory($full_path) && !$this->protection->is_protected_directory($full_path)) {
                    $results['empty_dirs'][] = $full_path;
                }
            } else {
                $results['total_files']++;
                $file_size = filesize($full_path);
                $results['total_size'] += $file_size;
                
                // Categorizza il file
                $this->categorize_file($full_path, $file_size, $results);
            }
        }
    }
    
    /**
     * Categorizza un file in base al tipo
     */
    private function categorize_file($file_path, $file_size, &$results) {
        $file_name = basename($file_path);
        $file_ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        $file_age = time() - filemtime($file_path);
        $min_age = isset($this->settings['min_file_age_days']) 
            ? $this->settings['min_file_age_days'] * DAY_IN_SECONDS 
            : 30 * DAY_IN_SECONDS;
        
        // File temporanei
        if (preg_match('/\.(tmp|temp|bak|old|swp|~)$/i', $file_name) || 
            strpos($file_name, '~') !== false ||
            strpos($file_name, '.tmp') !== false) {
            $results['temp_files'][] = array(
                'path' => $file_path,
                'size' => $file_size,
                'age' => $file_age,
                'modified' => filemtime($file_path),
            );
            return;
        }
        
        // File di cache
        if (strpos($file_path, '/cache/') !== false || 
            strpos($file_path, '/w3tc/') !== false ||
            strpos($file_path, '/wp-rocket/') !== false ||
            strpos($file_path, '/litespeed/') !== false ||
            $file_ext === 'cache') {
            if ($file_age > $min_age) {
                $results['cache_files'][] = array(
                    'path' => $file_path,
                    'size' => $file_size,
                    'age' => $file_age,
                    'modified' => filemtime($file_path),
                );
            }
            return;
        }
        
        // File di backup (ma NON file SQL che potrebbero essere importanti)
        // I file SQL vengono protetti da ProtectionManager, quindi qui li escludiamo dalla scansione
        if (preg_match('/\.sql$/i', $file_name)) {
            // File SQL sono protetti, non scansionarli
            return;
        }
        
        if (strpos($file_path, '/backup') !== false || 
            strpos($file_path, '/backups') !== false ||
            preg_match('/\.(zip|tar|gz|backup)$/i', $file_name)) {
            if ($file_age > $min_age) {
                $results['backup_files'][] = array(
                    'path' => $file_path,
                    'size' => $file_size,
                    'age' => $file_age,
                    'modified' => filemtime($file_path),
                );
            }
            return;
        }
        
        // File vecchi (oltre la soglia)
        if ($file_age > $min_age && $file_size > 0) {
            $results['old_files'][] = array(
                'path' => $file_path,
                'size' => $file_size,
                'age' => $file_age,
                'modified' => filemtime($file_path),
            );
        }
    }
    
    /**
     * Trova file duplicati
     */
    private function find_duplicates(&$results) {
        $file_hashes = array();
        $duplicates = array();
        
        // Raccogli tutti i file scansionati
        $all_files = array_merge(
            $results['temp_files'],
            $results['cache_files'],
            $results['backup_files'],
            $results['old_files']
        );
        
        $max_size = isset($this->settings['max_duplicate_size_mb']) 
            ? $this->settings['max_duplicate_size_mb'] * 1024 * 1024 
            : 10 * 1024 * 1024;
        
        foreach ($all_files as $file_info) {
            $file_path = $file_info['path'];
            $file_size = $file_info['size'];
            
            // Salta file troppo grandi per il controllo hash
            if ($file_size > $max_size || !is_readable($file_path)) {
                continue;
            }
            
            // Calcola hash MD5 (solo per file piccoli)
            if ($file_size < 5 * 1024 * 1024) { // Max 5MB per hash completo
                $hash = md5_file($file_path);
            } else {
                // Per file più grandi, usa hash parziale
                $handle = fopen($file_path, 'rb');
                if ($handle) {
                    $data = fread($handle, 8192); // Primi 8KB
                    fseek($handle, -8192, SEEK_END); // Ultimi 8KB
                    $data .= fread($handle, 8192);
                    fclose($handle);
                    $hash = md5($data . $file_size);
                } else {
                    continue;
                }
            }
            
            if (!isset($file_hashes[$hash])) {
                $file_hashes[$hash] = array();
            }
            
            $file_hashes[$hash][] = $file_info;
        }
        
        // Trova hash con più di un file
        foreach ($file_hashes as $hash => $files) {
            if (count($files) > 1) {
                $duplicates[] = array(
                    'hash' => $hash,
                    'files' => $files,
                    'count' => count($files),
                    'total_size' => array_sum(array_column($files, 'size')),
                );
            }
        }
        
        return $duplicates;
    }
    
    /**
     * Verifica se un percorso è escluso
     */
    private function is_excluded($path) {
        $exclude_patterns = isset($this->settings['exclude_patterns']) 
            ? $this->settings['exclude_patterns'] 
            : array();
        
        foreach ($exclude_patterns as $pattern) {
            if (strpos($path, $pattern) !== false) {
                return true;
            }
        }
        
        // Escludi sempre il plugin stesso
        if (strpos($path, FP_HOSTING_CLEANER_PLUGIN_DIR) !== false) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Verifica se una directory è vuota
     */
    private function is_empty_directory($dir) {
        $items = @scandir($dir);
        if ($items === false) {
            return false;
        }
        
        return count($items) <= 2; // Solo . e ..
    }
    
    /**
     * Ottiene le directory da scansionare
     */
    private function get_scan_directories() {
        $default_dirs = array(
            'wp-content/uploads',
            'wp-content/cache',
            'wp-content/backups',
            'wp-content/upgrade',
        );
        
        $dirs = isset($this->settings['scan_directories']) 
            ? $this->settings['scan_directories'] 
            : $default_dirs;
        
        return array_unique($dirs);
    }
    
    /**
     * Ottiene il percorso completo di una directory
     */
    private function get_full_path($dir) {
        if (strpos($dir, ABSPATH) === 0) {
            return $dir;
        }
        
        return ABSPATH . ltrim($dir, '/');
    }
}
