<?php
declare(strict_types=1);

/**
 * Libro de registro (tomo y folio), ADR 0004 de EduSystem, decisión del dueño del 2026-10-01.
 *
 * - El texto de la línea lo decide wp-certificates: la "descripción de la línea" de cada documento
 *   (documents_certificates.book_line_description), con las mismas variables que el documento, enviada como texto
 *   plano. Si una variable falla o ya no existe, no se reserva la línea y el documento no se emite.
 * - La conexión con el servidor de libros no es de wp-certificates: la pide por filtros y responde quien la tenga
 *   (hoy EduSystem, con su cliente de la API de EduSof):
 *     squuad_cert_books              (array $books)                                   => [['id' => int, 'title' => string], …]
 *     squuad_cert_book_reserve_line  (null, int $book_id, array $line)                => ['line_id', 'tomo', 'folio', 'line_number', 'raw'] | WP_Error
 *     squuad_cert_book_void_line     (null, int $line_id, string $reason)             => true | WP_Error
 *   $line = ['text', 'subject_type', 'subject_id', 'document_id']. Sin nadie que responda, un documento con libro no se emite.
 */

defined('ABSPATH') || exit;

/** Largo máximo del texto de la línea (provisional, hasta confirmar el del servidor de libros). */
const SQUUAD_CERT_BOOK_LINE_MAX = 1000;

/** Variables que no se pueden usar en la descripción: son el resultado de reservar la línea. */
const SQUUAD_CERT_BOOK_LINE_FORBIDDEN = ['tomo', 'folio', 'tomo_folio', 'qrcode'];

/** Texto por defecto si el documento no tiene descripción (equivalente al que se enviaba antes). */
function squuad_cert_book_line_default(): string
{
    return 'Certificado emitido: {{student_name}} ({{email}}) | Documento: {{document_name}} | Programa: {{program}} | Fecha: {{today}}';
}

/** Libros disponibles para el selector del documento: [['id' => int, 'title' => string], …]. */
function squuad_cert_books(): array
{
    $books = [];
    foreach ((array) apply_filters('squuad_cert_books', []) as $book) {
        $book = (array) $book;
        $id = (int) ($book['id'] ?? 0);
        if ($id > 0) {
            $books[] = ['id' => $id, 'title' => (string) ($book['title'] ?? $book['name'] ?? $id)];
        }
    }

    return $books;
}

/** Variables prohibidas que usa una descripción (para avisar al guardar el documento). */
function squuad_cert_book_line_forbidden_used(string $description): array
{
    preg_match_all('/\{\{[#^\/]?(\w+)\}\}/', $description, $found);

    return array_values(array_intersect(SQUUAD_CERT_BOOK_LINE_FORBIDDEN, array_unique($found[1])));
}

/**
 * Texto de la línea para un titular: la descripción del documento (o el texto por defecto) con sus variables,
 * como texto plano. failed: variables sin valor (método que falla, eliminada o prohibida).
 *
 * @return array{text: string, failed: array<string, string>}
 */
function squuad_cert_book_line_text(object $document, int $subject_id, array $ctx = []): array
{
    $template = trim((string) ($document->book_line_description ?? ''));
    $template = '' !== $template ? $template : squuad_cert_book_line_default();

    $failed = [];
    foreach (squuad_cert_book_line_forbidden_used($template) as $key) {
        $failed[$key] = 'forbidden';
    }
    $template = (string) preg_replace('/\{\{[#^\/]?(?:' . implode('|', SQUUAD_CERT_BOOK_LINE_FORBIDDEN) . ')\}\}/', '', $template);

    $resolved = squuad_cert_template_replacements($template, $subject_id, ['document' => $document] + $ctx);
    $failed += $resolved['failed'];
    $text = (string) squuad_cert_process_template($template, $resolved['replacements']);

    // Lo que siga como {{clave}} es una variable que ya no existe (ni general ni con método)
    if (preg_match_all('/\{\{[#^\/]?(\w+)\}\}/', $text, $left)) {
        foreach (array_unique($left[1]) as $key) {
            $failed[$key] = 'unknown';
        }
    }

    // Texto plano: sin HTML ni entidades y con los espacios unidos
    $text = html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim((string) preg_replace('/\s+/u', ' ', $text));

    return ['text' => $text, 'failed' => $failed];
}

/**
 * Reserva la línea del libro del documento para un titular. Devuelve ['line_id', 'tomo', 'folio', 'line_number',
 * 'raw'] o WP_Error (variables sin valor, texto vacío o demasiado largo, sin servidor de libros o error del servidor).
 * En caso de error no se llama al servidor o el servidor no asignó nada: el documento no se debe emitir.
 */
function squuad_cert_book_reserve_line(object $document, string $subject_type, int $subject_id, array $ctx = [])
{
    $book_id = (int) ($document->book ?? 0);
    $fail = static function (string $code, string $message, string $log) use ($document, $subject_id): WP_Error {
        squuad_cert_log(sprintf('Tomo y folio no reservados (documento %d, titular %d): %s', (int) ($document->id ?? 0), $subject_id, $log), 'book_line_not_reserved');

        return new WP_Error($code, $message);
    };

    if ($book_id <= 0) {
        return $fail('squuad_cert_book_none', __('The document has no registry book.', 'wp-certificates'), 'sin libro');
    }
    $line = squuad_cert_book_line_text($document, $subject_id, $ctx);
    if ($line['failed']) {
        $list = implode(', ', array_map(static fn($k, $why) => $k . ' (' . $why . ')', array_keys($line['failed']), $line['failed']));
        return $fail(
            'squuad_cert_book_variables',
            sprintf(__('The registry book line could not be filled (variables without value: %s). The document was not issued.', 'wp-certificates'), implode(', ', array_keys($line['failed']))),
            'variables sin valor ' . $list
        );
    }
    if ('' === $line['text']) {
        return $fail('squuad_cert_book_empty', __('The registry book line is empty. The document was not issued.', 'wp-certificates'), 'texto vacío');
    }
    if (mb_strlen($line['text']) > SQUUAD_CERT_BOOK_LINE_MAX) {
        return $fail(
            'squuad_cert_book_too_long',
            sprintf(__('The registry book line is longer than %d characters. The document was not issued.', 'wp-certificates'), SQUUAD_CERT_BOOK_LINE_MAX),
            'texto de ' . mb_strlen($line['text']) . ' caracteres'
        );
    }
    if (!has_filter('squuad_cert_book_reserve_line')) {
        return $fail('squuad_cert_book_unavailable', __('The registry book is not available on this site. The document was not issued.', 'wp-certificates'), 'sin servidor de libros');
    }

    $result = apply_filters('squuad_cert_book_reserve_line', null, $book_id, [
        'text' => $line['text'],
        'subject_type' => $subject_type,
        'subject_id' => $subject_id,
        'document_id' => (int) ($document->id ?? 0),
    ]);
    if (is_wp_error($result)) {
        return $fail($result->get_error_code(), $result->get_error_message(), 'error del servidor: ' . $result->get_error_message());
    }
    $result = is_array($result) ? $result : [];
    if (empty($result['line_id']) || empty($result['folio'])) {
        return $fail('squuad_cert_book_failed', __('The registry book did not assign a volume and folio (connection or book error). The document was not issued; try again.', 'wp-certificates'), 'el servidor no asignó tomo y folio');
    }
    squuad_cert_log(sprintf('Tomo %d, folio %d (línea %d del libro %d) reservados para el documento %d del titular %d', (int) $result['tomo'], (int) $result['folio'], (int) $result['line_id'], $book_id, (int) ($document->id ?? 0), $subject_id), 'book_line_reserved');

    return [
        'line_id' => (int) $result['line_id'],
        'tomo' => (int) ($result['tomo'] ?? $book_id),
        'folio' => (int) $result['folio'],
        'line_number' => (int) ($result['line_number'] ?? 0),
        'raw' => $result['raw'] ?? null,
    ];
}

/** Anula una línea del libro, con motivo. Devuelve true o WP_Error. */
function squuad_cert_book_void_line(int $line_id, string $reason)
{
    if (!has_filter('squuad_cert_book_void_line')) {
        return new WP_Error('squuad_cert_book_unavailable', __('The registry book is not available on this site.', 'wp-certificates'));
    }
    $result = apply_filters('squuad_cert_book_void_line', null, $line_id, $reason);
    if (true !== $result) {
        return is_wp_error($result) ? $result : new WP_Error('squuad_cert_book_failed', __('The registry book could not void the line.', 'wp-certificates'));
    }
    squuad_cert_log(sprintf('Línea %d del libro anulada por el usuario %d: %s', $line_id, get_current_user_id(), $reason), 'book_line_voided');

    return true;
}
