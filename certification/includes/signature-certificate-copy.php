<?php
declare(strict_types=1);

/**
 * Certificación - Certificado de firmas descargable aparte (ADR 0014 de Edusof, fase 5).
 *
 * Para cualquier solicitud completada, lleve o no la hoja en su PDF: se genera al momento con la misma función que la
 * hoja (squuad_cert_signature_certificate_html()), con la marca «copia generada… no es el documento firmado» y la
 * huella del PDF final. Motor del documento: el servidor de PDF; si no está o falla, una página para imprimir o guardar
 * como PDF desde el navegador.
 *
 * - Quién: el personal con el permiso que da acceso a los PDF firmados (manager_certificates) o, en las fichas de
 *   EduSystem, con permiso de ver su evidencia; el titular; los firmantes de esa solicitud. Con la verificación
 *   retirada, la evidencia sin comprobar o una emisión posterior del mismo documento, solo el personal (y su copia lo
 *   dice). Sin firmantes exigidos ni firmas (solo se rellenó) no hay certificado, como en el PDF final.
 * - Documentos de identidad: el titular y el personal los ven como en el PDF final (B3); cada firmante, el suyo completo
 *   y los de los demás enmascarados (decisión del dueño, 2026-10-06).
 * - Comprobación en el servidor en cada petición, con nonce y como mucho 10 por hora y usuario.
 */

if (!defined('ABSPATH')) exit;

const SQUUAD_CERT_CERTIFICATE_COPY_PER_HOUR = 10;

/**
 * Relación del usuario con una solicitud completada: 'staff', 'holder', 'signer' o '' (sin acceso). Con la
 * verificación retirada, solo 'staff'.
 */
function squuad_cert_signature_certificate_access(object $request, int $user_id): string
{
    global $wpdb;

    if ($user_id <= 0 || 'completed' !== (string) $request->status
        || (!squuad_cert_signature_request_required_roles($request) && !squuad_cert_signature_request_rows((int) $request->id))) {
        return '';
    }
    $staff = user_can($user_id, 'manager_certificates');
    if (!$staff && SQUUAD_CERT_SUBJECT_STUDENT === (string) $request->subject_type && $user_id === get_current_user_id()) {
        $provider = function_exists('squuad_cert_subject_type') ? squuad_cert_subject_type(SQUUAD_CERT_SUBJECT_STUDENT) : null;
        $staff = $provider && !empty($provider['can_act']) && (bool) call_user_func($provider['can_act'], $user_id, (int) $request->subject_id, 'view_evidence');
    }
    if ($staff) {
        return 'staff';
    }
    // Para el titular y los firmantes: ni retirada, ni reemplazada, ni con la evidencia sin comprobar
    if ('' !== squuad_cert_signature_certificate_warning($request)) {
        return '';
    }
    if (function_exists('squuad_cert_subject_account_id') && squuad_cert_subject_account_id((string) $request->subject_type, (int) $request->subject_id) === $user_id) {
        return 'holder';
    }
    foreach (squuad_cert_request_signers($request) as $signer) {
        if ((int) $signer['user_id'] === $user_id) {
            return squuad_cert_is_holder_slot((string) $signer['slot_key']) ? 'holder' : 'signer';
        }
    }
    $signed = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}squuad_cert_signatures WHERE request_id = %d AND user_id = %d",
        (int) $request->id,
        $user_id
    ));

    return $signed > 0 ? 'signer' : '';
}

/**
 * Aviso de la copia cuando la solicitud ya no vale como documento vigente (solo el personal puede descargarla así):
 * verificación retirada, emisión posterior del mismo documento o evidencia que no se pudo comprobar. '' si nada.
 */
function squuad_cert_signature_certificate_warning(object $request): string
{
    if (function_exists('squuad_cert_verify_withdrawn') && squuad_cert_verify_withdrawn($request)) {
        return __('Its public verification was withdrawn by the institution.', 'wp-certificates');
    }
    if (function_exists('squuad_cert_verify_page_integrity') && function_exists('squuad_cert_verify_page_state')) {
        $state = squuad_cert_verify_page_state($request, squuad_cert_verify_page_integrity($request));
        if ('replaced' === $state) {
            return __('Replaced by a later issue: it is not the current document.', 'wp-certificates');
        }
        if ('valid' !== $state) {
            return __('The evidence of this document could not be verified.', 'wp-certificates');
        }
    }

    return '';
}

/** Enlace de descarga del certificado de firmas de una solicitud (para el usuario actual). */
function squuad_cert_signature_certificate_url(int $request_id): string
{
    return add_query_arg([
        'action' => 'squuad_cert_signature_certificate',
        'request_id' => $request_id,
        '_wpnonce' => wp_create_nonce('squuad_cert_signature_certificate_' . $request_id),
    ], admin_url('admin-ajax.php'));
}

/** HTML del certificado para quien lo descarga: marca de copia y documentos de identidad según su relación. */
function squuad_cert_signature_certificate_copy_html(object $request, string $access, int $user_id): string
{
    $note = sprintf(
        /* translators: 1: date and time, 2: SHA-256 fingerprint of the signed PDF */
        __('Copy generated on %1$s from the sealed evidence. It is not the signed document; the fingerprint of the signed PDF is %2$s.', 'wp-certificates'),
        squuad_cert_signature_local_time(gmdate('Y-m-d H:i:s')),
        (string) $request->final_pdf_sha256
    );
    $warning = squuad_cert_signature_certificate_warning($request);
    if ('' !== $warning) {
        $note .= ' ' . $warning;
    }

    // Documentos de identidad: el titular y el personal, completos como en el PDF final (B3); un firmante, solo el suyo
    return squuad_cert_signature_certificate_html($request, false, 'signer' === $access ? $user_id : null, $note);
}

add_action('wp_ajax_squuad_cert_signature_certificate', 'squuad_cert_signature_certificate_download');
/** Descarga del certificado de firmas aparte: PDF del servidor o página para imprimir. */
function squuad_cert_signature_certificate_download(): void
{
    $request_id = absint($_GET['request_id'] ?? 0);
    $deny = static function (string $message, int $status): void {
        wp_die(esc_html($message), esc_html__('Certificate of signatures', 'wp-certificates'), ['response' => $status]);
    };
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash((string) ($_GET['_wpnonce'] ?? ''))), 'squuad_cert_signature_certificate_' . $request_id)) {
        $deny(__('The link has expired. Reload the page and try again.', 'wp-certificates'), 403);
    }
    $user_id = get_current_user_id();
    $request = squuad_cert_signature_request_get($request_id);
    $access = $request ? squuad_cert_signature_certificate_access($request, $user_id) : '';
    if ('' === $access) {
        $deny(__('Sorry, you are not allowed to access this page.', 'wp-certificates'), 403);
    }
    // Ventana fija de una hora desde la primera descarga (la caducidad no se alarga con cada una)
    $rate_key = 'squuad_cert_cert_copy_' . $user_id;
    $window = get_transient($rate_key);
    $window = is_array($window) && isset($window['start'], $window['count']) ? $window : ['start' => time(), 'count' => 0];
    if ((int) $window['count'] >= SQUUAD_CERT_CERTIFICATE_COPY_PER_HOUR) {
        $deny(__('Too many downloads in a short time. Try again later.', 'wp-certificates'), 429);
    }
    $window['count'] = (int) $window['count'] + 1;
    set_transient($rate_key, $window, max(60, (int) $window['start'] + HOUR_IN_SECONDS - time()));

    $html = squuad_cert_signature_certificate_copy_html($request, $access, $user_id);
    $filename = 'certificado-de-firmas-' . strtolower(squuad_cert_signature_request_code($request)) . '.pdf';
    squuad_cert_log(sprintf('Certificado de firmas de la solicitud %d descargado aparte por el usuario %d (%s)', $request_id, $user_id, $access), 'signature_certificate');

    if (function_exists('squuad_cert_final_pdf_engine') && 'servicio' === squuad_cert_final_pdf_engine($request) && function_exists('squuad_cert_pdf_render')) {
        $result = squuad_cert_pdf_render(
            squuad_cert_pdf_payload($html, squuad_cert_pdf_page(['unit' => 'mm', 'format' => 'a4', 'orientation' => 'portrait'], 10)),
            'certificado de firmas de la solicitud ' . $request_id,
            20
        );
        if (!is_wp_error($result)) {
            squuad_cert_pdf_send_inline($result['pdf'], $filename, 'attachment');
        }
    }
    squuad_cert_signature_certificate_print_page($html);
}

/** Respaldo sin servidor de PDF: la página del certificado para imprimir o guardar como PDF desde el navegador. */
function squuad_cert_signature_certificate_print_page(string $html): void
{
    $nonce = base64_encode(random_bytes(16));
    nocache_headers();
    header('X-Robots-Tag: noindex, nofollow');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; script-src 'nonce-{$nonce}'; style-src-elem 'nonce-{$nonce}'; style-src-attr 'unsafe-inline'; img-src 'self' data:; base-uri 'none'; frame-ancestors 'none'");
    $html = function_exists('squuad_cert_pdf_qr_inline') ? squuad_cert_pdf_qr_inline($html) : $html;
    ?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= esc_html__('Certificate of signatures', 'wp-certificates') ?></title>
    <style nonce="<?= esc_attr($nonce) ?>">
        @page { size: A4 portrait; margin: 10mm; }
        body { margin: 0; background: #f0f0f1; }
        .sheet { max-width: 794px; margin: 16px auto; background: #fff; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,.15); }
        .bar { max-width: 794px; margin: 16px auto 0; text-align: right; font-family: Arial, sans-serif; }
        .bar button { padding: 8px 16px; font-size: 14px; cursor: pointer; }
        @media print { body { background: #fff; } .bar { display: none; } .sheet { box-shadow: none; margin: 0; padding: 0; max-width: none; } }
    </style>
</head>
<body>
    <div class="bar"><button type="button" id="wpc-print"><?= esc_html__('Print or save as PDF', 'wp-certificates') ?></button></div>
    <main class="sheet"><?= $html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML del certificado construido y escapado en el servidor ?></main>
    <script nonce="<?= esc_attr($nonce) ?>">document.getElementById("wpc-print").addEventListener("click", function () { window.print(); });</script>
</body>
</html><?php
    exit;
}

/**
 * Documentos firmados (completados) en los que participó la cuenta, los más recientes primero, con acceso al
 * certificado de firmas. [['request', 'title', 'code', 'completed']]
 */
function squuad_cert_signature_certificates_for_user(int $user_id, int $limit = 20): array
{
    global $wpdb;

    if ($user_id <= 0 || !squuad_cert_signature_requests_enabled()) {
        return [];
    }
    $newer = "NOT EXISTS (SELECT 1 FROM {$wpdb->prefix}squuad_cert_requests n WHERE n.subject_type = r.subject_type
              AND n.subject_id = r.subject_id AND n.document_id = r.document_id AND n.round > r.round)";
    // Titular: su cuenta o su ficha de estudiante (documentos emitidos); firmante: puesto fijado o firma dada
    $student_id = function_exists('squuad_cert_account_student_id') ? squuad_cert_account_student_id($user_id) : 0;
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT r.id FROM {$wpdb->prefix}squuad_cert_requests r
         WHERE r.status = 'completed' AND {$newer} AND (
             (r.subject_type = %s AND r.subject_id = %d)
             OR (r.subject_type = %s AND r.subject_id = %d)
             OR EXISTS (SELECT 1 FROM {$wpdb->prefix}squuad_cert_request_signers rs WHERE rs.request_id = r.id AND rs.user_id = %d)
             OR EXISTS (SELECT 1 FROM {$wpdb->prefix}squuad_cert_signatures s WHERE s.request_id = r.id AND s.user_id = %d))
         ORDER BY r.completed_at_utc DESC, r.id DESC LIMIT %d",
        SQUUAD_CERT_SUBJECT_ACCOUNT,
        $user_id,
        SQUUAD_CERT_SUBJECT_STUDENT,
        $student_id > 0 ? $student_id : -1,
        $user_id,
        $user_id,
        max(1, $limit) * 3
    ));
    $list = [];
    foreach ($ids as $id) {
        if (count($list) >= $limit) {
            break;
        }
        $request = squuad_cert_signature_request_get((int) $id);
        if (!$request || '' === squuad_cert_signature_certificate_access($request, $user_id)) {
            continue;
        }
        $list[] = [
            'request' => $request,
            'title' => (string) $wpdb->get_var($wpdb->prepare("SELECT title FROM {$wpdb->prefix}documents_certificates WHERE id = %d", (int) ($request->document_certificate_id ?? 0))),
            'code' => squuad_cert_signature_request_code($request),
            'completed' => squuad_cert_signature_local_time((string) $request->completed_at_utc),
        ];
    }

    return $list;
}

/** Lista «Documentos firmados» con el enlace al certificado de firmas (bandeja del firmante y Mi Cuenta). */
function squuad_cert_signature_certificates_list(int $user_id, bool $admin = false): void
{
    $list = squuad_cert_signature_certificates_for_user($user_id);
    if (!$list) {
        return;
    }
    ?>
    <section class="wpc-signed-documents">
        <h2><?= esc_html__('Signed documents', 'wp-certificates') ?></h2>
        <p class="description"><?= esc_html__('Documents you took part in that are completed. The certificate of signatures shows who signed, when and how.', 'wp-certificates') ?></p>
        <table class="<?= $admin ? 'widefat striped' : 'shop_table shop_table_responsive' ?>">
            <thead><tr>
                <th><?= esc_html__('Document', 'wp-certificates') ?></th>
                <th><?= esc_html__('Code', 'wp-certificates') ?></th>
                <th><?= esc_html__('Completed', 'wp-certificates') ?></th>
                <th><span class="screen-reader-text"><?= esc_html__('Actions', 'wp-certificates') ?></span></th>
            </tr></thead>
            <tbody>
                <?php foreach ($list as $item) : ?>
                    <tr>
                        <td data-title="<?= esc_attr__('Document', 'wp-certificates') ?>"><?= esc_html('' !== $item['title'] ? $item['title'] : (string) $item['request']->document_id) ?></td>
                        <td data-title="<?= esc_attr__('Code', 'wp-certificates') ?>"><?= esc_html($item['code']) ?></td>
                        <td data-title="<?= esc_attr__('Completed', 'wp-certificates') ?>"><?= esc_html($item['completed']) ?></td>
                        <td><a class="button" href="<?= esc_url(squuad_cert_signature_certificate_url((int) $item['request']->id)) ?>" aria-label="<?= esc_attr(sprintf(/* translators: %s: code of the document */ __('Certificate of signatures of %s', 'wp-certificates'), $item['code'])) ?>"><?= esc_html__('Certificate of signatures', 'wp-certificates') ?></a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <?php
}

// Mi Cuenta (escritorio), después de «Documentos por firmar»
add_action('woocommerce_account_dashboard', static function (): void {
    if (is_user_logged_in()) {
        squuad_cert_signature_certificates_list(get_current_user_id());
    }
}, 5);
