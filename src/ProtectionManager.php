<?php

namespace FP\HostingCleaner;

defined('ABSPATH') || exit;

/**
 * Classe per gestire la protezione dei file critici e necessari
 */
class ProtectionManager {
    
    private static $instance = null;
    private $protected_paths = null;
    private $protected_patterns = null;
    
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
        $this->init_protected_paths();
        $this->init_protected_patterns();
    }
    
    /**
     * Inizializza i percorsi protetti
     */
    private function init_protected_paths() {
        $this->protected_paths = array();
        
        // WordPress Core - MAI toccare
        $this->protected_paths[] = ABSPATH . 'wp-admin/';
        $this->protected_paths[] = ABSPATH . 'wp-includes/';
        $this->protected_paths[] = ABSPATH . 'wp-config.php';
        $this->protected_paths[] = ABSPATH . '.htaccess';
        $this->protected_paths[] = ABSPATH . 'index.php';
        $this->protected_paths[] = ABSPATH . 'wp-load.php';
        $this->protected_paths[] = ABSPATH . 'wp-blog-header.php';
        $this->protected_paths[] = ABSPATH . 'wp-settings.php';
        $this->protected_paths[] = ABSPATH . 'wp-activate.php';
        $this->protected_paths[] = ABSPATH . 'wp-signup.php';
        $this->protected_paths[] = ABSPATH . 'wp-cron.php';
        $this->protected_paths[] = ABSPATH . 'xmlrpc.php';
        $this->protected_paths[] = ABSPATH . 'license.txt';
        $this->protected_paths[] = ABSPATH . 'readme.html';
        
        // File di sistema WordPress
        $this->protected_paths[] = WP_CONTENT_DIR . '/themes/';
        $this->protected_paths[] = WP_CONTENT_DIR . '/plugins/';
        $this->protected_paths[] = WP_CONTENT_DIR . '/mu-plugins/';
        $this->protected_paths[] = WP_CONTENT_DIR . '/languages/';
        
        // Plugin attivi - MAI toccare
        $active_plugins = get_option('active_plugins', array());
        foreach ($active_plugins as $plugin) {
            $plugin_dir = WP_PLUGIN_DIR . '/' . dirname($plugin) . '/';
            $this->protected_paths[] = $plugin_dir;
        }
        
        // Tema attivo - MAI toccare
        $active_theme = get_stylesheet_directory();
        $this->protected_paths[] = $active_theme . '/';
        
        // Tema parent (se presente)
        $parent_theme = get_template_directory();
        if ($parent_theme !== $active_theme) {
            $this->protected_paths[] = $parent_theme . '/';
        }
        
        // Uploads recenti (ultimi 30 giorni) - protezione extra
        $uploads_dir = wp_upload_dir();
        if (isset($uploads_dir['basedir'])) {
            // Non proteggiamo tutta la cartella uploads, ma i file recenti vengono esclusi dalla scansione
        }
        
        // File di database
        $this->protected_paths[] = ABSPATH . 'wp-content/db.php';
        
        // File di debug e log importanti
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            $this->protected_paths[] = WP_CONTENT_DIR . '/debug.log';
        }
        
        // Aggiungi whitelist personalizzata dalle impostazioni
        $settings = get_option('fp_hosting_cleaner_settings', array());
        if (isset($settings['protected_paths']) && is_array($settings['protected_paths'])) {
            foreach ($settings['protected_paths'] as $path) {
                $full_path = $this->normalize_path($path);
                if ($full_path) {
                    $this->protected_paths[] = $full_path;
                }
            }
        }
    }
    
    /**
     * Inizializza i pattern protetti
     */
    private function init_protected_patterns() {
        $this->protected_patterns = array(
            // File di configurazione
            '/wp-config\.php$/i',
            '/\.htaccess$/i',
            '/wp-config-sample\.php$/i',
            '/wp-config-backup\.php$/i',
            
            // File WordPress core
            '/^wp-(admin|includes|content|load|blog-header|settings|activate|signup|cron)\.php$/i',
            '/^xmlrpc\.php$/i',
            '/^license\.txt$/i',
            '/^readme\.html$/i',
            
            // File di sistema
            '/^index\.php$/i',
            '/^\.htaccess$/i',
            '/^web\.config$/i',
            '/^robots\.txt$/i',
            '/^sitemap.*\.xml$/i',
            
            // File di database
            '/\.sql$/i', // Proteggiamo i file SQL (potrebbero essere backup importanti)
            
            // File di log importanti
            '/^error_log$/i',
            '/^debug\.log$/i',
            '/^\.gitignore$/i',
            '/^\.gitkeep$/i',
            
            // File di autenticazione
            '/^\.well-known\//i',
            
            // File di sicurezza
            '/^\.user\.ini$/i',
            '/^php\.ini$/i',
        );
        
        // Aggiungi pattern personalizzati dalle impostazioni
        $settings = get_option('fp_hosting_cleaner_settings', array());
        if (isset($settings['protected_patterns']) && is_array($settings['protected_patterns'])) {
            $this->protected_patterns = array_merge($this->protected_patterns, $settings['protected_patterns']);
        }
    }
    
    /**
     * Verifica se un file è protetto e non può essere eliminato
     */
    public function is_protected($file_path) {
        // Normalizza il percorso
        $normalized_path = $this->normalize_path($file_path);
        if (!$normalized_path) {
            return true; // Se non possiamo normalizzare, proteggiamo per sicurezza
        }
        
        // Verifica che sia dentro ABSPATH
        $abspath = realpath(ABSPATH);
        $file_realpath = realpath($normalized_path);
        
        if (!$file_realpath || strpos($file_realpath, $abspath) !== 0) {
            return true; // Fuori da ABSPATH = protetto
        }
        
        // Verifica percorsi protetti
        foreach ($this->protected_paths as $protected_path) {
            $protected_realpath = realpath($protected_path);
            if ($protected_realpath && (
                $file_realpath === $protected_realpath ||
                strpos($file_realpath, $protected_realpath) === 0
            )) {
                return true;
            }
        }
        
        // Verifica pattern protetti
        $file_name = basename($normalized_path);
        $file_dir = dirname($normalized_path);
        
        foreach ($this->protected_patterns as $pattern) {
            if (preg_match($pattern, $file_name) || preg_match($pattern, $normalized_path)) {
                return true;
            }
        }
        
        // Verifica se è un file di upload recente (ultimi 30 giorni)
        if ($this->is_recent_upload($normalized_path)) {
            return true;
        }
        
        // Verifica se è un file di plugin/tema attivo
        if ($this->is_active_plugin_or_theme_file($normalized_path)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Verifica se un file è un upload recente
     */
    private function is_recent_upload($file_path) {
        $uploads_dir = wp_upload_dir();
        if (!isset($uploads_dir['basedir'])) {
            return false;
        }
        
        $uploads_basedir = realpath($uploads_dir['basedir']);
        $file_realpath = realpath($file_path);
        
        if (!$uploads_basedir || !$file_realpath) {
            return false;
        }
        
        // Verifica se il file è nella cartella uploads
        if (strpos($file_realpath, $uploads_basedir) !== 0) {
            return false;
        }
        
        // Proteggi file modificati negli ultimi 30 giorni
        $file_mtime = filemtime($file_path);
        if ($file_mtime && (time() - $file_mtime) < (30 * DAY_IN_SECONDS)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Verifica se un file appartiene a un plugin o tema attivo
     */
    private function is_active_plugin_or_theme_file($file_path) {
        $file_realpath = realpath($file_path);
        if (!$file_realpath) {
            return false;
        }
        
        // Verifica plugin attivi
        $active_plugins = get_option('active_plugins', array());
        foreach ($active_plugins as $plugin) {
            $plugin_dir = realpath(WP_PLUGIN_DIR . '/' . dirname($plugin));
            if ($plugin_dir && strpos($file_realpath, $plugin_dir) === 0) {
                return true;
            }
        }
        
        // Verifica tema attivo
        $active_theme = realpath(get_stylesheet_directory());
        if ($active_theme && strpos($file_realpath, $active_theme) === 0) {
            return true;
        }
        
        // Verifica tema parent
        $parent_theme = realpath(get_template_directory());
        if ($parent_theme && $parent_theme !== $active_theme && strpos($file_realpath, $parent_theme) === 0) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Normalizza un percorso
     */
    private function normalize_path($path) {
        if (empty($path)) {
            return false;
        }
        
        // Se è un percorso relativo, rendilo assoluto
        if (strpos($path, ABSPATH) !== 0 && !preg_match('/^[A-Z]:\\\\/i', $path)) {
            $path = ABSPATH . ltrim($path, '/\\');
        }
        
        // Rimuovi trailing slash per file, mantieni per directory
        $realpath = realpath($path);
        if ($realpath) {
            return $realpath;
        }
        
        // Se realpath fallisce, prova a normalizzare manualmente
        $path = str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $path);
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        
        return $path;
    }
    
    /**
     * Verifica se una directory è protetta
     */
    public function is_protected_directory($dir_path) {
        $normalized_path = $this->normalize_path($dir_path);
        if (!$normalized_path) {
            return true;
        }
        
        // Verifica percorsi protetti
        foreach ($this->protected_paths as $protected_path) {
            $protected_realpath = realpath($protected_path);
            if ($protected_realpath && (
                $normalized_path === $protected_realpath ||
                strpos($normalized_path, $protected_realpath) === 0 ||
                strpos($protected_realpath, $normalized_path) === 0
            )) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Ottiene la lista dei percorsi protetti (per debug/info)
     */
    public function get_protected_paths() {
        return $this->protected_paths;
    }
    
    /**
     * Ottiene la lista dei pattern protetti (per debug/info)
     */
    public function get_protected_patterns() {
        return $this->protected_patterns;
    }
}
