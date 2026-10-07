<?php
declare(strict_types=1);

/**
 * Certificación - Hoja del certificado de firmas opcional y ajustes de la verificación pública (ADR 0014 de Edusof,
 * fase 4).
 *
 * - Hoja: opción del sitio (Configuración › Firma de documentos, activada por defecto) y ajuste por documento
 *   («según el sitio», «sí», «no»). Se fija en la solicitud al congelarla (una solicitud en curso no cambia de forma) y
 *   se sella en status_completed el valor con el que se hizo el PDF. Vacía en la solicitud = con hoja (todo lo anterior).
 * - «Nombre en la verificación» por documento: lo que muestra la página pública en lugar del título.
 * - «Retirar la verificación» de una solicitud: la página responde como a un enlace no válido.
 *
 * Sin esquema 19 todo se comporta como antes (con hoja, sin nombre público, sin retirar).
 */

if (!defined('ABSPATH')) exit;

const SQUUAD_CERT_SIGNATURE_SHEET_OPTION = 'squuad_cert_signature_sheet';
const SQUUAD_CERT_VERIFY_PUBLIC_NAME_MAX = 190;

/**
 * ¿Existen las columnas del esquema 19? Versión y columnas de verdad (dbDelta puede fallar y la versión subir igual:
 * sin ellas, guardar el documento fallaría entero).
 */
function squuad_cert_signature_sheet_ready(): bool
{
    global $wpdb;
    static $ready = null;

    if (null === $ready) {
        $ready = version_compare((string) get_option('wp_c_db_version'), '19', '>=')
            && (bool) $wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}documents_certificates LIKE 'verify_public_name'")
            && (bool) $wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}squuad_cert_requests LIKE 'verify_public_name'")
            && (bool) $wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}squuad_cert_requests LIKE 'verify_withdrawn_at_utc'");
    }

    return $ready;
}

/** Opción del sitio: 'yes' (por defecto) o 'no'. */
function squuad_cert_signature_sheet_site(): string
{
    return 'no' === get_option(SQUUAD_CERT_SIGNATURE_SHEET_OPTION, 'yes') ? 'no' : 'yes';
}

/** Ajuste guardado en el documento: 'site', 'yes' o 'no'. */
function squuad_cert_signature_sheet_document_setting(?object $document): string
{
    $value = $document && isset($document->signature_sheet) ? (string) $document->signature_sheet : 'site';

    return in_array($value, ['yes', 'no'], true) ? $value : 'site';
}

/** ¿Lleva hoja un documento? 'yes' o 'no' (su ajuste o, si es «según el sitio», el del sitio). */
function squuad_cert_signature_sheet_for_document(?object $document): string
{
    if (!squuad_cert_signature_sheet_ready()) {
        return 'yes';
    }
    $setting = squuad_cert_signature_sheet_document_setting($document);

    return 'site' === $setting ? squuad_cert_signature_sheet_site() : $setting;
}

/** Documento de una solicitud (o null). */
function squuad_cert_signature_sheet_request_document(object $request): ?object
{
    global $wpdb;

    $document = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}documents_certificates WHERE id = %d",
        (int) ($request->document_certificate_id ?? 0)
    ));

    return $document ?: null;
}

/**
 * ¿Lleva hoja el PDF final de una solicitud? La fijada al congelarla; si no la tiene: sin congelar, la del documento;
 * congelada antes del esquema 19, 'yes' (todo lo anterior llevaba la hoja).
 */
function squuad_cert_signature_sheet_for_request(object $request): string
{
    $fixed = (string) ($request->signature_sheet ?? '');
    if (in_array($fixed, ['yes', 'no'], true)) {
        return $fixed;
    }
    if (empty($request->frozen_at_utc) && squuad_cert_signature_sheet_ready()) {
        return squuad_cert_signature_sheet_for_document(squuad_cert_signature_sheet_request_document($request));
    }

    return 'yes';
}

/**
 * Valores que se fijan en la solicitud al congelarla (y se sellan en su evento «frozen»): hoja del certificado de firmas
 * y «Nombre en la verificación» del documento en ese momento. Vacío sin el esquema 19.
 *
 * @return array{signature_sheet?: string, verify_public_name?: string}
 */
function squuad_cert_signature_sheet_freeze_values(object $request): array
{
    if (!squuad_cert_signature_sheet_ready()) {
        return [];
    }
    $document = squuad_cert_signature_sheet_request_document($request);

    return [
        'signature_sheet' => squuad_cert_signature_sheet_for_document($document),
        'verify_public_name' => $document ? trim((string) ($document->verify_public_name ?? '')) : '',
    ];
}

/** ¿Queda el documento sin forma de verificarse impreso? (sin hoja y sin {{qrcode}} en ninguna parte) */
function squuad_cert_signature_sheet_without_qr(?object $document): bool
{
    if (!$document || 'no' !== squuad_cert_signature_sheet_for_document($document)) {
        return false;
    }

    return false === strpos((string) $document->header . (string) $document->content . (string) $document->footer, '{{qrcode}}');
}

/** Aviso para quien edita o emite un documento sin hoja ni QR. */
function squuad_cert_signature_sheet_without_qr_message(): string
{
    return __('This document is set without the certificate of signatures and its template has no {{qrcode}}: a printed copy could not be verified. Add {{qrcode}} to the template or turn the certificate of signatures on.', 'wp-certificates');
}

/** ¿Se retiró la verificación pública de la solicitud? */
function squuad_cert_verify_withdrawn(object $request): bool
{
    return !empty($request->verify_withdrawn_at_utc);
}

/** Quién puede retirar o restablecer la verificación pública (decisión del dueño, 2026-10-07): configuración. */
function squuad_cert_verify_can_withdraw(): bool
{
    return current_user_can('manager_configuration_certificates');
}

/**
 * Retira (o restablece) la verificación pública de una solicitud congelada, con motivo obligatorio: queda sellado en la
 * evidencia de la solicitud (eventos verify_withdrawn / verify_restored, con quién, cuándo y por qué) y en el log.
 * $origin: 'admin' o 'wp-cli'.
 */
function squuad_cert_verify_set_withdrawn(int $request_id, bool $withdrawn, string $reason, string $origin = 'admin'): bool
{
    global $wpdb;

    $reason = mb_substr(trim($reason), 0, 500);
    $request = squuad_cert_signature_sheet_ready() ? squuad_cert_signature_request_get($request_id) : null;
    if (!$request || null === $request->frozen_at_utc || '' === $reason || squuad_cert_verify_withdrawn($request) === $withdrawn) {
        return false;
    }
    $user_id = get_current_user_id();
    $updated = $wpdb->query($withdrawn
        ? $wpdb->prepare("UPDATE {$wpdb->prefix}squuad_cert_requests SET verify_withdrawn_at_utc = UTC_TIMESTAMP(), verify_withdrawn_by = %d WHERE id = %d AND verify_withdrawn_at_utc IS NULL", $user_id, $request_id)
        : $wpdb->prepare("UPDATE {$wpdb->prefix}squuad_cert_requests SET verify_withdrawn_at_utc = NULL, verify_withdrawn_by = NULL WHERE id = %d AND verify_withdrawn_at_utc IS NOT NULL", $request_id));
    if (!$updated) {
        return false;
    }
    squuad_cert_signature_request_log_event($request_id, $withdrawn ? 'verify_withdrawn' : 'verify_restored', ['reason' => $reason, 'origin' => $origin], $user_id);
    squuad_cert_log(sprintf('Verificación pública de la solicitud %d %s por el usuario %d (%s): %s', $request_id, $withdrawn ? 'retirada' : 'restablecida', $user_id, $origin, $reason), 'verify_page');

    return true;
}

/** Quién puede cambiar la hoja y el nombre público de un documento: el permiso de la configuración de certificación. */
function squuad_cert_signature_sheet_can_manage(): bool
{
    return current_user_can('manager_configuration_certificates');
}

/**
 * Campos de la ficha del documento: «Certificado de firmas en el PDF» y «Nombre en la verificación», con el aviso si
 * el documento queda sin forma de verificarse. $classic: marcado de la ficha antigua.
 */
function squuad_cert_signature_sheet_document_fields(?object $document, bool $classic = false): void
{
    if (!squuad_cert_signature_sheet_ready()) {
        return;
    }
    $setting = squuad_cert_signature_sheet_document_setting($document);
    $site = 'yes' === squuad_cert_signature_sheet_site() ? __('attached', 'wp-certificates') : __('not attached', 'wp-certificates');
    $disabled = !squuad_cert_signature_sheet_can_manage();
    $name = $document ? (string) ($document->verify_public_name ?? '') : '';
    $wrap = $classic ? 'style="font-weight:400; text-align: center" class="space-offer"' : 'class="wpc-eds-field"';
    $help = $classic ? 'class="description"' : 'class="wpc-eds-help"';
    ?>
    <div <?= $wrap // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- atributos fijos ?>>
        <label for="signature_sheet"><?= $classic ? '<b>' : '' ?><?= esc_html__('Certificate of signatures in the PDF', 'wp-certificates') ?><?= $classic ? '</b>' : '' ?></label><?= $classic ? '<br>' : '' ?>
        <select name="signature_sheet" id="signature_sheet" aria-describedby="signature_sheet-help" <?php disabled($disabled); ?>>
            <option value="site" <?php selected($setting, 'site'); ?>><?= esc_html(sprintf(__('As the site (now: %s)', 'wp-certificates'), $site)) ?></option>
            <option value="yes" <?php selected($setting, 'yes'); ?>><?= esc_html__('Attach it', 'wp-certificates') ?></option>
            <option value="no" <?php selected($setting, 'no'); ?>><?= esc_html__('Do not attach it', 'wp-certificates') ?></option>
        </select>
        <p <?= $help // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- atributos fijos ?> id="signature_sheet-help"><?= esc_html__('Extra A4 page at the end of the signed PDF with who signed, when and how. Without it, the document is checked with its QR code on the verification page, and the certificate can be downloaded separately. Documents already signed do not change.', 'wp-certificates') ?></p>
        <?php if (squuad_cert_signature_sheet_without_qr($document)) : ?>
            <p <?= $help // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- atributos fijos ?> style="color:#b32d2e" role="alert"><?= esc_html(squuad_cert_signature_sheet_without_qr_message()) ?></p>
        <?php endif; ?>
    </div>
    <div <?= $wrap // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- atributos fijos ?>>
        <label for="verify_public_name"><?= $classic ? '<b>' : '' ?><?= esc_html__('Name on the verification page', 'wp-certificates') ?><?= $classic ? '</b>' : '' ?></label><?= $classic ? '<br>' : '' ?>
        <input type="text" name="verify_public_name" id="verify_public_name" maxlength="<?= (int) SQUUAD_CERT_VERIFY_PUBLIC_NAME_MAX ?>" value="<?= esc_attr($name) ?>" aria-describedby="verify_public_name-help" <?php disabled($disabled); ?>>
        <p <?= $help // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- atributos fijos ?> id="verify_public_name-help"><?= esc_html__('What anyone who scans the QR code sees instead of the title, for titles that reveal something about the person (for example "Certificate of inclusion" → "Certificate"). Empty: the title of the document. It is fixed when each document is issued: changing it only affects new issues.', 'wp-certificates') ?></p>
    </div>
    <?php
}

/**
 * Datos de la hoja y el nombre público al guardar un documento (admin/documents.php). Solo con permiso y si el
 * formulario los trae; si no, se conserva lo guardado. Los cambios van al log.
 */
function squuad_cert_signature_sheet_document_data(array $document_data, int $document_id): array
{
    if (!squuad_cert_signature_sheet_ready() || !squuad_cert_signature_sheet_can_manage()) {
        return $document_data;
    }
    $previous = $document_id > 0 && function_exists('get_document_detail') ? get_document_detail($document_id) : null;
    if (isset($_POST['signature_sheet']) && in_array($_POST['signature_sheet'], ['site', 'yes', 'no'], true)) {
        $document_data['signature_sheet'] = sanitize_key($_POST['signature_sheet']);
        $before = squuad_cert_signature_sheet_document_setting($previous);
        if ($document_id > 0 && $before !== $document_data['signature_sheet']) {
            squuad_cert_log(sprintf('Certificado de firmas del documento %d: %s → %s, por el usuario %d', $document_id, $before, $document_data['signature_sheet'], get_current_user_id()), 'signature_sheet');
        }
    }
    if (isset($_POST['verify_public_name']) && is_string($_POST['verify_public_name'])) {
        $name = mb_substr(trim(sanitize_text_field(wp_unslash($_POST['verify_public_name']))), 0, SQUUAD_CERT_VERIFY_PUBLIC_NAME_MAX);
        $document_data['verify_public_name'] = '' !== $name ? $name : null;
        $old_name = (string) ($previous->verify_public_name ?? '');
        if ($document_id > 0 && $old_name !== $name) {
            squuad_cert_log(sprintf('Nombre en la verificación del documento %d: «%s» → «%s», por el usuario %d (solo emisiones nuevas)', $document_id, $old_name, $name, get_current_user_id()), 'signature_sheet');
        }
    }

    return $document_data;
}

if (defined('WP_CLI') && WP_CLI) {
    /**
     * wp squuad-cert verificacion retirar --id=<n> --motivo="…"      · la página responde como a un enlace no válido.
     * wp squuad-cert verificacion restablecer --id=<n> --motivo="…"  · vuelve a mostrar la verificación.
     * Para cualquier solicitud (los documentos emitidos lo tienen también como botón en la ficha del estudiante).
     */
    foreach (['retirar' => true, 'restablecer' => false] as $command => $withdraw) {
        WP_CLI::add_command('squuad-cert verificacion ' . $command, static function (array $args, array $assoc) use ($withdraw): void {
            $id = (int) ($assoc['id'] ?? 0);
            $reason = trim((string) ($assoc['motivo'] ?? ''));
            if ($id <= 0 || '' === $reason) {
                WP_CLI::error('Indique la solicitud con --id=<n> y el motivo con --motivo="…".');
            }
            if (!squuad_cert_verify_set_withdrawn($id, $withdraw, $reason, 'wp-cli')) {
                WP_CLI::error('No se cambió: la solicitud no existe, no está congelada, ya estaba así o falta el esquema 19.');
            }
            WP_CLI::success(sprintf('Verificación pública de la solicitud %d %s.', $id, $withdraw ? 'retirada' : 'restablecida'));
        });
    }
}
