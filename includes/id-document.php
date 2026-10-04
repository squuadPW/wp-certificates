<?php
declare(strict_types=1);

/**
 * Documento de identidad de quien firma (ADR 0007 de Edusof; decisiones del dueño del 2026-10-04).
 *
 * Toda persona que firma tiene que tener un documento de identidad (valor legal de las firmas). Con el interruptor
 * «Pedir documento de identidad» apagado (por defecto) no cambia nada: no se pide, no se comprueba y no se sella.
 *
 * Modelo de datos:
 * - Tipos de documento: opción squuad_cert_id_document_types (pocos registros por sitio, sin consultas ni esquema
 *   propio, viaja con las opciones del sitio). Cada tipo tiene un id estable (entero que nunca se reutiliza), nombre,
 *   prefijo (con al menos una letra, que no es el comienzo de otro ni lo contiene y que no se reutiliza una vez
 *   borrado), país opcional (ISO 3166-1 alfa-2), formato (numeric | alnum) y si está activo. Un tipo que alguna
 *   persona usa no se borra: se desactiva.
 * - Documento de cada persona (cédula declarada): metadatos protegidos del usuario de WordPress
 *   (squuad_cert_id_doc_type, squuad_cert_id_doc_number, squuad_cert_id_document = prefijo + número normalizado, único
 *   entre cuentas, squuad_cert_id_doc_updated y squuad_cert_id_doc_origin = «origen|usuario|fecha UTC»).
 * - Evidencia: el identificador y su origen quedan sellados en cada firma nueva (columnas signer_id_document y
 *   signer_id_origin, formato EDUSIG3, certification/includes/signature-integrity.php).
 *
 * No usa las metas de EduSystem (id_document, type_document) ni lee sus tablas.
 */

defined('ABSPATH') || exit;

const SQUUAD_CERT_ID_DOCUMENT_ENABLED_OPTION = 'squuad_cert_id_document_enabled';
const SQUUAD_CERT_ID_DOCUMENT_TYPES_OPTION = 'squuad_cert_id_document_types';
const SQUUAD_CERT_ID_DOCUMENT_COLUMNS_OPTION = 'squuad_cert_id_document_columns';
const SQUUAD_CERT_ID_DOCUMENT_CAP = 'squuad_cert_manage_id_documents';
const SQUUAD_CERT_ID_DOCUMENT_META = 'squuad_cert_id_document';
const SQUUAD_CERT_ID_DOCUMENT_TYPE_META = 'squuad_cert_id_doc_type';
const SQUUAD_CERT_ID_DOCUMENT_NUMBER_META = 'squuad_cert_id_doc_number';
const SQUUAD_CERT_ID_DOCUMENT_UPDATED_META = 'squuad_cert_id_doc_updated';
const SQUUAD_CERT_ID_DOCUMENT_ORIGIN_META = 'squuad_cert_id_doc_origin';
const SQUUAD_CERT_ID_DOCUMENT_MIN_LENGTH = 4;
const SQUUAD_CERT_ID_DOCUMENT_MAX_LENGTH = 20;
/** Intentos fallidos de la propia persona por hora (decisión D1 del dueño). */
const SQUUAD_CERT_ID_DOCUMENT_MAX_FAILURES = 5;

/** ¿Está encendido «Pedir documento de identidad»? (apagado por defecto). */
function squuad_cert_id_document_required(): bool
{
    return '1' === (string) get_option(SQUUAD_CERT_ID_DOCUMENT_ENABLED_OPTION, '0');
}

/**
 * ¿Existen en las tablas de firmas (vivas y anuladas) las columnas signer_id_document y signer_id_origin? Se comprueba
 * con SHOW COLUMNS una sola vez por versión del esquema y se guarda en una opción. Si faltan, no se usan ni se sella
 * EDUSIG3 (la firma sigue en EDUSIG2): nunca se falla por una migración incompleta.
 */
function squuad_cert_id_document_evidence_enabled(): bool
{
    global $wpdb;
    static $enabled = null;

    $version = (string) get_option('wp_c_db_version');
    if (null !== $enabled && $enabled['db'] === $version) {
        return $enabled['ok'];
    }
    $saved = get_option(SQUUAD_CERT_ID_DOCUMENT_COLUMNS_OPTION);
    if (is_array($saved) && ($saved['db'] ?? '') === $version) {
        $enabled = ['db' => $version, 'ok' => !empty($saved['ok'])];
        return $enabled['ok'];
    }
    $ok = '' !== $version;
    foreach (['squuad_cert_signatures', 'squuad_cert_signatures_revoked'] as $table) {
        foreach (['signer_id_document', 'signer_id_origin'] as $column) {
            $ok = $ok && $column === $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$wpdb->prefix}{$table} LIKE %s", $column));
        }
    }
    update_option(SQUUAD_CERT_ID_DOCUMENT_COLUMNS_OPTION, ['db' => $version, 'ok' => $ok], true);
    $enabled = ['db' => $version, 'ok' => $ok];

    return $ok;
}

/** Olvida la comprobación de las columnas (tras crear o actualizar las tablas). */
function squuad_cert_id_document_evidence_reset(): void
{
    delete_option(SQUUAD_CERT_ID_DOCUMENT_COLUMNS_OPTION);
}

/** Metas de la persona: protegidas (A1), así ni la API REST de WooCommerce ni los campos personalizados las leen o escriben. */
add_filter('is_protected_meta', 'squuad_cert_id_document_protected_meta', 10, 3);
function squuad_cert_id_document_protected_meta($protected, $meta_key, $meta_type = null)
{
    if (is_string($meta_key) && 0 === strpos($meta_key, 'squuad_cert_id_')) {
        return true;
    }

    return $protected;
}

/** Formatos de número: clave => nombre visible. */
function squuad_cert_id_document_formats(): array
{
    return [
        'numeric' => __('Numbers only', 'wp-certificates'),
        'alnum' => __('Letters and numbers', 'wp-certificates'),
    ];
}

/**
 * Nombres de los tipos de serie (se traducen al mostrarlos mientras el administrador no les cambie el nombre).
 *
 * @return array<string, string>
 */
function squuad_cert_id_document_builtin_names(): array
{
    return [
        've_id' => __('Venezuelan identity card', 'wp-certificates'),
        'foreign_id' => __('Foreign identity card', 'wp-certificates'),
        'passport' => __('Passport', 'wp-certificates'),
    ];
}

/** Datos guardados de los tipos: ['next_id' => int, 'types' => [id => tipo], 'retired_prefixes' => string[]]. */
function squuad_cert_id_document_types_data(): array
{
    $data = get_option(SQUUAD_CERT_ID_DOCUMENT_TYPES_OPTION, []);
    $data = is_array($data) ? $data : [];
    $types = [];
    foreach ((array) ($data['types'] ?? []) as $id => $type) {
        if (!is_array($type) || (int) $id <= 0) {
            continue;
        }
        $types[(int) $id] = [
            'id' => (int) $id,
            'name' => (string) ($type['name'] ?? ''),
            'builtin' => (string) ($type['builtin'] ?? ''),
            'prefix' => (string) ($type['prefix'] ?? ''),
            'country' => (string) ($type['country'] ?? ''),
            'format' => 'alnum' === ($type['format'] ?? '') ? 'alnum' : 'numeric',
            'active' => !empty($type['active']),
        ];
    }
    $next = max((int) ($data['next_id'] ?? 1), $types ? max(array_keys($types)) + 1 : 1);
    $retired = array_values(array_filter(array_map('strval', (array) ($data['retired_prefixes'] ?? []))));

    return ['next_id' => $next, 'types' => $types, 'retired_prefixes' => $retired];
}

/**
 * Tipos de documento, en el orden en que se crearon. Con $only_active, solo los activos.
 *
 * @return array<int, array{id: int, name: string, builtin: string, prefix: string, country: string, format: string, active: bool}>
 */
function squuad_cert_id_document_types(bool $only_active = false): array
{
    $types = squuad_cert_id_document_types_data()['types'];

    return $only_active ? array_filter($types, static fn(array $type): bool => $type['active']) : $types;
}

/** Un tipo por id, o null. */
function squuad_cert_id_document_type(int $type_id): ?array
{
    return squuad_cert_id_document_types()[$type_id] ?? null;
}

/** Nombre visible de un tipo: el de serie traducido, o el que escribió el administrador. */
function squuad_cert_id_document_type_label(array $type): string
{
    $builtin = squuad_cert_id_document_builtin_names();

    return '' !== $type['builtin'] && isset($builtin[$type['builtin']]) ? $builtin[$type['builtin']] : $type['name'];
}

/**
 * Tipos de ejemplo (Venezuela) al instalar, solo si los tipos no se han configurado nunca en este sitio (la opción no
 * existe): desactivables y editables. Idempotente.
 */
function squuad_cert_id_document_install(): void
{
    if (false !== get_option(SQUUAD_CERT_ID_DOCUMENT_TYPES_OPTION, false)) {
        return;
    }
    // Nombres en inglés (texto fuente): se muestran traducidos mientras conserven su clave de serie (builtin)
    $seed = [
        1 => ['name' => 'Venezuelan identity card', 'builtin' => 've_id', 'prefix' => 'V', 'country' => 'VE', 'format' => 'numeric', 'active' => true],
        2 => ['name' => 'Foreign identity card', 'builtin' => 'foreign_id', 'prefix' => 'E', 'country' => 'VE', 'format' => 'numeric', 'active' => true],
        3 => ['name' => 'Passport', 'builtin' => 'passport', 'prefix' => 'P', 'country' => '', 'format' => 'alnum', 'active' => true],
    ];
    add_option(SQUUAD_CERT_ID_DOCUMENT_TYPES_OPTION, ['next_id' => 4, 'types' => $seed, 'retired_prefixes' => []], '', false);
}

/** Prefijo normalizado: mayúsculas, 1 a 5 letras o números con al menos una letra (Q2). '' si no es válido. */
function squuad_cert_id_document_normalize_prefix(string $prefix): string
{
    $prefix = strtoupper(trim($prefix));

    return preg_match('/^(?=.*[A-Z])[A-Z0-9]{1,5}$/', $prefix) ? $prefix : '';
}

/**
 * ¿Choca el prefijo con otro? (M1): igual, comienzo de otro o al revés (V y VE harían ambiguo «VE123»), contra los
 * demás tipos y contra los prefijos de tipos borrados, que no se reutilizan.
 */
function squuad_cert_id_document_prefix_conflict(string $prefix, array $data, int $except_type_id = 0): ?string
{
    $overlaps = static fn(string $a, string $b): bool => 0 === strpos($a, $b) || 0 === strpos($b, $a);
    foreach ($data['types'] as $id => $type) {
        if ($id !== $except_type_id && '' !== $type['prefix'] && $overlaps($prefix, $type['prefix'])) {
            return $type['prefix'] === $prefix
                ? __('Another document type already uses that prefix.', 'wp-certificates')
                /* translators: %s: prefix of another document type */
                : sprintf(__('The prefix cannot start with the prefix of another document type, or be the start of it (%s).', 'wp-certificates'), $type['prefix']);
        }
    }
    foreach ($data['retired_prefixes'] as $retired) {
        if ($overlaps($prefix, $retired)) {
            /* translators: %s: prefix of a deleted document type */
            return sprintf(__('The prefix %s belonged to a deleted document type and cannot be used again (old identifiers contain it).', 'wp-certificates'), $retired);
        }
    }

    return null;
}

/**
 * Crea o edita un tipo ($type_id 0 = nuevo). $fields: name, prefix, country, format, active. El prefijo no puede
 * chocar con otro (ver squuad_cert_id_document_prefix_conflict()) y no se puede cambiar si alguna persona ya usa el tipo
 * (su identificador lo lleva). Devuelve el id o WP_Error.
 *
 * @return int|WP_Error
 */
function squuad_cert_id_document_type_save(int $type_id, array $fields)
{
    $data = squuad_cert_id_document_types_data();
    $current = $type_id ? ($data['types'][$type_id] ?? null) : null;
    if ($type_id && !$current) {
        return new WP_Error('squuad_cert_id_type_missing', __('That document type does not exist.', 'wp-certificates'));
    }

    $name = trim(sanitize_text_field((string) ($fields['name'] ?? '')));
    $prefix = squuad_cert_id_document_normalize_prefix((string) ($fields['prefix'] ?? ''));
    $country = strtoupper(trim(sanitize_text_field((string) ($fields['country'] ?? ''))));
    $format = 'alnum' === ($fields['format'] ?? '') ? 'alnum' : 'numeric';
    $active = !empty($fields['active']);

    if ('' === $name || mb_strlen($name) > 80) {
        return new WP_Error('squuad_cert_id_type_name', __('Write the name of the document type (at most 80 characters).', 'wp-certificates'));
    }
    if ('' === $prefix) {
        return new WP_Error('squuad_cert_id_type_prefix', __('The prefix must have from 1 to 5 capital letters or numbers, with at least one letter (for example V, E, P or J).', 'wp-certificates'));
    }
    if ('' !== $country && !preg_match('/^[A-Z]{2}$/', $country)) {
        return new WP_Error('squuad_cert_id_type_country', __('The country must be a two-letter ISO code (for example VE), or be left empty.', 'wp-certificates'));
    }
    if ($current && $current['prefix'] !== $prefix && squuad_cert_id_document_type_usage($type_id) > 0) {
        return new WP_Error('squuad_cert_id_type_prefix_used', __('The prefix of a document type that people already use cannot be changed: their identifiers contain it. Create a new type instead.', 'wp-certificates'));
    }
    // N4: el formato tampoco (los números ya guardados se validaron con el anterior)
    if ($current && $current['format'] !== $format && squuad_cert_id_document_type_usage($type_id) > 0) {
        return new WP_Error('squuad_cert_id_type_format_used', __('The format of a document type that people already use cannot be changed. Create a new type instead.', 'wp-certificates'));
    }
    $conflict = squuad_cert_id_document_prefix_conflict($prefix, $data, $type_id);
    if (null !== $conflict) {
        return new WP_Error('squuad_cert_id_type_prefix_taken', $conflict);
    }

    // Un tipo de serie deja de traducirse cuando el administrador le cambia el nombre
    $builtin = $current['builtin'] ?? '';
    if ('' !== $builtin && $name !== $current['name'] && $name !== squuad_cert_id_document_type_label($current)) {
        $builtin = '';
    }
    if ('' !== $builtin) {
        $name = $current['name'];
    }

    $id = $type_id ?: $data['next_id'];
    $data['types'][$id] = ['id' => $id, 'name' => $name, 'builtin' => $builtin, 'prefix' => $prefix, 'country' => $country, 'format' => $format, 'active' => $active];
    if (!$type_id) {
        $data['next_id'] = $id + 1;
    }
    update_option(SQUUAD_CERT_ID_DOCUMENT_TYPES_OPTION, $data, false);

    $changes = [];
    foreach (['name', 'prefix', 'country', 'format', 'active'] as $key) {
        $old = $current[$key] ?? null;
        if ($old !== $data['types'][$id][$key]) {
            $changes[] = $key . '=' . var_export($data['types'][$id][$key], true);
        }
    }
    squuad_cert_log(sprintf(
        'Tipo de documento de identidad %d %s por el usuario %d: %s',
        $id,
        $type_id ? 'editado' : 'creado',
        get_current_user_id(),
        $changes ? implode(', ', $changes) : 'sin cambios'
    ), 'id_document_type');

    return $id;
}

/** Activa o desactiva un tipo. */
function squuad_cert_id_document_type_set_active(int $type_id, bool $active): bool
{
    $data = squuad_cert_id_document_types_data();
    if (!isset($data['types'][$type_id])) {
        return false;
    }
    $data['types'][$type_id]['active'] = $active;
    update_option(SQUUAD_CERT_ID_DOCUMENT_TYPES_OPTION, $data, false);
    squuad_cert_log(sprintf('Tipo de documento de identidad %d %s por el usuario %d', $type_id, $active ? 'activado' : 'desactivado', get_current_user_id()), 'id_document_type');

    return true;
}

/** Borra un tipo que nadie usa (un tipo usado solo se desactiva). Su prefijo queda reservado: no se reutiliza (M1). */
function squuad_cert_id_document_type_delete(int $type_id): bool
{
    $data = squuad_cert_id_document_types_data();
    if (!isset($data['types'][$type_id]) || squuad_cert_id_document_type_usage($type_id, true) > 0) {
        return false;
    }
    $prefix = $data['types'][$type_id]['prefix'];
    unset($data['types'][$type_id]);
    if ('' !== $prefix && !in_array($prefix, $data['retired_prefixes'], true)) {
        $data['retired_prefixes'][] = $prefix;
    }
    update_option(SQUUAD_CERT_ID_DOCUMENT_TYPES_OPTION, $data, false);
    squuad_cert_log(sprintf('Tipo de documento de identidad %d (prefijo %s) borrado sin uso por el usuario %d; el prefijo queda reservado', $type_id, $prefix, get_current_user_id()), 'id_document_type');

    return true;
}

/**
 * Personas que usan cada tipo: [id => cantidad]. Con $type_id, la cantidad de ese tipo. Se cuenta una vez por petición;
 * $refresh vuelve a contar (Q8: después de guardar un documento o antes de borrar un tipo).
 *
 * @return array<int, int>|int
 */
function squuad_cert_id_document_type_usage(int $type_id = 0, bool $refresh = false)
{
    global $wpdb;
    static $counts = null;

    if (null === $counts || $refresh) {
        $counts = [];
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_value, COUNT(*) AS total FROM {$wpdb->usermeta} WHERE meta_key = %s GROUP BY meta_value",
            SQUUAD_CERT_ID_DOCUMENT_TYPE_META
        ));
        foreach ((array) $rows as $row) {
            $counts[(int) $row->meta_value] = (int) $row->total;
        }
    }
    if ($type_id) {
        return $counts[$type_id] ?? 0;
    }

    return $counts;
}

/**
 * Número normalizado según el tipo: sin puntos, guiones, barras ni espacios y en mayúsculas. En un tipo «solo
 * números», si la persona escribió también el prefijo (V-12.345.678) se quita y se quitan los ceros a la izquierda
 * (D2: «V 012345678» = V12345678). '' si queda vacío.
 */
function squuad_cert_id_document_normalize_number(string $number, array $type): string
{
    $number = strtoupper((string) preg_replace('/[\s.\-\/_,]+/u', '', trim($number)));
    $prefix = $type['prefix'];
    if ('numeric' === $type['format'] && '' !== $prefix && 0 === strpos($number, $prefix) && preg_match('/^[0-9]+$/', substr($number, strlen($prefix)))) {
        $number = substr($number, strlen($prefix));
    }
    if ('numeric' === $type['format'] && preg_match('/^[0-9]+$/', $number)) {
        $number = ltrim($number, '0');
    }

    return $number;
}

/** Valida un número ya normalizado según el formato del tipo. Devuelve el mensaje de error o null. */
function squuad_cert_id_document_number_error(string $number, array $type): ?string
{
    $min = SQUUAD_CERT_ID_DOCUMENT_MIN_LENGTH;
    $max = SQUUAD_CERT_ID_DOCUMENT_MAX_LENGTH;
    if ('' === $number) {
        return __('Write the number of the identity document.', 'wp-certificates');
    }
    if ('numeric' === $type['format'] && !preg_match('/^[0-9]+$/', $number)) {
        /* translators: %s: name of the document type */
        return sprintf(__('The number of the document "%s" can only have numbers.', 'wp-certificates'), squuad_cert_id_document_type_label($type));
    }
    if ('alnum' === $type['format'] && !preg_match('/^[A-Z0-9]+$/', $number)) {
        /* translators: %s: name of the document type */
        return sprintf(__('The number of the document "%s" can only have letters (without accents) and numbers.', 'wp-certificates'), squuad_cert_id_document_type_label($type));
    }
    $length = strlen($number);
    if ($length < $min || $length > $max) {
        /* translators: 1: minimum length, 2: maximum length */
        return sprintf(__('The number must have from %1$d to %2$d characters.', 'wp-certificates'), $min, $max);
    }

    return null;
}

/**
 * Documento de identidad de una persona: ['type_id', 'type' (o null si se borró), 'number', 'identifier', 'origin',
 * 'updated'] o null si no lo tiene. 'origin' es la cadena sellada «origen|usuario|fecha UTC» (D1); los documentos
 * registrados antes de guardar el origen dan «unknown|0|<fecha>».
 */
function squuad_cert_id_document_get(int $user_id): ?array
{
    if ($user_id <= 0) {
        return null;
    }
    $identifier = (string) get_user_meta($user_id, SQUUAD_CERT_ID_DOCUMENT_META, true);
    if ('' === $identifier) {
        return null;
    }
    $type_id = (int) get_user_meta($user_id, SQUUAD_CERT_ID_DOCUMENT_TYPE_META, true);
    $updated = (string) get_user_meta($user_id, SQUUAD_CERT_ID_DOCUMENT_UPDATED_META, true);
    $origin = (string) get_user_meta($user_id, SQUUAD_CERT_ID_DOCUMENT_ORIGIN_META, true);

    return [
        'type_id' => $type_id,
        'type' => squuad_cert_id_document_type($type_id),
        'number' => (string) get_user_meta($user_id, SQUUAD_CERT_ID_DOCUMENT_NUMBER_META, true),
        'identifier' => $identifier,
        'origin' => '' !== $origin ? $origin : 'unknown|0|' . $updated,
        'updated' => $updated,
    ];
}

/** Partes de la cadena de origen: ['origin' => self|issue|admin|unknown, 'by' => usuario, 'at' => fecha UTC]. */
function squuad_cert_id_document_origin_parts(string $origin): array
{
    $parts = explode('|', $origin, 3);

    return ['origin' => (string) ($parts[0] ?? ''), 'by' => (int) ($parts[1] ?? 0), 'at' => (string) ($parts[2] ?? '')];
}

/** Nombre visible de un origen. */
function squuad_cert_id_document_origin_label(string $origin): string
{
    $labels = [
        'self' => __('Registered by the person', 'wp-certificates'),
        'issue' => __('Registered by the office when issuing a document', 'wp-certificates'),
        'admin' => __('Registered or corrected by the administration', 'wp-certificates'),
    ];

    return $labels[squuad_cert_id_document_origin_parts($origin)['origin']] ?? __('Origin not recorded', 'wp-certificates');
}

/** Identificador completo de una persona (V12345678), o ''. */
function squuad_cert_id_document_identifier(int $user_id): string
{
    $document = squuad_cert_id_document_get($user_id);

    return $document ? $document['identifier'] : '';
}

/** ¿Le falta el documento a esta persona y el sitio lo pide? */
function squuad_cert_id_document_missing(int $user_id): bool
{
    return squuad_cert_id_document_required() && $user_id > 0 && '' === squuad_cert_id_document_identifier($user_id);
}

/**
 * Motivo por el que esta persona no puede firmar todavía (le falta el documento y el sitio lo pide), o null. Lo usan
 * todos los puntos de firma del servidor.
 */
function squuad_cert_id_document_signing_error(int $user_id): ?string
{
    return squuad_cert_id_document_missing($user_id)
        ? __('Before signing, register your identity document.', 'wp-certificates')
        : null;
}

/**
 * Identificador enmascarado para listas y registros: el prefijo y los últimos 3 caracteres (V•••••678).
 */
function squuad_cert_id_document_mask(string $identifier, string $prefix = ''): string
{
    if ('' === $identifier) {
        return '';
    }
    $prefix = '' !== $prefix && 0 === strpos($identifier, $prefix) ? $prefix : '';
    if ('' === $prefix && preg_match('/^([A-Z]+)/', $identifier, $m)) {
        $prefix = $m[1]; // prefijo de un tipo borrado o desconocido: las letras iniciales
    }
    $rest = substr($identifier, strlen($prefix));
    $visible = strlen($rest) > 4 ? 3 : 1;

    return $prefix . str_repeat('•', max(strlen($rest) - $visible, 1)) . substr($rest, -$visible);
}

/** Identificador enmascarado de una persona, o ''. */
function squuad_cert_id_document_masked(int $user_id): string
{
    $document = squuad_cert_id_document_get($user_id);

    return $document ? squuad_cert_id_document_mask($document['identifier'], (string) ($document['type']['prefix'] ?? '')) : '';
}

/**
 * B3: ¿se enmascara el documento en los recuadros de firma? Solo en las vistas de pantalla de quien no genera el PDF
 * final (bandeja del firmante); el PDF final y su vista previa lo llevan completo. Con $set lo cambia.
 */
function squuad_cert_id_document_mask_in_boxes(?bool $set = null): bool
{
    static $mask = false;
    if (null !== $set) {
        $mask = $set;
    }

    return $mask;
}

/** Cuenta que ya tiene este identificador (distinta de $except), o 0. Búsqueda por meta_key (indexada) y valor. */
function squuad_cert_id_document_owner(string $identifier, int $except = 0): int
{
    global $wpdb;

    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s AND user_id <> %d LIMIT 1",
        SQUUAD_CERT_ID_DOCUMENT_META,
        $identifier,
        $except
    ));
}

/**
 * ¿Ya no puede la persona corregir su documento? Después de su primera firma con el documento sellado (firma viva o
 * anulada con signer_id_document), solo el administrador lo puede cambiar (decisión 2 del dueño).
 */
function squuad_cert_id_document_locked(int $user_id): bool
{
    global $wpdb;

    if ($user_id <= 0 || !squuad_cert_id_document_evidence_enabled()) {
        return false;
    }
    foreach (['squuad_cert_signatures', 'squuad_cert_signatures_revoked'] as $table) {
        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}{$table} WHERE user_id = %d AND signer_id_document IS NOT NULL AND signer_id_document <> '' LIMIT 1",
            $user_id
        ));
        if ($found) {
            return true;
        }
    }

    return false;
}

/**
 * ¿Le toca FIRMAR este documento pendiente (fila de squuad_cert_signature_user_documents())? Solo se pide el documento a
 * quien firma (decisión del dueño): quien solo rellena los campos de un automático sin firma no cuenta. Con solicitud,
 * firma si tiene un puesto en ella; sin solicitud aún, si la plantilla pide firma y la política tiene uno de sus roles.
 */
function squuad_cert_id_document_item_signs(array $item, WP_User $user): bool
{
    if ('to_sign' !== ($item['state'] ?? '')) {
        return false;
    }
    if (!empty($item['request']) && function_exists('squuad_cert_signature_request_role')) {
        return '' !== squuad_cert_signature_request_role($item['request'], (int) $user->ID);
    }
    $document = $item['document'] ?? null;
    if (!is_object($document) || !function_exists('squuad_cert_automatic_status') || !function_exists('squuad_cert_signing_policy')) {
        return false;
    }

    return squuad_cert_automatic_status($document)['signature']
        && '' !== squuad_cert_signing_policy_role_for_user(squuad_cert_signing_policy($document) + ['requires_signatures' => true], $user);
}

/**
 * ¿Tiene la persona motivo para registrar su documento? (D1.1): algo que FIRMAR (un documento pendiente en el que tiene
 * que firmar; rellenar campos no cuenta) o ser firmante del sistema (invitado o activo). Sin el módulo de firmas, no.
 */
function squuad_cert_id_document_self_allowed(int $user_id): bool
{
    if ($user_id <= 0) {
        return false;
    }
    if (function_exists('squuad_cert_signer_by_user')) {
        $signer = squuad_cert_signer_by_user($user_id);
        if ($signer && in_array((string) $signer->status, ['invited', 'active'], true)) {
            return true;
        }
    }
    $user = get_userdata($user_id);
    if ($user && function_exists('squuad_cert_signature_user_documents')) {
        foreach (squuad_cert_signature_user_documents($user) as $item) {
            if (squuad_cert_id_document_item_signs($item, $user)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * ¿Puede el usuario conectado escribir o cambiar el documento de $user_id por la vía $origin?
 * - 'self': la propia persona, si tiene algo que firmar o es firmante del sistema (D1) y aún no ha firmado con él.
 * - 'issue': quien emite documentos, solo si la persona aún no tiene documento (decisión 3).
 * - 'admin': quien tiene el permiso squuad_cert_manage_id_documents y puede editar esa cuenta.
 */
function squuad_cert_id_document_can_edit(int $user_id, string $origin): bool
{
    $current = get_current_user_id();
    if (!$current || $user_id <= 0) {
        return false;
    }
    switch ($origin) {
        case 'self':
            return $current === $user_id && !squuad_cert_id_document_locked($user_id) && squuad_cert_id_document_self_allowed($user_id);
        case 'issue':
            // N6: además, solo a las cuentas a las que se puede emitir desde «Emitir documentos» (no de administración)
            return current_user_can('manager_certificate_assignment') && '' === squuad_cert_id_document_identifier($user_id)
                && (!function_exists('squuad_cert_assignment_users_allowed') || squuad_cert_assignment_users_allowed($user_id));
        case 'admin':
            return current_user_can(SQUUAD_CERT_ID_DOCUMENT_CAP) && current_user_can('edit_user', $user_id);
    }

    return false;
}

/** Clave del transitorio de intentos fallidos de un usuario (no guarda ningún número). */
function squuad_cert_id_document_failures_key(int $user_id): string
{
    return 'squuad_cert_iddoc_fails_' . $user_id;
}

/** Intentos fallidos de la última hora (marcas de tiempo). */
function squuad_cert_id_document_failures(int $user_id): array
{
    $saved = get_transient(squuad_cert_id_document_failures_key($user_id));
    $since = time() - HOUR_IN_SECONDS;

    return array_values(array_filter(is_array($saved) ? array_map('intval', $saved) : [], static fn(int $t): bool => $t > $since));
}

/** Anota un intento fallido de la propia persona. */
function squuad_cert_id_document_add_failure(int $user_id): void
{
    $failures = squuad_cert_id_document_failures($user_id);
    $failures[] = time();
    set_transient(squuad_cert_id_document_failures_key($user_id), $failures, HOUR_IN_SECONDS);
    if (count($failures) >= SQUUAD_CERT_ID_DOCUMENT_MAX_FAILURES) {
        squuad_cert_log(sprintf('Documento de identidad: el usuario %d llegó al límite de %d intentos fallidos en una hora', $user_id, SQUUAD_CERT_ID_DOCUMENT_MAX_FAILURES), 'id_document_rejected');
    }
}

/** Nombre de un bloqueo GET_LOCK de los documentos (B2: con base_prefix, los usuarios son de toda la red). */
function squuad_cert_id_document_lock_name(string $what): string
{
    global $wpdb;

    return 'squuad_cert_iddoc_' . substr(hash('sha256', DB_NAME . '|' . $wpdb->base_prefix . '|' . $what), 0, 40);
}

/**
 * Comprueba un documento antes de guardarlo, sin guardar nada: permiso por la vía $origin, límite de intentos de la
 * propia persona (D1.2), tipo activo (o el mismo que ya tenía), formato, longitud y que no lo tenga otra cuenta (con
 * mensaje genérico salvo para administración, D1). Cada fallo de la propia persona cuenta como intento. Devuelve
 * ['type' => tipo, 'number' => número, 'identifier' => identificador, 'previous' => documento anterior o null,
 * 'unchanged' => bool] o WP_Error.
 *
 * @return array|WP_Error
 */
function squuad_cert_id_document_check(int $user_id, int $type_id, string $raw_number, string $origin)
{
    if (!get_userdata($user_id) || !squuad_cert_id_document_can_edit($user_id, $origin)) {
        if ('self' === $origin && squuad_cert_id_document_locked($user_id)) {
            return new WP_Error('squuad_cert_id_locked', __('Your identity document can no longer be changed because you already signed with it. Ask the administration to correct it.', 'wp-certificates'));
        }
        if ('self' === $origin && get_current_user_id() === $user_id) {
            return new WP_Error('squuad_cert_id_nothing', __('You can register your identity document when you have a document to sign.', 'wp-certificates'));
        }
        return new WP_Error('squuad_cert_id_forbidden', __('You are not allowed to change this identity document.', 'wp-certificates'));
    }
    $self = 'self' === $origin;
    if ($self && count(squuad_cert_id_document_failures($user_id)) >= SQUUAD_CERT_ID_DOCUMENT_MAX_FAILURES) {
        return new WP_Error('squuad_cert_id_limit', sprintf(
            /* translators: %d: maximum number of failed attempts */
            __('You made %d failed attempts in the last hour. Please try again later or contact the administration.', 'wp-certificates'),
            SQUUAD_CERT_ID_DOCUMENT_MAX_FAILURES
        ));
    }
    $fail = static function (WP_Error $error) use ($self, $user_id): WP_Error {
        if ($self) {
            squuad_cert_id_document_add_failure($user_id);
        }
        return $error;
    };

    $previous = squuad_cert_id_document_get($user_id);
    $type = squuad_cert_id_document_type($type_id);
    if (!$type || (!$type['active'] && (!$previous || $previous['type_id'] !== $type_id))) {
        return $fail(new WP_Error('squuad_cert_id_type', __('Choose the type of identity document.', 'wp-certificates')));
    }
    $number = squuad_cert_id_document_normalize_number($raw_number, $type);
    $error = squuad_cert_id_document_number_error($number, $type);
    if (null !== $error) {
        return $fail(new WP_Error('squuad_cert_id_number', $error));
    }
    $identifier = $type['prefix'] . $number;
    $unchanged = $previous && $previous['identifier'] === $identifier && $previous['type_id'] === $type_id;
    if (!$unchanged) {
        $owner = squuad_cert_id_document_owner($identifier, $user_id);
        if ($owner) {
            // Al registro de actividad, con la cuenta dueña, para que administración lo resuelva; a quien escribe, un
            // mensaje que no confirma que ese número exista (salvo a administración)
            squuad_cert_log(sprintf(
                'Documento de identidad rechazado para el usuario %d (por el usuario %d, %s): %s ya pertenece a la cuenta %d',
                $user_id,
                get_current_user_id(),
                $origin,
                squuad_cert_id_document_mask($identifier, $type['prefix']),
                $owner
            ), 'id_document_rejected', ['user_id' => $user_id, 'owner_user_id' => $owner, 'origin' => $origin]);
            return $fail(new WP_Error('squuad_cert_id_taken', 'admin' === $origin
                /* translators: %d: id of the account that already has the identity document */
                ? sprintf(__('That identity document already belongs to account #%d.', 'wp-certificates'), $owner)
                : __('That identity document could not be registered. Check the type and the number; if they are correct, contact the administration.', 'wp-certificates')));
        }
    }

    return ['type' => $type, 'number' => $number, 'identifier' => $identifier, 'previous' => $previous, 'unchanged' => $unchanged];
}

/**
 * Guarda el documento de una persona: lo comprueba todo (squuad_cert_id_document_check()) bajo dos bloqueos (B1: el de
 * la persona y el del identificador, para que dos cuentas no se queden con el mismo a la vez ni dos envíos de la misma
 * persona se crucen), guarda las metas con su origen (quién y cuándo, D1) y deja la alta o el cambio en el registro de
 * actividad, enmascarado. Devuelve el identificador o WP_Error.
 *
 * @return string|WP_Error
 */
function squuad_cert_id_document_save(int $user_id, int $type_id, string $raw_number, string $origin)
{
    global $wpdb;

    $busy = static fn(): WP_Error => new WP_Error('squuad_cert_id_busy', __('The identity document could not be saved. Please try again.', 'wp-certificates'));
    $user_lock = squuad_cert_id_document_lock_name('user|' . $user_id);
    if ('1' !== (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $user_lock))) {
        return $busy();
    }
    try {
        // Primera comprobación para saber el identificador; la definitiva va bajo su bloqueo
        $checked = squuad_cert_id_document_check($user_id, $type_id, $raw_number, $origin);
        if (is_wp_error($checked)) {
            return $checked;
        }
        if ($checked['unchanged']) {
            return $checked['identifier'];
        }
        $id_lock = squuad_cert_id_document_lock_name('id|' . $checked['identifier']);
        if ('1' !== (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $id_lock))) {
            return $busy();
        }
        try {
            if (squuad_cert_id_document_owner($checked['identifier'], $user_id)) {
                // Otra cuenta lo tomó entre la comprobación y el bloqueo: se repite para dejar constancia y el mensaje
                $checked = squuad_cert_id_document_check($user_id, $type_id, $raw_number, $origin);
                if (is_wp_error($checked)) {
                    return $checked;
                }
            }
            $now = gmdate('Y-m-d H:i:s');
            $origin_value = $origin . '|' . get_current_user_id() . '|' . $now;
            update_user_meta($user_id, SQUUAD_CERT_ID_DOCUMENT_TYPE_META, (string) $type_id);
            update_user_meta($user_id, SQUUAD_CERT_ID_DOCUMENT_NUMBER_META, $checked['number']);
            update_user_meta($user_id, SQUUAD_CERT_ID_DOCUMENT_META, $checked['identifier']);
            update_user_meta($user_id, SQUUAD_CERT_ID_DOCUMENT_UPDATED_META, $now);
            update_user_meta($user_id, SQUUAD_CERT_ID_DOCUMENT_ORIGIN_META, $origin_value);
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $id_lock));
        }
    } finally {
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $user_lock));
    }
    squuad_cert_id_document_type_usage(0, true);

    $previous = $checked['previous'];
    $origins = ['self' => 'la propia persona', 'issue' => 'al emitir', 'admin' => 'administración'];
    squuad_cert_log(sprintf(
        'Documento de identidad %s del usuario %d por el usuario %d (%s): %s%s',
        $previous ? 'cambiado' : 'registrado',
        $user_id,
        get_current_user_id(),
        $origins[$origin] ?? $origin,
        squuad_cert_id_document_mask($checked['identifier'], $checked['type']['prefix']),
        $previous ? ' (antes: ' . squuad_cert_id_document_mask($previous['identifier'], (string) ($previous['type']['prefix'] ?? '')) . ')' : ''
    ), $previous ? 'id_document_changed' : 'id_document_added', [
        'user_id' => $user_id,
        'type_id' => $type_id,
        'origin' => $origin,
    ]);
    do_action('squuad_cert_id_document_saved', $user_id, $type_id, $origin);

    return $checked['identifier'];
}

/**
 * Campos del formulario (tipo + número), compartidos por el modal de Mi Cuenta, la bandeja del firmante, el perfil y
 * la emisión. $prefix_id evita ids repetidos si hay dos formularios en la página. $compact (Emitir): sin texto de
 * ayuda, etiquetas solo para lectores de pantalla y los dos campos en una línea.
 */
function squuad_cert_id_document_fields_html(string $prefix_id, int $selected_type = 0, string $number = '', string $type_name = 'squuad_cert_id_type', string $number_name = 'squuad_cert_id_number', bool $required = true, bool $compact = false): string
{
    $req = $required ? ' required' : '';
    $types = squuad_cert_id_document_types(true);
    $current = $selected_type ? squuad_cert_id_document_type($selected_type) : null;
    if ($current && !$current['active']) {
        $types = [$current['id'] => $current] + $types; // el tipo que ya tiene la persona, aunque se haya desactivado
    }
    $label_class = $compact ? ' class="screen-reader-text"' : '';
    $html = '<span class="squuad-cert-iddoc-field"><label for="' . esc_attr($prefix_id . '-type') . '"' . $label_class . '>' . esc_html__('Document type', 'wp-certificates') . '</label>'
        . '<select id="' . esc_attr($prefix_id . '-type') . '" name="' . esc_attr($type_name) . '"' . $req . '>'
        . '<option value="">' . esc_html($compact ? __('Type…', 'wp-certificates') : __('Choose…', 'wp-certificates')) . '</option>';
    foreach ($types as $type) {
        $html .= '<option value="' . (int) $type['id'] . '" data-format="' . esc_attr($type['format']) . '"' . selected($selected_type, $type['id'], false) . '>'
            . esc_html($compact ? $type['prefix'] : $type['prefix'] . ' — ' . squuad_cert_id_document_type_label($type)) . '</option>';
    }
    $html .= '</select></span>'
        . '<span class="squuad-cert-iddoc-field"><label for="' . esc_attr($prefix_id . '-number') . '"' . $label_class . '>' . esc_html__('Document number', 'wp-certificates') . '</label>'
        . '<input type="text" id="' . esc_attr($prefix_id . '-number') . '" name="' . esc_attr($number_name) . '" value="' . esc_attr($number) . '"' . $req
        . ' maxlength="40" autocomplete="off" spellcheck="false" inputmode="text"'
        . ($compact ? ' size="12" placeholder="' . esc_attr__('Number', 'wp-certificates') . '"' : ' aria-describedby="' . esc_attr($prefix_id . '-help') . '"') . '></span>';
    if (!$compact) {
        $html .= '<span class="squuad-cert-iddoc-help" id="' . esc_attr($prefix_id . '-help') . '">'
            . esc_html(sprintf(
                /* translators: 1: minimum length, 2: maximum length */
                __('Write it as it appears on the document; dots, dashes and spaces are removed. From %1$d to %2$d characters.', 'wp-certificates'),
                SQUUAD_CERT_ID_DOCUMENT_MIN_LENGTH,
                SQUUAD_CERT_ID_DOCUMENT_MAX_LENGTH
            )) . '</span>';
    }

    return $html;
}

/**
 * Clases del contenedor de los componentes eds-* en el admin: con el diseño Edusof encendido en una pantalla de los
 * plugins no hace falta nada; si no, se carga su CSS y los componentes van dentro de .eds-scope (como Apariencia).
 */
function squuad_cert_id_document_eds_scope(): string
{
    if (!class_exists('Edusof_UI') || !defined('EDUSOF_UI_URL')) {
        return '';
    }
    if (Edusof_UI::active_here() && Edusof_UI::is_plugin_screen()) {
        return '';
    }
    wp_enqueue_style('edusof-ui', EDUSOF_UI_URL . 'assets/edusof-ui.css', [], defined('EDUSOF_UI_VERSION') ? EDUSOF_UI_VERSION : null);

    return 'eds-scope eds-theme-' . Edusof_UI::theme() . ' eds-mode-' . (Edusof_UI::enabled() ? Edusof_UI::mode() : 'light');
}

/* ---------------------------------------------------------------------------------------------------------------
 * Formulario de la propia persona (modal de Mi Cuenta, «Documentos por firmar» y bandeja del firmante)
 * ------------------------------------------------------------------------------------------------------------ */

/**
 * Aviso y tipo elegido del último envío fallido de la persona (se muestran una vez). B3: no guarda el número escrito.
 */
function squuad_cert_id_document_flash(?array $flash = null): ?array
{
    static $read = false;
    static $saved = null;
    $key = 'squuad_cert_iddoc_flash_' . get_current_user_id();
    if (null !== $flash) {
        unset($flash['number']);
        set_transient($key, $flash, 5 * MINUTE_IN_SECONDS);
        return null;
    }
    // Se lee una vez por petición (puede haber dos formularios en la misma página: el modal y el de la lista)
    if (!$read) {
        $read = true;
        $saved = get_transient($key);
        if (false !== $saved) {
            delete_transient($key);
        }
    }

    return is_array($saved) ? $saved : null;
}

add_action('admin_post_squuad_cert_save_id_document', 'squuad_cert_id_document_handle_self');
function squuad_cert_id_document_handle_self(): void
{
    $back = wp_get_referer() ?: home_url('/');
    $redirect = is_string($_POST['redirect_to'] ?? null) ? wp_validate_redirect(esc_url_raw(wp_unslash($_POST['redirect_to'])), $back) : $back;
    if (!is_user_logged_in() || !check_admin_referer('squuad_cert_save_id_document', '_squuad_cert_iddoc_nonce')) {
        wp_die(esc_html__('Your session expired. Please reload the page.', 'wp-certificates'), 403);
    }
    $type_id = is_scalar($_POST['squuad_cert_id_type'] ?? null) ? absint($_POST['squuad_cert_id_type']) : 0;
    $number = is_string($_POST['squuad_cert_id_number'] ?? null) ? sanitize_text_field(wp_unslash($_POST['squuad_cert_id_number'])) : '';
    $result = squuad_cert_id_document_required()
        ? squuad_cert_id_document_save(get_current_user_id(), $type_id, $number, 'self')
        : new WP_Error('squuad_cert_id_off', __('This site does not ask for the identity document.', 'wp-certificates'));

    // Si se guardó, la página siguiente ya muestra el documento por firmar (esa es la respuesta): no queda aviso pendiente
    if (is_wp_error($result)) {
        squuad_cert_id_document_flash(['ok' => false, 'message' => $result->get_error_message(), 'type_id' => $type_id]);
    } else {
        delete_transient('squuad_cert_iddoc_flash_' . get_current_user_id()); // sin avisos viejos de intentos anteriores
    }
    wp_safe_redirect($redirect);
    exit;
}

/**
 * Formulario de la propia persona. $context: 'modal' (encima de la página, Mi Cuenta o cualquier página pública),
 * 'inline' (dentro de «Documentos por firmar») o 'admin' (bandeja del firmante del sistema, con las clases eds-*).
 */
function squuad_cert_id_document_render_self_form(string $context = 'inline'): void
{
    static $shown = 0;
    $shown++;
    $flash = squuad_cert_id_document_flash();
    $prefix_id = 'squuad-cert-iddoc-' . $context . '-' . $shown;
    $redirect = (is_ssl() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/');
    // Modo claro/oscuro del modal: el de Edusof UI si está encendido (claro, oscuro o según el dispositivo); si no, el
    // del dispositivo, como antes
    $color_mode = class_exists('Edusof_UI') && Edusof_UI::enabled() ? Edusof_UI::mode() : 'auto';
    include WP_C_PATH . 'certification/public/templates/id-document-form.php';
}
