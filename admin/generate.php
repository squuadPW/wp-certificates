<?php
declare(strict_types=1);

/**
 * «Generar» un documento desde la ficha del estudiante (Admisión de EduSystem). ADR 0004 de EduSystem: todo documento
 * configurado en Certificación > Documentos lo genera wp-certificates; EduSystem solo muestra el botón.
 *
 * - squuad_cert_render_generate_modal(): las ventanas (firma heredada y vista del documento con descarga en PDF).
 * - El script admin/assets/js/generate-document.js, solo en la ficha del estudiante.
 * - AJAX generate_document: devuelve el documento rellenado. Movido desde edusystem/admin/admission/ajax.php; el titular
 *   (estudiante) y sus permisos los da el proveedor de EduSystem, y los valores los resuelve wp-certificates.
 */

defined('ABSPATH') || exit;

const SQUUAD_CERT_GENERATE_SUBJECT = 'edusystem_student';

/** Ventanas de «Generar» en la ficha del estudiante. */
function squuad_cert_render_generate_modal(int $student_id): void
{
    $users_signatures_certificates = function_exists('get_users_signatures_certificates') ? get_users_signatures_certificates() : [];
    include WP_C_PATH . 'admin/templates/generate-modal.php';
}

add_action('admin_enqueue_scripts', 'squuad_cert_generate_scripts');
function squuad_cert_generate_scripts(): void
{
    if (($_GET['page'] ?? '') !== 'add_admin_form_admission_content' || ($_GET['section_tab'] ?? '') !== 'student_details') {
        return;
    }
    wp_enqueue_script('squuad-cert-generate', plugins_url('wp-certificates') . '/admin/assets/js/generate-document.js', [], WP_C_VERSION, true);
    wp_localize_script('squuad-cert-generate', 'squuadCertGenerate', [
        'url' => admin_url('admin-ajax.php'),
        'action' => 'generate_document',
        'nonce' => wp_create_nonce('squuad_cert_generate'),
    ]);
}

add_action('wp_ajax_generate_document', 'squuad_cert_generate_document');
function squuad_cert_generate_document(): void
{
    check_ajax_referer('squuad_cert_generate');

    $student_id = absint($_POST['student_id'] ?? 0);
    $document_certificate_id = absint($_POST['document_certificate_id'] ?? 0);
    $user_signature_id = absint($_POST['user_signature_id'] ?? 0);

    // El titular lo aporta el proveedor de EduSystem: sin él no hay estudiantes
    $provider = squuad_cert_subject_type(SQUUAD_CERT_GENERATE_SUBJECT);
    if (!$provider || empty($provider['can_act']) || empty($provider['book_line_data'])) {
        wp_send_json_error(__('Students are not available (EduSystem is not active).', 'wp-certificates'), 503);
    }
    // Mismo permiso que antes: administrador con permiso de Admisión (edición o lectura)
    if (!call_user_func($provider['can_act'], get_current_user_id(), $student_id, 'generate')) {
        wp_send_json_error(['message' => __('You do not have permission to manage admissions.', 'edusystem')], 403);
    }
    $subject = (array) call_user_func($provider['book_line_data'], $student_id, null);
    $student = $subject['student'] ?? null;
    $document = get_document_detail($document_certificate_id);
    if (!$student || !$document) {
        wp_send_json_error(__('The student or the document does not exist.', 'edusystem'), 404);
    }
    $emission_date = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d');

    // Nadie firma por otro (ADR 0003 de EduSystem, paso 10): un documento que exige firma no se genera con firmas-imagen
    if ($document->signature_required && wpc_third_party_signatures_blocked()) {
        squuad_cert_log(sprintf('Generar con firma-imagen bloqueado: documento %d, estudiante %d, usuario %d', (int) $document->id, (int) $student->id, get_current_user_id()), 'signature_blocked');
        wp_send_json_error(__('This document requires signatures: it can no longer be generated with signature images. Configure its signers (Certification > Documents > Document signers) and issue it for signature; each responsible person signs from their own account.', 'edusystem'), 409);
    }
    // Firma-imagen heredada, leída con SQL preparado (ADR 0003 de EduSystem, paso 0)
    $signature = function_exists('edusystem_legacy_institutional_signature') ? edusystem_legacy_institutional_signature($user_signature_id) : null;
    if ($document->signature_required && !$signature) {
        wp_send_json_error(__('Select a valid signature for this document.', 'edusystem'), 400);
    }
    $user_signature = $signature ? get_user_by('id', (int) $signature->user_id) : null;

    // Valores de las variables: los resuelve wp-certificates (ADR 0005 de EduSystem)
    $replacements = squuad_cert_template_replacements($document->header . $document->content . $document->footer, (int) $student->id, ['document' => $document, 'certificate_id' => $document->id])['replacements'];
    // Campos adicionales del documento: aquí no hay respuestas; así no queda el texto literal {{clave}}
    if (function_exists('edusystem_document_fields_empty_replacements')) {
        $replacements = array_merge(edusystem_document_fields_empty_replacements($document), $replacements);
    }

    if ($document->signature_required && $signature) {
        // Cada uso de una firma heredada queda registrado: qué firma, quién la eligió, estudiante y documento
        wpc_log_signature_action(sprintf(
            'Firma institucional heredada %d (usuario %d) usada por el usuario %d en el documento %d del estudiante %d',
            (int) $signature->id,
            (int) $signature->user_id,
            get_current_user_id(),
            (int) $document->id,
            (int) $student->id
        ));
        $user_sign = $user_signature ? $user_signature->first_name . ' ' . $user_signature->last_name : '';
        $replacements['user_sign'] = ['value' => $user_sign, 'wrap' => true];
        $replacements['position_user_charge'] = ['value' => $signature->charge, 'wrap' => true];
        $replacements['signature'] = [
            'value' => '<img style="width: auto !important; height: 100px !important;" src="' . wp_get_attachment_url($signature->attach_id) . '"/>',
            'wrap' => false,
        ];
    }

    $create_certificate_qr = false !== strpos((string) $document->content, '{{qrcode}}')
        || false !== strpos((string) $document->header, '{{qrcode}}')
        || false !== strpos((string) $document->footer, '{{qrcode}}');

    $document->content = process_template($document->content, $replacements);
    $document->header = process_template($document->header, $replacements);
    $document->footer = process_template($document->footer, $replacements);

    $url = ['url' => '', 'image_url' => ''];
    if ($create_certificate_qr) {
        $url = apply_filters('create_certificate_edusystem', 'certificate', $document->title, (string) ($subject['program'] ?? ''), 1, $student, $emission_date);
    }
    if ('custom' === $document->paper_format) {
        $document->paper_format = [(float) $document->width_size, (float) $document->height_size];
    }

    wp_send_json(['url' => $url['url'], 'image_url' => $url['image_url'], 'html' => $document->content, 'header' => $document->header, 'footer' => $document->footer, 'document' => $document]);
}
