<?php
declare(strict_types=1);

/**
 * EduSystem - Evidencias de firma de estudiantes y representantes (ADR 0001).
 *
 * Paso 1: esquema v4 (core/schema/students.php), corte de firmas antiguas y clave automática por sitio.
 * Todavía no cambia cómo se guardan las firmas: eso llega en el paso 2 y solo con el esquema v4 aplicado.
 */

if (!defined('ABSPATH')) exit;

// Claves del sitio (también las usa la instalación del esquema, sin cargar el resto del módulo)
require_once __DIR__ . '/signature-keys.php';

/** Las columnas de evidencia y las tablas de anuladas y de cadena llegan con la versión 4 del esquema. */
function squuad_cert_signature_evidence_enabled(): bool
{
    return version_compare((string) get_option('edusystem_db_version'), '4', '>=');
}

/**
 * Versión de las plantillas de EduSystem que no vienen de documents_certificates (p. ej. MISSING DOCUMENT, que
 * se pinta en includes/html-documents.php). Subirla a mano cuando cambie el texto que se firma.
 */
const SQUUAD_CERT_TEMPLATE_VERSIONS = [
    'MISSING DOCUMENT' => '1',
];

/**
 * Clave con la que se firma ahora: [key_id, clave en bytes]. Si wp-config.php define las constantes
 * SQUUAD_CERT_SIGNATURE_KEYS (id => clave en base64) y SQUUAD_CERT_SIGNATURE_KEY_ID, se usan ellas; si no, la clave
 * automática del sitio. Devuelve null si no hay ninguna clave utilizable.
 */
function squuad_cert_signature_current_key(): ?array
{
    if (defined('SQUUAD_CERT_SIGNATURE_KEYS') && defined('SQUUAD_CERT_SIGNATURE_KEY_ID')) {
        $key_id = (string) SQUUAD_CERT_SIGNATURE_KEY_ID;
        $key = squuad_cert_signature_key_by_id($key_id);
        if (null !== $key) {
            return [$key_id, $key];
        }
    }

    $key_id = squuad_cert_signature_ensure_key();
    $key = squuad_cert_signature_key_by_id($key_id);

    return null === $key ? null : [$key_id, $key];
}

/** Clave en bytes por su id (constantes de wp-config.php o claves guardadas), o null si no existe. */
function squuad_cert_signature_key_by_id(string $key_id): ?string
{
    if ('' === $key_id) {
        return null;
    }
    if (defined('SQUUAD_CERT_SIGNATURE_KEYS') && is_array(SQUUAD_CERT_SIGNATURE_KEYS) && isset(SQUUAD_CERT_SIGNATURE_KEYS[$key_id])) {
        $key = base64_decode((string) SQUUAD_CERT_SIGNATURE_KEYS[$key_id], true);
        return (false !== $key && strlen($key) >= 32) ? $key : null;
    }
    $stored = squuad_cert_signature_stored_keys();
    if (isset($stored['keys'][$key_id]['key'])) {
        $key = base64_decode((string) $stored['keys'][$key_id]['key'], true);
        return (false !== $key && strlen($key) >= 32) ? $key : null;
    }

    return null;
}

/**
 * Fecha (UTC) en que se retiró una clave guardada al rotarla, o null si sigue activa o es de wp-config.php
 * (de esas no se conoce la fecha).
 */
function squuad_cert_signature_key_retired_at(string $key_id): ?string
{
    $stored = squuad_cert_signature_stored_keys();
    $retired = $stored['keys'][$key_id]['retired_at'] ?? null;

    return $retired ? (string) $retired : null;
}

/** Posición de una clave guardada en el orden en que se crearon (0 = la primera), o null si no es de la opción. */
function squuad_cert_signature_key_rank(string $key_id): ?int
{
    $position = array_search($key_id, array_keys(squuad_cert_signature_stored_keys()['keys']), true);

    return false === $position ? null : (int) $position;
}

/**
 * Switch de usuario (hoy WPFront): al crear la sesión de otro usuario, WordPress aún tiene conectado al que hace
 * el cambio. Se anota en los metadatos del token de sesión, en el servidor (la cookie del plugin la controla el
 * operador). Un login normal no tiene usuario conectado, así que no se marca.
 */
add_filter('attach_session_information', 'squuad_cert_signature_mark_switched_session', 10, 2);
function squuad_cert_signature_mark_switched_session($session, $user_id)
{
    $current_user_id = get_current_user_id();
    if ($current_user_id && $current_user_id !== (int) $user_id && is_array($session)) {
        $session['squuad_cert_switched_from'] = $current_user_id;
    }

    return $session;
}

/** Usuario que hizo el Switch hacia la sesión actual, o 0 si la sesión es del propio usuario. */
function squuad_cert_signature_session_switched_from(): int
{
    $user_id = get_current_user_id();
    $token = wp_get_session_token();
    if (!$user_id || '' === $token) {
        return 0;
    }
    $session = WP_Session_Tokens::get_instance($user_id)->get($token);

    return (int) ($session['squuad_cert_switched_from'] ?? 0);
}

/** sha256 de la versión del documento que se firma (plantilla de documents_certificates o versión fija). */
function squuad_cert_signature_doc_version_hash(string $document_id): string
{
    $document = function_exists('squuad_cert_get_automatic_document_by_identificator')
        ? squuad_cert_get_automatic_document_by_identificator($document_id)
        : null;
    if ($document) {
        return hash('sha256', implode("\n", [
            'documents_certificates',
            (string) $document->id,
            (string) $document->header,
            (string) $document->content,
            (string) $document->footer,
            (string) ($document->fields ?? ''),
        ]));
    }

    $version = SQUUAD_CERT_TEMPLATE_VERSIONS[$document_id] ?? '0';
    return hash('sha256', 'edusystem-template' . "\n" . $document_id . "\n" . $version);
}

/**
 * Mensaje que sella la huella (formato EDUSIG1). Campos en orden fijo, unidos por "\n"; los textos libres van
 * con rawurlencode para que un salto de línea no pueda desplazar campos. No cambiar sin subir la versión.
 */
function squuad_cert_signature_canonical(array $row): string
{
    $text = static fn($value): string => rawurlencode((string) ($value ?? ''));

    return implode("\n", [
        'EDUSIG1',
        (string) (int) $row['chain_seq'],
        $text($row['key_id']),
        $text($row['site_url']),
        (string) (int) $row['student_id'],
        (string) (int) $row['user_id'],
        $text($row['signer_role']),
        (string) (int) $row['actor_user_id'],
        (string) (int) $row['switched_from'],
        $text($row['document_id']),
        (string) $row['doc_version_sha256'],
        (string) $row['signature_sha256'],
        hash('sha256', (string) ($row['grade_selected'] ?? '')),
        (string) $row['document_fields_sha256'],
        (string) $row['signed_at_utc'],
        (string) $row['ip_hmac'],
        (string) $row['ua_sha256'],
        (string) $row['session_hash'],
        (string) (int) ($row['ratifies_id'] ?? 0),
        (string) $row['prev_fingerprint'],
    ]);
}

/**
 * Mensaje EDUSIG2 (ADR 0002): los campos de EDUSIG1 con la cabecera EDUSIG2 más los de la solicitud, en orden fijo.
 * request_created_fingerprint (huella del evento de creación) ata la firma a una solicitud concreta aunque alguien
 * reasigne ids en la BD. No cambiar sin subir la versión.
 */
function squuad_cert_signature_canonical_v2(array $row): string
{
    $text = static fn($value): string => rawurlencode((string) ($value ?? ''));
    $lines = explode("\n", squuad_cert_signature_canonical($row));
    $lines[0] = 'EDUSIG2';

    return implode("\n", array_merge($lines, [
        (string) (int) ($row['request_id'] ?? 0),
        (string) (int) ($row['student_document_id'] ?? 0),
        (string) (int) ($row['round'] ?? 0),
        (string) ($row['content_sha256'] ?? ''),
        (string) ($row['request_created_fingerprint'] ?? ''),
        $text($row['consent_version'] ?? ''),
        (string) ($row['consent_sha256'] ?? ''),
        $text($row['signature_method'] ?? ''),
        (string) (int) ($row['reused_signature_id'] ?? 0),
    ]));
}

/** Mensaje sellado de una fila según su formato (evidence_format; sin él, EDUSIG1). */
function squuad_cert_signature_canonical_for(array $row): string
{
    return 'EDUSIG2' === ($row['evidence_format'] ?? '') ? squuad_cert_signature_canonical_v2($row) : squuad_cert_signature_canonical($row);
}

/** Fija la cabeza de la cadena (llamar solo bajo el bloqueo de la cadena). */
function squuad_cert_signature_chain_set_head(int $seq, string $fingerprint): void
{
    global $wpdb;

    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$wpdb->prefix}squuad_cert_chain (id, last_seq, head_fingerprint, updated_at_utc)
         VALUES (1, %d, %s, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE last_seq = VALUES(last_seq), head_fingerprint = VALUES(head_fingerprint),
         updated_at_utc = VALUES(updated_at_utc)",
        $seq,
        $fingerprint
    ));
}

/** Nombre del bloqueo de la cadena: GET_LOCK es global en el servidor MySQL, que pueden compartir varios sitios. */
function squuad_cert_signature_lock_name(): string
{
    global $wpdb;

    return 'squuad_cert_chain_' . substr(hash('sha256', DB_NAME . '|' . $wpdb->prefix), 0, 40);
}

/**
 * Guarda una firma de estudiante o representante. Con el esquema v4 añade la evidencia y la huella encadenada;
 * sin él (sitio aún sin actualizar) la guarda como antes. La firma nunca se pierde: si no hay clave o no se
 * obtiene el bloqueo, se guarda con evidence_status = 'sin_huella_error' y se anota en el log.
 *
 * $data: columnas de siempre (user_id, signature, document_id, grade_selected, document_fields).
 * $request_evidence (ADR 0002, esquema v5): request_id, student_document_id, round, content_sha256,
 * request_created_fingerprint, template_version_sha256, consent_version, consent_sha256, signature_method y
 * reused_signature_id. Con ella la firma se sella en formato EDUSIG2; sin ella, EDUSIG1.
 * Devuelve el id de la fila o 0 si el INSERT falla.
 */
function squuad_cert_signature_insert(array $data, int $student_id, string $signer_role, array $request_evidence = []): int
{
    global $wpdb;
    $table = $wpdb->prefix . 'users_signatures';

    if (!squuad_cert_signature_evidence_enabled()) {
        return $wpdb->insert($table, $data) ? (int) $wpdb->insert_id : 0;
    }

    $key = squuad_cert_signature_current_key();
    $session_token = wp_get_session_token();
    $ip = isset($_SERVER['REMOTE_ADDR']) ? substr(sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])), 0, 45) : '';
    $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 255) : '';

    $row = $data + [
        'grade_selected' => null,
        'document_fields' => null,
    ];
    $row += [
        'site_url' => get_site_url(),
        'student_id' => $student_id,
        'signer_role' => $signer_role,
        'actor_user_id' => get_current_user_id(),
        'switched_from' => squuad_cert_signature_session_switched_from(),
        'doc_version_sha256' => squuad_cert_signature_doc_version_hash((string) $data['document_id']),
        'signature_sha256' => hash('sha256', (string) $data['signature']),
        'document_fields_sha256' => hash('sha256', (string) ($row['document_fields'] ?? '')),
        'signed_at_utc' => gmdate('Y-m-d H:i:s'),
        'ip' => $ip,
        'user_agent' => $user_agent,
        'ua_sha256' => hash('sha256', $user_agent),
        'session_hash' => '' === $session_token ? '' : hash('sha256', $session_token),
        'ratifies_id' => null,
    ];

    // Formato de la evidencia: EDUSIG2 si la firma pertenece a una solicitud (esquema v5)
    $v5 = function_exists('squuad_cert_signature_requests_enabled') && squuad_cert_signature_requests_enabled();
    if ($v5 && $request_evidence) {
        foreach (['request_id', 'student_document_id', 'round', 'reused_signature_id'] as $column) {
            $row[$column] = (int) ($request_evidence[$column] ?? 0);
        }
        foreach (['content_sha256', 'request_created_fingerprint', 'consent_version', 'consent_sha256'] as $column) {
            $row[$column] = (string) ($request_evidence[$column] ?? '');
        }
        $row['signature_method'] = (string) ($request_evidence['signature_method'] ?? 'drawn');
        if (!empty($request_evidence['template_version_sha256'])) {
            $row['doc_version_sha256'] = (string) $request_evidence['template_version_sha256'];
        }
        $row['evidence_format'] = 'EDUSIG2';
    } elseif ($v5) {
        $row['evidence_format'] = 'EDUSIG1';
    }

    $lock = squuad_cert_signature_lock_name();
    $locked = $key && '1' === (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock));

    if (!$locked) {
        $row['evidence_status'] = 'sin_huella_error';
        $row['key_id'] = $key[0] ?? null;
        $row['ip_hmac'] = null;
        $inserted = $wpdb->insert($table, $row) ? (int) $wpdb->insert_id : 0;
        if (function_exists('edusystem_set_log')) {
            edusystem_set_log(
                sprintf('Firma %d guardada sin huella (%s)', $inserted, $key ? 'sin bloqueo de la cadena' : 'sin clave'),
                'signature_error'
            );
        }
        return $inserted;
    }

    try {
        // La clave se vuelve a leer bajo el bloqueo: si se rotó mientras esta petición esperaba, se usa la nueva
        [$key_id, $key_bytes] = squuad_cert_signature_current_key() ?? $key;
        // Cabeza de la cadena con SQL directo bajo el bloqueo (nunca de una opción: la caché la podría servir vieja)
        $head = $wpdb->get_row("SELECT last_seq, head_fingerprint FROM {$wpdb->prefix}squuad_cert_chain WHERE id = 1");
        $last_seq = $head ? (int) $head->last_seq : 0;
        $prev = ($head && '' !== $head->head_fingerprint) ? $head->head_fingerprint : str_repeat('0', 64);

        $row['chain_seq'] = $last_seq + 1;
        $row['key_id'] = $key_id;
        $row['ip_hmac'] = hash_hmac('sha256', $ip, $key_bytes);
        $row['prev_fingerprint'] = $prev;
        $row['evidence_status'] = 'ok';
        $row['fingerprint'] = hash_hmac('sha256', squuad_cert_signature_canonical_for($row), $key_bytes);

        if (!$wpdb->insert($table, $row)) {
            return 0;
        }
        $inserted = (int) $wpdb->insert_id;

        squuad_cert_signature_chain_set_head((int) $row['chain_seq'], (string) $row['fingerprint']);
    } finally {
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
    }

    if (function_exists('edusystem_set_log')) {
        edusystem_set_log(sprintf(
            'Firma %d registrada: %s, %s del estudiante %d, desde la cuenta del usuario %d (eslabón %d)',
            $inserted,
            $row['document_id'],
            $signer_role,
            $student_id,
            $row['actor_user_id'],
            $row['chain_seq']
        ), 'signature');
    }

    return $inserted;
}

/**
 * Anula firmas en lugar de borrarlas: copia cada fila completa (con su huella) a squuad_cert_signatures_revoked y
 * después la retira de la tabla viva, así los puntos de lectura actuales vuelven a pedir la firma y la cadena se
 * sigue pudiendo verificar. Sin el esquema v4 las borra como antes. Otros plugins deben usar esta función
 * (con function_exists) para cambiar filas de users_signatures. Devuelve cuántas filas se retiraron.
 */
function squuad_cert_revoke_signatures(array $ids, string $reason, int $actor_user_id, ?int $student_document_id = null): int
{
    global $wpdb;
    $table = $wpdb->prefix . 'users_signatures';
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) {
        return 0;
    }
    $in = implode(',', $ids);

    if (!squuad_cert_signature_evidence_enabled()) {
        return (int) $wpdb->query("DELETE FROM {$table} WHERE id IN ({$in})");
    }

    $columns = 'user_id, signature, document_id, grade_selected, document_fields, created_at, chain_seq, evidence_status,
        key_id, site_url, student_id, signer_role, actor_user_id, switched_from, doc_version_sha256, signature_sha256,
        document_fields_sha256, signed_at_utc, ip, ip_hmac, user_agent, ua_sha256, session_hash, ratifies_id,
        prev_fingerprint, fingerprint';
    // Columnas de solicitud (ADR 0002, esquema v5): se copian también, o una firma EDUSIG2 anulada quedaría "alterada"
    if (function_exists('squuad_cert_signature_requests_enabled') && squuad_cert_signature_requests_enabled()) {
        $columns .= ', request_id, round, content_sha256, request_created_fingerprint, consent_version, consent_sha256,
        signature_method, reused_signature_id, evidence_format';
    }

    // Documento de la anulación: el indicado o, con el esquema v5, el que ya guarda la propia firma
    $document_expression = (function_exists('squuad_cert_signature_requests_enabled') && squuad_cert_signature_requests_enabled())
        ? "COALESCE(NULLIF(%s, ''), student_document_id)"
        : '%s';

    $removed = 0;
    foreach ($ids as $id) {
        $copied = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}squuad_cert_signatures_revoked
                (signature_row_id, {$columns}, revoked_at_utc, revoked_by, reason, student_document_id)
             SELECT id, {$columns}, UTC_TIMESTAMP(), %d, %s, {$document_expression} FROM {$table} WHERE id = %d",
            $actor_user_id,
            $reason,
            null === $student_document_id ? null : (string) $student_document_id,
            $id
        ));
        // Solo se borra lo que quedó copiado: si la copia falla, la firma sigue en la tabla viva
        if ($copied) {
            $removed += (int) $wpdb->delete($table, ['id' => $id]);
            if (function_exists('edusystem_set_log')) {
                edusystem_set_log(sprintf('Firma %d anulada: %s', $id, $reason), 'signature_revoked', $actor_user_id);
            }
        }
    }

    return $removed;
}

/**
 * Estado de integridad de una fila de firma (tabla viva o anuladas):
 * verified, altered, chain_broken, unknown_key, legacy, error (sin huella por error) o missing (sin huella, anómala).
 * $previous_fingerprint: huella del eslabón anterior si ya se conoce (el verificador recorre la cadena en orden);
 * si es null se busca en la BD.
 */
function squuad_cert_signature_verify_row(object $row, ?string $previous_fingerprint = null): string
{
    global $wpdb;

    $legacy_max_id = (int) get_option('squuad_cert_signature_legacy_max_id', 0);
    $row_id = (int) ($row->signature_row_id ?? $row->id);

    if (empty($row->fingerprint)) {
        if ('sin_huella_error' === ($row->evidence_status ?? '')) {
            return 'error';
        }
        return $row_id <= $legacy_max_id ? 'legacy' : 'missing';
    }

    $key = squuad_cert_signature_key_by_id((string) $row->key_id);
    if (null === $key) {
        return 'unknown_key';
    }

    $data = (array) $row;
    $expected = hash_hmac('sha256', squuad_cert_signature_canonical_for($data), $key);
    $content_ok = hash_equals((string) $row->signature_sha256, hash('sha256', (string) $row->signature))
        // document_fields se vacía a propósito al subir el PDF: solo se comprueba mientras exista
        && (null === $row->document_fields || hash_equals((string) $row->document_fields_sha256, hash('sha256', (string) $row->document_fields)))
        // la IP en claro se puede purgar (retención): solo se comprueba mientras exista
        && (null === $row->ip || '' === $row->ip && '' === (string) $row->ip_hmac || hash_equals((string) $row->ip_hmac, hash_hmac('sha256', (string) $row->ip, $key)))
        && (null === $row->user_agent || hash_equals((string) $row->ua_sha256, hash('sha256', (string) $row->user_agent)));
    if (!hash_equals($expected, (string) $row->fingerprint) || !$content_ok) {
        return 'altered';
    }

    // Clave retirada: nada firmado después de la rotación puede llevarla (margen de 60 s para una firma en curso)
    $retired_at = squuad_cert_signature_key_retired_at((string) $row->key_id);
    if (null !== $retired_at && strtotime((string) $row->signed_at_utc . ' UTC') > strtotime($retired_at . ' UTC') + 60) {
        return 'retired_key';
    }

    // EDUSIG2: el contenido que firmó tiene que ser el de su solicitud, y ese contenido tiene que estar íntegro
    if ('EDUSIG2' === ($row->evidence_format ?? '') && function_exists('squuad_cert_signature_request_get')) {
        $request = squuad_cert_signature_request_get((int) $row->request_id);
        if (!$request || !hash_equals((string) $request->content_sha256, (string) $row->content_sha256)
            || null === squuad_cert_signature_request_content((int) $row->request_id)) {
            return 'content_altered';
        }
    }

    // Eslabón anterior (firmas vivas, anuladas o eventos de solicitud): tiene que existir y coincidir
    $seq = (int) $row->chain_seq;
    if ($seq > 1) {
        $prev = null !== $previous_fingerprint ? $previous_fingerprint : squuad_cert_signature_chain_fingerprint_at($seq - 1);
        if (null === $prev || !hash_equals((string) $prev, (string) $row->prev_fingerprint)) {
            return 'chain_broken';
        }
    } elseif (!hash_equals(str_repeat('0', 64), (string) $row->prev_fingerprint)) {
        return 'chain_broken';
    }

    return 'verified';
}

/** Huella del eslabón número $seq de la cadena (firma viva, anulada o evento de solicitud), o null si no existe. */
function squuad_cert_signature_chain_fingerprint_at(int $seq): ?string
{
    global $wpdb;

    $sql = "SELECT fingerprint FROM {$wpdb->prefix}users_signatures WHERE chain_seq = %d
            UNION ALL SELECT fingerprint FROM {$wpdb->prefix}squuad_cert_signatures_revoked WHERE chain_seq = %d";
    $args = [$seq, $seq];
    if (function_exists('squuad_cert_signature_requests_enabled') && squuad_cert_signature_requests_enabled()) {
        $sql .= " UNION ALL SELECT fingerprint FROM {$wpdb->prefix}squuad_cert_events WHERE chain_seq = %d";
        $args[] = $seq;
    }
    $fingerprint = $wpdb->get_var($wpdb->prepare($sql . ' LIMIT 1', $args));

    return null === $fingerprint ? null : (string) $fingerprint;
}

/** Etiqueta traducible de un estado de integridad. */
function squuad_cert_signature_integrity_label(string $status): string
{
    $labels = [
        'verified' => __('Verified', 'edusystem'),
        'altered' => __('Altered', 'edusystem'),
        'retired_key' => __('Signed with a retired key', 'edusystem'),
        'content_altered' => __('Signed content altered', 'edusystem'),
        'chain_broken' => __('Chain broken', 'edusystem'),
        'unknown_key' => __('Unknown key', 'edusystem'),
        'legacy' => __('Legacy, no fingerprint', 'edusystem'),
        'error' => __('No fingerprint (error)', 'edusystem'),
        'missing' => __('No fingerprint', 'edusystem'),
    ];

    return $labels[$status] ?? $status;
}

/**
 * Contexto de una firma (independiente de la integridad): automática, hecha desde la cuenta de otra persona,
 * sesión conmutada o firmada en otro sitio. Devuelve etiquetas traducidas.
 */
function squuad_cert_signature_context_labels(object $row): array
{
    $labels = [];
    if ('["automatic"]' === (string) $row->signature) {
        $labels[] = __('Automatic (typed name)', 'edusystem');
    }
    if (!empty($row->fingerprint)) {
        if ((int) $row->switched_from) {
            $labels[] = sprintf(__('Switched session by user %d', 'edusystem'), (int) $row->switched_from);
        }
        if ((int) $row->actor_user_id && (int) $row->actor_user_id !== (int) $row->user_id) {
            $actor = get_userdata((int) $row->actor_user_id);
            $labels[] = sprintf(
                __('Signed from the account of %s', 'edusystem'),
                $actor ? $actor->display_name : '#' . (int) $row->actor_user_id
            );
        }
        if (untrailingslashit((string) $row->site_url) !== untrailingslashit(get_site_url())) {
            $labels[] = __('Signed on another site', 'edusystem');
        }
    }

    return $labels;
}

/**
 * Recorre toda la cadena (firmas vivas y anuladas, por lotes) y cuenta los estados de integridad y de contexto.
 * Comprueba además que los eslabones sean consecutivos y que la cabeza guardada coincida con el último.
 * Guarda el resultado en la opción squuad_cert_signature_last_verification (sin autoload) y lo devuelve.
 */
function squuad_cert_signature_verify_chain(int $problems_limit = 100): array
{
    global $wpdb;
    $live = $wpdb->prefix . 'users_signatures';
    $revoked = $wpdb->prefix . 'squuad_cert_signatures_revoked';

    $result = [
        'verified_at' => gmdate('Y-m-d H:i:s'),
        'verified_by' => get_current_user_id(),
        'status' => array_fill_keys(['verified', 'altered', 'content_altered', 'retired_key', 'chain_broken', 'unknown_key', 'legacy', 'error', 'missing'], 0),
        'context' => array_fill_keys(['automatic', 'other_account', 'switched', 'other_site'], 0),
        'live' => 0,
        'revoked' => 0,
        'events' => 0,
        'last_seq' => 0,
        'head_ok' => true,
        'problems' => [],
    ];
    if (!squuad_cert_signature_evidence_enabled()) {
        return $result;
    }

    $add_problem = static function (object $row, string $status) use (&$result, $problems_limit): void {
        if (count($result['problems']) < $problems_limit) {
            $result['problems'][] = [
                'id' => (int) ($row->signature_row_id ?? $row->id),
                'revoked' => isset($row->signature_row_id),
                'chain_seq' => $row->chain_seq ? (int) $row->chain_seq : null,
                'user_id' => (int) $row->user_id,
                'student_id' => $row->student_id ? (int) $row->student_id : null,
                'document_id' => (string) $row->document_id,
                'status' => $status,
            ];
        }
    };
    $count_context = static function (object $row) use (&$result): void {
        if ('["automatic"]' === (string) $row->signature) {
            $result['context']['automatic']++;
        }
        if (!empty($row->fingerprint)) {
            if ((int) $row->switched_from) {
                $result['context']['switched']++;
            }
            if ((int) $row->actor_user_id && (int) $row->actor_user_id !== (int) $row->user_id) {
                $result['context']['other_account']++;
            }
            if (untrailingslashit((string) $row->site_url) !== untrailingslashit(get_site_url())) {
                $result['context']['other_site']++;
            }
        }
    };

    // 1) Eslabones de la cadena, en orden: firmas vivas, anuladas y (esquema v5) eventos de solicitud, por tramos
    $events_table = $wpdb->prefix . 'squuad_cert_events';
    $with_events = function_exists('squuad_cert_signature_requests_enabled') && squuad_cert_signature_requests_enabled();
    $max_seq = (int) $wpdb->get_var(
        "SELECT MAX(s) FROM (SELECT MAX(chain_seq) s FROM {$live} UNION ALL SELECT MAX(chain_seq) FROM {$revoked}"
        . ($with_events ? " UNION ALL SELECT MAX(chain_seq) FROM {$events_table}" : '') . ") AS m"
    );
    $previous = str_repeat('0', 64);
    $expected_seq = 1;
    $last_fingerprint = '';
    $newest_key_rank = -1;
    $batch = 500;
    for ($from = 1; $from <= $max_seq; $from += $batch) {
        $to = $from + $batch - 1;
        $links = [];
        foreach ($wpdb->get_results($wpdb->prepare("SELECT * FROM {$live} WHERE chain_seq BETWEEN %d AND %d", $from, $to)) as $row) {
            $links[] = ['kind' => 'live', 'row' => $row];
        }
        foreach ($wpdb->get_results($wpdb->prepare("SELECT * FROM {$revoked} WHERE chain_seq BETWEEN %d AND %d", $from, $to)) as $row) {
            $links[] = ['kind' => 'revoked', 'row' => $row];
        }
        if ($with_events) {
            foreach ($wpdb->get_results($wpdb->prepare("SELECT * FROM {$events_table} WHERE chain_seq BETWEEN %d AND %d", $from, $to)) as $row) {
                $links[] = ['kind' => 'event', 'row' => $row];
            }
        }
        usort($links, static fn(array $a, array $b): int => (int) $a['row']->chain_seq <=> (int) $b['row']->chain_seq);

        foreach ($links as $link) {
            $row = $link['row'];
            $seq = (int) $row->chain_seq;
            if ('event' === $link['kind']) {
                $result['events']++;
                // Hueco en la secuencia: falta un eslabón (borrado de la BD)
                $status = $seq === $expected_seq ? squuad_cert_signature_request_verify_event($row, $previous) : 'chain_broken';
            } else {
                $result['revoked' === $link['kind'] ? 'revoked' : 'live']++;
                $status = $seq === $expected_seq ? squuad_cert_signature_verify_row($row, $previous) : 'chain_broken';
            }
            $key_rank = squuad_cert_signature_key_rank((string) $row->key_id);
            if (null !== $key_rank) {
                // Un eslabón posterior a otro sellado con una clave más nueva no puede usar una clave anterior
                if ('verified' === $status && $key_rank < $newest_key_rank) {
                    $status = 'retired_key';
                }
                $newest_key_rank = max($newest_key_rank, $key_rank);
            }
            $result['status'][$status] = ($result['status'][$status] ?? 0) + 1;
            if ('verified' !== $status) {
                if ('event' === $link['kind']) {
                    if (count($result['problems']) < $problems_limit) {
                        $result['problems'][] = [
                            'id' => (int) $row->id,
                            'revoked' => false,
                            'event' => (string) $row->event_type,
                            'request_id' => (int) $row->request_id,
                            'chain_seq' => $seq,
                            'user_id' => (int) $row->actor_user_id,
                            'student_id' => null,
                            'document_id' => 'evento: ' . (string) $row->event_type,
                            'status' => $status,
                        ];
                    }
                } else {
                    $add_problem($row, $status);
                }
            }
            if ('event' !== $link['kind']) {
                $count_context($row);
            }
            $previous = (string) $row->fingerprint;
            $last_fingerprint = $previous;
            $expected_seq = $seq + 1;
            $result['last_seq'] = $seq;
        }
    }

    // 2) Cabeza de la cadena: el último eslabón tiene que ser el que dice la tabla de la cabeza
    $head = $wpdb->get_row("SELECT last_seq, head_fingerprint FROM {$wpdb->prefix}squuad_cert_chain WHERE id = 1");
    if ($head && ((int) $head->last_seq !== $result['last_seq'] || ((int) $head->last_seq > 0 && !hash_equals((string) $head->head_fingerprint, $last_fingerprint)))) {
        $result['head_ok'] = false;
    }

    // 3) Filas sin eslabón: antiguas, sin huella por error o anómalas
    foreach ([$live => false, $revoked => true] as $table => $is_revoked) {
        $id_column = $is_revoked ? 'signature_row_id' : 'id';
        $rows = $wpdb->get_results(
            "SELECT id, {$id_column} AS row_id, user_id, signature, document_id, student_id, evidence_status, fingerprint,
                chain_seq, switched_from, actor_user_id, site_url
             FROM {$table} WHERE chain_seq IS NULL"
        );
        foreach ($rows as $row) {
            $row->id = $row->row_id;
            if ($is_revoked) {
                $row->signature_row_id = $row->row_id;
                $result['revoked']++;
            } else {
                $result['live']++;
            }
            $status = squuad_cert_signature_verify_row($row);
            $result['status'][$status]++;
            if (!in_array($status, ['legacy', 'verified'], true)) {
                $add_problem($row, $status);
            }
            $count_context($row);
        }
    }

    update_option('squuad_cert_signature_last_verification', $result, false);
    if (function_exists('edusystem_set_log')) {
        edusystem_set_log(sprintf(
            'Verificación de firmas: %d verificadas, %d alteradas, %d cadena rota, cabeza %s',
            $result['status']['verified'],
            $result['status']['altered'],
            $result['status']['chain_broken'],
            $result['head_ok'] ? 'correcta' : 'no coincide'
        ), 'signature_verification');
    }

    return $result;
}

/**
 * Diagnóstico de las firmas antiguas (sin huella, id <= corte), solo lectura. Una fila por firma, con los
 * indicadores de riesgo: automática, par firmado en la misma petición (<= 2 s), estudiante menor al firmar y
 * usuario que ya no existe. Incluye las antiguas que ya se anularon.
 */
function squuad_cert_signature_legacy_rows(): array
{
    global $wpdb;
    $cutoff = (int) get_option('squuad_cert_signature_legacy_max_id', 0);
    if ($cutoff <= 0) {
        return [];
    }

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT sig.id, sig.user_id, sig.document_id, sig.created_at, sig.signature, 0 AS revoked
         FROM {$wpdb->prefix}users_signatures sig WHERE sig.id <= %d
         UNION ALL
         SELECT rev.signature_row_id, rev.user_id, rev.document_id, rev.created_at, rev.signature, 1
         FROM {$wpdb->prefix}squuad_cert_signatures_revoked rev WHERE rev.signature_row_id <= %d AND rev.chain_seq IS NULL
         ORDER BY id",
        $cutoff,
        $cutoff
    ));

    // Estudiante y representante de cada usuario (un usuario representante puede tener varios estudiantes)
    $students = $wpdb->get_results(
        "SELECT st.id, st.partner_id, st.birth_date, u.ID AS student_user_id
         FROM {$wpdb->prefix}students st JOIN {$wpdb->users} u ON u.user_email = st.email"
    );
    $as_student = [];
    $as_parent = [];
    foreach ($students as $student) {
        $as_student[(int) $student->student_user_id] = $student;
        $as_parent[(int) $student->partner_id][] = $student;
    }
    $existing_users = array_flip(array_map('intval', $wpdb->get_col("SELECT ID FROM {$wpdb->users}")));

    // Índice (usuario, documento) => fecha, para encontrar el par del otro firmante
    $signed_at = [];
    foreach ($rows as $row) {
        $signed_at[(int) $row->user_id . '|' . $row->document_id] = strtotime((string) $row->created_at);
    }

    $result = [];
    foreach ($rows as $row) {
        $user_id = (int) $row->user_id;
        $role = '';
        $student = null;
        $other_user = 0;
        if (isset($as_student[$user_id])) {
            $student = $as_student[$user_id];
            $role = (int) $student->partner_id === $user_id ? 'self' : 'student';
            $other_user = (int) $student->partner_id;
        } elseif (!empty($as_parent[$user_id])) {
            $student = $as_parent[$user_id][0];
            $role = 'parent';
            $other_user = (int) $student->student_user_id;
        }
        $time = strtotime((string) $row->created_at);
        $other_key = $other_user . '|' . $row->document_id;
        $same_request = 'self' !== $role && $other_user && isset($signed_at[$other_key]) && abs($signed_at[$other_key] - $time) <= 2;
        $minor = false;
        if ($student && $student->birth_date && $time) {
            $minor = (new DateTime((string) $student->birth_date))->diff(new DateTime('@' . $time))->y < 18;
        }

        $result[] = [
            'id' => (int) $row->id,
            'revoked' => (bool) $row->revoked,
            'document_id' => (string) $row->document_id,
            'user_id' => $user_id,
            'student_id' => $student ? (int) $student->id : null,
            'role' => $role,
            'created_at' => (string) $row->created_at,
            'automatic' => '["automatic"]' === (string) $row->signature,
            'same_request' => (bool) $same_request,
            'minor' => $minor,
            'user_missing' => !isset($existing_users[$user_id]),
        ];
    }

    return $result;
}
