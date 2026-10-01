<?php
// Sin declare(strict_types=1) a propósito: el motor debe comportarse igual que el de EduSystem, que convierte los valores
// numéricos o booleanos a texto al reemplazar (con tipos estrictos, str_replace() rechaza un valor 0).

namespace Squuad\Certificados;

defined('ABSPATH') || exit;

/**
 * Motor de plantillas de wp-certificates (ADR 0004 de EduSystem: cada plugin con su propio motor). Copia exacta del
 * motor de EduSystem (process_template(), edusystem/includes/document-variables.php), para que los documentos salgan
 * igual: bloques {{#clave}}…{{/clave}} y {{^clave}}…{{/clave}}, variables {{clave}} y mayúsculas con el 'wrap'.
 * Los dos motores se comprueban con los mismos casos (Antigravity/tools/probar-motores.php de EduSystem).
 */
final class Template
{
    /**
     * @param string $template Texto con variables.
     * @param array<string, array{value: mixed, wrap: bool}> $replacements
     */
    public static function process($template, $replacements)
    {
        $span_open = '<span class="text-uppercase">';
        $span_close = '</span>';

        // Bloques condicionales {{#clave}}...{{/clave}}: el contenido se conserva solo si el valor de la clave es
        // verdadero; con {{^clave}}...{{/clave}} ("si no"), solo si es falso. Una clave que no existe se deja como
        // texto. Varias pasadas para bloques anidados de claves distintas.
        for ($pass = 0; $pass < 3 && (strpos($template, '{{#') !== false || strpos($template, '{{^') !== false); $pass++) {
            $processed = preg_replace_callback('/\{\{([#^])(\w+)\}\}(.*?)\{\{\/\2\}\}/s', function ($match) use ($replacements) {
                if (!isset($replacements[$match[2]])) {
                    return $match[0];
                }
                $value = $replacements[$match[2]]['value'];
                if ($value instanceof \Closure) {
                    $value = $value();
                }
                return ('#' === $match[1]) === (bool) $value ? $match[3] : '';
            }, $template);
            // Si la expresión falla (null) o ya no cambia nada, se deja el texto como está
            if (!is_string($processed) || $processed === $template) {
                break;
            }
            $template = $processed;
        }

        foreach ($replacements as $placeholder => $config) {
            $tag = '{{' . $placeholder . '}}';
            if (strpos($template, $tag) !== false) {
                $value = $config['value'];
                // Solo funciones anónimas: con is_callable() un valor de texto como «Link» o «Max» (p. ej. el
                // nombre de un estudiante) se ejecutaba como función de PHP
                if ($value instanceof \Closure) {
                    $value = $value();
                }
                if ($config['wrap']) {
                    $value = $span_open . $value . $span_close;
                }
                $template = str_replace($tag, $value, $template);
            }
        }
        return $template;
    }
}
