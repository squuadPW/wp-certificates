<?php
/**
 * EduSystem - Certificación: órdenes que EduSystem da al módulo (ADR 0004, paso 3g). Se llaman desde los envoltorios
 * de includes/certification.php, que antes anotan la orden en la bandeja (edusystem_certification_outbox).
 *
 * - squuad_cert_signature_order_decline(): declinar un requisito anula las firmas de la solicitud ligada a él.
 * - squuad_cert_signature_order_close_by_upload(): subir un archivo cierra la solicitud de firma abierta del requisito.
 */

if (!defined('ABSPATH')) exit;

/**
 * Anula las firmas de la solicitud ligada al requisito declinado ($document_loaded->id, fila de student_documents de
 * EduSystem = external_ref) y la cierra como declinada. Devuelve 'declined_request' o 'not_signed' (el requisito no
 * tiene una solicitud que anular). Las firmas antiguas (users_signatures) no se tocan: son legales y quedan selladas
 * (decisión del dueño); su anulación caso por caso va por squuad_cert_legacy_revocations (ADR 0004, sección 6).
 */
function squuad_cert_signature_order_decline(int $student_id, object $document_loaded, int $user_id, string $description): string
{
    global $wpdb;

    $external_ref = (int) ($document_loaded->id ?? 0);
    $request = $external_ref && squuad_cert_signature_requests_enabled() ? $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}squuad_cert_requests WHERE external_ref = %d ORDER BY round DESC, id DESC LIMIT 1",
        $external_ref
    )) : null;
    if (!$request || 'declined' === $request->status) {
        return 'not_signed';
    }

    $signature_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}squuad_cert_signatures WHERE request_id = %d",
        $request->id
    ));
    squuad_cert_revoke_signatures($signature_ids, sprintf('Documento rechazado: %s', $description), get_current_user_id(), $external_ref);
    squuad_cert_signature_request_transition((int) $request->id, ['open', 'partially_signed', 'signed', 'completed'], 'declined', [
        'declined_at_utc' => gmdate('Y-m-d H:i:s'),
        'declined_by' => get_current_user_id(),
        'decline_reason' => $description,
    ]);
    $request = squuad_cert_signature_request_get((int) $request->id) ?? $request;
    // La declinación nació en EduSystem (origin 'edusystem'): su oyente no debe reenviar la orden
    do_action('squuad_cert_request_declined', squuad_cert_request_event_payload($request) + [
        'reason' => $description,
        'actor_user_id' => get_current_user_id(),
        'origin' => 'edusystem',
    ]);

    return 'declined_request';
}

/** ¿Hay una solicitud de firma abierta ligada a este requisito (fila de student_documents de EduSystem)? */
function squuad_cert_signature_order_has_open_request(int $student_document_id): bool
{
    return function_exists('squuad_cert_signature_request_open_for_row') && (bool) squuad_cert_signature_request_open_for_row($student_document_id);
}

/**
 * Cierra como closed_by_upload la solicitud abierta del requisito. Devuelve 'closed' o 'no_open_request'. $extra: datos
 * que se sellan en el evento; una orden entregada después de una pausa lleva la huella del archivo (file_sha256), la
 * fecha y el autor de la subida (ADR 0004, sección 5).
 */
function squuad_cert_signature_order_close_by_upload(int $student_document_id, string $file_path, int $user_id, string $reason, array $extra = []): string
{
    if (!squuad_cert_signature_order_has_open_request($student_document_id)) {
        return 'no_open_request';
    }

    return squuad_cert_signature_request_close_by_upload($student_document_id, $file_path, $user_id, $reason, $extra) ? 'closed' : 'failed';
}
