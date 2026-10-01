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

const EDUSYSTEM_SIGNER_INBOX_PAGE = 'edusystem-documents-to-sign';

add_action('admin_menu', 'edusystem_signer_inbox_menu', 999);
function edusystem_signer_inbox_menu(): void
{
    if (!function_exists('edusystem_signers_enabled') || !edusystem_signers_enabled() || !current_user_can(EDUSYSTEM_SIGN_DOCUMENTS_CAP)) {
        return;
    }
    $count = count(edusystem_signer_inbox(get_current_user_id()));
    $title = __('Documents to sign', 'edusystem');
    $menu = $count ? $title . ' <span class="awaiting-mod">' . (int) $count . '</span>' : $title;
    add_menu_page($title, $menu, EDUSYSTEM_SIGN_DOCUMENTS_CAP, EDUSYSTEM_SIGNER_INBOX_PAGE, 'edusystem_signer_inbox_page', 'dashicons-edit-page', 3);
}

/**
 * Aviso en el panel del firmante: documentos que esperan su firma (paso 10). No en la propia bandeja. Va en
 * all_admin_notices porque admin/functions/branding.php (hide_notices) quita admin_notices a quien no es
 * superadministrador, y este aviso es parte del circuito de firma.
 */
add_action('all_admin_notices', 'edusystem_signer_inbox_pending_notice');
function edusystem_signer_inbox_pending_notice(): void
{
    if (($_GET['page'] ?? '') === EDUSYSTEM_SIGNER_INBOX_PAGE || !function_exists('edusystem_signers_enabled') || !edusystem_signers_enabled()
        || !current_user_can(EDUSYSTEM_SIGN_DOCUMENTS_CAP)) {
        return;
    }
    $count = count(edusystem_signer_inbox(get_current_user_id()));
    if (!$count) {
        return;
    }
    printf(
        '<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
        esc_html(sprintf(_n('You have %d document waiting for your signature.', 'You have %d documents waiting for your signature.', $count, 'edusystem'), $count)),
        esc_url(add_query_arg('page', EDUSYSTEM_SIGNER_INBOX_PAGE, admin_url('admin.php'))),
        esc_html__('Review and sign', 'edusystem')
    );
}

add_action('admin_post_edusystem_sign_as_signer', 'edusystem_signer_inbox_handle_sign');
function edusystem_signer_inbox_handle_sign(): void
{
    $request_id = absint($_POST['request_id'] ?? 0);
    check_admin_referer('edusystem_sign_as_signer_' . $request_id);
    if (!current_user_can(EDUSYSTEM_SIGN_DOCUMENTS_CAP)) {
        wp_die(esc_html__('You are not allowed to sign this document.', 'edusystem'), 403);
    }
    $result = edusystem_signature_sign_as_signer(
        $request_id,
        sanitize_text_field(wp_unslash($_POST['content_sha256'] ?? '')),
        sanitize_text_field(wp_unslash($_POST['consent_version'] ?? ''))
    );
    edusystem_signers_notice($result['message'], $result['ok']);
    $args = ['page' => EDUSYSTEM_SIGNER_INBOX_PAGE];
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
function edusystem_signer_decline_request(int $request_id, string $reason, bool $confirmed): array
{
    global $wpdb;

    $user_id = get_current_user_id();
    $request = edusystem_signature_request_get($request_id);
    $slot = $request ? edusystem_signature_request_role($request, $user_id) : '';
    $signer = edusystem_signer_by_user($user_id);
    if (!$request || 0 !== strpos($slot, 'signer:') || !$signer || 'active' !== $signer->status) {
        return ['ok' => false, 'message' => __('You are not allowed to sign this document.', 'edusystem')];
    }
    if (function_exists('edusystem_signature_session_switched_from') && edusystem_signature_session_switched_from()) {
        return ['ok' => false, 'message' => __('Documents cannot be signed from a switched session. The student or parent must sign from their own account.', 'edusystem')];
    }
    if (!in_array($request->status, ['open', 'partially_signed'], true) || !edusystem_signature_request_slot_open($request, $slot)) {
        return ['ok' => false, 'message' => __('This document no longer accepts signatures. Please reload the page.', 'edusystem')];
    }
    if (!$confirmed || '' === trim($reason)) {
        return ['ok' => false, 'message' => __('To decline a document you must write the reason and accept that the action cannot be reverted.', 'edusystem')];
    }

    $student_id = (int) $request->student_id;
    $row_id = (int) $request->student_document_id ?: (int) edusystem_signature_student_document_id($student_id, (string) $request->document_id);
    $document = $row_id && function_exists('get_document_details') ? get_document_details($row_id) : null;

    if ($document && 3 !== (int) $document->status) {
        // El mismo circuito que el rechazo de Admisión: estado, avisos, anulación de la solicitud y correos
        $description = build_status_description(3, $reason, $document);
        update_document_status($row_id, $student_id, 3, $description);
        handle_status_notifications(3, get_related_users($student_id), $description);
        handle_status_specific_actions(3, $student_id, get_document_details($row_id));
        if (function_exists('WC') && WC()->mailer()) {
            $emails = WC()->mailer()->get_emails();
            if (isset($emails['WC_Rejected_Document_Email'])) {
                $emails['WC_Rejected_Document_Email']->trigger($student_id, $row_id, $description);
                $emails['WC_Rejected_Document_Email']->trigger($student_id, $row_id, $description, true);
            }
        }
    } else {
        // Sin fila del documento: se anulan las firmas de la solicitud y se cierra como declinada
        $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}users_signatures WHERE request_id = %d", $request_id));
        edusystem_revoke_signatures($ids, sprintf('Documento rechazado: %s', $reason), $user_id);
        edusystem_signature_request_transition($request_id, ['open', 'partially_signed'], 'declined', [
            'declined_at_utc' => gmdate('Y-m-d H:i:s'),
            'declined_by' => $user_id,
            'decline_reason' => $reason,
        ]);
    }
    edusystem_signature_request_log_event($request_id, 'declined_by_signer', ['role' => $slot, 'charge' => (string) $signer->charge]);
    if (function_exists('edusystem_set_log')) {
        edusystem_set_log(sprintf('El firmante %d (%s) declinó la solicitud %d: %s', $user_id, $signer->charge, $request_id, $reason), 'signing_policy');
    }

    return ['ok' => true, 'message' => 'issued' === ($request->origin ?? '')
        ? __('The document was declined. The administration will decide how to issue it again.', 'edusystem')
        : __('The document was declined. The student and the parent were notified.', 'edusystem')];
}

add_action('admin_post_edusystem_signer_decline', 'edusystem_signer_inbox_handle_decline');
function edusystem_signer_inbox_handle_decline(): void
{
    $request_id = absint($_POST['request_id'] ?? 0);
    check_admin_referer('edusystem_signer_decline_' . $request_id);
    if (!current_user_can(EDUSYSTEM_SIGN_DOCUMENTS_CAP)) {
        wp_die(esc_html__('You are not allowed to sign this document.', 'edusystem'), 403);
    }
    $result = edusystem_signer_decline_request(
        $request_id,
        sanitize_textarea_field(wp_unslash($_POST['reason'] ?? '')),
        !empty($_POST['confirm_irreversible'])
    );
    edusystem_signers_notice($result['message'], $result['ok']);
    wp_safe_redirect(add_query_arg(array_filter(['page' => EDUSYSTEM_SIGNER_INBOX_PAGE, 'request_id' => $result['ok'] ? 0 : $request_id]), admin_url('admin.php')));
    exit;
}

/** Firma en lote, paso 1: el firmante marca documentos de su bandeja y el servidor prepara el lote (ADR 0003, paso 6). */
add_action('admin_post_edusystem_signer_batch_prepare', 'edusystem_signer_inbox_handle_batch_prepare');
function edusystem_signer_inbox_handle_batch_prepare(): void
{
    check_admin_referer('edusystem_signer_batch_prepare');
    if (!current_user_can(EDUSYSTEM_SIGN_DOCUMENTS_CAP)) {
        wp_die(esc_html__('You are not allowed to sign this document.', 'edusystem'), 403);
    }
    $result = edusystem_signature_batch_prepare(array_map('absint', (array) ($_POST['request_ids'] ?? [])));
    if (!$result['ok']) {
        edusystem_signers_notice($result['message'], false);
    }
    wp_safe_redirect(add_query_arg(array_filter(['page' => EDUSYSTEM_SIGNER_INBOX_PAGE, 'batch_id' => $result['batch_id']]), admin_url('admin.php')));
    exit;
}

/** Firma en lote, paso 2: confirmación con el consentimiento del lote y la contraseña. */
add_action('admin_post_edusystem_signer_batch_confirm', 'edusystem_signer_inbox_handle_batch_confirm');
function edusystem_signer_inbox_handle_batch_confirm(): void
{
    $batch_id = absint($_POST['batch_id'] ?? 0);
    check_admin_referer('edusystem_signer_batch_confirm_' . $batch_id);
    if (!current_user_can(EDUSYSTEM_SIGN_DOCUMENTS_CAP)) {
        wp_die(esc_html__('You are not allowed to sign this document.', 'edusystem'), 403);
    }
    $result = edusystem_signature_batch_confirm(
        $batch_id,
        (string) wp_unslash($_POST['password'] ?? ''), // phpcs:ignore -- contraseña: no se sanea
        sanitize_text_field(wp_unslash($_POST['consent_sha256'] ?? ''))
    );
    edusystem_signers_notice($result['message'], $result['ok']);
    wp_safe_redirect(add_query_arg(['page' => EDUSYSTEM_SIGNER_INBOX_PAGE, 'batch_id' => $batch_id], admin_url('admin.php')));
    exit;
}

function edusystem_signer_inbox_page(): void
{
    global $wpdb;

    $user_id = get_current_user_id();
    $notice = edusystem_signers_take_notice();
    $request_id = absint($_GET['request_id'] ?? 0);
    $profile = edusystem_user_signature_active($user_id);
    $items = edusystem_signer_inbox($user_id);

    // Documento concreto: pendiente de este usuario, o recién completado por él para generar el PDF
    $request = null;
    $final_html = null;
    $generate_pdf = false;
    if ($request_id) {
        $candidate = edusystem_signature_request_get($request_id);
        $slot = $candidate ? edusystem_signature_request_role($candidate, $user_id) : '';
        if ($candidate && 0 === strpos($slot, 'signer:')) {
            $request = $candidate;
            $final_html = edusystem_signature_request_render_final($candidate);
            $generate_pdf = !empty($_GET['generate_pdf']) && 'signed' === $candidate->status;
        }
    }
    $document_title = $request ? (string) $wpdb->get_var($wpdb->prepare(
        "SELECT title FROM {$wpdb->prefix}documents_certificates WHERE id = %d",
        (int) $request->document_certificate_id
    )) : '';
    $pending_here = $request && in_array($request->status, ['open', 'partially_signed'], true)
        && !in_array(edusystem_signature_request_role($request, $user_id), edusystem_signature_request_signed_roles((int) $request->id), true)
        && edusystem_signature_request_slot_open($request, edusystem_signature_request_role($request, $user_id));

    // Lote: confirmación (preparado) o resultado (terminado); cola de PDF finales pendientes
    $batch = !$request && !empty($_GET['batch_id']) ? edusystem_signature_batch_get(absint($_GET['batch_id'])) : null;
    $pdf_queue = $request ? [] : edusystem_signer_pdf_queue($user_id);

    include EDUSYSTEM_CERTIFICATION_PATH . 'admin/templates/signer-inbox.php';
}
