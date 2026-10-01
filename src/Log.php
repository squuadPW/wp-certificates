<?php
declare(strict_types=1);

namespace Squuad\Certificados;

defined('ABSPATH') || exit;

/**
 * Log propio de wp-certificates (tabla {prefix}squuad_cert_log). Funciona con o sin EduSystem; si EduSystem está,
 * cada entrada se copia también a su log (edusystem_set_log). No es evidencia legal: eso son los eventos sellados.
 */
final class Log
{
    public static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'squuad_cert_log';
    }

    /** DDL para dbDelta (create_tables_certificates). */
    public static function schema(string $charset_collate): string
    {
        return 'CREATE TABLE ' . self::table() . " (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        created_at_utc DATETIME NOT NULL,
        user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        type VARCHAR(60) NOT NULL DEFAULT 'info',
        message TEXT NOT NULL,
        context LONGTEXT NULL,
        ip VARCHAR(45) NULL,
        PRIMARY KEY  (id),
        KEY type (type),
        KEY created_at_utc (created_at_utc)
    ) {$charset_collate};";
    }

    /** Anota una acción. $context se guarda como JSON (sin secretos ni datos personales innecesarios). */
    public static function add(string $message, string $type = 'info', array $context = []): void
    {
        global $wpdb;

        $user_id = (int) get_current_user_id();
        $type = substr(sanitize_key($type) ?: 'info', 0, 60);

        if (self::ready()) {
            $wpdb->insert(self::table(), [
                'created_at_utc' => gmdate('Y-m-d H:i:s'),
                'user_id' => $user_id,
                'type' => $type,
                'message' => $message,
                'context' => $context ? wp_json_encode($context) : null,
                'ip' => self::ip(),
            ], ['%s', '%d', '%s', '%s', '%s', '%s']);
        } else {
            error_log('[wp-certificates] ' . $message);
        }

        // Copia en el log de EduSystem, donde el personal lo ve hoy (decisión 7 del ADR 0004)
        if (function_exists('edusystem_set_log')) {
            edusystem_set_log($message, $type, $user_id ?: null);
        }
    }

    /** ¿Existe ya la tabla? (antes de aplicar el esquema v3 no existe). Se consulta una vez por petición. */
    private static function ready(): bool
    {
        static $ready = null;
        if (null === $ready) {
            global $wpdb;
            $ready = self::table() === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', self::table()));
        }

        return $ready;
    }

    private static function ip(): ?string
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) filter_var(wp_unslash($_SERVER['REMOTE_ADDR']), FILTER_VALIDATE_IP) : '';

        return '' !== $ip ? $ip : null;
    }
}
