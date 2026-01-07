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
            'uncategorized' => array(), // File trovati ma non categorizzati
            'total_files' => 0,
            'total_size' => 0,
            'scanned_dirs' => array(),
        );
        
        try {
            $scan_dirs = $directory ? array($directory) : $this->get_scan_directories();
            
            if (empty($scan_dirs)) {
                return $results;
            }
            
            foreach ($scan_dirs as $dir) {
                $full_path = $this->get_full_path($dir);
                if (!is_dir($full_path) || !is_readable($full_path)) {
                    continue;
                }
                
                $results['scanned_dirs'][] = $dir;
                $this->scan_directory($full_path, $results);
            }
            
            // Trova duplicati (può richiedere tempo, gestisci errori)
            try {
                $results['duplicates'] = $this->find_duplicates($results);
            } catch (Exception $e) {
                error_log('[FP-HOSTING-CLEANER] Errore durante ricerca duplicati: ' . $e->getMessage());
                // Continua anche se la ricerca duplicati fallisce
            }
            
        } catch (Exception $e) {
            error_log('[FP-HOSTING-CLEANER] Errore durante scansione: ' . $e->getMessage());
            throw $e;
        }
        
        return $results;
    }
    
    /**
     * Scansiona una directory ricorsivamente
     */
    private function scan_directory($path, &$results, $depth = 0) {
        if (!is_readable($path) || !is_dir($path)) {
            return;
        }
        
        // Limita profondità ricorsione per evitare stack overflow
        if ($depth > 20) {
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
            
            // Controlla se è escluso (ma NON controllare protezione qui - lo facciamo dopo)
            if ($this->is_excluded($full_path)) {
                continue;
            }
            
            if (is_dir($full_path)) {
                // Controlla protezione directory solo per directory vuote
                $this->scan_directory($full_path, $results, $depth + 1);
                
                // Controlla se la directory è vuota (ma non protetta)
                if ($this->is_empty_directory($full_path) && !$this->protection->is_protected_directory($full_path)) {
                    $results['empty_dirs'][] = $full_path;
                }
            } else {
                // Limita numero file scansionati per evitare timeout
                if ($results['total_files'] > 100000) {
                    return;
                }
                
                // Controlla protezione SOLO per file critici, non per tutti
                // Questo permette di categorizzare anche file che potrebbero essere puliti
                if ($this->protection->is_protected($full_path)) {
                    // Salta solo file veramente critici (WordPress core, plugin attivi, temi attivi)
                    // Ma categorizza file che potrebbero essere puliti (cache, backup vecchi, ecc.)
                    $is_critical = $this->is_critical_protected($full_path);
                    if ($is_critical) {
                        continue;
                    }
                }
                
                $results['total_files']++;
                $file_size = @filesize($full_path);
                if ($file_size === false) {
                    $file_size = 0;
                }
                $results['total_size'] += $file_size;
                
                // Categorizza il file (anche se protetto, per vedere cosa c'è)
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
        
        $categorized = false;
        
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
            $categorized = true;
        }
        
        // File di cache
        if (strpos($file_path, '/cache/') !== false || 
            strpos($file_path, '/w3tc/') !== false ||
            strpos($file_path, '/wp-rocket/') !== false ||
            strpos($file_path, '/litespeed/') !== false ||
            strpos($file_path, '/cache/') !== false ||
            strpos($file_path, '\\cache\\') !== false ||
            $file_ext === 'cache') {
            // Aggiungi file cache anche se recenti (possono essere puliti)
            $results['cache_files'][] = array(
                'path' => $file_path,
                'size' => $file_size,
                'age' => $file_age,
                'modified' => filemtime($file_path),
            );
            $categorized = true;
        }
        
        // File di backup (ma NON file SQL che potrebbero essere importanti)
        // I file SQL vengono protetti da ProtectionManager, quindi qui li escludiamo dalla scansione
        if (preg_match('/\.sql$/i', $file_name)) {
            // File SQL sono protetti, non scansionarli
            return;
        }
        
        if (strpos($file_path, '/backup') !== false || 
            strpos($file_path, '/backups') !== false ||
            strpos($file_path, '\\backup') !== false ||
            strpos($file_path, '\\backups') !== false ||
            preg_match('/\.(zip|tar|gz|backup)$/i', $file_name)) {
            // Aggiungi backup anche se recenti (possono essere vecchi backup)
            if ($file_age > $min_age) {
                $results['backup_files'][] = array(
                    'path' => $file_path,
                    'size' => $file_size,
                    'age' => $file_age,
                    'modified' => filemtime($file_path),
                );
                $categorized = true;
            }
        }
        
        // File vecchi (oltre la soglia) - solo se non è già stato categorizzato
        if (!$categorized && $file_age > $min_age && $file_size > 0) {
            $results['old_files'][] = array(
                'path' => $file_path,
                'size' => $file_size,
                'age' => $file_age,
                'modified' => filemtime($file_path),
            );
            $categorized = true;
        }
        
        // Se non è stato categorizzato, aggiungilo a uncategorized (solo se vecchio)
        if (!$categorized && $file_age > $min_age && $file_size > 0) {
            $results['uncategorized'][] = array(
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
            'wp-content/backup-db',
            'wp-content/ai1wm-backups',
            'wp-content/backups-dup-pro',
        );
        
        $dirs = isset($this->settings['scan_directories']) 
            ? $this->settings['scan_directories'] 
            : $default_dirs;
        
        // Rimuovi duplicati e normalizza
        $dirs = array_unique($dirs);
        $valid_dirs = array();
        
        foreach ($dirs as $dir) {
            $full_path = $this->get_full_path($dir);
            if (is_dir($full_path) && is_readable($full_path)) {
                $valid_dirs[] = $dir;
            }
        }
        
        return $valid_dirs;
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
    
    /**
     * Verifica se un file è critico e deve essere saltato completamente
     */
    private function is_critical_protected($file_path) {
        // Solo file veramente critici: WordPress core, plugin attivi, temi attivi
        $critical_patterns = array(
            ABSPATH . 'wp-admin',
            ABSPATH . 'wp-includes',
            ABSPATH . 'wp-config.php',
            ABSPATH . '.htaccess',
            ABSPATH . 'index.php',
        );
        
        foreach ($critical_patterns as $pattern) {
            if (strpos($file_path, $pattern) === 0) {
                return true;
            }
        }
        
        // Plugin attivi
        $active_plugins = get_option('active_plugins', array());
        foreach ($active_plugins as $plugin) {
            $plugin_dir = WP_PLUGIN_DIR . '/' . dirname($plugin);
            if (strpos($file_path, $plugin_dir) === 0) {
                return true;
            }
        }
        
        // Tema attivo
        $active_theme = get_stylesheet_directory();
        if (strpos($file_path, $active_theme) === 0) {
            return true;
        }
        
        return false;
    }
}
