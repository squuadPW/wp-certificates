<?php
/**
 * EduSystem - Migración única: quitar el PHP de los documentos de wp-certificates.
 *
 * El documento automático se mostraba con eval() de su contenido, lo que permitía ejecutar
 * código en el servidor a quien pudiera editar documentos. Ya no se evalúa: este archivo
 * convierte el PHP conocido de header/content/footer a variables {{...}} y bloques
 * {{#show_parent_info}}...{{/show_parent_info}} (ver get_replacements_variables()).
 * Si queda PHP que no se reconoce, se guarda la lista y se avisa en el admin.
 *
 * Versión 2: el «último grado completado» ya no se marca con onclick dentro del documento. Pasa a ser
 * un campo adicional del documento (columna `fields` de wp-certificates, ver includes/document-fields.php)
 * que se pide antes de mostrarlo; el bloque de grados se convierte a {{last_completed_grade_list}}.
 */

if (!defined('ABSPATH')) exit;

/**
 * Convierte el PHP conocido de un HTML de documento a variables. Devuelve el HTML convertido,
 * o el original sin tocar si alguna expresión regular falla (p. ej. límite de pila del JIT de PCRE
 * con bloques grandes): así la migración nunca guarda un campo vacío.
 */
function squuad_cert_convert_document_php($html)
{
    if (strpos($html, '<?') === false) {
        return $html;
    }

    $patterns = [
        // «echo $user['parent_cell']» y similares  →  {{parent_cell}}
        '/<\?php\s+echo\s+\$user\[\s*[\'"](parent_cell|parent_identification|parent_full_name|parent_email)[\'"]\s*\]\s*;?\s*\?>/' => '{{$1}}',
        '/<\?php\s+echo\s+isset\(\s*\$institute\s*\)\s*\?\s*\$institute->name\s*:\s*\$institute_name\s*;?\s*\?>/' => '{{institute_name}}',
        '/<\?php\s+echo\s+isset\(\s*\$institute\s*\)\s*\?\s*\$institute->address\s*:\s*\'\'\s*;?\s*\?>/' => '{{institute_address}}',
        '/<\?php\s+echo\s+isset\(\s*\$institute\s*\)\s*\?\s*\$institute->phone\s*:\s*\'\'\s*;?\s*\?>/' => '{{institute_phone}}',
        '/<\?php\s+echo\s+\$user\[\s*\'student_payment\'\s*\]\s*==\s*\'2\'\s*\?\s*\'✓\'\s*:\s*\'[^\']*\'\s*;?\s*\?>/u' => '{{payment_full_year_check}}',
        '/<\?php\s+echo\s+\$user\[\s*\'student_payment\'\s*\]\s*==\s*\'1\'\s*\?\s*\'✓\'\s*:\s*\'[^\']*\'\s*;?\s*\?>/u' => '{{payment_balance_check}}',
    ];

    $converted = preg_replace(array_keys($patterns), array_values($patterns), $html);
    if (!is_string($converted)) {
        return $html;
    }

    // Después (ya sin echo dentro): bloque «if ($show_parent_info == 1) { ... }» de PHP  →  {{#show_parent_info}} ... {{/show_parent_info}}
    // Si dentro queda otro PHP (no reconocido) el bloque se deja como está y el documento queda pendiente.
    $converted = preg_replace_callback(
        '/<\?php\s+if\s*\(\s*\$show_parent_info\s*==\s*1\s*\)\s*\{\s*\?>(.*?)<\?php\s*\}\s*\?>/s',
        function ($match) {
            return strpos($match[1], '<?php') === false ? '{{#show_parent_info}}' . $match[1] . '{{/show_parent_info}}' : $match[0];
        },
        $converted
    );

    return is_string($converted) ? $converted : $html;
}

/**
 * Sustituye el bloque de grados con onclick="updateGrade(...)" por {{last_completed_grade_list}}.
 * Devuelve el HTML original si no encuentra el bloque completo o si la expresión falla.
 */
function squuad_cert_convert_document_grade($html)
{
    if (strpos($html, 'updateGrade(') === false) {
        return $html;
    }

    $converted = preg_replace(
        '/<strong id="select_grade">(.*?)<\/strong>\s*<br>\s*'
        . '(?:<span onclick="updateGrade\(\'grade\d+\'\)"[^>]*>\s*<span id="grade\d+">\(\s*\)<\/span>[^<]*<\/span>\s*)+'
        . '(?:<br>\s*<br>\s*)?<strong id="please_select_grade"[^>]*>.*?<\/strong>/s',
        '<strong>$1</strong> <br>' . "\n" . '{{last_completed_grade_list}}' . "\n" . '<br><br>',
        $html
    );

    return is_string($converted) ? $converted : $html;
}

/**
 * Campo adicional que sustituye a la selección de grado del documento de inscripción.
 */
function squuad_cert_last_completed_grade_field()
{
    return [
        'key' => 'last_completed_grade',
        'label' => 'Last completed grade',
        'type' => 'radio',
        'options' => ['5th Grade', '6th Grade', '7th Grade', '8th Grade', '9th Grade', '10th Grade', '11th Grade', '12th Grade'],
        'required' => true,
    ];
}

/**
 * Convierte una sola vez los documentos guardados. Corre en init para que el panel del estudiante
 * no muestre un documento sin convertir justo después de actualizar el plugin.
 */
function squuad_cert_migrate_documents_php()
{
    // 'done-2' = versión actual terminada; 'done-2-pending' = terminada sin EduSystem, con sus variables pendientes
    // (squuad_cert_migrate_documents_php_variables()); el 'done' de la versión 1 hace que se vuelva a ejecutar
    $state = get_option('squuad_cert_documents_php_migrated');
    if (in_array($state, ['done-2', SQUUAD_CERT_DOCUMENTS_PHP_VARIABLES_PENDING], true)) {
        return;
    }
    // Candado con la hora de inicio: evita que dos peticiones migren a la vez y, si una ejecución
    // se corta (o la tabla de wp-certificates aún no existe), se reintenta pasados 5 minutos
    if (false === $state) {
        if (!add_option('squuad_cert_documents_php_migrated', time())) {
            return;
        }
    } elseif (time() - (int) $state < 5 * MINUTE_IN_SECONDS) {
        return;
    } else {
        update_option('squuad_cert_documents_php_migrated', time());
    }

    global $wpdb;
    $table = $wpdb->prefix . 'documents_certificates';
    $pending = [];

    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
        return;
    }

    // La columna `fields` la crea wp-certificates (WP_C_DB_VERSION 2). Si aún no existe (plugin sin
    // actualizar), se convierte el PHP pero no el bloque de grados, y se reintenta más tarde.
    $has_fields_column = (bool) $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'fields'");

    $documents = $wpdb->get_results(
        "SELECT * FROM {$table}
         WHERE header LIKE '%<?%' OR content LIKE '%<?%' OR footer LIKE '%<?%'
            OR header LIKE '%updateGrade(%' OR content LIKE '%updateGrade(%' OR footer LIKE '%updateGrade(%'"
    );

    foreach ($documents as $document) {
        $data = [];
        $grade_converted = false;
        foreach (['header', 'content', 'footer'] as $field) {
            $original = (string) $document->$field;
            $converted = squuad_cert_convert_document_php($original);
            if ($has_fields_column) {
                $with_grade = squuad_cert_convert_document_grade($converted);
                $grade_converted = $grade_converted || $with_grade !== $converted;
                $converted = $with_grade;
            }
            if ($converted !== $original) {
                $data[$field] = $converted;
            }
            if (strpos($converted, '<?') !== false) {
                $pending[] = (int) $document->id;
            }
        }
        if ($grade_converted) {
            $fields = squuad_cert_get_document_fields($document);
            $keys = array_column($fields, 'key');
            if (!in_array('last_completed_grade', $keys, true)) {
                $fields[] = squuad_cert_last_completed_grade_field();
            }
            $data['fields'] = wp_json_encode($fields);
        }
        if ($data) {
            $wpdb->update($table, $data, ['id' => $document->id]);
        }
    }

    // Dar a conocer las variables nuevas en la lista «Variables» del formulario de documentos: son de EduSystem, así que
    // solo con EduSystem activo; sin él quedan pendientes y se añaden cuando se active (squuad_cert_migrate_documents_php_variables())
    $variables_pending = !squuad_cert_documents_php_variables_ready();
    if (!$variables_pending) {
        squuad_cert_documents_php_insert_variables();
    }

    if ($pending) {
        update_option('squuad_cert_documents_php_pending', array_values(array_unique($pending)), false);
    } else {
        delete_option('squuad_cert_documents_php_pending');
    }
    // Sin la columna `fields` queda pendiente la conversión del bloque de grados: se reintenta pasados 5 minutos
    if ($has_fields_column) {
        update_option('squuad_cert_documents_php_migrated', $variables_pending ? SQUUAD_CERT_DOCUMENTS_PHP_VARIABLES_PENDING : 'done-2');
    }
}
add_action('init', 'squuad_cert_migrate_documents_php');

/**
 * Estado de squuad_cert_documents_php_migrated cuando la migración terminó sin EduSystem: sus variables se añaden a la
 * lista cuando EduSystem esté activo. Se guarda en la misma opción (autocargada): no cuesta una consulta por petición.
 * Con 'done-2' (también el de la versión anterior, que las añadía siempre) no se vuelve a tocar.
 */
const SQUUAD_CERT_DOCUMENTS_PHP_VARIABLES_PENDING = 'done-2-pending';

/** Variables de EduSystem que se dan a conocer al convertir el PHP: clave => [descripción, cómo se escribe]. */
function squuad_cert_documents_php_variables(): array
{
    return [
        'parent_full_name' => ['Parent/guardian full name', '{{parent_full_name}}'],
        'parent_email' => ['Parent/guardian email', '{{parent_email}}'],
        'parent_cell' => ['Parent/guardian cell phone', '{{parent_cell}}'],
        'parent_identification' => ['Parent/guardian identification', '{{parent_identification}}'],
        'institute_name' => ['School name', '{{institute_name}}'],
        'institute_address' => ['School address', '{{institute_address}}'],
        'institute_phone' => ['School phone', '{{institute_phone}}'],
        'payment_full_year_check' => ['Full year payment mark (✓)', '{{payment_full_year_check}}'],
        'payment_balance_check' => ['Balance payment mark (✓)', '{{payment_balance_check}}'],
        'show_parent_info' => ['Only if the student is not their own guardian', '{{#show_parent_info}} ... {{/show_parent_info}}'],
    ];
}

/** ¿Se pueden añadir ya? Solo con EduSystem activo (sus métodos se registran en plugins_loaded, antes de init). */
function squuad_cert_documents_php_variables_ready(): bool
{
    return function_exists('wpc_edusystem_active') && wpc_edusystem_active();
}

/**
 * Añade a la lista las variables de EduSystem que falten, con su método edusystem.<clave> si está disponible (como
 * las demás, ADR 0005). Las que ya están no se tocan. Sin la tabla no hace nada.
 */
function squuad_cert_documents_php_insert_variables(): void
{
    global $wpdb;
    $table_variables = $wpdb->prefix . 'variables_document';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_variables)) !== $table_variables) {
        return;
    }
    $available = function_exists('squuad_cert_variable_methods') ? squuad_cert_variable_methods() : [];
    $added = [];
    foreach (squuad_cert_documents_php_variables() as $identificator => [$text, $visual]) {
        if ($wpdb->get_var($wpdb->prepare("SELECT id FROM {$table_variables} WHERE identificator = %s", $identificator))) {
            continue;
        }
        $method = 'edusystem.' . $identificator;
        $wpdb->insert($table_variables, [
            'text' => $text,
            'visual' => $visual,
            'identificator' => $identificator,
            'type' => 'all',
            'method' => isset($available[$method]) ? $method : null,
        ]);
        $added[] = $identificator;
    }
    if ($added) {
        squuad_cert_log(sprintf('Variables de EduSystem añadidas a la lista (conversión de documentos PHP): %s', implode(', ', $added)), 'variable_created');
    }
}

/** Migración hecha sin EduSystem: al activarse EduSystem, se añaden sus variables (una vez). */
function squuad_cert_migrate_documents_php_variables(): void
{
    // Primero lo barato (sin EduSystem no hay nada que hacer); la opción está autocargada
    if (!squuad_cert_documents_php_variables_ready() || SQUUAD_CERT_DOCUMENTS_PHP_VARIABLES_PENDING !== get_option('squuad_cert_documents_php_migrated')) {
        return;
    }
    // Sin la tabla tampoco se repite: no hay lista a la que añadirlas
    squuad_cert_documents_php_insert_variables();
    update_option('squuad_cert_documents_php_migrated', 'done-2');
}
add_action('init', 'squuad_cert_migrate_documents_php_variables', 11);

/**
 * Aviso para quien gestiona documentos si alguno conserva PHP que no se pudo convertir
 * (ya no se ejecuta: se mostraría como texto).
 */
function squuad_cert_notice_documents_php_pending()
{
    if (!current_user_can('manager_documents_certificates')) {
        return;
    }
    $pending = get_option('squuad_cert_documents_php_pending');
    if (empty($pending)) {
        return;
    }

    // Volver a comprobar: el aviso desaparece cuando los documentos se corrigen a mano
    global $wpdb;
    $table = $wpdb->prefix . 'documents_certificates';
    $ids = implode(',', array_map('intval', $pending));
    $pending = array_map('intval', $wpdb->get_col(
        "SELECT id FROM {$table} WHERE id IN ({$ids})
         AND (header LIKE '%<?%' OR content LIKE '%<?%' OR footer LIKE '%<?%')"
    ));
    if (empty($pending)) {
        delete_option('squuad_cert_documents_php_pending');
        return;
    }
    update_option('squuad_cert_documents_php_pending', $pending, false);

    echo '<div class="notice notice-warning"><p>' . esc_html(sprintf(
        /* translators: %s: IDs de documentos */
        __('These documents contain PHP code that is no longer executed and must be replaced with variables: %s', 'wp-certificates'),
        implode(', ', array_map('intval', $pending))
    )) . '</p></div>';
}
add_action('admin_notices', 'squuad_cert_notice_documents_php_pending');
