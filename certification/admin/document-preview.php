<?php
declare(strict_types=1);

/**
 * EduSystem - Vista previa en PDF de los documentos de wp-certificates, con datos de ejemplo.
 *
 * En la ficha del documento (page=add_admin_form_documents_content&section_tab=document_detail&document_id=X)
 * sustituye la vista previa HTML de wp-certificates (filtro wpc_document_preview) por el PDF que saldría, generado
 * en el navegador con las mismas opciones que el camino real del documento:
 *   - automatic: PDF final de la solicitud de firma (Mi Cuenta del último firmante): A4 vertical, margen 0,3 in y
 *     sin texto cortado entre páginas (avoid-all).
 *   - issued: documento gestionado con firmantes del sistema, emitido para firma (panel del firmante): su formato,
 *     sin margen.
 *   - generate: documento gestionado sin firmantes ("Generar" de la ficha del estudiante): su formato; en vertical,
 *     el encabezado y el pie se estampan en cada página.
 * Todas las variables toman valores de ejemplo, nunca datos de un estudiante. Las que la plantilla escribe mal se
 * dejan como texto, igual que en el documento real, y se listan debajo del visor.
 */

if (!defined('ABSPATH')) exit;

add_filter('wpc_document_preview', 'squuad_cert_document_preview_markup', 10, 2);
function squuad_cert_document_preview_markup($markup, $document)
{
    if (!is_object($document) || empty($document->id)) {
        return $markup;
    }
    $preview = squuad_cert_document_preview_data($document);
    // QR como imagen también en la vista del navegador (generador propio, ADR 0013): sin qr-code-styling de internet
    foreach (['html', 'header', 'content', 'footer'] as $part) {
        if (!empty($preview[$part])) {
            $preview[$part] = squuad_cert_pdf_qr_inline((string) $preview[$part], 'https://example.com/verify/EXAMPLE');
        }
    }

    ob_start();
    include SQUUAD_CERT_MODULE_PATH . 'admin/templates/document-preview.php';

    return (string) ob_get_clean();
}

/** Pie del PDF de las solicitudes de firma, con valores de ejemplo (el real lleva el número, la ronda y la huella). */
function squuad_cert_document_preview_fingerprint(?object $document = null): string
{
    // Sin la hoja del certificado de firmas (ADR 0014), el PDF final no lleva la línea
    if ($document && function_exists('squuad_cert_signature_sheet_for_document') && 'no' === squuad_cert_signature_sheet_for_document($document)) {
        return '';
    }

    return sprintf(__('Signature request #%1$d, round %2$d · Content fingerprint (SHA-256): %3$s', 'wp-certificates'), 123, 1, hash('sha256', 'example'));
}

/**
 * Petición para el motor de PDF del servidor (ADR 0013 de Edusof) con el mismo contenido y formato que la vista previa
 * del navegador: HTML construido aquí (nunca el que mande el navegador), QR como imagen y la página del documento.
 */
function squuad_cert_document_preview_server_payload(object $document): array
{
    $data = squuad_cert_document_preview_data($document);
    if ('generate' === $data['mode']) {
        $g = $data['generate'];
        $page = squuad_cert_pdf_page(['unit' => $g['unit'], 'format' => $g['paper_format'], 'orientation' => $g['orientation']], 0);
        // Como «Generar»: el contenido ocupa al menos la página; encabezado y pie en cada página solo en vertical
        $content = '<div style="padding:0;margin:0;background:#fff;min-width:' . esc_attr($g['width']) . ';min-height:' . esc_attr($g['height']) . '">' . $data['content'] . '</div>';
        $portrait = 'portrait' === $g['orientation'];

        return squuad_cert_pdf_payload($content, $page, $portrait ? $data['header'] : '', $portrait ? $data['footer'] : '', 'https://example.com/verify/EXAMPLE');
    }
    if ('issued' === $data['mode'] && function_exists('squuad_cert_final_pdf_issued_layout')) {
        // Emitido: la misma maqueta que el PDF final (diseño sin relleno; la línea de la solicitud en su hoja A4)
        [$html, $css] = squuad_cert_final_pdf_issued_layout(['content' => (string) $data['html'], 'line' => squuad_cert_document_preview_fingerprint($document), 'certificate' => '']);

        return squuad_cert_pdf_payload($html, squuad_cert_pdf_page($data['page']['jspdf'], (float) $data['page']['margin']), '', '', 'https://example.com/verify/EXAMPLE', $css);
    }
    // Automático: como signature-final-pdf.php (contenido de la solicitud + pie)
    $html = '<div style="box-sizing:border-box;width:100%;background:#fff;padding:16px;font-family:Arial,sans-serif;color:#111">'
        . $data['html'] . ('' !== ($line = squuad_cert_document_preview_fingerprint($document)) ? '<p style="margin-top:16px;font-size:9px;color:#666;word-break:break-all">' . esc_html($line) . '</p>' : '') . '</div>';
    $page = 'automatic' === $data['mode']
        ? squuad_cert_pdf_page(['unit' => 'mm', 'format' => 'a4', 'orientation' => 'portrait'], 7.62)
        : squuad_cert_pdf_page($data['page']['jspdf'], (float) $data['page']['margin']);

    return squuad_cert_pdf_payload($html, $page, '', '', 'https://example.com/verify/EXAMPLE');
}

/**
 * Vista previa hecha por el servidor: devuelve el PDF (o JSON con el error, y la página vuelve al navegador). Con el
 * permiso de la pantalla de documentos, nonce y como mucho 30 peticiones por minuto y usuario.
 */
add_action('wp_ajax_squuad_cert_preview_server_pdf', 'squuad_cert_document_preview_server_ajax');
function squuad_cert_document_preview_server_ajax(): void
{
    global $wpdb;

    if (!current_user_can('manager_documents_certificates')) {
        wp_send_json_error(['message' => __('Sorry, you are not allowed to access this page.', 'wp-certificates')], 403);
    }
    check_ajax_referer('squuad_cert_preview_server_pdf');
    $key = 'squuad_cert_preview_rate_' . get_current_user_id();
    $count = (int) get_transient($key);
    if ($count >= 30) {
        wp_send_json_error(['message' => __('Too many previews in a short time. Wait a minute.', 'wp-certificates')], 429);
    }
    set_transient($key, $count + 1, MINUTE_IN_SECONDS);
    $id = isset($_POST['document_id']) ? absint($_POST['document_id']) : 0;
    $document = $id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}documents_certificates WHERE id = %d", $id)) : null;
    if (!$document) {
        wp_send_json_error(['message' => __('The document does not exist.', 'wp-certificates')], 404);
    }
    if ('servicio' !== squuad_cert_pdf_engine_for_document($document)) {
        wp_send_json_error(['message' => 'navegador'], 409);
    }
    $result = squuad_cert_pdf_render(squuad_cert_document_preview_server_payload($document), 'vista previa del documento ' . $id);
    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()], 502);
    }
    // Nada antes del PDF (avisos, BOM, compresión): si no, el visor recibiría un PDF roto
    while (ob_get_level()) {
        ob_end_clean();
    }
    if (function_exists('ini_get') && ini_get('zlib.output_compression')) {
        @ini_set('zlib.output_compression', '0'); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    }
    nocache_headers();
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="vista-previa.pdf"');
    header('Content-Length: ' . strlen($result['pdf']));
    header('X-Content-Type-Options: nosniff');
    header('X-Squuad-Cert-Engine: servicio; ' . (int) $result['ms'] . ' ms; Chrome ' . preg_replace('/[^0-9.]/', '', $result['chrome']));
    echo $result['pdf']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- PDF binario
    exit;
}

/** Camino por el que sale el documento real: 'automatic', 'issued' o 'generate'. */
function squuad_cert_document_preview_mode(object $document): string
{
    if ('automatic' === ($document->type ?? '')) {
        return 'automatic';
    }

    return function_exists('squuad_cert_signature_issue_signers') && squuad_cert_signature_issue_signers($document) ? 'issued' : 'generate';
}

/** Recuadro de firma de ejemplo, como los del PDF final (trazo en SVG y fecha). */
function squuad_cert_document_preview_signature_box(string $name): string
{
    $strokes = '[{"penColor":"black","points":[{"x":20,"y":60},{"x":40,"y":30},{"x":60,"y":65},{"x":80,"y":25},{"x":100,"y":60},'
        . '{"x":120,"y":35},{"x":140,"y":62},{"x":160,"y":30},{"x":180,"y":58},{"x":200,"y":40},{"x":220,"y":50}]}]';
    $svg = function_exists('squuad_cert_signature_svg') ? squuad_cert_signature_svg($strokes, $name) : '';

    return $svg . '<div style="font-size:10px;color:#666">' . esc_html(gmdate('Y-m-d H:i')) . ' UTC</div>';
}

/** Recuadro con nombre y cargo debajo de la línea, como en el bloque de firmas del PDF final. */
function squuad_cert_document_preview_signature_block(string $name, string $label): string
{
    return '<div style="min-width:260px;text-align:center">' . squuad_cert_document_preview_signature_box($name)
        . '<div style="border-top:1px solid #333;margin-top:4px;padding-top:4px"><strong>' . esc_html($name) . '</strong><br>'
        . esc_html($label) . '</div></div>';
}

/** Firmantes de ejemplo según los firmantes configurados del documento (nombres y cargos de ejemplo). */
function squuad_cert_document_preview_signers(object $document): array
{
    $policy = function_exists('squuad_cert_signing_policy') ? squuad_cert_signing_policy($document) : ['slots' => []];
    $signers = [];
    $n = 0;
    foreach ($policy['slots'] ?? [] as $slot) {
        if ('signer' === $slot['slot_type'] && !empty($slot['signer_id'])) {
            $n++;
            $signers[] = [
                'slot_key' => squuad_cert_signer_slot_key((int) $slot['signer_id']),
                'signer_id' => (int) $slot['signer_id'],
                /* translators: %d: number of the signer in the document */
                'name' => sprintf(__('Signer %d (example)', 'wp-certificates'), $n),
                'charge' => __('Position (example)', 'wp-certificates'),
                'phase' => 2,
            ];
        } elseif ('role' === $slot['slot_type']) {
            // Cada rol del panel: el recuadro de quien recibe el documento con ese rol (cada uno ve solo el suyo)
            $signers[] = ['slot_key' => 'role:' . $slot['role'], 'signer_id' => 0, 'phase' => 1];
        } elseif ('var' === $slot['slot_type'] && 'automatic' === ($document->type ?? '') && function_exists('squuad_cert_var_slot_key')) {
            // Firmante por variable (ADR 0009 de Edusof): una persona de ejemplo con el nombre del puesto
            $signers[] = [
                'slot_key' => squuad_cert_var_slot_key((string) $slot['role']),
                'signer_id' => 0,
                'variable' => (string) $slot['role'],
                'name' => __('Person by variable (example)', 'wp-certificates'),
                'charge' => squuad_cert_signer_variable_label((string) $slot['role']),
                'phase' => 2,
            ];
        }
    }

    return $signers;
}

/** Tabla de ejemplo con el mismo formato que las tablas de notas reales (html-notes.php). */
function squuad_cert_document_preview_notes_table(array $headers, array $rows): string
{
    $head = '';
    foreach ($headers as $header => $width) {
        $head .= '<th' . ($width ? " style='width: {$width}px;'" : '') . '>' . esc_html($header) . '</th>';
    }
    $body = '';
    foreach ($rows as $row) {
        $body .= '<tr><td>' . implode('</td><td>', array_map('esc_html', $row)) . '</td></tr>';
    }

    return "<table class='wp-list-table widefat fixed posts striped' style='margin-top: 20px; border: 1px dashed #c3c4c7;' id=\"tablenotcustom\">"
        . "<thead><tr>{$head}</tr></thead><tbody>{$body}</tbody></table>";
}

/** Tabla simple de ejemplo con bordes (formato de las tablas de documentos de html-documents.php). */
function squuad_cert_document_preview_plain_table(array $headers, array $rows): string
{
    $cell = 'border: 1px solid #333; padding: 4px 6px; text-align: left;';
    $html = '<table style="width: 100%; border-collapse: collapse; margin: 0 !important"><thead><tr>';
    foreach ($headers as $header) {
        $html .= '<th style="' . $cell . '">' . esc_html($header) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr><td style="' . $cell . '">' . implode('</td><td style="' . $cell . '">', array_map('esc_html', $row)) . '</td></tr>';
    }

    return $html . '</tbody></table>';
}

/**
 * Valores de ejemplo de todas las variables de los documentos (las de get_replacements_variables(), las de firma,
 * las de tomo y folio y los campos adicionales del documento), con el mismo formato que los reales.
 */
function squuad_cert_document_preview_replacements(object $document, string $mode): array
{
    $text = static fn(string $value): array => ['value' => esc_html($value), 'wrap' => true];
    $html = static fn(string $value): array => ['value' => $value, 'wrap' => false];
    $student = 'Juan Carlos Pérez Gómez';
    $parent = 'María Gómez de Pérez';
    $subjects = [
        ['MAT0911', 'ALGEBRA I', '95', 'A', '4.00', '1'],
        ['ENG0913', 'ENGLISH I', '88', 'B+', '3.30', '1'],
        ['SCI0912', 'EARTH SCIENCE', '91', 'A-', '3.70', '1'],
        ['HIS0914', 'WORLD HISTORY', '84', 'B', '3.00', '1'],
    ];

    $replacements = [
        'student_name' => $text('PÉREZ GÓMEZ, JUAN CARLOS'),
        'full_name' => $text('PÉREZ GÓMEZ, JUAN CARLOS'),
        'name' => $text('Juan Carlos'),
        'last_name' => $text('Pérez Gómez'),
        'id_student' => $text('V-12345678'),
        'holder_id_document' => ['value' => 'V12345678', 'wrap' => false],
        'folio' => $text('125'),
        'tomo' => $text('3'),
        'tomo_folio' => $text('Tomo: 3 Folio: 125'),
        'race' => $text('HISPANIC'),
        'nacionality' => $text('Venezuelan'),
        'birth_date' => $text('05/14/2008'),
        'gender' => $text('male'),
        'program' => $text(__('Example program', 'wp-certificates')),
        'career_mention' => $text(__('Example career - Example mention', 'wp-certificates')),
        'name_term_student_entered' => $text('Fall 2025'),
        'year_term_student_entered' => $text('2025'),
        'start_term_student_entered' => $text('08/18/2025'),
        'end_term_student_entered' => $text('12/19/2025'),
        'academic_year' => $text('2025-2026'),
        'initial_academic_cut' => $html('August 18, 2025'),
        'start_academic_year' => $text('August 18, 2025'),
        'end_academic_year' => $text('June 12, 2026'),
        'address' => $text('123 Example Street'),
        'state' => $text('Florida'),
        'country' => $text('United States'),
        'zip_code' => $text('33172'),
        'phone' => $text('+1 (305) 555-0100'),
        'city' => $text('Doral'),
        'fax' => $text('+1 (305) 555-0101'),
        'other_phone' => $text('+1 (305) 555-0104'),
        'institution_name_form_filled' => $text('Example High School'),
        'institution_city_country_form_filled' => $text('Caracas / Venezuela'),
        'institution_title_obtained_form_filled' => $text('High School Diploma'),
        'institution_graduation_year_form_filled' => $text('2024'),
        'created_at' => $text(squuad_cert_format_date(time())),
        'email' => $text('juan.perez@example.com'),
        'today' => $text(squuad_cert_format_date_long(time())),
        'ethinicity_selected' => $html('HISPANIC'), // valor de ejemplo fijo (no se llama a EduSystem)
        'language_selected' => $html('ENGLISH'),
        'page_break' => $html('<div class="pagebreak"></div>'),
        'missing_documents' => $html('<ul style="list-style:none;padding-left:0"><li>' . esc_html__('Example document 1', 'wp-certificates') . '</li><li>' . esc_html__('Example document 2', 'wp-certificates') . '</li></ul>'),
        'show_parent_info' => $html('1'),
        'parent_full_name' => $text($parent),
        'parent_email' => $text('parent@example.com'),
        'parent_cell' => $text('+1 (305) 555-0102'),
        'parent_identification' => $text('V-87654321'),
        'institute_name' => $text('Example High School'),
        'institute_address' => $text('456 Example Avenue, Doral, FL'),
        'institute_phone' => $text('+1 (305) 555-0103'),
        'payment_full_year_check' => $html('✓'),
        'payment_balance_check' => $html('  '),
        'table_notes' => $html(squuad_cert_document_preview_notes_table(
            ['PERIOD' => 150, 'CODE' => 70, 'COURSE' => 0, 'CH' => 40, '0-100' => 40, '0-4' => 40],
            array_map(static fn($s) => ['2025-2026', $s[0], $s[1], $s[5], $s[2], $s[4]], $subjects)
        )),
        'new_table_notes' => $html(squuad_cert_document_preview_notes_table(
            ['Semester / Academic Year' => 70, 'Course Code and Title' => 0, 'Status' => 40, 'Grade' => 40, 'GPA' => 40],
            array_map(static fn($s) => ['2025-2026', $s[0] . ' - ' . $s[1], 'T', $s[3], $s[4]], $subjects)
        )),
        'table_notes_summary' => $html("<table class='wp-list-table widefat fixed posts striped' style='margin-top: 20px; border: 1px dashed #c3c4c7;' id=\"tablenotcustom\"><tbody>"
            . "<tr><td colspan='12'>Total Quality Points: 14</td></tr><tr><td colspan='12'>Earned CH: 4</td></tr><tr><td colspan='12'>GPA: 3.5</td></tr></tbody></table>"),
        'table_inscriptions' => $html(squuad_cert_document_preview_notes_table(
            ['Subject - Code' => 0, 'Period - cut' => 80, 'Calification' => 80, 'Status' => 90],
            array_map(static fn($s) => [$s[1] . ' - ' . $s[0], '2025-2026 - A', $s[2], 'Approved'], $subjects)
        )),
        'table_notes_period' => $html(squuad_cert_document_preview_notes_table(
            ['Subject - Code' => 0, 'Calification' => 0, 'Status' => 0],
            array_map(static fn($s) => [$s[1] . ' - ' . $s[0], $s[2], 'Approved'], $subjects)
        )),
        'subjects_enrolled' => $html(squuad_cert_document_preview_plain_table(['Code', 'Subject'], array_map(static fn($s) => [$s[0], $s[1]], $subjects))),
        'subjects_enrolled_spanish' => $html(squuad_cert_document_preview_plain_table(['Código', 'Materia'], array_map(static fn($s) => [$s[0], $s[1]], $subjects))),
        'payment_method_table' => $html(squuad_cert_document_preview_plain_table(['Payment method', 'Amount'], [['Credit card', '$1,200.00'], ['Bank transfer', '$800.00']])),
        'payment_plan_table' => $html(squuad_cert_document_preview_plain_table(['Concept', 'Date', 'Amount'], [['Registration fee', '08/01/2025', '$150.00'], ['Installment 1', '09/01/2025', '$500.00'], ['Installment 2', '10/01/2025', '$500.00']])),
        'educational_background_information' => $html(squuad_cert_document_preview_plain_table(['Institution', 'City / Country', 'Title', 'Year'], [['Example High School', 'Caracas / Venezuela', 'High School Diploma', '2024']])),
        'admission_requirements_table' => $html('<ul style="list-style-type: none; padding-left: 0; margin-top: 0"><li style="margin-bottom: 5px">✓ ' . esc_html__('Example requirement 1', 'wp-certificates') . '</li><li style="margin-bottom: 5px">✓ ' . esc_html__('Example requirement 2', 'wp-certificates') . '</li></ul>'),
    ];

    // Sin EduSystem sus variables no tienen valor: solo las generales y las de la persona ({{full_name}}…); los campos
    // adicionales y las firmas se añaden después
    if (function_exists('wpc_edusystem_active') && !wpc_edusystem_active()) {
        $keep = array_merge(\Squuad\Certificados\Variables::general_keys(), array_keys(squuad_cert_person_variables()));
        $replacements = array_intersect_key($replacements, array_flip($keep));
    }

    // Campos adicionales del documento: la primera opción marcada o un texto de ejemplo
    if (function_exists('squuad_cert_get_document_fields') && function_exists('squuad_cert_document_fields_replacements')) {
        $fields = squuad_cert_get_document_fields($document);
        $values = [];
        foreach ($fields as $field) {
            $values[$field['key']] = squuad_cert_document_field_has_options($field['type'])
                ? ('checkbox' === $field['type'] ? array_slice($field['options'], 0, 1) : ($field['options'][0] ?? ''))
                /* translators: %s: label of the additional field */
                : sprintf(__('%s (example)', 'wp-certificates'), $field['label']);
        }
        $replacements = array_merge(squuad_cert_document_fields_replacements($fields, $values), $replacements);
        // Un campo ya existente llamado como una variable nueva del sistema ({{full_name}}) gana, como al rellenarlo
        $field_values = squuad_cert_document_fields_replacements($fields, $values);
        foreach (squuad_cert_document_fields_shadowed_keys($document) as $shadowed) {
            if (isset($field_values[$shadowed])) {
                $replacements[$shadowed] = $field_values[$shadowed];
            }
        }
    }

    $replacements['signature_section'] = $html(SQUUAD_CERT_SIGNATURE_SLOT);
    $replacements['admission_signature_fgu'] = $html(SQUUAD_CERT_SIGNATURE_SLOT);
    // F1 equivale a las variables sin sufijo (ADR 0010 de Edusof): {{full_name_F1}} = {{full_name}}…, también al generar
    if (function_exists('squuad_cert_fn_f1_aliases')) {
        foreach (squuad_cert_fn_f1_aliases() as $alias => $key) {
            if (isset($replacements[$key])) {
                $replacements[$alias] = $replacements[$key];
            }
        }
    }

    // "Generar" no tiene variables por firmante (quedan como texto, igual que en el documento real); la firma-imagen
    // heredada solo existe si el documento la pide y el sitio aún la permite
    if ('generate' === $mode) {
        if (!empty($document->signature_required)) {
            $replacements['user_sign'] = $text(__('Signer (example)', 'wp-certificates'));
            $replacements['position_user_charge'] = $text(__('Position (example)', 'wp-certificates'));
            $replacements['signature'] = $html(squuad_cert_document_preview_signature_box(__('Signer (example)', 'wp-certificates')));
        }
        $replacements['qrcode'] = $html('<div id="qrcode"></div>');

        return $replacements;
    }

    // Firmas: los roles del panel y los firmantes del sistema, cada uno con su recuadro de ejemplo
    $signers = squuad_cert_document_preview_signers($document);
    $slots = array_column($signers, 'slot_key');
    foreach (squuad_cert_signing_roles() as $role) {
        $replacements[squuad_cert_signing_role_variable($role)] = $html(in_array('role:' . $role, $slots, true) ? squuad_cert_signer_slot_marker('role:' . $role) : '');
    }
    $replacements['signature_student'] = $html(in_array('role:student', $slots, true) ? squuad_cert_signer_slot_marker('role:student') : '');
    $replacements['signature_parent'] = $html('');
    $has_role = (bool) array_filter($slots, 'squuad_cert_is_holder_slot');
    $replacements['requires_student_signature'] = $html($has_role ? '1' : '');
    $replacements['requires_parent_signature'] = $html('');
    $replacements['student_is_own_parent'] = $html('');
    $n = 0;
    foreach ($signers as $signer) {
        if (!empty($signer['variable'])) {
            // Firmante por variable: sus propias variables, no cuenta como firmante del sistema N
            $replacements['signature_var_' . $signer['variable']] = $html(squuad_cert_signer_slot_marker($signer['slot_key']));
            $replacements['signer_name_var_' . $signer['variable']] = $text($signer['name']);
            $replacements['signer_charge_var_' . $signer['variable']] = $text($signer['charge']);
            continue;
        }
        if ($signer['phase'] < 2) {
            continue;
        }
        $n++;
        $values = [
            'signature' => $html(squuad_cert_signer_slot_marker($signer['slot_key'])),
            'user_sign' => $text($signer['name']),
            'position_user_charge' => $text($signer['charge']),
        ];
        foreach ($values as $key => $value) {
            $replacements[$key . '_' . $n] = $value;
            if (1 === $n) {
                $replacements[$key] = $value;
            }
        }
        $replacements['signature_signer_' . $signer['signer_id']] = $values['signature'];
        $replacements['signer_name_' . $signer['signer_id']] = $values['user_sign'];
        $replacements['signer_charge_' . $signer['signer_id']] = $values['position_user_charge'];
    }

    // Variables por firmante numeradas (ADR 0010 de Edusof), con datos de ejemplo de cada firmante del panel
    if (function_exists('squuad_cert_fn_template_uses')) {
        $template = squuad_cert_fn_document_template($document);
        if (squuad_cert_fn_template_uses($template)) {
            $map = squuad_cert_fn_map($document);
            $by_slot = array_column($signers, null, 'slot_key');
            $holder = (string) (array_values(array_filter($slots, 'squuad_cert_is_holder_slot'))[0] ?? '');
            foreach (squuad_cert_fn_template_numbers($template) as $fn) {
                if (1 === $fn) {
                    $replacements['F1'] = $html('1');
                    $replacements['charge_F1'] = $text('' !== $holder ? squuad_cert_holder_slot_label($holder) : '');
                    $replacements['signature_F1'] = $html('' !== $holder ? squuad_cert_signer_slot_marker($holder) : '');
                    continue;
                }
                $signer = $by_slot[$map[$fn] ?? ''] ?? null;
                $replacements['F' . $fn] = $html($signer ? '1' : '');
                /* translators: %d: number of the signer, e.g. 2 for F2 */
                $first = sprintf(__('Signer %d', 'wp-certificates'), $fn);
                $last = __('Example', 'wp-certificates');
                $replacements['full_name_F' . $fn] = $signer ? $text($last . ', ' . $first) : $html('');
                $replacements['name_F' . $fn] = $signer ? $text($first) : $html('');
                $replacements['last_name_F' . $fn] = $signer ? $text($last) : $html('');
                $replacements['email_F' . $fn] = $signer ? $text('signer' . $fn . '@example.com') : $html('');
                $replacements['id_document_F' . $fn] = $html($signer ? 'V1000000' . $fn : '');
                $replacements['charge_F' . $fn] = $signer ? $text((string) $signer['charge']) : $html('');
                $replacements['signature_F' . $fn] = $html($signer ? squuad_cert_signer_slot_marker($signer['slot_key']) : '');
            }
        }
    }

    // QR de verificación (aquí con una dirección de ejemplo): en el emitido va en el contenido; en el automático, en la
    // ranura que se rellena al generar el PDF final (ADR 0014)
    $replacements['qrcode'] = $html('<div data-edusig-qr="https://example.com/verify/EXAMPLE"></div>');

    return $replacements;
}

/**
 * Datos de la vista previa: modo, HTML con los valores de ejemplo y las firmas dibujadas, opciones de la página y
 * variables que la plantilla usa pero no existen.
 */
function squuad_cert_document_preview_data(object $document): array
{
    $mode = squuad_cert_document_preview_mode($document);
    $replacements = squuad_cert_document_preview_replacements($document, $mode);
    // Variables generales del propio documento (wp-certificates): {{document_name}} y {{document_code}}
    if (function_exists('squuad_cert_document_replacements')) {
        $replacements = array_merge($replacements, squuad_cert_document_replacements($document));
    }
    $parts = ['header' => (string) $document->header, 'content' => (string) $document->content, 'footer' => (string) $document->footer];
    $signers = squuad_cert_document_preview_signers($document);

    // Variables escritas en la plantilla que no existen: en el documento real quedan como texto
    preg_match_all('/\{\{[#^\/]?(\w+)\}\}/', implode('', $parts), $found);
    $unknown = array_values(array_unique(array_diff($found[1], array_keys($replacements))));
    // Las de un firmante por variable que no está en el panel no son desconocidas: quedan vacías (ADR 0009 de Edusof)
    $unknown = array_values(array_filter($unknown, static fn(string $key): bool => !preg_match('/^(?:signature_var|signer_name_var|signer_charge_var)_/', $key)));
    // Ni las numeradas por firmante en un documento que se firma (ADR 0010 de Edusof): sin firmante quedan vacías
    if ('generate' !== $mode) {
        $unknown = array_values(array_filter($unknown, static fn(string $key): bool => !preg_match('/^(?:(?:full_name|name|last_name|email|id_document|charge|signature)_)?F[1-9][0-9]?$/', $key)));
    }

    if ('generate' === $mode) {
        foreach ($parts as $key => $part) {
            $parts[$key] = squuad_cert_process_template($part, $replacements);
        }
        $html = null;
    } else {
        // Como la solicitud de firma: cabecera, cuerpo y pie en un solo contenido
        $wrap = 'automatic' === $mode ? 'automatic-document' : 'edusystem-doc';
        $html = '';
        foreach ($parts as $key => $part) {
            if ('' !== trim($part)) {
                $html .= '<div class="' . $wrap . '-' . $key . '">' . squuad_cert_process_template($part, $replacements) . '</div>';
            }
        }
        $html = squuad_cert_signature_strip_unused_signer_tags($html);
        // Firmantes del sistema que la plantilla no coloca: bloque al final, como en el documento real
        $missing = '';
        foreach ($signers as $signer) {
            if ($signer['phase'] >= 2 && false === strpos($html, squuad_cert_signer_slot_marker($signer['slot_key']))) {
                $missing .= '<div style="min-width:260px;text-align:center">' . squuad_cert_signer_slot_marker($signer['slot_key'])
                    . '<div style="border-top:1px solid #333;margin-top:4px;padding-top:4px"><strong>' . esc_html($signer['name']) . '</strong><br>'
                    . esc_html($signer['charge']) . '</div></div>';
            }
        }
        if ('' !== $missing) {
            $html .= '<div class="edusystem-institutional-signatures" style="margin-top:24px;display:flex;flex-wrap:wrap;gap:24px">' . $missing . '</div>';
        }
    }

    // Borde del color de cada firmante (F1 azul, F2 verde…; ADR 0010 de Edusof) en los recuadros: solo en esta vista
    // previa, para reconocer a cada uno; el documento real nunca lleva colores
    $fn_numbers = function_exists('squuad_cert_fn_numbering') ? array_flip(squuad_cert_fn_numbering(squuad_cert_signing_policy($document))) : [];
    $outline = static function (string $slot_key, string $box) use ($fn_numbers): string {
        $fn = squuad_cert_is_holder_slot($slot_key) ? 1 : (int) ($fn_numbers[$slot_key] ?? 0);
        if (!$fn || !defined('SQUUAD_CERT_FN_PREVIEW_COLORS')) {
            return $box;
        }
        $color = SQUUAD_CERT_FN_PREVIEW_COLORS[squuad_cert_fn_color($fn)];

        // Borde (no outline: el PDF de la vista previa no dibuja outline)
        return '<div class="wpc-preview-fn" style="border:2px solid ' . esc_attr($color) . ';padding:4px;border-radius:4px;position:relative">'
            . '<span style="position:absolute;top:-9px;right:-6px;background:' . esc_attr($color) . ';color:#fff;font:700 9px/1.4 sans-serif;padding:0 4px;border-radius:6px">F' . $fn . '</span>'
            . $box . '</div>';
    };

    // Recuadros de firma de ejemplo en lugar de los marcadores: uno por rol del panel (al generar sin panel, el del rol student)
    $labels = [];
    foreach ('generate' === $mode ? ['role:student'] : array_filter(array_column($signers, 'slot_key'), 'squuad_cert_is_holder_slot') as $slot_key) {
        $labels[$slot_key] = ['Juan Carlos Pérez Gómez', squuad_cert_holder_slot_label($slot_key)];
    }
    $section = '';
    $render = static function (string $text) use (&$section, $signers, $labels, $outline): string {
        foreach ($signers as $signer) {
            $marker = squuad_cert_signer_slot_marker($signer['slot_key']);
            if ($signer['phase'] >= 2) {
                $text = str_replace($marker, $outline($signer['slot_key'], squuad_cert_document_preview_signature_box($signer['name'])), $text);
            }
        }
        foreach ($labels as $role => [$name, $label]) {
            $text = str_replace(squuad_cert_signer_slot_marker($role), $outline($role, squuad_cert_document_preview_signature_block($name, $label)), $text);
        }
        return $text;
    };
    $users = '';
    $all = implode('', array_filter([$html, ...array_values($parts)]));
    foreach ($labels as $role => [$name, $label]) {
        $in_policy = 'generate' === $mode || in_array($role, array_column($signers, 'slot_key'), true);
        if ($in_policy && false === strpos($all, squuad_cert_signer_slot_marker($role))) {
            $users .= $outline($role, squuad_cert_document_preview_signature_block($name, $label));
        }
    }
    $users_block = '<div style="display:flex;flex-wrap:wrap;gap:24px;margin-top:16px">' . $users . '</div>';

    if (null !== $html) {
        $html = str_replace([SQUUAD_CERT_SIGNATURE_SLOT, SQUUAD_CERT_SIGNATURE_QR_SLOT], [$users_block, ''], $render($html));
        if (function_exists('squuad_cert_signature_inline_images')) {
            $html = squuad_cert_signature_inline_images($html);
        }
    } else {
        foreach ($parts as $key => $part) {
            $parts[$key] = str_replace(SQUUAD_CERT_SIGNATURE_SLOT, $users_block, $render($part));
        }
    }

    $options = [
        'orientation' => strtolower((string) ($document->orientation ?: 'portrait')),
        'unit' => strtolower((string) ($document->unit ?: 'mm')),
        'paper_format' => strtolower((string) ($document->paper_format ?: 'a4')),
        'width_size' => (float) $document->width_size,
        'height_size' => (float) $document->height_size,
    ];
    $page = 'automatic' === $mode
        ? squuad_cert_signature_pdf_page_from_options(['orientation' => 'portrait', 'unit' => 'mm', 'paper_format' => 'a4', 'width_size' => 0, 'height_size' => 0], 7.62)
        : squuad_cert_signature_pdf_page_from_options($options, 0);

    return [
        'mode' => $mode,
        'html' => $html,
        'header' => 'generate' === $mode ? $parts['header'] : '',
        'content' => 'generate' === $mode ? $parts['content'] : '',
        'footer' => 'generate' === $mode ? $parts['footer'] : '',
        'page' => $page,
        // "Generar" usa el formato del documento tal cual (document.js), con medidas personalizadas si las tiene
        'generate' => [
            'unit' => $options['unit'],
            'orientation' => $options['orientation'],
            'paper_format' => 'custom' === $options['paper_format'] ? [$options['width_size'], $options['height_size']] : $options['paper_format'],
            'width' => $options['width_size'] . $options['unit'],
            'height' => $options['height_size'] . $options['unit'],
            'margin_required' => (int) $document->margin_required,
        ],
        'unknown' => $unknown,
        'signature_blocked' => 'generate' === $mode && !empty($document->signature_required)
            && function_exists('squuad_cert_third_party_signatures_blocked') && squuad_cert_third_party_signatures_blocked(),
    ];
}
