<?php
/**
 * Editor del documento con el diseño Edusof, fase 0 (lo incluye document-detail.php solo si wpc_eds_documents_enabled()).
 * Mismo formulario, acción, nonce y nombres de campo que el de siempre; cambia el marcado: secciones con título, editor
 * de código (wp.codeEditor del core) en lugar de TinyMCE, panel «Datos» con buscador y un solo interruptor de firmas.
 * Variables: $document, $variables, $books, $html_document (admin/documents.php).
 */
if (!defined('ABSPATH')) exit;

$list_url = admin_url('admin.php?page=add_admin_form_documents_content');
$save_url = admin_url('admin.php?page=add_admin_form_documents_content&action=save_document');
?>
<div class="wrap eds-page wpc-eds-document">
<?php if (empty($document)) : ?>
    <?php // Alta en dos pasos: aquí solo nombre y código; el resto se completa en la página de edición ?>
    <header class="eds-page-header">
        <nav class="eds-breadcrumb" aria-label="<?= esc_attr__('Breadcrumb', 'wp-certificates') ?>">
            <?= esc_html__('Certification', 'wp-certificates') ?> / <a href="<?= esc_url($list_url) ?>"><?= esc_html__('Documents', 'wp-certificates') ?></a> / <span aria-current="page"><?= esc_html__('New document', 'wp-certificates') ?></span>
        </nav>
        <h1 class="eds-title"><?= esc_html__('New document', 'wp-certificates') ?></h1>
        <p class="eds-subtitle"><?= esc_html__('The document is created as inactive. Then you will be taken to its page to complete the content, the format and the rest of the settings, and to activate it.', 'wp-certificates') ?></p>
        <div class="eds-actions">
            <a class="eds-btn eds-btn--secondary" href="<?= esc_url($list_url) ?>"><?= esc_html__('Back', 'wp-certificates') ?></a>
        </div>
    </header>
    <hr class="wp-header-end">
    <?php if (!empty($_COOKIE['message-error'])) { ?>
        <div class="eds-notice eds-notice--bad" role="alert"><p><?= esc_html(wp_unslash($_COOKIE['message-error'])) ?></p></div>
        <?php setcookie('message-error', '', time(), '/'); ?>
    <?php } ?>
    <section class="eds-card wpc-eds-narrow">
        <form method="post" action="<?= esc_url($save_url) ?>" class="wpc-eds-form">
            <?php wp_nonce_field('wpc_save_document'); ?>
            <div class="wpc-eds-field">
                <label for="wpc-new-title"><?= esc_html__('Name', 'wp-certificates') ?> <span aria-hidden="true">*</span></label>
                <input type="text" id="wpc-new-title" name="title" required value="<?= esc_attr(sanitize_text_field(wp_unslash($_GET['title'] ?? ''))) ?>">
            </div>
            <div class="wpc-eds-field">
                <label for="wpc-new-code"><?= esc_html__('Code', 'wp-certificates') ?> <span aria-hidden="true">*</span></label>
                <input type="text" id="wpc-new-code" name="document_identificator" required aria-describedby="wpc-new-code-help" value="<?= esc_attr(sanitize_text_field(wp_unslash($_GET['document_identificator'] ?? ''))) ?>">
                <p class="wpc-eds-help" id="wpc-new-code-help"><?= esc_html__('Unique. It is saved in capital letters with hyphens (e.g. OFFICIAL-TRANSCRIPT) and cannot be repeated.', 'wp-certificates') ?></p>
            </div>
            <div class="wpc-eds-form__actions">
                <button type="submit" class="eds-btn eds-btn--primary"><?= esc_html__('Create document', 'wp-certificates') ?></button>
            </div>
        </form>
    </section>
<?php else :
    $automatic = 'automatic' === $document->type;
    $signing = wpc_document_signing_summary($document);
    // El panel «Firmantes del documento» (certification/admin/document-signing.php) es el interruptor de firmas
    $signers_panel = function_exists('squuad_cert_signers_enabled') && squuad_cert_signers_enabled()
        && defined('SQUUAD_CERT_MANAGE_SIGNING_POLICIES_CAP') && current_user_can(SQUUAD_CERT_MANAGE_SIGNING_POLICIES_CAP);
    $pending_automatic = wpc_document_automatic_pending_count((string) $document->document_identificator);
    $total_students = wpc_edusystem_active() ? (int) $GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->prefix}students") : 0;
    $document_fields = function_exists('squuad_cert_get_document_fields') ? squuad_cert_get_document_fields($document) : [];

    // Panel «Datos»: variables agrupadas (las generales del código, las de la tabla variables_document y las del titular)
    $groups = [
        'person' => [__('Person', 'wp-certificates'), []],
        'parent' => [__('Parent or guardian', 'wp-certificates'), []],
        'program' => [__('Program and term', 'wp-certificates'), []],
        'background' => [__('Previous studies', 'wp-certificates'), []],
        'payments' => [__('Payments', 'wp-certificates'), []],
        'tables' => [__('Tables', 'wp-certificates'), []],
        'school' => [__('School', 'wp-certificates'), []],
        'document' => [__('This document', 'wp-certificates'), []],
        'signatures' => [__('Signatures', 'wp-certificates'), []],
        'validation' => [__('Validation and registry book', 'wp-certificates'), []],
        'layout' => [__('Layout', 'wp-certificates'), []],
        'fields' => [__('Additional fields of this document', 'wp-certificates'), []],
    ];
    $general_group = static function (string $variable): string {
        if (preg_match('/qrcode|tomo|folio/', $variable)) {
            return 'validation';
        }
        if (preg_match('/signature|sign|signer|position_user_charge/', $variable)) {
            return 'signatures';
        }
        if (false !== strpos($variable, 'page_break')) {
            return 'layout';
        }
        if (false !== strpos($variable, '{{key}}')) {
            return '';
        }

        return 'document';
    };
    foreach (\Squuad\Certificados\Variables::general() as $variable => $description) {
        $group = $general_group((string) $variable);
        if ('' === $group) {
            continue; // los campos adicionales se listan uno a uno abajo
        }
        // Las familias (_N, _ID) no se insertan tal cual: se muestran como referencia
        $groups[$group][1][] = ['var' => (string) $variable, 'label' => (string) $description, 'insert' => false === strpos((string) $variable, ',')];
    }
    // Solo se ofrecen las variables a las que da valor un plugin activo (o el titular, p. ej. la cuenta de WordPress);
    // las demás siguen en Certificación > Variables, marcadas
    $available_methods = \Squuad\Certificados\VariableMethods::available();
    // Descripciones de los métodos en el idioma del usuario: la de la tabla se guardó en inglés (squuad_cert_variable_display_text())
    $all_methods = \Squuad\Certificados\VariableMethods::all();
    $holder_catalog = squuad_cert_holder_catalog();
    $catalog_keys = [];
    foreach ((array) $variables as $variable) {
        $key = (string) $variable->identificator;
        $catalog_keys[$key] = true;
        if (!squuad_cert_catalog_variable_offered(squuad_cert_catalog_variable_status($variable, $available_methods, $holder_catalog))) {
            continue;
        }
        if (preg_match('/^parent_|show_parent_info/', $key)) {
            $group = 'parent';
        } elseif (preg_match('/^institute_/', $key)) {
            $group = 'school';
        } elseif (preg_match('/^institution_|educational_background/', $key)) {
            $group = 'background';
        } elseif (false !== strpos($key, 'payment')) {
            $group = 'payments';
        } elseif (false !== strpos($key, 'table') || 'admission_requirements_table' === $key) {
            $group = 'tables';
        } elseif (preg_match('/program|career|academic_year|_term_/', $key)) {
            $group = 'program';
        } else {
            $group = 'person';
        }
        $groups[$group][1][] = ['var' => (string) $variable->visual, 'label' => squuad_cert_variable_display_text($variable, $all_methods), 'insert' => true];
    }
    // Variables de la persona ({{full_name}}…) que no están en la lista y tienen quien les dé valor (EduSystem o la cuenta)
    foreach (squuad_cert_person_variables_offered($available_methods) as $key => $person) {
        if (!isset($catalog_keys[$key])) {
            $groups['person'][1][] = ['var' => '{{' . $key . '}}', 'label' => $person['label'], 'insert' => true];
        }
    }
    foreach ($document_fields as $field) {
        if (empty($field['key'])) {
            continue;
        }
        $groups['fields'][1][] = ['var' => '{{' . $field['key'] . '}}', 'label' => (string) ($field['label'] ?? $field['key']), 'insert' => true];
    }
    // Variables por firmante numeradas (ADR 0010 de Edusof): un grupo por firmante del panel, con su número y su color,
    // justo después de «Firmas». F1 es quien recibe el documento (sus datos son los de siempre: {{full_name}}…)
    $fn_groups = [];
    if (function_exists('squuad_cert_fn_document_signers')) {
        foreach (squuad_cert_fn_document_signers($document) as $fn => $fn_signer) {
            $fn_items = [
                ['var' => '{{full_name_F' . $fn . '}}', 'label' => 1 === $fn ? __('Full name (same as {{full_name}})', 'wp-certificates') : __('Full name (last names, first names)', 'wp-certificates'), 'insert' => true],
                ['var' => '{{name_F' . $fn . '}}', 'label' => __('First names', 'wp-certificates'), 'insert' => true],
                ['var' => '{{last_name_F' . $fn . '}}', 'label' => __('Last names', 'wp-certificates'), 'insert' => true],
                ['var' => '{{email_F' . $fn . '}}', 'label' => __('Email of their account', 'wp-certificates'), 'insert' => true],
                ['var' => '{{id_document_F' . $fn . '}}', 'label' => __('Identity document (if the site asks for it)', 'wp-certificates'), 'insert' => true],
                ['var' => '{{charge_F' . $fn . '}}', 'label' => __('Position or role', 'wp-certificates'), 'insert' => true],
                ['var' => '{{signature_F' . $fn . '}}', 'label' => __('Signature box', 'wp-certificates'), 'insert' => true],
                ['var' => '{{#F' . $fn . '}}{{/F' . $fn . '}}', 'label' => __('Text shown only if this signer is in the request', 'wp-certificates'), 'insert' => true],
            ];
            /* translators: 1: number, e.g. F2, 2: name of the signer */
            $fn_groups['fn' . $fn] = [sprintf(__('%1$s · %2$s', 'wp-certificates'), 'F' . $fn, $fn_signer['label']), $fn_items, $fn];
        }
    }
    if ($fn_groups) {
        $position = array_search('signatures', array_keys($groups), true);
        $groups = array_slice($groups, 0, (int) $position + 1, true) + $fn_groups + array_slice($groups, (int) $position + 1, null, true);
    }
    $code_parts = [
        'header' => __('Header', 'wp-certificates'),
        'content' => __('Content', 'wp-certificates'),
        'footer' => __('Footer', 'wp-certificates'),
    ];
    ?>
    <header class="eds-page-header">
        <nav class="eds-breadcrumb" aria-label="<?= esc_attr__('Breadcrumb', 'wp-certificates') ?>">
            <?= esc_html__('Certification', 'wp-certificates') ?> / <a href="<?= esc_url($list_url) ?>"><?= esc_html__('Documents', 'wp-certificates') ?></a> / <span aria-current="page"><?= esc_html((string) $document->title) ?></span>
        </nav>
        <h1 class="eds-title"><?= esc_html((string) $document->title) ?>
            <?php if (1 === (int) $document->status) { ?>
                <span class="eds-badge eds-badge--ok"><?= esc_html__('Active', 'wp-certificates') ?></span>
            <?php } else { ?>
                <span class="eds-badge eds-badge--neutral"><?= esc_html__('Inactive', 'wp-certificates') ?></span>
            <?php } ?>
        </h1>
        <p class="eds-subtitle">
            <?= esc_html__('Code', 'wp-certificates') ?>: <code><?= esc_html((string) $document->document_identificator) ?></code>
            · <?= esc_html($automatic ? __('Signed by the person in their account', 'wp-certificates') : __('Issued by the school office', 'wp-certificates')) ?>
            · <?= esc_html($signing['asks'] ? $signing['label'] : __('Does not ask for signatures', 'wp-certificates')) ?>
        </p>
        <div class="eds-actions">
            <a class="eds-btn eds-btn--secondary" href="<?= esc_url($list_url) ?>"><?= esc_html__('Back', 'wp-certificates') ?></a>
            <button type="submit" form="wpc-document-form" class="eds-btn eds-btn--primary"><?= esc_html__('Save changes', 'wp-certificates') ?></button>
        </div>
    </header>
    <hr class="wp-header-end">

    <?php if (!empty($_COOKIE['message'])) { ?>
        <div class="eds-notice eds-notice--ok" role="status"><p><?= esc_html(wp_unslash($_COOKIE['message'])) ?></p></div>
        <?php setcookie('message', '', time(), '/'); ?>
    <?php } ?>
    <?php if (!empty($_COOKIE['message-error'])) { ?>
        <div class="eds-notice eds-notice--bad" role="alert"><p><?= esc_html(wp_unslash($_COOKIE['message-error'])) ?></p></div>
        <?php setcookie('message-error', '', time(), '/'); ?>
    <?php } ?>
    <?php // Campo adicional ya existente llamado como una variable nueva del sistema ({{full_name}}): gana el campo
    foreach (function_exists('squuad_cert_document_fields_shadowed_keys') ? squuad_cert_document_fields_shadowed_keys($document) : [] as $shadowed_key) : ?>
        <div class="eds-notice eds-notice--warn"><p><?= esc_html(sprintf(
            /* translators: %s: key of the field, e.g. full_name */
            __('This document has a field named %s that matches a system variable; the answer of the field is used.', 'wp-certificates'),
            $shadowed_key
        )) ?></p></div>
    <?php endforeach; ?>
    <?php // Avisos de otros módulos (p. ej. variables por firmante numeradas, ADR 0010 de Edusof)
    do_action('wpc_document_notices', $document); ?>
    <?php // Documento automático que no se mostrará en Mi Cuenta (ni pide firma ni tiene campos adicionales)
    $automatic_status = squuad_cert_automatic_status($document);
    if ($automatic && $automatic_status && !$automatic_status['signature'] && !$automatic_status['fields']) : ?>
        <div class="eds-notice eds-notice--warn"><p><?= esc_html(sprintf(__('This automatic document will not be shown in My Account: %s. It must ask someone to sign it (a signature variable in the template and signers in "Document signers") or have additional fields.', 'wp-certificates'), implode('; ', $automatic_status['reasons']))) ?></p></div>
    <?php endif; ?>

    <nav class="wpc-eds-index" aria-label="<?= esc_attr__('Sections of the document', 'wp-certificates') ?>">
        <a href="#wpc-sec-content"><?= esc_html__('Content', 'wp-certificates') ?></a>
        <a href="#wpc-sec-page"><?= esc_html__('Page and printing', 'wp-certificates') ?></a>
        <a href="#wpc-sec-delivery"><?= esc_html__('Delivery', 'wp-certificates') ?></a>
        <a href="#wpc-sec-book"><?= esc_html__('Registry book', 'wp-certificates') ?></a>
        <a href="#wpc-sec-advanced"><?= esc_html__('Advanced', 'wp-certificates') ?></a>
        <?php if ($signers_panel) { ?><a href="#edusystem-document-signers"><?= esc_html__('Signatures', 'wp-certificates') ?></a><?php } ?>
        <a href="#wpc-sec-preview"><?= esc_html__('Preview', 'wp-certificates') ?></a>
    </nav>

    <form method="post" action="<?= esc_url($save_url) ?>" enctype="multipart/form-data" id="wpc-document-form" class="wpc-eds-form"
        data-automatic-pending="<?= (int) $pending_automatic ?>"
        data-total-students="<?= (int) $total_students ?>"
        data-code="<?= esc_attr((string) $document->document_identificator) ?>">
        <?php wp_nonce_field('wpc_save_document'); ?>
        <input type="hidden" name="document_id" value="<?= (int) $document->id ?>">
        <input type="hidden" name="wpc_eds_form" value="1">
        <input type="hidden" name="wpc_eds_automatic_confirmed" value="" id="wpc-eds-automatic-confirmed">
        <?php // Mapa Fn → firmante con el que se abrió la plantilla: si el panel cambia mientras se edita, al guardar se renumera (ADR 0010 de Edusof) ?>
        <input type="hidden" name="wpc_fn_map" value="<?= esc_attr(function_exists('squuad_cert_fn_map') ? (string) wp_json_encode((object) squuad_cert_fn_map($document)) : '') ?>">

        <section class="eds-card" id="wpc-sec-content" aria-labelledby="wpc-sec-content-title">
            <h2 class="wpc-eds-section-title" id="wpc-sec-content-title"><?= esc_html__('Content', 'wp-certificates') ?></h2>
            <div class="wpc-eds-grid">
                <div class="wpc-eds-field">
                    <label for="title"><?= esc_html__('Name', 'wp-certificates') ?> <span aria-hidden="true">*</span></label>
                    <input type="text" name="title" id="title" value="<?= esc_attr((string) $document->title) ?>" required>
                </div>
                <div class="wpc-eds-field">
                    <label for="document_identificator"><?= esc_html__('Code', 'wp-certificates') ?> <span aria-hidden="true">*</span></label>
                    <input type="text" name="document_identificator" id="document_identificator" value="<?= esc_attr((string) $document->document_identificator) ?>" required aria-describedby="document_identificator-help">
                    <p class="wpc-eds-help" id="document_identificator-help"><?= wpc_edusystem_active() ? esc_html__('It links the document with the requirements of each student and with the signatures: change it only if you know what it affects.', 'wp-certificates') : esc_html__('It links the document with its signatures and issued copies: change it only if you know what it affects.', 'wp-certificates') ?></p>
                </div>
            </div>

            <div class="wpc-eds-editor">
                <div class="wpc-eds-editor__main">
                    <div class="wpc-eds-code-tabs" role="tablist" aria-label="<?= esc_attr__('Parts of the document', 'wp-certificates') ?>">
                        <?php foreach ($code_parts as $part => $label) { $first = 'content' === $part; // se abre en «Contenido» ?>
                            <button type="button" role="tab" class="wpc-eds-code-tab" id="wpc-tab-<?= esc_attr($part) ?>" aria-controls="wpc-panel-<?= esc_attr($part) ?>" aria-selected="<?= $first ? 'true' : 'false' ?>" tabindex="<?= $first ? '0' : '-1' ?>"><?= esc_html($label) ?></button>
                        <?php } ?>
                    </div>
                    <?php foreach ($code_parts as $part => $label) { $first = 'content' === $part; ?>
                        <div class="wpc-eds-code-panel" role="tabpanel" id="wpc-panel-<?= esc_attr($part) ?>" aria-labelledby="wpc-tab-<?= esc_attr($part) ?>"<?= $first ? '' : ' hidden' ?>>
                            <label class="eds-sr-only" for="<?= esc_attr($part) ?>"><?= esc_html($label) ?></label>
                            <?php // El salto de línea tras <textarea> lo quita el navegador: así se conserva uno inicial si lo hubiera ?>
                            <textarea class="wpc-eds-code" id="<?= esc_attr($part) ?>" name="<?= esc_attr($part) ?>" rows="22" spellcheck="false" data-part="<?= esc_attr($part) ?>">
<?= esc_textarea((string) $document->$part) ?></textarea>
                        </div>
                    <?php } ?>
                    <p class="wpc-eds-help"><?= esc_html__('Code editor: the HTML is saved exactly as written, without automatic changes.', 'wp-certificates') ?></p>
                    <p class="wpc-eds-help"><?= esc_html__('Fonts: web fonts loaded from the internet (Google Fonts, Adobe Fonts or any other address) are not allowed: the PDF server has no internet access. To use a special font, upload its file (.woff2, .woff or .ttf) to this site and declare it with @font-face pointing to that file; it is embedded in the PDF automatically. Without a declared font, Arial is used.', 'wp-certificates') ?></p>
                </div>

                <aside class="wpc-eds-data" aria-labelledby="wpc-eds-data-title">
                    <h3 class="wpc-eds-data__title" id="wpc-eds-data-title"><?= esc_html__('Data', 'wp-certificates') ?></h3>
                    <label class="eds-field wpc-eds-data__search"><?= esc_html__('Search data', 'wp-certificates') ?>
                        <input type="search" id="wpc-eds-data-search" autocomplete="off">
                    </label>
                    <p class="wpc-eds-help"><?= esc_html__('Click to insert it where the cursor is.', 'wp-certificates') ?></p>
                    <div class="wpc-eds-data__list">
                        <?php foreach ($groups as $group_key => $group) {
                            [$group_label, $items] = $group;
                            $group_fn = (int) ($group[2] ?? 0); // firmante numerado (ADR 0010 de Edusof): su color
                            if (!$items) {
                                continue;
                            } ?>
                            <div class="wpc-eds-data__group" role="group" aria-labelledby="wpc-eds-group-<?= esc_attr($group_key) ?>"<?= $group_fn ? ' data-fn="' . $group_fn . '" data-fn-color="' . (int) squuad_cert_fn_color($group_fn) . '"' : '' ?>>
                                <h4 id="wpc-eds-group-<?= esc_attr($group_key) ?>"><?php if ($group_fn) { ?><span class="wpc-eds-fn-badge" aria-hidden="true">F<?= (int) $group_fn ?></span><?php } ?><?= esc_html($group_label) ?></h4>
                                <ul>
                                    <?php foreach ($items as $item) { ?>
                                        <li class="wpc-eds-data__item" data-search="<?= esc_attr(strtolower($item['label'] . ' ' . $item['var'])) ?>">
                                            <?php if ($item['insert']) { ?>
                                                <button type="button" class="wpc-eds-var" data-var="<?= esc_attr($item['var']) ?>">
                                                    <span class="wpc-eds-var__label"><?= esc_html($item['label']) ?></span>
                                                    <code><?= esc_html($item['var']) ?></code>
                                                </button>
                                            <?php } else { ?>
                                                <div class="wpc-eds-var wpc-eds-var--ref">
                                                    <span class="wpc-eds-var__label"><?= esc_html($item['label']) ?></span>
                                                    <code><?= esc_html($item['var']) ?></code>
                                                </div>
                                            <?php } ?>
                                        </li>
                                    <?php } ?>
                                </ul>
                            </div>
                        <?php } ?>
                        <p class="wpc-eds-data__empty" hidden><?= esc_html__('No data matches the search.', 'wp-certificates') ?></p>
                    </div>
                    <p class="eds-sr-only" aria-live="polite" id="wpc-eds-data-status"></p>
                </aside>
            </div>
        </section>

        <section class="eds-card" id="wpc-sec-page" aria-labelledby="wpc-sec-page-title">
            <h2 class="wpc-eds-section-title" id="wpc-sec-page-title"><?= esc_html__('Page and printing', 'wp-certificates') ?></h2>
            <div class="wpc-eds-grid">
                <div class="wpc-eds-field">
                    <label for="paper_format"><?= esc_html__('Paper Format', 'wp-certificates') ?></label>
                    <select name="paper_format" id="paper_format" required>
                        <option value="a4" <?php selected($document->paper_format, 'a4'); ?>>A4</option>
                        <option value="a3" <?php selected($document->paper_format, 'a3'); ?>>A3</option>
                        <option value="letter" <?php selected($document->paper_format, 'letter'); ?>><?= esc_html__('Letter', 'wp-certificates') ?></option>
                        <option value="legal" <?php selected($document->paper_format, 'legal'); ?>><?= esc_html__('Legal', 'wp-certificates') ?></option>
                        <option value="tabloid" <?php selected($document->paper_format, 'tabloid'); ?>><?= esc_html__('Tabloid', 'wp-certificates') ?></option>
                        <option value="custom" <?php selected($document->paper_format, 'custom'); ?>><?= esc_html__('Custom', 'wp-certificates') ?></option>
                    </select>
                </div>
                <div class="wpc-eds-field">
                    <label for="orientation"><?= esc_html__('Orientation', 'wp-certificates') ?></label>
                    <select name="orientation" id="orientation" required>
                        <option value="portrait" <?php selected('landscape' !== $document->orientation); ?>><?= esc_html__('Portrait', 'wp-certificates') ?></option>
                        <option value="landscape" <?php selected($document->orientation, 'landscape'); ?>><?= esc_html__('Landscape', 'wp-certificates') ?></option>
                    </select>
                </div>
                <?php if (function_exists('squuad_cert_pdf_engine_column_ready') && squuad_cert_pdf_engine_column_ready()) :
                    $wpc_pdf_setting = squuad_cert_pdf_engine_document_setting($document);
                    $wpc_pdf_site = 'servicio' === squuad_cert_pdf_engine_site() ? __('PDF server', 'wp-certificates') : __('browser', 'wp-certificates'); ?>
                <div class="wpc-eds-field">
                    <label for="pdf_engine"><?= esc_html__('PDF engine', 'wp-certificates') ?></label>
                    <select name="pdf_engine" id="pdf_engine" aria-describedby="pdf_engine-help" <?php disabled(!squuad_cert_pdf_engine_can_manage()); ?>>
                        <option value="site" <?php selected($wpc_pdf_setting, 'site'); ?>><?= esc_html(sprintf(__('As the site (now: %s)', 'wp-certificates'), $wpc_pdf_site)) ?></option>
                        <option value="servicio" <?php selected($wpc_pdf_setting, 'servicio'); ?>><?= esc_html__('Always the PDF server', 'wp-certificates') ?></option>
                        <option value="navegador" <?php selected($wpc_pdf_setting, 'navegador'); ?>><?= esc_html__('Always the browser', 'wp-certificates') ?></option>
                    </select>
                    <p class="wpc-eds-help" id="pdf_engine-help"><?= esc_html__('Who makes the PDF of this document: the PDF server (always the same result) or the browser of the person (as before). Only the WordPress administrator can change it.', 'wp-certificates') ?></p>
                </div>
                <?php endif; ?>
                <div class="wpc-eds-field" data-show-if="custom">
                    <label for="unit"><?= esc_html__('Unit', 'wp-certificates') ?></label>
                    <select name="unit" id="unit" required>
                        <option value="mm" <?php selected(!in_array($document->unit, ['pt', 'cm', 'in', 'px'], true)); ?>><?= esc_html__('mm (millimeters)', 'wp-certificates') ?></option>
                        <option value="pt" <?php selected($document->unit, 'pt'); ?>><?= esc_html__('pt (points)', 'wp-certificates') ?></option>
                        <option value="cm" <?php selected($document->unit, 'cm'); ?>><?= esc_html__('cm (centimeters)', 'wp-certificates') ?></option>
                        <option value="in" <?php selected($document->unit, 'in'); ?>><?= esc_html__('in (inches)', 'wp-certificates') ?></option>
                        <option value="px" <?php selected($document->unit, 'px'); ?>><?= esc_html__('px (pixels)', 'wp-certificates') ?></option>
                    </select>
                </div>
                <div class="wpc-eds-field" data-show-if="custom">
                    <label for="width_size"><?= esc_html__('Width size', 'wp-certificates') ?></label>
                    <input type="number" name="width_size" id="width_size" placeholder="210" step="0.01" value="<?= esc_attr((string) $document->width_size) ?>">
                </div>
                <div class="wpc-eds-field" data-show-if="custom">
                    <label for="height_size"><?= esc_html__('Height size', 'wp-certificates') ?></label>
                    <input type="number" name="height_size" id="height_size" placeholder="287" step="0.01" value="<?= esc_attr((string) $document->height_size) ?>">
                </div>
            </div>
            <div class="wpc-eds-check">
                <input type="checkbox" name="margin_required" id="margin_required" <?php checked(1 === (int) $document->margin_required); ?>>
                <label for="margin_required"><?= esc_html__('This document has margins (on all sides)', 'wp-certificates') ?></label>
            </div>
        </section>

        <section class="eds-card" id="wpc-sec-delivery" aria-labelledby="wpc-sec-delivery-title">
            <h2 class="wpc-eds-section-title" id="wpc-sec-delivery-title"><?= esc_html__('Delivery', 'wp-certificates') ?></h2>
            <div class="wpc-eds-check">
                <input type="checkbox" name="status" id="status" <?php checked(1 === (int) $document->status); ?> aria-describedby="status-help">
                <label for="status"><?= esc_html__('Active', 'wp-certificates') ?></label>
                <p class="wpc-eds-help" id="status-help"><?= esc_html__('An inactive document is not requested or issued.', 'wp-certificates') ?></p>
            </div>

            <fieldset class="wpc-eds-choice">
                <legend><?= esc_html__('How it is delivered', 'wp-certificates') ?></legend>
                <label class="wpc-eds-choice__option">
                    <input type="radio" name="type" value="managed" <?php checked(!$automatic); ?>>
                    <span><strong><?= esc_html__('Issued by the school office', 'wp-certificates') ?></strong>
                        <span class="wpc-eds-help"><?= wpc_edusystem_active() ? esc_html__('The school office generates it, or issues it for signature, from the student file.', 'wp-certificates') : esc_html__('The school office issues it to each person from "Issue documents".', 'wp-certificates') ?></span></span>
                </label>
                <label class="wpc-eds-choice__option">
                    <input type="radio" name="type" value="automatic" <?php checked($automatic); ?>>
                    <span><strong><?= esc_html__('Signed or completed by the person in their account', 'wp-certificates') ?></strong>
                        <span class="wpc-eds-help"><?= wpc_edusystem_active() ? esc_html__('It appears in My Account of each student until they sign or complete it. When saved, it is added as a requirement to the students who do not have it yet.', 'wp-certificates') : esc_html__('It appears in My Account of each person who must sign or complete it, until they do.', 'wp-certificates') ?></span></span>
                </label>
            </fieldset>

            <div class="wpc-eds-automatic-only">
                <div class="wpc-eds-check">
                    <input type="checkbox" name="is_required" id="is_required" <?php checked(1 === (int) $document->is_required); ?>>
                    <label for="is_required"><?= esc_html__('Is required', 'wp-certificates') ?></label>
                </div>
                <div class="wpc-eds-check">
                    <input type="checkbox" name="is_visible" id="is_visible" <?php checked(1 === (int) $document->is_visible); ?>>
                    <label for="is_visible"><?= esc_html__('Is visible in documents page?', 'wp-certificates') ?></label>
                </div>
                <div class="wpc-eds-field wpc-eds-field--short">
                    <label for="priority"><?= esc_html__('Priority', 'wp-certificates') ?></label>
                    <input type="number" name="priority" id="priority" min="0" max="<?= (int) SQUUAD_CERT_PRIORITY_MAX ?>" step="1" value="<?= (int) ($document->priority ?? 0) ?>" aria-describedby="priority-help">
                    <p class="wpc-eds-help" id="priority-help"><?= esc_html__('Order in My Account when several automatic documents are pending: 0 is the most urgent; with the same priority, the oldest first.', 'wp-certificates') ?></p>
                </div>
            </div>

            <?php if (wpc_edusystem_active()) { // Graduado: dato de EduSystem ?>
            <div class="wpc-eds-check">
                <input type="checkbox" name="graduated_required" id="graduated_required" <?php checked(1 === (int) $document->graduated_required); ?>>
                <label for="graduated_required"><?= esc_html__('This document requires that the student be a graduate', 'wp-certificates') ?></label>
            </div>
            <?php } elseif (1 === (int) $document->graduated_required) { // sin EduSystem no se muestra, pero se conserva ?>
                <input type="hidden" name="graduated_required" value="on">
            <?php } ?>

            <div class="wpc-eds-signing" id="wpc-eds-signing">
                <h3><?= esc_html__('Signatures', 'wp-certificates') ?></h3>
                <?php if ($signers_panel) { ?>
                    <?php // Un solo interruptor: el de «Firmantes del documento». Aquí viaja el valor guardado, sin cambios ?>
                    <?php if (!empty($document->signature_required)) { ?><input type="hidden" name="signature_required" value="on"><?php } ?>
                    <p>
                        <?php if ($signing['missing']) { ?>
                            <span class="eds-badge eds-badge--warn"><?= esc_html($signing['label']) ?></span>
                        <?php } elseif ($signing['asks']) { ?>
                            <span class="eds-badge eds-badge--info"><?= esc_html__('Asks for signatures', 'wp-certificates') ?></span> <?= esc_html($signing['label']) ?>
                        <?php } else { ?>
                            <span class="eds-badge eds-badge--neutral"><?= esc_html__('Does not ask for signatures', 'wp-certificates') ?></span>
                        <?php } ?>
                    </p>
                    <p class="wpc-eds-help"><?= esc_html__('Whether the document asks for signatures and who signs it are chosen in «Document signers», below, with its own save button.', 'wp-certificates') ?></p>
                    <p><a class="eds-btn eds-btn--secondary eds-btn--sm" href="#edusystem-document-signers"><?= esc_html__('Choose who signs', 'wp-certificates') ?></a></p>
                <?php } else { ?>
                    <div class="wpc-eds-check">
                        <input type="checkbox" name="signature_required" id="signature_required" <?php checked(1 === (int) $document->signature_required); ?>>
                        <label for="signature_required"><?= esc_html__('This document asks for signatures', 'wp-certificates') ?></label>
                    </div>
                <?php } ?>
            </div>

            <?php if (wpc_edusystem_active()) { // Requisito y tipo de archivo: conceptos de los requisitos de EduSystem ?>
            <div class="wpc-eds-grid">
                <div class="wpc-eds-field">
                    <label for="id_requisito"><?= esc_html__('ID Requirement for the admin (ID requisito)', 'wp-certificates') ?></label>
                    <input type="text" name="id_requisito" id="id_requisito" value="<?= esc_attr((string) ($document->id_requisito ?? '')) ?>">
                </div>
                <div class="wpc-eds-field">
                    <label for="type_file"><?= esc_html__('Type file', 'wp-certificates') ?></label>
                    <input type="text" name="type_file" id="type_file" value="<?= esc_attr((string) ($document->type_file ?? '')) ?>">
                </div>
            </div>
            <?php } else { // sin EduSystem no se muestran, pero se conservan al guardar ?>
                <input type="hidden" name="id_requisito" value="<?= esc_attr((string) ($document->id_requisito ?? '')) ?>">
                <input type="hidden" name="type_file" value="<?= esc_attr((string) ($document->type_file ?? '')) ?>">
            <?php } ?>
        </section>

        <section class="eds-card" id="wpc-sec-book" aria-labelledby="wpc-sec-book-title">
            <h2 class="wpc-eds-section-title" id="wpc-sec-book-title"><?= esc_html__('Registry book', 'wp-certificates') ?></h2>
            <div class="wpc-eds-field">
                <label for="book"><?= esc_html__('Registry book', 'wp-certificates') ?></label>
                <select name="book" id="book">
                    <option value="0"><?= esc_html__('Select a book', 'wp-certificates') ?></option>
                    <?php foreach ((array) $books as $book) {
                        $book_id = is_object($book) ? ($book->id ?? 0) : ($book['id'] ?? 0);
                        $book_title = is_object($book) ? ($book->title ?? $book->name ?? $book_id) : ($book['title'] ?? $book['name'] ?? $book_id);
                        if ((int) $book_id <= 0) {
                            continue;
                        } ?>
                        <option value="<?= esc_attr((string) $book_id) ?>" <?php selected((int) ($document->book ?? 0), (int) $book_id); ?>><?= esc_html((string) $book_title) ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="wpc-eds-field">
                <label for="book_line_description"><?= esc_html__('Registry book line description', 'wp-certificates') ?></label>
                <textarea name="book_line_description" id="book_line_description" rows="3" placeholder="<?= esc_attr(squuad_cert_book_line_default()) ?>" aria-describedby="book_line_description-help"><?= esc_textarea((string) ($document->book_line_description ?? '')) ?></textarea>
                <p class="wpc-eds-help" id="book_line_description-help"><?= esc_html(sprintf(__('Text of the line registered in the book when the document is issued. It accepts the same variables as the document, except {{tomo}}, {{folio}}, {{tomo_folio}} and {{qrcode}}, and is sent as plain text (maximum %d characters). If a variable has no value, the document is not issued. Empty: the text shown as an example is used.', 'wp-certificates'), SQUUAD_CERT_BOOK_LINE_MAX)) ?></p>
            </div>
        </section>

        <section class="eds-card" id="wpc-sec-advanced" aria-labelledby="wpc-sec-advanced-title">
            <h2 class="wpc-eds-section-title" id="wpc-sec-advanced-title"><?= esc_html__('Advanced', 'wp-certificates') ?></h2>
            <?php if (function_exists('squuad_cert_get_document_fields')) { ?>
                <details id="wpc-advanced" <?= $document_fields ? 'open' : '' ?>>
                    <summary><?= esc_html__('Additional fields', 'wp-certificates') ?></summary>
                    <p class="wpc-eds-help"><?= esc_html__('They are requested before generating the document and the answers are only used to fill it in (they are only stored while the document is partially signed). Use {{key}} in the document to print the answer and, in fields with options, {{key_list}} to print all the options with (✓) on the selected ones. If the key is left empty, it is created from the label.', 'wp-certificates') ?></p>
                    <div class="eds-table-wrap">
                        <table class="eds-table" id="wpc-document-fields">
                            <thead>
                                <tr>
                                    <th scope="col"><?= esc_html__('Label', 'wp-certificates') ?></th>
                                    <th scope="col"><?= esc_html__('Key', 'wp-certificates') ?></th>
                                    <th scope="col"><?= esc_html__('Type', 'wp-certificates') ?></th>
                                    <th scope="col"><?= esc_html__('Options (one per line)', 'wp-certificates') ?></th>
                                    <th scope="col"><?= esc_html__('Required', 'wp-certificates') ?></th>
                                    <th scope="col"><span class="eds-sr-only"><?= esc_html__('Actions', 'wp-certificates') ?></span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($document_fields as $index => $field) {
                                    echo wpc_document_field_row($index, $field); // phpcs:ignore -- marcado escapado en la función
                                } ?>
                            </tbody>
                        </table>
                    </div>
                    <p><button type="button" class="eds-btn eds-btn--secondary eds-btn--sm" id="wpc-add-document-field"><?= esc_html__('+ Add field', 'wp-certificates') ?></button></p>
                    <template id="wpc-document-field-template"><?= wpc_document_field_row('__INDEX__'); // phpcs:ignore ?></template>
                </details>
            <?php } else { ?>
                <p class="wpc-eds-help"><?= esc_html__('There are no advanced settings on this site.', 'wp-certificates') ?></p>
            <?php } ?>
        </section>

        <div class="wpc-eds-form__actions">
            <button type="submit" class="eds-btn eds-btn--primary"><?= esc_html__('Save changes', 'wp-certificates') ?></button>
        </div>
    </form>

    <?php // Aquí se coloca el panel «Firmantes del documento» (certification/admin/templates/document-signing.php) ?>
    <div id="wpc-eds-signers-slot"></div>

    <?php
    // Vista previa: un plugin puede sustituirla (EduSystem la muestra en PDF, con datos de ejemplo)
    $custom_preview = (string) apply_filters('wpc_document_preview', '', $document);
    ?>
    <section class="eds-card wpc-eds-preview" id="wpc-sec-preview" aria-label="<?= esc_attr__('Preview', 'wp-certificates') ?>">
        <?php if ('' !== $custom_preview) { ?>
            <?= $custom_preview; // phpcs:ignore -- vista previa del plugin que la da (con su título) ?>
        <?php } else { ?>
            <h2 class="wpc-eds-section-title"><?= esc_html__('Preview', 'wp-certificates') ?></h2>
            <p class="wpc-eds-help"><?= esc_html__('It shows the saved version: save to see your changes.', 'wp-certificates') ?></p>
            <div class="wpc-eds-preview__page">
                <?= $html_document; // phpcs:ignore -- HTML del documento, como en la vista de siempre ?>
            </div>
        <?php } ?>
    </section>
<?php endif; ?>
</div>
