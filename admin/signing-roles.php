<?php
declare(strict_types=1);

/**
 * Certificación > Roles que firman: el administrador del sitio (manage_options) marca qué roles pueden firmar
 * documentos (includes/signing-roles.php). Lista todos los roles del sitio, también los que crean otros plugins.
 */

defined('ABSPATH') || exit;

const SQUUAD_CERT_SIGNING_ROLES_PAGE = 'squuad-cert-signing-roles';

add_action('admin_menu', 'squuad_cert_signing_roles_menu', 21);
function squuad_cert_signing_roles_menu(): void
{
    if ('expired' === get_option('site_status_subscription')) {
        return;
    }
    add_submenu_page(
        'add_admin_form_certificates_content',
        esc_html__('Signing roles', 'wp-certificates'),
        esc_html__('Signing roles', 'wp-certificates'),
        'manage_options',
        SQUUAD_CERT_SIGNING_ROLES_PAGE,
        'squuad_cert_signing_roles_page'
    );
}

/** Aviso de una sola vez para el usuario actual (después de guardar). */
function squuad_cert_signing_roles_notice(?string $message = null, bool $ok = true): ?array
{
    $key = 'squuad_cert_signing_roles_notice_' . get_current_user_id();
    if (null !== $message) {
        set_transient($key, ['message' => $message, 'ok' => $ok], 60);
        return null;
    }
    $notice = get_transient($key);
    if (false !== $notice) {
        delete_transient($key);
    }

    return is_array($notice) ? $notice : null;
}

function squuad_cert_signing_roles_page(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Sorry, you are not allowed to access this page.', 'wp-certificates'), 403);
    }
    $roles = squuad_cert_site_roles();
    $enabled = squuad_cert_signing_roles();
    $users = count_users()['avail_roles'] ?? [];
    $notice = squuad_cert_signing_roles_notice();
    include WP_C_PATH . 'admin/templates/signing-roles.php';
}

add_action('admin_post_squuad_cert_signing_roles_save', 'squuad_cert_signing_roles_save_handle');
function squuad_cert_signing_roles_save_handle(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Sorry, you are not allowed to access this page.', 'wp-certificates'), 403);
    }
    check_admin_referer('squuad_cert_signing_roles_save');

    // Las claves de rol pueden tener espacios o mayúsculas (p. ej. "student services"): se comparan tal cual con las del
    // sitio en squuad_cert_signing_roles_save(), que descarta las que no existen
    $posted = isset($_POST['roles']) && is_array($_POST['roles']) ? array_map('sanitize_text_field', wp_unslash($_POST['roles'])) : [];
    $result = squuad_cert_signing_roles_save($posted);
    squuad_cert_signing_roles_notice($result['added'] || $result['removed']
        ? __('Signing roles saved. Signature requests already in progress keep their signers.', 'wp-certificates')
        : __('No changes.', 'wp-certificates'));
    wp_safe_redirect(admin_url('admin.php?page=' . SQUUAD_CERT_SIGNING_ROLES_PAGE));
    exit;
}
