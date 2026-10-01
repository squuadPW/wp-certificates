<?php
declare(strict_types=1);

/**
 * EduSystem - Pantalla "Integridad de firmas" (ADR 0001, paso 3): estado de la clave y rotación, resultado del
 * verificador de la cadena y diagnóstico de las firmas antiguas (con exportación CSV). Solo lectura salvo rotar
 * la clave y lanzar la verificación. Requiere el permiso edusystem_manage_signature_integrity.
 */

if (!defined('ABSPATH')) exit;

const EDUSYSTEM_SIGNATURE_INTEGRITY_CAP = 'edusystem_manage_signature_integrity';

// Por defecto solo el administrador principal (rol administrator) tiene el permiso
add_action('admin_init', 'edusystem_signature_integrity_grant_cap');
function edusystem_signature_integrity_grant_cap(): void
{
    $role = get_role('administrator');
    if ($role && !$role->has_cap(EDUSYSTEM_SIGNATURE_INTEGRITY_CAP)) {
        $role->add_cap(EDUSYSTEM_SIGNATURE_INTEGRITY_CAP);
    }
}

// Submenú de la sección de auditoría (Edusystem Logs), después de que se registre ese menú
add_action('admin_menu', 'edusystem_signature_integrity_menu', 20);
function edusystem_signature_integrity_menu(): void
{
    add_submenu_page(
        'edusystem-logs',
        __('Signature integrity', 'edusystem'),
        __('Signature integrity', 'edusystem'),
        EDUSYSTEM_SIGNATURE_INTEGRITY_CAP,
        'edusystem-signature-integrity',
        'edusystem_signature_integrity_page',
        20
    );
}

/** Comprueba permiso y nonce de una acción de la pantalla; corta con 403 si falla. */
function edusystem_signature_integrity_check_request(string $action): void
{
    if (!current_user_can(EDUSYSTEM_SIGNATURE_INTEGRITY_CAP)) {
        wp_die(esc_html__('You do not have permission to manage signature integrity.', 'edusystem'), 403);
    }
    check_admin_referer($action);
}

function edusystem_signature_integrity_redirect(string $notice): void
{
    wp_safe_redirect(add_query_arg(
        ['page' => 'edusystem-signature-integrity', 'notice' => $notice],
        admin_url('admin.php')
    ));
    exit;
}

add_action('admin_post_edusystem_signature_verify', 'edusystem_signature_integrity_handle_verify');
function edusystem_signature_integrity_handle_verify(): void
{
    edusystem_signature_integrity_check_request('edusystem_signature_verify');
    edusystem_signature_verify_chain();
    edusystem_signature_integrity_redirect('verified');
}

add_action('admin_post_edusystem_signature_rotate_key', 'edusystem_signature_integrity_handle_rotate');
function edusystem_signature_integrity_handle_rotate(): void
{
    edusystem_signature_integrity_check_request('edusystem_signature_rotate_key');
    edusystem_signature_add_key();
    edusystem_signature_integrity_redirect('rotated');
}

add_action('admin_post_edusystem_signature_legacy_csv', 'edusystem_signature_integrity_handle_csv');
function edusystem_signature_integrity_handle_csv(): void
{
    edusystem_signature_integrity_check_request('edusystem_signature_legacy_csv');

    if (function_exists('edusystem_set_log')) {
        edusystem_set_log('Exportado el diagnóstico de firmas antiguas (CSV)', 'signature_verification');
    }

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="firmas-antiguas-' . gmdate('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM: Excel abre bien los acentos
    fputcsv($out, ['signature_id', 'revoked', 'document', 'user_id', 'student_id', 'role', 'signed_at', 'automatic', 'same_request', 'minor', 'user_missing']);
    foreach (edusystem_signature_legacy_rows() as $row) {
        fputcsv($out, [
            $row['id'],
            $row['revoked'] ? 1 : 0,
            $row['document_id'],
            $row['user_id'],
            $row['student_id'] ?? '',
            $row['role'],
            $row['created_at'],
            $row['automatic'] ? 1 : 0,
            $row['same_request'] ? 1 : 0,
            $row['minor'] ? 1 : 0,
            $row['user_missing'] ? 1 : 0,
        ]);
    }
    fclose($out);
    exit;
}

function edusystem_signature_integrity_page(): void
{
    if (!current_user_can(EDUSYSTEM_SIGNATURE_INTEGRITY_CAP)) {
        wp_die(esc_html__('You do not have permission to manage signature integrity.', 'edusystem'), 403);
    }

    $enabled = edusystem_signature_evidence_enabled();
    $stored = edusystem_signature_stored_keys();
    $uses_config = defined('EDUSYSTEM_SIGNATURE_KEYS') && defined('EDUSYSTEM_SIGNATURE_KEY_ID')
        && null !== edusystem_signature_key_by_id((string) EDUSYSTEM_SIGNATURE_KEY_ID);
    $current_key_id = $uses_config ? (string) EDUSYSTEM_SIGNATURE_KEY_ID : (string) $stored['current'];
    $current_key = $stored['keys'][$current_key_id] ?? null;
    $last = get_option('edusystem_signature_last_verification');
    $legacy = $enabled ? edusystem_signature_legacy_rows() : [];
    $cutoff = (int) get_option('edusystem_signature_legacy_max_id', 0);
    $notice = isset($_GET['notice']) ? sanitize_key(wp_unslash($_GET['notice'])) : '';

    $count = static fn(string $flag): int => count(array_filter($legacy, static fn(array $row): bool => (bool) $row[$flag]));
    $date = static function (?string $utc): string {
        return $utc ? get_date_from_gmt($utc, get_option('date_format') . ' ' . get_option('time_format')) : '—';
    };
    $user_name = static function (int $user_id): string {
        $user = $user_id ? get_userdata($user_id) : null;
        return $user ? $user->display_name : ($user_id ? '#' . $user_id : '—');
    };

    include EDUSYSTEM_CERTIFICATION_PATH . 'admin/templates/signature-integrity.php';
}
