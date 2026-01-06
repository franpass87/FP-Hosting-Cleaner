<?php
/**
 * Plugin Name: FP Hosting Cleaner
 * Plugin URI: https://www.francescopasseri.com
 * Description: Plugin per pulire file ridondanti, duplicati, cache obsolete e file temporanei dall'hosting WordPress. Aiuta a liberare spazio quando si raggiungono i limiti di file.
 * Version: 1.0.0
 * Author: Francesco Passeri
 * Author URI: https://www.francescopasseri.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: fp-hosting-cleaner
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

// Previeni accesso diretto
if (!defined('ABSPATH')) {
    exit;
}

// Definisci costanti del plugin
define('FP_HOSTING_CLEANER_VERSION', '1.0.0');
define('FP_HOSTING_CLEANER_PLUGIN_FILE', __FILE__);

// Calcola il percorso del plugin (supporto junction)
$plugin_dir = dirname(__FILE__);
$real_plugin_dir = realpath($plugin_dir);
if ($real_plugin_dir !== false) {
    $plugin_dir = $real_plugin_dir;
}
define('FP_HOSTING_CLEANER_PLUGIN_DIR', $plugin_dir . DIRECTORY_SEPARATOR);

// Usa plugin_dir_url() se disponibile, altrimenti calcola manualmente
if (function_exists('plugin_dir_url')) {
    define('FP_HOSTING_CLEANER_PLUGIN_URL', plugin_dir_url(__FILE__));
} else {
    $plugin_url = str_replace(ABSPATH, site_url('/'), $plugin_dir);
    define('FP_HOSTING_CLEANER_PLUGIN_URL', $plugin_url . '/');
}

if (function_exists('plugin_basename')) {
    define('FP_HOSTING_CLEANER_PLUGIN_BASENAME', plugin_basename(__FILE__));
} else {
    define('FP_HOSTING_CLEANER_PLUGIN_BASENAME', basename(dirname(__FILE__)) . '/' . basename(__FILE__));
}

// Carica Composer autoload (Best Practice PSR-4)
// Usa il percorso già risolto con realpath (supporto junction)
$autoload_path = FP_HOSTING_CLEANER_PLUGIN_DIR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

// Normalizza il percorso (rimuove doppio separatore)
$autoload_path = str_replace(DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR, $autoload_path);

if (file_exists($autoload_path)) {
    require_once $autoload_path;
} else {
    // Mostra errore dettagliato
    add_action('admin_notices', function() {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        $plugin_dir = dirname(FP_HOSTING_CLEANER_PLUGIN_FILE);
        $expected_path = $plugin_dir . '/vendor/autoload.php';
        $real_plugin_dir = realpath($plugin_dir);
        
        echo '<div class="notice notice-error"><p>';
        echo '<strong>' . esc_html__('FP Hosting Cleaner:', 'fp-hosting-cleaner') . '</strong> ';
        echo esc_html__('Autoloader non trovato. Esegui', 'fp-hosting-cleaner') . ' <code>composer install</code> ';
        echo esc_html__('nella cartella del plugin.', 'fp-hosting-cleaner');
        echo '<br><small><strong>Percorso atteso:</strong> ' . esc_html($expected_path) . '</small>';
        if ($real_plugin_dir) {
            echo '<br><small><strong>Percorso reale (junction):</strong> ' . esc_html($real_plugin_dir . '/vendor/autoload.php') . '</small>';
        }
        echo '</p></div>';
    });
    return;
}

// Usa i namespace delle classi
use FP\HostingCleaner\Plugin;
use FP\HostingCleaner\Admin;

/**
 * Inizializza il plugin
 */
function fp_hosting_cleaner_init() {
    if (!defined('ABSPATH')) {
        return false;
    }
    
    try {
        return Plugin::get_instance();
    } catch (Exception $e) {
        error_log("[FP-HOSTING-CLEANER] Errore fatale durante l'inizializzazione: " . $e->getMessage());
        return false;
    }
}

// Avvia il plugin
add_action('plugins_loaded', 'fp_hosting_cleaner_init', 10);
