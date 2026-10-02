<?php
declare(strict_types=1);

/**
 * Roles que pueden firmar (ADR 0004 de EduSystem, decisión del dueño del 2026-10-02).
 *
 * wp-certificates decide quién firma por el rol de WordPress del usuario conectado, sin preguntar a otro plugin. El
 * administrador marca, entre todos los roles del sitio (también los que crean otros plugins), cuáles pueden firmar;
 * solo esos aparecen como filas en el panel "Firmantes del documento", con su variable {{signature_role_<rol>}}. Cada
 * usuario con un rol marcado en un documento recibe su propio documento y lo firma con su cuenta.
 */

defined('ABSPATH') || exit;

const SQUUAD_CERT_SIGNING_ROLES_OPTION = 'squuad_cert_signing_roles';

/** Todos los roles del sitio: clave => nombre visible (traducido). */
function squuad_cert_site_roles(): array
{
    $roles = [];
    foreach (wp_roles()->roles as $key => $role) {
        $roles[(string) $key] = translate_user_role((string) ($role['name'] ?? $key));
    }

    return $roles;
}

/**
 * Roles marcados como firmantes que siguen existiendo en el sitio (un rol borrado deja de contar). Sin configurar, el
 * rol student si existe: es el que firmaba hasta ahora.
 *
 * @return string[]
 */
function squuad_cert_signing_roles(): array
{
    $saved = get_option(SQUUAD_CERT_SIGNING_ROLES_OPTION, null);
    $roles = is_array($saved) ? $saved : ['student'];

    return array_values(array_intersect(array_map('strval', $roles), array_keys(squuad_cert_site_roles())));
}

/** ¿Este rol está marcado como firmante? */
function squuad_cert_signing_role_enabled(string $role): bool
{
    return in_array($role, squuad_cert_signing_roles(), true);
}

/** Clave de la variable de firma de un rol: signature_role_<rol> (solo letras, números y guion bajo). */
function squuad_cert_signing_role_variable(string $role): string
{
    return 'signature_role_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($role));
}

/**
 * Guarda los roles marcados (solo roles que existen) y deja en el log los que se activan y desactivan. Las solicitudes
 * en curso conservan sus firmantes. Devuelve ['added' => [...], 'removed' => [...]].
 */
function squuad_cert_signing_roles_save(array $roles): array
{
    $site = squuad_cert_site_roles();
    $new = array_values(array_intersect(array_unique(array_map('strval', $roles)), array_keys($site)));
    $old = squuad_cert_signing_roles();
    $added = array_values(array_diff($new, $old));
    $removed = array_values(array_diff($old, $new));

    update_option(SQUUAD_CERT_SIGNING_ROLES_OPTION, $new, false);
    if ($added || $removed) {
        squuad_cert_log(sprintf(
            'Roles que pueden firmar cambiados por el usuario %d. Activados: %s. Desactivados: %s.',
            get_current_user_id(),
            $added ? implode(', ', $added) : '—',
            $removed ? implode(', ', $removed) : '—'
        ), 'signing_roles');
    }

    return ['added' => $added, 'removed' => $removed];
}
