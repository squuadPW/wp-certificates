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

function add_admin_form_certificates_templates_content(){

    if (isset($_GET['section_tab']) && !empty($_GET['section_tab'])) {
        if ($_GET['section_tab'] == 'template_certificate_detail') {
            global $wpdb;
            $table_certificates_templates = $wpdb->prefix . 'certificates_templates';
            $template_id = $_GET['template_id'];
            $template = $wpdb->get_row("SELECT * FROM {$table_certificates_templates} WHERE id = {$template_id}");
            include(plugin_dir_path(__FILE__) . 'templates/template-certificates-detail.php');
        }
    } else {

        if (isset($_GET['action']) && $_GET['action'] == 'save_template_certificate') {
            global $wpdb;
            $table_certificates_templates = $wpdb->prefix . 'certificates_templates';
            $template_id = $_POST['template_id'];
            $is_active = $_POST['is_active'];
            $content = $_POST['content'] ?? [];
            $certificate = get_certificate_details($template_id);
            $certificate_fields_obj = json_decode($certificate->fields);

            foreach ($certificate_fields_obj as $key => $value) {
                $certificate_fields_obj[$key]->content = $content[$key] ?? '';
            }

            $wpdb->update($table_certificates_templates, [
                'is_active' => $is_active == 'on' ? 1 : 0,
                'fields' => json_encode($certificate_fields_obj),
            ], ['id' => $template_id]);
            setcookie('message', esc_html__('Template adjusted successfully.', 'wp-certificates'), time() + 3600, '/');
            wp_redirect(admin_url('/admin.php?page=add_admin_form_certificates_templates_content&section_tab=template_certificate_detail&template_id=' . $template_id));
            exit;
        } else if (isset($_GET['action']) && $_GET['action'] == 'delete_inscription') {
            setcookie('message', esc_html__('Template deleted successfully.', 'wp-certificates'), time() + 3600, '/');
            wp_redirect(admin_url('/admin.php?page=add_admin_form_certificates_templates_content'));
            exit;
        } else {
            $list_certificates = new TT_template_certificates_all_List_Table;
            $list_certificates->prepare_items();
            include(plugin_dir_path(__FILE__) . 'templates/list-template-certificates.php');
        }
    }
}

function admin_certificate_assignment_content () {

    if( isset($_GET['action']) && $_GET['action'] == 'generate_certificates' ) {
        
        $student_ids = isset($_POST['student_ids']) ? array_map('intval', $_POST['student_ids']) : [];
        $certificate_id = isset($_POST['certificate_id']) ? intval($_POST['certificate_id']) : 0;
        
        // Fecha de hoy con formato de base de datos usando la hora local de WP
        $emission_date = current_time('mysql'); 

        // Validación rápida de datos requeridos
        if ( empty($student_ids) || empty($certificate_id) ) {
            $error_message = __('An error occurred while trying to issue the certificate', 'wp-certificates') . $student_failed;
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

            $student_failed = '';
            foreach( $failed as $student_id ) {
                $student = WPC_get_student( $student_id );

                $student_failed .= "<br>( $student_id ) {$student->name} {$student->middle_name} {$student->last_name} {$student->middle_last_name}";
            }
            $error_message = __('There have been problems issuing certificates to the following students:', 'wp-certificates') . $student_failed;
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
            case 'expiration_date':
                if ($item[$column_name]) {
                    $expiration_date = DateTime::createFromFormat('Y-m-d', $item[$column_name]);
                    return '<span>' . $expiration_date->format('M, Y') . '</span>';
                } else {
                    return '<span class="text-uppercase">N/A</span>';
                }
            case 'emission_date':
                if ($item[$column_name]) {
                    $emission_date = DateTime::createFromFormat('Y-m-d', $item[$column_name]);
                    return '<span>' . $emission_date->format('M, Y') . '</span>';
                } else {
                    return '<span class="text-uppercase">N/A</span>';
                }
            // case 'view_details':
            //     return "<a href='" . admin_url('/admin.php?page=add_admin_form_academic_projection_content&section_tab=academic_projection_details&projection_id=' . $item['academic_projection_id']) . "' class='button button-primary'>" . esc_html__('View Details', 'wp-certificates') . "</a>";
            default:
                return '<span class="text-uppercase">' . $item[$column_name] . '</span>';
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
            'user' => esc_html__('User', 'wp-certificates'),
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

class TT_template_certificates_all_List_Table extends WP_List_Table {

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
            // case 'view_details':
            //     return "<a href='" . admin_url('/admin.php?page=add_admin_form_certificates_templates_content&section_tab=template_certificate_detail&template_id=' . $item['id']) . "' class='button button-primary'>" . esc_html__('View Details', 'wp-certificates') . "</a>";
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
            'name' => esc_html__('Name of template', 'wp-certificates'),
            'description' => esc_html__('Description', 'wp-certificates'),
            'is_active' => esc_html__('Active', 'wp-certificates'),
            // 'view_details' => esc_html__('Actions', 'wp-certificates'),
        );

        return $columns;
    }

    function get_certificates_templates()
    {
        global $wpdb;
        $table_certificates_templates = $wpdb->prefix . 'certificates_templates';

        // PAGINATION
        $per_page = 20; // number of items per page
        $pagenum = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
        $offset = (($pagenum - 1) * $per_page);
        // PAGINATION

        $certifications = $wpdb->get_results("SELECT SQL_CALC_FOUND_ROWS * FROM {$table_certificates_templates} ORDER BY id DESC LIMIT {$per_page} OFFSET {$offset}", "ARRAY_A");
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

        $data_certificates = $this->get_certificates_templates();

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

function default_templates($return_fields = false) {
    global $wpdb;
    $table_certificates_templates = $wpdb->prefix . 'certificates_templates';
    $rows = $wpdb->get_results("SELECT * FROM {$table_certificates_templates}", "ARRAY_A");

    if (count($rows) == 0) {
       $fields = [
            [
                'id' => 'title',
                'position' => 'Title',
                'content' => 'Certificate of Educational Partnership',
                'is_visible' => true
            ],
            [
                'id' => 'subtitle',
                'position' => 'Subtitle',
                'content' => 'This certificate is to acknowledge that:',
                'is_visible' => true
            ],
            [
                'id' => 'user',
                'position' => 'User',
                'content' => '',
                'is_visible' => false
            ],
            [
                'id' => 'description',
                'position' => 'Description',
                'content' => 'Is a certified member of the dual degree educational agreement endorsed by American Elite School. The institution has fullfiled all the necessary requeriments of the State of Florida and our school, to be recognized as an authorized member affiliated',
                'is_visible' => true
            ],
            [
                'id' => 'text_on_another_side',
                'position' => 'Text on another side',
                'content' => '',
                'is_visible' => true
            ]
        ];

        if ($return_fields) {
            return $fields;
        }
        $wpdb->insert($table_certificates_templates, [
            'name' => 'Default template',
            'description' => 'Example template',
            'is_active' => 1,
            'fields' => json_encode($fields)
        ]);
    }
}

function get_certificate_details($template_id) {
    global $wpdb;
    $table_certificates_templates = $wpdb->prefix . 'certificates_templates';
    $template = $wpdb->get_row("SELECT * FROM {$table_certificates_templates} WHERE id={$template_id}");
    return $template;
}

function WPC_get_students() {
    global $wpdb;
    $students = $wpdb->get_results("SELECT * FROM `{$wpdb->prefix}students`") ?? [];
    return $students;
}

function WPC_get_student( $id ) {
    global $wpdb;
    $students = $wpdb->get_row("SELECT * FROM `{$wpdb->prefix}students` WHERE id={$id}") ?? [];
    return $students;
}

