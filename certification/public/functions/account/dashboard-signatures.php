<?php
/**
 * EduSystem - Certificación: modal del documento por firmar y sección "Documentos por firmar" del escritorio de Mi
 * Cuenta (squuad_cert_modal_document_automatic, squuad_cert_signature_account_documents_to_sign). Movido desde
 * public/functions/account/dashboard.php (ADR 0004, paso 3c). Los modales antiguos de la carta de documentos
 * faltantes y de inscripción (plantillas PHP create-missing-documents.php y create-enrollment.php) no pasan a
 * wp-certificates: la carta es un documento automático del catálogo que crea cada sitio en Documentos (con la
 * variable {{missing_documents}} de EduSystem); la conversión automática con plantilla fija se retiró (2026-10-04).
 */

if (!defined('ABSPATH')) exit;

function squuad_cert_modal_document_automatic()
{
    global $current_user;

    // Cada cuenta firma o rellena sus propios documentos, según sus roles (firma por roles, paso 3d). Nada más activa ni
    // bloquea los automáticos: ver squuad_cert_signature_user_documents()
    if (!is_user_logged_in()) {
        return;
    }

    // En la confirmación del lote o la generación del PDF final no se abre el documento pendiente encima
    if (!empty($_GET['squuad_cert_batch']) || !empty($_GET['squuad_cert_pdf'])) {
        return;
    }

    // Documento pendiente del usuario, con su solicitud (ADR 0002), por prioridad. Sin solicitudes no hay nada que hacer.
    $pending = squuad_cert_signature_requests_enabled()
        ? squuad_cert_signature_pending_for_user(
            $current_user,
            // Documento elegido en "Documentos por firmar" de Mi Cuenta (si no está pendiente, se abre el primero)
            isset($_GET['squuad_cert_sign']) ? sanitize_text_field(wp_unslash($_GET['squuad_cert_sign'])) : ''
        )
        : null;
    if (!$pending) {
        return;
    }
    // El documento es de la cuenta conectada; su ficha de EduSystem (si la tiene) solo aporta las variables
    $subject_id = (int) $pending['subject_id'];
    $student_id = $subject_id; // la plantilla del modal lo envía como student_user_id (cuenta que firma)
    $variables_subject = (int) $pending['student_id'];
    $document = $pending['document'];

    // Con solicitud: si el contenido ya está congelado (alguien firmó), se muestra tal cual, sin volver a pedir los
    // campos adicionales ni regenerar nada (ADR 0002, puntos 2 y 11)
    $request = $pending['request'];
    if ($request && null !== $request->frozen_at_utc) {
        $content = squuad_cert_signature_request_content((int) $request->id);
        if (null === $content) {
            return; // contenido alterado: no se muestra para firmar (el verificador lo marca)
        }
        $html = squuad_cert_signature_render_content($content, $request);
        $document_fields = [];
        $field_values = [];
        $legacy_partial = !empty($pending['legacy_partial']) && 1 === (int) $request->round;
        include SQUUAD_CERT_MODULE_PATH . 'public/templates/create-document-automatic.php';
        return;
    }

    // Campos adicionales del documento: se piden antes de mostrarlo. Las respuestas llegan por POST a esta misma
    // página y solo se usan para rellenar el documento (con solicitud, viven en el contenido congelado).
    $field_replacements = [];
    $field_values = [];
    $document_fields = squuad_cert_get_document_fields($document);
    if ($document_fields) {
        $field_errors = [];
        $posted = is_string($_POST['squuad_cert_document_fields'] ?? null)
            && (int) $_POST['squuad_cert_document_fields'] === (int) $document->id;
        $submitted = $posted
            && is_string($_POST['_wpnonce'] ?? null)
            && wp_verify_nonce($_POST['_wpnonce'], 'edusystem_document_fields_' . $document->id);

        if ($submitted) {
            [$field_values, $field_errors] = squuad_cert_document_fields_values($document_fields, wp_unslash($_POST['document_fields'] ?? []));
        } elseif ($posted) {
            // Nonce caducado (la página se envió horas después): se avisa y se conservan las respuestas
            [$field_values] = squuad_cert_document_fields_values($document_fields, wp_unslash($_POST['document_fields'] ?? []));
            $field_errors = ['expired' => __('Your session expired. Please check your answers and submit them again.', 'wp-certificates')];
        }
        if (!$submitted || $field_errors) {
            include SQUUAD_CERT_MODULE_PATH . 'public/templates/document-fields-form.php';
            return;
        }
        $field_replacements = squuad_cert_document_fields_replacements($document_fields, $field_values);
    }

    $html_parts = [];
    foreach (['header', 'content', 'footer'] as $part) {
        if (!empty($document->{$part})) {
            $html_parts[] = '<div class="automatic-document-' . $part . '">' . $document->{$part} . '</div>';
        }
    }
    $html = implode('', $html_parts);
    if ('' === $html) {
        return;
    }
    // Las variables del sistema ganan a un campo con la misma clave. Los valores los resuelve wp-certificates (ADR 0005).
    // La cuenta que recibe el documento es el titular: da {{full_name}}, {{email}}… solo si ningún plugin activo las da
    $replacements = array_merge($field_replacements, squuad_cert_template_replacements($html, $variables_subject, [
        'document' => $document,
        'holder_type' => SQUUAD_CERT_SUBJECT_ACCOUNT,
        'holder_id' => $subject_id,
    ])['replacements']);

    // Borrador de la solicitud (ADR 0002): datos escapados, marcadores fijos para las firmas y el QR, imágenes
    // incrustadas. Se regenera en cada apertura hasta la primera firma, que lo congela.
    $request = $request ?: squuad_cert_signature_request_get_or_create(
        $subject_id,
        (string) $document->document_identificator,
        ['holder_user_id' => $subject_id],
        squuad_cert_signature_external_ref($subject_id, (string) $document->document_identificator, (int) $document->id),
        squuad_cert_signature_doc_version_hash((string) $document->document_identificator),
        (int) $document->id,
        'opened'
    );
    if (!$request) {
        return;
    }
    // Variables de firma de cada firmante y reglas de la plantilla (estudiante y firmantes del sistema); los firmantes
    // del sistema que la plantilla no coloca van en un bloque "Firmas" al final
    $institutional = squuad_cert_signature_signer_replacements($request);
    $content = squuad_cert_process_template($html, array_merge(squuad_cert_signature_escape_replacements($replacements), $institutional));
    $content = squuad_cert_signature_strip_unused_signer_tags($content);
    $content = squuad_cert_signature_append_institutional_block($content, $request);
    $content = squuad_cert_signature_inline_images($content);
    if (null === squuad_cert_signature_request_save_draft((int) $request->id, $content)) {
        // Otra petición la congeló entre medias: se muestra el contenido congelado
        $request = squuad_cert_signature_request_get((int) $request->id);
        $content = $request ? squuad_cert_signature_request_content((int) $request->id) : null;
        if (null === $content) {
            return;
        }
    }
    $request = squuad_cert_signature_request_get((int) $request->id);
    $legacy_partial = !empty($pending['legacy_partial']) && 1 === (int) $request->round;
    if ($legacy_partial) {
        squuad_cert_signature_request_log_event_once((int) $request->id, 'legacy_partial_superseded');
    }
    $html = squuad_cert_signature_render_content($content, $request);
    $document_fields = [];

    include SQUUAD_CERT_MODULE_PATH . 'public/templates/create-document-automatic.php';
}

/**
 * Mi Cuenta: sección "Documentos por firmar" del escritorio (ADR 0003, paso 5b). Lista los documentos del estudiante:
 * los que le toca firmar, con un botón para abrir ese documento, y los que ya firmó y esperan a los firmantes del
 * sistema.
 */
add_action('woocommerce_account_dashboard', 'squuad_cert_signature_account_documents_to_sign', 1);
function squuad_cert_signature_account_documents_to_sign()
{
    if (!function_exists('squuad_cert_signature_user_documents') || !is_user_logged_in()) {
        return;
    }
    $user = wp_get_current_user();
    $dashboard = wc_get_account_endpoint_url('dashboard');
    if (function_exists('squuad_cert_signature_batch_print_notice')) {
        squuad_cert_signature_batch_print_notice();
    }

    // Firma en lote (ADR 0003, paso 6b): confirmación o resultado del lote, y PDF finales pendientes
    if (!empty($_GET['squuad_cert_batch']) && function_exists('squuad_cert_signature_batch_get')) {
        $batch = squuad_cert_signature_batch_get(absint($_GET['squuad_cert_batch']));
        if ($batch && 'holder' === ($batch->data['kind'] ?? '')) {
            $pdf_requests = 'finished' === $batch->status && !empty($batch->result_data['completed'])
                ? squuad_cert_signature_account_pdf_requests($user, array_map('intval', (array) $batch->result_data['completed']))
                : [];
            include SQUUAD_CERT_MODULE_PATH . 'public/templates/documents-to-sign-batch.php';
            return;
        }
    }
    if (!empty($_GET['squuad_cert_pdf']) && function_exists('squuad_cert_signature_account_pdf_requests')) {
        $pdf_requests = squuad_cert_signature_account_pdf_requests($user, [absint($_GET['squuad_cert_pdf'])]);
        if ($pdf_requests) {
            echo '<section class="edusystem-documents-to-sign" id="edusystem-documents-to-sign" style="margin-bottom:24px"><h3>' . esc_html__('Final PDF', 'wp-certificates') . '</h3>';
            include SQUUAD_CERT_MODULE_PATH . 'public/templates/signature-final-pdf.php';
            echo '</section>';
            return;
        }
    }

    $items = squuad_cert_signature_user_documents($user);
    if (!$items) {
        return;
    }
    $batchable = function_exists('squuad_cert_signature_batch_holder_candidates')
        ? array_map(static fn($row) => (int) $row->id, squuad_cert_signature_batch_holder_candidates($user))
        : [];
    include SQUUAD_CERT_MODULE_PATH . 'public/templates/documents-to-sign.php';
}
