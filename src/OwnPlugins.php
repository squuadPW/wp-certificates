<?php
declare(strict_types=1);

namespace Squuad\Certificados;

defined('ABSPATH') || exit;

/**
 * Plugins propios que pueden aportar métodos de variables (ADR 0005, reglas 4 y 5).
 *
 * Propio = su ficha (nombre, descripción, autor, web del autor o del plugin) menciona EduSof o Squuad. Entre ellos, el
 * administrador activa los que pueden aportar métodos (opción squuad_cert_method_plugins). Por defecto: wp-certificates
 * y EduSystem.
 */
final class OwnPlugins
{
    public const OPTION = 'squuad_cert_method_plugins';
    public const OWNERS = ['edusof', 'squuad'];

    /** @var array<string, array>|null */
    private static ?array $own = null;

    /**
     * Plugins instalados cuya ficha menciona a EduSof o Squuad: [archivo del plugin => datos de la ficha].
     *
     * @return array<string, array>
     */
    public static function all(): array
    {
        if (null !== self::$own) {
            return self::$own;
        }
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        self::$own = [];
        foreach (get_plugins() as $file => $data) {
            $text = strtolower(implode(' ', [
                $data['Name'] ?? '',
                $data['Description'] ?? '',
                $data['Author'] ?? '',
                $data['AuthorURI'] ?? '',
                $data['PluginURI'] ?? '',
            ]));
            foreach (self::OWNERS as $owner) {
                if (false !== strpos($text, $owner)) {
                    self::$own[$file] = $data;
                    break;
                }
            }
        }

        return self::$own;
    }

    public static function is_own(string $plugin_file): bool
    {
        return isset(self::all()[$plugin_file]);
    }

    /** Archivo de wp-certificates (siempre propio). */
    public static function self_file(): string
    {
        return plugin_basename(WP_C_PATH . 'wp-certificates.php');
    }

    /**
     * Plugins activados por el administrador para aportar métodos (solo los propios).
     *
     * @return string[]
     */
    public static function enabled(): array
    {
        $saved = get_option(self::OPTION, null);
        $files = is_array($saved) ? $saved : [self::self_file(), 'edusystem/edusystem.php'];

        return array_values(array_filter(array_map('strval', $files), [self::class, 'is_own']));
    }

    public static function is_enabled(string $plugin_file): bool
    {
        return in_array($plugin_file, self::enabled(), true);
    }

    /** Guarda los activados. Ignora cualquier plugin que no sea propio. Devuelve los guardados. */
    public static function set_enabled(array $files): array
    {
        $files = array_values(array_unique(array_filter(array_map('strval', $files), [self::class, 'is_own'])));
        update_option(self::OPTION, $files, false);

        return $files;
    }

    /**
     * Plugin al que pertenece un archivo PHP ('' si no está dentro de un plugin instalado).
     */
    public static function plugin_of_file(string $file): string
    {
        // plugin_basename() entiende los plugins enlazados (symlink) que WordPress registró al cargarlos
        $relative = plugin_basename($file);
        if ('' === $relative || wp_normalize_path($relative) === wp_normalize_path($file) || false === strpos($relative, '/')) {
            return '';
        }
        $folder = strtok($relative, '/');
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        foreach (array_keys(get_plugins()) as $plugin_file) {
            if (0 === strpos($plugin_file, $folder . '/')) {
                return $plugin_file;
            }
        }

        return '';
    }
}
