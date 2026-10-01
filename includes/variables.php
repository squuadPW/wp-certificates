<?php
declare(strict_types=1);

/**
 * Variables generales de wp-certificates (Antigravity/variable.md, regla 1).
 *
 * squuad_cert_document_replacements(): valores de las variables del propio documento, {{document_name}} y
 * {{document_code}}, con el formato de process_template() (['value' => …, 'wrap' => …]). Se añaden a las demás
 * variables en cada punto que rellena una plantilla con un documento concreto.
 */

defined('ABSPATH') || exit;

/** {{document_name}} (nombre del documento) y {{document_code}} (su código o identificador), escapados. */
function squuad_cert_document_replacements(object $document): array
{
    // Mismo escapado que las demás variables de texto: el texto no puede abrir ni cerrar otra variable
    $text = static fn($value): string => str_replace(['{', '}'], ['&#123;', '&#125;'], esc_html((string) $value));

    return [
        'document_name' => ['value' => $text($document->title ?? ''), 'wrap' => false],
        'document_code' => ['value' => $text($document->document_identificator ?? ''), 'wrap' => false],
    ];
}

/**
 * Valores de las variables generales, calculados por wp-certificates con el mismo resultado que antes
 * (get_replacements_variables() de EduSystem): fecha, salto de página, hueco del QR, tomo y folio de la plantilla de
 * certificado (ctx certificate_id), y nombre y código del documento (ctx document).
 */
function squuad_cert_general_replacements(array $ctx = []): array
{
    $certificate = !empty($ctx['certificate_id']) && function_exists('get_certificate_details') ? get_certificate_details($ctx['certificate_id']) : null;
    $certificate = $certificate ?: (object) ['folio' => '', 'tomo' => ''];
    $replacements = [
        'today' => ['value' => date('M d, Y'), 'wrap' => false],
        'qrcode' => ['value' => '<div id="qrcode"></div>', 'wrap' => false],
        'page_break' => ['value' => '<div class="pagebreak"></div>', 'wrap' => false],
        'folio' => ['value' => $certificate->folio ?? '', 'wrap' => true],
        'tomo' => ['value' => $certificate->tomo ?? '', 'wrap' => true],
        'tomo_folio' => ['value' => 'Tomo: ' . ($certificate->tomo ?? '') . ' Folio: ' . ($certificate->folio ?? ''), 'wrap' => true],
    ];
    if (isset($ctx['document']) && is_object($ctx['document'])) {
        $replacements = array_merge($replacements, squuad_cert_document_replacements($ctx['document']));
    }

    return $replacements;
}

/**
 * Todos los valores de una plantilla, resueltos por wp-certificates (ADR 0005): las variables generales y las de los
 * plugins propios por sus métodos, ejecutados aislados. Es lo que usan los puntos que generan documentos en lugar de
 * llamar a EduSystem. ctx: document, certificate_id, code_period, cut_period.
 *
 * @return array{replacements: array, failed: array} replacements con el formato de process_template()
 */
function squuad_cert_template_replacements(string $template, int $subject_id, array $ctx = []): array
{
    $resolved = \Squuad\Certificados\VariableRunner::resolve($template, $subject_id, $ctx);

    return [
        'replacements' => array_merge(squuad_cert_general_replacements($ctx), $resolved['replacements']),
        'failed' => $resolved['failed'],
    ];
}

/** Motor de plantillas propio de wp-certificates (mismo comportamiento que el de EduSystem). */
function squuad_cert_process_template($template, $replacements)
{
    return \Squuad\Certificados\Template::process($template, $replacements);
}
