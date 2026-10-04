<?php
/**
 * Vista previa en PDF del documento con datos de ejemplo (admin/document-preview.php). El PDF se genera en el
 * navegador con las mismas opciones que el camino real del documento y se muestra en el visor de PDF del navegador.
 * Variables: $preview (squuad_cert_document_preview_data()).
 */
if (!defined('ABSPATH')) exit;

$modes = [
    'automatic' => __('As the final PDF of the signature request (A4, portrait).', 'wp-certificates'),
    'issued' => __('As the document issued for signature, with the format of the document.', 'wp-certificates'),
    'generate' => wpc_edusystem_active()
        ? __('As "Generate" in the student file, with the format of the document.', 'wp-certificates')
        : __('This is how the issued document will look, with its page format.', 'wp-certificates'),
];
// Pie del PDF de las solicitudes de firma, con valores de ejemplo (el real lleva el número, la ronda y la huella)
$fingerprint = sprintf(__('Signature request #%1$d, round %2$d · Content fingerprint (SHA-256): %3$s', 'wp-certificates'), 123, 1, hash('sha256', 'example'));
?>
<div id="edusystem-document-preview" style="max-width:1100px;margin:24px auto 0">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
        <h2 style="margin:0"><?= esc_html__('Preview', 'wp-certificates') ?></h2>
        <div>
            <span id="edusystem-preview-status" class="description" aria-live="polite"></span>
            <button type="button" class="button" id="edusystem-preview-refresh"><?= esc_html__('Update preview', 'wp-certificates') ?></button>
        </div>
    </div>
    <p class="description" style="margin:6px 0 10px">
        <?= esc_html($modes[$preview['mode']]) ?>
        <?= esc_html__('All the variables show example data, never real data. It shows the saved version: save your changes to see them.', 'wp-certificates') ?>
    </p>
    <?php if ($preview['signature_blocked']) : ?>
        <div class="notice notice-warning inline"><p><?= esc_html__('This document asks for signature images: it can no longer be generated this way. Configure its signers so that each person signs from their own account.', 'wp-certificates') ?></p></div>
    <?php endif; ?>
    <?php if ($preview['unknown']) : ?>
        <div class="notice notice-warning inline"><p>
            <?= esc_html__('These variables do not exist and will appear as text in the document:', 'wp-certificates') ?>
            <?= esc_html(implode(', ', array_map(static fn($key) => '{{' . $key . '}}', $preview['unknown']))) ?>
        </p></div>
    <?php endif; ?>
    <iframe id="edusystem-preview-frame" title="<?= esc_attr__('Preview of the document in PDF', 'wp-certificates') ?>"
        style="display:block;width:100%;height:85vh;border:1px solid #c3c4c7;background:#525659"></iframe>
</div>
<?php // Fuera de pantalla el envoltorio, no el origen: html2pdf clona el origen y lo pinta con el ancho útil de la página ?>
<div id="edusystem-preview-holder" style="position:absolute;left:-10000px;top:0;width:<?= (int) $preview['page']['width_px'] ?>px"></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"
    integrity="sha512-GsLlZN/3F2ErC5ifS5QtgpiJtWd43JWSuIgh7mbzZ8zBps+dvLusV+eNQATqgA/HdeKFVgA5v3S/cIrLF7QnIg=="
    crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://unpkg.com/qr-code-styling@1.5.0/lib/qr-code-styling.js"></script>
<script>
    document.addEventListener("DOMContentLoaded", function () {
        const data = <?= wp_json_encode($preview, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
        const fingerprint = <?= wp_json_encode($fingerprint, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
        const texts = {
            generating: <?= wp_json_encode(__('Generating the PDF…', 'wp-certificates')) ?>,
            done: <?= wp_json_encode(__('PDF ready.', 'wp-certificates')) ?>,
            fail: <?= wp_json_encode(__('The preview could not be generated.', 'wp-certificates')) ?>,
            /* translators: %s: technical reason of the error (from the PDF library) */
            failReason: <?= wp_json_encode(__('The preview could not be generated (%s).', 'wp-certificates')) ?>
        };
        const frame = document.getElementById("edusystem-preview-frame");
        const holder = document.getElementById("edusystem-preview-holder");
        const status = document.getElementById("edusystem-preview-status");
        const refresh = document.getElementById("edusystem-preview-refresh");
        let url = null;

        const drawQr = function (root, selector, dataOf) {
            if (!window.QRCodeStyling) return;
            root.querySelectorAll(selector).forEach(function (box) {
                new QRCodeStyling({ width: 100, height: 100, type: "canvas", data: dataOf(box) }).append(box);
            });
        };

        // Automático y emitido: como signature-final-pdf.php y signer-inbox.php (contenido de la solicitud + pie)
        async function signedPdf() {
            const source = document.createElement("div");
            source.style.cssText = "box-sizing:border-box;width:100%;background:#fff;padding:16px;font-family:Arial,sans-serif;color:#111";
            source.innerHTML = data.html + '<p style="margin-top:16px;font-size:9px;color:#666;word-break:break-all"></p>';
            source.lastElementChild.textContent = fingerprint;
            holder.appendChild(source);
            const automatic = "automatic" === data.mode;
            if (!automatic) {
                drawQr(source, "[data-edusig-qr]", function (box) { return box.dataset.edusigQr; });
            }
            return html2pdf().set({
                margin: automatic ? [0.3, 0.3, 0.3, 0.3] : data.page.margin,
                filename: "preview.pdf",
                image: { type: "jpeg", quality: 0.98 },
                jsPDF: automatic ? { unit: "in", format: "a4", orientation: "portrait" } : data.page.jspdf,
                // scrollX/scrollY a 0: con la página desplazada (el botón está abajo) html2canvas capturaba en blanco
                html2canvas: { scale: 2, scrollX: 0, scrollY: 0 },
                pagebreak: automatic ? { mode: ["avoid-all", "css", "legacy"], after: ".pagebreak" } : { after: ".pagebreak" }
            }).from(source).outputPdf("blob");
        }

        // "Generar" de la ficha del estudiante: como document.js (download-grades), con el encabezado y el pie
        // estampados en cada página cuando el documento es vertical
        async function generatePdf() {
            const g = data.generate;
            const header = document.createElement("div");
            const content = document.createElement("div");
            const footer = document.createElement("div");
            header.innerHTML = data.header;
            content.innerHTML = data.content;
            footer.innerHTML = data.footer;
            content.style.cssText = "padding:0;margin:0;background:#fff";
            content.style.minWidth = g.width;
            content.style.minHeight = g.height;
            holder.append(header, content, footer);
            // El QR de ejemplo, también si la plantilla lo pone en el encabezado o en el pie
            [header, content, footer].forEach(function (part) {
                drawQr(part, "#qrcode", function () { return "https://example.com/verify/EXAMPLE"; });
            });

            let margin = [0, 0];
            if (1 === g.margin_required) {
                margin = [data.header ? Math.round((header.offsetHeight + 10) * 0.264583333) : 0, 0,
                    data.footer ? Math.round((footer.offsetHeight + 10) * 0.264583333) : 0, 0];
            }
            const pdf = await html2pdf().set({
                margin: margin,
                filename: "preview.pdf",
                image: { type: "jpeg", quality: 1 },
                jsPDF: { unit: g.unit, format: g.paper_format, orientation: g.orientation, hotfixes: ["px_scaling"] },
                html2canvas: { scale: 3, useCORS: true, scrollX: 0, scrollY: 0 },
                pagebreak: { after: ".pagebreak" }
            }).from(content).toPdf().get("pdf");

            if ("portrait" === g.orientation && (data.header || data.footer)) {
                const width = pdf.internal.pageSize.width;
                // Un encabezado o pie sin alto (p. ej. solo un hueco vacío) no se estampa: con 0 px jsPDF recibe una
                // medida no válida y fallaba toda la vista previa
                const capture = async function (part, has) {
                    if (!has || part.offsetWidth < 1 || part.offsetHeight < 1) return null;
                    const canvas = await html2canvas(part, { scale: 2, scrollX: 0, scrollY: 0 });
                    return canvas.width > 0 && canvas.height > 0 ? canvas : null;
                };
                const headerCanvas = await capture(header, data.header);
                const footerCanvas = await capture(footer, data.footer);
                for (let i = 1; i <= pdf.internal.getNumberOfPages(); i++) {
                    pdf.setPage(i);
                    if (headerCanvas) {
                        pdf.addImage(headerCanvas.toDataURL("image/jpeg"), "JPEG", 0, 0, width, (headerCanvas.height * width) / headerCanvas.width);
                    }
                    if (footerCanvas) {
                        const height = (footerCanvas.height * width) / footerCanvas.width;
                        pdf.addImage(footerCanvas.toDataURL("image/jpeg"), "JPEG", 0, pdf.internal.pageSize.height - height, width, height);
                    }
                }
            }
            return pdf.output("blob");
        }

        async function build() {
            refresh.disabled = true;
            status.textContent = texts.generating;
            holder.innerHTML = "";
            try {
                const blob = "generate" === data.mode ? await generatePdf() : await signedPdf();
                if (url) URL.revokeObjectURL(url);
                url = URL.createObjectURL(blob);
                frame.src = url;
                status.textContent = texts.done;
            } catch (error) {
                console.error("Vista previa del documento:", error);
                // Motivo técnico (mensaje de la biblioteca del PDF, sin datos del documento), recortado
                const reason = error && error.message ? String(error.message).slice(0, 160) : "";
                status.textContent = reason ? texts.failReason.replace("%s", reason) : texts.fail;
            }
            holder.innerHTML = "";
            refresh.disabled = false;
        }

        refresh.addEventListener("click", build);
        build();
    });
</script>
