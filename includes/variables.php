<?php
declare(strict_types=1);

/**
 * Variables generales de wp-certificates (Antigravity/variable.md, regla 1).
 *
 * squuad_cert_document_replacements(): valores de las variables del propio documento, {{document_name}} y
 * {{document_code}}, con el formato de process_template() (['value' => …, 'wrap' => …]). Se añaden a las demás
 * variables en cada punto que rellena una plantilla con un documento concreto.
 */

defined('ABSPATH') || exit;

/** {{document_name}} (nombre del documento) y {{document_code}} (su código o identificador), escapados. */
function squuad_cert_document_replacements(object $document): array
{
    // Mismo escapado que las demás variables de texto: el texto no puede abrir ni cerrar otra variable
    $text = static fn($value): string => str_replace(['{', '}'], ['&#123;', '&#125;'], esc_html((string) $value));

    return [
        'document_name' => ['value' => $text($document->title ?? ''), 'wrap' => false],
        'document_code' => ['value' => $text($document->document_identificator ?? ''), 'wrap' => false],
    ];
}

/**
 * Valores de las variables generales, calculados por wp-certificates con el mismo resultado que antes
 * (get_replacements_variables() de EduSystem): fecha, salto de página, hueco del QR, tomo y folio de la plantilla de
 * certificado (ctx certificate_id), y nombre y código del documento (ctx document).
 */
function squuad_cert_general_replacements(array $ctx = []): array
{
    $certificate = !empty($ctx['certificate_id']) && function_exists('get_certificate_details') ? get_certificate_details($ctx['certificate_id']) : null;
    $certificate = $certificate ?: (object) ['folio' => '', 'tomo' => ''];
    $replacements = [
        'today' => ['value' => date('M d, Y'), 'wrap' => false],
        'qrcode' => ['value' => '<div id="qrcode"></div>', 'wrap' => false],
        'page_break' => ['value' => '<div class="pagebreak"></div>', 'wrap' => false],
        // Recuadros de firma: vacío al generar sin firma; al firmar se sustituye por el hueco de las firmas
        'signature_section' => ['value' => '', 'wrap' => false],
        'folio' => ['value' => $certificate->folio ?? '', 'wrap' => true],
        'tomo' => ['value' => $certificate->tomo ?? '', 'wrap' => true],
        'tomo_folio' => ['value' => 'Tomo: ' . ($certificate->tomo ?? '') . ' Folio: ' . ($certificate->folio ?? ''), 'wrap' => true],
    ];
    if (isset($ctx['document']) && is_object($ctx['document'])) {
        $replacements = array_merge($replacements, squuad_cert_document_replacements($ctx['document']));
    }

    return $replacements;
}

/**
 * Todos los valores de una plantilla, resueltos por wp-certificates (ADR 0005): las variables generales y las de los
 * plugins propios por sus métodos, ejecutados aislados. Es lo que usan los puntos que generan documentos en lugar de
 * llamar a EduSystem. ctx: document, certificate_id, code_period, cut_period.
 *
 * Titular (opcional): ctx holder_type (p. ej. 'wp_user') y holder_id. Sus variables (variables_catalog() y
 * replacements() del proveedor) solo rellenan las que ningún plugin activo resuelve: con EduSystem activo,
 * {{full_name}} y las demás salen de EduSystem.
 *
 * @return array{replacements: array, failed: array} replacements con el formato de process_template()
 */
function squuad_cert_template_replacements(string $template, int $subject_id, array $ctx = []): array
{
    $holder = squuad_cert_holder_provider($ctx);
    if ($holder) {
        $ctx['holder_keys'] = $holder['keys'];
    }
    $resolved = \Squuad\Certificados\VariableRunner::resolve($template, $subject_id, $ctx);
    $replacements = array_merge(squuad_cert_general_replacements($ctx), $resolved['replacements']);

    if ($holder) {
        $values = (array) call_user_func($holder['callbacks']['replacements'], $holder['id'], $ctx['document'] ?? null, $ctx);
        foreach ($values as $key => $config) {
            if (in_array((string) $key, $holder['keys'], true) && !isset($replacements[$key]) && is_array($config) && array_key_exists('value', $config)) {
                $replacements[$key] = ['value' => $config['value'], 'wrap' => !empty($config['wrap'])];
            }
        }
    }

    // Un campo adicional ya existente llamado como una variable nueva del sistema ({{full_name}}) sigue ganando: se quita
    // aquí y quien llama pone la respuesta del campo (o vacío), como antes
    if (isset($ctx['document']) && empty($ctx['keep_shadowed']) && function_exists('squuad_cert_document_fields_shadowed_keys')) {
        foreach (squuad_cert_document_fields_shadowed_keys($ctx['document']) as $key) {
            unset($replacements[$key]);
            unset($resolved['failed'][$key]);
        }
    }

    return [
        'replacements' => $replacements,
        'failed' => $resolved['failed'],
    ];
}

/**
 * Proveedor del titular indicado en ctx (holder_type, holder_id), si está registrado y aporta variables
 * (replacements y variables_catalog): ['id' => int, 'callbacks' => array, 'keys' => string[]], o null.
 */
function squuad_cert_holder_provider(array $ctx): ?array
{
    $type = (string) ($ctx['holder_type'] ?? '');
    $id = (int) ($ctx['holder_id'] ?? 0);
    $callbacks = '' !== $type && $id > 0 && squuad_cert_holder_type_active($type) ? squuad_cert_subject_type($type) : null;
    if (!$callbacks || empty($callbacks['replacements']) || empty($callbacks['variables_catalog'])) {
        return null;
    }

    return ['id' => $id, 'callbacks' => $callbacks, 'keys' => array_keys(squuad_cert_holder_catalog($type))];
}

/** ¿Aporta variables este tipo de titular? wp_user solo sin EduSystem (squuad_cert_wp_user_holder_enabled()). */
function squuad_cert_holder_type_active(string $type): bool
{
    if (SQUUAD_CERT_SUBJECT_ACCOUNT === $type) {
        return squuad_cert_wp_user_holder_enabled();
    }

    return true;
}

/**
 * Variables que aportan los titulares (variables_catalog() de cada proveedor): clave => descripción. Con $type, solo
 * las de ese tipo de titular.
 *
 * @return array<string, string>
 */
function squuad_cert_holder_catalog(string $type = ''): array
{
    $catalog = [];
    foreach ('' !== $type ? [$type] : squuad_cert_subject_types() as $subject_type) {
        $callbacks = squuad_cert_holder_type_active($subject_type) ? squuad_cert_subject_type($subject_type) : null;
        if (!$callbacks || empty($callbacks['variables_catalog']) || empty($callbacks['replacements'])) {
            continue;
        }
        foreach ((array) call_user_func($callbacks['variables_catalog']) as $key => $label) {
            if (is_string($key) && preg_match('/^[a-z][a-z0-9_]*$/', $key) && !isset($catalog[$key])) {
                $catalog[$key] = (string) $label;
            }
        }
    }

    return $catalog;
}

/**
 * Variables de la persona (Antigravity/variable.md, grupo B), neutras y propias de wp-certificates: clave => descripción.
 * El valor lo da EduSystem (método edusystem.<clave>) si está activo y, si no, la cuenta de WordPress (titular wp_user).
 * {{student_name}} no está aquí: es una variable de EduSystem.
 *
 * @return array<string, string>
 */
function squuad_cert_person_variables(): array
{
    return [
        'full_name' => __('Full name of the person (last names, first names)', 'wp-certificates'),
        'name' => __('First names of the person', 'wp-certificates'),
        'last_name' => __('Last names of the person', 'wp-certificates'),
        'email' => __('Email of the person', 'wp-certificates'),
    ];
}

/**
 * Variables de la persona que hoy tienen quien les dé valor: clave => ['label' => descripción, 'source' => identificador
 * del método (un único método disponible con esa clave) o 'holder' (la cuenta de WordPress)]. Las que no tienen a nadie
 * no salen. Las usan el panel «Datos» (las que no están en la lista de la base de datos) y Certificación > Variables.
 *
 * @param array|null $available Métodos disponibles (VariableMethods::available()).
 * @return array<string, array{label: string, source: string}>
 */
function squuad_cert_person_variables_offered(?array $available = null): array
{
    $available = $available ?? \Squuad\Certificados\VariableMethods::available();
    $by_key = [];
    foreach ($available as $id => $method) {
        $by_key[(string) $method['key']][] = (string) $id;
    }
    $holder = squuad_cert_holder_catalog();
    $offered = [];
    foreach (squuad_cert_person_variables() as $key => $label) {
        if (1 === count($by_key[$key] ?? [])) {
            $offered[$key] = ['label' => $label, 'source' => $by_key[$key][0]];
        } elseif (isset($holder[$key])) {
            $offered[$key] = ['label' => $label, 'source' => 'holder'];
        }
    }

    return $offered;
}

/**
 * ¿Quién da valor a una variable de la lista (fila de variables_document)? Decide si se ofrece al escribir una
 * plantilla (panel «Datos») y qué aviso lleva en Certificación > Variables:
 * - 'method': su método está disponible (plugin propio activo);
 * - 'same_key': sin método, pero hay un único método disponible con su misma clave (lo usa al rellenar);
 * - 'holder': la da el titular (p. ej. la cuenta de WordPress) porque ningún plugin activo la da;
 * - 'quarantine': su método está en cuarentena;
 * - 'unavailable': el plugin de su proveedor está activo pero ese método ya no existe (se sigue ofreciendo, como antes);
 * - 'requires_edusystem': su método es de EduSystem y EduSystem no está activo;
 * - 'plugin_inactive': su método es de un plugin que no está activo o no está activado en Variables;
 * - 'no_method': sin método y nadie la da.
 * Se ofrecen method, same_key, holder y unavailable.
 *
 * @param array|null $available Métodos disponibles (VariableMethods::available()), para no calcularlos en cada fila.
 * @param array|null $holder Catálogo de los titulares (squuad_cert_holder_catalog()).
 */
function squuad_cert_catalog_variable_status(object $row, ?array $available = null, ?array $holder = null): string
{
    $available = $available ?? \Squuad\Certificados\VariableMethods::available();
    $holder = $holder ?? squuad_cert_holder_catalog();
    $key = (string) ($row->identificator ?? '');
    $method = (string) ($row->method ?? '');

    if ('' !== $method) {
        if (isset($available[$method])) {
            return 'method';
        }
        if (\Squuad\Certificados\Quarantine::has($method)) {
            return 'quarantine';
        }
        if (isset($holder[$key])) {
            return 'holder';
        }

        $provider = (string) strtok($method, '.');
        if ('edusystem' === $provider && !wpc_edusystem_active()) {
            return 'requires_edusystem';
        }
        // El proveedor tiene otros métodos disponibles: su plugin está activo y activado, pero este método no existe
        foreach ($available as $m) {
            if ((string) $m['provider'] === $provider) {
                return 'unavailable';
            }
        }

        return 'plugin_inactive';
    }
    $same_key = array_filter($available, static fn(array $m): bool => (string) $m['key'] === $key);
    if (1 === count($same_key) && !in_array($key, \Squuad\Certificados\Variables::general_keys(), true)) {
        return 'same_key';
    }

    return isset($holder[$key]) ? 'holder' : 'no_method';
}

/** ¿Se ofrece la variable al escribir una plantilla? (ver squuad_cert_catalog_variable_status()). */
function squuad_cert_catalog_variable_offered(string $status): bool
{
    return in_array($status, ['method', 'same_key', 'holder', 'unavailable'], true);
}

/** Motor de plantillas propio de wp-certificates (mismo comportamiento que el de EduSystem). */
function squuad_cert_process_template($template, $replacements)
{
    return \Squuad\Certificados\Template::process($template, $replacements);
}
