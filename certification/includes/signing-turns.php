<?php
declare(strict_types=1);

/**
 * Turno de firma y solicitudes creadas por el sistema (ADR 0012 de Edusof, decisiones del dueño del 2026-10-05).
 *
 * - El orden del panel «Firmantes del documento» es el turno de firma, también para quien recibe el documento: un
 *   firmante por variable (p. ej. el representante) puede firmar antes que el titular, con los datos del titular.
 * - Otro plugin puede abrir la solicitud de un documento automático para un titular en el momento que decida
 *   (squuad_cert_request_issue_for_holder(); EduSystem lo hace al vincular la cuenta del estudiante): así se sabe
 *   desde el principio quién firma y en qué turno, y la ve quien tiene el primer turno.
 * - Una solicitud a medio firmar se puede volver a pedir (squuad_cert_request_restart()): se anula sin borrar nada
 *   (firmas revocadas, legales y selladas) y se crea una ronda nueva con los firmantes actuales.
 * Sin otros plugins todo sigue igual: el titular primero, que abre su documento y crea su solicitud.
 */

if (!defined('ABSPATH')) exit;

/**
 * ¿Firma primero quien recibe el documento? (su fila de rol es la primera del panel). Sin política guardada, sin rol de
 * la cuenta en ella o sin firmas, como siempre: sí. Si no, la solicitud se crea antes de pedir nada, para saber de quién
 * es el primer turno (un firmante por variable vacío se omite y entonces el titular pasa a ser el primero).
 */
function squuad_cert_signing_policy_holder_first(object $document, WP_User $user): bool
{
    $policy = squuad_cert_signing_policy($document);
    $role = squuad_cert_signing_policy_role_for_user($policy + ['requires_signatures' => true], $user);
    if ('' === $role || empty($policy['slots'])) {
        return true;
    }
    foreach ($policy['slots'] as $slot) {
        // El primer puesto que puede firmar: otro rol del panel que la cuenta no tiene no cuenta (no estará en la solicitud)
        if ('role' === $slot['slot_type'] && $slot['role'] !== $role) {
            continue;
        }

        return 'role' === $slot['slot_type'];
    }

    return true;
}

/**
 * Abre la solicitud de firma de un documento automático para un titular (cuenta de WordPress), con los firmantes y los
 * turnos de la política vigente. Idempotente: si ya hay una abierta, la devuelve. No crea nada si el documento no está
 * activo o no se muestra (sin firma ni campos), si la cuenta no tiene un rol de los que firman el documento o si el
 * documento ya está resuelto (última ronda completada o cerrada por subida), salvo al volver a pedirlo ($origin
 * 'restarted'). No genera el contenido: el borrador lo prepara quien tiene el primer turno al abrirlo.
 *
 * @param int      $holder_user_id          Cuenta que recibe el documento (el titular).
 * @param int      $document_certificate_id Documento (documents_certificates.id).
 * @param int|null $external_ref            Requisito de otro plugin ligado al documento; null = el que dé el proveedor.
 * @param string   $origin                  'system' (otro plugin), 'opened' (al abrirlo) o 'restarted' (vuelto a pedir).
 */
function squuad_cert_request_issue_for_holder(int $holder_user_id, int $document_certificate_id, ?int $external_ref = null, string $origin = 'system'): ?object
{
    global $wpdb;

    if ($holder_user_id <= 0 || $document_certificate_id <= 0 || !squuad_cert_signature_requests_enabled()) {
        return null;
    }
    $holder = get_userdata($holder_user_id);
    $document = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}documents_certificates WHERE id = %d AND `type` = 'automatic' AND `status` = 1",
        $document_certificate_id
    ));
    if (!$holder || !$document || '' === (string) $document->document_identificator || !squuad_cert_automatic_status($document)['shown']) {
        return null;
    }
    if ('' === squuad_cert_signing_policy_role_for_user(squuad_cert_signing_policy($document), $holder)) {
        return null; // la cuenta no recibe este documento
    }
    $document_id = (string) $document->document_identificator;
    $latest = squuad_cert_signature_request_latest($holder_user_id, $document_id);
    if ($latest && in_array($latest->status, SQUUAD_CERT_SIGNATURE_REQUEST_OPEN, true)) {
        return $latest;
    }
    if ('restarted' !== $origin && $latest && in_array($latest->status, ['completed', 'closed_by_upload'], true)) {
        return null; // ya resuelto
    }

    $request = squuad_cert_signature_request_get_or_create(
        $holder_user_id,
        $document_id,
        ['holder_user_id' => $holder_user_id],
        $external_ref ?? squuad_cert_signature_external_ref($holder_user_id, $document_id, $document_certificate_id),
        squuad_cert_signature_doc_version_hash($document_id),
        $document_certificate_id,
        $origin
    );
    // Aviso a quien tiene el primer turno (una vez por puesto: squuad_cert_signer_notify_open_slots no repite)
    if ($request) {
        squuad_cert_signer_notify_open_slots((int) $request->id);
    }

    return $request;
}

/**
 * Vuelve a pedir las firmas de una solicitud a medio firmar (abierta o con alguna firma): revoca sus firmas (quedan
 * selladas como revocadas, nunca se borran), la cierra como declinada con el motivo y crea una ronda nueva con los
 * firmantes actuales (p. ej. el representante nuevo). Un documento con todas las firmas o completado no se toca.
 * Avisa con 'squuad_cert_request_restarted' (no con _declined: no es un rechazo del documento).
 *
 * @return array{ok: bool, message: string, request_id: int} request_id: la solicitud nueva (0 si no se creó).
 */
function squuad_cert_request_restart(int $request_id, string $reason, int $actor_user_id): array
{
    global $wpdb;

    $fail = static fn(string $message): array => ['ok' => false, 'message' => $message, 'request_id' => 0];
    $reason = trim($reason);
    $request = squuad_cert_signature_request_get($request_id);
    if (!$request || SQUUAD_CERT_SUBJECT_ACCOUNT !== (string) $request->subject_type) {
        return $fail(__('The signature request does not exist.', 'wp-certificates'));
    }
    if (!in_array($request->status, ['open', 'partially_signed'], true)) {
        return $fail(__('Only documents with signatures in progress can be requested again.', 'wp-certificates'));
    }
    if ('' === $reason) {
        return $fail(__('Write the reason for requesting the signatures again.', 'wp-certificates'));
    }

    $signature_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}squuad_cert_signatures WHERE request_id = %d",
        (int) $request->id
    ));
    $revoke_reason = sprintf('Firmas vueltas a pedir: %s', $reason);
    // Primero se cierra (si otra petición la cambió entre medias, no se revoca nada) y después se revocan sus firmas
    $closed = squuad_cert_signature_request_transition((int) $request->id, ['open', 'partially_signed'], 'declined', [
        'declined_at_utc' => gmdate('Y-m-d H:i:s'),
        'declined_by' => $actor_user_id,
        'decline_reason' => $revoke_reason,
    ]);
    if (!$closed) {
        return $fail(__('The signature request changed while you were working. Please reload the page.', 'wp-certificates'));
    }
    squuad_cert_revoke_signatures($signature_ids, $revoke_reason, $actor_user_id, (int) $request->external_ref ?: null);
    squuad_cert_signature_request_log_event((int) $request->id, 'restarted', [
        'reason' => $reason,
        'revoked_signatures' => array_map('intval', $signature_ids),
    ], $actor_user_id);

    $new = squuad_cert_request_issue_for_holder(
        (int) $request->subject_id,
        (int) $request->document_certificate_id,
        (int) $request->external_ref ?: null,
        'restarted'
    );
    squuad_cert_log(sprintf(
        'Solicitud %d vuelta a pedir por el usuario %d (%s): %s',
        (int) $request->id,
        $actor_user_id,
        $reason,
        $new ? 'ronda nueva ' . (int) $new->round . ', solicitud ' . (int) $new->id : 'no se pudo crear la ronda nueva'
    ), 'signature_request');
    do_action('squuad_cert_request_restarted', squuad_cert_request_event_payload(squuad_cert_signature_request_get((int) $request->id) ?? $request) + [
        'reason' => $reason,
        'actor_user_id' => $actor_user_id,
        'new_request_id' => $new ? (int) $new->id : 0,
    ]);
    if (!$new) {
        return ['ok' => true, 'message' => __('The signatures were cancelled, but the new request could not be created: the document is not active or the account no longer receives it.', 'wp-certificates'), 'request_id' => 0];
    }

    return ['ok' => true, 'message' => __('The signatures were requested again. Whoever has the first turn will see the document in their account.', 'wp-certificates'), 'request_id' => (int) $new->id];
}

/**
 * Solicitud a medio firmar (abierta o con alguna firma) ligada a un requisito de otro plugin (external_ref), o null.
 * Es la que se puede volver a pedir con squuad_cert_request_restart().
 */
function squuad_cert_request_restartable_for_ref(int $external_ref): ?object
{
    global $wpdb;

    if ($external_ref <= 0 || !squuad_cert_signature_requests_enabled()) {
        return null;
    }
    $request = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}squuad_cert_requests WHERE external_ref = %d AND subject_type = %s ORDER BY round DESC, id DESC LIMIT 1",
        $external_ref,
        SQUUAD_CERT_SUBJECT_ACCOUNT
    ));

    return $request && in_array($request->status, ['open', 'partially_signed'], true) ? $request : null;
}
