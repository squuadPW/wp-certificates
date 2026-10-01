<?php
/**
 * Formulario de los campos adicionales de un documento automático, antes de mostrarlo.
 * Lo incluye modal_document_automatic(). Variables: $document, $student, $document_fields,
 * $field_values, $field_errors.
 *
 * Se envía por POST a la misma página; las respuestas se copian en sessionStorage (create-enrollment.js)
 * para no perderlas si se recarga antes de firmar, y se borran al firmar.
 */
?>
<input class="formdata" autocomplete="off" type="hidden" id="modal_open" name="modal_open" value="1">

<div class="modal-content modal-enrollment" id="modal-content">
    <div id="modal-contraseña" class="modal" style="overflow: auto; padding: 0 !important">
        <div class="modal-content" style="box-sizing: border-box; width: calc(100% - 2rem); max-width: 640px; margin: 10vh auto">
            <span id="close-modal-enrollment" style="float: right; cursor: pointer"><span
                    class='dashicons dashicons-no-alt'></span></span>
            <form id="form-document-fields" class="modal-body" method="post"
                data-storage-key="<?= esc_attr('edusystem_document_fields_' . $document->id . '_' . $student->id) ?>">
                <input type="hidden" name="edusystem_document_fields" value="<?= esc_attr($document->id) ?>">
                <?php wp_nonce_field('edusystem_document_fields_' . $document->id); ?>

                <h3 style="font-weight: 600; margin: 0 0 0.5rem"><?= esc_html($document->title) ?></h3>
                <p style="margin-top: 0"><?= esc_html__('Before continuing, complete the following information. It will be used to fill in the document.', 'edusystem') ?></p>

                <?php if ($field_errors) { ?>
                    <div id="document-fields-errors" role="alert" style="color: #b32d2e; margin-bottom: 1rem">
                        <?php foreach ($field_errors as $error) { ?>
                            <div><?= esc_html($error) ?></div>
                        <?php } ?>
                    </div>
                <?php } ?>

                <?= edusystem_render_document_fields($document_fields, $field_values) ?>

                <div style="text-align: center; margin-top: 2rem">
                    <button type="submit" class="submit button-create-enrollment"><?= esc_html__('Continue', 'edusystem') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
