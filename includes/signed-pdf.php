<?php
declare(strict_types=1);

/**
 * PDF finales firmados como adjuntos protegidos (decisión D3 del dueño, 2026-10-04; ADR 0007 de Edusof, «Impacto en
 * producción»).
 *
 * Arreglo rápido de un problema que ya existía: el PDF final de una solicitud se guardaba en uploads con el nombre del
 * documento (predecible) como adjunto público, visible en la API REST de medios y en la biblioteca.
 * - PDF nuevos: nombre aleatorio no predecible (128 bits), adjunto con post_status 'private' y marca
 *   _squuad_cert_signed_pdf (id de la solicitud).
 * - Fuera de la API REST de medios, de la biblioteca, de post.php y del AJAX de adjuntos para quien no tiene permiso de
 *   certificación ni lo subió (map_meta_cap, rest_request_before_callbacks, pre_get_posts y wp_ajax_get-attachment).
 * - Migración idempotente de los ya existentes, por niveles: los final_attachment_id de las solicitudes (v13) y los
 *   PDF firmados del sistema anterior identificados por título y nombre de archivo (v14). Se marcan privados SIN
 *   renombrar ni mover el archivo (las rutas que guarda EduSystem siguen valiendo).
 *
 * Pendiente (arquitecto-plataforma): sacar los PDF de uploads y servirlos con un manejador que compruebe permisos.
 * Hasta entonces la URL directa del archivo sigue respondiendo a quien la conozca; la de los antiguos es adivinable.
 */

defined('ABSPATH') || exit;

/** Marca del adjunto: 'request:<id>' (solicitud de wp-certificates) o 'edusystem:<id>' (tabla antigua de EduSystem). */
const SQUUAD_CERT_SIGNED_PDF_META = '_squuad_cert_signed_pdf';
/** Estado que tenía el adjunto antes de marcarlo privado (para revertir exactamente). */
const SQUUAD_CERT_SIGNED_PDF_PREV_STATUS_META = '_squuad_cert_signed_pdf_prev_status';
/** Opción: nivel de la migración de los adjuntos existentes ya hecho ('1' solicitudes, '2' sistema anterior). */
const SQUUAD_CERT_SIGNED_PDF_MIGRATED_OPTION = 'squuad_cert_signed_pdf_migrated';

/** Nombre aleatorio no predecible para un PDF firmado. */
function squuad_cert_signed_pdf_filename(): string
{
    return 'documento-firmado-' . bin2hex(random_bytes(16)) . '.pdf';
}

/** ¿Puede el usuario actual ver los PDF firmados en la biblioteca y en la REST? Solo con el permiso de certificación (N7). */
function squuad_cert_signed_pdf_can_list(): bool
{
    return current_user_can('manager_certificates');
}

/**
 * Marca un adjunto como PDF firmado privado (idempotente). $marker: 'request:<id>' o 'edusystem:<id>'; no pisa una
 * marca ya puesta. Guarda el estado anterior del adjunto la primera vez que lo cambia. Devuelve true si cambió algo.
 */
function squuad_cert_signed_pdf_protect(int $attachment_id, string $marker): bool
{
    global $wpdb;

    $post = $attachment_id > 0 ? get_post($attachment_id) : null;
    if (!$post || 'attachment' !== $post->post_type) {
        return false;
    }
    $changed = false;
    if ('private' !== $post->post_status) {
        if ('' === (string) get_post_meta($attachment_id, SQUUAD_CERT_SIGNED_PDF_PREV_STATUS_META, true)) {
            update_post_meta($attachment_id, SQUUAD_CERT_SIGNED_PDF_PREV_STATUS_META, (string) $post->post_status);
        }
        // SQL directo: wp_update_post() en un adjunto podría tocar otros campos; aquí solo cambia el estado
        $changed = (bool) $wpdb->update($wpdb->posts, ['post_status' => 'private'], ['ID' => $attachment_id], ['%s'], ['%d']);
        clean_post_cache($attachment_id);
    }
    if ('' === (string) get_post_meta($attachment_id, SQUUAD_CERT_SIGNED_PDF_META, true)) {
        update_post_meta($attachment_id, SQUUAD_CERT_SIGNED_PDF_META, $marker);
        $changed = true;
    }

    return $changed;
}

/**
 * PDF firmados identificables con certeza: [adjunto => marca]. Primero la tabla antigua de EduSystem (solo lectura) y
 * después las solicitudes de wp-certificates, que ganan si el adjunto está en las dos (es su solicitud real).
 *
 * @return array<int, string>
 */
function squuad_cert_signed_pdf_known(): array
{
    global $wpdb;

    $known = [];
    foreach (['edusystem_signature_requests' => 'edusystem', 'squuad_cert_requests' => 'request'] as $table => $kind) {
        $full = $wpdb->prefix . $table;
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $full)) !== $full) {
            continue;
        }
        foreach ((array) $wpdb->get_results("SELECT id, final_attachment_id FROM {$full} WHERE final_attachment_id IS NOT NULL AND final_attachment_id > 0") as $row) {
            $known[(int) $row->final_attachment_id] = $kind . ':' . (int) $row->id;
        }
    }

    return $known;
}

/**
 * Corrige las marcas de la primera versión de esta migración (2026-10-04, solo en los sitios de prueba), que guardaba
 * el id de la solicitud o '1' sin decir de qué tabla, y anota el estado anterior que entonces no se guardaba: 'inherit'
 * para los adjuntos anteriores a la primera pasada (esa versión solo los cambió desde 'inherit') y 'private' para los
 * subidos después (nacieron privados). Idempotente: solo toca marcas numéricas.
 */
function squuad_cert_signed_pdf_fix_markers(): int
{
    global $wpdb;

    $known = squuad_cert_signed_pdf_known();
    $fixed = 0;
    // Hasta la primera pasada de la migración, los adjuntos marcados eran públicos ('inherit'); los subidos después ya
    // nacieron privados
    $first_run = (string) $wpdb->get_var($wpdb->prepare(
        "SELECT MIN(created_at_utc) FROM {$wpdb->prefix}squuad_cert_log WHERE type = %s",
        'signed_pdf_migration'
    ));
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value REGEXP '^[0-9]+$'",
        SQUUAD_CERT_SIGNED_PDF_META
    ));
    foreach ((array) $rows as $row) {
        $attachment_id = (int) $row->post_id;
        $marker = $known[$attachment_id] ?? 'legacy';
        update_post_meta($attachment_id, SQUUAD_CERT_SIGNED_PDF_META, $marker);
        $post = get_post($attachment_id);
        if ($post && '' === (string) get_post_meta($attachment_id, SQUUAD_CERT_SIGNED_PDF_PREV_STATUS_META, true)) {
            $born_private = '' !== $first_run && (string) $post->post_date_gmt > $first_run;
            update_post_meta($attachment_id, SQUUAD_CERT_SIGNED_PDF_PREV_STATUS_META, $born_private ? 'private' : 'inherit');
        }
        $fixed++;
    }
    if ($fixed) {
        squuad_cert_log(sprintf('Marcas de PDF firmados corregidas: %d', $fixed), 'signed_pdf_migration');
    }

    return $fixed;
}

/**
 * meta_query que añade «sin la marca de PDF firmado» a una existente sin cambiar su sentido: las dos se unen con AND
 * (mezclar con array_merge podía heredar una relation OR de la consulta original).
 */
function squuad_cert_signed_pdf_exclude_meta_query($existing): array
{
    $exclude = ['key' => SQUUAD_CERT_SIGNED_PDF_META, 'compare' => 'NOT EXISTS'];
    if (empty($existing) || !is_array($existing)) {
        return [$exclude];
    }

    return ['relation' => 'AND', $existing, $exclude];
}

/** Fuera de la API REST de medios para quien no tiene permiso (además de lo que ya hace WordPress con los privados). */
add_filter('rest_attachment_query', 'squuad_cert_signed_pdf_rest_query', 10, 1);
function squuad_cert_signed_pdf_rest_query($args)
{
    if (!squuad_cert_signed_pdf_can_list()) {
        $args['meta_query'] = squuad_cert_signed_pdf_exclude_meta_query($args['meta_query'] ?? null);
    }

    return $args;
}

/** ¿Es un PDF firmado marcado? */
function squuad_cert_signed_pdf_is_marked(int $attachment_id): bool
{
    return $attachment_id > 0 && '' !== (string) get_post_meta($attachment_id, SQUUAD_CERT_SIGNED_PDF_META, true);
}

/**
 * ¿Puede este usuario acceder a la ficha de este PDF firmado? Con el permiso de certificación, o verla ($write false) si
 * lo subió él. Editarla o borrarla ($write true) solo con el permiso de certificación: un PDF firmado es evidencia.
 */
function squuad_cert_signed_pdf_user_can(int $attachment_id, int $user_id, bool $write = false): bool
{
    if (!squuad_cert_signed_pdf_is_marked($attachment_id)) {
        return true;
    }
    $post = get_post($attachment_id);
    if (!$write && $user_id > 0 && $post && (int) $post->post_author === $user_id) {
        return true;
    }

    return $user_id > 0 && user_can($user_id, 'manager_certificates');
}

/**
 * Permisos de WordPress sobre la ficha de un PDF firmado (read_post, edit_post, delete_post): sin el permiso de
 * certificación no se permite (do_not_allow); quien lo subió solo puede verla. Cubre la REST de medios (GET, POST y DELETE de
 * /wp/v2/media/<id> responden 401/403 limpios), post.php, la biblioteca y el AJAX de adjuntos. No afecta a la URL del
 * archivo (wp_get_attachment_url()), que es lo que usan EduSystem y Admisión.
 */
add_filter('map_meta_cap', 'squuad_cert_signed_pdf_map_meta_cap', 10, 4);
function squuad_cert_signed_pdf_map_meta_cap($caps, $cap, $user_id, $args)
{
    if (!in_array($cap, ['read_post', 'edit_post', 'delete_post'], true) || empty($args[0])) {
        return $caps;
    }
    $post_id = (int) $args[0];
    if ('attachment' !== get_post_type($post_id) || squuad_cert_signed_pdf_user_can($post_id, (int) $user_id, 'read_post' !== $cap)) {
        return $caps;
    }

    return ['do_not_allow'];
}

/**
 * REST /wp/v2/media/<id> (GET, POST, PUT, PATCH, DELETE) de un PDF firmado ajeno: 401 (sin sesión) o 403 limpios
 * antes del controlador. Cubre también un adjunto marcado que volviera a 'inherit' (la REST da por públicos los
 * adjuntos 'inherit' sin padre sin preguntar read_post).
 */
add_filter('rest_request_before_callbacks', 'squuad_cert_signed_pdf_rest_guard', 10, 3);
function squuad_cert_signed_pdf_rest_guard($response, $handler, $request)
{
    if (!$request instanceof WP_REST_Request || is_wp_error($response)
        || !preg_match('#^/wp/v2/media/(\d+)(?:/|$)#', (string) $request->get_route(), $match)) {
        return $response;
    }
    if (!squuad_cert_signed_pdf_user_can((int) $match[1], get_current_user_id(), 'GET' !== $request->get_method() && 'HEAD' !== $request->get_method())) {
        return new WP_Error('rest_forbidden', __('Sorry, you are not allowed to access this page.', 'wp-certificates'), ['status' => rest_authorization_required_code()]);
    }

    return $response;
}

/** AJAX get-attachment (biblioteca): un PDF firmado ajeno responde 403 antes que WordPress (prioridad 0). */
add_action('wp_ajax_get-attachment', 'squuad_cert_signed_pdf_ajax_get_attachment', 0);
function squuad_cert_signed_pdf_ajax_get_attachment(): void
{
    $id = is_scalar($_REQUEST['id'] ?? null) ? absint($_REQUEST['id']) : 0;
    if ($id && !squuad_cert_signed_pdf_user_can($id, get_current_user_id())) {
        wp_send_json_error(null, 403);
    }
}

/** Listado de la biblioteca en modo lista (upload.php?mode=list): sin los PDF firmados para quien no tiene permiso. */
add_action('pre_get_posts', 'squuad_cert_signed_pdf_admin_list_query');
function squuad_cert_signed_pdf_admin_list_query($query): void
{
    if (!is_admin() || !$query instanceof WP_Query || !$query->is_main_query() || squuad_cert_signed_pdf_can_list()) {
        return;
    }
    global $pagenow;
    if ('upload.php' !== $pagenow && 'attachment' !== $query->get('post_type')) {
        return;
    }
    $query->set('meta_query', squuad_cert_signed_pdf_exclude_meta_query($query->get('meta_query')));
}

/** Fuera de la biblioteca de medios para quien no tiene permiso. */
add_filter('ajax_query_attachments_args', 'squuad_cert_signed_pdf_library_query', 10, 1);
function squuad_cert_signed_pdf_library_query($query)
{
    if (!squuad_cert_signed_pdf_can_list()) {
        $query['meta_query'] = squuad_cert_signed_pdf_exclude_meta_query($query['meta_query'] ?? null);
    }

    return $query;
}

/**
 * PDF firmados del sistema de firma anterior (EduSystem, antes de las solicitudes) que ninguna tabla referencia con
 * certeza. Criterio (todas a la vez): adjunto application/pdf; título exacto de los que ponía el navegador al subir el
 * documento firmado («Student Enrollment Agreement.pdf», «Student Missing Document Agreement.pdf», «Student Missing
 * Documents Agreement.pdf» o «<título de un documento automático en minúsculas>.pdf», p. ej. «enrollment.pdf»: los
 * adjuntos subidos a mano no llevan «.pdf» en el título); nombre del archivo = ese título saneado, con o sin sufijo -N
 * (Student-Enrollment-Agreement-12.pdf); sin marca nuestra; y sin la marca _edusystem_student_document (esos los
 * gestiona EduSystem), y que no esté enlazado en {prefix}student_documents ni {prefix}teacher_documents de EduSystem
 * (solo lectura). Una consulta para todos, sin N+1. Devuelve [adjunto => estado actual].
 *
 * @return array<int, string>
 */
function squuad_cert_signed_pdf_legacy_candidates(): array
{
    global $wpdb;

    $titles = ['Student Enrollment Agreement.pdf', 'Student Missing Document Agreement.pdf', 'Student Missing Documents Agreement.pdf'];
    $table = $wpdb->prefix . 'documents_certificates';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
        foreach ((array) $wpdb->get_col("SELECT title FROM {$table} WHERE type = 'automatic'") as $title) {
            $titles[] = strtolower((string) $title) . '.pdf';
        }
    }
    $titles = array_values(array_unique($titles));
    // Los adjuntos que EduSystem enlaza en un requisito (student_documents / teacher_documents) son suyos: nunca entran,
    // aunque esta migración corra antes que la de EduSystem (init frente a admin_init)
    $referenced = '';
    foreach (['student_documents', 'teacher_documents'] as $edu_table) {
        $full = $wpdb->prefix . $edu_table;
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $full)) === $full) {
            $referenced .= " AND NOT EXISTS (SELECT 1 FROM {$full} d WHERE d.attachment_id = p.ID)";
        }
    }
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT p.ID, p.post_status, p.post_title, f.meta_value AS file
         FROM {$wpdb->posts} p
         JOIN {$wpdb->postmeta} f ON f.post_id = p.ID AND f.meta_key = '_wp_attached_file'
         LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s
         LEFT JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = '_edusystem_student_document'
         WHERE p.post_type = 'attachment' AND p.post_mime_type = 'application/pdf' AND s.meta_id IS NULL AND e.meta_id IS NULL
           AND p.post_title IN (" . implode(',', array_fill(0, count($titles), '%s')) . ')' . $referenced,
        array_merge([SQUUAD_CERT_SIGNED_PDF_META], $titles)
    ));
    $candidates = [];
    foreach ((array) $rows as $row) {
        $expected = preg_replace('/\.pdf$/i', '', sanitize_file_name((string) $row->post_title));
        if (preg_match('/^' . preg_quote((string) $expected, '/') . '(-\d+)?\.pdf$/i', basename((string) $row->file))) {
            $candidates[(int) $row->ID] = (string) $row->post_status;
        }
    }

    return $candidates;
}

/**
 * Marca privados los PDF firmados antiguos (squuad_cert_signed_pdf_legacy_candidates()) con la marca 'legacy' y su
 * estado anterior, por lotes de 200 (una consulta de actualización y una de inserción por lote). Idempotente: los ya
 * marcados no vuelven a salir. Devuelve ['found', 'changed'].
 */
function squuad_cert_signed_pdf_migrate_legacy(): array
{
    global $wpdb;

    $candidates = squuad_cert_signed_pdf_legacy_candidates();
    $changed = 0;
    $ok = true;
    foreach (array_chunk($candidates, 200, true) as $chunk) {
        $ids = array_map('intval', array_keys($chunk));
        $in = implode(',', $ids);
        // Primero las marcas (con el estado anterior) y después el estado: si algo falla, no queda un adjunto privado sin
        // marca que permita revertirlo
        $values = [];
        foreach ($chunk as $id => $status) {
            $values[] = $wpdb->prepare('(%d, %s, %s)', $id, SQUUAD_CERT_SIGNED_PDF_META, 'legacy');
            $values[] = $wpdb->prepare('(%d, %s, %s)', $id, SQUUAD_CERT_SIGNED_PDF_PREV_STATUS_META, $status);
        }
        $inserted = $wpdb->query("INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode(',', $values));
        if (false === $inserted) {
            $ok = false;
            break;
        }
        $updated = $wpdb->query("UPDATE {$wpdb->posts} SET post_status = 'private' WHERE ID IN ({$in}) AND post_status <> 'private'");
        foreach ($ids as $id) {
            clean_post_cache($id);
        }
        if (false === $updated) {
            $ok = false;
            break;
        }
        $changed += (int) $updated;
    }
    squuad_cert_log(sprintf(
        'PDF firmados antiguos (sistema de firma anterior) protegidos: %d identificados, %d pasados a privados%s',
        count($candidates),
        $changed,
        $ok ? '' : ' (ERROR de base de datos: se reintentará en la siguiente actualización; ' . $wpdb->last_error . ')'
    ), $ok ? 'signed_pdf_migration' : 'signed_pdf_migration_error');

    return ['found' => count($candidates), 'changed' => $changed, 'ok' => $ok];
}

/**
 * Migración de los PDF firmados ya guardados, sin renombrarlos ni moverlos, por niveles guardados en la opción
 * squuad_cert_signed_pdf_migrated (cada nivel corre una sola vez por sitio):
 * - 1 (esquema v13): los final_attachment_id de las solicitudes (squuad_cert_signed_pdf_known()); solo si la versión
 *   previa del esquema era menor que 13 o es una instalación nueva (en los sitios que ya pasaron por la 13 ya se hizo).
 * - 2 (esquema v14): los PDF firmados del sistema anterior (squuad_cert_signed_pdf_migrate_legacy()).
 * Siempre corrige las marcas antiguas (idempotente). Devuelve ['found', 'changed', 'fixed', 'legacy_found',
 * 'legacy_changed'].
 */
function squuad_cert_signed_pdf_migrate(string $previous_db_version = ''): array
{
    $result = ['found' => 0, 'changed' => 0, 'fixed' => squuad_cert_signed_pdf_fix_markers(), 'legacy_found' => 0, 'legacy_changed' => 0];
    $saved = get_option(SQUUAD_CERT_SIGNED_PDF_MIGRATED_OPTION, '0');
    $level = is_numeric($saved) ? (int) $saved : 1; // una fecha (primera versión de esta opción) = nivel 1

    if ($level < 1) {
        if ('' === $previous_db_version || version_compare($previous_db_version, '13', '<')) {
            $known = squuad_cert_signed_pdf_known();
            foreach ($known as $attachment_id => $marker) {
                $post = get_post($attachment_id);
                if ($post && 'attachment' === $post->post_type && 'application/pdf' === $post->post_mime_type
                    && squuad_cert_signed_pdf_protect($attachment_id, $marker)) {
                    $result['changed']++;
                }
            }
            $result['found'] = count($known);
            squuad_cert_log(sprintf('PDF firmados protegidos (privados, fuera de la API de medios): %d identificados, %d cambiados', $result['found'], $result['changed']), 'signed_pdf_migration');
        }
        $level = 1;
        update_option(SQUUAD_CERT_SIGNED_PDF_MIGRATED_OPTION, '1', false);
    }
    if ($level < 2) {
        $legacy = squuad_cert_signed_pdf_migrate_legacy();
        $result['legacy_found'] = $legacy['found'];
        $result['legacy_changed'] = $legacy['changed'];
        // Si la base de datos falló, el nivel no se da por hecho: lo ya marcado no vuelve a salir y el resto se reintenta
        if ($legacy['ok']) {
            update_option(SQUUAD_CERT_SIGNED_PDF_MIGRATED_OPTION, '2', false);
        }
    }

    return $result;
}

/**
 * Reversión simétrica de la protección de los PDF firmados: devuelve cada adjunto privado marcado a su estado anterior
 * (_squuad_cert_signed_pdf_prev_status) y borra nuestras dos metas. Solo toca adjuntos en 'private' con estado anterior
 * guardado, y nunca los que también lleven marcas de EduSystem (_edusystem_student_document, _edusystem_teacher_document,
 * _edusystem_signature_image): esos los revierte EduSystem. Los PDF nacidos privados (estado anterior 'private' o sin
 * él) se quedan privados. No cambia la opción de la migración salvo con $reset_level (entonces se borra, y la siguiente
 * actualización del esquema volvería a proteger). Uso: `wp squuad-cert pdf revertir` o `wp eval
 * 'print_r(squuad_cert_signed_pdf_revert());'`. Devuelve ['reverted' => int, 'skipped_edusystem' => int].
 */
function squuad_cert_signed_pdf_revert(bool $reset_level = false): array
{
    global $wpdb;

    $edusystem = "SELECT 1 FROM {$wpdb->postmeta} e WHERE e.post_id = p.ID AND e.meta_key IN ('_edusystem_student_document', '_edusystem_teacher_document', '_edusystem_signature_image')";
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT p.ID, prev.meta_value AS prev_status, EXISTS({$edusystem}) AS edusystem
         FROM {$wpdb->posts} p
         JOIN {$wpdb->postmeta} mark ON mark.post_id = p.ID AND mark.meta_key = %s
         JOIN {$wpdb->postmeta} prev ON prev.post_id = p.ID AND prev.meta_key = %s
         WHERE p.post_type = 'attachment' AND p.post_status = 'private'",
        SQUUAD_CERT_SIGNED_PDF_META,
        SQUUAD_CERT_SIGNED_PDF_PREV_STATUS_META
    ));
    $reverted = 0;
    $skipped = 0;
    foreach ((array) $rows as $row) {
        if ((int) $row->edusystem) {
            $skipped++;
            continue;
        }
        $prev = (string) $row->prev_status;
        if ('' !== $prev && 'private' !== $prev) {
            $reverted += (int) $wpdb->update($wpdb->posts, ['post_status' => $prev], ['ID' => (int) $row->ID, 'post_status' => 'private'], ['%s'], ['%d', '%s']);
        }
        delete_post_meta((int) $row->ID, SQUUAD_CERT_SIGNED_PDF_META);
        delete_post_meta((int) $row->ID, SQUUAD_CERT_SIGNED_PDF_PREV_STATUS_META);
        clean_post_cache((int) $row->ID);
    }
    if ($reset_level) {
        delete_option(SQUUAD_CERT_SIGNED_PDF_MIGRATED_OPTION);
    }
    squuad_cert_log(sprintf('Protección de PDF firmados revertida: %d devueltos a su estado anterior, %d omitidos por tener marcas de EduSystem', $reverted, $skipped), 'signed_pdf_revert');

    return ['reverted' => $reverted, 'skipped_edusystem' => $skipped];
}

if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
    WP_CLI::add_command('squuad-cert pdf revertir', static function (array $args, array $assoc): void {
        $result = squuad_cert_signed_pdf_revert(!empty($assoc['reiniciar-nivel']));
        WP_CLI::success(sprintf('%d PDF firmados devueltos a su estado anterior; %d omitidos (marcas de EduSystem).', $result['reverted'], $result['skipped_edusystem']));
    }, [
        'shortdesc' => 'Revierte la protección de los PDF firmados (ADR 0007 de Edusof) usando su estado anterior guardado.',
        'synopsis' => [['type' => 'flag', 'name' => 'reiniciar-nivel', 'optional' => true, 'description' => 'Borra también el nivel de la migración.']],
    ]);
}
