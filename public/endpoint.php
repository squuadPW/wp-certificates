<?php

function certificates_api()
{
    register_rest_route('api', '/get-certificate', array(
        'methods' => 'GET',
        'callback' => 'get_certificate_callback',
        'permission_callback' => '__return_true'
    ));
}
add_action('rest_api_init', 'certificates_api');

function get_certificate_callback(WP_REST_Request $request)
{
    // Obtener el parámetro certificate_id de la URL
    $certificate_id = $request->get_param('certificate_id');

    // Verificar si el certificate_id está presente
    if ($certificate_id) {
        global $wpdb;
        $table_certificates = $wpdb->prefix . 'certificates';
        $certificate = $wpdb->get_row("SELECT * FROM {$table_certificates} WHERE simple_uuid = '{$certificate_id}'");
        if ($certificate) {
            $student = isset($certificate->participant_id) ? (function_exists('woocerti_get_participant_data') ? woocerti_get_participant_data($certificate->participant_id) : null) : (function_exists('get_student_by_email') ? get_student_by_email($certificate->email) : null);
            $url = get_option('validation_url') . 'verificate-certificate/' . $certificate->simple_uuid;
            $response = array(
                'success' => true,
                'message' => esc_html__('Certificate found', 'wp-certificates'),
                'certificate' => $certificate,
                'student' => $student,
                'url' => $url
            );
        } else {
            $response = array(
                'success' => false,
                'message' => esc_html__('Certificate not found', 'wp-certificates')
            );
        }
    } else {
        $response = array(
            'success' => false,
            'message' => esc_html__('The certificate_id parameter is missing.', 'wp-certificates')
        );
    }

    // Devolver la respuesta en formato JSON
    return new WP_REST_Response($response, 200);
}