<?php
/**
 * EduSystem - Certificación: órdenes que EduSystem da al módulo (ADR 0004, paso 3g). Se llaman desde los envoltorios
 * de includes/certification.php, que antes anotan la orden en la bandeja (edusystem_certification_outbox).
 *
 * - edusystem_signature_order_decline(): declinar un requisito anula las firmas de ese documento. Es el código que
 *   estaba en handle_rejected_document() (admin/admission/documents.php), movido sin cambios; solo devuelve qué hizo.
 * - edusystem_signature_order_close_by_upload(): subir un archivo cierra la solicitud de firma abierta del requisito.
 */

if (!defined('ABSPATH')) exit;

/**
 * Anula las firmas del documento declinado. Devuelve 'declined_request' (solicitud ADR 0002 declinada),
 * 'revoked_legacy' (firmas del modelo anterior) o 'not_signed' (el documento no se firma).
 */
function edusystem_signature_order_decline(int $student_id, object $document_loaded, int $user_id, string $description): string
{
    global $wpdb;

    $document_types = ['ENROLLMENT', 'MISSING DOCUMENT'];

    // Documentos que se firman desde Mi Cuenta: al rechazarlos se borran las firmas para que se vuelvan a pedir.
    // Además de los dos fijos, cualquier documento automático de wp-certificates.
    if (in_array($document_loaded->document_id, $document_types) || edusystem_get_automatic_document_by_identificator($document_loaded->document_id)) {
        $table_users_signatures = $wpdb->prefix . 'users_signatures';
        $student = get_student($student_id);
        $user_student = get_user_by('email', $student->email);
        $reason = sprintf('Documento rechazado: %s', (string) $description);

        // Con solicitudes (ADR 0002): se anulan solo las firmas de la solicitud de la última ronda de ESTE documento
        // y se cierra como declinada. La firma del representante en el documento de un hermano es de otra solicitud.
        $request = function_exists('edusystem_signature_request_latest')
            ? edusystem_signature_request_latest((int) $student_id, (string) $document_loaded->document_id)
            : null;
        if ($request && 'declined' !== $request->status) {
            $signature_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$table_users_signatures} WHERE request_id = %d",
                $request->id
            ));
            edusystem_revoke_signatures($signature_ids, $reason, get_current_user_id(), (int) $document_loaded->id);
            edusystem_signature_request_transition((int) $request->id, ['open', 'partially_signed', 'signed', 'completed'], 'declined', [
                'declined_at_utc' => gmdate('Y-m-d H:i:s'),
                'declined_by' => get_current_user_id(),
                'decline_reason' => (string) $description,
            ]);
            return 'declined_request';
        }

        // Modelo anterior (sin solicitud): firmas del estudiante y del representante de este documento, salvo la del
        // representante si tiene más de un estudiante (es compartida y puede ser la del hermano: no se anula)
        $signer_ids = [$user_student ? (int) $user_student->ID : 0];
        $parent_students = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}students WHERE partner_id = %d",
            $user_id
        ));
        if ($parent_students <= 1) {
            $signer_ids[] = (int) $user_id;
        }
        $in = implode(',', array_map('intval', $signer_ids));
        $signature_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$table_users_signatures} WHERE document_id = %s AND user_id IN ({$in})"
            . ((function_exists('edusystem_signature_requests_enabled') && edusystem_signature_requests_enabled()) ? ' AND request_id IS NULL' : ''),
            $document_loaded->document_id
        ));
        if (function_exists('edusystem_revoke_signatures')) {
            edusystem_revoke_signatures(
                $signature_ids,
                $reason,
                get_current_user_id(),
                (int) $document_loaded->id
            );
        } else {
            foreach ($signature_ids as $signature_id) {
                $wpdb->delete($table_users_signatures, ['id' => (int) $signature_id]);
            }
        }

        return 'revoked_legacy';
    }

    return 'not_signed';
}

/** ¿Hay una solicitud de firma abierta para este requisito (fila de student_documents)? */
function edusystem_signature_order_has_open_request(int $student_document_id): bool
{
    return function_exists('edusystem_signature_request_open_for_row') && (bool) edusystem_signature_request_open_for_row($student_document_id);
}

/** Cierra como closed_by_upload la solicitud abierta del requisito. Devuelve 'closed' o 'no_open_request'. */
function edusystem_signature_order_close_by_upload(int $student_document_id, string $file_path, int $user_id, string $reason): string
{
    if (!edusystem_signature_order_has_open_request($student_document_id)) {
        return 'no_open_request';
    }

    return edusystem_signature_request_close_by_upload($student_document_id, $file_path, $user_id, $reason) ? 'closed' : 'failed';
}
