<?php
declare(strict_types=1);

/**
 * EduSystem - Comando WP-CLI de evidencias de firma (ADR 0001, paso 3).
 *
 *   wp squuad-cert firmas verificar    Recorre la cadena y muestra los estados (sale con código 1 si hay problemas)
 */

if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('squuad-cert firmas verificar', 'squuad_cert_signature_cli_verify', [
    'shortdesc' => 'Verifica la cadena de huellas de las firmas de estudiantes y representantes.',
]);

function squuad_cert_signature_cli_verify(): void
{
    if (!squuad_cert_signature_evidence_enabled()) {
        WP_CLI::error('El esquema de la BD aún no está en la versión 4: no hay huellas que verificar.');
    }

    $result = squuad_cert_signature_verify_chain();

    $items = [];
    foreach ($result['status'] as $status => $total) {
        $items[] = ['estado' => $status, 'firmas' => $total];
    }
    WP_CLI\Utils\format_items('table', $items, ['estado', 'firmas']);
    WP_CLI::log(sprintf(
        'Vivas: %d · anuladas: %d · último eslabón: %d · cabeza de la cadena: %s',
        $result['live'],
        $result['revoked'],
        $result['last_seq'],
        $result['head_ok'] ? 'correcta' : 'NO COINCIDE'
    ));
    foreach ($result['problems'] as $problem) {
        WP_CLI::warning(sprintf(
            'Firma #%d%s (%s, usuario %d, estudiante %s): %s',
            $problem['id'],
            $problem['revoked'] ? ' anulada' : '',
            $problem['document_id'],
            $problem['user_id'],
            $problem['student_id'] ?? '—',
            $problem['status']
        ));
    }

    $bad = $result['status']['altered'] + ($result['status']['retired_key'] ?? 0) + $result['status']['chain_broken'] + $result['status']['unknown_key']
        + $result['status']['missing'] + ($result['head_ok'] ? 0 : 1);
    if ($bad > 0) {
        WP_CLI::halt(1);
    }
    WP_CLI::success('Cadena de firmas verificada sin problemas.');
}
