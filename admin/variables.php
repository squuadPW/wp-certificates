<?php
declare(strict_types=1);

/**
 * Certificación > Variables: las variables del catálogo guardadas en la base de datos ({prefix}variables_document).
 * Solo quien tiene squuad_cert_manage_variables (por defecto, el administrator). Permite ver, editar y eliminar cada variable.
 *
 * Esta tabla solo es la LISTA que se muestra al escribir una plantilla: editar o eliminar una fila no cambia cómo se
 * calcula la variable al generar un documento (Antigravity/variable.md de EduSystem).
 *
 * ADR 0005 de EduSystem: cada variable puede tener un MÉTODO, elegido con un selector entre los que registran los
 * plugins propios activados (nunca código ni nombres de función escritos a mano). Aquí también se activan los plugins
 * propios que pueden aportar métodos y se rehabilitan los métodos en cuarentena.
 */

defined('ABSPATH') || exit;

const SQUUAD_CERT_VARIABLES_PAGE = 'squuad-cert-variables';
const SQUUAD_CERT_VARIABLE_TYPES = ['all', 'document', 'email'];

add_action('admin_menu', 'squuad_cert_variables_menu', 20);
function squuad_cert_variables_menu(): void
{
    if ('expired' === get_option('site_status_subscription')) {
        return;
    }
    add_submenu_page(
        'add_admin_form_certificates_content',
        esc_html__('Variables', 'wp-certificates'),
        esc_html__('Variables', 'wp-certificates'),
        'squuad_cert_manage_variables',
        SQUUAD_CERT_VARIABLES_PAGE,
        'squuad_cert_variables_page'
    );
}

function squuad_cert_variables_table(): string
{
    global $wpdb;

    return $wpdb->prefix . 'variables_document';
}

function squuad_cert_variable_get(int $id): ?object
{
    global $wpdb;
    $row = $id ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . squuad_cert_variables_table() . ' WHERE id = %d', $id)) : null;

    return $row ?: null;
}

/** Documentos cuyo texto usa la variable ({{clave}} o un bloque {{#clave}} / {{^clave}}). */
function squuad_cert_variable_documents(string $identificator): array
{
    global $wpdb;
    if ('' === $identificator) {
        return [];
    }
    $like = static fn(string $tag): string => '%' . $wpdb->esc_like($tag) . '%';
    $where = [];
    $args = [];
    foreach (['{{' . $identificator . '}}', '{{#' . $identificator . '}}', '{{^' . $identificator . '}}'] as $tag) {
        foreach (['header', 'content', 'footer'] as $column) {
            $where[] = "`{$column}` LIKE %s";
            $args[] = $like($tag);
        }
    }

    return $wpdb->get_results($wpdb->prepare(
        "SELECT id, title, document_identificator, status FROM {$wpdb->prefix}documents_certificates WHERE " . implode(' OR ', $where) . ' ORDER BY title',
        $args
    ));
}

function squuad_cert_variables_url(array $args = []): string
{
    return add_query_arg(array_merge(['page' => SQUUAD_CERT_VARIABLES_PAGE], $args), admin_url('admin.php'));
}

/** Aviso de la última acción (se guarda por usuario unos minutos y se muestra una vez). */
function squuad_cert_variables_notice(?string $message = null, bool $ok = true): ?array
{
    $key = 'squuad_cert_variables_notice_' . get_current_user_id();
    if (null !== $message) {
        set_transient($key, ['message' => $message, 'ok' => $ok], 5 * MINUTE_IN_SECONDS);
        return null;
    }
    $notice = get_transient($key);
    if (false !== $notice) {
        delete_transient($key);
    }

    return is_array($notice) ? $notice : null;
}

function squuad_cert_variables_page(): void
{
    if (!current_user_can('squuad_cert_manage_variables')) {
        wp_die(esc_html__('Sorry, you are not allowed to access this page.', 'wp-certificates'), 403);
    }
    global $wpdb;

    $view = sanitize_key($_GET['view'] ?? '');
    $variable = squuad_cert_variable_get(absint($_GET['id'] ?? 0));
    if (in_array($view, ['view', 'edit'], true) && !$variable) {
        $view = '';
    }
    if (!in_array($view, ['', 'view', 'edit', 'new'], true)) {
        $view = '';
    }
    $methods = \Squuad\Certificados\VariableMethods::all();
    $available = squuad_cert_variable_methods();
    $own_plugins = \Squuad\Certificados\OwnPlugins::all();
    $enabled_plugins = \Squuad\Certificados\OwnPlugins::enabled();
    $quarantine = \Squuad\Certificados\Quarantine::all();
    $variables = '' === $view ? $wpdb->get_results('SELECT * FROM ' . squuad_cert_variables_table() . ' ORDER BY id ASC') : [];
    $documents = $variable ? squuad_cert_variable_documents((string) $variable->identificator) : [];
    $notice = squuad_cert_variables_notice();

    include WP_C_PATH . 'admin/templates/variables.php';
}

add_action('admin_post_squuad_cert_variable_save', 'squuad_cert_variable_save_handle');
function squuad_cert_variable_save_handle(): void
{
    if (!current_user_can('squuad_cert_manage_variables')) {
        wp_die(esc_html__('Sorry, you are not allowed to access this page.', 'wp-certificates'), 403);
    }
    $id = absint($_POST['id'] ?? 0);
    check_admin_referer('squuad_cert_variable_save_' . $id);
    global $wpdb;

    $variable = squuad_cert_variable_get($id);
    if (!$variable) {
        squuad_cert_variables_notice(__('The variable does not exist.', 'wp-certificates'), false);
        wp_safe_redirect(squuad_cert_variables_url());
        exit;
    }
    $text = sanitize_text_field(wp_unslash($_POST['text'] ?? ''));
    $visual = sanitize_text_field(wp_unslash($_POST['visual'] ?? ''));
    $type = sanitize_key($_POST['type'] ?? '');
    if ('' === $text || '' === $visual || !in_array($type, SQUUAD_CERT_VARIABLE_TYPES, true)) {
        squuad_cert_variables_notice(__('Description, how it is written and where it is offered are required.', 'wp-certificates'), false);
        wp_safe_redirect(squuad_cert_variables_url(['view' => 'edit', 'id' => $id]));
        exit;
    }
    $method = squuad_cert_variable_method_from_post();
    if (null === $method) {
        squuad_cert_variables_notice(__('Choose a method from the list.', 'wp-certificates'), false);
        wp_safe_redirect(squuad_cert_variables_url(['view' => 'edit', 'id' => $id]));
        exit;
    }

    $wpdb->update(squuad_cert_variables_table(), ['text' => $text, 'visual' => $visual, 'type' => $type, 'method' => '' === $method ? null : $method], ['id' => $id], ['%s', '%s', '%s', '%s'], ['%d']);
    squuad_cert_log(
        sprintf('Variable %s (%d) editada: descripción «%s» → «%s», escritura «%s» → «%s», tipo %s → %s, método %s → %s', $variable->identificator, $id, $variable->text, $text, $variable->visual, $visual, $variable->type, $type, $variable->method ?: '—', $method ?: '—'),
        'variable_edited'
    );
    squuad_cert_variables_notice(__('Variable saved.', 'wp-certificates'));
    wp_safe_redirect(squuad_cert_variables_url(['view' => 'view', 'id' => $id]));
    exit;
}

add_action('admin_post_squuad_cert_variable_delete', 'squuad_cert_variable_delete_handle');
function squuad_cert_variable_delete_handle(): void
{
    if (!current_user_can('squuad_cert_manage_variables')) {
        wp_die(esc_html__('Sorry, you are not allowed to access this page.', 'wp-certificates'), 403);
    }
    $id = absint($_POST['id'] ?? 0);
    check_admin_referer('squuad_cert_variable_delete_' . $id);
    global $wpdb;

    $variable = squuad_cert_variable_get($id);
    if ($variable) {
        $wpdb->delete(squuad_cert_variables_table(), ['id' => $id], ['%d']);
        squuad_cert_log(
            sprintf('Variable %s (%d) eliminada del catálogo: «%s», %s, tipo %s', $variable->identificator, $id, $variable->text, $variable->visual, $variable->type),
            'variable_deleted',
            ['row' => (array) $variable]
        );
        squuad_cert_variables_notice(sprintf(
            /* translators: %s: variable, e.g. {{program}} */
            __('Variable %s deleted from the list.', 'wp-certificates'),
            (string) $variable->visual
        ));
    }
    wp_safe_redirect(squuad_cert_variables_url());
    exit;
}

/**
 * Método elegido en el formulario: '' (sin método), el identificador si está disponible, o null si no es válido.
 * Nunca se acepta un nombre de función escrito a mano: solo identificadores del registro (ADR 0005, regla 3).
 */
function squuad_cert_variable_method_from_post(): ?string
{
    $method = sanitize_text_field(wp_unslash($_POST['method'] ?? ''));
    if ('' === $method) {
        return '';
    }

    return isset(squuad_cert_variable_methods()[$method]) ? $method : null;
}

add_action('admin_post_squuad_cert_variable_create', 'squuad_cert_variable_create_handle');
function squuad_cert_variable_create_handle(): void
{
    if (!current_user_can('squuad_cert_manage_variables')) {
        wp_die(esc_html__('Sorry, you are not allowed to access this page.', 'wp-certificates'), 403);
    }
    check_admin_referer('squuad_cert_variable_create');
    global $wpdb;

    $back = squuad_cert_variables_url(['view' => 'new']);
    $key = sanitize_key($_POST['identificator'] ?? '');
    $text = sanitize_text_field(wp_unslash($_POST['text'] ?? ''));
    $type = sanitize_key($_POST['type'] ?? '');
    $method = squuad_cert_variable_method_from_post();
    $general = array_map(static fn($tag) => trim((string) $tag, '{}'), array_keys(\Squuad\Certificados\Variables::general()));

    if (!preg_match('/^[a-z][a-z0-9_]{1,59}$/', $key)) {
        $error = __('The key must start with a letter and contain only lowercase letters, numbers and underscores.', 'wp-certificates');
    } elseif ($wpdb->get_var($wpdb->prepare('SELECT id FROM ' . squuad_cert_variables_table() . ' WHERE identificator = %s', $key))) {
        $error = __('There is already a variable with that key.', 'wp-certificates');
    } elseif (in_array($key, $general, true)) {
        $error = __('That key is a general variable of WP Certificates.', 'wp-certificates');
    } elseif ('' === $text || !in_array($type, SQUUAD_CERT_VARIABLE_TYPES, true)) {
        $error = __('Description and where it is offered are required.', 'wp-certificates');
    } elseif (null === $method || '' === $method) {
        $error = __('Choose a method from the list.', 'wp-certificates');
    }
    if (!empty($error)) {
        squuad_cert_variables_notice($error, false);
        wp_safe_redirect($back);
        exit;
    }

    $wpdb->insert(squuad_cert_variables_table(), [
        'text' => $text,
        'visual' => '{{' . $key . '}}',
        'identificator' => $key,
        'type' => $type,
        'method' => $method,
        'created_at' => current_time('mysql'),
    ], ['%s', '%s', '%s', '%s', '%s', '%s']);
    $id = (int) $wpdb->insert_id;
    squuad_cert_log(sprintf('Variable %s (%d) creada con el método %s', $key, $id, $method), 'variable_created');
    squuad_cert_variables_notice(__('Variable created.', 'wp-certificates'));
    wp_safe_redirect(squuad_cert_variables_url(['view' => 'view', 'id' => $id]));
    exit;
}

add_action('admin_post_squuad_cert_variables_link', 'squuad_cert_variables_link_handle');
function squuad_cert_variables_link_handle(): void
{
    if (!current_user_can('squuad_cert_manage_variables')) {
        wp_die(esc_html__('Sorry, you are not allowed to access this page.', 'wp-certificates'), 403);
    }
    check_admin_referer('squuad_cert_variables_link');
    $linked = \Squuad\Certificados\Variables::link_by_key();
    squuad_cert_variables_notice(sprintf(
        /* translators: %d: number of variables */
        __('%d variables linked to the method with the same key.', 'wp-certificates'),
        $linked
    ));
    wp_safe_redirect(squuad_cert_variables_url());
    exit;
}

add_action('admin_post_squuad_cert_method_plugins', 'squuad_cert_method_plugins_handle');
function squuad_cert_method_plugins_handle(): void
{
    if (!current_user_can('squuad_cert_manage_variables')) {
        wp_die(esc_html__('Sorry, you are not allowed to access this page.', 'wp-certificates'), 403);
    }
    check_admin_referer('squuad_cert_method_plugins');

    $before = \Squuad\Certificados\OwnPlugins::enabled();
    $after = \Squuad\Certificados\OwnPlugins::set_enabled(array_map('sanitize_text_field', (array) wp_unslash($_POST['plugins'] ?? [])));
    squuad_cert_log(sprintf('Plugins propios que aportan métodos de variables: %s → %s', implode(', ', $before) ?: '—', implode(', ', $after) ?: '—'), 'variable_method_plugins');
    squuad_cert_variables_notice(__('Plugins saved.', 'wp-certificates'));
    wp_safe_redirect(squuad_cert_variables_url());
    exit;
}

add_action('admin_post_squuad_cert_method_release', 'squuad_cert_method_release_handle');
function squuad_cert_method_release_handle(): void
{
    if (!current_user_can('squuad_cert_manage_variables')) {
        wp_die(esc_html__('Sorry, you are not allowed to access this page.', 'wp-certificates'), 403);
    }
    $method = sanitize_text_field(wp_unslash($_POST['method'] ?? ''));
    check_admin_referer('squuad_cert_method_release_' . $method);
    if (\Squuad\Certificados\Quarantine::release($method)) {
        squuad_cert_variables_notice(sprintf(
            /* translators: %s: method identifier */
            __('Method %s enabled again.', 'wp-certificates'),
            $method
        ));
    }
    wp_safe_redirect(squuad_cert_variables_url());
    exit;
}
