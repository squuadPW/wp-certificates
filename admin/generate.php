<?php
declare(strict_types=1);

/**
 * «Generar» un documento desde la ficha del estudiante (Admisión de EduSystem). ADR 0004 de EduSystem: todo documento
 * configurado en Certificación > Documentos lo genera wp-certificates; EduSystem solo muestra el botón.
 *
 * - squuad_cert_render_generate_modal(): la ventana con la vista del documento y la descarga en PDF.
 * - El script admin/assets/js/generate-document.js, solo en la ficha del estudiante.
 * - AJAX generate_document: devuelve el documento rellenado. Movido desde edusystem/admin/admission/ajax.php; el titular
 *   (estudiante) y sus permisos los da el proveedor de EduSystem, y los valores los resuelve wp-certificates.
 */

defined('ABSPATH') || exit;

const SQUUAD_CERT_GENERATE_SUBJECT = 'edusystem_student';

/** Ventanas de «Generar» en la ficha del estudiante. */
function squuad_cert_render_generate_modal(int $student_id): void
{
    include WP_C_PATH . 'admin/templates/generate-modal.php';
}

add_action('admin_enqueue_scripts', 'squuad_cert_generate_scripts');
function squuad_cert_generate_scripts(): void
{
    if (($_GET['page'] ?? '') !== 'add_admin_form_admission_content' || ($_GET['section_tab'] ?? '') !== 'student_details') {
        return;
    }
    // -pdf-1: descarga por el servidor de PDF y QR propio (ADR 0013, fase 3)
    wp_enqueue_script('squuad-cert-generate', plugins_url('wp-certificates') . '/admin/assets/js/generate-document.js', [], WP_C_VERSION . '-pdf-1', true);
    wp_localize_script('squuad-cert-generate', 'squuadCertGenerate', [
        'url' => admin_url('admin-ajax.php'),
        'action' => 'generate_document',
        'nonce' => wp_create_nonce('squuad_cert_generate'),
        // Servidor de PDF (ADR 0013, fase 3): la descarga la hace el servidor si el documento lo usa
        'serverAction' => 'squuad_cert_generate_server_pdf',
        'serverNonce' => wp_create_nonce('squuad_cert_generate_server_pdf'),
        'i18n' => ['failed' => __('The document could not be generated.', 'wp-certificates')],
    ]);
}

add_action('wp_ajax_generate_document', 'squuad_cert_generate_document');
function squuad_cert_generate_document(): void
{
    check_ajax_referer('squuad_cert_generate');
    // Límite por usuario (cada «Generar» registra un certificado y, con el servidor de PDF, prepara su descarga)
    $rate_key = 'squuad_cert_gen_doc_rate_' . get_current_user_id();
    $rate = (int) get_transient($rate_key);
    if ($rate >= 30) {
        wp_send_json_error(__('Too many previews in a short time. Wait a minute.', 'wp-certificates'), 429);
    }
    set_transient($rate_key, $rate + 1, MINUTE_IN_SECONDS);

    $student_id = absint($_POST['student_id'] ?? 0);
    $document_certificate_id = absint($_POST['document_certificate_id'] ?? 0);

    // El titular lo aporta el proveedor de EduSystem: sin él no hay estudiantes
    $provider = squuad_cert_subject_type(SQUUAD_CERT_GENERATE_SUBJECT);
    if (!$provider || empty($provider['can_act']) || empty($provider['book_line_data'])) {
        wp_send_json_error(__('This action requires EduSystem.', 'wp-certificates'), 503);
    }
    // Mismo permiso que antes: administrador con permiso de Admisión (edición o lectura)
    if (!call_user_func($provider['can_act'], get_current_user_id(), $student_id, 'generate')) {
        wp_send_json_error(['message' => __('You do not have permission to manage admissions.', 'wp-certificates')], 403);
    }
    $subject = (array) call_user_func($provider['book_line_data'], $student_id, null);
    $student = $subject['student'] ?? null;
    $document = get_document_detail($document_certificate_id);
    if (!$student || !$document) {
        wp_send_json_error(__('The person or the document does not exist.', 'wp-certificates'), 404);
    }
    $emission_date = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d');

    // Nadie firma por otro (ADR 0003 de EduSystem, paso 10): un documento que exige firma no se genera aquí, se emite
    // para firma. Siempre, sin depender de otra comprobación: las firmas-imagen se retiraron (ADR 0004)
    if ($document->signature_required) {
        squuad_cert_log(sprintf('Generar con firma-imagen bloqueado: documento %d, estudiante %d, usuario %d', (int) $document->id, (int) $student->id, get_current_user_id()), 'signature_blocked');
        wp_send_json_error(__('This document requires signatures: it can no longer be generated with signature images. Configure its signers (Certification > Documents > Document signers) and issue it for signature; each responsible person signs from their own account.', 'wp-certificates'), 409);
    }

    // Valores de las variables: los resuelve wp-certificates (ADR 0005 de EduSystem)
    $replacements = squuad_cert_template_replacements($document->header . $document->content . $document->footer, (int) $student->id, ['document' => $document, 'certificate_id' => $document->id])['replacements'];
    // Campos adicionales del documento: aquí no hay respuestas; así no queda el texto literal {{clave}}
    if (function_exists('squuad_cert_document_fields_empty_replacements')) {
        $replacements = array_merge(squuad_cert_document_fields_empty_replacements($document), $replacements);
    }

    $create_certificate_qr = false !== strpos((string) $document->content, '{{qrcode}}')
        || false !== strpos((string) $document->header, '{{qrcode}}')
        || false !== strpos((string) $document->footer, '{{qrcode}}');

    // Motor propio de wp-certificates (mismo comportamiento que el de EduSystem)
    $document->content = squuad_cert_process_template($document->content, $replacements);
    $document->header = squuad_cert_process_template($document->header, $replacements);
    $document->footer = squuad_cert_process_template($document->footer, $replacements);

    $url = ['url' => '', 'image_url' => ''];
    if ($create_certificate_qr) {
        $url = apply_filters('create_certificate_edusystem', 'certificate', $document->title, (string) ($subject['program'] ?? ''), 1, $student, $emission_date);
        // Sin el filtro (sin EduSystem) llega la cadena 'certificate': sin QR
        $url = is_array($url) ? $url + ['url' => '', 'image_url' => ''] : ['url' => '', 'image_url' => ''];
    }
    // QR como imagen (generador propio, con el logo de Configuración en el centro): sin qr-code-styling de internet
    $document->content = squuad_cert_pdf_qr_inline($document->content, (string) $url['url'], (string) $url['image_url']);
    $document->header = squuad_cert_pdf_qr_inline($document->header, (string) $url['url'], (string) $url['image_url']);
    $document->footer = squuad_cert_pdf_qr_inline($document->footer, (string) $url['url'], (string) $url['image_url']);
    if ('custom' === $document->paper_format) {
        $document->paper_format = [(float) $document->width_size, (float) $document->height_size];
    }

    // Servidor de PDF (ADR 0013, fase 3): el PDF se prepara aquí con el documento ya rellenado (el certificado ya se
    // registró arriba: no se emite dos veces) y se guarda unos minutos, ligado a este usuario, para la descarga
    $server = null;
    if ('servicio' === squuad_cert_pdf_engine_for_document($document)) {
        $token = wp_generate_password(32, false, false);
        // Solo el documento ya rellenado (sin imágenes incrustadas: el PDF se prepara al descargar)
        set_transient('squuad_cert_gen_' . $token, [
            'user_id' => get_current_user_id(),
            'student_id' => (int) $student->id,
            'document' => array_intersect_key((array) $document, array_flip(['id', 'title', 'header', 'content', 'footer', 'unit', 'orientation', 'paper_format', 'width_size', 'height_size'])),
        ], 10 * MINUTE_IN_SECONDS);
        $server = ['token' => $token];
    }

    wp_send_json(['url' => $url['url'], 'image_url' => $url['image_url'], 'html' => $document->content, 'header' => $document->header, 'footer' => $document->footer, 'document' => $document, 'server' => $server]);
}

/**
 * Petición para el servidor con el mismo PDF que hoy hace «Descargar» en el navegador (generate-document.js): contenido
 * con el alto y el ancho de la página; encabezado y pie en cada página solo en vertical (con la regla R2 del servidor
 * nunca tapan el contenido).
 */
function squuad_cert_generate_server_payload(object $document): array
{
    $unit = strtolower((string) ($document->unit ?: 'mm'));
    $orientation = 'landscape' === strtolower((string) $document->orientation) ? 'landscape' : 'portrait';
    $page = squuad_cert_pdf_page(['unit' => $unit, 'format' => $document->paper_format, 'orientation' => $orientation], 0);
    $width = (float) $document->width_size > 0 ? $document->width_size . $unit : $page['width'] . $page['unit'];
    $height = (float) $document->height_size > 0 ? $document->height_size . $unit : $page['height'] . $page['unit'];
    $content = '<div id="content-pdf" style="padding:0;margin:0;background:#fff;min-width:' . esc_attr($width) . ';min-height:' . esc_attr($height) . '">' . $document->content . '</div>';
    $portrait = 'portrait' === $orientation;

    return squuad_cert_pdf_payload($content, $page, $portrait ? (string) $document->header : '', $portrait ? (string) $document->footer : '');
}

/** Descarga de «Generar» hecha por el servidor (con el token del paso anterior, solo para quien lo pidió). */
add_action('wp_ajax_squuad_cert_generate_server_pdf', 'squuad_cert_generate_server_pdf');
function squuad_cert_generate_server_pdf(): void
{
    check_ajax_referer('squuad_cert_generate_server_pdf');
    $token = preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['token'] ?? ''));
    $job = '' !== $token ? get_transient('squuad_cert_gen_' . $token) : false;
    if (!is_array($job) || (int) ($job['user_id'] ?? 0) !== get_current_user_id()) {
        wp_send_json_error(['message' => __('The document could not be generated.', 'wp-certificates')], 403);
    }
    // El permiso se vuelve a comprobar al descargar (pudo retirarse en estos minutos)
    $provider = squuad_cert_subject_type(SQUUAD_CERT_GENERATE_SUBJECT);
    if (!$provider || empty($provider['can_act']) || !call_user_func($provider['can_act'], get_current_user_id(), (int) $job['student_id'], 'generate')) {
        wp_send_json_error(['message' => __('You do not have permission to manage admissions.', 'wp-certificates')], 403);
    }
    $count = (int) get_transient('squuad_cert_gen_rate_' . get_current_user_id());
    if ($count >= 30) {
        wp_send_json_error(['message' => __('Too many previews in a short time. Wait a minute.', 'wp-certificates')], 429);
    }
    set_transient('squuad_cert_gen_rate_' . get_current_user_id(), $count + 1, MINUTE_IN_SECONDS);
    $document = (object) $job['document'];
    $result = squuad_cert_pdf_render(squuad_cert_generate_server_payload($document), '«Generar» del documento ' . (int) $document->id);
    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()], 502);
    }
    delete_transient('squuad_cert_gen_' . $token); // de un solo uso: para otra copia, «Generar» de nuevo
    squuad_cert_pdf_send_inline($result['pdf'], (sanitize_file_name(strtolower((string) $document->title)) ?: 'document') . '.pdf', 'attachment');
}
