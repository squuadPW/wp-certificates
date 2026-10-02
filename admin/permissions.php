<?php
declare(strict_types=1);

/**
 * Certificación > Permisos: qué permisos de certificación tiene cada rol del sitio (includes/permissions.php). Solo
 * quien tiene squuad_cert_manage_permissions (por defecto, el administrator).
 */

defined('ABSPATH') || exit;

const SQUUAD_CERT_PERMISSIONS_PAGE = 'squuad-cert-permissions';

add_action('admin_menu', 'squuad_cert_permissions_menu', 22);
function squuad_cert_permissions_menu(): void
{
    if ('expired' === get_option('site_status_subscription')) {
        return;
    }
    add_submenu_page(
        'add_admin_form_certificates_content',
        esc_html__('Permissions', 'wp-certificates'),
        esc_html__('Permissions', 'wp-certificates'),
        'squuad_cert_manage_permissions',
        SQUUAD_CERT_PERMISSIONS_PAGE,
        'squuad_cert_permissions_page'
    );
}

function squuad_cert_permissions_page(): void
{
    if (!current_user_can('squuad_cert_manage_permissions')) {
        wp_die(esc_html__('Sorry, you are not allowed to access this page.', 'wp-certificates'), 403);
    }
    $roles = squuad_cert_site_roles();
    $selected = isset($_GET['role']) ? sanitize_text_field(wp_unslash($_GET['role'])) : '';
    $selected = isset($roles[$selected]) ? $selected : (string) array_key_first($roles);
    $role = get_role($selected);
    $capabilities = squuad_cert_capabilities();
    $users = count_users()['avail_roles'] ?? [];
    $notice = squuad_cert_signing_roles_notice();
    include WP_C_PATH . 'admin/templates/permissions.php';
}

add_action('admin_post_squuad_cert_permissions_save', 'squuad_cert_permissions_save_handle');
function squuad_cert_permissions_save_handle(): void
{
    if (!current_user_can('squuad_cert_manage_permissions')) {
        wp_die(esc_html__('Sorry, you are not allowed to access this page.', 'wp-certificates'), 403);
    }
    $role = sanitize_text_field(wp_unslash($_POST['role'] ?? ''));
    check_admin_referer('squuad_cert_permissions_save_' . $role);
    if (!get_role($role)) {
        wp_die(esc_html__('That role does not exist.', 'wp-certificates'), 400);
    }
    $caps = isset($_POST['caps']) && is_array($_POST['caps']) ? array_map('sanitize_key', wp_unslash($_POST['caps'])) : [];
    $changes = squuad_cert_permissions_save([$role => $caps]);
    squuad_cert_signing_roles_notice($changes ? __('Permissions saved.', 'wp-certificates') : __('No changes.', 'wp-certificates'));
    wp_safe_redirect(add_query_arg(['page' => SQUUAD_CERT_PERMISSIONS_PAGE, 'role' => $role], admin_url('admin.php')));
    exit;
}
