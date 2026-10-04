<?php
declare(strict_types=1);

/**
 * Documento de identidad de quien firma, en el admin (ADR 0007 de Edusof):
 * - Certificación › Configuración, sección «Documento de identidad»: interruptor y tipos de documento.
 * - Perfil del usuario (wp-admin): la persona lo corrige hasta su primera firma; después, solo quien tiene el permiso
 *   squuad_cert_manage_id_documents.
 *
 * Todo con el permiso propio squuad_cert_manage_id_documents (Certificación › Permisos), nonces y validación en el
 * servidor (includes/id-document.php).
 */

defined('ABSPATH') || exit;

const SQUUAD_CERT_ID_DOCUMENT_SETTINGS_URL = 'admin.php?page=add_admin_form_configuration_options_certificates_content';

/** Aviso de la última acción en la sección (una vez, por usuario). */
function squuad_cert_id_document_settings_notice(?array $notice = null): ?array
{
    $key = 'squuad_cert_iddoc_settings_notice_' . get_current_user_id();
    if (null !== $notice) {
        set_transient($key, $notice, 5 * MINUTE_IN_SECONDS);
        return null;
    }
    $saved = get_transient($key);
    if (false !== $saved) {
        delete_transient($key);
    }

    return is_array($saved) ? $saved : null;
}

/** Comprueba permiso y nonce de una acción de la sección y devuelve la URL de vuelta. */
function squuad_cert_id_document_settings_check(string $action): string
{
    if (!current_user_can(SQUUAD_CERT_ID_DOCUMENT_CAP)) {
        wp_die(esc_html__('Sorry, you are not allowed to access this page.', 'wp-certificates'), 403);
    }
    check_admin_referer($action);

    return admin_url(SQUUAD_CERT_ID_DOCUMENT_SETTINGS_URL) . '#squuad-cert-id-document';
}

add_action('admin_post_squuad_cert_id_document_toggle', 'squuad_cert_id_document_handle_toggle');
function squuad_cert_id_document_handle_toggle(): void
{
    $back = squuad_cert_id_document_settings_check('squuad_cert_id_document_toggle');
    $enable = !empty($_POST['enabled']);
    if ($enable && !squuad_cert_id_document_types(true)) {
        squuad_cert_id_document_settings_notice(['ok' => false, 'message' => __('Activate at least one document type before asking for the identity document.', 'wp-certificates')]);
    } elseif ($enable !== squuad_cert_id_document_required()) {
        update_option(SQUUAD_CERT_ID_DOCUMENT_ENABLED_OPTION, $enable ? '1' : '0', true);
        squuad_cert_log(sprintf('«Pedir documento de identidad» %s por el usuario %d', $enable ? 'encendido' : 'apagado', get_current_user_id()), 'id_document_settings');
        squuad_cert_id_document_settings_notice(['ok' => true, 'message' => $enable
            ? __('The identity document is now required: whoever signs must register it first.', 'wp-certificates')
            : __('The identity document is no longer required.', 'wp-certificates')]);
    } else {
        squuad_cert_id_document_settings_notice(['ok' => true, 'message' => __('No changes.', 'wp-certificates')]);
    }
    wp_safe_redirect($back);
    exit;
}

add_action('admin_post_squuad_cert_id_document_type_save', 'squuad_cert_id_document_handle_type_save');
function squuad_cert_id_document_handle_type_save(): void
{
    $back = squuad_cert_id_document_settings_check('squuad_cert_id_document_type_save');
    $type_id = is_scalar($_POST['type_id'] ?? null) ? absint($_POST['type_id']) : 0;
    $fields = [
        'name' => is_string($_POST['name'] ?? null) ? sanitize_text_field(wp_unslash($_POST['name'])) : '',
        'prefix' => is_string($_POST['prefix'] ?? null) ? sanitize_text_field(wp_unslash($_POST['prefix'])) : '',
        'country' => is_string($_POST['country'] ?? null) ? sanitize_text_field(wp_unslash($_POST['country'])) : '',
        'format' => is_string($_POST['format'] ?? null) ? sanitize_key(wp_unslash($_POST['format'])) : '',
        'active' => !empty($_POST['active']),
    ];
    $result = squuad_cert_id_document_type_save($type_id, $fields);
    if (is_wp_error($result)) {
        // Q6: lo escrito vuelve al formulario
        squuad_cert_id_document_settings_notice(['ok' => false, 'message' => $result->get_error_message(), 'fields' => $fields]);
        $back = add_query_arg('edit_id_type', $type_id ?: 'new', admin_url(SQUUAD_CERT_ID_DOCUMENT_SETTINGS_URL)) . '#squuad-cert-id-document';
    } else {
        squuad_cert_id_document_settings_notice(['ok' => true, 'message' => $type_id ? __('Document type saved.', 'wp-certificates') : __('Document type created.', 'wp-certificates')]);
    }
    wp_safe_redirect($back);
    exit;
}

add_action('admin_post_squuad_cert_id_document_type_status', 'squuad_cert_id_document_handle_type_status');
function squuad_cert_id_document_handle_type_status(): void
{
    $type_id = is_scalar($_POST['type_id'] ?? null) ? absint($_POST['type_id']) : 0;
    $back = squuad_cert_id_document_settings_check('squuad_cert_id_document_type_status_' . $type_id);
    $active = !empty($_POST['active']);
    if (!$active && squuad_cert_id_document_required() && array_keys(squuad_cert_id_document_types(true)) === [$type_id]) {
        squuad_cert_id_document_settings_notice(['ok' => false, 'message' => __('The last active document type cannot be deactivated while the identity document is required.', 'wp-certificates')]);
    } elseif (squuad_cert_id_document_type_set_active($type_id, $active)) {
        squuad_cert_id_document_settings_notice(['ok' => true, 'message' => $active ? __('Document type activated.', 'wp-certificates') : __('Document type deactivated: it is no longer offered, and the people who have it keep it.', 'wp-certificates')]);
    }
    wp_safe_redirect($back);
    exit;
}

add_action('admin_post_squuad_cert_id_document_type_delete', 'squuad_cert_id_document_handle_type_delete');
function squuad_cert_id_document_handle_type_delete(): void
{
    $type_id = is_scalar($_POST['type_id'] ?? null) ? absint($_POST['type_id']) : 0;
    $back = squuad_cert_id_document_settings_check('squuad_cert_id_document_type_delete_' . $type_id);
    if (squuad_cert_id_document_required() && array_keys(squuad_cert_id_document_types(true)) === [$type_id]) {
        squuad_cert_id_document_settings_notice(['ok' => false, 'message' => __('The last active document type cannot be deleted while the identity document is required.', 'wp-certificates')]);
    } elseif (squuad_cert_id_document_type_delete($type_id)) {
        squuad_cert_id_document_settings_notice(['ok' => true, 'message' => __('Document type deleted. Its prefix stays reserved and is not reused.', 'wp-certificates')]);
    } else {
        squuad_cert_id_document_settings_notice(['ok' => false, 'message' => __('A document type that people use cannot be deleted: deactivate it instead.', 'wp-certificates')]);
    }
    wp_safe_redirect($back);
    exit;
}

/** Sección «Documento de identidad» de Certificación › Configuración (solo con el permiso propio). */
function squuad_cert_id_document_settings_section(): void
{
    if (!current_user_can(SQUUAD_CERT_ID_DOCUMENT_CAP)) {
        return;
    }
    $notice = squuad_cert_id_document_settings_notice();
    $enabled = squuad_cert_id_document_required();
    $types = squuad_cert_id_document_types();
    $usage = squuad_cert_id_document_type_usage();
    $formats = squuad_cert_id_document_formats();
    $edit_raw = is_string($_GET['edit_id_type'] ?? null) ? sanitize_key(wp_unslash($_GET['edit_id_type'])) : '';
    $editing = ctype_digit($edit_raw) ? squuad_cert_id_document_type((int) $edit_raw) : null;
    $scope = squuad_cert_id_document_eds_scope();
    include WP_C_PATH . 'admin/templates/id-document-settings.php';
}

/* ---------------------------------------------------------------------------------------------------------------
 * Perfil del usuario (wp-admin)
 * ------------------------------------------------------------------------------------------------------------ */

add_action('show_user_profile', 'squuad_cert_id_document_profile_section', 20);
add_action('edit_user_profile', 'squuad_cert_id_document_profile_section', 20);
function squuad_cert_id_document_profile_section($user): void
{
    if (!$user instanceof WP_User || !squuad_cert_id_document_required()) {
        return;
    }
    $user_id = (int) $user->ID;
    $is_self = get_current_user_id() === $user_id;
    $is_admin = squuad_cert_id_document_can_edit($user_id, 'admin');
    $locked = squuad_cert_id_document_locked($user_id);
    $can_edit = $is_admin || squuad_cert_id_document_can_edit($user_id, 'self');
    // Q6: tras un error del perfil (misma petición), lo escrito; si no, lo guardado
    $posted_type = is_scalar($_POST['squuad_cert_id_type'] ?? null) ? absint($_POST['squuad_cert_id_type']) : null;
    $posted_number = is_string($_POST['squuad_cert_id_number'] ?? null) ? sanitize_text_field(wp_unslash($_POST['squuad_cert_id_number'])) : null;
    $notice_key = 'squuad_cert_iddoc_profile_notice_' . get_current_user_id();
    $late_error = get_transient($notice_key);
    if (false !== $late_error) {
        delete_transient($notice_key);
    }
    $document = squuad_cert_id_document_get($user_id);
    // Número completo solo para la propia persona y para quien administra los documentos; enmascarado para los demás
    $shown = $document ? (($is_self || $is_admin) ? $document['identifier'] : squuad_cert_id_document_masked($user_id)) : '';
    $scope = squuad_cert_id_document_eds_scope();
    include WP_C_PATH . 'admin/templates/id-document-profile.php';
}

/** Documento pendiente de guardar del perfil (comprobado en user_profile_update_errors y guardado en profile_update). */
function squuad_cert_id_document_profile_pending(?array $set = null, bool $clear = false): ?array
{
    static $pending = null;
    if ($clear) {
        $pending = null;
    } elseif (null !== $set) {
        $pending = $set;
    }

    return $pending;
}

/**
 * Perfil (B4): el documento se comprueba al final de la validación del perfil (prioridad máxima, después de los demás
 * plugins) y se guarda solo si el perfil se guarda sin errores (acción profile_update). Si falla, el error sale en el
 * perfil y lo escrito se conserva en el formulario (Q6).
 */
add_action('user_profile_update_errors', 'squuad_cert_id_document_profile_save', PHP_INT_MAX, 3);
function squuad_cert_id_document_profile_save($errors, $update, $user): void
{
    squuad_cert_id_document_profile_pending(null, true);
    if (!$update || !is_object($user) || empty($user->ID) || !squuad_cert_id_document_required() || !is_string($_POST['squuad_cert_id_number'] ?? null)) {
        return;
    }
    if (!is_string($_POST['_squuad_cert_profile_iddoc'] ?? null) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_squuad_cert_profile_iddoc'])), 'squuad_cert_profile_iddoc_' . (int) $user->ID)) {
        return;
    }
    if ($errors instanceof WP_Error && $errors->has_errors()) {
        return; // el perfil no se guarda: tampoco el documento
    }
    $number = sanitize_text_field(wp_unslash($_POST['squuad_cert_id_number']));
    $type_id = is_scalar($_POST['squuad_cert_id_type'] ?? null) ? absint($_POST['squuad_cert_id_type']) : 0;
    if ('' === trim($number) && !$type_id) {
        return; // sin cambios
    }
    $user_id = (int) $user->ID;
    $origin = squuad_cert_id_document_can_edit($user_id, 'admin') ? 'admin' : 'self';
    // N2: bajo el bloqueo de la persona, para que varios envíos a la vez no se salten el límite de intentos fallidos
    global $wpdb;
    $lock = squuad_cert_id_document_lock_name('user|' . $user_id);
    if ('1' !== (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock))) {
        if ($errors instanceof WP_Error) {
            $errors->add('squuad_cert_id_document', __('The identity document could not be saved. Please try again.', 'wp-certificates'));
        }
        return;
    }
    try {
        $checked = squuad_cert_id_document_check($user_id, $type_id, $number, $origin);
    } finally {
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
    }
    if (is_wp_error($checked)) {
        if ($errors instanceof WP_Error) {
            $errors->add('squuad_cert_id_document', $checked->get_error_message());
        }
        return;
    }
    if (!$checked['unchanged']) {
        squuad_cert_id_document_profile_pending(['user_id' => $user_id, 'type_id' => $type_id, 'number' => $number, 'origin' => $origin]);
    }
}

add_action('profile_update', 'squuad_cert_id_document_profile_commit', 10, 1);
function squuad_cert_id_document_profile_commit($user_id): void
{
    $pending = squuad_cert_id_document_profile_pending();
    if (!$pending || (int) $pending['user_id'] !== (int) $user_id) {
        return;
    }
    squuad_cert_id_document_profile_pending(null, true);
    $result = squuad_cert_id_document_save((int) $user_id, (int) $pending['type_id'], (string) $pending['number'], (string) $pending['origin']);
    if (is_wp_error($result)) {
        // Carrera rara (otra cuenta lo tomó entre la comprobación y el guardado): aviso en la siguiente carga del perfil
        set_transient('squuad_cert_iddoc_profile_notice_' . get_current_user_id(), $result->get_error_message(), 5 * MINUTE_IN_SECONDS);
    }
}
