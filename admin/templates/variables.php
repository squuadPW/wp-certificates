<?php
/**
 * Certificación > Variables (admin/variables.php). Variables: $view ('' lista, 'view', 'edit', 'new'), $variables,
 * $variable, $documents, $notice, $methods (todos los registrados), $available (los que se pueden elegir),
 * $own_plugins, $enabled_plugins, $quarantine, $statuses (id => quién le da valor, squuad_cert_catalog_variable_status())
 * y $person_variables (variables de la persona que no están en la lista, con quién les da valor).
 */
defined('ABSPATH') || exit;

// Nombre legible de un método: «Plugin › Grupo › Descripción (identificador)»
$method_label = static function (string $id) use ($methods, $own_plugins): string {
    if (!isset($methods[$id])) {
        return $id;
    }
    $m = $methods[$id];
    $plugin = $own_plugins[$m['plugin']]['Name'] ?? $m['plugin'];

    return trim($plugin . ' › ' . ($m['group'] ? $m['group'] . ' › ' : '') . $m['label']) . ' (' . $id . ')';
};
// Selector de método: solo los disponibles (plugin propio activado y fuera de cuarentena), agrupados por plugin
$method_select = static function (string $current, bool $allow_empty) use ($available, $methods, $own_plugins, $method_label): void {
    $by_plugin = [];
    foreach ($available as $id => $m) {
        $by_plugin[$own_plugins[$m['plugin']]['Name'] ?? $m['plugin']][$id] = $m;
    }
    ksort($by_plugin);
    echo '<select name="method" id="squuad-cert-method">';
    echo '<option value="">' . esc_html($allow_empty ? __('— No method —', 'wp-certificates') : __('— Choose a method —', 'wp-certificates')) . '</option>';
    foreach ($by_plugin as $plugin => $items) {
        echo '<optgroup label="' . esc_attr($plugin) . '">';
        foreach ($items as $id => $m) {
            echo '<option value="' . esc_attr($id) . '"' . selected($current, $id, false) . '>'
                . esc_html(($m['group'] ? $m['group'] . ' › ' : '') . $m['label'] . ' (' . $id . ')') . '</option>';
        }
        echo '</optgroup>';
    }
    if ('' !== $current && !isset($available[$current])) {
        echo '<option value="" selected>' . esc_html(sprintf(
            /* translators: %s: method */
            __('Unavailable: %s', 'wp-certificates'),
            $method_label($current)
        )) . '</option>';
    }
    echo '</select>';
};

$type_labels = [
    'all' => __('Documents and emails', 'wp-certificates'),
    'document' => __('Documents', 'wp-certificates'),
    'email' => __('Emails', 'wp-certificates'),
];
?>
<div class="wrap">
    <h1 class="wp-heading-inline"><?= esc_html__('Variables', 'wp-certificates') ?></h1>
    <?php if ('' === $view) : ?>
        <a class="page-title-action" href="<?= esc_url(squuad_cert_variables_url(['view' => 'new'])) ?>"><?= esc_html__('Add variable', 'wp-certificates') ?></a>
    <?php endif; ?>
    <?php if ('' !== $view) : ?>
        <a class="page-title-action" href="<?= esc_url(squuad_cert_variables_url()) ?>"><?= esc_html__('Back to the list', 'wp-certificates') ?></a>
    <?php endif; ?>
    <hr class="wp-header-end">

    <?php if ($notice) : ?>
        <div class="notice <?= $notice['ok'] ? 'notice-success' : 'notice-error' ?> is-dismissible"><p><?= esc_html($notice['message']) ?></p></div>
    <?php endif; ?>

    <div class="notice notice-warning inline" style="margin: 12px 0">
        <p><strong><?= esc_html__('Administrators only.', 'wp-certificates') ?></strong>
            <?= esc_html__('The value of a variable is given by a method written in the code of an own plugin (EduSof or Squuad) and chosen from a list. No code is ever written or stored here. Changes are recorded in the log.', 'wp-certificates') ?></p>
    </div>

    <?php if ('' === $view) : ?>
        <p class="description" style="max-width: 900px">
            <?= esc_html__('Variables saved in the database: this is the list shown when writing a document template. Editing or deleting a variable only changes this list; it does not change how the variable is filled in when a document is issued.', 'wp-certificates') ?>
        </p>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 50px">ID</th>
                    <th><?= esc_html__('Variable', 'wp-certificates') ?></th>
                    <th><?= esc_html__('Description', 'wp-certificates') ?></th>
                    <th style="width: 170px"><?= esc_html__('Offered in', 'wp-certificates') ?></th>
                    <th><?= esc_html__('Method', 'wp-certificates') ?></th>
                    <th style="width: 210px"><?= esc_html__('Actions', 'wp-certificates') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$variables) : ?>
                    <tr><td colspan="6"><?= esc_html__('There are no variables in the database.', 'wp-certificates') ?></td></tr>
                <?php endif; ?>
                <?php foreach ($variables as $row) : ?>
                    <tr>
                        <td><?= (int) $row->id ?></td>
                        <td><code><?= esc_html($row->visual) ?></code></td>
                        <td><?= esc_html(squuad_cert_variable_display_text($row, $methods)) ?></td>
                        <td><?= esc_html($type_labels[$row->type] ?? $row->type) ?></td>
                        <td>
                            <?php if (empty($row->method)) : ?>
                                <span class="description"><?= wpc_edusystem_active() ? esc_html__('No method (calculated by EduSystem as before)', 'wp-certificates') : esc_html__('No method', 'wp-certificates') ?></span>
                            <?php elseif (isset($available[$row->method])) : ?>
                                <code><?= esc_html($row->method) ?></code>
                            <?php else : ?>
                                <span style="color:#b32d2e"><?= esc_html(isset($quarantine[$row->method]) ? __('In quarantine', 'wp-certificates') : __('Unavailable', 'wp-certificates')) ?>: <code><?= esc_html($row->method) ?></code></span>
                            <?php endif; ?>
                            <?php $badge = squuad_cert_variable_status_badge($statuses[(int) $row->id] ?? ''); ?>
                            <?php if ('' !== $badge) : ?><br><?= $badge // escapado en squuad_cert_variable_status_badge() ?><?php endif; ?>
                        </td>
                        <td>
                            <a class="button button-small" href="<?= esc_url(squuad_cert_variables_url(['view' => 'view', 'id' => (int) $row->id])) ?>"><?= esc_html__('View', 'wp-certificates') ?></a>
                            <a class="button button-small" href="<?= esc_url(squuad_cert_variables_url(['view' => 'edit', 'id' => (int) $row->id])) ?>"><?= esc_html__('Edit', 'wp-certificates') ?></a>
                            <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" style="display:inline"
                                onsubmit="return confirm(<?= esc_attr(wp_json_encode(sprintf(
                                    /* translators: %s: variable */
                                    __('Delete %s from the list? Documents that already use it are not changed.', 'wp-certificates'),
                                    $row->visual
                                ))) ?>);">
                                <input type="hidden" name="action" value="squuad_cert_variable_delete">
                                <input type="hidden" name="id" value="<?= (int) $row->id ?>">
                                <?php wp_nonce_field('squuad_cert_variable_delete_' . (int) $row->id); ?>
                                <button type="submit" class="button button-small button-link-delete"><?= esc_html__('Delete', 'wp-certificates') ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($person_variables) : ?>
            <h2 style="margin-top: 30px"><?= esc_html__('Variables of the person', 'wp-certificates') ?></h2>
            <p class="description" style="max-width: 900px">
                <?= esc_html__('Details of the person who receives the document. They are offered in the editor when a plugin or the WordPress account of the person gives them a value; the source is shown in "Source of the value".', 'wp-certificates') ?>
            </p>
            <table class="wp-list-table widefat fixed striped" style="max-width: 900px">
                <thead>
                    <tr>
                        <th><?= esc_html__('Variable', 'wp-certificates') ?></th>
                        <th><?= esc_html__('Description', 'wp-certificates') ?></th>
                        <th><?= esc_html__('Source of the value', 'wp-certificates') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($person_variables as $key => $person) : ?>
                        <tr>
                            <td><code><?= esc_html('{{' . $key . '}}') ?></code></td>
                            <td><?= esc_html($person['label']) ?></td>
                            <td><?= 'holder' === $person['source'] ? esc_html__('WordPress account of the person', 'wp-certificates') : esc_html($method_label($person['source'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" style="margin-top: 12px">
            <input type="hidden" name="action" value="squuad_cert_variables_link">
            <?php wp_nonce_field('squuad_cert_variables_link'); ?>
            <button type="submit" class="button"><?= esc_html__('Link variables without a method to the method with the same key', 'wp-certificates') ?></button>
        </form>

        <h2 style="margin-top: 30px"><?= esc_html__('Own plugins that can provide methods', 'wp-certificates') ?></h2>
        <p class="description" style="max-width: 900px">
            <?= esc_html__('Only plugins whose details mention EduSof or Squuad appear here. Check the author and website before enabling one: only enabled plugins can provide methods.', 'wp-certificates') ?>
        </p>
        <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
            <input type="hidden" name="action" value="squuad_cert_method_plugins">
            <?php wp_nonce_field('squuad_cert_method_plugins'); ?>
            <table class="wp-list-table widefat fixed striped" style="max-width: 1100px">
                <thead>
                    <tr>
                        <th style="width: 70px"><?= esc_html__('Enabled', 'wp-certificates') ?></th>
                        <th><?= esc_html__('Plugin', 'wp-certificates') ?></th>
                        <th><?= esc_html__('Author', 'wp-certificates') ?></th>
                        <th style="width: 110px"><?= esc_html__('Methods', 'wp-certificates') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($own_plugins as $file => $data) :
                        $count = count(array_filter($methods, static fn($m) => $m['plugin'] === $file)); ?>
                        <tr>
                            <td><input type="checkbox" name="plugins[]" value="<?= esc_attr($file) ?>" <?php checked(in_array($file, $enabled_plugins, true)); ?>></td>
                            <td><strong><?= esc_html($data['Name']) ?></strong><br><code><?= esc_html($file) ?></code><?= is_plugin_active($file) ? '' : ' <span class="description">(' . esc_html__('inactive', 'wp-certificates') . ')</span>' ?></td>
                            <td><?= esc_html(wp_strip_all_tags($data['Author'])) ?><br><span class="description"><?= esc_html($data['AuthorURI']) ?></span></td>
                            <td><?= (int) $count ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php submit_button(__('Save plugins', 'wp-certificates')); ?>
        </form>

        <?php if ($quarantine) : ?>
            <h2 style="margin-top: 30px"><?= esc_html__('Methods in quarantine', 'wp-certificates') ?></h2>
            <p class="description"><?= esc_html__('These methods stopped a request (memory, time or exit) and are not executed again until they are enabled. Fix the plugin before enabling them.', 'wp-certificates') ?></p>
            <table class="wp-list-table widefat fixed striped" style="max-width: 1100px">
                <tbody>
                    <?php foreach ($quarantine as $id => $info) : ?>
                        <tr>
                            <td><?= esc_html($method_label($id)) ?></td>
                            <td><?= esc_html($info['at']) ?> UTC — <?= esc_html($info['reason']) ?></td>
                            <td style="width: 140px">
                                <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
                                    <input type="hidden" name="action" value="squuad_cert_method_release">
                                    <input type="hidden" name="method" value="<?= esc_attr($id) ?>">
                                    <?php wp_nonce_field('squuad_cert_method_release_' . $id); ?>
                                    <button type="submit" class="button button-small"><?= esc_html__('Enable again', 'wp-certificates') ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

    <?php elseif ('new' === $view) : ?>
        <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
            <input type="hidden" name="action" value="squuad_cert_variable_create">
            <?php wp_nonce_field('squuad_cert_variable_create'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="squuad-cert-key"><?= esc_html__('Key', 'wp-certificates') ?></label></th>
                    <td>
                        <input type="text" class="regular-text" id="squuad-cert-key" name="identificator" pattern="[a-z][a-z0-9_]+" required>
                        <p class="description"><?= esc_html__('Lowercase letters, numbers and underscores. In the template it is written {{key}}.', 'wp-certificates') ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="squuad-cert-text"><?= esc_html__('Description', 'wp-certificates') ?></label></th>
                    <td><input type="text" class="large-text" id="squuad-cert-text" name="text" required></td>
                </tr>
                <tr>
                    <th><label for="squuad-cert-type"><?= esc_html__('Offered in', 'wp-certificates') ?></label></th>
                    <td>
                        <select id="squuad-cert-type" name="type">
                            <?php foreach ($type_labels as $value => $label) : ?>
                                <option value="<?= esc_attr($value) ?>"><?= esc_html($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="squuad-cert-method"><?= esc_html__('Method that gives the value', 'wp-certificates') ?></label></th>
                    <td>
                        <?php $method_select('', false); ?>
                        <p class="description"><?= esc_html__('Methods of the enabled own plugins.', 'wp-certificates') ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(__('Create variable', 'wp-certificates')); ?>
        </form>

    <?php elseif ('view' === $view) : ?>
        <table class="form-table" role="presentation">
            <tr><th><?= esc_html__('ID', 'wp-certificates') ?></th><td><?= (int) $variable->id ?></td></tr>
            <tr><th><?= esc_html__('Key', 'wp-certificates') ?></th><td><code><?= esc_html($variable->identificator) ?></code></td></tr>
            <tr><th><?= esc_html__('How it is written', 'wp-certificates') ?></th><td><code><?= esc_html($variable->visual) ?></code></td></tr>
            <tr><th><?= esc_html__('Description', 'wp-certificates') ?></th><td><?= esc_html($variable->text) ?></td></tr>
            <tr><th><?= esc_html__('Offered in', 'wp-certificates') ?></th><td><?= esc_html($type_labels[$variable->type] ?? $variable->type) ?></td></tr>
            <tr><th><?= esc_html__('Method', 'wp-certificates') ?></th><td><?= empty($variable->method) ? (wpc_edusystem_active() ? esc_html__('No method (calculated by EduSystem as before)', 'wp-certificates') : esc_html__('No method', 'wp-certificates')) : esc_html($method_label((string) $variable->method)) ?>
                <?php $badge = squuad_cert_variable_status_badge($statuses[(int) $variable->id] ?? ''); ?>
                <?php if ('' !== $badge) : ?><br><?= $badge // escapado en squuad_cert_variable_status_badge() ?><?php endif; ?></td></tr>
            <tr><th><?= esc_html__('Created', 'wp-certificates') ?></th><td><?= esc_html((string) $variable->created_at) ?></td></tr>
            <tr>
                <th><?= esc_html__('Documents that use it', 'wp-certificates') ?></th>
                <td>
                    <?php if (!$documents) : ?>
                        <?= esc_html__('No document template uses it.', 'wp-certificates') ?>
                    <?php else : ?>
                        <ul style="margin: 0">
                            <?php foreach ($documents as $doc) : ?>
                                <li>
                                    <a href="<?= esc_url(admin_url('admin.php?page=add_admin_form_documents_content&section_tab=document_detail&document_id=' . (int) $doc->id)) ?>"><?= esc_html($doc->title) ?></a>
                                    (<code><?= esc_html($doc->document_identificator) ?></code><?= (int) $doc->status ? '' : ', ' . esc_html__('inactive', 'wp-certificates') ?>)
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
        <p>
            <a class="button button-primary" href="<?= esc_url(squuad_cert_variables_url(['view' => 'edit', 'id' => (int) $variable->id])) ?>"><?= esc_html__('Edit', 'wp-certificates') ?></a>
        </p>

    <?php else : ?>
        <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
            <input type="hidden" name="action" value="squuad_cert_variable_save">
            <input type="hidden" name="id" value="<?= (int) $variable->id ?>">
            <?php wp_nonce_field('squuad_cert_variable_save_' . (int) $variable->id); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th><?= esc_html__('Key', 'wp-certificates') ?></th>
                    <td>
                        <code><?= esc_html($variable->identificator) ?></code>
                        <p class="description"><?= esc_html__('The key is what the code fills in when a document is generated: it cannot be changed here.', 'wp-certificates') ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="squuad-cert-visual"><?= esc_html__('How it is written', 'wp-certificates') ?></label></th>
                    <td><input type="text" class="regular-text" id="squuad-cert-visual" name="visual" value="<?= esc_attr($variable->visual) ?>" required></td>
                </tr>
                <tr>
                    <th><label for="squuad-cert-text"><?= esc_html__('Description', 'wp-certificates') ?></label></th>
                    <td><input type="text" class="large-text" id="squuad-cert-text" name="text" value="<?= esc_attr($variable->text) ?>" required></td>
                </tr>
                <tr>
                    <th><label for="squuad-cert-type"><?= esc_html__('Offered in', 'wp-certificates') ?></label></th>
                    <td>
                        <select id="squuad-cert-type" name="type">
                            <?php foreach ($type_labels as $value => $label) : ?>
                                <option value="<?= esc_attr($value) ?>" <?php selected($variable->type, $value); ?>><?= esc_html($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="squuad-cert-method"><?= esc_html__('Method that gives the value', 'wp-certificates') ?></label></th>
                    <td>
                        <?php $method_select((string) ($variable->method ?? ''), true); ?>
                        <p class="description"><?= wpc_edusystem_active() ? esc_html__('Without a method, the value is calculated as before (EduSystem).', 'wp-certificates') : esc_html__('Without a method, the variable has no value.', 'wp-certificates') ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(__('Save variable', 'wp-certificates')); ?>
        </form>
    <?php endif; ?>
</div>
