<?php
declare(strict_types=1);

/**
 * Permisos de certificación (ADR 0004, sección 7; decisión del dueño del 2026-10-02).
 *
 * Todos los permisos de certificación están aquí, con su nombre visible: los de wp-certificates de siempre
 * (manager_*) y los propios del sistema de firmas (squuad_cert_*). Lo delicado ya no se abre con manage_options (en
 * los sitios de los clientes muchos roles lo tienen): Variables, Signing roles, emitir para firma y esta misma
 * pantalla tienen su permiso propio. Por defecto solo los tiene el rol administrator; cada sitio los reparte a sus
 * roles en Certificación > Permisos (sin tocar código). Filtro squuad_cert_capabilities para añadir otros.
 */

defined('ABSPATH') || exit;

/** Opción que marca que la concesión inicial de permisos ya se hizo (una sola vez por sitio). */
const SQUUAD_CERT_PERMISSIONS_INSTALLED = 'squuad_cert_permissions_installed';

/** Permisos de certificación: clave => [nombre visible, descripción]. */
function squuad_cert_capabilities(): array
{
    $caps = [
        'manager_certificates' => [__('Certificates', 'wp-certificates'), __('Student certificates.', 'wp-certificates')],
        'manager_documents_certificates' => [__('Documents', 'wp-certificates'), __('Create and edit the documents (templates, book, priority).', 'wp-certificates')],
        'manager_certificate_assignment' => [__('Certificate assignment', 'wp-certificates'), __('Assign certificates to students.', 'wp-certificates')],
        'manager_users_signatures_certificate' => [__('Users and signatures', 'wp-certificates'), __('See the system signers.', 'wp-certificates')],
        'manager_id_card' => [__('ID card', 'wp-certificates'), __('Student ID cards.', 'wp-certificates')],
        'manager_configuration_certificates' => [__('Configuration', 'wp-certificates'), __('Certification configuration.', 'wp-certificates')],
        'squuad_cert_manage_signers' => [__('Manage signers', 'wp-certificates'), __('Invite, suspend and remove system signers.', 'wp-certificates')],
        'squuad_cert_manage_signing_policies' => [__('Document signers', 'wp-certificates'), __('Choose who signs each document and in which order.', 'wp-certificates')],
        'squuad_cert_issue_documents' => [__('Issue for signature', 'wp-certificates'), __('Issue documents for signature from a student file, and decline them.', 'wp-certificates')],
        'squuad_cert_manage_signature_integrity' => [__('Signature integrity', 'wp-certificates'), __('Verify the signature chain and rotate the key.', 'wp-certificates')],
        'squuad_cert_manage_variables' => [__('Variables', 'wp-certificates'), __('Edit the variable list and choose the method of each variable.', 'wp-certificates')],
        'squuad_cert_manage_signing_roles' => [__('Signing roles', 'wp-certificates'), __('Choose which roles can sign documents.', 'wp-certificates')],
        'squuad_cert_manage_api_keys' => [__('API connections', 'wp-certificates'), __('Create and revoke the keys that other systems use to check this platform.', 'wp-certificates')],
        'squuad_cert_manage_permissions' => [__('Permissions', 'wp-certificates'), __('Give these permissions to the roles (this screen).', 'wp-certificates')],
    ];

    return (array) apply_filters('squuad_cert_capabilities', $caps);
}

/**
 * Concesión inicial, una sola vez por sitio: el administrator recibe todos los permisos de certificación, y
 * «Emitir para firma» se da a los roles que hoy pueden emitir (manage_options y manager_admission_aes), para que nadie
 * pierda lo que hoy hace. Después, los permisos solo cambian desde Certificación > Permisos.
 */
function squuad_cert_permissions_install(): void
{
    $admin = get_role('administrator');
    if ($admin) {
        foreach (array_keys(squuad_cert_capabilities()) as $cap) {
            if (!$admin->has_cap($cap)) {
                $admin->add_cap($cap);
            }
        }
    }
    if (get_option(SQUUAD_CERT_PERMISSIONS_INSTALLED)) {
        return;
    }
    $granted = [];
    foreach (wp_roles()->role_objects as $key => $role) {
        if ($role->has_cap('manage_options') && $role->has_cap('manager_admission_aes') && !$role->has_cap('squuad_cert_issue_documents')) {
            $role->add_cap('squuad_cert_issue_documents');
            $granted[] = $key;
        }
    }
    add_option(SQUUAD_CERT_PERMISSIONS_INSTALLED, gmdate('Y-m-d H:i:s'), '', false);
    squuad_cert_log(sprintf(
        'Permisos de certificación instalados: el administrator los tiene todos; «Emitir para firma» a los roles que ya podían emitir: %s',
        $granted ? implode(', ', $granted) : '—'
    ), 'permissions');
}

/**
 * Guarda los permisos de los roles enviados: $matrix = [rol => [permiso, …]] (los demás roles no se tocan). Solo
 * roles y permisos que existen; al administrator no se le puede quitar el permiso de esta pantalla (para no quedarse
 * fuera). Cada cambio queda en el log. Devuelve cuántos cambios hubo.
 */
function squuad_cert_permissions_save(array $matrix): int
{
    $caps = array_keys(squuad_cert_capabilities());
    $changes = [];
    foreach (wp_roles()->role_objects as $key => $role) {
        if (!array_key_exists($key, $matrix)) {
            continue;
        }
        $wanted = array_intersect(array_map('strval', (array) ($matrix[$key] ?? [])), $caps);
        if ('administrator' === $key) {
            $wanted[] = 'squuad_cert_manage_permissions';
        }
        foreach ($caps as $cap) {
            $has = $role->has_cap($cap);
            $want = in_array($cap, $wanted, true);
            if ($want && !$has) {
                $role->add_cap($cap);
                $changes[] = "+{$key}:{$cap}";
            } elseif (!$want && $has) {
                $role->remove_cap($cap);
                $changes[] = "-{$key}:{$cap}";
            }
        }
    }
    if ($changes) {
        squuad_cert_log(sprintf('Permisos de certificación cambiados por el usuario %d: %s', get_current_user_id(), implode(', ', $changes)), 'permissions');
    }

    return count($changes);
}
