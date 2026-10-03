<?php

/**
 * API pública de verificación de certificados (la usa la página externa del QR: verificate-certificate/<código>).
 *
 * ADR 0004 de EduSystem, riesgo G3 y decisión 9: antes devolvía la fila completa del certificado y la del estudiante
 * (contraseña de Moodle, documento, fecha de nacimiento, datos de menores) a cualquiera con un código de 6
 * caracteres, sin límite. Ahora:
 * - Solo devuelve una lista blanca: los datos del certificado necesarios para validarlo y el nombre del titular.
 * - Límite de consultas fallidas por IP (SQUUAD_CERT_API_MAX_FAILURES por hora): pasado el límite responde 429.
 * - Los certificados nuevos llevan códigos de 128 bits (squuad_cert_new_certificate_code()); los cortos ya
 *   impresos siguen validando, con el mismo límite.
 * Se mantienen los nombres de la respuesta (success, message, certificate, student, url).
 */

/** Consultas fallidas permitidas por IP y hora antes de responder 429. */
const SQUUAD_CERT_API_MAX_FAILURES = 20;

function certificates_api()
{
    register_rest_route('api', '/get-certificate', array(
        'methods' => 'GET',
        'callback' => 'get_certificate_callback',
        'permission_callback' => '__return_true'
    ));
}
add_action('rest_api_init', 'certificates_api');

/** Código nuevo de verificación de un certificado: 32 caracteres hexadecimales al azar (128 bits). */
function squuad_cert_new_certificate_code(): string
{
    return bin2hex(random_bytes(16));
}

/**
 * Clave del contador de fallos de la IP de la petición. Se usa REMOTE_ADDR (las cabeceras de proxy se pueden
 * falsificar); un sitio detrás de un proxy de confianza puede dar la IP real con el filtro squuad_cert_api_client_ip.
 */
function squuad_cert_api_failures_key(): string
{
    $ip = (string) apply_filters('squuad_cert_api_client_ip', (string) ($_SERVER['REMOTE_ADDR'] ?? ''));

    return 'squuad_cert_api_fail_' . md5($ip);
}

/** Respuesta de "no encontrado" (o de parámetro inválido) que además suma un fallo a la IP. */
function squuad_cert_api_failure(string $message): WP_REST_Response
{
    $key = squuad_cert_api_failures_key();
    set_transient($key, (int) get_transient($key) + 1, HOUR_IN_SECONDS);

    return new WP_REST_Response(array('success' => false, 'message' => $message), 200);
}

/** Datos públicos del certificado: solo lo necesario para validarlo. */
function squuad_cert_api_public_certificate(object $certificate): array
{
    $expiration = (string) ($certificate->expiration_date ?? '');
    $expired = '' !== $expiration && '0000-00-00' !== substr($expiration, 0, 10) && strtotime($expiration) < strtotime(current_time('Y-m-d'));

    return array(
        'simple_uuid' => (string) $certificate->simple_uuid,
        'type' => (string) ($certificate->type ?? ''),
        'name_document' => (string) ($certificate->name_document ?? ''),
        'program_document' => (string) ($certificate->program_document ?? ''),
        'user' => (string) ($certificate->user ?? ''),
        'emission_date' => (string) ($certificate->emission_date ?? ''),
        'expiration_date' => $expiration,
        'tomo' => (string) ($certificate->tomo ?? ''),
        'folio' => (string) ($certificate->folio ?? ''),
        'status' => $expired ? 'expired' : 'valid',
    );
}

/** Titular: solo su nombre (de la ficha del estudiante de EduSystem o del participante de un curso). */
function squuad_cert_api_public_holder(object $certificate): array
{
    $source = null;
    if (!empty($certificate->participant_id) && function_exists('woocerti_get_participant_data')) {
        $source = woocerti_get_participant_data($certificate->participant_id);
    } elseif (!empty($certificate->email) && function_exists('get_student_by_email')) {
        $source = get_student_by_email($certificate->email);
    }
    $source = is_object($source) ? get_object_vars($source) : (is_array($source) ? $source : array());

    $holder = array();
    foreach (array('name', 'middle_name', 'last_name', 'middle_last_name', 'first_name') as $field) {
        if (isset($source[$field]) && is_scalar($source[$field])) {
            $holder[$field] = (string) $source[$field];
        }
    }
    $first = trim(implode(' ', array_filter(array($holder['name'] ?? ($holder['first_name'] ?? ''), $holder['middle_name'] ?? ''))));
    $last = trim(implode(' ', array_filter(array($holder['last_name'] ?? '', $holder['middle_last_name'] ?? ''))));
    $holder['full_name'] = trim($first . ' ' . $last) ?: (string) ($certificate->user ?? '');

    return $holder;
}

function get_certificate_callback(WP_REST_Request $request)
{
    // Límite por IP: demasiadas consultas fallidas en la última hora
    if ((int) get_transient(squuad_cert_api_failures_key()) >= SQUUAD_CERT_API_MAX_FAILURES) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => esc_html__('Too many attempts. Please try again later.', 'wp-certificates')
        ), 429);
    }

    // Código de verificación del QR: letras y números (6 caracteres los antiguos, 32 los nuevos); cualquier otra cosa
    // es "no encontrado"
    $certificate_id = $request->get_param('certificate_id');
    $certificate_id = is_string($certificate_id) ? trim($certificate_id) : '';
    if ('' === $certificate_id) {
        return squuad_cert_api_failure(esc_html__('The certificate_id parameter is missing.', 'wp-certificates'));
    }
    if (!preg_match('/^[A-Za-z0-9]{1,64}$/', $certificate_id)) {
        return squuad_cert_api_failure(esc_html__('Certificate not found', 'wp-certificates'));
    }

    global $wpdb;
    $certificate = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}certificates WHERE simple_uuid = %s",
        $certificate_id
    ));
    if (!$certificate) {
        return squuad_cert_api_failure(esc_html__('Certificate not found', 'wp-certificates'));
    }

    $response = array(
        'success' => true,
        'message' => esc_html__('Certificate found', 'wp-certificates'),
        'certificate' => squuad_cert_api_public_certificate($certificate),
        'student' => squuad_cert_api_public_holder($certificate),
        'url' => get_option('validation_url') . 'verificate-certificate/' . $certificate->simple_uuid,
    );

    return new WP_REST_Response($response, 200);
}
