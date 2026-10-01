<?php
/**
 * EduSystem - Certificación: firma del documento por el estudiante y su representante desde Mi Cuenta
 * (create_enrollment_document y funciones squuad_cert_signature_*). Movido sin cambios desde
 * public/functions/documents.php (ADR 0004, paso 3c).
 */

if (!defined('ABSPATH')) exit;

add_action('wp_ajax_create_enrollment_document', 'squuad_cert_create_enrollment_document_callback');

/**
 * Comprueba que el usuario actual puede firmar por este estudiante y su representante: tiene que ser uno de
 * los dos, y los dos tienen que ser pareja (students.partner_id). Devuelve la fila del estudiante o null.
 * Los IDs son de usuarios de WordPress; si el estudiante es su propio representante, ambos coinciden.
 */
function squuad_cert_signature_student($student_user_id, $partner_user_id)
{
    global $wpdb;

    $current_user_id = get_current_user_id();
    $student_user = $student_user_id ? get_user_by('id', $student_user_id) : null;
    if (!$current_user_id || !$student_user) {
        return null;
    }

    $student = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}students WHERE email = %s",
        $student_user->user_email
    ));
    if (!$student || (int) $student->partner_id !== (int) $partner_user_id) {
        return null;
    }
    if ($current_user_id !== (int) $student_user->ID && $current_user_id !== (int) $student->partner_id) {
        return null;
    }

    return $student;
}

/**
 * Documento que se puede firmar desde Mi Cuenta: la carta de documentos faltantes, uno de los documentos
 * del estudiante (student_documents, p. ej. ENROLLMENT) o un documento automático de wp-certificates.
 */
function squuad_cert_signature_document_is_valid($student, $document_id)
{
    global $wpdb;

    if ('MISSING DOCUMENT' === $document_id) {
        return true;
    }
    $has_document = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}student_documents WHERE student_id = %d AND document_id = %s LIMIT 1",
        $student->id,
        $document_id
    ));

    return $has_document || squuad_cert_get_automatic_document_by_identificator($document_id);
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
        wp_send_json_error(__('Your session expired. Please reload the page.', 'edusystem'), 403);
    }

    // Nadie firma por otro desde una sesión conmutada (Switch de usuario): ADR 0001, decisión abierta 1
    $switched_from = function_exists('squuad_cert_signature_session_switched_from') ? squuad_cert_signature_session_switched_from() : 0;
    if ($switched_from) {
        if (function_exists('edusystem_set_log')) {
            edusystem_set_log(
                sprintf('Firma bloqueada: el usuario %d intentó firmar desde la cuenta del usuario %d (Switch)', $switched_from, get_current_user_id()),
                'signature_blocked',
                $switched_from
            );
        }
        wp_send_json_error(__('Documents cannot be signed from a switched session. The student or parent must sign from their own account.', 'edusystem'), 403);
    }

    // Solicitudes de firma (ADR 0002, esquema v5): el servidor deduce de la solicitud el estudiante, los firmantes y
    // el rol; lo que envía el navegador (ids de usuario, documento) no se usa
    $requests_enabled = function_exists('squuad_cert_signature_requests_enabled') && squuad_cert_signature_requests_enabled();
    if ($requests_enabled && !empty($_POST['request_id'])) {
        squuad_cert_signature_handle_request_submission(absint($_POST['request_id']));
    }
    $table_student_documents = $wpdb->prefix . 'student_documents';
    $table_users_signatures = $wpdb->prefix . 'users_signatures';
    $student_user_id = absint($_POST['student_user_id'] ?? 0);
    $partner_user_id = absint($_POST['partner_user_id'] ?? 0);
    $document_id = is_string($_POST['document_id'] ?? null) ? sanitize_text_field(wp_unslash($_POST['document_id'])) : 'ENROLLMENT';
    $grade_selected = is_string($_POST['grade_selected'] ?? null) ? sanitize_text_field(wp_unslash($_POST['grade_selected'])) : null;
    $signature_student = squuad_cert_signature_from_request('signature_student');
    $signature_parent = squuad_cert_signature_from_request('signature_parent');
    $file = !empty($_FILES['document']) ? $_FILES['document'] : null;

    // Solo el estudiante o su representante, y solo sobre un documento que le corresponde
    $student = squuad_cert_signature_student($student_user_id, $partner_user_id);
    if (!$student || '' === $document_id || !squuad_cert_signature_document_is_valid($student, $document_id)) {
        wp_send_json_error(__('You are not allowed to sign this document.', 'edusystem'), 403);
    }

    // Con solicitudes, los documentos automáticos ya no se firman por esta vía: página abierta antes de actualizar
    // o JS en caché (se comprueba después del permiso, para no dar pistas a quien no firma este documento)
    if ($requests_enabled && squuad_cert_get_automatic_document_by_identificator($document_id)) {
        if (function_exists('edusystem_set_log')) {
            edusystem_set_log(sprintf('Firma sin solicitud rechazada (%s): la página era anterior a la actualización', $document_id), 'signature_blocked');
        }
        wp_send_json_error(__('The document was updated. Please reload the page.', 'edusystem'), 409);
    }

    // Cada usuario firma solo su parte, desde su propia cuenta (ADR 0001): el estudiante su recuadro y el
    // representante el suyo. Si el estudiante es su propio representante, los dos ids coinciden.
    $current_user_id = get_current_user_id();
    if (($signature_student && $current_user_id !== $student_user_id) || ($signature_parent && $current_user_id !== $partner_user_id)) {
        wp_send_json_error(__('You can only sign your own part of the document.', 'edusystem'), 403);
    }

    // El PDF se comprueba por su contenido (el tipo que declara el navegador no es fiable)
    if ($file) {
        $is_pdf = is_array($file)
            && UPLOAD_ERR_OK === ($file['error'] ?? UPLOAD_ERR_NO_FILE)
            && is_uploaded_file($file['tmp_name'])
            && '%PDF-' === file_get_contents($file['tmp_name'], false, null, 0, 5);
        if (!$is_pdf) {
            wp_send_json_error('Invalid file type');
        }
    }

    // Campos adicionales del documento: si solo firma uno (sin PDF) las respuestas se guardan con su firma
    // para que el segundo firmante vea el mismo documento. Se validan otra vez con la definición del documento.
    $document_fields_json = null;
    if (!$file && squuad_cert_signatures_store_document_fields()) {
        $automatic_document = squuad_cert_get_automatic_document_by_identificator($document_id);
        $fields = $automatic_document ? squuad_cert_get_document_fields($automatic_document) : [];
        if ($fields) {
            $input = is_string($_POST['document_fields'] ?? null) ? json_decode(wp_unslash($_POST['document_fields']), true) : null;
            [$field_values, $field_errors] = squuad_cert_document_fields_values($fields, $input);
            if ($field_errors) {
                wp_send_json_error(implode(' ', $field_errors));
            }
            $document_fields_json = wp_json_encode($field_values);
        }
    }

    // Firmas del estudiante y del representante (una sola por usuario y documento)
    $signatures = [
        [$student_user_id, $signature_student],
        [$partner_user_id, $signature_parent],
    ];
    foreach ($signatures as [$user_id, $signature]) {
        if (!$user_id || !$signature) {
            continue;
        }
        $existing_row = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table_users_signatures} WHERE user_id = %d AND document_id = %s",
            $user_id,
            $document_id
        ));
        if ($existing_row) {
            continue;
        }
        $data = [
            'user_id' => $user_id,
            'signature' => wp_json_encode($signature),
            'document_id' => $document_id,
            'grade_selected' => $grade_selected,
        ];
        if (null !== $document_fields_json) {
            $data['document_fields'] = $document_fields_json;
        }
        // Con la evidencia de firma (ADR 0001) si el esquema ya está en v4; si no, se guarda como antes
        if (function_exists('squuad_cert_signature_insert')) {
            squuad_cert_signature_insert($data, (int) $student->id, $user_id === $student_user_id ? 'student' : 'parent');
        } else {
            $wpdb->insert($table_users_signatures, $data);
        }
    }

    // Documento completo (PDF firmado): solo cuando ya están guardadas las firmas de todos los firmantes
    $attach_id = null;
    if ($file) {
        $signers = array_values(array_unique(array_filter([$student_user_id, $partner_user_id])));
        $signed = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT user_id) FROM {$table_users_signatures} WHERE document_id = %s AND user_id IN (" . implode(',', array_fill(0, count($signers), '%d')) . ")",
            array_merge([$document_id], $signers)
        ));
        if ($signed < count($signers)) {
            wp_send_json_error(__('The document can only be completed when everyone has signed from their own account.', 'edusystem'));
        }

        $filename = sanitize_file_name(preg_replace('/\.pdf$/i', '', (string) $file['name'])) . '.pdf';
        $upload = wp_upload_bits($filename, null, file_get_contents($file['tmp_name']));
        if (!empty($upload['error'])) {
            wp_send_json_error('Failed to upload file');
        }

        $attachment = array(
            'post_mime_type' => $upload['type'],
            'post_title' => $filename,
            'post_content' => '',
            'post_status' => 'inherit'
        );

        $attach_id = wp_insert_attachment($attachment, $upload['file']);
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attach_data = wp_generate_attachment_metadata($attach_id, $upload['file']);
        wp_update_attachment_metadata($attach_id, $attach_data);

        $document_row = ['status' => 1, 'attachment_id' => $attach_id, 'upload_at' => date('Y-m-d H:i:s')];
        $has_row = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table_student_documents} WHERE student_id = %d AND document_id = %s LIMIT 1",
            $student->id,
            $document_id
        ));
        if ($has_row) {
            $wpdb->update($table_student_documents, $document_row, ['student_id' => $student->id, 'document_id' => $document_id]);
        } elseif ($automatic_document = squuad_cert_get_automatic_document_by_identificator($document_id)) {
            // wp-certificates solo crea la fila del documento automático para los estudiantes que existían al
            // guardarlo en el admin: a los registrados después se les crea aquí, con sus mismos valores
            $wpdb->insert($table_student_documents, $document_row + [
                'student_id' => $student->id,
                'document_id' => $document_id,
                'is_required' => (int) $automatic_document->is_required,
                'is_visible' => (int) $automatic_document->is_visible,
                'created_at' => current_time('mysql'),
            ]);
        }

        // Documento completo: las respuestas guardadas con la firma parcial ya están en el PDF
        if (squuad_cert_signatures_store_document_fields()) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table_users_signatures} SET document_fields = NULL WHERE document_id = %s AND user_id IN (%d, %d)",
                $document_id,
                $student_user_id,
                $partner_user_id
            ));
        }
    }

    wp_send_json_success(['media_id' => $attach_id]);
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
    if (!$request || '' === $role) {
        if (function_exists('edusystem_set_log')) {
            edusystem_set_log(sprintf('Firma rechazada: el usuario %d no firma la solicitud %d', $user_id, $request_id), 'signature_blocked');
        }
        wp_send_json_error(__('You are not allowed to sign this document.', 'edusystem'), 403);
    }
    if (!in_array($request->status, SQUUAD_CERT_SIGNATURE_REQUEST_OPEN, true)) {
        wp_send_json_error(__('This document no longer accepts signatures. Please reload the page.', 'edusystem'), 409);
    }
    if (function_exists('squuad_cert_signature_request_slot_open') && !squuad_cert_signature_request_slot_open($request, $role)) {
        wp_send_json_error(__('This document is still waiting for previous signatures.', 'edusystem'), 409);
    }

    $shown_sha256 = is_string($_POST['content_sha256'] ?? null) ? strtolower(sanitize_text_field(wp_unslash($_POST['content_sha256']))) : '';
    $signature_student = squuad_cert_signature_from_request('signature_student');
    $signature_parent = squuad_cert_signature_from_request('signature_parent');
    if (('student' === $role && $signature_parent) || ('parent' === $role && $signature_student)) {
        wp_send_json_error(__('You can only sign your own part of the document.', 'edusystem'), 403);
    }
    $signature = 'student' === $role ? $signature_student : $signature_parent;
    $file = !empty($_FILES['document']) ? $_FILES['document'] : null;
    $signed_roles = squuad_cert_signature_request_signed_roles((int) $request->id);

    // 1) Firma del usuario (la primera congela el contenido)
    if ($signature) {
        if (in_array($role, $signed_roles, true)) {
            wp_send_json_error(__('You already signed this document.', 'edusystem'), 409);
        }
        // Consentimiento explícito (ADR 0002, punto 7): sin la casilla no hay firma, también con la firma automática
        $consent = squuad_cert_signature_consent_evidence(
            is_string($_POST['consent_version'] ?? null) ? sanitize_text_field(wp_unslash($_POST['consent_version'])) : ''
        );
        if (null === $consent) {
            wp_send_json_error(__('To sign, you must accept signing the document electronically.', 'edusystem'), 400);
        }
        if (!squuad_cert_signature_request_freeze((int) $request->id, $shown_sha256, $user_id)) {
            wp_send_json_error(__('The document was updated while you had it open. Please reload the page and review it again before signing.', 'edusystem'), 409);
        }
        $request = squuad_cert_signature_request_get((int) $request->id);
        $signature_json = wp_json_encode($signature);
        $signature_id = squuad_cert_signature_insert([
            'user_id' => $user_id,
            'signature' => $signature_json,
            'document_id' => $request->document_id,
        ], (int) $request->student_id, $role, [
            'request_id' => (int) $request->id,
            'student_document_id' => (int) $request->student_document_id,
            'round' => (int) $request->round,
            'content_sha256' => (string) $request->content_sha256,
            'request_created_fingerprint' => squuad_cert_signature_request_created_fingerprint((int) $request->id),
            'template_version_sha256' => (string) $request->template_version_sha256,
            'signature_method' => '["automatic"]' === $signature_json ? 'automatic' : 'drawn',
        ] + $consent);
        if (!$signature_id) {
            wp_send_json_error(__('Your signature could not be saved. Please reload the page and try again.', 'edusystem'), 409);
        }
        squuad_cert_signature_request_log_event((int) $request->id, 'signed', ['role' => $role, 'signature_id' => $signature_id] + $consent);

        $signed_roles = squuad_cert_signature_request_signed_roles((int) $request->id);
        $all_signed = !array_diff(squuad_cert_signature_request_required_roles($request), $signed_roles);
        squuad_cert_signature_request_transition((int) $request->id, ['open', 'partially_signed'], $all_signed ? 'signed' : 'partially_signed');
        // Estudiante y representante terminaron: avisar a los firmantes institucionales (ADR 0003, paso 10)
        if (function_exists('squuad_cert_signer_notify_open_slots')) {
            squuad_cert_signer_notify_open_slots((int) $request->id);
        }
        $request = squuad_cert_signature_request_get((int) $request->id);
    }

    // 2) PDF final: una sola vez, con todas las firmas y sobre el contenido congelado
    $attach_id = null;
    // Faltan firmas institucionales (fase 2): el PDF lo generará el último firmante; la firma de este usuario ya quedó
    if ($file && 'signed' !== $request->status && function_exists('squuad_cert_signature_request_institutional_pending')
        && squuad_cert_signature_request_institutional_pending($request)) {
        $file = null;
    }
    if ($file) {
        if ('signed' !== $request->status) {
            wp_send_json_error(__('The document can only be completed when everyone has signed from their own account.', 'edusystem'), 409);
        }
        if (!hash_equals((string) $request->content_sha256, $shown_sha256)) {
            wp_send_json_error(__('The document was updated while you had it open. Please reload the page and review it again before signing.', 'edusystem'), 409);
        }
        $is_pdf = is_array($file)
            && UPLOAD_ERR_OK === ($file['error'] ?? UPLOAD_ERR_NO_FILE)
            && is_uploaded_file($file['tmp_name'])
            && '%PDF-' === file_get_contents($file['tmp_name'], false, null, 0, 5);
        if (!$is_pdf) {
            wp_send_json_error('Invalid file type');
        }
        $pdf = (string) file_get_contents($file['tmp_name']);
        $filename = sanitize_file_name(preg_replace('/\.pdf$/i', '', (string) $file['name'])) . '.pdf';
        $upload = wp_upload_bits($filename, null, $pdf);
        if (!empty($upload['error'])) {
            wp_send_json_error('Failed to upload file');
        }
        $attach_id = wp_insert_attachment([
            'post_mime_type' => $upload['type'],
            'post_title' => $filename,
            'post_content' => '',
            'post_status' => 'inherit',
        ], $upload['file']);
        require_once ABSPATH . 'wp-admin/includes/image.php';
        wp_update_attachment_metadata($attach_id, wp_generate_attachment_metadata($attach_id, $upload['file']));

        // Fila del documento del estudiante: la de la solicitud, la existente o una nueva (documento automático)
        $student_document_id = (int) $request->student_document_id
            ?: (int) squuad_cert_signature_student_document_id((int) $request->student_id, (string) $request->document_id);
        $document_row = ['status' => 1, 'attachment_id' => $attach_id, 'upload_at' => date('Y-m-d H:i:s')];

        // Solo una finalización: si otra petición ya la completó, este PDF se descarta
        $completed = squuad_cert_signature_request_transition((int) $request->id, ['signed'], 'completed', [
            'completed_at_utc' => gmdate('Y-m-d H:i:s'),
            'final_attachment_id' => $attach_id,
            'final_pdf_sha256' => hash('sha256', $pdf),
            'final_uploaded_by' => $user_id,
        ]);
        if (!$completed) {
            wp_delete_attachment($attach_id, true);
            wp_send_json_error(__('This document was already completed. Please reload the page.', 'edusystem'), 409);
        }

        if ($student_document_id) {
            $wpdb->update($wpdb->prefix . 'student_documents', $document_row, ['id' => $student_document_id]);
        } elseif ($automatic_document = squuad_cert_get_automatic_document_by_identificator((string) $request->document_id)) {
            $wpdb->insert($wpdb->prefix . 'student_documents', $document_row + [
                'student_id' => (int) $request->student_id,
                'document_id' => (string) $request->document_id,
                'is_required' => (int) $automatic_document->is_required,
                'is_visible' => (int) $automatic_document->is_visible,
                'created_at' => current_time('mysql'),
            ]);
            $student_document_id = (int) $wpdb->insert_id;
        }
        if ($student_document_id && !(int) $request->student_document_id) {
            $wpdb->update($wpdb->prefix . 'squuad_cert_requests', ['student_document_id' => $student_document_id], ['id' => (int) $request->id]);
        }
    }

    wp_send_json_success(['media_id' => $attach_id]);
}
