<?php
declare(strict_types=1);

namespace Squuad\Certificados;

defined('ABSPATH') || exit;

/**
 * Registro de proveedores de titulares (ADR 0004, sección 4.2). Un proveedor es un plugin que aporta un tipo de
 * titular (p. ej. EduSystem aporta "edusystem_student") y responde, mediante callbacks, quién es el titular, quién
 * firma por él, sus variables, condiciones y el estado de su requisito. wp-certificates nunca lee las tablas del
 * proveedor: le pregunta.
 */
final class Providers
{
    /** Callbacks que un proveedor puede aportar (sección 4.2). */
    public const CALLBACKS = [
        'label',
        'holder_slots',
        'slot_types',
        'subjects_for_user',
        'replacements',
        'variables_catalog',
        'template_rules',
        'conditions',
        'external_ref',
        'external_state',
        'can_act',
        'book_line_data',
    ];

    /** Callbacks sin los que un tipo de titular no sirve. */
    public const REQUIRED = ['label', 'holder_slots'];

    /** @var array<string, array<string, callable>> */
    private static array $types = [];

    /**
     * Registra un tipo de titular. Devuelve false (y lo anota en el log) si el tipo no es válido, ya existe, faltan
     * callbacks obligatorios o alguno no es invocable o no es conocido.
     */
    public static function register(string $type, array $callbacks): bool
    {
        $error = self::validate($type, $callbacks);
        if ('' !== $error) {
            Log::add(sprintf('Proveedor "%s" no registrado: %s', $type, $error), 'provider_error');
            return false;
        }
        self::$types[$type] = $callbacks;

        return true;
    }

    /** @return array<string, callable>|null */
    public static function get(string $type): ?array
    {
        return self::$types[$type] ?? null;
    }

    /** @return string[] */
    public static function types(): array
    {
        return array_keys(self::$types);
    }

    private static function validate(string $type, array $callbacks): string
    {
        if (!preg_match('/^[a-z][a-z0-9_]{1,39}$/', $type)) {
            return 'el tipo debe ser un identificador en minúsculas de 2 a 40 caracteres';
        }
        if (isset(self::$types[$type])) {
            return 'ya hay un proveedor registrado con ese tipo';
        }
        $missing = array_diff(self::REQUIRED, array_keys($callbacks));
        if ($missing) {
            return 'faltan callbacks obligatorios: ' . implode(', ', $missing);
        }
        $unknown = array_diff(array_keys($callbacks), self::CALLBACKS);
        if ($unknown) {
            return 'callbacks desconocidos: ' . implode(', ', $unknown);
        }
        foreach ($callbacks as $name => $callback) {
            if (!is_callable($callback)) {
                return 'el callback "' . $name . '" no es invocable';
            }
        }

        return '';
    }
}
