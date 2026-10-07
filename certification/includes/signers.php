<?php
declare(strict_types=1);

/**
 * EduSystem - Firmantes del sistema (ADR 0003): firmantes registrados, invitaciones, firma propia registrada,
 * política de firmantes por documento y lotes.
 *
 * Paso 0-1: lectura segura de las firmas-imagen heredadas de wp-certificates y funciones base del esquema v6.
 */

if (!defined('ABSPATH')) exit;

/** Las tablas de firmantes, invitaciones, firmas propias, políticas y lotes llegan con la versión 6 del esquema. */
function squuad_cert_signers_enabled(): bool
{
    return version_compare((string) get_option('wp_c_db_version'), '9', '>=');
}

/**
 * Firma-imagen heredada de wp-certificates (users_signatures_certificate) por id, con SQL preparado propio: no se
 * usa get_user_signature_detail() de wp-certificates, que concatena el id en la consulta (y cuya versión varía por
 * cliente). EduSystem solo lee esta tabla, nunca escribe en ella (ADR 0003, punto 1). Devuelve null si no existe.
 */
function squuad_cert_legacy_institutional_signature(int $id): ?object
{
    global $wpdb;

    if ($id <= 0) {
        return null;
    }
    $table = $wpdb->prefix . 'users_signatures_certificate';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
        return null;
    }
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));

    return $row ?: null;
}

/** Firmante registrado por id, o null. */
function squuad_cert_signer_get(int $signer_id): ?object
{
    global $wpdb;

    if ($signer_id <= 0 || !squuad_cert_signers_enabled()) {
        return null;
    }
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}squuad_cert_signers WHERE id = %d", $signer_id));

    return $row ?: null;
}

/** Firmante registrado de un usuario, o null. */
function squuad_cert_signer_by_user(int $user_id): ?object
{
    global $wpdb;

    if ($user_id <= 0 || !squuad_cert_signers_enabled()) {
        return null;
    }
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}squuad_cert_signers WHERE user_id = %d", $user_id));

    return $row ?: null;
}

/** Clave del hueco de un firmante registrado en una solicitud ('signer:<id>'). */
function squuad_cert_signer_slot_key(int $signer_id): string
{
    return 'signer:' . $signer_id;
}

/**
 * Firmantes fijados en una solicitud, en orden: filas de squuad_cert_request_signers o, para solicitudes
 * sin módulo de firmantes, ninguno.
 * Cada elemento: ['slot_key', 'user_id', 'signer_id', 'name', 'charge', 'required', 'phase'].
 */
function squuad_cert_request_signers(object $request): array
{
    global $wpdb;

    if (squuad_cert_signers_enabled()) {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}squuad_cert_request_signers WHERE request_id = %d ORDER BY position ASC, id ASC",
            $request->id
        ));
        // Sin filas = nadie firma (p. ej. un automático que solo pide campos adicionales): no se deduce al estudiante
        return array_map(static fn($row): array => [
            'slot_key' => (string) $row->slot_key,
            'user_id' => (int) $row->user_id,
            'signer_id' => (int) $row->signer_id,
            'name' => (string) $row->name_snapshot,
            'charge' => (string) $row->charge_snapshot,
            'required' => (bool) $row->required,
            'phase' => (int) $row->phase,
        ], (array) $rows);
    }

    return [];
}

/**
 * Puestos por variable omitidos al fijar los firmantes de una solicitud (ADR 0009 de Edusof), para sellarlos en su
 * evento 'created'. squuad_cert_request_signers_fix() los guarda ($set) y quien crea la solicitud los lee (sin $set);
 * solo duran la petición.
 */
function squuad_cert_request_signers_omitted(int $request_id, ?array $set = null): array
{
    static $omitted = [];
    if (null !== $set) {
        $omitted[$request_id] = $set;
    }

    return $omitted[$request_id] ?? [];
}

/**
 * Mapa Fn → puesto de la plantilla al crear una solicitud (ADR 0010 de Edusof), para sellarlo en su evento 'created'
 * (solo si la plantilla usa variables por firmante numeradas). Igual que los omitidos: solo dura la petición.
 */
function squuad_cert_request_signers_fn_map(int $request_id, ?array $set = null): array
{
    static $maps = [];
    if (null !== $set) {
        $maps[$request_id] = $set;
    }

    return $maps[$request_id] ?? [];
}

/**
 * Fija los firmantes de una solicitud recién creada (esquema v6) según la política del documento: el estudiante y los
 * firmantes del sistema, con el nombre de la cuenta de cada uno en ese momento. Guarda también
 * el documento y el origen en la solicitud. Devuelve la lista fijada para sellarla en el evento 'created' (solo puestos:
 * los puestos por variable omitidos se leen aparte con squuad_cert_request_signers_omitted()), o [] si el esquema no
 * está en v6. En un puesto por variable cada elemento lleva además el método que resolvió la variable y su fase.
 */
function squuad_cert_request_signers_fix(object $request, array $signers, int $document_certificate_id = 0, string $origin = 'opened'): array
{
    global $wpdb;

    if (!squuad_cert_signers_enabled()) {
        return [];
    }
    // La cuenta que firma su puesto (la dueña del documento); 0 en un documento emitido desde la ficha
    $student_user_id = (int) ($signers['holder_user_id'] ?? 0);

    // Política del documento (paso 4): qué huecos y en qué orden; sin documento, el estudiante
    $document = $document_certificate_id ? $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}documents_certificates WHERE id = %d",
        $document_certificate_id
    )) : null;
    $policy = $document ? squuad_cert_signing_policy($document) : [
        'slots' => [['slot_type' => 'role', 'signer_id' => 0, 'role' => 'student']],
        'policy_sha256' => null,
    ];
    // Un documento automático sin variable de firma no pide firma aunque el panel tenga firmantes: solo lo rellena el
    // estudiante (campos adicionales). ADR 0004, decisión del 2026-10-01
    if ($document && 'automatic' === $document->type && !squuad_cert_document_has_signature_variable($document)) {
        $policy['slots'] = [];
    }

    // Puesto de quien recibe el documento (firma por roles, paso 3d): el primer rol de la política que tenga su cuenta.
    // Una cuenta con varios roles de la política firma una sola vez
    $holder = $student_user_id ? get_userdata($student_user_id) : false;
    $holder_role = $holder ? squuad_cert_signing_policy_role_for_user($policy + ['requires_signatures' => true], $holder) : '';

    $slots = [];
    foreach ($policy['slots'] as $policy_slot) {
        if ('role' === $policy_slot['slot_type']) {
            if ('' !== $holder_role && $holder_role === $policy_slot['role']) {
                $slots[] = ['slot_key' => 'role:' . $holder_role, 'user_id' => $student_user_id, 'signer_id' => 0, 'charge' => '', 'phase' => 1];
            }
        } elseif ('signer' === $policy_slot['slot_type'] && squuad_cert_signer_inbox_enabled()) {
            // Firmantes institucionales: fase 2 (después de quien recibe el documento), cuando ya tienen su panel
            $signer = squuad_cert_signer_get((int) $policy_slot['signer_id']);
            if ($signer && !in_array($signer->status, ['suspended', 'retired'], true)) {
                $slots[] = ['slot_key' => squuad_cert_signer_slot_key((int) $signer->id), 'user_id' => (int) $signer->user_id, 'signer_id' => (int) $signer->id, 'charge' => (string) $signer->charge, 'phase' => 2];
            }
        } elseif ('var' === $policy_slot['slot_type']) {
            // Firmante por variable (ADR 0009 de Edusof): la cuenta que da la variable para el titular de esta solicitud
            $slots[] = squuad_cert_request_var_slot($request, $document, (string) $policy_slot['role']);
        }
    }
    // Una persona firma una sola vez, fases por el orden del panel y puestos omitidos al registro de actividad. Sin
    // puestos por variable, el resultado es el de siempre (rol en la fase 1, firmantes del sistema en la 2)
    [$slots, $omitted] = squuad_cert_request_slots_finalize($request, $slots);

    $fixed = [];
    // Variables por firmante numeradas (ADR 0010 de Edusof): si la plantilla las usa, los datos de cada persona se fijan
    // y se sellan ahora en 'created' (nombre, correo, documento de identidad y puesto en el idioma del sitio)
    $numbered = $document && function_exists('squuad_cert_fn_template_uses') && squuad_cert_fn_template_uses(squuad_cert_fn_document_template($document));
    foreach ($slots as $position => $slot) {
        $user = get_userdata($slot['user_id']);
        $name = $user ? trim($user->first_name . ' ' . $user->last_name) ?: $user->display_name : '';
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->prefix}squuad_cert_request_signers
                (request_id, position, slot_key, user_id, signer_id, name_snapshot, charge_snapshot, required, phase)
             VALUES (%d, %d, %s, %d, %d, %s, %s, 1, %d)",
            $request->id,
            $position,
            $slot['slot_key'],
            $slot['user_id'],
            $slot['signer_id'],
            $name,
            $slot['charge'],
            $slot['phase']
        ));
        $fixed[] = ['slot' => $slot['slot_key'], 'user_id' => $slot['user_id'], 'name' => $name] + ($slot['charge'] ? ['charge' => $slot['charge']] : [])
            // Firmante por variable (ADR 0009 de Edusof): con qué método se resolvió la cuenta y en qué fase firma
            + (squuad_cert_is_var_slot($slot['slot_key']) ? ['method' => (string) ($slot['method'] ?? ''), 'phase' => (int) $slot['phase']] : [])
            + ($numbered ? squuad_cert_fn_created_person($slot, $name) : []);
    }
    // Mapa Fn → puesto con el que se rellena esta solicitud (ADR 0010 de Edusof), sellado en 'created'
    if ($numbered && $fixed) {
        squuad_cert_request_signers_fn_map((int) $request->id, squuad_cert_fn_map($document));
    }

    $wpdb->update($wpdb->prefix . 'squuad_cert_requests', [
        'document_certificate_id' => $document_certificate_id ?: null,
        // system: la creó otro plugin con squuad_cert_request_issue_for_holder() (p. ej. EduSystem al vincular la cuenta
        // del estudiante); restarted: la volvió a pedir Admisión (ADR 0012 de Edusof)
        'origin' => in_array($origin, ['opened', 'issued', 'system', 'restarted'], true) ? $origin : 'opened',
        'policy_sha256' => $policy['policy_sha256'],
    ], ['id' => (int) $request->id]);

    // Los puestos por variable omitidos (variable vacía o inválida, misma cuenta) quedan sellados en el evento 'created'
    squuad_cert_request_signers_omitted((int) $request->id, $omitted);

    return $fixed;
}


/* ---------------------------------------------------------------------------------------------------------------
 * Paso 3: rol Firmante, invitaciones y firma propia registrada
 * ------------------------------------------------------------------------------------------------------------ */

const SQUUAD_CERT_SIGNER_ROLE = 'squuad_cert_signer';
const SQUUAD_CERT_SIGN_DOCUMENTS_CAP = 'squuad_cert_sign_documents';
const SQUUAD_CERT_MANAGE_SIGNERS_CAP = 'squuad_cert_manage_signers';
const SQUUAD_CERT_SIGNER_INVITATION_HOURS = 72;
const SQUUAD_CERT_SIGNER_PROFILE_CONSENT = 'v1-perfil';

/**
 * Rol "Firmante" para quien se registra por invitación: solo entrar a su panel, registrar su firma y firmar sus
 * documentos. El permiso de gestionar firmantes lo tiene el administrador. Idempotente.
 */
add_action('admin_init', 'squuad_cert_signers_register_roles');
function squuad_cert_signers_register_roles(): void
{
    if (!get_role(SQUUAD_CERT_SIGNER_ROLE)) {
        add_role(SQUUAD_CERT_SIGNER_ROLE, __('Signer', 'wp-certificates'), ['read' => true, SQUUAD_CERT_SIGN_DOCUMENTS_CAP => true]);
    }
    $admin = get_role('administrator');
    if ($admin && !$admin->has_cap(SQUUAD_CERT_MANAGE_SIGNERS_CAP)) {
        $admin->add_cap(SQUUAD_CERT_MANAGE_SIGNERS_CAP);
    }
}

/**
 * WooCommerce saca del admin a quien no puede editar entradas: el Firmante (y quien tenga una invitación pendiente)
 * necesita entrar a "Mi firma" y a sus documentos por firmar.
 */
add_filter('woocommerce_prevent_admin_access', 'squuad_cert_signers_allow_admin_access');
function squuad_cert_signers_allow_admin_access($prevent)
{
    $user_id = get_current_user_id();
    if ($prevent && $user_id && squuad_cert_signers_enabled()
        && (user_can($user_id, SQUUAD_CERT_SIGN_DOCUMENTS_CAP) || squuad_cert_signer_by_user($user_id))) {
        return false;
    }

    return $prevent;
}

/**
 * Roles que no pueden ser firmantes del sistema: los de Certificación > Signing roles (firman lo suyo, como dueños del
 * documento) y siempre el rol student.
 *
 * @return string[]
 */
function squuad_cert_signer_excluded_roles(): array
{
    return array_values(array_unique(array_merge(squuad_cert_signing_roles(), ['student'])));
}

/** ¿Puede este usuario ser firmante del sistema? Cualquiera que no tenga uno de los roles que firman lo suyo. */
function squuad_cert_signer_user_is_eligible(WP_User $user): bool
{
    return !array_intersect((array) $user->roles, squuad_cert_signer_excluded_roles());
}

/** Texto del consentimiento para registrar la firma propia (versión v1-perfil), traducido. */
function squuad_cert_signer_profile_consent_text(): string
{
    return __('I register this drawing as my electronic signature. I understand that it will only be used when I expressly sign each document requested from me, that each use is recorded with the date, the time and the details of my connection, and that nobody else can use it.', 'wp-certificates');
}

/** HMAC del token de una invitación con la clave del sitio (en la BD nunca se guarda el token). */
function squuad_cert_signer_token_hmac(string $token, ?string $key = null): string
{
    if (null === $key) {
        $current = function_exists('squuad_cert_signature_current_key') ? squuad_cert_signature_current_key() : null;
        $key = $current ? $current[1] : wp_salt('auth');
    }

    return hash_hmac('sha256', 'squuad-cert-signer-invitation|' . $token, $key);
}

/** Invitación pendiente y vigente por token (se prueba con todas las claves del sitio, por si se rotó), o null. */
function squuad_cert_signer_invitation_by_token(string $token): ?object
{
    global $wpdb;

    if (!squuad_cert_signers_enabled() || !preg_match('/^[A-Za-z0-9_-]{40,64}$/', $token)) {
        return null;
    }
    $keys = [];
    $current = squuad_cert_signature_current_key();
    if ($current) {
        $keys[] = $current[1]; // también la de wp-config.php, si el sitio la usa
    }
    foreach (squuad_cert_signature_stored_keys()['keys'] as $key_id => $unused) {
        $bytes = squuad_cert_signature_key_by_id((string) $key_id);
        if (null !== $bytes) {
            $keys[] = $bytes;
        }
    }
    foreach ($keys as $key) {
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}squuad_cert_signer_invitations
             WHERE token_hmac = %s AND status = 'pending' AND expires_at_utc > UTC_TIMESTAMP()",
            squuad_cert_signer_token_hmac($token, $key)
        ));
        if ($row) {
            return $row;
        }
    }

    return null;
}

/** Invitación pendiente y vigente de un usuario (la más reciente), o null. */
function squuad_cert_signer_pending_invitation(int $user_id): ?object
{
    global $wpdb;

    if (!$user_id || !squuad_cert_signers_enabled()) {
        return null;
    }
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}squuad_cert_signer_invitations
         WHERE user_id = %d AND status = 'pending' AND expires_at_utc > UTC_TIMESTAMP() ORDER BY id DESC LIMIT 1",
        $user_id
    ));

    return $row ?: null;
}

/** Firma propia activa de un usuario, o null. */
function squuad_cert_user_signature_active(int $user_id): ?object
{
    global $wpdb;

    if (!$user_id || !squuad_cert_signers_enabled()) {
        return null;
    }
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}squuad_cert_signer_signatures WHERE user_id = %d AND status = 'active' ORDER BY id DESC LIMIT 1",
        $user_id
    ));

    return $row ?: null;
}

/**
 * Invita a un usuario a registrar su firma como firmante del sistema. Si ya tenía una invitación pendiente, se
 * revoca y se emite otra (reenvío). Envía el correo. Devuelve ['ok' => bool, 'message' => string].
 * $new_account: el usuario se creó en esta misma invitación (el correo lleva el enlace para crear su contraseña).
 */
function squuad_cert_signer_invite(WP_User $user, string $charge, bool $new_account = false): array
{
    global $wpdb;

    if (!squuad_cert_signers_enabled()) {
        return ['ok' => false, 'message' => __('The signers module is not available yet.', 'wp-certificates')];
    }
    if (!squuad_cert_signer_user_is_eligible($user)) {
        return ['ok' => false, 'message' => (wpc_edusystem_active() ? __('Students and parents cannot be invited as signers.', 'wp-certificates') : __('This user has a role that signs its own documents (see "Signing roles"): they cannot be a system signer.', 'wp-certificates'))];
    }
    $charge = mb_substr(trim($charge), 0, 191);
    $now = gmdate('Y-m-d H:i:s');
    $actor = get_current_user_id();

    $signer = squuad_cert_signer_by_user((int) $user->ID);
    if (!$signer) {
        $wpdb->insert($wpdb->prefix . 'squuad_cert_signers', [
            'user_id' => (int) $user->ID,
            'charge' => $charge,
            'status' => 'invited',
            'created_by' => $actor,
            'created_at_utc' => $now,
        ]);
        $signer = squuad_cert_signer_by_user((int) $user->ID);
    } elseif ('' !== $charge && $charge !== (string) $signer->charge) {
        $wpdb->update($wpdb->prefix . 'squuad_cert_signers', ['charge' => $charge, 'updated_at_utc' => $now], ['id' => (int) $signer->id]);
    }
    if (!$signer) {
        return ['ok' => false, 'message' => __('The signer could not be saved.', 'wp-certificates')];
    }
    if ('suspended' === $signer->status || 'retired' === $signer->status) {
        return ['ok' => false, 'message' => __('This signer is suspended.', 'wp-certificates')];
    }

    // Reenvío: la invitación anterior deja de valer
    $previous = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(sent_count), 0) FROM {$wpdb->prefix}squuad_cert_signer_invitations WHERE user_id = %d AND created_at_utc > UTC_TIMESTAMP() - INTERVAL 1 DAY",
        $user->ID
    ));
    if ($previous >= 5) {
        return ['ok' => false, 'message' => __('Too many invitations were sent to this user today. Try again tomorrow.', 'wp-certificates')];
    }
    $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}squuad_cert_signer_invitations SET status = 'revoked', revoked_at_utc = UTC_TIMESTAMP(), revoked_by = %d
         WHERE user_id = %d AND status = 'pending'",
        $actor,
        $user->ID
    ));

    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $wpdb->insert($wpdb->prefix . 'squuad_cert_signer_invitations', [
        'signer_id' => (int) $signer->id,
        'user_id' => (int) $user->ID,
        'email_at_invite' => (string) $user->user_email,
        'token_hmac' => squuad_cert_signer_token_hmac($token),
        'status' => 'pending',
        'expires_at_utc' => gmdate('Y-m-d H:i:s', time() + SQUUAD_CERT_SIGNER_INVITATION_HOURS * HOUR_IN_SECONDS),
        'sent_count' => 1,
        'last_sent_at_utc' => $now,
        'created_by' => $actor,
        'created_at_utc' => $now,
    ]);
    $invitation_id = (int) $wpdb->insert_id;
    if ($signer->status !== 'active') {
        $wpdb->update($wpdb->prefix . 'squuad_cert_signers', ['status' => 'invited', 'updated_at_utc' => $now], ['id' => (int) $signer->id]);
    }

    $sent = squuad_cert_signer_send_invitation_email($user, $token, $new_account);
    squuad_cert_log(sprintf('Invitación de firmante %d enviada al usuario %d (%s)%s', $invitation_id, $user->ID, $user->user_email, $sent ? '' : ' — el correo falló'), 'signer_invitation');

    return ['ok' => true, 'message' => $sent
        ? sprintf(__('Invitation sent to %s.', 'wp-certificates'), $user->user_email)
        : __('The invitation was created, but the email could not be sent.', 'wp-certificates')];
}

/** Correo de invitación: sin datos de alumnos ni inicio de sesión automático. */
function squuad_cert_signer_send_invitation_email(WP_User $user, string $token, bool $new_account): bool
{
    $site = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
    $link = $new_account
        ? add_query_arg('squuad_cert_invitation', $token, home_url('/'))
        : add_query_arg(['page' => 'squuad-cert-my-signature'], admin_url('admin.php'));
    $hours = SQUUAD_CERT_SIGNER_INVITATION_HOURS;

    $subject = sprintf(__('[%s] Invitation to register your signature', 'wp-certificates'), $site);
    $body = sprintf(__('Hello %s,', 'wp-certificates'), $user->display_name) . "\n\n"
        . sprintf(__('%s invites you to register your electronic signature to sign the documents that require it.', 'wp-certificates'), $site) . "\n\n"
        . ($new_account
            ? __('Open this link to create your password and then draw your signature:', 'wp-certificates')
            : __('Sign in with your account and draw your signature here:', 'wp-certificates'))
        . "\n" . $link . "\n\n"
        . sprintf(__('The link expires in %d hours and can only be used once. If you were not expecting this invitation, ignore this email.', 'wp-certificates'), $hours) . "\n";

    return (bool) wp_mail($user->user_email, $subject, $body);
}

/**
 * Crea la cuenta de alguien que no tiene usuario (rol Firmante, sin contraseña utilizable hasta que la cree con el
 * enlace de la invitación) y lo invita. Devuelve ['ok', 'message'].
 */
function squuad_cert_signer_invite_new_account(string $name, string $email, string $charge): array
{
    $email = sanitize_email($email);
    $name = sanitize_text_field($name);
    if (!is_email($email) || '' === $name) {
        return ['ok' => false, 'message' => __('Write a valid name and email.', 'wp-certificates')];
    }
    if (email_exists($email)) {
        return ['ok' => false, 'message' => __('That email already has an account: search for it and invite it from the results.', 'wp-certificates')];
    }
    $login = sanitize_user(current(explode('@', $email)), true) ?: 'signer';
    $base = $login;
    for ($i = 2; username_exists($login); $i++) {
        $login = $base . $i;
    }
    $parts = explode(' ', $name, 2);
    $user_id = wp_insert_user([
        'user_login' => $login,
        'user_email' => $email,
        'user_pass' => wp_generate_password(32, true, true),
        'display_name' => $name,
        'first_name' => $parts[0],
        'last_name' => $parts[1] ?? '',
        'role' => SQUUAD_CERT_SIGNER_ROLE,
    ]);
    if (is_wp_error($user_id)) {
        return ['ok' => false, 'message' => $user_id->get_error_message()];
    }
    update_user_meta($user_id, 'squuad_cert_signer_needs_password', 1);

    return squuad_cert_signer_invite(get_userdata($user_id), $charge, true);
}

/**
 * Valida los trazos de una firma dibujada (formato de los recuadros de firma: [{penColor, points: [{x, y, time,
 * pressure}]}]) y devuelve el JSON normalizado que se guarda, o null si no es válida.
 */
function squuad_cert_signer_normalize_strokes($data): ?string
{
    if (!is_array($data) || !$data || count($data) > 100) {
        return null;
    }
    $strokes = [];
    $total = 0;
    foreach ($data as $stroke) {
        if (!is_array($stroke) || !isset($stroke['points']) || !is_array($stroke['points']) || !$stroke['points']) {
            return null;
        }
        $points = [];
        foreach ($stroke['points'] as $point) {
            if (!is_array($point) || !isset($point['x'], $point['y']) || !is_numeric($point['x']) || !is_numeric($point['y'])) {
                return null;
            }
            $x = round((float) $point['x'], 2);
            $y = round((float) $point['y'], 2);
            if ($x < 0 || $y < 0 || $x > 5000 || $y > 5000) {
                return null;
            }
            $points[] = ['x' => $x, 'y' => $y, 'time' => (int) ($point['time'] ?? 0), 'pressure' => round((float) ($point['pressure'] ?? 0.5), 3)];
            $total++;
        }
        $color = is_string($stroke['penColor'] ?? null) && preg_match('/^(#[0-9a-fA-F]{3,8}|black|rgb\([0-9, ]+\))$/', $stroke['penColor']) ? $stroke['penColor'] : 'black';
        $strokes[] = ['penColor' => $color, 'points' => $points];
    }
    if ($total < 10 || $total > 10000) {
        return null; // una firma real tiene trazos; un punto suelto no es una firma
    }

    return (string) wp_json_encode($strokes);
}

/**
 * Registra la firma propia del usuario actual (ADR 0003, punto 3): solo en su sesión, sin Switch, con su contraseña
 * y consentimiento. Si ya tenía una, la anterior pasa a 'replaced' (los documentos ya firmados no cambian). Acepta la
 * invitación pendiente, activa al firmante, le da el permiso de firmar documentos y deja un evento sellado.
 * Devuelve ['ok', 'message'].
 */
function squuad_cert_user_signature_register(string $strokes_json_input, string $password, bool $consent): array
{
    global $wpdb;

    $user = wp_get_current_user();
    if (!$user->ID || !squuad_cert_signers_enabled()) {
        return ['ok' => false, 'message' => __('You are not allowed to register a signature.', 'wp-certificates')];
    }
    if (function_exists('squuad_cert_signature_session_switched_from') && squuad_cert_signature_session_switched_from()) {
        return ['ok' => false, 'message' => __('A signature cannot be registered from a switched session.', 'wp-certificates')];
    }
    $signer = squuad_cert_signer_by_user((int) $user->ID);
    $invitation = squuad_cert_signer_pending_invitation((int) $user->ID);
    if (!$signer || (!$invitation && 'active' !== $signer->status)) {
        return ['ok' => false, 'message' => __('You do not have a pending signer invitation.', 'wp-certificates')];
    }
    if ($invitation && 0 !== strcasecmp((string) $invitation->email_at_invite, (string) $user->user_email)) {
        return ['ok' => false, 'message' => __('This invitation was sent to another email.', 'wp-certificates')];
    }
    if (in_array($signer->status, ['suspended', 'retired'], true)) {
        return ['ok' => false, 'message' => __('This signer is suspended.', 'wp-certificates')];
    }
    if (!wp_check_password($password, $user->user_pass, $user->ID)) {
        return ['ok' => false, 'message' => __('The password is not correct.', 'wp-certificates')];
    }
    if (!$consent) {
        return ['ok' => false, 'message' => __('You must accept registering your signature.', 'wp-certificates')];
    }
    $strokes = squuad_cert_signer_normalize_strokes(json_decode($strokes_json_input, true));
    if (null === $strokes) {
        return ['ok' => false, 'message' => __('Draw your signature in the box before saving.', 'wp-certificates')];
    }

    $key = squuad_cert_signature_current_key();
    $ip = isset($_SERVER['REMOTE_ADDR']) ? substr(sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])), 0, 45) : '';
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 255) : '';
    $token = wp_get_session_token();
    $consent_sha256 = hash('sha256', squuad_cert_signer_profile_consent_text());
    $now = gmdate('Y-m-d H:i:s');
    $previous = squuad_cert_user_signature_active((int) $user->ID);

    $wpdb->insert($wpdb->prefix . 'squuad_cert_signer_signatures', [
        'user_id' => (int) $user->ID,
        'strokes' => $strokes,
        'strokes_sha256' => hash('sha256', $strokes),
        'status' => 'active',
        'invitation_id' => $invitation ? (int) $invitation->id : null,
        'consent_version' => SQUUAD_CERT_SIGNER_PROFILE_CONSENT,
        'consent_sha256' => $consent_sha256,
        'session_hash' => '' === $token ? '' : hash('sha256', $token),
        'ip_hmac' => $key ? hash_hmac('sha256', $ip, $key[1]) : null,
        'ua_sha256' => hash('sha256', $ua),
        'created_at_utc' => $now,
    ]);
    $signature_id = (int) $wpdb->insert_id;
    if (!$signature_id) {
        return ['ok' => false, 'message' => __('Your signature could not be saved.', 'wp-certificates')];
    }
    if ($previous) {
        $wpdb->update($wpdb->prefix . 'squuad_cert_signer_signatures', ['status' => 'replaced', 'replaced_by' => $signature_id], ['id' => (int) $previous->id]);
    }
    if ($invitation) {
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}squuad_cert_signer_invitations SET status = 'accepted', accepted_at_utc = UTC_TIMESTAMP()
             WHERE id = %d AND status = 'pending'",
            $invitation->id
        ));
    }
    $wpdb->update($wpdb->prefix . 'squuad_cert_signers', ['status' => 'active', 'updated_at_utc' => $now], ['id' => (int) $signer->id]);
    if (!user_can($user, SQUUAD_CERT_SIGN_DOCUMENTS_CAP)) {
        $user->add_cap(SQUUAD_CERT_SIGN_DOCUMENTS_CAP);
    }

    // Evento sellado en la cadena (EDUEVT1, sin solicitud): quién registró qué trazos, con qué consentimiento y cargo
    squuad_cert_signature_request_log_event(0, $previous ? 'profile_signature_replaced' : 'profile_signature_created', [
        'user_id' => (int) $user->ID,
        'user_signature_id' => $signature_id,
        'strokes_sha256' => hash('sha256', $strokes),
        'consent_version' => SQUUAD_CERT_SIGNER_PROFILE_CONSENT . ':' . determine_locale(),
        'consent_sha256' => $consent_sha256,
        'invitation_id' => $invitation ? (int) $invitation->id : 0,
        'replaced_signature_id' => $previous ? (int) $previous->id : 0,
        'charge' => (string) $signer->charge,
    ], (int) $user->ID);
    squuad_cert_log(sprintf('Firma propia %d registrada por el usuario %d%s', $signature_id, $user->ID, $previous ? ' (sustituye a la ' . (int) $previous->id . ')' : ''), 'signer_signature');

    return ['ok' => true, 'message' => __('Your signature was registered.', 'wp-certificates')];
}

/* ---------------------------------------------------------------------------------------------------------------
 * Paso 4: firmantes por documento (política versionada)
 * ------------------------------------------------------------------------------------------------------------ */

const SQUUAD_CERT_MANAGE_SIGNING_POLICIES_CAP = 'squuad_cert_manage_signing_policies';

add_action('admin_init', 'squuad_cert_signing_policies_grant_cap');
function squuad_cert_signing_policies_grant_cap(): void
{
    $admin = get_role('administrator');
    if ($admin && !$admin->has_cap(SQUUAD_CERT_MANAGE_SIGNING_POLICIES_CAP)) {
        $admin->add_cap(SQUUAD_CERT_MANAGE_SIGNING_POLICIES_CAP);
    }
}

/**
 * ¿Pueden ya firmar los firmantes institucionales? Hasta que exista su panel de "Documentos por firmar" (paso 5),
 * la política los guarda pero no se exigen en las solicitudes, para que ningún documento quede esperando una firma
 * que todavía no se puede dar.
 */
function squuad_cert_signer_inbox_enabled(): bool
{
    return (bool) apply_filters('squuad_cert_signer_inbox_enabled', true); // paso 5: panel "Documentos por firmar" activo
}

/**
 * Política de firmantes vigente de un documento de documents_certificates:
 * ['document_certificate_id', 'policy_id' (0 = por defecto), 'requires_signatures', 'slots' => [['slot_type',
 * 'signer_id', 'position', 'required']], 'policy_sha256'].
 * Sin fila guardada se calcula al leer (no se escribe): documento automático → estudiante y representante (como
 * hasta ahora); managed → no pide firmas de usuarios (camino heredado).
 */
function squuad_cert_signing_policy(object $document): array
{
    global $wpdb;

    $document_certificate_id = (int) $document->id;
    $row = squuad_cert_signers_enabled() ? $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}squuad_cert_signing_policies
         WHERE document_certificate_id = %d AND is_current = 1 ORDER BY id DESC LIMIT 1",
        $document_certificate_id
    )) : null;

    if ($row) {
        $slots = [];
        foreach ((array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}squuad_cert_signing_slots WHERE policy_id = %d ORDER BY position ASC, id ASC",
            $row->id
        )) as $slot) {
            // Puestos de antes de la firma por roles: 'student' es el rol student; 'parent' ya no firma
            $type = (string) $slot->slot_type;
            $role = (string) ($slot->role_key ?? '');
            if ('student' === $type) {
                [$type, $role] = ['role', 'student'];
            } elseif (!in_array($type, ['role', 'signer', 'var'], true)) {
                continue;
            }
            $slots[] = [
                'slot_type' => $type,
                'signer_id' => (int) $slot->signer_id,
                'role' => $role,
                'position' => (int) $slot->position,
                'required' => (bool) $slot->required,
            ];
        }

        return [
            'document_certificate_id' => $document_certificate_id,
            'policy_id' => (int) $row->id,
            'requires_signatures' => (bool) $row->requires_signatures,
            'slots' => $slots,
            'policy_sha256' => (string) $row->policy_sha256,
        ];
    }

    $automatic = 'automatic' === ($document->type ?? '');
    // Sin configurar, un automático lo firma el rol student si está entre los roles que pueden firmar
    $slots = $automatic && squuad_cert_signing_role_enabled('student') ? [
        ['slot_type' => 'role', 'signer_id' => 0, 'role' => 'student', 'position' => 1, 'required' => true],
    ] : [];

    return [
        'document_certificate_id' => $document_certificate_id,
        'policy_id' => 0,
        'requires_signatures' => $automatic,
        'slots' => $slots,
        'policy_sha256' => squuad_cert_signing_policy_hash($document_certificate_id, $automatic, $slots),
    ];
}

/** Huella de una política (documento, si pide firmas y huecos en orden). */
function squuad_cert_signing_policy_hash(int $document_certificate_id, bool $requires, array $slots): string
{
    return hash('sha256', (string) wp_json_encode([
        'document_certificate_id' => $document_certificate_id,
        'requires_signatures' => $requires,
        'slots' => array_map(static fn(array $slot): array => [$slot['slot_type'], (int) $slot['signer_id'], (int) $slot['position'], (string) ($slot['role'] ?? '')], $slots),
    ]));
}

/**
 * ¿La política incluye este puesto? $slot_type: 'signer' (algún firmante del sistema), 'role' (algún rol) o 'student'
 * (el rol student; se mantiene mientras el resto del módulo pasa a la firma por roles, pasos 3c y 3d).
 */
function squuad_cert_signing_policy_has(array $policy, string $slot_type): bool
{
    if (!$policy['requires_signatures']) {
        return false;
    }
    foreach ($policy['slots'] as $slot) {
        if ($slot['slot_type'] === $slot_type || ('student' === $slot_type && 'role' === $slot['slot_type'] && 'student' === $slot['role'])) {
            return true;
        }
    }

    return false;
}

/** Primer rol de la política que tiene la cuenta (quien recibe el documento), o '' si no tiene ninguno. */
function squuad_cert_signing_policy_role_for_user(array $policy, WP_User $user): string
{
    $user_roles = (array) $user->roles;
    foreach (squuad_cert_signing_policy_roles($policy) as $role) {
        if (in_array($role, $user_roles, true) && squuad_cert_signing_role_enabled($role)) {
            return $role;
        }
    }

    return '';
}

/** ¿Es el puesto de quien recibe el documento? 'role:<rol>' (y 'student' de solicitudes anteriores a la firma por roles). */
function squuad_cert_is_holder_slot(string $slot_key): bool
{
    return 0 === strpos($slot_key, 'role:') || 'student' === $slot_key;
}

/** Puesto de quien recibe el documento en una solicitud ('role:<rol>'), o '' si no lo tiene. */
function squuad_cert_request_holder_slot(object $request): string
{
    foreach (squuad_cert_request_signers($request) as $signer) {
        if (squuad_cert_is_holder_slot($signer['slot_key'])) {
            return $signer['slot_key'];
        }
    }

    return '';
}

/** Nombre del rol de un puesto de quien recibe el documento (p. ej. "Student"). */
function squuad_cert_holder_slot_label(string $slot_key): string
{
    $role = 0 === strpos($slot_key, 'role:') ? substr($slot_key, 5) : 'student';

    return (string) (squuad_cert_site_roles()[$role] ?? $role);
}

/** Roles que la política pide que firmen (claves de rol), en orden. */
function squuad_cert_signing_policy_roles(array $policy): array
{
    if (!$policy['requires_signatures']) {
        return [];
    }

    return array_values(array_map(
        static fn(array $slot): string => (string) $slot['role'],
        array_filter($policy['slots'], static fn(array $slot): bool => 'role' === $slot['slot_type'])
    ));
}

/**
 * Guarda una versión nueva de la política de un documento (la anterior queda como no vigente, nunca se borra) y deja
 * un evento sellado. $slots: [['slot_type' => 'role'|'signer', 'role', 'signer_id', 'position']]. Solo roles marcados
 * en Certificación > Signing roles y firmantes registrados que no estén suspendidos. Devuelve ['ok', 'message'].
 */
function squuad_cert_signing_policy_save(int $document_certificate_id, bool $requires, array $slots): array
{
    global $wpdb;

    if (!squuad_cert_signers_enabled() || $document_certificate_id <= 0) {
        return ['ok' => false, 'message' => __('The signers module is not available yet.', 'wp-certificates')];
    }
    $clean = [];
    $seen = [];
    foreach ($slots as $slot) {
        $type = (string) ($slot['slot_type'] ?? '');
        $signer_id = 'signer' === $type ? (int) ($slot['signer_id'] ?? 0) : 0;
        // En un firmante por variable (ADR 0009 de Edusof), role guarda el nombre de la variable
        $role = in_array($type, ['role', 'var'], true) ? (string) ($slot['role'] ?? '') : '';
        if (!in_array($type, ['role', 'signer', 'var'], true) || ('signer' === $type && !$signer_id)
            || ('role' === $type && !squuad_cert_signing_role_enabled($role))
            || ('var' === $type && !squuad_cert_signer_variable_name_valid($role))) {
            continue;
        }
        if ('signer' === $type) {
            $signer = squuad_cert_signer_get($signer_id);
            if (!$signer || in_array($signer->status, ['suspended', 'retired'], true)) {
                continue;
            }
        }
        $key = $type . ':' . ('signer' === $type ? $signer_id : $role);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $clean[] = ['slot_type' => $type, 'signer_id' => $signer_id, 'role' => $role, 'position' => (int) ($slot['position'] ?? 0)];
    }
    // Turno de firma (ADR 0012 de Edusof): el orden elegido en el panel, también para quien recibe el documento (p. ej.
    // el representante firma antes que el estudiante). Empate: quien recibe el documento primero y el firmante del
    // sistema después, como antes (las políticas guardadas antes tenían los roles delante y dan el mismo orden)
    usort($clean, static fn(array $a, array $b): int => [$a['position'], $a['slot_type'] !== 'role', $a['slot_type'] === 'signer'] <=> [$b['position'], $b['slot_type'] !== 'role', $b['slot_type'] === 'signer']);
    foreach ($clean as $i => $slot) {
        $clean[$i]['position'] = $i + 1;
    }
    // Un firmante del sistema firma lo que ya fijó una persona (su bandeja exige el contenido congelado): si firman
    // personas, el primer turno es de una de ellas (ADR 0012 de Edusof)
    if ($requires && $clean && 'signer' === $clean[0]['slot_type']
        && array_filter($clean, static fn(array $slot): bool => 'signer' !== $slot['slot_type'])) {
        return ['ok' => false, 'message' => __('A system signer cannot have the first turn: the first turn belongs to a person who receives the document or signs by variable. Change the signing turns.', 'wp-certificates')];
    }
    if ($requires && !$clean) {
        return ['ok' => false, 'message' => __('Choose at least one signer, or mark that the document does not require signatures.', 'wp-certificates')];
    }
    if (!$requires) {
        $clean = [];
    }

    $hash = squuad_cert_signing_policy_hash($document_certificate_id, $requires, $clean);
    $current = $wpdb->get_row($wpdb->prepare(
        "SELECT id, policy_sha256 FROM {$wpdb->prefix}squuad_cert_signing_policies WHERE document_certificate_id = %d AND is_current = 1 ORDER BY id DESC LIMIT 1",
        $document_certificate_id
    ));
    if ($current && hash_equals((string) $current->policy_sha256, $hash)) {
        return ['ok' => true, 'message' => __('No changes.', 'wp-certificates')];
    }

    $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}squuad_cert_signing_policies SET is_current = 0 WHERE document_certificate_id = %d AND is_current = 1",
        $document_certificate_id
    ));
    $wpdb->insert($wpdb->prefix . 'squuad_cert_signing_policies', [
        'document_certificate_id' => $document_certificate_id,
        'requires_signatures' => $requires ? 1 : 0,
        'is_current' => 1,
        'policy_sha256' => $hash,
        'updated_by' => get_current_user_id(),
        'updated_at_utc' => gmdate('Y-m-d H:i:s'),
    ]);
    $policy_id = (int) $wpdb->insert_id;
    foreach ($clean as $slot) {
        $wpdb->insert($wpdb->prefix . 'squuad_cert_signing_slots', [
            'policy_id' => $policy_id,
            'position' => $slot['position'],
            'slot_type' => $slot['slot_type'],
            'signer_id' => $slot['signer_id'],
            'role_key' => $slot['role'],
            'required' => 1,
        ]);
    }

    squuad_cert_signature_request_log_event(0, 'signing_policy_changed', [
        'document_certificate_id' => $document_certificate_id,
        'policy_id' => $policy_id,
        'policy_sha256' => $hash,
        'requires_signatures' => $requires,
        'slots' => array_map(static fn(array $slot): string => $slot['slot_type'] . ('signer' !== $slot['slot_type'] ? ':' . $slot['role'] : ($slot['signer_id'] ? ':' . $slot['signer_id'] : '')), $clean),
    ]);
    squuad_cert_log(sprintf('Firmantes del documento %d cambiados (política %d): %s', $document_certificate_id, $policy_id,
            $requires ? implode(', ', array_map(static fn(array $slot): string => $slot['slot_type'] . ('signer' !== $slot['slot_type'] ? ':' . $slot['role'] : ($slot['signer_id'] ? ':' . $slot['signer_id'] : '')), $clean)) : 'no pide firmas'), 'signing_policy');

    return ['ok' => true, 'message' => __('Document signers saved. They apply to new signature requests; requests already in progress keep their signers.', 'wp-certificates')];
}

/* ---------------------------------------------------------------------------------------------------------------
 * Paso 5: fases, bandeja del firmante, firmas en SVG y firma institucional
 * ------------------------------------------------------------------------------------------------------------ */

/** Marcador fijo del hueco de un firmante institucional en el contenido ('signer:<id>'). */
function squuad_cert_signer_slot_marker(string $slot_key): string
{
    return '<div data-edusig-slot="' . esc_attr($slot_key) . '"></div>';
}

/**
 * ¿Puede firmar ya este hueco? Las fases se cumplen en orden: un hueco de fase N solo se abre cuando están firmados
 * todos los huecos obligatorios de fases anteriores (fase 1: estudiante y representante; fase 2: institucionales).
 */
function squuad_cert_signature_request_slot_open(object $request, string $slot_key): bool
{
    $signers = squuad_cert_request_signers($request);
    $phase = 1;
    foreach ($signers as $signer) {
        if ($signer['slot_key'] === $slot_key) {
            $phase = $signer['phase'];
        }
    }
    $signed = squuad_cert_signature_request_signed_roles((int) $request->id);
    foreach ($signers as $signer) {
        if ($signer['required'] && $signer['phase'] < $phase && !in_array($signer['slot_key'], $signed, true)) {
            return false;
        }
    }

    return true;
}

/**
 * ¿Quedan firmas después de la de quien recibe el documento (firmantes del sistema y, ADR 0009 de Edusof, firmantes por
 * variable) sin dar en la solicitud? Entonces el PDF final lo genera quien firme el último.
 */
function squuad_cert_signature_request_institutional_pending(object $request): bool
{
    $signed = squuad_cert_signature_request_signed_roles((int) $request->id);
    foreach (squuad_cert_request_signers($request) as $signer) {
        $after_holder = squuad_cert_is_signer_slot($signer['slot_key']) || squuad_cert_is_var_slot($signer['slot_key']);
        if ($after_holder && $signer['required'] && !in_array($signer['slot_key'], $signed, true)) {
            return true;
        }
    }

    return false;
}

/**
 * Documentos que esperan la firma de un usuario como firmante institucional: solicitudes abiertas y congeladas en las
 * que tiene un hueco sin firmar cuya fase ya está abierta. Devuelve filas con request, slot_key, título del documento
 * y nombre del estudiante.
 */
function squuad_cert_signer_inbox(int $user_id): array
{
    global $wpdb;

    if (!$user_id || !squuad_cert_signers_enabled()) {
        return [];
    }
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT r.*, rs.slot_key, rs.charge_snapshot, d.title AS document_title
         FROM {$wpdb->prefix}squuad_cert_request_signers rs
         JOIN {$wpdb->prefix}squuad_cert_requests r ON r.id = rs.request_id
         LEFT JOIN {$wpdb->prefix}documents_certificates d ON d.id = r.document_certificate_id
         WHERE rs.user_id = %d AND rs.slot_key LIKE 'signer:%%' AND r.status IN ('open', 'partially_signed') AND r.frozen_at_utc IS NOT NULL
         ORDER BY r.frozen_at_utc ASC, r.id ASC",
        $user_id
    ));
    $inbox = [];
    foreach ($rows as $row) {
        if (in_array($row->slot_key, squuad_cert_signature_request_signed_roles((int) $row->id), true)) {
            continue;
        }
        if (!squuad_cert_signature_request_slot_open($row, (string) $row->slot_key)) {
            continue;
        }
        // Titular del documento (la cuenta o la ficha), sin leer las tablas de EduSystem
        $row->student_name = squuad_cert_request_subject_label($row);
        $row->student_last_name = '';
        $inbox[] = $row;
    }

    return $inbox;
}

/**
 * Firma dibujada (JSON de trazos, o ["automatic"] con el nombre) como SVG estático, para mostrarla en el documento
 * final y en el PDF sin depender de los recuadros del navegador. Escala los trazos al recuadro. Una firma v2 (ADR 0011
 * de Edusof) sale como imagen PNG (escrita o subida) o como SVG de sus trazos (dibujada).
 */
function squuad_cert_signature_svg(string $signature_json, string $name = '', int $width = 260, int $height = 90): string
{
    $data = json_decode($signature_json, true);
    // Formato v2 (ADR 0011 de Edusof): escrita o imagen subida (PNG recodificado en el servidor) o trazos dibujados
    if (is_array($data) && 2 === ($data['v'] ?? null) && function_exists('squuad_cert_signature_v2_html')) {
        return squuad_cert_signature_v2_html(squuad_cert_signature_info($signature_json), $name, $width, $height);
    }
    if (['automatic'] === $data) {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '" role="img" aria-label="' . esc_attr($name) . '">'
            . '<text x="50%" y="65%" text-anchor="middle" font-family="Great Vibes, cursive" font-size="28">' . esc_html($name) . '</text></svg>';
    }
    if (!is_array($data) || !$data) {
        return '';
    }
    $xs = [];
    $ys = [];
    foreach ($data as $stroke) {
        foreach ((array) ($stroke['points'] ?? []) as $point) {
            $xs[] = (float) ($point['x'] ?? 0);
            $ys[] = (float) ($point['y'] ?? 0);
        }
    }
    if (!$xs) {
        return '';
    }
    $min_x = min($xs);
    $min_y = min($ys);
    $w = max(max($xs) - $min_x, 1.0);
    $h = max(max($ys) - $min_y, 1.0);
    $scale = min(($width - 10) / $w, ($height - 10) / $h);
    $off_x = ($width - $w * $scale) / 2;
    $off_y = ($height - $h * $scale) / 2;

    $paths = '';
    foreach ($data as $stroke) {
        $points = (array) ($stroke['points'] ?? []);
        if (!$points) {
            continue;
        }
        $d = '';
        foreach ($points as $i => $point) {
            $x = round(((float) $point['x'] - $min_x) * $scale + $off_x, 1);
            $y = round(((float) $point['y'] - $min_y) * $scale + $off_y, 1);
            $d .= ($i ? ' L' : 'M') . $x . ' ' . $y;
        }
        $color = preg_match('/^(#[0-9a-fA-F]{3,8}|black|rgb\([0-9, ]+\))$/', (string) ($stroke['penColor'] ?? '')) ? $stroke['penColor'] : 'black';
        $paths .= '<path d="' . esc_attr($d) . '" fill="none" stroke="' . esc_attr($color) . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>';
    }

    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="' . esc_attr($name) . '">' . $paths . '</svg>';
}

/**
 * Variables de plantilla de los firmantes institucionales de una solicitud (fase 2), en su orden:
 * {{signature}}, {{user_sign}}, {{position_user_charge}} (el primero) y {{signature_N}}, {{user_sign_N}},
 * {{position_user_charge_N}}. La firma es un marcador fijo que se pinta al mostrar el documento.
 */
function squuad_cert_signature_institutional_replacements(object $request): array
{
    $replacements = [];
    $n = 0;
    foreach (squuad_cert_request_signers($request) as $signer) {
        if (!squuad_cert_is_signer_slot($signer['slot_key'])) {
            continue;
        }
        $n++;
        $values = [
            'signature' => ['value' => squuad_cert_signer_slot_marker($signer['slot_key']), 'wrap' => false],
            'user_sign' => ['value' => esc_html($signer['name']), 'wrap' => true],
            'position_user_charge' => ['value' => esc_html($signer['charge']), 'wrap' => true],
        ];
        foreach ($values as $key => $value) {
            $replacements[$key . '_' . $n] = $value;
            if (1 === $n) {
                $replacements[$key] = $value;
            }
        }
    }

    return $replacements;
}

/**
 * Variables de firma de una solicitud, cada firmante por separado (además de {{signature_section}}, que se mantiene):
 *   {{signature_role_<rol>}}, {{signature_student}}  recuadro de quien recibe el documento (vacío si no firma)
 *   {{signature_parent}}                            siempre vacío: el representante ya no firma (se mantiene para que
 *                                                   las plantillas antiguas no muestren el texto de la variable)
 *   {{signature_signer_ID}}, {{signer_name_ID}}, {{signer_charge_ID}}   firmante del sistema ID (su número en
 *                                                   "Firmantes del documento"); también {{signature}} / {{signature_N}}
 * y las reglas para la plantilla ({{#regla}}...{{/regla}} si se cumple, {{^regla}}...{{/regla}} si no):
 *   requires_student_signature; requires_parent_signature y student_is_own_parent son siempre falsas (el representante
 *   ya no firma, decisión del 2026-10-01).
 */
function squuad_cert_signature_signer_replacements(object $request): array
{
    $replacements = squuad_cert_signature_institutional_replacements($request);
    $slots = [];
    foreach (squuad_cert_request_signers($request) as $signer) {
        $slots[] = $signer['slot_key'];
        if (squuad_cert_is_signer_slot($signer['slot_key']) && $signer['signer_id']) {
            $id = (int) $signer['signer_id'];
            $replacements['signature_signer_' . $id] = ['value' => squuad_cert_signer_slot_marker($signer['slot_key']), 'wrap' => false];
            $replacements['signer_name_' . $id] = ['value' => esc_html($signer['name']), 'wrap' => true];
            $replacements['signer_charge_' . $id] = ['value' => esc_html($signer['charge']), 'wrap' => true];
        }
    }
    // Firma por roles: {{signature_role_<rol>}} lleva el recuadro de quien recibe el documento si ese es su rol; los
    // demás roles quedan vacíos (cada usuario recibe su propio documento). {{signature_student}} es el del rol student
    $holder = squuad_cert_request_holder_slot($request);
    $marker = '' !== $holder ? squuad_cert_signer_slot_marker($holder) : '';
    foreach (squuad_cert_signing_roles() as $role) {
        $replacements[squuad_cert_signing_role_variable($role)] = ['value' => 'role:' . $role === $holder ? $marker : '', 'wrap' => false];
    }
    $replacements['signature_student'] = ['value' => in_array($holder, ['role:student', 'student'], true) ? $marker : '', 'wrap' => false];
    $replacements['signature_parent'] = ['value' => '', 'wrap' => false];
    // Regla de plantilla: {{#requires_student_signature}} se cumple si quien recibe el documento lo firma
    $replacements['requires_student_signature'] = ['value' => '' !== $holder, 'wrap' => false];
    $replacements['requires_parent_signature'] = ['value' => false, 'wrap' => false];
    $replacements['student_is_own_parent'] = ['value' => false, 'wrap' => false];

    // Firmantes por variable (ADR 0009 de Edusof): {{signature_var_X}}, {{signer_name_var_X}}, {{signer_charge_var_X}}
    $replacements = array_merge($replacements, squuad_cert_signature_var_replacements($request));

    // Variables por firmante numeradas (ADR 0010 de Edusof): {{full_name_F2}}, {{signature_F3}}, {{#F2}}…
    return function_exists('squuad_cert_fn_replacements') ? array_merge($replacements, squuad_cert_fn_replacements($request)) : $replacements;
}

/**
 * Quita las variables de firmantes del sistema que la plantilla usa pero que no firman esta solicitud (p. ej. el
 * firmante se quitó del documento), para que no quede el texto {{signature_signer_ID}} en el documento.
 */
function squuad_cert_signature_strip_unused_signer_tags(string $html): string
{
    // También las de un firmante por variable omitido o que no está en el panel (ADR 0009 de Edusof)
    $html = (string) preg_replace(SQUUAD_CERT_SIGNER_VAR_TAG_PATTERN, '', $html);
    // Y las numeradas de un firmante que no está en la solicitud (ADR 0010 de Edusof): {{full_name_F4}} sin firmante
    if (defined('SQUUAD_CERT_FN_TAG_PATTERN')) {
        $html = (string) preg_replace('/\{\{(?:full_name|name|last_name|email|id_document|charge|signature)_F[1-9][0-9]?\}\}/', '', $html);
    }

    return (string) preg_replace('/\{\{(?:signature_signer|signer_name|signer_charge)_\d+\}\}/', '', $html);
}

/**
 * Si la plantilla no coloca las firmas institucionales ({{signature}} / {{signature_N}}), se añade al final un bloque
 * "Firmas" con un hueco por firmante, para que ninguna firma quede fuera del documento.
 */
function squuad_cert_signature_append_institutional_block(string $html, object $request): string
{
    $missing = [];
    foreach (squuad_cert_request_signers($request) as $signer) {
        // Firmantes del sistema y firmantes por variable (ADR 0009 de Edusof) que la plantilla no coloca
        $placed_apart = squuad_cert_is_signer_slot($signer['slot_key']) || squuad_cert_is_var_slot($signer['slot_key']);
        if ($placed_apart && false === strpos($html, squuad_cert_signer_slot_marker($signer['slot_key']))) {
            $missing[] = $signer;
        }
    }
    if (!$missing) {
        return $html;
    }
    // Bloque de firma común (ADR 0011 de Edusof): el marcador se pinta al mostrar el documento con la firma sobre la línea,
    // el nombre, el puesto y la fecha. Los contenidos ya congelados conservan su bloque anterior (marcador + nombre + cargo)
    $block = '<div class="edusystem-institutional-signatures" style="margin-top:24px;display:flex;flex-wrap:wrap;gap:4px 0">';
    foreach ($missing as $signer) {
        $block .= function_exists('squuad_cert_signature_block_marker')
            ? squuad_cert_signature_block_marker($signer['slot_key'])
            : '<div style="min-width:260px;text-align:center">' . squuad_cert_signer_slot_marker($signer['slot_key'])
                . '<div style="border-top:1px solid #333;margin-top:4px;padding-top:4px"><strong>' . esc_html($signer['name']) . '</strong><br>'
                . esc_html($signer['charge']) . '</div></div>';
    }

    return $html . $block . '</div>';
}

/**
 * Documento de una solicitud con las firmas pintadas en el servidor: los huecos institucionales con su SVG (o
 * "pendiente") y la sección de estudiante/representante con sus SVG. Sirve para ver el documento en el panel del
 * firmante y para generar el PDF cuando firma el último.
 */
function squuad_cert_signature_request_render_final(object $request): ?string
{
    $content = squuad_cert_signature_request_content((int) $request->id);
    if (null === $content) {
        return null;
    }
    // Bloque de firma común (ADR 0011 de Edusof): firma sobre la línea, nombre, puesto, fecha y hora local; compacto
    // donde la plantilla ya pone el nombre y el cargo junto a la firma
    $rows = squuad_cert_signature_request_rows((int) $request->id);
    $users_block = '';
    foreach (squuad_cert_request_signers($request) as $signer) {
        $slot = (string) $signer['slot_key'];
        $marker = squuad_cert_signer_slot_marker($slot);
        $block_marker = squuad_cert_signature_block_marker($slot);
        $placed = false !== strpos($content, $marker) || false !== strpos($content, $block_marker);
        $full = squuad_cert_signature_block_html($request, $signer, true, $rows);
        $named = squuad_cert_signature_slot_named($request, $slot, $content);
        $content = str_replace($block_marker, $full, $content);
        if (false !== strpos($content, $marker)) {
            $content = str_replace($marker, $named ? squuad_cert_signature_block_html($request, $signer, false, $rows) : $full, $content);
        } elseif (!$placed && squuad_cert_is_holder_slot($slot)) {
            $users_block .= $full;
        }
    }
    // {{qrcode}} de los automáticos (fuera de la huella del contenido): lleva a la página de verificación (ADR 0014)
    $content = str_replace(
        [SQUUAD_CERT_SIGNATURE_SLOT, SQUUAD_CERT_SIGNATURE_QR_SLOT],
        [
            '' !== $users_block ? '<div style="display:flex;flex-wrap:wrap;gap:4px 0;margin-top:16px">' . $users_block . '</div>' : '',
            '<div data-edusig-qr="' . esc_url(squuad_cert_signature_verify_url($request)) . '"></div>',
        ],
        $content
    );

    return $content;
}

/**
 * Firma de un firmante institucional (paso 5): en su sesión, sin Switch, con su firma registrada (se copian sus
 * trazos: signature_method 'profile') y el consentimiento del documento, sobre el contenido congelado que vio y solo
 * si su fase ya está abierta. Devuelve ['ok', 'message', 'completed' => bool].
 */
function squuad_cert_signature_sign_as_signer(int $request_id, string $shown_sha256, string $consent_version): array
{
    $consent = squuad_cert_signature_consent_evidence($consent_version);
    if (null === $consent) {
        return ['ok' => false, 'message' => __('To sign, you must accept signing the document electronically.', 'wp-certificates'), 'completed' => false];
    }

    return squuad_cert_signature_sign_as_signer_with($request_id, $shown_sha256, $consent);
}

/**
 * Núcleo de la firma del firmante institucional (una solicitud): revalida todo (cuenta propia, slot pendiente en su
 * fase, contenido congelado y huella mostrada, firma registrada íntegra) y guarda la firma con la evidencia del
 * consentimiento ya calculada ($consent: consent_version y consent_sha256). $event_extra se añade al evento
 * 'signed' (p. ej. batch_id). Devuelve ['ok', 'message', 'completed'].
 */
function squuad_cert_signature_sign_as_signer_with(int $request_id, string $shown_sha256, array $consent, array $event_extra = []): array
{
    $user_id = get_current_user_id();
    $request = squuad_cert_signature_request_get($request_id);
    $fail = static fn(string $message): array => ['ok' => false, 'message' => $message, 'completed' => false];

    if (!$request || !$user_id) {
        return $fail(__('You are not allowed to sign this document.', 'wp-certificates'));
    }
    if (function_exists('squuad_cert_signature_session_switched_from') && squuad_cert_signature_session_switched_from()) {
        return $fail(__('Documents cannot be signed from a switched session. Each person must sign from their own account.', 'wp-certificates'));
    }
    $slot_key = squuad_cert_signature_request_role($request, $user_id);
    $signer = squuad_cert_signer_by_user($user_id);
    if (0 !== strpos($slot_key, 'signer:') || !$signer || 'active' !== $signer->status) {
        return $fail(__('You are not allowed to sign this document.', 'wp-certificates'));
    }
    // Documento de identidad del firmante del sistema (ADR 0007 de Edusof)
    $id_document_error = squuad_cert_id_document_signing_error($user_id);
    if (null !== $id_document_error) {
        return $fail($id_document_error);
    }
    if (!in_array($request->status, ['open', 'partially_signed'], true) || null === $request->frozen_at_utc) {
        return $fail(__('This document no longer accepts signatures. Please reload the page.', 'wp-certificates'));
    }
    if (in_array($slot_key, squuad_cert_signature_request_signed_roles($request_id), true)) {
        return $fail(__('You already signed this document.', 'wp-certificates'));
    }
    if (!squuad_cert_signature_request_slot_open($request, $slot_key)) {
        return $fail(__('This document is still waiting for previous signatures.', 'wp-certificates'));
    }
    if (!hash_equals((string) $request->content_sha256, strtolower($shown_sha256))) {
        return $fail(__('The document was updated while you had it open. Please reload the page and review it again before signing.', 'wp-certificates'));
    }
    if (empty($consent['consent_version']) || empty($consent['consent_sha256'])) {
        return $fail(__('To sign, you must accept signing the document electronically.', 'wp-certificates'));
    }
    $profile = squuad_cert_user_signature_active($user_id);
    if (!$profile || !hash_equals((string) $profile->strokes_sha256, hash('sha256', (string) $profile->strokes))) {
        return $fail(__('Register your signature before signing documents.', 'wp-certificates'));
    }

    $signature_id = squuad_cert_signature_insert([
        'user_id' => $user_id,
        'signature' => (string) $profile->strokes, // copia: cambiar la firma registrada no altera este documento
        'document_id' => $request->document_id,
    ], (int) $request->subject_id, $slot_key, [
        'request_id' => (int) $request->id,
        'external_ref' => (int) $request->external_ref,
        'round' => (int) $request->round,
        'content_sha256' => (string) $request->content_sha256,
        'request_created_fingerprint' => squuad_cert_signature_request_created_fingerprint((int) $request->id),
        'template_version_sha256' => (string) $request->template_version_sha256,
        'signature_method' => 'profile',
        'reused_signature_id' => (int) $profile->id,
        'consent_version' => (string) $consent['consent_version'],
        'consent_sha256' => (string) $consent['consent_sha256'],
    ], (string) $request->subject_type);
    if (!$signature_id) {
        return $fail(__('Your signature could not be saved. Please reload the page and try again.', 'wp-certificates'));
    }
    squuad_cert_signature_request_log_event((int) $request->id, 'signed', [
        'role' => $slot_key,
        'signature_id' => $signature_id,
        'charge' => (string) $signer->charge,
        'user_signature_id' => (int) $profile->id,
    ] + $consent + $event_extra);

    $all_signed = !array_diff(squuad_cert_signature_request_required_roles($request), squuad_cert_signature_request_signed_roles((int) $request->id));
    squuad_cert_signature_request_transition((int) $request->id, ['open', 'partially_signed'], $all_signed ? 'signed' : 'partially_signed');
    // Quien firma después: un firmante por variable colocado tras los firmantes del sistema (ADR 0009 de Edusof). Solo
    // en solicitudes con firmantes por variable: en las demás todos los firmantes del sistema comparten fase y ya
    // recibieron su aviso, como hasta ahora
    $has_variable_slots = (bool) array_filter(squuad_cert_request_signers($request), static fn(array $slot): bool => squuad_cert_is_var_slot($slot['slot_key']));
    if (!$all_signed && $has_variable_slots) {
        squuad_cert_signer_notify_open_slots((int) $request->id);
    }

    return ['ok' => true, 'message' => $all_signed
        ? __('Signed. All signatures are complete: the final PDF is being generated.', 'wp-certificates')
        : __('Signed. The document is waiting for the remaining signatures.', 'wp-certificates'), 'completed' => $all_signed];
}

/* ---------------------------------------------------------------------------------------------------------------
 * Paso 6: firma en lote del firmante institucional (ADR 0003, punto 10)
 * ------------------------------------------------------------------------------------------------------------ */

const SQUUAD_CERT_SIGNATURE_BATCH_MAX = 50;
const SQUUAD_CERT_SIGNATURE_BATCH_MINUTES = 30;
const SQUUAD_CERT_SIGNATURE_BATCH_CONSENT = 'v1-lote';

/**
 * Consentimiento del lote (v1-lote), generado por el servidor: nombra cada documento (título, estudiante, ronda y
 * huella corta) en el orden del manifiesto. Su sha256 va en cada firma del lote.
 */
function squuad_cert_signature_batch_consent_text(array $items, bool $drawn = false): string
{
    $lines = [sprintf(
        $drawn
            /* translators: %d: number of documents */
            ? _n('I agree to sign electronically, with the signature I draw on this page, the following %d document:', 'I agree to sign electronically, with the signature I draw on this page, the following %d documents:', count($items), 'wp-certificates')
            /* translators: %d: number of documents */
            : _n('I agree to sign electronically, with my registered signature, the following %d document:', 'I agree to sign electronically, with my registered signature, the following %d documents:', count($items), 'wp-certificates'),
        count($items)
    )];
    foreach (array_values($items) as $i => $item) {
        $lines[] = sprintf(
            /* translators: 1: position, 2: document title, 3: student name, 4: round, 5: short content fingerprint */
            __('%1$d. %2$s — %3$s — round %4$d — fingerprint %5$s', 'wp-certificates'),
            $i + 1,
            $item['title'],
            $item['student'],
            $item['round'],
            substr($item['content_sha256'], 0, 12)
        );
    }
    $lines[] = __('I understand that each signature has the same validity as my handwritten signature, that it is recorded with the date, the time and the details of my connection, and that the signed documents cannot be modified.', 'wp-certificates');

    return implode("\n", $lines);
}

/** Lote del usuario actual (null si no existe o es de otro usuario). El manifiesto se devuelve decodificado. */
function squuad_cert_signature_batch_get(int $batch_id): ?object
{
    global $wpdb;

    $batch = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}squuad_cert_batches WHERE id = %d AND user_id = %d",
        $batch_id,
        get_current_user_id()
    ));
    if (!$batch) {
        return null;
    }
    $batch->data = json_decode((string) $batch->manifest, true) ?: ['items' => [], 'consent_text' => ''];
    $batch->result_data = json_decode((string) $batch->result, true) ?: null;

    return $batch;
}

/**
 * Preparar el lote: con las solicitudes que marcó el usuario, el servidor toma solo las que están pendientes de SU
 * firma, guarda el manifiesto ordenado (solicitud y huella del contenido), el consentimiento que nombra cada
 * documento y una caducidad. $kind: 'signer' (firmante institucional, su bandeja, firma registrada) o 'holder'
 * (estudiante o representante en Mi Cuenta, firma dibujada una vez; solo documentos ya congelados por la firma de
 * otra persona, ADR 0003 decisión 9). Devuelve ['ok', 'message', 'batch_id'].
 */
function squuad_cert_signature_batch_prepare(array $request_ids, string $kind = 'signer'): array
{
    global $wpdb;

    $user_id = get_current_user_id();
    $fail = static fn(string $message): array => ['ok' => false, 'message' => $message, 'batch_id' => 0];
    $holder = 'holder' === $kind;
    if (!$user_id) {
        return $fail(__('You are not allowed to sign this document.', 'wp-certificates'));
    }
    if (function_exists('squuad_cert_signature_session_switched_from') && squuad_cert_signature_session_switched_from()) {
        return $fail(__('Documents cannot be signed from a switched session. Each person must sign from their own account.', 'wp-certificates'));
    }
    // Documento de identidad (ADR 0007 de Edusof): antes de preparar nada
    $id_document_error = squuad_cert_id_document_signing_error($user_id);
    if (null !== $id_document_error) {
        return $fail($id_document_error);
    }
    if (!$holder) {
        $signer = squuad_cert_signer_by_user($user_id);
        if (!$signer || 'active' !== $signer->status) {
            return $fail(__('You are not allowed to sign this document.', 'wp-certificates'));
        }
        if (!squuad_cert_user_signature_active($user_id)) {
            return $fail(__('Register your signature before signing documents.', 'wp-certificates'));
        }
    }
    $wanted = array_values(array_unique(array_filter(array_map('intval', $request_ids))));
    if (!$wanted) {
        return $fail(__('Select at least one document to sign.', 'wp-certificates'));
    }
    if (count($wanted) > SQUUAD_CERT_SIGNATURE_BATCH_MAX) {
        /* translators: %d: maximum number of documents per batch */
        return $fail(sprintf(__('You can sign at most %d documents at once.', 'wp-certificates'), SQUUAD_CERT_SIGNATURE_BATCH_MAX));
    }

    // Solo lo que el servidor considera pendiente de este usuario, en el orden de su lista
    $items = [];
    foreach ($holder ? squuad_cert_signature_batch_holder_candidates(wp_get_current_user()) : squuad_cert_signer_inbox($user_id) as $row) {
        if (!in_array((int) $row->id, $wanted, true)) {
            continue;
        }
        $items[] = [
            'request_id' => (int) $row->id,
            'content_sha256' => (string) $row->content_sha256,
            'title' => (string) ($row->document_title ?: $row->document_id),
            'student' => trim((string) $row->student_name . ' ' . (string) $row->student_last_name),
            'round' => (int) $row->round,
        ];
    }
    if (!$items) {
        return $fail(__('None of the selected documents is waiting for your signature. Please reload the page.', 'wp-certificates'));
    }

    $manifest_sha256 = hash('sha256', implode("\n", array_map(static fn($i) => $i['request_id'] . ':' . $i['content_sha256'], $items)));
    $consent_text = squuad_cert_signature_batch_consent_text($items, $holder);
    $consent_version = substr(SQUUAD_CERT_SIGNATURE_BATCH_CONSENT . ':' . determine_locale(), 0, 20);
    $now = time();
    $inserted = $wpdb->insert("{$wpdb->prefix}squuad_cert_batches", [
        'user_id' => $user_id,
        'manifest' => wp_json_encode(['kind' => $holder ? 'holder' : 'signer', 'items' => $items, 'consent_text' => $consent_text]),
        'manifest_sha256' => $manifest_sha256,
        'status' => 'prepared',
        'consent_version' => $consent_version,
        'consent_sha256' => hash('sha256', $consent_text),
        'created_at_utc' => gmdate('Y-m-d H:i:s', $now),
        'expires_at_utc' => gmdate('Y-m-d H:i:s', $now + SQUUAD_CERT_SIGNATURE_BATCH_MINUTES * MINUTE_IN_SECONDS),
    ]);
    if (!$inserted) {
        return $fail(__('The batch could not be prepared. Please try again.', 'wp-certificates'));
    }
    $batch_id = (int) $wpdb->insert_id;

    return ['ok' => true, 'message' => '', 'batch_id' => $batch_id];
}

/**
 * Confirmar el lote: exige la contraseña del firmante y que acepte exactamente el consentimiento guardado (se
 * compara su sha256). Pasa el lote a 'running' de forma atómica (un segundo envío no firma nada), sella
 * 'batch_started' con el manifiesto y el texto aceptado, firma cada solicitud del manifiesto revalidándola una a una
 * (lo que falle se omite con su motivo) y sella 'batch_finished'. Devuelve ['ok', 'message', 'signed', 'skipped',
 * 'completed'] (completed: solicitudes que quedaron con todas las firmas y esperan su PDF).
 */
function squuad_cert_signature_batch_confirm(int $batch_id, string $password, string $accepted_consent_sha256, $drawn_strokes = null): array
{
    global $wpdb;

    $user = wp_get_current_user();
    $fail = static fn(string $message): array => ['ok' => false, 'message' => $message, 'signed' => [], 'skipped' => [], 'completed' => []];
    $batch = squuad_cert_signature_batch_get($batch_id);
    if (!$batch || !$user->exists()) {
        return $fail(__('This batch does not exist.', 'wp-certificates'));
    }
    if ('prepared' !== $batch->status) {
        return $fail(__('This batch was already processed.', 'wp-certificates'));
    }
    if (strtotime($batch->expires_at_utc . ' UTC') < time()) {
        $wpdb->update("{$wpdb->prefix}squuad_cert_batches", ['status' => 'expired'], ['id' => $batch_id, 'status' => 'prepared']);
        return $fail(__('This batch expired. Select the documents again.', 'wp-certificates'));
    }
    if (function_exists('squuad_cert_signature_session_switched_from') && squuad_cert_signature_session_switched_from()) {
        return $fail(__('Documents cannot be signed from a switched session. Each person must sign from their own account.', 'wp-certificates'));
    }
    if (!hash_equals((string) $batch->consent_sha256, strtolower($accepted_consent_sha256))
        || !hash_equals((string) $batch->consent_sha256, hash('sha256', (string) $batch->data['consent_text']))) {
        return $fail(__('To sign, you must accept signing the documents electronically.', 'wp-certificates'));
    }
    $holder = 'holder' === ($batch->data['kind'] ?? 'signer');
    $strokes = $holder ? squuad_cert_signer_normalize_strokes($drawn_strokes) : null;
    if ($holder && null === $strokes) {
        return $fail(__('Draw your signature before signing.', 'wp-certificates'));
    }
    // Documento de identidad (ADR 0007 de Edusof): el lote no empieza sin él
    $id_document_error = squuad_cert_id_document_signing_error((int) $user->ID);
    if (null !== $id_document_error) {
        return $fail($id_document_error);
    }
    if ('' === $password || !wp_check_password($password, $user->user_pass, $user->ID)) {
        return $fail(__('The password is not correct.', 'wp-certificates'));
    }

    // Una sola ejecución por lote
    $claimed = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}squuad_cert_batches SET status = 'running' WHERE id = %d AND user_id = %d AND status = 'prepared'",
        $batch_id,
        $user->ID
    ));
    if (1 !== (int) $claimed) {
        return $fail(__('This batch was already processed.', 'wp-certificates'));
    }

    $items = (array) $batch->data['items'];
    squuad_cert_signature_request_log_event(0, 'batch_started', [
        'batch_id' => $batch_id,
        'manifest_sha256' => (string) $batch->manifest_sha256,
        'manifest' => array_map(static fn($i) => (int) $i['request_id'] . ':' . (string) $i['content_sha256'], $items),
        'consent_version' => (string) $batch->consent_version,
        'consent_sha256' => (string) $batch->consent_sha256,
        'consent_text' => (string) $batch->data['consent_text'],
    ] + ($holder ? ['kind' => 'holder', 'signature_sha256' => hash('sha256', (string) $strokes)] : []));

    $consent = ['consent_version' => (string) $batch->consent_version, 'consent_sha256' => (string) $batch->consent_sha256];
    $signed = $skipped = $completed = [];
    foreach ($items as $item) {
        $result = $holder
            ? squuad_cert_signature_sign_as_holder_with((int) $item['request_id'], (string) $item['content_sha256'], (string) $strokes, $consent, ['batch_id' => $batch_id])
            : squuad_cert_signature_sign_as_signer_with((int) $item['request_id'], (string) $item['content_sha256'], $consent, ['batch_id' => $batch_id]);
        if ($result['ok']) {
            $signed[] = (int) $item['request_id'];
            if ($result['completed']) {
                $completed[] = (int) $item['request_id'];
            }
        } else {
            $skipped[] = ['request_id' => (int) $item['request_id'], 'reason' => (string) $result['message']];
        }
    }

    $result = ['signed' => $signed, 'skipped' => $skipped, 'completed' => $completed];
    $wpdb->update("{$wpdb->prefix}squuad_cert_batches", [
        'status' => 'finished',
        'finished_at_utc' => gmdate('Y-m-d H:i:s'),
        'result' => wp_json_encode($result),
    ], ['id' => $batch_id]);
    squuad_cert_signature_request_log_event(0, 'batch_finished', ['batch_id' => $batch_id] + $result);
    squuad_cert_log(sprintf('Lote %d del firmante %d: %d firmados, %d omitidos', $batch_id, $user->ID, count($signed), count($skipped)), 'signer_signature');

    $message = sprintf(
        /* translators: 1: signed documents, 2: skipped documents */
        __('Batch finished: %1$d signed, %2$d skipped.', 'wp-certificates'),
        count($signed),
        count($skipped)
    );

    return ['ok' => (bool) $signed, 'message' => $message] + $result;
}

/**
 * Solicitudes que este firmante ya firmó y que están completas ('signed') pero sin PDF final: la cola del panel
 * para generarlo (ADR 0003, punto 11).
 */
function squuad_cert_signer_pdf_queue(int $user_id): array
{
    global $wpdb;

    if (!$user_id || !squuad_cert_signers_enabled()) {
        return [];
    }

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT r.*, d.title AS document_title
         FROM {$wpdb->prefix}squuad_cert_request_signers rs
         JOIN {$wpdb->prefix}squuad_cert_requests r ON r.id = rs.request_id
         LEFT JOIN {$wpdb->prefix}documents_certificates d ON d.id = r.document_certificate_id
         WHERE rs.user_id = %d AND r.status = 'signed' AND (r.final_attachment_id IS NULL OR r.final_attachment_id = 0)
         ORDER BY r.id ASC",
        $user_id
    ));
    foreach ($rows as $row) {
        $row->student_name = squuad_cert_request_subject_label($row);
        $row->student_last_name = '';
    }

    return $rows;
}

/* ---------------------------------------------------------------------------------------------------------------
 * Paso 6b: firma en lote de la cuenta dueña en Mi Cuenta (ADR 0003, punto 10)
 * ------------------------------------------------------------------------------------------------------------ */

/**
 * Documentos que la cuenta puede firmar en lote: pendientes de su firma y ya congelados
 * (otra persona firmó antes, así el contenido y los campos del documento ya están fijados), en su fase. Mismo
 * formato de fila que la bandeja del firmante (id, content_sha256, round, document_title, student_name...).
 */
function squuad_cert_signature_batch_holder_candidates(WP_User $user): array
{
    $rows = [];
    foreach (squuad_cert_signature_user_documents($user) as $item) {
        $request = $item['request'];
        if ('to_sign' !== $item['state'] || !$request || null === $request->frozen_at_utc
            || !in_array($request->status, ['open', 'partially_signed'], true)) {
            continue;
        }
        $role = squuad_cert_signature_request_role($request, (int) $user->ID);
        if (!squuad_cert_is_person_slot($role) || !squuad_cert_signature_request_slot_open($request, $role)) {
            continue;
        }
        // Quien firma en representación del titular acepta su propio consentimiento, uno por documento: no va en lote
        // (ADR 0012 de Edusof)
        if (SQUUAD_CERT_SIGNATURE_CONSENT_CURRENT !== squuad_cert_signature_consent_for($request, $role)['version']) {
            continue;
        }
        $row = clone $request;
        $row->document_title = (string) $item['document']->title;
        $row->student_name = squuad_cert_request_subject_label($request);
        $row->student_last_name = '';
        $rows[] = $row;
    }

    return $rows;
}

/**
 * Firma del estudiante sobre una solicitud ya congelada, con trazos ya validados
 * (squuad_cert_signer_normalize_strokes) y la evidencia del consentimiento calculada: la usa el lote de Mi Cuenta.
 * Revalida cuenta propia, rol, estado, fase y la huella del contenido; no congela nada. Devuelve ['ok', 'message',
 * 'completed'] (completed: con esta firma están todas y la solicitud espera su PDF final).
 */
function squuad_cert_signature_sign_as_holder_with(int $request_id, string $shown_sha256, string $strokes_json, array $consent, array $event_extra = []): array
{
    $user_id = get_current_user_id();
    $request = squuad_cert_signature_request_get($request_id);
    $fail = static fn(string $message): array => ['ok' => false, 'message' => $message, 'completed' => false];

    if (!$request || !$user_id) {
        return $fail(__('You are not allowed to sign this document.', 'wp-certificates'));
    }
    if (function_exists('squuad_cert_signature_session_switched_from') && squuad_cert_signature_session_switched_from()) {
        return $fail(__('Documents cannot be signed from a switched session. Each person must sign from their own account.', 'wp-certificates'));
    }
    $role = squuad_cert_signature_request_role($request, $user_id);
    // Quien recibe el documento o un firmante por variable (ADR 0009 de Edusof)
    if (!squuad_cert_is_person_slot($role)) {
        return $fail(__('You are not allowed to sign this document.', 'wp-certificates'));
    }
    // Documento de identidad de quien firma (ADR 0007 de Edusof)
    $id_document_error = squuad_cert_id_document_signing_error($user_id);
    if (null !== $id_document_error) {
        return $fail($id_document_error);
    }
    if (!in_array($request->status, ['open', 'partially_signed'], true) || null === $request->frozen_at_utc) {
        return $fail(__('This document no longer accepts signatures. Please reload the page.', 'wp-certificates'));
    }
    if (in_array($role, squuad_cert_signature_request_signed_roles($request_id), true)) {
        return $fail(__('You already signed this document.', 'wp-certificates'));
    }
    if (!squuad_cert_signature_request_slot_open($request, $role)) {
        return $fail(__('This document is still waiting for previous signatures.', 'wp-certificates'));
    }
    // Quien firma en representación del titular acepta su propio consentimiento: no firma con el del lote
    if (SQUUAD_CERT_SIGNATURE_CONSENT_CURRENT !== squuad_cert_signature_consent_for($request, $role)['version']) {
        return $fail(__('Open this document and sign it on its own: it has its own consent text.', 'wp-certificates'));
    }
    // Firmante por variable: la variable tiene que seguir dando esta cuenta (ADR 0012 de Edusof)
    if (!squuad_cert_request_var_slot_valid($request, $role, $user_id)) {
        return $fail(__('You can no longer sign this document. Ask the school office to request the signatures again.', 'wp-certificates'));
    }
    if (!hash_equals((string) $request->content_sha256, strtolower($shown_sha256))) {
        return $fail(__('The document was updated while you had it open. Please reload the page and review it again before signing.', 'wp-certificates'));
    }
    if ('' === $strokes_json || empty($consent['consent_version']) || empty($consent['consent_sha256'])) {
        return $fail(__('To sign, you must accept signing the document electronically.', 'wp-certificates'));
    }

    $signature_id = squuad_cert_signature_insert([
        'user_id' => $user_id,
        'signature' => $strokes_json,
        'document_id' => $request->document_id,
    ], (int) $request->subject_id, $role, [
        'request_id' => (int) $request->id,
        'external_ref' => (int) $request->external_ref,
        'round' => (int) $request->round,
        'content_sha256' => (string) $request->content_sha256,
        'request_created_fingerprint' => squuad_cert_signature_request_created_fingerprint((int) $request->id),
        'template_version_sha256' => (string) $request->template_version_sha256,
        'signature_method' => 'drawn',
        'consent_version' => (string) $consent['consent_version'],
        'consent_sha256' => (string) $consent['consent_sha256'],
    ], (string) $request->subject_type);
    if (!$signature_id) {
        return $fail(__('Your signature could not be saved. Please reload the page and try again.', 'wp-certificates'));
    }
    squuad_cert_signature_request_log_event((int) $request->id, 'signed', [
        'role' => $role,
        'signature_id' => $signature_id,
    ] + $consent + $event_extra);

    $all_signed = !array_diff(squuad_cert_signature_request_required_roles($request), squuad_cert_signature_request_signed_roles((int) $request->id));
    squuad_cert_signature_request_transition((int) $request->id, ['open', 'partially_signed'], $all_signed ? 'signed' : 'partially_signed');

    return ['ok' => true, 'message' => $all_signed
        ? __('Signed. All signatures are complete.', 'wp-certificates')
        : __('Signed. Waiting for the remaining signatures.', 'wp-certificates'), 'completed' => $all_signed] + ['notified' => squuad_cert_signer_notify_open_slots((int) $request->id)];
}

/* ---------------------------------------------------------------------------------------------------------------
 * Paso 7: documentos gestionados "Emitir para firma" (ADR 0003, punto 8)
 * ------------------------------------------------------------------------------------------------------------ */

const SQUUAD_CERT_ISSUED_PREFIX = 'issued:';

/**
 * Firmantes institucionales que exige la política de un documento gestionado para emitirlo para firma (activos o
 * invitados). Vacío si el documento no pide firmas de firmantes del sistema: entonces sigue el camino heredado
 * ("Generar" con la firma-imagen de wp-certificates). Estudiante y representante no firman documentos emitidos
 * (decisión del dueño, 2026-09-29).
 */
function squuad_cert_signature_issue_signers(object $document): array
{
    if (!squuad_cert_signers_enabled() || !squuad_cert_signer_inbox_enabled() || 'automatic' === ($document->type ?? '')) {
        return [];
    }
    $policy = squuad_cert_signing_policy($document);
    if (empty($policy['requires_signatures'])) {
        return [];
    }
    $signers = [];
    foreach ($policy['slots'] as $slot) {
        if ('signer' !== $slot['slot_type']) {
            continue;
        }
        $signer = squuad_cert_signer_get((int) $slot['signer_id']);
        if ($signer && in_array($signer->status, ['active', 'invited'], true)) {
            $signers[] = $signer;
        }
    }

    return $signers;
}

/** Última solicitud emitida de un documento gestionado para la ficha de un estudiante (o null). */
function squuad_cert_signature_issued_latest(int $student_id, int $document_certificate_id): ?object
{
    return squuad_cert_signature_request_latest($student_id, SQUUAD_CERT_ISSUED_PREFIX . $document_certificate_id, SQUUAD_CERT_SUBJECT_STUDENT);
}

/**
 * Opciones de página del documento emitido (formato, orientación, unidad, tamaño), selladas en el evento 'issued'.
 * Por defecto A4 vertical en milímetros.
 */
function squuad_cert_signature_issued_options(object $request): array
{
    global $wpdb;

    $defaults = ['orientation' => 'portrait', 'unit' => 'mm', 'paper_format' => 'a4', 'width_size' => 0, 'height_size' => 0];
    if ('issued' !== ($request->origin ?? '')) {
        return $defaults;
    }
    $data = $wpdb->get_var($wpdb->prepare(
        "SELECT data FROM {$wpdb->prefix}squuad_cert_events WHERE request_id = %d AND event_type = 'issued' ORDER BY id ASC LIMIT 1",
        $request->id
    ));
    $data = json_decode((string) $data, true);

    return is_array($data['page'] ?? null) ? array_merge($defaults, $data['page']) : $defaults;
}

/**
 * Emitir para firma (ADR 0003, punto 8): genera el contenido del documento gestionado para el estudiante (cabecera,
 * cuerpo y pie, con los datos escapados, las imágenes incrustadas, los huecos de los firmantes institucionales y el
 * QR con su URL), crea la solicitud (origin 'issued', solo firmantes institucionales: empieza en la fase 2) y la
 * congela al emitir. Cada firmante firma desde su panel y el último genera el PDF final. Si el documento lleva QR,
 * el certificado se registra en wp-certificates al emitir, como al generarlo. Si el documento tiene libro de
 * registro (paso 8b), la línea de tomo/folio se reserva al emitir y queda en el contenido congelado; con
 * $reuse_book_entry_id se reemite con la misma línea (tras declinar, opción "seguir con el mismo tomo/folio").
 * Devuelve ['ok', 'message', 'request_id'].
 */
function squuad_cert_signature_issue_document(int $student_id, int $document_certificate_id, int $reuse_book_entry_id = 0): array
{
    global $wpdb;

    $fail = static fn(string $message): array => ['ok' => false, 'message' => $message, 'request_id' => 0];
    $user_id = get_current_user_id();
    // Datos de la ficha para el QR (programa y estudiante): los da el proveedor, no las funciones de EduSystem
    $provider = function_exists('squuad_cert_subject_type') ? squuad_cert_subject_type(SQUUAD_CERT_SUBJECT_STUDENT) : null;
    $subject = ($provider && !empty($provider['book_line_data'])) ? (array) call_user_func($provider['book_line_data'], $student_id, null) : [];
    $student = $subject['student'] ?? null;
    $document = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}documents_certificates WHERE id = %d", $document_certificate_id));
    if (!$student || !$document || !$user_id) {
        return $fail(__('The person or the document does not exist.', 'wp-certificates'));
    }
    if (function_exists('squuad_cert_signature_session_switched_from') && squuad_cert_signature_session_switched_from()) {
        return $fail(__('Documents cannot be issued from a switched session.', 'wp-certificates'));
    }
    if (!squuad_cert_signature_issue_signers($document)) {
        return $fail(__('This document has no system signers configured. Configure its signers or generate it as before.', 'wp-certificates'));
    }
    $latest = squuad_cert_signature_issued_latest($student_id, $document_certificate_id);
    if ($latest && in_array($latest->status, SQUUAD_CERT_SIGNATURE_REQUEST_OPEN, true) && null !== $latest->frozen_at_utc) {
        return $fail(__('This document was already issued to this person and is waiting for signatures.', 'wp-certificates'));
    }

    // Tomo y folio (paso 8b): la línea reservada que se reutiliza, o ninguna decisión pendiente sobre la anterior
    $uses_book = (int) ($document->book ?? 0) > 0 && squuad_cert_book_entries_enabled();
    $entry = null;
    if ($reuse_book_entry_id) {
        $entry = squuad_cert_book_entry_get($reuse_book_entry_id);
        if (!$uses_book || !$entry || 'active' !== $entry->status || (int) $entry->student_id !== $student_id
            || (int) $entry->document_certificate_id !== $document_certificate_id) {
            return $fail(__('That volume and folio cannot be reused.', 'wp-certificates'));
        }
    } elseif ($uses_book && squuad_cert_book_entry_pending_decision($student_id, $document_certificate_id)) {
        return $fail(__('The previous issue of this document was declined and still holds its volume and folio: choose in "Documents issued for signature" whether to keep them or void them.', 'wp-certificates'));
    }

    $parts = ['header' => (string) $document->header, 'content' => (string) $document->content, 'footer' => (string) $document->footer];
    $template_sha256 = hash('sha256', implode("\n", $parts));
    $request = squuad_cert_signature_request_get_or_create(
        $student_id,
        SQUUAD_CERT_ISSUED_PREFIX . $document_certificate_id,
        ['holder_user_id' => 0],
        null,
        $template_sha256,
        $document_certificate_id,
        'issued',
        SQUUAD_CERT_SUBJECT_STUDENT
    );
    if (!$request) {
        return $fail(__('The document could not be issued. Please try again.', 'wp-certificates'));
    }
    $has_signers = false;
    foreach (squuad_cert_request_signers($request) as $signer) {
        $has_signers = $has_signers || squuad_cert_is_signer_slot($signer['slot_key']);
    }
    if (!$has_signers) {
        return $fail(__('This document has no system signers configured. Configure its signers or generate it as before.', 'wp-certificates'));
    }

    // Línea nueva del libro: la reserva la capa admin (API de libros de EduSof) con el filtro; sin ella no se emite
    if ($uses_book && !$entry) {
        $entry = apply_filters('squuad_cert_issue_book_entry', null, $student, $document, $request);
        if (is_wp_error($entry) || !is_object($entry) || empty($entry->id)) {
            return $fail(is_wp_error($entry) ? $entry->get_error_message() : __('The registry book did not assign a volume and folio. The document was not issued.', 'wp-certificates'));
        }
    }

    // QR: lleva a la página de verificación del propio sitio (ADR 0014) y su dirección queda sellada en el contenido. El
    // certificado se sigue registrando como al generar: su fila sirve a la API pública, al contador de uso del documento
    // y a la deduplicación al reemitir
    $qr_url = '';
    if (false !== strpos(implode('', $parts), '{{qrcode}}')) {
        $qr_url = squuad_cert_signature_verify_url($request, 'design');
        $emission_date = gmdate('Y-m-d');
        $program = (string) ($subject['program'] ?? '');
        apply_filters('create_certificate_edusystem', 'certificate', $document->title, $program, 1, $student, $emission_date);
    }

    // Valores de las variables: los resuelve wp-certificates (ADR 0005). Un documento que se emite para firma no puede
    // quedar incompleto: si una variable falla, no se emite.
    $resolved = squuad_cert_template_replacements(implode('', $parts), $student_id, ['document' => $document, 'certificate_id' => $document->id]);
    if ($resolved['failed']) {
        return $fail(sprintf(
            /* translators: %s: list of variables */
            __('The document was not issued: these variables could not be filled in: %s.', 'wp-certificates'),
            implode(', ', array_keys($resolved['failed']))
        ));
    }
    $replacements = $resolved['replacements'];
    if (function_exists('squuad_cert_document_fields_empty_replacements')) {
        $replacements = array_merge(squuad_cert_document_fields_empty_replacements($document), $replacements);
    }
    $replacements = squuad_cert_signature_escape_replacements($replacements);
    // Variables generales del propio documento (wp-certificates, ya escapadas): {{document_name}} y {{document_code}}
    if (function_exists('squuad_cert_document_replacements')) {
        $replacements = array_merge($replacements, squuad_cert_document_replacements($document));
    }
    $replacements['qrcode'] = ['value' => '<div data-edusig-qr="' . esc_url($qr_url) . '"></div>', 'wrap' => false];
    $replacements = array_merge($replacements, squuad_cert_signature_signer_replacements($request));
    if ($entry) {
        $replacements['tomo'] = ['value' => (string) (int) $entry->tomo, 'wrap' => true];
        $replacements['folio'] = ['value' => (string) (int) $entry->folio, 'wrap' => true];
        /* translators: 1: volume number, 2: folio number */
        $replacements['tomo_folio'] = ['value' => esc_html(sprintf(__('Volume: %1$d Folio: %2$d', 'wp-certificates'), (int) $entry->tomo, (int) $entry->folio)), 'wrap' => true];
    }

    foreach ($parts as $key => $part) {
        $parts[$key] = squuad_cert_signature_strip_unused_signer_tags((string) squuad_cert_process_template($part, $replacements));
    }
    // Firmantes que la plantilla no coloca: un bloque al final del cuerpo
    $joined = implode('', $parts);
    $parts['content'] .= substr(squuad_cert_signature_append_institutional_block($joined, $request), strlen($joined));
    $html = squuad_cert_signature_inline_images(
        ('' !== trim($parts['header']) ? '<div class="edusystem-doc-header">' . $parts['header'] . '</div>' : '')
        . '<div class="edusystem-doc-content">' . $parts['content'] . '</div>'
        . ('' !== trim($parts['footer']) ? '<div class="edusystem-doc-footer">' . $parts['footer'] . '</div>' : '')
    );

    $sha256 = squuad_cert_signature_request_save_draft((int) $request->id, $html);
    if (null === $sha256 || !squuad_cert_signature_request_freeze((int) $request->id, $sha256, $user_id)) {
        return $fail(__('The document is too large or could not be saved. Please try again.', 'wp-certificates'));
    }
    if ($entry) {
        $wpdb->update($wpdb->prefix . 'squuad_cert_requests', ['book_entry_id' => (int) $entry->id], ['id' => (int) $request->id]);
        $wpdb->update($wpdb->prefix . 'squuad_cert_book_entries', ['request_id' => (int) $request->id], ['id' => (int) $entry->id]);
    }
    $is_custom = 'custom' === (string) $document->paper_format;
    squuad_cert_signature_request_log_event((int) $request->id, 'issued', [
        'document_certificate_id' => (int) $document->id,
        'title' => (string) $document->title,
        'content_sha256' => $sha256,
        'template_sha256' => $template_sha256,
        'qr_url' => $qr_url,
        'page' => [
            'orientation' => strtolower((string) ($document->orientation ?: 'portrait')),
            'unit' => strtolower((string) ($document->unit ?: 'mm')),
            'paper_format' => $is_custom ? 'custom' : strtolower((string) ($document->paper_format ?: 'a4')),
            'width_size' => (float) $document->width_size,
            'height_size' => (float) $document->height_size,
        ],
    ] + ($entry ? ['book' => [
        'entry_id' => (int) $entry->id,
        'book_id' => (int) $entry->book_id,
        'line_id' => (int) $entry->line_id,
        'tomo' => (int) $entry->tomo,
        'folio' => (int) $entry->folio,
        'line_number' => (int) $entry->line_number,
        'reused' => (bool) $reuse_book_entry_id,
    ]] : []));
    squuad_cert_log(sprintf('Documento %d emitido para firma al estudiante %d (solicitud %d) por el usuario %d', (int) $document->id, $student_id, (int) $request->id, $user_id), 'signing_policy');

    squuad_cert_signer_notify_open_slots((int) $request->id);

    return ['ok' => true, 'message' => __('Document issued for signature. Each signer will find it in "Documents to sign".', 'wp-certificates'), 'request_id' => (int) $request->id];
}

/** Documentos emitidos para firma de un estudiante (todas las rondas), con título y firmas dadas / exigidas. */
function squuad_cert_signature_issued_for_student(int $student_id): array
{
    global $wpdb;

    if (!$student_id || !squuad_cert_signature_requests_enabled()) {
        return [];
    }
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT r.*, d.title AS document_title FROM {$wpdb->prefix}squuad_cert_requests r
         LEFT JOIN {$wpdb->prefix}documents_certificates d ON d.id = r.document_certificate_id
         WHERE r.subject_type = %s AND r.subject_id = %d AND r.origin = 'issued' ORDER BY r.id DESC",
        SQUUAD_CERT_SUBJECT_STUDENT,
        $student_id
    ));
    $latest = [];
    foreach ($rows as $row) {
        $row->required_count = count(squuad_cert_signature_request_required_roles($row));
        $row->signed_count = count(squuad_cert_signature_request_signed_roles((int) $row->id));
        // Tomo y folio (paso 8b) y si es la última ronda de su documento (las acciones solo van en la última)
        $row->book_entry = !empty($row->book_entry_id) ? squuad_cert_book_entry_get((int) $row->book_entry_id) : null;
        $row->is_latest = !isset($latest[$row->document_id]);
        $latest[$row->document_id] = true;
        $row->book_pending = $row->is_latest && 'declined' === $row->status && $row->book_entry
            && 'active' === $row->book_entry->status && (int) $row->book_entry->request_id === (int) $row->id;
    }

    return $rows;
}

/**
 * Opciones de html2pdf/jsPDF para el PDF final de una solicitud: formato, orientación y unidad del documento emitido
 * (A4 vertical para el resto) y el ancho en píxeles (96 ppp) de la página, para maquetar el contenido a ese ancho.
 */
function squuad_cert_signature_pdf_page(object $request): array
{
    // Documentos automáticos: el mismo margen de siempre (0,3 in = 7,62 mm); los emitidos traen su propia maquetación
    return squuad_cert_signature_pdf_page_from_options(squuad_cert_signature_issued_options($request), 'issued' === ($request->origin ?? '') ? 0 : 7.62);
}

/**
 * Página del PDF (formato de jsPDF, ancho en px y margen) a partir de las opciones de página de un documento
 * (orientation, unit, paper_format, width_size, height_size). También la usa la vista previa del documento.
 */
function squuad_cert_signature_pdf_page_from_options(array $page, float $margin): array
{
    $unit = in_array($page['unit'], ['mm', 'cm', 'in', 'px', 'pt'], true) ? $page['unit'] : 'mm';
    $orientation = 'landscape' === $page['orientation'] ? 'landscape' : 'portrait';
    $sizes_mm = ['a4' => [210, 297], 'a3' => [297, 420], 'a5' => [148, 210], 'letter' => [215.9, 279.4], 'legal' => [215.9, 355.6]];
    $to_px = ['mm' => 96 / 25.4, 'cm' => 96 / 2.54, 'in' => 96, 'px' => 1, 'pt' => 96 / 72];

    if ('custom' === $page['paper_format'] && $page['width_size'] > 0 && $page['height_size'] > 0) {
        $format = [(float) $page['width_size'], (float) $page['height_size']];
        $width_px = (float) $page['width_size'] * $to_px[$unit];
    } else {
        $format = isset($sizes_mm[$page['paper_format']]) ? $page['paper_format'] : 'a4';
        [$w, $h] = $sizes_mm[$format];
        $width_px = ('landscape' === $orientation ? $h : $w) * $to_px['mm'];
    }

    return ['jspdf' => ['unit' => $unit, 'format' => $format, 'orientation' => $orientation], 'width_px' => (int) round($width_px), 'margin' => $margin];
}

/* ---------------------------------------------------------------------------------------------------------------
 * Paso 8b: tomo y folio de los documentos emitidos (libro de registro de EduSof)
 * ------------------------------------------------------------------------------------------------------------ */

function squuad_cert_book_entries_enabled(): bool
{
    return version_compare((string) get_option('wp_c_db_version'), '9', '>=');
}

function squuad_cert_book_entry_get(int $entry_id): ?object
{
    global $wpdb;

    if (!$entry_id || !squuad_cert_book_entries_enabled()) {
        return null;
    }
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}squuad_cert_book_entries WHERE id = %d", $entry_id));

    return $row ?: null;
}

/**
 * Guarda la línea que asignó el libro a un documento emitido. $line: ['id', 'tomo', 'folio', 'line_number'] (la
 * respuesta de edusof_insert_certificate_book_line). Devuelve la fila o null.
 */
function squuad_cert_book_entry_insert(int $student_id, int $document_certificate_id, int $book_id, array $line): ?object
{
    global $wpdb;

    if (empty($line['id'])) {
        return null;
    }
    $wpdb->insert($wpdb->prefix . 'squuad_cert_book_entries', [
        'student_id' => $student_id,
        'document_certificate_id' => $document_certificate_id,
        'book_id' => $book_id,
        'line_id' => (int) $line['id'],
        'tomo' => (int) ($line['tomo'] ?? 0),
        'folio' => (int) ($line['folio'] ?? 0),
        'line_number' => (int) ($line['line_number'] ?? 0),
        'status' => 'active',
        'created_at_utc' => gmdate('Y-m-d H:i:s'),
        'created_by' => get_current_user_id(),
    ]);

    return squuad_cert_book_entry_get((int) $wpdb->insert_id);
}

/** Marca la línea como anulada en EduSystem (la anulación en el libro la hace antes la capa admin). */
function squuad_cert_book_entry_mark_void(int $entry_id, string $reason): bool
{
    global $wpdb;

    return (bool) $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}squuad_cert_book_entries SET status = 'void', voided_at_utc = UTC_TIMESTAMP(), voided_by = %d, void_reason = %s
         WHERE id = %d AND status = 'active'",
        get_current_user_id(),
        $reason,
        $entry_id
    ));
}

/**
 * Documento emitido y declinado cuya línea de tomo/folio sigue reservada: el encargado debe decidir si reemite con la
 * misma o la anula. Devuelve la línea (con ->declined_request_id) o null.
 */
function squuad_cert_book_entry_pending_decision(int $student_id, int $document_certificate_id): ?object
{
    if (!squuad_cert_book_entries_enabled()) {
        return null;
    }
    $latest = squuad_cert_signature_issued_latest($student_id, $document_certificate_id);
    if (!$latest || 'declined' !== $latest->status || !(int) $latest->book_entry_id) {
        return null;
    }
    $entry = squuad_cert_book_entry_get((int) $latest->book_entry_id);
    if (!$entry || 'active' !== $entry->status || (int) $entry->request_id !== (int) $latest->id) {
        return null;
    }
    $entry->declined_request_id = (int) $latest->id;

    return $entry;
}

/**
 * Declina un documento emitido (encargado, desde la ficha del estudiante): anula las firmas de esa solicitud y la
 * cierra como declinada, con motivo. Definitivo. No decide sobre el tomo/folio (lo hace quien llama). Devuelve
 * ['ok', 'message'].
 */
function squuad_cert_signature_issued_decline(int $request_id, string $reason): array
{
    global $wpdb;

    $request = squuad_cert_signature_request_get($request_id);
    if (!$request || 'issued' !== $request->origin || !in_array($request->status, ['open', 'partially_signed', 'signed', 'completed'], true)) {
        return ['ok' => false, 'message' => __('This document can no longer be declined. Please reload the page.', 'wp-certificates')];
    }
    $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}squuad_cert_signatures WHERE request_id = %d", $request_id));
    squuad_cert_revoke_signatures($ids, sprintf('Documento emitido declinado: %s', $reason), get_current_user_id());
    $declined = squuad_cert_signature_request_transition($request_id, ['open', 'partially_signed', 'signed', 'completed'], 'declined', [
        'declined_at_utc' => gmdate('Y-m-d H:i:s'),
        'declined_by' => get_current_user_id(),
        'decline_reason' => $reason,
    ]);
    if (!$declined) {
        return ['ok' => false, 'message' => __('This document can no longer be declined. Please reload the page.', 'wp-certificates')];
    }

    return ['ok' => true, 'message' => __('The document was declined and its signatures were revoked.', 'wp-certificates')];
}

/* ---------------------------------------------------------------------------------------------------------------
 * Paso 9: carta de documentos faltantes (MISSING DOCUMENT). La condición «Cuándo pedirlo» se quitó (ADR 0004, decisión
 * del 2026-10-01): un automático se muestra si pide firma o tiene campos adicionales; la lista {{missing_documents}}
 * es un dato de EduSystem y la da su método de variable.
 * ------------------------------------------------------------------------------------------------------------ */

const SQUUAD_CERT_MISSING_LETTER_ID = 'MISSING DOCUMENT';

/** ¿El sitio ya tiene la carta de documentos faltantes como documento automático? */
function squuad_cert_missing_letter_is_automatic(): bool
{
    $document = function_exists('squuad_cert_get_automatic_document_by_identificator')
        ? squuad_cert_get_automatic_document_by_identificator(SQUUAD_CERT_MISSING_LETTER_ID)
        : null;

    return $document && 1 === (int) $document->status;
}

/* ---------------------------------------------------------------------------------------------------------------
 * Paso 10: nadie firma por otro. Sin firmas-imagen de terceros; aviso a los firmantes cuando un documento les llega
 * ------------------------------------------------------------------------------------------------------------ */

/**
 * Regla del sitio (decisión del dueño, 2026-09-29): en el admin no se pone la firma de nadie. Las firmas-imagen de
 * "Users and signatures" ya no se insertan en ningún documento; un documento que exige firma se emite para firma y
 * cada responsable firma desde su propia cuenta. Lo firmado antes con ellas se conserva (es legal).
 */
function squuad_cert_third_party_signatures_blocked(): bool
{
    return squuad_cert_signers_enabled();
}

/**
 * Avisa a los firmantes institucionales cuyo hueco de una solicitud acaba de abrirse (documento emitido, o estudiante
 * y representante ya firmaron): correo con el enlace a "Documentos por firmar", sin datos del estudiante. Una sola
 * vez por firmante y solicitud (evento sellado 'signer_notified'). Devuelve cuántos avisos se enviaron.
 */
function squuad_cert_signer_notify_open_slots(int $request_id): int
{
    global $wpdb;

    $request = squuad_cert_signature_request_get($request_id);
    if (!$request || !in_array($request->status, ['open', 'partially_signed'], true)) {
        return 0;
    }
    // Turno de firma (ADR 0012 de Edusof): una solicitud que creó el sistema (o que Admisión volvió a pedir) avisa ya a
    // quien tiene el primer turno; las demás, desde que alguien firmó (como siempre)
    $frozen = null !== $request->frozen_at_utc;
    $issued = in_array((string) ($request->origin ?? ''), ['system', 'restarted'], true);
    $signed = squuad_cert_signature_request_signed_roles($request_id);
    $notified = [];
    foreach ($wpdb->get_col($wpdb->prepare(
        "SELECT data FROM {$wpdb->prefix}squuad_cert_events WHERE request_id = %d AND event_type = 'signer_notified'",
        $request_id
    )) as $data) {
        $notified[] = (string) (json_decode((string) $data, true)['role'] ?? '');
    }
    $title = (string) $wpdb->get_var($wpdb->prepare(
        "SELECT title FROM {$wpdb->prefix}documents_certificates WHERE id = %d",
        (int) $request->document_certificate_id
    )) ?: (string) $request->document_id;
    $site = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);

    $sent = 0;
    foreach (squuad_cert_request_signers($request) as $signer) {
        $slot = (string) $signer['slot_key'];
        // Quien recibe el documento y los firmantes por variable firman desde «Documentos por firmar» de Mi Cuenta; a
        // quien recibe el documento solo se le avisa si otra persona firmó antes o si la solicitud la creó el sistema
        $by_variable = squuad_cert_is_var_slot($slot) || squuad_cert_is_holder_slot($slot);
        if (!(squuad_cert_is_signer_slot($slot) || $by_variable) || in_array($slot, $signed, true) || in_array($slot, $notified, true)
            || !squuad_cert_signature_request_slot_open($request, $slot) || (!$frozen && squuad_cert_is_signer_slot($slot))) {
            continue;
        }
        // Quien recibe el documento, sin nadie que haya firmado antes, solo si no la abrió él (la creó el sistema)
        if (squuad_cert_is_holder_slot($slot) && !$issued && !$signed) {
            continue;
        }
        $user = get_userdata((int) $signer['user_id']);
        if (!$user || !$user->user_email) {
            continue;
        }
        // Firmante por variable (ADR 0009 de Edusof): firma desde «Documentos por firmar» de Mi Cuenta
        $link = $by_variable
            ? (function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url('dashboard') : home_url('/'))
            : add_query_arg(['page' => 'squuad-cert-documents-to-sign', 'request_id' => $request_id], admin_url('admin.php'));
        $subject = sprintf(__('[%1$s] Document waiting for your signature: %2$s', 'wp-certificates'), $site, $title);
        $body = sprintf(__('Hello %s,', 'wp-certificates'), $user->display_name) . "\n\n"
            . sprintf(__('The document "%s" is waiting for your signature.', 'wp-certificates'), $title) . "\n\n"
            . __('Sign in with your own account and open "Documents to sign" to review it and sign it:', 'wp-certificates') . "\n" . $link . "\n\n"
            . __('Only you can sign it: nobody else can sign on your behalf.', 'wp-certificates') . "\n";
        $mailed = (bool) wp_mail($user->user_email, $subject, $body);
        squuad_cert_signature_request_log_event($request_id, 'signer_notified', ['role' => $slot, 'user_id' => (int) $user->ID, 'mailed' => $mailed]);
        $sent++;
    }

    return $sent;
}
