<?php // Diseño Edusof activo: la pantalla se dibuja con el marcado nuevo (eds-document-detail.php); si no, como siempre
if (wpc_eds_documents_enabled()) {
    include __DIR__ . '/eds-document-detail.php';
    return;
} ?>
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
    <?php // Documento automático que no se mostrará en Mi Cuenta (ni pide firma ni tiene campos adicionales)
    $automatic_status = !empty($document) ? squuad_cert_automatic_status($document) : null;
    if ($automatic_status && 'automatic' === $document->type && !$automatic_status['signature'] && !$automatic_status['fields']) : ?>
        <div class="notice notice-warning">
            <p><?= esc_html(sprintf(__('This automatic document will not be shown in My Account: %s. It must ask someone to sign it (a signature variable in the template and signers in "Document signers") or have additional fields.', 'wp-certificates'), implode('; ', $automatic_status['reasons']))); ?></p>
        </div>
    <?php endif; ?>
    <div style="display:flex;width:100%;">
        <a class="button button-outline-primary"
            href="<?= admin_url('admin.php?page=add_admin_form_documents_content'); ?>"><?= esc_html__('Back', 'wp-certificates') ?></a>
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
                                        <input type="text" name="title" id="title" value="<?= esc_attr($document->title ?? ''); ?>" required>
                                    </div>

                                    <div style="font-weight:400;" class="space-offer">
                                        <label for="document_identificator"><b><?= esc_html__('Identifier (can be the name of the document)', 'wp-certificates'); ?></b><span
                                                class="text-danger">*</span></label><br>
                                        <input type="text" name="document_identificator" id="document_identificator" value="<?= esc_attr($document->document_identificator ?? ''); ?>" required>
                                    </div>

                                    <?php // Variables generales: definidas en el código de wp-certificates, sirven en cualquier sitio (Antigravity/variable.md) ?>
                                    <div style="font-weight:400;" class="space-offer">
                                        <b><?= esc_html__('General variables', 'wp-certificates'); ?></b><br>
                                        <span class="description"><?= esc_html__('Defined by WP Certificates: they work on any site.', 'wp-certificates'); ?></span>
                                        <ul style="display: grid;grid-template-columns: 1fr 1fr;">
                                            <?php foreach (\Squuad\Certificados\Variables::general() as $variable_visual => $variable_text) { ?>
                                                <li><strong><?= esc_html($variable_text) ?></strong>: <?= esc_html($variable_visual) ?></li>
                                            <?php } ?>
                                        </ul>
                                    </div>

                                    <div style="font-weight:400;" class="space-offer">
                                        <b><?= esc_html__('Variables', 'wp-certificates'); ?></b><br>
                                        <ul style="display: grid;grid-template-columns: 1fr 1fr;">
                                            <?php // Solo las variables a las que da valor un plugin activo o el titular (como el panel «Datos»)
                                            $wpc_available = \Squuad\Certificados\VariableMethods::available();
                                            $wpc_methods = \Squuad\Certificados\VariableMethods::all();
                                            $wpc_holder = squuad_cert_holder_catalog();
                                            $wpc_keys = [];
                                            foreach ($variables as $key => $variable) {
                                                $wpc_keys[(string) $variable->identificator] = true;
                                                if (!squuad_cert_catalog_variable_offered(squuad_cert_catalog_variable_status($variable, $wpc_available, $wpc_holder))) {
                                                    continue;
                                                } ?>
                                                <li><strong><?= esc_html(squuad_cert_variable_display_text($variable, $wpc_methods)) ?></strong>: <?= esc_html($variable->visual) ?></li>
                                            <?php }
                                            foreach (squuad_cert_person_variables_offered($wpc_available) as $wpc_key => $wpc_person) {
                                                if (!isset($wpc_keys[$wpc_key])) { ?>
                                                    <li><strong><?= esc_html($wpc_person['label']) ?></strong>: <?= esc_html('{{' . $wpc_key . '}}') ?></li>
                                                <?php }
                                            } ?>
                                        </ul>
                                    </div>

                                    <div style="font-weight:400;" class="space-offer">
                                        <label for="header"><b><?= esc_html__('Header', 'wp-certificates'); ?></b><span
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
                                        <label for="content"><b><?= esc_html__('Content', 'wp-certificates'); ?></b><span
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
                                        <label for="footer"><b><?= esc_html__('Footer', 'wp-certificates'); ?></b><span
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

                                    <?php if (wpc_edusystem_active()) { // Graduado: dato de EduSystem ?>
                                    <div style="font-weight:400; text-align: center;" class="space-offer">
                                        <input type="checkbox" name="graduated_required" id="graduated_required"
                                            <?= ($document->graduated_required == 1) ? 'checked' : ''; ?>
                                            style="width: auto !important">
                                        <label
                                            for="graduated_required"><b><?= esc_html__('This document requires that the student be a graduate', 'wp-certificates'); ?></b></label>
                                    </div>
                                    <?php } elseif (1 === (int) ($document->graduated_required ?? 0)) { // sin EduSystem no se muestra, pero se conserva ?>
                                        <input type="hidden" name="graduated_required" value="on">
                                    <?php } ?>

                                    <div style="font-weight:400; text-align: center;" class="space-offer">
                                        <input type="checkbox" name="margin_required" id="margin_required"
                                            <?= ($document->margin_required == 1) ? 'checked' : ''; ?>
                                            style="width: auto !important">
                                        <label
                                            for="margin_required"><b><?= esc_html__('This document has margins (on all sides)', 'wp-certificates'); ?></b></label>
                                    </div>

                                    <?php if (wpc_edusystem_active()) { // Requisito y tipo de archivo: conceptos de los requisitos de EduSystem ?>
                                    <div style="font-weight:400; text-align: center" class="space-offer">
                                        <label for="id_requisito"><b><?= esc_html__('ID Requirement for the admin (ID requisito)', 'wp-certificates'); ?></b></label><br>
                                        <input type="text" name="id_requisito" id="id_requisito" value="<?= esc_attr($document->id_requisito ?? ''); ?>">
                                    </div>

                                    <div style="font-weight:400; text-align: center" class="space-offer">
                                        <label for="type_file"><b><?= esc_html__('Type file', 'wp-certificates'); ?></b></label><br>
                                        <input type="text" name="type_file" id="type_file" value="<?= esc_attr($document->type_file ?? ''); ?>">
                                    </div>
                                    <?php } else { // sin EduSystem no se muestran, pero se conservan al guardar ?>
                                        <input type="hidden" name="id_requisito" value="<?= esc_attr($document->id_requisito ?? ''); ?>">
                                        <input type="hidden" name="type_file" value="<?= esc_attr($document->type_file ?? ''); ?>">
                                    <?php } ?>

                                    <div style="font-weight:400; text-align: center" class="space-offer">
                                        <label for="book"><b><?= esc_html__('Registry book', 'wp-certificates'); ?></b></label><br>
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
                                        <label for="book_line_description"><b><?= esc_html__('Registry book line description', 'wp-certificates'); ?></b></label><br>
                                        <textarea name="book_line_description" id="book_line_description" rows="3" style="width: 100%;" placeholder="<?= esc_attr(squuad_cert_book_line_default()); ?>"><?= esc_textarea($document->book_line_description ?? ''); ?></textarea>
                                        <p class="description"><?= esc_html(sprintf(__('Text of the line registered in the book when the document is issued. It accepts the same variables as the document, except {{tomo}}, {{folio}}, {{tomo_folio}} and {{qrcode}}, and is sent as plain text (maximum %d characters). If a variable has no value, the document is not issued. Empty: the text shown as an example is used.', 'wp-certificates'), SQUUAD_CERT_BOOK_LINE_MAX)); ?></p>
                                    </div>

                                    <?php if (function_exists('squuad_cert_signature_sheet_document_fields')) {
                                        squuad_cert_signature_sheet_document_fields($document ?: null, true);
                                    } ?>

                                    <div style="font-weight:400; text-align: center" class="space-offer">
                                        <label for="orientation"><b><?= esc_html__('Orientation', 'wp-certificates'); ?></b></label><br>
                                        <select name="orientation" id="orientation" required>
                                            <option value="portrait" <?= ($document->orientation == 'portrait' || !$document) ? 'selected' : ''; ?>><?= esc_html__('Portrait', 'wp-certificates') ?></option>
                                            <option value="landscape" <?= ($document->orientation == 'landscape') ? 'selected' : ''; ?>><?= esc_html__('Landscape', 'wp-certificates') ?></option>
                                        </select>
                                    </div>

                                    <div style="font-weight:400; text-align: center" class="space-offer">
                                        <label for="paper_format"><b><?= esc_html__('Paper Format', 'wp-certificates'); ?></b></label><br>
                                        <select name="paper_format" id="paper_format" required>
                                            <option value="a4" <?= ($document->paper_format == 'a4' || !$document) ? 'selected' : ''; ?>>A4</option>
                                            <option value="a3" <?= ($document->paper_format == 'a3') ? 'selected' : ''; ?>>A3</option>
                                            <option value="letter" <?= ($document->paper_format == 'letter') ? 'selected' : ''; ?>><?= esc_html__('Letter', 'wp-certificates'); ?></option>
                                            <option value="legal" <?= ($document->paper_format == 'legal') ? 'selected' : ''; ?>><?= esc_html__('Legal', 'wp-certificates'); ?></option>
                                            <option value="tabloid" <?= ($document->paper_format == 'tabloid') ? 'selected' : ''; ?>><?= esc_html__('Tabloid', 'wp-certificates'); ?></option>
                                            <option value="custom" <?= ($document->paper_format == 'custom') ? 'selected' : ''; ?>><?= esc_html__('Custom', 'wp-certificates'); ?></option>
                                        </select>
                                    </div>

                                    <div style="font-weight:400; text-align: center" class="space-offer">
                                        <label for="type"><b><?= esc_html__('Type', 'wp-certificates'); ?></b></label><br>
                                        <select name="type" id="type" required>
                                            <option value="managed" <?= ($document->type == 'managed' || !$document) ? 'selected' : ''; ?>><?= esc_html__('Managed', 'wp-certificates') ?></option>
                                            <option value="automatic" <?= ($document->type == 'automatic') ? 'selected' : ''; ?>><?= esc_html__('Automatic', 'wp-certificates') ?></option>
                                        </select>
                                    </div>

                                    <div style="font-weight:400; text-align: center" class="space-offer">
                                        <label for="unit"><b><?= esc_html__('Unit', 'wp-certificates'); ?></b></label><br>
                                        <select name="unit" id="unit" required>
                                            <option value="mm" <?= ($document->unit == 'mm' || !$document) ? 'selected' : ''; ?>><?= esc_html__('mm (millimeters)', 'wp-certificates'); ?></option>
                                            <option value="pt" <?= ($document->unit == 'pt') ? 'selected' : ''; ?>><?= esc_html__('pt (points)', 'wp-certificates'); ?></option>
                                            <option value="cm" <?= ($document->unit == 'cm') ? 'selected' : ''; ?>><?= esc_html__('cm (centimeters)', 'wp-certificates'); ?></option>
                                            <option value="in" <?= ($document->unit == 'in') ? 'selected' : ''; ?>><?= esc_html__('in (inches)', 'wp-certificates'); ?></option>
                                            <option value="px" <?= ($document->unit == 'px') ? 'selected' : ''; ?>><?= esc_html__('px (pixels)', 'wp-certificates'); ?></option>
                                        </select>
                                    </div>

                                    <div style="font-weight:400; text-align: center" class="space-offer">
                                        <label for="width_size"><b><?= esc_html__('Width size', 'wp-certificates'); ?></b></label><br>
                                        <input type="number" name="width_size" id="width_size" placeholder="210mm" step="0.01" value="<?= esc_attr($document->width_size ?? ''); ?>">
                                    </div>

                                    <div style="font-weight:400; text-align: center" class="space-offer">
                                        <label for="height_size"><b><?= esc_html__('Height size', 'wp-certificates'); ?></b></label><br>
                                        <input type="number" name="height_size" id="height_size" placeholder="287mm" step="0.01" value="<?= esc_attr($document->height_size ?? ''); ?>">
                                    </div>

                                    <div style="font-weight:400; text-align: center;" class="space-offer">
                                        <input type="checkbox" name="is_required" id="is_required" <?= ($document->is_required == 1) ? 'checked' : ''; ?> style="width: auto !important">
                                        <label for="is_required"><b><?= esc_html__('Is required', 'wp-certificates'); ?></b></label>
                                    </div>

                                    <div style="font-weight:400; text-align: center;" class="space-offer">
                                        <input type="checkbox" name="is_visible" id="is_visible" <?= ($document->is_visible == 1) ? 'checked' : ''; ?> style="width: auto !important">
                                        <label for="is_visible"><b><?= esc_html__('Is visible in documents page?', 'wp-certificates'); ?></b></label>
                                    </div>

                                    <div style="font-weight:400; text-align: center;" class="space-offer">
                                        <label for="priority"><b><?= esc_html__('Priority', 'wp-certificates'); ?></b></label><br>
                                        <input type="number" name="priority" id="priority" min="0" max="<?= (int) SQUUAD_CERT_PRIORITY_MAX ?>" step="1" value="<?= (int) ($document->priority ?? 0); ?>" style="width: 90px">
                                        <p class="description"><?= esc_html__('Order in My Account when several automatic documents are pending: 0 is the most urgent; with the same priority, the oldest first.', 'wp-certificates'); ?></p>
                                    </div>


                                </div>
                            </div>

                            <?php if (function_exists('squuad_cert_get_document_fields')) {
                                $document_fields = $document ? squuad_cert_get_document_fields($document) : []; ?>
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
        const priority = document.querySelector('div.space-offer:has(label[for="priority"])');
        if (!paperFormatSelect || !documentType) return; // alta: formulario corto sin estos campos

        function toggleCustomSizeFields() {
            const isCustom = paperFormatSelect.value === 'custom';
            unitSection.style.display = isCustom ? 'block' : 'none';
            widthSection.style.display = isCustom ? 'block' : 'none';
            heightSection.style.display = isCustom ? 'block' : 'none';

            const isAutomatic = documentType.value === 'automatic';
            isRequired.style.display = isAutomatic ? 'block' : 'none';
            isVisible.style.display = isAutomatic ? 'block' : 'none';
            if (priority) priority.style.display = isAutomatic ? 'block' : 'none';
        }

        // Ejecuta la función al cargar la página para establecer el estado inicial
        toggleCustomSizeFields();

        // Escucha el evento 'change' en el select para actualizar la visibilidad
        paperFormatSelect.addEventListener('change', toggleCustomSizeFields);
        documentType.addEventListener('change', toggleCustomSizeFields);
    });
</script>