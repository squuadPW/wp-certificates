<?php
declare(strict_types=1);

/**
 * Certificación - Solicitudes de firma (ADR 0002; ADR 0004, paso 3c).
 *
 * Una solicitud por titular, documento y ronda. El titular es la cuenta de WordPress que recibe el documento
 * (subject_type 'wp_user', subject_id = id de la cuenta): cada firmante es dueño del documento. Borrador, congelado
 * con la primera firma, estados y eventos sellados en la cadena de huellas (EDUEVT1). Los datos de EduSystem
 * (variables, requisito) se piden al proveedor con la ficha de estudiante de esa cuenta, si la tiene.
 *
 * Estados de una solicitud: open → partially_signed → signed → completed, o declined / closed_by_upload.
 * El contenido es un borrador (se puede regenerar) hasta la primera firma, que lo congela; después es inmutable.
 */

if (!defined('ABSPATH')) exit;

const SQUUAD_CERT_SIGNATURE_REQUEST_CLOSED = ['completed', 'declined', 'closed_by_upload'];
const SQUUAD_CERT_SIGNATURE_REQUEST_OPEN = ['open', 'partially_signed', 'signed'];
const SQUUAD_CERT_SIGNATURE_CONTENT_MAX_BYTES = 1048576; // 1 MB

/** Tipos de titular: la cuenta de WordPress que recibe el documento (Mi Cuenta) o la ficha de un estudiante de
 * EduSystem (documento emitido para firma desde su ficha, que solo firman los firmantes del sistema). */
// Definida también por el proveedor wp_user (includes/provider-wp-user.php), que se carga antes: una sola constante
defined('SQUUAD_CERT_SUBJECT_ACCOUNT') || define('SQUUAD_CERT_SUBJECT_ACCOUNT', 'wp_user');
const SQUUAD_CERT_SUBJECT_STUDENT = 'edusystem_student';

/** Las tablas de firma de wp-certificates llegan con la versión 9 de su esquema (core/schema/signatures.php). */
function squuad_cert_signature_requests_enabled(): bool
{
    return version_compare((string) get_option('wp_c_db_version'), '9', '>=');
}

/**
 * Ficha de estudiante de EduSystem que corresponde a una cuenta (para sus variables y su requisito), o 0. La da el
 * proveedor del titular (subjects_for_user); wp-certificates no lee las tablas de EduSystem.
 */
function squuad_cert_account_student_id(int $user_id): int
{
    static $cache = [];
    if ($user_id <= 0) {
        return 0;
    }
    if (!isset($cache[$user_id])) {
        $provider = function_exists('squuad_cert_subject_type') ? squuad_cert_subject_type('edusystem_student') : null;
        $ids = ($provider && !empty($provider['subjects_for_user'])) ? (array) call_user_func($provider['subjects_for_user'], $user_id) : [];
        $cache[$user_id] = (int) ($ids[0] ?? 0);
    }

    return $cache[$user_id];
}

/** Ficha de estudiante de EduSystem de una solicitud (para sus variables, su requisito y su libro), o 0. */
function squuad_cert_request_student_id(object $request): int
{
    return SQUUAD_CERT_SUBJECT_STUDENT === (string) $request->subject_type
        ? (int) $request->subject_id
        : squuad_cert_account_student_id((int) $request->subject_id);
}

/** Nombre visible del titular de una solicitud: el de la cuenta o, si es una ficha, el que da el proveedor. */
function squuad_cert_request_subject_label(object $request): string
{
    if (SQUUAD_CERT_SUBJECT_STUDENT === (string) $request->subject_type) {
        $provider = function_exists('squuad_cert_subject_type') ? squuad_cert_subject_type(SQUUAD_CERT_SUBJECT_STUDENT) : null;

        return ($provider && !empty($provider['label'])) ? (string) call_user_func($provider['label'], (int) $request->subject_id) : '';
    }

    return squuad_cert_account_name((int) $request->subject_id);
}

/**
 * Datos de una solicitud para los eventos que wp-certificates avisa a otros plugins (ADR 0004, sección 4.4):
 * squuad_cert_request_created, _signed, _completed, _declined y _closed_by_upload. Los oyentes deben ser idempotentes.
 */
function squuad_cert_request_event_payload(object $request): array
{
    return [
        'id' => (int) $request->id,
        'subject_type' => (string) $request->subject_type,
        'subject_id' => (int) $request->subject_id,
        'student_id' => squuad_cert_request_student_id($request),
        'document_code' => (string) $request->document_id,
        'document_certificate_id' => (int) ($request->document_certificate_id ?? 0),
        'round' => (int) $request->round,
        'external_ref' => (int) ($request->external_ref ?? 0),
        'status' => (string) $request->status,
        'content_sha256' => (string) ($request->content_sha256 ?? ''),
    ];
}

/** Nombre de una cuenta: nombre y apellido, o el nombre visible. */
function squuad_cert_account_name(int $user_id): string
{
    $user = $user_id > 0 ? get_userdata($user_id) : false;

    return $user ? (trim($user->first_name . ' ' . $user->last_name) ?: (string) $user->display_name) : '';
}

/**
 * Requisito de EduSystem enlazado al documento para la ficha de la cuenta (external_ref del proveedor: el requisito cuya
 * configuración eligió este documento en «Document template»), o null si no hay.
 */
function squuad_cert_signature_external_ref(int $user_id, string $document_id, int $document_certificate_id = 0): ?int
{
    $student_id = squuad_cert_account_student_id($user_id);
    $provider = $student_id && function_exists('squuad_cert_subject_type') ? squuad_cert_subject_type('edusystem_student') : null;
    $ref = ($provider && !empty($provider['external_ref'])) ? (int) call_user_func($provider['external_ref'], $student_id, $document_id, $document_certificate_id) : 0;

    return $ref > 0 ? $ref : null;
}

/** Solicitud por id, o null. */
function squuad_cert_signature_request_get(int $request_id): ?object
{
    global $wpdb;

    if (!$request_id || !squuad_cert_signature_requests_enabled()) {
        return null;
    }
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}squuad_cert_requests WHERE id = %d",
        $request_id
    ));

    return $row ?: null;
}

/** Última ronda de un documento de un titular (abierta o cerrada), o null si nunca tuvo solicitud. */
function squuad_cert_signature_request_latest(int $subject_id, string $document_id, string $subject_type = SQUUAD_CERT_SUBJECT_ACCOUNT): ?object
{
    global $wpdb;

    if (!$subject_id || '' === $document_id || !squuad_cert_signature_requests_enabled()) {
        return null;
    }
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}squuad_cert_requests
         WHERE subject_type = %s AND subject_id = %d AND document_id = %s ORDER BY round DESC LIMIT 1",
        $subject_type,
        $subject_id,
        $document_id
    ));

    return $row ?: null;
}

/**
 * Solicitud abierta de un documento de una cuenta; si no hay (nunca tuvo o la última ronda está cerrada), la crea con
 * la ronda siguiente. Bajo un bloqueo por (sitio, titular, documento) y con la clave única (titular, documento,
 * ronda): si dos peticiones abren a la vez, la segunda recibe la de la primera.
 *
 * $subject_id: la cuenta dueña del documento (o la ficha, con $subject_type SQUUAD_CERT_SUBJECT_STUDENT). $signers: ['holder_user_id' => int] (la cuenta que firma su puesto;
 * 0 en un documento emitido que solo firman los firmantes del sistema). $external_ref: requisito de EduSystem si el
 * documento está enlazado. Devuelve null si el esquema no está listo o si falla.
 */
function squuad_cert_signature_request_get_or_create(
    int $subject_id,
    string $document_id,
    array $signers,
    ?int $external_ref = null,
    string $template_version_sha256 = '',
    int $document_certificate_id = 0,
    string $origin = 'opened',
    string $subject_type = SQUUAD_CERT_SUBJECT_ACCOUNT
): ?object {
    global $wpdb;

    if (!$subject_id || '' === $document_id || strlen($document_id) > 100 || !squuad_cert_signature_requests_enabled()) {
        return null;
    }

    $latest = squuad_cert_signature_request_latest($subject_id, $document_id, $subject_type);
    if ($latest && in_array($latest->status, SQUUAD_CERT_SIGNATURE_REQUEST_OPEN, true)) {
        return $latest;
    }

    $lock = 'squuad_cert_req_' . substr(hash('sha256', DB_NAME . '|' . $wpdb->prefix . '|' . $subject_type . '|' . $subject_id . '|' . $document_id), 0, 40);
    if ('1' !== (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock))) {
        return null;
    }

    try {
        // Bajo el bloqueo se vuelve a leer: otra petición pudo crearla mientras esperábamos
        $latest = squuad_cert_signature_request_latest($subject_id, $document_id, $subject_type);
        if ($latest && in_array($latest->status, SQUUAD_CERT_SIGNATURE_REQUEST_OPEN, true)) {
            return $latest;
        }

        $round = $latest ? (int) $latest->round + 1 : 1;
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->prefix}squuad_cert_requests
                (subject_type, subject_id, document_id, round, external_ref, status, template_version_sha256, created_at_utc,
                 created_by)
             VALUES (%s, %d, %s, %d, %s, 'open', %s, UTC_TIMESTAMP(), %d)",
            $subject_type,
            $subject_id,
            $document_id,
            $round,
            null === $external_ref ? null : (string) $external_ref,
            '' === $template_version_sha256 ? null : $template_version_sha256,
            get_current_user_id()
        ));

        $request = squuad_cert_signature_request_latest($subject_id, $document_id, $subject_type);
        if ($request && (int) $request->round === $round && $wpdb->insert_id) {
            // Esquema v6 (ADR 0003): firmantes fijados en su tabla (con nombre en ese momento) y sellados en 'created'
            $fixed = function_exists('squuad_cert_request_signers_fix')
                ? squuad_cert_request_signers_fix($request, $signers, $document_certificate_id, $origin)
                : [];
            squuad_cert_signature_request_log_event((int) $request->id, 'created', ['round' => $round] + ($fixed ? ['signers' => $fixed] : []));
        }

        return $request;
    } finally {
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
    }
}

/**
 * Ejecuta $callback bajo un bloqueo por solicitud (GET_LOCK con el nombre del sitio): el borrador y el congelado no
 * se pueden cruzar. Devuelve el resultado del callback, o $on_busy si no se obtiene el bloqueo.
 */
function squuad_cert_signature_request_locked(int $request_id, callable $callback, $on_busy = null)
{
    global $wpdb;

    $lock = 'squuad_cert_rc_' . substr(hash('sha256', DB_NAME . '|' . $wpdb->prefix . '|' . $request_id), 0, 40);
    if ('1' !== (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock))) {
        return $on_busy;
    }
    try {
        return $callback();
    } finally {
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
    }
}

/**
 * Guarda (o sustituye) el borrador del contenido de una solicitud y devuelve su sha256. Solo mientras no esté
 * congelada: después de la primera firma el contenido es inmutable y devuelve null. El hash se calcula sobre los
 * bytes exactos que se guardan (UTF-8, sin normalizar). Límite: SQUUAD_CERT_SIGNATURE_CONTENT_MAX_BYTES.
 */
function squuad_cert_signature_request_save_draft(int $request_id, string $html): ?string
{
    return squuad_cert_signature_request_locked($request_id, static fn() => squuad_cert_signature_request_save_draft_unlocked($request_id, $html));
}

function squuad_cert_signature_request_save_draft_unlocked(int $request_id, string $html): ?string
{
    global $wpdb;

    $request = squuad_cert_signature_request_get($request_id);
    if (!$request || null !== $request->frozen_at_utc || !in_array($request->status, SQUUAD_CERT_SIGNATURE_REQUEST_OPEN, true)) {
        return null;
    }
    if (strlen($html) > SQUUAD_CERT_SIGNATURE_CONTENT_MAX_BYTES) {
        return null;
    }

    $sha256 = hash('sha256', $html);
    if ($sha256 === (string) $request->content_sha256) {
        return $sha256; // mismo borrador: nada que cambiar
    }

    // Primero la solicitud, solo si sigue sin congelar; después el contenido. Si alguien intentara congelar entre las
    // dos operaciones, el contenido guardado no coincidiría con el hash y el congelado fallaría (nunca al revés).
    $updated = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}squuad_cert_requests SET content_sha256 = %s
         WHERE id = %d AND frozen_at_utc IS NULL",
        $sha256,
        $request_id
    ));
    if (!$updated) {
        return null;
    }
    $wpdb->query($wpdb->prepare(
        "REPLACE INTO {$wpdb->prefix}squuad_cert_request_contents (request_id, content, content_sha256, updated_at_utc)
         VALUES (%d, %s, %s, UTC_TIMESTAMP())",
        $request_id,
        $html,
        $sha256
    ));

    return $sha256;
}

/**
 * Contenido de una solicitud (borrador o congelado), comprobando que su sha256 coincide con el guardado en la
 * solicitud. Devuelve null si no hay contenido o si no coincide (contenido alterado).
 */
function squuad_cert_signature_request_content(int $request_id): ?string
{
    global $wpdb;

    $request = squuad_cert_signature_request_get($request_id);
    if (!$request || !$request->content_sha256) {
        return null;
    }
    $content = $wpdb->get_var($wpdb->prepare(
        "SELECT content FROM {$wpdb->prefix}squuad_cert_request_contents WHERE request_id = %d",
        $request_id
    ));
    if (null === $content || !hash_equals((string) $request->content_sha256, hash('sha256', (string) $content))) {
        return null;
    }

    return (string) $content;
}

/**
 * Congela el contenido con la primera firma. Solo si sigue siendo borrador y si $shown_sha256 (lo que el navegador
 * mostró al firmante) es el borrador vigente. Devuelve true si la congeló esta llamada o ya estaba congelada con
 * ese mismo contenido; false si el borrador cambió (el firmante debe revisarlo de nuevo) o si la solicitud no lo
 * admite.
 */
function squuad_cert_signature_request_freeze(int $request_id, string $shown_sha256, int $user_id): bool
{
    return (bool) squuad_cert_signature_request_locked($request_id, static fn() => squuad_cert_signature_request_freeze_unlocked($request_id, $shown_sha256, $user_id), false);
}

function squuad_cert_signature_request_freeze_unlocked(int $request_id, string $shown_sha256, int $user_id): bool
{
    global $wpdb;

    $request = squuad_cert_signature_request_get($request_id);
    if (!$request || !in_array($request->status, SQUUAD_CERT_SIGNATURE_REQUEST_OPEN, true) || '' === $shown_sha256) {
        return false;
    }
    if (null !== $request->frozen_at_utc) {
        return hash_equals((string) $request->content_sha256, $shown_sha256);
    }
    // El contenido guardado tiene que ser íntegro y ser el que vio el firmante
    if (null === squuad_cert_signature_request_content($request_id) || !hash_equals((string) $request->content_sha256, $shown_sha256)) {
        return false;
    }

    $updated = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}squuad_cert_requests
         SET frozen_at_utc = UTC_TIMESTAMP(), frozen_by = %d
         WHERE id = %d AND frozen_at_utc IS NULL AND content_sha256 = %s",
        $user_id,
        $request_id,
        $shown_sha256
    ));
    if ($updated) {
        squuad_cert_signature_request_log_event($request_id, 'frozen', ['content_sha256' => $shown_sha256], $user_id);
        return true;
    }

    // Otra petición la congeló a la vez: vale si fue con el mismo contenido
    $request = squuad_cert_signature_request_get($request_id);
    return $request && null !== $request->frozen_at_utc && hash_equals((string) $request->content_sha256, $shown_sha256);
}

/**
 * Cambia el estado de una solicitud solo si su estado actual es uno de $from (UPDATE condicional): impide, p. ej.,
 * firmar una solicitud declinada o finalizarla dos veces. $fields: columnas adicionales a fijar en el mismo UPDATE
 * (p. ej. completed_at_utc, final_pdf_sha256). Devuelve true si cambió.
 */
function squuad_cert_signature_request_transition(int $request_id, array $from, string $to, array $fields = [], array $event_data = []): bool
{
    global $wpdb;

    $allowed_fields = [
        'completed_at_utc', 'final_attachment_id', 'final_pdf_sha256', 'final_uploaded_by', 'declined_at_utc',
        'declined_by', 'decline_reason', 'closed_by', 'closed_upload_sha256', 'external_ref',
    ];
    $valid_states = array_merge(SQUUAD_CERT_SIGNATURE_REQUEST_OPEN, SQUUAD_CERT_SIGNATURE_REQUEST_CLOSED);
    if (!$request_id || !in_array($to, $valid_states, true) || !$from || array_diff($from, $valid_states)) {
        return false;
    }

    $sets = ['status = %s'];
    $values = [$to];
    foreach ($fields as $column => $value) {
        if (!in_array($column, $allowed_fields, true)) {
            return false;
        }
        if (null === $value) {
            $sets[] = "{$column} = NULL";
            continue;
        }
        $sets[] = "{$column} = %s";
        $values[] = (string) $value;
    }
    $values[] = $request_id;
    $placeholders = implode(', ', array_fill(0, count($from), '%s'));
    $values = array_merge($values, $from);

    $updated = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}squuad_cert_requests SET " . implode(', ', $sets) . "
         WHERE id = %d AND status IN ({$placeholders})",
        $values
    ));
    if ($updated) {
        squuad_cert_signature_request_log_event($request_id, 'status_' . $to, array_intersect_key($fields, array_flip(['final_pdf_sha256', 'closed_upload_sha256', 'decline_reason'])) + $event_data);
    }

    return (bool) $updated;
}

/**
 * Mensaje sellado de un evento de solicitud (formato EDUEVT1), en orden fijo. data se sella por su sha256 (el JSON
 * guardado, byte a byte). No cambiar sin subir la versión.
 */
function squuad_cert_signature_request_event_canonical(array $row): string
{
    $text = static fn($value): string => rawurlencode((string) ($value ?? ''));

    return implode("\n", [
        'EDUEVT1',
        (string) (int) $row['chain_seq'],
        $text($row['key_id']),
        (string) (int) $row['request_id'],
        $text($row['event_type']),
        (string) (int) $row['actor_user_id'],
        (string) (int) $row['switched_from'],
        (string) $row['created_at_utc'],
        hash('sha256', (string) ($row['data'] ?? '')),
        (string) $row['prev_fingerprint'],
    ]);
}

/**
 * Registra un evento de una solicitud (creación, congelado, firma, cambios de estado...) sellado con HMAC en la
 * misma cadena que las firmas (mismo bloqueo y misma cabeza, ADR 0002). Si no hay clave o no se obtiene el
 * bloqueo, el evento se guarda sin huella y se anota en el log (el verificador lo mostrará). Devuelve el id del
 * evento o 0.
 */
function squuad_cert_signature_request_log_event(int $request_id, string $event_type, array $data = [], ?int $actor_user_id = null): int
{
    global $wpdb;
    $table = $wpdb->prefix . 'squuad_cert_events';

    $row = [
        'request_id' => $request_id,
        'event_type' => substr($event_type, 0, 30),
        'actor_user_id' => null === $actor_user_id ? get_current_user_id() : $actor_user_id,
        'switched_from' => function_exists('squuad_cert_signature_session_switched_from') ? squuad_cert_signature_session_switched_from() : 0,
        'created_at_utc' => gmdate('Y-m-d H:i:s'),
        'data' => $data ? wp_json_encode($data) : null,
    ];

    $key = function_exists('squuad_cert_signature_current_key') ? squuad_cert_signature_current_key() : null;
    $lock = squuad_cert_signature_lock_name();
    $locked = $key && '1' === (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock));
    if (!$locked) {
        $wpdb->insert($table, $row);
        $id = (int) $wpdb->insert_id;
        squuad_cert_log(sprintf('Evento %d de la solicitud %d guardado sin huella', $id, $request_id), 'signature_error');
        return $id;
    }

    try {
        [$key_id, $key_bytes] = squuad_cert_signature_current_key() ?? $key;
        $head = $wpdb->get_row("SELECT last_seq, head_fingerprint FROM {$wpdb->prefix}squuad_cert_chain WHERE id = 1");
        $row['chain_seq'] = ($head ? (int) $head->last_seq : 0) + 1;
        $row['key_id'] = $key_id;
        $row['prev_fingerprint'] = ($head && '' !== $head->head_fingerprint) ? $head->head_fingerprint : str_repeat('0', 64);
        $row['fingerprint'] = hash_hmac('sha256', squuad_cert_signature_request_event_canonical($row), $key_bytes);

        if (!$wpdb->insert($table, $row)) {
            return 0;
        }
        $id = (int) $wpdb->insert_id;
        squuad_cert_signature_chain_set_head((int) $row['chain_seq'], (string) $row['fingerprint']);

        return $id;
    } finally {
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
    }
}

/**
 * Estado de integridad de un evento de solicitud: verified, altered, unknown_key, retired_key, chain_broken o
 * missing (sin huella). $previous_fingerprint: huella del eslabón anterior si ya se conoce.
 */
function squuad_cert_signature_request_verify_event(object $row, ?string $previous_fingerprint = null): string
{
    if (empty($row->fingerprint)) {
        return 'missing';
    }
    $key = squuad_cert_signature_key_by_id((string) $row->key_id);
    if (null === $key) {
        return 'unknown_key';
    }
    if (!hash_equals(hash_hmac('sha256', squuad_cert_signature_request_event_canonical((array) $row), $key), (string) $row->fingerprint)) {
        return 'altered';
    }
    $retired_at = squuad_cert_signature_key_retired_at((string) $row->key_id);
    if (null !== $retired_at && strtotime((string) $row->created_at_utc . ' UTC') > strtotime($retired_at . ' UTC') + 60) {
        return 'retired_key';
    }
    $seq = (int) $row->chain_seq;
    $prev = $seq > 1
        ? (null !== $previous_fingerprint ? $previous_fingerprint : squuad_cert_signature_chain_fingerprint_at($seq - 1))
        : str_repeat('0', 64);
    if (null === $prev || !hash_equals((string) $prev, (string) $row->prev_fingerprint)) {
        return 'chain_broken';
    }

    return 'verified';
}

/** Huella del evento de creación de una solicitud (se sella en cada firma EDUSIG2), o '' si no existe. */
function squuad_cert_signature_request_created_fingerprint(int $request_id): string
{
    global $wpdb;

    return (string) $wpdb->get_var($wpdb->prepare(
        "SELECT fingerprint FROM {$wpdb->prefix}squuad_cert_events
         WHERE request_id = %d AND event_type = 'created' ORDER BY id ASC LIMIT 1",
        $request_id
    ));
}

/* ---------------------------------------------------------------------------------------------------------------
 * Paso 2: firmantes, documento pendiente y contenido del borrador
 * ------------------------------------------------------------------------------------------------------------ */

/** Marcador fijo que sustituye a {{signature_section}} en el contenido (los recuadros se pintan al mostrarlo). */
const SQUUAD_CERT_SIGNATURE_SLOT = '<div data-edusig-slot="signature_section"></div>';
/** Marcador fijo que sustituye a {{qrcode}} en el contenido. */
const SQUUAD_CERT_SIGNATURE_QR_SLOT = '<div data-edusig-slot="qrcode"></div>';

/** Puesto del usuario en la solicitud según los firmantes fijados en ella ('role:<rol>', 'signer:<id>') o '' si no firma. */
function squuad_cert_signature_request_role(object $request, int $user_id): string
{
    if (!$user_id) {
        return '';
    }
    // ADR 0003: hueco del usuario entre los firmantes fijados en la solicitud ('role:<rol>', 'signer:<id>')
    if (function_exists('squuad_cert_request_signers')) {
        foreach (squuad_cert_request_signers($request) as $signer) {
            if ($signer['user_id'] === $user_id) {
                return $signer['slot_key'];
            }
        }
        return '';
    }
    return '';
}

/**
 * ¿El usuario es quien carga el documento sin firmarlo? (documento automático con campos adicionales en el que el
 * estudiante no tiene puesto de firma: responde, revisa y guarda; ADR 0004, decisión del 2026-10-01).
 */
function squuad_cert_signature_request_is_holder(object $request, int $user_id): bool
{
    return $user_id > 0 && SQUUAD_CERT_SUBJECT_ACCOUNT === (string) $request->subject_type && (int) $request->subject_id === $user_id
        && '' === squuad_cert_signature_request_role($request, $user_id);
}

/** Puestos que deben firmar la solicitud (los firmantes fijados en ella). */
function squuad_cert_signature_request_required_roles(object $request): array
{
    if (function_exists('squuad_cert_request_signers')) {
        return array_values(array_map(
            static fn(array $signer): string => $signer['slot_key'],
            array_filter(squuad_cert_request_signers($request), static fn(array $signer): bool => $signer['required'])
        ));
    }
    return [];
}

/** Roles que ya firmaron dentro de la solicitud (firmas vivas con ese request_id). */
function squuad_cert_signature_request_signed_roles(int $request_id): array
{
    global $wpdb;

    return array_map('strval', $wpdb->get_col($wpdb->prepare(
        "SELECT signer_role FROM {$wpdb->prefix}squuad_cert_signatures WHERE request_id = %d",
        $request_id
    )));
}

/**
 * Siguiente documento automático que el usuario tiene que firmar o rellenar, por prioridad (0 la más urgente; empate:
 * el más antiguo). Ver squuad_cert_signature_user_documents().
 *
 * Un documento de un estudiante está resuelto si su última ronda está completada o cerrada por subida, o si (sin
 * solicitudes) su requisito de EduSystem ya está subido o aprobado. Si el usuario ya hizo su parte en la
 * ronda abierta, se pasa al siguiente (faltan los firmantes del sistema).
 *
 * Devuelve ['subject_id' (la cuenta), 'student_id' (su ficha de EduSystem o 0), 'document', 'request' (o null),
 * 'legacy_partial'] o null.
 */
function squuad_cert_signature_pending_for_user(WP_User $user, string $selection = ''): ?array
{
    $first = null;
    foreach (squuad_cert_signature_user_documents($user) as $item) {
        if ('to_sign' !== $item['state']) {
            continue;
        }
        // Documento elegido en Mi Cuenta ("<estudiante>-<documento>"); si no está entre los pendientes, el primero
        if ('' !== $selection && $selection === $item['subject_id'] . '-' . $item['document']->id) {
            return $item;
        }
        $first = $first ?? $item;
    }

    return $first;
}

/**
 * Documentos automáticos que conciernen al usuario (ADR 0003, paso 5b), por prioridad: los que le toca firmar o
 * rellenar ('to_sign'), los que ya hizo y esperan a otro firmante ('waiting') y los que tienen todas las firmas y
 * esperan su PDF final ('pdf', paso 6b).
 *
 * Regla (ADR 0004, decisión del 2026-10-01): un documento automático activo se muestra solo si pide firma (variable de
 * firma en la plantilla y firmantes en el panel) o tiene campos adicionales, y solo a quien tiene que firmarlo o
 * rellenarlo: al estudiante si el panel pide su firma o si el documento tiene campos. Nada más lo activa ni lo
 * bloquea. Una solicitud en curso sigue hasta el final aunque cambie la configuración.
 */
function squuad_cert_signature_user_documents(WP_User $user): array
{
    $items = [];
    global $wpdb;

    if (!$user->ID || !squuad_cert_signature_requests_enabled()) {
        return [];
    }
    // Firma por roles (paso 3d): a la cuenta le llega cada automático cuya política pide uno de sus roles. El documento
    // es de la cuenta; su ficha de EduSystem, si la tiene, solo aporta datos (variables, requisito)
    $subject_id = (int) $user->ID;
    $student_id = squuad_cert_account_student_id($subject_id);

    $table_documents = $wpdb->prefix . 'documents_certificates';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_documents)) !== $table_documents) {
        return [];
    }
    $documents = $wpdb->get_results("SELECT * FROM {$table_documents} WHERE `type` = 'automatic' AND `status` = 1 ORDER BY `priority` ASC, id ASC");
    $provider = $student_id && function_exists('squuad_cert_subject_type') ? squuad_cert_subject_type('edusystem_student') : null;

    foreach ($documents as $document) {
        $document_id = (string) $document->document_identificator;
        if ('' === $document_id) {
            continue;
        }
        $request = squuad_cert_signature_request_latest($subject_id, $document_id);
        // Firmantes del documento (ADR 0003, paso 4): la política decide solo para solicitudes nuevas; una solicitud
        // en curso conserva sus firmantes aunque la configuración haya cambiado
        $in_progress = $request && in_array($request->status, SQUUAD_CERT_SIGNATURE_REQUEST_OPEN, true);
        if (!$in_progress) {
            // Solo si pide firma o tiene campos, y solo a las cuentas con uno de los roles del panel: firman si la
            // plantilla tiene variable de firma; si no, solo rellenan los campos
            $automatic = squuad_cert_automatic_status($document);
            if (!$automatic['shown'] || '' === squuad_cert_signing_policy_role_for_user(squuad_cert_signing_policy($document), $user)) {
                continue;
            }
        }
        if ($request && in_array($request->status, ['completed', 'closed_by_upload'], true)) {
            continue;
        }
        $state = 'to_sign';
        if ($in_progress) {
            $role_here = squuad_cert_signature_request_role($request, $subject_id);
            $holder = '' === $role_here && squuad_cert_signature_request_is_holder($request, $subject_id);
            if ('' === $role_here && !$holder) {
                continue; // no firma ni rellena aquí
            }
            if ('signed' === $request->status) {
                $state = 'pdf'; // todas las firmas; falta generar el PDF final
            } elseif ($holder ? null !== $request->frozen_at_utc : in_array($role_here, squuad_cert_signature_request_signed_roles((int) $request->id), true)) {
                $state = 'waiting'; // ya hizo su parte (firmó o guardó sus respuestas); faltan otros firmantes
            }
        } elseif (!$request && $provider && !empty($provider['external_state'])) {
            // Sin solicitudes: requisito de EduSystem ya resuelto por otra vía (subido o aprobado)
            $ref = squuad_cert_signature_external_ref($subject_id, $document_id, (int) $document->id);
            if ($ref && in_array((string) call_user_func($provider['external_state'], $ref), ['uploaded', 'approved'], true)) {
                continue;
            }
        }

        $items[] = [
            'state' => $state,
            'subject_id' => $subject_id,
            'student_id' => $student_id,
            'document' => $document,
            'request' => $in_progress ? $request : null,
            // Aviso de "fírmelo de nuevo" durante toda la ronda 1 de un documento con firma del modelo anterior
            'legacy_partial' => (!$request || 1 === (int) $request->round)
                && squuad_cert_signature_has_legacy_partial($subject_id, $document_id),
        ];
    }

    return $items;
}

/**
 * ¿Hay una firma del modelo anterior (en users_signatures, de EduSystem; solo se lee) de este documento hecha por esta
 * cuenta? Se usa para el aviso de "fírmelo de nuevo" (ADR 0002, punto 10, opción b).
 */
function squuad_cert_signature_has_legacy_partial(int $user_id, string $document_id): bool
{
    global $wpdb;

    $table = $wpdb->prefix . 'users_signatures';
    if (!$user_id || $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
        return false;
    }

    return (bool) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$table} WHERE document_id = %s AND user_id = %s LIMIT 1",
        $document_id,
        (string) $user_id
    ));
}

/**
 * Variables de plantilla preparadas para el contenido que se firma (ADR 0002, punto 2): los textos se escapan con
 * esc_html (también las llaves, para que un dato como "{{signature_section}}" no se expanda), las funciones que
 * devuelven texto se evalúan y se escapan, y las secciones que dependen del estado de las firmas se sustituyen por
 * marcadores fijos. Las tablas HTML se dejan como las genera el plugin.
 */
function squuad_cert_signature_escape_replacements(array $replacements): array
{
    $text_closures = ['parent_full_name', 'parent_email', 'parent_cell', 'parent_identification', 'institute_name',
        'institute_address', 'institute_phone', 'start_academic_year', 'end_academic_year', 'today'];
    $escape = static fn($value): string => str_replace(['{', '}'], ['&#123;', '&#125;'], esc_html((string) $value));

    // Huecos fijos de las firmas y del QR: siempre, los aporte o no otro plugin (variables generales de wp-certificates)
    $replacements['signature_section'] = ['value' => SQUUAD_CERT_SIGNATURE_SLOT, 'wrap' => false];
    $replacements['qrcode'] = $replacements['qrcode'] ?? ['value' => '', 'wrap' => false];
    foreach ($replacements as $key => $config) {
        if (!is_array($config) || !array_key_exists('value', $config)) {
            continue;
        }
        $value = $config['value'];
        if ('signature_section' === $key) {
            $replacements[$key] = ['value' => SQUUAD_CERT_SIGNATURE_SLOT, 'wrap' => false];
            continue;
        }
        if ('qrcode' === $key) {
            $replacements[$key] = ['value' => SQUUAD_CERT_SIGNATURE_QR_SLOT, 'wrap' => false];
            continue;
        }
        if (!empty($config['wrap']) || in_array($key, $text_closures, true)) {
            if ($value instanceof Closure) {
                $value = $value();
            }
            $replacements[$key]['value'] = $escape($value);
        }
    }

    return $replacements;
}

/**
 * Incrusta en el contenido las imágenes de este mismo sitio (logos, sellos) como data: URI, para que el documento
 * se vea siempre igual aunque cambien los archivos. Solo archivos locales bajo ABSPATH, de hasta 300 KB cada uno y
 * mientras el contenido no pase del límite.
 */
function squuad_cert_signature_inline_images(string $html): string
{
    $home = trailingslashit(home_url());
    $site = trailingslashit(site_url());

    return (string) preg_replace_callback('/(<img\b[^>]*\bsrc=)(["\'])([^"\']+)\2/i', static function ($match) use ($home, $site, $html) {
        $url = html_entity_decode($match[3]);
        foreach ([$site, $home] as $base) {
            if (0 === strpos($url, $base)) {
                $path = realpath(ABSPATH . ltrim(substr((string) strtok($url, '?'), strlen($base)), '/'));
                if ($path && 0 === strpos($path, realpath(ABSPATH)) && is_file($path) && filesize($path) <= 307200
                    && strlen($html) + filesize($path) * 1.4 < SQUUAD_CERT_SIGNATURE_CONTENT_MAX_BYTES) {
                    $mime = wp_check_filetype($path)['type'] ?? '';
                    if ($mime && 0 === strpos($mime, 'image/')) {
                        return $match[1] . $match[2] . 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($path)) . $match[2];
                    }
                }
            }
        }
        return $match[0];
    }, $html);
}

/**
 * HTML para mostrar el contenido de una solicitud al firmante: los marcadores se sustituyen por los recuadros de
 * firma y el hueco del QR. El contenido guardado (y su huella) no cambia.
 */
function squuad_cert_signature_render_content(string $content, ?object $request = null): string
{
    // Huecos institucionales (ADR 0003): la firma si ya existe o "pendiente"
    if ($request && function_exists('squuad_cert_request_signers') && false !== strpos($content, 'data-edusig-slot="signer:')) {
        foreach (squuad_cert_request_signers($request) as $signer) {
            if ($signer['phase'] >= 2) {
                $content = str_replace(squuad_cert_signer_slot_marker($signer['slot_key']), squuad_cert_signature_render_slot_box($request, $signer), $content);
            }
        }
    }

    // Recuadro de quien recibe el documento: donde la plantilla lo coloca ({{signature_role_<rol>}}) o, si no, en
    // {{signature_section}}. Un hueco del representante de una solicitud antigua queda vacío.
    $content = str_replace('<div data-edusig-slot="parent"></div>', '', $content);
    $holder = $request ? squuad_cert_request_holder_slot($request) : '';
    $marker = '' !== $holder ? squuad_cert_signer_slot_marker($holder) : '';
    $box = '' !== $holder ? squuad_cert_signature_pad_box($holder, $request) : '';
    $section = $box;
    if ('' !== $marker && false !== strpos($content, $marker)) {
        $content = str_replace($marker, $box, $content);
        $section = '';
    }

    return str_replace(
        [SQUUAD_CERT_SIGNATURE_SLOT, SQUUAD_CERT_SIGNATURE_QR_SLOT],
        [$section, '<div id="qrcode"></div>'],
        $content
    );
}

/** Registra un evento de la solicitud solo si todavía no existe uno de ese tipo. */
function squuad_cert_signature_request_log_event_once(int $request_id, string $event_type, array $data = []): void
{
    global $wpdb;

    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}squuad_cert_events WHERE request_id = %d AND event_type = %s LIMIT 1",
        $request_id,
        $event_type
    ));
    if (!$exists) {
        squuad_cert_signature_request_log_event($request_id, $event_type, $data);
    }
}

/* ---------------------------------------------------------------------------------------------------------------
 * Paso 3: consentimiento explícito (ADR 0002, punto 7)
 * ------------------------------------------------------------------------------------------------------------ */

/** Versión vigente del texto de consentimiento. Si el texto cambia, se añade una versión nueva y esta pasa a ella. */
const SQUUAD_CERT_SIGNATURE_CONSENT_CURRENT = 'v1';

/**
 * Texto de consentimiento de una versión, traducido al idioma actual, o null si la versión no existe. El cliente
 * solo envía la versión; el texto que se sella es el que el servidor mostró.
 */
function squuad_cert_signature_consent_text(string $version): ?string
{
    $texts = [
        'v1' => __('I agree to sign this document electronically. I understand that my electronic signature has the same validity as my handwritten signature, that it is recorded with the date, the time and the details of my connection, and that the signed document cannot be modified.', 'wp-certificates'),
    ];

    return $texts[$version] ?? null;
}

/**
 * Evidencia del consentimiento que aceptó el firmante: ['consent_version' => 'v1:es_ES', 'consent_sha256' => ...].
 * Sella la versión, el idioma y el sha256 del texto exacto mostrado. Devuelve null si la versión no es la vigente.
 */
function squuad_cert_signature_consent_evidence(string $version): ?array
{
    if (SQUUAD_CERT_SIGNATURE_CONSENT_CURRENT !== $version) {
        return null;
    }
    $text = squuad_cert_signature_consent_text($version);
    if (null === $text) {
        return null;
    }

    return [
        'consent_version' => substr($version . ':' . determine_locale(), 0, 20),
        'consent_sha256' => hash('sha256', $text),
    ];
}

/* ---------------------------------------------------------------------------------------------------------------
 * Paso 5: cierre por subida manual (ADR 0002, punto 9)
 * ------------------------------------------------------------------------------------------------------------ */

/**
 * Solicitud abierta (abierta, a medio firmar o firmada sin PDF) ligada a un requisito de EduSystem (external_ref), o
 * null. Sirve para saber si una subida manual la cerraría.
 */
function squuad_cert_signature_request_open_for_row(int $external_ref): ?object
{
    global $wpdb;

    if (!$external_ref || !squuad_cert_signature_requests_enabled()) {
        return null;
    }
    $request = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}squuad_cert_requests WHERE external_ref = %d ORDER BY round DESC, id DESC LIMIT 1",
        $external_ref
    ));

    return ($request && in_array($request->status, SQUUAD_CERT_SIGNATURE_REQUEST_OPEN, true)) ? $request : null;
}

/**
 * Un archivo subido a mano (por el usuario o por el admin) completa el documento sin firmas electrónicas: si tenía
 * una solicitud abierta, se cierra como closed_by_upload con el sha256 del archivo, quién lo subió y el motivo
 * (evento sellado). Las firmas que ya tuviera la solicitud no se anulan: quedan ligadas a ella. Una solicitud
 * completada no se cierra por subida. Devuelve true si cerró una solicitud.
 */
function squuad_cert_signature_request_close_by_upload(int $external_ref, string $file_path, int $user_id, string $reason = '', array $extra = []): bool
{
    $request = squuad_cert_signature_request_open_for_row($external_ref);
    if (!$request) {
        return false;
    }
    // La huella del archivo; si ya no está (orden entregada después de una pausa), la que se anotó al subirlo
    $sha256 = is_readable($file_path) ? (string) hash_file('sha256', $file_path) : (string) ($extra['file_sha256'] ?? '');

    return squuad_cert_signature_request_transition((int) $request->id, SQUUAD_CERT_SIGNATURE_REQUEST_OPEN, 'closed_by_upload', [
        'closed_by' => $user_id,
        'closed_upload_sha256' => $sha256,
        'external_ref' => $external_ref,
    ], [
        'uploaded_by' => $user_id,
        'reason' => $reason,
        'signed_roles' => squuad_cert_signature_request_signed_roles((int) $request->id),
    ] + array_intersect_key($extra, array_flip(['origin', 'during_pause', 'uploaded_at_utc', 'file_sha256'])));
}

/** Hueco de un firmante institucional pintado: su firma en SVG con la fecha, o "Pendiente de firma". */
function squuad_cert_signature_render_slot_box(object $request, array $signer): string
{
    global $wpdb;

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT signature, signed_at_utc FROM {$wpdb->prefix}squuad_cert_signatures WHERE request_id = %d AND signer_role = %s",
        $request->id,
        $signer['slot_key']
    ));
    if (!$row) {
        return '<div style="height:90px;display:flex;align-items:center;justify-content:center;color:#888;border:1px dashed #bbb">'
            . esc_html__('Pending signature', 'wp-certificates') . '</div>';
    }

    return squuad_cert_signature_svg((string) $row->signature, $signer['name'])
        . '<div style="font-size:10px;color:#666">' . esc_html(get_date_from_gmt((string) $row->signed_at_utc, 'Y-m-d H:i')) . ' UTC</div>';
}
