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
