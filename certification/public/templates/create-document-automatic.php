<!-- Your modal content here -->
<input class="formdata" autocomplete="off" type="hidden" id="modal_open" name="modal_open" value="1">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Great+Vibes&display=swap" rel="stylesheet">

<div class="modal-content modal-enrollment" id="modal-content">
    <div id="modal-contraseña" class="modal" style="overflow: auto; padding: 0 !important">
        <div class="modal-content modal-enrollment">
            <span id="close-modal-enrollment" style="float: right; cursor: pointer"><span
                    class='dashicons dashicons-no-alt'></span></span>
            <div class="modal-body" id="content-pdf">
                <input class="formdata" autocomplete="off" type="hidden" id="modal_open" name="modal_open" value="1" />
                <input type="hidden" name="student_user_id" value="<?= $student_id ?>" />
                <input type="hidden" name="document_id" value="<?= $document->document_identificator ?>">
                <input type="hidden" name="document_name" value="<?= $document->title ?>">
                <?php if (!empty($request)) { // solicitud de firma (ADR 0002): el servidor deduce de ella quién firma qué ?>
                    <input type="hidden" name="request_id" value="<?= (int) $request->id ?>">
                    <input type="hidden" name="content_sha256" value="<?= esc_attr($request->content_sha256) ?>">
                    <?php // Firmantes exigidos en esta solicitud (el recuadro de quien no firma se oculta)
                    $required_slots = function_exists('squuad_cert_signature_request_required_roles') ? squuad_cert_signature_request_required_roles($request) : [];
                    // Para el JS, el puesto de quien recibe el documento (cualquier rol) es el recuadro «student»
                    $required_for_js = array_map(static fn(string $slot): string => squuad_cert_is_holder_slot($slot) ? 'student' : $slot, $required_slots);
                    ?>
                    <input type="hidden" name="required_roles" value="<?= esc_attr(implode(',', $required_for_js)) ?>">
                    <input type="hidden" name="institutional_pending" value="<?= (function_exists('squuad_cert_signature_request_institutional_pending') && squuad_cert_signature_request_institutional_pending($request)) ? '1' : '0' ?>">
                <?php } ?>
                <?php if (!empty($legacy_partial)) { ?>
                    <div class="edusystem-signature-notice" style="margin:0 0 12px;padding:10px 12px;border-left:4px solid #dba617;background:#fcf9e8;" data-html2canvas-ignore="true">
                        <?= esc_html__('This document was updated to the new signature system: please sign it again. Everyone who has to sign it must do so from their own account.', 'wp-certificates') ?>
                    </div>
                <?php } ?>
                <?php if (!empty($document_fields)) { // respuestas de los campos adicionales: se guardan con una firma parcial ?>
                    <input type="hidden" name="document_fields_values" value="<?= esc_attr(wp_json_encode((object) $field_values)) ?>">
                <?php } ?>
                <?= $html ?>
                <?php if (!empty($request)) { // queda impreso en el PDF: une el documento a su solicitud y a su contenido ?>
                    <p class="edusystem-signature-footprint" style="margin-top:16px;font-size:9px;color:#666;word-break:break-all;">
                        <?= esc_html(sprintf(__('Signature request #%1$d, round %2$d · Content fingerprint (SHA-256): %3$s', 'wp-certificates'), (int) $request->id, (int) $request->round, $request->content_sha256)) ?>
                    </p>
                <?php } ?>
            </div>
            <div class="modal-footer" style="text-align: center; display: block">
                <?php // Quien solo rellena (campos adicionales, sin recuadro de firma): guarda sin firmar y sin consentimiento de firma
                $holder_only = !empty($request) && function_exists('squuad_cert_signature_request_is_holder')
                    && squuad_cert_signature_request_is_holder($request, get_current_user_id());
                ?>
                <?php if (!empty($request) && !$holder_only && function_exists('squuad_cert_signature_consent_text')) { // consentimiento (ADR 0002, punto 7): fuera del PDF ?>
                    <label class="edusystem-signature-consent" style="display:block;max-width:640px;margin:0 auto 12px;text-align:left;font-size:13px;">
                        <input type="checkbox" name="consent_version" value="<?= esc_attr(SQUUAD_CERT_SIGNATURE_CONSENT_CURRENT) ?>">
                        <?= esc_html(squuad_cert_signature_consent_text(SQUUAD_CERT_SIGNATURE_CONSENT_CURRENT)) ?>
                    </label>
                <?php } ?>
                <button type="button" class="submit button-create-enrollment" id="saveSignatures"><?= __('Save', 'wp-certificates') ?></button>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"
        integrity="sha512-GsLlZN/3F2ErC5ifS5QtgpiJtWd43JWSuIgh7mbzZ8zBps+dvLusV+eNQATqgA/HdeKFVgA5v3S/cIrLF7QnIg=="
        crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <?php if ($holder_only) { // Guardar sin firma (ADR 0004, decisión del 2026-10-01): el JS de firma solo se activa con recuadro ?>
        <script>
            (function () {
                const button = document.getElementById("saveSignatures");
                if (!button) return;
                // Si nadie más firma, el PDF final va en la misma petición; si faltan firmantes del sistema, lo harán ellos
                const withPdf = <?= wp_json_encode(empty($required_slots)) ?>;
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
                    html2pdf().set({
                        margin: [0.2, 0, 0, 0],
                        image: { type: "jpeg", quality: 0.98 },
                        jsPDF: { unit: "in", format: "a4", orientation: "portrait" },
                        html2canvas: { scrollX: 0, scrollY: 0, scale: 3 },
                        pagebreak: { mode: ["avoid-all", "css", "legacy"], after: ".pagebreak" },
                    }).from(document.getElementById("content-pdf")).outputPdf("blob").then(send).catch(() => fail(""));
                });
            })();
        </script>
    <?php } ?>
</div>