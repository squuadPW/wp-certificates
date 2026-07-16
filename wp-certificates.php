<?php
/*
Plugin Name: WP Certificates
Description: The WordPress plugin for certificates, certificates and personalized documents of the institution.
Author: EduSof
Version: 1.0.29
Author URI: https://edusof.com
License: GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: wp-certificates
*/

// Evitar acceso directo
defined('ABSPATH') || exit;

// Constantes del plugin
define('WP_C_PATH', plugin_dir_path(__FILE__));
define('WP_C_REMOTE_INFO_URL', 'https://versions.squuad.com/plugins/wp-certificates/info.json');

// Now you can safely use get_plugin_data()
$plugin_data = get_plugin_data(__FILE__);
define('WP_C_VERSION', $plugin_data['Version']);

// Cargar archivos necesarios
if ( !class_exists('WP_List_Table') ) 
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');

require_once WP_C_PATH . 'public/functions.php';
require_once WP_C_PATH . 'admin/functions.php';
require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

// Include the required file for get_plugin_data()
if ( !function_exists('get_plugin_data') ) 
    require_once ABSPATH . 'wp-admin/includes/plugin.php';

// Sistema de actualizaciones
add_filter('plugins_api', 'wp_c_plugin_info', 20, 3);
add_filter('site_transient_update_plugins', 'wp_c_check_update');

// Obtener información remota con caché
function wp_c_get_remote_info() {
    static $remote_info = null;

    if (null === $remote_info) {
        $remote = wp_remote_get(WP_C_REMOTE_INFO_URL, [
            'timeout' => 10,
            'headers' => ['Accept' => 'application/json']
        ]);

        if (
            !is_wp_error($remote) &&
            200 === wp_remote_retrieve_response_code($remote) &&
            !empty($body = wp_remote_retrieve_body($remote))
        ) {
            $remote_info = json_decode($body);
        }
    }

    return $remote_info;
}

// Proporcionar información del plugin
function wp_c_plugin_info($res, $action, $args) {
    if (
        'plugin_information' !== $action ||
        plugin_basename(__DIR__) !== $args->slug
    ) {
        return $res;
    }

    if ($remote = wp_c_get_remote_info()) {
        $res = new stdClass();
        $res->name = $remote->name;
        $res->slug = $remote->slug;
        $res->author = $remote->author;
        $res->author_profile = $remote->author_profile;
        $res->version = $remote->version;
        $res->tested = $remote->tested;
        $res->requires = $remote->requires;
        $res->requires_php = $remote->requires_php;
        $res->download_link = $remote->download_url;
        $res->trunk = $remote->download_url;
        $res->last_updated = $remote->last_updated;

        $res->sections = [
            'description' => $remote->sections->description,
            'installation' => $remote->sections->installation,
            'changelog' => $remote->sections->changelog
        ];

        if (!empty($remote->sections->screenshots)) {
            $res->sections['screenshots'] = $remote->sections->screenshots;
        }

        $res->banners = [
            'low' => $remote->banners->low,
            'high' => $remote->banners->high
        ];
    }

    return $res;
}

// Verificar actualizaciones
function wp_c_check_update($transient) {

    if (empty($transient->checked)) return $transient;

    if ($remote = wp_c_get_remote_info()) {
        $plugin_file = plugin_basename(__FILE__);
        $current_version = WP_C_VERSION;

        if (
            version_compare($current_version, $remote->version, '<') &&
            version_compare($remote->requires, get_bloginfo('version'), '<') &&
            version_compare($remote->requires_php, PHP_VERSION, '<')
        ) {

            $update = new stdClass();
            $update->slug = $remote->slug;
            $update->plugin = $plugin_file;
            $update->new_version = $remote->version;
            $update->tested = $remote->tested;
            $update->package = $remote->download_url;

            $transient->response[$update->plugin] = $update;
        }
    }

    return $transient;
}

function create_tables_certificates() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    // Definición de las tablas.
    $table_documents_certificates = $wpdb->prefix . 'documents_certificates';
    $table_variables_document = $wpdb->prefix . 'variables_document';
    $table_users_signatures_certificate = $wpdb->prefix . 'users_signatures_certificate';
    $table_certificates = $wpdb->prefix . 'certificates';
    $table_certificates_templates = $wpdb->prefix . 'certificates_templates';
    $table_cards_templates = $wpdb->prefix . 'cards_templates';

    // dbDelta se encargará de crear la tabla si no existe o de
    // agregar/modificar columnas si ya existe.
    dbDelta( "CREATE TABLE {$table_documents_certificates} (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `title` text NOT NULL,
        `document_identificator` text NULL,
        `header` text NOT NULL,
        `content` text NOT NULL,
        `footer` text NOT NULL,
        `status` int(11) NOT NULL,
        `signature_required` int(11) NOT NULL,
        `graduated_required` int(11) NOT NULL,
        `margin_required` int(11) NOT NULL DEFAULT 0,
        `orientation` VARCHAR(20) NOT NULL DEFAULT 'portrait',
        `type` VARCHAR(255) NOT NULL DEFAULT 'managed',
        `id_requisito` VARCHAR(255) NULL,
        `type_file` VARCHAR(255) NULL,
        `width_size` DOUBLE(10, 2) NOT NULL DEFAULT 210,
        `height_size` DOUBLE(10, 2) NOT NULL DEFAULT 297,
        `paper_format` VARCHAR(255) NOT NULL DEFAULT 'a4',
        `unit` VARCHAR(255) NOT NULL DEFAULT 'mm',
        `is_required` BOOLEAN NOT NULL DEFAULT 0,
        `is_visible` BOOLEAN NOT NULL DEFAULT 1,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (id)
    )" . $charset_collate . ";");

    dbDelta( "CREATE TABLE {$table_variables_document} (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `text` text NOT NULL,
        `visual` text NOT NULL,
        `identificator` text NOT NULL,
        `type` VARCHAR(255) NOT NULL DEFAULT 'all',
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (id)
    )" . $charset_collate . ";");

    dbDelta( "CREATE TABLE {$table_users_signatures_certificate} (
        id INT(11) NOT NULL AUTO_INCREMENT,
        user_id INT(11) NOT NULL,
        charge TEXT NOT NULL,
        attach_id INT(11) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY (id)
    )" . $charset_collate . ";");

    dbDelta("CREATE TABLE {$table_certificates} (
        id INT(11) NOT NULL AUTO_INCREMENT,
        simple_uuid VARCHAR(255) NULL,
        type TEXT NOT NULL,
        name_document TEXT NOT NULL,
        program_document TEXT NOT NULL,
        template_id INT(11) NOT NULL,
        signature_id INT(11) NULL,
        `user` VARCHAR(255) NOT NULL,
        email VARCHAR(255) NOT NULL,
        emission_date DATE NULL,
        expiration_date DATE NULL,
        participant_id INT(11) NULL,
        course_id INT(11) NULL,
        html text NULL,
        option_document JSON NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    )" . $charset_collate . ";");

    dbDelta("CREATE TABLE {$table_certificates_templates} (
        id INT(11) NOT NULL AUTO_INCREMENT,
        name TEXT NOT NULL,
        description TEXT NOT NULL,
        is_active BOOLEAN NOT NULL DEFAULT 1,
        fields JSON NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    )" . $charset_collate . ";");

    dbDelta("CREATE TABLE {$table_cards_templates} (
        id INT(11) NOT NULL AUTO_INCREMENT,
        name TEXT NOT NULL,
        main_side_file INT(11) NULL,
        rear_side_file INT(11) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    )" . $charset_collate . ";");

    default_templates();
    default_templates_cards();
}

register_activation_hook(__FILE__, function () {

    // Incluir funciones necesarias de WordPress
    require_once ABSPATH . 'wp-admin/includes/plugin.php';

    // Plugin requerido (ejemplo: WooCommerce)
    $plugin_requerido = 'edusystem/edusystem.php'; // Reemplaza con el path correcto

    // Verificar si el plugin está activo
    if (!is_plugin_active($plugin_requerido)) {
        // Desactivar este plugin
        deactivate_plugins(plugin_basename(__FILE__));

        // Mensaje de error
        $mensaje = '<h2>Error</h2>';
        $mensaje .= '<p>This plugin requires that <strong>' . $plugin_requerido . '</strong> is installed and activated.</p>';

        // Verificar si el plugin está instalado
        $plugins_instalados = get_plugins();
        if (!isset($plugins_instalados[$plugin_requerido])) {
            $mensaje .= '<p>The required plugin is not installed. Please install it first.</p>';
        } else {
            $mensaje .= '<p>The required plugin is installed but not activated. Activate it before proceeding.</p>';
        }

        // Mostrar error y detener la ejecución
        wp_die($mensaje);
    } else {
        create_tables_certificates();
    }
});