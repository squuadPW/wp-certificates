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
 * - Fuera de la API REST de medios y de la biblioteca para quien no tiene permiso de certificación (además, WordPress
 *   ya no lista los adjuntos privados en la REST ni los muestra a quien no puede leer privados).
 * - Migración idempotente de los ya existentes: solo los identificables con certeza (final_attachment_id de las
 *   solicitudes de wp-certificates y, si existe, de la tabla antigua de EduSystem): se marcan privados SIN renombrar ni
 *   mover el archivo (las rutas que guarda EduSystem siguen valiendo).
 *
 * Pendiente (arquitecto-plataforma): sacar los PDF de uploads y servirlos con un manejador que compruebe permisos.
 * Hasta entonces la URL directa del archivo sigue respondiendo a quien la conozca; la de los antiguos es adivinable.
 */

defined('ABSPATH') || exit;

/** Marca del adjunto: 'request:<id>' (solicitud de wp-certificates) o 'edusystem:<id>' (tabla antigua de EduSystem). */
const SQUUAD_CERT_SIGNED_PDF_META = '_squuad_cert_signed_pdf';
/** Estado que tenía el adjunto antes de marcarlo privado (para revertir exactamente). */
const SQUUAD_CERT_SIGNED_PDF_PREV_STATUS_META = '_squuad_cert_signed_pdf_prev_status';
/** Opción: la migración de los adjuntos existentes ya se hizo (fecha UTC). */
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

/** Fuera de la API REST de medios para quien no tiene permiso (además de lo que ya hace WordPress con los privados). */
add_filter('rest_attachment_query', 'squuad_cert_signed_pdf_rest_query', 10, 1);
function squuad_cert_signed_pdf_rest_query($args)
{
    if (!squuad_cert_signed_pdf_can_list()) {
        $args['meta_query'] = array_merge((array) ($args['meta_query'] ?? []), [['key' => SQUUAD_CERT_SIGNED_PDF_META, 'compare' => 'NOT EXISTS']]);
    }

    return $args;
}

/** Ficha REST de un PDF firmado: solo para quien tiene permiso (aunque alguien pudiera leer adjuntos privados). */
add_filter('rest_prepare_attachment', 'squuad_cert_signed_pdf_rest_prepare', 10, 2);
function squuad_cert_signed_pdf_rest_prepare($response, $post)
{
    if ($post instanceof WP_Post && '' !== (string) get_post_meta($post->ID, SQUUAD_CERT_SIGNED_PDF_META, true) && !squuad_cert_signed_pdf_can_list()) {
        return new WP_Error('rest_forbidden', __('Sorry, you are not allowed to access this page.', 'wp-certificates'), ['status' => rest_authorization_required_code()]);
    }

    return $response;
}

/** Fuera de la biblioteca de medios para quien no tiene permiso. */
add_filter('ajax_query_attachments_args', 'squuad_cert_signed_pdf_library_query', 10, 1);
function squuad_cert_signed_pdf_library_query($query)
{
    if (!squuad_cert_signed_pdf_can_list()) {
        $query['meta_query'] = array_merge((array) ($query['meta_query'] ?? []), [['key' => SQUUAD_CERT_SIGNED_PDF_META, 'compare' => 'NOT EXISTS']]);
    }

    return $query;
}

/**
 * Migración de los PDF firmados ya guardados (esquema v13): los marca privados, sin renombrarlos ni moverlos. Solo los
 * identificables con certeza (squuad_cert_signed_pdf_known()). Se ejecuta una sola vez por sitio: si la versión previa
 * del esquema era menor que 13 y aún no consta en la opción squuad_cert_signed_pdf_migrated. Siempre corrige las marcas
 * antiguas (idempotente). Deja en el log cuántos encontró y cuántos cambió. Devuelve ['found', 'changed', 'fixed'].
 */
function squuad_cert_signed_pdf_migrate(string $previous_db_version = ''): array
{
    $fixed = squuad_cert_signed_pdf_fix_markers();
    $due = ('' === $previous_db_version || version_compare($previous_db_version, '13', '<'))
        && false === get_option(SQUUAD_CERT_SIGNED_PDF_MIGRATED_OPTION, false);
    if (!$due) {
        return ['found' => 0, 'changed' => 0, 'fixed' => $fixed];
    }
    $known = squuad_cert_signed_pdf_known();
    $changed = 0;
    foreach ($known as $attachment_id => $marker) {
        $post = get_post($attachment_id);
        if (!$post || 'attachment' !== $post->post_type || 'application/pdf' !== $post->post_mime_type) {
            continue;
        }
        if (squuad_cert_signed_pdf_protect($attachment_id, $marker)) {
            $changed++;
        }
    }
    add_option(SQUUAD_CERT_SIGNED_PDF_MIGRATED_OPTION, gmdate('Y-m-d H:i:s'), '', false);
    squuad_cert_log(sprintf('PDF firmados protegidos (privados, fuera de la API de medios): %d identificados, %d cambiados', count($known), $changed), 'signed_pdf_migration');

    return ['found' => count($known), 'changed' => $changed, 'fixed' => $fixed];
}
