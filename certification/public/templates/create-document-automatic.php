<?php
/**
 * Ventana de firma del documento automático en Mi Cuenta (ADR 0011 de Edusof, maqueta aprobada el 2026-10-05).
 *
 * Variables (de squuad_cert_modal_document_automatic): $student_id, $document, $request, $html (contenido con las
 * etiquetas y los bloques de firma), $legacy_partial, $document_fields y $field_values.
 *
 * - Cabecera (título, código, botón principal y cerrar), orden de firma (F1, F2… con los colores de firmante, solo en
 *   esta interfaz), franja «Revise el documento y fírmelo» con el consentimiento v1 (mismo texto y versión) y «Empezar»,
 *   guía con el contador, el documento (papel blanco), pie fijo en móvil, panel de éxito y la ventana «Adoptar su
 *   firma» (<dialog>). El JS es create-enrollment.js; el servidor revalida todo al firmar (create_enrollment_document).
 * - Quien solo rellena (campos adicionales, sin puesto de firma) guarda sin firmar: ese flujo no cambia.
 */
if (!defined('ABSPATH')) exit;

$holder_only = !empty($request) && function_exists('squuad_cert_signature_request_is_holder')
    && squuad_cert_signature_request_is_holder($request, get_current_user_id());
$signing = !empty($request) && !$holder_only;
$overview = $request ? squuad_cert_signature_signers_overview($request, get_current_user_id()) : [];
$own = null;
foreach ($overview as $item) {
    if ($item['own']) {
        $own = $item;
    }
}
$eds_theme = class_exists('Edusof_UI') ? Edusof_UI::theme() : 'azul';
$eds_mode = class_exists('Edusof_UI') && Edusof_UI::enabled() ? Edusof_UI::mode() : 'auto';
$styles = squuad_cert_signature_styles();
$upload_allowed = squuad_cert_signing_upload_allowed();
// «Escribir» solo si el servidor puede dibujar la firma escrita (GD con FreeType): nunca se acepta una imagen del navegador
$typed_allowed = squuad_cert_signature_typed_available();
$own_name = $own ? $own['name'] : squuad_cert_account_name(get_current_user_id());
$own_id_document = function_exists('squuad_cert_id_document_required') && squuad_cert_id_document_required()
    ? squuad_cert_id_document_identifier(get_current_user_id()) : '';
$return_url = function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url('dashboard') : home_url('/');
$code = $request ? squuad_cert_signature_request_code($request) : '';
$icon_close = '<svg width="20" height="20" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M5 5l10 10M15 5 5 15" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
?>
<input class="formdata" autocomplete="off" type="hidden" id="modal_open" name="modal_open" value="1">

<div class="wpc-sign-modal eds-scope eds-theme-<?= esc_attr($eds_theme) ?> eds-mode-<?= esc_attr($eds_mode) ?>" id="wpc-sign"
    data-return-url="<?= esc_url($return_url) ?>" data-own-name="<?= esc_attr($own_name) ?>" data-own-label="<?= esc_attr($own ? $own['label'] : '') ?>"
    data-id-document="<?= esc_attr($own_id_document) ?>" data-upload="<?= $upload_allowed ? '1' : '0' ?>">
    <div class="wpc-sign-backdrop" aria-hidden="true"></div>
    <section class="wpc-sign-window" role="dialog" aria-modal="true" aria-labelledby="wpc-sign-title">
        <header class="wpc-sign-head">
            <div class="wpc-sign-doc">
                <h2 id="wpc-sign-title" tabindex="-1"><?= esc_html((string) $document->title) ?></h2>
                <p class="wpc-sign-docmeta"><?= esc_html(get_bloginfo('name')) ?><?php if ('' !== $code) : ?> · <?= esc_html(sprintf(
                    /* translators: %s: code of the document */
                    __('Code %s', 'wp-certificates'),
                    $code
                )) ?><?php endif; ?></p>
            </div>
            <div class="wpc-sign-acts">
                <?php if ($signing) : ?>
                    <button type="button" class="wpc-btn wpc-btn--primary wpc-btn--lg wpc-sign-main" data-wpc-main hidden><?= esc_html__('Next', 'wp-certificates') ?></button>
                <?php endif; ?>
                <button type="button" class="wpc-btn wpc-btn--ghost wpc-icon-btn" id="close-modal-enrollment" aria-label="<?= esc_attr__('Close without signing', 'wp-certificates') ?>"><?= $icon_close // phpcs:ignore -- SVG fijo ?></button>
            </div>
        </header>

        <?php if ($overview) : ?>
            <ol class="wpc-sign-order" aria-label="<?= esc_attr__('Signing order', 'wp-certificates') ?>">
                <?php foreach ($overview as $item) : ?>
                    <li><span class="wpc-chip wpc-signer-c<?= (int) $item['color'] ?>"<?= $item['own'] && 'signed' !== $item['state'] ? ' aria-current="step"' : '' ?> data-wpc-chip="<?= esc_attr($item['slot']) ?>">
                        <span class="wpc-chip-dot" aria-hidden="true"></span><?= esc_html($item['own'] ? __('You', 'wp-certificates') : $item['label']) ?>
                        <b>F<?= (int) $item['n'] ?></b>
                        <?php if ('signed' === $item['state']) : ?>
                            <span class="wpc-chip-st wpc-chip-st--ok">· <?= esc_html__('signed', 'wp-certificates') ?></span>
                        <?php elseif ($item['own']) : ?>
                            <span class="wpc-chip-st" data-wpc-own-state>· <?= esc_html__('your turn', 'wp-certificates') ?></span>
                        <?php endif; ?>
                    </span></li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>

        <?php if ($signing && function_exists('squuad_cert_signature_consent_text')) : // consentimiento (ADR 0002, punto 7): fuera del PDF ?>
            <div class="wpc-sign-consent" data-wpc-consent>
                <div class="wpc-sign-consent-txt">
                    <h3><?= esc_html__('Review the document and sign it', 'wp-certificates') ?></h3>
                    <label class="wpc-sign-consent-label">
                        <?php // Quien firma en representación del titular ve su propio texto (ADR 0012 de Edusof)
                        $consent_shown = squuad_cert_signature_consent_for($request, squuad_cert_signature_request_role($request, get_current_user_id())); ?>
                        <input type="checkbox" name="consent_version" value="<?= esc_attr($consent_shown['version']) ?>" data-wpc-consent-box aria-describedby="wpc-sign-consent-err">
                        <span><?= esc_html($consent_shown['text']) ?></span>
                    </label>
                    <p class="wpc-sign-err" id="wpc-sign-consent-err" data-wpc-consent-err hidden><?= esc_html__('Tick the box to be able to sign.', 'wp-certificates') ?></p>
                </div>
                <button type="button" class="wpc-btn wpc-btn--primary wpc-btn--lg" data-wpc-start><?= esc_html__('Start', 'wp-certificates') ?></button>
            </div>
            <div class="wpc-sign-guide" data-wpc-guide role="status" hidden>
                <svg width="18" height="18" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M3 14.5V17h2.5L15 7.5 12.5 5zM14 3.5l2.5 2.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                <span data-wpc-guide-text></span>
                <span class="wpc-sign-count" data-wpc-count></span>
            </div>
        <?php endif; ?>
        <div class="wpc-sign-alert" data-wpc-alert role="alert" hidden></div>

        <div class="wpc-sign-body" data-wpc-scroll tabindex="-1">
            <article class="wpc-sign-paper modal-body" id="content-pdf" aria-label="<?= esc_attr__('Document', 'wp-certificates') ?>">
                <input type="hidden" name="student_user_id" value="<?= (int) $student_id ?>" />
                <input type="hidden" name="document_id" value="<?= esc_attr((string) $document->document_identificator) ?>">
                <input type="hidden" name="document_name" value="<?= esc_attr((string) $document->title) ?>">
                <?php if (!empty($request)) { // solicitud de firma (ADR 0002): el servidor deduce de ella quién firma qué ?>
                    <input type="hidden" name="request_id" value="<?= (int) $request->id ?>">
                    <input type="hidden" name="content_sha256" value="<?= esc_attr($request->content_sha256) ?>">
                <?php } ?>
                <?php if (!empty($legacy_partial)) { ?>
                    <div class="wpc-sign-notice" data-html2canvas-ignore="true">
                        <?= esc_html__('This document was updated to the new signature system: please sign it again. Everyone who has to sign it must do so from their own account.', 'wp-certificates') ?>
                    </div>
                <?php } ?>
                <?php if (!empty($document_fields)) { // respuestas de los campos adicionales: se guardan con una firma parcial ?>
                    <input type="hidden" name="document_fields_values" value="<?= esc_attr(wp_json_encode((object) $field_values)) ?>">
                <?php } ?>
                <?= $html // phpcs:ignore -- contenido de la solicitud (escapado al generarlo) con las etiquetas y bloques de firma ?>
                <?php if (!empty($request)) { // queda impreso en el PDF: une el documento a su solicitud y a su contenido ?>
                    <p class="edusystem-signature-footprint" style="margin-top:16px;font-size:9px;color:#666;word-break:break-all;">
                        <?= esc_html(sprintf(__('Signature request #%1$d, round %2$d · Content fingerprint (SHA-256): %3$s', 'wp-certificates'), (int) $request->id, (int) $request->round, $request->content_sha256)) ?>
                    </p>
                <?php } ?>
            </article>
        </div>

        <?php if ($signing) : ?>
            <div class="wpc-sign-foot" data-wpc-foot>
                <span class="wpc-sign-foot-info" data-wpc-count-m></span>
                <button type="button" class="wpc-btn wpc-btn--primary" data-wpc-main-m><?= esc_html__('Start', 'wp-certificates') ?></button>
            </div>

            <div class="wpc-sign-success" data-wpc-success hidden>
                <div class="wpc-sign-card">
                    <div class="wpc-sign-success-ic" aria-hidden="true"><svg width="34" height="34" viewBox="0 0 24 24" focusable="false"><path d="M5 12.5l4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                    <h3 tabindex="-1" data-wpc-success-title><?= esc_html__('Document signed', 'wp-certificates') ?></h3>
                    <p data-wpc-success-text role="status"></p>
                    <ol class="wpc-steps" aria-label="<?= esc_attr__('Status of the signatures', 'wp-certificates') ?>" data-wpc-steps></ol>
                    <p class="wpc-sign-pdf-status" data-wpc-pdf-status role="status" hidden></p>
                    <div class="wpc-sign-success-btns">
                        <a class="wpc-btn wpc-btn--primary" href="<?= esc_url($return_url) ?>" data-wpc-return><?= esc_html__('Back to My documents', 'wp-certificates') ?></a>
                    </div>
                </div>
            </div>
        <?php elseif ($holder_only || empty($request)) : ?>
            <div class="wpc-sign-holder-foot">
                <button type="button" class="wpc-btn wpc-btn--primary wpc-btn--lg submit button-create-enrollment" id="saveSignatures"><?= esc_html__('Save', 'wp-certificates') ?></button>
            </div>
        <?php endif; ?>

        <?php if ($signing) : ?>
            <dialog class="wpc-adopt" id="wpc-adopt" aria-labelledby="wpc-adopt-title" aria-describedby="wpc-adopt-desc">
                <div class="wpc-adopt-head">
                    <div>
                        <h2 id="wpc-adopt-title"><?= esc_html__('Adopt your signature', 'wp-certificates') ?></h2>
                        <p id="wpc-adopt-desc" data-wpc-adopt-desc><?= esc_html__('Choose how you want to sign.', 'wp-certificates') ?></p>
                    </div>
                    <button type="button" class="wpc-btn wpc-btn--ghost wpc-icon-btn" data-wpc-adopt-close aria-label="<?= esc_attr__('Close', 'wp-certificates') ?>"><?= $icon_close // phpcs:ignore -- SVG fijo ?></button>
                </div>
                <div class="wpc-adopt-body">
                    <div class="wpc-tabs" role="tablist" aria-label="<?= esc_attr__('How to sign', 'wp-certificates') ?>">
                        <?php if ($typed_allowed) : ?>
                        <button type="button" role="tab" id="wpc-tab-typed" aria-controls="wpc-pan-typed" aria-selected="true" data-wpc-tab="typed">
                            <svg width="18" height="18" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M4 15h12M6 4h8M10 4v9" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg><?= esc_html_x('Type', 'signature tab: type the signature', 'wp-certificates') ?></button>
                        <?php endif; ?>
                        <button type="button" role="tab" id="wpc-tab-drawn" aria-controls="wpc-pan-drawn" aria-selected="<?= $typed_allowed ? 'false' : 'true' ?>"<?= $typed_allowed ? ' tabindex="-1"' : '' ?> data-wpc-tab="drawn">
                            <svg width="18" height="18" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M3 14.5V17h2.5L15 7.5 12.5 5zM14 3.5l2.5 2.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg><?= esc_html__('Draw', 'wp-certificates') ?></button>
                        <?php if ($upload_allowed) : ?>
                            <button type="button" role="tab" id="wpc-tab-image" aria-controls="wpc-pan-image" aria-selected="false" tabindex="-1" data-wpc-tab="image">
                                <svg width="18" height="18" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M10 13V4M6 8l4-4 4 4M4 16h12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg><?= esc_html__('Upload image', 'wp-certificates') ?></button>
                        <?php endif; ?>
                    </div>

                    <?php if ($typed_allowed) : ?>
                    <div role="tabpanel" id="wpc-pan-typed" aria-labelledby="wpc-tab-typed" data-wpc-panel="typed">
                        <label class="wpc-field"><span><?= esc_html__('Your typed signature', 'wp-certificates') ?></span>
                            <input type="text" data-wpc-typed value="<?= esc_attr($own_name) ?>" maxlength="<?= (int) SQUUAD_CERT_SIGNATURE_TYPED_MAX ?>" autocomplete="name" aria-describedby="wpc-typed-help">
                            <span class="wpc-help" id="wpc-typed-help"><?= esc_html__('You can change how it is written (for example, with an initial). The name shown under the signature does not change: it is the name of your account.', 'wp-certificates') ?></span>
                        </label>
                        <fieldset class="wpc-styles-set">
                            <legend><?= esc_html__('Choose a lettering style', 'wp-certificates') ?></legend>
                            <div class="wpc-styles" role="radiogroup">
                                <?php foreach ($styles as $n => $style) : ?>
                                    <label class="wpc-style">
                                        <input type="radio" name="wpc_style" value="<?= (int) $n ?>"<?= 1 === $n ? ' checked' : '' ?>>
                                        <span class="wpc-style-tick" aria-hidden="true"><svg width="14" height="14" viewBox="0 0 24 24" focusable="false"><path d="M5 12.5l4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="3"/></svg></span>
                                        <span class="wpc-style-sample wpc-font-<?= (int) $n ?>" data-wpc-sample aria-hidden="true"><?= esc_html($own_name) ?></span>
                                        <span class="wpc-style-name"><?= esc_html($style['label']) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </fieldset>
                    </div>

                    <?php endif; ?>

                    <div role="tabpanel" id="wpc-pan-drawn" aria-labelledby="wpc-tab-drawn" data-wpc-panel="drawn"<?= $typed_allowed ? ' hidden' : '' ?>>
                        <div class="wpc-pad" data-wpc-pad>
                            <canvas aria-label="<?= esc_attr__('Canvas to draw your signature', 'wp-certificates') ?>" role="img" data-wpc-canvas></canvas>
                            <div class="wpc-pad-base" aria-hidden="true"></div><div class="wpc-pad-x" aria-hidden="true">×</div>
                            <div class="wpc-pad-ph" data-wpc-pad-ph aria-hidden="true"><?= esc_html__('Sign here with the mouse or with your finger', 'wp-certificates') ?></div>
                        </div>
                        <div class="wpc-pad-tools">
                            <button type="button" class="wpc-btn wpc-btn--sm" data-wpc-undo><svg width="16" height="16" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M7 5 3 9l4 4M3 9h9a5 5 0 0 1 0 10h-2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg><?= esc_html__('Undo', 'wp-certificates') ?></button>
                            <button type="button" class="wpc-btn wpc-btn--sm" data-wpc-clear><svg width="16" height="16" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M4 6h12M8 6V4h4v2M6 6l1 11h6l1-11" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg><?= esc_html__('Clear', 'wp-certificates') ?></button>
                            <?php if ($typed_allowed) : ?><span class="wpc-help"><?= esc_html__('Hard to draw? Use the «Type» tab.', 'wp-certificates') ?></span><?php endif; ?>
                        </div>
                    </div>

                    <?php if ($upload_allowed) : ?>
                        <div role="tabpanel" id="wpc-pan-image" aria-labelledby="wpc-tab-image" data-wpc-panel="image" hidden>
                            <div class="wpc-drop" data-wpc-drop>
                                <svg width="36" height="36" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 16V5M7 10l5-5 5 5M4 19h16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                <span class="wpc-drop-big"><?= esc_html__('Drag the image of your signature here', 'wp-certificates') ?></span>
                                <span class="wpc-help"><?= esc_html__('or', 'wp-certificates') ?></span>
                                <label class="wpc-btn wpc-btn--primary" for="wpc-file"><?= esc_html__('Choose file', 'wp-certificates') ?></label>
                                <input class="wpc-sr-only" type="file" id="wpc-file" accept="image/png,image/jpeg" data-wpc-file aria-describedby="wpc-up-help">
                                <img class="wpc-drop-preview" data-wpc-file-preview alt="" hidden>
                            </div>
                            <ul class="wpc-helplist" id="wpc-up-help">
                                <li><?= esc_html__('Formats: PNG or JPG, up to 2 MB.', 'wp-certificates') ?></li>
                                <li><?= esc_html__('Sign with dark ink on white or light paper, without shadows.', 'wp-certificates') ?></li>
                                <li><?= esc_html__('Crop the image so that only the signature shows.', 'wp-certificates') ?></li>
                                <li><?= esc_html__('The background of the paper is not cleaned: use a white background.', 'wp-certificates') ?></li>
                            </ul>
                            <div class="wpc-notice wpc-notice--warn" role="note">
                                <svg width="18" height="18" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M10 3 18 17H2z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M10 8v4M10 14.5v.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                                <span><strong><?= esc_html__('You can only upload your own signature.', 'wp-certificates') ?></strong> <?= esc_html__('Using another person\'s signature is not allowed and the upload is recorded.', 'wp-certificates') ?></span>
                            </div>
                            <label class="wpc-check"><input type="checkbox" data-wpc-own-confirm> <span><?= esc_html__('I confirm that it is my own signature', 'wp-certificates') ?></span></label>
                        </div>
                    <?php endif; ?>

                    <div class="wpc-preview">
                        <h3><?= esc_html__('This is how it will appear in the document', 'wp-certificates') ?></h3>
                        <div class="wpc-preview-row">
                            <div class="wpc-paper-mini">
                                <div class="wpc-mini-area" data-wpc-preview></div>
                                <div class="wpc-sign-line">
                                    <div class="wpc-sign-name"><?= esc_html($own_name) ?></div>
                                    <?php if ($own && '' !== $own['label']) : ?><div><?= esc_html($own['label']) ?></div><?php endif; ?>
                                    <div><?= esc_html__('Date and time: added automatically when you sign', 'wp-certificates') ?></div>
                                    <?php if ('' !== $own_id_document) : ?><div><?= esc_html(sprintf(
                                        /* translators: %s: identity document of the signer (prefix and number) */
                                        __('ID document: %s', 'wp-certificates'),
                                        $own_id_document
                                    )) ?></div><?php endif; ?>
                                    <div class="wpc-mini-e"><?= esc_html__('Signed electronically', 'wp-certificates') ?></div>
                                </div>
                            </div>
                            <div class="wpc-uses"><?= esc_html__('It will be used in:', 'wp-certificates') ?><ol data-wpc-uses></ol>
                                <p class="wpc-help"><?= esc_html__('If you want to change it later, press «Change» next to the signature before finishing.', 'wp-certificates') ?></p></div>
                        </div>
                    </div>
                    <p class="wpc-sign-err" data-wpc-adopt-err role="alert" hidden></p>
                </div>
                <div class="wpc-adopt-foot">
                    <p class="wpc-adopt-legal"><?= esc_html__('By pressing «Adopt and sign», you accept that this signature represents you and has the same validity as your handwritten signature.', 'wp-certificates') ?></p>
                    <button type="button" class="wpc-btn" data-wpc-adopt-close><?= esc_html__('Cancel', 'wp-certificates') ?></button>
                    <button type="button" class="wpc-btn wpc-btn--primary" data-wpc-adopt><?= esc_html__('Adopt and sign', 'wp-certificates') ?></button>
                </div>
            </dialog>
        <?php endif; ?>
    </section>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"
        integrity="sha512-GsLlZN/3F2ErC5ifS5QtgpiJtWd43JWSuIgh7mbzZ8zBps+dvLusV+eNQATqgA/HdeKFVgA5v3S/cIrLF7QnIg=="
        crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <?php if ($holder_only) { // Guardar sin firma (ADR 0004, decisión del 2026-10-01): este flujo no cambia ?>
        <script>
            (function () {
                const button = document.getElementById("saveSignatures");
                if (!button) return;
                // Si nadie más firma, el PDF final va en la misma petición; si faltan firmantes del sistema, lo harán ellos
                const withPdf = <?= wp_json_encode(!squuad_cert_signature_request_required_roles($request)) ?>;
                const ajaxUrl = (window.ajax_object && window.ajax_object.ajax_url) || <?= wp_json_encode(admin_url('admin-ajax.php')) ?>;
                const field = (name) => { const input = document.querySelector(`input[name="${name}"]`); return input ? input.value : ""; };
                const fail = (message) => { alert(message || <?= wp_json_encode(__('The document could not be saved. Please reload the page and try again.', 'wp-certificates')) ?>); button.disabled = false; };
                const send = (pdf) => {
                    const data = new FormData();
                    data.append("action", "create_enrollment_document");
                    data.append("_ajax_nonce", window.edusystemSignatures ? window.edusystemSignatures.nonce : "");
                    data.append("request_id", field("request_id"));
                    data.append("content_sha256", field("content_sha256"));
                    if (pdf) data.append("document", pdf, (field("document_name") || "document").toLowerCase() + ".pdf");
                    return fetch(`${ajaxUrl}?action=create_enrollment_document`, { method: "POST", body: data, credentials: "same-origin" })
                        .then((response) => response.json().catch(() => null))
                        .then((response) => (response && response.success ? window.location.reload() : fail(response && typeof response.data === "string" ? response.data : "")));
                };
                button.addEventListener("click", () => {
                    button.disabled = true;
                    if (!withPdf) { send(null).catch(() => fail("")); return; }
                    // El PDF sale del papel sin el relleno ni la sombra de la ventana
                    const paper = document.getElementById("content-pdf");
                    paper.classList.add("wpc-sign-paper--print");
                    html2pdf().set({
                        margin: [0.2, 0, 0, 0],
                        image: { type: "jpeg", quality: 0.98 },
                        jsPDF: { unit: "in", format: "a4", orientation: "portrait" },
                        html2canvas: { scrollX: 0, scrollY: 0, scale: 3 },
                        pagebreak: { mode: ["avoid-all", "css", "legacy"], after: ".pagebreak" },
                    }).from(paper).outputPdf("blob").then((pdf) => { paper.classList.remove("wpc-sign-paper--print"); return send(pdf); })
                        .catch(() => { paper.classList.remove("wpc-sign-paper--print"); fail(""); });
                });
            })();
        </script>
    <?php } ?>
</div>
