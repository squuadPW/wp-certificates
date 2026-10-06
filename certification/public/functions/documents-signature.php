<?php
/**
 * Certificación: firma del documento por el estudiante desde Mi Cuenta (AJAX create_enrollment_document y funciones
 * squuad_cert_signature_*). Movido desde public/functions/documents.php de EduSystem (ADR 0004, paso 3c).
 *
 * Solo se firma con solicitud (ADR 0002): el servidor deduce de ella el titular, el puesto y el contenido. El camino
 * antiguo sin solicitud (el navegador enviaba los ids del estudiante y del representante, para la inscripción y la
 * carta de documentos faltantes de las plantillas PHP) no pasa a wp-certificates: en 2.0.0 las solicitudes siempre
 * existen, y el representante ya no firma (decisión del dueño, 2026-10-01).
 */

if (!defined('ABSPATH')) exit;

add_action('wp_ajax_create_enrollment_document', 'squuad_cert_create_enrollment_document_callback');

/** PDF final: tamaño máximo aceptado. */
const SQUUAD_CERT_FINAL_PDF_MAX_BYTES = 15728640; // 15 MB
/** Reserva de la subida del PDF final para quien dio la última firma desde Mi Cuenta (ADR 0011 de Edusof). */
const SQUUAD_CERT_FINAL_PDF_TOKEN_MINUTES = 15;

/** Nombre del transitorio con la reserva de la subida del PDF final de una solicitud. */
function squuad_cert_final_pdf_token_key(int $request_id): string
{
    return 'squuad_cert_pdf_tok_' . $request_id;
}

/**
 * Reserva la subida del PDF final para quien dio la última firma: token de un solo uso (se guarda solo su HMAC) ligado a
 * la cuenta, la solicitud y la huella del contenido, durante 15 minutos. Devuelve el token para el navegador.
 */
function squuad_cert_final_pdf_token_issue(object $request, int $user_id): string
{
    $token = wp_generate_password(32, false, false);
    set_transient(squuad_cert_final_pdf_token_key((int) $request->id), [
        'hmac' => hash_hmac('sha256', $token . '|' . (int) $request->id . '|' . $user_id . '|' . (string) $request->content_sha256, wp_salt('auth')),
        'user_id' => $user_id,
        'content_sha256' => (string) $request->content_sha256,
        'until' => time() + SQUUAD_CERT_FINAL_PDF_TOKEN_MINUTES * MINUTE_IN_SECONDS,
    ], SQUUAD_CERT_FINAL_PDF_TOKEN_MINUTES * MINUTE_IN_SECONDS);

    return $token;
}

/**
 * ¿Puede esta cuenta subir ahora el PDF final de la solicitud? Mientras la reserva esté vigente, solo quien la recibió
 * (y, si envía el token, tiene que coincidir); al caducar, los caminos de recuperación de siempre («Generar PDF» en
 * «Documentos por firmar», el lote o la bandeja del firmante).
 */
function squuad_cert_final_pdf_token_allows(object $request, int $user_id, string $token): bool
{
    $reserved = get_transient(squuad_cert_final_pdf_token_key((int) $request->id));
    if (!is_array($reserved) || (int) ($reserved['until'] ?? 0) < time()) {
        return true;
    }
    if ((int) $reserved['user_id'] !== $user_id || !hash_equals((string) $reserved['content_sha256'], (string) $request->content_sha256)) {
        return false;
    }

    return '' === $token || hash_equals((string) $reserved['hmac'], hash_hmac('sha256', $token . '|' . (int) $request->id . '|' . $user_id . '|' . (string) $request->content_sha256, wp_salt('auth')));
}

/**
 * Firma decodificada que envía create-enrollment.js (JSON de signature_pad o ["automatic"]); [] si no hay.
 */
function squuad_cert_signature_from_request($name)
{
    $signature = is_string($_POST[$name] ?? null) ? json_decode(wp_unslash($_POST[$name])) : null;

    return is_array($signature) ? $signature : [];
}

function squuad_cert_create_enrollment_document_callback()
{
    global $wpdb;

    if (!check_ajax_referer('edusystem_signatures', false, false)) {
        wp_send_json_error(__('Your session expired. Please reload the page.', 'wp-certificates'), 403);
    }

    // Nadie firma por otro desde una sesión conmutada (Switch de usuario): ADR 0001, decisión abierta 1
    $switched_from = function_exists('squuad_cert_signature_session_switched_from') ? squuad_cert_signature_session_switched_from() : 0;
    if ($switched_from) {
        squuad_cert_log(
            sprintf('Firma bloqueada: el usuario %d intentó firmar desde la cuenta del usuario %d (Switch)', $switched_from, get_current_user_id()),
            'signature_blocked'
        );
        wp_send_json_error(__('Documents cannot be signed from a switched session. Each person must sign from their own account.', 'wp-certificates'), 403);
    }

    // Solicitudes de firma (ADR 0002): el servidor deduce de la solicitud el estudiante, el puesto y el contenido; lo
    // que envía el navegador (ids de usuario, documento) no se usa. Sin solicitud no se firma.
    $request_id = absint($_POST['request_id'] ?? 0);
    if ($request_id && squuad_cert_signature_requests_enabled()) {
        squuad_cert_signature_handle_request_submission($request_id);
    }
    // Sin solicitud no hay nada que firmar: se rechaza como cualquier firma sin permiso (página antigua o petición ajena)
    squuad_cert_log(sprintf('Firma sin solicitud rechazada (usuario %d)', get_current_user_id()), 'signature_blocked');
    wp_send_json_error(__('You are not allowed to sign this document.', 'wp-certificates'), 403);
}

/**
 * Firma (y, al final, PDF) de un documento con solicitud (ADR 0002). Siempre responde y termina.
 * - El rol sale de los firmantes fijados en la solicitud; cada usuario firma solo el suyo.
 * - La primera firma congela el contenido: el hash que mostró el navegador tiene que ser el borrador vigente.
 * - El PDF se acepta una sola vez, cuando ya firmaron todos, y cierra la solicitud como completada.
 */
function squuad_cert_signature_handle_request_submission(int $request_id): void
{
    global $wpdb;

    $request = squuad_cert_signature_request_get($request_id);
    $user_id = get_current_user_id();
    $role = $request ? squuad_cert_signature_request_role($request, $user_id) : '';
    // Quien carga el documento sin firmarlo (automático con campos adicionales en el que el estudiante no firma)
    $holder = $request && '' === $role && squuad_cert_signature_request_is_holder($request, $user_id);
    if (!$request || ('' === $role && !$holder)) {
        squuad_cert_log(sprintf('Firma rechazada: el usuario %d no firma la solicitud %d', $user_id, $request_id), 'signature_blocked');
        wp_send_json_error(__('You are not allowed to sign this document.', 'wp-certificates'), 403);
    }
    if (!in_array($request->status, SQUUAD_CERT_SIGNATURE_REQUEST_OPEN, true)) {
        wp_send_json_error(__('This document no longer accepts signatures. Please reload the page.', 'wp-certificates'), 409);
    }
    if ('' !== $role && function_exists('squuad_cert_signature_request_slot_open') && !squuad_cert_signature_request_slot_open($request, $role)) {
        wp_send_json_error(__('This document is still waiting for previous signatures.', 'wp-certificates'), 409);
    }
    // Firmante por variable (ADR 0012 de Edusof): la variable tiene que seguir dando esta cuenta para el titular (p. ej.
    // el representante no cambió en la ficha); si no, no firma y la solicitud espera a que la reinicien
    if (function_exists('squuad_cert_request_var_slot_valid') && !squuad_cert_request_var_slot_valid($request, $role, $user_id)) {
        wp_send_json_error(__('You can no longer sign this document. Ask the school office to request the signatures again.', 'wp-certificates'), 403);
    }

    $shown_sha256 = is_string($_POST['content_sha256'] ?? null) ? strtolower(sanitize_text_field(wp_unslash($_POST['content_sha256']))) : '';
    $signature_student = squuad_cert_signature_from_request('signature_student');
    // Firma del flujo nuevo (formato v2, ADR 0011 de Edusof): escrita, dibujada o imagen subida, validada en el servidor
    $signature_v2 = null;
    if (is_string($_POST['signature_v2'] ?? null) && '' !== $_POST['signature_v2']) {
        $signature_v2 = squuad_cert_signature_v2_from_input((string) wp_unslash($_POST['signature_v2']));
        if (is_wp_error($signature_v2)) {
            squuad_cert_log(sprintf('Firma rechazada: formato no válido del usuario %d en la solicitud %d (%s)', $user_id, $request_id, $signature_v2->get_error_code()), 'signature_blocked');
            wp_send_json_error($signature_v2->get_error_message(), 400);
        }
        $signature_student = ['v2'];
    }
    // Por aquí firma solo quien recibe el documento (su recuadro llega como signature_student); los firmantes del sistema firman desde su bandeja y solo envían por aquí el PDF
    // final, sin firma. Una firma de otro recuadro (p. ej. el del representante de una página antigua) se rechaza.
    // También firma por aquí un firmante por variable (ADR 0009 de Edusof: p. ej. el representante), en su propio puesto
    if (($signature_student && !squuad_cert_is_person_slot($role)) || squuad_cert_signature_from_request('signature_parent')) {
        wp_send_json_error(__('You can only sign your own part of the document.', 'wp-certificates'), 403);
    }
    $signature = $signature_student;
    // Documento de identidad (ADR 0007 de Edusof): sin él no se firma (con «Pedir documento de identidad» encendido).
    // Solo se pide a quien firma: guardar las respuestas de un automático sin firma y el PDF final no lo exigen
    $id_document_error = squuad_cert_id_document_signing_error($user_id);
    if (null !== $id_document_error && $signature) {
        squuad_cert_log(sprintf('Firma rechazada: el usuario %d no tiene documento de identidad (solicitud %d)', $user_id, $request_id), 'signature_blocked');
        wp_send_json_error($id_document_error, 403);
    }
    $file = !empty($_FILES['document']) ? $_FILES['document'] : null;
    $signed_roles = squuad_cert_signature_request_signed_roles((int) $request->id);

    // 1) Firma del usuario (la primera congela el contenido)
    if ($signature) {
        if (in_array($role, $signed_roles, true)) {
            wp_send_json_error(__('You already signed this document.', 'wp-certificates'), 409);
        }
        // La primera firma congela el contenido, sea de quien recibe el documento o de un firmante por variable: la da
        // quien tiene el primer turno (ADR 0012 de Edusof; el turno ya se comprobó arriba)
        // Consentimiento explícito (ADR 0002, punto 7): sin la casilla no hay firma, también con la firma automática. Quien
        // firma en representación del titular acepta su propio texto (ADR 0012 de Edusof)
        $consent = squuad_cert_signature_consent_evidence(
            is_string($_POST['consent_version'] ?? null) ? sanitize_text_field(wp_unslash($_POST['consent_version'])) : '',
            $request,
            $role
        );
        if (null === $consent) {
            wp_send_json_error(__('To sign, you must accept signing the document electronically.', 'wp-certificates'), 400);
        }
        if (!squuad_cert_signature_request_freeze((int) $request->id, $shown_sha256, $user_id)) {
            wp_send_json_error(__('The document was updated while you had it open. Please reload the page and review it again before signing.', 'wp-certificates'), 409);
        }
        $request = squuad_cert_signature_request_get((int) $request->id);
        $signature_json = $signature_v2 ? $signature_v2['json'] : wp_json_encode($signature);
        $signature_id = squuad_cert_signature_insert([
            'user_id' => $user_id,
            'signature' => $signature_json,
            'document_id' => $request->document_id,
        ], (int) $request->subject_id, $role, [
            'request_id' => (int) $request->id,
            'external_ref' => (int) $request->external_ref,
            'round' => (int) $request->round,
            'content_sha256' => (string) $request->content_sha256,
            'request_created_fingerprint' => squuad_cert_signature_request_created_fingerprint((int) $request->id),
            'template_version_sha256' => (string) $request->template_version_sha256,
            'signature_method' => $signature_v2 ? $signature_v2['method'] : ('["automatic"]' === $signature_json ? 'automatic' : 'drawn'),
        ] + ($signature_v2 ? ['signature_image_sha256' => $signature_v2['image_sha256']] : []) + $consent, (string) $request->subject_type);
        if (!$signature_id) {
            wp_send_json_error(__('Your signature could not be saved. Please reload the page and try again.', 'wp-certificates'), 409);
        }
        squuad_cert_signature_request_log_event((int) $request->id, 'signed', ['role' => $role, 'signature_id' => $signature_id] + $consent
            + ($signature_v2 ? ['signature_method' => $signature_v2['method'], 'signature_image_sha256' => $signature_v2['image_sha256']] : []));

        $signed_roles = squuad_cert_signature_request_signed_roles((int) $request->id);
        $all_signed = !array_diff(squuad_cert_signature_request_required_roles($request), $signed_roles);
        squuad_cert_signature_request_transition((int) $request->id, ['open', 'partially_signed'], $all_signed ? 'signed' : 'partially_signed');
        // Estudiante y representante terminaron: avisar a los firmantes institucionales (ADR 0003, paso 10)
        if (function_exists('squuad_cert_signer_notify_open_slots')) {
            squuad_cert_signer_notify_open_slots((int) $request->id);
        }
        $request = squuad_cert_signature_request_get((int) $request->id);
    }

    // 1b) Quien solo rellena (campos adicionales, sin firma): al guardar se congela el contenido que vio. Si nadie más
    // tiene que firmar, la solicitud queda lista para su PDF final, que llega en esta misma petición.
    if ($holder && null === $request->frozen_at_utc) {
        if (!squuad_cert_signature_request_freeze((int) $request->id, $shown_sha256, $user_id)) {
            wp_send_json_error(__('The document was updated while you had it open. Please reload the page and review it again before saving.', 'wp-certificates'), 409);
        }
        squuad_cert_signature_request_log_event((int) $request->id, 'filled', ['role' => 'holder', 'content_sha256' => $shown_sha256]);
        $request = squuad_cert_signature_request_get((int) $request->id);
        if (!squuad_cert_signature_request_required_roles($request)) {
            squuad_cert_signature_request_transition((int) $request->id, ['open'], 'signed');
            $request = squuad_cert_signature_request_get((int) $request->id);
        } elseif (function_exists('squuad_cert_signer_notify_open_slots')) {
            squuad_cert_signer_notify_open_slots((int) $request->id);
        }
    }

    // 2) PDF final: una sola vez, con todas las firmas y sobre el contenido congelado
    $attach_id = null;
    // Faltan firmas (de cualquier turno, ADR 0012 de Edusof): el PDF lo generará el último firmante; la firma de este
    // usuario ya quedó
    if ($file && 'signed' !== $request->status && function_exists('squuad_cert_signature_request_others_pending')
        && squuad_cert_signature_request_others_pending($request, $user_id)) {
        $file = null;
    }
    if ($file) {
        if ('signed' !== $request->status) {
            wp_send_json_error(__('The document can only be completed when everyone has signed from their own account.', 'wp-certificates'), 409);
        }
        if (!hash_equals((string) $request->content_sha256, $shown_sha256)) {
            wp_send_json_error(__('The document was updated while you had it open. Please reload the page and review it again before signing.', 'wp-certificates'), 409);
        }
        // Reserva de la subida (ADR 0011 de Edusof): mientras dure, solo quien dio la última firma desde Mi Cuenta
        $pdf_token = is_string($_POST['pdf_token'] ?? null) ? sanitize_text_field(wp_unslash($_POST['pdf_token'])) : '';
        if (!squuad_cert_final_pdf_token_allows($request, $user_id, $pdf_token)) {
            squuad_cert_log(sprintf('PDF final rechazado: el usuario %d intentó subir el de la solicitud %d, reservado para quien dio la última firma', $user_id, (int) $request->id), 'signature_blocked');
            wp_send_json_error(__('The final PDF of this document is being generated by the person who signed last. Please try again in a few minutes.', 'wp-certificates'), 409);
        }
        $is_pdf = is_array($file)
            && UPLOAD_ERR_OK === ($file['error'] ?? UPLOAD_ERR_NO_FILE)
            && is_uploaded_file($file['tmp_name'])
            && filesize($file['tmp_name']) <= SQUUAD_CERT_FINAL_PDF_MAX_BYTES
            && '%PDF-' === file_get_contents($file['tmp_name'], false, null, 0, 5);
        if (!$is_pdf) {
            wp_send_json_error(__('The final PDF is not valid or is larger than 15 MB.', 'wp-certificates'), 400);
        }
        $pdf = (string) file_get_contents($file['tmp_name']);
        // D3 (ADR 0007 de Edusof): nombre aleatorio no predecible y adjunto privado, fuera de la API de medios y de la
        // biblioteca (includes/signed-pdf.php). El título conserva el nombre del documento para quien lo administra
        $title = sanitize_file_name(preg_replace('/\.pdf$/i', '', (string) $file['name'])) . '.pdf';
        $upload = wp_upload_bits(squuad_cert_signed_pdf_filename(), null, $pdf);
        if (!empty($upload['error'])) {
            wp_send_json_error('Failed to upload file');
        }
        $attach_id = wp_insert_attachment([
            'post_mime_type' => $upload['type'],
            'post_title' => $title,
            'post_content' => '',
            'post_status' => 'private',
        ], $upload['file']);
        squuad_cert_signed_pdf_protect((int) $attach_id, 'request:' . (int) $request->id);
        require_once ABSPATH . 'wp-admin/includes/image.php';
        wp_update_attachment_metadata($attach_id, wp_generate_attachment_metadata($attach_id, $upload['file']));

        // Solo una finalización: si otra petición ya la completó, este PDF se descarta
        $completed = squuad_cert_signature_request_transition((int) $request->id, ['signed'], 'completed', [
            'completed_at_utc' => gmdate('Y-m-d H:i:s'),
            'final_attachment_id' => $attach_id,
            'final_pdf_sha256' => hash('sha256', $pdf),
            'final_uploaded_by' => $user_id,
        ]);
        if (!$completed) {
            wp_delete_attachment($attach_id, true);
            wp_send_json_error(__('This document was already completed. Please reload the page.', 'wp-certificates'), 409);
        }

        delete_transient(squuad_cert_final_pdf_token_key((int) $request->id));
        // Documento completo (todas las firmas y su PDF): se avisa. Si está enlazado a un requisito de EduSystem
        // (Admisión > Documentos > «Document template»), EduSystem lo pone en la ficha para que Admisión lo revise
        squuad_cert_log(sprintf('Solicitud %d completada: PDF final %d subido por el usuario %d', (int) $request->id, $attach_id, $user_id), 'signature');
        $request = squuad_cert_signature_request_get((int) $request->id) ?? $request;
        do_action('squuad_cert_request_completed', squuad_cert_request_event_payload($request) + [
            'final_attachment_id' => (int) $attach_id,
            'final_pdf_sha256' => hash('sha256', $pdf),
        ]);
    }

    // Ventana de firma nueva (ADR 0011 de Edusof): estado de cada firmante para el panel de éxito y, si con esta firma
    // ya firmaron todos, el documento final (con el certificado de firmas) para que el navegador genere el PDF y lo suba
    // en una segunda petición (las mismas comprobaciones de arriba: estado 'signed', huella y una sola finalización)
    $response = ['media_id' => $attach_id];
    if ($signature) {
        $request = squuad_cert_signature_request_get((int) $request->id) ?? $request;
        $response['signers'] = squuad_cert_signature_signers_overview($request, $user_id);
        $response['final'] = 'signed' === $request->status && !$attach_id ? squuad_cert_signature_final_pdf_payload($request) : null;
        if ($response['final']) {
            $response['final']['token'] = squuad_cert_final_pdf_token_issue($request, $user_id);
        }
    }
    wp_send_json_success($response);
}
