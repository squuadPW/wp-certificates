<?php
/**
 * EduSystem - Campos adicionales de los documentos (wp-certificates).
 *
 * Cada documento puede definir campos (texto, área de texto, radio, casillas, lista) en su columna
 * `fields` (JSON). Antes de generar el documento se piden en un formulario y las respuestas solo
 * se usan para rellenarlo (quedan en el PDF generado). Si el documento se firma en dos tiempos
 * (representante y estudiante por separado), se guardan con la primera firma parcial en
 * users_signatures.document_fields para que el segundo firmante vea el mismo documento, y se borran
 * al subir el PDF final.
 *
 * En el documento se usan como {{clave}} (la respuesta) y, en los campos con opciones,
 * {{clave_list}} (todas las opciones con (✓) en las elegidas).
 */

if (!defined('ABSPATH')) exit;

/**
 * Tipos de campo admitidos.
 */
function squuad_cert_document_field_types()
{
    return [
        'text' => __('Text', 'edusystem'),
        'textarea' => __('Text area', 'edusystem'),
        'radio' => __('Single choice (radio)', 'edusystem'),
        'checkbox' => __('Multiple choice (checkboxes)', 'edusystem'),
        'select' => __('Drop-down list', 'edusystem'),
    ];
}

/**
 * Tipos de campo que tienen opciones.
 */
function squuad_cert_document_field_has_options($type)
{
    return in_array($type, ['radio', 'checkbox', 'select'], true);
}

/**
 * Texto plano de una etiqueta, opción o respuesta. sanitize_text_field() convierte «<» en «&lt;»;
 * aquí se guarda el texto tal cual (se escapa al mostrarlo), para que «< 5 years» coincida con lo
 * que envía el navegador.
 */
function squuad_cert_document_field_text($text, $multiline = false)
{
    if (!is_string($text)) {
        return '';
    }
    $text = $multiline ? sanitize_textarea_field($text) : sanitize_text_field($text);

    return trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

/**
 * Formato válido de una clave: empieza por letra; solo minúsculas, números y «_».
 */
function squuad_cert_document_field_key_is_valid($key)
{
    return is_string($key) && (bool) preg_match('/^[a-z][a-z0-9_]*$/', $key);
}

/**
 * Claves que no puede usar un campo porque ya son variables del sistema: las fijas de
 * get_replacements_variables() (includes/document-variables.php) y las de la tabla variables_document.
 * Solo se usa al guardar en el admin; al rellenar el documento ganan siempre las del sistema.
 */
function squuad_cert_document_fields_reserved_keys()
{
    global $wpdb;
    static $reserved = null;

    if (null === $reserved) {
        $reserved = [
            'user_sign', 'position_user_charge', 'signature', 'show_parent_info', 'document_name', 'document_code',
            'academic_year', 'address', 'admission_requirements_table', 'admission_signature_fgu', 'birth_date',
            'career_mention', 'city', 'country', 'created_at', 'educational_background_information', 'email',
            'end_academic_year', 'end_term_student_entered', 'ethinicity_selected', 'fax', 'folio', 'gender',
            'id_student', 'initial_academic_cut', 'institute_address', 'institute_name', 'institute_phone',
            'institution_city_country_form_filled', 'institution_graduation_year_form_filled',
            'institution_name_form_filled', 'institution_title_obtained_form_filled', 'language_selected',
            'last_name', 'missing_documents', 'nacionality', 'name', 'name_term_student_entered', 'new_table_notes', 'other_phone',
            'page_break', 'parent_cell', 'parent_email', 'parent_full_name', 'parent_identification',
            'payment_balance_check', 'payment_full_year_check', 'payment_method_table', 'payment_plan_table',
            'phone', 'program', 'qrcode', 'race', 'requires_parent_signature', 'requires_student_signature',
            'signature', 'signature_parent', 'signature_section', 'signature_student', 'start_academic_year', 'student_is_own_parent',
            'start_term_student_entered', 'state', 'student_name', 'subjects_enrolled', 'subjects_enrolled_spanish',
            'table_inscriptions', 'table_notes', 'table_notes_period', 'table_notes_summary', 'today', 'tomo',
            'tomo_folio', 'year_term_student_entered', 'zip_code',
        ];
        $table_variables = $wpdb->prefix . 'variables_document';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_variables)) === $table_variables) {
            $reserved = array_merge($reserved, $wpdb->get_col("SELECT identificator FROM {$table_variables}"));
        }
    }

    return $reserved;
}

/**
 * Limpia y valida la definición de campos que llega del formulario del admin.
 * Devuelve [campos válidos, errores]. Las filas inválidas se descartan con su error.
 *
 * @param array $rows Filas con label, key, type, options (texto, una por línea) y required.
 */
function squuad_cert_sanitize_document_fields($rows)
{
    $fields = [];
    $errors = [];
    $reserved = squuad_cert_document_fields_reserved_keys();

    foreach ((array) $rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $label = squuad_cert_document_field_text($row['label'] ?? '');
        $key = is_string($row['key'] ?? null) ? sanitize_key($row['key']) : '';
        $type = is_string($row['type'] ?? null) ? $row['type'] : 'text';

        if ('' === $label && '' === $key) {
            continue; // fila vacía
        }
        if ('' === $label) {
            $errors[] = sprintf(__('The field "%s" needs a label.', 'edusystem'), $key);
            continue;
        }
        $derived = '' === $key;
        if ($derived) {
            $key = str_replace('-', '_', sanitize_title($label));
        }
        $key = str_replace('-', '_', $key);

        // Clave derivada de una etiqueta sin letras latinas («¿?», «年级»): se pide que la escriban
        if ('' === $key || ($derived && !squuad_cert_document_field_key_is_valid($key))) {
            $errors[] = sprintf(__('The field "%s" needs a key (lowercase letters, numbers and _).', 'edusystem'), $label);
            continue;
        }
        if (!squuad_cert_document_field_key_is_valid($key)) {
            $errors[] = sprintf(__('The key "%s" must start with a letter and only use lowercase letters, numbers and _.', 'edusystem'), $key);
            continue;
        }
        if (!array_key_exists($type, squuad_cert_document_field_types())) {
            $errors[] = sprintf(__('The field "%s" has an invalid type.', 'edusystem'), $label);
            continue;
        }
        if (in_array($key, $reserved, true) || in_array($key . '_list', $reserved, true)) {
            $errors[] = sprintf(__('The key "%s" is already a system variable; choose another one.', 'edusystem'), $key);
            continue;
        }
        // Un campo «x» también ocupa la variable {{x_list}}: tampoco puede existir otro campo «x_list»
        if (isset($fields[$key]) || isset($fields[$key . '_list']) || isset($fields[preg_replace('/_list$/', '', $key)])) {
            $errors[] = sprintf(__('The key "%s" is repeated.', 'edusystem'), $key);
            continue;
        }

        $options = [];
        if (squuad_cert_document_field_has_options($type)) {
            foreach (preg_split('/\r\n|\r|\n/', (string) ($row['options'] ?? '')) as $option) {
                $option = squuad_cert_document_field_text($option);
                if ('' !== $option && !in_array($option, $options, true)) {
                    $options[] = $option;
                }
            }
            if (!$options) {
                $errors[] = sprintf(__('The field "%s" needs at least one option (one per line).', 'edusystem'), $label);
                continue;
            }
        }

        $fields[$key] = [
            'key' => $key,
            'label' => $label,
            'type' => $type,
            'options' => $options,
            'required' => !empty($row['required']),
        ];
    }

    return [array_values($fields), $errors];
}

/**
 * Campos definidos en un documento (objeto o array con la columna `fields`).
 */
function squuad_cert_get_document_fields($document)
{
    $json = is_object($document) ? ($document->fields ?? '') : ($document['fields'] ?? '');
    if (empty($json)) {
        return [];
    }

    $fields = json_decode($json, true);
    if (!is_array($fields)) {
        return [];
    }

    // Se vuelve a validar al leer: la columna podría haberse editado a mano. Las claves reservadas no se
    // comprueban aquí (evita consultas en cada página): al rellenar el documento ganan las variables del sistema.
    // html_entity_decode(): definiciones guardadas antes con «&lt;» en etiquetas u opciones.
    $valid = [];
    foreach ($fields as $field) {
        if (!is_array($field)) {
            continue;
        }
        $key = $field['key'] ?? '';
        $type = $field['type'] ?? '';
        if (!squuad_cert_document_field_key_is_valid($key) || !is_string($type) || !array_key_exists($type, squuad_cert_document_field_types())) {
            continue;
        }
        $options = [];
        foreach ((array) ($field['options'] ?? []) as $option) {
            if (is_scalar($option)) {
                $options[] = html_entity_decode((string) $option, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        $valid[] = [
            'key' => $key,
            'label' => html_entity_decode(is_scalar($field['label'] ?? null) ? (string) $field['label'] : $key, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'type' => $type,
            'options' => array_values(array_unique($options)),
            'required' => !empty($field['required']),
        ];
    }

    return $valid;
}

/**
 * HTML de los campos para un formulario. Los nombres son document_fields[clave]
 * (document_fields[clave][] en las casillas). $values rellena el formulario (p. ej. tras un error).
 */
function squuad_cert_render_document_fields($fields, $values = [])
{
    $html = '';

    foreach ($fields as $field) {
        $key = $field['key'];
        $id = 'document-field-' . $key;
        $name = 'document_fields[' . $key . ']';
        $value = $values[$key] ?? ($field['type'] === 'checkbox' ? [] : '');
        $required_mark = $field['required'] ? '<span class="required">*</span>' : '';
        $required_attr = $field['required'] ? ' required' : '';

        $html .= '<div class="document-field" style="margin-bottom: 1.25rem">';

        if (in_array($field['type'], ['radio', 'checkbox'], true)) {
            $role = 'radio' === $field['type'] ? 'radiogroup' : 'group';
            $html .= '<div role="' . $role . '" aria-labelledby="' . esc_attr($id) . '-label"'
                . ($field['required'] ? ' aria-required="true"' : '') . '>';
            $html .= '<p id="' . esc_attr($id) . '-label" style="font-weight: 600; margin: 0 0 0.5rem">' . esc_html($field['label']) . $required_mark . '</p>';
            $html .= '<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 0.5rem 1rem">';
            foreach ($field['options'] as $option) {
                $checked = 'radio' === $field['type'] ? $value === $option : in_array($option, (array) $value, true);
                $html .= '<label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer">'
                    . '<input type="' . $field['type'] . '" name="' . esc_attr($name) . ('checkbox' === $field['type'] ? '[]' : '') . '"'
                    . ' value="' . esc_attr($option) . '"' . checked($checked, true, false)
                    . ('radio' === $field['type'] ? $required_attr : '') . '> '
                    . esc_html($option) . '</label>';
            }
            $html .= '</div></div>';
        } else {
            $html .= '<label for="' . esc_attr($id) . '" style="display: block; font-weight: 600; margin-bottom: 0.5rem">' . esc_html($field['label']) . $required_mark . '</label>';
            if ('textarea' === $field['type']) {
                $html .= '<textarea class="form-control" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" rows="4"' . $required_attr . '>' . esc_textarea($value) . '</textarea>';
            } elseif ('select' === $field['type']) {
                $html .= '<select class="form-control" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '"' . $required_attr . '>';
                $html .= '<option value="">' . esc_html__('Select an option', 'edusystem') . '</option>';
                foreach ($field['options'] as $option) {
                    $html .= '<option value="' . esc_attr($option) . '"' . selected($value, $option, false) . '>' . esc_html($option) . '</option>';
                }
                $html .= '</select>';
            } else {
                $html .= '<input type="text" class="form-control" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"' . $required_attr . '>';
            }
        }

        $html .= '</div>';
    }

    return $html;
}

/**
 * Valida las respuestas enviadas (sin barras: pasar wp_unslash($_POST['document_fields'])).
 * Devuelve [respuestas, errores]. Las opciones solo se aceptan si están en la definición.
 */
function squuad_cert_document_fields_values($fields, $input)
{
    $values = [];
    $errors = [];
    $input = is_array($input) ? $input : [];

    foreach ($fields as $field) {
        $key = $field['key'];
        $raw = $input[$key] ?? null;

        switch ($field['type']) {
            case 'textarea':
                $value = squuad_cert_document_field_text($raw, true);
                break;
            case 'checkbox':
                $value = array_values(array_intersect($field['options'], array_filter((array) $raw, 'is_string')));
                break;
            case 'radio':
            case 'select':
                $value = is_string($raw) && in_array($raw, $field['options'], true) ? $raw : '';
                break;
            default:
                $value = squuad_cert_document_field_text($raw);
        }

        if ($field['required'] && (is_array($value) ? !$value : '' === trim($value))) {
            $errors[$key] = sprintf(__('"%s" is required.', 'edusystem'), $field['label']);
        }
        $values[$key] = $value;
    }

    return [$values, $errors];
}

/**
 * Variables para process_template() a partir de las respuestas: {{clave}} y, en los campos con
 * opciones, {{clave_list}}. Los valores van escapados.
 */
function squuad_cert_document_fields_replacements($fields, $values)
{
    $replacements = [];

    foreach ($fields as $field) {
        $key = $field['key'];
        $value = $values[$key] ?? '';

        if ('textarea' === $field['type']) {
            $text = nl2br(esc_html($value));
        } else {
            $text = esc_html(is_array($value) ? implode(', ', $value) : $value);
        }
        $replacements[$key] = ['value' => $text, 'wrap' => false];

        if (squuad_cert_document_field_has_options($field['type'])) {
            $items = [];
            foreach ($field['options'] as $option) {
                $selected = is_array($value) ? in_array($option, $value, true) : $value === $option;
                $items[] = '<span>' . ($selected ? '(✓)' : '( )') . ' ' . esc_html($option) . '</span>';
            }
            $replacements[$key . '_list'] = ['value' => implode(' ', $items), 'wrap' => false];
        }
    }

    return $replacements;
}

/**
 * Documento automático de wp-certificates por su identificador (document_identificator), o null.
 */
function squuad_cert_get_automatic_document_by_identificator($identificator)
{
    global $wpdb;

    if (!is_string($identificator) || '' === $identificator) {
        return null;
    }
    $table = $wpdb->prefix . 'documents_certificates';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
        return null;
    }

    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE document_identificator = %s AND `type` = 'automatic' ORDER BY id ASC LIMIT 1",
        $identificator
    ));
}

/**
 * La columna users_signatures.document_fields llega con la versión 3 del esquema (core/schema.php).
 */
function squuad_cert_signatures_store_document_fields()
{
    return version_compare((string) get_option('edusystem_db_version'), '3', '>=');
}

/**
 * Respuestas guardadas con una firma parcial del documento (de cualquiera de los usuarios dados),
 * validadas otra vez con la definición actual. Devuelve null si no hay respuestas válidas.
 */
function squuad_cert_document_fields_stored_values($document, $fields, $user_ids)
{
    global $wpdb;

    $user_ids = array_values(array_filter(array_map('intval', (array) $user_ids)));
    if (!$fields || !$user_ids || !squuad_cert_signatures_store_document_fields()) {
        return null;
    }

    $table = $wpdb->prefix . 'users_signatures';
    $placeholders = implode(',', array_fill(0, count($user_ids), '%d'));
    $json = $wpdb->get_var($wpdb->prepare(
        "SELECT document_fields FROM {$table}
        WHERE document_id = %s AND user_id IN ({$placeholders}) AND document_fields IS NOT NULL AND document_fields <> ''
        ORDER BY id ASC LIMIT 1",
        array_merge([$document->document_identificator], $user_ids)
    ));
    $input = $json ? json_decode($json, true) : null;
    if (!is_array($input)) {
        return null;
    }

    [$values, $errors] = squuad_cert_document_fields_values($fields, $input);

    return $errors ? null : $values;
}

/**
 * Variables de los campos con respuestas vacías, para las vías que generan el documento sin pasar por el
 * formulario (admin, plantillas): así no queda el texto literal {{clave}} ni {{clave_list}}.
 */
function squuad_cert_document_fields_empty_replacements($document)
{
    return $document ? squuad_cert_document_fields_replacements(squuad_cert_get_document_fields($document), []) : [];
}
