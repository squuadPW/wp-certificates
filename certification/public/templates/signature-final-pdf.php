<?php
/**
 * Genera en el navegador el PDF final de solicitudes con todas las firmas (ADR 0003, punto 11), una tras otra, a
 * partir del contenido congelado con las firmas dibujadas en el servidor, y lo sube una sola vez por solicitud (el
 * servidor comprueba el estado y la huella). Variables: $pdf_requests (['request', 'title', 'html']).
 */
if (!defined('ABSPATH')) exit;
?>
<ul id="edusystem-final-pdf-status">
    <?php foreach ($pdf_requests as $pdf) : ?>
        <li data-request="<?= (int) $pdf['request']->id ?>"><?= esc_html($pdf['title']) ?>: <span><?= esc_html__('Generating the final PDF…', 'edusystem') ?></span></li>
    <?php endforeach; ?>
</ul>
<?php // Fuera de pantalla el envoltorio, no el origen: html2pdf clona el origen y, si este va en posición absoluta, lo pinta
// con alto 0 (PDF en blanco). El origen ocupa el 100 %: en el clon, el ancho útil de la página (sin recortar el borde derecho) ?>
<div style="position:absolute;left:-10000px;top:0;width:794px">
<?php foreach ($pdf_requests as $pdf) : ?>
    <div class="edusystem-final-pdf-source" data-request="<?= (int) $pdf['request']->id ?>" data-sha="<?= esc_attr($pdf['request']->content_sha256) ?>"
        data-filename="<?= esc_attr(sanitize_file_name(strtolower($pdf['title'] ?: (string) $pdf['request']->document_id)) . '.pdf') ?>"
        style="box-sizing:border-box;width:100%;background:#fff;padding:16px;font-family:Arial,sans-serif;color:#111">
        <?= $pdf['html'] // phpcs:ignore -- contenido congelado (escapado al generarlo) con las firmas en SVG ?>
        <p style="margin-top:16px;font-size:9px;color:#666;word-break:break-all"><?= esc_html(sprintf(__('Signature request #%1$d, round %2$d · Content fingerprint (SHA-256): %3$s', 'edusystem'), (int) $pdf['request']->id, (int) $pdf['request']->round, $pdf['request']->content_sha256)) ?></p>
    </div>
<?php endforeach; ?>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"
    integrity="sha512-GsLlZN/3F2ErC5ifS5QtgpiJtWd43JWSuIgh7mbzZ8zBps+dvLusV+eNQATqgA/HdeKFVgA5v3S/cIrLF7QnIg=="
    crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script>
    // Uno tras otro: cada PDF se sube con su solicitud y la huella del contenido; el servidor lo acepta una sola vez
    document.addEventListener("DOMContentLoaded", async function () {
        const texts = {
            ok: <?= wp_json_encode(__('The final PDF was generated and saved.', 'edusystem')) ?>,
            fail: <?= wp_json_encode(__('The final PDF could not be saved.', 'edusystem')) ?>
        };
        for (const source of document.querySelectorAll(".edusystem-final-pdf-source")) {
            const status = document.querySelector('#edusystem-final-pdf-status li[data-request="' + source.dataset.request + '"] span');
            try {
                const blob = await html2pdf().set({ margin: [0.3, 0.3, 0.3, 0.3], filename: source.dataset.filename, image: { type: "jpeg", quality: 0.98 },
                    jsPDF: { unit: "in", format: "a4", orientation: "portrait" }, html2canvas: { scale: 2 },
                    pagebreak: { mode: ["avoid-all", "css", "legacy"], after: ".pagebreak" } }) // sin cortar texto entre páginas
                    .from(source).outputPdf("blob");
                const data = new FormData();
                data.append("action", "create_enrollment_document");
                data.append("_ajax_nonce", <?= wp_json_encode(wp_create_nonce('edusystem_signatures')) ?>);
                data.append("request_id", source.dataset.request);
                data.append("content_sha256", source.dataset.sha);
                data.append("document", blob, source.dataset.filename);
                const response = await fetch(<?= wp_json_encode(admin_url('admin-ajax.php')) ?>, { method: "POST", body: data, credentials: "same-origin" });
                const result = await response.json();
                status.textContent = result && result.success ? texts.ok : (result && typeof result.data === "string" ? result.data : texts.fail);
            } catch (error) {
                status.textContent = texts.fail;
            }
        }
    });
</script>
