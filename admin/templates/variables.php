<?php
/**
 * Certificación > Variables (admin/variables.php). Variables: $view ('' lista, 'view', 'edit'), $variables,
 * $variable, $documents, $notice.
 */
defined('ABSPATH') || exit;

$type_labels = [
    'all' => __('Documents and emails', 'wp-certificates'),
    'document' => __('Documents', 'wp-certificates'),
    'email' => __('Emails', 'wp-certificates'),
];
?>
<div class="wrap">
    <h1 class="wp-heading-inline"><?= esc_html__('Variables', 'wp-certificates') ?></h1>
    <?php if ('' !== $view) : ?>
        <a class="page-title-action" href="<?= esc_url(squuad_cert_variables_url()) ?>"><?= esc_html__('Back to the list', 'wp-certificates') ?></a>
    <?php endif; ?>
    <hr class="wp-header-end">

    <?php if ($notice) : ?>
        <div class="notice <?= $notice['ok'] ? 'notice-success' : 'notice-error' ?> is-dismissible"><p><?= esc_html($notice['message']) ?></p></div>
    <?php endif; ?>

    <?php if ('' === $view) : ?>
        <p class="description" style="max-width: 900px">
            <?= esc_html__('Variables saved in the database: this is the list shown when writing a document template. Editing or deleting a variable only changes this list; it does not change how the variable is filled in when a document is generated.', 'wp-certificates') ?>
        </p>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 50px">ID</th>
                    <th><?= esc_html__('Variable', 'wp-certificates') ?></th>
                    <th><?= esc_html__('Description', 'wp-certificates') ?></th>
                    <th style="width: 170px"><?= esc_html__('Offered in', 'wp-certificates') ?></th>
                    <th style="width: 210px"><?= esc_html__('Actions', 'wp-certificates') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$variables) : ?>
                    <tr><td colspan="5"><?= esc_html__('There are no variables in the database.', 'wp-certificates') ?></td></tr>
                <?php endif; ?>
                <?php foreach ($variables as $row) : ?>
                    <tr>
                        <td><?= (int) $row->id ?></td>
                        <td><code><?= esc_html($row->visual) ?></code></td>
                        <td><?= esc_html($row->text) ?></td>
                        <td><?= esc_html($type_labels[$row->type] ?? $row->type) ?></td>
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

    <?php elseif ('view' === $view) : ?>
        <table class="form-table" role="presentation">
            <tr><th><?= esc_html__('ID', 'wp-certificates') ?></th><td><?= (int) $variable->id ?></td></tr>
            <tr><th><?= esc_html__('Key', 'wp-certificates') ?></th><td><code><?= esc_html($variable->identificator) ?></code></td></tr>
            <tr><th><?= esc_html__('How it is written', 'wp-certificates') ?></th><td><code><?= esc_html($variable->visual) ?></code></td></tr>
            <tr><th><?= esc_html__('Description', 'wp-certificates') ?></th><td><?= esc_html($variable->text) ?></td></tr>
            <tr><th><?= esc_html__('Offered in', 'wp-certificates') ?></th><td><?= esc_html($type_labels[$variable->type] ?? $variable->type) ?></td></tr>
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
            </table>
            <?php submit_button(__('Save variable', 'wp-certificates')); ?>
        </form>
    <?php endif; ?>
</div>
