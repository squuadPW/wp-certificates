<?php
declare(strict_types=1);

/**
 * Certificación > Asignación de certificados, sin EduSystem: emitir un documento a usuarios de WordPress (titular
 * wp_user, ADR 0004 de EduSystem, paso 10). Con EduSystem la pantalla sigue siendo la de estudiantes
 * (admin_certificate_assignment_content() en admin/certificates.php), sin cambios.
 *
 * Mismo permiso que la asignación a estudiantes (manager_certificate_assignment). Se busca al usuario por nombre,
 * usuario o correo (al menos SQUUAD_CERT_ASSIGN_USERS_MIN_SEARCH caracteres; nunca se lista a nadie sin buscar ni a
 * las cuentas de administración), se elige el documento (solo los activos que no piden firmas) y se emite con
 * squuad_cert_issue_document_to_user(): queda en «Student certificates» con su código de validación.
 */

defined('ABSPATH') || exit;

const SQUUAD_CERT_ASSIGN_USERS_MAX = 50;
const SQUUAD_CERT_ASSIGN_USERS_MIN_SEARCH = 3;

/** Roles con manage_options (cuentas de administración): no se listan ni se les emite desde aquí. */
function squuad_cert_assignment_users_excluded_roles(): array
{
    $roles = [];
    foreach (wp_roles()->role_objects as $key => $role) {
        if ($role->has_cap('manage_options')) {
            $roles[] = (string) $key;
        }
    }

    return $roles;
}

/** ¿Se puede emitir a esta cuenta desde aquí? (existe y no es de administración). */
function squuad_cert_assignment_users_allowed(int $user_id): bool
{
    $user = $user_id > 0 ? get_userdata($user_id) : false;

    return $user && !array_intersect((array) $user->roles, squuad_cert_assignment_users_excluded_roles()) && !user_can($user, 'manage_options');
}

/** Pantalla (la llama admin_certificate_assignment_content() cuando EduSystem no está activo). */
function squuad_cert_assignment_users_page(): void
{
    if (!current_user_can('manager_certificate_assignment')) {
        wp_die(esc_html__('Sorry, you are not allowed to access this page.', 'wp-certificates'), 403);
    }
    $search = sanitize_text_field(wp_unslash($_GET['user_search'] ?? ''));
    $searched = mb_strlen($search) >= SQUUAD_CERT_ASSIGN_USERS_MIN_SEARCH;
    $users = [];
    $total_users = 0;
    if ($searched) {
        $query = new WP_User_Query([
            'number' => SQUUAD_CERT_ASSIGN_USERS_MAX,
            'orderby' => 'display_name',
            'order' => 'ASC',
            'count_total' => true,
            'search' => '*' . $search . '*',
            'search_columns' => ['user_login', 'user_email', 'user_nicename', 'display_name'],
            'role__not_in' => squuad_cert_assignment_users_excluded_roles(),
        ]);
        $users = (array) $query->get_results();
        $total_users = (int) $query->get_total();
    }
    $documents = squuad_cert_assignment_users_documents();
    $notice = squuad_cert_assignment_users_notice();
    // Documento de identidad (ADR 0007 de Edusof): columna propia solo con «Pedir documento de identidad» encendido
    $id_required = squuad_cert_id_document_required();

    include WP_C_PATH . 'admin/templates/certificate-assignment-users.php';
}

/** Documentos que se pueden emitir aquí: activos y sin firmas. */
function squuad_cert_assignment_users_documents(): array
{
    global $wpdb;
    // Consulta propia: get_documents_certificates() sin tipo llama a prepare() sin marcadores (aviso de WordPress)
    $documents = $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}documents_certificates WHERE `status` = 1 AND `signature_required` = 0 ORDER BY title ASC"
    );

    return array_values((array) $documents);
}

/** Aviso de la última emisión (por usuario, unos minutos, se muestra una vez). */
function squuad_cert_assignment_users_notice(?array $notice = null): ?array
{
    $key = 'squuad_cert_assign_users_notice_' . get_current_user_id();
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

add_action('admin_post_squuad_cert_issue_to_users', 'squuad_cert_issue_to_users_handle');
function squuad_cert_issue_to_users_handle(): void
{
    // Con EduSystem se emite a sus estudiantes (admin_certificate_assignment_content): este camino no existe
    if (!current_user_can('manager_certificate_assignment') || !squuad_cert_wp_user_holder_enabled()) {
        wp_die(esc_html__('Sorry, you are not allowed to access this page.', 'wp-certificates'), 403);
    }
    check_admin_referer('squuad_cert_issue_to_users');
    $back = admin_url('admin.php?page=admin_certificate_assignment_content');

    $document_id = is_scalar($_POST['document_id'] ?? null) ? absint($_POST['document_id']) : 0;
    $user_ids = array_values(array_unique(array_filter(array_map(static fn($v): int => is_scalar($v) ? absint($v) : 0, (array) wp_unslash($_POST['user_ids'] ?? [])))));
    $allowed = array_map(static fn($document): int => (int) $document->id, squuad_cert_assignment_users_documents());
    if (!$document_id || !in_array($document_id, $allowed, true) || !$user_ids) {
        squuad_cert_assignment_users_notice(['ok' => false, 'lines' => [__('Choose a document and at least one user.', 'wp-certificates')]]);
        wp_safe_redirect($back);
        exit;
    }

    // Documento de identidad (ADR 0007 de Edusof, decisión 3): con «Pedir documento de identidad» encendido, a quien aún no
    // lo tiene se le escribe aquí (tipo + número de su fila); si no se escribe, no se le emite
    $id_required = squuad_cert_id_document_required();
    // Q5: solo valores escalares (un array anidado se descarta)
    $id_types = $id_required ? array_map(static fn($v): int => is_scalar($v) ? absint($v) : 0, (array) wp_unslash($_POST['id_doc_type'] ?? [])) : [];
    $id_numbers = $id_required ? array_map(static fn($v): string => is_string($v) ? sanitize_text_field($v) : '', (array) wp_unslash($_POST['id_doc_number'] ?? [])) : [];

    $emission_date = current_time('mysql');
    $issued = [];
    $existing = [];
    $errors = [];
    foreach (array_slice($user_ids, 0, SQUUAD_CERT_ASSIGN_USERS_MAX) as $user_id) {
        $name = squuad_cert_wp_user_full_name($user_id) ?: '#' . $user_id;
        if (!squuad_cert_assignment_users_allowed($user_id)) {
            $errors[] = $name . ': ' . __('This account cannot receive documents from here.', 'wp-certificates');
            continue;
        }
        if ($id_required && '' === squuad_cert_id_document_identifier($user_id)) {
            $typed_type = (int) ($id_types[$user_id] ?? 0);
            $typed_number = trim((string) ($id_numbers[$user_id] ?? ''));
            if (!$typed_type && '' === $typed_number) {
                $errors[] = $name . ': ' . __('not issued, the person has no identity document. Write its type and number in their row.', 'wp-certificates');
                continue;
            }
            $saved = squuad_cert_id_document_save($user_id, $typed_type, $typed_number, 'issue');
            if (is_wp_error($saved)) {
                $errors[] = $name . ': ' . __('not issued', 'wp-certificates') . ' (' . $saved->get_error_message() . ')';
                continue;
            }
        }
        $already = false;
        $result = squuad_cert_issue_document_to_user($user_id, $document_id, $emission_date, 'download_certificate', $already);
        if (is_wp_error($result)) {
            $errors[] = $name . ': ' . $result->get_error_message();
        } elseif ($already) {
            $existing[] = $name;
        } else {
            $issued[] = $name;
        }
    }

    $lines = [];
    if ($issued) {
        /* translators: %s: list of names */
        $lines[] = sprintf(__('Document issued to: %s', 'wp-certificates'), implode(', ', $issued));
    }
    if ($existing) {
        /* translators: %s: list of names */
        $lines[] = sprintf(__('Already issued before (not issued again): %s', 'wp-certificates'), implode(', ', $existing));
    }
    foreach ($errors as $error) {
        $lines[] = $error;
    }
    squuad_cert_assignment_users_notice(['ok' => !$errors, 'lines' => $lines]);
    wp_safe_redirect($back);
    exit;
}
