<?php
declare(strict_types=1);

/**
 * Proveedor del titular «Usuario de WordPress» (subject_type 'wp_user'), ADR 0004 de EduSystem, decisión 6 y paso 10.
 *
 * wp-certificates lo trae siempre para funcionar sin EduSystem: el titular es una cuenta del sitio (users.ID) y aporta
 * las variables de la persona (grupo B de Antigravity/variable.md, squuad_cert_person_variables()) desde esa cuenta:
 * {{full_name}}, {{name}}, {{last_name}} y {{email}}. {{student_name}} es solo de EduSystem.
 *
 * No interfiere con EduSystem:
 * - No registra métodos de variables (ADR 0005): así el vínculo por clave con edusystem.* sigue siendo único.
 * - Sus valores solo se usan cuando quien rellena la plantilla dice que el titular es una cuenta (ctx holder_type y
 *   holder_id, ver squuad_cert_template_replacements()) y, además, ningún plugin activo da valor a esa variable. Con
 *   EduSystem activo esas cuatro variables las da EduSystem, como siempre.
 */

defined('ABSPATH') || exit;

// Una sola constante para el tipo: la usa también el módulo de firmas (certification/includes/signature-requests.php),
// que se carga después (plugins_loaded)
defined('SQUUAD_CERT_SUBJECT_ACCOUNT') || define('SQUUAD_CERT_SUBJECT_ACCOUNT', 'wp_user');

/**
 * ¿Actúa el titular «Usuario de WordPress»? Solo sin EduSystem: con EduSystem las variables del titular y los
 * documentos de las personas son de EduSystem, aunque se desactive como plugin que aporta métodos.
 */
function squuad_cert_wp_user_holder_enabled(): bool
{
    return !(function_exists('wpc_edusystem_active') && wpc_edusystem_active());
}

add_action('squuad_cert_register_providers', 'squuad_cert_wp_user_register_provider');
function squuad_cert_wp_user_register_provider(): void
{
    squuad_cert_register_subject_type(SQUUAD_CERT_SUBJECT_ACCOUNT, [
        'label' => 'squuad_cert_wp_user_label',
        'holder_slots' => 'squuad_cert_wp_user_holder_slots',
        'subjects_for_user' => 'squuad_cert_wp_user_subjects_for_user',
        'replacements' => 'squuad_cert_wp_user_replacements',
        'variables_catalog' => 'squuad_cert_wp_user_variables_catalog',
        'book_line_data' => 'squuad_cert_wp_user_book_line_data',
    ]);
}

/**
 * Partes del nombre de una cuenta: nombres (first_name o, si está vacío, el nombre visible), apellidos (last_name) y
 * correo. null si la cuenta no existe.
 *
 * @return array{first: string, last: string, display: string, email: string}|null
 */
function squuad_cert_wp_user_name_parts(int $user_id): ?array
{
    $user = $user_id > 0 ? get_userdata($user_id) : false;
    if (!$user) {
        return null;
    }
    $display = trim((string) $user->display_name);
    $first = trim((string) $user->first_name);
    $last = trim((string) $user->last_name);

    return [
        'first' => '' !== $first ? $first : ('' === $last ? $display : ''),
        'last' => $last,
        'display' => $display,
        'email' => (string) $user->user_email,
    ];
}

/** label($id): «Apellidos, Nombres», como el titular de EduSystem; el nombre visible si la cuenta no tiene nombre. */
function squuad_cert_wp_user_label(int $user_id): string
{
    $parts = squuad_cert_wp_user_name_parts($user_id);
    if (!$parts) {
        return '';
    }
    $full = trim($parts['last'] . ($parts['last'] && $parts['first'] ? ', ' : '') . $parts['first']);

    return '' !== $full ? $full : $parts['display'];
}

/** Nombre en orden natural («Nombres Apellidos»), para la lista de certificados y el QR. */
function squuad_cert_wp_user_full_name(int $user_id): string
{
    $parts = squuad_cert_wp_user_name_parts($user_id);
    if (!$parts) {
        return '';
    }
    $full = trim($parts['first'] . ' ' . $parts['last']);

    return '' !== $full ? $full : $parts['display'];
}

/** holder_slots($id, $document): un único puesto, el de la propia cuenta (cada uno firma por sí mismo). */
function squuad_cert_wp_user_holder_slots(int $user_id, $document = null): array
{
    $parts = squuad_cert_wp_user_name_parts($user_id);
    if (!$parts) {
        return [];
    }

    return [
        [
            'slot_key' => 'self',
            'user_id' => $user_id,
            'name' => squuad_cert_wp_user_full_name($user_id),
            'required' => true,
        ],
    ];
}

/** subjects_for_user($user_id): la propia cuenta. */
function squuad_cert_wp_user_subjects_for_user(int $user_id): array
{
    return $user_id > 0 && get_userdata($user_id) ? [$user_id] : [];
}

/**
 * variables_catalog(): variables de la persona que aporta la cuenta (las neutras de wp-certificates, que EduSystem también
 * rellena), para que una plantilla sirva con y sin EduSystem. Clave => descripción.
 *
 * @return array<string, string>
 */
function squuad_cert_wp_user_variables_catalog(): array
{
    return squuad_cert_person_variables();
}

/**
 * replacements($id, $document, $ctx): valores de las variables del titular, con el formato de process_template().
 * Escapados como las demás variables de texto (el texto no puede abrir ni cerrar otra variable) y en mayúsculas
 * ('wrap'), como los de EduSystem, para que el documento salga igual con uno u otro titular.
 */
function squuad_cert_wp_user_replacements(int $user_id, $document = null, array $ctx = []): array
{
    $parts = squuad_cert_wp_user_name_parts($user_id);
    if (!$parts) {
        return [];
    }
    $text = static fn(string $value): string => str_replace(['{', '}'], ['&#123;', '&#125;'], esc_html($value));

    return [
        'full_name' => ['value' => $text(squuad_cert_wp_user_label($user_id)), 'wrap' => true],
        'name' => ['value' => $text($parts['first']), 'wrap' => true],
        'last_name' => ['value' => $text($parts['last']), 'wrap' => true],
        'email' => ['value' => $text($parts['email']), 'wrap' => true],
    ];
}

/** book_line_data($id, $document): datos del renglón del libro (sin programa: la cuenta no tiene). */
function squuad_cert_wp_user_book_line_data(int $user_id, $document = null): array
{
    $parts = squuad_cert_wp_user_name_parts($user_id);
    if (!$parts) {
        return [];
    }

    return [
        'name' => squuad_cert_wp_user_label($user_id),
        'program' => '',
    ];
}

/**
 * Emite un documento (sin firma) a una cuenta de WordPress: rellena la plantilla con las variables generales y las del
 * titular, reserva tomo y folio si el documento tiene libro y guarda el certificado con su código de validación (y el
 * QR, si la plantilla lo usa). Mismo camino que assign_certificate_student() para los estudiantes de EduSystem.
 *
 * Solo sin EduSystem (con EduSystem se emite a sus estudiantes). Devuelve el id del certificado o WP_Error; si ya se
 * emitió ese documento a ese correo, $already queda en true y se devuelve el id existente.
 *
 * @return int|WP_Error
 */
function squuad_cert_issue_document_to_user(int $user_id, int $document_id, string $emission_date, string $type = 'download_certificate', ?bool &$already = null)
{
    global $wpdb;
    $table = $wpdb->prefix . 'certificates';
    $already = false;

    if (!squuad_cert_wp_user_holder_enabled()) {
        return new WP_Error('squuad_cert_edusystem_active', __('With EduSystem, documents are issued to its students.', 'wp-certificates'));
    }
    $user = $user_id > 0 ? get_userdata($user_id) : false;
    if (!$user) {
        return new WP_Error('squuad_cert_no_user', __('The user does not exist.', 'wp-certificates'));
    }
    if (!is_email((string) $user->user_email)) {
        return new WP_Error('squuad_cert_no_email', __('The user has no email: the document cannot be identified or validated.', 'wp-certificates'));
    }
    $document = $document_id > 0 && function_exists('get_document_detail') ? get_document_detail($document_id) : null;
    if (!$document) {
        return new WP_Error('squuad_cert_no_document', __('The document does not exist.', 'wp-certificates'));
    }
    // Nadie firma por otro (ADR 0003 de EduSystem): un documento que exige firma no se emite aquí
    if (!empty($document->signature_required)) {
        return new WP_Error('squuad_cert_signature_required', __('This document requires signatures: it cannot be issued here.', 'wp-certificates'));
    }

    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM {$table} WHERE type = %s AND name_document = %s AND email = %s",
        $type,
        (string) $document->title,
        (string) $user->user_email
    ));
    if ($existing) {
        $already = true;
        return (int) $existing->id;
    }

    $header = (string) $document->header;
    $content = (string) $document->content;
    $footer = (string) $document->footer;
    // Los métodos de otros plugins no reciben la cuenta como titular (sin EduSystem no hay ficha): 0. La línea del libro
    // usa el mismo titular que la plantilla (methods_subject_id, ver squuad_cert_book_line_text())
    $methods_subject = 0;
    $ctx = ['document' => $document, 'holder_type' => SQUUAD_CERT_SUBJECT_ACCOUNT, 'holder_id' => $user_id, 'methods_subject_id' => $methods_subject];

    $resolved = squuad_cert_template_replacements($header . $content . $footer, $methods_subject, $ctx);
    if ($resolved['failed'] && !empty($document->book)) {
        squuad_cert_log(sprintf('Documento %d no emitido al usuario %d: variables sin valor %s', (int) $document->id, $user_id, implode(', ', array_keys($resolved['failed']))), 'certificate_not_issued');
        return new WP_Error('squuad_cert_variables', __('Some variables of the document have no value: it was not issued.', 'wp-certificates'));
    }
    $replacements = $resolved['replacements'];

    $book_data = [];
    if (!empty($document->book)) {
        $book_data = squuad_cert_book_reserve_line($document, SQUUAD_CERT_SUBJECT_ACCOUNT, $user_id, $ctx);
        if (is_wp_error($book_data)) {
            return $book_data;
        }
        $replacements['folio'] = ['value' => $book_data['folio'] ?? '', 'wrap' => true];
        $replacements['tomo'] = ['value' => $book_data['tomo'] ?? '', 'wrap' => true];
        $replacements['tomo_folio'] = [
            'value' => __('Tome: ', 'wp-certificates') . ($book_data['tomo'] ?? '') . ' ' . __('Folio: ', 'wp-certificates') . ($book_data['folio'] ?? ''),
            'wrap' => true,
        ];
    }

    // Campos adicionales: aquí no hay respuestas; así no queda el texto literal {{clave}}
    if (function_exists('squuad_cert_document_fields_empty_replacements')) {
        $replacements = array_merge(squuad_cert_document_fields_empty_replacements($document), $replacements);
    }

    $processed_header = squuad_cert_process_template($header, $replacements);
    $processed_content = squuad_cert_process_template($content, $replacements);
    $processed_footer = squuad_cert_process_template($footer, $replacements);

    $parts = squuad_cert_wp_user_name_parts($user_id);
    $qr = ['url' => '', 'image_url' => ''];
    if (false !== strpos($header . $content . $footer, '{{qrcode}}')) {
        // Mismo registro de validación que los estudiantes (filtro create_certificate_edusystem de wp-certificates)
        $holder = (object) ['name' => $parts['first'], 'last_name' => $parts['last'], 'email' => $parts['email']];
        $qr = apply_filters('create_certificate_edusystem', 'certificate', (string) $document->title, '', 1, $holder, $emission_date);
    }

    $html = trim(
        ($processed_header ? '<div id="header-document">' . $processed_header . '</div>' . "\n" : '') .
        ($processed_content ? '<div id="content-pdf">' . $processed_content . '</div>' . "\n" : '') .
        ($processed_footer ? '<div id="footer-document">' . $processed_footer . '</div>' : '')
    );
    $unit = (string) $document->unit;
    $format = is_array($document->paper_format) ? 'custom' : (string) $document->paper_format;

    $inserted = $wpdb->insert($table, [
        'type' => $type,
        'name_document' => (string) $document->title,
        'program_document' => '',
        'template_id' => (int) $document->id,
        'user' => squuad_cert_wp_user_full_name($user_id),
        'email' => (string) $user->user_email,
        'emission_date' => $emission_date,
        'expiration_date' => null,
        'participant_id' => null,
        'course_id' => null,
        'html' => $html,
        'tomo' => $book_data['tomo'] ?? null,
        'folio' => $book_data['folio'] ?? null,
        'option_document' => wp_json_encode([
            'orientation' => strtolower((string) $document->orientation),
            'unit' => strtolower($unit),
            'paper_format' => strtolower($format),
            'width_size' => $document->width_size,
            'height_size' => $document->height_size,
            'width_style' => $document->width_size . $unit,
            'height_style' => $document->height_size . $unit,
            'qr' => $qr,
        ]),
    ]);
    if (!$inserted) {
        return new WP_Error('squuad_cert_insert', __('The certificate could not be saved.', 'wp-certificates'));
    }
    $certificate_id = (int) $wpdb->insert_id;
    $wpdb->update($table, ['simple_uuid' => squuad_cert_new_certificate_code()], ['id' => $certificate_id], ['%s'], ['%d']);
    squuad_cert_log(sprintf('Documento %d (%s) emitido al usuario de WordPress %d por el usuario %d: certificado %d', (int) $document->id, (string) $document->document_identificator, $user_id, get_current_user_id(), $certificate_id), 'certificate_issued_wp_user');

    return $certificate_id;
}
