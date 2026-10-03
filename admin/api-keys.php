<?php
declare(strict_types=1);

/**
 * Certificación > Conexiones API: crear y revocar las claves con las que otros sistemas consultan esta plataforma
 * (includes/api-keys.php). Solo quien tiene squuad_cert_manage_api_keys (por defecto, el administrator).
 */

defined('ABSPATH') || exit;

const SQUUAD_CERT_API_KEYS_PAGE = 'squuad-cert-api-keys';

add_action('admin_menu', 'squuad_cert_api_keys_menu', 23);
function squuad_cert_api_keys_menu(): void
{
    if ('expired' === get_option('site_status_subscription')) {
        return;
    }
    add_submenu_page(
        'add_admin_form_certificates_content',
        esc_html__('API connections', 'wp-certificates'),
        esc_html__('API connections', 'wp-certificates'),
        'squuad_cert_manage_api_keys',
        SQUUAD_CERT_API_KEYS_PAGE,
        'squuad_cert_api_keys_page'
    );
}

/** Clave recién creada, para mostrarla una sola vez al usuario que la creó (unos minutos). */
function squuad_cert_api_keys_new_key(?string $key = null): ?string
{
    $name = 'squuad_cert_api_new_key_' . get_current_user_id();
    if (null !== $key) {
        set_transient($name, $key, 5 * MINUTE_IN_SECONDS);
        return null;
    }
    $value = get_transient($name);
    if (false !== $value) {
        delete_transient($name);
    }

    return is_string($value) ? $value : null;
}

function squuad_cert_api_keys_page(): void
{
    if (!current_user_can('squuad_cert_manage_api_keys')) {
        wp_die(esc_html__('Sorry, you are not allowed to access this page.', 'wp-certificates'), 403);
    }
    $keys = squuad_cert_api_keys();
    uasort($keys, static fn(array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']));
    $scopes = squuad_cert_api_scopes();
    $new_key = squuad_cert_api_keys_new_key();
    $notice = squuad_cert_signing_roles_notice();
    $endpoint = rest_url('squuad-cert/v1/documents/');
    include WP_C_PATH . 'admin/templates/api-keys.php';
}

add_action('admin_post_squuad_cert_api_key_create', 'squuad_cert_api_key_create_handle');
function squuad_cert_api_key_create_handle(): void
{
    if (!current_user_can('squuad_cert_manage_api_keys')) {
        wp_die(esc_html__('Sorry, you are not allowed to access this page.', 'wp-certificates'), 403);
    }
    check_admin_referer('squuad_cert_api_key_create');
    $scopes = isset($_POST['scopes']) && is_array($_POST['scopes']) ? array_map('sanitize_key', wp_unslash($_POST['scopes'])) : [];
    $result = squuad_cert_api_key_create((string) wp_unslash($_POST['name'] ?? ''), $scopes);
    if (is_wp_error($result)) {
        squuad_cert_signing_roles_notice($result->get_error_message(), false);
    } else {
        squuad_cert_api_keys_new_key($result['key']);
    }
    wp_safe_redirect(admin_url('admin.php?page=' . SQUUAD_CERT_API_KEYS_PAGE));
    exit;
}

add_action('admin_post_squuad_cert_api_key_revoke', 'squuad_cert_api_key_revoke_handle');
function squuad_cert_api_key_revoke_handle(): void
{
    if (!current_user_can('squuad_cert_manage_api_keys')) {
        wp_die(esc_html__('Sorry, you are not allowed to access this page.', 'wp-certificates'), 403);
    }
    $id = sanitize_key(wp_unslash($_POST['id'] ?? ''));
    check_admin_referer('squuad_cert_api_key_revoke_' . $id);
    squuad_cert_signing_roles_notice(squuad_cert_api_key_revoke($id)
        ? __('The key was revoked: it no longer works.', 'wp-certificates')
        : __('The key does not exist or was already revoked.', 'wp-certificates'), true);
    wp_safe_redirect(admin_url('admin.php?page=' . SQUUAD_CERT_API_KEYS_PAGE));
    exit;
}
