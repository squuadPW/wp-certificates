<?php
/**
 * Certificación > Roles que firman. Variables: $roles (clave => nombre), $enabled (claves marcadas), $users (usuarios
 * por rol), $notice.
 */

defined('ABSPATH') || exit;
?>
<div class="wrap">
    <h1><?= esc_html__('Signing roles', 'wp-certificates') ?></h1>
    <?php if ($notice) : ?>
        <div class="notice <?= $notice['ok'] ? 'notice-success' : 'notice-error' ?> is-dismissible"><p><?= esc_html($notice['message']) ?></p></div>
    <?php endif; ?>
    <p style="max-width:820px"><?= esc_html__('Mark the roles that can sign documents. Each marked role appears in "Document signers" of every document, with its own variable for the template. When a document asks a role to sign, every user with that role receives their own document and signs it from their own account; the system signers of the document sign after them.', 'wp-certificates') ?></p>

    <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
        <input type="hidden" name="action" value="squuad_cert_signing_roles_save">
        <?php wp_nonce_field('squuad_cert_signing_roles_save'); ?>
        <table class="widefat striped" style="max-width:820px">
            <thead>
                <tr>
                    <th style="width:40px"></th>
                    <th><?= esc_html__('Role', 'wp-certificates') ?></th>
                    <th><?= esc_html__('Key', 'wp-certificates') ?></th>
                    <th><?= esc_html__('Users', 'wp-certificates') ?></th>
                    <th><?= esc_html__('Variable for the template', 'wp-certificates') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($roles as $key => $name) : ?>
                    <tr>
                        <td><input type="checkbox" name="roles[]" id="squuad-cert-role-<?= esc_attr($key) ?>" value="<?= esc_attr($key) ?>" <?= checked(in_array($key, $enabled, true), true, false) ?>></td>
                        <td><label for="squuad-cert-role-<?= esc_attr($key) ?>"><strong><?= esc_html($name) ?></strong></label></td>
                        <td><code><?= esc_html($key) ?></code></td>
                        <td><?= (int) ($users[$key] ?? 0) ?></td>
                        <td><code>{{<?= esc_html(squuad_cert_signing_role_variable($key)) ?>}}</code></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="description" style="max-width:820px"><?= esc_html__('Unmarking a role does not change the signature requests already in progress: they keep their signers.', 'wp-certificates') ?></p>
        <?php submit_button(__('Save', 'wp-certificates')); ?>
    </form>
</div>
