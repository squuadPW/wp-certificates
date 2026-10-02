<?php
declare(strict_types=1);

/**
 * EduSystem - Solicitudes de firma por documento de cada estudiante (ADR 0002).
 *
 * Esquema v5: solicitud por documento, estudiante y ronda; borrador, congelado con la primera firma, estados y
 * eventos sellados en la cadena de huellas (EDUEVT1).
 *
 * Estados de una solicitud: open → partially_signed → signed → completed, o declined / closed_by_upload.
 * El contenido es un borrador (se puede regenerar) hasta la primera firma, que lo congela; después es inmutable.
 */

if (!defined('ABSPATH')) exit;

const SQUUAD_CERT_SIGNATURE_REQUEST_CLOSED = ['completed', 'declined', 'closed_by_upload'];
const SQUUAD_CERT_SIGNATURE_REQUEST_OPEN = ['open', 'partially_signed', 'signed'];
const SQUUAD_CERT_SIGNATURE_CONTENT_MAX_BYTES = 1048576; // 1 MB

/** Las tablas de solicitudes y las columnas de solicitud llegan con la versión 5 del esquema. */
function squuad_cert_signature_requests_enabled(): bool
{
    return version_compare((string) get_option('edusystem_db_version'), '5', '>=');
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

/** Última ronda de un documento de un estudiante (abierta o cerrada), o null si nunca tuvo solicitud. */
function squuad_cert_signature_request_latest(int $student_id, string $document_id): ?object
{
    global $wpdb;

    if (!$student_id || '' === $document_id || !squuad_cert_signature_requests_enabled()) {
        return null;
    }
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}squuad_cert_requests
         WHERE student_id = %d AND document_id = %s ORDER BY round DESC LIMIT 1",
        $student_id,
        $document_id
    ));

    return $row ?: null;
}

/**
 * Solicitud abierta de un documento de un estudiante; si no hay (nunca tuvo o la última ronda está cerrada), la
 * crea con la ronda siguiente. Bajo un bloqueo por (sitio, estudiante, documento) y con la clave única
 * (student_id, document_id, round): si dos firmantes abren a la vez, el segundo recibe la del primero.
 *
 * $signers: ['student_user_id' => int] (la cuenta del estudiante; el representante no firma, parent_user_id queda 0).
 * Quedan fijados en la solicitud. Devuelve null si el esquema no está en v5 o si falla.
 */
function squuad_cert_signature_request_get_or_create(
    int $student_id,
    string $document_id,
    array $signers,
    ?int $student_document_id = null,
    string $template_version_sha256 = '',
    int $document_certificate_id = 0,
    string $origin = 'opened'
): ?object {
    global $wpdb;

    if (!$student_id || '' === $document_id || strlen($document_id) > 100 || !squuad_cert_signature_requests_enabled()) {
        return null;
    }

    $latest = squuad_cert_signature_request_latest($student_id, $document_id);
    if ($latest && in_array($latest->status, SQUUAD_CERT_SIGNATURE_REQUEST_OPEN, true)) {
        return $latest;
    }

    $lock = 'squuad_cert_req_' . substr(hash('sha256', DB_NAME . '|' . $wpdb->prefix . '|' . $student_id . '|' . $document_id), 0, 40);
    if ('1' !== (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock))) {
        return null;
    }

    try {
        // Bajo el bloqueo se vuelve a leer: otra petición pudo crearla mientras esperábamos
        $latest = squuad_cert_signature_request_latest($student_id, $document_id);
        if ($latest && in_array($latest->status, SQUUAD_CERT_SIGNATURE_REQUEST_OPEN, true)) {
            return $latest;
        }

        $round = $latest ? (int) $latest->round + 1 : 1;
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->prefix}squuad_cert_requests
                (student_id, document_id, round, student_document_id, student_user_id, parent_user_id, status,
                 template_version_sha256, created_at_utc, created_by)
             VALUES (%d, %s, %d, %s, %d, %d, 'open', %s, UTC_TIMESTAMP(), %d)",
            $student_id,
            $document_id,
            $round,
            null === $student_document_id ? null : (string) $student_document_id,
            (int) ($signers['student_user_id'] ?? 0),
            (int) ($signers['parent_user_id'] ?? 0),
            '' === $template_version_sha256 ? null : $template_version_sha256,
            get_current_user_id()
        ));

        $request = squuad_cert_signature_request_latest($student_id, $document_id);
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
        'declined_by', 'decline_reason', 'closed_by', 'closed_upload_sha256', 'student_document_id',
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
        if (function_exists('edusystem_set_log')) {
            edusystem_set_log(sprintf('Evento %d de la solicitud %d guardado sin huella', $id, $request_id), 'signature_error');
        }
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

/** Puesto del usuario en la solicitud según los firmantes fijados en ella ('student', 'signer:<id>') o '' si no firma. */
function squuad_cert_signature_request_role(object $request, int $user_id): string
{
    if (!$user_id) {
        return '';
    }
    // ADR 0003: hueco del usuario entre los firmantes fijados en la solicitud ('student', 'signer:<id>')
    if (function_exists('squuad_cert_request_signers')) {
        foreach (squuad_cert_request_signers($request) as $signer) {
            if ($signer['user_id'] === $user_id) {
                return $signer['slot_key'];
            }
        }
        return '';
    }
    // Solicitud sin firmantes fijados: firma el estudiante (el representante no firma, decisión del 2026-10-01)
    return (int) $request->student_user_id === $user_id ? 'student' : '';
}

/**
 * ¿El usuario es quien carga el documento sin firmarlo? (documento automático con campos adicionales en el que el
 * estudiante no tiene puesto de firma: responde, revisa y guarda; ADR 0004, decisión del 2026-10-01).
 */
function squuad_cert_signature_request_is_holder(object $request, int $user_id): bool
{
    return $user_id > 0 && (int) $request->student_user_id === $user_id && '' === squuad_cert_signature_request_role($request, $user_id);
}

/** Puestos que deben firmar la solicitud; sin firmantes fijados, el estudiante. */
function squuad_cert_signature_request_required_roles(object $request): array
{
    if (function_exists('squuad_cert_request_signers')) {
        return array_values(array_map(
            static fn(array $signer): string => $signer['slot_key'],
            array_filter(squuad_cert_request_signers($request), static fn(array $signer): bool => $signer['required'])
        ));
    }
    return (int) $request->student_user_id ? ['student'] : [];
}

/** Roles que ya firmaron dentro de la solicitud (firmas vivas con ese request_id). */
function squuad_cert_signature_request_signed_roles(int $request_id): array
{
    global $wpdb;

    return array_map('strval', $wpdb->get_col($wpdb->prepare(
        "SELECT signer_role FROM {$wpdb->prefix}users_signatures WHERE request_id = %d",
        $request_id
    )));
}

/**
 * Siguiente documento automático que el usuario tiene que firmar o rellenar, por prioridad (0 la más urgente; empate:
 * el más antiguo). Ver squuad_cert_signature_user_documents().
 *
 * Un documento de un estudiante está resuelto si su última ronda está completada o cerrada por subida, o si (sin
 * solicitudes) su fila de student_documents tiene un archivo y no está declinada. Si el usuario ya hizo su parte en la
 * ronda abierta, se pasa al siguiente (faltan los firmantes del sistema).
 *
 * Devuelve ['student', 'document', 'request' (o null), 'student_user_id', 'legacy_partial'] o null.
 */
function squuad_cert_signature_pending_for_user(WP_User $user, string $selection = ''): ?array
{
    $first = null;
    foreach (squuad_cert_signature_user_documents($user) as $item) {
        if ('to_sign' !== $item['state']) {
            continue;
        }
        // Documento elegido en Mi Cuenta ("<estudiante>-<documento>"); si no está entre los pendientes, el primero
        if ('' !== $selection && $selection === $item['student']->id . '-' . $item['document']->id) {
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
    $roles = (array) $user->roles;
    $students = [];
    if (in_array('student', $roles, true)) {
        $student = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}students WHERE email = %s", $user->user_email));
        if ($student) {
            $students[(int) $student->id] = $student;
        }
    }
    if (!$students) {
        return [];
    }

    $table_documents = $wpdb->prefix . 'documents_certificates';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_documents)) !== $table_documents) {
        return [];
    }
    $documents = $wpdb->get_results("SELECT * FROM {$table_documents} WHERE `type` = 'automatic' AND `status` = 1 ORDER BY `priority` ASC, id ASC");

    foreach ($students as $student) {
        $student_user = get_user_by('email', $student->email);
        $student_user_id = $student_user ? (int) $student_user->ID : 0;
        // Firma solo el estudiante con su propia cuenta (decisión del 2026-10-01)
        if ($student_user_id !== (int) $user->ID) {
            continue;
        }

        foreach ($documents as $document) {
            $document_id = (string) $document->document_identificator;
            if ('' === $document_id) {
                continue;
            }
            $request = squuad_cert_signature_request_latest((int) $student->id, $document_id);
            // Firmantes del documento (ADR 0003, paso 4): la política decide solo para solicitudes nuevas; una solicitud
            // en curso conserva sus firmantes aunque la configuración haya cambiado
            $in_progress = $request && in_array($request->status, SQUUAD_CERT_SIGNATURE_REQUEST_OPEN, true);
            if (!$in_progress) {
                // Solo si pide firma o tiene campos, y solo si el estudiante firma (panel) o rellena (campos)
                $automatic = squuad_cert_automatic_status($document);
                $signs = $automatic['signature'] && squuad_cert_signing_policy_has(squuad_cert_signing_policy($document), 'student');
                if (!$automatic['shown'] || (!$signs && !$automatic['fields'])) {
                    continue;
                }
            }
            if ($request && in_array($request->status, ['completed', 'closed_by_upload'], true)) {
                continue;
            }
            $state = 'to_sign';
            if ($request && in_array($request->status, SQUUAD_CERT_SIGNATURE_REQUEST_OPEN, true)) {
                $role_here = squuad_cert_signature_request_role($request, (int) $user->ID);
                $holder = '' === $role_here && squuad_cert_signature_request_is_holder($request, (int) $user->ID);
                if ('' === $role_here && !$holder) {
                    continue; // no firma ni rellena aquí
                }
                if ('signed' === $request->status) {
                    $state = 'pdf'; // todas las firmas; falta generar el PDF final
                } elseif ($holder ? null !== $request->frozen_at_utc : in_array($role_here, squuad_cert_signature_request_signed_roles((int) $request->id), true)) {
                    $state = 'waiting'; // ya hizo su parte (firmó o guardó sus respuestas); faltan otros firmantes
                }
            } elseif (!$request) {
                // Sin solicitudes: completado con el modelo anterior si la fila tiene archivo y no está declinada
                $row = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, status, attachment_id FROM {$wpdb->prefix}student_documents
                     WHERE student_id = %d AND document_id = %s ORDER BY id ASC LIMIT 1",
                    $student->id,
                    $document_id
                ));
                if ($row && (int) $row->attachment_id > 0 && 3 !== (int) $row->status) {
                    continue;
                }
            }

            $items[] = [
                'state' => $state,
                'student' => $student,
                'document' => $document,
                'request' => ($request && in_array($request->status, SQUUAD_CERT_SIGNATURE_REQUEST_OPEN, true)) ? $request : null,
                'student_user_id' => $student_user_id,
                // Aviso de "fírmelo de nuevo" durante toda la ronda 1 de un documento con firma del modelo anterior
                'legacy_partial' => (!$request || 1 === (int) $request->round)
                    && squuad_cert_signature_has_legacy_partial($student, $document_id, $student_user_id, 0),
            ];
        }
    }

    return $items;
}

/**
 * ¿Hay una firma del modelo anterior (sin solicitud) de este documento que corresponda a este estudiante? Cuenta la
 * del estudiante y la del representante solo si no tiene otros hijos (si los tiene, esa firma es compartida y puede
 * ser la de un hermano). Se usa para el aviso de "fírmelo de nuevo" (ADR 0002, punto 10, opción b).
 */
function squuad_cert_signature_has_legacy_partial(object $student, string $document_id, int $student_user_id, int $parent_user_id): bool
{
    global $wpdb;

    $user_ids = array_filter([$student_user_id]);
    if ($parent_user_id && 1 === (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}students WHERE partner_id = %d",
        $parent_user_id
    ))) {
        $user_ids[] = $parent_user_id;
    }
    if (!$user_ids) {
        return false;
    }
    $in = implode(',', array_map('intval', array_unique($user_ids)));

    return (bool) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}users_signatures WHERE document_id = %s AND request_id IS NULL AND user_id IN ({$in}) LIMIT 1",
        $document_id
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
function squuad_cert_signature_render_content(string $content, object $student, ?object $request = null): string
{
    // Huecos institucionales (ADR 0003): la firma si ya existe o "pendiente"
    if ($request && function_exists('squuad_cert_request_signers') && false !== strpos($content, 'data-edusig-slot="signer:')) {
        foreach (squuad_cert_request_signers($request) as $signer) {
            if ($signer['phase'] >= 2) {
                $content = str_replace(squuad_cert_signer_slot_marker($signer['slot_key']), squuad_cert_signature_render_slot_box($request, $signer), $content);
            }
        }
    }

    // Recuadro del estudiante: donde la plantilla lo coloca ({{signature_student}}) o, si no, en {{signature_section}}.
    // Un hueco del representante de una solicitud antigua queda vacío (el representante ya no firma).
    $content = str_replace('<div data-edusig-slot="parent"></div>', '', $content);
    $marker = '<div data-edusig-slot="student"></div>';
    $box = squuad_cert_signature_pad_box($student, 'student', $request);
    $section = $box;
    if (false !== strpos($content, $marker)) {
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

/** Fila de student_documents del documento del estudiante (la más antigua si hubiera duplicadas), o null. */
function squuad_cert_signature_student_document_id(int $student_id, string $document_id): ?int
{
    global $wpdb;

    $id = $wpdb->get_var($wpdb->prepare(
        "SELECT MIN(id) FROM {$wpdb->prefix}student_documents WHERE student_id = %d AND document_id = %s",
        $student_id,
        $document_id
    ));

    return $id ? (int) $id : null;
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
        'v1' => __('I agree to sign this document electronically. I understand that my electronic signature has the same validity as my handwritten signature, that it is recorded with the date, the time and the details of my connection, and that the signed document cannot be modified.', 'edusystem'),
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
 * Solicitud abierta (abierta, a medio firmar o firmada sin PDF) del documento de una fila de student_documents, o
 * null. Sirve para saber si una subida manual la cerraría.
 */
function squuad_cert_signature_request_open_for_row(int $student_document_row_id): ?object
{
    global $wpdb;

    if (!$student_document_row_id || !squuad_cert_signature_requests_enabled()) {
        return null;
    }
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT student_id, document_id FROM {$wpdb->prefix}student_documents WHERE id = %d",
        $student_document_row_id
    ));
    if (!$row) {
        return null;
    }
    $request = squuad_cert_signature_request_latest((int) $row->student_id, (string) $row->document_id);

    return ($request && in_array($request->status, SQUUAD_CERT_SIGNATURE_REQUEST_OPEN, true)) ? $request : null;
}

/**
 * Un archivo subido a mano (por el usuario o por el admin) completa el documento sin firmas electrónicas: si tenía
 * una solicitud abierta, se cierra como closed_by_upload con el sha256 del archivo, quién lo subió y el motivo
 * (evento sellado). Las firmas que ya tuviera la solicitud no se anulan: quedan ligadas a ella. Una solicitud
 * completada no se cierra por subida. Devuelve true si cerró una solicitud.
 */
function squuad_cert_signature_request_close_by_upload(int $student_document_row_id, string $file_path, int $user_id, string $reason = ''): bool
{
    $request = squuad_cert_signature_request_open_for_row($student_document_row_id);
    if (!$request) {
        return false;
    }
    $sha256 = is_readable($file_path) ? (string) hash_file('sha256', $file_path) : '';

    return squuad_cert_signature_request_transition((int) $request->id, SQUUAD_CERT_SIGNATURE_REQUEST_OPEN, 'closed_by_upload', [
        'closed_by' => $user_id,
        'closed_upload_sha256' => $sha256,
        'student_document_id' => $student_document_row_id,
    ], [
        'uploaded_by' => $user_id,
        'reason' => $reason,
        'signed_roles' => squuad_cert_signature_request_signed_roles((int) $request->id),
    ]);
}

/** Hueco de un firmante institucional pintado: su firma en SVG con la fecha, o "Pendiente de firma". */
function squuad_cert_signature_render_slot_box(object $request, array $signer): string
{
    global $wpdb;

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT signature, signed_at_utc FROM {$wpdb->prefix}users_signatures WHERE request_id = %d AND signer_role = %s",
        $request->id,
        $signer['slot_key']
    ));
    if (!$row) {
        return '<div style="height:90px;display:flex;align-items:center;justify-content:center;color:#888;border:1px dashed #bbb">'
            . esc_html__('Pending signature', 'edusystem') . '</div>';
    }

    return squuad_cert_signature_svg((string) $row->signature, $signer['name'])
        . '<div style="font-size:10px;color:#666">' . esc_html(get_date_from_gmt((string) $row->signed_at_utc, 'Y-m-d H:i')) . ' UTC</div>';
}
