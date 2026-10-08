<?php
/**
 * Plugin Name: WP Cross-Site Sync
 * Description: Синхронизация WPCS.by → WPCSrussia.ru
 * Version: 1.0
 * Author: WPCS
 */

if (!defined('ABSPATH')) exit;

define('WPCS_VERSION', '1.0');
define('WPCS_DIR', plugin_dir_path(__FILE__));
define('WPCS_URL', plugin_dir_url(__FILE__));

require_once WPCS_DIR . 'includes/class-collector.php';
require_once WPCS_DIR . 'includes/class-settings.php';
require_once WPCS_DIR . 'includes/class-receiver.php';
require_once WPCS_DIR . 'includes/class-sender.php';
require_once WPCS_DIR . 'includes/class-sync.php';

add_action('plugins_loaded', function() {
    new WPCS_Settings();
    new WPCS();
});

register_activation_hook(__FILE__, function() {
    add_option('wpcs_mode', 'source');
    add_option('wpcs_receiver_url', '');
    add_option('wpcs_receiver_user', '');
    add_option('wpcs_receiver_pass', '');
    add_option('wpcs_excluded_pages', []);
    add_option('wpcs_excluded_options', []);
    add_option('wpcs_excluded_menus', []);
    add_option('wpcs_excluded_meta', []);
    add_option('wpcs_excluded_acf_groups', []);
    add_option('wpcs_excluded_terms', []);
    add_option('wpcs_last_run', '');
    add_option('wpcs_log', []);
    add_option('wpcs_last_received', '');
    add_option('wpcs_receive_log', []);
    add_option('wpcs_secret_key', wp_generate_password(32, false));
});

// Очистка cron при деактивации
register_deactivation_hook(__FILE__, function() {
    $timestamp = wp_next_scheduled('wpcs_cron_hook');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'wpcs_cron_hook');
    }
});