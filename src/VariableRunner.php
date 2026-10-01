<?php
declare(strict_types=1);

namespace Squuad\Certificados;

defined('ABSPATH') || exit;

/**
 * Ejecutor de variables (ADR 0005, reglas 6 a 9). Resuelve las variables que una plantilla usa y que tienen un método
 * asignado en el catálogo (columna method de variables_document), ejecutando cada método aislado:
 * - solo se ejecutan las variables que aparecen en la plantilla;
 * - el identificador se valida otra vez contra el registro (lo de la BD nunca se ejecuta);
 * - errores y excepciones se capturan y cualquier salida se descarta; si falla, la variable queda vacía y va al log;
 * - un fallo no capturable pone el método en cuarentena (Quarantine);
 * - el valor se escapa según el tipo: texto escapado, HTML tal cual (código revisado del plugin), condición booleana.
 */
final class VariableRunner
{
    /**
     * @param string $template Texto de la plantilla (cabecera + contenido + pie).
     * @param int    $subject_id Titular (p. ej. students.id); 0 si no hay.
     * @param array  $ctx Contexto: document (fila de documents_certificates), subject_type, etc.
     * @return array{replacements: array<string, array{value: mixed, wrap: bool}>, failed: array<string, string>}
     */
    public static function resolve(string $template, int $subject_id, array $ctx = []): array
    {
        $replacements = [];
        $failed = [];

        foreach (self::assigned_methods($template) as $key => $method_id) {
            $method = VariableMethods::get_available($method_id);
            if (!$method) {
                $failed[$key] = Quarantine::has($method_id) ? 'quarantine' : 'unavailable';
                $replacements[$key] = ['value' => '', 'wrap' => false];
                continue;
            }
            if ($method['subject'] && $subject_id <= 0) {
                $failed[$key] = 'no_subject';
                $replacements[$key] = ['value' => '', 'wrap' => false];
                continue;
            }
            [$ok, $value] = self::run($method, $subject_id, $ctx);
            if (!$ok) {
                $failed[$key] = (string) $value;
                $value = 'condition' === $method['type'] ? false : '';
            }
            // wrap del método: process_template() pone las mayúsculas y cada punto escapa igual que antes
            $replacements[$key] = ['value' => self::format($method['type'], $value), 'wrap' => 'condition' !== $method['type'] && $method['wrap']];
        }

        if ($failed) {
            Log::add(
                sprintf('Variables sin valor al rellenar una plantilla: %s', implode(', ', array_map(
                    static fn($k, $why) => $k . ' (' . $why . ')',
                    array_keys($failed),
                    $failed
                ))),
                'variable_method_failed',
                ['subject_id' => $subject_id, 'document_id' => (int) ($ctx['document']->id ?? 0)]
            );
        }

        return ['replacements' => $replacements, 'failed' => $failed];
    }

    /**
     * Variables de la plantilla con método asignado en el catálogo: [clave => identificador del método].
     *
     * @return array<string, string>
     */
    public static function assigned_methods(string $template): array
    {
        global $wpdb;
        if (!preg_match_all('/\{\{[#^\/]?(\w+)\}\}/', $template, $found)) {
            return [];
        }
        $keys = array_values(array_unique($found[1]));
        $placeholders = implode(',', array_fill(0, count($keys), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT identificator, method FROM {$wpdb->prefix}variables_document
             WHERE method IS NOT NULL AND method <> '' AND identificator IN ({$placeholders})",
            $keys
        ));
        $assigned = [];
        foreach ((array) $rows as $row) {
            $assigned[(string) $row->identificator] = (string) $row->method;
        }

        // Variables de la plantilla que no están en la lista: si un único método disponible tiene su misma clave, se
        // usa ese (siguen funcionando como antes sin añadirlas a la lista). Las generales nunca son métodos.
        $general = Variables::general_keys();
        $by_key = [];
        foreach (VariableMethods::available() as $id => $method) {
            $by_key[$method['key']][] = $id;
        }
        foreach ($keys as $key) {
            if (!isset($assigned[$key]) && !in_array($key, $general, true) && 1 === count($by_key[$key] ?? [])) {
                $assigned[$key] = $by_key[$key][0];
            }
        }

        return $assigned;
    }

    /** Ejecuta un método aislado. Devuelve [true, valor] o [false, motivo]. */
    private static function run(array $method, int $subject_id, array $ctx): array
    {
        Quarantine::start($method['id']);
        ob_start();
        try {
            $value = call_user_func($method['callback'], $subject_id, $ctx);
            $result = [true, $value];
        } catch (\Throwable $e) {
            $result = [false, 'error: ' . $e->getMessage()];
        } finally {
            ob_end_clean();
            Quarantine::stop();
        }

        return $result;
    }

    private static function format(string $type, $value)
    {
        if ('condition' === $type) {
            return (bool) $value;
        }
        $value = is_scalar($value) ? (string) $value : '';
        if ('html' === $type) {
            return $value;
        }

        // Texto: escapado, y sin llaves para que no pueda abrir otra variable
        return str_replace(['{', '}'], ['&#123;', '&#125;'], esc_html($value));
    }
}
