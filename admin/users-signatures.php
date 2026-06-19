<?php

function add_admin_form_users_signatures_certificate_list_content()
{

    if (isset($_GET['section_tab']) && !empty($_GET['section_tab'])) {
        if ($_GET['section_tab'] == 'user_signature_detail') {
            global $wpdb;
            $signature_id = $_GET['signature_id'];
            $signature = get_user_signature_detail($signature_id);
            $users = get_users();
            include(plugin_dir_path(__FILE__) . 'templates/user-signatures-detail.php');
        }
    } else {

        if (isset($_GET['action']) && $_GET['action'] == 'save_user_signature') {
            global $wpdb;
            $table_users_signatures_certificate = $wpdb->prefix . 'users_signatures_certificate';

            // Sanitizar entradas
            $signature_id = isset($_POST['signature_id']) ? intval($_POST['signature_id']) : 0;
            $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
            $charge = isset($_POST['charge']) ? sanitize_text_field($_POST['charge']) : '';
            $attach_id = isset($_POST['attach_id']) ? intval($_POST['attach_id']) : 0;

            // Validar user_id
            if ($user_id <= 0) {
                setcookie('message', esc_html__('Invalid user.', 'wp-certificates'), time() + 3600, '/');
                wp_redirect(admin_url('/admin.php?page=add_admin_form_users_signatures_certificate_list_content'));
                exit;
            }

            // Verificar firma existente
            if ($signature_id) {
                // Modo actualización: verificar si otro registro usa el mismo user_id
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $table_users_signatures_certificate 
                    WHERE user_id = %d AND id != %d",
                    $user_id,
                    $signature_id
                ));
            } else {
                // Modo inserción: verificar si el user_id ya existe
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $table_users_signatures_certificate 
                    WHERE user_id = %d",
                    $user_id
                ));
            }

            if ($existing > 0) {
                setcookie('message-error', esc_html__('Error: User already has a signature.', 'wp-certificates'), time() + 3600, '/');
                wp_redirect(admin_url('/admin.php?page=add_admin_form_users_signatures_certificate_list_content'));
                exit;
            }

            // Procesar firma subida
            if (isset($_FILES['signature']) && !empty($_FILES['signature']['tmp_name'])) {
                $upload_data = wp_handle_upload($_FILES['signature'], array('test_form' => false));

                if (!is_wp_error($upload_data) && $upload_data) {
                    $new_attach_id = upload_file_attchment($upload_data, 'SIGNATURE');
                    if ($new_attach_id) {
                        $attach_id = $new_attach_id;
                    }
                }
            }

            // Actualizar o insertar
            if ($signature_id) {
                $wpdb->update(
                    $table_users_signatures_certificate,
                    array(
                        'user_id' => $user_id,
                        'charge' => $charge,
                        'attach_id' => $attach_id
                    ),
                    array('id' => $signature_id)
                );
            } else {
                $wpdb->insert(
                    $table_users_signatures_certificate,
                    array(
                        'user_id' => $user_id,
                        'charge' => $charge,
                        'attach_id' => $attach_id
                    )
                );
            }

            setcookie('message', esc_html__('Signature adjusted successfully.', 'wp-certificates'), time() + 3600, '/');
            wp_redirect(admin_url('/admin.php?page=add_admin_form_users_signatures_certificate_list_content'));
            exit;
        }
        if (isset($_GET['action']) && $_GET['action'] == 'delete_user_signature') {
            global $wpdb;
            $table_users_signatures_certificate = $wpdb->prefix . 'users_signatures_certificate';
            $signature_id = $_GET['signature_id'];
            $wpdb->delete($table_users_signatures_certificate, ['id' => $signature_id]);

            setcookie('message', esc_html__('Signature deleted successfully.', 'wp-certificates'), time() + 3600, '/');
            wp_redirect(admin_url('/admin.php?page=add_admin_form_users_signatures_certificate_list_content'));
            exit;
        } else {
            $list_user_signatures = new TT_Users_Signatures_Certificate_List_Table;
            $list_user_signatures->prepare_items();
            include(plugin_dir_path(__FILE__) . 'templates/list-user-signatures.php');
        }
    }
}

class TT_Users_Signatures_Certificate_List_Table extends WP_List_Table
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
            case 'view_details':
                $html = "<a href='" . admin_url('/admin.php?page=add_admin_form_users_signatures_certificate_list_content&section_tab=user_signature_detail&signature_id=' . $item['id']) . "' class='button button-primary'>" . esc_html__('View Details', 'wp-certificates') . "</a>";
                $html .= '<a style="margin-left: 10px" href="' . admin_url('admin.php?page=add_admin_form_users_signatures_certificate_list_content&action=delete_user_signature&signature_id=' . $item['id']) . '" class="button button-danger" onclick="return confirm(\'Are you sure?\');"><span class="dashicons dashicons-trash"></span></a>';
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
            'user' => esc_html__('User', 'wp-certificates'),
            'charge' => esc_html__('User charge', 'wp-certificates'),
            'view_details' => esc_html__('Actions', 'wp-certificates'),
        );

        return $columns;
    }

    function get_certificates()
    {
        global $wpdb;
        $table_users_signatures_certificate = $wpdb->prefix . 'users_signatures_certificate';

        // PAGINATION
        $per_page = 20; // number of items per page
        $pagenum = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
        $offset = (($pagenum - 1) * $per_page);
        // PAGINATION

        $certifications = $wpdb->get_results("SELECT SQL_CALC_FOUND_ROWS * FROM {$table_users_signatures_certificate} LIMIT {$per_page} OFFSET {$offset}", "ARRAY_A");
        $total_count = $wpdb->get_var("SELECT FOUND_ROWS()");

        foreach ($certifications as $key => $cert) {
            $user = get_user_by('id', $cert['user_id']);
            $certifications[$key]['user'] = $user->first_name . ' ' . $user->last_name . ' (' . $user->user_email . ')';
        }

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

function get_user_signature_detail($id)
{
    global $wpdb;
    $table_users_signatures_certificate = $wpdb->prefix . 'users_signatures_certificate';
    $signature = $wpdb->get_row("SELECT * FROM {$table_users_signatures_certificate} WHERE id = {$id}");
    return $signature;
}

function get_users_signatures_certificates()
{
    global $wpdb;
    $table_users_signatures_certificate = $wpdb->prefix . 'users_signatures_certificate';
    $signatures = $wpdb->get_results("SELECT * FROM {$table_users_signatures_certificate}");
    return $signatures;
}