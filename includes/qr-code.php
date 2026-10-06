<?php
declare(strict_types=1);

/**
 * Generador de códigos QR propio (ADR 0013 de Edusof): sin JavaScript ni bibliotecas, para que el QR viaje como imagen
 * `data:` al motor de PDF (el JS de las plantillas no se ejecuta en el servidor).
 *
 * Norma ISO/IEC 18004: modo byte (UTF-8), corrección de errores M (~15 %), versiones 1 a 15 (hasta 412 bytes, de sobra
 * para una dirección de verificación), Reed-Solomon sobre GF(256) y la máscara de menor penalización.
 */

defined('ABSPATH') || exit;

/** Bloques por versión con corrección M: [codewords totales, codewords de corrección por bloque, [[bloques, datos por bloque], …]]. */
const SQUUAD_CERT_QR_M_BLOCKS = [
    1 => [26, 10, [[1, 16]]], 2 => [44, 16, [[1, 28]]], 3 => [70, 26, [[1, 44]]], 4 => [100, 18, [[2, 32]]],
    5 => [134, 24, [[2, 43]]], 6 => [172, 16, [[4, 27]]], 7 => [196, 18, [[4, 31]]], 8 => [242, 22, [[2, 38], [2, 39]]],
    9 => [292, 22, [[3, 36], [2, 37]]], 10 => [346, 26, [[4, 43], [1, 44]]], 11 => [404, 30, [[1, 50], [4, 51]]],
    12 => [466, 22, [[6, 36], [2, 37]]], 13 => [532, 22, [[8, 37], [1, 38]]], 14 => [581, 24, [[4, 40], [5, 41]]],
    15 => [655, 24, [[5, 41], [5, 42]]],
];

/** Centros de los patrones de alineación por versión. */
const SQUUAD_CERT_QR_ALIGN = [
    1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42],
    9 => [6, 26, 46], 10 => [6, 28, 50], 11 => [6, 30, 54], 12 => [6, 32, 58], 13 => [6, 34, 62], 14 => [6, 26, 46, 66],
    15 => [6, 26, 48, 70],
];

/**
 * Matriz del QR: lista de filas con 1 (oscuro) o 0 (claro), sin zona de silencio.
 *
 * @throws InvalidArgumentException si el texto no cabe en la versión 15.
 */
function squuad_cert_qr_matrix(string $text): array
{
    $data = array_values(unpack('C*', $text) ?: []);
    $len = count($data);
    $version = 0;
    foreach (SQUUAD_CERT_QR_M_BLOCKS as $v => [$total, $ec, $groups]) {
        $capacity_bits = ($total - $ec * array_sum(array_column($groups, 0))) * 8;
        if (4 + ($v < 10 ? 8 : 16) + 8 * $len <= $capacity_bits) {
            $version = $v;
            break;
        }
    }
    if (!$version) {
        throw new InvalidArgumentException('qr_demasiado_largo');
    }
    [$total, $ec_len, $groups] = SQUUAD_CERT_QR_M_BLOCKS[$version];
    $data_cw = $total - $ec_len * array_sum(array_column($groups, 0));

    // Cadena de bits: modo byte (0100), longitud, datos, terminador y relleno
    $bits = [];
    $push = static function (int $value, int $count) use (&$bits): void {
        for ($i = $count - 1; $i >= 0; $i--) {
            $bits[] = ($value >> $i) & 1;
        }
    };
    $push(0b0100, 4);
    $push($len, $version < 10 ? 8 : 16);
    foreach ($data as $byte) {
        $push($byte, 8);
    }
    $push(0, min(4, $data_cw * 8 - count($bits)));
    while (count($bits) % 8) {
        $bits[] = 0;
    }
    $codewords = [];
    foreach (array_chunk($bits, 8) as $chunk) {
        $codewords[] = (int) bindec(implode('', $chunk));
    }
    for ($pad = 0; count($codewords) < $data_cw; $pad ^= 1) {
        $codewords[] = $pad ? 0x11 : 0xEC;
    }

    // Bloques con su corrección Reed-Solomon, intercalados
    $blocks = [];
    $offset = 0;
    foreach ($groups as [$count, $size]) {
        for ($b = 0; $b < $count; $b++) {
            $chunk = array_slice($codewords, $offset, $size);
            $offset += $size;
            $blocks[] = [$chunk, squuad_cert_qr_rs($chunk, $ec_len)];
        }
    }
    $final = [];
    $max_data = max(array_map(static fn($b) => count($b[0]), $blocks));
    for ($i = 0; $i < $max_data; $i++) {
        foreach ($blocks as [$d]) {
            if (isset($d[$i])) {
                $final[] = $d[$i];
            }
        }
    }
    for ($i = 0; $i < $ec_len; $i++) {
        foreach ($blocks as [, $e]) {
            $final[] = $e[$i];
        }
    }

    // Patrones fijos
    $size = 17 + 4 * $version;
    $m = array_fill(0, $size, array_fill(0, $size, 0));
    $fixed = array_fill(0, $size, array_fill(0, $size, false));
    $set = static function (int $r, int $c, int $v) use (&$m, &$fixed): void {
        $m[$r][$c] = $v;
        $fixed[$r][$c] = true;
    };
    foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as [$r0, $c0]) {
        for ($r = -1; $r <= 7; $r++) {
            for ($c = -1; $c <= 7; $c++) {
                $rr = $r0 + $r;
                $cc = $c0 + $c;
                if ($rr < 0 || $cc < 0 || $rr >= $size || $cc >= $size) {
                    continue;
                }
                $dark = $r >= 0 && $r <= 6 && $c >= 0 && $c <= 6
                    && (0 === $r || 6 === $r || 0 === $c || 6 === $c || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4));
                $set($rr, $cc, $dark ? 1 : 0);
            }
        }
    }
    for ($i = 8; $i < $size - 8; $i++) {
        $set(6, $i, ($i + 1) % 2);
        $set($i, 6, ($i + 1) % 2);
    }
    $align = SQUUAD_CERT_QR_ALIGN[$version];
    $last = count($align) - 1;
    foreach ($align as $ai => $ar) {
        foreach ($align as $aj => $ac) {
            // Solo se omiten las tres esquinas que caen sobre los patrones de posición (las que cruzan la línea de
            // sincronización sí se dibujan)
            if ((0 === $ai && 0 === $aj) || (0 === $ai && $aj === $last) || ($ai === $last && 0 === $aj)) {
                continue;
            }
            for ($r = -2; $r <= 2; $r++) {
                for ($c = -2; $c <= 2; $c++) {
                    $set($ar + $r, $ac + $c, (2 === max(abs($r), abs($c)) || (0 === $r && 0 === $c)) ? 1 : 0);
                }
            }
        }
    }
    $set($size - 8, 8, 1); // módulo oscuro
    // Reservas del formato y de la versión (se escriben después)
    for ($i = 0; $i < 9; $i++) {
        if (!$fixed[8][$i]) $set(8, $i, 0);
        if (!$fixed[$i][8]) $set($i, 8, 0);
    }
    for ($i = 0; $i < 8; $i++) {
        $set(8, $size - 1 - $i, 0);
        $set($size - 1 - $i, 8, 0);
    }
    if ($version >= 7) {
        for ($i = 0; $i < 6; $i++) {
            for ($j = 0; $j < 3; $j++) {
                $set($i, $size - 11 + $j, 0);
                $set($size - 11 + $j, $i, 0);
            }
        }
    }

    // Datos en zigzag
    $data_bits = [];
    foreach ($final as $cw) {
        for ($i = 7; $i >= 0; $i--) {
            $data_bits[] = ($cw >> $i) & 1;
        }
    }
    $k = 0;
    $upward = true;
    for ($col = $size - 1; $col > 0; $col -= 2) {
        if (6 === $col) {
            $col--;
        }
        for ($n = 0; $n < $size; $n++) {
            $row = $upward ? $size - 1 - $n : $n;
            for ($j = 0; $j < 2; $j++) {
                $c = $col - $j;
                if (!$fixed[$row][$c]) {
                    $m[$row][$c] = $data_bits[$k++] ?? 0;
                }
            }
        }
        $upward = !$upward;
    }

    // Máscara con la menor penalización
    $best = null;
    $best_score = PHP_INT_MAX;
    for ($mask = 0; $mask < 8; $mask++) {
        $candidate = squuad_cert_qr_apply_mask($m, $fixed, $mask);
        squuad_cert_qr_write_info($candidate, $version, $mask);
        $score = squuad_cert_qr_penalty($candidate);
        if ($score < $best_score) {
            $best_score = $score;
            $best = $candidate;
        }
    }

    return $best;
}

/** Corrección Reed-Solomon (GF(256), polinomio 0x11D). */
function squuad_cert_qr_rs(array $data, int $ec_len): array
{
    static $exp = null, $log = null;
    if (null === $exp) {
        $exp = array_fill(0, 512, 0);
        $log = array_fill(0, 256, 0);
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            $exp[$i] = $exp[$i - 255];
        }
    }
    $mul = static fn(int $a, int $b): int => (0 === $a || 0 === $b) ? 0 : $exp[$log[$a] + $log[$b]];
    $gen = [1];
    for ($i = 0; $i < $ec_len; $i++) {
        $next = array_fill(0, count($gen) + 1, 0);
        foreach ($gen as $j => $g) {
            $next[$j] ^= $g;
            $next[$j + 1] ^= $mul($g, $exp[$i]);
        }
        $gen = $next;
    }
    $rem = array_merge($data, array_fill(0, $ec_len, 0));
    for ($i = 0, $n = count($data); $i < $n; $i++) {
        $coef = $rem[$i];
        if (0 !== $coef) {
            foreach ($gen as $j => $g) {
                $rem[$i + $j] ^= $mul($g, $coef);
            }
        }
    }

    return array_slice($rem, count($data));
}

function squuad_cert_qr_apply_mask(array $m, array $fixed, int $mask): array
{
    $size = count($m);
    for ($r = 0; $r < $size; $r++) {
        for ($c = 0; $c < $size; $c++) {
            if ($fixed[$r][$c]) {
                continue;
            }
            switch ($mask) {
                case 0: $flip = 0 === ($r + $c) % 2; break;
                case 1: $flip = 0 === $r % 2; break;
                case 2: $flip = 0 === $c % 3; break;
                case 3: $flip = 0 === ($r + $c) % 3; break;
                case 4: $flip = 0 === (intdiv($r, 2) + intdiv($c, 3)) % 2; break;
                case 5: $flip = 0 === ($r * $c) % 2 + ($r * $c) % 3; break;
                case 6: $flip = 0 === (($r * $c) % 2 + ($r * $c) % 3) % 2; break;
                default: $flip = 0 === (($r + $c) % 2 + ($r * $c) % 3) % 2;
            }
            if ($flip) {
                $m[$r][$c] ^= 1;
            }
        }
    }

    return $m;
}

/** Información de formato (corrección M + máscara, BCH 15,5) y de versión (BCH 18,6, versiones 7+). */
function squuad_cert_qr_write_info(array &$m, int $version, int $mask): void
{
    $size = count($m);
    $data = (0b00 << 3) | $mask; // M = 00
    $rem = $data;
    for ($i = 0; $i < 10; $i++) {
        $rem = ($rem << 1) ^ ((($rem >> 9) & 1) * 0x537);
    }
    $bits = (($data << 10) | ($rem & 0x3FF)) ^ 0x5412;
    $bit = static fn(int $i): int => ($bits >> $i) & 1;
    // [fila][columna]: primera copia alrededor del patrón de arriba a la izquierda, segunda repartida entre los otros dos
    for ($i = 0; $i <= 5; $i++) {
        $m[$i][8] = $bit($i);
    }
    $m[7][8] = $bit(6);
    $m[8][8] = $bit(7);
    $m[8][7] = $bit(8);
    for ($i = 9; $i < 15; $i++) {
        $m[8][14 - $i] = $bit($i);
    }
    for ($i = 0; $i < 8; $i++) {
        $m[8][$size - 1 - $i] = $bit($i);
    }
    for ($i = 8; $i < 15; $i++) {
        $m[$size - 15 + $i][8] = $bit($i);
    }
    $m[$size - 8][8] = 1;

    if ($version >= 7) {
        $rem = $version;
        for ($i = 0; $i < 12; $i++) {
            $rem = ($rem << 1) ^ ((($rem >> 11) & 1) * 0x1F25);
        }
        $vbits = ($version << 12) | ($rem & 0xFFF);
        for ($i = 0; $i < 18; $i++) {
            $b = ($vbits >> $i) & 1;
            $m[intdiv($i, 3)][$size - 11 + $i % 3] = $b;
            $m[$size - 11 + $i % 3][intdiv($i, 3)] = $b;
        }
    }
}

/** Penalización de la norma (reglas 1 a 4). */
function squuad_cert_qr_penalty(array $m): int
{
    $size = count($m);
    $score = 0;
    for ($pass = 0; $pass < 2; $pass++) {
        for ($i = 0; $i < $size; $i++) {
            $run = 1;
            $line = [];
            for ($j = 0; $j < $size; $j++) {
                $line[] = $pass ? $m[$j][$i] : $m[$i][$j];
            }
            for ($j = 1; $j < $size; $j++) {
                if ($line[$j] === $line[$j - 1]) {
                    $run++;
                    if (5 === $run) $score += 3;
                    elseif ($run > 5) $score++;
                } else {
                    $run = 1;
                }
            }
            $s = implode('', $line);
            $score += 40 * (substr_count($s, '10111010000') + substr_count($s, '00001011101'));
        }
    }
    $dark = 0;
    for ($r = 0; $r < $size; $r++) {
        for ($c = 0; $c < $size; $c++) {
            $dark += $m[$r][$c];
            if ($r < $size - 1 && $c < $size - 1) {
                $sum = $m[$r][$c] + $m[$r + 1][$c] + $m[$r][$c + 1] + $m[$r + 1][$c + 1];
                if (0 === $sum || 4 === $sum) {
                    $score += 3;
                }
            }
        }
    }
    $score += 10 * intdiv(abs(intdiv($dark * 100, $size * $size) - 50), 5);

    return $score;
}

/** QR como SVG (zona de silencio de 4 módulos), escalable sin perder nitidez en el PDF. */
function squuad_cert_qr_svg(string $text, string $dark = '#000'): string
{
    $m = squuad_cert_qr_matrix($text);
    $size = count($m);
    $q = 4;
    $path = '';
    foreach ($m as $r => $row) {
        foreach ($row as $c => $v) {
            if ($v) {
                $path .= 'M' . ($c + $q) . ' ' . ($r + $q) . 'h1v1h-1z';
            }
        }
    }
    $full = $size + 2 * $q;

    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $full . ' ' . $full . '" shape-rendering="crispEdges">'
        . '<rect width="100%" height="100%" fill="#fff"/><path fill="' . esc_attr($dark) . '" d="' . $path . '"/></svg>';
}

/** QR como imagen `data:` para incrustar en el HTML que se manda al motor de PDF. */
function squuad_cert_qr_data_uri(string $text): string
{
    return 'data:image/svg+xml;base64,' . base64_encode(squuad_cert_qr_svg($text));
}
