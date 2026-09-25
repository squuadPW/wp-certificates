<?php

function get_certificates_books_list(array $args = []): array
{
    $response = function_exists('edusof_get_books') ? edusof_get_books($args) : [];

    if (!is_array($response)) return [];

    if (isset($response['data']['data']) && is_array($response['data']['data'])) {
        return array_values($response['data']['data']);
    }

    if (isset($response['data']) && is_array($response['data']) && !isset($response['data']['meta'])) {
        return array_values($response['data']);
    }

    if (is_array($response) && array_keys($response) !== range(0, count($response) - 1)) return [];

    return array_values($response);
}

function add_admin_form_documents_content()
{

    if (isset($_GET['section_tab']) && !empty($_GET['section_tab'])) {
        if ($_GET['section_tab'] == 'document_detail') {
            global $wpdb;
            $document_id = $_GET['document_id'];
            $document = get_document_detail($document_id);
            $variables = get_variables_documents();
            $books = get_certificates_books_list();
            $all_documents_html = [];
            $document_html = '';
            ob_start(); // Iniciar la captura de salida

            // Renderizar Header
            if (!empty($document->header)) {
                echo '<div class="automatic-document-header">';
                // El uso de eval permite ejecutar código PHP incrustado en el header
                eval ('?>' . $document->header . '<?php ');
                echo '</div>';
            }

            // Renderizar Content
            if (!empty($document->content)) {
                echo '<div class="automatic-document-content">';
                eval ('?>' . $document->content . '<?php ');
                echo '</div>';
            }

            // Renderizar Footer
            if (!empty($document->footer)) {
                echo '<div class="automatic-document-footer">';
                eval ('?>' . $document->footer . '<?php ');
                echo '</div>';
            }

            $document_html = ob_get_clean(); // Finalizar la captura y guardar el HTML renderizado
            $all_documents_html[] = $document_html;
            $html_document = implode('<hr class="document-separator">', $all_documents_html);
            include(plugin_dir_path(__FILE__) . 'templates/document-detail.php');
        }
    } else {
        if (isset($_GET['action']) && $_GET['action'] == 'save_document') {
            global $wpdb;
            $table_documents_certificates = $wpdb->prefix . 'documents_certificates';
            $table_student_documents = $wpdb->prefix . 'student_documents';
            $table_students = $wpdb->prefix . 'students';
            $table_users_signatures = $wpdb->prefix . 'users_signatures';

            // --- 1. Saneamiento y Conversión de Entradas ---
            $document_id = isset($_POST['document_id']) ? absint($_POST['document_id']) : 0;
            $title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';
            // El identificador se usa en la tabla de estudiantes, mantenemos el uso de strtoupper
            $document_identificator = isset($_POST['document_identificator']) ? strtoupper(sanitize_title($_POST['document_identificator'])) : '';

            // Saneamiento de otros campos
            $orientation = isset($_POST['orientation']) ? sanitize_text_field($_POST['orientation']) : '';
            $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : '';
            $paper_format = isset($_POST['paper_format']) ? sanitize_text_field($_POST['paper_format']) : '';
            $unit = isset($_POST['unit']) ? sanitize_text_field($_POST['unit']) : '';
            $id_requisito = isset($_POST['id_requisito']) ? sanitize_text_field($_POST['id_requisito']) : '';
            $type_file = isset($_POST['type_file']) ? sanitize_text_field($_POST['type_file']) : '';
            $book = isset($_POST['book']) ? absint($_POST['book']) : 0;

            // Convertir a valores binarios (0 o 1)
            $status = isset($_POST['status']) && $_POST['status'] === 'on' ? 1 : 0;
            $isRequired = (isset($_POST['is_required']) && $_POST['is_required'] === 'on') && $type == 'automatic' ? 1 : 0;
            $isVisible = (isset($_POST['is_visible']) && $_POST['is_visible'] === 'on') && $type == 'automatic' ? 1 : 0;
            $deleteSignatures = isset($_POST['delete_signatures']) && $_POST['delete_signatures'] === 'on' ? 1 : 0;
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
            );

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

            // --- 3. Ejecución del Lote de Documentos/Firmas de Estudiantes (Optimización N+1) ---
            // La variable de identificación para las tablas relacionadas (documentos de estudiantes y firmas)
            $document_identifier_for_related_tables = $document_identificator; // Asumimos que la tabla usa el IDENTIFICATOR de texto.

            // a) Consulta OPTIMIZADA de estudiantes. Solo necesitamos ID, email y partner_id.
            $students = $wpdb->get_results("SELECT id, email, partner_id FROM {$table_students} ORDER BY id DESC");

            if (!empty($students)) {

                // b) Obtención OPTIMIZADA de User IDs por email
                $student_emails = array_column($students, 'email');
                $wp_user_ids = [];

                if (!empty($student_emails)) {

                    // 1. Crear los placeholders de formato (%s) para la cláusula IN
                    $email_placeholders = implode(', ', array_fill(0, count($student_emails), '%s'));

                    // 2. Construir la consulta de forma segura
                    $sql_select_user_ids = "SELECT ID FROM {$wpdb->users} 
                                    WHERE user_email IN ({$email_placeholders})";

                    // 3. Ejecutar la consulta inyectando los emails como un array
                    $user_query_results = $wpdb->get_results(
                        $wpdb->prepare($sql_select_user_ids, $student_emails),
                        ARRAY_A
                    );

                    // 4. Mapear los resultados
                    $wp_user_ids = array_column($user_query_results, 'ID');
                }
                // c) Mapeo de IDs
                $student_ids_to_process = array_column($students, 'id');
                $partner_ids_to_process = array_filter(array_column($students, 'partner_id')); // Filtra 0s o vacíos

                $format_in = implode(', ', array_fill(0, count($student_ids_to_process), '%d'));
                $format_in_parent = implode(', ', array_fill(0, count($partner_ids_to_process), '%d'));
                $format_in_student_users = implode(', ', array_fill(0, count($wp_user_ids), '%d'));

                if ($deleteSignatures) {
                    // 1. Bulk Delete student documents
                    $wpdb->query($wpdb->prepare(
                        "DELETE FROM {$table_student_documents}
                WHERE document_id = %s
                AND student_id IN ({$format_in})",
                        array_merge([$document_identifier_for_related_tables], $student_ids_to_process)
                    ));

                    // 2. Bulk Delete parent/partner signatures
                    if (!empty($partner_ids_to_process)) {
                        $wpdb->query($wpdb->prepare(
                            "DELETE FROM {$table_users_signatures}
                    WHERE document_id = %s
                    AND user_id IN ({$format_in_parent})",
                            array_merge([$document_identifier_for_related_tables], $partner_ids_to_process)
                        ));
                    }

                    // 3. Bulk Delete student signatures
                    if (!empty($wp_user_ids)) {
                        $wpdb->query($wpdb->prepare(
                            "DELETE FROM {$table_users_signatures}
                    WHERE document_id = %s
                    AND user_id IN ({$format_in_student_users})",
                            array_merge([$document_identifier_for_related_tables], $wp_user_ids)
                        ));
                    }
                }

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
            if ($document_id > 0) {
                $redirect_url = admin_url('/admin.php?page=add_admin_form_documents_content&section_tab=document_detail&document_id=' . $redirect_id);
            } else {
                $redirect_url = admin_url('/admin.php?page=add_admin_form_documents_content&document_id=' . $redirect_id);
            }

            wp_redirect($redirect_url);
            exit;
        } else if (isset($_GET['action']) && $_GET['action'] == 'delete_document') {
            global $wpdb;
            $table_documents_certificates = $wpdb->prefix . 'documents_certificates';
            $document_id = $_GET['document_id'];
            $wpdb->delete($table_documents_certificates, ['id' => $document_id]);

            setcookie('message', esc_html__('Document deleted successfully.', 'wp-certificates'), time() + 3600, '/');
            wp_redirect(admin_url('/admin.php?page=add_admin_form_documents_content'));
            exit;
        } else {
            // $card = get_card_detail(1);
            // include(plugin_dir_path(__FILE__) . 'templates/card-detail.php');
            $list_documents = new TT_Documents_Certificates_List_Table;
            $list_documents->prepare_items();
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
                return $item[$column_name] == 1 ? '<span style="color: green">Active</span>' : '<span style="color: red">Inactive</span>';
            case 'view_details':
                $html = "<a href='" . admin_url('/admin.php?page=add_admin_form_documents_content&section_tab=document_detail&document_id=' . $item['id']) . "' class='button button-primary'>" . esc_html__('View Details', 'wp-certificates') . "</a>";
                $html .= '<a style="margin-left: 10px" href="' . admin_url('admin.php?page=add_admin_form_documents_content&action=delete_document&document_id=' . $item['id']) . '" class="button button-danger" onclick="return confirm(\'Are you sure?\');"><span class="dashicons dashicons-trash"></span></a>';
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

function get_document_detail($id) {
    global $wpdb;
    $table_documents_certificates = $wpdb->prefix . 'documents_certificates';
    $document = $wpdb->get_row("SELECT * FROM {$table_documents_certificates} WHERE id = {$id}");
    return $document;
}