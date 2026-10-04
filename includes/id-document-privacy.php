<?php
declare(strict_types=1);

/**
 * Documento de identidad en las herramientas de privacidad de WordPress (Herramientas › Exportar / Borrar datos
 * personales), M3 del ADR 0007 de Edusof.
 *
 * - Exportador: tipo, número, identificador, origen (quién y cuándo lo registró) y el documento sellado en cada firma de
 *   la persona (vivas y anuladas).
 * - Borrador: borra las metas del documento; las firmas se conservan (tienen valor legal y su huella sella el
 *   identificador): items_retained con el motivo.
 */

defined('ABSPATH') || exit;

add_filter('wp_privacy_personal_data_exporters', 'squuad_cert_id_document_register_exporter');
function squuad_cert_id_document_register_exporter($exporters)
{
    $exporters['wp-certificates-id-document'] = [
        'exporter_friendly_name' => __('Identity document (WP Certificates)', 'wp-certificates'),
        'callback' => 'squuad_cert_id_document_privacy_export',
    ];

    return $exporters;
}

add_filter('wp_privacy_personal_data_erasers', 'squuad_cert_id_document_register_eraser');
function squuad_cert_id_document_register_eraser($erasers)
{
    $erasers['wp-certificates-id-document'] = [
        'eraser_friendly_name' => __('Identity document (WP Certificates)', 'wp-certificates'),
        'callback' => 'squuad_cert_id_document_privacy_erase',
    ];

    return $erasers;
}

/** Firmas de la persona con documento sellado: filas con id, documento, fecha, identificador y origen. */
function squuad_cert_id_document_privacy_signatures(int $user_id): array
{
    global $wpdb;

    if (!squuad_cert_id_document_evidence_enabled()) {
        return [];
    }
    $rows = [];
    foreach (['squuad_cert_signatures' => false, 'squuad_cert_signatures_revoked' => true] as $table => $revoked) {
        $id = $revoked ? 'signature_row_id' : 'id';
        foreach ((array) $wpdb->get_results($wpdb->prepare(
            "SELECT {$id} AS id, document_id, signed_at_utc, signer_id_document, signer_id_origin FROM {$wpdb->prefix}{$table}
             WHERE user_id = %d AND signer_id_document IS NOT NULL AND signer_id_document <> '' ORDER BY id",
            $user_id
        )) as $row) {
            $row->revoked = $revoked;
            $rows[] = $row;
        }
    }

    return $rows;
}

function squuad_cert_id_document_privacy_export($email, $page = 1): array
{
    $user = get_user_by('email', (string) $email);
    $items = [];
    if ($user) {
        $document = squuad_cert_id_document_get((int) $user->ID);
        if ($document) {
            $origin = squuad_cert_id_document_origin_parts($document['origin']);
            $items[] = [
                'group_id' => 'wp-certificates-id-document',
                'group_label' => __('Identity document', 'wp-certificates'),
                'item_id' => 'id-document-' . (int) $user->ID,
                'data' => [
                    ['name' => __('Document type', 'wp-certificates'), 'value' => $document['type'] ? squuad_cert_id_document_type_label($document['type']) : '—'],
                    ['name' => __('Document number', 'wp-certificates'), 'value' => $document['number']],
                    ['name' => __('Identifier', 'wp-certificates'), 'value' => $document['identifier']],
                    ['name' => __('Origin', 'wp-certificates'), 'value' => squuad_cert_id_document_origin_label($document['origin'])],
                    ['name' => __('Registered on (UTC)', 'wp-certificates'), 'value' => '' !== $origin['at'] ? $origin['at'] : $document['updated']],
                ],
            ];
        }
        foreach (squuad_cert_id_document_privacy_signatures((int) $user->ID) as $row) {
            $items[] = [
                'group_id' => 'wp-certificates-id-document-signatures',
                'group_label' => __('Identity document recorded in signatures', 'wp-certificates'),
                'item_id' => 'signature-' . ($row->revoked ? 'revoked-' : '') . (int) $row->id,
                'data' => [
                    ['name' => __('Document', 'wp-certificates'), 'value' => (string) $row->document_id],
                    ['name' => __('Signed on (UTC)', 'wp-certificates'), 'value' => (string) $row->signed_at_utc],
                    ['name' => __('Identifier', 'wp-certificates'), 'value' => (string) $row->signer_id_document],
                    ['name' => __('Origin', 'wp-certificates'), 'value' => null === $row->signer_id_origin ? '—' : squuad_cert_id_document_origin_label((string) $row->signer_id_origin)],
                    ['name' => __('Revoked', 'wp-certificates'), 'value' => $row->revoked ? __('Yes', 'wp-certificates') : __('No', 'wp-certificates')],
                ],
            ];
        }
    }

    return ['data' => $items, 'done' => true];
}

function squuad_cert_id_document_privacy_erase($email, $page = 1): array
{
    $user = get_user_by('email', (string) $email);
    $removed = false;
    $retained = false;
    $messages = [];
    if ($user) {
        $user_id = (int) $user->ID;
        foreach ([SQUUAD_CERT_ID_DOCUMENT_META, SQUUAD_CERT_ID_DOCUMENT_TYPE_META, SQUUAD_CERT_ID_DOCUMENT_NUMBER_META, SQUUAD_CERT_ID_DOCUMENT_UPDATED_META, SQUUAD_CERT_ID_DOCUMENT_ORIGIN_META] as $key) {
            if ('' !== (string) get_user_meta($user_id, $key, true)) {
                $removed = delete_user_meta($user_id, $key) || $removed;
            }
        }
        if ($removed) {
            squuad_cert_log(sprintf('Documento de identidad del usuario %d borrado por la herramienta de privacidad (usuario %d)', $user_id, get_current_user_id()), 'id_document_erased');
        }
        if (squuad_cert_id_document_privacy_signatures($user_id)) {
            $retained = true;
            $messages[] = __('The identity document recorded in the signatures of this person was kept: it is part of the legal evidence of each signature and cannot be removed without breaking its verification.', 'wp-certificates');
        }
    }

    return ['items_removed' => $removed, 'items_retained' => $retained, 'messages' => $messages, 'done' => true];
}
