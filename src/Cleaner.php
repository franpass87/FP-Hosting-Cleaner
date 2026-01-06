<?php

namespace FP\HostingCleaner;

defined('ABSPATH') || exit;

/**
 * Classe per eseguire la pulizia dei file
 */
class Cleaner {
    
    private static $instance = null;
    private $logger = null;
    private $protection = null;
    private $backup = null;
    
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
        $this->logger = Logger::get_instance();
        $this->protection = ProtectionManager::get_instance();
        $this->backup = BackupManager::get_instance();
    }
    
    /**
     * Pulisce file in base al tipo
     * 
     * @param string $type Tipo di pulizia
     * @param array $files Array di file da pulire
     * @param bool $dry_run Se true, non elimina realmente (solo simulazione)
     * @param bool $create_backup Se true, crea backup prima di eliminare
     */
    public function clean($type, $files = array(), $dry_run = false, $create_backup = true) {
        $results = array(
            'deleted' => 0,
            'failed' => 0,
            'total_size' => 0,
            'errors' => array(),
        );
        
        if (empty($files)) {
            return $results;
        }
        
        foreach ($files as $file_info) {
            $file_path = is_array($file_info) ? $file_info['path'] : $file_info;
            $file_size = is_array($file_info) && isset($file_info['size']) ? $file_info['size'] : 0;
            
            if (!file_exists($file_path)) {
                continue;
            }
            
            // Verifica sicurezza: non eliminare file fuori da ABSPATH
            if (strpos(realpath($file_path), realpath(ABSPATH)) !== 0) {
                $results['errors'][] = "Percorso non sicuro: " . $file_path;
                $results['failed']++;
                continue;
            }
            
            // Verifica protezione file (sistema completo di protezione)
            if ($this->protection->is_protected($file_path)) {
                $results['errors'][] = "File protetto non eliminabile: " . $file_path;
                $results['failed']++;
                continue;
            }
            
            // Modalità dry-run: solo simulazione
            if ($dry_run) {
                $results['deleted']++;
                $results['total_size'] += $file_size;
                $results['dry_run_files'][] = $file_path;
                continue;
            }
            
            // Crea backup se richiesto
            $backup_path = null;
            if ($create_backup) {
                $backup_path = $this->backup->backup_file($file_path);
                if (!$backup_path) {
                    $results['errors'][] = "Impossibile creare backup per: " . $file_path;
                    $results['failed']++;
                    continue;
                }
            }
            
            // Elimina il file
            if (@unlink($file_path)) {
                $results['deleted']++;
                $results['total_size'] += $file_size;
                if ($backup_path) {
                    $results['backed_up'][] = array(
                        'original' => $file_path,
                        'backup' => $backup_path,
                    );
                }
                $this->logger->log('info', "File eliminato: {$file_path}", array(
                    'type' => $type,
                    'size' => $file_size,
                    'backup' => $backup_path,
                ));
            } else {
                $results['failed']++;
                $error = error_get_last();
                $results['errors'][] = "Errore eliminazione {$file_path}: " . ($error ? $error['message'] : 'Errore sconosciuto');
                $this->logger->log('error', "Errore eliminazione file: {$file_path}", array(
                    'type' => $type,
                    'error' => $error,
                ));
            }
        }
        
        return $results;
    }
    
    /**
     * Pulisce directory vuote
     * 
     * @param array $directories Array di directory da pulire
     * @param bool $dry_run Se true, non elimina realmente (solo simulazione)
     */
    public function clean_empty_directories($directories = array(), $dry_run = false) {
        $results = array(
            'deleted' => 0,
            'failed' => 0,
            'errors' => array(),
        );
        
        foreach ($directories as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            
            // Verifica sicurezza
            if (strpos(realpath($dir), realpath(ABSPATH)) !== 0) {
                $results['errors'][] = "Directory non sicura: " . $dir;
                $results['failed']++;
                continue;
            }
            
            // Verifica protezione directory
            if ($this->protection->is_protected_directory($dir)) {
                $results['errors'][] = "Directory protetta non eliminabile: " . $dir;
                $results['failed']++;
                continue;
            }
            
            // Verifica che sia vuota
            $items = @scandir($dir);
            if ($items === false || count($items) > 2) {
                continue;
            }
            
            // Modalità dry-run
            if ($dry_run) {
                $results['deleted']++;
                $results['dry_run_dirs'][] = $dir;
                continue;
            }
            
            if (@rmdir($dir)) {
                $results['deleted']++;
                $this->logger->log('info', "Directory vuota eliminata: {$dir}", array(
                    'type' => 'empty_directory',
                ));
            } else {
                $results['failed']++;
                $error = error_get_last();
                $results['errors'][] = "Errore eliminazione directory {$dir}: " . ($error ? $error['message'] : 'Errore sconosciuto');
            }
        }
        
        return $results;
    }
    
    /**
     * Pulisce duplicati (mantiene solo il primo)
     * 
     * @param array $duplicates Array di gruppi di duplicati
     * @param bool $dry_run Se true, non elimina realmente (solo simulazione)
     * @param bool $create_backup Se true, crea backup prima di eliminare
     */
    public function clean_duplicates($duplicates = array(), $dry_run = false, $create_backup = true) {
        $results = array(
            'deleted' => 0,
            'failed' => 0,
            'total_size' => 0,
            'errors' => array(),
        );
        
        foreach ($duplicates as $duplicate_group) {
            if (!isset($duplicate_group['files']) || count($duplicate_group['files']) <= 1) {
                continue;
            }
            
            // Mantieni il primo file, elimina gli altri
            $files = $duplicate_group['files'];
            $first_file = array_shift($files);
            
            foreach ($files as $file_info) {
                $file_path = $file_info['path'];
                $file_size = $file_info['size'];
                
                if (!file_exists($file_path)) {
                    continue;
                }
                
                // Verifica sicurezza
                if (strpos(realpath($file_path), realpath(ABSPATH)) !== 0) {
                    $results['errors'][] = "Percorso non sicuro: " . $file_path;
                    $results['failed']++;
                    continue;
                }
                
                // Verifica protezione file
                if ($this->protection->is_protected($file_path)) {
                    $results['errors'][] = "File protetto non eliminabile: " . $file_path;
                    $results['failed']++;
                    continue;
                }
                
                // Modalità dry-run
                if ($dry_run) {
                    $results['deleted']++;
                    $results['total_size'] += $file_size;
                    $results['dry_run_files'][] = $file_path;
                    continue;
                }
                
                // Crea backup se richiesto
                $backup_path = null;
                if ($create_backup) {
                    $backup_path = $this->backup->backup_file($file_path);
                    if (!$backup_path) {
                        $results['errors'][] = "Impossibile creare backup per: " . $file_path;
                        $results['failed']++;
                        continue;
                    }
                }
                
                if (@unlink($file_path)) {
                    $results['deleted']++;
                    $results['total_size'] += $file_size;
                    if ($backup_path) {
                        $results['backed_up'][] = array(
                            'original' => $file_path,
                            'backup' => $backup_path,
                        );
                    }
                    $this->logger->log('info', "Duplicato eliminato: {$file_path}", array(
                        'type' => 'duplicate',
                        'size' => $file_size,
                        'kept' => $first_file['path'],
                        'backup' => $backup_path,
                    ));
                } else {
                    $results['failed']++;
                    $error = error_get_last();
                    $results['errors'][] = "Errore eliminazione {$file_path}: " . ($error ? $error['message'] : 'Errore sconosciuto');
                }
            }
        }
        
        return $results;
    }
    
}
