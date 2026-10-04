<?php
/**
 * "Documentos por firmar" (admin/signer-inbox.php). Variables: $notice, $items, $profile, $request, $final_html,
 * $generate_pdf, $document_title, $pending_here, $batch, $pdf_queue y $id_document_blocked (falta el documento de
 * identidad: su formulario va encima y se quitan solo los botones de firma; el PDF final, la cola de PDF, el resultado
 * del lote y «Declinar» siguen accesibles; ADR 0007 de Edusof, Q3).
 */
if (!defined('ABSPATH')) exit;

$page_url = add_query_arg('page', SQUUAD_CERT_SIGNER_INBOX_PAGE, admin_url('admin.php'));
$frame = static function (string $html): string {
    // Contenido del documento aislado: sin scripts ni acceso a la página del admin
    $doc = '<!doctype html><html><head><meta charset="utf-8"><style>body{font-family:Arial,sans-serif;margin:16px;color:#111}img{max-width:100%}</style></head><body>' . $html . '</body></html>';
    return '<iframe sandbox="" srcdoc="' . esc_attr($doc) . '" style="width:100%;height:70vh;border:1px solid #c3c4c7;background:#fff" title="' . esc_attr__('Document', 'wp-certificates') . '"></iframe>';
};
?>
<div class="wrap">
    <h1><?= esc_html__('Documents to sign', 'wp-certificates') ?></h1>

    <?php if ($notice) : ?>
        <div class="notice <?= $notice['ok'] ? 'notice-success' : 'notice-error' ?> is-dismissible"><p><?= esc_html($notice['message']) ?></p></div>
    <?php endif; ?>

    <?php if (!$profile) : ?>
        <div class="notice notice-warning"><p>
            <?= esc_html__('Register your signature before signing documents.', 'wp-certificates') ?>
            <a href="<?= esc_url(add_query_arg('page', 'squuad-cert-my-signature', admin_url('admin.php'))) ?>"><?= esc_html__('My signature', 'wp-certificates') ?></a>
        </p></div>
    <?php endif; ?>

    <?php if (!empty($id_document_blocked)) {
        squuad_cert_id_document_render_self_form('admin');
    } ?>

    <?php if ($request && null !== $final_html) : ?>
        <p><a href="<?= esc_url($page_url) ?>">&larr; <?= esc_html__('Back to the list', 'wp-certificates') ?></a></p>
        <h2><?= esc_html($document_title ?: $request->document_id) ?></h2>
        <p class="description"><?= esc_html(sprintf(__('Signature request #%1$d, round %2$d · Content fingerprint (SHA-256): %3$s', 'wp-certificates'), (int) $request->id, (int) $request->round, $request->content_sha256)) ?></p>

        <?= $frame($final_html) // phpcs:ignore -- iframe aislado con el contenido congelado (datos escapados al generarlo) ?>

        <?php if ($pending_here && $profile) : ?>
            <?php if (empty($id_document_blocked)) : ?>
            <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" style="max-width:760px;margin-top:16px">
                <input type="hidden" name="action" value="squuad_cert_sign_as_signer">
                <input type="hidden" name="request_id" value="<?= (int) $request->id ?>">
                <input type="hidden" name="content_sha256" value="<?= esc_attr($request->content_sha256) ?>">
                <?php wp_nonce_field('squuad_cert_sign_as_signer_' . (int) $request->id); ?>
                <p><?= esc_html__('Your registered signature will be applied to this document:', 'wp-certificates') ?></p>
                <div style="background:#fff;border:1px solid #c3c4c7;display:inline-block;padding:4px"><?= squuad_cert_signature_svg((string) $profile->strokes) // SVG generado en el servidor ?></div>
                <p><label><input type="checkbox" name="consent_version" value="<?= esc_attr(SQUUAD_CERT_SIGNATURE_CONSENT_CURRENT) ?>" required>
                    <?= esc_html(squuad_cert_signature_consent_text(SQUUAD_CERT_SIGNATURE_CONSENT_CURRENT)) ?></label></p>
                <p><button type="submit" class="button button-primary"><?= esc_html__('Sign this document', 'wp-certificates') ?></button></p>
            </form>
            <?php endif; ?>

            <details style="max-width:760px;margin-top:16px">
                <summary style="cursor:pointer;color:#b32d2e;font-weight:600"><?= esc_html__('Decline this document', 'wp-certificates') ?></summary>
                <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" style="margin-top:8px">
                    <input type="hidden" name="action" value="squuad_cert_signer_decline">
                    <input type="hidden" name="request_id" value="<?= (int) $request->id ?>">
                    <?php wp_nonce_field('squuad_cert_signer_decline_' . (int) $request->id); ?>
                    <div class="notice notice-error inline" style="margin:0 0 8px"><p><strong><?= esc_html__('This action cannot be reverted.', 'wp-certificates') ?></strong>
                        <?= esc_html__('When you decline this document, the signature of the person linked to it (if any) will be revoked, and the document can no longer be approved or set back to pending. The user will have to send or sign a new document.', 'wp-certificates') ?></p></div>
                    <p><label for="edusystem-decline-reason"><?= esc_html__('Reason why it is declined', 'wp-certificates') ?></label><br>
                        <textarea id="edusystem-decline-reason" name="reason" required rows="3" style="width:100%"></textarea></p>
                    <p><label><input type="checkbox" name="confirm_irreversible" value="1" required> <?= esc_html__('I understand that declining is final and cannot be reverted.', 'wp-certificates') ?></label></p>
                    <p><button type="submit" class="button" style="color:#b32d2e;border-color:#b32d2e"><?= esc_html__('Decline', 'wp-certificates') ?></button></p>
                </form>
            </details>
        <?php elseif ('signed' === $request->status && $generate_pdf) : ?>
            <div id="edusystem-pdf-status" class="notice notice-info inline"><p><?= esc_html__('Generating the final PDF…', 'wp-certificates') ?></p></div>
            <?php
            $pdf_page = squuad_cert_signature_pdf_page($request);
            // Sin texto cortado entre páginas (avoid-all) en los documentos automáticos; no en los emitidos, que son
            // diseños de página fija de wp-certificates y avoid-all los desplaza (páginas en blanco, probado en Chrome)
            $pdf_pagebreak = 'issued' === ($request->origin ?? '') ? ['after' => '.pagebreak'] : ['mode' => ['avoid-all', 'css', 'legacy'], 'after' => '.pagebreak'];
            ?>
            <?php // Fuera de pantalla el envoltorio, no el origen: html2pdf clona el origen y, si este va en posición absoluta, lo pinta
            // con alto 0 (PDF en blanco). El origen ocupa el 100 %: en el clon, el ancho útil de la página (sin recortar el borde derecho) ?>
            <div style="position:absolute;left:-10000px;top:0;width:<?= (int) $pdf_page['width_px'] ?>px"><div id="edusystem-pdf-source" style="width:100%;box-sizing:border-box;background:#fff;padding:16px;font-family:Arial,sans-serif;color:#111">
                <?= $final_html // phpcs:ignore -- contenido congelado (escapado al generarlo) con las firmas en SVG ?>
                <p style="margin-top:16px;font-size:9px;color:#666;word-break:break-all"><?= esc_html(sprintf(__('Signature request #%1$d, round %2$d · Content fingerprint (SHA-256): %3$s', 'wp-certificates'), (int) $request->id, (int) $request->round, $request->content_sha256)) ?></p>
            </div></div>
            <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"
                integrity="sha512-GsLlZN/3F2ErC5ifS5QtgpiJtWd43JWSuIgh7mbzZ8zBps+dvLusV+eNQATqgA/HdeKFVgA5v3S/cIrLF7QnIg=="
                crossorigin="anonymous" referrerpolicy="no-referrer"></script>
            <?php if (false !== strpos((string) $final_html, 'data-edusig-qr="http')) : ?>
                <?php // QR del documento emitido: misma librería heredada que "Generar" (admin/templates/document-export.php) ?>
                <script src="https://unpkg.com/qr-code-styling@1.5.0/lib/qr-code-styling.js"></script>
            <?php endif; ?>
            <script>
                // Último firmante: el PDF se genera desde el documento firmado (contenido congelado + firmas) y se sube
                // una sola vez; el servidor comprueba que todos firmaron y guarda su huella
                document.addEventListener("DOMContentLoaded", function () {
                    const status = document.querySelector("#edusystem-pdf-status p");
                    const source = document.getElementById("edusystem-pdf-source");
                    const filename = <?= wp_json_encode(sanitize_file_name(strtolower($document_title ?: (string) $request->document_id)) . '.pdf') ?>;
                    // QR sellado en el contenido del documento emitido (su URL): se dibuja antes de generar el PDF
                    if (window.QRCodeStyling) {
                        source.querySelectorAll("[data-edusig-qr]").forEach(function (box) {
                            if (box.dataset.edusigQr) {
                                new QRCodeStyling({ width: 100, height: 100, type: "canvas", data: box.dataset.edusigQr }).append(box);
                            }
                        });
                    }
                    html2pdf().set({ margin: <?= wp_json_encode($pdf_page['margin']) ?>, filename: filename, image: { type: "jpeg", quality: 0.98 },
                        jsPDF: <?= wp_json_encode($pdf_page['jspdf']) ?>, html2canvas: { scrollX: 0, scrollY: 0, scale: 2 }, pagebreak: <?= wp_json_encode($pdf_pagebreak) ?> })
                        .from(source).outputPdf("blob").then(function (blob) {
                            const data = new FormData();
                            data.append("action", "create_enrollment_document");
                            data.append("_ajax_nonce", <?= wp_json_encode(wp_create_nonce('edusystem_signatures')) ?>);
                            data.append("request_id", <?= (int) $request->id ?>);
                            data.append("content_sha256", <?= wp_json_encode($request->content_sha256) ?>);
                            data.append("document", blob, filename);
                            return fetch(<?= wp_json_encode(admin_url('admin-ajax.php')) ?>, { method: "POST", body: data, credentials: "same-origin" });
                        }).then(function (response) { return response.json(); }).then(function (result) {
                            status.textContent = result && result.success
                                ? <?= wp_json_encode(__('The final PDF was generated and saved.', 'wp-certificates')) ?>
                                : (result && typeof result.data === "string" ? result.data : <?= wp_json_encode(__('The final PDF could not be saved.', 'wp-certificates')) ?>);
                        }).catch(function () {
                            status.textContent = <?= wp_json_encode(__('The final PDF could not be saved.', 'wp-certificates')) ?>;
                        });
                });
            </script>
        <?php else : ?>
            <p><strong><?= esc_html__('Status:', 'wp-certificates') ?></strong> <?= esc_html($request->status) ?></p>
        <?php endif; ?>
    <?php elseif ($batch) : ?>
        <p><a href="<?= esc_url($page_url) ?>">&larr; <?= esc_html__('Back to the list', 'wp-certificates') ?></a></p>
        <?php $expired = 'prepared' === $batch->status && strtotime($batch->expires_at_utc . ' UTC') < time(); ?>
        <?php if ('prepared' === $batch->status && !$expired) : ?>
            <h2><?= esc_html__('Sign selected documents', 'wp-certificates') ?></h2>
            <p class="description"><?= esc_html__('Review the list. Each document keeps its own signature and its own record; any document that changed or no longer waits for your signature is skipped.', 'wp-certificates') ?></p>
            <table class="widefat striped" style="max-width:1100px">
                <thead><tr>
                    <th>#</th>
                    <th><?= esc_html__('Document', 'wp-certificates') ?></th>
                    <th><?= esc_html__('Holder', 'wp-certificates') ?></th>
                    <th><?= esc_html__('Round', 'wp-certificates') ?></th>
                    <th><?= esc_html__('Content fingerprint', 'wp-certificates') ?></th>
                    <th></th>
                </tr></thead>
                <tbody>
                    <?php foreach ((array) $batch->data['items'] as $i => $item) : ?>
                        <tr>
                            <td><?= (int) $i + 1 ?></td>
                            <td><?= esc_html((string) $item['title']) ?></td>
                            <td><?= esc_html((string) $item['student']) ?></td>
                            <td><?= (int) $item['round'] ?></td>
                            <td><code><?= esc_html(substr((string) $item['content_sha256'], 0, 12)) ?>…</code></td>
                            <td><a href="<?= esc_url(add_query_arg('request_id', (int) $item['request_id'], $page_url)) ?>" target="_blank" rel="noopener"><?= esc_html__('View document', 'wp-certificates') ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (empty($id_document_blocked)) : ?>
            <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" style="max-width:760px;margin-top:16px">
                <input type="hidden" name="action" value="squuad_cert_signer_batch_confirm">
                <input type="hidden" name="batch_id" value="<?= (int) $batch->id ?>">
                <?php wp_nonce_field('squuad_cert_signer_batch_confirm_' . (int) $batch->id); ?>
                <?php if ($profile) : ?>
                    <p><?= esc_html__('Your registered signature will be applied to each document:', 'wp-certificates') ?></p>
                    <div style="background:#fff;border:1px solid #c3c4c7;display:inline-block;padding:4px"><?= squuad_cert_signature_svg((string) $profile->strokes) // SVG generado en el servidor ?></div>
                <?php endif; ?>
                <p><label><input type="checkbox" name="consent_sha256" value="<?= esc_attr($batch->consent_sha256) ?>" required>
                    <?= nl2br(esc_html((string) $batch->data['consent_text'])) ?></label></p>
                <p><label for="edusystem-batch-password"><?= esc_html__('Confirm with your password', 'wp-certificates') ?></label><br>
                    <input type="password" id="edusystem-batch-password" name="password" required autocomplete="current-password" class="regular-text"></p>
                <p><button type="submit" class="button button-primary"><?= esc_html(sprintf(
                    /* translators: %d: number of documents */
                    _n('Sign %d document', 'Sign %d documents', count((array) $batch->data['items']), 'wp-certificates'),
                    count((array) $batch->data['items'])
                )) ?></button></p>
                <p class="description"><?= esc_html(sprintf(
                    /* translators: %s: expiry date and time */
                    __('This selection expires on %s.', 'wp-certificates'),
                    get_date_from_gmt((string) $batch->expires_at_utc, get_option('date_format') . ' ' . get_option('time_format'))
                )) ?></p>
            </form>
            <?php endif; ?>
        <?php elseif ('finished' === $batch->status && $batch->result_data) : ?>
            <h2><?= esc_html__('Batch result', 'wp-certificates') ?></h2>
            <?php $titles = array_column((array) $batch->data['items'], null, 'request_id'); ?>
            <table class="widefat striped" style="max-width:1100px">
                <thead><tr><th><?= esc_html__('Document', 'wp-certificates') ?></th><th><?= esc_html__('Holder', 'wp-certificates') ?></th><th><?= esc_html__('Result', 'wp-certificates') ?></th></tr></thead>
                <tbody>
                    <?php foreach ((array) $batch->result_data['signed'] as $id) : ?>
                        <tr><td><?= esc_html((string) ($titles[$id]['title'] ?? $id)) ?></td><td><?= esc_html((string) ($titles[$id]['student'] ?? '')) ?></td>
                            <td><?= in_array($id, (array) $batch->result_data['completed'], true) ? esc_html__('Signed. All signatures are complete.', 'wp-certificates') : esc_html__('Signed. Waiting for the remaining signatures.', 'wp-certificates') ?></td></tr>
                    <?php endforeach; ?>
                    <?php foreach ((array) $batch->result_data['skipped'] as $skip) : ?>
                        <tr><td><?= esc_html((string) ($titles[$skip['request_id']]['title'] ?? $skip['request_id'])) ?></td><td><?= esc_html((string) ($titles[$skip['request_id']]['student'] ?? '')) ?></td>
                            <td><strong><?= esc_html__('Skipped:', 'wp-certificates') ?></strong> <?= esc_html((string) $skip['reason']) ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else : ?>
            <p><?= esc_html($expired || 'expired' === $batch->status ? __('This batch expired. Select the documents again.', 'wp-certificates') : __('This batch was already processed.', 'wp-certificates')) ?></p>
        <?php endif; ?>
    <?php else : ?>
        <?php if (!$items) : ?>
            <p><?= esc_html__('There are no documents waiting for your signature.', 'wp-certificates') ?></p>
        <?php else : ?>
            <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
            <input type="hidden" name="action" value="squuad_cert_signer_batch_prepare">
            <?php wp_nonce_field('squuad_cert_signer_batch_prepare'); ?>
            <table class="widefat striped" style="max-width:1100px">
                <thead><tr>
                    <td class="check-column" style="padding:8px 0 0 3px"><?php if (empty($id_document_blocked)) : ?><input type="checkbox" id="edusystem-batch-all" aria-label="<?= esc_attr__('Select all', 'wp-certificates') ?>"><?php endif; ?></td>
                    <th><?= esc_html__('Document', 'wp-certificates') ?></th>
                    <th><?= esc_html__('Holder', 'wp-certificates') ?></th>
                    <th><?= esc_html__('Round', 'wp-certificates') ?></th>
                    <th><?= esc_html__('Waiting since', 'wp-certificates') ?></th>
                    <th></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($items as $item) : ?>
                        <tr>
                            <th scope="row" class="check-column" style="padding:8px 0 0 3px"><?php if (empty($id_document_blocked)) : ?><input type="checkbox" name="request_ids[]" value="<?= (int) $item->id ?>" class="edusystem-batch-item"><?php endif; ?></th>
                            <td><?= esc_html((string) ($item->document_title ?: $item->document_id)) ?><br><span class="description"><?= esc_html(substr((string) $item->content_sha256, 0, 12)) ?>…</span></td>
                            <td><?= esc_html(trim((string) $item->student_name . ' ' . (string) $item->student_last_name)) ?></td>
                            <td><?= (int) $item->round ?></td>
                            <td><?= esc_html(get_date_from_gmt((string) $item->frozen_at_utc, get_option('date_format') . ' ' . get_option('time_format'))) ?></td>
                            <td><a class="button button-primary" href="<?= esc_url(add_query_arg('request_id', (int) $item->id, $page_url)) ?>"><?= esc_html(empty($id_document_blocked) ? __('Review and sign', 'wp-certificates') : __('View document', 'wp-certificates')) ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($profile && empty($id_document_blocked)) : ?>
                <p><button type="submit" class="button"><?= esc_html__('Sign selected documents', 'wp-certificates') ?></button>
                    <span class="description"><?= esc_html(sprintf(
                        /* translators: %d: maximum number of documents per batch */
                        __('Up to %d documents at once. You will review the list and confirm with your password.', 'wp-certificates'),
                        SQUUAD_CERT_SIGNATURE_BATCH_MAX
                    )) ?></span></p>
            <?php endif; ?>
            </form>
            <script>
                const batchAll = document.getElementById("edusystem-batch-all"); // no existe sin documento de identidad
                if (batchAll) batchAll.addEventListener("change", function () {
                    document.querySelectorAll(".edusystem-batch-item").forEach((box) => { box.checked = this.checked; });
                });
            </script>
        <?php endif; ?>

        <?php if ($pdf_queue) : ?>
            <h2 style="margin-top:24px"><?= esc_html__('Signed documents waiting for the final PDF', 'wp-certificates') ?></h2>
            <table class="widefat striped" style="max-width:1100px">
                <thead><tr><th><?= esc_html__('Document', 'wp-certificates') ?></th><th><?= esc_html__('Holder', 'wp-certificates') ?></th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($pdf_queue as $done) : ?>
                        <tr>
                            <td><?= esc_html((string) ($done->document_title ?: $done->document_id)) ?></td>
                            <td><?= esc_html(trim((string) $done->student_name . ' ' . (string) $done->student_last_name)) ?></td>
                            <td><a class="button" href="<?= esc_url(add_query_arg(['request_id' => (int) $done->id, 'generate_pdf' => 1], $page_url)) ?>"><?= esc_html__('Generate PDF', 'wp-certificates') ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>
</div>
