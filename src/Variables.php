<?php
declare(strict_types=1);

namespace Squuad\Certificados;

defined('ABSPATH') || exit;

/**
 * Variables generales de las plantillas (Antigravity/variable.md, grupo A): no dependen de ningún otro plugin y
 * sirven en cualquier sitio con wp-certificates. Se definen aquí, en el código, y no en la tabla variables_document:
 * son fijas, sus valores se calculan siempre en el código y en un sitio nuevo esa tabla está vacía.
 *
 * Esta clase solo dice qué variables son las generales (para mostrarlas en la ficha del documento). Sus valores se
 * siguen calculando donde se calculan hoy.
 */
final class Variables
{
    /**
     * Variables generales: [variable => descripción]. Las que llevan N o ID son familias (una por firmante).
     *
     * @return array<string, string>
     */
    public static function general(): array
    {
        return [
            '{{document_name}}' => __('Name of this document', 'wp-certificates'),
            '{{document_code}}' => __('Code (identifier) of this document', 'wp-certificates'),
            '{{today}}' => __('Today\'s date', 'wp-certificates'),
            '{{page_break}}' => __('Page break in the PDF', 'wp-certificates'),
            '{{signature_section}}' => __('Signatures of the roles not placed separately (empty when the document does not ask for signatures)', 'wp-certificates'),
            '{{qrcode}}' => __('QR code to validate the document', 'wp-certificates'),
            '{{tomo}}' => __('Volume of the registry book', 'wp-certificates'),
            '{{folio}}' => __('Folio of the registry book', 'wp-certificates'),
            '{{tomo_folio}}' => __('Volume and folio of the registry book', 'wp-certificates'),
            '{{signature}}' => __('Signature of the system signer (old templates)', 'wp-certificates'),
            '{{user_sign}}' => __('Name of the system signer (old templates)', 'wp-certificates'),
            '{{position_user_charge}}' => __('Position of the system signer (old templates)', 'wp-certificates'),
            '{{signature_N}}, {{user_sign_N}}, {{position_user_charge_N}}' => __('Signature, name and position of system signer number N (old templates)', 'wp-certificates'),
            '{{signature_signer_ID}}, {{signer_name_ID}}, {{signer_charge_ID}}' => __('Signature, name and position of a system signer (see "Document signers")', 'wp-certificates'),
            '{{key}}, {{key_list}}' => __('Answers to the additional fields of this document (see "Advanced")', 'wp-certificates'),
        ];
    }

    /**
     * Claves simples de las variables generales (today, page_break, document_name…), sin las familias _N, _ID ni los
     * campos adicionales.
     *
     * @return string[]
     */
    public static function general_keys(): array
    {
        $keys = [];
        foreach (array_keys(self::general()) as $entry) {
            if (preg_match('/^\{\{([a-z_]+)\}\}$/', $entry, $m)) {
                $keys[] = $m[1];
            }
        }

        return $keys;
    }

    /**
     * Las variables generales no están en la lista de la base de datos (variables_document): siempre están disponibles
     * (decisión del dueño, 2026-10-01). Quita las filas de la tabla cuya clave sea de una variable general; antes guarda
     * cada fila completa en el log, por si hubiera que recuperarla. No cambia ningún documento: la fila de la lista
     * nunca interviene al rellenarlo. Devuelve cuántas quitó.
     */
    public static function remove_general_from_catalog(): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'variables_document';
        $keys = self::general_keys();
        if (!$keys || $table !== $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table))) {
            return 0;
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE identificator IN (" . implode(',', array_fill(0, count($keys), '%s')) . ')',
            $keys
        ));
        $removed = 0;
        foreach ((array) $rows as $row) {
            Log::add(
                sprintf('Variable general %s (%d) quitada de la lista: siempre está disponible en el código', $row->identificator, $row->id),
                'variable_general_removed',
                ['row' => (array) $row]
            );
            $removed += (int) $wpdb->delete($table, ['id' => (int) $row->id], ['%d']);
        }

        return $removed;
    }

    /**
     * Vincula cada variable de la lista sin método con el método disponible que tiene su MISMA clave (p. ej.
     * {{student_name}} -> edusystem.student_name), si hay exactamente uno. Así las variables existentes siguen igual
     * pero las resuelve wp-certificates. No toca las que ya tienen método. Devuelve cuántas vinculó.
     */
    public static function link_by_key(): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'variables_document';
        if ($table !== $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table))) {
            return 0;
        }
        $by_key = [];
        foreach (VariableMethods::available() as $id => $method) {
            $by_key[$method['key']][] = $id;
        }
        $linked = 0;
        foreach ((array) $wpdb->get_results("SELECT id, identificator FROM {$table} WHERE method IS NULL OR method = ''") as $row) {
            $candidates = $by_key[(string) $row->identificator] ?? [];
            if (1 !== count($candidates)) {
                if (count($candidates) > 1) {
                    Log::add(sprintf('Variable %s sin vincular: varios métodos con la misma clave (%s)', $row->identificator, implode(', ', $candidates)), 'variable_link_ambiguous');
                }
                continue;
            }
            if ($wpdb->update($table, ['method' => $candidates[0]], ['id' => (int) $row->id], ['%s'], ['%d'])) {
                $linked++;
            }
        }
        if ($linked) {
            Log::add(sprintf('%d variables vinculadas con el método de su misma clave', $linked), 'variable_linked');
        }

        return $linked;
    }
}
