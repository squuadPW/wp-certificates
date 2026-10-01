<?php
/**
 * EduSystem - Certificación: firmas ya guardadas para el modal de firma (AJAX squuad_cert_load_signatures_data). Movido sin
 * cambios desde public/functions/checkout/cart.php (ADR 0004, paso 3c).
 */

if (!defined('ABSPATH')) exit;

add_action('wp_ajax_load_signatures_data', 'squuad_cert_load_signatures_data');

/**
 * Firmas ya guardadas del estudiante y su representante para un documento (las pinta create-enrollment.js).
 * Solo con sesión; el estudiante y el representante salen del usuario actual, no de la petición.
 */
function squuad_cert_load_signatures_data()
{
    global $wpdb, $current_user;

    if (!check_ajax_referer('edusystem_signatures', false, false)) {
        wp_send_json_error(__('Your session expired. Please reload the page.', 'edusystem'), 403);
    }

    // Con solicitud (ADR 0002): las firmas de esa solicitud, solo para sus firmantes
    $request_id = absint($_POST['request_id'] ?? 0);
    if ($request_id && function_exists('squuad_cert_signature_requests_enabled') && squuad_cert_signature_requests_enabled()) {
        $request = squuad_cert_signature_request_get($request_id);
        if (!$request || '' === squuad_cert_signature_request_role($request, (int) $current_user->ID)) {
            wp_send_json_error(__('You are not allowed to sign this document.', 'edusystem'), 403);
        }
        $by_role = [];
        foreach ($wpdb->get_results($wpdb->prepare(
            "SELECT signer_role, signature FROM {$wpdb->prefix}users_signatures WHERE request_id = %d",
            $request_id
        )) as $row) {
            $by_role[$row->signer_role] = json_decode($row->signature);
        }
        wp_send_json(array(
            'grade_selected' => null,
            'student_signature' => $by_role['student'] ?? [],
            'parent_signature' => $by_role['parent'] ?? [],
        ));
    }

    $roles = (array) $current_user->roles;
    $document = is_string($_POST['document'] ?? null) ? sanitize_text_field(wp_unslash($_POST['document'])) : 'ENROLLMENT';
    $table_students = $wpdb->prefix . 'students';
    $student_id = 0;
    $partner_id = 0;

    if (in_array('student', $roles, true)) {
        $student = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_students} WHERE email = %s", $current_user->user_email));
        $partner_id = $student ? (int) $student->partner_id : 0;
        $student_id = $current_user->ID;
    } elseif (in_array('parent', $roles, true)) {
        $student = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_students} WHERE partner_id = %d", $current_user->ID));
        $user_student = $student ? get_user_by('email', $student->email) : null;
        $student_id = $user_student ? $user_student->ID : 0;
        $partner_id = $current_user->ID;
    }

    $table_signatures = $wpdb->prefix . 'users_signatures';
    $query = "SELECT * FROM {$table_signatures} WHERE user_id = %d AND document_id = %s";
    $student_signature = $student_id ? $wpdb->get_row($wpdb->prepare($query, $student_id, $document)) : null;
    $parent_signature = $partner_id ? $wpdb->get_row($wpdb->prepare($query, $partner_id, $document)) : null;

    $grade_selected = null;
    if ($parent_signature) {
        $grade_selected = $parent_signature->grade_selected ? $parent_signature->grade_selected : null;
    } else if ($student_signature) {
        $grade_selected = $student_signature->grade_selected ? $student_signature->grade_selected : null;
    }
    wp_send_json(array('grade_selected' => $grade_selected, 'parent_signature' => $parent_signature ? json_decode($parent_signature->signature) : [], 'student_signature' => $student_signature ? json_decode($student_signature->signature) : []));
}
