<?php

namespace FP\HostingCleaner;

defined('ABSPATH') || exit;

/**
 * Classe per gestire i backup prima dell'eliminazione
 */
class BackupManager {
    
    private static $instance = null;
    private $backup_dir = null;
    
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
        $this->backup_dir = WP_CONTENT_DIR . '/fp-hosting-cleaner-backups/';
        $this->ensure_backup_dir();
    }
    
    /**
     * Assicura che la directory di backup esista
     */
    private function ensure_backup_dir() {
        if (!is_dir($this->backup_dir)) {
            wp_mkdir_p($this->backup_dir);
            
            // Proteggi la directory con .htaccess
            $htaccess_content = "deny from all\n";
            file_put_contents($this->backup_dir . '.htaccess', $htaccess_content);
            file_put_contents($this->backup_dir . 'index.php', '<?php // Silence is golden.');
        }
    }
    
    /**
     * Crea un backup di un file prima dell'eliminazione
     */
    public function backup_file($file_path) {
        if (!file_exists($file_path) || !is_file($file_path)) {
            return false;
        }
        
        // Verifica che il file sia dentro ABSPATH
        $realpath = realpath($file_path);
        $abspath = realpath(ABSPATH);
        
        if (!$realpath || strpos($realpath, $abspath) !== 0) {
            return false;
        }
        
        // Crea percorso relativo per il backup
        $relative_path = str_replace($abspath, '', $realpath);
        $backup_path = $this->backup_dir . date('Y-m-d_H-i-s') . '_' . md5($relative_path) . '_' . basename($file_path);
        
        // Crea directory se necessario
        $backup_dir = dirname($backup_path);
        if (!is_dir($backup_dir)) {
            wp_mkdir_p($backup_dir);
        }
        
        // Copia il file
        if (@copy($file_path, $backup_path)) {
            // Salva metadati
            $metadata = array(
                'original_path' => $file_path,
                'relative_path' => $relative_path,
                'backup_path' => $backup_path,
                'backup_date' => current_time('mysql'),
                'file_size' => filesize($file_path),
                'file_mtime' => filemtime($file_path),
            );
            
            file_put_contents($backup_path . '.meta', json_encode($metadata));
            
            return $backup_path;
        }
        
        return false;
    }
    
    /**
     * Crea backup di più file
     */
    public function backup_files($files) {
        $backed_up = array();
        $failed = array();
        
        foreach ($files as $file_info) {
            $file_path = is_array($file_info) ? $file_info['path'] : $file_info;
            
            $backup_path = $this->backup_file($file_path);
            if ($backup_path) {
                $backed_up[] = array(
                    'original' => $file_path,
                    'backup' => $backup_path,
                );
            } else {
                $failed[] = $file_path;
            }
        }
        
        return array(
            'backed_up' => $backed_up,
            'failed' => $failed,
        );
    }
    
    /**
     * Ripristina un file dal backup
     */
    public function restore_file($backup_path) {
        if (!file_exists($backup_path)) {
            return false;
        }
        
        $metadata_path = $backup_path . '.meta';
        if (!file_exists($metadata_path)) {
            return false;
        }
        
        $metadata = json_decode(file_get_contents($metadata_path), true);
        if (!$metadata || !isset($metadata['original_path'])) {
            return false;
        }
        
        $original_path = $metadata['original_path'];
        $original_dir = dirname($original_path);
        
        // Crea directory se necessario
        if (!is_dir($original_dir)) {
            wp_mkdir_p($original_dir);
        }
        
        // Ripristina il file
        if (@copy($backup_path, $original_path)) {
            // Ripristina anche la data di modifica se possibile
            if (isset($metadata['file_mtime'])) {
                @touch($original_path, $metadata['file_mtime']);
            }
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Ottiene la lista dei backup disponibili
     */
    public function get_backups($limit = 50) {
        $backups = array();
        
        if (!is_dir($this->backup_dir)) {
            return $backups;
        }
        
        $files = glob($this->backup_dir . '*.meta');
        if (!$files) {
            return $backups;
        }
        
        // Ordina per data (più recenti prima)
        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        
        $count = 0;
        foreach ($files as $meta_file) {
            if ($count >= $limit) {
                break;
            }
            
            $metadata = json_decode(file_get_contents($meta_file), true);
            if ($metadata) {
                $backup_file = str_replace('.meta', '', $meta_file);
                if (file_exists($backup_file)) {
                    $backups[] = array_merge($metadata, array(
                        'backup_file' => $backup_file,
                        'backup_size' => filesize($backup_file),
                    ));
                    $count++;
                }
            }
        }
        
        return $backups;
    }
    
    /**
     * Pulisce backup vecchi (oltre X giorni)
     */
    public function clean_old_backups($days = 30) {
        $cutoff = time() - ($days * DAY_IN_SECONDS);
        $deleted = 0;
        
        $files = glob($this->backup_dir . '*');
        if (!$files) {
            return $deleted;
        }
        
        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $cutoff) {
                if (@unlink($file)) {
                    $deleted++;
                }
            }
        }
        
        return $deleted;
    }
    
    /**
     * Ottiene la dimensione totale dei backup
     */
    public function get_backup_size() {
        $total_size = 0;
        
        if (!is_dir($this->backup_dir)) {
            return $total_size;
        }
        
        $files = glob($this->backup_dir . '*');
        if (!$files) {
            return $total_size;
        }
        
        foreach ($files as $file) {
            if (is_file($file) && !strpos($file, '.meta')) {
                $total_size += filesize($file);
            }
        }
        
        return $total_size;
    }
}
