<?php
/**
 * Panel "Firmantes del documento" (admin/document-signing.php). Variables: $document, $policy, $positions,
 * $signing_roles (roles activos: clave => nombre), $inactive_roles, $signers, $notice, $inbox, $template_text y
 * $signer_variables (variable => available, label, in_template; ADR 0009 de Edusof).
 * Arriba, una fila por rol activo en Certificación > Signing roles; después, los firmantes por variable y los firmantes
 * del sistema.
 */
if (!defined('ABSPATH')) exit;

// Variable de plantilla: se copia con un clic; en un tono más claro si la plantilla ya la usa (se actualiza en vivo)
$chip = static function (string $variable) use ($template_text): string {
    $tag = '{{' . $variable . '}}';
    return '<code class="edusig-var' . (false !== strpos($template_text, $tag) ? ' is-used' : '') . '" data-var="' . esc_attr($tag) . '" title="'
        . esc_attr__('Click to copy', 'wp-certificates') . '">' . esc_html($tag) . '</code>';
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
        <td style="width:110px"><input type="number" min="1" max="99" name="slots[<?= esc_attr($key) ?>][position]" value="<?= (int) $position ?>" style="width:70px" aria-label="<?= esc_attr__('Order', 'wp-certificates') ?>"></td>
    </tr>
    <?php
};
$next = count($positions) + 1;
// Diseño Edusof: este es el único interruptor de firmas del documento. En los gestionados también refleja el «requerirá
// firmas» de siempre (signature_required), que se actualiza al guardar aquí (admin/document-signing.php)
$eds = function_exists('wpc_eds_documents_enabled') && wpc_eds_documents_enabled();
$requires_checked = $eds
    ? ($policy['requires_signatures'] || ('automatic' !== $document->type && !empty($document->signature_required)))
    : $policy['requires_signatures'];
?>
<div id="edusystem-document-signers" class="<?= $eds ? 'eds-card wpc-eds-signers' : 'postbox' ?>" style="margin-top:20px;display:none">
    <div class="inside">
        <h2 style="padding-left:0"><?= esc_html__('Document signers', 'wp-certificates') ?></h2>
        <p class="description" style="max-width:820px"><?= esc_html__('Who signs this document and in which order. First the roles: every user with a marked role receives their own document and signs it from their own account. Then the registered signers of the system sign every one of those documents. The document is valid when all the signatures are done. Changes apply to new signature requests; requests already in progress keep their signers.', 'wp-certificates') ?></p>

        <?php if ('automatic' !== $document->type) : ?>
            <p class="description" style="max-width:820px"><?php if (wpc_edusystem_active()) { ?><strong><?= esc_html__('Issued document:', 'wp-certificates') ?></strong> <?= esc_html__('only the system signers sign it (the roles do not). With at least one signer, "Generate" in the student file becomes "Issue for signature".', 'wp-certificates') ?><?php } else { ?><?= esc_html__('If the school office issues it: only the system signers sign it (the roles do not).', 'wp-certificates') ?><?php } ?></p>
        <?php endif; ?>
        <?php if ($notice) : ?>
            <div class="notice <?= $notice['ok'] ? 'notice-success' : 'notice-error' ?> inline"><p><?= esc_html($notice['message']) ?></p></div>
        <?php endif; ?>
        <?php if (!$policy['policy_id']) : ?>
            <p><em><?= esc_html('automatic' === $document->type
                ? (wpc_edusystem_active()
                    ? __('Not configured yet: by default the users with the student role sign this document, if that role is active in "Signing roles".', 'wp-certificates')
                    : __('Not configured yet: choose below which roles and signers sign this document.', 'wp-certificates'))
                : __('Not configured yet: by default this document does not ask for user signatures.', 'wp-certificates')) ?></em></p>
        <?php endif; ?>

        <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
            <input type="hidden" name="action" value="squuad_cert_save_signing_policy">
            <input type="hidden" name="document_certificate_id" value="<?= (int) $document->id ?>">
            <?php wp_nonce_field('squuad_cert_save_signing_policy'); ?>
            <?php if ($eds) : ?>
                <input type="hidden" name="wpc_eds_sync_signature" value="1">
            <?php endif; ?>
            <p><label><input type="checkbox" name="requires_signatures" value="1" id="edusig-requires-signatures" <?= checked($requires_checked, true, false) ?>> <strong><?= esc_html__('This document asks for signatures', 'wp-certificates') ?></strong></label></p>
            <?php if ($eds) : ?>
                <p class="description" style="max-width:820px"><?= esc_html__('This is the only signature switch of the document. Save it with «Save document signers»: the document button does not save it.', 'wp-certificates') ?></p>
            <?php endif; ?>

            <table class="widefat striped" style="max-width:820px">
                <thead><tr><th></th><th><?= esc_html__('Signer', 'wp-certificates') ?></th><th><?= esc_html__('Variables for the template', 'wp-certificates') ?></th><th><?= esc_html__('Order', 'wp-certificates') ?></th></tr></thead>
                <tbody>
                    <?php
                    $in_section = __('Not in the template: it goes in {{signature_section}}.', 'wp-certificates');
                    $role_position = 1;
                    foreach ($signing_roles as $role_key => $role_name) {
                        $key = 'role:' . $role_key;
                        $variables = [squuad_cert_signing_role_variable((string) $role_key)];
                        if ('student' === $role_key) {
                            $variables[] = 'signature_student'; // plantillas anteriores
                        }
                        /* translators: %s: name of the role */
                        $row($key, sprintf(__('Role: %s', 'wp-certificates'), $role_name), __('Every user with this role receives their own document and signs it from their own account.', 'wp-certificates'),
                            isset($positions[$key]), $positions[$key] ?? $role_position, $variables, $in_section);
                        $role_position++;
                    }
                    if (!$signing_roles) {
                        echo '<tr><td></td><td colspan="3"><em>' . esc_html__('No role can sign yet: mark them in Certification > Signing roles.', 'wp-certificates') . '</em></td></tr>';
                    }
                    // Firmantes por variable (ADR 0009 de Edusof): la persona cuya cuenta da la variable firma el mismo documento
                    foreach ($signer_variables as $variable => $info) {
                        $key = 'var:' . $variable;
                        if (!$info['available']) {
                            /* translators: %s: variable, for example {{parent_user_id}} */
                            $detail = sprintf(__('Variable not available: no active plugin gives {{%s}} as an account. This signature is skipped and the others sign.', 'wp-certificates'), $variable);
                        } elseif ('automatic' !== $document->type) {
                            $detail = __('Only in automatic documents: in this document this signature is skipped.', 'wp-certificates');
                        } else {
                            /* translators: %s: variable, for example {{parent_user_id}} */
                            $detail = sprintf(__('The person whose account is given by {{%s}} signs this same document, with the data of whoever receives it, from their own account. If the variable is empty or not valid, or it is the account of someone who already signs, this signature is skipped.', 'wp-certificates'), $variable);
                        }
                        /* translators: %s: description of the variable */
                        $row($key, sprintf(__('Signer by variable: %s', 'wp-certificates'), $info['label']), $detail, isset($positions[$key]), $positions[$key] ?? $next++,
                            ['signature_var_' . $variable, 'signer_name_var_' . $variable, 'signer_charge_var_' . $variable],
                            __('Not in the template: it goes in the signatures block at the end.', 'wp-certificates'));
                    }
                    foreach ($signers as $signer) {
                        $key = 'signer:' . (int) $signer->id;
                        $detail = trim((string) $signer->charge . ' · ' . (string) $signer->user_email, ' ·');
                        if ('invited' === $signer->status) {
                            $detail .= ' — ' . __('has not registered their signature yet', 'wp-certificates');
                        }
                        $id = (int) $signer->id;
                        $row($key, (string) $signer->display_name, $detail, isset($positions[$key]), $positions[$key] ?? $next++,
                            ['signature_signer_' . $id, 'signer_name_' . $id, 'signer_charge_' . $id],
                            __('Not in the template: it goes in the signatures block at the end.', 'wp-certificates'));
                    }
                    ?>
                </tbody>
            </table>
            <?php if ($inactive_roles) : ?>
                <p class="description" style="max-width:820px"><?= esc_html(sprintf(__('This document asked these roles, which are no longer active in "Signing roles": %s. Requests in progress keep them; when you save, they are removed.', 'wp-certificates'), implode(', ', $inactive_roles))) ?></p>
            <?php endif; ?>
            <div class="edusig-help" style="max-width:820px;margin-top:10px;padding:10px 12px;background:#f6f7f7;border:1px solid #dcdcde">
                <p style="margin-top:0"><strong><?= esc_html__('How to place the signatures in the template', 'wp-certificates') ?></strong></p>
                <p><?= $chip('signature_section') // phpcs:ignore ?> <?= esc_html__('The signatures of the roles that are not placed separately (as before).', 'wp-certificates') ?></p>
                <p><?= esc_html__('Each signer separately: use the variables of their row. Signers not placed in the template go in {{signature_section}} (roles) or in a signatures block at the end (system signers).', 'wp-certificates') ?></p>
                <?php if ($signer_variables) { ?>
                <p><?= esc_html__('Signers by variable sign after the roles, in the order you choose among the system signers. Each person signs only once: if the variable gives the account of someone who already signs (for example, an adult student who is their own parent), that row is skipped.', 'wp-certificates') ?></p>
                <?php } ?>
                <?php if (wpc_edusystem_active()) { // Reglas de los puestos de EduSystem (estudiante y representante) ?>
                <p style="margin-bottom:4px"><strong><?= esc_html__('Template rules', 'wp-certificates') ?></strong> — <?= esc_html__('the text between the marks is shown only if the rule is met; with ^ , only if it is not:', 'wp-certificates') ?></p>
                <ul style="list-style:disc;margin:0 0 0 20px">
                    <li><code>{{#requires_student_signature}}</code> … <code>{{/requires_student_signature}}</code> — <?= esc_html__('the student signs this document', 'wp-certificates') ?></li>
                </ul>
                <p class="description"><?= esc_html__('The parent no longer signs: in old templates {{signature_parent}} is empty and {{#requires_parent_signature}} and {{#student_is_own_parent}} are never met.', 'wp-certificates') ?></p>
                <?php } ?>
                <?php // Variable fija del sistema (definida en el código, no en la tabla variables_document): siempre disponible ?>
                <p style="margin-bottom:4px"><strong><?= esc_html__('PDF layout', 'wp-certificates') ?></strong></p>
                <p style="margin:0"><?= $chip('page_break') // phpcs:ignore ?> <?= esc_html__('Page break: what follows starts on a new page. In automatic documents, lines, paragraphs and table rows are never cut between pages; use it to decide where a section starts.', 'wp-certificates') ?></p>
            </div>
            <?php if (!$signers) : ?>
                <p class="description"><?= esc_html__('There are no registered signers yet. Invite them from Certification > Signers.', 'wp-certificates') ?></p>
            <?php elseif (!$inbox) : ?>
                <p class="description"><?= esc_html__('Registered signers are saved in the configuration and will be required once their "Documents to sign" panel is active.', 'wp-certificates') ?></p>
            <?php endif; ?>
            <p><button type="submit" class="button button-primary"><?= esc_html__('Save document signers', 'wp-certificates') ?></button></p>
        </form>
    </div>
</div>
<style>
/* La página de documentos estira todos sus campos (.admin-add-offer input { width: 100% }); los checks y radios no */
#edusystem-document-signers input[type=checkbox], #edusystem-document-signers input[type=radio] { width: auto; }
    #edusystem-document-signers .edusig-var { background: #f0f6fc; border: 1px solid #c5d9ed; border-radius: 3px; padding: 1px 6px; cursor: pointer; display: inline-block; margin: 2px 0; }
    #edusystem-document-signers .edusig-var.is-used { opacity: .45; }
    #edusystem-document-signers .edusig-var.copied { background: #d7f0dd; }
</style>
<script>
    // El panel se coloca debajo del formulario del documento (wp-certificates), con su propio botón de guardar
    document.addEventListener("DOMContentLoaded", function () {
        const panel = document.getElementById("edusystem-document-signers");
        const anchor = document.getElementById("metabox") || document.querySelector(".wrap");
        const slot = document.getElementById("wpc-eds-signers-slot"); // diseño Edusof: hueco propio en el editor
        if (panel && slot) {
            slot.appendChild(panel);
            panel.style.display = "";
        } else if (panel && anchor) {
            anchor.parentNode.insertBefore(panel, anchor.nextSibling);
            panel.style.display = "";
        }
        if (!panel) {
            return;
        }
        const texts = {
            used: <?= wp_json_encode(__('✓ In the template', 'wp-certificates')) ?>
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
