<?php
declare(strict_types=1);

/**
 * Certificación › Configuración, sección «Documento de identidad» (admin/id-document.php, ADR 0007 de Edusof).
 * Variables: $notice, $enabled, $types, $usage, $formats, $editing (tipo en edición o null), $edit_raw y $scope.
 */

defined('ABSPATH') || exit;

$squuad_cert_settings_url = admin_url(SQUUAD_CERT_ID_DOCUMENT_SETTINGS_URL);
$squuad_cert_form_type = $editing ?: ['id' => 0, 'name' => '', 'builtin' => '', 'prefix' => '', 'country' => '', 'format' => 'numeric', 'active' => true];
$squuad_cert_form_name = $editing ? squuad_cert_id_document_type_label($editing) : '';
// Q6: tras un error, lo que se escribió
if (!empty($notice['fields']) && is_array($notice['fields'])) {
    $squuad_cert_posted = $notice['fields'];
    $squuad_cert_form_name = (string) ($squuad_cert_posted['name'] ?? '');
    $squuad_cert_form_type = array_merge($squuad_cert_form_type, [
        'prefix' => strtoupper((string) ($squuad_cert_posted['prefix'] ?? '')),
        'country' => strtoupper((string) ($squuad_cert_posted['country'] ?? '')),
        'format' => 'alnum' === ($squuad_cert_posted['format'] ?? '') ? 'alnum' : 'numeric',
        'active' => !empty($squuad_cert_posted['active']),
    ]);
}
$squuad_cert_retired = squuad_cert_id_document_types_data()['retired_prefixes'];
$squuad_cert_form_used = $editing ? (int) ($usage[$editing['id']] ?? 0) : 0;
?>
<div class="<?= esc_attr($scope) ?>" style="margin-top: 24px">
<section class="eds-card squuad-cert-iddoc-settings" id="squuad-cert-id-document" aria-labelledby="squuad-cert-iddoc-settings-title">
    <h2 id="squuad-cert-iddoc-settings-title"><?= esc_html__('Identity document', 'wp-certificates') ?></h2>

    <?php if ($notice) : ?>
        <div class="eds-notice <?= !empty($notice['ok']) ? 'eds-notice--ok' : 'eds-notice--bad' ?>" role="status"><p><?= esc_html((string) $notice['message']) ?></p></div>
    <?php endif; ?>

    <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" class="squuad-cert-iddoc-settings__toggle">
        <input type="hidden" name="action" value="squuad_cert_id_document_toggle">
        <?php wp_nonce_field('squuad_cert_id_document_toggle'); ?>
        <div style="display: flex; gap: 16px; align-items: flex-start; justify-content: space-between; flex-wrap: wrap">
            <div style="flex: 1 1 420px">
                <p style="margin: 0 0 4px"><strong id="squuad-cert-iddoc-enabled-label"><?= esc_html__('Ask for the identity document', 'wp-certificates') ?></strong></p>
                <p id="squuad-cert-iddoc-enabled-help" style="margin: 0; color: var(--eds-muted, #50575e)"><?= esc_html__('Every person who signs (holders, roles and system signers) must register their identity document before signing; whoever only fills in the fields of a document without signature is not asked. It is recorded with each new signature. When it is off, nothing is asked.', 'wp-certificates') ?></p>
            </div>
            <label class="eds-switch">
                <input type="checkbox" role="switch" name="enabled" value="1" aria-labelledby="squuad-cert-iddoc-enabled-label" aria-describedby="squuad-cert-iddoc-enabled-help" <?php checked($enabled); ?>>
                <span class="eds-switch__track" aria-hidden="true"><span class="eds-switch__thumb"></span></span>
            </label>
        </div>
        <p><button type="submit" class="eds-btn eds-btn--primary"><?= esc_html__('Save', 'wp-certificates') ?></button>
            <span class="eds-badge <?= $enabled ? 'eds-badge--ok' : 'eds-badge--neutral' ?>" style="margin-left: 8px"><?= esc_html($enabled ? __('On', 'wp-certificates') : __('Off', 'wp-certificates')) ?></span></p>
    </form>

    <h3 style="margin: 24px 0 8px"><?= esc_html__('Document types', 'wp-certificates') ?></h3>
    <p style="margin: 0 0 12px; color: var(--eds-muted, #50575e)"><?= esc_html__('The identifier of each person is the prefix plus the number without dots, dashes or spaces (V + 12.345.678 = V12345678), and it cannot be repeated between accounts. A type that people use cannot be deleted: deactivate it.', 'wp-certificates') ?></p>

    <p style="margin: 0 0 12px; color: var(--eds-muted, #50575e)"><?= esc_html__('The types marked as examples come preinstalled for Venezuela: edit them, deactivate them or add the ones of your country. A prefix has at least one letter and cannot be the start of another prefix.', 'wp-certificates') ?>
        <?php if ($squuad_cert_retired) : ?>
            <?= esc_html(sprintf(
                /* translators: %s: list of prefixes */
                __('Prefixes of deleted types, which are not reused: %s.', 'wp-certificates'),
                implode(', ', $squuad_cert_retired)
            )) ?>
        <?php endif; ?></p>

    <?php if (!$types) : ?>
        <div class="eds-empty"><p><?= esc_html__('There are no document types yet.', 'wp-certificates') ?></p></div>
    <?php else : ?>
        <div class="eds-table-wrap">
            <table class="eds-table">
                <caption class="eds-sr-only"><?= esc_html__('Document types', 'wp-certificates') ?></caption>
                <thead>
                    <tr>
                        <th scope="col"><?= esc_html__('Name', 'wp-certificates') ?></th>
                        <th scope="col"><?= esc_html__('Prefix', 'wp-certificates') ?></th>
                        <th scope="col"><?= esc_html__('Country', 'wp-certificates') ?></th>
                        <th scope="col"><?= esc_html__('Format', 'wp-certificates') ?></th>
                        <th scope="col"><?= esc_html__('Status', 'wp-certificates') ?></th>
                        <th scope="col"><?= esc_html__('People', 'wp-certificates') ?></th>
                        <th scope="col"><span class="eds-sr-only"><?= esc_html__('Actions', 'wp-certificates') ?></span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($types as $type) :
                        $used = (int) ($usage[$type['id']] ?? 0); ?>
                        <tr>
                            <td><?= esc_html(squuad_cert_id_document_type_label($type)) ?>
                                <?php if ('' !== $type['builtin']) : ?> <span class="eds-badge eds-badge--info"><?= esc_html__('Example (Venezuela)', 'wp-certificates') ?></span><?php endif; ?></td>
                            <td><code><?= esc_html($type['prefix']) ?></code></td>
                            <td><?= esc_html('' !== $type['country'] ? $type['country'] : '—') ?></td>
                            <td><?= esc_html($formats[$type['format']] ?? $type['format']) ?></td>
                            <td><span class="eds-badge <?= $type['active'] ? 'eds-badge--ok' : 'eds-badge--neutral' ?>"><?= esc_html($type['active'] ? __('Active', 'wp-certificates') : __('Inactive', 'wp-certificates')) ?></span></td>
                            <td><?= (int) $used ?></td>
                            <td style="white-space: nowrap">
                                <a class="eds-btn eds-btn--secondary eds-btn--sm" href="<?= esc_url(add_query_arg('edit_id_type', $type['id'], $squuad_cert_settings_url) . '#squuad-cert-iddoc-type-form') ?>"><?= esc_html__('Edit', 'wp-certificates') ?></a>
                                <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" style="display: inline">
                                    <input type="hidden" name="action" value="squuad_cert_id_document_type_status">
                                    <input type="hidden" name="type_id" value="<?= (int) $type['id'] ?>">
                                    <input type="hidden" name="active" value="<?= $type['active'] ? '0' : '1' ?>">
                                    <?php wp_nonce_field('squuad_cert_id_document_type_status_' . (int) $type['id']); ?>
                                    <button type="submit" class="eds-btn eds-btn--link eds-btn--sm"><?= esc_html($type['active'] ? __('Deactivate', 'wp-certificates') : __('Activate', 'wp-certificates')) ?></button>
                                </form>
                                <?php if (!$used) : ?>
                                    <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" style="display: inline" onsubmit="return confirm(<?= esc_attr(wp_json_encode(__('Delete this document type? Nobody uses it.', 'wp-certificates'))) ?>);">
                                        <input type="hidden" name="action" value="squuad_cert_id_document_type_delete">
                                        <input type="hidden" name="type_id" value="<?= (int) $type['id'] ?>">
                                        <?php wp_nonce_field('squuad_cert_id_document_type_delete_' . (int) $type['id']); ?>
                                        <button type="submit" class="eds-btn eds-btn--link eds-btn--sm" style="color: var(--eds-bad-fg, #b32d2e)"><?= esc_html__('Delete', 'wp-certificates') ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" id="squuad-cert-iddoc-type-form" style="margin-top: 20px">
        <input type="hidden" name="action" value="squuad_cert_id_document_type_save">
        <input type="hidden" name="type_id" value="<?= (int) $squuad_cert_form_type['id'] ?>">
        <?php wp_nonce_field('squuad_cert_id_document_type_save'); ?>
        <h3 style="margin: 0 0 8px"><?= esc_html($editing ? __('Edit document type', 'wp-certificates') : __('Add a document type', 'wp-certificates')) ?></h3>
        <div class="eds-filters">
            <label class="eds-field" style="flex: 2 1 240px"><?= esc_html__('Name', 'wp-certificates') ?>
                <input type="text" name="name" maxlength="80" required value="<?= esc_attr($squuad_cert_form_name) ?>">
            </label>
            <label class="eds-field" style="flex: 0 1 120px"><?= esc_html__('Prefix', 'wp-certificates') ?>
                <input type="text" name="prefix" maxlength="5" required pattern="(?=.*[A-Za-z])[A-Za-z0-9]{1,5}" style="text-transform: uppercase" value="<?= esc_attr($squuad_cert_form_type['prefix']) ?>"<?= $squuad_cert_form_used ? ' readonly aria-describedby="squuad-cert-iddoc-prefix-help"' : '' ?>>
            </label>
            <label class="eds-field" style="flex: 0 1 120px"><?= esc_html__('Country (ISO, optional)', 'wp-certificates') ?>
                <input type="text" name="country" maxlength="2" pattern="[A-Za-z]{2}" style="text-transform: uppercase" value="<?= esc_attr($squuad_cert_form_type['country']) ?>" placeholder="VE">
            </label>
            <label class="eds-field" style="flex: 1 1 180px"><?= esc_html__('Format', 'wp-certificates') ?>
                <select name="format">
                    <?php foreach ($formats as $key => $label) : ?>
                        <option value="<?= esc_attr($key) ?>" <?php selected($squuad_cert_form_type['format'], $key); ?>><?= esc_html($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label style="display: flex; gap: 6px; align-items: center; min-height: 40px">
                <input type="checkbox" name="active" value="1" <?php checked($squuad_cert_form_type['active']); ?>> <?= esc_html__('Active', 'wp-certificates') ?>
            </label>
        </div>
        <?php if ($squuad_cert_form_used) : ?>
            <p id="squuad-cert-iddoc-prefix-help" style="color: var(--eds-muted, #50575e)"><?= esc_html(sprintf(
                /* translators: %d: number of people */
                _n('%d person uses this type: its prefix cannot be changed.', '%d people use this type: its prefix cannot be changed.', $squuad_cert_form_used, 'wp-certificates'),
                $squuad_cert_form_used
            )) ?></p>
        <?php endif; ?>
        <p>
            <button type="submit" class="eds-btn eds-btn--primary"><?= esc_html($editing ? __('Save document type', 'wp-certificates') : __('Add document type', 'wp-certificates')) ?></button>
            <?php if ($editing || 'new' === $edit_raw) : ?>
                <a class="eds-btn eds-btn--link" href="<?= esc_url($squuad_cert_settings_url . '#squuad-cert-id-document') ?>"><?= esc_html__('Cancel', 'wp-certificates') ?></a>
            <?php endif; ?>
        </p>
    </form>
</section>
</div>
