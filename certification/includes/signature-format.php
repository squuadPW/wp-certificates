<?php
declare(strict_types=1);

/**
 * Certificación - Flujo de firma nuevo (ADR 0011 de Edusof, aprobado por el dueño el 2026-10-05).
 *
 * - Formato de firma v2 (columna signature): {"v":2,"method":"typed|drawn|image",...}. Las firmas anteriores (trazos
 *   en JSON y ["automatic"]) no cambian y se siguen pintando igual.
 *     typed  {"v":2,"method":"typed","style":1-4,"text":"…","raster":"server|client","png":"data:image/png;base64,…"}
 *            El servidor dibuja el texto con la fuente del estilo (GD + FreeType, fuentes OFL del plugin): la imagen no
 *            depende del navegador ni de la fuente al generar el PDF, y se puede volver a dibujar para comprobarla.
 *            Sin FreeType, se acepta la imagen del navegador recodificada con GD ("raster":"client").
 *     drawn  {"v":2,"method":"drawn","strokes":[{penColor, points:[{x,y,time,pressure}]}]} (vector, como siempre).
 *     image  {"v":2,"method":"image","own_signature_confirmed":true,"source_type":"image/png|image/jpeg",
 *            "source_sha256":"…","png":"data:image/png;base64,…"}: imagen subida (PNG o JPG, hasta 2 MB) validada con
 *            getimagesizefromstring y recodificada con GD (sin metadatos ni contenido oculto) a PNG de 900×300 px máx.
 * - La huella de la imagen (sha256 de los bytes del PNG guardado) se sella en la evidencia EDUSIG4.
 * - Bloque de firma común (pantalla y PDF), hora local del sitio, numeración F1, F2… de los firmantes y la página
 *   final «Certificado de firmas», generada con datos del servidor.
 */

if (!defined('ABSPATH')) exit;

/** Opción por sitio: «Subir imagen» en la ventana de firma ('1' sí, '0' no). Por defecto, sí. */
const SQUUAD_CERT_SIGNING_UPLOAD_OPTION = 'squuad_cert_signing_allow_upload';
/** Opción por sitio: nota legal del certificado de firmas (vacía = texto neutro por defecto). */
const SQUUAD_CERT_SIGNING_LEGAL_NOTE_OPTION = 'squuad_cert_signing_legal_note';
/** Imagen subida: tamaño máximo del archivo original. */
const SQUUAD_CERT_SIGNATURE_UPLOAD_MAX_BYTES = 2097152;
/** Imagen guardada (subida o escrita): medidas máximas del PNG recodificado. */
const SQUUAD_CERT_SIGNATURE_IMAGE_MAX_W = 900;
const SQUUAD_CERT_SIGNATURE_IMAGE_MAX_H = 300;
/** Imagen de origen: lado y superficie máximos, comprobados ANTES de descomprimirla (bomba de descompresión). */
const SQUUAD_CERT_SIGNATURE_SOURCE_MAX_SIDE = 4000;
const SQUUAD_CERT_SIGNATURE_SOURCE_MAX_PIXELS = 4000000;
/** Firma escrita: tamaño mínimo de letra al reducirla para que quepa. */
const SQUUAD_CERT_SIGNATURE_TYPED_MIN_SIZE = 24;
/** Firma escrita: largo máximo del texto. */
const SQUUAD_CERT_SIGNATURE_TYPED_MAX = 80;
/** Petición con la firma v2: tamaño máximo del JSON (una imagen de 2 MB en base64 más los datos). */
const SQUUAD_CERT_SIGNATURE_V2_MAX_BYTES = 3145728;

/** ¿El sitio permite la pestaña «Subir imagen»? (y el servidor puede recodificar imágenes) */
function squuad_cert_signing_upload_allowed(): bool
{
    return '0' !== (string) get_option(SQUUAD_CERT_SIGNING_UPLOAD_OPTION, '1') && squuad_cert_signature_gd_available();
}

/** ¿Hay GD para recodificar imágenes? */
function squuad_cert_signature_gd_available(): bool
{
    return function_exists('imagecreatefromstring') && function_exists('imagepng') && function_exists('getimagesizefromstring');
}

/** ¿Se ofrece la firma escrita? Solo si el servidor la puede dibujar (GD con FreeType y la fuente del estilo 1). */
function squuad_cert_signature_typed_available(): bool
{
    return squuad_cert_signature_freetype_available() && '' !== squuad_cert_signature_style_font(1);
}

/** ¿Puede el servidor dibujar la firma escrita con las fuentes del plugin? (GD con FreeType) */
function squuad_cert_signature_freetype_available(): bool
{
    return squuad_cert_signature_gd_available() && function_exists('imagettftext') && function_exists('imagettfbbox');
}

/** Opción con el resultado de comprobar la columna signature_image_sha256 (ligado a la versión del esquema). */
const SQUUAD_CERT_SIGNATURE_IMAGE_COLUMNS_OPTION = 'squuad_cert_signature_image_columns';

/**
 * ¿Existe la columna signature_image_sha256 en las firmas vivas Y en las anuladas (esquema 15)? Se comprueba con SHOW
 * COLUMNS una vez por versión del esquema y se guarda en una opción (mismo patrón que
 * squuad_cert_id_document_evidence_enabled). Si falta, no se usa la columna ni se sella EDUSIG4 (la firma se sella en
 * EDUSIG3/EDUSIG2 como antes): nunca falla el INSERT de la firma ni el INSERT…SELECT de anular.
 */
function squuad_cert_signature_image_evidence_enabled(): bool
{
    global $wpdb;
    static $enabled = null;

    $version = (string) get_option('wp_c_db_version');
    if (null !== $enabled && $enabled['db'] === $version) {
        return $enabled['ok'];
    }
    $saved = get_option(SQUUAD_CERT_SIGNATURE_IMAGE_COLUMNS_OPTION);
    if (is_array($saved) && ($saved['db'] ?? '') === $version) {
        $enabled = ['db' => $version, 'ok' => !empty($saved['ok'])];
        return $enabled['ok'];
    }
    $ok = '' !== $version && version_compare($version, '15', '>=');
    foreach (['squuad_cert_signatures', 'squuad_cert_signatures_revoked'] as $table) {
        if ($ok && !$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$wpdb->prefix}{$table} LIKE %s", 'signature_image_sha256'))) {
            $ok = false;
        }
    }
    update_option(SQUUAD_CERT_SIGNATURE_IMAGE_COLUMNS_OPTION, ['db' => $version, 'ok' => $ok], false);
    $enabled = ['db' => $version, 'ok' => $ok];

    return $ok;
}

/** Olvida la comprobación de la columna (la llama la instalación del esquema). */
function squuad_cert_signature_image_evidence_reset(): void
{
    delete_option(SQUUAD_CERT_SIGNATURE_IMAGE_COLUMNS_OPTION);
}

/**
 * Estilos de la firma escrita: fuentes manuscritas de licencia libre (SIL OFL 1.1) servidas desde el plugin
 * (public/assets/fonts/), sin Google Fonts ni CDN (excepción a «cero terceros» registrada en el ADR 0011).
 */
function squuad_cert_signature_styles(): array
{
    return [
        1 => ['file' => 'GreatVibes-Regular.ttf', 'family' => 'WPC Firma 1', 'label' => __('Style 1 · calligraphic', 'wp-certificates'), 'size' => 46],
        2 => ['file' => 'Caveat-Variable.ttf', 'family' => 'WPC Firma 2', 'label' => __('Style 2 · handwritten', 'wp-certificates'), 'size' => 48],
        3 => ['file' => 'DancingScript-Variable.ttf', 'family' => 'WPC Firma 3', 'label' => __('Style 3 · handwritten, slanted', 'wp-certificates'), 'size' => 42],
        4 => ['file' => 'Allura-Regular.ttf', 'family' => 'WPC Firma 4', 'label' => __('Style 4 · classic', 'wp-certificates'), 'size' => 48],
    ];
}

/** Ruta de la fuente de un estilo, o '' si no existe. */
function squuad_cert_signature_style_font(int $style): string
{
    $styles = squuad_cert_signature_styles();
    if (!isset($styles[$style])) {
        return '';
    }
    $path = SQUUAD_CERT_MODULE_PATH . 'public/assets/fonts/' . $styles[$style]['file'];

    return is_readable($path) ? $path : '';
}

/** Hoja con las @font-face de las firmas (la cargan la ventana de firma y las páginas que generan el PDF final). */
function squuad_cert_signature_fonts_enqueue(): void
{
    wp_enqueue_style('squuad-cert-signature-fonts', SQUUAD_CERT_MODULE_URL . 'public/assets/css/signature-fonts.css', [], squuad_cert_signing_assets_version());
}

/**
 * Generador de QR que ya usa el plugin (public/assets/js/qrcode.min.js, «QRCode for JavaScript», de terceros con
 * licencia MIT): pendiente de reescribirlo o aprobarlo con un ADR (ADR 0011, pendientes).
 */
function squuad_cert_qrcode_script_url(): string
{
    return plugins_url('public/assets/js/qrcode.min.js', WP_C_PATH . 'wp-certificates.php');
}

/** Versión de caché de los JS y CSS de la firma: la del plugin más la del flujo de firma. */
function squuad_cert_signing_assets_version(): string
{
    return (defined('WP_C_VERSION') ? WP_C_VERSION : '0') . '-firma-4'; // 4: aviso del PDF final por el servidor (ADR 0013)
}

/* ---------------------------------------------------------------------------------------------------------------
 * Imágenes: recodificar con GD y dibujar la firma escrita
 * ------------------------------------------------------------------------------------------------------------ */

/** Lienzo PNG transparente de w×h. */
function squuad_cert_signature_gd_canvas(int $width, int $height)
{
    $image = imagecreatetruecolor(max(1, $width), max(1, $height));
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefilledrectangle($image, 0, 0, max(1, $width) - 1, max(1, $height) - 1, imagecolorallocatealpha($image, 255, 255, 255, 127));

    return $image;
}

/** Bytes PNG de una imagen GD. */
function squuad_cert_signature_gd_png($image): string
{
    ob_start();
    imagepng($image, null, 9);

    return (string) ob_get_clean();
}

/**
 * Recodifica una imagen (PNG o JPG) con GD: tipo real comprobado con getimagesizefromstring (no por la extensión ni
 * por lo que diga el navegador), medidas razonables, reducida a 900×300 px como máximo y guardada como PNG nuevo
 * (sin metadatos, perfiles ni datos añadidos al final del archivo). Devuelve [bytes PNG, tipo original] o null.
 */
function squuad_cert_signature_image_recode(string $bytes): ?array
{
    if (!squuad_cert_signature_gd_available() || '' === $bytes || strlen($bytes) > SQUUAD_CERT_SIGNATURE_UPLOAD_MAX_BYTES) {
        return null;
    }
    $info = @getimagesizefromstring($bytes);
    if (!is_array($info) || !in_array((int) ($info[2] ?? 0), [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
        return null;
    }
    [$width, $height] = [(int) $info[0], (int) $info[1]];
    // Medidas leídas de la cabecera, antes de descomprimir: un PNG de pocos KB puede ocupar cientos de MB en memoria
    if ($width < 40 || $height < 15 || $width > SQUUAD_CERT_SIGNATURE_SOURCE_MAX_SIDE || $height > SQUUAD_CERT_SIGNATURE_SOURCE_MAX_SIDE
        || $width * $height > SQUUAD_CERT_SIGNATURE_SOURCE_MAX_PIXELS) {
        return null;
    }
    $source = @imagecreatefromstring($bytes);
    if (!$source) {
        return null;
    }
    $scale = min(1.0, SQUUAD_CERT_SIGNATURE_IMAGE_MAX_W / $width, SQUUAD_CERT_SIGNATURE_IMAGE_MAX_H / $height);
    $new_width = max(1, (int) round($width * $scale));
    $new_height = max(1, (int) round($height * $scale));
    $target = squuad_cert_signature_gd_canvas($new_width, $new_height);
    imagealphablending($target, true);
    imagecopyresampled($target, $source, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
    imagealphablending($target, false);
    $png = squuad_cert_signature_gd_png($target);
    imagedestroy($source);
    imagedestroy($target);

    return '' === $png ? null : [$png, IMAGETYPE_PNG === (int) $info[2] ? 'image/png' : 'image/jpeg'];
}

/**
 * ¿Es un texto válido para la firma escrita? Sin caracteres de control ni de formato (\p{Cc}, \p{Cf}: marcas bidi
 * como U+202E o caracteres de ancho cero) y sin más de dos marcas combinantes seguidas. El texto no tiene que coincidir
 * con el nombre de la cuenta (como en DocuSign): el nombre impreso bajo la firma es siempre el de la cuenta.
 */
function squuad_cert_signature_typed_text_valid(string $text): bool
{
    return '' !== $text && 1 === preg_match('//u', $text) && !preg_match('/[\p{Cc}\p{Cf}]/u', $text) && !preg_match('/\p{M}{3,}/u', $text);
}

/**
 * Firma escrita dibujada SIEMPRE en el servidor con la fuente del estilo (tinta #10182A sobre fondo transparente).
 * Si no cabe (2400×600 px), reduce la letra por pasos hasta 24 pt; si aun así no cabe, null (texto demasiado largo).
 * Nunca se usa una imagen del navegador. Mismo texto y estilo = misma imagen: se puede volver a dibujar para
 * comprobarla. El «&» se escapa: GD interpreta las entidades numéricas (&#x202E; invertiría el texto).
 */
function squuad_cert_signature_typed_png(string $text, int $style): ?string
{
    $font = squuad_cert_signature_style_font($style);
    if ('' === $font || !squuad_cert_signature_typed_text_valid($text) || !squuad_cert_signature_freetype_available()) {
        return null;
    }
    $gd_text = str_replace('&', '&#38;', $text);
    for ($size = (float) (squuad_cert_signature_styles()[$style]['size'] ?? 44); $size >= SQUUAD_CERT_SIGNATURE_TYPED_MIN_SIZE; $size -= 4) {
        $box = @imagettfbbox($size, 0, $font, $gd_text);
        if (!is_array($box)) {
            return null;
        }
        $left = min($box[0], $box[6]);
        $right = max($box[2], $box[4]);
        $top = min($box[5], $box[7]);
        $bottom = max($box[1], $box[3]);
        // Margen amplio: los rasgos de las letras manuscritas salen de su caja
        $pad_x = (int) ceil($size * 0.6);
        $pad_y = (int) ceil($size * 0.45);
        $width = (int) ($right - $left) + 2 * $pad_x;
        $height = (int) ($bottom - $top) + 2 * $pad_y;
        if ($width > 2400 || $height > 600) {
            continue;
        }
        $image = squuad_cert_signature_gd_canvas($width, $height);
        imagealphablending($image, true);
        imagettftext($image, $size, 0, $pad_x - $left, $pad_y - $top, imagecolorallocate($image, 16, 24, 42), $font, $gd_text);
        imagealphablending($image, false);
        $png = squuad_cert_signature_gd_png($image);
        imagedestroy($image);
        if ('' === $png) {
            return null;
        }
        // A la misma escala máxima que las imágenes subidas
        if ($width > SQUUAD_CERT_SIGNATURE_IMAGE_MAX_W || $height > SQUUAD_CERT_SIGNATURE_IMAGE_MAX_H) {
            $recoded = squuad_cert_signature_image_recode($png);
            return $recoded ? $recoded[0] : null;
        }

        return $png;
    }

    return null;
}

/**
 * Cuenta distinta de $user_id que ya firmó con una imagen de esta huella (firmas vivas y anuladas), o 0. Sirve para
 * rechazar la reutilización de una imagen de firma por otra cuenta.
 */
function squuad_cert_signature_image_owner(array $hashes, int $user_id): int
{
    global $wpdb;

    $hashes = array_values(array_unique(array_filter($hashes, static fn($hash): bool => is_string($hash) && 64 === strlen($hash))));
    if (!$hashes || !squuad_cert_signature_image_evidence_enabled()) {
        return 0;
    }
    $in = implode(',', array_fill(0, count($hashes), '%s'));
    foreach (['squuad_cert_signatures', 'squuad_cert_signatures_revoked'] as $table) {
        $owner = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}{$table} WHERE signature_image_sha256 IN ({$in}) AND user_id <> %d LIMIT 1",
            array_merge($hashes, [$user_id])
        ));
        if ($owner) {
            return $owner;
        }
    }

    return 0;
}

/** Bytes de un data URI de imagen PNG o JPG (base64 estricto), o null. */
function squuad_cert_signature_data_uri_bytes(string $uri, array $types = ['image/png', 'image/jpeg']): ?array
{
    if (!preg_match('#^data:(image/(?:png|jpeg));base64,([A-Za-z0-9+/]+={0,2})$#', $uri, $match) || !in_array($match[1], $types, true)) {
        return null;
    }
    $bytes = base64_decode($match[2], true);

    return false === $bytes ? null : [$bytes, $match[1]];
}

/* ---------------------------------------------------------------------------------------------------------------
 * Formato v2: validar lo que envía el navegador y leer lo guardado
 * ------------------------------------------------------------------------------------------------------------ */

/**
 * Firma v2 enviada por la ventana de firma (campo signature_v2, JSON). Valida y normaliza todo en el servidor.
 * Devuelve ['json' => lo que se guarda, 'method' => typed|drawn|image, 'image_sha256' => sha256 del PNG o ''] o un
 * WP_Error con el mensaje para la persona.
 *
 * @return array|WP_Error
 */
function squuad_cert_signature_v2_from_input(string $raw, int $user_id = 0)
{
    $user_id = $user_id ?: get_current_user_id();
    $invalid = new WP_Error('squuad_cert_signature_invalid', __('Your signature could not be read. Please adopt it again.', 'wp-certificates'));
    if ('' === $raw) {
        return $invalid;
    }
    if (strlen($raw) > SQUUAD_CERT_SIGNATURE_V2_MAX_BYTES) {
        return new WP_Error('squuad_cert_signature_upload', __('The image must be a PNG or JPG file of up to 2 MB.', 'wp-certificates'));
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || 2 !== ($data['v'] ?? null) || !is_string($data['method'] ?? null)) {
        return $invalid;
    }

    switch ($data['method']) {
        case 'typed':
            // Sin FreeType no hay firma escrita (la pestaña no se muestra): nunca se usa una imagen del navegador
            if (!squuad_cert_signature_typed_available()) {
                return new WP_Error('squuad_cert_signature_typed_off', __('The typed signature is not available on this site. Please draw your signature.', 'wp-certificates'));
            }
            $raw_text = is_string($data['text'] ?? null) ? $data['text'] : '';
            $text = trim(sanitize_text_field($raw_text));
            $style = is_int($data['style'] ?? null) ? $data['style'] : (int) ($data['style'] ?? 0);
            if ('' === $text || mb_strlen($text) > SQUUAD_CERT_SIGNATURE_TYPED_MAX || !isset(squuad_cert_signature_styles()[$style])) {
                return new WP_Error('squuad_cert_signature_typed', __('Type your signature (up to 80 characters) and choose a style.', 'wp-certificates'));
            }
            if (!squuad_cert_signature_typed_text_valid($raw_text) || !squuad_cert_signature_typed_text_valid($text)) {
                return new WP_Error('squuad_cert_signature_typed_chars', __('The typed signature has characters that cannot be used. Type it again with letters, spaces and punctuation only.', 'wp-certificates'));
            }
            $png = squuad_cert_signature_typed_png($text, $style);
            if (null === $png) {
                return new WP_Error('squuad_cert_signature_typed_long', __('The typed signature is too long to fit. Write a shorter version (for example, with an initial).', 'wp-certificates'));
            }
            $stored = ['v' => 2, 'method' => 'typed', 'style' => $style, 'text' => $text, 'raster' => 'server', 'png' => 'data:image/png;base64,' . base64_encode($png)];

            return ['json' => (string) wp_json_encode($stored), 'method' => 'typed', 'image_sha256' => hash('sha256', $png)];

        case 'drawn':
            $strokes = squuad_cert_signer_normalize_strokes($data['strokes'] ?? null);
            if (null === $strokes) {
                return new WP_Error('squuad_cert_signature_drawn', __('Draw your signature before signing.', 'wp-certificates'));
            }
            $stored = ['v' => 2, 'method' => 'drawn', 'strokes' => json_decode($strokes, true)];

            return ['json' => (string) wp_json_encode($stored), 'method' => 'drawn', 'image_sha256' => ''];

        case 'image':
            if (!squuad_cert_signing_upload_allowed()) {
                return new WP_Error('squuad_cert_signature_upload_off', __('This site does not allow uploading an image of the signature.', 'wp-certificates'));
            }
            if (true !== ($data['own_signature_confirmed'] ?? null)) {
                return new WP_Error('squuad_cert_signature_upload_confirm', __('Confirm that the image is your own signature.', 'wp-certificates'));
            }
            $source = is_string($data['png'] ?? null) ? squuad_cert_signature_data_uri_bytes($data['png']) : null;
            if (!$source || strlen($source[0]) > SQUUAD_CERT_SIGNATURE_UPLOAD_MAX_BYTES) {
                return new WP_Error('squuad_cert_signature_upload', __('The image must be a PNG or JPG file of up to 2 MB.', 'wp-certificates'));
            }
            $recoded = squuad_cert_signature_image_recode($source[0]);
            if (!$recoded) {
                return new WP_Error('squuad_cert_signature_upload', __('The image could not be used. Use a PNG or JPG file of up to 2 MB with only your signature.', 'wp-certificates'));
            }
            // La misma imagen ya la usó otra cuenta: se rechaza y se anota (la identidad la dan la cuenta y la evidencia, no
            // la imagen; esto solo evita reutilizar a la vista una firma ajena ya presentada en el sitio)
            $owner = squuad_cert_signature_image_owner([hash('sha256', $source[0]), hash('sha256', $recoded[0])], $user_id);
            if ($owner) {
                squuad_cert_log(sprintf('Firma rechazada: el usuario %d subió una imagen de firma que ya usó el usuario %d', $user_id, $owner), 'signature_blocked');
                return new WP_Error('squuad_cert_signature_upload_reused', __('This image was already used as the signature of another account. You can only upload your own signature.', 'wp-certificates'));
            }
            $stored = [
                'v' => 2,
                'method' => 'image',
                'own_signature_confirmed' => true,
                'source_type' => $recoded[1],
                'source_sha256' => hash('sha256', $source[0]),
                'png' => 'data:image/png;base64,' . base64_encode($recoded[0]),
            ];

            return ['json' => (string) wp_json_encode($stored), 'method' => 'image', 'image_sha256' => hash('sha256', $recoded[0])];
    }

    return $invalid;
}

/**
 * Lo que hay en una firma guardada: ['format' => v2|strokes|automatic|unknown, 'method' => typed|drawn|image|'',
 * 'png' => base64 del PNG o '', 'strokes' => trazos o [], 'text' => texto escrito o ''].
 */
function squuad_cert_signature_info(string $signature_json): array
{
    $info = ['format' => 'unknown', 'method' => '', 'png' => '', 'strokes' => [], 'text' => ''];
    $data = json_decode($signature_json, true);
    if (['automatic'] === $data) {
        return ['format' => 'automatic'] + $info;
    }
    if (is_array($data) && 2 === ($data['v'] ?? null)) {
        $info['format'] = 'v2';
        $info['method'] = in_array($data['method'] ?? '', ['typed', 'drawn', 'image'], true) ? (string) $data['method'] : '';
        if (is_string($data['png'] ?? null) && preg_match('#^data:image/png;base64,([A-Za-z0-9+/]+={0,2})$#', $data['png'], $match)) {
            $info['png'] = $match[1];
        }
        $info['strokes'] = is_array($data['strokes'] ?? null) ? $data['strokes'] : [];
        $info['text'] = is_string($data['text'] ?? null) ? $data['text'] : '';
        return $info;
    }
    if (is_array($data) && $data && isset($data[0]['points'])) {
        return ['format' => 'strokes', 'strokes' => $data] + $info;
    }

    return $info;
}

/** sha256 del PNG de una firma v2 (lo que sella EDUSIG4), o '' si no lleva imagen. */
function squuad_cert_signature_image_sha256(string $signature_json): string
{
    $info = squuad_cert_signature_info($signature_json);
    $bytes = '' !== $info['png'] ? base64_decode($info['png'], true) : false;

    return false === $bytes ? '' : hash('sha256', $bytes);
}

/** Firma v2 como HTML (lo llama squuad_cert_signature_svg()): imagen PNG o trazos en SVG. */
function squuad_cert_signature_v2_html(array $info, string $name, int $width, int $height): string
{
    if ('drawn' === $info['method'] && $info['strokes']) {
        return squuad_cert_signature_svg((string) wp_json_encode($info['strokes']), $name, $width, $height);
    }
    if ('' === $info['png']) {
        return '';
    }
    /* translators: %s: name of the person who signed */
    $alt = '' !== $name ? sprintf(__('Signature of %s', 'wp-certificates'), $name) : __('Signature', 'wp-certificates');

    return '<img src="data:image/png;base64,' . esc_attr($info['png']) . '" alt="' . esc_attr($alt) . '" style="display:block;width:auto;height:auto;max-width:'
        . (int) $width . 'px;max-height:' . (int) $height . 'px">';
}

/**
 * Método de una firma, para el certificado y las pantallas: Escrita, Dibujada, Imagen subida; las firmas anteriores
 * al formato v2 dicen «(formato anterior)»; la del firmante del sistema, «Firma registrada».
 */
function squuad_cert_signature_method_label(string $signature_json, string $method_column = ''): string
{
    $info = squuad_cert_signature_info($signature_json);
    if ('v2' === $info['format']) {
        $labels = [
            'typed' => __('Typed', 'wp-certificates'),
            'drawn' => __('Drawn', 'wp-certificates'),
            'image' => __('Uploaded image', 'wp-certificates'),
        ];
        return $labels[$info['method']] ?? __('Unknown', 'wp-certificates');
    }
    if ('profile' === $method_column) {
        return __('Registered signature of the signer', 'wp-certificates');
    }
    if ('automatic' === $info['format']) {
        return __('Typed automatically (previous format)', 'wp-certificates');
    }

    return __('Drawn (previous format)', 'wp-certificates');
}

/* ---------------------------------------------------------------------------------------------------------------
 * Fechas, numeración y estado de los firmantes
 * ------------------------------------------------------------------------------------------------------------ */

/** Desfase de la zona horaria del sitio en un momento dado, p. ej. «UTC−4» o «UTC+5:30» (signo menos tipográfico). */
function squuad_cert_signature_utc_offset_label(int $timestamp): string
{
    $offset = wp_timezone()->getOffset(new DateTimeImmutable('@' . $timestamp));
    $sign = $offset < 0 ? "\u{2212}" : '+';
    $offset = abs($offset);
    $hours = intdiv($offset, 3600);
    $minutes = intdiv($offset % 3600, 60);

    return 0 === $offset ? 'UTC' : 'UTC' . $sign . $hours . ($minutes ? ':' . str_pad((string) $minutes, 2, '0', STR_PAD_LEFT) : '');
}

/** Fecha y hora UTC de la BD en la zona horaria del sitio: «05/10/2026, 10:42 (UTC−4)», con segundos si se pide. */
function squuad_cert_signature_local_time(string $utc, bool $seconds = false): string
{
    if ('' === $utc) {
        return '';
    }
    $timestamp = strtotime($utc . ' UTC');
    if (false === $timestamp) {
        return '';
    }

    return wp_date($seconds ? 'd/m/Y, H:i:s' : 'd/m/Y, H:i', $timestamp) . ' (' . squuad_cert_signature_utc_offset_label($timestamp) . ')';
}

/**
 * Número de cada firmante de una solicitud (F1, F2…; ADR 0010 de Edusof): F1 es quien recibe el documento; los demás,
 * su número del mapa del documento o, si no está, el siguiente libre en el orden de la solicitud. [puesto => n]
 */
function squuad_cert_signature_signer_numbers(object $request): array
{
    global $wpdb;
    static $cache = [];

    // Una consulta por solicitud y petición (los firmantes de una solicitud no cambian)
    if (isset($cache[(int) $request->id])) {
        return $cache[(int) $request->id];
    }

    $signers = squuad_cert_request_signers($request);
    $numbers = [];
    $holder = squuad_cert_request_holder_slot($request);
    if ('' !== $holder) {
        $numbers[$holder] = 1;
    }
    $document_id = (int) ($request->document_certificate_id ?? 0);
    $document = $document_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}documents_certificates WHERE id = %d", $document_id)) : null;
    $map = ($document && function_exists('squuad_cert_fn_map')) ? squuad_cert_fn_map($document) : [];
    foreach ($map as $n => $slot) {
        foreach ($signers as $signer) {
            if ($signer['slot_key'] === $slot && !isset($numbers[$slot]) && !in_array((int) $n, $numbers, true)) {
                $numbers[$slot] = (int) $n;
            }
        }
    }
    $next = '' === $holder ? 1 : 2;
    foreach (squuad_cert_signature_signers_in_order($request) as $signer) {
        if (isset($numbers[$signer['slot_key']])) {
            continue;
        }
        while (in_array($next, $numbers, true)) {
            $next++;
        }
        $numbers[$signer['slot_key']] = $next;
    }

    $cache[(int) $request->id] = $numbers;

    return $numbers;
}

/** Firmantes de la solicitud en el orden en que firman (fase y, dentro de ella, su posición). */
function squuad_cert_signature_signers_in_order(object $request): array
{
    $signers = squuad_cert_request_signers($request);
    foreach ($signers as $i => $signer) {
        $signers[$i]['_order'] = [(int) $signer['phase'], $i];
    }
    usort($signers, static fn(array $a, array $b): int => $a['_order'] <=> $b['_order']);

    return array_map(static function (array $signer): array {
        unset($signer['_order']);
        return $signer;
    }, $signers);
}

/** Nombre del puesto de un firmante para las etiquetas: el rol de quien recibe el documento, el puesto o el cargo. */
function squuad_cert_signature_signer_label(array $signer, ?object $request = null): string
{
    if (squuad_cert_is_holder_slot($signer['slot_key'])) {
        return squuad_cert_holder_slot_label($signer['slot_key']);
    }
    if ('' !== (string) $signer['charge']) {
        return (string) $signer['charge'];
    }

    return squuad_cert_is_var_slot($signer['slot_key']) ? squuad_cert_person_slot_label($signer['slot_key'], $request) : '';
}

/** Firmas vivas de una solicitud por puesto (signature, signed_at_utc, método, documento de identidad, consentimiento). */
function squuad_cert_signature_request_rows(int $request_id): array
{
    global $wpdb;

    $id_column = function_exists('squuad_cert_id_document_evidence_enabled') && squuad_cert_id_document_evidence_enabled() ? ', signer_id_document' : '';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, signer_role, user_id, signature, signed_at_utc, signature_method, consent_version, chain_seq{$id_column}
         FROM {$wpdb->prefix}squuad_cert_signatures WHERE request_id = %d ORDER BY signed_at_utc ASC, chain_seq ASC",
        $request_id
    ));
    $by_slot = [];
    foreach ((array) $rows as $row) {
        $by_slot[(string) $row->signer_role] = $row;
    }

    return $by_slot;
}

/**
 * Estado de cada firmante para la ventana de firma (chips del orden y panel de éxito), con datos del servidor:
 * [['slot', 'n', 'color', 'label', 'name', 'state' => signed|turn|pending, 'signed_at' (hora local), 'own']].
 */
function squuad_cert_signature_signers_overview(object $request, int $viewer_id): array
{
    $numbers = squuad_cert_signature_signer_numbers($request);
    $rows = squuad_cert_signature_request_rows((int) $request->id);
    $own = squuad_cert_signature_request_role($request, $viewer_id);
    $overview = [];
    foreach (squuad_cert_signature_signers_in_order($request) as $signer) {
        $slot = $signer['slot_key'];
        $row = $rows[$slot] ?? null;
        $n = (int) ($numbers[$slot] ?? 1);
        $overview[] = [
            'slot' => $slot,
            'n' => $n,
            'color' => function_exists('squuad_cert_fn_color') ? squuad_cert_fn_color($n) : 1,
            'label' => squuad_cert_signature_signer_label($signer, $request),
            'name' => (string) $signer['name'],
            'state' => $row ? 'signed' : (squuad_cert_signature_request_slot_open($request, $slot) ? 'turn' : 'pending'),
            'signed_at' => $row ? squuad_cert_signature_local_time((string) $row->signed_at_utc) : '',
            'own' => $slot === $own,
        ];
    }

    return $overview;
}

/* ---------------------------------------------------------------------------------------------------------------
 * Bloque de firma común (pantalla y PDF)
 * ------------------------------------------------------------------------------------------------------------ */

/**
 * ¿Se está pintando la ventana de firma (interactiva)? Entonces los puestos pendientes llevan el color del firmante
 * (por clase, nunca en línea); en el PDF y en la bandeja, en gris.
 */
function squuad_cert_signature_render_interactive(?bool $set = null): bool
{
    static $interactive = false;
    if (null !== $set) {
        $interactive = $set;
    }

    return $interactive;
}

/** Marcador del bloque completo de un firmante que la plantilla no coloca (bloque «Firmas» del final, contenido nuevo). */
function squuad_cert_signature_block_marker(string $slot_key): string
{
    return '<div data-edusig-block="' . esc_attr($slot_key) . '"></div>';
}

/**
 * Sustituye cada aparición de $marker por $render($i) (i = 0, 1…): si una variable de firma aparece varias veces, cada
 * etiqueta es distinta (antes str_replace repetía el mismo recuadro con los mismos ids).
 */
function squuad_cert_signature_replace_each(string $content, string $marker, callable $render, int &$counter = 0): string
{
    if ('' === $marker || false === strpos($content, $marker)) {
        return $content;
    }
    $parts = explode($marker, $content);
    $out = array_shift($parts);
    foreach ($parts as $part) {
        $out .= $render($counter++) . $part;
    }

    return $out;
}

/**
 * ¿Pone la plantilla el nombre de este firmante junto a su firma? Entonces su firma se pinta compacta (sin repetir
 * nombre ni puesto): firmantes del sistema con {{user_sign}}/{{signer_name_ID}}, por variable con
 * {{signer_name_var_X}}, o con {{full_name_Fn}}/{{charge_Fn}}. También el bloque «Firmas» antiguo, ya congelado, que
 * lleva el nombre y el cargo debajo del hueco.
 */
function squuad_cert_signature_slot_named(object $request, string $slot_key, string $content): bool
{
    global $wpdb;
    static $templates = [];

    if (squuad_cert_is_holder_slot($slot_key)) {
        return false;
    }
    if (false !== strpos($content, squuad_cert_signer_slot_marker($slot_key) . '<div style="border-top:1px solid #333;margin-top:4px;padding-top:4px"><strong>')) {
        return true;
    }
    $document_id = (int) ($request->document_certificate_id ?? 0);
    if (!isset($templates[$document_id])) {
        $document = $document_id ? $wpdb->get_row($wpdb->prepare("SELECT header, content, footer FROM {$wpdb->prefix}documents_certificates WHERE id = %d", $document_id)) : null;
        $templates[$document_id] = $document ? (string) $document->header . (string) $document->content . (string) $document->footer : '';
    }
    $template = $templates[$document_id];
    $n = (int) (squuad_cert_signature_signer_numbers($request)[$slot_key] ?? 0);
    if ($n > 1 && (false !== strpos($template, '{{full_name_F' . $n . '}}') || false !== strpos($template, '{{charge_F' . $n . '}}'))) {
        return true;
    }
    if (squuad_cert_is_var_slot($slot_key)) {
        return false !== strpos($template, '{{signer_name_var_' . squuad_cert_var_slot_variable($slot_key) . '}}');
    }
    if (0 === strpos($slot_key, 'signer:')) {
        return false !== strpos($template, '{{user_sign') || false !== strpos($template, '{{signer_name_' . (int) substr($slot_key, 7) . '}}');
    }

    return false;
}

/** Documento de identidad de una firma para el bloque (enmascarado en las vistas de pantalla, B3 del ADR 0007). */
function squuad_cert_signature_block_id_document(?object $row): string
{
    $id_document = (string) ($row->signer_id_document ?? '');
    if ('' !== $id_document && function_exists('squuad_cert_id_document_mask_in_boxes') && squuad_cert_id_document_mask_in_boxes()) {
        $id_document = squuad_cert_id_document_mask($id_document);
    }

    return $id_document;
}

/**
 * Bloque de firma de un firmante (igual para todos): firma sobre la línea, nombre, puesto, fecha y hora local,
 * documento de identidad (si lo tiene) y «Firmado electrónicamente»; si falta, «Pendiente de firma: <puesto>».
 * $full false: solo la firma y sus datos (la plantilla pone nombre y puesto). Estilos en línea y en gris (sirve para el
 * PDF); en la ventana de firma, el pendiente lleva además la clase con el color del firmante.
 */
function squuad_cert_signature_block_html(object $request, array $signer, bool $full = true, ?array $rows = null): string
{
    $rows = $rows ?? squuad_cert_signature_request_rows((int) $request->id);
    $row = $rows[$signer['slot_key']] ?? null;
    $label = squuad_cert_signature_signer_label($signer, $request);
    $name = (string) $signer['name'];
    $muted = 'font-size:12px;line-height:1.45;color:#4A5466';

    if ($row) {
        $area = squuad_cert_signature_svg((string) $row->signature, $name, 220, 76);
        $meta = '<div>' . esc_html(squuad_cert_signature_local_time((string) $row->signed_at_utc)) . '</div>';
        $id_document = squuad_cert_signature_block_id_document($row);
        if ('' !== $id_document) {
            /* translators: %s: identity document of the signer (prefix and number) */
            $meta .= '<div>' . esc_html(sprintf(__('ID document: %s', 'wp-certificates'), $id_document)) . '</div>';
        }
        $meta .= '<div style="font-size:11px">' . esc_html__('Signed electronically', 'wp-certificates') . '</div>';
    } else {
        /* translators: %s: position of the person who still has to sign, for example "Parent" */
        $pending_text = '' !== $label ? sprintf(__('Pending signature: %s', 'wp-certificates'), $label) : __('Pending signature', 'wp-certificates');
        if (squuad_cert_signature_render_interactive()) {
            $n = (int) (squuad_cert_signature_signer_numbers($request)[$signer['slot_key']] ?? 1);
            $color = function_exists('squuad_cert_fn_color') ? squuad_cert_fn_color($n) : 1;
            $area = '<div class="wpc-sign-pending wpc-signer-c' . (int) $color . '" data-signer="' . (int) $n . '"><span class="wpc-sign-dot" aria-hidden="true"></span><span>' . esc_html($pending_text) . '</span></div>';
        } else {
            $area = '<div style="font-style:italic;color:#666;font-size:12px;padding-bottom:6px">' . esc_html($pending_text) . '</div>';
        }
        $meta = '';
    }

    if (!$full) {
        return '<div class="wpc-sig-compact" style="display:inline-block;vertical-align:bottom;text-align:left">'
            . '<div style="min-height:60px;display:flex;align-items:flex-end">' . $area . '</div>'
            . ('' !== $meta ? '<div style="' . $muted . ';margin-top:2px">' . $meta . '</div>' : '') . '</div>';
    }

    return '<div class="wpc-sig-block" data-slot="' . esc_attr($signer['slot_key']) . '" style="display:inline-block;vertical-align:top;width:240px;max-width:100%;margin:0 24px 16px 0;text-align:left;page-break-inside:avoid">'
        . '<div style="height:84px;display:flex;align-items:flex-end;justify-content:flex-start;overflow:hidden">' . $area . '</div>'
        . '<div style="border-top:1.5px solid #1C2430;margin-top:4px;padding-top:6px;' . $muted . '">'
        . '<div style="font-weight:700;font-size:13px;color:#1C2430">' . esc_html($name) . '</div>'
        . ('' !== $label ? '<div>' . esc_html($label) . '</div>' : '')
        . $meta . '</div></div>';
}

/**
 * Etiqueta «Firmar aquí» de quien mira (ventana de firma), con su número de firmante y su color (por clase). $index:
 * número de aparición de su puesto en el documento (cada etiqueta es distinta). $full: con la línea, el nombre, el puesto
 * y los datos que se rellenan al firmar (fecha y hora, documento de identidad).
 */
function squuad_cert_signature_tag_html(string $slot_key, object $request, int $index, bool $full = true): string
{
    $numbers = squuad_cert_signature_signer_numbers($request);
    $n = (int) ($numbers[$slot_key] ?? 1);
    $color = function_exists('squuad_cert_fn_color') ? squuad_cert_fn_color($n) : 1;
    $signer = null;
    foreach (squuad_cert_request_signers($request) as $candidate) {
        if ($candidate['slot_key'] === $slot_key) {
            $signer = $candidate;
        }
    }
    $label = $signer ? squuad_cert_signature_signer_label($signer, $request) : squuad_cert_person_slot_label($slot_key, $request);
    $name = $signer ? (string) $signer['name'] : squuad_cert_account_name(get_current_user_id());

    $tag = '<button type="button" class="wpc-sign-tag wpc-signer-c' . (int) $color . '" data-wpc-tag data-slot="' . esc_attr($slot_key) . '" data-signer="' . (int) $n
        . '" data-tag-index="' . (int) $index . '" data-label="' . esc_attr($label) . '" data-html2canvas-ignore="true">'
        . '<span class="wpc-sign-flag" aria-hidden="true">' . esc_html__('Next', 'wp-certificates') . '</span>'
        . '<span class="wpc-sign-fn" aria-hidden="true">F' . (int) $n . '</span>'
        . '<span class="wpc-sign-tag-text">' . esc_html__('Sign here', 'wp-certificates')
        /* translators: %s: position of the person who signs, for example "Student" */
        . '<small>' . esc_html(sprintf(__('You · %s', 'wp-certificates'), $label)) . '</small></span></button>'
        . '<div class="wpc-sign-done" data-wpc-done hidden><div class="wpc-sign-done-img" data-wpc-done-img></div>'
        . '<button type="button" class="wpc-sign-change" data-wpc-change data-html2canvas-ignore="true">' . esc_html__('Change', 'wp-certificates') . '</button></div>';

    $id_document = function_exists('squuad_cert_id_document_required') && squuad_cert_id_document_required()
        ? squuad_cert_id_document_identifier(get_current_user_id()) : '';
    $meta = '<div class="wpc-sign-when">' . esc_html__('Date and time: added when you sign', 'wp-certificates') . '</div>'
        /* translators: %s: identity document of the signer (prefix and number) */
        . ('' !== $id_document ? '<div>' . esc_html(sprintf(__('ID document: %s', 'wp-certificates'), $id_document)) . '</div>' : '');

    if (!$full) {
        return '<div class="wpc-sign-slot wpc-sign-slot--compact" data-wpc-slot>' . '<div class="wpc-sign-area">' . $tag . '</div>'
            . '<div class="wpc-sign-meta">' . $meta . '</div></div>';
    }

    return '<div class="wpc-sign-slot" data-wpc-slot data-slot="' . esc_attr($slot_key) . '">'
        . '<div class="wpc-sign-area">' . $tag . '</div>'
        . '<div class="wpc-sign-line"><div class="wpc-sign-name">' . esc_html($name) . '</div>'
        . ('' !== $label ? '<div>' . esc_html($label) . '</div>' : '') . $meta . '</div></div>';
}

/* ---------------------------------------------------------------------------------------------------------------
 * Certificado de firmas (última página del PDF final)
 * ------------------------------------------------------------------------------------------------------------ */

/** Código del documento firmado, p. ej. WPC-2026-001325 (año de la solicitud y su número). */
function squuad_cert_signature_request_code(object $request): string
{
    $year = substr((string) ($request->created_at_utc ?? ''), 0, 4);

    return sprintf('WPC-%s-%06d', preg_match('/^\d{4}$/', $year) ? $year : gmdate('Y'), (int) $request->id);
}

/**
 * Dirección de verificación del QR: la atiende la página pública de verificación
 * (certification/public/functions/verify-page.php, ADR 0014 de Edusof). Filtro para cambiarla; la página acepta esta
 * dirección siempre, porque los PDF ya entregados la llevan.
 */
function squuad_cert_signature_verify_url(object $request, string $kind = 'sheet'): string
{
    $url = add_query_arg([
        'squuad_cert_verify' => rawurlencode(squuad_cert_signature_request_code($request)),
        't' => 'design' === $kind ? squuad_cert_signature_verify_doc_token($request) : squuad_cert_signature_verify_token($request),
    ], home_url('/'));

    // $kind: 'sheet' (hoja A4 y ranura del QR de los automáticos: token ligado al contenido) o 'design' (QR del diseño
    // de los emitidos, puesto antes de que exista el contenido). Quien use el filtro debe conservar el parámetro «t» que
    // recibe: rehacerlo con otro token dejaría inservible el QR del diseño
    return (string) apply_filters('squuad_cert_signature_verify_url', $url, $request, $kind);
}

/**
 * Token del QR del diseño de un documento emitido (ADR 0014): 16 hex del HMAC de «verify-doc|id» con una subclave de la
 * clave vigente. No depende del contenido, que aún no existe cuando se pone el QR. Vacío si no hay clave.
 */
function squuad_cert_signature_verify_doc_token(object $request): string
{
    $key = function_exists('squuad_cert_signature_current_key') ? squuad_cert_signature_current_key() : null;
    if (!$key || !function_exists('squuad_cert_signature_verify_doc_subkey')) {
        return '';
    }

    return substr(hash_hmac('sha256', 'verify-doc|' . (int) $request->id, squuad_cert_signature_verify_doc_subkey($key[1])), 0, 16);
}

/**
 * Parte no adivinable de la dirección de verificación: 16 hex del HMAC (clave de firma vigente) sobre
 * «id de la solicitud|huella del contenido». El código WPC-AAAA-NNNNNN se puede adivinar; el token no. La página de
 * verificación exige código + token y lo comprueba con todas las claves del sitio, por si se rotó (ADR 0014).
 */
function squuad_cert_signature_verify_token(object $request): string
{
    $key = function_exists('squuad_cert_signature_current_key') ? squuad_cert_signature_current_key() : null;
    // Sin contenido todavía (borrador): no hay token de la hoja
    if (!$key || '' === (string) ($request->content_sha256 ?? '')) {
        return '';
    }

    return substr(hash_hmac('sha256', 'verify|' . (int) $request->id . '|' . (string) $request->content_sha256, $key[1]), 0, 16);
}

/** Nota legal del certificado: la del sitio (Configuración) o un texto neutro. */
function squuad_cert_signing_legal_note(): string
{
    $note = trim((string) get_option(SQUUAD_CERT_SIGNING_LEGAL_NOTE_OPTION, ''));

    return '' !== $note ? $note : squuad_cert_signing_legal_note_default();
}

/** Texto neutro por defecto de la nota legal. */
function squuad_cert_signing_legal_note_default(): string
{
    return __('This document was signed electronically. Each signer agreed to sign electronically, and their signature was recorded with the date, the time and the fingerprint of the content. Times are shown in the time zone of the institution.', 'wp-certificates');
}

/** Versión del consentimiento aceptado («v1» de «v1:es_ES»). */
function squuad_cert_signature_consent_label(string $consent_version): string
{
    $version = (string) strtok($consent_version, ':');
    if ('' === $version) {
        return __('No consent recorded', 'wp-certificates');
    }

    /* translators: %s: version of the consent text, for example "v1" */
    return sprintf(__('Consent %s accepted', 'wp-certificates'), $version);
}

/**
 * Página «Certificado de firmas», con datos del servidor (nunca del navegador): documento, código, solicitud y ronda,
 * estado, fechas, firmantes en el orden en que firmaron (firma, nombre, puesto, fecha y hora local con segundos, método,
 * consentimiento y documento de identidad o «No aplica»), huella SHA-256 del contenido, QR y dirección de verificación y
 * la nota legal. Sin IP ni datos de la conexión (siguen en la evidencia). En gris, para el PDF.
 */
function squuad_cert_signature_certificate_html(object $request, bool $page_break = true, ?int $mask_except_user = null, string $copy_note = ''): string
{
    // $mask_except_user (copia descargada aparte, ADR 0014): documentos de identidad enmascarados salvo los de esa cuenta.
    // $copy_note: marca de copia (no es el documento firmado) bajo el título
    global $wpdb;

    $rows = squuad_cert_signature_request_rows((int) $request->id);
    $signers = [];
    foreach (squuad_cert_request_signers($request) as $signer) {
        $signers[$signer['slot_key']] = $signer;
    }
    $required = squuad_cert_signature_request_required_roles($request);
    // Título sellado al emitir (evento «issued»); si no lo hay, el actual del documento
    $issued = json_decode((string) $wpdb->get_var($wpdb->prepare(
        "SELECT data FROM {$wpdb->prefix}squuad_cert_events WHERE request_id = %d AND event_type = 'issued' ORDER BY id ASC LIMIT 1",
        (int) $request->id
    )), true);
    $title = is_array($issued) ? (string) ($issued['title'] ?? '') : '';
    if ('' === $title) {
        $title = (string) $wpdb->get_var($wpdb->prepare("SELECT title FROM {$wpdb->prefix}documents_certificates WHERE id = %d", (int) ($request->document_certificate_id ?? 0)));
    }
    $title = '' !== $title ? $title : (string) $request->document_id;
    $code = squuad_cert_signature_request_code($request);
    $last_signed = '';
    foreach ($rows as $row) {
        $last_signed = max($last_signed, (string) $row->signed_at_utc);
    }
    $completed_at = (string) ($request->completed_at_utc ?? '') ?: $last_signed;
    $signed_count = count(array_intersect($required, array_keys($rows)));
    $all = $signed_count >= count($required);
    $hash = (string) $request->content_sha256;
    $hash_lines = implode('<br>', array_map(static fn(array $groups): string => implode(' ', $groups), array_chunk(str_split($hash, 8), 4)));
    $site = (string) get_bloginfo('name');
    $host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
    $verify_url = squuad_cert_signature_verify_url($request);
    $cell = 'border-bottom:1px solid #ccc;padding:7px 6px;vertical-align:top';
    $head = 'text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#333;border-bottom:1.5px solid #111;padding:6px';
    $dt = 'color:#444;font-weight:600;padding:3px 16px 3px 0;vertical-align:top;white-space:nowrap';
    $dd = 'padding:3px 0;vertical-align:top';
    $h2 = 'font-size:12px;letter-spacing:.04em;text-transform:uppercase;margin:16px 0 6px;color:#111';
    $timestamp = strtotime(('' !== $completed_at ? $completed_at : gmdate('Y-m-d H:i:s')) . ' UTC') ?: time();

    $html = ($page_break ? '<div class="pagebreak"></div>' : '') . '<div class="wpc-signature-certificate" style="font-family:Arial,Helvetica,sans-serif;color:#111;font-size:12.5px;line-height:1.45;padding:4px">';
    $html .= '<div style="padding-bottom:12px;border-bottom:2px solid #111"><div style="font-weight:800;letter-spacing:.04em;font-size:15px">' . esc_html(mb_strtoupper($site)) . '</div>'
        . '<div style="font-size:11.5px;color:#444">' . esc_html($host) . '</div></div>';
    $html .= '<h1 style="margin:14px 0 2px;font-size:20px;text-align:left">' . esc_html__('Certificate of signatures', 'wp-certificates') . '</h1>'
        . '<p style="margin:0 0 12px;color:#444">' . esc_html__('Summary of who signed this document, when and how.', 'wp-certificates') . '</p>'
        . ('' !== $copy_note ? '<p style="margin:0 0 12px;padding:6px 10px;border:1px solid #c9a227;background:#fdf6e3;color:#5c4700;font-size:11.5px;overflow-wrap:anywhere">' . esc_html($copy_note) . '</p>' : '');
    $html .= '<table style="border-collapse:collapse;font-size:12.5px;margin:0 0 8px"><tbody>'
        . '<tr><td style="' . $dt . '">' . esc_html__('Document', 'wp-certificates') . '</td><td style="' . $dd . '">' . esc_html($title) . '</td></tr>'
        . '<tr><td style="' . $dt . '">' . esc_html__('Code', 'wp-certificates') . '</td><td style="' . $dd . '">' . esc_html($code) . '</td></tr>'
        /* translators: 1: number of the signature request, 2: round */
        . '<tr><td style="' . $dt . '">' . esc_html__('Request', 'wp-certificates') . '</td><td style="' . $dd . '">' . esc_html(sprintf(__('No. %1$d, round %2$d', 'wp-certificates'), (int) $request->id, (int) $request->round)) . '</td></tr>'
        . '<tr><td style="' . $dt . '">' . esc_html__('Status', 'wp-certificates') . '</td><td style="' . $dd . '">' . esc_html($all
            /* translators: 1: signatures given, 2: signatures required */
            ? sprintf(__('Completed: %1$d of %2$d signatures', 'wp-certificates'), $signed_count, count($required))
            /* translators: 1: signatures given, 2: signatures required */
            : sprintf(__('In progress: %1$d of %2$d signatures', 'wp-certificates'), $signed_count, count($required))) . '</td></tr>'
        . '<tr><td style="' . $dt . '">' . esc_html__('Sent for signature', 'wp-certificates') . '</td><td style="' . $dd . '">' . esc_html(squuad_cert_signature_local_time((string) $request->created_at_utc)) . '</td></tr>'
        . ($all && '' !== $completed_at ? '<tr><td style="' . $dt . '">' . esc_html__('Completed', 'wp-certificates') . '</td><td style="' . $dd . '">' . esc_html(squuad_cert_signature_local_time($completed_at)) . '</td></tr>' : '')
        . '</tbody></table>';

    $html .= '<h2 style="' . $h2 . '">' . esc_html__('Signers, in the order in which they signed', 'wp-certificates') . '</h2>';
    $html .= '<table style="width:100%;border-collapse:collapse;font-size:12px"><thead><tr>'
        . '<th style="' . $head . '">' . esc_html__('No.', 'wp-certificates') . '</th>'
        . '<th style="' . $head . '">' . esc_html__('Signer', 'wp-certificates') . '</th>'
        . '<th style="' . $head . '">' . esc_html__('Date and time', 'wp-certificates') . '</th>'
        . '<th style="' . $head . '">' . esc_html__('Method', 'wp-certificates') . '</th>'
        . '<th style="' . $head . '">' . esc_html__('ID document', 'wp-certificates') . '</th></tr></thead><tbody>';
    $i = 0;
    foreach ($rows as $slot => $row) {
        $signer = $signers[$slot] ?? ['slot_key' => $slot, 'name' => squuad_cert_account_name((int) $row->user_id), 'charge' => '', 'signer_id' => 0];
        $i++;
        $time = squuad_cert_signature_local_time((string) $row->signed_at_utc, true);
        $id_document = (string) ($row->signer_id_document ?? '');
        if (null !== $mask_except_user && '' !== $id_document && (int) $row->user_id !== $mask_except_user && function_exists('squuad_cert_id_document_mask')) {
            $id_document = squuad_cert_id_document_mask($id_document);
        }
        $html .= '<tr><td style="' . $cell . '">' . $i . '</td>'
            . '<td style="' . $cell . '"><div style="height:40px;display:flex;align-items:flex-end;margin-bottom:4px">' . squuad_cert_signature_svg((string) $row->signature, (string) $signer['name'], 180, 44) . '</div>'
            . '<strong>' . esc_html((string) $signer['name']) . '</strong><br>' . esc_html(squuad_cert_signature_signer_label($signer, $request)) . '</td>'
            . '<td style="' . $cell . '">' . implode('<br>', array_map('esc_html', explode(', ', $time, 2))) . '</td>'
            . '<td style="' . $cell . '">' . esc_html(squuad_cert_signature_method_label((string) $row->signature, (string) $row->signature_method))
            . '<br><span style="color:#555">' . esc_html(squuad_cert_signature_consent_label((string) $row->consent_version)) . '</span></td>'
            . '<td style="' . $cell . '">' . esc_html('' !== $id_document ? $id_document : __('Not applicable', 'wp-certificates')) . '</td></tr>';
    }
    $html .= '</tbody></table>';

    $html .= '<table style="width:100%;border-collapse:collapse;margin-top:14px"><tbody><tr><td style="vertical-align:top;padding-right:22px">'
        . '<h2 style="' . $h2 . ';margin-top:0">' . esc_html__('Content fingerprint (SHA-256)', 'wp-certificates') . '</h2>'
        . '<div style="font-family:Menlo,Consolas,monospace;font-size:12px;letter-spacing:.02em;word-break:break-all;background:#f3f3f3;padding:8px 10px;border:1px solid #ddd">' . $hash_lines . '</div>'
        . '<p style="margin:8px 0 0;font-size:11.5px;color:#333">' . esc_html__('It is a unique summary of the signed content. If a single letter of the document changed, the fingerprint would be different and the verification would detect it.', 'wp-certificates') . '</p>'
        . '<h2 style="' . $h2 . '">' . esc_html__('Verify', 'wp-certificates') . '</h2>'
        /* translators: 1: verification address, 2: code of the document */
        . '<p style="margin:0;font-size:12px">' . sprintf(esc_html__('Scan the QR code or go to %1$s and enter the code %2$s.', 'wp-certificates'), '<strong style="word-break:break-all">' . esc_html($verify_url) . '</strong>', '<strong>' . esc_html($code) . '</strong>') . '</p>'
        . '</td><td style="vertical-align:top;width:132px;text-align:center;font-size:10.5px;color:#444">'
        . '<div data-wpc-qr="' . esc_attr($verify_url) . '" style="width:120px;height:120px;margin:0 auto 4px"></div>' . esc_html__('Verification QR', 'wp-certificates') . '</td></tr></tbody></table>';

    $html .= '<p style="margin:14px 0 0;padding-top:10px;border-top:1px solid #ccc;font-size:11px;color:#333">' . esc_html(squuad_cert_signing_legal_note())
        /* translators: %s: offset of the time zone, for example "UTC−4" */
        . ' ' . esc_html(sprintf(__('Time zone: %s.', 'wp-certificates'), squuad_cert_signature_utc_offset_label($timestamp))) . '</p>';
    $html .= '<div style="margin-top:12px;padding-top:6px;border-top:1px solid #ccc;font-size:10.5px;color:#444">' . esc_html(sprintf(
        /* translators: %s: code of the document */
        __('Certificate of signatures · %s', 'wp-certificates'),
        $code
    )) . '</div></div>';

    return $html;
}

/**
 * Documento final para el PDF: el contenido congelado con las firmas pintadas en el servidor, la línea de la solicitud y
 * la huella, y la página «Certificado de firmas». Lo usan todos los caminos que generan el PDF final.
 */
function squuad_cert_signature_final_pdf_html(object $request): ?string
{
    $parts = squuad_cert_signature_final_pdf_parts($request);
    if (null === $parts) {
        return null;
    }

    return $parts['content'] . ('' !== $parts['line'] ? '<p style="margin-top:16px;font-size:9px;color:#666;word-break:break-all">' . esc_html($parts['line']) . '</p>' : '')
        . ('' !== $parts['certificate'] ? '<div class="pagebreak"></div>' . $parts['certificate'] : '');
}

/**
 * Piezas del PDF final (una sola fuente para el navegador y el servidor de PDF, ADR 0013): contenido con las firmas,
 * texto de la línea de la solicitud y certificado de firmas sin salto de página (vacío si no hay firmantes exigidos ni
 * firmas: solo se rellenó). Quien llama decide la máscara del documento de identidad y cómo las coloca.
 *
 * @return array{content: string, line: string, certificate: string}|null
 */
function squuad_cert_signature_final_pdf_parts(object $request): ?array
{
    $content = squuad_cert_signature_request_render_final($request);
    if (null === $content) {
        return null;
    }
    // Sin la hoja del certificado de firmas (ADR 0014): solo el diseño, sin la línea de la solicitud ni el certificado
    if (function_exists('squuad_cert_signature_sheet_for_request') && 'no' === squuad_cert_signature_sheet_for_request($request)) {
        return ['content' => (string) $content, 'line' => '', 'certificate' => ''];
    }
    $certificate = squuad_cert_signature_request_required_roles($request) || squuad_cert_signature_request_rows((int) $request->id)
        ? squuad_cert_signature_certificate_html($request, false) : '';

    return [
        'content' => (string) $content,
        'line' => sprintf(
            __('Signature request #%1$d, round %2$d · Content fingerprint (SHA-256): %3$s', 'wp-certificates'),
            (int) $request->id,
            (int) $request->round,
            $request->content_sha256
        ),
        'certificate' => $certificate,
    ];
}

/** Datos para que el navegador genere el PDF final (html, nombre del archivo, página). */
function squuad_cert_signature_final_pdf_payload(object $request): ?array
{
    global $wpdb;

    // B3 (ADR 0007 de Edusof): en el PDF final, los documentos de identidad completos
    $mask = function_exists('squuad_cert_id_document_mask_in_boxes') ? squuad_cert_id_document_mask_in_boxes() : false;
    if (function_exists('squuad_cert_id_document_mask_in_boxes')) {
        squuad_cert_id_document_mask_in_boxes(false);
    }
    $html = squuad_cert_signature_final_pdf_html($request);
    if (function_exists('squuad_cert_id_document_mask_in_boxes')) {
        squuad_cert_id_document_mask_in_boxes($mask);
    }
    if (null === $html) {
        return null;
    }
    // QR ya dibujados como imagen (diseño, ranura de los automáticos y hoja): el navegador no tiene que generarlos
    if (function_exists('squuad_cert_pdf_qr_inline')) {
        $html = squuad_cert_pdf_qr_inline($html);
    }
    $title = (string) $wpdb->get_var($wpdb->prepare("SELECT title FROM {$wpdb->prefix}documents_certificates WHERE id = %d", (int) ($request->document_certificate_id ?? 0)));
    $page = function_exists('squuad_cert_signature_pdf_page') ? squuad_cert_signature_pdf_page($request) : ['width_px' => 794, 'margin' => [0.3, 0.3, 0.3, 0.3], 'jspdf' => ['unit' => 'in', 'format' => 'a4', 'orientation' => 'portrait']];

    return [
        'html' => $html,
        'filename' => sanitize_file_name(strtolower('' !== $title ? $title : (string) $request->document_id)) . '.pdf',
        'sha' => (string) $request->content_sha256,
        'width' => (int) ($page['width_px'] ?? 794),
        'margin' => $page['margin'] ?? [0.3, 0.3, 0.3, 0.3],
        'jspdf' => $page['jspdf'] ?? ['unit' => 'in', 'format' => 'a4', 'orientation' => 'portrait'],
    ];
}
