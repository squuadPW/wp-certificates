<?php
declare(strict_types=1);

/**
 * Documentos automáticos (ADR 0004 de EduSystem, decisión del dueño del 2026-10-01).
 *
 * Un documento automático (selector "automático" y activo) se muestra en Mi Cuenta solo si:
 * - pide firma: su plantilla tiene una variable de firma y el panel "Firmantes del documento" (que decide quién firma)
 *   pide la firma de alguien; o
 * - tiene campos adicionales: pide datos a quien lo carga.
 * Aparece solo a quien tiene que firmarlo o rellenarlo, hasta que lo hace, y por prioridad (0 la más urgente; empate:
 * el más antiguo). Nada más lo activa ni lo bloquea.
 */

defined('ABSPATH') || exit;

/** Variables de firma: recuadros de los roles ({{signature_role_<rol>}}, {{signature_student}}), de los firmantes por variable ({{signature_var_<variable>}}, ADR 0009 de Edusof), de cada firmante numerado ({{signature_F2}}, ADR 0010) y firmas de los firmantes del sistema. */
const SQUUAD_CERT_SIGNATURE_VARIABLE_PATTERN = '/\{\{(?:signature_section|signature_student|signature|signature_\d+|signature_signer_\d+|signature_role_[a-z0-9_]+|signature_var_[a-z0-9_]+|signature_F[1-9][0-9]?)\}\}/';

/** Prioridad máxima admitida (0 es la más urgente). */
const SQUUAD_CERT_PRIORITY_MAX = 999;

/** ¿La plantilla del documento (cabecera, contenido o pie) tiene alguna variable de firma? */
function squuad_cert_document_has_signature_variable(object $document): bool
{
    $template = (string) ($document->header ?? '') . (string) ($document->content ?? '') . (string) ($document->footer ?? '');

    return 1 === preg_match(SQUUAD_CERT_SIGNATURE_VARIABLE_PATTERN, $template);
}

/** ¿El documento tiene campos adicionales (columna fields)? */
function squuad_cert_document_has_fields(object $document): bool
{
    $fields = json_decode((string) ($document->fields ?? ''), true);

    return is_array($fields) && [] !== $fields;
}

/**
 * ¿El panel "Firmantes del documento" pide la firma de alguien? null si el módulo de firmas aún no está disponible en
 * wp-certificates (no se puede saber).
 */
function squuad_cert_document_policy_asks_signature(object $document): ?bool
{
    if (!function_exists('squuad_cert_signing_policy')) {
        return null;
    }
    $policy = squuad_cert_signing_policy($document);

    return !empty($policy['requires_signatures']) && !empty($policy['slots']);
}

/**
 * Si un documento automático se muestra en Mi Cuenta y por qué.
 *
 * @return array{automatic: bool, shown: bool, signature: bool, fields: bool, reasons: string[]}
 *   signature: pide firma (variable y panel); fields: tiene campos adicionales; reasons: por qué no se muestra.
 */
function squuad_cert_automatic_status(object $document): array
{
    $automatic = 'automatic' === ($document->type ?? '') && 1 === (int) ($document->status ?? 0);
    $variable = squuad_cert_document_has_signature_variable($document);
    $policy = squuad_cert_document_policy_asks_signature($document);
    $signature = $variable && false !== $policy;
    $fields = squuad_cert_document_has_fields($document);

    $reasons = [];
    if (!$signature && !$fields) {
        if (!$variable) {
            $reasons[] = __('its template has no signature variable', 'wp-certificates');
        }
        if (false === $policy) {
            $reasons[] = __('"Document signers" does not ask anyone to sign it', 'wp-certificates');
        }
        $reasons[] = __('it has no additional fields', 'wp-certificates');
    }

    return [
        'automatic' => $automatic,
        'shown' => $automatic && ($signature || $fields),
        'signature' => $signature,
        'fields' => $fields,
        'reasons' => $reasons,
    ];
}
