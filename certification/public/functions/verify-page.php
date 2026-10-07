<?php
declare(strict_types=1);

/**
 * Certificación - Página pública de verificación de un documento firmado (ADR 0014 de Edusof, fase 1).
 *
 * La dirección es la que ya llevan los PDF entregados (squuad_cert_signature_verify_url()):
 * ?squuad_cert_verify=WPC-AAAA-NNNNNN&t=<token>. Se acepta siempre en el propio sitio, aunque otro filtro cambie la
 * dirección de los QR nuevos.
 *
 * - Sin token válido no se muestra nada: el código se puede adivinar, el token no (HMAC con las claves del sitio).
 *   Todos los fallos responden igual (mismo código HTTP y cuerpo), sin repetir lo recibido, y cuentan en el límite por
 *   IP que comparte la API pública de certificados (public/endpoint.php).
 * - Solo lectura: nunca escribe en la cadena sellada (eventos, firmas, cabeza de la cadena).
 * - Muestra estado, documento, código, fechas, huellas e integridad. De los firmantes: nombre y cargo solo del personal
 *   de la institución (puestos «signer:»); del titular, su representante o un firmante por variable, solo el papel y la
 *   fecha (decisión del dueño, 2026-10-06). Nunca la imagen de la firma, documentos de identidad, IP, correo, método,
 *   motivos de rechazo o anulación ni detalles técnicos.
 * - Página propia, sin tema ni JS de terceros, con cabeceras de no indexar, no guardar en caché y no enmarcar.
 */

if (!defined('ABSPATH')) exit;

/** Minutos que se guarda el resultado de la comprobación de integridad de una solicitud (lee el PDF del disco). */
const SQUUAD_CERT_VERIFY_CACHE_MINUTES = 10;

/**
 * Todas las claves del sitio en bytes (constantes de wp-config.php y opción, también las retiradas), sin repetir y sin
 * las nulas o cortas. Las usa la página para aceptar los QR impresos antes de una rotación.
 *
 * @return string[]
 */
function squuad_cert_signature_all_keys(): array
{
    $ids = array_keys(squuad_cert_signature_stored_keys()['keys']);
    if (defined('SQUUAD_CERT_SIGNATURE_KEYS') && is_array(SQUUAD_CERT_SIGNATURE_KEYS)) {
        $ids = array_merge(array_map('strval', array_keys(SQUUAD_CERT_SIGNATURE_KEYS)), $ids);
    }
    $keys = [];
    foreach (array_unique($ids) as $key_id) {
        $key = squuad_cert_signature_key_by_id((string) $key_id);
        if (null !== $key && strlen($key) >= 32) {
            $keys[hash('sha256', $key)] = $key;
        }
    }

    return array_values($keys);
}

/** Subclave del token del QR del diseño (etiqueta reservada «wpc-verify-doc-v1», ADR 0014). */
function squuad_cert_signature_verify_doc_subkey(string $key): string
{
    return hash_hmac('sha256', 'wpc-verify-doc-v1', $key, true);
}

/**
 * ¿Es $token un token de verificación de la solicitud con alguna clave del sitio? Acepta el de la hoja
 * (verify|id|content_sha256) y el del QR del diseño (verify-doc|id, con subclave). Un token vacío o mal formado nunca
 * vale: se exige el formato antes de calcular nada.
 */
function squuad_cert_signature_verify_token_matches(object $request, string $token): bool
{
    if (!preg_match('/^[0-9a-f]{16}$/', $token)) {
        return false;
    }
    $ok = false;
    foreach (squuad_cert_signature_all_keys() as $key) {
        // Sin contenido (borrador) no hay token de la hoja: «verify|id|» nunca vale
        $sheet = '' !== (string) ($request->content_sha256 ?? '')
            ? substr(hash_hmac('sha256', 'verify|' . (int) $request->id . '|' . (string) $request->content_sha256, $key), 0, 16) : '';
        $design = substr(hash_hmac('sha256', 'verify-doc|' . (int) $request->id, squuad_cert_signature_verify_doc_subkey($key)), 0, 16);
        // Sin cortar el bucle: el tiempo no depende de qué clave coincide
        $ok = hash_equals($sheet, $token) | hash_equals($design, $token) | $ok;
    }

    return (bool) $ok;
}

/**
 * Comprobación de integridad de la solicitud, solo con sus propias filas (nunca la cadena completa): firmas vivas,
 * eventos sellados de creación, emisión y finalización, y PDF final (huella del archivo y, si lo hizo el servidor de
 * PDF, su firma). Lo que la página muestra como intacto sale de esos eventos ya verificados (firmantes y título), no de
 * columnas sin sello. En caché unos minutos por solicitud, estado y huella del PDF.
 *
 * @return array{ok: bool, pdf: string, signers: ?array, title: string} pdf: ok | unavailable | bad;
 *         signers: [puesto => ['name', 'charge']] del evento «created» (null en solicitudes sin él)
 */
function squuad_cert_verify_page_integrity(object $request): array
{
    global $wpdb;

    // Con el último eslabón de sus firmas: una firma nueva o anulada sin cambio de estado no deja la caché atrás
    $last_seq = (string) $wpdb->get_var($wpdb->prepare(
        "SELECT CONCAT(COUNT(*), ':', COALESCE(MAX(chain_seq), 0)) FROM {$wpdb->prefix}squuad_cert_signatures WHERE request_id = %d",
        (int) $request->id
    ));
    $cache_key = 'squuad_cert_verify_' . md5((int) $request->id . '|' . (string) $request->status . '|' . (string) $request->final_pdf_sha256 . '|' . $last_seq);
    $cached = get_transient($cache_key);
    if (is_array($cached) && isset($cached['ok'], $cached['pdf'], $cached['title']) && array_key_exists('signers', $cached)) {
        return $cached;
    }

    $ok = true;
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}squuad_cert_signatures WHERE request_id = %d", (int) $request->id));
    foreach ((array) $rows as $row) {
        // retired_key = firmada con una clave ya retirada (anomalía): no vale
        $ok = $ok && 'verified' === squuad_cert_signature_verify_row($row);
    }

    // Eventos sellados de la solicitud (el primero de cada tipo; el último en la finalización)
    $event = static function (string $type, string $order = 'ASC') use ($wpdb, $request): ?object {
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}squuad_cert_events WHERE request_id = %d AND event_type = %s ORDER BY id " . ('DESC' === $order ? 'DESC' : 'ASC') . ' LIMIT 1',
            (int) $request->id,
            $type
        ));

        return $row ?: null;
    };

    // Firmantes sellados al crear la solicitud: los nombres y cargos fijados tienen que coincidir con ellos
    $signers = null;
    $created = $event('created');
    if ($created) {
        $ok = $ok && 'verified' === squuad_cert_signature_request_verify_event($created);
        $data = json_decode((string) $created->data, true);
        if (is_array($data) && isset($data['signers']) && is_array($data['signers'])) {
            $signers = [];
            foreach ($data['signers'] as $sealed) {
                if (is_array($sealed) && isset($sealed['slot'])) {
                    $signers[(string) $sealed['slot']] = ['name' => (string) ($sealed['name'] ?? ''), 'charge' => (string) ($sealed['charge'] ?? '')];
                }
            }
            foreach (squuad_cert_request_signers($request) as $fixed) {
                $sealed = $signers[$fixed['slot_key']] ?? null;
                if (null !== $sealed && ($sealed['name'] !== $fixed['name'] || $sealed['charge'] !== $fixed['charge'])) {
                    $ok = false;
                }
            }
        }
    }

    // Título sellado al emitir (solo si su evento está íntegro)
    $title = '';
    $issued = $event('issued');
    if ($issued) {
        $ok = $ok && 'verified' === squuad_cert_signature_request_verify_event($issued);
        $data = json_decode((string) $issued->data, true);
        $title = is_array($data) ? (string) ($data['title'] ?? '') : '';
    }

    $pdf = 'unavailable';
    if ('completed' === $request->status) {
        $signed = array_map(static fn($row): string => (string) $row->signer_role, (array) $rows);
        $ok = $ok && !array_diff(squuad_cert_signature_request_required_roles($request), $signed);
        $completed = $event('status_completed', 'DESC');
        // El evento de finalización tiene que estar sellado y verificado (sin huella = no se puede comprobar)
        $ok = $ok && $completed && 'verified' === squuad_cert_signature_request_verify_event($completed);
        $data = $completed ? json_decode((string) $completed->data, true) : null;
        // Manda el evento, no las columnas: si selló la huella del PDF, el archivo tiene que existir y coincidir
        if (is_array($data) && '' !== (string) ($data['final_pdf_sha256'] ?? '')) {
            $pdf = !empty($request->final_attachment_id) && squuad_cert_final_pdf_verify($request)['ok'] ? 'ok' : 'bad';
            $ok = $ok && 'ok' === $pdf;
        }
    }

    $result = ['ok' => $ok, 'pdf' => $pdf, 'signers' => $signers, 'title' => $title];
    set_transient($cache_key, $result, SQUUAD_CERT_VERIFY_CACHE_MINUTES * MINUTE_IN_SECONDS);

    return $result;
}

/**
 * Estado público de la solicitud: valid | replaced | declined | closed | voided | finishing | in_progress |
 * unverifiable. Nunca «válido» si algo no cuadra ni si hay una emisión posterior del mismo documento al mismo titular.
 */
function squuad_cert_verify_page_state(object $request, array $integrity): string
{
    global $wpdb;

    if (!$integrity['ok']) {
        return 'unverifiable';
    }
    $newer = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}squuad_cert_requests WHERE subject_type = %s AND subject_id = %d AND document_id = %s AND round > %d",
        (string) $request->subject_type,
        (int) $request->subject_id,
        (string) $request->document_id,
        (int) $request->round
    ));
    if ($newer > 0) {
        return 'replaced';
    }
    if ('completed' === $request->status) {
        return 'valid';
    }
    if ('declined' === $request->status) {
        return 'declined';
    }
    if ('closed_by_upload' === $request->status) {
        return 'closed';
    }
    $revoked = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}squuad_cert_signatures_revoked WHERE request_id = %d",
        (int) $request->id
    ));
    if ($revoked > 0) {
        return 'voided';
    }
    if ('signed' === $request->status) {
        return 'finishing';
    }

    return in_array($request->status, ['open', 'partially_signed'], true) ? 'in_progress' : 'unverifiable';
}

/** Título del documento: el sellado al emitir; si no lo hay, el actual del documento o «Documento». */
function squuad_cert_verify_page_title(object $request, array $integrity): string
{
    global $wpdb;

    if ('' !== $integrity['title']) {
        return $integrity['title'];
    }
    $title = (string) $wpdb->get_var($wpdb->prepare(
        "SELECT title FROM {$wpdb->prefix}documents_certificates WHERE id = %d",
        (int) ($request->document_certificate_id ?? 0)
    ));

    return '' !== $title ? $title : __('Document', 'wp-certificates');
}

/**
 * Firmantes que ya firmaron, en orden: [['who' => nombre o papel, 'role' => cargo o '', 'at' => fecha local]]. Nombre y
 * cargo solo del personal, tal como quedaron sellados al crear la solicitud; el resto, solo su papel.
 */
function squuad_cert_verify_page_signers(object $request, array $integrity): array
{
    $slots = [];
    foreach (squuad_cert_request_signers($request) as $signer) {
        $slots[$signer['slot_key']] = $signer;
    }
    $list = [];
    foreach (squuad_cert_signature_request_rows((int) $request->id) as $slot => $row) {
        $slot = (string) $slot;
        $at = squuad_cert_signature_local_time((string) $row->signed_at_utc);
        if (squuad_cert_is_signer_slot($slot)) {
            $signer = null !== $integrity['signers'] ? ($integrity['signers'][$slot] ?? ['name' => '', 'charge' => '']) : ($slots[$slot] ?? ['name' => '', 'charge' => '']);
            $list[] = ['who' => '' !== $signer['name'] ? $signer['name'] : __('Institution signer', 'wp-certificates'), 'role' => (string) $signer['charge'], 'at' => $at];
        } else {
            $role = squuad_cert_is_var_slot($slot) || squuad_cert_is_holder_slot($slot) ? squuad_cert_person_slot_label($slot, $request) : '';
            $list[] = ['who' => '' !== $role ? $role : __('Signer', 'wp-certificates'), 'role' => '', 'at' => $at];
        }
    }

    return $list;
}

add_action('init', 'squuad_cert_verify_page_handle', 20);
/**
 * Atiende ?squuad_cert_verify=…&t=… en las páginas públicas (GET), en «init» para ir antes del tema, de «forzar inicio
 * de sesión» y del modo mantenimiento. No actúa en el admin, AJAX, cron, la API REST ni WP-CLI.
 */
function squuad_cert_verify_page_handle(): void
{
    if (!isset($_GET['squuad_cert_verify']) || is_admin() || wp_doing_ajax() || wp_doing_cron()
        || (defined('REST_REQUEST') && REST_REQUEST) || (defined('WP_CLI') && WP_CLI) || squuad_cert_verify_page_is_rest()
        || !in_array(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['GET', 'HEAD'], true)) {
        return;
    }
    squuad_cert_verify_page_headers();

    // Límite por IP antes de calcular nada
    if (function_exists('squuad_cert_api_failures_key') && (int) get_transient(squuad_cert_api_failures_key()) >= SQUUAD_CERT_API_MAX_FAILURES) {
        squuad_cert_verify_page_render(null, 429);
    }

    // Tal como llegan, sin normalizar: una sola dirección válida por solicitud
    $code = is_string($_GET['squuad_cert_verify']) ? wp_unslash($_GET['squuad_cert_verify']) : '';
    $token = isset($_GET['t']) && is_string($_GET['t']) ? wp_unslash($_GET['t']) : '';
    $request = null;
    if (preg_match('/^WPC-(\d{4})-(\d{6,10})$/', $code, $match) && squuad_cert_signature_requests_enabled()) {
        $request = squuad_cert_signature_request_get((int) $match[2]);
        // Una sola dirección válida por solicitud (el código tiene que ser exactamente el suyo) y su token
        if (!$request || squuad_cert_signature_request_code($request) !== $code || !squuad_cert_signature_verify_token_matches($request, $token)) {
            $request = null;
        }
    }
    if (!$request) {
        if (function_exists('squuad_cert_api_failures_key')) {
            $key = squuad_cert_api_failures_key();
            set_transient($key, (int) get_transient($key) + 1, HOUR_IN_SECONDS);
        }
        squuad_cert_verify_page_render(null, 404);
    }

    squuad_cert_verify_page_render($request, 200);
}

/**
 * ¿Es una petición a la API REST? En «init» aún no está definida REST_REQUEST (llega con parse_request): se mira la
 * dirección (/wp-json/… o ?rest_route=).
 */
function squuad_cert_verify_page_is_rest(): bool
{
    if (isset($_GET['rest_route'])) {
        return true;
    }
    $path = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $prefix = '/' . trim(rest_get_url_prefix(), '/') . '/';

    return false !== strpos(trailingslashit($path), $prefix);
}

/** Cabeceras de la página: sin caché, sin indexar, sin enmarcar, sin enviar la dirección (lleva el token). */
function squuad_cert_verify_page_headers(): void
{
    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }
    nocache_headers();
    header('X-Robots-Tag: noindex, nofollow');
    header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
}

/** Pinta la página (o el aviso genérico si $request es null) y termina. */
function squuad_cert_verify_page_render(?object $request, int $status): void
{
    $nonce = base64_encode(random_bytes(16));
    header("Content-Security-Policy: default-src 'none'; script-src 'nonce-{$nonce}'; style-src 'nonce-{$nonce}'; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    status_header($status);

    $site = (string) get_bloginfo('name');
    $data = null;
    if ($request) {
        $integrity = squuad_cert_verify_page_integrity($request);
        $state = squuad_cert_verify_page_state($request, $integrity);
        $data = [
            'state' => $state,
            'title' => squuad_cert_verify_page_title($request, $integrity),
            'code' => squuad_cert_signature_request_code($request),
            'issued' => squuad_cert_signature_local_time((string) $request->created_at_utc),
            'completed' => 'valid' === $state ? squuad_cert_signature_local_time((string) ($request->completed_at_utc ?? '')) : '',
            'signers' => 'unverifiable' === $state ? [] : squuad_cert_verify_page_signers($request, $integrity),
            'content_sha256' => (string) $request->content_sha256,
            'pdf_sha256' => 'valid' === $state && 'ok' === $integrity['pdf'] ? (string) $request->final_pdf_sha256 : '',
        ];
    }
    $states = [
        'valid' => ['ok', __('Valid document', 'wp-certificates'), __('The signatures and the content are intact and match what was sealed when it was signed.', 'wp-certificates')],
        'finishing' => ['wait', __('Final document in preparation', 'wp-certificates'), __('Everyone has signed. The final document is being prepared; it is not valid until it is completed.', 'wp-certificates')],
        'in_progress' => ['wait', __('Signature in progress', 'wp-certificates'), __('This document has not been signed by everyone yet. It is not valid until it is completed.', 'wp-certificates')],
        'declined' => ['bad', __('Declined', 'wp-certificates'), __('This document was declined and is not valid.', 'wp-certificates')],
        'closed' => ['wait', __('Closed', 'wp-certificates'), __('This signature request was closed by the institution with a document delivered by other means. Contact the institution.', 'wp-certificates')],
        'voided' => ['bad', __('Voided', 'wp-certificates'), __('One or more signatures of this document were voided. It is not valid.', 'wp-certificates')],
        'replaced' => ['bad', __('Replaced', 'wp-certificates'), __('This document was replaced by a later issue. It is not valid.', 'wp-certificates')],
        'unverifiable' => ['bad', __('Could not be verified', 'wp-certificates'), __('This document could not be verified. Contact the institution.', 'wp-certificates')],
    ];
    ?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title><?= esc_html(sprintf(/* translators: %s: site name */ __('Document verification — %s', 'wp-certificates'), $site)) ?></title>
    <style nonce="<?= esc_attr($nonce) ?>">
        :root{--bg:#f4f5f7;--card:#fff;--text:#1d2327;--muted:#50575e;--line:#dcdcde;--ok:#00704a;--ok-bg:#e6f4ee;--wait:#8a5a00;--wait-bg:#fcf3e1;--bad:#b32d2e;--bad-bg:#fcf0f1;--mono:#f0f0f1}
        @media (prefers-color-scheme:dark){:root{--bg:#121417;--card:#1c1f24;--text:#e8eaed;--muted:#a7aaad;--line:#33373d;--ok:#5fd4a0;--ok-bg:#12332a;--wait:#f0c060;--wait-bg:#3a2e12;--bad:#f28b8c;--bad-bg:#3d1c1d;--mono:#26292e}}
        *{box-sizing:border-box}
        body{margin:0;background:var(--bg);color:var(--text);font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;padding:32px 16px}
        main{max-width:640px;margin:0 auto}
        .site{font-weight:700;letter-spacing:.04em;text-transform:uppercase;font-size:13px;color:var(--muted);margin:0 0 12px}
        .card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:24px;margin-bottom:16px}
        h1{font-size:22px;margin:0 0 4px}
        .state{border-radius:8px;padding:14px 16px;margin:16px 0 4px}
        .state strong{display:block;font-size:17px}
        .ok{background:var(--ok-bg);color:var(--ok)}.wait{background:var(--wait-bg);color:var(--wait)}.bad{background:var(--bad-bg);color:var(--bad)}
        .state span{color:var(--text)}
        dl{display:grid;grid-template-columns:max-content 1fr;gap:6px 16px;margin:16px 0 0}
        dt{color:var(--muted);font-weight:600}dd{margin:0;overflow-wrap:anywhere}
        h2{font-size:14px;text-transform:uppercase;letter-spacing:.04em;margin:0 0 10px}
        ol{margin:0;padding-left:20px}li{margin:0 0 8px}li small{display:block;color:var(--muted)}
        code{font:13px/1.5 Menlo,Consolas,monospace;background:var(--mono);padding:6px 8px;border-radius:6px;display:block;overflow-wrap:anywhere}
        .note{color:var(--muted);font-size:13px}
        .gap{margin-top:16px}
        .compare{margin-top:16px;border:2px dashed var(--line);border-radius:8px;padding:16px;text-align:center}
        .compare.over{border-color:var(--ok);background:var(--ok-bg)}
        .compare label{display:inline-block;cursor:pointer;font-weight:600;padding:8px 14px;border:1px solid var(--line);border-radius:6px;background:var(--card);color:var(--text)}
        .compare label:focus-within{outline:2px solid var(--ok);outline-offset:2px}
        .compare input{position:absolute;opacity:0;width:1px;height:1px}
        .result{margin:12px 0 0;border-radius:8px;padding:10px 12px;text-align:left}
        .result:empty{display:none}
        @media (max-width:480px){dl{grid-template-columns:1fr}dt{margin-top:6px}}
    </style>
</head>
<body>
<main>
    <p class="site"><?= esc_html($site) ?></p>
    <?php if (!$data) : ?>
        <div class="card">
            <h1><?= esc_html__('Document verification', 'wp-certificates') ?></h1>
            <div class="state bad"><strong><?= esc_html($status === 429 ? __('Too many attempts', 'wp-certificates') : __('Could not be verified', 'wp-certificates')) ?></strong>
                <span><?= esc_html($status === 429
                    ? __('Too many attempts. Please try again later.', 'wp-certificates')
                    : __('The verification link is not valid. Scan the QR code of the document again or contact the institution.', 'wp-certificates')) ?></span></div>
        </div>
    <?php else :
        [$class, $label, $text] = $states[$data['state']]; ?>
        <div class="card">
            <h1><?= esc_html__('Document verification', 'wp-certificates') ?></h1>
            <div class="state <?= esc_attr($class) ?>"><strong><?= esc_html($label) ?></strong><span><?= esc_html($text) ?></span></div>
            <dl>
                <dt><?= esc_html__('Document', 'wp-certificates') ?></dt><dd><?= esc_html($data['title']) ?></dd>
                <dt><?= esc_html__('Code', 'wp-certificates') ?></dt><dd><?= esc_html($data['code']) ?></dd>
                <dt><?= esc_html__('Sent for signature', 'wp-certificates') ?></dt><dd><?= esc_html($data['issued']) ?></dd>
                <?php if ('' !== $data['completed']) : ?><dt><?= esc_html__('Completed', 'wp-certificates') ?></dt><dd><?= esc_html($data['completed']) ?></dd><?php endif; ?>
            </dl>
        </div>
        <?php if ($data['signers']) : ?>
            <div class="card">
                <h2><?= esc_html__('Signers, in the order in which they signed', 'wp-certificates') ?></h2>
                <ol>
                    <?php foreach ($data['signers'] as $signer) : ?>
                        <li><strong><?= esc_html($signer['who']) ?></strong><?= '' !== $signer['role'] ? ' · ' . esc_html($signer['role']) : '' ?>
                            <small><?= esc_html(sprintf(/* translators: %s: date and time */ __('Signed on %s', 'wp-certificates'), $signer['at'])) ?></small></li>
                    <?php endforeach; ?>
                </ol>
            </div>
        <?php endif; ?>
        <?php if ('unverifiable' !== $data['state']) : ?>
            <div class="card">
                <h2><?= esc_html__('Content fingerprint (SHA-256)', 'wp-certificates') ?></h2>
                <?php if ('' !== $data['content_sha256']) : ?>
                    <code><?= esc_html($data['content_sha256']) ?></code>
                <?php else : ?>
                    <p class="note"><?= esc_html__('Not available for this document.', 'wp-certificates') ?></p>
                <?php endif; ?>
                <?php if ('valid' === $data['state']) : ?>
                    <h2 class="gap"><?= esc_html__('Fingerprint of the signed PDF (SHA-256)', 'wp-certificates') ?></h2>
                    <?php if ('' !== $data['pdf_sha256']) : ?>
                        <code><?= esc_html($data['pdf_sha256']) ?></code>
                        <p class="note"><?= esc_html__('Only the PDF file delivered by the institution has this fingerprint. A scan, a printout or a copy saved again will have a different one.', 'wp-certificates') ?></p>
                        <div class="compare" id="wpc-compare" data-sha256="<?= esc_attr($data['pdf_sha256']) ?>">
                            <h2><?= esc_html__('Compare your PDF', 'wp-certificates') ?></h2>
                            <p class="note"><?= esc_html__('Drop the PDF here or choose it. The check is done in your browser: the file is not sent anywhere.', 'wp-certificates') ?></p>
                            <label><input type="file" id="wpc-compare-file" accept="application/pdf,.pdf"><?= esc_html__('Choose PDF', 'wp-certificates') ?></label>
                            <div class="result" id="wpc-compare-result" role="status" aria-live="polite"></div>
                        </div>
                    <?php else : ?>
                        <p class="note"><?= esc_html__('Not available for this document.', 'wp-certificates') ?></p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
    <p class="note"><?= esc_html__('This page only shows the data needed to check the document. The personal data of the holder is not shown.', 'wp-certificates') ?></p>
</main>
<?php if ($data && '' !== $data['pdf_sha256']) : ?>
<script nonce="<?= esc_attr($nonce) ?>">
(function () {
    // Comparar el PDF (ADR 0014, fase 2): la huella se calcula aquí; la CSP no deja enviar nada (sin connect-src)
    var box = document.getElementById("wpc-compare"), input = document.getElementById("wpc-compare-file"), out = document.getElementById("wpc-compare-result");
    var text = <?= wp_json_encode([
        'insecure' => __('Your browser can only compare files on a secure (https) page. Open the verification link with https.', 'wp-certificates'),
        'big' => __('The file is too large to be the signed PDF.', 'wp-certificates'),
        'reading' => __('Checking…', 'wp-certificates'),
        'same' => __('It matches: this file is exactly the signed PDF.', 'wp-certificates'),
        'different' => __('It does not match. This file is not the signed PDF delivered by the institution. It may be a copy saved again, a scan or a different version; it does not necessarily mean it was forged. If in doubt, contact the institution.', 'wp-certificates'),
        'error' => __('The file could not be read.', 'wp-certificates'),
    ]) ?>;
    var max = 15 * 1024 * 1024;
    function show(kind, message) { out.className = "result " + kind; out.textContent = message; }
    function check(file) {
        if (!file) return;
        if (!window.crypto || !window.crypto.subtle) { show("wait", text.insecure); return; }
        if (file.size > max) { show("bad", text.big); return; }
        show("wait", text.reading);
        file.arrayBuffer().then(function (buffer) { return crypto.subtle.digest("SHA-256", buffer); }).then(function (digest) {
            var hex = Array.prototype.map.call(new Uint8Array(digest), function (b) { return ("0" + b.toString(16)).slice(-2); }).join("");
            show(hex === box.dataset.sha256 ? "ok" : "bad", hex === box.dataset.sha256 ? text.same : text.different);
        }).catch(function () { show("bad", text.error); });
    }
    input.addEventListener("change", function () { check(input.files[0]); input.value = ""; });
    ["dragenter", "dragover"].forEach(function (type) { box.addEventListener(type, function (e) { e.preventDefault(); box.classList.add("over"); }); });
    ["dragleave", "drop"].forEach(function (type) { box.addEventListener(type, function (e) { e.preventDefault(); box.classList.remove("over"); }); });
    box.addEventListener("drop", function (e) { check(e.dataTransfer.files[0]); });
    // Soltar el archivo fuera de la caja no debe abrirlo en la pestaña (se perdería la página)
    window.addEventListener("dragover", function (e) { e.preventDefault(); });
    window.addEventListener("drop", function (e) { e.preventDefault(); });
})();
</script>
<?php endif; ?>
</body>
</html><?php
    exit;
}
