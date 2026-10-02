<?php
declare(strict_types=1);

/**
 * EduSystem - Pantalla "Integridad de firmas" (ADR 0001, paso 3): estado de la clave y rotación, resultado del
 * verificador de la cadena y diagnóstico de las firmas antiguas (con exportación CSV). Solo lectura salvo rotar
 * la clave y lanzar la verificación. Requiere el permiso squuad_cert_manage_signature_integrity.
 */

if (!defined('ABSPATH')) exit;

const SQUUAD_CERT_SIGNATURE_INTEGRITY_CAP = 'squuad_cert_manage_signature_integrity';

// Por defecto solo el administrador principal (rol administrator) tiene el permiso
add_action('admin_init', 'squuad_cert_signature_integrity_grant_cap');
function squuad_cert_signature_integrity_grant_cap(): void
{
    $role = get_role('administrator');
    if ($role && !$role->has_cap(SQUUAD_CERT_SIGNATURE_INTEGRITY_CAP)) {
        $role->add_cap(SQUUAD_CERT_SIGNATURE_INTEGRITY_CAP);
    }
}

// Submenú de Certificación, después de que se registre ese menú
add_action('admin_menu', 'squuad_cert_signature_integrity_menu', 20);
function squuad_cert_signature_integrity_menu(): void
{
    add_submenu_page(
        SQUUAD_CERT_SIGNERS_PARENT,
        __('Signature integrity', 'wp-certificates'),
        __('Signature integrity', 'wp-certificates'),
        SQUUAD_CERT_SIGNATURE_INTEGRITY_CAP,
        'squuad-cert-signature-integrity',
        'squuad_cert_signature_integrity_page',
        20
    );
}

/** Comprueba permiso y nonce de una acción de la pantalla; corta con 403 si falla. */
function squuad_cert_signature_integrity_check_request(string $action): void
{
    if (!current_user_can(SQUUAD_CERT_SIGNATURE_INTEGRITY_CAP)) {
        wp_die(esc_html__('You do not have permission to manage signature integrity.', 'wp-certificates'), 403);
    }
    check_admin_referer($action);
}

function squuad_cert_signature_integrity_redirect(string $notice): void
{
    wp_safe_redirect(add_query_arg(
        ['page' => 'squuad-cert-signature-integrity', 'notice' => $notice],
        admin_url('admin.php')
    ));
    exit;
}

add_action('admin_post_squuad_cert_signature_verify', 'squuad_cert_signature_integrity_handle_verify');
function squuad_cert_signature_integrity_handle_verify(): void
{
    squuad_cert_signature_integrity_check_request('squuad_cert_signature_verify');
    squuad_cert_signature_verify_chain();
    squuad_cert_signature_integrity_redirect('verified');
}

add_action('admin_post_squuad_cert_signature_rotate_key', 'squuad_cert_signature_integrity_handle_rotate');
function squuad_cert_signature_integrity_handle_rotate(): void
{
    squuad_cert_signature_integrity_check_request('squuad_cert_signature_rotate_key');
    squuad_cert_signature_add_key();
    squuad_cert_signature_integrity_redirect('rotated');
}

add_action('admin_post_squuad_cert_signature_legacy_csv', 'squuad_cert_signature_integrity_handle_csv');
function squuad_cert_signature_integrity_handle_csv(): void
{
    squuad_cert_signature_integrity_check_request('squuad_cert_signature_legacy_csv');

    squuad_cert_log('Exportado el diagnóstico de firmas antiguas (CSV)', 'signature_verification');

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="firmas-antiguas-' . gmdate('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM: Excel abre bien los acentos
    fputcsv($out, ['signature_id', 'document', 'user_id', 'signed_at', 'automatic', 'user_missing']);
    foreach (squuad_cert_signature_legacy_rows() as $row) {
        fputcsv($out, [
            $row['id'],
            $row['document_id'],
            $row['user_id'],
            $row['created_at'],
            $row['automatic'] ? 1 : 0,
            $row['user_missing'] ? 1 : 0,
        ]);
    }
    fclose($out);
    exit;
}

function squuad_cert_signature_integrity_page(): void
{
    if (!current_user_can(SQUUAD_CERT_SIGNATURE_INTEGRITY_CAP)) {
        wp_die(esc_html__('You do not have permission to manage signature integrity.', 'wp-certificates'), 403);
    }

    $enabled = squuad_cert_signature_evidence_enabled();
    $stored = squuad_cert_signature_stored_keys();
    $uses_config = defined('SQUUAD_CERT_SIGNATURE_KEYS') && defined('SQUUAD_CERT_SIGNATURE_KEY_ID')
        && null !== squuad_cert_signature_key_by_id((string) SQUUAD_CERT_SIGNATURE_KEY_ID);
    $current_key_id = $uses_config ? (string) SQUUAD_CERT_SIGNATURE_KEY_ID : (string) $stored['current'];
    $current_key = $stored['keys'][$current_key_id] ?? null;
    $last = get_option('squuad_cert_signature_last_verification');
    $legacy = $enabled ? squuad_cert_signature_legacy_rows() : [];
    $notice = isset($_GET['notice']) ? sanitize_key(wp_unslash($_GET['notice'])) : '';

    $count = static fn(string $flag): int => count(array_filter($legacy, static fn(array $row): bool => (bool) $row[$flag]));
    $date = static function (?string $utc): string {
        return $utc ? get_date_from_gmt($utc, get_option('date_format') . ' ' . get_option('time_format')) : '—';
    };
    $user_name = static function (int $user_id): string {
        $user = $user_id ? get_userdata($user_id) : null;
        return $user ? $user->display_name : ($user_id ? '#' . $user_id : '—');
    };

    include SQUUAD_CERT_MODULE_PATH . 'admin/templates/signature-integrity.php';
}
