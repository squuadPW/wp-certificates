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
    $version = function_exists('squuad_cert_signing_assets_version') ? squuad_cert_signing_assets_version() : (defined('WP_C_VERSION') ? WP_C_VERSION : null);

    // Ventana de firma (ADR 0011 de Edusof): variables de Edusof UI dentro de .eds-scope (claro, oscuro o según el
    // dispositivo), su hoja propia y las fuentes manuscritas servidas desde el plugin. Solo en la página que la abre
    if (defined('EDUSOF_UI_URL')) {
        wp_enqueue_style('edusof-ui', EDUSOF_UI_URL . 'assets/edusof-ui.css', [], defined('EDUSOF_UI_VERSION') ? EDUSOF_UI_VERSION : null);
    }
    squuad_cert_signature_fonts_enqueue();
    wp_enqueue_style('squuad-cert-signing', SQUUAD_CERT_MODULE_URL . 'public/assets/css/signing.css', defined('EDUSOF_UI_URL') ? ['edusof-ui'] : [], $version);

    // Recuadro de firma propio (sin librerías de terceros; decisión del dueño del 2026-10-02)
    wp_register_script('squuad-cert-signature-pad', SQUUAD_CERT_MODULE_URL . 'admin/assets/js/signature-pad-edusystem.js', [], $version, true);
    // QR del certificado de firmas: el generador que ya usa el plugin (de terceros, pendiente de reescribir: ADR 0011)
    wp_register_script('squuad-cert-qrcode', squuad_cert_qrcode_script_url(), [], $version, true);
    $deps = ['jquery', 'squuad-cert-signature-pad', 'squuad-cert-qrcode'];

    wp_register_script('create-enrollment', SQUUAD_CERT_MODULE_URL . 'public/assets/js/create-enrollment.js', $deps, $version, true);
    wp_localize_script('create-enrollment', 'ajax_object', ['ajax_url' => admin_url('admin-ajax.php')]);
    // Nonce de las firmas en una variable propia: varios scripts redefinen ajax_object
    wp_localize_script('create-enrollment', 'edusystemSignatures', [
        'nonce' => wp_create_nonce('edusystem_signatures'),
        'currentUserId' => get_current_user_id(),
        'uploadMaxBytes' => SQUUAD_CERT_SIGNATURE_UPLOAD_MAX_BYTES,
        'typedMax' => SQUUAD_CERT_SIGNATURE_TYPED_MAX,
        'fonts' => array_map(static fn(array $style): string => $style['family'], squuad_cert_signature_styles()),
        'i18n' => [
            'saveFailed' => __('The document could not be saved. Please reload the page and try again.', 'wp-certificates'),
            'consentRequired' => __('Tick the box to be able to sign.', 'wp-certificates'),
            'start' => __('Start', 'wp-certificates'),
            'next' => __('Next', 'wp-certificates'),
            'finish' => __('Finish', 'wp-certificates'),
            /* translators: 1: signatures done, 2: signatures to do */
            'counter' => __('%1$d of %2$d signatures', 'wp-certificates'),
            'guideNext' => __('Press each «Sign here» tag with your colour. The «Next» button takes you to the next one.', 'wp-certificates'),
            'guideDone' => __('You have signed in all your places. Review the document and press «Finish».', 'wp-certificates'),
            /* translators: 1: position, 2: number of the tag, 3: total of tags */
            'tagLabel' => __('Sign here: %1$s (signature %2$d of %3$d)', 'wp-certificates'),
            /* translators: 1: position, 2: number of the tag, 3: total of tags */
            'tagSigned' => __('Signed: %1$s (signature %2$d of %3$d). Press «Change» to adopt another signature.', 'wp-certificates'),
            /* translators: %d: number of places where the person signs (2 or more) */
            'adoptDesc' => __('Choose how you want to sign. We will use this signature in the %d places where you must sign.', 'wp-certificates'),
            'adoptDescOne' => __('Choose how you want to sign. We will use this signature in the place where you must sign.', 'wp-certificates'),
            /* translators: 1: number of the place, 2: position */
            'usePlace' => __('Signature %1$d · %2$s', 'wp-certificates'),
            'typedEmpty' => __('Type your signature (up to 80 characters) and choose a style.', 'wp-certificates'),
            'drawnEmpty' => __('Draw your signature before signing.', 'wp-certificates'),
            'imageEmpty' => __('Choose the image of your signature.', 'wp-certificates'),
            'imageType' => __('The image must be a PNG or JPG file of up to 2 MB.', 'wp-certificates'),
            'imageConfirm' => __('Confirm that the image is your own signature.', 'wp-certificates'),
            'saving' => __('Saving your signature…', 'wp-certificates'),
            'signedTitle' => __('Document signed', 'wp-certificates'),
            'signedText' => __('We will let you know when the others sign.', 'wp-certificates'),
            'completeTitle' => __('Document signed by everyone', 'wp-certificates'),
            'completeText' => __('All signatures are complete. The final PDF with the certificate of signatures is being generated.', 'wp-certificates'),
            'pdfOk' => __('The final PDF was generated and saved.', 'wp-certificates'),
            'pdfFail' => __('The final PDF could not be saved now. You can generate it from «Documents to sign».', 'wp-certificates'),
            'you' => __('You', 'wp-certificates'),
            'stateSigned' => __('Signed', 'wp-certificates'),
            'stateTurn' => __('Their turn', 'wp-certificates'),
            'statePending' => __('Pending', 'wp-certificates'),
            'turnHint' => __('It is their turn to sign', 'wp-certificates'),
            'pendingHint' => __('Will sign after the previous signatures', 'wp-certificates'),
            'yourTurnChip' => __('your turn', 'wp-certificates'),
            'signedChip' => __('signed', 'wp-certificates'),
            /* translators: %s: name of the person who signed */
            'signatureOf' => __('Signature of %s', 'wp-certificates'),
            'closeConfirm' => __('You have adopted a signature but the document is not signed yet. Close without signing?', 'wp-certificates'),
            'save' => __('Save', 'wp-certificates'),
        ],
    ]);
    wp_enqueue_script('create-enrollment');
    wp_enqueue_script('squuad-cert-signature-pad'); // también lo usa la firma en lote (documents-to-sign-batch.php)
}

// El modal se pinta al final de la página (insiste hasta que la cuenta firma o rellena lo pendiente)
add_action('wp_footer', 'squuad_cert_signature_modal_footer');
function squuad_cert_signature_modal_footer(): void
{
    // Otro plugin puede aplazarlo si en esta página muestra un modal prioritario (p. ej. EduSystem: crear la
    // contraseña o elegir una electiva); en la siguiente visita vuelve a insistir
    if (squuad_cert_modal_page() && apply_filters('squuad_cert_show_signature_modal', true)) {
        squuad_cert_modal_document_automatic();
    }
}
