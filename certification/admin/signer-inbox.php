<?php
declare(strict_types=1);

/**
 * EduSystem - "Documentos por firmar" del firmante institucional (ADR 0003, paso 5).
 *
 * Lista los documentos que esperan su firma (fase ya abierta: estudiante y representante firmaron antes), muestra
 * cada uno con las firmas pintadas en el servidor (dentro de un iframe aislado, sin scripts) y lo firma con su firma
 * registrada y el consentimiento. Si es el último firmante, la misma página genera el PDF final a partir del
 * documento firmado y lo sube una sola vez. Paso 6: firma en lote (preparar y confirmar con contraseña) y cola
 * de los documentos completos que esperan su PDF final.
 */

if (!defined('ABSPATH')) exit;

const SQUUAD_CERT_SIGNER_INBOX_PAGE = 'squuad-cert-documents-to-sign';

add_action('admin_menu', 'squuad_cert_signer_inbox_menu', 999);
function squuad_cert_signer_inbox_menu(): void
{
    if (!function_exists('squuad_cert_signers_enabled') || !squuad_cert_signers_enabled() || !current_user_can(SQUUAD_CERT_SIGN_DOCUMENTS_CAP)) {
        return;
    }
    $count = count(squuad_cert_signer_inbox(get_current_user_id()));
    $title = __('Documents to sign', 'wp-certificates');
    $menu = $count ? $title . ' <span class="awaiting-mod">' . (int) $count . '</span>' : $title;
    add_menu_page($title, $menu, SQUUAD_CERT_SIGN_DOCUMENTS_CAP, SQUUAD_CERT_SIGNER_INBOX_PAGE, 'squuad_cert_signer_inbox_page', 'dashicons-edit-page', 3);
}

/**
 * Aviso en el panel del firmante: documentos que esperan su firma (paso 10). No en la propia bandeja. Va en
 * all_admin_notices porque admin/functions/branding.php (hide_notices) quita admin_notices a quien no es
 * superadministrador, y este aviso es parte del circuito de firma.
 */
add_action('all_admin_notices', 'squuad_cert_signer_inbox_pending_notice');
function squuad_cert_signer_inbox_pending_notice(): void
{
    if (($_GET['page'] ?? '') === SQUUAD_CERT_SIGNER_INBOX_PAGE || !function_exists('squuad_cert_signers_enabled') || !squuad_cert_signers_enabled()
        || !current_user_can(SQUUAD_CERT_SIGN_DOCUMENTS_CAP)) {
        return;
    }
    $count = count(squuad_cert_signer_inbox(get_current_user_id()));
    if (!$count) {
        return;
    }
    printf(
        '<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
        esc_html(sprintf(_n('You have %d document waiting for your signature.', 'You have %d documents waiting for your signature.', $count, 'wp-certificates'), $count)),
        esc_url(add_query_arg('page', SQUUAD_CERT_SIGNER_INBOX_PAGE, admin_url('admin.php'))),
        esc_html__('Review and sign', 'wp-certificates')
    );
}

add_action('admin_post_squuad_cert_sign_as_signer', 'squuad_cert_signer_inbox_handle_sign');
function squuad_cert_signer_inbox_handle_sign(): void
{
    $request_id = absint($_POST['request_id'] ?? 0);
    check_admin_referer('squuad_cert_sign_as_signer_' . $request_id);
    if (!current_user_can(SQUUAD_CERT_SIGN_DOCUMENTS_CAP)) {
        wp_die(esc_html__('You are not allowed to sign this document.', 'wp-certificates'), 403);
    }
    $result = squuad_cert_signature_sign_as_signer(
        $request_id,
        sanitize_text_field(wp_unslash($_POST['content_sha256'] ?? '')),
        sanitize_text_field(wp_unslash($_POST['consent_version'] ?? ''))
    );
    squuad_cert_signers_notice($result['message'], $result['ok']);
    $args = ['page' => SQUUAD_CERT_SIGNER_INBOX_PAGE];
    if ($result['ok'] && $result['completed']) {
        $args += ['request_id' => $request_id, 'generate_pdf' => 1];
    } elseif (!$result['ok']) {
        $args['request_id'] = $request_id;
    }
    wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
    exit;
}

/**
 * Declinar desde el panel del firmante institucional (ADR 0003, punto 13): mismo efecto que declinar en Admisión y
 * también definitivo. Exige motivo y la aceptación de que no se puede revertir. Anula solo las firmas de esta
 * solicitud (la marca 'declined'), pone el documento del estudiante en "declinado" y avisa al estudiante y al
 * representante. Devuelve ['ok', 'message'].
 */
function squuad_cert_signer_decline_request(int $request_id, string $reason, bool $confirmed): array
{
    global $wpdb;

    $user_id = get_current_user_id();
    $request = squuad_cert_signature_request_get($request_id);
    $slot = $request ? squuad_cert_signature_request_role($request, $user_id) : '';
    $signer = squuad_cert_signer_by_user($user_id);
    if (!$request || 0 !== strpos($slot, 'signer:') || !$signer || 'active' !== $signer->status) {
        return ['ok' => false, 'message' => __('You are not allowed to sign this document.', 'wp-certificates')];
    }
    if (function_exists('squuad_cert_signature_session_switched_from') && squuad_cert_signature_session_switched_from()) {
        return ['ok' => false, 'message' => __('Documents cannot be signed from a switched session. Each person must sign from their own account.', 'wp-certificates')];
    }
    if (!in_array($request->status, ['open', 'partially_signed'], true) || !squuad_cert_signature_request_slot_open($request, $slot)) {
        return ['ok' => false, 'message' => __('This document no longer accepts signatures. Please reload the page.', 'wp-certificates')];
    }
    if (!$confirmed || '' === trim($reason)) {
        return ['ok' => false, 'message' => __('To decline a document you must write the reason and accept that the action cannot be reverted.', 'wp-certificates')];
    }

    // wp-certificates hace su parte: anula las firmas de la solicitud y la cierra como declinada. El requisito, sus
    // avisos y sus correos son de quien escuche el evento (EduSystem, si el documento está enlazado a un requisito)
    $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}squuad_cert_signatures WHERE request_id = %d", $request_id));
    squuad_cert_revoke_signatures($ids, sprintf('Documento rechazado: %s', $reason), $user_id);
    squuad_cert_signature_request_transition($request_id, ['open', 'partially_signed'], 'declined', [
        'declined_at_utc' => gmdate('Y-m-d H:i:s'),
        'declined_by' => $user_id,
        'decline_reason' => $reason,
    ]);
    squuad_cert_signature_request_log_event($request_id, 'declined_by_signer', ['role' => $slot, 'charge' => (string) $signer->charge]);
    squuad_cert_log(sprintf('El firmante %d (%s) declinó la solicitud %d: %s', $user_id, $signer->charge, $request_id, $reason), 'signing_policy');
    $request = squuad_cert_signature_request_get($request_id) ?? $request;
    do_action('squuad_cert_request_declined', squuad_cert_request_event_payload($request) + [
        'reason' => $reason,
        'actor_user_id' => $user_id,
        'origin' => 'signer',
    ]);

    return ['ok' => true, 'message' => 'issued' === ($request->origin ?? '')
        ? __('The document was declined. The administration will decide how to issue it again.', 'wp-certificates')
        : (wpc_edusystem_active() ? __('The document was declined. The student was notified.', 'wp-certificates') : __('The document was declined.', 'wp-certificates'))];
}

add_action('admin_post_squuad_cert_signer_decline', 'squuad_cert_signer_inbox_handle_decline');
function squuad_cert_signer_inbox_handle_decline(): void
{
    $request_id = absint($_POST['request_id'] ?? 0);
    check_admin_referer('squuad_cert_signer_decline_' . $request_id);
    if (!current_user_can(SQUUAD_CERT_SIGN_DOCUMENTS_CAP)) {
        wp_die(esc_html__('You are not allowed to sign this document.', 'wp-certificates'), 403);
    }
    $result = squuad_cert_signer_decline_request(
        $request_id,
        sanitize_textarea_field(wp_unslash($_POST['reason'] ?? '')),
        !empty($_POST['confirm_irreversible'])
    );
    squuad_cert_signers_notice($result['message'], $result['ok']);
    wp_safe_redirect(add_query_arg(array_filter(['page' => SQUUAD_CERT_SIGNER_INBOX_PAGE, 'request_id' => $result['ok'] ? 0 : $request_id]), admin_url('admin.php')));
    exit;
}

/** Firma en lote, paso 1: el firmante marca documentos de su bandeja y el servidor prepara el lote (ADR 0003, paso 6). */
add_action('admin_post_squuad_cert_signer_batch_prepare', 'squuad_cert_signer_inbox_handle_batch_prepare');
function squuad_cert_signer_inbox_handle_batch_prepare(): void
{
    check_admin_referer('squuad_cert_signer_batch_prepare');
    if (!current_user_can(SQUUAD_CERT_SIGN_DOCUMENTS_CAP)) {
        wp_die(esc_html__('You are not allowed to sign this document.', 'wp-certificates'), 403);
    }
    $result = squuad_cert_signature_batch_prepare(array_map('absint', (array) ($_POST['request_ids'] ?? [])));
    if (!$result['ok']) {
        squuad_cert_signers_notice($result['message'], false);
    }
    wp_safe_redirect(add_query_arg(array_filter(['page' => SQUUAD_CERT_SIGNER_INBOX_PAGE, 'batch_id' => $result['batch_id']]), admin_url('admin.php')));
    exit;
}

/** Firma en lote, paso 2: confirmación con el consentimiento del lote y la contraseña. */
add_action('admin_post_squuad_cert_signer_batch_confirm', 'squuad_cert_signer_inbox_handle_batch_confirm');
function squuad_cert_signer_inbox_handle_batch_confirm(): void
{
    $batch_id = absint($_POST['batch_id'] ?? 0);
    check_admin_referer('squuad_cert_signer_batch_confirm_' . $batch_id);
    if (!current_user_can(SQUUAD_CERT_SIGN_DOCUMENTS_CAP)) {
        wp_die(esc_html__('You are not allowed to sign this document.', 'wp-certificates'), 403);
    }
    $result = squuad_cert_signature_batch_confirm(
        $batch_id,
        (string) wp_unslash($_POST['password'] ?? ''), // phpcs:ignore -- contraseña: no se sanea
        sanitize_text_field(wp_unslash($_POST['consent_sha256'] ?? ''))
    );
    squuad_cert_signers_notice($result['message'], $result['ok']);
    wp_safe_redirect(add_query_arg(['page' => SQUUAD_CERT_SIGNER_INBOX_PAGE, 'batch_id' => $batch_id], admin_url('admin.php')));
    exit;
}

function squuad_cert_signer_inbox_page(): void
{
    global $wpdb;

    $user_id = get_current_user_id();
    $notice = squuad_cert_signers_take_notice();
    $request_id = absint($_GET['request_id'] ?? 0);
    $profile = squuad_cert_user_signature_active($user_id);
    $items = squuad_cert_signer_inbox($user_id);

    // Documento concreto: pendiente de este usuario, o recién completado por él para generar el PDF
    $request = null;
    $final_html = null;
    $generate_pdf = false;
    if ($request_id) {
        $candidate = squuad_cert_signature_request_get($request_id);
        $slot = $candidate ? squuad_cert_signature_request_role($candidate, $user_id) : '';
        if ($candidate && 0 === strpos($slot, 'signer:')) {
            $request = $candidate;
            $generate_pdf = !empty($_GET['generate_pdf']) && 'signed' === $candidate->status;
            // B3 (ADR 0007 de Edusof): en pantalla, los documentos de identidad de los recuadros van enmascarados; al
            // generar el PDF final, completos
            squuad_cert_id_document_mask_in_boxes(!$generate_pdf);
            $final_html = squuad_cert_signature_request_render_final($candidate);
            squuad_cert_id_document_mask_in_boxes(false);
        }
    }
    $document_title = $request ? (string) $wpdb->get_var($wpdb->prepare(
        "SELECT title FROM {$wpdb->prefix}documents_certificates WHERE id = %d",
        (int) $request->document_certificate_id
    )) : '';
    $pending_here = $request && in_array($request->status, ['open', 'partially_signed'], true)
        && !in_array(squuad_cert_signature_request_role($request, $user_id), squuad_cert_signature_request_signed_roles((int) $request->id), true)
        && squuad_cert_signature_request_slot_open($request, squuad_cert_signature_request_role($request, $user_id));

    // Lote: confirmación (preparado) o resultado (terminado); cola de PDF finales pendientes
    $batch = !$request && !empty($_GET['batch_id']) ? squuad_cert_signature_batch_get(absint($_GET['batch_id'])) : null;
    $pdf_queue = $request ? [] : squuad_cert_signer_pdf_queue($user_id);
    // Documento de identidad (ADR 0007 de Edusof): si tiene algo que firmar y le falta, el formulario va primero
    $id_document_blocked = squuad_cert_id_document_missing($user_id)
        && ($items || $pending_here || $batch);
    // Q3: el formulario va encima; solo se quitan los botones de firma (PDF final, cola de PDF, resultado del lote y
    // «Declinar» siguen accesibles)

    include SQUUAD_CERT_MODULE_PATH . 'admin/templates/signer-inbox.php';
}
