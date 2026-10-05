<?php
declare(strict_types=1);

/**
 * Variables por firmante numeradas (ADR 0010 de Edusof, decisiones del dueño del 2026-10-05).
 *
 * Un documento puede tener varios firmantes de cualquier tipo. Cada uno tiene un número por el orden del panel
 * «Firmantes del documento»:
 * - F1 es la persona dueña del documento (quien lo recibe: el titular). Todas las filas de rol del panel son F1, porque
 *   cada persona con uno de esos roles recibe su propio documento. {{full_name_F1}} equivale a {{full_name}} (y lo mismo
 *   {{name_F1}}, {{last_name_F1}}, {{email_F1}}; {{id_document_F1}} equivale a {{holder_id_document}}).
 * - F2, F3… son los demás firmantes del panel en su orden (firmantes por variable y firmantes del sistema).
 *
 * Variables de cada firmante n: {{full_name_Fn}}, {{name_Fn}}, {{last_name_Fn}}, {{email_Fn}} (de su cuenta de
 * WordPress), {{id_document_Fn}} (su documento de identidad, si el sitio lo pide), {{charge_Fn}} (su cargo o puesto),
 * {{signature_Fn}} (su recuadro de firma) y el bloque {{#Fn}}…{{/Fn}} (solo si ese puesto está en la solicitud).
 *
 * Los valores se fijan al crear la solicitud (se sellan en su evento 'created', por puesto) y se congelan con la
 * primera firma, como el resto del contenido. Un puesto omitido deja sus variables vacías.
 *
 * Renumerado: al guardar la plantilla se recuerda a qué puesto apunta cada Fn (mapa Fn → puesto estable, en la opción
 * squuad_cert_fn_map_<documento>); al cambiar el panel, la plantilla se renumera sola para seguir apuntando a la misma
 * persona. Una plantilla sin variables _Fn no cambia nunca.
 */

if (!defined('ABSPATH')) exit;

/** Datos de cada firmante n: sufijo _Fn. */
const SQUUAD_CERT_FN_FIELDS = ['full_name', 'name', 'last_name', 'email', 'id_document', 'charge', 'signature'];

/** Variables por firmante: {{campo_Fn}} (grupo 1 y 2) o {{#Fn}}, {{^Fn}}, {{/Fn}} (grupo 3 y 4). n de 1 a 99. */
const SQUUAD_CERT_FN_TAG_PATTERN = '/\{\{(?:(full_name|name|last_name|email|id_document|charge|signature)_F([1-9][0-9]?)|([#^\/])F([1-9][0-9]?))\}\}/';

/** Opción con el mapa Fn → puesto de un documento (autoload no). */
const SQUUAD_CERT_FN_MAP_OPTION = 'squuad_cert_fn_map_';

/** Puesto de F1 en el mapa: la persona dueña del documento, tenga el rol que tenga. */
const SQUUAD_CERT_FN_HOLDER = 'holder';

/** Colores de los firmantes (se repiten a partir del 7.º): solo en el admin y en la vista previa, nunca en el documento. */
const SQUUAD_CERT_FN_COLORS = 6;

/** Colores del modo claro, para el borde de los recuadros en la vista previa en PDF (papel blanco). */
const SQUUAD_CERT_FN_PREVIEW_COLORS = [1 => '#1D4ED8', 2 => '#15803D', 3 => '#B45309', 4 => '#7E22CE', 5 => '#BE185D', 6 => '#0F766E'];

/** F1 equivale a las variables sin sufijo: variable _F1 => variable de siempre. */
function squuad_cert_fn_f1_aliases(): array
{
    return [
        'full_name_F1' => 'full_name',
        'name_F1' => 'name',
        'last_name_F1' => 'last_name',
        'email_F1' => 'email',
        'id_document_F1' => 'holder_id_document',
    ];
}

/** Índice de color (1 a SQUUAD_CERT_FN_COLORS) del firmante n. */
function squuad_cert_fn_color(int $n): int
{
    return (max(1, $n) - 1) % SQUUAD_CERT_FN_COLORS + 1;
}

/** ¿Usa la plantilla alguna variable por firmante numerada? */
function squuad_cert_fn_template_uses(string $template): bool
{
    return 1 === preg_match(SQUUAD_CERT_FN_TAG_PATTERN, $template);
}

/**
 * Números de firmante que usa una plantilla, sin repetir y en orden.
 *
 * @return int[]
 */
function squuad_cert_fn_template_numbers(string $template): array
{
    preg_match_all(SQUUAD_CERT_FN_TAG_PATTERN, $template, $found, PREG_SET_ORDER);
    $numbers = [];
    foreach ($found as $match) {
        $numbers[] = (int) ('' !== ($match[2] ?? '') ? $match[2] : ($match[4] ?? 0));
    }
    $numbers = array_values(array_unique(array_filter($numbers)));
    sort($numbers);

    return $numbers;
}

/** Texto completo de la plantilla de un documento (cabecera, contenido y pie). */
function squuad_cert_fn_document_template(object $document): string
{
    return (string) ($document->header ?? '') . (string) ($document->content ?? '') . (string) ($document->footer ?? '');
}

/** Puesto estable de una fila de la política: 'var:<variable>' o 'signer:<id>' ('' para los roles: son F1). */
function squuad_cert_fn_policy_slot_key(array $slot): string
{
    if ('var' === ($slot['slot_type'] ?? '')) {
        return 'var:' . (string) $slot['role'];
    }
    if ('signer' === ($slot['slot_type'] ?? '') && !empty($slot['signer_id'])) {
        return 'signer:' . (int) $slot['signer_id'];
    }

    return '';
}

/**
 * Numeración de una política: [n => puesto] para n ≥ 2, por su orden (los roles son F1 y no cuentan).
 *
 * @return array<int, string>
 */
function squuad_cert_fn_numbering(array $policy): array
{
    $numbering = [];
    $n = 1;
    foreach ((array) ($policy['slots'] ?? []) as $slot) {
        $key = squuad_cert_fn_policy_slot_key((array) $slot);
        if ('' === $key || in_array($key, $numbering, true)) {
            continue;
        }
        $numbering[++$n] = $key;
    }

    return $numbering;
}

/** ¿Es un puesto válido para el mapa? ('holder', 'role:<rol>', 'var:<variable>' o 'signer:<id>'). */
function squuad_cert_fn_slot_valid(string $slot): bool
{
    return SQUUAD_CERT_FN_HOLDER === $slot
        || 1 === preg_match('/^(?:role:[^\s{}]{1,60}|var:[a-z][a-z0-9_]{1,59}|signer:[1-9][0-9]{0,10})$/', $slot);
}

/**
 * Limpia un mapa (de la opción o del formulario): [n => puesto] con n de 2 a 99 y puestos válidos.
 *
 * @return array<int, string>
 */
function squuad_cert_fn_clean_map($map): array
{
    $clean = [];
    foreach ((array) $map as $n => $slot) {
        $n = (int) preg_replace('/^F/i', '', (string) $n);
        if ($n >= 2 && $n <= 99 && is_string($slot) && squuad_cert_fn_slot_valid($slot)) {
            $clean[$n] = $slot;
        }
    }
    ksort($clean);

    return $clean;
}

/** Mapa guardado de un documento, o null si nunca se guardó. */
function squuad_cert_fn_stored_map(int $document_id): ?array
{
    $stored = get_option(SQUUAD_CERT_FN_MAP_OPTION . $document_id, null);

    return is_array($stored) && isset($stored['map']) ? squuad_cert_fn_clean_map($stored['map']) : null;
}

/** Guarda el mapa de un documento (solo si cambia). */
function squuad_cert_fn_save_map(int $document_id, array $map): void
{
    $map = squuad_cert_fn_clean_map($map);
    if ($map === squuad_cert_fn_stored_map($document_id)) {
        return;
    }
    $value = ['v' => 1, 'map' => $map, 'updated_at_utc' => gmdate('Y-m-d H:i:s'), 'updated_by' => get_current_user_id()];
    if (false === get_option(SQUUAD_CERT_FN_MAP_OPTION . $document_id, false)) {
        add_option(SQUUAD_CERT_FN_MAP_OPTION . $document_id, $value, '', false);
    } else {
        update_option(SQUUAD_CERT_FN_MAP_OPTION . $document_id, $value, false);
    }
}

/**
 * Mapa vigente de un documento: el guardado o, si no hay (plantillas anteriores), la numeración de su política actual.
 *
 * @return array<int, string>
 */
function squuad_cert_fn_map(object $document): array
{
    $stored = squuad_cert_fn_stored_map((int) $document->id);
    if (null !== $stored) {
        return $stored;
    }

    return function_exists('squuad_cert_signing_policy') ? squuad_cert_fn_numbering(squuad_cert_signing_policy($document)) : [];
}

/**
 * Cómo pasar de un mapa anterior a la numeración nueva, para los números que usa la plantilla: [de número => a número,
 * mapa nuevo, cambios].
 * - Un número que apuntaba a un firmante que sigue en el panel pasa a su número nuevo (F2 → F3).
 * - Un número que apuntaba a un firmante que ya no firma pasa a un número libre al final (sigue sin nadie: sus
 *   variables quedan vacías), para que su número no apunte a otra persona.
 * - Un número que no apuntaba a nadie (la plantilla usaba F4 con 3 firmantes) se queda igual: si ahora hay un firmante
 *   con ese número, pasa a ser el suyo. Solo se mueve si otro número va a ocupar el suyo.
 *
 * @param array<int, string> $old Mapa anterior.
 * @param array<int, string> $numbering Numeración nueva.
 * @param int[] $used Números que usa la plantilla.
 * @return array{0: array<int, int>, 1: array<int, string>, 2: array<int, array{from: int, to: int, slot: string, removed: bool}>}
 */
function squuad_cert_fn_remap(array $old, array $numbering, array $used): array
{
    $by_slot = array_flip($numbering);
    $map = $numbering;
    $renumber = [];
    $changes = [];
    $used = array_values(array_filter(array_map('intval', $used), static fn(int $n): bool => $n >= 2));
    // 1. Los que apuntan a un firmante que sigue en el panel: su número nuevo
    foreach ($used as $n) {
        $slot = $old[$n] ?? '';
        if ('' !== $slot && isset($by_slot[$slot])) {
            $renumber[$n] = (int) $by_slot[$slot];
        }
    }
    // 2. Los que no apuntan a nadie se quedan con su número, salvo que otro vaya a ocuparlo
    foreach ($used as $n) {
        if (!isset($renumber[$n]) && '' === ($old[$n] ?? '') && !in_array($n, $renumber, true)) {
            $renumber[$n] = $n;
        }
    }
    // 3. Los demás (su firmante ya no firma, o su número lo ocupa otro): el primer número libre después de los firmantes
    $taken = array_flip(array_merge(array_keys($numbering), array_values($renumber)));
    foreach ($used as $n) {
        if (isset($renumber[$n])) {
            continue;
        }
        $to = count($numbering) + 2;
        while (isset($taken[$to]) && $to < 99) {
            $to++;
        }
        $taken[$to] = true;
        $renumber[$n] = $to;
        $slot = $old[$n] ?? '';
        if ('' !== $slot) {
            $map[$to] = $slot;
        }
    }
    foreach ($used as $n) {
        $slot = $old[$n] ?? '';
        $removed = '' !== $slot && !isset($by_slot[$slot]);
        if ($renumber[$n] !== $n || $removed) {
            $changes[$n] = ['from' => $n, 'to' => $renumber[$n], 'slot' => $slot, 'removed' => $removed];
        }
    }

    return [$renumber, squuad_cert_fn_clean_map($map), $changes];
}

/** Cambia los números de las variables _Fn de un texto (todos a la vez: F2→F3 y F3→F2 no se pisan). */
function squuad_cert_fn_renumber_text(string $text, array $renumber): string
{
    if (!$renumber || '' === $text) {
        return $text;
    }

    return (string) preg_replace_callback(SQUUAD_CERT_FN_TAG_PATTERN, static function (array $match) use ($renumber): string {
        if ('' !== ($match[1] ?? '')) {
            $n = (int) $match[2];
            return '{{' . $match[1] . '_F' . ($renumber[$n] ?? $n) . '}}';
        }
        $n = (int) $match[4];
        return '{{' . $match[3] . 'F' . ($renumber[$n] ?? $n) . '}}';
    }, $text);
}

/** Nombre de un puesto para los avisos y el panel «Datos» (p. ej. «Firmante por variable: Representante»). */
function squuad_cert_fn_slot_label(string $slot): string
{
    if (SQUUAD_CERT_FN_HOLDER === $slot || 0 === strpos($slot, 'role:')) {
        return __('Whoever receives the document', 'wp-certificates');
    }
    if (0 === strpos($slot, 'var:')) {
        /* translators: %s: name of the signer by variable, for example Parent/guardian */
        return sprintf(__('Signer by variable: %s', 'wp-certificates'), squuad_cert_signer_variable_label(substr($slot, 4)));
    }
    if (0 === strpos($slot, 'signer:')) {
        $signer = squuad_cert_signer_get((int) substr($slot, 7));
        $user = $signer ? get_userdata((int) $signer->user_id) : false;
        /* translators: %d: id of the system signer */
        return $user ? (string) $user->display_name : sprintf(__('System signer %d', 'wp-certificates'), (int) substr($slot, 7));
    }

    return $slot;
}

/**
 * Firmantes numerados de un documento para el admin: [n => ['slot', 'label', 'color']], con F1 primero. Los de la
 * política actual y, después, los números que la plantilla usa sin firmante (label vacío).
 */
function squuad_cert_fn_document_signers(object $document): array
{
    $policy = function_exists('squuad_cert_signing_policy') ? squuad_cert_signing_policy($document) : ['slots' => []];
    $signers = [1 => ['slot' => SQUUAD_CERT_FN_HOLDER, 'label' => squuad_cert_fn_slot_label(SQUUAD_CERT_FN_HOLDER), 'color' => 1]];
    foreach (squuad_cert_fn_numbering($policy) as $n => $slot) {
        $signers[$n] = ['slot' => $slot, 'label' => squuad_cert_fn_slot_label($slot), 'color' => squuad_cert_fn_color($n)];
    }

    return $signers;
}

/* ---------------------------------------------------------------------------------------------------------------
 * Renumerado al guardar el panel y la plantilla
 * ------------------------------------------------------------------------------------------------------------ */

/**
 * Después de guardar «Firmantes del documento»: si la plantilla usa variables _Fn y algún número cambió de persona, se
 * renumera la plantilla para seguir apuntando a la misma (y se guarda el mapa nuevo). $old_map: el mapa de antes de
 * guardar. Devuelve los avisos para quien guardó.
 *
 * @return string[]
 */
function squuad_cert_fn_after_policy_save(int $document_id, array $old_map): array
{
    global $wpdb;

    $document = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}documents_certificates WHERE id = %d", $document_id));
    if (!$document) {
        return [];
    }
    $numbering = squuad_cert_fn_numbering(squuad_cert_signing_policy($document));
    $template = squuad_cert_fn_document_template($document);
    if (!squuad_cert_fn_template_uses($template)) {
        // Sin variables _Fn la plantilla no cambia; el mapa sigue a la numeración del panel
        squuad_cert_fn_save_map($document_id, $numbering);
        return [];
    }
    [$renumber, $map, $changes] = squuad_cert_fn_remap($old_map, $numbering, squuad_cert_fn_template_numbers($template));
    $notices = squuad_cert_fn_change_notices($changes, $old_map);
    $moves = array_filter($renumber, static fn(int $to, int $from): bool => $to !== $from, ARRAY_FILTER_USE_BOTH);
    if ($moves) {
        $parts = [];
        foreach (['header', 'content', 'footer'] as $part) {
            $parts[$part] = squuad_cert_fn_renumber_text((string) $document->$part, $moves);
        }
        $wpdb->update($wpdb->prefix . 'documents_certificates', $parts, ['id' => $document_id]);
        squuad_cert_log(sprintf(
            'Documento %d: plantilla renumerada al guardar sus firmantes (usuario %d): %s',
            $document_id,
            get_current_user_id(),
            implode(', ', array_map(static fn(int $from, int $to): string => 'F' . $from . '→F' . $to, array_keys($moves), $moves))
        ), 'signing_policy');
    }
    squuad_cert_fn_save_map($document_id, $map);

    return $notices;
}

/**
 * Avisos de un renumerado: «La plantilla usaba F2 (Representante); ahora es F3: la plantilla se actualizó» o, si ese
 * firmante ya no firma, que sus variables quedarán vacías.
 *
 * @return string[]
 */
function squuad_cert_fn_change_notices(array $changes, array $old_map): array
{
    $notices = [];
    foreach ($changes as $change) {
        $label = '' !== $change['slot'] ? squuad_cert_fn_slot_label($change['slot']) : '';
        if ($change['removed']) {
            $notices[] = sprintf(
                /* translators: 1: old number, e.g. F2, 2: name of the signer, 3: new number */
                __('The template used %1$s (%2$s), who no longer signs this document: those variables were renamed to %3$s and will be empty. Remove them from the template or add that signer again.', 'wp-certificates'),
                'F' . $change['from'],
                $label,
                'F' . $change['to']
            );
        } elseif ('' === $change['slot']) {
            $notices[] = sprintf(
                /* translators: 1: old number, 2: new number */
                __('The template used %1$s, which had no signer: it was renamed to %2$s so that it does not point to another person.', 'wp-certificates'),
                'F' . $change['from'],
                'F' . $change['to']
            );
        } else {
            $notices[] = sprintf(
                /* translators: 1: old number, e.g. F2, 2: name of the signer, 3: new number */
                __('The template used %1$s (%2$s); now it is %3$s: the template was updated.', 'wp-certificates'),
                'F' . $change['from'],
                $label,
                'F' . $change['to']
            );
        }
    }

    return $notices;
}

/**
 * Al guardar la plantilla (filtro wpc_document_save_data de admin/documents.php): la plantilla enviada se escribió con
 * el mapa que tenía la página al abrirse (campo wpc_fn_map); si el panel cambió entretanto, se renumera a la numeración
 * actual. Sin variables _Fn no cambia nada.
 */
add_filter('wpc_document_save_data', 'squuad_cert_fn_document_save_data', 10, 2);
function squuad_cert_fn_document_save_data($data, $document_id)
{
    global $wpdb;

    $document_id = (int) $document_id;
    if (!is_array($data) || $document_id <= 0 || !function_exists('squuad_cert_signing_policy')) {
        return $data;
    }
    $template = (string) ($data['header'] ?? '') . (string) ($data['content'] ?? '') . (string) ($data['footer'] ?? '');
    if (!squuad_cert_fn_template_uses($template)) {
        return $data;
    }
    $document = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}documents_certificates WHERE id = %d", $document_id));
    if (!$document) {
        return $data;
    }
    $posted = isset($_POST['wpc_fn_map']) && is_string($_POST['wpc_fn_map']) ? json_decode(wp_unslash($_POST['wpc_fn_map']), true) : null; // phpcs:ignore -- nonce comprobado por quien guarda (wpc_save_document)
    $old = is_array($posted) ? squuad_cert_fn_clean_map($posted) : squuad_cert_fn_map($document);
    $numbering = squuad_cert_fn_numbering(squuad_cert_signing_policy($document));
    [$renumber, $map, $changes] = squuad_cert_fn_remap($old, $numbering, squuad_cert_fn_template_numbers($template));
    $moves = array_filter($renumber, static fn(int $to, int $from): bool => $to !== $from, ARRAY_FILTER_USE_BOTH);
    if ($moves) {
        foreach (['header', 'content', 'footer'] as $part) {
            if (isset($data[$part])) {
                $data[$part] = squuad_cert_fn_renumber_text((string) $data[$part], $moves);
            }
        }
        squuad_cert_fn_notice_add(squuad_cert_fn_change_notices($changes, $old));
        squuad_cert_log(sprintf(
            'Documento %d: plantilla renumerada al guardarla, el panel cambió mientras se editaba (usuario %d): %s',
            $document_id,
            get_current_user_id(),
            implode(', ', array_map(static fn(int $from, int $to): string => 'F' . $from . '→F' . $to, array_keys($moves), $moves))
        ), 'signing_policy');
    }
    $GLOBALS['squuad_cert_fn_pending_map'] = [$document_id, $map];

    return $data;
}

/** Después de guardar la plantilla: guarda el mapa (Fn → puesto) con el que quedó escrita. */
add_action('wpc_document_saved', 'squuad_cert_fn_document_saved', 10, 1);
function squuad_cert_fn_document_saved($document_id): void
{
    $pending = $GLOBALS['squuad_cert_fn_pending_map'] ?? null;
    if (is_array($pending) && (int) $pending[0] === (int) $document_id) {
        squuad_cert_fn_save_map((int) $document_id, $pending[1]);
    }
    unset($GLOBALS['squuad_cert_fn_pending_map']);
}

/**
 * Avisos de renumerado para la próxima carga de la página (por usuario, 5 minutos). $where: 'editor' (arriba del
 * editor, al guardar la plantilla) o 'panel' (en «Firmantes del documento», al guardar el panel).
 */
function squuad_cert_fn_notice_add(array $notices, string $where = 'editor'): void
{
    if (!$notices) {
        return;
    }
    $key = 'squuad_cert_fn_notice_' . sanitize_key($where) . '_' . get_current_user_id();
    $current = get_transient($key);
    set_transient($key, array_merge(is_array($current) ? $current : [], $notices), 300);
}

/** Lee y borra los avisos de renumerado pendientes ('editor' o 'panel'). */
function squuad_cert_fn_notice_take(string $where = 'editor'): array
{
    $key = 'squuad_cert_fn_notice_' . sanitize_key($where) . '_' . get_current_user_id();
    $notices = get_transient($key);
    delete_transient($key);

    return is_array($notices) ? array_map('strval', $notices) : [];
}

/**
 * Avisos sobre la plantilla con su panel actual (se calculan en cada carga del editor): números sin firmante y firmantes
 * que firman pero cuyo recuadro la plantilla no coloca. Solo si la plantilla usa variables _Fn.
 *
 * @return string[]
 */
function squuad_cert_fn_template_warnings(object $document): array
{
    $template = squuad_cert_fn_document_template($document);
    if (!squuad_cert_fn_template_uses($template) || !function_exists('squuad_cert_signing_policy')) {
        return [];
    }
    $policy = squuad_cert_signing_policy($document);
    $numbering = squuad_cert_fn_numbering($policy);
    $map = squuad_cert_fn_map($document);
    $by_slot = array_flip($numbering);
    $warnings = [];
    $count = count($numbering) + 1;
    foreach (squuad_cert_fn_template_numbers($template) as $n) {
        if ($n < 2) {
            continue;
        }
        $slot = $map[$n] ?? '';
        if ('' === $slot || !isset($by_slot[$slot]) || (int) $by_slot[$slot] !== $n) {
            $warnings[] = sprintf(
                /* translators: 1: number, e.g. F4, 2: how many signers the document has */
                _n('The template uses %1$s but this document has only %2$d signer: those variables will be empty.', 'The template uses %1$s but this document has only %2$d signers: those variables will be empty.', $count, 'wp-certificates'),
                'F' . $n,
                $count
            );
        }
    }
    $holder_signs = (bool) squuad_cert_signing_policy_roles($policy);
    $signs = $holder_signs ? [1 => SQUUAD_CERT_FN_HOLDER] + $numbering : $numbering;
    foreach ($signs as $n => $slot) {
        if (false !== strpos($template, '{{signature_F' . $n . '}}')) {
            continue;
        }
        // F1: también vale cualquier otra variable que coloque el recuadro de quien recibe el documento
        if (1 === $n && preg_match('/\{\{(?:signature_section|signature_student|signature_role_[a-z0-9_]+)\}\}/', $template)) {
            continue;
        }
        // Firmantes por variable y del sistema colocados con sus variables de siempre
        if (0 === strpos($slot, 'var:') && false !== strpos($template, '{{signature_var_' . substr($slot, 4) . '}}')) {
            continue;
        }
        if (0 === strpos($slot, 'signer:') && false !== strpos($template, '{{signature_signer_' . substr($slot, 7) . '}}')) {
            continue;
        }
        $warnings[] = sprintf(
            /* translators: 1: number, e.g. F2, 2: name of the signer, 3: variable, e.g. {{signature_F2}} */
            __('%1$s (%2$s) signs, but the template does not have %3$s: the signature goes to the signatures block at the end.', 'wp-certificates'),
            'F' . $n,
            squuad_cert_fn_slot_label($slot),
            '{{signature_F' . $n . '}}'
        );
    }

    return $warnings;
}

/** Avisos en el editor del documento (acción wpc_document_notices de la plantilla del editor). */
add_action('wpc_document_notices', 'squuad_cert_fn_print_document_notices');
function squuad_cert_fn_print_document_notices($document): void
{
    if (!is_object($document) || empty($document->id)) {
        return;
    }
    foreach (squuad_cert_fn_notice_take() as $notice) {
        echo '<div class="eds-notice eds-notice--info" role="status"><p>' . esc_html($notice) . '</p></div>';
    }
    foreach (squuad_cert_fn_template_warnings($document) as $warning) {
        echo '<div class="eds-notice eds-notice--warn"><p>' . esc_html($warning) . '</p></div>';
    }
}

/* ---------------------------------------------------------------------------------------------------------------
 * Valores en una solicitud
 * ------------------------------------------------------------------------------------------------------------ */

/**
 * Datos de la persona de una cuenta para sus variables numeradas (sin escapar): nombre completo («Apellidos, Nombres»,
 * como {{full_name}} de una cuenta), nombres, apellidos, correo y documento de identidad (solo si el sitio lo pide).
 */
function squuad_cert_fn_person(int $user_id): array
{
    $parts = function_exists('squuad_cert_wp_user_name_parts') ? squuad_cert_wp_user_name_parts($user_id) : null;
    if (!$parts) {
        return ['full_name' => '', 'name' => '', 'last_name' => '', 'email' => '', 'id_document' => ''];
    }

    return [
        'full_name' => squuad_cert_wp_user_label($user_id),
        'name' => $parts['first'],
        'last_name' => $parts['last'],
        'email' => $parts['email'],
        'id_document' => function_exists('squuad_cert_id_document_required') && squuad_cert_id_document_required()
            ? squuad_cert_id_document_identifier($user_id) : '',
    ];
}

/**
 * Lo que se sella por puesto en el evento 'created' de una solicitud cuyo documento usa variables _Fn: los datos de la
 * persona y el puesto en el idioma del sitio. Así el contenido y la evidencia coinciden aunque la cuenta cambie después.
 */
function squuad_cert_fn_created_person(array $slot, string $name): array
{
    $key = (string) $slot['slot_key'];
    $person = squuad_cert_fn_person((int) $slot['user_id']);
    $charge = (string) ($slot['charge'] ?? '');
    if ('' === $charge && squuad_cert_is_holder_slot($key)) {
        $charge = (string) squuad_cert_in_site_locale(static fn(): string => squuad_cert_holder_slot_label($key));
    }

    return ['person' => $person + ['charge' => $charge]];
}

/** Datos sellados por puesto en el evento 'created' de una solicitud: [puesto => person]. */
function squuad_cert_fn_request_snapshot(int $request_id): array
{
    global $wpdb;
    static $cache = [];

    if (!isset($cache[$request_id])) {
        $data = json_decode((string) $wpdb->get_var($wpdb->prepare(
            "SELECT data FROM {$wpdb->prefix}squuad_cert_events WHERE request_id = %d AND event_type = 'created' ORDER BY id ASC LIMIT 1",
            $request_id
        )), true);
        $snapshot = [];
        foreach ((array) ($data['signers'] ?? []) as $signer) {
            if (is_array($signer) && isset($signer['slot'], $signer['person']) && is_array($signer['person'])) {
                $snapshot[(string) $signer['slot']] = $signer['person'];
            }
        }
        $cache[$request_id] = $snapshot;
    }

    return $cache[$request_id];
}

/** Texto escapado para una variable: sin HTML y sin llaves (no puede abrir otra variable). */
function squuad_cert_fn_text(string $value): string
{
    return str_replace(['{', '}'], ['&#123;', '&#125;'], esc_html($value));
}

/**
 * Variables numeradas de una solicitud (se añaden a las de firma, squuad_cert_signature_signer_replacements()):
 * {{signature_F1}}, {{charge_F1}} y {{#F1}} de quien recibe el documento (sus datos personales van por las variables sin
 * sufijo) y, para cada número que usa la plantilla, las del firmante al que apunta su mapa. Los datos salen de lo
 * sellado en 'created' (o, en una solicitud anterior a estas variables, de la cuenta en ese momento).
 */
function squuad_cert_fn_replacements(object $request): array
{
    global $wpdb;

    $document_id = (int) ($request->document_certificate_id ?? 0);
    if (!$document_id && !empty($request->id)) {
        // Recién creada: el objeto de la solicitud es de antes de fijar su documento (squuad_cert_request_signers_fix())
        $document_id = (int) $wpdb->get_var($wpdb->prepare("SELECT document_certificate_id FROM {$wpdb->prefix}squuad_cert_requests WHERE id = %d", (int) $request->id));
    }
    $document = $document_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}documents_certificates WHERE id = %d", $document_id)) : null;
    if (!$document) {
        return [];
    }
    $template = squuad_cert_fn_document_template($document);
    if (!squuad_cert_fn_template_uses($template)) {
        return [];
    }
    $map = squuad_cert_fn_map($document);
    $signers = [];
    foreach (squuad_cert_request_signers($request) as $signer) {
        $signers[$signer['slot_key']] = $signer;
    }
    $snapshot = squuad_cert_fn_request_snapshot((int) $request->id);
    $holder = squuad_cert_request_holder_slot($request);
    $replacements = [];
    $set = static function (int $n, ?array $signer, array $person) use (&$replacements): void {
        $present = null !== $signer;
        $replacements['F' . $n] = ['value' => $present, 'wrap' => false];
        foreach (['full_name', 'name', 'last_name', 'email'] as $field) {
            $replacements[$field . '_F' . $n] = ['value' => $present ? squuad_cert_fn_text((string) ($person[$field] ?? '')) : '', 'wrap' => $present];
        }
        $replacements['id_document_F' . $n] = ['value' => $present ? squuad_cert_fn_text((string) ($person['id_document'] ?? '')) : '', 'wrap' => false];
        $replacements['charge_F' . $n] = ['value' => $present ? squuad_cert_fn_text((string) ($person['charge'] ?? '')) : '', 'wrap' => $present];
        $replacements['signature_F' . $n] = ['value' => $present ? squuad_cert_signer_slot_marker($signer['slot_key']) : '', 'wrap' => false];
    };

    foreach (squuad_cert_fn_template_numbers($template) as $n) {
        if (1 === $n) {
            // F1: quien recibe el documento. Nombre, correo y documento de identidad: alias de las variables sin sufijo
            $replacements['F1'] = ['value' => true, 'wrap' => false];
            $charge = '' !== $holder ? (string) ($snapshot[$holder]['charge'] ?? squuad_cert_holder_slot_label($holder)) : '';
            $replacements['charge_F1'] = ['value' => squuad_cert_fn_text($charge), 'wrap' => '' !== $charge];
            $replacements['signature_F1'] = ['value' => '' !== $holder ? squuad_cert_signer_slot_marker($holder) : '', 'wrap' => false];
            continue;
        }
        $slot = $map[$n] ?? '';
        $signer = '' !== $slot ? ($signers[$slot] ?? null) : null;
        if (null === $signer) {
            $set($n, null, []);
            continue;
        }
        $person = $snapshot[$slot] ?? (squuad_cert_fn_person((int) $signer['user_id']) + ['charge' => (string) $signer['charge']]);
        if ('' === (string) ($person['charge'] ?? '')) {
            $person['charge'] = (string) $signer['charge'];
        }
        $set($n, $signer, $person);
    }

    return $replacements;
}
