<?php
/**
 * EduSystem - Certificación: modales y sección "Documentos por firmar" del escritorio de Mi Cuenta
 * (squuad_cert_modal_missing_student, squuad_cert_modal_enrollment_student, squuad_cert_modal_document_automatic,
 * squuad_cert_signature_account_documents_to_sign). Movido sin cambios desde public/functions/account/dashboard.php
 * (ADR 0004, paso 3c). squuad_cert_modal_enrollment_student() no se usa (ya no se llamaba en main).
 */

if (!defined('ABSPATH')) exit;

function squuad_cert_modal_missing_student()
{
    // Imprime el contenido del archivo modal-reset-password.php
    global $wpdb, $current_user;
    $roles = $current_user->roles;
    $show_parent_info = 1;
    if (in_array('student', $roles)) {
        $table_students = $wpdb->prefix . 'students';
        $student = $wpdb->get_row("SELECT * FROM {$table_students} WHERE email='{$current_user->user_email}'");

        $partner_id = $student->partner_id;
        $student_id = $current_user->ID;

        $birth_date = get_user_meta($current_user->ID, 'birth_date', true);
        $birth_date = new DateTime($birthday);
        $today = new DateTime('today');
        $age = $today->diff($birth_date)->y;
        if ( $current_user->ID == $partner_id ) {
            $show_parent_info = 0;
        }
    } else if (in_array('parent', $roles)) {
        $table_students = $wpdb->prefix . 'students';
        $student = $wpdb->get_row("SELECT * FROM {$table_students} WHERE partner_id='{$current_user->ID}'");

        $user_student = get_user_by('email', $student->email);
        $student_id = $user_student->ID;
        $partner_id = $current_user->ID;
    }

    $user_partner = get_user_by('id', $student->partner_id);
    $user = [
        'student_full_name' => $student->name . ' ' . $student->middle_name . ' ' . $student->last_name . ' ' . $student->middle_last_name,
        'student_signature' => $student->name . ' ' . $student->last_name,
        'parent_full_name' => get_user_meta($student->partner_id, 'first_name', true) . ' ' . get_user_meta($student->partner_id, 'last_name', true),
        'today' => date('Y-m-d'),
    ];
    $table_student_documents = $wpdb->prefix . 'student_documents';
    $documents = $wpdb->get_results("SELECT * FROM {$table_student_documents} WHERE `status` != 5 AND is_visible=1 AND is_required = 0 AND student_id={$student->id}");
    $documents_required_not_approved = $wpdb->get_results("SELECT * FROM {$table_student_documents} WHERE `status` != 5 AND is_visible=1 AND is_required = 1 AND student_id={$student->id}");
    $today = date('m-d-Y');
    if (count($documents_required_not_approved) == 0) {
        include SQUUAD_CERT_MODULE_PATH . 'public/templates/create-missing-documents.php';
    }
}

function squuad_cert_modal_enrollment_student()
{
    // Imprime el contenido del archivo modal-reset-password.php
    global $wpdb, $current_user;
    $roles = $current_user->roles;
    $show_parent_info = 1;
    if (in_array('student', $roles)) {
        $table_students = $wpdb->prefix . 'students';
        $table_student_payments = $wpdb->prefix . 'student_payments';
        $student = $wpdb->get_row("SELECT * FROM {$table_students} WHERE email='{$current_user->user_email}'");
        $payment = $wpdb->get_row("SELECT * FROM {$table_student_payments} WHERE student_id='{$student->id}' ORDER BY id DESC");
        $partner_id = $student->partner_id;
        $student_id = $current_user->ID;
        $institute_id = $student->institute_id;
        $birthday = $student->birth_date;
        $birth_date = new DateTime($birthday);
        $today = new DateTime('today');
        $age = $today->diff($birth_date)->y;
        if ( $partner_id == $current_user->ID  ) {
            $show_parent_info = 0;
        }
    } else if (in_array('parent', $roles)) {
        $table_students = $wpdb->prefix . 'students';
        $table_student_payments = $wpdb->prefix . 'student_payments';
        $student = $wpdb->get_row("SELECT * FROM {$table_students} WHERE partner_id='{$current_user->ID}'");
        $user_student = get_user_by('email', $student->email);
        $payment = $wpdb->get_row("SELECT * FROM {$table_student_payments} WHERE student_id='{$student->id}' ORDER BY id DESC");
        $student_id = $user_student->ID;
        $partner_id = $current_user->ID;
        $institute_id = $student->institute_id;
    }

    $institute = $institute_id ? get_institute_details($institute_id) : null;
    $institute_name = $student->name_institute;
    $user_partner = get_user_by('id', $student->partner_id);

    $user = [
        'student_full_name' => $student->name . ' ' . $student->middle_name . ' ' . $student->last_name . ' ' . $student->middle_last_name,
        'student_signature' => $student->name . ' ' . $student->last_name,
        'student_created_at' => date('Y-m-d', strtotime($student->created_at)),
        'student_grade' => $student->grade_id,
        'student_payment' => $payment->type_payment,
        'student_birth_date' => $student->birth_date,
        'student_gender' => ucfirst($student->gender),
        'student_address' => get_user_meta($partner_id, 'billing_address_1', true),
        'student_country' => get_user_meta($partner_id, 'billing_country', true),
        'student_phone' => $student->phone,
        'parent_cell' => get_user_meta($partner_id, 'billing_phone', true),
        'parent_identification' => get_user_meta($partner_id, 'id_document', true),
        'student_identification' => $student->id_document,
        'parent_full_name' => get_user_meta($student->partner_id, 'first_name', true) . ' ' . get_user_meta($student->partner_id, 'last_name', true),
        'parent_email' => $user_partner->user_email,
        'student_email' => $student->email,
        'today' => date('Y-m-d'),
    ];
    include SQUUAD_CERT_MODULE_PATH . 'public/templates/create-enrollment.php';
}

function squuad_cert_modal_document_automatic()
{
    global $wpdb, $current_user;
    $roles = (array) $current_user->roles;
    $table_students = $wpdb->prefix . 'students';
    $table_student_payments = $wpdb->prefix . 'student_payments';

    $has_registered_status = false;
    $is_parent = in_array('parent', $roles);
    $is_student = in_array('student', $roles);
    $is_parent_or_student = $is_parent || $is_student;

    if ($is_parent_or_student) {
        if ($is_parent) {
            $has_registered_status = get_user_meta($current_user->ID, 'status_register', true) == 1;
        }

        if ($is_student && !$has_registered_status) {
            $student_id = get_user_meta($current_user->ID, 'student_id', true);
            $student = $student_id ? get_student($student_id) : null;
            if ($student && isset($student->partner_id)) {
                $has_registered_status = get_user_meta($student->partner_id, 'status_register', true) == 1;
            }
        }
    }

    if (!$has_registered_status) {
        return;
    }

    // En la confirmación del lote o la generación del PDF final no se abre el documento pendiente encima
    if (!empty($_GET['squuad_cert_batch']) || !empty($_GET['squuad_cert_pdf'])) {
        return;
    }

    $load_automatic_available = function_exists('get_complete_data_success') ? get_complete_data_success() : true;
    if (!$load_automatic_available) {
        return;
    }

    $student = null;
    $student_id = 0;
    $partner_id = 0;
    $show_parent_info = 1;

    // Solicitudes de firma (ADR 0002, esquema v5): el documento pendiente se elige por estudiante (todos los hijos
    // del representante) y por solicitud, no por usuario
    $pending = function_exists('squuad_cert_signature_requests_enabled') && squuad_cert_signature_requests_enabled()
        ? squuad_cert_signature_pending_for_user(
            $current_user,
            // Documento elegido en "Documentos por firmar" de Mi Cuenta (si no está pendiente, se abre el primero)
            isset($_GET['squuad_cert_sign']) ? sanitize_text_field(wp_unslash($_GET['squuad_cert_sign'])) : ''
        )
        : false;
    if (null === $pending) {
        return;
    }

    if ($pending) {
        $student = $pending['student'];
        $student_id = (int) $pending['student_user_id'];
        $partner_id = (int) $pending['parent_user_id'];
    } elseif (in_array('student', $roles)) {
        $student = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table_students} WHERE email = %s",
                $current_user->user_email
            )
        );

        if ($student) {
            $student_id = $current_user->ID;
            $partner_id = (int) $student->partner_id;
            $birthday = $student->birth_date;
            $birth_date = new DateTime($student->birth_date);
            $today = new DateTime('today');
            $age = $today->diff($birth_date)->y;
            
            if ($current_user->ID == $partner_id ) {
                $show_parent_info = 0;
            }
        }
    } elseif (in_array('parent', $roles)) {
        $partner_id = $current_user->ID;
        $student = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table_students} WHERE partner_id = %d",
                $partner_id
            )
        );

        if ($student) {
            $user_student = get_user_by('email', $student->email);
            if ($user_student) {
                $student_id = $user_student->ID;
            }
        }
    }

    if (!$student) {
        return;
    }

    // Estudiante que es su propio representante (p. ej. se registró él mismo con el rol parent): sin la parte
    // del representante, igual que el bloque {{#show_parent_info}} del documento (get_replacements_variables()).
    // Si no coinciden, create-enrollment.js espera un panel de firma del representante que no existe.
    if ($student_id && (int) $student_id === (int) $student->partner_id) {
        $show_parent_info = 0;
    }

    $institute_id = (int) $student->institute_id;
    $user_partner = $student->partner_id ? get_user_by('id', (int) $student->partner_id) : null;

    $payment = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT type_payment FROM {$table_student_payments} WHERE student_id = %d ORDER BY id DESC",
            $student->id
        )
    );

    $institute = $institute_id ? get_institute_details($institute_id) : null;
    $institute_name = $student->name_institute;

    $user = [
        'student_full_name' => trim(implode(' ', array_filter([$student->name, $student->middle_name, $student->last_name, $student->middle_last_name]))),
        'student_signature' => trim($student->name . ' ' . $student->last_name),
        'student_created_at' => date('Y-m-d', strtotime($student->created_at)),
        'student_grade' => $student->grade_id,
        'student_payment' => $payment ? $payment->type_payment : '',
        'student_birth_date' => $student->birth_date,
        'student_gender' => ucfirst($student->gender),
        'student_address' => $partner_id ? get_user_meta($partner_id, 'billing_address_1', true) : '',
        'student_country' => $partner_id ? get_user_meta($partner_id, 'billing_country', true) : '',
        'student_phone' => $student->phone,
        'parent_cell' => $partner_id ? get_user_meta($partner_id, 'billing_phone', true) : '',
        'parent_identification' => $partner_id ? get_user_meta($partner_id, 'id_document', true) : '',
        'student_identification' => $student->id_document,
        'parent_full_name' => $user_partner ? trim($user_partner->first_name . ' ' . $user_partner->last_name) : '',
        'parent_email' => $user_partner ? $user_partner->user_email : '',
        'student_email' => $student->email,
        'today' => date('Y-m-d'),
    ];

    $template_data = [
        'user' => $user,
        'institute' => $institute,
        'institute_name' => $institute_name,
        'show_parent_info' => $show_parent_info,
        'student_id' => $student_id,
        'partner_id' => $partner_id,
        'student' => $student,
        'payment' => $payment,
        'user_partner' => $user_partner,
        'form_filled' => function_exists('get_form_filled') ? get_form_filled() : null,
        'program_data_student' => get_program_data_student($student->id)
    ];

    $document = $pending ? $pending['document'] : apply_filters('get_first_pending_automatic_document', null);

    // Con solicitud: si el contenido ya está congelado (alguien firmó), se muestra tal cual, sin volver a pedir los
    // campos adicionales ni regenerar nada (ADR 0002, puntos 2 y 11)
    $request = $pending ? $pending['request'] : null;
    if ($request && null !== $request->frozen_at_utc) {
        $content = squuad_cert_signature_request_content((int) $request->id);
        if (null === $content) {
            return; // contenido alterado: no se muestra para firmar (el verificador lo marca)
        }
        $html = squuad_cert_signature_render_content($content, $student, $request);
        $document_fields = [];
        $field_values = [];
        $legacy_partial = !empty($pending['legacy_partial']) && 1 === (int) $request->round;
        extract($template_data, EXTR_SKIP);
        include SQUUAD_CERT_MODULE_PATH . 'public/templates/create-document-automatic.php';
        return;
    }

    // Campos adicionales del documento: se piden antes de mostrarlo. Las respuestas llegan por POST a esta
    // misma página y solo se usan para rellenar el documento. Si el otro firmante ya firmó su parte, se usan
    // las respuestas guardadas con su firma (users_signatures.document_fields) y no se vuelve a preguntar.
    $field_replacements = [];
    $field_values = [];
    $document_fields = $document ? squuad_cert_get_document_fields($document) : [];
    if ($document_fields) {
        // Con solicitudes, las respuestas viven en el contenido congelado: antes de la primera firma no hay guardadas
        $stored_values = $pending ? null : squuad_cert_document_fields_stored_values($document, $document_fields, [$student_id, $partner_id]);

        if (null !== $stored_values) {
            $field_values = $stored_values;
        } else {
            $field_errors = [];
            $posted = is_string($_POST['squuad_cert_document_fields'] ?? null)
                && (int) $_POST['squuad_cert_document_fields'] === (int) $document->id;
            $submitted = $posted
                && is_string($_POST['_wpnonce'] ?? null)
                && wp_verify_nonce($_POST['_wpnonce'], 'edusystem_document_fields_' . $document->id);

            if ($submitted) {
                [$field_values, $field_errors] = squuad_cert_document_fields_values($document_fields, wp_unslash($_POST['document_fields'] ?? []));
            } elseif ($posted) {
                // Nonce caducado (la página se envió horas después): se avisa y se conservan las respuestas
                [$field_values] = squuad_cert_document_fields_values($document_fields, wp_unslash($_POST['document_fields'] ?? []));
                $field_errors = ['expired' => __('Your session expired. Please check your answers and submit them again.', 'edusystem')];
            }
            if (!$submitted || $field_errors) {
                include SQUUAD_CERT_MODULE_PATH . 'public/templates/document-fields-form.php';
                return;
            }
        }
        $field_replacements = squuad_cert_document_fields_replacements($document_fields, $field_values);
    }

    $html_parts = [];

    extract($template_data, EXTR_SKIP);

    if ($document) {
        if (!empty($document->header) || !empty($document->content) || !empty($document->footer)) {

            ob_start();

            if (!empty($document->header)) {
                echo '<div class="automatic-document-header">';
                echo $document->header;
                echo '</div>';
            }

            if (!empty($document->content)) {
                echo '<div class="automatic-document-content">';
                echo $document->content;
                echo '</div>';
            }

            if (!empty($document->footer)) {
                echo '<div class="automatic-document-footer">';
                echo $document->footer;
                echo '</div>';
            }

            $document_html = ob_get_clean();

            if (!empty($document_html)) {
                // Se agrega el único documento a la lista de partes
                $html_parts[] = $document_html;
            }
        }
    }

    $html = implode('<hr class="document-separator">', $html_parts);
    // Las variables del sistema ganan a un campo con la misma clave. Los valores los resuelve wp-certificates (ADR 0005)
    $replacements = array_merge($field_replacements, ($document && function_exists('squuad_cert_template_replacements'))
        ? squuad_cert_template_replacements($html, (int) $student->id, ['document' => $document])['replacements']
        : get_replacements_variables($student));

    $legacy_partial = false;
    if ($pending && '' !== $html) {
        // Borrador de la solicitud (ADR 0002): datos escapados, marcadores fijos para las firmas y el QR, imágenes
        // incrustadas. Se regenera en cada apertura hasta la primera firma, que lo congela.
        $request = $request ?: squuad_cert_signature_request_get_or_create(
            (int) $student->id,
            (string) $document->document_identificator,
            ['student_user_id' => $student_id, 'parent_user_id' => $partner_id],
            squuad_cert_signature_student_document_id((int) $student->id, (string) $document->document_identificator),
            squuad_cert_signature_doc_version_hash((string) $document->document_identificator),
            (int) $document->id,
            'opened'
        );
        if (!$request) {
            return;
        }
        // Variables de firma de cada firmante y reglas de la plantilla (estudiante, representante, firmantes del
        // sistema); los firmantes del sistema que la plantilla no coloca van en un bloque "Firmas" al final
        $institutional = function_exists('squuad_cert_signature_signer_replacements') ? squuad_cert_signature_signer_replacements($request) : [];
        $content = process_template($html, array_merge(squuad_cert_signature_escape_replacements($replacements), $institutional));
        if (function_exists('squuad_cert_signature_strip_unused_signer_tags')) {
            $content = squuad_cert_signature_strip_unused_signer_tags($content);
        }
        if (function_exists('squuad_cert_signature_append_institutional_block')) {
            $content = squuad_cert_signature_append_institutional_block($content, $request);
        }
        $content = squuad_cert_signature_inline_images($content);
        if (null === squuad_cert_signature_request_save_draft((int) $request->id, $content)) {
            // Otra petición la congeló entre medias: se muestra el contenido congelado
            $request = squuad_cert_signature_request_get((int) $request->id);
            $content = $request ? squuad_cert_signature_request_content((int) $request->id) : null;
            if (null === $content) {
                return;
            }
        }
        $request = squuad_cert_signature_request_get((int) $request->id);
        $legacy_partial = !empty($pending['legacy_partial']) && 1 === (int) $request->round;
        if ($legacy_partial) {
            squuad_cert_signature_request_log_event_once((int) $request->id, 'legacy_partial_superseded');
        }
        $html = squuad_cert_signature_render_content($content, $student, $request);
        $document_fields = [];
    } else {
        $html = process_template($html, $replacements);
    }

    if (!empty($html)) {
        include SQUUAD_CERT_MODULE_PATH . 'public/templates/create-document-automatic.php';
    }
}

/**
 * Mi Cuenta: sección "Documentos por firmar" del escritorio (ADR 0003, paso 5b). Lista todos los documentos del
 * estudiante o del representante (todos sus hijos): los que le toca firmar, con un botón para abrir ese documento, y
 * los que ya firmó y esperan a otra persona.
 */
add_action('woocommerce_account_dashboard', 'squuad_cert_signature_account_documents_to_sign', 1);
function squuad_cert_signature_account_documents_to_sign()
{
    if (!function_exists('squuad_cert_signature_user_documents') || !is_user_logged_in()) {
        return;
    }
    $user = wp_get_current_user();
    $dashboard = wc_get_account_endpoint_url('dashboard');
    if (function_exists('squuad_cert_signature_batch_print_notice')) {
        squuad_cert_signature_batch_print_notice();
    }

    // Firma en lote (ADR 0003, paso 6b): confirmación o resultado del lote, y PDF finales pendientes
    if (!empty($_GET['squuad_cert_batch']) && function_exists('squuad_cert_signature_batch_get')) {
        $batch = squuad_cert_signature_batch_get(absint($_GET['squuad_cert_batch']));
        if ($batch && 'holder' === ($batch->data['kind'] ?? '')) {
            $pdf_requests = 'finished' === $batch->status && !empty($batch->result_data['completed'])
                ? squuad_cert_signature_account_pdf_requests($user, array_map('intval', (array) $batch->result_data['completed']))
                : [];
            include SQUUAD_CERT_MODULE_PATH . 'public/templates/documents-to-sign-batch.php';
            return;
        }
    }
    if (!empty($_GET['squuad_cert_pdf']) && function_exists('squuad_cert_signature_account_pdf_requests')) {
        $pdf_requests = squuad_cert_signature_account_pdf_requests($user, [absint($_GET['squuad_cert_pdf'])]);
        if ($pdf_requests) {
            echo '<section class="edusystem-documents-to-sign" id="edusystem-documents-to-sign" style="margin-bottom:24px"><h3>' . esc_html__('Final PDF', 'edusystem') . '</h3>';
            include SQUUAD_CERT_MODULE_PATH . 'public/templates/signature-final-pdf.php';
            echo '</section>';
            return;
        }
    }

    $items = squuad_cert_signature_user_documents($user);
    if (!$items) {
        return;
    }
    $batchable = function_exists('squuad_cert_signature_batch_holder_candidates')
        ? array_map(static fn($row) => (int) $row->id, squuad_cert_signature_batch_holder_candidates($user))
        : [];
    include SQUUAD_CERT_MODULE_PATH . 'public/templates/documents-to-sign.php';
}
