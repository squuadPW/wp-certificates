<?php
declare(strict_types=1);

/**
 * API para sistemas conectados, versión 1 (/wp-json/squuad-cert/v1/). Exige una clave de Conexiones API en la
 * cabecera X-API-Key (nunca en la URL). Hoy solo verifica un documento por su código, sin búsqueda por persona:
 *
 *   GET /wp-json/squuad-cert/v1/documents/<código>
 *
 * Devuelve los mismos datos que la verificación pública (public/endpoint.php): los del documento para validarlo y el
 * nombre del titular. Sin clave, con una clave falsa, revocada o sin el permiso, responde 401 sin decir si el código
 * existe. Con clave válida no se aplica el límite de intentos por IP de la API pública.
 */

defined('ABSPATH') || exit;

add_action('rest_api_init', 'squuad_cert_rest_v1_routes');
function squuad_cert_rest_v1_routes(): void
{
    register_rest_route('squuad-cert/v1', '/documents/(?P<code>[A-Za-z0-9]{1,64})', [
        'methods' => 'GET',
        'callback' => 'squuad_cert_rest_v1_document',
        'permission_callback' => 'squuad_cert_rest_v1_can_verify',
        'args' => [
            'code' => ['type' => 'string', 'required' => true],
        ],
    ]);
}

/** Permiso de la ruta: clave vigente con el permiso «verify». */
function squuad_cert_rest_v1_can_verify(WP_REST_Request $request)
{
    $key = squuad_cert_api_key_authenticate((string) $request->get_header('x-api-key'), 'verify');
    if (!$key) {
        return new WP_Error('squuad_cert_api_unauthorized', __('Missing or invalid API key.', 'wp-certificates'), ['status' => 401]);
    }
    $request->set_param('_squuad_cert_api_key', $key['id']);

    return true;
}

function squuad_cert_rest_v1_document(WP_REST_Request $request): WP_REST_Response
{
    global $wpdb;

    $code = (string) $request->get_param('code');
    $certificate = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}certificates WHERE simple_uuid = %s",
        $code
    ));
    $keys = squuad_cert_api_keys();
    $key = $keys[(string) $request->get_param('_squuad_cert_api_key')] ?? [];
    squuad_cert_log(sprintf('Consulta por API con la clave «%s» (%s…): documento %s, %s', $key['name'] ?? '?', $key['prefix'] ?? '?', $code, $certificate ? 'encontrado' : 'no encontrado'), 'api_use');

    if (!$certificate) {
        return new WP_REST_Response([
            'success' => false,
            'message' => __('Document not found.', 'wp-certificates'),
        ], 404);
    }

    return new WP_REST_Response([
        'success' => true,
        'document' => squuad_cert_api_public_certificate($certificate),
        'holder' => squuad_cert_api_public_holder($certificate),
        'url' => get_option('validation_url') . 'verificate-certificate/' . $certificate->simple_uuid,
    ], 200);
}
