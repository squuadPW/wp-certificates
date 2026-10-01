<?php
declare(strict_types=1);

namespace Squuad\Certificados;

defined('ABSPATH') || exit;

/**
 * Pareja de versiones con EduSystem (ADR 0004, sección 8). wp-certificates declara en su cabecera la versión mínima
 * de EduSystem que necesita ("Requires EduSystem"). Si EduSystem está activo con una versión menor, la certificación
 * queda en pausa y los administradores ven un aviso. Sin EduSystem no hay nada que comprobar.
 *
 * Mientras se desarrolla (wp-certificates < 2.0.0, EduSystem 5.x) la comprobación no se aplica: se activa con la
 * versión 2.0.0 de wp-certificates. El filtro squuad_cert_pair_enforced permite forzarla en pruebas.
 */
final class Pair
{
    public const ENFORCED_FROM = '2.0.0';

    /** @var array{ok: bool, reason: string, edusystem: string, required: string}|null */
    private static ?array $result = null;

    /** @return array{ok: bool, reason: string, edusystem: string, required: string} */
    public static function check(): array
    {
        if (null !== self::$result) {
            return self::$result;
        }

        $required = defined('WP_C_REQUIRES_EDUSYSTEM') ? (string) WP_C_REQUIRES_EDUSYSTEM : '';
        $edusystem = defined('EDUSYSTEM_VERSION') ? (string) EDUSYSTEM_VERSION : '';
        $enforced = (bool) apply_filters('squuad_cert_pair_enforced', version_compare(WP_C_VERSION, self::ENFORCED_FROM, '>='));

        $ok = true;
        $reason = '';
        if ($enforced && '' !== $edusystem && '' !== $required && version_compare($edusystem, $required, '<')) {
            $ok = false;
            $reason = 'edusystem_outdated';
        }

        return self::$result = ['ok' => $ok, 'reason' => $reason, 'edusystem' => $edusystem, 'required' => $required];
    }

    public static function paused(): bool
    {
        return !self::check()['ok'];
    }

    /** Vuelve a calcular (solo para pruebas, tras cambiar el filtro). */
    public static function reset(): void
    {
        self::$result = null;
    }

    /** Aviso crítico para administradores (all_admin_notices: EduSystem oculta admin_notices a no superadministradores). */
    public static function notice(): void
    {
        if (!self::paused() || !current_user_can('manage_options')) {
            return;
        }
        $check = self::check();
        printf(
            '<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
            esc_html__('Certification is paused.', 'wp-certificates'),
            esc_html(sprintf(
                /* translators: 1: installed EduSystem version, 2: required EduSystem version */
                __('WP Certificates needs EduSystem %2$s or later and this site has EduSystem %1$s. Update EduSystem: until then, documents and signatures are paused (nothing is lost).', 'wp-certificates'),
                $check['edusystem'],
                $check['required']
            ))
        );
    }
}
