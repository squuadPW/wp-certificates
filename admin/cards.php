<?php

function add_admin_form_cards_content()
{

    if (isset($_GET['section_tab']) && !empty($_GET['section_tab'])) {
        if ($_GET['section_tab'] == 'certification_detail') {
            global $wpdb;
            include(plugin_dir_path(__FILE__) . 'templates/card-detail.php');
        }
    } else {

        if (isset($_GET['action']) && $_GET['action'] == 'save_card') {
            global $wpdb;
            $table_cards_templates = $wpdb->prefix . 'cards_templates';
            $card_id = $_POST['card_id'];
            $name = $_POST['name'];

            if (isset($_FILES['main_side']) && !empty($_FILES['main_side'])) {
                $file_temp_main = $_FILES['main_side'];
            } else {
                $file_temp = [];
            }

            if (isset($_FILES['rear_side']) && !empty($_FILES['rear_side'])) {
                $file_temp_rear = $_FILES['rear_side'];
            } else {
                $file_temp = [];
            }

            if (!empty($file_temp_main['tmp_name'])) {
                $upload_data = wp_handle_upload($file_temp_main, array('test_form' => FALSE));
                if ($upload_data && !is_wp_error($upload_data)) {
                    $main_side_attach = upload_file_attchment($upload_data, 'MAIN SIDE');
                }
            }

            if (!empty($file_temp_rear['tmp_name'])) {
                $upload_data = wp_handle_upload($file_temp_rear, array('test_form' => FALSE));
                if ($upload_data && !is_wp_error($upload_data)) {
                    $rear_side_attach = upload_file_attchment($upload_data, 'REAR SIDE');
                }
            }

            if ($main_side_attach && $rear_side_attach) {
                $wpdb->update($table_cards_templates, ['main_side_file' => $main_side_attach, 'rear_side_file' => $rear_side_attach], ['id' => $card_id]);
            }
            setcookie('message', esc_html__('Card adjusted successfully.', 'wp-certificates'), time() + 3600, '/');
            wp_redirect(admin_url('/admin.php?page=add_admin_form_cards_content'));
            exit;
        } else if (isset($_GET['action']) && $_GET['action'] == 'delete_card') {
            setcookie('message', esc_html__('Certification deleted successfully.', 'wp-certificates'), time() + 3600, '/');
            wp_redirect(admin_url('/admin.php?page=add_admin_form_cards_content'));
            exit;
        } else {
            $card = get_card_detail(1);
            include(plugin_dir_path(__FILE__) . 'templates/card-detail.php');
            // $list_certificates = new TT_Cards_List_Table;
            // $list_certificates->prepare_items();
            // include(plugin_dir_path(__FILE__) . 'templates/list-cards.php');
        }
    }
}

class TT_Cards_List_Table extends WP_List_Table
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
                return "<a href='" . admin_url('/admin.php?page=add_admin_form_academic_projection_content&section_tab=academic_projection_details&projection_id=' . $item['academic_projection_id']) . "' class='button button-primary'>" . esc_html__('View Details', 'wp-certificates') . "</a>";
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
            'certificate' => esc_html__('Certificate', 'wp-certificates'),
            'user' => esc_html__('User', 'wp-certificates'),
            'view_details' => esc_html__('Actions', 'wp-certificates'),
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

        $certifications = $wpdb->get_results("SELECT SQL_CALC_FOUND_ROWS * FROM {$table_certificates} LIMIT {$per_page} OFFSET {$offset}", "ARRAY_A");
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

function default_templates_cards()
{
    global $wpdb;
    $table_cards_templates = $wpdb->prefix . 'cards_templates';
    $rows = $wpdb->get_results("SELECT * FROM {$table_cards_templates}", "ARRAY_A");

    if (count($rows) == 0) {
        $wpdb->insert($table_cards_templates, [
            'name' => 'Default card'
        ]);
    }
}

function get_card_detail($card_id)
{
    global $wpdb;
    $table_cards_templates = $wpdb->prefix . 'cards_templates';
    $card = $wpdb->get_row("SELECT * FROM {$table_cards_templates} WHERE id = {$card_id}");
    return $card;
}

function upload_file_attchment($upload_data, $document_name)
{
    $attachment = array(
        'post_mime_type' => $upload_data['type'],
        'post_title' => $document_name,
        'post_content' => '',
        'post_status' => 'inherit'
    );

    $attach_id = wp_insert_attachment($attachment, $upload_data['file']);
    $attach_data = wp_generate_attachment_metadata($attach_id, $upload_data['file']);
    wp_update_attachment_metadata($attach_id, $attach_data);
    return $attach_id;
}