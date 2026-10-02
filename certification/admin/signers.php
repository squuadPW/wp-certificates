<?php
declare(strict_types=1);

/**
 * EduSystem - Firmantes del sistema en el admin (ADR 0003, paso 3).
 *
 * - "Users and signatures" (menú Certification, misma URL que la antigua pantalla de firmas-imagen, retirada en el
 *   ADR 0004): gestión de firmantes: buscar usuarios por nombre o correo, invitarlos (o invitar a quien no tiene
 *   cuenta a registrarse), reenviar, revocar y suspender. Las firmas-imagen heredadas se listan en solo lectura con
 *   "Invitar a este usuario".
 * - "Mi firma": cada firmante registra su propia firma (dibujada, con contraseña y consentimiento).
 */

if (!defined('ABSPATH')) exit;

const SQUUAD_CERT_SIGNERS_PAGE = 'add_admin_form_users_signatures_certificate_list_content';
const SQUUAD_CERT_SIGNERS_PARENT = 'add_admin_form_certificates_content';

add_action('admin_menu', 'squuad_cert_signers_menu', 999);
function squuad_cert_signers_menu(): void
{
    global $submenu;

    if (!function_exists('squuad_cert_signers_enabled') || !squuad_cert_signers_enabled()) {
        return;
    }

    // "Users and signatures": la gestión de firmantes, en la URL de la pantalla antigua de firmas-imagen (retirada, ADR
    // 0004). Con el menú Certificación visible va en su sitio; si no (p. ej. suscripción caducada), como "Signers"
    if (!empty($submenu[SQUUAD_CERT_SIGNERS_PARENT])) {
        // En el sitio de siempre: antes de "ID card"
        $position = array_search('add_admin_form_cards_content', array_column($submenu[SQUUAD_CERT_SIGNERS_PARENT], 2), true);
        add_submenu_page(
            SQUUAD_CERT_SIGNERS_PARENT,
            __('Users and signatures', 'wp-certificates'),
            __('Users and signatures', 'wp-certificates'),
            'manager_users_signatures_certificate',
            SQUUAD_CERT_SIGNERS_PAGE,
            'squuad_cert_signers_page',
            false === $position ? null : (int) $position
        );
    } else {
        add_submenu_page(SQUUAD_CERT_SIGNERS_PARENT, __('Signers', 'wp-certificates'), __('Signers', 'wp-certificates'), SQUUAD_CERT_MANAGE_SIGNERS_CAP, SQUUAD_CERT_SIGNERS_PAGE, 'squuad_cert_signers_page', 30);
    }

    // "Mi firma" para quien es firmante o tiene una invitación pendiente
    $user_id = get_current_user_id();
    if (squuad_cert_signer_by_user($user_id) || squuad_cert_signer_pending_invitation($user_id)) {
        add_menu_page(__('My signature', 'wp-certificates'), __('My signature', 'wp-certificates'), 'read', 'squuad-cert-my-signature', 'squuad_cert_my_signature_page', 'dashicons-edit', 3);
    }
}

/** Aviso para mostrar tras la redirección (por usuario, unos minutos). */
function squuad_cert_signers_notice(string $message, bool $ok): void
{
    set_transient('squuad_cert_signers_notice_' . get_current_user_id(), ['message' => $message, 'ok' => $ok], 300);
}

function squuad_cert_signers_take_notice(): ?array
{
    $key = 'squuad_cert_signers_notice_' . get_current_user_id();
    $notice = get_transient($key);
    delete_transient($key);

    return is_array($notice) ? $notice : null;
}

/** Comprueba nonce y permiso de gestión de firmantes (acciones por admin-post); corta con 403 si falla. */
function squuad_cert_signers_check_manage(string $action): void
{
    if (!current_user_can(SQUUAD_CERT_MANAGE_SIGNERS_CAP)) {
        wp_die(esc_html__('You do not have permission to manage signers.', 'wp-certificates'), 403);
    }
    check_admin_referer($action);
}

function squuad_cert_signers_back(): void
{
    wp_safe_redirect(add_query_arg('page', SQUUAD_CERT_SIGNERS_PAGE, admin_url('admin.php')));
    exit;
}

add_action('admin_post_squuad_cert_signer_invite', 'squuad_cert_signers_handle_invite');
function squuad_cert_signers_handle_invite(): void
{
    squuad_cert_signers_check_manage('squuad_cert_signer_invite');
    $user = get_userdata(absint($_POST['user_id'] ?? 0));
    $charge = sanitize_text_field(wp_unslash($_POST['charge'] ?? ''));
    $result = $user ? squuad_cert_signer_invite($user, $charge) : ['ok' => false, 'message' => __('The user does not exist.', 'wp-certificates')];
    squuad_cert_signers_notice($result['message'], $result['ok']);
    squuad_cert_signers_back();
}

add_action('admin_post_squuad_cert_signer_invite_new', 'squuad_cert_signers_handle_invite_new');
function squuad_cert_signers_handle_invite_new(): void
{
    squuad_cert_signers_check_manage('squuad_cert_signer_invite_new');
    $result = squuad_cert_signer_invite_new_account(
        (string) wp_unslash($_POST['name'] ?? ''),
        (string) wp_unslash($_POST['email'] ?? ''),
        sanitize_text_field(wp_unslash($_POST['charge'] ?? ''))
    );
    squuad_cert_signers_notice($result['message'], $result['ok']);
    squuad_cert_signers_back();
}

add_action('admin_post_squuad_cert_signer_resend', 'squuad_cert_signers_handle_resend');
function squuad_cert_signers_handle_resend(): void
{
    squuad_cert_signers_check_manage('squuad_cert_signer_resend');
    $signer = squuad_cert_signer_get(absint($_POST['signer_id'] ?? 0));
    $user = $signer ? get_userdata((int) $signer->user_id) : null;
    $result = $user
        ? squuad_cert_signer_invite($user, (string) $signer->charge, (bool) get_user_meta($user->ID, 'squuad_cert_signer_needs_password', true))
        : ['ok' => false, 'message' => __('The signer does not exist.', 'wp-certificates')];
    squuad_cert_signers_notice($result['message'], $result['ok']);
    squuad_cert_signers_back();
}

add_action('admin_post_squuad_cert_signer_revoke', 'squuad_cert_signers_handle_revoke');
function squuad_cert_signers_handle_revoke(): void
{
    global $wpdb;

    squuad_cert_signers_check_manage('squuad_cert_signer_revoke');
    $revoked = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}squuad_cert_signer_invitations SET status = 'revoked', revoked_at_utc = UTC_TIMESTAMP(), revoked_by = %d
         WHERE id = %d AND status = 'pending'",
        get_current_user_id(),
        absint($_POST['invitation_id'] ?? 0)
    ));
    if ($revoked) {
        squuad_cert_log(sprintf('Invitación de firmante %d revocada', absint($_POST['invitation_id'] ?? 0)), 'signer_invitation');
    }
    squuad_cert_signers_notice($revoked ? __('Invitation revoked.', 'wp-certificates') : __('The invitation was not pending.', 'wp-certificates'), (bool) $revoked);
    squuad_cert_signers_back();
}

add_action('admin_post_squuad_cert_signer_status', 'squuad_cert_signers_handle_status');
function squuad_cert_signers_handle_status(): void
{
    global $wpdb;

    squuad_cert_signers_check_manage('squuad_cert_signer_status');
    $signer = squuad_cert_signer_get(absint($_POST['signer_id'] ?? 0));
    $suspend = 'suspend' === ($_POST['change'] ?? '');
    if (!$signer) {
        squuad_cert_signers_notice(__('The signer does not exist.', 'wp-certificates'), false);
        squuad_cert_signers_back();
    }
    // Suspender no cambia nada firmado: solo impide firmar hasta reactivar
    $new_status = $suspend ? 'suspended' : (squuad_cert_user_signature_active((int) $signer->user_id) ? 'active' : 'invited');
    $wpdb->update($wpdb->prefix . 'squuad_cert_signers', ['status' => $new_status, 'updated_at_utc' => gmdate('Y-m-d H:i:s')], ['id' => (int) $signer->id]);
    squuad_cert_signature_request_log_event(0, $suspend ? 'signer_suspended' : 'signer_reactivated', ['signer_id' => (int) $signer->id, 'user_id' => (int) $signer->user_id]);
    squuad_cert_signers_notice($suspend ? __('Signer suspended.', 'wp-certificates') : __('Signer reactivated.', 'wp-certificates'), true);
    squuad_cert_signers_back();
}

function squuad_cert_signers_page(): void
{
    global $wpdb;

    $can_manage = current_user_can(SQUUAD_CERT_MANAGE_SIGNERS_CAP);
    $notice = squuad_cert_signers_take_notice();
    $search = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';

    $signers = $wpdb->get_results(
        "SELECT s.*, u.display_name, u.user_email,
            (SELECT COUNT(*) FROM {$wpdb->prefix}squuad_cert_signer_signatures us WHERE us.user_id = s.user_id AND us.status = 'active') AS has_signature,
            (SELECT i.id FROM {$wpdb->prefix}squuad_cert_signer_invitations i WHERE i.signer_id = s.id AND i.status = 'pending' AND i.expires_at_utc > UTC_TIMESTAMP() ORDER BY i.id DESC LIMIT 1) AS pending_invitation_id,
            (SELECT i.expires_at_utc FROM {$wpdb->prefix}squuad_cert_signer_invitations i WHERE i.signer_id = s.id AND i.status = 'pending' ORDER BY i.id DESC LIMIT 1) AS invitation_expires
         FROM {$wpdb->prefix}squuad_cert_signers s LEFT JOIN {$wpdb->users} u ON u.ID = s.user_id ORDER BY s.id DESC"
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
            'role__not_in' => squuad_cert_signer_excluded_roles(),
            'number' => 20,
            'orderby' => 'display_name',
        ]);
        $results = $query->get_results();
    }

    include SQUUAD_CERT_MODULE_PATH . 'admin/templates/signers.php';
}

/* ---------------------------------------------------------------------------------------------------------------
 * Mi firma
 * ------------------------------------------------------------------------------------------------------------ */

add_action('admin_post_squuad_cert_save_my_signature', 'squuad_cert_signers_handle_save_my_signature');
function squuad_cert_signers_handle_save_my_signature(): void
{
    check_admin_referer('squuad_cert_save_my_signature');
    $result = squuad_cert_user_signature_register(
        (string) wp_unslash($_POST['strokes'] ?? ''),
        (string) wp_unslash($_POST['password'] ?? ''),
        !empty($_POST['consent'])
    );
    squuad_cert_signers_notice($result['message'], $result['ok']);
    wp_safe_redirect(add_query_arg('page', 'squuad-cert-my-signature', admin_url('admin.php')));
    exit;
}

add_action('admin_enqueue_scripts', 'squuad_cert_signers_assets');
function squuad_cert_signers_assets(string $hook): void
{
    if (false === strpos($hook, 'squuad-cert-my-signature')) {
        return;
    }
    $version = defined('WP_C_VERSION') ? WP_C_VERSION : null;
    wp_enqueue_script('edusystem-signature-pad', SQUUAD_CERT_MODULE_URL . 'admin/assets/js/signature-pad-edusystem.js', [], $version, true);
}

function squuad_cert_my_signature_page(): void
{
    $user = wp_get_current_user();
    $signer = squuad_cert_signer_by_user((int) $user->ID);
    $invitation = squuad_cert_signer_pending_invitation((int) $user->ID);
    $active = squuad_cert_user_signature_active((int) $user->ID);
    $notice = squuad_cert_signers_take_notice();
    $switched = function_exists('squuad_cert_signature_session_switched_from') && squuad_cert_signature_session_switched_from();
    $can_register = $signer && !in_array($signer->status, ['suspended', 'retired'], true) && ($invitation || 'active' === $signer->status) && !$switched;

    include SQUUAD_CERT_MODULE_PATH . 'admin/templates/my-signature.php';
}
