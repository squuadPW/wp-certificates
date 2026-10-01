<?php
declare(strict_types=1);

/**
 * Autoload propio (sin Composer) para las clases de wp-certificates: Squuad\Certificados\Nombre -> src/Nombre.php.
 */

defined('ABSPATH') || exit;

spl_autoload_register(static function (string $class): void {
    $prefix = 'Squuad\\Certificados\\';
    if (0 !== strpos($class, $prefix)) {
        return;
    }
    $file = WP_C_PATH . 'src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_readable($file)) {
        require_once $file;
    }
});
