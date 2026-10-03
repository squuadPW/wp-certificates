<?php
/**
 * Edusof UI: cargador de la biblioteca compartida del sistema de diseño (ADR 0006).
 *
 * La carpeta edusof-ui/ es idéntica en EduSystem y en WP Certificates (se copia con
 * Antigravity/tools/sincronizar-edusof-ui.sh). Cada plugin incluye este archivo; cada copia se registra aquí y en
 * plugins_loaded (prioridad 20) se carga una sola: la de versión más alta. Así funciona con uno solo de los dos
 * plugins o con ambos, sin que dependan uno del otro.
 *
 * Los textos de la biblioteca usan el dominio de traducción del plugin cuya copia se cargó (cabecera «Text Domain»
 * de su archivo principal), por eso sus cadenas están en los dos catálogos (edusystem y wp-certificates).
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

(static function (): void {
    // Versión de ESTA copia de la biblioteca. Súbela al cambiar cualquier archivo de edusof-ui/.
    $version = '1.0.0';

    $plugin_dir = dirname(__DIR__);
    $main_file  = $plugin_dir . '/' . basename($plugin_dir) . '.php';
    $domain     = '';
    if (is_readable($main_file)) {
        $data   = get_file_data($main_file, ['domain' => 'Text Domain']);
        $domain = (string) ($data['domain'] ?? '');
    }
    if ('' === $domain) {
        $domain = basename($plugin_dir);
    }

    if (!isset($GLOBALS['edusof_ui_copies']) || !is_array($GLOBALS['edusof_ui_copies'])) {
        $GLOBALS['edusof_ui_copies'] = [];
    }
    $GLOBALS['edusof_ui_copies'][] = [
        'version' => $version,
        'path'    => __DIR__,
        'url'     => plugin_dir_url(__FILE__),
        'domain'  => $domain,
    ];
})();

if (!function_exists('edusof_ui_boot')) {
    /**
     * Elige la copia de versión más alta (a igual versión, la primera registrada) y la arranca.
     */
    function edusof_ui_boot(): void
    {
        if (class_exists('Edusof_UI', false) || empty($GLOBALS['edusof_ui_copies'])) {
            return;
        }
        $best = null;
        foreach ((array) $GLOBALS['edusof_ui_copies'] as $copy) {
            if (null === $best || version_compare((string) $copy['version'], (string) $best['version'], '>')) {
                $best = $copy;
            }
        }
        if (!defined('EDUSOF_UI_VERSION')) {
            define('EDUSOF_UI_VERSION', (string) $best['version']);
        }
        if (!defined('EDUSOF_UI_PATH')) {
            define('EDUSOF_UI_PATH', trailingslashit((string) $best['path']));
        }
        if (!defined('EDUSOF_UI_URL')) {
            define('EDUSOF_UI_URL', trailingslashit((string) $best['url']));
        }
        require_once EDUSOF_UI_PATH . 'includes/class-edusof-ui.php';
        Edusof_UI::boot((string) $best['domain']);
    }
    add_action('plugins_loaded', 'edusof_ui_boot', 20);
}
