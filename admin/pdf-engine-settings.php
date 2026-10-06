<?php
declare(strict_types=1);

/**
 * Certificación › Configuración, sección «Motor de PDF» (ADR 0013 de Edusof).
 *
 * Solo el administrador de WordPress (manage_options) cambia el motor del sitio, la dirección y el identificador: decide
 * por dónde viajan los datos de los documentos. Para pasar a «servidor» hay que confirmar que existe el contrato de
 * encargado del tratamiento con la institución. Las claves van en wp-config.php y aquí solo se dice si están.
 * «Comprobar conexión» pide el estado y un PDF de prueba (sin datos personales) y comprueba la firma del servicio.
 * Cada cambio queda en el log.
 */

defined('ABSPATH') || exit;

const SQUUAD_CERT_PDF_SETTINGS_URL = 'admin.php?page=add_admin_form_configuration_options_certificates_content';

function squuad_cert_pdf_settings_back(): string
{
    return admin_url(SQUUAD_CERT_PDF_SETTINGS_URL) . '#squuad-cert-pdf-engine';
}

function squuad_cert_pdf_settings_notice(?array $notice = null): ?array
{
    $key = 'squuad_cert_pdf_settings_notice_' . get_current_user_id();
    if (null !== $notice) {
        set_transient($key, $notice, 5 * MINUTE_IN_SECONDS);
        return null;
    }
    $saved = get_transient($key);
    if (false !== $saved) {
        delete_transient($key);
    }

    return is_array($saved) ? $saved : null;
}

add_action('admin_post_squuad_cert_pdf_engine_save', 'squuad_cert_pdf_engine_settings_save');
function squuad_cert_pdf_engine_settings_save(): void
{
    if (!squuad_cert_pdf_engine_can_manage()) {
        wp_die(esc_html__('Sorry, you are not allowed to access this page.', 'wp-certificates'), 403);
    }
    check_admin_referer('squuad_cert_pdf_engine_save');

    $engine = 'servicio' === ($_POST['engine'] ?? '') ? 'servicio' : 'navegador';
    $url = defined('SQUUAD_CERT_PDF_SERVICE_URL') ? (string) get_option(SQUUAD_CERT_PDF_SERVICE_URL_OPTION, '')
        : esc_url_raw(trim((string) wp_unslash($_POST['service_url'] ?? '')), ['https', 'http']);
    // Identificador del sitio: minúsculas, números, punto, guion y guion bajo (como lo registra el servicio)
    $site = strtolower(preg_replace('/[^a-z0-9._-]/i', '', (string) wp_unslash($_POST['site_id'] ?? '')));
    if ('' !== $url && !squuad_cert_pdf_valid_url($url)) {
        squuad_cert_pdf_settings_notice(['ok' => false, 'message' => __('The address must start with https:// (or http://127.0.0.1 if the service is installed on this same server).', 'wp-certificates')]);
        wp_safe_redirect(squuad_cert_pdf_settings_back());
        exit;
    }
    $old = [squuad_cert_pdf_engine_site(), (string) get_option(SQUUAD_CERT_PDF_SERVICE_URL_OPTION, ''), (string) get_option(SQUUAD_CERT_PDF_SITE_ID_OPTION, '')];
    update_option(SQUUAD_CERT_PDF_SERVICE_URL_OPTION, untrailingslashit($url), false);
    // Respaldo en el navegador tras 5 fallos del servidor (ADR 0013, fase 2): apagado por defecto
    $fallback = !empty($_POST['allow_fallback']) ? '1' : '0';
    if ($fallback !== (string) get_option(SQUUAD_CERT_PDF_FALLBACK_OPTION, '0')) {
        update_option(SQUUAD_CERT_PDF_FALLBACK_OPTION, $fallback, false);
        squuad_cert_log(sprintf('Motor de PDF: respaldo en el navegador %s, por el usuario %d', '1' === $fallback ? 'permitido' : 'no permitido', get_current_user_id()), 'pdf_engine');
    }
    update_option(SQUUAD_CERT_PDF_SITE_ID_OPTION, $site, false);

    // Contrato de encargado: quién lo confirmó y cuándo; sin la casilla se retira (y ningún documento va al servicio)
    $contract_before = squuad_cert_pdf_contract_confirmed();
    if (!empty($_POST['processor_contract'])) {
        if (!$contract_before) {
            update_option(SQUUAD_CERT_PDF_CONTRACT_OPTION, ['user' => get_current_user_id(), 'at' => time()], false);
            squuad_cert_log(sprintf('Motor de PDF: el usuario %d confirma que existe el contrato de encargado del tratamiento', get_current_user_id()), 'pdf_engine');
        }
    } elseif ($contract_before) {
        delete_option(SQUUAD_CERT_PDF_CONTRACT_OPTION);
        squuad_cert_log(sprintf('Motor de PDF: el usuario %d retira la confirmación del contrato de encargado (ningún documento va al servidor)', get_current_user_id()), 'pdf_engine');
    }

    $message = __('Settings saved.', 'wp-certificates');
    $ok = true;
    if ('servicio' === $engine) {
        $health = squuad_cert_pdf_service_missing() ? null : squuad_cert_pdf_service_health();
        if (empty($_POST['processor_contract'])) {
            $engine = 'navegador';
            $ok = false;
            $message = __('The PDF server was not activated: confirm that the data processing agreement with the institution exists.', 'wp-certificates');
        } elseif (squuad_cert_pdf_service_missing()) {
            $engine = 'navegador';
            $ok = false;
            $message = __('The PDF server was not activated: complete the address, the site identifier and the keys in wp-config.php.', 'wp-certificates');
        } elseif (is_array($health) && array_key_exists('sandbox', $health) && empty($health['sandbox'])) {
            // ADR 0013: en producción, Chrome siempre con su aislamiento
            $engine = 'navegador';
            $ok = false;
            $message = __('The PDF server was not activated: the server runs Chrome without isolation (sandbox), which is only acceptable for tests with example data.', 'wp-certificates');
        }
    }
    update_option(SQUUAD_CERT_PDF_ENGINE_OPTION, $engine, false);
    $new = [$engine, untrailingslashit($url), $site];
    if ($old !== $new) {
        squuad_cert_log(sprintf('Motor de PDF: %s → %s, dirección «%s», sitio «%s», por el usuario %d', $old[0], $engine, $new[1], $site, get_current_user_id()), 'pdf_engine');
    }
    squuad_cert_pdf_settings_notice(['ok' => $ok, 'message' => $message]);
    wp_safe_redirect(squuad_cert_pdf_settings_back());
    exit;
}

add_action('admin_post_squuad_cert_pdf_queue_run', 'squuad_cert_pdf_engine_settings_queue_run');
function squuad_cert_pdf_engine_settings_queue_run(): void
{
    if (!squuad_cert_pdf_engine_can_manage()) {
        wp_die(esc_html__('Sorry, you are not allowed to access this page.', 'wp-certificates'), 403);
    }
    check_admin_referer('squuad_cert_pdf_queue_run');
    $added = squuad_cert_final_pdf_enqueue_pending();
    $stats = squuad_cert_final_pdf_queue_process(20, 'manual');
    squuad_cert_pdf_settings_notice(['ok' => 0 === $stats['fallidos'], 'message' => sprintf(
        /* translators: 1: generated, 2: failed, 3: still pending, 4: added to the queue */
        __('Final PDFs: %1$d generated, %2$d failed, %3$d still pending (%4$d added to the queue).', 'wp-certificates'),
        $stats['hechos'], $stats['fallidos'], $stats['pendientes'], $added
    )]);
    wp_safe_redirect(squuad_cert_pdf_settings_back());
    exit;
}

add_action('admin_post_squuad_cert_pdf_engine_test', 'squuad_cert_pdf_engine_settings_test');
function squuad_cert_pdf_engine_settings_test(): void
{
    if (!squuad_cert_pdf_engine_can_manage()) {
        wp_die(esc_html__('Sorry, you are not allowed to access this page.', 'wp-certificates'), 403);
    }
    check_admin_referer('squuad_cert_pdf_engine_test');

    $health = squuad_cert_pdf_service_health();
    if (is_wp_error($health)) {
        squuad_cert_pdf_settings_notice(['ok' => false, 'message' => sprintf(__('The PDF server does not answer: %s', 'wp-certificates'), $health->get_error_message())]);
    } elseif ($missing = squuad_cert_pdf_service_missing()) {
        squuad_cert_pdf_settings_notice(['ok' => false, 'message' => sprintf(__('The PDF server answers (Chrome %1$s), but this site is missing: %2$s.', 'wp-certificates'), (string) ($health['chrome'] ?? '?'), implode(', ', $missing))]);
    } else {
        // PDF de prueba sin datos personales, con un QR
        $html = '<div style="padding:24px;font-family:Arial,sans-serif"><h1>' . esc_html__('PDF server test', 'wp-certificates') . '</h1><p>'
            . esc_html(sprintf(__('Site: %s', 'wp-certificates'), home_url())) . '</p><div data-wpc-qr="' . esc_attr(home_url('/')) . '"></div></div>';
        $result = squuad_cert_pdf_render(squuad_cert_pdf_payload($html, squuad_cert_pdf_page(['unit' => 'mm', 'format' => 'a4', 'orientation' => 'portrait'], 10)), 'comprobación de la conexión');
        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            squuad_cert_pdf_settings_notice(['ok' => false, 'message' => $result->get_error_message() . (is_array($data) && isset($data['reason']) ? ' (' . $data['reason'] . ')' : '')]);
        } else {
            squuad_cert_pdf_settings_notice(['ok' => true, 'message' => sprintf(
                /* translators: 1: milliseconds, 2: Chrome version, 3: version of the printing rules, 4: yes/no sandbox */
                __('Connection correct: test PDF in %1$d ms, Chrome %2$s, printing rules %3$s, signature of the service valid. Chrome isolation (sandbox): %4$s.', 'wp-certificates'),
                (int) $result['ms'], $result['chrome'], $result['rules'], !empty($health['sandbox']) ? __('yes', 'wp-certificates') : __('no', 'wp-certificates')
            )]);
        }
    }
    wp_safe_redirect(squuad_cert_pdf_settings_back());
    exit;
}

/** Sección «Motor de PDF» de Certificación › Configuración. */
function squuad_cert_pdf_engine_settings_section(): void
{
    if (!current_user_can('manager_configuration_certificates') && !squuad_cert_pdf_engine_can_manage()) {
        return;
    }
    $can = squuad_cert_pdf_engine_can_manage();
    $notice = squuad_cert_pdf_settings_notice();
    $engine = squuad_cert_pdf_engine_site();
    $url_const = defined('SQUUAD_CERT_PDF_SERVICE_URL');
    $url = $url_const ? (string) SQUUAD_CERT_PDF_SERVICE_URL : (string) get_option(SQUUAD_CERT_PDF_SERVICE_URL_OPTION, '');
    $site = (string) get_option(SQUUAD_CERT_PDF_SITE_ID_OPTION, '');
    $missing = squuad_cert_pdf_service_missing();
    $consts = [
        'SQUUAD_CERT_PDF_SERVICE_KEY_ID' => !in_array('SQUUAD_CERT_PDF_SERVICE_KEY_ID', $missing, true),
        'SQUUAD_CERT_PDF_SERVICE_KEY' => !in_array('SQUUAD_CERT_PDF_SERVICE_KEY', $missing, true),
        'SQUUAD_CERT_PDF_SERVICE_PUBKEY' => !in_array('SQUUAD_CERT_PDF_SERVICE_PUBKEY', $missing, true),
    ];
    $scope = function_exists('squuad_cert_id_document_eds_scope') ? squuad_cert_id_document_eds_scope() : '';
    ?>
    <div class="<?= esc_attr($scope) ?>" style="margin-top: 24px">
    <section class="eds-card" id="squuad-cert-pdf-engine" aria-labelledby="squuad-cert-pdf-engine-title">
        <h2 id="squuad-cert-pdf-engine-title"><?= esc_html__('PDF engine', 'wp-certificates') ?></h2>
        <?php if ($notice) : ?>
            <div class="eds-notice eds-notice--<?= $notice['ok'] ? 'ok' : 'bad' ?>" role="status"><p><?= esc_html($notice['message']) ?></p></div>
        <?php endif; ?>
        <p style="margin: 0 0 12px; color: var(--eds-muted, #50575e)"><?= esc_html__('Who makes the PDF of the documents. The browser of each person (as before: the result may change with the browser, the zoom or the window) or the PDF server of Squuad (Chrome on the server: always the same result, the same fonts and real text). If the server does not answer, the preview uses the browser and says so. Each document can follow the site or choose its own engine.', 'wp-certificates') ?></p>
        <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
            <input type="hidden" name="action" value="squuad_cert_pdf_engine_save">
            <?php wp_nonce_field('squuad_cert_pdf_engine_save'); ?>
            <fieldset style="margin: 0 0 16px" <?php disabled(!$can); ?>>
                <legend><strong><?= esc_html__('Engine of the site', 'wp-certificates') ?></strong></legend>
                <label style="display: block; margin: 6px 0"><input type="radio" name="engine" value="navegador" <?php checked('navegador', $engine); ?>> <?= esc_html__('Browser of each person (default)', 'wp-certificates') ?></label>
                <label style="display: block; margin: 6px 0"><input type="radio" name="engine" value="servicio" <?php checked('servicio', $engine); ?>> <?= esc_html__('PDF server', 'wp-certificates') ?></label>
                <label style="display: block; margin: 10px 0 0"><input type="checkbox" name="processor_contract" value="1" <?php checked(squuad_cert_pdf_contract_confirmed()); ?>> <?= esc_html__('I confirm that the data processing agreement with the institution exists: with the PDF server, the content of the documents (also of minors) travels encrypted to the server of Squuad, which does not keep it.', 'wp-certificates') ?></label>
            </fieldset>
            <div class="eds-field">
                <label for="squuad-cert-pdf-url"><strong><?= esc_html__('Address of the PDF server', 'wp-certificates') ?></strong></label>
                <input type="url" id="squuad-cert-pdf-url" name="service_url" value="<?= esc_attr($url) ?>" placeholder="https://certpdf.squuad.com" <?php disabled(!$can || $url_const); ?>>
                <?php if ($url_const) : ?><p style="margin: 4px 0 0; color: var(--eds-muted, #50575e)"><?= esc_html__('Fixed in wp-config.php (SQUUAD_CERT_PDF_SERVICE_URL).', 'wp-certificates') ?></p><?php endif; ?>
            </div>
            <div class="eds-field" style="margin-top: 12px">
                <label for="squuad-cert-pdf-site"><strong><?= esc_html__('Site identifier', 'wp-certificates') ?></strong></label>
                <input type="text" id="squuad-cert-pdf-site" name="site_id" value="<?= esc_attr($site) ?>" placeholder="mi-colegio" pattern="[a-z0-9][a-z0-9._\-]{1,63}" <?php disabled(!$can); ?>>
                <p style="margin: 4px 0 0; color: var(--eds-muted, #50575e)"><?= esc_html__('The name under which the PDF server registered this site (lowercase letters, numbers, dot and hyphens).', 'wp-certificates') ?></p>
            </div>
            <p style="margin: 16px 0 4px"><strong><?= esc_html__('Keys in wp-config.php', 'wp-certificates') ?></strong></p>
            <ul style="margin: 0 0 12px">
                <?php foreach ($consts as $name => $ok) : ?>
                    <li><code><?= esc_html($name) ?></code> — <?= $ok ? esc_html__('present', 'wp-certificates') : '<strong>' . esc_html__('missing', 'wp-certificates') . '</strong>' ?></li>
                <?php endforeach; ?>
                <?php if (in_array('sodium', $missing, true)) : ?>
                    <li><strong><?= esc_html__('This server does not have the sodium extension of PHP: the signature of the PDF server cannot be checked.', 'wp-certificates') ?></strong></li>
                <?php endif; ?>
            </ul>
            <label style="display: block; margin: 0 0 12px"><input type="checkbox" name="allow_fallback" value="1" <?php checked('1' === get_option(SQUUAD_CERT_PDF_FALLBACK_OPTION, '0')); ?> <?php disabled(!$can); ?>> <?= esc_html(sprintf(__('Allow the browser as a backup: if the PDF server fails %d times with the same document, the person who signed last can generate its final PDF once (marked as a backup in the evidence).', 'wp-certificates'), SQUUAD_CERT_PDF_MAX_ATTEMPTS)) ?></label>
            <?php if ($can) : ?>
                <p><button type="submit" class="eds-btn eds-btn--primary"><?= esc_html__('Save', 'wp-certificates') ?></button></p>
            <?php else : ?>
                <p style="color: var(--eds-muted, #50575e)"><?= esc_html__('Only the WordPress administrator can change the PDF engine.', 'wp-certificates') ?></p>
            <?php endif; ?>
        </form>
        <?php
        global $wpdb;
        $queue = function_exists('squuad_cert_final_pdf_queue_count') ? squuad_cert_final_pdf_queue_count() : 0;
        $failing = $wpdb->get_results("SELECT id, pdf_attempts, pdf_next_at_utc, pdf_last_error FROM {$wpdb->prefix}squuad_cert_requests WHERE status = 'signed' AND (final_attachment_id IS NULL OR final_attachment_id = 0) AND pdf_attempts > 0 ORDER BY pdf_attempts DESC LIMIT 10");
        $browser_waiting = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}squuad_cert_requests WHERE status = 'signed' AND (final_attachment_id IS NULL OR final_attachment_id = 0) AND pdf_next_at_utc IS NULL");
        ?>
        <h3 style="margin: 20px 0 6px"><?= esc_html__('Final PDFs', 'wp-certificates') ?></h3>
        <p style="margin: 0 0 6px"><?= esc_html(sprintf(__('In the queue of the PDF server: %1$d. Signed documents waiting for the browser of the person who signed last: %2$d.', 'wp-certificates'), $queue, $browser_waiting)) ?></p>
        <?php if ($failing) : ?>
            <ul style="margin: 0 0 8px">
                <?php foreach ($failing as $row) : ?>
                    <li><?= esc_html(sprintf(__('Request #%1$d: %2$d failed attempts; next one %3$s UTC (%4$s)', 'wp-certificates'), (int) $row->id, (int) $row->pdf_attempts, (string) $row->pdf_next_at_utc, (string) $row->pdf_last_error)) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if ($can) : ?>
            <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
                <input type="hidden" name="action" value="squuad_cert_pdf_queue_run">
                <?php wp_nonce_field('squuad_cert_pdf_queue_run'); ?>
                <p><button type="submit" class="eds-btn"><?= esc_html__('Process now', 'wp-certificates') ?></button>
                    <span style="color: var(--eds-muted, #50575e)"><?= esc_html__('Generates now the pending final PDFs of the documents that use the PDF server (the site also does it every 5 minutes).', 'wp-certificates') ?></span></p>
            </form>
            <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
                <input type="hidden" name="action" value="squuad_cert_pdf_engine_test">
                <?php wp_nonce_field('squuad_cert_pdf_engine_test'); ?>
                <p><button type="submit" class="eds-btn"><?= esc_html__('Check connection', 'wp-certificates') ?></button>
                    <span style="color: var(--eds-muted, #50575e)"><?= esc_html__('Asks the server for its status and a test PDF without personal data, and checks its signature.', 'wp-certificates') ?></span></p>
            </form>
        <?php endif; ?>
    </section>
    </div>
    <?php
}
