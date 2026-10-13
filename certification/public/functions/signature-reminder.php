<?php
declare(strict_types=1);

/**
 * Documentos por firmar en Mi Cuenta: cuándo se abre sola la ventana y el aviso fijo (decisión del dueño de Edusof del
 * 12/10/2026, con el experto en administración educativa y el de UX).
 *
 * - Los documentos automáticos piden atención: mientras a una cuenta le toca firmar o rellenar uno, Mi Cuenta lo
 *   recuerda con un AVISO FIJO arriba del contenido, en todas sus páginas, que no se puede cerrar («Firmar ahora»).
 * - La VENTANA se abre sola como mucho una vez por inicio de sesión, y solo en el escritorio de Mi Cuenta (nunca encima
 *   de otra pantalla de Mi Cuenta ni del pago). «Firmar más tarde» la cierra; en el siguiente inicio de sesión vuelve.
 * - Ajuste del sitio (Certificación › Configuración › Firma de documentos): «una vez por inicio de sesión» (por
 *   defecto) o «solo al pulsar Firmar».
 * - Abrirla a propósito (?squuad_cert_sign=…, el formulario de campos enviado) funciona siempre, en cualquier modo.
 * Solo ve el aviso y la ventana quien tiene el turno (squuad_cert_signature_user_documents, estado to_sign).
 */

defined('ABSPATH') || exit;

const SQUUAD_CERT_SIGNATURE_AUTO_OPEN_OPTION = 'squuad_cert_signature_auto_open';
const SQUUAD_CERT_SIGNATURE_AUTO_OPEN_META = '_squuad_cert_auto_opened_session';

/** Modo del sitio: 'session' (una vez por inicio de sesión, por defecto) o 'manual' (solo al pulsar Firmar). */
function squuad_cert_signature_auto_open_mode(): string
{
    return 'manual' === get_option(SQUUAD_CERT_SIGNATURE_AUTO_OPEN_OPTION, 'session') ? 'manual' : 'session';
}

/** ¿La persona pidió abrir la ventana (enlace «Firmar» o formulario de campos enviado)? Entonces se abre siempre. */
function squuad_cert_signature_explicit_open(): bool
{
    return isset($_GET['squuad_cert_sign']) || isset($_POST['squuad_cert_document_fields']); // phpcs:ignore WordPress.Security.NonceVerification -- solo decide si se pinta; el formulario valida su nonce
}

/** Huella de la sesión actual de WordPress (cambia en cada inicio de sesión). */
function squuad_cert_signature_session_hash(): string
{
    $token = function_exists('wp_get_session_token') ? (string) wp_get_session_token() : '';
    return '' === $token ? '' : hash('sha256', $token);
}

/**
 * ¿Puede abrirse sola aquí y ahora? Modo «una vez por sesión», todavía no abierta en esta sesión y en el escritorio de
 * Mi Cuenta. El filtro squuad_cert_signature_auto_open_here permite a otro plugin o tema cambiar las páginas.
 */
function squuad_cert_signature_auto_open_here(): bool
{
    if ('session' !== squuad_cert_signature_auto_open_mode()) {
        return false;
    }
    $session = squuad_cert_signature_session_hash();
    if ('' !== $session && in_array($session, (array) get_user_meta(get_current_user_id(), SQUUAD_CERT_SIGNATURE_AUTO_OPEN_META, true), true)) {
        return false;
    }
    $dashboard = function_exists('is_account_page') && is_account_page() && function_exists('is_wc_endpoint_url') && !is_wc_endpoint_url();
    return (bool) apply_filters('squuad_cert_signature_auto_open_here', $dashboard);
}

/**
 * Marca que la ventana ya se abrió sola en esta sesión. Guarda las 5 últimas sesiones, para que quien alterna entre el
 * móvil y el ordenador no la vea otra vez en cada cambio. Sin token de sesión (no pasa en el front con cookie) no se
 * marca y la ventana se abriría en cada visita al escritorio.
 */
function squuad_cert_signature_mark_auto_opened(): void
{
    $session = squuad_cert_signature_session_hash();
    if ('' === $session) {
        return;
    }
    $user_id = get_current_user_id();
    $seen = array_values(array_filter((array) get_user_meta($user_id, SQUUAD_CERT_SIGNATURE_AUTO_OPEN_META, true), 'is_string'));
    $seen = array_slice(array_values(array_unique(array_merge([$session], $seen))), 0, 5);
    update_user_meta($user_id, SQUUAD_CERT_SIGNATURE_AUTO_OPEN_META, $seen);
}

/** Documentos que le toca firmar o rellenar a la cuenta conectada (para el aviso). */
function squuad_cert_signature_pending_items(): array
{
    if (!is_user_logged_in() || !function_exists('squuad_cert_signature_user_documents') || !squuad_cert_signature_requests_enabled()) {
        return [];
    }
    $user = wp_get_current_user();
    return array_values(array_filter(squuad_cert_signature_user_documents($user), static fn(array $item): bool => 'to_sign' === $item['state']));
}

/** Aviso fijo arriba del contenido de Mi Cuenta: no se puede cerrar y lleva a la firma. */
add_action('woocommerce_account_content', 'squuad_cert_signature_pending_banner', 5);
function squuad_cert_signature_pending_banner(): void
{
    if (!squuad_cert_modal_page() || !apply_filters('squuad_cert_show_signature_banner', true)) {
        return;
    }
    $items = squuad_cert_signature_pending_items();
    if (!$items) {
        return;
    }
    $base = function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url('dashboard') : home_url('/');
    $user_id = get_current_user_id();
    ?>
    <div class="wpc-pending-banner" role="region" aria-labelledby="wpc-pending-banner-title">
        <p class="wpc-pending-banner__title" id="wpc-pending-banner-title"><strong><?= esc_html(sprintf(
            /* translators: %d: number of documents */
            _n('You have %d required document to sign', 'You have %d required documents to sign', count($items), 'wp-certificates'),
            count($items)
        )) ?></strong></p>
        <ul class="wpc-pending-banner__list">
            <?php foreach (array_slice($items, 0, 5) as $item) :
                $holder = (int) $item['subject_id'] !== $user_id ? get_userdata((int) $item['subject_id']) : null;
                $label = $holder
                    /* translators: 1: document title, 2: name of the person the document belongs to */
                    ? sprintf(__('%1$s of %2$s', 'wp-certificates'), (string) $item['document']->title, (string) $holder->display_name)
                    : (string) $item['document']->title;
                $url = add_query_arg('squuad_cert_sign', (int) $item['subject_id'] . '-' . (int) $item['document']->id, $base);
                ?>
                <li><span><?= esc_html($label) ?></span> <a class="wpc-pending-banner__sign" href="<?= esc_url($url) ?>"><?= esc_html__('Sign now', 'wp-certificates') ?></a></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php
}

add_action('wp_head', 'squuad_cert_signature_pending_banner_style');
function squuad_cert_signature_pending_banner_style(): void
{
    if (!squuad_cert_modal_page()) {
        return;
    }
    ?>
    <style id="wpc-pending-banner-css">
        .wpc-pending-banner{border:1px solid var(--eds-warn-border,#e6c200);background:var(--eds-warn-bg,#fff8db);color:var(--eds-text,#1d2327);border-radius:var(--eds-radius,8px);padding:12px 16px;margin:0 0 16px}
        .wpc-pending-banner__title{margin:0 0 6px}
        .wpc-pending-banner__list{margin:0;padding:0;list-style:none;display:grid;gap:6px}
        .wpc-pending-banner__list li{display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between}
        .wpc-pending-banner__sign{font-weight:600;white-space:nowrap}
    </style>
    <?php
}
