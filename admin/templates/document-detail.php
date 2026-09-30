<div class="wrap">
    <?php if (isset($document) && !empty($document)): ?>
        <h2 style="margin-bottom:15px;"><?= esc_html__('Document details', 'wp-certificates'); ?></h2>
    <?php else: ?>
        <h2 style="margin-bottom:15px;"><?= esc_html__('Document', 'wp-certificates'); ?></h2>
    <?php endif; ?>

    <?php if (isset($_COOKIE['message']) && !empty($_COOKIE['message'])) { ?>
        <div class="notice notice-success is-dismissible">
            <p><?= esc_html(wp_unslash($_COOKIE['message'])); ?></p>
        </div>
        <?php setcookie('message', '', time(), '/'); ?>
    <?php } ?>
    <?php if (isset($_COOKIE['message-error']) && !empty($_COOKIE['message-error'])) { ?>
        <div class="notice notice-error is-dismissible">
            <p><?= esc_html(wp_unslash($_COOKIE['message-error'])); ?></p>
        </div>
        <?php setcookie('message-error', '', time(), '/'); ?>
    <?php } ?>
    <div style="display:flex;width:100%;">
        <a class="button button-outline-primary"
            href="<?= admin_url('admin.php?page=add_admin_form_documents_content'); ?>"><?= esc_html__('Back') ?></a>
    </div>

    <?php if (empty($document)): ?>
        <?php // Alta en dos pasos: aquí solo nombre y código; el resto se completa en la página de edición ?>
        <div class="postbox" style="max-width:640px;margin-top:20px;">
            <div class="inside">
                <form method="post" action="<?= esc_url(admin_url('admin.php?page=add_admin_form_documents_content&action=save_document')); ?>">
                    <?php wp_nonce_field('wpc_save_document'); ?>
                    <h3 style="margin-top:10px;"><?= esc_html__('New document', 'wp-certificates'); ?></h3>
                    <p class="description"><?= esc_html__('The document is created as inactive. Then you will be taken to its page to complete the content, the format and the rest of the settings, and to activate it.', 'wp-certificates'); ?></p>
                    <p>
                        <label for="wpc-new-title"><b><?= esc_html__('Name', 'wp-certificates'); ?></b><span class="text-danger">*</span></label><br>
                        <input type="text" id="wpc-new-title" name="title" class="regular-text" style="width:100%" required
                            value="<?= esc_attr(sanitize_text_field(wp_unslash($_GET['title'] ?? ''))); ?>">
                    </p>
                    <p>
                        <label for="wpc-new-code"><b><?= esc_html__('Code', 'wp-certificates'); ?></b><span class="text-danger">*</span></label><br>
                        <input type="text" id="wpc-new-code" name="document_identificator" class="regular-text" style="width:100%" required
                            value="<?= esc_attr(sanitize_text_field(wp_unslash($_GET['document_identificator'] ?? ''))); ?>">
                        <span class="description"><?= esc_html__('Unique. It is saved in capital letters with hyphens (e.g. OFFICIAL-TRANSCRIPT) and cannot be repeated.', 'wp-certificates'); ?></span>
                    </p>
                    <p style="text-align:right;margin-bottom:0;">
                        <button type="submit" class="button button-primary"><?= esc_html__('Create document', 'wp-certificates'); ?></button>
                    </p>
                </form>
            </div>
        </div>
    <?php else: ?>

    <div id="dashboard-widgets" class="metabox-holder admin-add-offer" style="width:100% !important">
        <div id="postbox-container-1" style="width:100% !important;">
            <div id="normal-sortables">
                <div id="metabox" class="postbox" style="width:100%;min-width:0px;">
                    <div class="inside">

                        <form method="post"
                            action="<?= admin_url('admin.php?page=add_admin_form_documents_content&action=save_document'); ?>"
                            enctype="multipart/form-data">
                            <?php wp_nonce_field('wpc_save_document'); ?>
                            <div>
                                <h3
                                    style="margin-top:20px;margin-bottom:0px;text-align:center; border-bottom: 1px solid #8080805c;">
                                    <b><?= esc_html__('Document Information', 'wp-certificates'); ?></b>
                                </h3>

                                <div style="margin: 18px;">
                                    <input type="hidden" name="document_id" value="<?= esc_attr($document->id ?? ''); ?>">

                                    <div style="font-weight:400; text-align: center;" class="space-offer">
                                        <input type="checkbox" name="status" id="status" <?= ($document->status == 1) ? 'checked' : ''; ?> style="width: auto !important">
                                        <label for="status"><b><?= esc_html__('Active', 'wp-certificates'); ?></b></label>
                                    </div>

                                    <div style="font-weight:400;" class="space-offer">
                                        <label for="title"><b><?= esc_html__('Name', 'wp-certificates'); ?></b><span
                                                class="text-danger">*</span></label><br>
                                        <input type="text" name="title" value="<?= esc_attr($document->title ?? ''); ?>" required>
                                    </div>

                                    <div style="font-weight:400;" class="space-offer">
                                        <label for="title"><b><?= esc_html__('Identifier (can be the name of the document)', 'wp-certificates'); ?></b><span
                                                class="text-danger">*</span></label><br>
                                        <input type="text" name="document_identificator" value="<?= esc_attr($document->document_identificator ?? ''); ?>" required>
                                    </div>

                                    <div style="font-weight:400;" class="space-offer">
                                        <label
                                            for="variables-select"><b><?= esc_html__('Variables', 'wp-certificates'); ?></b></label><br>
                                        <ul style="display: grid;grid-template-columns: 1fr 1fr;">
                                            <?php foreach ($variables as $key => $variable) { ?>
                                                <li><strong><?= $variable->text ?></strong>: <?= $variable->visual ?></li>
                                            <?php } ?>
                                        </ul>
                                    </div>

                                    <div style="font-weight:400;" class="space-offer">
                                        <label for="title"><b><?= esc_html__('Header', 'wp-certificates'); ?></b><span
                                                class="text-danger">*</span></label><br>
                                        <?= wp_editor(
                                            isset($document->header) ? wp_unslash($document->header) : '',
                                            'header',
                                            array(
                                                'textarea_name' => 'header',
                                                'media_buttons' => false,
                                                'teeny' => true
                                            )
                                        ); ?>
                                    </div>

                                    <div style="font-weight:400;" class="space-offer">
                                        <label for="title"><b><?= esc_html__('Content', 'wp-certificates'); ?></b><span
                                                class="text-danger">*</span></label><br>
                                        <?= wp_editor(
                                            isset($document->content) ? wp_unslash($document->content) : '',
                                            'content',
                                            array(
                                                'textarea_name' => 'content',
                                                'media_buttons' => false,
                                                'teeny' => true
                                            )
                                        ); ?>
                                    </div>

                                    <div style="font-weight:400;" class="space-offer">
                                        <label for="title"><b><?= esc_html__('Footer', 'wp-certificates'); ?></b><span
                                                class="text-danger">*</span></label><br>
                                        <?= wp_editor(
                                            isset($document->footer) ? wp_unslash($document->footer) : '',
                                            'footer',
                                            array(
                                                'textarea_name' => 'footer',
                                                'media_buttons' => false,
                                                'teeny' => true
                                            )
                                        ); ?>
                                    </div>

                                    <div style="font-weight:400; text-align: center;" class="space-offer">
                                        <input type="checkbox" name="signature_required" id="signature_required"
                                            <?= ($document->signature_required == 1) ? 'checked' : ''; ?>
                                            style="width: auto !important">
                                        <label
                                            for="signature_required"><b><?= esc_html__('This document will require signatures', 'wp-certificates'); ?></b></label>
                                    </div>

                                    <div style="font-weight:400; text-align: center;" class="space-offer">
                                        <input type="checkbox" name="graduated_required" id="graduated_required"
                                            <?= ($document->graduated_required == 1) ? 'checked' : ''; ?>
                                            style="width: auto !important">
                                        <label
                                            for="graduated_required"><b><?= esc_html__('This document requires that the student be a graduate', 'wp-certificates'); ?></b></label>
                                    </div>

                                    <div style="font-weight:400; text-align: center;" class="space-offer">
                                        <input type="checkbox" name="margin_required" id="margin_required"
                                            <?= ($document->margin_required == 1) ? 'checked' : ''; ?>
                                            style="width: auto !important">
                                        <label
                                            for="margin_required"><b><?= esc_html__('This document has margins (on all sides)', 'wp-certificates'); ?></b></label>
                                    </div>

                                    <div style="font-weight:400; text-align: center" class="space-offer">
                                        <label for="id_requisito"><b><?= esc_html__('ID Requirement for the admin (ID requisito)', 'wp-certificates'); ?></b></label><br>
                                        <input type="text" name="id_requisito" value="<?= esc_attr($document->id_requisito ?? ''); ?>">
                                    </div>

                                    <div style="font-weight:400; text-align: center" class="space-offer">
                                        <label for="type_file"><b><?= esc_html__('Type file', 'wp-certificates'); ?></b></label><br>
                                        <input type="text" name="type_file" value="<?= esc_attr($document->type_file ?? ''); ?>">
                                    </div>

                                    <div style="font-weight:400; text-align: center" class="space-offer">
                                        <label for="book"><b><?= esc_html__('Certificate book', 'wp-certificates'); ?></b></label><br>
                                        <select name="book" id="book">
                                            <option value="0"><?= esc_html__('Select a book', 'wp-certificates'); ?></option>
                                            <?php foreach ((array) $books as $book):
                                                $book_id = is_object($book) ? ($book->id ?? 0) : ($book['id'] ?? 0);
                                                $book_title = is_object($book) ? ($book->title ?? $book->name ?? $book_id) : ($book['title'] ?? $book['name'] ?? $book_id);
                                                if ((int) $book_id <= 0) {
                                                    continue;
                                                }
                                            ?>
                                                <option value="<?= esc_attr($book_id); ?>" <?= selected((int) ($document->book ?? 0), (int) $book_id, false); ?>>
                                                    <?= esc_html($book_title); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div style="font-weight:400; text-align: center" class="space-offer">
                                        <label for="hc"><b><?= esc_html__('Orientation', 'wp-certificates'); ?></b></label><br>
                                        <select name="orientation" required>
                                            <option value="portrait" <?= ($document->orientation == 'portrait' || !$document) ? 'selected' : ''; ?>><?= esc_html__('Portrait', 'wp-certificates') ?></option>
                                            <option value="landscape" <?= ($document->orientation == 'landscape') ? 'selected' : ''; ?>><?= esc_html__('Landscape', 'wp-certificates') ?></option>
                                        </select>
                                    </div>

                                    <div style="font-weight:400; text-align: center" class="space-offer">
                                        <label for="paper_format"><b><?= esc_html__('Paper Format', 'wp-certificates'); ?></b></label><br>
                                        <select name="paper_format" required>
                                            <option value="a4" <?= ($document->paper_format == 'a4' || !$document) ? 'selected' : ''; ?>>A4</option>
                                            <option value="a3" <?= ($document->paper_format == 'a3') ? 'selected' : ''; ?>>A3</option>
                                            <option value="letter" <?= ($document->paper_format == 'letter') ? 'selected' : ''; ?>>Letter</option>
                                            <option value="legal" <?= ($document->paper_format == 'legal') ? 'selected' : ''; ?>>Legal</option>
                                            <option value="tabloid" <?= ($document->paper_format == 'tabloid') ? 'selected' : ''; ?>>Tabloid</option>
                                            <option value="custom" <?= ($document->paper_format == 'custom') ? 'selected' : ''; ?>>Custom</option>
                                        </select>
                                    </div>

                                    <div style="font-weight:400; text-align: center" class="space-offer">
                                        <label for="hc"><b><?= esc_html__('Type', 'wp-certificates'); ?></b></label><br>
                                        <select name="type" required>
                                            <option value="managed" <?= ($document->type == 'managed' || !$document) ? 'selected' : ''; ?>><?= esc_html__('Managed', 'wp-certificates') ?></option>
                                            <option value="automatic" <?= ($document->type == 'automatic') ? 'selected' : ''; ?>><?= esc_html__('Automatic', 'wp-certificates') ?></option>
                                        </select>
                                    </div>

                                    <div style="font-weight:400; text-align: center" class="space-offer">
                                        <label for="unit"><b><?= esc_html__('Unit', 'wp-certificates'); ?></b></label><br>
                                        <select name="unit" required>
                                            <option value="mm" <?= ($document->unit == 'mm' || !$document) ? 'selected' : ''; ?>>mm (millimeters)</option>
                                            <option value="pt" <?= ($document->unit == 'pt') ? 'selected' : ''; ?>>pt (points)</option>
                                            <option value="cm" <?= ($document->unit == 'cm') ? 'selected' : ''; ?>>cm (centimeters)</option>
                                            <option value="in" <?= ($document->unit == 'in') ? 'selected' : ''; ?>>in (inches)</option>
                                            <option value="px" <?= ($document->unit == 'px') ? 'selected' : ''; ?>>px (pixels)</option>
                                        </select>
                                    </div>

                                    <div style="font-weight:400; text-align: center" class="space-offer">
                                        <label for="width_size"><b><?= esc_html__('Width size', 'wp-certificates'); ?></b></label><br>
                                        <input type="number" name="width_size" placeholder="210mm" step="0.01" value="<?= esc_attr($document->width_size ?? ''); ?>">
                                    </div>

                                    <div style="font-weight:400; text-align: center" class="space-offer">
                                        <label for="height_size"><b><?= esc_html__('Height size', 'wp-certificates'); ?></b></label><br>
                                        <input type="number" name="height_size" placeholder="287mm" step="0.01" value="<?= esc_attr($document->height_size ?? ''); ?>">
                                    </div>

                                    <div style="font-weight:400; text-align: center;" class="space-offer">
                                        <input type="checkbox" name="is_required" id="is_required" <?= ($document->is_required == 1) ? 'checked' : ''; ?> style="width: auto !important">
                                        <label for="is_required"><b><?= esc_html__('Is required', 'wp-certificates'); ?></b></label>
                                    </div>

                                    <div style="font-weight:400; text-align: center;" class="space-offer">
                                        <input type="checkbox" name="is_visible" id="is_visible" <?= ($document->is_visible == 1) ? 'checked' : ''; ?> style="width: auto !important">
                                        <label for="is_visible"><b><?= esc_html__('Is visible in documents page?', 'wp-certificates'); ?></b></label>
                                    </div>

                                    <?php if (!wpc_edusystem_signatures_active()) : ?>
                                    <div style="font-weight:400; text-align: center;" class="space-offer">
                                        <input type="checkbox" name="delete_signatures" id="delete_signatures" style="width: auto !important">
                                        <label for="delete_signatures" style="color: red"><b><?= esc_html__('When saving, all existing signatures will be deleted.', 'wp-certificates'); ?></b></label>
                                    </div>
                                    <?php endif; ?>

                                </div>
                            </div>

                            <?php if (function_exists('edusystem_get_document_fields')) {
                                $document_fields = $document ? edusystem_get_document_fields($document) : []; ?>
                                <details id="wpc-advanced" style="margin: 18px;" <?= $document_fields ? 'open' : '' ?>>
                                    <summary style="cursor: pointer"><b><?= esc_html__('Advanced', 'wp-certificates'); ?></b></summary>

                                    <h4 style="margin-bottom: 4px"><?= esc_html__('Additional fields', 'wp-certificates'); ?></h4>
                                    <p class="description" style="margin-top: 0">
                                        <?= esc_html__('They are requested before generating the document and the answers are only used to fill it in (they are only stored while the document is partially signed). Use {{key}} in the document to print the answer and, in fields with options, {{key_list}} to print all the options with (✓) on the selected ones. If the key is left empty, it is created from the label.', 'wp-certificates'); ?>
                                    </p>

                                    <table class="widefat striped" id="wpc-document-fields">
                                        <thead>
                                            <tr>
                                                <th><?= esc_html__('Label', 'wp-certificates'); ?></th>
                                                <th><?= esc_html__('Key', 'wp-certificates'); ?></th>
                                                <th><?= esc_html__('Type', 'wp-certificates'); ?></th>
                                                <th><?= esc_html__('Options (one per line)', 'wp-certificates'); ?></th>
                                                <th style="text-align: center"><?= esc_html__('Required', 'wp-certificates'); ?></th>
                                                <th><span class="screen-reader-text"><?= esc_html__('Actions', 'wp-certificates'); ?></span></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($document_fields as $index => $field) {
                                                echo wpc_document_field_row($index, $field);
                                            } ?>
                                        </tbody>
                                    </table>
                                    <p><button type="button" class="button" id="wpc-add-document-field"><?= esc_html__('+ Add field', 'wp-certificates'); ?></button></p>
                                    <template id="wpc-document-field-template"><?= wpc_document_field_row('__INDEX__'); ?></template>
                                </details>
                            <?php } ?>

                            <?php if (isset($document) && !empty($document)): ?>
                                <div style="margin-top:20px;display:flex;flex-direction:row;justify-content:end;gap:5px;">
                                    <button type="submit"
                                        class="button button-primary"><?= esc_html__('Saves changes', 'wp-certificates'); ?></button>
                                </div>
                            <?php else: ?>
                                <div style="margin-top:20px;display:flex;flex-direction:row;justify-content:end;gap:5px;">
                                    <button type="submit"
                                        class="button button-primary"><?= esc_html__('Add document', 'wp-certificates'); ?></button>
                                </div>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php
    // Vista previa: un plugin puede sustituirla (EduSystem la muestra en PDF, con datos de ejemplo)
    $custom_preview = (isset($document) && !empty($document)) ? (string) apply_filters('wpc_document_preview', '', $document) : '';
    ?>
    <?php if ('' !== $custom_preview): ?>
        <?= $custom_preview; ?>
    <?php elseif (isset($document) && !empty($document)): ?>
        <div>
            <div style="text-align: center">
                <h2 style="margin-bottom:15px;"><?= esc_html__('Preview', 'wp-certificates'); ?></h2>
            </div>
            <div style="width: 210mm !important; margin: auto; background-color: #fff;">
                <?= $html_document; ?>
            </div>
        </div>
    <?php endif; ?>

</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const paperFormatSelect = document.querySelector('select[name="paper_format"]');
        const documentType = document.querySelector('select[name="type"]');
        const unitSection = document.querySelector('div.space-offer:has(label[for="unit"])');
        const widthSection = document.querySelector('div.space-offer:has(label[for="width_size"])');
        const heightSection = document.querySelector('div.space-offer:has(label[for="height_size"])');
        const isRequired = document.querySelector('div.space-offer:has(label[for="is_required"])');
        const isVisible = document.querySelector('div.space-offer:has(label[for="is_visible"])');
        if (!paperFormatSelect || !documentType) return; // alta: formulario corto sin estos campos

        function toggleCustomSizeFields() {
            const isCustom = paperFormatSelect.value === 'custom';
            unitSection.style.display = isCustom ? 'block' : 'none';
            widthSection.style.display = isCustom ? 'block' : 'none';
            heightSection.style.display = isCustom ? 'block' : 'none';

            const isAutomatic = documentType.value === 'automatic';
            isRequired.style.display = isAutomatic ? 'block' : 'none';
            isVisible.style.display = isAutomatic ? 'block' : 'none';
        }

        // Ejecuta la función al cargar la página para establecer el estado inicial
        toggleCustomSizeFields();

        // Escucha el evento 'change' en el select para actualizar la visibilidad
        paperFormatSelect.addEventListener('change', toggleCustomSizeFields);
        documentType.addEventListener('change', toggleCustomSizeFields);
    });
</script>