<?php
declare(strict_types=1);

/**
 * PDF final de las solicitudes de firma (ADR 0013 de Edusof, fase 2).
 *
 * Un solo guardado para el PDF final, venga del navegador (subida de quien firmó el último, como hasta ahora) o del
 * servidor de PDF: adjunto privado con nombre aleatorio (ADR 0007, D3), transición `signed → completed` con la evidencia
 * sellada y, después de guardar, la acción `squuad_cert_request_completed` (EduSystem la escucha).
 */

defined('ABSPATH') || exit;

/**
 * Guarda el PDF final y completa la solicitud. Solo una finalización: si otra petición ya la completó, este PDF se
 * descarta y se devuelve WP_Error('ya_completada').
 *
 * @param array $seal campos que se sellan además de final_pdf_sha256 en el evento status_completed (pdf_engine,
 *                    render_input_sha256, versiones del servicio…)
 * @return int|WP_Error id del adjunto
 */
function squuad_cert_final_pdf_store(object $request, string $pdf, string $title, int $uploaded_by, array $seal = [])
{
    $request_id = (int) $request->id;
    // D3 (ADR 0007 de Edusof): nombre aleatorio no predecible y adjunto privado, fuera de la API de medios y de la
    // biblioteca (includes/signed-pdf.php). El título conserva el nombre del documento para quien lo administra
    $upload = wp_upload_bits(squuad_cert_signed_pdf_filename(), null, $pdf);
    if (!empty($upload['error'])) {
        return new WP_Error('subida', (string) $upload['error']);
    }
    $attach_id = (int) wp_insert_attachment([
        'post_mime_type' => $upload['type'],
        'post_title' => $title,
        'post_content' => '',
        'post_status' => 'private',
    ], $upload['file']);
    squuad_cert_signed_pdf_protect($attach_id, 'request:' . $request_id);
    require_once ABSPATH . 'wp-admin/includes/image.php';
    wp_update_attachment_metadata($attach_id, wp_generate_attachment_metadata($attach_id, $upload['file']));

    $sha = hash('sha256', $pdf);
    $completed = squuad_cert_signature_request_transition($request_id, ['signed'], 'completed', [
        'completed_at_utc' => gmdate('Y-m-d H:i:s'),
        'final_attachment_id' => $attach_id,
        'final_pdf_sha256' => $sha,
        'final_uploaded_by' => $uploaded_by,
    ], $seal);
    if (!$completed) {
        wp_delete_attachment($attach_id, true);
        return new WP_Error('ya_completada', __('This document was already completed. Please reload the page.', 'wp-certificates'));
    }

    delete_transient(squuad_cert_final_pdf_token_key($request_id));
    // Documento completo (todas las firmas y su PDF): se avisa. Si está enlazado a un requisito de EduSystem
    // (Admisión > Documentos > «Document template»), EduSystem lo pone en la ficha para que Admisión lo revise
    squuad_cert_log(sprintf('Solicitud %d completada: PDF final %d (%s) por el usuario %d', $request_id, $attach_id, (string) ($seal['pdf_engine'] ?? 'navegador'), $uploaded_by), 'signature');
    $fresh = squuad_cert_signature_request_get($request_id) ?? $request;
    do_action('squuad_cert_request_completed', squuad_cert_request_event_payload($fresh) + [
        'final_attachment_id' => $attach_id,
        'final_pdf_sha256' => $sha,
    ]);

    return $attach_id;
}

/** Motor de PDF del documento de una solicitud: 'servicio' o 'navegador' (includes/pdf-engine.php). */
function squuad_cert_final_pdf_engine(object $request): string
{
    global $wpdb;

    if (!function_exists('squuad_cert_pdf_engine_for_document')) {
        return 'navegador';
    }
    $document = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}documents_certificates WHERE id = %d", (int) ($request->document_certificate_id ?? 0)));

    return squuad_cert_pdf_engine_for_document($document ?: null);
}

/**
 * Petición para el servidor con el mismo PDF final que genera hoy el navegador (create-enrollment.js): contenido
 * congelado, firmas y certificado de firmas, envuelto igual y con la página de squuad_cert_signature_pdf_page().
 *
 * @return array{payload: array, title: string}|null
 */
function squuad_cert_final_pdf_server_payload(object $request): ?array
{
    $final = squuad_cert_signature_final_pdf_payload($request);
    if (!$final) {
        return null;
    }
    $margin = is_array($final['margin']) ? (float) ($final['margin'][0] ?? 0) : (float) $final['margin'];
    $extra_css = '';
    if (squuad_cert_final_pdf_is_issued($request)) {
        // Documento emitido (diseño de página completa, a menudo apaisado): el diseño ocupa su página sin márgenes ni
        // relleno y la línea de la solicitud y el «Certificado de firmas» van en hojas A4 verticales propias (páginas con
        // nombre de CSS), en lugar de colgar del diseño y partirse en hojas de su tamaño
        [$html, $extra_css] = squuad_cert_final_pdf_issued_layout(squuad_cert_final_pdf_parts($request));
    } else {
        $html = '<div style="width:100%;box-sizing:border-box;background:#fff;padding:16px;font-family:Arial,sans-serif;color:#111">' . $final['html'] . '</div>';
    }

    return [
        // Página en px de CSS (no la de jsPDF sin px_scaling): html2pdf estiraba el contenido hasta llenar una página 1,333
        // veces mayor; Chrome no estira, así que la página es la del diseño (1056 × 817 px = Carta) y el contenido la llena
        'payload' => squuad_cert_pdf_payload($html, squuad_cert_pdf_page($final['jspdf'], $margin), '', '', '', $extra_css),
        'title' => (string) $final['filename'],
    ];
}

/** Opción del sitio: ¿se permite el respaldo en el navegador tras 5 fallos del servidor? Apagada por defecto. */
const SQUUAD_CERT_PDF_FALLBACK_OPTION = 'squuad_cert_pdf_allow_browser_fallback';
const SQUUAD_CERT_PDF_MAX_ATTEMPTS = 5;
/** Presupuesto del intento en la misma petición de la firma (el resto, la cola). */
const SQUUAD_CERT_PDF_SYNC_TIMEOUT = 15;

/** Candado de MySQL por solicitud (sin esperar): generar o guardar el PDF final, de uno en uno. */
function squuad_cert_final_pdf_lock(int $request_id): bool
{
    global $wpdb;

    return '1' === (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', squuad_cert_final_pdf_lock_name($request_id)));
}

function squuad_cert_final_pdf_unlock(int $request_id): void
{
    global $wpdb;

    $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', squuad_cert_final_pdf_lock_name($request_id)));
}

/** Nombre del candado: único por sitio aunque varios compartan el servidor MySQL (máximo 64 caracteres). */
function squuad_cert_final_pdf_lock_name(int $request_id): string
{
    global $wpdb;

    return 'sqcpdf_' . substr(md5(DB_NAME . '|' . $wpdb->prefix), 0, 16) . '_' . $request_id;
}

/** Pone una solicitud en la cola del servidor (próximo intento: ya) si aún no estaba: nunca acorta una espera. */
function squuad_cert_final_pdf_enqueue(int $request_id): void
{
    global $wpdb;

    if (!squuad_cert_final_pdf_schema_ready()) {
        return;
    }
    $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}squuad_cert_requests SET pdf_next_at_utc = %s WHERE id = %d AND status = 'signed' AND (final_attachment_id IS NULL OR final_attachment_id = 0) AND pdf_next_at_utc IS NULL",
        gmdate('Y-m-d H:i:s'),
        $request_id
    ));
}

/** Autorización de respaldo vigente de una solicitud (o null). */
function squuad_cert_final_pdf_fallback(object $request): ?array
{
    $fb = get_transient('squuad_cert_pdf_fallback_' . (int) $request->id);

    return is_array($fb) && ($fb['until'] ?? 0) > time() && hash_equals((string) ($fb['content_sha256'] ?? ''), (string) $request->content_sha256) ? $fb : null;
}

/**
 * ¿Puede el navegador subir el PDF final? Con el motor «servicio», solo con una autorización de respaldo vigente (B2 de
 * la revisión: nunca «abierto por defecto»); con «navegador», como siempre (reserva pdf_token).
 */
function squuad_cert_final_pdf_browser_allowed(object $request): bool
{
    if ('servicio' !== squuad_cert_final_pdf_engine($request)) {
        return true;
    }
    $fb = squuad_cert_final_pdf_fallback($request);

    return null !== $fb && (int) ($fb['user_id'] ?? 0) === get_current_user_id();
}

/** Cuenta que dio la última firma (actor sellado en el evento status_signed). */
function squuad_cert_final_pdf_last_signer(int $request_id): int
{
    global $wpdb;

    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT actor_user_id FROM {$wpdb->prefix}squuad_cert_events WHERE request_id = %d AND event_type = 'status_signed' ORDER BY id DESC LIMIT 1",
        $request_id
    ));
}

/** Sello de un PDF final subido por el navegador: motor y, si fue un respaldo, el evento que lo autorizó. */
function squuad_cert_final_pdf_browser_seal(object $request): array
{
    $fb = 'servicio' === squuad_cert_final_pdf_engine($request) ? squuad_cert_final_pdf_fallback($request) : null;
    $seal = ['pdf_engine' => $fb ? 'browser_fallback' : 'navegador', 'origin' => 'navegador', 'actor' => get_current_user_id()];
    if ($fb) {
        $seal['fallback_event_id'] = (int) ($fb['event_id'] ?? 0);
    }

    return $seal;
}

/** ¿Es un documento emitido para firma (gestionado), no uno automático de Mi Cuenta? */
function squuad_cert_final_pdf_is_issued(object $request): bool
{
    global $wpdb;

    return 'managed' === (string) $wpdb->get_var($wpdb->prepare("SELECT type FROM {$wpdb->prefix}documents_certificates WHERE id = %d", (int) ($request->document_certificate_id ?? 0)));
}

/** Piezas del PDF final con los documentos de identidad completos (sin máscara, B3 del ADR 0007). */
function squuad_cert_final_pdf_parts(object $request): array
{
    $mask = function_exists('squuad_cert_id_document_mask_in_boxes') ? squuad_cert_id_document_mask_in_boxes() : false;
    if (function_exists('squuad_cert_id_document_mask_in_boxes')) {
        squuad_cert_id_document_mask_in_boxes(false);
    }
    $parts = squuad_cert_signature_final_pdf_parts($request) ?? ['content' => '', 'line' => '', 'certificate' => ''];
    if (function_exists('squuad_cert_id_document_mask_in_boxes')) {
        squuad_cert_id_document_mask_in_boxes($mask);
    }

    return $parts;
}

/**
 * Maqueta de un documento emitido (diseño de página completa): el diseño ocupa su página sin relleno y la línea de la
 * solicitud y el «Certificado de firmas» van en hojas A4 verticales propias (páginas con nombre de CSS). La usan el PDF
 * final y la vista previa, para que coincidan. Devuelve [html, css extra].
 */
function squuad_cert_final_pdf_issued_layout(array $parts): array
{
    $html = '<div style="width:100%;box-sizing:border-box;background:#fff;color:#111">' . $parts['content'] . '</div>';
    if ('' !== (string) $parts['line'] || '' !== (string) $parts['certificate']) {
        $html .= '<div class="sq-cert-pages" style="font-family:Arial,sans-serif;color:#111">'
            . ('' !== (string) $parts['line'] ? '<p style="margin:0 0 8px;font-size:9px;color:#666;word-break:break-all">' . esc_html($parts['line']) . '</p>' : '')
            . $parts['certificate'] . '</div>';
    }

    // min-height: la regla R3 del servicio mide en pantalla, donde la hoja A4 queda justo bajo el diseño; si solo lleva la
    // línea (vista previa), creía que era un sobrante y recortaba la hoja. Cabe en una A4 (277 mm útiles)
    return [$html, '@page sqcert { size: A4 portrait; margin: 10mm; } .sq-cert-pages { page: sqcert; break-before: page; min-height: 200mm; }'];
}

/**
 * Genera en el servidor de PDF el PDF final de una solicitud `signed` sin PDF y la completa. Candado de MySQL por
 * solicitud: dentro se vuelve a leer el estado (dos procesos no llaman dos veces al servicio). Sella la evidencia del
 * servicio para poder volver a comprobar su firma (sitio, nonce y huella de su clave pública).
 *
 * @param string $origin firma | filled | inbox | cron | manual
 * @return int|WP_Error id del adjunto
 */
function squuad_cert_final_pdf_server_generate(int $request_id, string $origin, int $timeout = 45)
{
    if (!squuad_cert_final_pdf_lock($request_id)) {
        return new WP_Error('en_curso', 'otra petición ya genera este PDF');
    }
    try {
        $request = squuad_cert_signature_request_get($request_id);
        if (!$request || 'signed' !== $request->status || !empty($request->final_attachment_id)) {
            return new WP_Error('no_pendiente', 'la solicitud no espera su PDF final');
        }
        if ('servicio' !== squuad_cert_final_pdf_engine($request)) {
            return new WP_Error('motor_navegador', 'el documento usa el navegador');
        }
        $built = squuad_cert_final_pdf_server_payload($request);
        if (!$built) {
            return new WP_Error('sin_contenido', 'la solicitud no tiene contenido para el PDF');
        }
        $result = squuad_cert_pdf_render($built['payload'], 'PDF final de la solicitud ' . $request_id, $timeout);
        if (is_wp_error($result)) {
            return $result;
        }
        $actor = get_current_user_id();
        $origin = sanitize_key($origin);
        $stored = squuad_cert_final_pdf_store($request, $result['pdf'], $built['title'], $actor, [
            'pdf_engine' => 'servicio',
            'origin' => $origin,
            'actor' => $actor,
            'render_input_sha256' => $result['render_input_sha256'],
            'rules' => $result['rules'],
            'chrome' => $result['chrome'],
            'service_site' => $result['site'],
            'service_nonce' => $result['nonce'],
            'service_key_id' => defined('SQUUAD_CERT_PDF_SERVICE_KEY_ID') ? (string) SQUUAD_CERT_PDF_SERVICE_KEY_ID : '',
            'service_pubkey_sha256' => $result['service_pubkey_sha256'],
            'service_signature' => $result['service_signature'],
            'service_signed_at' => (int) $result['signed_at'],
        ]);
        if (!is_wp_error($stored)) {
            // Después de guardar: si otra petición la completó antes, no queda un «generado» engañoso
            squuad_cert_signature_request_log_event($request_id, 'pdf_rendered', [
                'pdf_engine' => 'servicio',
                'origin' => $origin,
                'ms' => (int) $result['ms'],
                'pages' => (int) $result['pages'],
            ], $actor);
        }

        return $stored;
    } finally {
        squuad_cert_final_pdf_unlock($request_id);
    }
}

/**
 * Anota un fallo del servidor: intentos, próximo intento con espera creciente (5 min, 10, 20… hasta 6 h) y último error.
 * Tras SQUUAD_CERT_PDF_MAX_ATTEMPTS fallos, si el sitio permite el respaldo, autoriza una vez al navegador (24 h, de un
 * solo uso, ligado al contenido) y lo sella en el evento pdf_fallback_granted.
 */
function squuad_cert_final_pdf_record_failure(int $request_id, string $reason): void
{
    global $wpdb;

    $table = $wpdb->prefix . 'squuad_cert_requests';
    $wpdb->query($wpdb->prepare("UPDATE {$table} SET pdf_attempts = pdf_attempts + 1, pdf_last_error = %s WHERE id = %d AND status = 'signed'", mb_substr($reason, 0, 190), $request_id));
    $attempts = (int) $wpdb->get_var($wpdb->prepare("SELECT pdf_attempts FROM {$table} WHERE id = %d", $request_id));
    $wait = min(6 * HOUR_IN_SECONDS, 5 * MINUTE_IN_SECONDS * (2 ** min(7, max(0, $attempts - 1))));
    $wpdb->query($wpdb->prepare("UPDATE {$table} SET pdf_next_at_utc = %s WHERE id = %d AND status = 'signed'", gmdate('Y-m-d H:i:s', time() + $wait), $request_id));
    $request = squuad_cert_signature_request_get($request_id);
    $already = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}squuad_cert_events WHERE request_id = %d AND event_type = 'pdf_fallback_granted'", $request_id));
    $last_signer = squuad_cert_final_pdf_last_signer($request_id);
    if ($request && 'signed' === $request->status && empty($request->final_attachment_id) && !$already && $last_signer
        && $attempts >= SQUUAD_CERT_PDF_MAX_ATTEMPTS && '1' === get_option(SQUUAD_CERT_PDF_FALLBACK_OPTION, '0')) {
        $event_id = squuad_cert_signature_request_log_event($request_id, 'pdf_fallback_granted', [
            'reason' => mb_substr($reason, 0, 190),
            'attempts' => $attempts,
            'by' => 'servidor',
            'user_id' => $last_signer,
            'valid_hours' => 24,
        ], 0);
        set_transient('squuad_cert_pdf_fallback_' . $request_id, [
            'event_id' => (int) $event_id,
            'user_id' => $last_signer,
            'content_sha256' => (string) $request->content_sha256,
            'until' => time() + DAY_IN_SECONDS,
        ], DAY_IN_SECONDS);
        squuad_cert_log(sprintf('PDF final de la solicitud %d: %d fallos del servidor; se autoriza una vez el navegador (24 h)', $request_id, $attempts), 'pdf_engine');
    }
}

/**
 * Intento en la misma petición que completó las firmas: si falla, a la cola sin error para el usuario. Solo si en esta
 * misma petición la solicitud pasó a `signed` (squuad_cert_final_pdf_on_signed); en cualquier otra, nada: decide la
 * cola (un usuario no puede forzar llamadas al servicio recargando ni repitiendo peticiones).
 */
function squuad_cert_final_pdf_try_now(int $request_id, string $origin): bool
{
    global $wpdb;

    if (!in_array($request_id, squuad_cert_final_pdf_signed_now(), true)) {
        return false;
    }
    // Reclama el intento: solo si toca (sin espera pendiente) y aplaza el siguiente 60 s mientras tanto
    $claimed = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}squuad_cert_requests SET pdf_next_at_utc = %s WHERE id = %d AND status = 'signed'
         AND (final_attachment_id IS NULL OR final_attachment_id = 0) AND (pdf_next_at_utc IS NULL OR pdf_next_at_utc <= %s)",
        gmdate('Y-m-d H:i:s', time() + 60),
        $request_id,
        gmdate('Y-m-d H:i:s')
    ));
    if (!$claimed) {
        return false;
    }
    $result = squuad_cert_final_pdf_server_generate($request_id, $origin, SQUUAD_CERT_PDF_SYNC_TIMEOUT);
    if (is_wp_error($result)) {
        if (!in_array($result->get_error_code(), ['en_curso', 'no_pendiente', 'motor_navegador'], true)) {
            squuad_cert_final_pdf_record_failure($request_id, $result->get_error_code() . ': ' . (string) (($result->get_error_data()['reason'] ?? '') ?: $result->get_error_message()));
        }
        return false;
    }

    return true;
}

/** Procesa la cola (cron o «Procesar ahora») con un presupuesto de tiempo. */
function squuad_cert_final_pdf_queue_process(int $budget_seconds = 20, string $origin = 'cron'): array
{
    global $wpdb;

    $stats = ['hechos' => 0, 'fallidos' => 0, 'pendientes' => 0];
    $start = time();
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}squuad_cert_requests WHERE status = 'signed' AND (final_attachment_id IS NULL OR final_attachment_id = 0)
         AND pdf_next_at_utc IS NOT NULL AND pdf_next_at_utc <= %s ORDER BY pdf_next_at_utc LIMIT 50",
        gmdate('Y-m-d H:i:s')
    ));
    foreach ($ids as $id) {
        if (time() - $start >= $budget_seconds) {
            break;
        }
        $result = squuad_cert_final_pdf_server_generate((int) $id, $origin, max(5, min(20, $budget_seconds - (time() - $start))));
        if (!is_wp_error($result)) {
            $stats['hechos']++;
        } elseif (in_array($result->get_error_code(), ['motor_navegador', 'sin_contenido'], true)) {
            // El documento volvió al navegador (el PDF lo hará quien firmó el último, como siempre) o la solicitud no
            // tiene contenido: sale de la cola con el motivo, sin reintentos sin fin
            $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}squuad_cert_requests SET pdf_next_at_utc = NULL, pdf_last_error = %s WHERE id = %d", $result->get_error_code(), (int) $id));
        } elseif (!in_array($result->get_error_code(), ['en_curso', 'no_pendiente'], true)) {
            $stats['fallidos']++;
            squuad_cert_final_pdf_record_failure((int) $id, $result->get_error_code() . ': ' . (string) (($result->get_error_data()['reason'] ?? '') ?: $result->get_error_message()));
        }
    }
    $stats['pendientes'] = squuad_cert_final_pdf_queue_count();

    return $stats;
}

/** ¿Ya existen las columnas de la cola (esquema 18)? Entre el despliegue y la actualización de tablas, no. */
function squuad_cert_final_pdf_schema_ready(): bool
{
    return version_compare((string) get_option('wp_c_db_version'), '18', '>=');
}

/** Solicitudes en la cola del servidor (firmadas, sin PDF y con próximo intento). */
function squuad_cert_final_pdf_queue_count(): int
{
    global $wpdb;

    if (!squuad_cert_final_pdf_schema_ready()) {
        return 0;
    }
    return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}squuad_cert_requests WHERE status = 'signed' AND (final_attachment_id IS NULL OR final_attachment_id = 0) AND pdf_next_at_utc IS NOT NULL");
}

/** Pone en la cola las firmadas sin PDF de documentos que ya usan el servidor (p. ej. tras cambiar de motor). */
function squuad_cert_final_pdf_enqueue_pending(): int
{
    global $wpdb;

    $count = 0;
    foreach ($wpdb->get_results("SELECT * FROM {$wpdb->prefix}squuad_cert_requests WHERE status = 'signed' AND (final_attachment_id IS NULL OR final_attachment_id = 0) AND pdf_next_at_utc IS NULL") as $request) {
        if ('servicio' === squuad_cert_final_pdf_engine($request)) {
            squuad_cert_final_pdf_enqueue((int) $request->id);
            $count++;
        }
    }

    return $count;
}

// Todas las firmas hechas (cualquier camino: Mi Cuenta, bandeja, lotes): con el motor «servicio», a la cola
/** Solicitudes que pasaron a `signed` en esta misma petición (las únicas que se intentan en el momento). */
function squuad_cert_final_pdf_signed_now(?int $add = null): array
{
    static $ids = [];
    if (null !== $add) {
        $ids[] = $add;
    }

    return $ids;
}

add_action('squuad_cert_request_signed', 'squuad_cert_final_pdf_on_signed');
function squuad_cert_final_pdf_on_signed(int $request_id): void
{
    $request = squuad_cert_signature_request_get($request_id);
    if ($request && 'servicio' === squuad_cert_final_pdf_engine($request)) {
        squuad_cert_final_pdf_signed_now($request_id);
        squuad_cert_final_pdf_enqueue($request_id);
        if (!wp_next_scheduled('squuad_cert_pdf_queue_soon')) {
            wp_schedule_single_event(time() + 30, 'squuad_cert_pdf_queue_soon');
        }
    }
}

// WP-Cron: cada 5 minutos y, tras una firma, a los 30 s. Si el cron del sitio no funciona, «Procesar ahora»
add_filter('cron_schedules', static function (array $schedules): array {
    $schedules['squuad_cert_5min'] = ['interval' => 5 * MINUTE_IN_SECONDS, 'display' => __('Every 5 minutes (final PDFs)', 'wp-certificates')];
    return $schedules;
});
add_action('squuad_cert_pdf_queue_tick', 'squuad_cert_final_pdf_cron');
add_action('squuad_cert_pdf_queue_soon', 'squuad_cert_final_pdf_cron');
function squuad_cert_final_pdf_cron(): void
{
    if (squuad_cert_final_pdf_queue_count() > 0) {
        squuad_cert_final_pdf_queue_process(20, 'cron');
    }
}
register_deactivation_hook(dirname(__DIR__, 2) . '/wp-certificates.php', static function (): void {
    wp_clear_scheduled_hook('squuad_cert_pdf_queue_tick');
    wp_clear_scheduled_hook('squuad_cert_pdf_queue_soon');
});
add_action('init', static function (): void {
    if (!wp_next_scheduled('squuad_cert_pdf_queue_tick')) {
        wp_schedule_event(time() + MINUTE_IN_SECONDS, 'squuad_cert_5min', 'squuad_cert_pdf_queue_tick');
    }
});

/**
 * Comprueba el PDF final de una solicitud completada: huella del archivo = final_pdf_sha256 y, si lo hizo el servidor,
 * la firma Ed25519 del servicio con la clave cuya huella quedó sellada. Devuelve ['ok' => bool, 'motor', 'detalle'].
 */
function squuad_cert_final_pdf_verify(object $request): array
{
    global $wpdb;

    $file = !empty($request->final_attachment_id) ? get_attached_file((int) $request->final_attachment_id) : '';
    if (!$file || !is_readable($file)) {
        return ['ok' => false, 'motor' => '?', 'detalle' => 'sin archivo'];
    }
    $pdf = (string) file_get_contents($file);
    if (!hash_equals((string) $request->final_pdf_sha256, hash('sha256', $pdf))) {
        return ['ok' => false, 'motor' => '?', 'detalle' => 'el archivo no coincide con la huella sellada'];
    }
    $event = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}squuad_cert_events WHERE request_id = %d AND event_type = 'status_completed' ORDER BY id DESC LIMIT 1",
        (int) $request->id
    ));
    if (!$event) {
        return ['ok' => false, 'motor' => '?', 'detalle' => 'sin el evento sellado de la finalización'];
    }
    // El evento manda (sellado en la cadena), no las columnas: huella HMAC de la cadena y la huella del PDF que selló
    $chain = squuad_cert_signature_request_verify_event($event);
    $data = json_decode((string) $event->data, true);
    if ('verified' !== $chain && 'missing' !== $chain) {
        return ['ok' => false, 'motor' => '?', 'detalle' => 'evento de finalización no válido en la cadena (' . $chain . ')'];
    }
    if (!is_array($data) || !hash_equals((string) ($data['final_pdf_sha256'] ?? ''), hash('sha256', $pdf))) {
        return ['ok' => false, 'motor' => '?', 'detalle' => 'el archivo no coincide con la huella sellada en el evento'];
    }
    $engine = is_array($data) && isset($data['pdf_engine']) ? (string) $data['pdf_engine'] : 'navegador (anterior)';
    if ('servicio' !== $engine) {
        return ['ok' => true, 'motor' => $engine, 'detalle' => 'huella del archivo correcta'];
    }
    if (!function_exists('sodium_crypto_sign_verify_detached')) {
        return ['ok' => false, 'motor' => $engine, 'detalle' => 'sin la extensión sodium: no se puede comprobar la firma del servidor'];
    }
    $keys = squuad_cert_pdf_service_pubkeys();
    $key = $keys[(string) ($data['service_pubkey_sha256'] ?? '')] ?? null;
    if (!$key) {
        return ['ok' => false, 'motor' => $engine, 'detalle' => 'clave pública del servicio desconocida'];
    }
    $signed = implode("\n", ['v1', (string) $data['service_site'], (string) $data['service_nonce'], (string) $data['render_input_sha256'],
        hash('sha256', $pdf), (string) $data['rules'], (string) $data['chrome'], (string) $data['service_signed_at']]);
    $sig = base64_decode((string) ($data['service_signature'] ?? ''), true);
    $ok = false !== $sig && SODIUM_CRYPTO_SIGN_BYTES === strlen($sig) && sodium_crypto_sign_verify_detached($sig, $signed, $key);

    return ['ok' => $ok, 'motor' => $engine, 'detalle' => $ok ? 'huella y firma del servidor de PDF correctas' : 'la firma del servidor de PDF no es válida'];
}

if (defined('WP_CLI') && WP_CLI) {
    /**
     * wp squuad-cert pdf verificar [--id=<n>]  · PDF finales: huella del archivo y firma del servidor de PDF.
     * wp squuad-cert pdf cola                   · procesa la cola del servidor ahora.
     */
    WP_CLI::add_command('squuad-cert pdf verificar', static function (array $args, array $assoc): void {
        global $wpdb;
        $where = isset($assoc['id']) ? $wpdb->prepare(' AND id = %d', (int) $assoc['id']) : '';
        $bad = 0;
        foreach ($wpdb->get_results("SELECT * FROM {$wpdb->prefix}squuad_cert_requests WHERE status = 'completed' AND final_attachment_id > 0{$where} ORDER BY id") as $request) {
            $r = squuad_cert_final_pdf_verify($request);
            $bad += $r['ok'] ? 0 : 1;
            WP_CLI::log(sprintf('%s solicitud %d · %s · %s', $r['ok'] ? '✔' : '✘', (int) $request->id, $r['motor'], $r['detalle']));
        }
        $bad ? WP_CLI::error(sprintf('%d PDF finales con problemas', $bad)) : WP_CLI::success('PDF finales correctos');
    });
    WP_CLI::add_command('squuad-cert pdf cola', static function (): void {
        $added = squuad_cert_final_pdf_enqueue_pending();
        $stats = squuad_cert_final_pdf_queue_process(300, 'manual');
        WP_CLI::success(sprintf('Encoladas %d · hechas %d · fallidas %d · pendientes %d', $added, $stats['hechos'], $stats['fallidos'], $stats['pendientes']));
    });
}
