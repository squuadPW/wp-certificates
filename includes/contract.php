<?php
declare(strict_types=1);

/**
 * Contrato de wp-certificates con otros plugins (ADR 0004 de EduSystem, sección 4; paso 2: esqueleto).
 *
 * - SQUUAD_CERT_API_VERSION y squuad_cert_status(): si wp-certificates está disponible, en qué versión del contrato
 *   y si su base de datos está lista. Quien lo use (EduSystem) pregunta aquí en vez de mirar tablas.
 * - squuad_cert_register_providers / squuad_cert_register_subject_type(): registro de proveedores de titulares.
 * - squuad_cert_loaded: aviso "ya estoy aquí" (solo si no está en pausa).
 * - squuad_cert_log(): log propio.
 * - Pareja de versiones con EduSystem (Squuad\Certificados\Pair): pausa y aviso si EduSystem es demasiado antiguo.
 *
 * Todavía no hay ningún proveedor ni ninguna orden: este paso no cambia el comportamiento.
 */

defined('ABSPATH') || exit;

use Squuad\Certificados\Log;
use Squuad\Certificados\Pair;
use Squuad\Certificados\Providers;

/**
 * Estado de wp-certificates para otros plugins.
 *
 * @return array{api_version: int, signatures_owner: bool, schema_ready: bool, paused: bool, pause_reason: string}
 */
function squuad_cert_status(): array
{
    $pair = Pair::check();

    return [
        'api_version' => SQUUAD_CERT_API_VERSION,
        // Pasa a true cuando wp-certificates reciba el módulo de firmas (paso 4 del ADR 0004)
        'signatures_owner' => false,
        'schema_ready' => get_option('wp_c_db_version') === WP_C_DB_VERSION,
        'paused' => !$pair['ok'],
        'pause_reason' => $pair['reason'],
    ];
}

/** Registra un tipo de titular (p. ej. 'edusystem_student'). Llamar dentro de la acción squuad_cert_register_providers. */
function squuad_cert_register_subject_type(string $type, array $callbacks): bool
{
    return Providers::register($type, $callbacks);
}

/** Callbacks del tipo de titular o null si no está registrado. */
function squuad_cert_subject_type(string $type): ?array
{
    return Providers::get($type);
}

/** Tipos de titular registrados. */
function squuad_cert_subject_types(): array
{
    return Providers::types();
}

/** Anota una acción en el log de wp-certificates (y en el de EduSystem si existe). */
function squuad_cert_log(string $message, string $type = 'info', array $context = []): void
{
    Log::add($message, $type, $context);
}

// Arranque: primero se registran los proveedores y después se avisa de que wp-certificates está disponible
add_action('plugins_loaded', 'squuad_cert_boot', 5);
function squuad_cert_boot(): void
{
    do_action('squuad_cert_register_providers');

    if (!Pair::paused()) {
        do_action('squuad_cert_loaded', SQUUAD_CERT_API_VERSION);
    }
}

add_action('all_admin_notices', [Pair::class, 'notice']);

/**
 * Registra un método de variable (ADR 0005). Llamar dentro de la acción squuad_cert_register_providers, desde el
 * código de un plugin propio. $definition: label (texto o función que lo devuelve, para traducirlo al mostrarse),
 * type ('text'|'html'|'condition'), callback (recibe
 * int $subject_id, array $ctx y devuelve el valor; solo lectura), y opcionales group, offered ('all'|'document'|
 * 'email'), subject (necesita titular, por defecto true) y sensitive.
 */
function squuad_cert_register_variable_method(string $provider, string $key, array $definition): bool
{
    // El plugin se identifica por el archivo desde el que se llama a esta función, no por lo que declare
    $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0]['file'] ?? '';

    return \Squuad\Certificados\VariableMethods::register($provider, $key, $definition, (string) $caller);
}

/** Métodos que se pueden asignar a una variable (plugin propio activado, fuera de cuarentena). */
function squuad_cert_variable_methods(): array
{
    return \Squuad\Certificados\VariableMethods::available();
}

/**
 * Valores de las variables de una plantilla que tienen método asignado, ejecutados aislados.
 *
 * @return array{replacements: array, failed: array} replacements con el formato de process_template()
 */
function squuad_cert_resolve_variables(string $template, int $subject_id, array $ctx = []): array
{
    return \Squuad\Certificados\VariableRunner::resolve($template, $subject_id, $ctx);
}
