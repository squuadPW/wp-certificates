<?php
/**
 * Certificación: recuadro de firma del estudiante en los documentos (squuad_cert_get_signature_section,
 * squuad_cert_signature_pad_box). Movido desde includes/html-documents.php de EduSystem (ADR 0004, paso 3c).
 *
 * Firma solo el estudiante, con su cuenta de WordPress (decisión del dueño, 2026-10-01): el recuadro muestra el nombre
 * de esa cuenta, el mismo que queda en el documento firmado. No se consulta la ficha de EduSystem: el nombre es el
 * fijado en la solicitud o, sin solicitud, el de la cuenta conectada (la dueña del documento).
 */

if (!defined('ABSPATH')) exit;

/** Nombre de la cuenta que ocupa un puesto: el fijado en la solicitud o, sin solicitud, el de la cuenta conectada. */
function squuad_cert_signature_holder_name(string $slot_key, ?object $request = null): string
{
    if ($request) {
        foreach (squuad_cert_request_signers($request) as $signer) {
            if ($slot_key === $signer['slot_key']) {
                return (string) $signer['name'];
            }
        }
    }

    return squuad_cert_account_name(get_current_user_id());
}

/** {{signature_section}}: el recuadro de quien recibe el documento (el único puesto que firma desde Mi Cuenta). */
function squuad_cert_get_signature_section(?object $request = null): string
{
    $holder = $request ? squuad_cert_request_holder_slot($request) : '';

    return '' !== $holder ? squuad_cert_signature_pad_box($holder, $request) : '';
}

/**
 * Recuadro de firma de quien recibe el documento ($slot_key 'role:<rol>'), para colocarlo en la plantilla con
 * {{signature_role_<rol>}} o en {{signature_section}} (ADR 0003, variables de firma por firmante). Los firmantes del
 * sistema no tienen recuadro aquí: firman desde su bandeja. El nombre es el de la cuenta de WordPress.
 */
function squuad_cert_signature_pad_box(string $slot_key, ?object $request = null): string
{
    if (!squuad_cert_is_holder_slot($slot_key)) {
        return '';
    }
    $full_name = $short_name = squuad_cert_signature_holder_name($slot_key, $request);
    /* translators: %s: name of the role */
    $label = sprintf(__('Signature (%s):', 'wp-certificates'), squuad_cert_holder_slot_label($slot_key));
    // El recuadro conserva los ids «student» (signature-pad-student…) que usa create-enrollment.js para cualquier rol
    $role = 'student';
    ob_start();
    ?>
        <div class="signatures_squares">
            <input type="hidden" name="auto_signature_<?= esc_attr($role) ?>" value="0">
            <div class="signature_square_field">
                <div>
                    <div style="padding: 8px; text-align: center"><strong><?= esc_html($label) ?></strong>
                        <br> <?= esc_html($full_name) ?>
                    </div>
                </div>
                <div style="position: relative; padding: 8px;" id="signature-pad-<?= esc_attr($role) ?>">
                    <canvas id="signature-<?= esc_attr($role) ?>" width="100%" height="200"
                        style="border: 1px solid gray; margin: auto !important; background-color: #ffff005c"></canvas>
                    <div id="sign-here-<?= esc_attr($role) ?>"
                        style="pointer-events: none;position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-weight: bold; padding: 10px; color: #4f4e4e7a; font-size: 20px;">
                        <span><?= esc_html__('SIGN HERE', 'wp-certificates') ?></span>
                    </div>
                </div>
                <button id="clear-<?= esc_attr($role) ?>" style="width: 100%;"><?= esc_html__('Clear', 'wp-certificates') ?></button>
                <button id="generate-signature-<?= esc_attr($role) ?>" style="width: 100%;"
                    onclick="autoSignature('signature-pad-<?= esc_attr($role) ?>', 'signature-text-<?= esc_attr($role) ?>', 'generate-signature-<?= esc_attr($role) ?>', 'clear-<?= esc_attr($role) ?>')"><?= esc_html__('Generate signature automatically', 'wp-certificates') ?></button>
                <div style="position: relative; padding: 8px; text-align: center; width: 70%; margin: 8px auto; border-bottom: 1px solid gray; font-family: Great Vibes, cursive; font-size: 28px; display: block; height: 120px; display: none"
                    id="signature-text-<?= esc_attr($role) ?>">
                    <div style="bottom: 0; position: absolute; text-align: center; width: 100%;">
                        <?= esc_html($short_name) ?>
                    </div>
                </div>
                <button id="clear-<?= esc_attr($role) ?>-signature"
                    style="width: 100%; display: none"><?= esc_html__('Cancel', 'wp-certificates') ?></button>
            </div>
        </div>
    <?php

    return (string) ob_get_clean();
}
