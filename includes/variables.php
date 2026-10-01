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

// Métodos de las variables generales (ADR 0005, proveedor core): no dependen de ningún otro plugin
add_action('squuad_cert_register_providers', 'squuad_cert_register_core_variable_methods');
function squuad_cert_register_core_variable_methods(): void
{
    $document = static fn(array $ctx): ?object => isset($ctx['document']) && is_object($ctx['document']) ? $ctx['document'] : null;

    squuad_cert_register_variable_method('core', 'today', [
        'label' => __("Today's date", 'wp-certificates'),
        'group' => __('General', 'wp-certificates'),
        'type' => 'text',
        'subject' => false,
        'callback' => static fn(int $subject_id, array $ctx): string => date_i18n('M d, Y'),
    ]);
    squuad_cert_register_variable_method('core', 'page_break', [
        'label' => __('Page break in the PDF', 'wp-certificates'),
        'group' => __('General', 'wp-certificates'),
        'type' => 'html',
        'offered' => 'document',
        'subject' => false,
        'callback' => static fn(int $subject_id, array $ctx): string => '<div class="pagebreak"></div>',
    ]);
    squuad_cert_register_variable_method('core', 'document_name', [
        'label' => __('Name of this document', 'wp-certificates'),
        'group' => __('Document', 'wp-certificates'),
        'type' => 'text',
        'offered' => 'document',
        'subject' => false,
        'callback' => static fn(int $subject_id, array $ctx): string => (string) ($document($ctx)->title ?? ''),
    ]);
    squuad_cert_register_variable_method('core', 'document_code', [
        'label' => __('Code (identifier) of this document', 'wp-certificates'),
        'group' => __('Document', 'wp-certificates'),
        'type' => 'text',
        'offered' => 'document',
        'subject' => false,
        'callback' => static fn(int $subject_id, array $ctx): string => (string) ($document($ctx)->document_identificator ?? ''),
    ]);
}
