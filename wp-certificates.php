<?php
/*
Plugin Name: WP Certificates
Description: The WordPress plugin for certificates, certificates and personalized documents of the institution.
Author: EduSof
Version: 2.0.0
Requires EduSystem: 6.0.0
Author URI: https://edusof.com
License: GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: wp-certificates
Domain Path: /languages
*/

// Evitar acceso directo
defined('ABSPATH') || exit;

// Constantes del plugin
define('WP_C_PATH', plugin_dir_path(__FILE__));
define('WP_C_REMOTE_INFO_URL', 'https://versions.squuad.com/plugins/wp-certificates/info.json');
// Versión del esquema de tablas: al subirla, create_tables_certificates() se vuelve a ejecutar sin
// reactivar el plugin (dbDelta solo crea tablas o añade/modifica columnas)
// 3: log propio {prefix}squuad_cert_log (ADR 0004 de EduSystem, paso 2).
// 4: variables_document.method, identificador del método que da el valor de la variable (ADR 0005 de EduSystem).
// 5: las variables generales salen de la lista de la base de datos (siempre están disponibles en el código).
// 6: cada variable de la lista se vincula con el método de su misma clave (ADR 0005 de EduSystem).
// 7: documents_certificates.book_line_description, texto de la línea del libro de registro (ADR 0004 de EduSystem).
// 8: documents_certificates.priority, orden de los documentos automáticos en Mi Cuenta (0 = el más urgente).
// 9: tablas de las firmas propias (squuad_cert_*: solicitudes por cuenta, firmas, anuladas, contenido, eventos,
//    cadena, firmantes, políticas, lotes y libro) y clave del sitio (ADR 0004 de EduSystem, paso 3b).
// 10: permisos propios de certificación (squuad_cert_*); concesión inicial una sola vez (includes/permissions.php).
// 11: permiso squuad_cert_manage_api_keys (Conexiones API) para el administrator.
define('WP_C_DB_VERSION', '11');

// get_plugin_data() vive en wp-admin/includes/plugin.php, que en el front no está cargado (antes solo funcionaba
// porque EduSystem lo cargaba primero)
if ( !function_exists('get_plugin_data') )
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
$plugin_data = get_plugin_data(__FILE__);
define('WP_C_VERSION', $plugin_data['Version']);

// Contrato con otros plugins (ADR 0004 de EduSystem): versión del contrato (entero; sube solo con cambios
// incompatibles) y versión mínima de EduSystem, leída de la cabecera "Requires EduSystem"
define('SQUUAD_CERT_API_VERSION', 1);
define('WP_C_REQUIRES_EDUSYSTEM', (string) (get_file_data(__FILE__, ['requires_edusystem' => 'Requires EduSystem'])['requires_edusystem'] ?? ''));

// Cargar archivos necesarios
if ( !class_exists('WP_List_Table') ) 
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');

require_once WP_C_PATH . 'includes/autoload.php';
require_once WP_C_PATH . 'includes/contract.php';
require_once WP_C_PATH . 'includes/variables.php';
require_once WP_C_PATH . 'includes/book.php';
require_once WP_C_PATH . 'includes/automatic.php';
require_once WP_C_PATH . 'includes/signing-roles.php';
require_once WP_C_PATH . 'includes/permissions.php';
require_once WP_C_PATH . 'includes/api-keys.php';
require_once WP_C_PATH . 'includes/rest-v1.php';

// Módulo de firmas (ADR 0004 de EduSystem, paso 4b-4): solo si EduSystem no carga el suyo, que define
// EDUSYSTEM_CERTIFICATION_PATH al arrancar (nunca dos módulos de firma a la vez). En plugins_loaded, cuando todos los
// plugins ya están cargados, para no depender del orden de carga.
add_action('plugins_loaded', 'squuad_cert_load_signature_module', 1);

// Traducciones propias (languages/): todo el plugin, también el módulo de firmas, usa el dominio wp-certificates
add_action('plugins_loaded', 'squuad_cert_load_textdomain');
function squuad_cert_load_textdomain() {
    load_plugin_textdomain('wp-certificates', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
function squuad_cert_load_signature_module() {
    if (defined('EDUSYSTEM_CERTIFICATION_PATH') || defined('SQUUAD_CERT_MODULE_PATH')) {
        return;
    }
    require_once WP_C_PATH . 'certification/bootstrap.php';
}
require_once WP_C_PATH . 'public/functions.php';
require_once WP_C_PATH . 'admin/functions.php';

// Include the required file for get_plugin_data()
if ( !function_exists('get_plugin_data') ) 
    require_once ABSPATH . 'wp-admin/includes/plugin.php';

// Sistema de actualizaciones
add_filter('plugins_api', 'wp_c_plugin_info', 20, 3);
add_filter('site_transient_update_plugins', 'wp_c_check_update');

// Obtener información remota con caché
// WordPress aplica site_transient_update_plugins varias veces por página del admin: el servidor se
// consulta como máximo una vez cada 12 h (1 h si falla, p. ej. 404) y "Comprobar de nuevo" en
// Actualizaciones fuerza la consulta.
function wp_c_get_remote_info() {
    static $remote_info = null; // '' = la consulta falló

    if (null === $remote_info) {
        $forzar = is_admin() && isset($_GET['force-check']);
        $cache = $forzar ? false : get_site_transient('wp_c_remote_info');

        if (false !== $cache) {
            $remote_info = $cache;
        } else {
            $remote = wp_remote_get(WP_C_REMOTE_INFO_URL, [
                'timeout' => 10,
                'headers' => ['Accept' => 'application/json']
            ]);

            $remote_info = '';
            if (
                !is_wp_error($remote) &&
                200 === wp_remote_retrieve_response_code($remote) &&
                !empty($body = wp_remote_retrieve_body($remote))
            ) {
                $remote_info = json_decode($body) ?: '';
            }

            set_site_transient('wp_c_remote_info', $remote_info, $remote_info ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS);
        }
    }

    return $remote_info ?: null;
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
    // dbDelta() vive en upgrade.php: se carga solo aquí (antes se cargaba en cada petición)
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

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
        `priority` INT(11) NOT NULL DEFAULT 0,
        `book` TEXT NULL,
        `book_line_description` TEXT NULL,
        `fields` LONGTEXT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (id)
    )" . $charset_collate . ";");

    dbDelta( "CREATE TABLE {$table_variables_document} (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `text` text NOT NULL,
        `visual` text NOT NULL,
        `identificator` text NOT NULL,
        `type` VARCHAR(255) NOT NULL DEFAULT 'all',
        `method` VARCHAR(191) NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (id)
    )" . $charset_collate . ";");

    dbDelta( "CREATE TABLE {$table_users_signatures_certificate} (
        id INT(11) NOT NULL AUTO_INCREMENT,
        user_id INT(11) NOT NULL,
        charge TEXT NOT NULL,
        attach_id INT(11) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
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
        tomo INT NULL,
        folio INT NULL,
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

    // Log propio (esquema v3)
    dbDelta(\Squuad\Certificados\Log::schema($charset_collate));

    // Las variables generales no están en la lista de la base de datos (esquema v5): siempre están disponibles
    \Squuad\Certificados\Variables::remove_general_from_catalog();

    // Firmas (ADR 0004 de EduSystem, paso 3b): tablas propias y clave del sitio. Solo esquema y datos iniciales: el
    // módulo de firmas (certification/) se carga en el paso 4b-4
    require_once WP_C_PATH . 'certification/includes/signature-keys.php';
    require_once WP_C_PATH . 'certification/core/schema/signers.php';
    require_once WP_C_PATH . 'certification/core/schema/signatures.php';
    squuad_cert_schema_signers();
    squuad_cert_schema_signatures();
    squuad_cert_signatures_install();
    // Permisos propios de certificación (Certificación > Permisos)
    squuad_cert_permissions_install();

    default_templates();
    default_templates_cards();
}

// Actualiza las tablas cuando cambia WP_C_DB_VERSION (p. ej. tras actualizar el plugin). Prioridad 5:
// antes de las migraciones de EduSystem que usan estas columnas.
function wp_c_maybe_update_db() {
    if (get_option('wp_c_db_version') === WP_C_DB_VERSION) {
        return;
    }
    // Candado: si varias peticiones llegan a la vez, solo una ejecuta dbDelta y las plantillas por defecto.
    // add_option() falla si la opción ya existe; pasados 10 minutos se considera abandonado.
    $lock = get_option('wp_c_db_updating');
    if ($lock && (time() - (int) $lock) < 10 * MINUTE_IN_SECONDS) {
        return;
    }
    if (!$lock && !add_option('wp_c_db_updating', time(), '', false)) {
        return;
    }
    if ($lock) {
        update_option('wp_c_db_updating', time(), false);
    }
    create_tables_certificates();
    // Variables de la lista con el método de su misma clave (los proveedores ya se registraron en plugins_loaded)
    \Squuad\Certificados\Variables::link_by_key();
    update_option('wp_c_db_version', WP_C_DB_VERSION);
    delete_option('wp_c_db_updating');
}
add_action('init', 'wp_c_maybe_update_db', 5);

// Activación: wp-certificates funciona con o sin EduSystem (ADR 0004 de EduSystem, paso 1). Con EduSystem, además,
// trabaja con sus estudiantes; sin él, esas funciones se ocultan (ver wpc_edusystem_active()).
register_activation_hook(__FILE__, function () {
    create_tables_certificates();
    // Permisos del administrador ya al activar: WordPress comprueba el acceso a las pantallas antes de admin_init
    add_certificates_to_administrator();
    update_option('wp_c_db_version', WP_C_DB_VERSION);
});
