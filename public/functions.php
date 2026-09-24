<?php
require plugin_dir_path(__FILE__) . 'endpoint.php';

function wp_certificates_scripts() {

    global $wp;
    $version = '1.6';

    // Hoja de estilos
    wp_enqueue_style('style-certificates', plugins_url('wp-certificates') . '/public/assets/css/style.css', [], $version, 'all');

    // Script JS
    if (str_contains(home_url($wp->request), 'card')) {
        wp_enqueue_script( 'my-card-script', plugins_url('wp-certificates') . '/public/assets/js/my-card.js', ['jquery'], $version, true );
    }

    wp_enqueue_script('qrcode-js', plugins_url('wp-certificates') . '/public/assets/js/qrcode.min.js');
    wp_enqueue_script('qrcode', plugins_url('wp-certificates') . '/public/assets/js/qrcode.js');
}

add_action('wp_enqueue_scripts', 'wp_certificates_scripts');


add_filter('woocommerce_account_menu_items', 'add_certification_public', 10, 1);

function add_certification_public($menu_links) {
    global $current_user;
    $roles = $current_user->roles;
    $disable_idcard = get_option('disable_idcard');

    if ((in_array('student', $roles) || in_array('teacher', $roles)) && !$disable_idcard) {
        $menu_links = array_slice($menu_links, 0, 3, true)
            + array('my-card' => esc_html__('ID Card', 'wp-certificates'))
            + array_slice($menu_links, 3, null, true); // Cambiado de 2 a 3 aquí
    }

    if ( in_array('student', $roles) ) {
        $menu_links = array_slice($menu_links, 0, 3, true)
            + array('certificates' => esc_html__('Certificates', 'wp-certificates'))
            + array_slice($menu_links, 3, null, true); // Cambiado de 2 a 3 aquí
    }

    return $menu_links;
}

add_action('init', function () {
    add_rewrite_endpoint('my-card', EP_ROOT | EP_PAGES);
    add_rewrite_endpoint('certificates', EP_ROOT | EP_PAGES);
});

/* add_filter('wp_nav_menu_items', 'add_certification_link', 10, 2);

function add_certification_link($items, $args)
{
    global $current_user;
    $disable_idcard = get_option('disable_idcard');

    if (is_user_logged_in() && in_array('student', $current_user->roles) && $args->theme_location != 'primary' && !$disable_idcard) {

        // Nuevo elemento SIN cerrar </li>
        $new_item = '<li class="menu-item"><a href="'
            . esc_url(get_permalink(get_option('woocommerce_myaccount_page_id'))) . '/my-card">'
            . esc_html__('ID Card', 'form-plugin')
            . '</a>';

        // Dividir los items existentes
        $menu_items = explode('</li>', $items);

        // Posición de inserción (ej: 2 = tercer lugar)
        $position = 4;

        // Insertar el nuevo elemento
        array_splice($menu_items, $position, 0, $new_item);

        // Eliminar elementos vacíos y unir
        $items = implode('</li>', array_filter($menu_items)) . '</li>';
    }

    if ( is_user_logged_in() && in_array('student', $current_user->roles) && $args->theme_location != 'primary' ) {

        // Nuevo elemento SIN cerrar </li>
        $new_item = '<li class="menu-item"><a href="'
            . esc_url(get_permalink(get_option('woocommerce_myaccount_page_id'))) . '/certificates">'
            . esc_html__('Certificates', 'wp-certificates')
            . '</a>';

        // Dividir los items existentes
        $menu_items = explode('</li>', $items);

        // Posición de inserción (ej: 2 = tercer lugar)
        $position = 6;

        // Insertar el nuevo elemento
        array_splice($menu_items, $position, 0, $new_item);

        // Eliminar elementos vacíos y unir
        $items = implode('</li>', array_filter($menu_items)) . '</li>';
    }

    return $items;
} */

add_action('woocommerce_account_certificates_endpoint', function () {

    global $wpdb;
    $certificates = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM `{$wpdb->prefix}certificates` WHERE type = 'download_certificate' AND email = %s",
        wp_get_current_user()->user_email
    )) ?? [];

    include(plugin_dir_path(__FILE__) . 'templates/certificates.php');
    return;

});

add_action('woocommerce_account_my-card_endpoint', 'my_card_endpoint');
function my_card_endpoint()
{
    global $wpdb, $current_user;
    $roles = $current_user->roles;
    $card_default = get_card_detail(card_id: 1);
    $my_account_url = get_permalink(get_option('woocommerce_myaccount_page_id'));
    $documents_path = in_array('teacher', $roles) ? 'teacher-documents/' : 'student-documents/';
    $final_url = trailingslashit($my_account_url) . $documents_path;

    if (in_array('student', $roles)) {
        $table_certificates = $wpdb->prefix . 'certificates';
        $user = function_exists('helper_get_student_logged') ? helper_get_student_logged() : null;
        $current_date = new DateTime();

        if (!$user->nacionality) {
            // Cargar el archivo local de países
            $countries_file = plugins_url('wp-certificates') . '/public/assets/js/countries.json';
            $countries_json = file_get_contents($countries_file);
            $countries_data = json_decode($countries_json, true);

            $demonyms = array();
            $added_demonyms = array();

            foreach ($countries_data as $country) {
                $code = $country['cca2'];
                $nombre_pais = $country['name']['common'];

                $demonym = 'Unknown';
                if (isset($country['demonyms']['eng']['m'])) {
                    $demonym = $country['demonyms']['eng']['m'];
                } elseif (isset($country['demonyms']['eng']['f'])) {
                    $demonym = $country['demonyms']['eng']['f'];
                } else {
                    $demonym = $nombre_pais . 'n';
                }

                $demonym = ucfirst(strtolower($demonym));

                if (!in_array($demonym, $added_demonyms)) {
                    $demonyms[$code] = $demonym;
                    $added_demonyms[] = $demonym;
                }
            }

            asort($demonyms);
            include(plugin_dir_path(__FILE__) . 'templates/select-nacionality.php');
            return;
        }

        // Resto del código...
        $card = $wpdb->get_row($wpdb->prepare(
            "SELECT * 
            FROM {$table_certificates} 
            WHERE email = %s 
            AND type = 'card' ORDER BY emission_date DESC",
            $user->email,
            $current_date->format('Y-m-d')
        ));

        $birth_date = DateTime::createFromFormat('Y-m-d', $user->birth_date);
        $emission_date = DateTime::createFromFormat('Y-m-d', $card->emission_date);
        $expiration_date = DateTime::createFromFormat('Y-m-d', $card->expiration_date);

        include(plugin_dir_path(__FILE__) . 'templates/my-card.php');
        return;
    }

    if (in_array('teacher', $roles)) {
        $table_certificates = $wpdb->prefix . 'certificates';
        $user = function_exists('helper_get_teacher_logged') ? helper_get_teacher_logged() : null;
        $current_date = new DateTime();

        if (!$user->nacionality) {
            // Cargar el archivo local de países
            $countries_file = plugins_url('wp-certificates') . '/public/assets/js/countries.json';
            $countries_json = file_get_contents($countries_file);
            $countries_data = json_decode($countries_json, true);

            $demonyms = array();
            $added_demonyms = array();

            foreach ($countries_data as $country) {
                $code = $country['cca2'];
                $nombre_pais = $country['name']['common'];

                $demonym = 'Unknown';
                if (isset($country['demonyms']['eng']['m'])) {
                    $demonym = $country['demonyms']['eng']['m'];
                } elseif (isset($country['demonyms']['eng']['f'])) {
                    $demonym = $country['demonyms']['eng']['f'];
                } else {
                    $demonym = $nombre_pais . 'n';
                }

                $demonym = ucfirst(strtolower($demonym));

                if (!in_array($demonym, $added_demonyms)) {
                    $demonyms[$code] = $demonym;
                    $added_demonyms[] = $demonym;
                }
            }

            asort($demonyms);
            include(plugin_dir_path(__FILE__) . 'templates/select-nacionality.php');
            return;
        }

        // Resto del código...
        $card = $wpdb->get_row($wpdb->prepare(
            "SELECT * 
        FROM {$table_certificates} 
        WHERE email = %s 
        AND type = 'card' ORDER BY emission_date DESC",
            $user->email,
            $current_date->format('Y-m-d')
        ));

        $birth_date = DateTime::createFromFormat('Y-m-d', $user->birth_date);
        $emission_date = DateTime::createFromFormat('Y-m-d', $card->emission_date);
        $expiration_date = DateTime::createFromFormat('Y-m-d', $card->expiration_date);

        include(plugin_dir_path(__FILE__) . 'templates/my-card.php');
        return;
    }
}

add_action('wp_ajax_set_nacionality', 'set_nacionality_callback');
add_action('wp_ajax_nopriv_set_nacionality', 'set_nacionality_callback');
function set_nacionality_callback()
{
    global $wpdb, $current_user;
    $roles = $current_user->roles;
    $nacionality = $_POST['nacionality'];

    if (in_array('student', $roles)) {
        $table_students = $wpdb->prefix . 'students';
        $student = function_exists('helper_get_student_logged') ? helper_get_student_logged() : null;

        $wpdb->update($table_students, [
            'nacionality' => $nacionality
        ], ['id' => $student->id]);
    }

    if (in_array('teacher', $roles)) {
        $table_teachers = $wpdb->prefix . 'teachers';
        $teacher = function_exists('helper_get_teacher_logged') ? helper_get_teacher_logged() : null;

        $wpdb->update($table_teachers, [
            'nacionality' => $nacionality
        ], ['id' => $teacher->id]);
    }

    wp_send_json_success(array('success' => true));
    exit;
}

add_action('wp_ajax_request_card', 'request_card_callback');
add_action('wp_ajax_nopriv_request_card', 'request_card_callback');
function request_card_callback()
{
    global $current_user;
    $roles = $current_user->roles;

    if (in_array('student', $roles)) {
        $user = function_exists('helper_get_student_logged') ? helper_get_student_logged() : null;
    }

    if (in_array('teacher', $roles)) {
        $user = function_exists('helper_get_teacher_logged') ? helper_get_teacher_logged() : null;
    }

    $program = in_array('student', $roles) ? (function_exists('get_name_program') ? get_name_program($user->program_id) : '') : '';
    $today = new DateTime('now', new DateTimeZone('UTC'));
    $expiration = clone $today;
    $expiration->modify('+1 year');
    $emission_date = $today->format('Y-m-d');
    $expiration_date = $expiration->format('Y-m-d');

    apply_filters('create_certificate_edusystem', 'card', 'ID Card', $program, 1, $user, $emission_date, $expiration_date);
    wp_send_json_success(['success' => true]);
    exit;
}

function add_viewport_meta()
{
    echo '<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">';
}
add_action('wp_head', 'add_viewport_meta', 1);

// Plugin B: mi-plugin-receptor.php
function create_certificate_edusystem_callback($type, $name, $program = '', $template_id, $student, $emission_date, $expiration_date = null)
{
    global $wpdb;
    $table_certificates = $wpdb->prefix . 'certificates';

    // If $student is provided as an integer (ID) or numeric string, fetch the student record.
    if (is_int($student) || (is_string($student) && ctype_digit($student))) {
        $course_participant = function_exists('woocerti_get_course_and_participant_data') ? woocerti_get_course_and_participant_data($student) : null;
        $participant_id = isset($course_participant['id_participant']) ? $course_participant['id_participant'] : '';
        $student_name = isset($course_participant['first_name']) ? $course_participant['first_name'] : '';
        $student_last_name = isset($course_participant['last_name']) ? $course_participant['last_name'] : '';
        $student_email = isset($course_participant['email']) ? $course_participant['email'] : '';
        $template_id_data = isset($course_participant['template_id']) ? $course_participant['template_id'] : null;
        $student_full_name = trim($student_name . ' ' . $student_last_name);

        $course_id = isset($course_participant['id_course']) ? $course_participant['id_course'] : null;
        $template_id = $template_id_data !== null ? $template_id_data : $template_id;

        $document_certificate = get_document_detail($template_id);

        // Unir de forma segura header, content y footer.
        // get_document_detail puede devolver un objeto, un array o null.
        $html = '';

        if ($document_certificate) {
            // Obtener cada parte soportando array u object
            $get_part = function ($part) use ($document_certificate) {
                if (is_array($document_certificate) && isset($document_certificate[$part])) {
                    return $document_certificate[$part];
                }

                if (is_object($document_certificate) && isset($document_certificate->{$part})) {
                    return $document_certificate->{$part};
                }

                return '';
            };

            $header = $get_part('header');
            $content = $get_part('content');
            $footer = $get_part('footer');

            // Concatenar las partes con separación mínima
            $html = trim(($header ? $header . "\n" : '') . ($content ? $content . "\n" : '') . $footer);
        }
    } else {
        $student_name = is_object($student) && isset($student->name) ? $student->name : '';
        $student_last_name = is_object($student) && isset($student->last_name) ? $student->last_name : '';
        $student_email = is_object($student) && isset($student->email) ? $student->email : '';
        $student_full_name = trim($student_name . ' ' . $student_last_name);
    }

    // 1. Validar si el registro ya existe
    $existing_record = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id, simple_uuid FROM $table_certificates WHERE type = %s AND name_document = %s AND email = %s",
            $type,
            $name,
            $student->email
        )
    );

    if ($existing_record && !empty($existing_record->simple_uuid)) {
        // Si el registro existe, devolvemos su URL y la URL de la imagen.
        $url = get_option('validation_url') . 'verificate-certificate/' . $existing_record->simple_uuid;
        $image_url = get_option('image_qr_url');
        return ['url' => $url, 'image_url' => $image_url];
    }

    // 2. Si no existe, procedemos con la inserción del nuevo registro
    $insert_data = [
        'type' => $type,
        'name_document' => $name,
        'program_document' => $program,
        'template_id' => $template_id,
        'user' => $student_full_name,
        'email' => $student_email,
        'emission_date' => $emission_date,
        'expiration_date' => $expiration_date,
        'participant_id' => isset($participant_id) ? $participant_id : null,
        'course_id' => isset($course_id) ? $course_id : null,
        'html' => isset($html) ? $html : null,
    ];

    $wpdb->insert($table_certificates, $insert_data);
    $inserted_id = $wpdb->insert_id;

    // 3. Generar y actualizar con el token único
    $string = $inserted_id;
    $hash = wp_hash($string);
    $simple_uuid = substr($hash, 0, 6);

    $wpdb->update(
        $table_certificates,
        ['simple_uuid' => $simple_uuid],
        ['id' => $inserted_id]
    );

    $url = get_option('validation_url') . 'verificate-certificate/' . $simple_uuid;
    $download_url = get_option('validation_url') . 'download-certificate/' . $simple_uuid;
    $image_url = get_option('image_qr_url');

    return ['url' => $url, 'download_url' => $download_url, 'image_url' => $image_url];
}

add_filter('create_certificate_edusystem', 'create_certificate_edusystem_callback', 10, 7);

function automatic_documents_loaded() {
    global $wpdb;
    $table_documents_certificates = $wpdb->prefix . 'documents_certificates';
    $documents = $wpdb->get_results("SELECT * FROM {$table_documents_certificates} WHERE `type` = 'automatic' AND `status` = 1 ORDER BY id ASC");
    return $documents;
}

// Agregamos la función al filtro.
add_filter('load_automatic_documents', 'automatic_documents_loaded');


function automatic_documents_last_optimized() {
    global $wpdb, $current_user;

    // Table definitions
    $table_documents_certificates = $wpdb->prefix . 'documents_certificates';
    $table_users_signatures = $wpdb->prefix . 'users_signatures';
    $user_id = $current_user->ID;

    // The SQL query is optimized to fetch only the FIRST document that the user has NOT signed.
    $query = $wpdb->prepare(
        "
        SELECT t1.*
        FROM {$table_documents_certificates} AS t1
        LEFT JOIN {$table_users_signatures} AS t2
            ON t1.document_identificator = t2.document_id
            AND t2.user_id = %d
        WHERE t1.type = 'automatic'
          AND t1.status = 1
          AND t2.id IS NULL
        ORDER BY t1.id ASC
        LIMIT 1
        ",
        $user_id
    );

    $doc = $wpdb->get_row($query);

    return $doc;
}

add_filter('get_first_pending_automatic_document', 'automatic_documents_last_optimized');

function assign_certificate_student( $student_id, $template_id, $type, $emission_date, $expiration_date = null, $program = '', $course_id = '', $user_signature_id = null ) {
    global $wpdb;
    $table_certificates = $wpdb->prefix . 'certificates';

    // 1. Obtener detalles del estudiante
    $student = get_student($student_id); 
    if( !$student ) return false;

    // 2. Obtener detalles del documento
    $document = get_document_detail( $template_id );
    if( !$document ) return false;

    $existing_record = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id, simple_uuid FROM $table_certificates WHERE type = %s AND name_document = %s AND email = %s",
            $type,
            $document->title,
            $student->email
        )
    );

    if ( !$existing_record ) {
        $student_full_name = trim("{$student->name} {$student->middle_name} {$student->last_name} {$student->middle_last_name}");

        // Helper para mapear propiedades tanto de objetos como de arrays uniformemente
        $get_part = function ($part) use ($document) {
            if (is_array($document) && isset($document[$part])) return $document[$part];
            if (is_object($document) && isset($document->{$part})) return $document->{$part};
            return '';
        };

        $header      = $get_part('header');
        $content     = $get_part('content');
        $footer      = $get_part('footer');
        $title       = $get_part('title');
        $doc_id      = $get_part('id');
        $orientation = $get_part('orientation');
        $unit        = $get_part('unit');
        $width_size  = $get_part('width_size');
        $height_size = $get_part('height_size');
        $paper_format= $get_part('paper_format');

        // 3. Preparar los datos del tamaño/dimensiones para guardarlos en la BD
        // Convertimos el formato a string si viene como array (en caso de 'custom')
        $format_save = is_array($paper_format) ? 'custom' : $paper_format;

        $certificate_template = implode('', [
            $header,
            $content,
            $footer,
        ]);

        if ( $document->book && function_exists('edusof_insert_certificate_book_line') ) {
            $book_data = edusof_insert_certificate_book_line( $document->book, $student, $type, $title, $emission_date, $program, $course_id );

            if (!empty($book_data)) {
                $replacements['folio'] = [
                    'value' => $book_data['folio'] ?? '',
                    'wrap' => true,
                ];

                $replacements['tomo'] = [
                    'value' => $book_data['tomo'] ?? '',
                    'wrap' => true,
                ];

                $replacements['tomo_folio'] = [
                    'value' => __('Tome: ','wp-certificates').
                        ($book_data['tomo'] ?? '') .
                        __('Folio: ','wp-certificates').
                        ($book_data['folio'] ?? ''),
                    'wrap' => true,
                ];
            }
        }

        // 4. Preparar las variables de reemplazo estándar
        $replacements = get_replacements_variables($student);

        // 5. Procesar Firma Digital si se requiere
        $signature_required = is_object($document) ? ($document->signature_required ?? false) : ($document['signature_required'] ?? false);
        
        if ( $signature_required && !empty($user_signature_id) ) {
            $signature = get_user_signature_detail($user_signature_id);
            if ( $signature ) {
                $user_signature = get_user_by('id', $signature->user_id);
                if ( $user_signature ) {
                    $user_sign = $user_signature->first_name . ' ' . $user_signature->last_name;
                    $replacements['user_sign'] = ['value' => $user_sign, 'wrap' => true];
                    $replacements['position_user_charge'] = ['value' => $signature->charge, 'wrap' => true];
                    $replacements['signature'] = [
                        'value' => '<img style="width: auto !important; height: 100px !important;" src="' . wp_get_attachment_url($signature->attach_id) . '"/>',
                        'wrap' => false
                    ];
                }
            }
        }

        // 6. Procesar las secciones individualmente
        $processed_header  = process_template($header, $replacements);
        $processed_content = process_template($content, $replacements);
        $processed_footer  = process_template($footer, $replacements);

        // 7. Lógica del QR Code
        $create_certificate_qr = (
            strpos($content, '{{qrcode}}') !== false ||
            strpos($header, '{{qrcode}}') !== false ||
            strpos($footer, '{{qrcode}}') !== false
        );

        $qr = ['url' => '', 'image_url' => ''];
        if ($create_certificate_qr) {
            $qr = apply_filters('create_certificate_edusystem', 'certificate', $document->title, get_name_program_student($student->id), 1, $student, $emission_date);
        }

        // Estructura HTML final con contenedores limpios
        $html_final = trim(
            ($processed_header ? '<div id="header-document">' . $processed_header . '</div>' . "\n" : '') .
            ($processed_content ? '<div id="content-pdf">' . $processed_content . '</div>' . "\n" : '') .
            ($processed_footer ? '<div id="footer-document">' . $processed_footer . '</div>' : '')
        );

        $option_document = [
            'orientation'  => strtolower($orientation),
            'unit'         => strtolower($unit),
            'paper_format' => strtolower($format_save),
            'width_size'   => $width_size,
            'height_size'  => $height_size,
            'width_style'  => $width_size . $unit,
            'height_style' => $height_size . $unit,
            'qr'          => $qr
        ];

        // 8. Inserción de datos (Nota la columna 'document_dimensions')
        $insert_data = [
            'type'                => $type,
            'name_document'       => $title,
            'program_document'    => $program,
            'template_id'         => $doc_id,
            'user'                => $student_full_name,
            'email'               => $student->email,
            'emission_date'       => $emission_date,
            'expiration_date'     => $expiration_date,
            'participant_id'      => $student->id,
            'course_id'           => $course_id,
            'html'                => $html_final,
            'tomo'                => $book_data['tomo'] ?? null,
            'folio'               => $book_data['folio'] ?? null,
            'option_document'     => json_encode($option_document) // Guardamos el tamaño en un JSON estructurado
        ];

        $result = $wpdb->insert($table_certificates, $insert_data);
        if( !$result ) return false;

        $inserted_id = $wpdb->insert_id;

        // 9. Generar y actualizar con el token único
        $string = $inserted_id;
        $hash = wp_hash($string);
        $simple_uuid = substr($hash, 0, 6);

        $wpdb->update(
            $table_certificates,
            ['simple_uuid' => $simple_uuid],
            ['id' => $inserted_id]
        );
        
    } else {
        $inserted_id = $existing_record->id;
    }

    return $inserted_id;
}

