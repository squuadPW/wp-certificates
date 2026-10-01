<?php
declare(strict_types=1);

namespace Squuad\Certificados;

defined('ABSPATH') || exit;

/**
 * Cuarentena de métodos de variables (ADR 0005, regla 8). Un método que provocó un fallo que PHP no puede capturar
 * (memoria, tiempo, exit) se detecta al terminar la petición y no se vuelve a ejecutar hasta que un administrador lo
 * rehabilite.
 */
final class Quarantine
{
    public const OPTION = 'squuad_cert_method_quarantine';

    /** Método que se está ejecutando ahora (para detectar el fallo al terminar la petición). */
    private static ?string $running = null;
    private static bool $watching = false;

    /** @return array<string, array{at: string, reason: string}> */
    public static function all(): array
    {
        $saved = get_option(self::OPTION, []);

        return is_array($saved) ? $saved : [];
    }

    public static function has(string $method_id): bool
    {
        return isset(self::all()[$method_id]);
    }

    public static function add(string $method_id, string $reason): void
    {
        $all = self::all();
        $all[$method_id] = ['at' => gmdate('Y-m-d H:i:s'), 'reason' => $reason];
        update_option(self::OPTION, $all, false);
        Log::add(sprintf('Método de variable %s en cuarentena: %s', $method_id, $reason), 'variable_method_quarantine');
    }

    public static function release(string $method_id): bool
    {
        $all = self::all();
        if (!isset($all[$method_id])) {
            return false;
        }
        unset($all[$method_id]);
        update_option(self::OPTION, $all, false);
        Log::add(sprintf('Método de variable %s rehabilitado', $method_id), 'variable_method_released');

        return true;
    }

    /** Marca el inicio de la ejecución de un método. */
    public static function start(string $method_id): void
    {
        self::$running = $method_id;
        if (!self::$watching) {
            self::$watching = true;
            register_shutdown_function([self::class, 'on_shutdown']);
        }
    }

    public static function stop(): void
    {
        self::$running = null;
    }

    /** Al terminar la petición: si un método no llegó a terminar, se pone en cuarentena. */
    public static function on_shutdown(): void
    {
        if (null === self::$running) {
            return;
        }
        $error = error_get_last();
        $reason = $error ? (string) $error['message'] : 'la ejecución terminó dentro del método (exit o error fatal)';
        self::add(self::$running, $reason);
        self::$running = null;
    }
}
