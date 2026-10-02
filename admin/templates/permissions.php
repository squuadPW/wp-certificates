<?php
/**
 * Certificación > Permisos. Variables: $roles (clave => nombre), $selected, $role (WP_Role), $capabilities
 * (permiso => [nombre, descripción]), $users (usuarios por rol), $notice.
 */

defined('ABSPATH') || exit;
?>
<div class="wrap">
    <h1><?= esc_html__('Permissions', 'wp-certificates') ?></h1>
    <?php if ($notice) : ?>
        <div class="notice <?= $notice['ok'] ? 'notice-success' : 'notice-error' ?> is-dismissible"><p><?= esc_html($notice['message']) ?></p></div>
    <?php endif; ?>
    <p style="max-width:820px"><?= esc_html__('Choose a role and mark the certification permissions it has. Every change is saved in the log. The administrator always keeps this screen.', 'wp-certificates') ?></p>

    <form method="get" style="margin:12px 0">
        <input type="hidden" name="page" value="<?= esc_attr(SQUUAD_CERT_PERMISSIONS_PAGE) ?>">
        <label for="squuad-cert-role"><strong><?= esc_html__('Role', 'wp-certificates') ?></strong></label>
        <select name="role" id="squuad-cert-role" onchange="this.form.submit()">
            <?php foreach ($roles as $key => $name) : ?>
                <option value="<?= esc_attr($key) ?>" <?= selected($selected, $key, false) ?>><?= esc_html($name . ' (' . (int) ($users[$key] ?? 0) . ')') ?></option>
            <?php endforeach; ?>
        </select>
        <noscript><button class="button"><?= esc_html__('Show', 'wp-certificates') ?></button></noscript>
    </form>

    <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
        <input type="hidden" name="action" value="squuad_cert_permissions_save">
        <input type="hidden" name="role" value="<?= esc_attr($selected) ?>">
        <?php wp_nonce_field('squuad_cert_permissions_save_' . $selected); ?>
        <table class="widefat striped" style="max-width:820px">
            <thead><tr><th style="width:40px"></th><th><?= esc_html__('Permission', 'wp-certificates') ?></th><th><?= esc_html__('What it allows', 'wp-certificates') ?></th></tr></thead>
            <tbody>
                <?php foreach ($capabilities as $cap => [$name, $description]) :
                    $locked = 'administrator' === $selected && 'squuad_cert_manage_permissions' === $cap; ?>
                    <tr>
                        <td><input type="checkbox" name="caps[]" id="squuad-cert-cap-<?= esc_attr($cap) ?>" value="<?= esc_attr($cap) ?>" <?= checked($role && $role->has_cap($cap), true, false) ?> <?= $locked ? 'disabled' : '' ?>></td>
                        <td><label for="squuad-cert-cap-<?= esc_attr($cap) ?>"><strong><?= esc_html($name) ?></strong></label><br><code><?= esc_html($cap) ?></code></td>
                        <td><?= esc_html($description) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php submit_button(__('Save', 'wp-certificates')); ?>
    </form>
</div>
