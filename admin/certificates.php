<?php

function add_admin_form_certificates_list_content() {

    if (isset($_GET['section_tab']) && !empty($_GET['section_tab'])) {
        if ($_GET['section_tab'] == 'certification_detail') {
            global $wpdb;
            include(plugin_dir_path(__FILE__) . 'templates/certificate-detail.php');
        }
    } else {

        if (isset($_GET['action']) && $_GET['action'] == 'save_certification') {
            $certificate_id = $_GET['certificate_id'];
            setcookie('message', esc_html__('Certification adjusted successfully.', 'wp-certificates'), time() + 3600, '/');
            wp_redirect(admin_url('/admin.php?page=add_admin_form_certificates_list_content&section_tab=certification_detail&certificate_id=' . $certificate_id));
            exit;
        } else if (isset($_GET['action']) && $_GET['action'] == 'delete_certification') {
            setcookie('message', esc_html__('Certification deleted successfully.', 'wp-certificates'), time() + 3600, '/');
            wp_redirect(admin_url('/admin.php?page=add_admin_form_certificates_list_content'));
            exit;
        } else {
            $list_certificates = new TT_certificates_all_List_Table;
            $list_certificates->prepare_items();
            include(plugin_dir_path(__FILE__) . 'templates/list-certificates.php');
        }
    }
}

function admin_certificate_assignment_content () {

    // Sin EduSystem no hay estudiantes: se emite a usuarios de WordPress (admin/assignment-users.php)
    if ( !wpc_edusystem_active() ) {
        squuad_cert_assignment_users_page();
        return;
    }

    if( isset($_GET['action']) && $_GET['action'] == 'generate_certificates' ) {

        // Solo por POST, con el permiso de la pantalla y su nonce (antes se podía provocar desde otra web: CSRF)
        if ( 'POST' !== ($_SERVER['REQUEST_METHOD'] ?? '') || !current_user_can('manager_certificate_assignment') ) {
            wp_die(esc_html__('Sorry, you are not allowed to access this page.', 'wp-certificates'), 403);
        }
        check_admin_referer('wpc_issue_certificates');

        $student_ids = isset($_POST['student_ids']) && is_array($_POST['student_ids']) ? array_values(array_unique(array_filter(array_map('absint', $_POST['student_ids'])))) : [];
        $certificate_id = isset($_POST['certificate_id']) && is_scalar($_POST['certificate_id']) ? absint($_POST['certificate_id']) : 0;
        // El documento tiene que ser uno de los que ofrece la pantalla (activos)
        if ( $certificate_id && !in_array($certificate_id, array_map(static fn($document) => (int) $document->id, get_documents_certificates()), true) ) {
            $certificate_id = 0;
        }
        
        // emission_date es DATE: día de la institución (ADR 0017 de Edusof)
        $emission_date = current_time('Y-m-d');

        // Nadie firma por otro (EduSystem, ADR 0003 paso 10): los documentos que exigen firma se emiten para firma
        // desde la ficha del estudiante, no aquí. Siempre: las firmas-imagen se retiraron (ADR 0004)
        $certificate_document = $certificate_id && function_exists('get_document_detail') ? get_document_detail($certificate_id) : null;
        if ( $certificate_document && !empty($certificate_document->signature_required) ) {
            setcookie('message-error', __('This document requires signatures: it cannot be issued here. Configure its signers and issue it for signature from the student file; each responsible person signs from their own account.', 'wp-certificates'), time() + 10, '/');
            wp_redirect(admin_url('admin.php?page=admin_certificate_assignment_content'));
            exit;
        }

        // Validación rápida de datos requeridos
        if ( empty($student_ids) || empty($certificate_id) ) {
            $error_message = __('An error occurred while trying to issue the certificate', 'wp-certificates');
            setcookie('message-error', $error_message, time() + 10, '/');
            wp_redirect(admin_url('admin.php?page=admin_certificate_assignment_content'));
            exit;
        }

        $failed = [];
        foreach ( $student_ids as $student_id ) {
            
            // Ejecutamos tu función por cada ID de estudiante
            $assign_certificate_student = assign_certificate_student(
                $student_id, 
                $certificate_id, 
                'download_certificate', 
                $emission_date
            );

            if ( !$assign_certificate_student ) {
                $failed[ $student_id ] = $student_id;
            }
        }

        if( $failed ) {

            // Texto plano (el aviso se muestra escapado): «(id) nombre» separados por punto y coma
            $student_failed = [];
            foreach( $failed as $student_id ) {
                $student = WPC_get_student( $student_id );
                $student_failed[] = trim("($student_id) " . ($student ? trim(preg_replace('/\s+/', ' ', "{$student->name} {$student->middle_name} {$student->last_name} {$student->middle_last_name}")) : ''));
            }
            $error_message = __('There have been problems issuing certificates to the following students:', 'wp-certificates') . ' ' . implode('; ', $student_failed);
            setcookie('message-error', $error_message, time() + 10, '/');

        } else {
            setcookie('message', __('The certificates have been successfully issued', 'wp-certificates'), time() + 3600, '/');
        }

        wp_redirect(admin_url('admin.php?page=admin_certificate_assignment_content'));
        exit;

    } else {
        $students = WPC_get_students() ?? [];
        $documents_certificates = get_documents_certificates() ?? [];
        include(plugin_dir_path(__FILE__) . 'templates/certificate-assignment.php');
    }
}


class TT_certificates_all_List_Table extends WP_List_Table {

    function __construct()
    {
        global $status, $page, $categories;

        parent::__construct(
            array(
                'singular' => 'academic_projection_',
                'plural' => 'academic_projection_s',
                'ajax' => true
            )
        );

    }

    function column_default($item, $column_name)
    {

        global $current_user;

        switch ($column_name) {
            // Fechas con el formato y el idioma del sitio (Ajustes > Generales)
            case 'expiration_date':
                if ($item[$column_name]) {
                    return '<span>' . esc_html(mysql2date(get_option('date_format'), $item[$column_name])) . '</span>';
                } else {
                    return '<span>' . esc_html__('No expiry', 'wp-certificates') . '</span>';
                }
            case 'emission_date':
                if ($item[$column_name]) {
                    return '<span>' . esc_html(mysql2date(get_option('date_format'), $item[$column_name])) . '</span>';
                } else {
                    return '<span>—</span>';
                }
            // case 'view_details':
            //     return "<a href='" . admin_url('/admin.php?page=add_admin_form_academic_projection_content&section_tab=academic_projection_details&projection_id=' . $item['academic_projection_id']) . "' class='button button-primary'>" . esc_html__('View Details', 'wp-certificates') . "</a>";
            default:
                // Escapado: el nombre puede venir del perfil de una cuenta de WordPress (titular wp_user), que edita su dueño
                return '<span class="text-uppercase">' . esc_html((string) $item[$column_name]) . '</span>';
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
            'name_document' => esc_html__('Document', 'wp-certificates'),
            'simple_uuid' => esc_html__('Code', 'wp-certificates'),
            'user' => esc_html__('Holder', 'wp-certificates'),
            // 'email' => esc_html__('Email', 'wp-certificates'),
            'emission_date' => esc_html__('Emission date', 'wp-certificates'),
            'expiration_date' => esc_html__('Expiration date', 'wp-certificates'),
            // 'view_details' => esc_html__('Actions', 'wp-certificates'),
        );

        return $columns;
    }

    function get_certificates()
    {
        global $wpdb;
        $table_certificates = $wpdb->prefix . 'certificates';

        // PAGINATION
        $per_page = 20; // number of items per page
        $pagenum = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
        $offset = (($pagenum - 1) * $per_page);
        // PAGINATION

        $certifications = $wpdb->get_results("SELECT SQL_CALC_FOUND_ROWS * FROM {$table_certificates} ORDER BY created_at DESC LIMIT {$per_page} OFFSET {$offset}", "ARRAY_A");
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

        $data_certificates = $this->get_certificates();

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

function WPC_get_students() {
    global $wpdb;
    if (!wpc_edusystem_active()) {
        return [];
    }
    $students = $wpdb->get_results("SELECT * FROM `{$wpdb->prefix}students`") ?? [];
    return $students;
}

function WPC_get_student( $id ) {
    global $wpdb;
    if (!wpc_edusystem_active()) {
        return [];
    }
    $students = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$wpdb->prefix}students` WHERE id = %d", absint($id))) ?? [];
    return $students;
}

