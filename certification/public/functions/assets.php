<?php
/**
 * Certificación - Dónde insiste el modal de los documentos automáticos y sus scripts (ADR 0004, paso 4b-4; decisión
 * del dueño del 2026-10-02).
 *
 * El modal se abre a la cuenta conectada que tiene algo pendiente: en "Mi Cuenta" si el sitio tiene WooCommerce y, si
 * no, en cualquier página pública. Antes lo abría EduSystem desde su escritorio de Mi Cuenta y él cargaba los scripts.
 * Los nombres de los scripts y del objeto JS (create-enrollment, edusystemSignatures) se mantienen: los usa
 * create-enrollment.js.
 */

if (!defined('ABSPATH')) exit;

/** ¿Se puede abrir aquí el modal de los documentos automáticos? */
function squuad_cert_modal_page(): bool
{
    if (is_admin() || !is_user_logged_in() || wp_doing_ajax()) {
        return false;
    }

    return function_exists('is_account_page') ? is_account_page() : true;
}

add_action('wp_enqueue_scripts', 'squuad_cert_signature_public_assets');
function squuad_cert_signature_public_assets(): void
{
    if (!squuad_cert_modal_page()) {
        return;
    }
    $version = defined('WP_C_VERSION') ? WP_C_VERSION : null;

    // Recuadro de firma propio (sin librerías de terceros; decisión del dueño del 2026-10-02)
    wp_register_script('squuad-cert-signature-pad', SQUUAD_CERT_MODULE_URL . 'admin/assets/js/signature-pad-edusystem.js', [], $version, true);
    $deps = ['jquery', 'squuad-cert-signature-pad'];

    wp_register_script('create-enrollment', SQUUAD_CERT_MODULE_URL . 'public/assets/js/create-enrollment.js', $deps, $version, true);
    wp_localize_script('create-enrollment', 'ajax_object', ['ajax_url' => admin_url('admin-ajax.php')]);
    // Nonce de las firmas en una variable propia: varios scripts redefinen ajax_object
    wp_localize_script('create-enrollment', 'edusystemSignatures', [
        'nonce' => wp_create_nonce('edusystem_signatures'),
        'currentUserId' => get_current_user_id(),
        'i18n' => [
            'pendingStudent' => __('Pending: the student must sign from their own account', 'edusystem'),
            'pendingParent' => __('Pending: the parent or guardian must sign from their own account', 'edusystem'),
            'signYourPart' => __('To continue, please sign in your area or generate your signature automatically.', 'edusystem'),
            'waitingOther' => __('Your signature is saved. The document will be completed when the other person signs from their own account.', 'edusystem'),
            'consentRequired' => __('To sign, you must accept signing the document electronically.', 'edusystem'),
        ],
    ]);
    wp_enqueue_script('create-enrollment');
    wp_enqueue_script('squuad-cert-signature-pad'); // también lo usa la firma en lote (documents-to-sign-batch.php)
}

// El modal se pinta al final de la página (insiste hasta que la cuenta firma o rellena lo pendiente)
add_action('wp_footer', 'squuad_cert_signature_modal_footer');
function squuad_cert_signature_modal_footer(): void
{
    if (squuad_cert_modal_page()) {
        squuad_cert_modal_document_automatic();
    }
}
