<?php
declare(strict_types=1);

/**
 * EduSystem - Registro por invitación de un firmante sin cuenta (ADR 0003, paso 3).
 *
 * El enlace del correo (?squuad_cert_invitation=TOKEN) permite, una sola vez y antes de que caduque, crear la
 * contraseña de la cuenta que se creó al invitar (rol Firmante, ligada al correo invitado). Después se inicia la
 * sesión y se va a "Mi firma" para dibujar la firma. Si la cuenta ya tiene contraseña, el enlace solo lleva al acceso.
 */

if (!defined('ABSPATH')) exit;

add_action('init', 'squuad_cert_signer_registration_handle', 20);
function squuad_cert_signer_registration_handle(): void
{
    if (!isset($_GET['squuad_cert_invitation']) || !function_exists('squuad_cert_signers_enabled') || !squuad_cert_signers_enabled()) {
        return;
    }
    header('Referrer-Policy: no-referrer'); // el token no debe salir en cabeceras hacia otros sitios
    nocache_headers();

    $token = sanitize_text_field(wp_unslash($_GET['squuad_cert_invitation']));
    $invitation = squuad_cert_signer_invitation_by_token($token);
    $user = $invitation ? get_userdata((int) $invitation->user_id) : null;
    if (!$invitation || !$user || 0 !== strcasecmp((string) $user->user_email, (string) $invitation->email_at_invite)) {
        squuad_cert_signer_registration_render(__('This invitation is not valid or has expired. Ask the administration to send it again.', 'wp-certificates'));
    }
    // La cuenta ya tiene contraseña: el enlace no vuelve a servir para crearla, solo lleva al acceso
    if (!get_user_meta($user->ID, 'squuad_cert_signer_needs_password', true)) {
        wp_safe_redirect(wp_login_url(add_query_arg('page', 'squuad-cert-my-signature', admin_url('admin.php'))));
        exit;
    }

    $error = '';
    if ('POST' === ($_SERVER['REQUEST_METHOD'] ?? '')) {
        $nonce_ok = wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? '')), 'squuad_cert_signer_register_' . $invitation->id);
        $password = (string) wp_unslash($_POST['password'] ?? '');
        $repeat = (string) wp_unslash($_POST['password_repeat'] ?? '');
        if (!$nonce_ok) {
            $error = __('Your session expired. Please try again.', 'wp-certificates');
        } elseif (strlen($password) < 10) {
            $error = __('The password must have at least 10 characters.', 'wp-certificates');
        } elseif ($password !== $repeat) {
            $error = __('The passwords do not match.', 'wp-certificates');
        } else {
            wp_set_password($password, $user->ID);
            delete_user_meta($user->ID, 'squuad_cert_signer_needs_password');
            squuad_cert_log(sprintf('El firmante invitado %d creó su contraseña con la invitación %d', $user->ID, $invitation->id), 'signer_invitation');
            wp_set_current_user($user->ID);
            wp_set_auth_cookie($user->ID);
            wp_safe_redirect(add_query_arg('page', 'squuad-cert-my-signature', admin_url('admin.php')));
            exit;
        }
    }

    squuad_cert_signer_registration_render('', $user, (int) $invitation->id, $error);
}

/** Página mínima de registro (sin el tema), con el correo fijo. Termina la petición. */
function squuad_cert_signer_registration_render(string $message, ?WP_User $user = null, int $invitation_id = 0, string $error = ''): void
{
    status_header($user ? 200 : 403);
    $site = get_bloginfo('name');
    ?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= esc_html(sprintf(__('Register your signature — %s', 'wp-certificates'), $site)) ?></title>
    <style>
        body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f0f0f1;color:#1d2327;margin:0;padding:40px 16px}
        .box{max-width:420px;margin:0 auto;background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:24px}
        label{display:block;margin:12px 0 4px;font-weight:600}
        input{width:100%;box-sizing:border-box;padding:8px;font-size:15px}
        button{margin-top:16px;padding:10px 16px;font-size:15px;background:#2271b1;color:#fff;border:0;border-radius:4px;cursor:pointer}
        .error{background:#fcf0f1;border-left:4px solid #d63638;padding:8px 12px;margin:12px 0}
    </style>
</head>
<body>
    <div class="box">
        <h1 style="font-size:20px"><?= esc_html($site) ?></h1>
        <?php if (!$user) : ?>
            <p><?= esc_html($message) ?></p>
        <?php else : ?>
            <p><?= esc_html(sprintf(__('Hello %s. Create your password to access your account; then you will draw your signature.', 'wp-certificates'), $user->display_name)) ?></p>
            <?php if ($error) : ?><div class="error"><?= esc_html($error) ?></div><?php endif; ?>
            <form method="post">
                <?php wp_nonce_field('squuad_cert_signer_register_' . $invitation_id); ?>
                <label><?= esc_html__('Email', 'wp-certificates') ?></label>
                <input type="email" value="<?= esc_attr($user->user_email) ?>" readonly disabled>
                <label for="password"><?= esc_html__('Password', 'wp-certificates') ?></label>
                <input type="password" id="password" name="password" required minlength="10" autocomplete="new-password">
                <label for="password_repeat"><?= esc_html__('Repeat the password', 'wp-certificates') ?></label>
                <input type="password" id="password_repeat" name="password_repeat" required minlength="10" autocomplete="new-password">
                <button type="submit"><?= esc_html__('Create password and continue', 'wp-certificates') ?></button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html><?php
    exit;
}
