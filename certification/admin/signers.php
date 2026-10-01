<?php
declare(strict_types=1);

/**
 * EduSystem - Firmantes del sistema en el admin (ADR 0003, paso 3).
 *
 * - "Users and signatures" (menú Certification de wp-certificates): con el esquema v6, EduSystem sustituye el
 *   contenido de esa misma página (mismo menú y URL) por la gestión de firmantes: buscar usuarios por nombre o
 *   correo, invitarlos (o invitar a quien no tiene cuenta a registrarse), reenviar, revocar y suspender. Las
 *   firmas-imagen heredadas se listan en solo lectura con "Invitar a este usuario". Las acciones antiguas de
 *   wp-certificates (guardar/borrar sin nonce) dejan de estar disponibles.
 * - "Mi firma": cada firmante registra su propia firma (dibujada, con contraseña y consentimiento).
 */

if (!defined('ABSPATH')) exit;

const EDUSYSTEM_SIGNERS_PAGE = 'add_admin_form_users_signatures_certificate_list_content';
const EDUSYSTEM_SIGNERS_PARENT = 'add_admin_form_certificates_content';

add_action('admin_menu', 'edusystem_signers_menu', 999);
function edusystem_signers_menu(): void
{
    global $submenu;

    if (!function_exists('edusystem_signers_enabled') || !edusystem_signers_enabled()) {
        return;
    }

    // Sustituir el contenido de "Users and signatures" de wp-certificates, en su misma URL
    $exists = false;
    foreach ((array) ($submenu[EDUSYSTEM_SIGNERS_PARENT] ?? []) as $item) {
        if (($item[2] ?? '') === EDUSYSTEM_SIGNERS_PAGE) {
            $exists = true;
            break;
        }
    }
    if ($exists) {
        $hook = get_plugin_page_hookname(EDUSYSTEM_SIGNERS_PAGE, EDUSYSTEM_SIGNERS_PARENT);
        remove_all_actions($hook);
        remove_submenu_page(EDUSYSTEM_SIGNERS_PARENT, EDUSYSTEM_SIGNERS_PAGE);
        add_submenu_page(
            EDUSYSTEM_SIGNERS_PARENT,
            __('Users and signatures', 'edusystem'),
            __('Users and signatures', 'edusystem'),
            'manager_users_signatures_certificate',
            EDUSYSTEM_SIGNERS_PAGE,
            'edusystem_signers_page',
            10
        );
    } else {
        // Sitio sin wp-certificates (o sin su menú): la gestión va en la sección de auditoría de EduSystem
        add_submenu_page('edusystem-logs', __('Signers', 'edusystem'), __('Signers', 'edusystem'), EDUSYSTEM_MANAGE_SIGNERS_CAP, EDUSYSTEM_SIGNERS_PAGE, 'edusystem_signers_page', 30);
    }

    // "Mi firma" para quien es firmante o tiene una invitación pendiente
    $user_id = get_current_user_id();
    if (edusystem_signer_by_user($user_id) || edusystem_signer_pending_invitation($user_id)) {
        add_menu_page(__('My signature', 'edusystem'), __('My signature', 'edusystem'), 'read', 'edusystem-my-signature', 'edusystem_my_signature_page', 'dashicons-edit', 3);
    }
}

/** Aviso para mostrar tras la redirección (por usuario, unos minutos). */
function edusystem_signers_notice(string $message, bool $ok): void
{
    set_transient('edusystem_signers_notice_' . get_current_user_id(), ['message' => $message, 'ok' => $ok], 300);
}

function edusystem_signers_take_notice(): ?array
{
    $key = 'edusystem_signers_notice_' . get_current_user_id();
    $notice = get_transient($key);
    delete_transient($key);

    return is_array($notice) ? $notice : null;
}

/** Comprueba nonce y permiso de gestión de firmantes (acciones por admin-post); corta con 403 si falla. */
function edusystem_signers_check_manage(string $action): void
{
    if (!current_user_can(EDUSYSTEM_MANAGE_SIGNERS_CAP)) {
        wp_die(esc_html__('You do not have permission to manage signers.', 'edusystem'), 403);
    }
    check_admin_referer($action);
}

function edusystem_signers_back(): void
{
    wp_safe_redirect(add_query_arg('page', EDUSYSTEM_SIGNERS_PAGE, admin_url('admin.php')));
    exit;
}

add_action('admin_post_edusystem_signer_invite', 'edusystem_signers_handle_invite');
function edusystem_signers_handle_invite(): void
{
    edusystem_signers_check_manage('edusystem_signer_invite');
    $user = get_userdata(absint($_POST['user_id'] ?? 0));
    $charge = sanitize_text_field(wp_unslash($_POST['charge'] ?? ''));
    $result = $user ? edusystem_signer_invite($user, $charge) : ['ok' => false, 'message' => __('The user does not exist.', 'edusystem')];
    edusystem_signers_notice($result['message'], $result['ok']);
    edusystem_signers_back();
}

add_action('admin_post_edusystem_signer_invite_new', 'edusystem_signers_handle_invite_new');
function edusystem_signers_handle_invite_new(): void
{
    edusystem_signers_check_manage('edusystem_signer_invite_new');
    $result = edusystem_signer_invite_new_account(
        (string) wp_unslash($_POST['name'] ?? ''),
        (string) wp_unslash($_POST['email'] ?? ''),
        sanitize_text_field(wp_unslash($_POST['charge'] ?? ''))
    );
    edusystem_signers_notice($result['message'], $result['ok']);
    edusystem_signers_back();
}

add_action('admin_post_edusystem_signer_resend', 'edusystem_signers_handle_resend');
function edusystem_signers_handle_resend(): void
{
    edusystem_signers_check_manage('edusystem_signer_resend');
    $signer = edusystem_signer_get(absint($_POST['signer_id'] ?? 0));
    $user = $signer ? get_userdata((int) $signer->user_id) : null;
    $result = $user
        ? edusystem_signer_invite($user, (string) $signer->charge, (bool) get_user_meta($user->ID, 'edusystem_signer_needs_password', true))
        : ['ok' => false, 'message' => __('The signer does not exist.', 'edusystem')];
    edusystem_signers_notice($result['message'], $result['ok']);
    edusystem_signers_back();
}

add_action('admin_post_edusystem_signer_revoke', 'edusystem_signers_handle_revoke');
function edusystem_signers_handle_revoke(): void
{
    global $wpdb;

    edusystem_signers_check_manage('edusystem_signer_revoke');
    $revoked = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}edusystem_signer_invitations SET status = 'revoked', revoked_at_utc = UTC_TIMESTAMP(), revoked_by = %d
         WHERE id = %d AND status = 'pending'",
        get_current_user_id(),
        absint($_POST['invitation_id'] ?? 0)
    ));
    if ($revoked && function_exists('edusystem_set_log')) {
        edusystem_set_log(sprintf('Invitación de firmante %d revocada', absint($_POST['invitation_id'] ?? 0)), 'signer_invitation');
    }
    edusystem_signers_notice($revoked ? __('Invitation revoked.', 'edusystem') : __('The invitation was not pending.', 'edusystem'), (bool) $revoked);
    edusystem_signers_back();
}

add_action('admin_post_edusystem_signer_status', 'edusystem_signers_handle_status');
function edusystem_signers_handle_status(): void
{
    global $wpdb;

    edusystem_signers_check_manage('edusystem_signer_status');
    $signer = edusystem_signer_get(absint($_POST['signer_id'] ?? 0));
    $suspend = 'suspend' === ($_POST['change'] ?? '');
    if (!$signer) {
        edusystem_signers_notice(__('The signer does not exist.', 'edusystem'), false);
        edusystem_signers_back();
    }
    // Suspender no cambia nada firmado: solo impide firmar hasta reactivar
    $new_status = $suspend ? 'suspended' : (edusystem_user_signature_active((int) $signer->user_id) ? 'active' : 'invited');
    $wpdb->update($wpdb->prefix . 'edusystem_signers', ['status' => $new_status, 'updated_at_utc' => gmdate('Y-m-d H:i:s')], ['id' => (int) $signer->id]);
    edusystem_signature_request_log_event(0, $suspend ? 'signer_suspended' : 'signer_reactivated', ['signer_id' => (int) $signer->id, 'user_id' => (int) $signer->user_id]);
    edusystem_signers_notice($suspend ? __('Signer suspended.', 'edusystem') : __('Signer reactivated.', 'edusystem'), true);
    edusystem_signers_back();
}

function edusystem_signers_page(): void
{
    global $wpdb;

    $can_manage = current_user_can(EDUSYSTEM_MANAGE_SIGNERS_CAP);
    $notice = edusystem_signers_take_notice();
    $search = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';

    $signers = $wpdb->get_results(
        "SELECT s.*, u.display_name, u.user_email,
            (SELECT COUNT(*) FROM {$wpdb->prefix}edusystem_user_signatures us WHERE us.user_id = s.user_id AND us.status = 'active') AS has_signature,
            (SELECT i.id FROM {$wpdb->prefix}edusystem_signer_invitations i WHERE i.signer_id = s.id AND i.status = 'pending' AND i.expires_at_utc > UTC_TIMESTAMP() ORDER BY i.id DESC LIMIT 1) AS pending_invitation_id,
            (SELECT i.expires_at_utc FROM {$wpdb->prefix}edusystem_signer_invitations i WHERE i.signer_id = s.id AND i.status = 'pending' ORDER BY i.id DESC LIMIT 1) AS invitation_expires
         FROM {$wpdb->prefix}edusystem_signers s LEFT JOIN {$wpdb->users} u ON u.ID = s.user_id ORDER BY s.id DESC"
    );

    $legacy = [];
    $legacy_table = $wpdb->prefix . 'users_signatures_certificate';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $legacy_table)) === $legacy_table) {
        $legacy = $wpdb->get_results("SELECT l.*, u.display_name, u.user_email FROM {$legacy_table} l LEFT JOIN {$wpdb->users} u ON u.ID = l.user_id ORDER BY l.id");
    }

    $results = [];
    if ($can_manage && '' !== $search) {
        $query = new WP_User_Query([
            'search' => '*' . $search . '*',
            'search_columns' => ['user_email', 'display_name', 'user_login', 'user_nicename'],
            'role__not_in' => ['student', 'parent'],
            'number' => 20,
            'orderby' => 'display_name',
        ]);
        $results = $query->get_results();
    }

    include EDUSYSTEM_CERTIFICATION_PATH . 'admin/templates/signers.php';
}

/* ---------------------------------------------------------------------------------------------------------------
 * Mi firma
 * ------------------------------------------------------------------------------------------------------------ */

add_action('admin_post_edusystem_save_my_signature', 'edusystem_signers_handle_save_my_signature');
function edusystem_signers_handle_save_my_signature(): void
{
    check_admin_referer('edusystem_save_my_signature');
    $result = edusystem_user_signature_register(
        (string) wp_unslash($_POST['strokes'] ?? ''),
        (string) wp_unslash($_POST['password'] ?? ''),
        !empty($_POST['consent'])
    );
    edusystem_signers_notice($result['message'], $result['ok']);
    wp_safe_redirect(add_query_arg('page', 'edusystem-my-signature', admin_url('admin.php')));
    exit;
}

add_action('admin_enqueue_scripts', 'edusystem_signers_assets');
function edusystem_signers_assets(string $hook): void
{
    if (false === strpos($hook, 'edusystem-my-signature')) {
        return;
    }
    $version = defined('VERSIONS_JS') ? VERSIONS_JS : EDUSYSTEM_VERSION;
    wp_enqueue_script('edusystem-signature-pad', EDUSYSTEM_CERTIFICATION_URL . 'admin/assets/js/signature-pad-edusystem.js', [], $version, true);
}

function edusystem_my_signature_page(): void
{
    $user = wp_get_current_user();
    $signer = edusystem_signer_by_user((int) $user->ID);
    $invitation = edusystem_signer_pending_invitation((int) $user->ID);
    $active = edusystem_user_signature_active((int) $user->ID);
    $notice = edusystem_signers_take_notice();
    $switched = function_exists('edusystem_signature_session_switched_from') && edusystem_signature_session_switched_from();
    $can_register = $signer && !in_array($signer->status, ['suspended', 'retired'], true) && ($invitation || 'active' === $signer->status) && !$switched;

    include EDUSYSTEM_CERTIFICATION_PATH . 'admin/templates/my-signature.php';
}
