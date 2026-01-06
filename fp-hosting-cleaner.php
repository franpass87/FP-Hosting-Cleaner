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
define('FP_HOSTING_CLEANER_PLUGIN_DIR', dirname(__FILE__) . '/');
define('FP_HOSTING_CLEANER_PLUGIN_URL', plugin_dir_url(__FILE__));
define('FP_HOSTING_CLEANER_PLUGIN_FILE', __FILE__);
define('FP_HOSTING_CLEANER_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Carica Composer autoload (Best Practice PSR-4)
if (file_exists(FP_HOSTING_CLEANER_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once FP_HOSTING_CLEANER_PLUGIN_DIR . 'vendor/autoload.php';
} else {
    add_action('admin_notices', function() {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        echo '<div class="notice notice-error"><p>';
        echo '<strong>' . esc_html__('FP Hosting Cleaner:', 'fp-hosting-cleaner') . '</strong> ';
        echo esc_html__('Esegui', 'fp-hosting-cleaner') . ' <code>composer install</code> ';
        echo esc_html__('nella cartella del plugin.', 'fp-hosting-cleaner');
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
