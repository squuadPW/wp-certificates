<?php
/**
 * Mi Cuenta: documento en solo lectura (ADR 0012 de Edusof). Quien recibe el documento lo ve mientras firma otra persona
 * antes (p. ej. el estudiante mientras firma su representante) o después de firmar, mientras faltan otras firmas. Sin
 * botones de firma ni formularios. Variables (de squuad_cert_signature_account_documents_to_sign): $view_request,
 * $view_document, $view_html (contenido con los puestos pendientes, o '' si aún no está preparado), $view_waiting
 * (firmantes con el turno abierto, de squuad_cert_signature_signers_overview()), $view_state (queued: aún no le toca;
 * waiting: ya hizo su parte) y $dashboard.
 */
if (!defined('ABSPATH')) exit;

$eds_theme = class_exists('Edusof_UI') ? Edusof_UI::theme() : 'azul';
$eds_mode = class_exists('Edusof_UI') && Edusof_UI::enabled() ? Edusof_UI::mode() : 'auto';
$waiting_names = implode(', ', array_map(static fn(array $signer): string => '' !== $signer['name'] ? $signer['name'] . ' (' . $signer['label'] . ')' : $signer['label'], $view_waiting));
?>
<section class="wpc-doc-readonly eds-scope eds-theme-<?= esc_attr($eds_theme) ?> eds-mode-<?= esc_attr($eds_mode) ?>" aria-labelledby="wpc-doc-readonly-title" style="margin-bottom:24px">
    <h3 id="wpc-doc-readonly-title"><?= esc_html((string) $view_document->title) ?></h3>
    <p class="wpc-sign-notice">
        <?php if ('' !== $waiting_names && 'waiting' === $view_state) : ?>
            <?= esc_html(sprintf(
                /* translators: %s: names of the people who have to sign now */
                __('Read only. You already did your part. Waiting for the signature of: %s.', 'wp-certificates'),
                $waiting_names
            )) ?>
        <?php elseif ('' !== $waiting_names) : ?>
            <?= esc_html(sprintf(
                /* translators: %s: names of the people who have to sign now */
                __('Read only. Waiting for the signature of: %s. When it is your turn, the document will open in your account so you can sign it.', 'wp-certificates'),
                $waiting_names
            )) ?>
        <?php else : ?>
            <?= esc_html__('Read only. This document is waiting for other signatures.', 'wp-certificates') ?>
        <?php endif; ?>
    </p>
    <?php if ('' !== $view_html) : ?>
        <div style="max-height:70vh;overflow:auto;border:1px solid var(--eds-line, #dcdcde);border-radius:6px;padding:12px;background:var(--eds-surface-2, #f6f7f7)">
            <article class="wpc-sign-paper" aria-label="<?= esc_attr__('Document', 'wp-certificates') ?>">
                <?= $view_html // phpcs:ignore -- contenido de la solicitud (escapado al generarlo) con los puestos de firma pendientes ?>
            </article>
        </div>
    <?php else : ?>
        <p><?= esc_html__('The document is not ready yet: the person who signs first has to open it.', 'wp-certificates') ?></p>
    <?php endif; ?>
    <p><a class="woocommerce-button button" href="<?= esc_url($dashboard) ?>"><?= esc_html__('Close', 'wp-certificates') ?></a></p>
</section>
