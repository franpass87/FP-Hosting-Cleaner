<?php

namespace FP\HostingCleaner;

defined('ABSPATH') || exit;

/**
 * Classe per l'interfaccia admin
 */
class Admin {
    
    private static $instance = null;
    private $scanner = null;
    private $cleaner = null;
    
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
        $this->scanner = Scanner::get_instance();
        $this->cleaner = Cleaner::get_instance();
        
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_fp_hosting_cleaner_scan', array($this, 'ajax_scan'));
        add_action('wp_ajax_fp_hosting_cleaner_clean', array($this, 'ajax_clean'));
        add_action('wp_ajax_fp_hosting_cleaner_dry_run', array($this, 'ajax_dry_run'));
    }
    
    /**
     * Aggiunge il menu admin
     */
    public function add_admin_menu() {
        add_management_page(
            __('FP Hosting Cleaner', 'fp-hosting-cleaner'),
            __('Hosting Cleaner', 'fp-hosting-cleaner'),
            'manage_options',
            'fp-hosting-cleaner',
            array($this, 'render_page')
        );
    }
    
    /**
     * Carica script e stili
     */
    public function enqueue_scripts($hook) {
        if ($hook !== 'tools_page_fp-hosting-cleaner') {
            return;
        }
        
        wp_enqueue_script(
            'fp-hosting-cleaner-admin',
            FP_HOSTING_CLEANER_PLUGIN_URL . 'assets/admin.js',
            array('jquery'),
            FP_HOSTING_CLEANER_VERSION,
            true
        );
        
        wp_localize_script('fp-hosting-cleaner-admin', 'fpHostingCleaner', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('fp_hosting_cleaner_nonce'),
            'strings' => array(
                'scanning' => __('Scansione in corso...', 'fp-hosting-cleaner'),
                'cleaning' => __('Pulizia in corso...', 'fp-hosting-cleaner'),
                'success' => __('Operazione completata con successo!', 'fp-hosting-cleaner'),
                'error' => __('Si è verificato un errore.', 'fp-hosting-cleaner'),
            ),
        ));
        
        wp_add_inline_style('admin-bar', '
            .fp-hosting-cleaner-page { padding: 20px; }
            .fp-scan-results { margin-top: 20px; }
            .fp-file-list { max-height: 400px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #f9f9f9; }
            .fp-file-item { padding: 5px; border-bottom: 1px solid #eee; }
            .fp-file-size { color: #666; font-size: 0.9em; }
            .fp-clean-button { margin-top: 10px; }
        ');
    }
    
    /**
     * Renderizza la pagina admin
     */
    public function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Non hai i permessi per accedere a questa pagina.', 'fp-hosting-cleaner'));
        }
        
        // Gestisci salvataggio impostazioni
        if (isset($_POST['save_settings']) && check_admin_referer('fp_hosting_cleaner_settings')) {
            $this->save_settings();
        }
        
        $settings = get_option('fp_hosting_cleaner_settings', array());
        ?>
        <div class="wrap fp-hosting-cleaner-page">
            <h1><?php echo esc_html__('FP Hosting Cleaner', 'fp-hosting-cleaner'); ?></h1>
            
            <div class="card">
                <h2><?php echo esc_html__('Scansione File', 'fp-hosting-cleaner'); ?></h2>
                <p><?php echo esc_html__('Clicca il pulsante per scansionare l\'hosting e trovare file ridondanti, duplicati, cache obsolete e file temporanei.', 'fp-hosting-cleaner'); ?></p>
                <button type="button" class="button button-primary" id="fp-scan-button">
                    <?php echo esc_html__('Avvia Scansione', 'fp-hosting-cleaner'); ?>
                </button>
                <div id="fp-scan-results" class="fp-scan-results" style="display:none;"></div>
            </div>
            
            <div class="card" style="margin-top: 20px; background: #fff3cd; border-left: 4px solid #ffc107;">
                <h2 style="color: #856404;">🛡️ <?php echo esc_html__('Modalità Sicura Attiva', 'fp-hosting-cleaner'); ?></h2>
                <p><strong><?php echo esc_html__('Il plugin opera in modalità ultra-sicura:', 'fp-hosting-cleaner'); ?></strong></p>
                <ul style="margin-left: 20px;">
                    <li>✅ <strong>Backup automatico</strong>: Tutti i file vengono salvati prima dell'eliminazione</li>
                    <li>✅ <strong>Protezione multi-livello</strong>: File WordPress, plugin, temi e uploads recenti sono sempre protetti</li>
                    <li>✅ <strong>Doppia conferma</strong>: Devi confermare esplicitamente ogni operazione di pulizia</li>
                    <li>✅ <strong>Visualizzazione preventiva</strong>: Vedi esattamente cosa verrà eliminato prima di procedere</li>
                    <li>✅ <strong>Possibilità di ripristino</strong>: I file eliminati possono essere ripristinati dai backup</li>
                </ul>
                <p style="margin-top: 10px;"><em><?php echo esc_html__('I backup vengono salvati in:', 'fp-hosting-cleaner'); ?> <code>wp-content/fp-hosting-cleaner-backups/</code></em></p>
            </div>
            
            <div class="card" style="margin-top: 20px;">
                <h2><?php echo esc_html__('Sicurezza e Protezioni', 'fp-hosting-cleaner'); ?></h2>
                <div class="notice notice-info">
                    <p><strong><?php echo esc_html__('File Protetti Automaticamente:', 'fp-hosting-cleaner'); ?></strong></p>
                    <ul style="margin-left: 20px;">
                        <li><?php echo esc_html__('Tutti i file WordPress Core (wp-admin, wp-includes, wp-config.php, ecc.)', 'fp-hosting-cleaner'); ?></li>
                        <li><?php echo esc_html__('Tutti i plugin attivi', 'fp-hosting-cleaner'); ?></li>
                        <li><?php echo esc_html__('Tutti i temi attivi', 'fp-hosting-cleaner'); ?></li>
                        <li><?php echo esc_html__('File di upload modificati negli ultimi 30 giorni', 'fp-hosting-cleaner'); ?></li>
                        <li><?php echo esc_html__('File di configurazione (.htaccess, wp-config.php, ecc.)', 'fp-hosting-cleaner'); ?></li>
                        <li><?php echo esc_html__('File di database (.sql)', 'fp-hosting-cleaner'); ?></li>
                        <li><?php echo esc_html__('File di sistema e sicurezza', 'fp-hosting-cleaner'); ?></li>
                    </ul>
                    <p><?php echo esc_html__('Il plugin include un sistema completo di protezione che impedisce l\'eliminazione di file necessari per il funzionamento del sito.', 'fp-hosting-cleaner'); ?></p>
                </div>
            </div>
            
            <div class="card" style="margin-top: 20px;">
                <h2><?php echo esc_html__('Impostazioni', 'fp-hosting-cleaner'); ?></h2>
                <form method="post" action="">
                    <?php wp_nonce_field('fp_hosting_cleaner_settings'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label><?php echo esc_html__('Giorni minimi per file vecchi', 'fp-hosting-cleaner'); ?></label>
                            </th>
                            <td>
                                <input type="number" name="min_file_age_days" 
                                       value="<?php echo esc_attr(isset($settings['min_file_age_days']) ? $settings['min_file_age_days'] : 30); ?>" 
                                       min="1" />
                                <p class="description"><?php echo esc_html__('I file più vecchi di questo numero di giorni verranno considerati per la pulizia.', 'fp-hosting-cleaner'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label><?php echo esc_html__('Dimensione massima per controllo duplicati (MB)', 'fp-hosting-cleaner'); ?></label>
                            </th>
                            <td>
                                <input type="number" name="max_duplicate_size_mb" 
                                       value="<?php echo esc_attr(isset($settings['max_duplicate_size_mb']) ? $settings['max_duplicate_size_mb'] : 10); ?>" 
                                       min="1" />
                                <p class="description"><?php echo esc_html__('I file più grandi di questa dimensione non verranno controllati per duplicati (per performance).', 'fp-hosting-cleaner'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label>
                                    <input type="checkbox" name="enable_backup" value="1" 
                                           <?php checked(isset($settings['enable_backup']) ? $settings['enable_backup'] : true, true); ?> />
                                    <?php echo esc_html__('Crea backup automatico prima dell\'eliminazione', 'fp-hosting-cleaner'); ?>
                                </label>
                            </th>
                            <td>
                                <p class="description"><?php echo esc_html__('Se attivato, tutti i file verranno salvati in backup prima di essere eliminati. Consigliato: ON', 'fp-hosting-cleaner'); ?></p>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <input type="submit" name="save_settings" class="button button-primary" 
                               value="<?php echo esc_attr__('Salva Impostazioni', 'fp-hosting-cleaner'); ?>" />
                    </p>
                </form>
            </div>
        </div>
        <?php
    }
    
    /**
     * Salva le impostazioni
     */
    private function save_settings() {
        $settings = get_option('fp_hosting_cleaner_settings', array());
        
        if (isset($_POST['min_file_age_days'])) {
            $settings['min_file_age_days'] = intval($_POST['min_file_age_days']);
        }
        
        if (isset($_POST['max_duplicate_size_mb'])) {
            $settings['max_duplicate_size_mb'] = intval($_POST['max_duplicate_size_mb']);
        }
        
        $settings['enable_backup'] = isset($_POST['enable_backup']) ? true : false;
        
        update_option('fp_hosting_cleaner_settings', $settings);
        
        echo '<div class="notice notice-success"><p>' . esc_html__('Impostazioni salvate.', 'fp-hosting-cleaner') . '</p></div>';
    }
    
    /**
     * AJAX: Scansione
     */
    public function ajax_scan() {
        check_ajax_referer('fp_hosting_cleaner_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permessi insufficienti.', 'fp-hosting-cleaner')));
        }
        
        // Esegui scansione in background (può richiedere tempo)
        set_time_limit(300); // 5 minuti
        
        $results = $this->scanner->scan();
        
        // Formatta i risultati per la visualizzazione
        $formatted = $this->format_scan_results($results);
        
        wp_send_json_success($formatted);
    }
    
    /**
     * AJAX: Dry Run (solo simulazione)
     */
    public function ajax_dry_run() {
        check_ajax_referer('fp_hosting_cleaner_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permessi insufficienti.', 'fp-hosting-cleaner')));
        }
        
        $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : '';
        $files = isset($_POST['files']) ? json_decode(stripslashes($_POST['files']), true) : array();
        
        if (empty($type) || empty($files)) {
            wp_send_json_error(array('message' => __('Parametri mancanti.', 'fp-hosting-cleaner')));
        }
        
        set_time_limit(300);
        
        if ($type === 'duplicates') {
            $results = $this->cleaner->clean_duplicates($files, true, false);
        } elseif ($type === 'empty_dirs') {
            $results = $this->cleaner->clean_empty_directories($files, true);
        } else {
            $results = $this->cleaner->clean($type, $files, true, false);
        }
        
        wp_send_json_success($results);
    }
    
    /**
     * AJAX: Pulizia
     */
    public function ajax_clean() {
        check_ajax_referer('fp_hosting_cleaner_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permessi insufficienti.', 'fp-hosting-cleaner')));
        }
        
        $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : '';
        $files = isset($_POST['files']) ? json_decode(stripslashes($_POST['files']), true) : array();
        $dry_run = isset($_POST['dry_run']) && $_POST['dry_run'] === 'true';
        
        if (empty($type) || empty($files)) {
            wp_send_json_error(array('message' => __('Parametri mancanti.', 'fp-hosting-cleaner')));
        }
        
        set_time_limit(300);
        
        $settings = get_option('fp_hosting_cleaner_settings', array());
        $create_backup = isset($settings['enable_backup']) ? $settings['enable_backup'] : true;
        
        if ($type === 'duplicates') {
            $results = $this->cleaner->clean_duplicates($files, $dry_run, $create_backup);
        } elseif ($type === 'empty_dirs') {
            $results = $this->cleaner->clean_empty_directories($files, $dry_run);
        } else {
            $results = $this->cleaner->clean($type, $files, $dry_run, $create_backup);
        }
        
        wp_send_json_success($results);
    }
    
    /**
     * Formatta i risultati della scansione
     */
    private function format_scan_results($results) {
        $formatted = array(
            'summary' => array(
                'total_files' => $results['total_files'],
                'total_size' => $this->format_bytes($results['total_size']),
                'scanned_dirs' => count($results['scanned_dirs']),
            ),
            'categories' => array(),
        );
        
        $categories = array(
            'duplicates' => __('Duplicati', 'fp-hosting-cleaner'),
            'temp_files' => __('File Temporanei', 'fp-hosting-cleaner'),
            'cache_files' => __('File Cache', 'fp-hosting-cleaner'),
            'backup_files' => __('File Backup', 'fp-hosting-cleaner'),
            'old_files' => __('File Vecchi', 'fp-hosting-cleaner'),
            'empty_dirs' => __('Directory Vuote', 'fp-hosting-cleaner'),
        );
        
        foreach ($categories as $key => $label) {
            $items = isset($results[$key]) ? $results[$key] : array();
            $count = is_array($items) ? count($items) : 0;
            
            if ($key === 'duplicates') {
                $total_size = 0;
                foreach ($items as $dup) {
                    $total_size += isset($dup['total_size']) ? $dup['total_size'] : 0;
                }
            } else {
                $total_size = array_sum(array_column($items, 'size'));
            }
            
            $formatted['categories'][$key] = array(
                'label' => $label,
                'count' => $count,
                'size' => $this->format_bytes($total_size),
                'items' => array_slice($items, 0, 100), // Limita a 100 per performance
            );
        }
        
        return $formatted;
    }
    
    /**
     * Formatta bytes in formato leggibile
     */
    private function format_bytes($bytes, $precision = 2) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
