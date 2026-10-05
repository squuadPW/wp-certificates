<?php
/**
 * Certificación: etiqueta de firma de quien recibe el documento (squuad_cert_get_signature_section,
 * squuad_cert_signature_pad_box). Movido desde includes/html-documents.php de EduSystem (ADR 0004, paso 3c).
 *
 * Firma solo el estudiante, con su cuenta de WordPress (decisión del dueño, 2026-10-01): el recuadro muestra el nombre
 * de esa cuenta, el mismo que queda en el documento firmado. No se consulta la ficha de EduSystem: el nombre es el
 * fijado en la solicitud o, sin solicitud, el de la cuenta conectada (la dueña del documento).
 */

if (!defined('ABSPATH')) exit;

/** Nombre de la cuenta que ocupa un puesto: el fijado en la solicitud o, sin solicitud, el de la cuenta conectada. */
function squuad_cert_signature_holder_name(string $slot_key, ?object $request = null): string
{
    if ($request) {
        foreach (squuad_cert_request_signers($request) as $signer) {
            if ($slot_key === $signer['slot_key']) {
                return (string) $signer['name'];
            }
        }
    }

    return squuad_cert_account_name(get_current_user_id());
}

/** {{signature_section}}: el recuadro de quien recibe el documento (el único puesto que firma desde Mi Cuenta). */
function squuad_cert_get_signature_section(?object $request = null): string
{
    $holder = $request ? squuad_cert_request_holder_slot($request) : '';

    return '' !== $holder ? squuad_cert_signature_pad_box($holder, $request) : '';
}

/**
 * Etiqueta «Firmar aquí» de quien recibe el documento ($slot_key 'role:<rol>') o de un firmante por variable, para
 * colocarla en la plantilla con {{signature_role_<rol>}} o en {{signature_section}} (ADR 0003, variables de firma por
 * firmante). Los firmantes del sistema no tienen etiqueta aquí: firman desde su bandeja. El nombre es el de la cuenta de
 * WordPress. Desde el ADR 0011 de Edusof es la etiqueta del flujo nuevo (antes, un lienzo con «Generar firma
 * automáticamente»); la firma se adopta en la ventana «Adoptar su firma» (create-enrollment.js).
 */
function squuad_cert_signature_pad_box(string $slot_key, ?object $request = null): string
{
    // Quien recibe el documento o un firmante por variable (ADR 0009 de Edusof), que firma el mismo documento
    if (!squuad_cert_is_person_slot($slot_key) || !$request) {
        return '';
    }

    return squuad_cert_signature_tag_html($slot_key, $request, 0, true);
}
