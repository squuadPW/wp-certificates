<?php
declare(strict_types=1);

/**
 * API pública de documentos para otros plugins (contrato). Genérica: no sabe de ningún plugin en concreto.
 *
 * 1. Contexto de un documento al generarlo:
 *    - filtro squuad_cert_document_context ($ctx, $document, $subject_type, $subject_id): otro plugin añade datos al
 *      contexto que reciben las variables (p. ej. la inscripción o una promoción);
 *    - filtro squuad_cert_document_required_context ([], $document): otro plugin declara que un documento SOLO se genera
 *      con ciertas claves de contexto, como [clave => aviso]. Si faltan, ninguna ruta de wp-certificates lo genera
 *      (Generar, asignar, emitir para firma, documentos automáticos, Mi cuenta) y se muestra el aviso. Así un documento
 *      que solo tiene sentido desde otra pantalla (p. ej. un acta de una promoción) no sale vacío desde otro sitio.
 *    squuad_cert_document_ctx() junta las dos cosas; todas las rutas de generación la usan.
 * 2. squuad_cert_create_document(): crea un documento INACTIVO con su política de firmantes (plantillas de ejemplo que
 *    instala otro plugin). El sitio lo revisa y lo activa en Certificación > Documentos.
 * 3. squuad_cert_create_variable(): crea en la lista de variables (Certificación > Variables) una variable con su método
 *    registrado, o enlaza la que ya existe sin método. Nunca cambia una variable que ya tiene método.
 */

defined('ABSPATH') || exit;

/** Claves de contexto que exige un documento para generarse, con su aviso: [clave => mensaje]. */
function squuad_cert_document_required_context($document): array
{
    if (!$document) {
        return [];
    }
    $required = apply_filters('squuad_cert_document_required_context', [], $document);
    $out = [];
    foreach ((array) $required as $key => $message) {
        $key = sanitize_key((string) $key);
        if ('' !== $key) {
            $out[$key] = '' !== trim((string) $message) ? (string) $message : __('This document can only be generated from the screen of the plugin that provides it.', 'wp-certificates');
        }
    }
    return $out;
}

/**
 * Contexto completo de un documento para sus variables: el de la ruta ($base) más lo que añadan otros plugins, o un
 * WP_Error (squuad_cert_context_required, con el aviso) si falta una clave que el documento exige. $log = false para
 * consultar sin dejar rastro (listas). Contrato: el contexto lo ponen la ruta y los plugins con datos propios; nunca
 * entrada del usuario sin validar (un valor del contexto llega a las variables del documento).
 *
 * @return array|WP_Error
 */
function squuad_cert_document_ctx($document, string $subject_type, int $subject_id, array $base, bool $log = true)
{
    $ctx = apply_filters('squuad_cert_document_context', $base, $document, $subject_type, $subject_id);
    // Lo que puso la ruta gana: un filtro solo añade claves, no quita ni cambia las de la ruta (titular, documento…)
    $ctx = is_array($ctx) ? $base + $ctx : $base;
    foreach (squuad_cert_document_required_context($document) as $key => $message) {
        $value = $ctx[$key] ?? null;
        $empty = null === $value
            || (is_scalar($value) && ('' === trim((string) $value) || (is_numeric($value) && (float) $value <= 0)))
            || (is_array($value) && !$value)
            || (!is_scalar($value) && !is_array($value) && !is_object($value));
        if ($empty) {
            if ($log && function_exists('squuad_cert_log')) {
                squuad_cert_log(sprintf('Documento %d no generado: falta el contexto «%s» (titular %s %d, usuario %d)', (int) (is_object($document) ? ($document->id ?? 0) : 0), $key, $subject_type, $subject_id, get_current_user_id()), 'context_required');
            }
            return new WP_Error('squuad_cert_context_required', $message, ['key' => $key]);
        }
    }
    return $ctx;
}

/** ¿Exige el documento un contexto que ninguna ruta propia de wp-certificates puede dar? (para ocultarlo de listas) */
function squuad_cert_document_needs_external_context($document, string $subject_type, int $subject_id, array $base = []): bool
{
    return is_wp_error(squuad_cert_document_ctx($document, $subject_type, $subject_id, $base + ['document' => $document], false));
}

/**
 * Crea un documento INACTIVO (status 0) con su política de firmantes, para instalar plantillas de ejemplo desde otro
 * plugin. No hace nada más: no crea requisitos ni solicitudes y no activa nada.
 *
 * $data: title y document_identificator (obligatorios; el código no puede existir), header, content, footer, type
 * ('managed' | 'automatic'), orientation, paper_format, unit, width_size, height_size, margin_required.
 * $slots: como squuad_cert_signing_policy_save(): [['slot_type' => 'role'|'signer'|'var', 'role', 'signer_id', 'position']].
 * $requires: si pide firmas (con $slots). Si la política no se puede guardar completa, el documento recién creado se
 * borra y se devuelve el error (no queda un documento a medias).
 *
 * @return int|WP_Error id del documento
 */
function squuad_cert_create_document(array $data, array $slots = [], bool $requires = false)
{
    global $wpdb;
    $table = $wpdb->prefix . 'documents_certificates';
    $title = trim(sanitize_text_field((string) ($data['title'] ?? '')));
    $code = strtoupper(sanitize_title((string) ($data['document_identificator'] ?? '')));
    if ('' === $title || '' === $code) {
        return new WP_Error('squuad_cert_document_invalid', __('The name and the code are required.', 'wp-certificates'));
    }
    if ($wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE document_identificator = %s LIMIT 1", $code))) {
        /* translators: %s: document code */
        return new WP_Error('squuad_cert_document_exists', sprintf(__('There is already a document with the code %s.', 'wp-certificates'), $code));
    }
    $type = in_array($data['type'] ?? 'managed', ['managed', 'automatic'], true) ? (string) ($data['type'] ?? 'managed') : 'managed';
    $row = [
        'title'                 => strtoupper($title),
        'document_identificator' => $code,
        'header'                => (string) ($data['header'] ?? ''),
        'content'               => (string) ($data['content'] ?? ''),
        'footer'                => (string) ($data['footer'] ?? ''),
        'status'                => 0, // siempre inactivo: el sitio lo revisa y lo activa
        'signature_required'    => 0,
        'graduated_required'    => 0,
        'margin_required'       => empty($data['margin_required']) ? 0 : 1,
        'orientation'           => in_array($data['orientation'] ?? 'portrait', ['portrait', 'landscape'], true) ? (string) ($data['orientation'] ?? 'portrait') : 'portrait',
        'type'                  => $type,
        'width_size'            => (float) ($data['width_size'] ?? 0),
        'height_size'           => (float) ($data['height_size'] ?? 0),
        'paper_format'          => sanitize_key((string) ($data['paper_format'] ?? 'a4')) ?: 'a4',
        'unit'                  => in_array($data['unit'] ?? 'mm', ['mm', 'cm', 'in', 'px', 'pt'], true) ? (string) ($data['unit'] ?? 'mm') : 'mm',
        'is_required'           => 0,
        'is_visible'            => 0,
    ];
    $row = (array) apply_filters('wpc_document_save_data', $row, 0);
    $row['status'] = 0; // ni un filtro lo activa
    if (!$wpdb->insert($table, $row)) {
        return new WP_Error('squuad_cert_document_db', __('Error saving document.', 'wp-certificates'));
    }
    $id = (int) $wpdb->insert_id;

    if ($slots || $requires) {
        $saved = function_exists('squuad_cert_signing_policy_save') ? squuad_cert_signing_policy_save($id, $requires, $slots) : ['ok' => false, 'message' => __('The signers module is not available yet.', 'wp-certificates')];
        $created = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
        $policy = $created && function_exists('squuad_cert_signing_policy') ? squuad_cert_signing_policy($created) : null;
        $stored = $policy && !empty($policy['slots']) ? count((array) $policy['slots']) : 0;
        if (empty($saved['ok']) || ($requires && $stored !== count($slots))) {
            // La política no quedó como se pidió (un firmante o rol no válido se descarta sin aviso): no se deja a medias
            $wpdb->delete($table, ['id' => $id]);
            if (function_exists('squuad_cert_signing_policy_save')) {
                squuad_cert_signing_policy_save($id, false, []); // y sin restos de su política
            }
            return new WP_Error('squuad_cert_document_signers', (string) ($saved['message'] ?? '') ?: __('The signers of the document could not be saved as requested.', 'wp-certificates'));
        }
    }

    do_action('wpc_document_saved', $id, $row);
    if (function_exists('squuad_cert_log')) {
        squuad_cert_log(sprintf('Documento %d (%s) creado inactivo por otro plugin (usuario %d)', $id, $code, get_current_user_id()), 'document_created');
    }
    return $id;
}

/**
 * Crea una variable de la lista (Certificación > Variables) con un método registrado, para que otro plugin deje
 * disponibles las variables de sus plantillas de ejemplo. Si ya existe una variable con esa clave:
 *  - sin método: se le asigna este (igual que «Vincular por clave»);
 *  - con método: no se toca (el sitio pudo cambiarlo) y se devuelve su id.
 * $type: dónde se ofrece ('all', 'document' o 'email').
 *
 * @return int|WP_Error id de la variable
 */
function squuad_cert_create_variable(string $key, string $text, string $method, string $type = 'all')
{
    global $wpdb;
    $table = $wpdb->prefix . 'variables_document';
    $key = sanitize_key($key);
    $text = trim(sanitize_text_field($text));
    if (!preg_match('/^[a-z][a-z0-9_]{1,59}$/', $key) || '' === $text) {
        return new WP_Error('squuad_cert_variable_invalid', __('The key and the description of the variable are required.', 'wp-certificates'));
    }
    if (!in_array($type, ['all', 'document', 'email'], true)) {
        $type = 'all';
    }
    $general = array_map(static fn($tag) => trim((string) $tag, '{}'), array_keys(\Squuad\Certificados\Variables::general()));
    if (in_array($key, $general, true)) {
        return new WP_Error('squuad_cert_variable_general', __('That key is a general variable of WP Certificates.', 'wp-certificates'));
    }
    if (!isset(squuad_cert_variable_methods()[$method])) {
        return new WP_Error('squuad_cert_variable_method', __('The method of the variable is not available.', 'wp-certificates'));
    }
    if ($table !== $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table))) {
        return new WP_Error('squuad_cert_variable_db', __('The list of variables is not ready yet.', 'wp-certificates'));
    }
    $row = $wpdb->get_row($wpdb->prepare("SELECT id, method FROM {$table} WHERE identificator = %s LIMIT 1", $key));
    if ($row) {
        if ('' === (string) $row->method) {
            $wpdb->update($table, ['method' => $method], ['id' => (int) $row->id], ['%s'], ['%d']);
            squuad_cert_log(sprintf('Variable %s (%d) enlazada con el método %s por otro plugin', $key, (int) $row->id, $method), 'variable_linked');
        }
        return (int) $row->id;
    }
    $ok = $wpdb->insert($table, [
        'text' => $text,
        'visual' => '{{' . $key . '}}',
        'identificator' => $key,
        'type' => $type,
        'method' => $method,
        'created_at' => current_time('mysql', true), // UTC (ADR 0017 de Edusof)
    ], ['%s', '%s', '%s', '%s', '%s', '%s']);
    if (!$ok) {
        return new WP_Error('squuad_cert_variable_db', __('Error saving the variable.', 'wp-certificates'));
    }
    $id = (int) $wpdb->insert_id;
    squuad_cert_log(sprintf('Variable %s (%d) creada con el método %s por otro plugin (usuario %d)', $key, $id, $method, get_current_user_id()), 'variable_created');
    return $id;
}
