<?php
/**
 * Panel "Firmantes del documento" (admin/document-signing.php). Variables: $document, $policy, $positions,
 * $signers, $notice, $inbox, $template_text.
 */
if (!defined('ABSPATH')) exit;

// Variable de plantilla: se copia con un clic; en un tono más claro si la plantilla ya la usa (se actualiza en vivo)
$chip = static function (string $variable) use ($template_text): string {
    $tag = '{{' . $variable . '}}';
    return '<code class="edusig-var' . (false !== strpos($template_text, $tag) ? ' is-used' : '') . '" data-var="' . esc_attr($tag) . '" title="'
        . esc_attr__('Click to copy', 'edusystem') . '">' . esc_html($tag) . '</code>';
};
$row = static function (string $key, string $label, string $detail, bool $checked, int $position, array $variables = [], string $fallback = '') use ($chip): void {
    ?>
    <tr>
        <td style="width:36px"><input type="checkbox" name="slots[<?= esc_attr($key) ?>][enabled]" value="1" id="edusig-slot-<?= esc_attr(sanitize_html_class($key)) ?>" <?= checked($checked, true, false) ?>></td>
        <td><label for="edusig-slot-<?= esc_attr(sanitize_html_class($key)) ?>"><strong><?= esc_html($label) ?></strong></label><?= $detail ? '<br><span class="description">' . esc_html($detail) . '</span>' : '' ?></td>
        <td class="edusig-vars" data-fallback="<?= esc_attr($fallback) ?>">
            <?= implode(' ', array_map($chip, $variables)) // phpcs:ignore -- marcado escapado en $chip ?>
            <br><span class="edusig-var-status description"></span>
        </td>
        <td style="width:110px"><input type="number" min="1" max="99" name="slots[<?= esc_attr($key) ?>][position]" value="<?= (int) $position ?>" style="width:70px" aria-label="<?= esc_attr__('Order', 'edusystem') ?>"></td>
    </tr>
    <?php
};
$next = count($positions) + 1;
?>
<div id="edusystem-document-signers" class="postbox" style="margin-top:20px;display:none">
    <div class="inside">
        <h2 style="padding-left:0"><?= esc_html__('Document signers', 'edusystem') ?></h2>
        <p class="description" style="max-width:820px"><?= esc_html__('Who signs this document and in which order. The student signs first; then the registered signers of the system. Changes apply to new signature requests; requests already in progress keep their signers.', 'edusystem') ?></p>

        <?php if ('automatic' !== $document->type) : ?>
            <p class="description" style="max-width:820px"><strong><?= esc_html__('Issued document:', 'edusystem') ?></strong> <?= esc_html__('only the system signers sign it (the student does not). With at least one signer, "Generate" in the student file becomes "Issue for signature".', 'edusystem') ?></p>
        <?php endif; ?>
        <?php if ($notice) : ?>
            <div class="notice <?= $notice['ok'] ? 'notice-success' : 'notice-error' ?> inline"><p><?= esc_html($notice['message']) ?></p></div>
        <?php endif; ?>
        <?php if (!$policy['policy_id']) : ?>
            <p><em><?= esc_html('automatic' === $document->type
                ? __('Not configured yet: by default the student signs this document.', 'edusystem')
                : __('Not configured yet: by default this document does not ask for user signatures.', 'edusystem')) ?></em></p>
        <?php endif; ?>

        <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
            <input type="hidden" name="action" value="squuad_cert_save_signing_policy">
            <input type="hidden" name="document_certificate_id" value="<?= (int) $document->id ?>">
            <?php wp_nonce_field('squuad_cert_save_signing_policy'); ?>
            <p><label><input type="checkbox" name="requires_signatures" value="1" <?= checked($policy['requires_signatures'], true, false) ?>> <strong><?= esc_html__('This document asks for signatures', 'edusystem') ?></strong></label></p>

            <table class="widefat striped" style="max-width:820px">
                <thead><tr><th></th><th><?= esc_html__('Signer', 'edusystem') ?></th><th><?= esc_html__('Variables for the template', 'edusystem') ?></th><th><?= esc_html__('Order', 'edusystem') ?></th></tr></thead>
                <tbody>
                    <?php
                    $in_section = __('Not in the template: it goes in {{signature_section}}.', 'edusystem');
                    $row('student:0', __('Student', 'edusystem'), __('Signs from their own account.', 'edusystem'), isset($positions['student:0']), $positions['student:0'] ?? 1, ['signature_student'], $in_section);
                    foreach ($signers as $signer) {
                        $key = 'signer:' . (int) $signer->id;
                        $detail = trim((string) $signer->charge . ' · ' . (string) $signer->user_email, ' ·');
                        if ('invited' === $signer->status) {
                            $detail .= ' — ' . __('has not registered their signature yet', 'edusystem');
                        }
                        $id = (int) $signer->id;
                        $row($key, (string) $signer->display_name, $detail, isset($positions[$key]), $positions[$key] ?? $next++,
                            ['signature_signer_' . $id, 'signer_name_' . $id, 'signer_charge_' . $id],
                            __('Not in the template: it goes in the signatures block at the end.', 'edusystem'));
                    }
                    ?>
                </tbody>
            </table>
            <div class="edusig-help" style="max-width:820px;margin-top:10px;padding:10px 12px;background:#f6f7f7;border:1px solid #dcdcde">
                <p style="margin-top:0"><strong><?= esc_html__('How to place the signatures in the template', 'edusystem') ?></strong></p>
                <p><?= $chip('signature_section') // phpcs:ignore ?> <?= esc_html__('The signature of the student when it is not placed separately (as before).', 'edusystem') ?></p>
                <p><?= esc_html__('Each signer separately: use the variables of their row. Signers not placed in the template go in {{signature_section}} (the student) or in a signatures block at the end (system signers).', 'edusystem') ?></p>
                <p style="margin-bottom:4px"><strong><?= esc_html__('Template rules', 'edusystem') ?></strong> — <?= esc_html__('the text between the marks is shown only if the rule is met; with ^ , only if it is not:', 'edusystem') ?></p>
                <ul style="list-style:disc;margin:0 0 0 20px">
                    <li><code>{{#requires_student_signature}}</code> … <code>{{/requires_student_signature}}</code> — <?= esc_html__('the student signs this document', 'edusystem') ?></li>
                </ul>
                <p class="description"><?= esc_html__('The parent no longer signs: in old templates {{signature_parent}} is empty and {{#requires_parent_signature}} and {{#student_is_own_parent}} are never met.', 'edusystem') ?></p>
                <?php // Variable fija del sistema (definida en el código, no en la tabla variables_document): siempre disponible ?>
                <p style="margin-bottom:4px"><strong><?= esc_html__('PDF layout', 'edusystem') ?></strong></p>
                <p style="margin:0"><?= $chip('page_break') // phpcs:ignore ?> <?= esc_html__('Page break: what follows starts on a new page. In automatic documents, lines, paragraphs and table rows are never cut between pages; use it to decide where a section starts.', 'edusystem') ?></p>
            </div>
            <?php if (!$signers) : ?>
                <p class="description"><?= esc_html__('There are no registered signers yet. Invite them from Certification > Users and signatures.', 'edusystem') ?></p>
            <?php elseif (!$inbox) : ?>
                <p class="description"><?= esc_html__('Registered signers are saved in the configuration and will be required once their "Documents to sign" panel is active.', 'edusystem') ?></p>
            <?php endif; ?>
            <p><button type="submit" class="button button-primary"><?= esc_html__('Save document signers', 'edusystem') ?></button></p>
        </form>
    </div>
</div>
<style>
    #edusystem-document-signers .edusig-var { background: #f0f6fc; border: 1px solid #c5d9ed; border-radius: 3px; padding: 1px 6px; cursor: pointer; display: inline-block; margin: 2px 0; }
    #edusystem-document-signers .edusig-var.is-used { opacity: .45; }
    #edusystem-document-signers .edusig-var.copied { background: #d7f0dd; }
</style>
<script>
    // El panel se coloca debajo del formulario del documento (wp-certificates), con su propio botón de guardar
    document.addEventListener("DOMContentLoaded", function () {
        const panel = document.getElementById("edusystem-document-signers");
        const anchor = document.getElementById("metabox") || document.querySelector(".wrap");
        if (panel && anchor) {
            anchor.parentNode.insertBefore(panel, anchor.nextSibling);
            panel.style.display = "";
        }
        if (!panel) {
            return;
        }
        const texts = {
            used: <?= wp_json_encode(__('✓ In the template', 'edusystem')) ?>
        };
        // Texto actual de la plantilla (cabecera, contenido y pie), desde el editor visual o el de texto
        const templateText = function () {
            return ["header", "content", "footer"].map(function (id) {
                const editor = window.tinymce && window.tinymce.get(id);
                if (editor && !editor.isHidden()) {
                    return editor.getContent();
                }
                const area = document.getElementById(id);
                return area ? area.value : "";
            }).join("");
        };
        // Variables en uso: tono más claro y estado por firmante
        const refresh = function () {
            const text = templateText();
            panel.querySelectorAll(".edusig-var").forEach(function (chip) {
                chip.classList.toggle("is-used", text.indexOf(chip.dataset.var) !== -1);
            });
            panel.querySelectorAll(".edusig-vars").forEach(function (cell) {
                const first = cell.querySelector(".edusig-var");
                const status = cell.querySelector(".edusig-var-status");
                if (first && status) {
                    status.textContent = first.classList.contains("is-used") ? texts.used : cell.dataset.fallback;
                }
            });
        };
        panel.querySelectorAll(".edusig-var").forEach(function (chip) {
            chip.addEventListener("click", function () {
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(chip.dataset.var);
                }
                chip.classList.add("copied");
                setTimeout(function () { chip.classList.remove("copied"); }, 800);
            });
        });
        ["header", "content", "footer"].forEach(function (id) {
            const area = document.getElementById(id);
            if (area) {
                area.addEventListener("input", refresh);
            }
        });
        if (window.tinymce) {
            window.tinymce.on("AddEditor", function (event) {
                event.editor.on("keyup change SetContent", refresh);
            });
            window.tinymce.editors.forEach(function (editor) {
                editor.on("keyup change SetContent", refresh);
            });
        }
        refresh();
    });
</script>
