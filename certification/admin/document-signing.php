<?php
declare(strict_types=1);

/**
 * EduSystem - Firmantes del documento (ADR 0003, paso 4).
 *
 * En la pantalla de configuración de un documento de wp-certificates
 * (page=add_admin_form_documents_content&section_tab=document_detail&document_id=X) EduSystem añade el panel
 * "Firmantes del documento", con su propio formulario, sin modificar ese plugin: si el documento pide firmas y
 * quién firma, en orden (estudiante y representante primero, después los firmantes registrados). Cada cambio crea
 * una versión nueva de la política (sellada en la cadena) y solo afecta a las solicitudes nuevas.
 */

if (!defined('ABSPATH')) exit;

/** ¿Estamos en la ficha de un documento de wp-certificates? Devuelve el documento o null. */
function edusystem_document_signing_current_document(): ?object
{
    global $wpdb;

    if (($_GET['page'] ?? '') !== 'add_admin_form_documents_content' || ($_GET['section_tab'] ?? '') !== 'document_detail') {
        return null;
    }
    $document_id = absint($_GET['document_id'] ?? 0);
    if (!$document_id) {
        return null;
    }
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}documents_certificates WHERE id = %d", $document_id));

    return $row ?: null;
}

add_action('admin_footer', 'edusystem_document_signing_panel');
function edusystem_document_signing_panel(): void
{
    if (!function_exists('edusystem_signers_enabled') || !edusystem_signers_enabled() || !current_user_can(EDUSYSTEM_MANAGE_SIGNING_POLICIES_CAP)) {
        return;
    }
    $document = edusystem_document_signing_current_document();
    if (!$document) {
        return;
    }
    global $wpdb;

    $policy = edusystem_signing_policy($document);
    $positions = [];
    foreach ($policy['slots'] as $slot) {
        $positions[$slot['slot_type'] . ':' . $slot['signer_id']] = $slot['position'];
    }
    $signers = $wpdb->get_results(
        "SELECT s.*, u.display_name, u.user_email FROM {$wpdb->prefix}edusystem_signers s
         LEFT JOIN {$wpdb->users} u ON u.ID = s.user_id
         WHERE s.status IN ('active', 'invited') ORDER BY u.display_name"
    );
    $notice = function_exists('edusystem_signers_take_notice') ? edusystem_signers_take_notice() : null;
    $inbox = edusystem_signer_inbox_enabled();
    // Texto de la plantilla para marcar qué variables de firma ya están en uso (el panel lo actualiza en vivo)
    $template_text = (string) $document->header . (string) $document->content . (string) $document->footer;

    include EDUSYSTEM_CERTIFICATION_PATH . 'admin/templates/document-signing.php';
}

add_action('admin_post_edusystem_save_signing_policy', 'edusystem_document_signing_handle_save');
function edusystem_document_signing_handle_save(): void
{
    if (!current_user_can(EDUSYSTEM_MANAGE_SIGNING_POLICIES_CAP)) {
        wp_die(esc_html__('You do not have permission to configure document signers.', 'edusystem'), 403);
    }
    check_admin_referer('edusystem_save_signing_policy');
    $document_id = absint($_POST['document_certificate_id'] ?? 0);
    $requires = !empty($_POST['requires_signatures']);

    $slots = [];
    foreach ((array) ($_POST['slots'] ?? []) as $key => $slot) {
        if (empty($slot['enabled'])) {
            continue;
        }
        $key = sanitize_text_field((string) $key);
        [$type, $signer_id] = array_pad(explode(':', $key, 2), 2, '0');
        $slots[] = ['slot_type' => $type, 'signer_id' => (int) $signer_id, 'position' => (int) ($slot['position'] ?? 0)];
    }

    $result = edusystem_signing_policy_save($document_id, $requires, $slots);
    // Condición para pedir el documento automático (paso 9); se guarda aparte de la política de firmantes
    if (isset($_POST['request_condition']) && function_exists('edusystem_document_request_condition_set')) {
        if (edusystem_document_request_condition_set($document_id, sanitize_key($_POST['request_condition']))) {
            $saved = __('The condition of the document was saved.', 'edusystem');
            if (!$result['ok']) {
                $result['message'] .= ' ' . $saved; // la política no se guardó, la condición sí
            } else {
                $result['message'] = __('No changes.', 'edusystem') === $result['message'] ? $saved : $result['message'] . ' ' . $saved;
            }
        }
    }
    if (function_exists('edusystem_signers_notice')) {
        edusystem_signers_notice($result['message'], $result['ok']);
    }
    wp_safe_redirect(add_query_arg([
        'page' => 'add_admin_form_documents_content',
        'section_tab' => 'document_detail',
        'document_id' => $document_id,
    ], admin_url('admin.php')) . '#edusystem-document-signers');
    exit;
}

/* ---------------------------------------------------------------------------------------------------------------
 * Paso 7: "Emitir para firma" desde la ficha del estudiante (documentos gestionados con firmantes del sistema)
 * ------------------------------------------------------------------------------------------------------------ */

/** Mismo acceso que la gestión de Admisión (no el de solo lectura). */
function edusystem_document_issue_can(): bool
{
    return current_user_can('manage_options') && current_user_can('manager_admission_aes');
}

add_action('admin_post_edusystem_issue_document', 'edusystem_document_issue_handle');
function edusystem_document_issue_handle(): void
{
    $student_id = absint($_POST['student_id'] ?? 0);
    $document_certificate_id = absint($_POST['document_certificate_id'] ?? 0);
    check_admin_referer('edusystem_issue_document_' . $student_id . '_' . $document_certificate_id);
    if (!edusystem_document_issue_can()) {
        wp_die(esc_html__('You do not have permission to manage admissions.', 'edusystem'), 403);
    }
    $result = edusystem_signature_issue_document($student_id, $document_certificate_id);
    edusystem_signers_notice($result['message'], $result['ok']);
    $back = wp_get_referer() ?: admin_url('admin.php?page=add_admin_form_admission_content');
    wp_safe_redirect(remove_query_arg('edusystem_issued', $back) . '#edusystem-issued-documents');
    exit;
}

/**
 * Sección "Documentos emitidos para firma" de la ficha del estudiante: cada emisión con su estado, firmas dadas y
 * el PDF final cuando está completo. Se llama desde admin/templates/student-details.php.
 */
function edusystem_document_issued_section(object $student): void
{
    if (!function_exists('edusystem_signature_issued_for_student') || !edusystem_signers_enabled()) {
        return;
    }
    $issued = edusystem_signature_issued_for_student((int) $student->id);
    $notice = edusystem_signers_take_notice();
    if (!$issued && !$notice) {
        return;
    }
    include EDUSYSTEM_CERTIFICATION_PATH . 'admin/templates/document-issued.php';
}

/* ---------------------------------------------------------------------------------------------------------------
 * Paso 8b: tomo y folio (libro de registro de EduSof) de los documentos emitidos
 * ------------------------------------------------------------------------------------------------------------ */

/**
 * Reserva la línea del libro al emitir (filtro de edusystem_signature_issue_document): una línea nueva en el libro del
 * documento; el libro devuelve tomo, folio y número de línea. Devuelve la fila de edusystem_book_entries o WP_Error.
 */
add_filter('edusystem_issue_book_entry', 'edusystem_document_issue_reserve_book_line', 10, 4);
function edusystem_document_issue_reserve_book_line($entry, object $student, object $document, object $request)
{
    if ((is_object($entry) && !is_wp_error($entry)) || !function_exists('edusof_insert_certificate_book_line')) {
        return $entry ?? new WP_Error('edusystem_book', __('The registry book is not available on this site. The document was not issued.', 'edusystem'));
    }
    $program = function_exists('get_name_program_student') ? (string) get_name_program_student($student->id) : '';
    $line = edusof_insert_certificate_book_line((int) $document->book, $student, 'certificate', (string) $document->title, gmdate('Y-m-d'), $program, '');
    $row = $line ? edusystem_book_entry_insert((int) $student->id, (int) $document->id, (int) $document->book, $line) : null;
    if (!$row) {
        return new WP_Error('edusystem_book', __('The registry book did not assign a volume and folio (connection or book error). The document was not issued; try again.', 'edusystem'));
    }
    if (function_exists('edusystem_set_log')) {
        edusystem_set_log(sprintf('Tomo %d, folio %d (línea %d del libro %d) reservados para el documento %d del estudiante %d', (int) $row->tomo, (int) $row->folio, (int) $row->line_id, (int) $row->book_id, (int) $document->id, (int) $student->id), 'signing_policy');
    }

    return $row;
}

/** Anula la línea en el libro de EduSof (voidLine, con motivo) y la marca anulada en EduSystem. Devuelve ['ok', 'message']. */
function edusystem_document_issue_void_book_line(object $entry, string $reason): array
{
    $api = function_exists('edusof_api') ? edusof_api() : null;
    if (!$api || is_wp_error($api)) {
        return ['ok' => false, 'message' => __('The registry book is not available on this site.', 'edusystem')];
    }
    try {
        $api->voidLine((int) $entry->line_id, $reason);
    } catch (Exception $e) {
        return ['ok' => false, 'message' => sprintf(__('The registry book could not void the volume and folio: %s', 'edusystem'), $e->getMessage())];
    }
    edusystem_book_entry_mark_void((int) $entry->id, $reason);
    if (function_exists('edusystem_set_log')) {
        edusystem_set_log(sprintf('Tomo %d, folio %d (línea %d del libro %d) anulados por el usuario %d: %s', (int) $entry->tomo, (int) $entry->folio, (int) $entry->line_id, (int) $entry->book_id, get_current_user_id(), $reason), 'signing_policy');
    }

    return ['ok' => true, 'message' => ''];
}

/**
 * Decisión sobre el tomo/folio de un documento emitido y declinado: 'keep' reemite con la misma línea; 'void' la anula
 * en el libro y reemite con una nueva. Se sella en la solicitud declinada. Devuelve ['ok', 'message'].
 */
function edusystem_document_issue_resolve_book(object $request, object $entry, string $option, string $reason): array
{
    if ('void' === $option) {
        $voided = edusystem_document_issue_void_book_line($entry, $reason);
        if (!$voided['ok']) {
            return $voided;
        }
    }
    edusystem_signature_request_log_event((int) $request->id, 'book_decision', [
        'option' => $option,
        'entry_id' => (int) $entry->id,
        'line_id' => (int) $entry->line_id,
        'tomo' => (int) $entry->tomo,
        'folio' => (int) $entry->folio,
        'reason' => $reason,
    ]);
    $reissued = edusystem_signature_issue_document((int) $request->student_id, (int) $request->document_certificate_id, 'keep' === $option ? (int) $entry->id : 0);
    if (!$reissued['ok']) {
        return ['ok' => false, 'message' => sprintf(__('Declined, but the document could not be issued again: %s', 'edusystem'), $reissued['message'])];
    }
    $new_entry = edusystem_book_entry_get((int) (edusystem_signature_request_get((int) $reissued['request_id'])->book_entry_id ?? 0));

    return ['ok' => true, 'message' => sprintf(
        /* translators: 1: volume, 2: folio */
        __('Declined and issued again with volume %1$d, folio %2$d. The signers will find it in "Documents to sign".', 'edusystem'),
        (int) ($new_entry->tomo ?? 0),
        (int) ($new_entry->folio ?? 0)
    )];
}

/** Declinar un documento emitido desde la ficha del estudiante, con la decisión sobre el tomo/folio si lo tiene. */
add_action('admin_post_edusystem_issued_decline', 'edusystem_document_issued_decline_handle');
function edusystem_document_issued_decline_handle(): void
{
    $request_id = absint($_POST['request_id'] ?? 0);
    check_admin_referer('edusystem_issued_decline_' . $request_id);
    if (!edusystem_document_issue_can()) {
        wp_die(esc_html__('You do not have permission to manage admissions.', 'edusystem'), 403);
    }
    $reason = sanitize_textarea_field(wp_unslash($_POST['reason'] ?? ''));
    $option = sanitize_key($_POST['book_option'] ?? '');
    $request = edusystem_signature_request_get($request_id);
    $entry = $request && (int) $request->book_entry_id ? edusystem_book_entry_get((int) $request->book_entry_id) : null;
    $entry = $entry && 'active' === $entry->status ? $entry : null;

    if (!$request || 'issued' !== $request->origin) {
        $result = ['ok' => false, 'message' => __('This document can no longer be declined. Please reload the page.', 'edusystem')];
    } elseif ('' === trim($reason) || empty($_POST['confirm_irreversible'])) {
        $result = ['ok' => false, 'message' => __('To decline a document you must write the reason and accept that the action cannot be reverted.', 'edusystem')];
    } elseif ($entry && !in_array($option, ['keep', 'void'], true)) {
        $result = ['ok' => false, 'message' => __('Choose whether to keep or void the volume and folio.', 'edusystem')];
    } else {
        $result = edusystem_signature_issued_decline($request_id, $reason);
        if ($result['ok'] && $entry) {
            $result = edusystem_document_issue_resolve_book(edusystem_signature_request_get($request_id), $entry, $option, $reason);
        }
    }
    edusystem_signers_notice($result['message'], $result['ok']);
    wp_safe_redirect((wp_get_referer() ?: admin_url('admin.php?page=add_admin_form_admission_content')) . '#edusystem-issued-documents');
    exit;
}

/** Decisión pendiente sobre el tomo/folio (documento declinado por un firmante, o reemisión que falló). */
add_action('admin_post_edusystem_issued_book_decision', 'edusystem_document_issued_book_decision_handle');
function edusystem_document_issued_book_decision_handle(): void
{
    $request_id = absint($_POST['request_id'] ?? 0);
    check_admin_referer('edusystem_issued_book_decision_' . $request_id);
    if (!edusystem_document_issue_can()) {
        wp_die(esc_html__('You do not have permission to manage admissions.', 'edusystem'), 403);
    }
    $option = sanitize_key($_POST['book_option'] ?? '');
    $reason = sanitize_textarea_field(wp_unslash($_POST['reason'] ?? ''));
    $request = edusystem_signature_request_get($request_id);
    $entry = $request ? edusystem_book_entry_pending_decision((int) $request->student_id, (int) $request->document_certificate_id) : null;

    if (!$entry || (int) $entry->declined_request_id !== $request_id) {
        $result = ['ok' => false, 'message' => __('There is no pending decision for this document. Please reload the page.', 'edusystem')];
    } elseif (!in_array($option, ['keep', 'void'], true)) {
        $result = ['ok' => false, 'message' => __('Choose whether to keep or void the volume and folio.', 'edusystem')];
    } elseif ('void' === $option && '' === trim($reason)) {
        $result = ['ok' => false, 'message' => __('Write why the volume and folio are voided.', 'edusystem')];
    } else {
        $result = edusystem_document_issue_resolve_book($request, $entry, $option, '' !== trim($reason) ? $reason : (string) $request->decline_reason);
    }
    edusystem_signers_notice($result['message'], $result['ok']);
    wp_safe_redirect((wp_get_referer() ?: admin_url('admin.php?page=add_admin_form_admission_content')) . '#edusystem-issued-documents');
    exit;
}

/* ---------------------------------------------------------------------------------------------------------------
 * Paso 9: convertir la carta de documentos faltantes (MISSING DOCUMENT) en documento automático
 * ------------------------------------------------------------------------------------------------------------ */

/**
 * ¿Se ofrece la conversión? Solo en sitios que usaron la carta del modelo anterior (tienen firmas o documentos
 * "MISSING DOCUMENT") y que todavía no tienen ningún documento con ese identificador en wp-certificates.
 */
function edusystem_missing_letter_conversion_available(): bool
{
    global $wpdb;

    if (!function_exists('edusystem_signers_enabled') || !edusystem_signers_enabled()) {
        return false;
    }
    $table = $wpdb->prefix . 'documents_certificates';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
        return false;
    }
    if ($wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE document_identificator = %s LIMIT 1", EDUSYSTEM_MISSING_LETTER_ID))) {
        return false;
    }

    return (bool) ($wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}users_signatures WHERE document_id = %s LIMIT 1", EDUSYSTEM_MISSING_LETTER_ID))
        || $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}student_documents WHERE document_id = %s LIMIT 1", EDUSYSTEM_MISSING_LETTER_ID)));
}

/**
 * Plantilla de la carta: el texto de la carta anterior (public/templates/create-missing-documents.php) con la lista
 * {{missing_documents}}, el año y la fecha de inicio como variables y la sección de firmas del sistema.
 */
function edusystem_missing_letter_template(): array
{
    $header = '<div style="text-align:center">'
        . '<img style="width:240px;margin:auto;padding:10px" src="http://portal.americanelite.school/wp-content/uploads/2025/06/cropped-cropped-cropped-AMERICAN-ELITE-SCHOOL_LOGOTIPO-COLOR-3-scaled.png"><br>'
        . '<span style="font-size:12px">3105 NW 107 Avenue, Doral, FL 33172 | (786) 361 – 9307 | www.American-elite.us</span></div>';
    $content = '<h4 style="padding:4px;text-align:center;font-weight:bold;font-size:20px">MISSING DOCUMENT COMMITMENT LETTER</h4>'
        . '<div style="font-size:15px;padding:10px 40px">'
        . '<p style="margin-bottom:20px">This document serves as a commitment on behalf of <strong style="text-transform:uppercase">{{student_name}}</strong>, to submit, within the specified deadlines set by American Elite School, the missing documents required for complete registration and graduation.</p>'
        . '<div style="margin:0 0 20px 20px">{{missing_documents}}</div>'
        . '<p style="margin-bottom:20px">The academic high school program will begin for the {{academic_year}} on {{start_academic_year}}. As it is the responsibility of the representative to submit the above-mentioned documents, by signing this letter, they acknowledge and accept their commitment to comply with all instructions provided by AES regarding the required documents and their respective deadlines.</p>'
        . '<p style="margin-bottom:20px">Failure to comply with the foregoing, the student may be penalized for being withdrawn from the program until he delivers the missing document or documents.</p>'
        . '</div>{{signature_section}}';

    return ['header' => $header, 'content' => $content, 'footer' => ''];
}

add_action('all_admin_notices', 'edusystem_missing_letter_conversion_notice'); // ver hide_notices (branding.php)
function edusystem_missing_letter_conversion_notice(): void
{
    if (($_GET['page'] ?? '') !== 'add_admin_form_documents_content' || !empty($_GET['section_tab'])
        || !current_user_can(EDUSYSTEM_MANAGE_SIGNING_POLICIES_CAP) || !edusystem_missing_letter_conversion_available()) {
        return;
    }
    ?>
    <div class="notice notice-info">
        <p><strong><?= esc_html__('Missing documents commitment letter', 'edusystem') ?></strong><br>
            <?= esc_html__('This site uses the old fixed letter. Convert it into an automatic document: it will be editable here, signed with the new signature system (frozen content, consent, "Documents to sign") and asked only when the student has optional documents pending. Letters already signed keep their validity and are not asked again.', 'edusystem') ?></p>
        <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
            <input type="hidden" name="action" value="edusystem_convert_missing_letter">
            <?php wp_nonce_field('edusystem_convert_missing_letter'); ?>
            <p><button type="submit" class="button button-primary"><?= esc_html__('Convert the letter into an automatic document', 'edusystem') ?></button></p>
        </form>
    </div>
    <?php
}

add_action('admin_post_edusystem_convert_missing_letter', 'edusystem_missing_letter_convert_handle');
function edusystem_missing_letter_convert_handle(): void
{
    global $wpdb;

    check_admin_referer('edusystem_convert_missing_letter');
    if (!current_user_can(EDUSYSTEM_MANAGE_SIGNING_POLICIES_CAP)) {
        wp_die(esc_html__('You do not have permission to configure document signers.', 'edusystem'), 403);
    }
    if (!edusystem_missing_letter_conversion_available()) {
        edusystem_signers_notice(__('The letter was already converted or is not used on this site.', 'edusystem'), false);
        wp_safe_redirect(admin_url('admin.php?page=add_admin_form_documents_content'));
        exit;
    }

    // Se inserta directamente (no con el formulario de wp-certificates, que al guardar un documento automático crea
    // una fila pendiente para todos los estudiantes). No obligatorio ni visible, como las filas de la carta anterior.
    $template = edusystem_missing_letter_template();
    $inserted = $wpdb->insert($wpdb->prefix . 'documents_certificates', [
        'title' => 'MISSING DOCUMENT COMMITMENT LETTER',
        'document_identificator' => EDUSYSTEM_MISSING_LETTER_ID,
        'header' => $template['header'],
        'content' => $template['content'],
        'footer' => $template['footer'],
        'status' => 1,
        'signature_required' => 0,
        'graduated_required' => 0,
        'margin_required' => 0,
        'orientation' => 'portrait',
        'type' => 'automatic',
        'width_size' => 0,
        'height_size' => 0,
        'paper_format' => 'letter',
        'unit' => 'mm',
        'id_requisito' => '',
        'type_file' => '',
        'is_required' => 0,
        'is_visible' => 0,
        'book' => 0,
        'created_at' => current_time('mysql'),
    ]);
    $document_id = (int) $wpdb->insert_id;
    if (!$inserted || !$document_id) {
        edusystem_signers_notice(__('The letter could not be converted. Please try again.', 'edusystem'), false);
        wp_safe_redirect(admin_url('admin.php?page=add_admin_form_documents_content'));
        exit;
    }
    edusystem_document_request_condition_set($document_id, 'missing_documents');

    // La variable nueva aparece en la lista de variables del editor de wp-certificates
    $variables = $wpdb->prefix . 'variables_document';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $variables)) === $variables
        && !$wpdb->get_var($wpdb->prepare("SELECT id FROM {$variables} WHERE identificator = %s", 'missing_documents'))) {
        $wpdb->insert($variables, [
            'text' => 'Missing documents (optional documents pending)',
            'visual' => '{{missing_documents}}',
            'identificator' => 'missing_documents',
            'created_at' => current_time('mysql'),
            'type' => 'all',
        ]);
    }
    if (function_exists('edusystem_set_log')) {
        edusystem_set_log(sprintf('Carta de documentos faltantes convertida en el documento automático %d por el usuario %d', $document_id, get_current_user_id()), 'signing_policy');
    }
    edusystem_signers_notice(__('The letter is now an automatic document. Review its text below; it is asked only when the student has optional documents pending.', 'edusystem'), true);
    wp_safe_redirect(add_query_arg([
        'page' => 'add_admin_form_documents_content',
        'section_tab' => 'document_detail',
        'document_id' => $document_id,
    ], admin_url('admin.php')) . '#edusystem-document-signers');
    exit;
}

/* ---------------------------------------------------------------------------------------------------------------
 * Paso 10: aviso de la actualización (documentos que exigen firma sin responsables configurados)
 * ------------------------------------------------------------------------------------------------------------ */

const EDUSYSTEM_LEGACY_SIGNATURES_NOTIFIED = 'edusystem_legacy_signatures_notified';
const EDUSYSTEM_LEGACY_SIGNATURES_DISMISSED = 'edusystem_legacy_signatures_notice_dismissed';

/** Documentos gestionados activos que exigen firma y todavía no tienen firmantes del sistema: quedan deshabilitados. */
function edusystem_legacy_signature_affected_documents(): array
{
    global $wpdb;

    $table = $wpdb->prefix . 'documents_certificates';
    if (!function_exists('edusystem_third_party_signatures_blocked') || !edusystem_third_party_signatures_blocked()
        || $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
        return [];
    }
    $documents = $wpdb->get_results("SELECT * FROM {$table} WHERE signature_required = 1 AND `status` = 1 AND `type` <> 'automatic' ORDER BY title");

    return array_values(array_filter($documents, static fn($document): bool => !edusystem_signature_issue_signers($document)));
}

/**
 * Una sola vez por sitio, la primera vez que un administrador entra tras la actualización: si hay documentos
 * afectados, correo a los titulares de las firmas-imagen de "Users and signatures" y a los administradores
 * explicando el cambio y qué hacer. add_option() hace de cerrojo (no se envía dos veces aunque entren a la vez).
 */
add_action('admin_init', 'edusystem_legacy_signatures_migration_notify', 20);
function edusystem_legacy_signatures_migration_notify(): void
{
    global $wpdb;

    if (wp_doing_ajax() || wp_doing_cron() || !current_user_can('manage_options') || false !== get_option(EDUSYSTEM_LEGACY_SIGNATURES_NOTIFIED, false)) {
        return;
    }
    if (!function_exists('edusystem_third_party_signatures_blocked') || !edusystem_third_party_signatures_blocked()) {
        return;
    }
    if (!add_option(EDUSYSTEM_LEGACY_SIGNATURES_NOTIFIED, ['at' => gmdate('Y-m-d H:i:s'), 'sent' => 0], '', false)) {
        return;
    }
    $documents = edusystem_legacy_signature_affected_documents();
    if (!$documents) {
        return;
    }

    $owners = [];
    $legacy = $wpdb->prefix . 'users_signatures_certificate';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $legacy)) === $legacy) {
        foreach ($wpdb->get_col("SELECT DISTINCT user_id FROM {$legacy}") as $user_id) {
            $user = get_userdata((int) $user_id);
            if ($user && is_email($user->user_email)) {
                $owners[strtolower($user->user_email)] = $user;
            }
        }
    }
    $admins = [];
    foreach (get_users(['role' => 'administrator']) as $user) {
        if (is_email($user->user_email) && !isset($owners[strtolower($user->user_email)])) {
            $admins[strtolower($user->user_email)] = $user;
        }
    }

    $site = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
    $list = implode("\n", array_map(static fn($document): string => '- ' . $document->title, $documents));
    $subject = sprintf(__('[%s] Security change: documents that require a signature', 'edusystem'), $site);
    $intro = __('For security, documents can no longer be signed with signature images uploaded to "Users and signatures". Only the responsible person can sign, from their own account: nobody can sign on behalf of someone else.', 'edusystem');
    $sent = 0;
    foreach ($owners as $user) {
        $body = sprintf(__('Hello %s,', 'edusystem'), $user->display_name) . "\n\n" . $intro . "\n\n"
            . __('These documents require a signature and are disabled until the administration configures who signs them:', 'edusystem') . "\n" . $list . "\n\n"
            . __('If you are responsible for signing them, the administration will invite you to register your own signature. Documents already signed keep their validity.', 'edusystem') . "\n";
        $sent += (int) wp_mail($user->user_email, $subject, $body);
    }
    foreach ($admins as $user) {
        $body = sprintf(__('Hello %s,', 'edusystem'), $user->display_name) . "\n\n" . $intro . "\n\n"
            . __('These documents require a signature and are disabled until you configure who signs them:', 'edusystem') . "\n" . $list . "\n\n"
            . __('What to do: 1) in Users and signatures, invite the responsible persons to register their own signature; 2) in Certification > Documents, open each document and choose its signers in "Document signers"; 3) from the student file, use "Issue for signature". Documents already signed keep their validity.', 'edusystem') . "\n"
            . admin_url('admin.php?page=add_admin_form_documents_content') . "\n";
        $sent += (int) wp_mail($user->user_email, $subject, $body);
    }
    update_option(EDUSYSTEM_LEGACY_SIGNATURES_NOTIFIED, ['at' => gmdate('Y-m-d H:i:s'), 'sent' => $sent, 'documents' => count($documents)], false);
    if (function_exists('edusystem_set_log')) {
        edusystem_set_log(sprintf(
            'Aviso de la actualización (nadie firma por otro): %d documentos sin firmantes; correo a %d titulares de firmas-imagen (%s) y %d administradores (%s)',
            count($documents),
            count($owners),
            implode(', ', array_keys($owners)),
            count($admins),
            implode(', ', array_keys($admins))
        ), 'signing_policy');
    }
}

/**
 * Aviso en el admin (gestores de firmantes) mientras haya documentos que exigen firma sin responsables. En
 * all_admin_notices: hide_notices (branding.php) quita admin_notices a quien no es superadministrador.
 */
add_action('all_admin_notices', 'edusystem_legacy_signatures_admin_notice');
function edusystem_legacy_signatures_admin_notice(): void
{
    if (!current_user_can(EDUSYSTEM_MANAGE_SIGNING_POLICIES_CAP) || get_option(EDUSYSTEM_LEGACY_SIGNATURES_DISMISSED)) {
        return;
    }
    $documents = edusystem_legacy_signature_affected_documents();
    if (!$documents) {
        return;
    }
    ?>
    <div class="notice notice-warning">
        <p><strong><?= esc_html__('Documents that require a signature are disabled until you configure who signs them', 'edusystem') ?></strong><br>
            <?= esc_html__('For security, nobody can sign on behalf of someone else: signature images are no longer used. Choose the signers of each document; they will sign from their own account.', 'edusystem') ?></p>
        <ul style="list-style:disc;margin-left:20px">
            <?php foreach ($documents as $document) : ?>
                <li><a href="<?= esc_url(add_query_arg(['page' => 'add_admin_form_documents_content', 'section_tab' => 'document_detail', 'document_id' => (int) $document->id], admin_url('admin.php')) . '#edusystem-document-signers') ?>"><?= esc_html((string) $document->title) ?></a></li>
            <?php endforeach; ?>
        </ul>
        <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
            <input type="hidden" name="action" value="edusystem_legacy_signatures_dismiss">
            <?php wp_nonce_field('edusystem_legacy_signatures_dismiss'); ?>
            <p><button type="submit" class="button-link"><?= esc_html__('Hide this notice', 'edusystem') ?></button></p>
        </form>
    </div>
    <?php
}

add_action('admin_post_edusystem_legacy_signatures_dismiss', 'edusystem_legacy_signatures_dismiss_handle');
function edusystem_legacy_signatures_dismiss_handle(): void
{
    check_admin_referer('edusystem_legacy_signatures_dismiss');
    if (!current_user_can(EDUSYSTEM_MANAGE_SIGNING_POLICIES_CAP)) {
        wp_die(esc_html__('You do not have permission to configure document signers.', 'edusystem'), 403);
    }
    update_option(EDUSYSTEM_LEGACY_SIGNATURES_DISMISSED, gmdate('Y-m-d H:i:s'), false);
    if (function_exists('edusystem_set_log')) {
        edusystem_set_log(sprintf('Aviso de documentos sin firmantes ocultado por el usuario %d', get_current_user_id()), 'signing_policy');
    }
    wp_safe_redirect(wp_get_referer() ?: admin_url());
    exit;
}
