<?php
declare(strict_types=1);

/**
 * Formulario «Documento de identidad» de la propia persona (ADR 0007): es lo primero que ve quien tiene algo que
 * firmar y aún no tiene documento, con «Pedir documento de identidad» encendido. Lo incluye
 * squuad_cert_id_document_render_self_form(). Variables: $context ('modal', 'inline' o 'admin'), $flash (aviso del
 * último envío o null; sin el número escrito), $prefix_id (prefijo de los ids), $redirect (adónde volver después de
 * guardar) y $color_mode (modo de Edusof UI para el modal: light, dark o auto).
 *
 * Se envía a admin-post.php (squuad_cert_id_document_handle_self), que valida todo en el servidor.
 */

defined('ABSPATH') || exit;

$squuad_cert_iddoc_type = (int) ($flash['type_id'] ?? 0);
$squuad_cert_iddoc_number = ''; // B3: el número no se guarda en el aviso; se vuelve a escribir
$squuad_cert_iddoc_mode = in_array($color_mode ?? 'auto', ['light', 'dark', 'auto'], true) ? $color_mode : 'auto';
$squuad_cert_iddoc_title_id = $prefix_id . '-title';
$squuad_cert_iddoc_admin = 'admin' === $context;
$squuad_cert_iddoc_types = squuad_cert_id_document_types(true);

// Estilos una sola vez por página (puede haber dos formularios: el modal y el de «Documentos por firmar»)
if (!$squuad_cert_iddoc_admin && !defined('SQUUAD_CERT_IDDOC_STYLES_PRINTED')) {
    define('SQUUAD_CERT_IDDOC_STYLES_PRINTED', true); ?>
    <style>
        .squuad-cert-iddoc { --sc-bg: #fff; --sc-ink: #1c2430; --sc-muted: #4a5466; --sc-line: #cbd2dd; --sc-acc: var(--primary-color, #2347c6); --sc-bad-bg: #fbe4e2; --sc-bad-fg: #9b1c14; --sc-ok-bg: #e3f4ea; --sc-ok-fg: #1d6b42; color: var(--sc-ink); font-size: 15px; line-height: 1.5; }
        /* Modal: el modo de Edusof UI (oscuro fijo, o según el dispositivo con «auto»); con «light», siempre claro */
        .squuad-cert-iddoc.squuad-cert-iddoc--modal.squuad-cert-iddoc--dark { --sc-bg: #171d26; --sc-ink: #e6eaf0; --sc-muted: #aab3c2; --sc-line: #3a4556; --sc-acc: #8aa6ff; --sc-bad-bg: #3b1715; --sc-bad-fg: #ffb4ab; --sc-ok-bg: #12321f; --sc-ok-fg: #8fdcaa; }
        @media (prefers-color-scheme: dark) {
            .squuad-cert-iddoc.squuad-cert-iddoc--modal.squuad-cert-iddoc--auto { --sc-bg: #171d26; --sc-ink: #e6eaf0; --sc-muted: #aab3c2; --sc-line: #3a4556; --sc-acc: #8aa6ff; --sc-bad-bg: #3b1715; --sc-bad-fg: #ffb4ab; --sc-ok-bg: #12321f; --sc-ok-fg: #8fdcaa; }
        }
        .squuad-cert-iddoc--modal { position: fixed; inset: 0; z-index: 100000; overflow: auto; padding: 16px; box-sizing: border-box; background: rgba(15, 20, 27, .6); }
        .squuad-cert-iddoc__card { box-sizing: border-box; width: 100%; max-width: 560px; margin: 8vh auto; padding: 24px; background: var(--sc-bg); color: var(--sc-ink); border-radius: 12px; box-shadow: 0 12px 32px rgba(0, 0, 0, .25); }
        .squuad-cert-iddoc--inline .squuad-cert-iddoc__card { margin: 0 0 24px; max-width: none; border: 1px solid var(--sc-line); box-shadow: none; }
        .squuad-cert-iddoc__card h3 { margin: 0 0 8px; font-size: 20px; font-weight: 600; color: var(--sc-ink); }
        .squuad-cert-iddoc__card p { margin: 0 0 12px; color: var(--sc-muted); }
        .squuad-cert-iddoc__close { float: right; padding: 4px; border: 0; background: none; color: var(--sc-muted); cursor: pointer; font-size: 22px; line-height: 1; }
        .squuad-cert-iddoc__fields { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 12px 16px; margin: 16px 0 8px; }
        @media (max-width: 600px) { .squuad-cert-iddoc__fields { grid-template-columns: minmax(0, 1fr); } }
        .squuad-cert-iddoc-field { display: flex; flex-direction: column; gap: 6px; font-weight: 600; font-size: 14px; }
        .squuad-cert-iddoc-field select, .squuad-cert-iddoc-field input { box-sizing: border-box; width: 100%; min-height: 42px; padding: 0 12px; border: 1px solid var(--sc-line); border-radius: 8px; background: var(--sc-bg); color: var(--sc-ink); font-size: 15px; font-weight: 400; }
        .squuad-cert-iddoc-field select:focus, .squuad-cert-iddoc-field input:focus { outline: 2px solid var(--sc-acc); outline-offset: 1px; }
        .squuad-cert-iddoc-help { grid-column: 1 / -1; font-size: 13px; color: var(--sc-muted); }
        .squuad-cert-iddoc__notice { margin: 0 0 12px; padding: 10px 12px; border-radius: 8px; background: var(--sc-bad-bg); color: var(--sc-bad-fg); }
        .squuad-cert-iddoc__notice--ok { background: var(--sc-ok-bg); color: var(--sc-ok-fg); }
        .squuad-cert-iddoc__actions { margin-top: 16px; text-align: center; }
        .squuad-cert-iddoc__submit { min-height: 42px; padding: 0 22px; border: 0; border-radius: 8px; background: var(--sc-acc); color: #fff; font-size: 15px; font-weight: 600; cursor: pointer; }
        .squuad-cert-iddoc--modal.squuad-cert-iddoc--dark .squuad-cert-iddoc__submit { color: #0f141b; }
        @media (prefers-color-scheme: dark) { .squuad-cert-iddoc--modal.squuad-cert-iddoc--auto .squuad-cert-iddoc__submit { color: #0f141b; } }
    </style>
<?php }

if ($squuad_cert_iddoc_admin) :
    // Con el diseño Edusof apagado, los componentes eds-* dentro de .eds-scope (como la pantalla Apariencia)
    $squuad_cert_iddoc_scope = squuad_cert_id_document_eds_scope(); ?>
    <div class="<?= esc_attr($squuad_cert_iddoc_scope) ?>">
    <section class="eds-card squuad-cert-iddoc-admin" aria-labelledby="<?= esc_attr($squuad_cert_iddoc_title_id) ?>" style="max-width: 640px; margin: 16px 0">
        <h2 id="<?= esc_attr($squuad_cert_iddoc_title_id) ?>"><?= esc_html__('Identity document', 'wp-certificates') ?></h2>
        <?php if ($flash) : ?>
            <div class="eds-notice <?= !empty($flash['ok']) ? 'eds-notice--ok' : 'eds-notice--bad' ?> notice <?= !empty($flash['ok']) ? 'notice-success' : 'notice-error' ?> inline" role="alert"><p><?= esc_html((string) $flash['message']) ?></p></div>
        <?php endif; ?>
        <p><?= esc_html__('Before reviewing and signing documents, register your identity document. It will be recorded with each signature you make.', 'wp-certificates') ?></p>
        <?php if (!$squuad_cert_iddoc_types) : ?>
            <div class="eds-notice eds-notice--warn notice notice-warning inline"><p><?= esc_html__('There are no active document types. Contact the administration.', 'wp-certificates') ?></p></div>
        <?php else : ?>
            <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" class="squuad-cert-iddoc-admin__form">
                <input type="hidden" name="action" value="squuad_cert_save_id_document">
                <input type="hidden" name="redirect_to" value="<?= esc_attr($redirect) ?>">
                <?php wp_nonce_field('squuad_cert_save_id_document', '_squuad_cert_iddoc_nonce'); ?>
                <div class="eds-filters squuad-cert-iddoc-admin__fields">
                    <?= str_replace('squuad-cert-iddoc-field"', 'squuad-cert-iddoc-field eds-field"', squuad_cert_id_document_fields_html($prefix_id, $squuad_cert_iddoc_type, $squuad_cert_iddoc_number)) ?>
                </div>
                <p class="description"><?= esc_html__('You can correct it until your first signature; after that, only the administration can change it.', 'wp-certificates') ?></p>
                <p><button type="submit" class="eds-btn eds-btn--primary button button-primary"><?= esc_html__('Save and continue', 'wp-certificates') ?></button></p>
            </form>
        <?php endif; ?>
    </section>
    </div>
<?php else : ?>
    <?php if ('modal' === $context) : ?>
        <input class="formdata" autocomplete="off" type="hidden" id="modal_open" name="modal_open" value="1">
    <?php endif; ?>
    <div class="squuad-cert-iddoc squuad-cert-iddoc--<?= esc_attr($context) ?> squuad-cert-iddoc--<?= esc_attr($squuad_cert_iddoc_mode) ?>" id="<?= esc_attr($prefix_id) ?>"<?= 'modal' === $context ? ' role="dialog" aria-modal="true"' : '' ?> aria-labelledby="<?= esc_attr($squuad_cert_iddoc_title_id) ?>">
        <div class="squuad-cert-iddoc__card">
            <?php if ('modal' === $context) : ?>
                <button type="button" class="squuad-cert-iddoc__close" aria-label="<?= esc_attr__('Close', 'wp-certificates') ?>"
                    onclick="document.getElementById(<?= esc_attr(wp_json_encode($prefix_id)) ?>).style.display='none';document.body.classList.remove('modal-open');">&times;</button>
            <?php endif; ?>
            <h3 id="<?= esc_attr($squuad_cert_iddoc_title_id) ?>"><?= esc_html__('Identity document', 'wp-certificates') ?></h3>
            <?php if ($flash) : ?>
                <div class="squuad-cert-iddoc__notice<?= !empty($flash['ok']) ? ' squuad-cert-iddoc__notice--ok' : '' ?>" role="alert"><?= esc_html((string) $flash['message']) ?></div>
            <?php endif; ?>
            <p><?= esc_html__('Before reviewing and signing your documents, register your identity document. It will be recorded with each signature you make.', 'wp-certificates') ?></p>
            <?php if (!$squuad_cert_iddoc_types) : ?>
                <div class="squuad-cert-iddoc__notice" role="alert"><?= esc_html__('There are no active document types. Contact the administration.', 'wp-certificates') ?></div>
            <?php else : ?>
                <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
                    <input type="hidden" name="action" value="squuad_cert_save_id_document">
                    <input type="hidden" name="redirect_to" value="<?= esc_attr($redirect) ?>">
                    <?php wp_nonce_field('squuad_cert_save_id_document', '_squuad_cert_iddoc_nonce'); ?>
                    <div class="squuad-cert-iddoc__fields">
                        <?= squuad_cert_id_document_fields_html($prefix_id, $squuad_cert_iddoc_type, $squuad_cert_iddoc_number) ?>
                    </div>
                    <p><small><?= esc_html__('You can correct it until your first signature; after that, only the administration can change it.', 'wp-certificates') ?></small></p>
                    <div class="squuad-cert-iddoc__actions">
                        <button type="submit" class="squuad-cert-iddoc__submit"><?= esc_html__('Save and continue', 'wp-certificates') ?></button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php endif;
