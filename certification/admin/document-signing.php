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
function squuad_cert_document_signing_current_document(): ?object
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

add_action('admin_footer', 'squuad_cert_document_signing_panel');
function squuad_cert_document_signing_panel(): void
{
    if (!function_exists('squuad_cert_signers_enabled') || !squuad_cert_signers_enabled() || !current_user_can(SQUUAD_CERT_MANAGE_SIGNING_POLICIES_CAP)) {
        return;
    }
    $document = squuad_cert_document_signing_current_document();
    if (!$document) {
        return;
    }
    global $wpdb;

    $policy = squuad_cert_signing_policy($document);
    $positions = [];
    foreach ($policy['slots'] as $slot) {
        $positions[$slot['slot_type'] . ':' . ('role' === $slot['slot_type'] ? $slot['role'] : $slot['signer_id'])] = $slot['position'];
    }
    // Roles que pueden firmar (Certificación > Signing roles): una fila por rol, encima de los firmantes del sistema
    $site_roles = squuad_cert_site_roles();
    $signing_roles = array_intersect_key($site_roles, array_flip(squuad_cert_signing_roles()));
    // Roles que la política pedía pero ya no están activos: se avisa; al guardar se quitan
    $inactive_roles = array_diff(squuad_cert_signing_policy_roles($policy), array_keys($signing_roles));
    $signers = $wpdb->get_results(
        "SELECT s.*, u.display_name, u.user_email FROM {$wpdb->prefix}squuad_cert_signers s
         LEFT JOIN {$wpdb->users} u ON u.ID = s.user_id
         WHERE s.status IN ('active', 'invited') ORDER BY u.display_name"
    );
    $notice = function_exists('squuad_cert_signers_take_notice') ? squuad_cert_signers_take_notice() : null;
    $inbox = squuad_cert_signer_inbox_enabled();
    // Texto de la plantilla para marcar qué variables de firma ya están en uso (el panel lo actualiza en vivo)
    $template_text = (string) $document->header . (string) $document->content . (string) $document->footer;

    include SQUUAD_CERT_MODULE_PATH . 'admin/templates/document-signing.php';
}

add_action('admin_post_squuad_cert_save_signing_policy', 'squuad_cert_document_signing_handle_save');
function squuad_cert_document_signing_handle_save(): void
{
    if (!current_user_can(SQUUAD_CERT_MANAGE_SIGNING_POLICIES_CAP)) {
        wp_die(esc_html__('You do not have permission to configure document signers.', 'wp-certificates'), 403);
    }
    check_admin_referer('squuad_cert_save_signing_policy');
    $document_id = absint($_POST['document_certificate_id'] ?? 0);
    $requires = !empty($_POST['requires_signatures']);

    $slots = [];
    foreach ((array) ($_POST['slots'] ?? []) as $key => $slot) {
        if (empty($slot['enabled'])) {
            continue;
        }
        // 'role:<clave del rol>' (la clave puede tener espacios) o 'signer:<id>'
        $key = sanitize_text_field(wp_unslash((string) $key));
        [$type, $value] = array_pad(explode(':', $key, 2), 2, '');
        $slots[] = 'role' === $type
            ? ['slot_type' => 'role', 'role' => $value, 'signer_id' => 0, 'position' => (int) ($slot['position'] ?? 0)]
            : ['slot_type' => $type, 'role' => '', 'signer_id' => (int) $value, 'position' => (int) ($slot['position'] ?? 0)];
    }

    $result = squuad_cert_signing_policy_save($document_id, $requires, $slots);
    // Diseño Edusof: un solo interruptor de firmas. En los gestionados, el «requerirá firmas» de siempre
    // (documents_certificates.signature_required) sigue a este: así no hay dos valores distintos
    if ($result['ok'] && !empty($_POST['wpc_eds_sync_signature'])) {
        squuad_cert_document_signing_sync_required($document_id, $requires);
    }
    if (function_exists('squuad_cert_signers_notice')) {
        squuad_cert_signers_notice($result['message'], $result['ok']);
    }
    wp_safe_redirect(add_query_arg([
        'page' => 'add_admin_form_documents_content',
        'section_tab' => 'document_detail',
        'document_id' => $document_id,
    ], admin_url('admin.php')) . '#edusystem-document-signers');
    exit;
}

/**
 * Pone signature_required del documento gestionado igual que «Este documento pide firmas» de su política (solo si
 * cambia; los automáticos no usan ese campo). Queda en el log.
 */
function squuad_cert_document_signing_sync_required(int $document_id, bool $requires): void
{
    global $wpdb;

    $table = $wpdb->prefix . 'documents_certificates';
    $document = $wpdb->get_row($wpdb->prepare("SELECT id, `type`, signature_required FROM {$table} WHERE id = %d", $document_id));
    if (!$document || 'automatic' === $document->type || (int) (bool) $document->signature_required === (int) $requires) {
        return;
    }
    $wpdb->update($table, ['signature_required' => $requires ? 1 : 0], ['id' => $document_id]);
    squuad_cert_log(sprintf('Documento %d: «requerirá firmas» pasa a %d al guardar sus firmantes (usuario %d)', $document_id, $requires ? 1 : 0, get_current_user_id()), 'signing_policy');
}

/* ---------------------------------------------------------------------------------------------------------------
 * Paso 7: "Emitir para firma" desde la ficha del estudiante (documentos gestionados con firmantes del sistema)
 * ------------------------------------------------------------------------------------------------------------ */

/**
 * ¿Puede el usuario actual emitir (o declinar) documentos para firma de esta ficha? Hace falta el permiso «Emitir para
 * firma» (squuad_cert_issue_documents, Certificación > Permisos) y, si el titular tiene proveedor, que este lo deje
 * actuar sobre esa ficha (can_act 'issue').
 */
function squuad_cert_document_issue_can(int $student_id = 0): bool
{
    if (!current_user_can('squuad_cert_issue_documents')) {
        return false;
    }
    $provider = $student_id && function_exists('squuad_cert_subject_type') ? squuad_cert_subject_type(SQUUAD_CERT_SUBJECT_STUDENT) : null;
    if ($provider && !empty($provider['can_act'])) {
        return (bool) call_user_func($provider['can_act'], get_current_user_id(), $student_id, 'issue');
    }

    return true;
}

add_action('admin_post_squuad_cert_issue_document', 'squuad_cert_document_issue_handle');
function squuad_cert_document_issue_handle(): void
{
    $student_id = absint($_POST['student_id'] ?? 0);
    $document_certificate_id = absint($_POST['document_certificate_id'] ?? 0);
    check_admin_referer('squuad_cert_issue_document_' . $student_id . '_' . $document_certificate_id);
    if (!squuad_cert_document_issue_can($student_id)) {
        wp_die(esc_html__('You do not have permission to manage admissions.', 'wp-certificates'), 403);
    }
    $result = squuad_cert_signature_issue_document($student_id, $document_certificate_id);
    squuad_cert_signers_notice($result['message'], $result['ok']);
    $back = wp_get_referer() ?: admin_url('admin.php?page=add_admin_form_admission_content');
    wp_safe_redirect(remove_query_arg('squuad_cert_issued', $back) . '#edusystem-issued-documents');
    exit;
}

/**
 * Sección "Documentos emitidos para firma" de la ficha del estudiante: cada emisión con su estado, firmas dadas y
 * el PDF final cuando está completo. Se llama desde admin/templates/student-details.php.
 */
function squuad_cert_document_issued_section(object $student): void
{
    if (!function_exists('squuad_cert_signature_issued_for_student') || !squuad_cert_signers_enabled()) {
        return;
    }
    $issued = squuad_cert_signature_issued_for_student((int) $student->id);
    $notice = squuad_cert_signers_take_notice();
    if (!$issued && !$notice) {
        return;
    }
    include SQUUAD_CERT_MODULE_PATH . 'admin/templates/document-issued.php';
}

/* ---------------------------------------------------------------------------------------------------------------
 * Paso 8b: tomo y folio (libro de registro de EduSof) de los documentos emitidos
 * ------------------------------------------------------------------------------------------------------------ */

/**
 * Reserva la línea del libro al emitir (filtro de squuad_cert_signature_issue_document): una línea nueva en el libro del
 * documento, con su descripción (includes/book.php de wp-certificates); el libro devuelve tomo, folio y número de
 * línea. Devuelve la fila de squuad_cert_book_entries o WP_Error (no se emite).
 */
add_filter('squuad_cert_issue_book_entry', 'squuad_cert_document_issue_reserve_book_line', 10, 4);
function squuad_cert_document_issue_reserve_book_line($entry, object $student, object $document, object $request)
{
    if (is_object($entry) && !is_wp_error($entry)) {
        return $entry;
    }
    $line = squuad_cert_book_reserve_line($document, 'edusystem_student', (int) $student->id);
    if (is_wp_error($line)) {
        return $line;
    }
    $row = squuad_cert_book_entry_insert((int) $student->id, (int) $document->id, (int) $document->book, ['id' => $line['line_id']] + $line);
    if (!$row) {
        return new WP_Error('squuad_cert_book', __('The registry book did not assign a volume and folio (connection or book error). The document was not issued; try again.', 'wp-certificates'));
    }

    return $row;
}

/** Anula la línea en el libro (con motivo) y la marca anulada. Devuelve ['ok', 'message']. */
function squuad_cert_document_issue_void_book_line(object $entry, string $reason): array
{
    $voided = squuad_cert_book_void_line((int) $entry->line_id, $reason);
    if (is_wp_error($voided)) {
        return ['ok' => false, 'message' => sprintf(__('The registry book could not void the volume and folio: %s', 'wp-certificates'), $voided->get_error_message())];
    }
    squuad_cert_book_entry_mark_void((int) $entry->id, $reason);

    return ['ok' => true, 'message' => ''];
}

/**
 * Decisión sobre el tomo/folio de un documento emitido y declinado: 'keep' reemite con la misma línea; 'void' la anula
 * en el libro y reemite con una nueva. Se sella en la solicitud declinada. Devuelve ['ok', 'message'].
 */
function squuad_cert_document_issue_resolve_book(object $request, object $entry, string $option, string $reason): array
{
    if ('void' === $option) {
        $voided = squuad_cert_document_issue_void_book_line($entry, $reason);
        if (!$voided['ok']) {
            return $voided;
        }
    }
    squuad_cert_signature_request_log_event((int) $request->id, 'book_decision', [
        'option' => $option,
        'entry_id' => (int) $entry->id,
        'line_id' => (int) $entry->line_id,
        'tomo' => (int) $entry->tomo,
        'folio' => (int) $entry->folio,
        'reason' => $reason,
    ]);
    $reissued = squuad_cert_signature_issue_document((int) $request->subject_id, (int) $request->document_certificate_id, 'keep' === $option ? (int) $entry->id : 0);
    if (!$reissued['ok']) {
        return ['ok' => false, 'message' => sprintf(__('Declined, but the document could not be issued again: %s', 'wp-certificates'), $reissued['message'])];
    }
    $new_entry = squuad_cert_book_entry_get((int) (squuad_cert_signature_request_get((int) $reissued['request_id'])->book_entry_id ?? 0));

    return ['ok' => true, 'message' => sprintf(
        /* translators: 1: volume, 2: folio */
        __('Declined and issued again with volume %1$d, folio %2$d. The signers will find it in "Documents to sign".', 'wp-certificates'),
        (int) ($new_entry->tomo ?? 0),
        (int) ($new_entry->folio ?? 0)
    )];
}

/** Declinar un documento emitido desde la ficha del estudiante, con la decisión sobre el tomo/folio si lo tiene. */
add_action('admin_post_squuad_cert_issued_decline', 'squuad_cert_document_issued_decline_handle');
function squuad_cert_document_issued_decline_handle(): void
{
    $request_id = absint($_POST['request_id'] ?? 0);
    check_admin_referer('squuad_cert_issued_decline_' . $request_id);
    $request = squuad_cert_signature_request_get($request_id);
    if (!squuad_cert_document_issue_can($request ? (int) $request->subject_id : 0)) {
        wp_die(esc_html__('You do not have permission to manage admissions.', 'wp-certificates'), 403);
    }
    $reason = sanitize_textarea_field(wp_unslash($_POST['reason'] ?? ''));
    $option = sanitize_key($_POST['book_option'] ?? '');
    $entry = $request && (int) $request->book_entry_id ? squuad_cert_book_entry_get((int) $request->book_entry_id) : null;
    $entry = $entry && 'active' === $entry->status ? $entry : null;

    if (!$request || 'issued' !== $request->origin) {
        $result = ['ok' => false, 'message' => __('This document can no longer be declined. Please reload the page.', 'wp-certificates')];
    } elseif ('' === trim($reason) || empty($_POST['confirm_irreversible'])) {
        $result = ['ok' => false, 'message' => __('To decline a document you must write the reason and accept that the action cannot be reverted.', 'wp-certificates')];
    } elseif ($entry && !in_array($option, ['keep', 'void'], true)) {
        $result = ['ok' => false, 'message' => __('Choose whether to keep or void the volume and folio.', 'wp-certificates')];
    } else {
        $result = squuad_cert_signature_issued_decline($request_id, $reason);
        if ($result['ok'] && $entry) {
            $result = squuad_cert_document_issue_resolve_book(squuad_cert_signature_request_get($request_id), $entry, $option, $reason);
        }
    }
    squuad_cert_signers_notice($result['message'], $result['ok']);
    wp_safe_redirect((wp_get_referer() ?: admin_url('admin.php?page=add_admin_form_admission_content')) . '#edusystem-issued-documents');
    exit;
}

/** Decisión pendiente sobre el tomo/folio (documento declinado por un firmante, o reemisión que falló). */
add_action('admin_post_squuad_cert_issued_book_decision', 'squuad_cert_document_issued_book_decision_handle');
function squuad_cert_document_issued_book_decision_handle(): void
{
    $request_id = absint($_POST['request_id'] ?? 0);
    check_admin_referer('squuad_cert_issued_book_decision_' . $request_id);
    $request = squuad_cert_signature_request_get($request_id);
    if (!squuad_cert_document_issue_can($request ? (int) $request->subject_id : 0)) {
        wp_die(esc_html__('You do not have permission to manage admissions.', 'wp-certificates'), 403);
    }
    $option = sanitize_key($_POST['book_option'] ?? '');
    $reason = sanitize_textarea_field(wp_unslash($_POST['reason'] ?? ''));
    $entry = $request ? squuad_cert_book_entry_pending_decision((int) $request->subject_id, (int) $request->document_certificate_id) : null;

    if (!$entry || (int) $entry->declined_request_id !== $request_id) {
        $result = ['ok' => false, 'message' => __('There is no pending decision for this document. Please reload the page.', 'wp-certificates')];
    } elseif (!in_array($option, ['keep', 'void'], true)) {
        $result = ['ok' => false, 'message' => __('Choose whether to keep or void the volume and folio.', 'wp-certificates')];
    } elseif ('void' === $option && '' === trim($reason)) {
        $result = ['ok' => false, 'message' => __('Write why the volume and folio are voided.', 'wp-certificates')];
    } else {
        $result = squuad_cert_document_issue_resolve_book($request, $entry, $option, '' !== trim($reason) ? $reason : (string) $request->decline_reason);
    }
    squuad_cert_signers_notice($result['message'], $result['ok']);
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
function squuad_cert_missing_letter_conversion_available(): bool
{
    global $wpdb;

    // La carta es de EduSystem (documentos faltantes del estudiante): sin EduSystem activo no se ofrece
    if (!function_exists('squuad_cert_signers_enabled') || !squuad_cert_signers_enabled() || !wpc_edusystem_active()) {
        return false;
    }
    $table = $wpdb->prefix . 'documents_certificates';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
        return false;
    }
    if ($wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE document_identificator = %s LIMIT 1", SQUUAD_CERT_MISSING_LETTER_ID))) {
        return false;
    }

    // users_signatures y student_documents son tablas de EduSystem: sin él (o en un sitio que no las tenga) no hay carta
    foreach (['users_signatures', 'student_documents'] as $legacy) {
        $legacy_table = $wpdb->prefix . $legacy;
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $legacy_table)) === $legacy_table
            && $wpdb->get_var($wpdb->prepare("SELECT id FROM {$legacy_table} WHERE document_id = %s LIMIT 1", SQUUAD_CERT_MISSING_LETTER_ID))) {
            return true;
        }
    }

    return false;
}

/**
 * Plantilla de la carta: el texto de la carta anterior (public/templates/create-missing-documents.php) con la lista
 * {{missing_documents}}, el año y la fecha de inicio como variables y la sección de firmas del sistema.
 */
function squuad_cert_missing_letter_template(): array
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

add_action('all_admin_notices', 'squuad_cert_missing_letter_conversion_notice'); // ver hide_notices (branding.php)
function squuad_cert_missing_letter_conversion_notice(): void
{
    if (($_GET['page'] ?? '') !== 'add_admin_form_documents_content' || !empty($_GET['section_tab'])
        || !current_user_can(SQUUAD_CERT_MANAGE_SIGNING_POLICIES_CAP) || !squuad_cert_missing_letter_conversion_available()) {
        return;
    }
    // Con el diseño Edusof, el aviso se dibuja dentro de la lista de documentos (admin/templates/eds-list-documents.php)
    if (function_exists('wpc_eds_documents_enabled') && wpc_eds_documents_enabled()) {
        return;
    }
    ?>
    <div class="notice notice-info">
        <p><strong><?= esc_html__('Missing documents commitment letter', 'wp-certificates') ?></strong><br>
            <?= esc_html__('This site uses the old fixed letter. Convert it into an automatic document: it will be editable here, signed with the new signature system (frozen content, consent, "Documents to sign"). Like every automatic document, it is shown while the student has not signed it. Letters already signed keep their validity and are not asked again.', 'wp-certificates') ?></p>
        <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
            <input type="hidden" name="action" value="squuad_cert_convert_missing_letter">
            <?php wp_nonce_field('squuad_cert_convert_missing_letter'); ?>
            <p><button type="submit" class="button button-primary"><?= esc_html__('Convert the letter into an automatic document', 'wp-certificates') ?></button></p>
        </form>
    </div>
    <?php
}

add_action('admin_post_squuad_cert_convert_missing_letter', 'squuad_cert_missing_letter_convert_handle');
function squuad_cert_missing_letter_convert_handle(): void
{
    global $wpdb;

    check_admin_referer('squuad_cert_convert_missing_letter');
    if (!current_user_can(SQUUAD_CERT_MANAGE_SIGNING_POLICIES_CAP)) {
        wp_die(esc_html__('You do not have permission to configure document signers.', 'wp-certificates'), 403);
    }
    if (!squuad_cert_missing_letter_conversion_available()) {
        squuad_cert_signers_notice(__('The letter was already converted or is not used on this site.', 'wp-certificates'), false);
        wp_safe_redirect(admin_url('admin.php?page=add_admin_form_documents_content'));
        exit;
    }

    // Se inserta directamente (no con el formulario de wp-certificates, que al guardar un documento automático crea
    // una fila pendiente para todos los estudiantes). No obligatorio ni visible, como las filas de la carta anterior.
    $template = squuad_cert_missing_letter_template();
    $inserted = $wpdb->insert($wpdb->prefix . 'documents_certificates', [
        'title' => 'MISSING DOCUMENT COMMITMENT LETTER',
        'document_identificator' => SQUUAD_CERT_MISSING_LETTER_ID,
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
        squuad_cert_signers_notice(__('The letter could not be converted. Please try again.', 'wp-certificates'), false);
        wp_safe_redirect(admin_url('admin.php?page=add_admin_form_documents_content'));
        exit;
    }

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
    squuad_cert_log(sprintf('Carta de documentos faltantes convertida en el documento automático %d por el usuario %d', $document_id, get_current_user_id()), 'signing_policy');
    squuad_cert_signers_notice(__('The letter is now an automatic document. Review its text below; like every automatic document, it is shown while the student has not signed it.', 'wp-certificates'), true);
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

const SQUUAD_CERT_LEGACY_SIGNATURES_NOTIFIED = 'squuad_cert_legacy_signatures_notified';
const SQUUAD_CERT_LEGACY_SIGNATURES_DISMISSED = 'squuad_cert_legacy_signatures_notice_dismissed';

/** Documentos gestionados activos que exigen firma y todavía no tienen firmantes del sistema: quedan deshabilitados. */
function squuad_cert_legacy_signature_affected_documents(): array
{
    global $wpdb;

    $table = $wpdb->prefix . 'documents_certificates';
    if (!function_exists('squuad_cert_third_party_signatures_blocked') || !squuad_cert_third_party_signatures_blocked()
        || $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
        return [];
    }
    $documents = $wpdb->get_results("SELECT * FROM {$table} WHERE signature_required = 1 AND `status` = 1 AND `type` <> 'automatic' ORDER BY title");

    return array_values(array_filter($documents, static fn($document): bool => !squuad_cert_signature_issue_signers($document)));
}

/**
 * Una sola vez por sitio, la primera vez que un administrador entra tras la actualización: si hay documentos
 * afectados, correo a los titulares de las firmas-imagen de "Users and signatures" y a los administradores
 * explicando el cambio y qué hacer. add_option() hace de cerrojo (no se envía dos veces aunque entren a la vez).
 */
add_action('admin_init', 'squuad_cert_legacy_signatures_migration_notify', 20);
function squuad_cert_legacy_signatures_migration_notify(): void
{
    global $wpdb;

    if (wp_doing_ajax() || wp_doing_cron() || !current_user_can('manage_options') || false !== get_option(SQUUAD_CERT_LEGACY_SIGNATURES_NOTIFIED, false)) {
        return;
    }
    if (!function_exists('squuad_cert_third_party_signatures_blocked') || !squuad_cert_third_party_signatures_blocked()) {
        return;
    }
    if (!add_option(SQUUAD_CERT_LEGACY_SIGNATURES_NOTIFIED, ['at' => gmdate('Y-m-d H:i:s'), 'sent' => 0], '', false)) {
        return;
    }
    $documents = squuad_cert_legacy_signature_affected_documents();
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
    $subject = sprintf(__('[%s] Security change: documents that require a signature', 'wp-certificates'), $site);
    $intro = __('For security, documents can no longer be signed with signature images uploaded to "Users and signatures". Only the responsible person can sign, from their own account: nobody can sign on behalf of someone else.', 'wp-certificates');
    $sent = 0;
    foreach ($owners as $user) {
        $body = sprintf(__('Hello %s,', 'wp-certificates'), $user->display_name) . "\n\n" . $intro . "\n\n"
            . __('These documents require a signature and are disabled until the administration configures who signs them:', 'wp-certificates') . "\n" . $list . "\n\n"
            . __('If you are responsible for signing them, the administration will invite you to register your own signature. Documents already signed keep their validity.', 'wp-certificates') . "\n";
        $sent += (int) wp_mail($user->user_email, $subject, $body);
    }
    foreach ($admins as $user) {
        $body = sprintf(__('Hello %s,', 'wp-certificates'), $user->display_name) . "\n\n" . $intro . "\n\n"
            . __('These documents require a signature and are disabled until you configure who signs them:', 'wp-certificates') . "\n" . $list . "\n\n"
            . (wpc_edusystem_active()
                ? __('What to do: 1) in Certification > Signers, invite the responsible persons to register their own signature; 2) in Certification > Documents, open each document and choose its signers in "Document signers"; 3) from the student file, use "Issue for signature". Documents already signed keep their validity.', 'wp-certificates')
                : __('What to do: 1) in Certification > Signers, invite the responsible persons to register their own signature; 2) in Certification > Documents, open each document and choose its signers in "Document signers". Documents already signed keep their validity.', 'wp-certificates')) . "\n"
            . admin_url('admin.php?page=add_admin_form_documents_content') . "\n";
        $sent += (int) wp_mail($user->user_email, $subject, $body);
    }
    update_option(SQUUAD_CERT_LEGACY_SIGNATURES_NOTIFIED, ['at' => gmdate('Y-m-d H:i:s'), 'sent' => $sent, 'documents' => count($documents)], false);
    squuad_cert_log(sprintf(
            'Aviso de la actualización (nadie firma por otro): %d documentos sin firmantes; correo a %d titulares de firmas-imagen (%s) y %d administradores (%s)',
            count($documents),
            count($owners),
            implode(', ', array_keys($owners)),
            count($admins),
            implode(', ', array_keys($admins))
        ), 'signing_policy');
}

/**
 * Aviso en el admin (gestores de firmantes) mientras haya documentos que exigen firma sin responsables. En
 * all_admin_notices: hide_notices (branding.php) quita admin_notices a quien no es superadministrador.
 */
add_action('all_admin_notices', 'squuad_cert_legacy_signatures_admin_notice');
function squuad_cert_legacy_signatures_admin_notice(): void
{
    if (!current_user_can(SQUUAD_CERT_MANAGE_SIGNING_POLICIES_CAP) || get_option(SQUUAD_CERT_LEGACY_SIGNATURES_DISMISSED)) {
        return;
    }
    // Con el diseño Edusof, solo en la lista de documentos, dentro de la página (admin/templates/eds-list-documents.php)
    if (function_exists('wpc_eds_documents_enabled') && wpc_eds_documents_enabled()) {
        return;
    }
    $documents = squuad_cert_legacy_signature_affected_documents();
    if (!$documents) {
        return;
    }
    ?>
    <div class="notice notice-warning">
        <p><strong><?= esc_html__('Documents that require a signature are disabled until you configure who signs them', 'wp-certificates') ?></strong><br>
            <?= esc_html__('For security, nobody can sign on behalf of someone else: signature images are no longer used. Choose the signers of each document; they will sign from their own account.', 'wp-certificates') ?></p>
        <ul style="list-style:disc;margin-left:20px">
            <?php foreach ($documents as $document) : ?>
                <li><a href="<?= esc_url(add_query_arg(['page' => 'add_admin_form_documents_content', 'section_tab' => 'document_detail', 'document_id' => (int) $document->id], admin_url('admin.php')) . '#edusystem-document-signers') ?>"><?= esc_html((string) $document->title) ?></a></li>
            <?php endforeach; ?>
        </ul>
        <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
            <input type="hidden" name="action" value="squuad_cert_legacy_signatures_dismiss">
            <?php wp_nonce_field('squuad_cert_legacy_signatures_dismiss'); ?>
            <p><button type="submit" class="button-link"><?= esc_html__('Hide this notice', 'wp-certificates') ?></button></p>
        </form>
    </div>
    <?php
}

add_action('admin_post_squuad_cert_legacy_signatures_dismiss', 'squuad_cert_legacy_signatures_dismiss_handle');
function squuad_cert_legacy_signatures_dismiss_handle(): void
{
    check_admin_referer('squuad_cert_legacy_signatures_dismiss');
    if (!current_user_can(SQUUAD_CERT_MANAGE_SIGNING_POLICIES_CAP)) {
        wp_die(esc_html__('You do not have permission to configure document signers.', 'wp-certificates'), 403);
    }
    update_option(SQUUAD_CERT_LEGACY_SIGNATURES_DISMISSED, gmdate('Y-m-d H:i:s'), false);
    squuad_cert_log(sprintf('Aviso de documentos sin firmantes ocultado por el usuario %d', get_current_user_id()), 'signing_policy');
    wp_safe_redirect(wp_get_referer() ?: admin_url());
    exit;
}
