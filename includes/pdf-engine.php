<?php
declare(strict_types=1);

/**
 * Motor de PDF (ADR 0013 de Edusof): el PDF se pide al servicio de PDF (Chrome en el servidor) o se genera en el
 * navegador del usuario, como hasta ahora (html2pdf.js).
 *
 * Doble función:
 * - Motor del sitio (Certificación › Configuración, solo `manage_options`): «navegador» (por defecto: nada cambia al
 *   actualizar) o «servicio». El modo «local» es el mismo servicio instalado en el servidor del sitio y escuchando en
 *   127.0.0.1 (dirección http://127.0.0.1:…): WordPress nunca arranca Chrome.
 * - Ajuste por documento (columna documents_certificates.pdf_engine): «site» (según el sitio), «servicio» o «navegador».
 * - Respaldo: si el servicio falla, quien llama decide (la vista previa vuelve al navegador con aviso).
 *
 * Secretos solo en wp-config.php: SQUUAD_CERT_PDF_SERVICE_KEY (base64, 32 bytes), SQUUAD_CERT_PDF_SERVICE_KEY_ID y
 * SQUUAD_CERT_PDF_SERVICE_PUBKEY (clave pública Ed25519 del servicio, base64). La dirección puede fijarse con
 * SQUUAD_CERT_PDF_SERVICE_URL (manda sobre la opción).
 */

defined('ABSPATH') || exit;

const SQUUAD_CERT_PDF_ENGINE_OPTION = 'squuad_cert_pdf_engine';
const SQUUAD_CERT_PDF_SERVICE_URL_OPTION = 'squuad_cert_pdf_service_url';
const SQUUAD_CERT_PDF_SITE_ID_OPTION = 'squuad_cert_pdf_site_id';
/** Versión de las reglas de impresión del servicio (Antigravity/servicios/pdf/reglas.mjs). */
const SQUUAD_CERT_PDF_RULES = '1';
/** Tope del cuerpo que acepta el servicio. */
const SQUUAD_CERT_PDF_MAX_BODY = 10 * 1024 * 1024;
/** Tope del PDF que se acepta de vuelta. */
const SQUUAD_CERT_PDF_MAX_PDF = 30 * 1024 * 1024;
/** Tope por imagen incrustada (fondos de certificados en alta resolución). */
const SQUUAD_CERT_PDF_MAX_IMAGE = 4 * 1024 * 1024;

/** Dirección del servicio: constante o opción; https, o http solo hacia la propia máquina (modo local). */
function squuad_cert_pdf_service_url(): string
{
    $url = defined('SQUUAD_CERT_PDF_SERVICE_URL') ? (string) SQUUAD_CERT_PDF_SERVICE_URL : (string) get_option(SQUUAD_CERT_PDF_SERVICE_URL_OPTION, '');

    return squuad_cert_pdf_valid_url($url) ? untrailingslashit($url) : '';
}

function squuad_cert_pdf_valid_url(string $url): bool
{
    $p = wp_parse_url($url);
    if (!$p || empty($p['host']) || !empty($p['user']) || !empty($p['query']) || !empty($p['fragment'])
        || !in_array($p['path'] ?? '', ['', '/'], true)) {
        return false;
    }
    if ('https' === ($p['scheme'] ?? '')) {
        return true;
    }

    return 'http' === ($p['scheme'] ?? '') && in_array($p['host'], ['127.0.0.1', 'localhost', '[::1]'], true);
}

function squuad_cert_pdf_is_local_url(string $url): bool
{
    $host = (string) (wp_parse_url($url, PHP_URL_HOST) ?: '');

    return in_array($host, ['127.0.0.1', 'localhost', '[::1]'], true);
}

/** ¿Está todo para usar el servicio? Devuelve lo que falta (vacío = listo). */
function squuad_cert_pdf_service_missing(): array
{
    $missing = [];
    if ('' === squuad_cert_pdf_service_url()) $missing[] = 'url';
    if (!preg_match('/^[a-z0-9][a-z0-9._-]{1,63}$/', (string) get_option(SQUUAD_CERT_PDF_SITE_ID_OPTION, ''))) $missing[] = 'site_id';
    if (!defined('SQUUAD_CERT_PDF_SERVICE_KEY') || 32 !== strlen((string) base64_decode((string) SQUUAD_CERT_PDF_SERVICE_KEY, true))) $missing[] = 'SQUUAD_CERT_PDF_SERVICE_KEY';
    if (!defined('SQUUAD_CERT_PDF_SERVICE_KEY_ID') || !preg_match('/^[A-Za-z0-9_-]{1,32}$/', (string) SQUUAD_CERT_PDF_SERVICE_KEY_ID)) $missing[] = 'SQUUAD_CERT_PDF_SERVICE_KEY_ID';
    if (!defined('SQUUAD_CERT_PDF_SERVICE_PUBKEY') || 32 !== strlen((string) base64_decode((string) SQUUAD_CERT_PDF_SERVICE_PUBKEY, true))) $missing[] = 'SQUUAD_CERT_PDF_SERVICE_PUBKEY';
    if (!function_exists('sodium_crypto_sign_verify_detached')) $missing[] = 'sodium';

    return $missing;
}

const SQUUAD_CERT_PDF_CONTRACT_OPTION = 'squuad_cert_pdf_processor_contract';

/**
 * ¿Confirmó el administrador que existe el contrato de encargado del tratamiento con la institución? Sin él no se manda
 * ningún documento al servicio, ni por el sitio ni por un documento en «Siempre servidor» (B2 de la revisión).
 */
function squuad_cert_pdf_contract_confirmed(): bool
{
    $c = get_option(SQUUAD_CERT_PDF_CONTRACT_OPTION, []);

    return is_array($c) && !empty($c['user']) && !empty($c['at']);
}

/**
 * Claves públicas del servicio (base64) por huella SHA-256: la actual (SQUUAD_CERT_PDF_SERVICE_PUBKEY) y las anteriores
 * (SQUUAD_CERT_PDF_SERVICE_PUBKEYS, lista), para seguir verificando los PDF ya sellados tras rotar la clave del servicio.
 */
function squuad_cert_pdf_service_pubkeys(): array
{
    $keys = [];
    $list = defined('SQUUAD_CERT_PDF_SERVICE_PUBKEYS') && is_array(SQUUAD_CERT_PDF_SERVICE_PUBKEYS) ? SQUUAD_CERT_PDF_SERVICE_PUBKEYS : [];
    if (defined('SQUUAD_CERT_PDF_SERVICE_PUBKEY')) {
        $list[] = SQUUAD_CERT_PDF_SERVICE_PUBKEY;
    }
    foreach ($list as $b64) {
        $raw = base64_decode((string) $b64, true);
        if (false !== $raw && 32 === strlen($raw)) {
            $keys[hash('sha256', $raw)] = $raw;
        }
    }

    return $keys;
}

/** Motor del sitio: 'navegador' (por defecto) o 'servicio'. */
function squuad_cert_pdf_engine_site(): string
{
    return 'servicio' === get_option(SQUUAD_CERT_PDF_ENGINE_OPTION, 'navegador') ? 'servicio' : 'navegador';
}

/** ¿Existe ya la columna del ajuste por documento (esquema 17)? */
function squuad_cert_pdf_engine_column_ready(): bool
{
    static $ready = null;
    if (null === $ready) {
        global $wpdb;
        $ready = version_compare((string) get_option('wp_c_db_version'), '17', '>=')
            && (bool) $wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}documents_certificates LIKE 'pdf_engine'");
    }

    return $ready;
}

/**
 * ¿Puede el usuario cambiar el motor (del sitio o de un documento)? Solo el administrador de WordPress: decide por
 * dónde viajan los datos del documento (ADR 0013, punto 1).
 */
function squuad_cert_pdf_engine_can_manage(): bool
{
    return current_user_can('manage_options');
}

/** Ajuste del documento: 'site' (según el sitio), 'servicio' o 'navegador'. */
function squuad_cert_pdf_engine_document_setting(?object $document): string
{
    $value = $document && isset($document->pdf_engine) ? (string) $document->pdf_engine : 'site';

    return in_array($value, ['servicio', 'navegador'], true) ? $value : 'site';
}

/**
 * Motor efectivo para un documento: 'servicio' o 'navegador'. «Siempre servidor» o el sitio en servicio solo valen si
 * el servicio está configurado; si no, navegador (como hoy).
 */
function squuad_cert_pdf_engine_for_document(?object $document): string
{
    $setting = squuad_cert_pdf_engine_document_setting($document);
    $wanted = 'site' === $setting ? squuad_cert_pdf_engine_site() : $setting;

    return 'servicio' === $wanted && squuad_cert_pdf_contract_confirmed() && !squuad_cert_pdf_service_missing() ? 'servicio' : 'navegador';
}

/* ------------------------------------------------------------------------------------------------ petición firmada */

/**
 * Llama al servicio con la autenticación del sitio (HMAC de método, ruta, sitio, clave, hora, nonce y huella del
 * cuerpo). Devuelve la respuesta de wp_remote_request o WP_Error.
 */
function squuad_cert_pdf_service_request(string $method, string $path, string $body = '', array $extra_headers = [], int $timeout = 45)
{
    $url = squuad_cert_pdf_service_url();
    $site = (string) get_option(SQUUAD_CERT_PDF_SITE_ID_OPTION, '');
    $headers = ['Accept' => 'application/pdf, application/json'];
    if ('POST' === $method) {
        $ts = (string) time();
        $nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
        $canonical = implode("\n", ['v1', 'POST', $path, $site, (string) SQUUAD_CERT_PDF_SERVICE_KEY_ID, $ts, $nonce, hash('sha256', $body)]);
        $headers += [
            'Content-Type' => 'application/json',
            'X-Squuad-Cert-Site' => $site,
            'X-Squuad-Cert-Key-Id' => (string) SQUUAD_CERT_PDF_SERVICE_KEY_ID,
            'X-Squuad-Cert-Timestamp' => $ts,
            'X-Squuad-Cert-Nonce' => $nonce,
            'X-Squuad-Cert-Auth' => hash_hmac('sha256', $canonical, (string) base64_decode((string) SQUUAD_CERT_PDF_SERVICE_KEY, true)),
        ];
    }
    $response = wp_remote_request($url . $path, [
        'method' => $method,
        'body' => 'POST' === $method ? $body : null,
        'headers' => $headers + $extra_headers,
        'timeout' => 'POST' === $method ? max(5, $timeout) : 8,
        'redirection' => 0,
        'sslverify' => true,
        // Modo local: la propia máquina; en remoto, nunca direcciones internas
        'reject_unsafe_urls' => !squuad_cert_pdf_is_local_url($url),
        'limit_response_size' => SQUUAD_CERT_PDF_MAX_PDF + 1,
    ]);
    if (!is_wp_error($response) && 'POST' === $method) {
        $response['_sq_nonce'] = $nonce ?? '';
        $response['_sq_site'] = $site;
    }

    return $response;
}

/** Estado del servicio (GET /v1/health, sin datos): ['ok', 'chrome', 'rules', 'service', 'sandbox'] o WP_Error. */
function squuad_cert_pdf_service_health()
{
    if ('' === squuad_cert_pdf_service_url()) {
        return new WP_Error('pdf_sin_url', __('The address of the PDF service is not configured.', 'wp-certificates'));
    }
    $r = squuad_cert_pdf_service_request('GET', '/v1/health');
    if (is_wp_error($r)) {
        return $r;
    }
    $data = json_decode((string) wp_remote_retrieve_body($r), true);
    if (200 !== (int) wp_remote_retrieve_response_code($r) || !is_array($data)) {
        return new WP_Error('pdf_salud', sprintf(__('The PDF service answered with code %d.', 'wp-certificates'), (int) wp_remote_retrieve_response_code($r)));
    }

    return $data;
}

/**
 * Pide un PDF al servicio y comprueba la respuesta: código 200, PDF válido, tamaño y firma Ed25519 del servicio sobre
 * (sitio, nonce, huella de la entrada, huella del PDF, reglas, Chrome, hora). Registra cada fallo en el log.
 *
 * @return array{pdf: string, pages: int, chrome: string, rules: string, signed_at: int, service_signature: string,
 *               render_input_sha256: string, pdf_sha256: string, ms: int}|WP_Error
 */
function squuad_cert_pdf_render(array $payload, string $context, int $timeout = 45)
{
    $missing = squuad_cert_pdf_service_missing();
    if ($missing) {
        return new WP_Error('pdf_sin_configurar', __('The PDF service is not configured on this site.', 'wp-certificates'), $missing);
    }
    $body = (string) wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (strlen($body) > SQUUAD_CERT_PDF_MAX_BODY) {
        squuad_cert_log(sprintf('Motor de PDF (%s): documento de %d KB, más que el máximo del servicio', $context, (int) (strlen($body) / 1024)), 'pdf_engine');
        return new WP_Error('pdf_grande', __('The document is too large for the PDF service (reduce the size of its images).', 'wp-certificates'));
    }
    $t0 = microtime(true);
    $r = squuad_cert_pdf_service_request('POST', '/v1/render', $body, ['X-Squuad-Cert-Request-Id' => wp_generate_uuid4()], $timeout);
    $ms = (int) round((microtime(true) - $t0) * 1000);
    $fail = static function (string $code, string $why) use ($context, $ms): WP_Error {
        squuad_cert_log(sprintf('Motor de PDF (%s): %s (%d ms)', $context, $why, $ms), 'pdf_engine');
        return new WP_Error($code, __('The PDF service could not generate the document.', 'wp-certificates'), ['reason' => $why]);
    };
    if (is_wp_error($r)) {
        return $fail('pdf_conexion', 'sin conexión: ' . $r->get_error_message());
    }
    $status = (int) wp_remote_retrieve_response_code($r);
    $pdf = (string) wp_remote_retrieve_body($r);
    if (200 !== $status) {
        $err = json_decode($pdf, true);
        $why = 'código ' . $status . (is_array($err) && isset($err['error']) ? ' ' . sanitize_key((string) $err['error']) : '');
        if (401 === $status && is_array($err) && isset($err['server_time'])) {
            $why .= sprintf(' (diferencia de reloj: %d s)', time() - (int) $err['server_time']);
        }
        return $fail('pdf_servicio', $why);
    }
    if (strlen($pdf) > SQUUAD_CERT_PDF_MAX_PDF || '%PDF-' !== substr($pdf, 0, 5)) {
        return $fail('pdf_invalido', 'respuesta que no es un PDF válido');
    }
    $h = static fn(string $name): string => (string) wp_remote_retrieve_header($r, $name);
    $signed = implode("\n", ['v1', $r['_sq_site'], $r['_sq_nonce'], hash('sha256', $body), hash('sha256', $pdf),
        $h('x-squuad-cert-rules'), $h('x-squuad-cert-chrome'), $h('x-squuad-cert-signed-at')]);
    $sig = base64_decode($h('x-squuad-cert-signature'), true);
    $ok = false;
    if (false !== $sig && SODIUM_CRYPTO_SIGN_BYTES === strlen($sig)) {
        try {
            $ok = sodium_crypto_sign_verify_detached($sig, $signed, (string) base64_decode((string) SQUUAD_CERT_PDF_SERVICE_PUBKEY, true));
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    if (!$ok) {
        return $fail('pdf_firma', 'la firma del servicio no es válida');
    }

    return [
        'pdf' => $pdf,
        'pages' => (int) $h('x-squuad-cert-pages'),
        'chrome' => $h('x-squuad-cert-chrome'),
        'rules' => $h('x-squuad-cert-rules'),
        'signed_at' => (int) $h('x-squuad-cert-signed-at'),
        'service_signature' => $h('x-squuad-cert-signature'),
        'render_input_sha256' => hash('sha256', $body),
        // Para volver a comprobar la firma del servicio más tarde (ADR 0013, fase 2): sitio, nonce y qué clave firmó
        'site' => (string) $r['_sq_site'],
        'nonce' => (string) $r['_sq_nonce'],
        'service_pubkey_sha256' => hash('sha256', (string) base64_decode((string) SQUUAD_CERT_PDF_SERVICE_PUBKEY, true)),
        'pdf_sha256' => hash('sha256', $pdf),
        'ms' => $ms,
    ];
}

/* ------------------------------------------------------------------------------------------------ HTML del motor */

/** CSS base fijo de los PDF del servidor (los valores del admin que los documentos heredaban hasta ahora). */
function squuad_cert_pdf_base_css(): string
{
    static $css = null;
    if (null === $css) {
        $css = (string) file_get_contents(WP_C_PATH . 'includes/pdf-base.css');
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
    }

    return $css;
}

/** Carpetas de las que se pueden incrustar imágenes y fuentes (B1 de la revisión de seguridad del ADR 0013). */
function squuad_cert_pdf_allowed_roots(): array
{
    $roots = [WP_C_PATH, get_stylesheet_directory(), get_template_directory(), ABSPATH . WPINC, WP_PLUGIN_DIR, wp_upload_dir()['basedir'] ?? ''];
    $out = [];
    foreach ($roots as $r) {
        $real = $r ? realpath($r) : false;
        if ($real) {
            $out[] = trailingslashit($real);
        }
    }

    return array_values(array_unique($out));
}

/**
 * ¿Es un archivo protegido que nunca debe ir a un PDF? Adjuntos privados (documentos de estudiantes y docentes, firmas,
 * PDF firmados: ADR 0007/0008) y sus tamaños intermedios, y carpetas protegidas de uploads.
 */
function squuad_cert_pdf_is_protected(string $path, string $url): bool
{
    $uploads = realpath((string) (wp_upload_dir()['basedir'] ?? ''));
    if (!$uploads || 0 !== strpos($path, trailingslashit($uploads))) {
        return false;
    }
    $rel = substr($path, strlen(trailingslashit($uploads)));
    if (preg_match('#^(woocommerce_uploads|wc-logs|wo-keys|squuad-cert-private)/#', $rel)) {
        return true;
    }
    global $wpdb;
    // El adjunto original y, si es un tamaño intermedio (-300x200), el de su imagen original
    $candidates = array_unique([$rel, (string) preg_replace('/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', $rel), (string) preg_replace('/-scaled(\.[a-z0-9]+)$/i', '$1', $rel)]);
    foreach ($candidates as $candidate) {
        $id = (int) $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1", $candidate));
        if (!$id) {
            continue;
        }
        if ('private' === get_post_status($id)) {
            return true;
        }
        foreach (['_edusystem_student_document', '_edusystem_teacher_document', '_edusystem_signature_image', '_squuad_cert_signed_pdf'] as $marker) {
            if ('' !== (string) get_post_meta($id, $marker, true)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Ruta en disco candidata de una URL de este sitio: absoluta, sin esquema («//…») o relativa a la raíz («/wp-content/…»).
 * Prueba las bases de uploads, de wp-content y del sitio (también si wp-content o uploads están fuera de ABSPATH).
 */
function squuad_cert_pdf_url_to_path(string $url): ?string
{
    $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5);
    $strip = static fn(string $u): string => (string) preg_replace('#^https?:#i', '', $u);
    $url = (string) strtok($url, '?#');
    if ('' === $url || preg_match('#^[a-z][a-z0-9+.-]*:(?!//)#i', $url)) {
        return null; // data:, mailto:, javascript:…
    }
    if ('/' === $url[0] && (strlen($url) < 2 || '/' !== $url[1])) {
        $url = '//' . (string) wp_parse_url(home_url(), PHP_URL_HOST) . $url; // relativa a la raíz
    } elseif (!preg_match('#^(https?:)?//#i', $url)) {
        return null; // relativa al documento: sin base fiable
    }
    $uploads = wp_upload_dir();
    $bases = [
        [(string) ($uploads['baseurl'] ?? ''), (string) ($uploads['basedir'] ?? '')],
        [content_url(), WP_CONTENT_DIR],
        [site_url(), ABSPATH],
        [home_url(), ABSPATH],
    ];
    foreach ($bases as [$base_url, $base_dir]) {
        if ('' === $base_url || '' === $base_dir) {
            continue;
        }
        $b = trailingslashit($strip($base_url));
        $u = $strip($url);
        if (0 === strpos($u, $b)) {
            return trailingslashit($base_dir) . ltrim(rawurldecode(substr($u, strlen($b))), '/');
        }
    }

    return null;
}

/** Archivo local de este sitio que corresponde a una URL (solo en carpetas permitidas y nunca protegido), o null. */
function squuad_cert_pdf_local_file(string $url): ?string
{
    $candidate = squuad_cert_pdf_url_to_path($url);
    $path = $candidate ? realpath($candidate) : false;
    if (!$path || !is_file($path) || filesize($path) > SQUUAD_CERT_PDF_MAX_IMAGE) {
        return null;
    }
    foreach (squuad_cert_pdf_allowed_roots() as $root) {
        if (0 === strpos($path, $root)) {
            return squuad_cert_pdf_is_protected($path, $url) ? null : $path;
        }
    }

    return null;
}

/** Bytes ya incrustados en la petición en curso (tope acumulado: el servicio no acepta más de 10 MB). */
function squuad_cert_pdf_inline_budget(?int $reset = null): int
{
    static $used = 0;
    if (null !== $reset) {
        $used = $reset;
    }

    return $used;
}

/** Imagen o fuente local como data: URI (solo tipos de imagen y fuente), o null. */
function squuad_cert_pdf_data_uri(string $url): ?string
{
    $path = squuad_cert_pdf_local_file($url);
    if (!$path) {
        return null;
    }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $types = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'svg' => 'image/svg+xml', 'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf'];
    if (!isset($types[$ext])) {
        return null;
    }

    $size = (int) filesize($path);
    $used = squuad_cert_pdf_inline_budget();
    if ($used + (int) ($size * 1.37) > SQUUAD_CERT_PDF_MAX_BODY) {
        return null; // tope acumulado: el resto se queda sin incrustar (y el servicio no lo carga)
    }
    squuad_cert_pdf_inline_budget($used + (int) ($size * 1.37));

    return 'data:' . $types[$ext] . ';base64,' . base64_encode((string) file_get_contents($path));
}

/**
 * Prepara el HTML para el motor: imágenes y url() de este sitio incrustadas, QR como imagen (sin JavaScript) y fuera
 * los <script>. Lo que no sea de este sitio se queda tal cual (el servicio lo bloquea).
 *
 * @param string $qr_default dirección del QR para los huecos `<div id="qrcode">` (vacío: el hueco queda vacío)
 */
function squuad_cert_pdf_prepare_html(string $html, string $qr_default = ''): string
{
    $html = (string) preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html);
    // QR: hueco {{qrcode}} y QR de la verificación de la firma (data-edusig-qr / data-wpc-qr)
    $qr = static function (string $text): string {
        if ('' === $text) {
            return '';
        }
        try {
            return '<img alt="" src="' . esc_attr(squuad_cert_qr_data_uri($text)) . '" style="width:100px;height:100px;display:block">';
        } catch (Throwable $e) {
            return '';
        }
    };
    $html = (string) preg_replace_callback('#<div\b([^>]*?)(?<![\w-])id=(["\'])qrcode\2([^>]*)>\s*</div>#i', static fn($m) => '<div' . $m[1] . 'id="qrcode"' . $m[3] . '>' . $qr($qr_default) . '</div>', $html);
    $html = (string) preg_replace_callback('#(<[a-z]+\b[^>]*?(?<![\w-])data-(?:edusig|wpc)-qr=(["\'])([^"\']*)\2[^>]*>)\s*(</[a-z]+>)#i', static fn($m) => $m[1] . $qr(html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5)) . $m[4], $html);
    // srcset/sizes: el motor usaría otra imagen (no incrustada); se queda solo src
    $html = (string) preg_replace('#\s(?<![\w-])(?:srcset|sizes)=(["\'])[^"\']*\1#i', '', $html);
    // Imágenes y url() de este sitio
    $html = (string) preg_replace_callback('/(<(?:img|source)\b[^>]*?(?<![\w-])src=)(["\'])([^"\']+)\2/i', static function ($m) {
        if (0 === strpos($m[3], 'data:')) {
            return $m[0];
        }
        $data = squuad_cert_pdf_data_uri($m[3]);

        return $data ? $m[1] . $m[2] . $data . $m[2] : $m[0];
    }, $html);

    return squuad_cert_pdf_inline_css_urls($html);
}

/** url(...) de este sitio dentro de estilos (atributos style y <style>) como data: URI. */
function squuad_cert_pdf_inline_css_urls(string $css): string
{
    return (string) preg_replace_callback('/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i', static function ($m) {
        if (0 === strpos($m[2], 'data:') || 0 === strpos($m[2], '#')) {
            return $m[0];
        }
        $data = squuad_cert_pdf_data_uri($m[2]);

        return $data ? 'url("' . $data . '")' : $m[0];
    }, $css);
}

/**
 * Página del contrato /v1 a partir de las opciones de página de jsPDF que usa hoy el navegador
 * (squuad_cert_signature_pdf_page_from_options()): ['width', 'height', 'unit', 'margin' => [arriba, der., abajo, izq.]].
 */
function squuad_cert_pdf_page(array $jspdf, float $margin, bool $jspdf_px_unscaled = false): array
{
    $unit = in_array($jspdf['unit'] ?? 'mm', ['mm', 'cm', 'in', 'px', 'pt'], true) ? $jspdf['unit'] : 'mm';
    $landscape = 'landscape' === ($jspdf['orientation'] ?? 'portrait');
    $format = $jspdf['format'] ?? 'a4';
    if (is_array($format) && (float) ($format[0] ?? 0) > 0 && (float) ($format[1] ?? 0) > 0) {
        [$w, $h] = [(float) $format[0], (float) $format[1]];
        // jsPDF sin el arreglo px_scaling (vista previa «emitido» y bandeja) toma 1 px = 1,333 pt, no 0,75 pt (px de CSS)
        if ('px' === $unit && $jspdf_px_unscaled) {
            [$w, $h, $margin, $unit] = [$w * 96 / 72, $h * 96 / 72, $margin * 96 / 72, 'pt'];
        }
    } else {
        $format = is_array($format) ? 'a4' : $format;
        $unit = is_array($jspdf['format'] ?? null) ? 'mm' : $unit;
        $sizes = ['a4' => [210, 297], 'a3' => [297, 420], 'a5' => [148, 210], 'letter' => [215.9, 279.4], 'legal' => [215.9, 355.6], 'tabloid' => [279.4, 431.8]];
        [$w, $h] = $sizes[strtolower((string) $format)] ?? $sizes['a4'];
        $per_mm = ['mm' => 1, 'cm' => 0.1, 'in' => 1 / 25.4, 'px' => 96 / 25.4, 'pt' => 72 / 25.4][$unit];
        [$w, $h] = [$w * $per_mm, $h * $per_mm];
    }
    if ($landscape !== ($w > $h)) {
        [$w, $h] = [$h, $w];
    }

    return ['width' => round($w, 3), 'height' => round($h, 3), 'unit' => $unit, 'margin' => array_fill(0, 4, round($margin, 3))];
}

/**
 * Hojas del núcleo que una plantilla usa sin declararlas (en la pantalla siempre estaban cargadas): Dashicons, solo si el
 * documento la usa (la fuente ya va incrustada en dashicons.min.css; unos 59 KB).
 */
function squuad_cert_pdf_core_css(string $all_html): string
{
    $css = '';
    if (false !== strpos($all_html, 'dashicons')) {
        $file = ABSPATH . WPINC . '/css/dashicons.min.css';
        if (is_readable($file)) {
            // Fuera las direcciones relativas (eot/ttf/svg): la fuente woff incrustada basta y el servicio no tiene red
            $css .= (string) preg_replace('#url\((["\']?)\.\./fonts/[^)]*\)\s*format\((["\'])[a-z-]+\2\)\s*,?#i', '', (string) file_get_contents($file));
        }
    }

    return $css;
}

/** ¿Tiene algo visible un encabezado o pie (texto, imagen, SVG, tabla con bordes, fondo o QR)? */
function squuad_cert_pdf_part_has_content(string $html): bool
{
    return '' !== trim(html_entity_decode(wp_strip_all_tags($html, true), ENT_QUOTES | ENT_HTML5), " \t\n\r\0\x0B\xC2\xA0")
        || (bool) preg_match('#<(img|svg|table|hr|canvas)\b|background|border#i', $html);
}

/** Petición completa para el servicio. */
function squuad_cert_pdf_payload(string $html, array $page, string $header = '', string $footer = '', string $qr_default = '', string $extra_css = ''): array
{
    squuad_cert_pdf_inline_budget(0);

    return [
        'html' => squuad_cert_pdf_prepare_html($html, $qr_default),
        'head' => '<style>' . squuad_cert_pdf_base_css() . squuad_cert_pdf_core_css($html . $header . $footer) . squuad_cert_pdf_inline_css_urls($extra_css) . '</style>',
        'header' => squuad_cert_pdf_part_has_content($h = squuad_cert_pdf_prepare_html($header, $qr_default)) ? $h : '',
        'footer' => squuad_cert_pdf_part_has_content($f = squuad_cert_pdf_prepare_html($footer, $qr_default)) ? $f : '',
        'page' => $page,
        'rules' => SQUUAD_CERT_PDF_RULES,
    ];
}
