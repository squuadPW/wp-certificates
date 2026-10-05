<?php
declare(strict_types=1);

/**
 * Certificación - Contrato para la ficha del titular (ADR 0004, sección 4.3; paso 5b). EduSystem ya no pinta firmas ni
 * llama al módulo: pide a wp-certificates estas piezas para la ficha del estudiante.
 *
 * - squuad_cert_render_subject_panel($type, $id, $ctx): documentos firmados del titular (firmas nuevas, con su
 *   integridad, y antiguas de users_signatures en solo lectura) y documentos emitidos para firma.
 * - squuad_cert_render_document_actions($type, $id, $document): acción de un documento de la institución en la ficha
 *   («Emitir para firma», «Configurar firmantes» o «Generar»).
 *
 * Mismo marcado que tenía la ficha de EduSystem (clases status-grid), para que se vea igual.
 */

if (!defined('ABSPATH')) exit;

/** Cuenta de WordPress del titular (para sus firmas nuevas como dueño del documento), según el proveedor. */
function squuad_cert_subject_account_id(string $type, int $id): int
{
    if (SQUUAD_CERT_SUBJECT_ACCOUNT === $type) {
        return $id;
    }
    $provider = function_exists('squuad_cert_subject_type') ? squuad_cert_subject_type($type) : null;
    foreach (($provider && !empty($provider['holder_slots'])) ? (array) call_user_func($provider['holder_slots'], $id, null) : [] as $slot) {
        if (!empty($slot['user_id'])) {
            return (int) $slot['user_id'];
        }
    }

    return 0;
}

/**
 * Documentos firmados del titular y documentos emitidos para firma. $ctx['legacy_user_ids']: cuentas cuyas firmas
 * antiguas (users_signatures) se muestran, solo lectura (las da quien llama: p. ej. el estudiante y su representante).
 */
function squuad_cert_render_subject_panel(string $type, int $id, array $ctx = []): string
{
    global $wpdb;

    $account = squuad_cert_subject_account_id($type, $id);
    $rows = [];
    if (squuad_cert_signature_evidence_enabled()) {
        // Firmas nuevas: documentos de la cuenta del titular y, en documentos emitidos, los de su ficha
        foreach ((array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}squuad_cert_signatures
             WHERE (subject_type = %s AND subject_id = %d) OR (subject_type = %s AND subject_id = %d) ORDER BY id DESC",
            SQUUAD_CERT_SUBJECT_ACCOUNT,
            $account,
            $type,
            $id
        )) as $row) {
            $rows[] = ['row' => $row, 'legacy' => false];
        }
    }
    $legacy_ids = array_values(array_filter(array_map('intval', (array) ($ctx['legacy_user_ids'] ?? []))));
    $legacy_table = $wpdb->prefix . 'users_signatures';
    if ($legacy_ids && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $legacy_table)) === $legacy_table) {
        $in = implode(',', array_fill(0, count($legacy_ids), '%s'));
        foreach ((array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$legacy_table} WHERE user_id IN ({$in}) ORDER BY id DESC",
            array_map('strval', $legacy_ids)
        )) as $row) {
            $rows[] = ['row' => $row, 'legacy' => true];
        }
    }

    ob_start();
    ?>
    <div>
        <h3><?= esc_html__('Signed documents', 'wp-certificates') ?></h3>
        <?php if ($rows) : ?>
            <div class="status-grid-wrapper">
                <div class="status-grid header-row">
                    <div class="status-item header"><strong><?= esc_html__('Document', 'wp-certificates') ?></strong></div>
                    <div class="status-item header"><strong><?= esc_html__('Signed by', 'wp-certificates') ?></strong></div>
                    <div class="status-item header"><strong><?= esc_html__('Date', 'wp-certificates') ?></strong></div>
                    <div class="status-item header"><strong><?= esc_html__('Integrity', 'wp-certificates') ?></strong></div>
                    <div class="status-item header"><strong><?= esc_html__('Context', 'wp-certificates') ?></strong></div>
                </div>
                <?php foreach ($rows as ['row' => $row, 'legacy' => $legacy]) :
                    $signer = get_userdata((int) $row->user_id);
                    $signed_by = $signer ? squuad_cert_account_name((int) $row->user_id) : '#' . (int) $row->user_id;
                    if (!$legacy && !empty($row->signer_role)) {
                        // Quien recibe el documento (su rol), un firmante por variable (p. ej. «Representante», ADR 0009 de Edusof) o del sistema
                        $signed_by .= ' (' . (squuad_cert_is_person_slot((string) $row->signer_role) ? squuad_cert_person_slot_label((string) $row->signer_role) : __('System signer', 'wp-certificates')) . ')';
                    }
                    $integrity = $legacy ? 'legacy' : squuad_cert_signature_verify_row($row);
                    $context = squuad_cert_signature_context_labels($row);
                    ?>
                    <div class="status-grid data-row">
                        <div class="status-item data" data-colname="<?= esc_attr__('Document', 'wp-certificates') ?>"><?= esc_html((string) $row->document_id) ?></div>
                        <div class="status-item data" data-colname="<?= esc_attr__('Signed by', 'wp-certificates') ?>"><?= esc_html($signed_by) ?></div>
                        <div class="status-item data" data-colname="<?= esc_attr__('Date', 'wp-certificates') ?>"><?= esc_html(mysql2date(get_option('date_format'), (string) $row->created_at)) ?></div>
                        <div class="status-item data" data-colname="<?= esc_attr__('Integrity', 'wp-certificates') ?>"><?= esc_html(squuad_cert_signature_integrity_label($integrity)) ?></div>
                        <div class="status-item data" data-colname="<?= esc_attr__('Context', 'wp-certificates') ?>"><?= $context ? esc_html(implode(' · ', $context)) : '—' ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else : ?>
            <div><strong><?= esc_html__('There are no signed files', 'wp-certificates') ?></strong></div>
        <?php endif; ?>
    </div>
    <?php
    // Documentos emitidos para firma desde la ficha (titular: la ficha del estudiante)
    if (SQUUAD_CERT_SUBJECT_STUDENT === $type) {
        squuad_cert_document_issued_section((object) ['id' => $id]);
    }

    return (string) ob_get_clean();
}

/**
 * Acción de un documento de la institución (managed) en la ficha: «Emitir para firma» si tiene firmantes del sistema
 * y el usuario puede emitir; aviso para configurar firmantes si exige firma y no los tiene; si no, «Generar».
 */
function squuad_cert_render_document_actions(string $type, int $id, object $document): string
{
    ob_start();
    if (squuad_cert_signature_issue_signers($document) && squuad_cert_document_issue_can($id)) : ?>
        <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" style="display:inline"
            onsubmit="return confirm(<?= esc_attr(wp_json_encode(__('The document will be generated now with the current data and frozen; the signers will sign it from their panel. Continue?', 'wp-certificates'))) ?>);">
            <input type="hidden" name="action" value="squuad_cert_issue_document">
            <input type="hidden" name="student_id" value="<?= (int) $id ?>">
            <input type="hidden" name="document_certificate_id" value="<?= (int) $document->id ?>">
            <?php wp_nonce_field('squuad_cert_issue_document_' . (int) $id . '_' . (int) $document->id); ?>
            <button type="submit" class="button button-success"><?= esc_html__('Issue for signature', 'wp-certificates') ?></button>
        </form>
    <?php elseif (!empty($document->signature_required) && squuad_cert_third_party_signatures_blocked()) : ?>
        <span class="description"><?= esc_html__('Requires signatures: configure its signers to issue it for signature.', 'wp-certificates') ?></span>
        <?php if (current_user_can(SQUUAD_CERT_MANAGE_SIGNING_POLICIES_CAP)) : ?>
            <a class="button" href="<?= esc_url(add_query_arg(['page' => 'add_admin_form_documents_content', 'section_tab' => 'document_detail', 'document_id' => (int) $document->id], admin_url('admin.php')) . '#edusystem-document-signers') ?>"><?= esc_html__('Configure signers', 'wp-certificates') ?></a>
        <?php endif; ?>
    <?php else : ?>
        <button type="button" data-documentcertificate="<?= (int) $document->id ?>" data-signaturerequired="<?= (int) $document->signature_required ?>"
            class="button download-document-certificate button-success"><?= esc_html__('Generate', 'wp-certificates') ?></button>
    <?php endif;

    return (string) ob_get_clean();
}
