<?php

// Libros del selector: los da quien tenga la conexión con el servidor de libros (filtro squuad_cert_books, ver
// includes/book.php); wp-certificates no llama a funciones de EduSystem.
function get_certificates_books_list(array $args = []): array
{
    return squuad_cert_books();
}

/* ---------------------------------------------------------------------------------------------------------------
 * Diseño Edusof (rediseño 2.0.0, contrato Antigravity/docs/edusof-ui-contrato.md): la lista y el editor de documentos
 * se dibujan con el marcado nuevo solo si la biblioteca edusof-ui existe y está activa; si no, como siempre.
 * ------------------------------------------------------------------------------------------------------------ */

/** ¿Se usa el diseño Edusof en las pantallas de documentos? */
function wpc_eds_documents_enabled(): bool
{
    return class_exists('Edusof_UI') && Edusof_UI::enabled();
}

/** CSS y JS del diseño nuevo, solo en Documentos y solo con el diseño activo; en el editor, el editor de código del core. */
add_action('admin_enqueue_scripts', 'wpc_eds_documents_assets', 20);
function wpc_eds_documents_assets(): void
{
    if (($_GET['page'] ?? '') !== 'add_admin_form_documents_content' || !wpc_eds_documents_enabled()) {
        return;
    }
    $version = '1.1.0'; // subir al cambiar eds-documentos.css o eds-documentos.js
    wp_enqueue_style('wpc-eds-documentos', plugins_url('assets/css/eds-documentos.css', __FILE__), [], $version);
    wp_enqueue_script('wpc-eds-documentos', plugins_url('assets/js/eds-documentos.js', __FILE__), [], $version, true);

    $code_settings = false;
    if (($_GET['section_tab'] ?? '') === 'document_detail' && absint($_GET['document_id'] ?? 0)) {
        // CodeMirror del core: sin cierres automáticos de etiquetas ni de comillas (el HTML se guarda tal cual se escribe)
        $code_settings = wp_enqueue_code_editor([
            'type' => 'text/html',
            'codemirror' => [
                'lineWrapping' => true,
                'lint' => false,
                'autoCloseTags' => false,
                'autoCloseBrackets' => false,
            ],
        ]);
    }
    wp_add_inline_script('wpc-eds-documentos', 'window.wpcEdsDocuments = ' . wp_json_encode([
        'code' => $code_settings ?: null,
        'text' => [
            'cancel' => __('Cancel', 'wp-certificates'),
            'close' => __('Close', 'wp-certificates'),
            'delete' => __('Delete', 'wp-certificates'),
            /* translators: %s: name of the document */
            'deleteTitle' => __('Delete «%s»', 'wp-certificates'),
            'deleteBody' => __('The document will be deleted permanently. This cannot be undone.', 'wp-certificates'),
            /* translators: %s: name of the document */
            'deleteBlockedTitle' => __('«%s» cannot be deleted', 'wp-certificates'),
            /* translators: 1: number of issued certificates, 2: number of signature requests */
            'deleteBlockedBody' => __('It has %1$s issued certificates and %2$s signature requests. If it were deleted, they would be left without their template. Deactivate it instead so that it is no longer used.', 'wp-certificates'),
            'automaticTitle' => __('Save as a document signed by the person?', 'wp-certificates'),
            // Requisitos de los estudiantes: solo con EduSystem (sin él nunca hay requisitos que añadir ni se muestra)
            /* translators: %s: number of students */
            'automaticBody' => wpc_edusystem_active() ? __('When you save, this document is added as a requirement to %s students who do not have it yet. They will see it in My Account.', 'wp-certificates') : '',
            /* translators: %s: number of students */
            'automaticBodyUpTo' => wpc_edusystem_active() ? __('When you save, this document is added as a requirement to up to %s students who do not have it yet (the code changed). They will see it in My Account.', 'wp-certificates') : '',
            'automaticConfirm' => __('Save and add the requirement', 'wp-certificates'),
            'unsavedTitle' => __('There are unsaved changes', 'wp-certificates'),
            'unsavedSigners' => __('You changed the signers and did not save them. If you save the document now, those changes are lost.', 'wp-certificates'),
            'unsavedDocument' => __('You changed the document and did not save it. If you save the signers now, those changes are lost.', 'wp-certificates'),
            'unsavedConfirm' => __('Save anyway', 'wp-certificates'),
            /* translators: %s: variable, e.g. {{student_name}} */
            'inserted' => __('%s inserted', 'wp-certificates'),
            /* translators: %s: number of results */
            'results' => __('%s results', 'wp-certificates'),
        ],
    ]) . ';', 'before');
}

/**
 * Lo que impide borrar documentos: certificados emitidos (tabla certificates, por nombre del documento, o por id en las
 * descargas) y solicitudes de firma (squuad_cert_requests, por id o por código). Devuelve [id => ['certificates', 'requests']].
 */
function wpc_documents_usage(array $documents): array
{
    global $wpdb;

    $usage = [];
    foreach ($documents as $document) {
        $usage[(int) $document->id] = ['certificates' => 0, 'requests' => 0];
    }
    if (!$usage) {
        return [];
    }
    $normalize = static fn($text): string => function_exists('mb_strtolower') ? mb_strtolower(trim((string) $text)) : strtolower(trim((string) $text));

    $certificates = $wpdb->prefix . 'certificates';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $certificates)) === $certificates) {
        $issued = (array) $wpdb->get_results("SELECT name_document, `type`, template_id, COUNT(*) AS n FROM {$certificates} GROUP BY name_document, `type`, template_id");
        foreach ($documents as $document) {
            $title = $normalize($document->title);
            foreach ($issued as $row) {
                if (('' !== $title && $normalize($row->name_document) === $title)
                    || ('download_certificate' === $row->type && (int) $row->template_id === (int) $document->id)) {
                    $usage[(int) $document->id]['certificates'] += (int) $row->n;
                }
            }
        }
    }

    $requests = $wpdb->prefix . 'squuad_cert_requests';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $requests)) === $requests) {
        $rows = (array) $wpdb->get_results("SELECT document_certificate_id, document_id, COUNT(*) AS n FROM {$requests} GROUP BY document_certificate_id, document_id");
        foreach ($documents as $document) {
            $code = (string) $document->document_identificator;
            foreach ($rows as $row) {
                if ((int) $row->document_certificate_id === (int) $document->id || ('' !== $code && (string) $row->document_id === $code)) {
                    $usage[(int) $document->id]['requests'] += (int) $row->n;
                }
            }
        }
    }

    return $usage;
}

/**
 * Resumen de firmas de un documento para la lista y el editor: si las pide (la política de firmantes o, en los
 * gestionados, el «requerirá firmas» de siempre), quién firma y si faltan firmantes.
 * Devuelve ['asks' => bool, 'signers' => int, 'missing' => bool, 'label' => string].
 */
function wpc_document_signing_summary(object $document): array
{
    $legacy = !empty($document->signature_required);
    if (!function_exists('squuad_cert_signing_policy') || !function_exists('squuad_cert_signers_enabled') || !squuad_cert_signers_enabled()) {
        return [
            'asks' => $legacy,
            'signers' => 0,
            'missing' => false,
            'label' => $legacy ? __('Asks for signatures', 'wp-certificates') : __('Does not ask for signatures', 'wp-certificates'),
        ];
    }
    $policy = squuad_cert_signing_policy($document);
    $asks = !empty($policy['requires_signatures']) || ('automatic' !== $document->type && $legacy);
    $slots = !empty($policy['requires_signatures']) ? (array) $policy['slots'] : [];
    if (!$asks) {
        return ['asks' => false, 'signers' => 0, 'missing' => false, 'label' => __('Does not ask for signatures', 'wp-certificates')];
    }
    if (!$slots) {
        return ['asks' => true, 'signers' => 0, 'missing' => true, 'label' => __('Asks for signatures, but has no signers', 'wp-certificates')];
    }
    $roles = function_exists('squuad_cert_site_roles') ? squuad_cert_site_roles() : [];
    $parts = [];
    $signers = 0;
    foreach ($slots as $slot) {
        if ('role' === $slot['slot_type']) {
            $parts[] = (string) ($roles[$slot['role']] ?? $slot['role']);
        } else {
            $signers++;
        }
    }
    if ($signers) {
        /* translators: %d: number of system signers */
        $parts[] = sprintf(_n('%d signer', '%d signers', $signers, 'wp-certificates'), $signers);
    }

    return ['asks' => true, 'signers' => count($slots), 'missing' => false, 'label' => implode(' + ', $parts)];
}

/** Estudiantes a los que se añadiría el documento como requisito al guardarlo como automático (sin fila para ese código). */
function wpc_document_automatic_pending_count(string $document_identificator): int
{
    global $wpdb;

    if (!wpc_edusystem_active() || '' === $document_identificator) {
        return 0;
    }

    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}students s
         WHERE NOT EXISTS (SELECT 1 FROM {$wpdb->prefix}student_documents d WHERE d.student_id = s.id AND d.document_id = %s)",
        $document_identificator
    ));
}

/**
 * El navegador envía los saltos de línea de un campo de texto como CRLF. Con el editor de código, si el texto no cambió
 * (salvo saltos de línea) se conserva el guardado, byte a byte; si cambió, se usan los saltos que ya tenía el documento.
 */
function wpc_eds_keep_line_endings(string $posted, string $stored): string
{
    $normalize = static fn(string $text): string => str_replace(["\r\n", "\r"], "\n", $text);
    if ($normalize($posted) === $normalize($stored)) {
        return $stored;
    }
    if ('' !== $stored && false === strpos($stored, "\r")) {
        return $normalize($posted);
    }

    return $posted;
}

/**
 * Datos de la lista nueva de documentos: pestaña ('all', 'automatic', 'managed', 'inactive'), búsqueda por nombre o
 * código y página. Devuelve ['rows', 'total', 'counts', 'usage', 'per_page'].
 */
function wpc_eds_documents_list_data(string $view, string $search, int $paged, int $per_page = 20): array
{
    global $wpdb;
    $table = $wpdb->prefix . 'documents_certificates';

    $views = [
        'all' => '1=1',
        'automatic' => "`type` = 'automatic'",
        'managed' => "`type` <> 'automatic'",
        'inactive' => '`status` <> 1',
    ];
    $view = isset($views[$view]) ? $view : 'all';

    $search_sql = '';
    if ('' !== $search) {
        $like = '%' . $wpdb->esc_like($search) . '%';
        $search_sql = $wpdb->prepare(' AND (title LIKE %s OR document_identificator LIKE %s)', $like, $like);
    }

    $counts = [];
    foreach ($views as $key => $where) {
        $counts[$key] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$where}{$search_sql}");
    }
    $offset = max(0, $paged - 1) * $per_page;
    $rows = (array) $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table} WHERE {$views[$view]}{$search_sql} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
        $per_page,
        $offset
    ));

    return [
        'view' => $view,
        'rows' => $rows,
        'total' => $counts[$view],
        'counts' => $counts,
        'usage' => wpc_documents_usage($rows),
        'per_page' => $per_page,
    ];
}

function add_admin_form_documents_content()
{

    if (isset($_GET['section_tab']) && !empty($_GET['section_tab'])) {
        if ($_GET['section_tab'] == 'document_detail') {
            global $wpdb;
            $document_id = isset($_GET['document_id']) ? absint($_GET['document_id']) : 0;
            $document = $document_id ? get_document_detail($document_id) : null;
            $variables = get_variables_documents();
            $books = get_certificates_books_list();
            $all_documents_html = [];
            $document_html = '';
            ob_start(); // Iniciar la captura de salida

            // Renderizar Header
            if (!empty($document->header)) {
                echo '<div class="automatic-document-header">';
                // El contenido se muestra como HTML: ya no se ejecuta como PHP (antes eval)
                echo $document->header;
                echo '</div>';
            }

            // Renderizar Content
            if (!empty($document->content)) {
                echo '<div class="automatic-document-content">';
                echo $document->content;
                echo '</div>';
            }

            // Renderizar Footer
            if (!empty($document->footer)) {
                echo '<div class="automatic-document-footer">';
                echo $document->footer;
                echo '</div>';
            }

            $document_html = ob_get_clean(); // Finalizar la captura y guardar el HTML renderizado
            $all_documents_html[] = $document_html;
            $html_document = implode('<hr class="document-separator">', $all_documents_html);
            include(plugin_dir_path(__FILE__) . 'templates/document-detail.php');
        }
    } else {
        if (isset($_GET['action']) && $_GET['action'] == 'save_document') {
            if ('POST' !== $_SERVER['REQUEST_METHOD'] || !current_user_can('manager_documents_certificates')) {
                wp_die(esc_html__('You are not allowed to do this.', 'wp-certificates'), 403);
            }
            check_admin_referer('wpc_save_document');

            global $wpdb;
            $table_documents_certificates = $wpdb->prefix . 'documents_certificates';

            // Alta en dos pasos: primero solo nombre y código; el documento nace inactivo (no se pide ni se genera
            // hasta activarlo) y se completa en su página de edición
            if (empty($_POST['document_id'])) {
                $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
                $document_identificator = isset($_POST['document_identificator']) ? strtoupper(sanitize_title(wp_unslash($_POST['document_identificator']))) : '';
                $create_url = admin_url('admin.php?page=add_admin_form_documents_content&section_tab=document_detail');
                $back_with_error = static function (string $message) use ($create_url, $title, $document_identificator) {
                    setcookie('message-error', $message, time() + 30, '/');
                    wp_redirect(add_query_arg(['title' => rawurlencode($title), 'document_identificator' => rawurlencode($document_identificator)], $create_url));
                    exit;
                };
                if ('' === $title || '' === $document_identificator) {
                    $back_with_error(esc_html__('The name and the code are required.', 'wp-certificates'));
                }
                // El código enlaza el documento con los documentos de cada estudiante y con las firmas: no se repite
                $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table_documents_certificates} WHERE document_identificator = %s LIMIT 1", $document_identificator));
                if ($exists) {
                    $back_with_error(sprintf(esc_html__('There is already a document with the code %s.', 'wp-certificates'), $document_identificator));
                }
                $inserted = $wpdb->insert($table_documents_certificates, [
                    'title' => strtoupper($title),
                    'document_identificator' => $document_identificator,
                    'header' => '',
                    'content' => '',
                    'footer' => '',
                    'status' => 0,
                    'signature_required' => 0,
                    'graduated_required' => 0,
                    'margin_required' => 0,
                    'orientation' => 'portrait',
                    'type' => 'managed',
                    'width_size' => 0,
                    'height_size' => 0,
                    'paper_format' => 'a4',
                    'unit' => 'mm',
                    'is_required' => 0,
                    'is_visible' => 0,
                ]);
                if (!$inserted) {
                    $back_with_error(esc_html__('Error saving document.', 'wp-certificates'));
                }
                setcookie('message', esc_html__('Document created as inactive. Complete it and activate it when it is ready.', 'wp-certificates'), time() + 30, '/');
                wp_redirect(admin_url('admin.php?page=add_admin_form_documents_content&section_tab=document_detail&document_id=' . (int) $wpdb->insert_id));
                exit;
            }

            $table_student_documents = $wpdb->prefix . 'student_documents';
            $table_students = $wpdb->prefix . 'students';

            // --- 1. Saneamiento y Conversión de Entradas ---
            $document_id = isset($_POST['document_id']) ? absint($_POST['document_id']) : 0;
            $title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';
            // El identificador se usa en la tabla de estudiantes, mantenemos el uso de strtoupper
            $document_identificator = isset($_POST['document_identificator']) ? strtoupper(sanitize_title($_POST['document_identificator'])) : '';

            // Saneamiento de otros campos
            $orientation = isset($_POST['orientation']) && in_array($_POST['orientation'], ['portrait', 'landscape'], true) ? $_POST['orientation'] : 'portrait';
            $type = isset($_POST['type']) && in_array($_POST['type'], ['managed', 'automatic'], true) ? $_POST['type'] : 'managed';
            $paper_format = isset($_POST['paper_format']) && in_array($_POST['paper_format'], ['a4', 'a3', 'letter', 'legal', 'tabloid', 'custom'], true) ? $_POST['paper_format'] : 'a4';
            $unit = isset($_POST['unit']) && in_array($_POST['unit'], ['mm', 'pt', 'cm', 'in', 'px'], true) ? $_POST['unit'] : 'mm';
            $id_requisito = isset($_POST['id_requisito']) ? sanitize_text_field($_POST['id_requisito']) : '';
            $type_file = isset($_POST['type_file']) ? sanitize_text_field($_POST['type_file']) : '';
            $book = isset($_POST['book']) ? absint($_POST['book']) : 0;
            // Descripción de la línea del libro: texto plano con variables (includes/book.php)
            $book_line_description = isset($_POST['book_line_description']) ? sanitize_textarea_field(wp_unslash($_POST['book_line_description'])) : '';
            // Prioridad de los documentos automáticos en Mi Cuenta: 0 es la más urgente (includes/automatic.php)
            $priority = isset($_POST['priority']) ? min(SQUUAD_CERT_PRIORITY_MAX, absint($_POST['priority'])) : 0;

            // Convertir a valores binarios (0 o 1)
            $status = isset($_POST['status']) && $_POST['status'] === 'on' ? 1 : 0;
            $isRequired = (isset($_POST['is_required']) && $_POST['is_required'] === 'on') && $type == 'automatic' ? 1 : 0;
            $isVisible = (isset($_POST['is_visible']) && $_POST['is_visible'] === 'on') && $type == 'automatic' ? 1 : 0;
            $signature_required = isset($_POST['signature_required']) && $_POST['signature_required'] === 'on' ? 1 : 0;
            $graduated_required = isset($_POST['graduated_required']) && $_POST['graduated_required'] === 'on' ? 1 : 0;
            $margin_required = isset($_POST['margin_required']) && $_POST['margin_required'] === 'on' ? 1 : 0;

            // Campos numéricos
            $width_size = isset($_POST['width_size']) ? floatval($_POST['width_size']) : 0.0;
            $height_size = isset($_POST['height_size']) ? floatval($_POST['height_size']) : 0.0;
            if ($paper_format !== 'custom') {
                $width_size = 0.0;
                $height_size = 0.0;
            }

            // Saneamiento CRÍTICO para contenido HTML
            $header = isset($_POST['header']) ? wp_unslash($_POST['header']) : '';
            $content = isset($_POST['content']) ? wp_unslash($_POST['content']) : '';
            $footer = isset($_POST['footer']) ? wp_unslash($_POST['footer']) : '';

            // Formulario del diseño Edusof (eds-document-detail.php): editor de código y confirmación de los automáticos
            if (!empty($_POST['wpc_eds_form']) && $document_id > 0) {
                $stored = get_document_detail($document_id);
                if ($stored) {
                    $header = wpc_eds_keep_line_endings($header, (string) $stored->header);
                    $content = wpc_eds_keep_line_endings($content, (string) $stored->content);
                    $footer = wpc_eds_keep_line_endings($footer, (string) $stored->footer);
                }
                // Al guardar como automático se añade como requisito a los estudiantes que no lo tienen: solo con confirmación
                if ('automatic' === $type && empty($_POST['wpc_eds_automatic_confirmed']) && wpc_document_automatic_pending_count($document_identificator) > 0) {
                    setcookie('message-error', esc_html__('The document was not saved: confirm that it is added as a requirement to the students who do not have it yet.', 'wp-certificates'), time() + 30, '/');
                    wp_redirect(admin_url('admin.php?page=add_admin_form_documents_content&section_tab=document_detail&document_id=' . $document_id));
                    exit;
                }
            }

            // Datos comunes para INSERT/UPDATE del documento maestro
            $document_data = array(
                'title' => strtoupper($title),
                'document_identificator' => $document_identificator,
                'header' => $header,
                'content' => $content,
                'footer' => $footer,
                'status' => $status,
                'is_required' => $isRequired,
                'is_visible' => $isVisible,
                'signature_required' => $signature_required,
                'graduated_required' => $graduated_required,
                'margin_required' => $margin_required,
                'orientation' => $orientation,
                'type' => $type,
                'width_size' => $width_size,
                'height_size' => $height_size,
                'paper_format' => $paper_format,
                'unit' => $unit,
                'id_requisito' => $id_requisito,
                'type_file' => $type_file,
                'book' => $book,
                'book_line_description' => '' !== $book_line_description ? $book_line_description : null,
                'priority' => $priority,
            );

            // Campos adicionales: se piden antes de generar el documento y sus respuestas no se guardan
            // (ver edusystem/includes/document-fields.php). Las filas inválidas se descartan con un aviso.
            $field_errors = [];
            if (function_exists('squuad_cert_sanitize_document_fields')) {
                // Un campo que ya existía con una clave que después pasó a ser variable del sistema ({{full_name}}) se conserva
                if (function_exists('squuad_cert_document_fields_kept_keys')) {
                    $stored_document = $document_id > 0 ? get_document_detail($document_id) : null;
                    squuad_cert_document_fields_kept_keys($stored_document ? array_column(squuad_cert_get_document_fields($stored_document), 'key') : []);
                }
                [$document_fields, $field_errors] = squuad_cert_sanitize_document_fields(wp_unslash($_POST['fields'] ?? []));
                $document_data['fields'] = $document_fields ? wp_json_encode($document_fields) : null;
            }

            // Otros módulos ajustan lo que se guarda (p. ej. certification/includes/signer-numbers.php renumera las variables
            // por firmante {{full_name_F2}} si el panel de firmantes cambió mientras se editaba la plantilla, ADR 0010)
            $document_data = (array) apply_filters('wpc_document_save_data', $document_data, $document_id);

            // --- 2. Actualización o Inserción del Documento Maestro ---
            $result = false;
            if ($document_id > 0) {
                $result = $wpdb->update($table_documents_certificates, $document_data, array('id' => $document_id));
                $redirect_id = $document_id;
            } else {
                $result = $wpdb->insert($table_documents_certificates, $document_data);
                $redirect_id = $wpdb->insert_id;
            }

            if ($result === false || $redirect_id === 0) {
                setcookie('message', esc_html__('Error saving document.', 'wp-certificates'), time() + 30, '/');
                $redirect_url = admin_url('/admin.php?page=documents_page');
                wp_redirect($redirect_url);
                exit;
            }
            do_action('wpc_document_saved', (int) $redirect_id, $document_data);

            // --- 3. Ejecución del Lote de Documentos/Firmas de Estudiantes (Optimización N+1) ---
            // La variable de identificación para las tablas relacionadas (documentos de estudiantes y firmas)
            $document_identifier_for_related_tables = $document_identificator; // Asumimos que la tabla usa el IDENTIFICATOR de texto.

            // a) Consulta OPTIMIZADA de estudiantes. Solo necesitamos ID, email y partner_id.
            // Sin EduSystem no hay estudiantes ni requisitos: solo se guarda el documento
            $students = wpc_edusystem_active() ? $wpdb->get_results("SELECT id, email, partner_id FROM {$table_students} ORDER BY id DESC") : [];

            if (!empty($students)) {

                // c) Mapeo de IDs
                $student_ids_to_process = array_column($students, 'id');

                $format_in = implode(', ', array_fill(0, count($student_ids_to_process), '%d'));

                // Ya no hay borrado en bloque de firmas ni de requisitos al guardar (ADR 0004, G2): las firmas son legales
                // y cada documento se anula uno a uno, con motivo, desde Admisión

                // --- Lógica AUTOMATIC (INSERT) ---
                if ($type === 'automatic') {
                    // Bulk SELECT de existentes (validación de existencia)
                    $sql_select_existing = $wpdb->prepare(
                        "SELECT student_id
                FROM {$table_student_documents}
                WHERE document_id = %s
                AND student_id IN ({$format_in})",
                        array_merge([$document_identifier_for_related_tables], $student_ids_to_process)
                    );

                    $existing_student_ids = $wpdb->get_col($sql_select_existing);
                    $new_student_ids = array_diff($student_ids_to_process, array_map('intval', $existing_student_ids));

                    if (!empty($new_student_ids)) {
                        // Bulk Insert
                        $current_time = current_time('mysql');
                        $values = [];
                        $placeholders = [];
                        foreach ($new_student_ids as $student_id) {
                            $placeholders[] = '(%d, %s, %d, %d, %d, %s)';
                            $values[] = (int) $student_id;
                            $values[] = $document_identifier_for_related_tables;
                            $values[] = $isRequired;
                            $values[] = $isVisible;
                            $values[] = 0; // status
                            $values[] = $current_time;
                        }

                        $sql_insert = "INSERT INTO {$table_student_documents} 
                            (student_id, document_id, is_required, is_visible, status, created_at) 
                            VALUES " . implode(', ', $placeholders);

                        $wpdb->query($wpdb->prepare($sql_insert, $values));
                    }
                }
            }

            // --- 4. Redirección Final ---
            setcookie('message', esc_html__('Document adjusted successfully.', 'wp-certificates'), time() + 30, '/');
            $save_warnings = $field_errors ? [esc_html__('Some additional fields were not saved:', 'wp-certificates') . ' ' . implode(' ', $field_errors)] : [];
            $forbidden = squuad_cert_book_line_forbidden_used($book_line_description);
            if ($forbidden) {
                $save_warnings[] = esc_html(sprintf(__('The registry book line cannot use %s: they are assigned when the line is reserved. Remove them, or the document will not be issued.', 'wp-certificates'), '{{' . implode('}}, {{', $forbidden) . '}}'));
            }
            if ($save_warnings) {
                setcookie('message-error', implode(' ', $save_warnings), time() + 30, '/');
            }
            if ($document_id > 0) {
                $redirect_url = admin_url('/admin.php?page=add_admin_form_documents_content&section_tab=document_detail&document_id=' . $redirect_id);
            } else {
                $redirect_url = admin_url('/admin.php?page=add_admin_form_documents_content&document_id=' . $redirect_id);
            }

            wp_redirect($redirect_url);
            exit;
        } else if (isset($_GET['action']) && $_GET['action'] == 'delete_document') {
            $document_id = isset($_GET['document_id']) ? absint($_GET['document_id']) : 0;
            if (!current_user_can('manager_documents_certificates')) {
                wp_die(esc_html__('You are not allowed to do this.', 'wp-certificates'), 403);
            }
            check_admin_referer('wpc_delete_document_' . $document_id);

            global $wpdb;
            $table_documents_certificates = $wpdb->prefix . 'documents_certificates';
            // No se borra un documento con certificados emitidos o solicitudes de firma: quedarían sin su plantilla
            $document = $document_id ? get_document_detail($document_id) : null;
            $usage = $document ? (wpc_documents_usage([$document])[$document_id] ?? null) : null;
            if ($usage && ($usage['certificates'] || $usage['requests'])) {
                setcookie('message-error', sprintf(
                    /* translators: 1: name of the document, 2: number of issued certificates, 3: number of signature requests */
                    esc_html__('«%1$s» was not deleted: it has %2$d issued certificates and %3$d signature requests, which would be left without their template. Deactivate it instead.', 'wp-certificates'),
                    (string) $document->title, // el aviso lo escapa al mostrarlo
                    (int) $usage['certificates'],
                    (int) $usage['requests']
                ), time() + 30, '/');
                wp_redirect(admin_url('/admin.php?page=add_admin_form_documents_content'));
                exit;
            }
            $wpdb->delete($table_documents_certificates, ['id' => $document_id]);

            setcookie('message', esc_html__('Document deleted successfully.', 'wp-certificates'), time() + 3600, '/');
            wp_redirect(admin_url('/admin.php?page=add_admin_form_documents_content'));
            exit;
        } else {
            // $card = get_card_detail(1);
            // include(plugin_dir_path(__FILE__) . 'templates/card-detail.php');
            // Con el diseño Edusof la lista tiene sus propios datos (wpc_eds_documents_list_data, en la plantilla)
            if (!wpc_eds_documents_enabled()) {
                $list_documents = new TT_Documents_Certificates_List_Table;
                $list_documents->prepare_items();
            }
            include(plugin_dir_path(__FILE__) . 'templates/list-documents.php');
        }
    }
}

// Agregar 'display' a las propiedades CSS permitidas
function agregar_display_a_safe_css($propiedades_seguras)
{
    $propiedades_seguras[] = 'display'; // Permitir propiedad display
    $propiedades_seguras[] = 'transform'; // Permitir propiedad display
    return $propiedades_seguras;
}
add_filter('safe_style_css', 'agregar_display_a_safe_css');

class TT_Documents_Certificates_List_Table extends WP_List_Table
{

    function __construct()
    {
        global $status, $page, $categories;

        parent::__construct(
            array(
                'singular' => 'card',
                'plural' => 'cards',
                'ajax' => true
            )
        );

    }

    function column_default($item, $column_name)
    {

        global $current_user;

        switch ($column_name) {
            case 'status':
                return $item[$column_name] == 1 ? '<span style="color: green">' . esc_html__('Active', 'wp-certificates') . '</span>' : '<span style="color: red">' . esc_html__('Inactive', 'wp-certificates') . '</span>';
            case 'view_details':
                $html = "<a href='" . admin_url('/admin.php?page=add_admin_form_documents_content&section_tab=document_detail&document_id=' . $item['id']) . "' class='button button-primary'>" . esc_html__('View Details', 'wp-certificates') . "</a>";
                $html .= '<a style="margin-left: 10px" href="' . esc_url(wp_nonce_url(admin_url('admin.php?page=add_admin_form_documents_content&action=delete_document&document_id=' . $item['id']), 'wpc_delete_document_' . $item['id'])) . '" class="button button-danger" onclick="return confirm(\'' . esc_js(__('Are you sure?', 'wp-certificates')) . '\');"><span class="dashicons dashicons-trash"></span></a>';
                return $html;
            default:
                return strtoupper($item[$column_name]);
        }
    }

    function column_name($item)
    {

        return ucwords($item['name']);
    }

    function column_cb($item)
    {
        return '';
    }

    function get_columns()
    {

        $columns = array(
            'title' => esc_html__('Name', 'wp-certificates'),
            'status' => esc_html__('Status', 'wp-certificates'),
            'created_at' => esc_html__('Created at', 'wp-certificates'),
            'view_details' => esc_html__('Actions', 'wp-certificates'),
        );

        return $columns;
    }

    function get_documents_certificates()
    {
        global $wpdb;
        $table_documents_certificates = $wpdb->prefix . 'documents_certificates';

        // PAGINATION
        $per_page = 20; // number of items per page
        $pagenum = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
        $offset = (($pagenum - 1) * $per_page);
        // PAGINATION

        $certifications = $wpdb->get_results("SELECT SQL_CALC_FOUND_ROWS * FROM {$table_documents_certificates} ORDER BY created_at DESC LIMIT {$per_page} OFFSET {$offset}", "ARRAY_A");
        $total_count = $wpdb->get_var("SELECT FOUND_ROWS()");

        return ['data' => $certifications, 'total_count' => $total_count];
    }

    function get_sortable_columns()
    {
        $sortable_columns = [];
        return $sortable_columns;
    }

    function get_bulk_actions()
    {
        $actions = [];
        return $actions;
    }

    function process_bulk_action()
    {

        //Detect when a bulk action is being triggered...
        if ('delete' === $this->current_action()) {
            wp_die('Items deleted (or they would be if we had items to delete)!');
        }
    }

    function prepare_items()
    {

        $data_certificates = $this->get_documents_certificates();

        $per_page = 10;


        $columns = $this->get_columns();
        $hidden = array();
        $sortable = $this->get_sortable_columns();

        $this->_column_headers = array($columns, $hidden, $sortable);
        $this->process_bulk_action();

        $data = $data_certificates['data'];
        $total_count = (int) $data_certificates['total_count'];

        function usort_reorder($a, $b)
        {
            $orderby = (!empty($_REQUEST['orderby'])) ? $_REQUEST['orderby'] : 'order';
            $order = (!empty($_REQUEST['order'])) ? $_REQUEST['order'] : 'asc';
            $result = strcmp($a[$orderby], $b[$orderby]);
            return ($order === 'asc') ? $result : -$result;
        }

        $per_page = 20; // items per page
        $this->set_pagination_args(array(
            'total_items' => $total_count,
            'per_page' => $per_page,
        ));

        $this->items = $data;
    }

}

function get_variables_documents()
{
    global $wpdb;
    $table_variables_document = $wpdb->prefix . 'variables_document';
    $variables = $wpdb->get_results("SELECT * FROM {$table_variables_document} ORDER BY id ASC");
    return $variables;
}

function get_documents_certificates(string $type = null): array
{
    global $wpdb;
    // Define el nombre de la tabla de forma segura
    $table_documents_certificates = $wpdb->prefix . 'documents_certificates';

    // Base de la consulta, siempre filtrando por status = 1
    $sql = "SELECT * FROM {$table_documents_certificates} WHERE `status` = 1";

    // Array para almacenar los argumentos de la cláusula WHERE (para prepare)
    $where_args = [];
    $where_formats = [];

    if (!empty($type)) {
        // Añade el filtro por tipo. El 'AND' garantiza que se combine con 'status = 1'
        $sql .= " AND `type` = %s";
        $where_args[] = $type;
        $where_formats[] = '%s'; // %s para string
    }

    $query = $wpdb->prepare($sql, ...$where_args);
    $documents = $wpdb->get_results($query);
    return $documents ?: [];
}

/**
 * Fila de la tabla «Campos adicionales» del formulario de documentos. $index es el índice del
 * array fields[] del POST ('__INDEX__' en la plantilla que usa documents.js para añadir filas).
 */
function wpc_document_field_row($index, $field = []) {
    $name = 'fields[' . $index . ']';
    $type = $field['type'] ?? 'text';
    $has_options = function_exists('squuad_cert_document_field_has_options') && squuad_cert_document_field_has_options($type);

    ob_start();
    ?>
    <tr class="wpc-document-field">
        <td><input type="text" class="widefat" name="<?= esc_attr($name) ?>[label]" value="<?= esc_attr($field['label'] ?? '') ?>" aria-label="<?= esc_attr__('Label', 'wp-certificates') ?>"></td>
        <td>
            <input type="text" class="widefat" name="<?= esc_attr($name) ?>[key]" value="<?= esc_attr($field['key'] ?? '') ?>" pattern="[a-z][a-z0-9_]*" aria-label="<?= esc_attr__('Key', 'wp-certificates') ?>">
            <?php if (!empty($field['key'])) { ?>
                <p class="description"><code>{{<?= esc_html($field['key']) ?>}}</code><?php if ($has_options) { ?> <code>{{<?= esc_html($field['key']) ?>_list}}</code><?php } ?></p>
            <?php } ?>
        </td>
        <td>
            <select name="<?= esc_attr($name) ?>[type]" class="wpc-document-field-type" aria-label="<?= esc_attr__('Type', 'wp-certificates') ?>">
                <?php foreach (squuad_cert_document_field_types() as $value => $label) { ?>
                    <option value="<?= esc_attr($value) ?>" <?php selected($type, $value); ?>><?= esc_html($label) ?></option>
                <?php } ?>
            </select>
        </td>
        <td><textarea class="widefat wpc-document-field-options" name="<?= esc_attr($name) ?>[options]" rows="3" aria-label="<?= esc_attr__('Options (one per line)', 'wp-certificates') ?>" <?= $has_options ? '' : 'disabled' ?>><?= esc_textarea(implode("\n", $field['options'] ?? [])) ?></textarea></td>
        <td style="text-align: center"><input type="checkbox" name="<?= esc_attr($name) ?>[required]" value="1" style="width: auto !important" <?php checked(!empty($field['required'])); ?> aria-label="<?= esc_attr__('Required', 'wp-certificates') ?>"></td>
        <td><button type="button" class="button-link button-link-delete wpc-remove-document-field"><?= esc_html__('Remove', 'wp-certificates') ?></button></td>
    </tr>
    <?php
    return ob_get_clean();
}

function get_document_detail($id) {
    global $wpdb;
    $table_documents_certificates = $wpdb->prefix . 'documents_certificates';
    $document = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_documents_certificates} WHERE id = %d", $id));
    return $document;
}