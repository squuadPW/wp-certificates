<?php
declare(strict_types=1);

/**
 * Certificación - Claves del sitio para sellar las firmas (ADR 0001). Movido sin cambios desde
 * includes/signature-integrity.php (ADR 0004, paso 3b) para que la instalación del esquema pueda generar la clave sin
 * cargar el resto del módulo. Sin hooks.
 */

if (!defined('ABSPATH')) exit;

/**
 * Claves del sitio guardadas en la BD (opción sin autoload). Nunca se muestran ni se editan desde el admin.
 * Formato: ['current' => key_id, 'keys' => [key_id => ['key' => base64, 'created_at' => UTC, 'created_by' => user_id]]]
 */
function squuad_cert_signature_stored_keys(): array
{
    $stored = get_option('squuad_cert_signature_keys', []);
    if (!is_array($stored) || empty($stored['keys']) || !is_array($stored['keys'])) {
        return ['current' => '', 'keys' => []];
    }
    return $stored;
}

/**
 * Genera la clave del sitio si no hay ninguna. El key_id es aleatorio (opt-AAAAMMDD-xxxxxx): si la opción
 * desaparece (restauración parcial, borrado), la clave nueva nunca reutiliza el id de una anterior, así que las
 * huellas firmadas con la clave perdida pasan a "clave desconocida" en lugar de parecer alteradas.
 */
function squuad_cert_signature_ensure_key(): string
{
    $stored = squuad_cert_signature_stored_keys();
    if ($stored['current'] !== '' && isset($stored['keys'][$stored['current']])) {
        return $stored['current'];
    }
    return squuad_cert_signature_add_key($stored);
}

/** Crea una clave nueva y la deja como actual; las anteriores se conservan solo para verificar. */
function squuad_cert_signature_add_key(?array $stored = null): string
{
    $stored = $stored ?? squuad_cert_signature_stored_keys();
    $key_id = 'opt-' . gmdate('Ymd') . '-' . bin2hex(random_bytes(3));

    // La clave que deja de ser la activa queda retirada: solo sirve para verificar lo firmado antes de esta fecha
    $previous_key_id = (string) ($stored['current'] ?? '');
    if ('' !== $previous_key_id && isset($stored['keys'][$previous_key_id]) && empty($stored['keys'][$previous_key_id]['retired_at'])) {
        $stored['keys'][$previous_key_id]['retired_at'] = gmdate('Y-m-d H:i:s');
    }

    $stored['keys'][$key_id] = [
        'key' => base64_encode(random_bytes(32)),
        'created_at' => gmdate('Y-m-d H:i:s'),
        'created_by' => get_current_user_id(),
    ];
    $stored['current'] = $key_id;

    // update_option no cambia el autoload de una opción existente; add_option la crea sin autoload
    if (get_option('squuad_cert_signature_keys', null) === null) {
        add_option('squuad_cert_signature_keys', $stored, '', 'no');
    } else {
        update_option('squuad_cert_signature_keys', $stored, 'no');
    }

    squuad_cert_log('Clave de evidencias de firma creada: ' . $key_id, 'signature_key');

    return $key_id;
}
