<?php
declare(strict_types=1);

/**
 * Firmante por variable (ADR 0009 de Edusof, decisión del dueño del 2026-10-04).
 *
 * Un documento automático puede pedir, además de la firma de quien lo recibe (rol) y de los firmantes del sistema, la
 * firma de la persona cuya cuenta de WordPress da una variable de un plugin propio (p. ej. {{parent_user_id}} de
 * EduSystem: el representante del estudiante). Esa persona firma EL MISMO documento del titular (misma solicitud, mismos
 * datos), desde su cuenta, en el puesto 'var:<variable>'.
 *
 * Reglas:
 * - La plantilla coloca el recuadro con {{signature_var_<variable>}} (y {{signer_name_var_<variable>}},
 *   {{signer_charge_var_<variable>}}); el panel «Firmantes del documento» decide si firma y en qué orden (política,
 *   slot_type 'var', role_key = la variable).
 * - La cuenta se resuelve al CREAR la solicitud, para su titular, solo con un método registrado de un plugin propio
 *   activo que se declara de cuenta ('account' => true, tipo texto). Nunca con un campo adicional ni con un valor del
 *   catálogo sin método. El valor tiene que ser el id de un usuario que existe; si no, el puesto se omite y queda en el
 *   registro de actividad y en el evento sellado 'created'.
 * - Una cuenta que ya firma la solicitud (quien la recibe, un firmante del sistema u otra variable) firma una sola vez.
 * - Solo solicitudes nuevas: los puestos se fijan y se sellan al crearlas.
 */

if (!defined('ABSPATH')) exit;

const SQUUAD_CERT_VAR_SLOT_PREFIX = 'var:';

/** Variables de firma por variable en una plantilla: signature_var_X, signer_name_var_X y signer_charge_var_X. */
const SQUUAD_CERT_SIGNER_VAR_TAG_PATTERN = '/\{\{(?:signature_var|signer_name_var|signer_charge_var)_([a-z][a-z0-9_]{1,59})\}\}/';

/** ¿Es un nombre de variable válido para un firmante por variable? (mismo formato que las claves de los métodos). */
function squuad_cert_signer_variable_name_valid(string $variable): bool
{
    return 1 === preg_match('/^[a-z][a-z0-9_]{1,59}$/', $variable);
}

/** Puesto de un firmante por variable: 'var:<variable>'. */
function squuad_cert_var_slot_key(string $variable): string
{
    return SQUUAD_CERT_VAR_SLOT_PREFIX . $variable;
}

/** ¿Es el puesto de un firmante por variable? */
function squuad_cert_is_var_slot(string $slot_key): bool
{
    return 0 === strpos($slot_key, SQUUAD_CERT_VAR_SLOT_PREFIX);
}

/** Variable de un puesto 'var:<variable>' ('' si no lo es). */
function squuad_cert_var_slot_variable(string $slot_key): string
{
    return squuad_cert_is_var_slot($slot_key) ? substr($slot_key, strlen(SQUUAD_CERT_VAR_SLOT_PREFIX)) : '';
}

/** ¿Es el puesto de un firmante del sistema ('signer:<id>')? */
function squuad_cert_is_signer_slot(string $slot_key): bool
{
    return 0 === strpos($slot_key, 'signer:');
}

/** ¿Firma este puesto una persona desde Mi Cuenta? (quien recibe el documento o un firmante por variable). */
function squuad_cert_is_person_slot(string $slot_key): bool
{
    return squuad_cert_is_holder_slot($slot_key) || squuad_cert_is_var_slot($slot_key);
}

/**
 * Variables que pueden designar a un firmante: métodos disponibles (plugin propio activo y activado, fuera de
 * cuarentena) que se declaran de cuenta. clave => ['method', 'label', 'signer_label', 'provider'].
 *
 * @return array<string, array{method: string, label: string, signer_label: string, provider: string}>
 */
function squuad_cert_signer_variables(): array
{
    $variables = [];
    if (!class_exists('\Squuad\Certificados\VariableMethods')) {
        return $variables;
    }
    foreach (\Squuad\Certificados\VariableMethods::available() as $id => $method) {
        if (empty($method['account']) || isset($variables[(string) $method['key']])) {
            continue;
        }
        $variables[(string) $method['key']] = [
            'method' => (string) $id,
            'label' => (string) $method['label'],
            'signer_label' => '' !== (string) $method['signer_label'] ? (string) $method['signer_label'] : (string) $method['label'],
            'provider' => (string) $method['provider'],
        ];
    }

    return $variables;
}

/**
 * Método que da valor a una variable de firmante (el asignado en la lista de variables o el único disponible con su
 * clave, como al rellenar una plantilla), solo si es un método de cuenta disponible. null si no hay.
 */
function squuad_cert_signer_variable_method(string $variable): ?array
{
    if (!squuad_cert_signer_variable_name_valid($variable) || !class_exists('\Squuad\Certificados\VariableRunner')) {
        return null;
    }
    $assigned = \Squuad\Certificados\VariableRunner::assigned_methods('{{' . $variable . '}}');
    $method = isset($assigned[$variable]) ? \Squuad\Certificados\VariableMethods::get_available($assigned[$variable]) : null;
    if (!$method || empty($method['account']) || 'text' !== $method['type']) {
        return null;
    }

    return $method;
}

/** Nombre del puesto de una variable para el recuadro (p. ej. «Representante»), o la propia variable si no está disponible. */
function squuad_cert_signer_variable_label(string $variable): string
{
    $method = squuad_cert_signer_variable_method($variable);
    if (!$method) {
        return $variable;
    }

    return '' !== (string) $method['signer_label'] ? (string) $method['signer_label'] : (string) $method['label'];
}

/**
 * Resuelve la cuenta que firma un puesto por variable para el titular de una solicitud. $subject_id: el que reciben los
 * métodos de las variables (la ficha de EduSystem del titular; 0 si no tiene). Ejecuta el método aislado (ADR 0005) y
 * exige un id de usuario que existe en el sitio. Nunca lee campos adicionales.
 *
 * @return array{user_id: int, reason: string, label: string} reason: '' si se resolvió; si no, por qué se omite
 *   (invalid_name, unavailable, no_subject, failed, empty, no_user).
 */
function squuad_cert_signer_variable_resolve(string $variable, int $subject_id, ?object $document = null): array
{
    $result = static fn(int $user_id, string $reason, string $label = ''): array => ['user_id' => $user_id, 'reason' => $reason, 'label' => $label];

    if (!squuad_cert_signer_variable_name_valid($variable)) {
        return $result(0, 'invalid_name');
    }
    $method = squuad_cert_signer_variable_method($variable);
    if (!$method) {
        return $result(0, 'unavailable');
    }
    $label = '' !== (string) $method['signer_label'] ? (string) $method['signer_label'] : (string) $method['label'];
    if ($subject_id <= 0 && !empty($method['subject'])) {
        return $result(0, 'no_subject', $label);
    }
    $resolved = \Squuad\Certificados\VariableRunner::resolve('{{' . $variable . '}}', $subject_id, array_filter(['document' => $document]));
    if (isset($resolved['failed'][$variable])) {
        return $result(0, 'failed', $label);
    }
    $value = trim((string) ($resolved['replacements'][$variable]['value'] ?? ''));
    if (!preg_match('/^[1-9][0-9]{0,19}$/', $value)) {
        return $result(0, 'empty', $label);
    }
    $user = get_userdata((int) $value);
    if (!$user || (is_multisite() && !is_user_member_of_blog((int) $user->ID))) {
        return $result(0, 'no_user', $label);
    }

    return $result((int) $user->ID, '', $label);
}

/**
 * Variables de firmante por variable que usa una plantilla (cabecera, contenido y pie): nombres sin repetir, en orden.
 *
 * @return string[]
 */
function squuad_cert_template_signer_variables(string $template): array
{
    preg_match_all(SQUUAD_CERT_SIGNER_VAR_TAG_PATTERN, $template, $found);

    return array_values(array_unique($found[1] ?? []));
}

/**
 * Fases de los puestos de una solicitud por el orden del panel: quien recibe el documento (rol) firma siempre primero
 * (fase 1: es quien lo abre, rellena sus campos y crea la solicitud); después cada firmante por variable tiene su fase y
 * los firmantes del sistema seguidos comparten la suya (como hasta ahora: rol en la fase 1 y firmantes en la 2).
 *
 * @param array $slots Puestos en orden, con slot_key.
 */
function squuad_cert_signing_slots_phases(array $slots): array
{
    $phase = 1;
    $previous = '';
    foreach ($slots as $i => $slot) {
        $key = (string) $slot['slot_key'];
        if (squuad_cert_is_holder_slot($key)) {
            $slots[$i]['phase'] = 1;
            $previous = 'role';
            continue;
        }
        $kind = squuad_cert_is_signer_slot($key) ? 'signer' : 'var';
        if (!('signer' === $kind && 'signer' === $previous)) {
            $phase++;
        }
        $slots[$i]['phase'] = $phase;
        $previous = $kind;
    }

    return $slots;
}

/**
 * Puesto por variable de una solicitud nueva: resuelve la variable para el titular de la solicitud. Devuelve el puesto
 * (con phase 0: la fija squuad_cert_request_slots_finalize()) o ['omitted' => motivo] si se omite.
 */
function squuad_cert_request_var_slot(object $request, ?object $document, string $variable): array
{
    $slot_key = squuad_cert_var_slot_key($variable);
    if (!$document || 'automatic' !== ($document->type ?? '')) {
        return ['slot_key' => $slot_key, 'omitted' => 'not_automatic'];
    }
    $resolved = squuad_cert_signer_variable_resolve($variable, squuad_cert_request_student_id($request), $document);
    if (!$resolved['user_id']) {
        return ['slot_key' => $slot_key, 'omitted' => $resolved['reason']];
    }

    return ['slot_key' => $slot_key, 'user_id' => $resolved['user_id'], 'signer_id' => 0, 'charge' => $resolved['label'], 'phase' => 0];
}

/**
 * Cierra los puestos de una solicitud nueva, en el orden de la política: una persona firma una sola vez (decisión 4: el
 * puesto por variable de una cuenta que ya firma se omite), fases por ese orden y registro de actividad de los
 * omitidos. Devuelve [puestos, omitidos] (los omitidos se sellan en el evento 'created').
 *
 * @param array $slots Puestos en orden; los omitidos llevan 'omitted' => motivo.
 * @return array{0: array, 1: array}
 */
function squuad_cert_request_slots_finalize(object $request, array $slots): array
{
    $omitted = [];
    $users = [];
    foreach ($slots as $slot) {
        if (!isset($slot['omitted']) && !squuad_cert_is_var_slot($slot['slot_key'])) {
            $users[(int) $slot['user_id']] = $slot['slot_key'];
        }
    }
    $kept = [];
    foreach ($slots as $slot) {
        if (isset($slot['omitted'])) {
            $omitted[] = ['slot' => $slot['slot_key'], 'reason' => $slot['omitted']];
            continue;
        }
        if (squuad_cert_is_var_slot($slot['slot_key'])) {
            $user_id = (int) $slot['user_id'];
            if (isset($users[$user_id])) {
                $omitted[] = ['slot' => $slot['slot_key'], 'reason' => 'same_account', 'signs_as' => $users[$user_id]];
                continue;
            }
            $users[$user_id] = $slot['slot_key'];
        }
        $kept[] = $slot;
    }

    foreach ($omitted as $item) {
        squuad_cert_log(sprintf(
            'Solicitud %d (%s, titular %s/%d): puesto %s omitido (%s)%s',
            (int) $request->id,
            (string) $request->document_id,
            (string) $request->subject_type,
            (int) $request->subject_id,
            $item['slot'],
            $item['reason'],
            isset($item['signs_as']) ? '; esa cuenta ya firma como ' . $item['signs_as'] : ''
        ), 'signer_variable');
    }

    return [squuad_cert_signing_slots_phases($kept), $omitted];
}

/**
 * Variables de plantilla de los firmantes por variable de una solicitud: {{signature_var_X}} (marcador del recuadro),
 * {{signer_name_var_X}} y {{signer_charge_var_X}}. Las de un puesto omitido no se dan (se quitan después).
 */
function squuad_cert_signature_var_replacements(object $request): array
{
    $replacements = [];
    foreach (squuad_cert_request_signers($request) as $signer) {
        $variable = squuad_cert_var_slot_variable($signer['slot_key']);
        if ('' === $variable) {
            continue;
        }
        $replacements['signature_var_' . $variable] = ['value' => squuad_cert_signer_slot_marker($signer['slot_key']), 'wrap' => false];
        $replacements['signer_name_var_' . $variable] = ['value' => esc_html($signer['name']), 'wrap' => true];
        $replacements['signer_charge_var_' . $variable] = ['value' => esc_html($signer['charge']), 'wrap' => true];
    }

    return $replacements;
}

/**
 * Nombre del puesto de una persona para su recuadro: el rol de quien recibe el documento o, en un puesto por variable,
 * el que quedó fijado en la solicitud (p. ej. «Representante»).
 */
function squuad_cert_person_slot_label(string $slot_key, ?object $request = null): string
{
    if (squuad_cert_is_var_slot($slot_key)) {
        if ($request) {
            foreach (squuad_cert_request_signers($request) as $signer) {
                if ($signer['slot_key'] === $slot_key && '' !== $signer['charge']) {
                    return $signer['charge'];
                }
            }
        }

        return squuad_cert_signer_variable_label(squuad_cert_var_slot_variable($slot_key));
    }

    return squuad_cert_holder_slot_label($slot_key);
}

/**
 * ¿Faltan firmas de otras personas después de la de este usuario? (para decidir si su navegador genera el PDF final:
 * solo quien da la última firma lo genera).
 */
function squuad_cert_signature_request_others_pending(object $request, int $user_id): bool
{
    $own = squuad_cert_signature_request_role($request, $user_id);
    $signed = squuad_cert_signature_request_signed_roles((int) $request->id);
    foreach (squuad_cert_request_signers($request) as $signer) {
        if ($signer['required'] && $signer['slot_key'] !== $own && !in_array($signer['slot_key'], $signed, true)) {
            return true;
        }
    }

    return false;
}

/**
 * Documentos de OTROS titulares en los que la cuenta ocupa un puesto por variable (p. ej. el representante en la
 * inscripción de su hijo): mismas filas que squuad_cert_signature_user_documents(), con los datos del titular. Solo
 * solicitudes en curso de documentos automáticos activos. Estados: 'to_sign' (su fase está abierta), 'queued' (esperan
 * firmas anteriores), 'waiting' (ya firmó) y 'pdf' (todas las firmas, falta el PDF final).
 */
function squuad_cert_signature_var_documents(WP_User $user): array
{
    global $wpdb;

    if (!$user->ID || !squuad_cert_signers_enabled()) {
        return [];
    }
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT r.*, rs.slot_key AS var_slot_key
         FROM {$wpdb->prefix}squuad_cert_request_signers rs
         JOIN {$wpdb->prefix}squuad_cert_requests r ON r.id = rs.request_id
         WHERE rs.user_id = %d AND rs.slot_key LIKE %s AND r.status IN ('open', 'partially_signed', 'signed')
         ORDER BY r.id ASC",
        (int) $user->ID,
        $wpdb->esc_like(SQUUAD_CERT_VAR_SLOT_PREFIX) . '%'
    ));
    $items = [];
    $documents = [];
    foreach ((array) $rows as $row) {
        $document_id = (int) $row->document_certificate_id;
        if (!array_key_exists($document_id, $documents)) {
            $documents[$document_id] = $document_id ? $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}documents_certificates WHERE id = %d AND `type` = 'automatic' AND `status` = 1",
                $document_id
            )) : null;
        }
        $document = $documents[$document_id];
        $slot_key = (string) $row->var_slot_key;
        unset($row->var_slot_key);
        if (!$document) {
            continue;
        }
        $request = $row;
        if ('signed' === $request->status) {
            $state = 'pdf';
        } elseif (in_array($slot_key, squuad_cert_signature_request_signed_roles((int) $request->id), true)) {
            $state = 'waiting';
        } elseif (null === $request->frozen_at_utc || !squuad_cert_signature_request_slot_open($request, $slot_key)) {
            $state = 'queued';
        } else {
            $state = 'to_sign';
        }
        $items[] = [
            'state' => $state,
            'subject_id' => (int) $request->subject_id,
            'student_id' => squuad_cert_request_student_id($request),
            'document' => $document,
            'request' => $request,
            'legacy_partial' => false,
            'slot_key' => $slot_key,
        ];
    }

    return $items;
}
