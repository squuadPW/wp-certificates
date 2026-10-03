<?php
declare(strict_types=1);

/**
 * Conexiones API (decisión del dueño del 2026-10-02): claves para que otro sistema (p. ej. el sitio principal de la
 * institución, en otro dominio o subdominio) consulte esta plataforma de servidor a servidor.
 *
 * - Cada clave tiene nombre, permisos (por ahora solo «Verificar documentos»), fecha de alta, autor y último uso.
 * - La clave se muestra una sola vez al crearla: en la base de datos solo se guarda su huella (SHA-256) y un prefijo
 *   para reconocerla. Una clave revocada deja de funcionar al instante.
 * - Altas, revocaciones y usos quedan en el log.
 * La API que las usa está en includes/rest-v1.php; la pantalla, en admin/api-keys.php.
 */

defined('ABSPATH') || exit;

const SQUUAD_CERT_API_KEYS_OPTION = 'squuad_cert_api_keys';
const SQUUAD_CERT_API_KEY_PREFIX = 'sqc_';

/** Permisos que se pueden dar a una clave: clave => [nombre, descripción]. Preparado para añadir otros (p. ej. PDF). */
function squuad_cert_api_scopes(): array
{
    return (array) apply_filters('squuad_cert_api_scopes', [
        'verify' => [__('Verify documents', 'wp-certificates'), __('Check a document by its code and get the data needed to validate it.', 'wp-certificates')],
    ]);
}

/** Todas las claves (sin la clave en claro, que nunca se guarda), por id. */
function squuad_cert_api_keys(): array
{
    $keys = get_option(SQUUAD_CERT_API_KEYS_OPTION, []);

    return is_array($keys) ? $keys : [];
}

/**
 * Crea una clave. Devuelve ['key' => clave en claro (mostrarla una sola vez), 'row' => datos guardados] o un WP_Error.
 *
 * @param string[] $scopes
 */
function squuad_cert_api_key_create(string $name, array $scopes)
{
    $name = trim(sanitize_text_field($name));
    $scopes = array_values(array_intersect(array_map('strval', $scopes), array_keys(squuad_cert_api_scopes())));
    if ('' === $name) {
        return new WP_Error('name', __('The name of the connection is required.', 'wp-certificates'));
    }
    if (!$scopes) {
        return new WP_Error('scopes', __('Choose at least one permission.', 'wp-certificates'));
    }

    $plain = SQUUAD_CERT_API_KEY_PREFIX . bin2hex(random_bytes(24));
    $id = bin2hex(random_bytes(6));
    $row = [
        'id' => $id,
        'name' => $name,
        'prefix' => substr($plain, 0, 12),
        'hash' => hash('sha256', $plain),
        'scopes' => $scopes,
        'created_at' => gmdate('Y-m-d H:i:s'),
        'created_by' => get_current_user_id(),
        'revoked_at' => null,
        'revoked_by' => null,
        'last_used_at' => null,
    ];
    $keys = squuad_cert_api_keys();
    $keys[$id] = $row;
    update_option(SQUUAD_CERT_API_KEYS_OPTION, $keys, false);
    squuad_cert_log(sprintf('Clave de API «%s» (%s…) creada por el usuario %d. Permisos: %s', $name, $row['prefix'], get_current_user_id(), implode(', ', $scopes)), 'api_keys');

    return ['key' => $plain, 'row' => $row];
}

/** Revoca una clave (deja de funcionar al instante). Devuelve false si no existe o ya estaba revocada. */
function squuad_cert_api_key_revoke(string $id): bool
{
    $keys = squuad_cert_api_keys();
    if (!isset($keys[$id]) || !empty($keys[$id]['revoked_at'])) {
        return false;
    }
    $keys[$id]['revoked_at'] = gmdate('Y-m-d H:i:s');
    $keys[$id]['revoked_by'] = get_current_user_id();
    update_option(SQUUAD_CERT_API_KEYS_OPTION, $keys, false);
    squuad_cert_log(sprintf('Clave de API «%s» (%s…) revocada por el usuario %d', $keys[$id]['name'], $keys[$id]['prefix'], get_current_user_id()), 'api_keys');

    return true;
}

/**
 * Clave vigente que corresponde a la clave en claro y tiene el permiso pedido, o null. Compara las huellas en tiempo
 * constante. Anota el último uso (como mucho una vez por minuto, para no escribir en cada consulta).
 */
function squuad_cert_api_key_authenticate(string $plain, string $scope): ?array
{
    $plain = trim($plain);
    if (0 !== strpos($plain, SQUUAD_CERT_API_KEY_PREFIX) || strlen($plain) > 128) {
        return null;
    }
    $hash = hash('sha256', $plain);
    $keys = squuad_cert_api_keys();
    foreach ($keys as $id => $row) {
        if (!hash_equals((string) ($row['hash'] ?? ''), $hash)) {
            continue;
        }
        if (!empty($row['revoked_at']) || !in_array($scope, (array) ($row['scopes'] ?? []), true)) {
            return null;
        }
        $last = $row['last_used_at'] ? strtotime($row['last_used_at'] . ' UTC') : 0;
        if (time() - $last >= MINUTE_IN_SECONDS) {
            $keys[$id]['last_used_at'] = gmdate('Y-m-d H:i:s');
            update_option(SQUUAD_CERT_API_KEYS_OPTION, $keys, false);
        }

        return $row;
    }

    return null;
}
