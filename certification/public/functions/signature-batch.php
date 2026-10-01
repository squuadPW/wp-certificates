<?php
declare(strict_types=1);

/**
 * EduSystem - Firma en lote del estudiante y del representante en Mi Cuenta (ADR 0003, paso 6b).
 *
 * En "Documentos por firmar" del escritorio marcan los documentos que ya firmó otra persona (contenido congelado),
 * revisan la lista, dibujan su firma una vez, aceptan el consentimiento que nombra cada documento y confirman con su
 * contraseña. Cada documento recibe su propia firma (copia de los trazos) y su evento. Los documentos que quedan con
 * todas las firmas generan su PDF final en el navegador, uno por uno, desde el contenido congelado.
 */

if (!defined('ABSPATH')) exit;

/** URL del escritorio de Mi Cuenta con parámetros. */
function squuad_cert_signature_batch_account_url(array $args = []): string
{
    return add_query_arg($args, wc_get_account_endpoint_url('dashboard'));
}

/**
 * Aviso de una sola lectura para la siguiente carga de Mi Cuenta (admin-post.php no tiene la sesión de WooCommerce,
 * así que sus avisos no llegan a la página).
 */
function squuad_cert_signature_batch_notice(string $message, bool $ok): void
{
    set_transient('squuad_cert_batch_notice_' . get_current_user_id(), ['message' => $message, 'ok' => $ok], 300);
}

function squuad_cert_signature_batch_take_notice(): ?array
{
    $key = 'squuad_cert_batch_notice_' . get_current_user_id();
    $notice = get_transient($key);
    delete_transient($key);

    return is_array($notice) ? $notice : null;
}

/** Imprime el aviso pendiente con el estilo de avisos de WooCommerce. */
function squuad_cert_signature_batch_print_notice(): void
{
    $notice = squuad_cert_signature_batch_take_notice();
    if ($notice) {
        printf('<div class="%s" role="alert">%s</div>', $notice['ok'] ? 'woocommerce-message' : 'woocommerce-error', esc_html($notice['message']));
    }
}

add_action('admin_post_squuad_cert_holder_batch_prepare', 'squuad_cert_signature_handle_holder_batch_prepare');
function squuad_cert_signature_handle_holder_batch_prepare(): void
{
    check_admin_referer('squuad_cert_holder_batch_prepare');
    $result = squuad_cert_signature_batch_prepare(array_map('absint', (array) ($_POST['request_ids'] ?? [])), 'holder');
    if (!$result['ok']) {
        squuad_cert_signature_batch_notice($result['message'], false);
    }
    wp_safe_redirect(squuad_cert_signature_batch_account_url(array_filter(['squuad_cert_batch' => $result['batch_id']])));
    exit;
}

add_action('admin_post_squuad_cert_holder_batch_confirm', 'squuad_cert_signature_handle_holder_batch_confirm');
function squuad_cert_signature_handle_holder_batch_confirm(): void
{
    $batch_id = absint($_POST['batch_id'] ?? 0);
    check_admin_referer('squuad_cert_holder_batch_confirm_' . $batch_id);
    $strokes = is_string($_POST['strokes'] ?? null) ? json_decode(wp_unslash($_POST['strokes']), true) : null;
    $result = squuad_cert_signature_batch_confirm(
        $batch_id,
        (string) wp_unslash($_POST['password'] ?? ''), // phpcs:ignore -- contraseña: no se sanea
        sanitize_text_field(wp_unslash($_POST['consent_sha256'] ?? '')),
        $strokes
    );
    squuad_cert_signature_batch_notice($result['message'], $result['ok']);
    wp_safe_redirect(squuad_cert_signature_batch_account_url(['squuad_cert_batch' => $batch_id]));
    exit;
}

/**
 * Solicitudes con todas las firmas que este usuario (estudiante o representante firmante) puede cerrar generando
 * el PDF final. Con $only, solo esas.
 */
function squuad_cert_signature_account_pdf_requests(WP_User $user, array $only = []): array
{
    $requests = [];
    foreach (squuad_cert_signature_user_documents($user) as $item) {
        $request = $item['request'];
        if (!$request || 'signed' !== $request->status || ($only && !in_array((int) $request->id, $only, true))) {
            continue;
        }
        $html = squuad_cert_signature_request_render_final($request);
        if (null === $html) {
            continue;
        }
        $requests[] = ['request' => $request, 'title' => (string) $item['document']->title, 'html' => $html];
    }

    return $requests;
}
