<?php
/**
 * Certificación: firmas ya guardadas para el modal de firma (AJAX load_signatures_data). Movido desde
 * public/functions/checkout/cart.php de EduSystem (ADR 0004, paso 3c). Solo con solicitud (ADR 0002): sin ella no hay
 * nada que devolver (el camino antiguo por documento y usuario no pasa a wp-certificates).
 */

if (!defined('ABSPATH')) exit;

add_action('wp_ajax_load_signatures_data', 'squuad_cert_load_signatures_data');

/**
 * OBSOLETO desde el ADR 0011 de Edusof (2026-10-05): la ventana de firma nueva no la llama (las firmas ya dadas las
 * pinta el servidor en el documento). Se mantiene por compatibilidad con páginas antiguas en caché; se puede retirar en
 * una versión posterior.
 *
 * Firma ya guardada del estudiante en una solicitud (la pintaba create-enrollment.js), solo para sus firmantes.
 * parent_signature va siempre vacía: el representante ya no firma (el JS antiguo la sigue esperando).
 */
function squuad_cert_load_signatures_data()
{
    global $wpdb, $current_user;

    if (!check_ajax_referer('edusystem_signatures', false, false)) {
        wp_send_json_error(__('Your session expired. Please reload the page.', 'wp-certificates'), 403);
    }

    // Con solicitud (ADR 0002): las firmas de esa solicitud, solo para sus firmantes
    $request_id = absint($_POST['request_id'] ?? 0);
    if ($request_id && function_exists('squuad_cert_signature_requests_enabled') && squuad_cert_signature_requests_enabled()) {
        $request = squuad_cert_signature_request_get($request_id);
        if (!$request || '' === squuad_cert_signature_request_role($request, (int) $current_user->ID)) {
            wp_send_json_error(__('You are not allowed to sign this document.', 'wp-certificates'), 403);
        }
        $by_role = [];
        foreach ($wpdb->get_results($wpdb->prepare(
            "SELECT signer_role, signature FROM {$wpdb->prefix}squuad_cert_signatures WHERE request_id = %d",
            $request_id
        )) as $row) {
            $by_role[$row->signer_role] = json_decode($row->signature);
        }
        // Firma del puesto de quien mira (quien recibe el documento, 'role:<rol>', o un firmante por variable,
        // 'var:<variable>', ADR 0009 de Edusof): el JS la pinta en el recuadro «student», el único que se firma aquí
        $own = squuad_cert_signature_request_role($request, (int) $current_user->ID);
        wp_send_json(array(
            'grade_selected' => null,
            'student_signature' => squuad_cert_is_person_slot($own) ? ($by_role[$own] ?? []) : ($by_role[squuad_cert_request_holder_slot($request)] ?? []),
            'parent_signature' => [],
        ));
    }

    wp_send_json(array('grade_selected' => null, 'parent_signature' => [], 'student_signature' => []));
}
