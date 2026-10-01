<?php
declare(strict_types=1);

namespace Squuad\Certificados;

defined('ABSPATH') || exit;

/**
 * Variables generales de las plantillas (Antigravity/variable.md, grupo A): no dependen de ningún otro plugin y
 * sirven en cualquier sitio con wp-certificates. Se definen aquí, en el código, y no en la tabla variables_document:
 * son fijas, sus valores se calculan siempre en el código y en un sitio nuevo esa tabla está vacía.
 *
 * Esta clase solo dice qué variables son las generales (para mostrarlas en la ficha del documento). Sus valores se
 * siguen calculando donde se calculan hoy.
 */
final class Variables
{
    /**
     * Variables generales: [variable => descripción]. Las que llevan N o ID son familias (una por firmante).
     *
     * @return array<string, string>
     */
    public static function general(): array
    {
        return [
            '{{document_name}}' => __('Name of this document', 'wp-certificates'),
            '{{document_code}}' => __('Code (identifier) of this document', 'wp-certificates'),
            '{{today}}' => __('Today\'s date', 'wp-certificates'),
            '{{page_break}}' => __('Page break in the PDF', 'wp-certificates'),
            '{{qrcode}}' => __('QR code to validate the document', 'wp-certificates'),
            '{{tomo}}' => __('Volume of the registry book', 'wp-certificates'),
            '{{folio}}' => __('Folio of the registry book', 'wp-certificates'),
            '{{tomo_folio}}' => __('Volume and folio of the registry book', 'wp-certificates'),
            '{{signature}}' => __('Signature of the institutional signer', 'wp-certificates'),
            '{{user_sign}}' => __('Name of the institutional signer', 'wp-certificates'),
            '{{position_user_charge}}' => __('Position of the institutional signer', 'wp-certificates'),
            '{{signature_N}}, {{user_sign_N}}, {{position_user_charge_N}}' => __('Signature, name and position of institutional signer number N', 'wp-certificates'),
            '{{signature_signer_ID}}, {{signer_name_ID}}, {{signer_charge_ID}}' => __('Signature, name and position of a system signer (see "Document signers")', 'wp-certificates'),
            '{{key}}, {{key_list}}' => __('Answers to the additional fields of this document (see "Advanced")', 'wp-certificates'),
        ];
    }
}
