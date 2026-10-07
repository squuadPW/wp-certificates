<?php
declare(strict_types=1);

/**
 * Certificación › Configuración, sección «Firma de documentos» (ADR 0011 de Edusof):
 * - «Permitir subir una imagen de la firma»: muestra u oculta la pestaña «Subir imagen» de la ventana de firma; con ella
 *   apagada, el servidor también rechaza el método imagen.
 * - Nota legal del «Certificado de firmas» (última página del PDF final): vacía, el texto neutro por defecto. La debería
 *   revisar el asesor legal de cada institución.
 * - «Adjuntar el certificado de firmas al PDF» (ADR 0014 de Edusof): valor del sitio, activado por defecto; cada
 *   documento puede seguirlo o cambiarlo en su ficha.
 *
 * Con el permiso de la pantalla (manager_configuration_certificates), nonce y registro en el log.
 */

defined('ABSPATH') || exit;

const SQUUAD_CERT_SIGNING_SETTINGS_CAP = 'manager_configuration_certificates';
const SQUUAD_CERT_SIGNING_LEGAL_NOTE_MAX = 1500;

add_action('admin_post_squuad_cert_signing_settings', 'squuad_cert_signing_settings_handle');
function squuad_cert_signing_settings_handle(): void
{
    if (!current_user_can(SQUUAD_CERT_SIGNING_SETTINGS_CAP)) {
        wp_die(esc_html__('Sorry, you are not allowed to access this page.', 'wp-certificates'), 403);
    }
    check_admin_referer('squuad_cert_signing_settings');

    $upload = !empty($_POST['allow_upload']) ? '1' : '0';
    $note = is_string($_POST['legal_note'] ?? null) ? sanitize_textarea_field(wp_unslash($_POST['legal_note'])) : '';
    $note = mb_substr(trim($note), 0, SQUUAD_CERT_SIGNING_LEGAL_NOTE_MAX);
    $sheet = !empty($_POST['signature_sheet']) ? 'yes' : 'no';
    $old_sheet = function_exists('squuad_cert_signature_sheet_site') ? squuad_cert_signature_sheet_site() : 'yes';
    $old_upload = (string) get_option(SQUUAD_CERT_SIGNING_UPLOAD_OPTION, '1');
    $old_note = (string) get_option(SQUUAD_CERT_SIGNING_LEGAL_NOTE_OPTION, '');

    update_option(SQUUAD_CERT_SIGNING_UPLOAD_OPTION, $upload, false);
    update_option(SQUUAD_CERT_SIGNING_LEGAL_NOTE_OPTION, $note, false);
    if (function_exists('squuad_cert_signature_sheet_site')) {
        update_option(SQUUAD_CERT_SIGNATURE_SHEET_OPTION, $sheet, false);
        if ($old_sheet !== $sheet) {
            squuad_cert_log(sprintf('Firma de documentos: certificado de firmas en el PDF %s en el sitio, por el usuario %d', 'yes' === $sheet ? 'activado' : 'desactivado', get_current_user_id()), 'signature_sheet');
        }
    }
    if ($old_upload !== $upload || $old_note !== $note) {
        squuad_cert_log(sprintf(
            'Firma de documentos: «Subir imagen» %s y nota legal %s, por el usuario %d',
            '1' === $upload ? 'permitida' : 'oculta',
            '' === $note ? 'por defecto' : 'propia (' . hash('sha256', $note) . ')',
            get_current_user_id()
        ), 'signing_settings');
    }
    set_transient('squuad_cert_signing_settings_notice_' . get_current_user_id(), 1, 5 * MINUTE_IN_SECONDS);
    wp_safe_redirect(admin_url('admin.php?page=add_admin_form_configuration_options_certificates_content') . '#squuad-cert-signing-settings');
    exit;
}

/** Sección «Firma de documentos» de Certificación › Configuración. */
function squuad_cert_signing_settings_section(): void
{
    if (!current_user_can(SQUUAD_CERT_SIGNING_SETTINGS_CAP)) {
        return;
    }
    $notice_key = 'squuad_cert_signing_settings_notice_' . get_current_user_id();
    $saved = (bool) get_transient($notice_key);
    if ($saved) {
        delete_transient($notice_key);
    }
    $upload = '0' !== (string) get_option(SQUUAD_CERT_SIGNING_UPLOAD_OPTION, '1');
    $note = (string) get_option(SQUUAD_CERT_SIGNING_LEGAL_NOTE_OPTION, '');
    $scope = function_exists('squuad_cert_id_document_eds_scope') ? squuad_cert_id_document_eds_scope() : '';
    $gd = squuad_cert_signature_gd_available();
    ?>
    <div class="<?= esc_attr($scope) ?>" style="margin-top: 24px">
    <section class="eds-card" id="squuad-cert-signing-settings" aria-labelledby="squuad-cert-signing-settings-title">
        <h2 id="squuad-cert-signing-settings-title"><?= esc_html__('Signing documents', 'wp-certificates') ?></h2>
        <?php if ($saved) : ?>
            <div class="eds-notice eds-notice--ok" role="status"><p><?= esc_html__('Settings saved.', 'wp-certificates') ?></p></div>
        <?php endif; ?>
        <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
            <input type="hidden" name="action" value="squuad_cert_signing_settings">
            <?php wp_nonce_field('squuad_cert_signing_settings'); ?>
            <div style="display: flex; gap: 16px; align-items: flex-start; justify-content: space-between; flex-wrap: wrap">
                <div style="flex: 1 1 420px">
                    <p style="margin: 0 0 4px"><strong id="squuad-cert-signing-upload-label"><?= esc_html__('Allow uploading an image of the signature', 'wp-certificates') ?></strong></p>
                    <p id="squuad-cert-signing-upload-help" style="margin: 0; color: var(--eds-muted, #50575e)"><?= esc_html__('In the signing window, the person can type or draw their signature and, with this option on, upload an image of their own signature (they must confirm that it is theirs). It is the weakest proof: turn it off if your institution does not accept it. When it is off, the tab is hidden and the server rejects uploaded images.', 'wp-certificates') ?></p>
                    <?php if ($gd && !squuad_cert_signature_typed_available()) : ?>
                        <p style="margin: 6px 0 0; color: var(--eds-bad-fg, #b32d2e)"><?= esc_html__('The GD extension of this server has no FreeType support: the «Type» tab is hidden and only drawn or uploaded signatures are accepted.', 'wp-certificates') ?></p>
                    <?php endif; ?>
                    <?php if (!$gd) : ?>
                        <p style="margin: 6px 0 0; color: var(--eds-bad-fg, #b32d2e)"><?= esc_html__('This server does not have the GD extension of PHP: uploaded images cannot be checked, so the tab stays hidden.', 'wp-certificates') ?></p>
                    <?php endif; ?>
                </div>
                <label class="eds-switch">
                    <input type="checkbox" role="switch" name="allow_upload" value="1" aria-labelledby="squuad-cert-signing-upload-label" aria-describedby="squuad-cert-signing-upload-help" <?php checked($upload); ?>>
                    <span class="eds-switch__track" aria-hidden="true"><span class="eds-switch__thumb"></span></span>
                </label>
            </div>
            <?php if (function_exists('squuad_cert_signature_sheet_site')) : ?>
            <div style="display: flex; gap: 16px; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; margin-top: 20px">
                <div style="flex: 1 1 420px">
                    <p style="margin: 0 0 4px"><strong id="squuad-cert-signing-sheet-label"><?= esc_html__('Attach the certificate of signatures to the PDF', 'wp-certificates') ?></strong></p>
                    <p id="squuad-cert-signing-sheet-help" style="margin: 0; color: var(--eds-muted, #50575e)"><?= esc_html__('Extra A4 page at the end of each signed PDF with who signed, when and how. If you turn it off, the document is checked with its QR code on the verification page and the certificate can be downloaded separately; make sure the templates use {{qrcode}}. Each document can follow this value or change it. Documents already signed do not change.', 'wp-certificates') ?></p>
                </div>
                <label class="eds-switch">
                    <input type="checkbox" role="switch" name="signature_sheet" value="1" aria-labelledby="squuad-cert-signing-sheet-label" aria-describedby="squuad-cert-signing-sheet-help" <?php checked('yes' === squuad_cert_signature_sheet_site()); ?>>
                    <span class="eds-switch__track" aria-hidden="true"><span class="eds-switch__thumb"></span></span>
                </label>
            </div>
            <?php endif; ?>
            <div class="eds-field" style="margin-top: 20px">
                <label for="squuad-cert-signing-legal-note"><strong><?= esc_html__('Legal note of the certificate of signatures', 'wp-certificates') ?></strong></label>
                <textarea id="squuad-cert-signing-legal-note" name="legal_note" rows="4" maxlength="<?= (int) SQUUAD_CERT_SIGNING_LEGAL_NOTE_MAX ?>" aria-describedby="squuad-cert-signing-legal-help" style="height: auto; min-height: 96px; padding: 8px 12px"><?= esc_textarea($note) ?></textarea>
                <p id="squuad-cert-signing-legal-help" style="margin: 4px 0 0; color: var(--eds-muted, #50575e)"><?= esc_html__('It is printed at the end of the last page of each signed PDF. Leave it empty to use the neutral text below. It should be reviewed by the legal adviser of your institution.', 'wp-certificates') ?></p>
                <p style="margin: 4px 0 0; color: var(--eds-muted, #50575e)"><em><?= esc_html(squuad_cert_signing_legal_note_default()) ?></em></p>
            </div>
            <p><button type="submit" class="eds-btn eds-btn--primary"><?= esc_html__('Save', 'wp-certificates') ?></button></p>
        </form>
    </section>
    </div>
    <?php
}
