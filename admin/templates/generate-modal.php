<?php
/**
 * «Generar» un documento desde la ficha del estudiante: datos del documento a generar (#documentcertificate-modal, ya
 * no se muestra) y ventana con el documento y el botón de descarga en PDF. Movido desde edusystem/admin/templates/student-details.php y document-export.php (ADR 0004 de
 * EduSystem); sin el selector de firma-imagen, que se retiró. Variables: $student_id.
 * Los textos conservan el dominio de traducción de EduSystem hasta que se porten a wp-certificates.
 */
defined('ABSPATH') || exit;
?>
    <div id='documentcertificate-modal' class='modal' style='display:none'>
        <div class='modal-content' style="width: 70%;">
            <div class="modal-header">
                <h3 style="font-size:20px;"><?= esc_html__('Generate Document', 'edusystem') ?></h3>
                <span id="documentcertificate-exit-icon" class="modal-close"><span
                        class="dashicons dashicons-no-alt"></span></span>
            </div>
            <div class="modal-body" style="padding:10px;">
                <input type="hidden" name="document_certificate_id">
                <input type="hidden" name="student_document_certificate_id" value="<?= (int) $student_id ?>">
            </div>
            <div class="modal-footer">
                <button id="documentcertificate-button" type="button"
                    class="button button-outline-primary modal-close"><?= esc_html__('Generate', 'edusystem') ?></button>
                <button id="documentcertificate-exit-button" type="button"
                    class="button button-danger modal-close"><?= esc_html__('Exit', 'edusystem') ?></button>
            </div>
        </div>
    </div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"
    integrity="sha512-GsLlZN/3F2ErC5ifS5QtgpiJtWd43JWSuIgh7mbzZ8zBps+dvLusV+eNQATqgA/HdeKFVgA5v3S/cIrLF7QnIg=="
    crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script type="text/javascript" src="https://unpkg.com/qr-code-styling@1.5.0/lib/qr-code-styling.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>

<div id="modal-grades" class="modal" style="overflow: auto; padding: 0 !important">
    <div class="modal-content modal-document-export">
        <div class="modal-body" id="content-pdf" style="padding: 0 !important; margin: 0 !important;">

        </div>
        <div class="modal-footer" style="text-align: center; display: block">
            <button type="button" class="button button-danger" id="close-modal-grades"><?= __('Close', 'edusystem') ?></button>
            <button type="button" class="button button-primary" id="download-grades"><?= __('Download', 'edusystem') ?></button>
        </div>
    </div>
</div>